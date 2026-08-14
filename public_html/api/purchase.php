<?php
/**
 * api/purchase.php — start a purchase.
 *
 * Creates the pending transaction and hands back what the customer needs
 * to pay: the account number and a reference code for a bank transfer,
 * or a wallet address for USDT. Nothing is activated here — that happens
 * on approval, in payments.php or admin.php.
 *
 * The one exception is paying from wallet balance, which is already our
 * money and settles immediately.
 */

declare(strict_types=1);
require __DIR__ . '/../../private/bootstrap.php';

require_method('POST');
csrf_check();

$customer = require_customer();
rate_limit('purchase:' . $customer['id'], 12, 600);

$planId = (int) want('plan_id');
$method = (string) input('method', 'bank_transfer');

$allowed = ['bank_transfer', 'usdt_bsc', 'usdt_tron', 'wallet', 'opay'];
if (!in_array($method, $allowed, true)) {
    fail('Unknown payment method', 422);
}
if ($method === 'opay' && setting_int('opay_enabled', 0) !== 1) {
    fail('Card payment is not switched on yet. Use bank transfer.', 400);
}

$plan = get_plan($planId);
if (!$plan) {
    fail('That plan is not available', 404);
}

$amount = (float) $plan['price_naira'];

// ---------------------------------------------------------------------
// Wallet — settles now.
// ---------------------------------------------------------------------
if ($method === 'wallet') {
    if ((float) $customer['wallet_naira'] < $amount) {
        fail('Not enough wallet balance', 402, ['balance' => (float) $customer['wallet_naira']]);
    }

    tx_begin();
    try {
        // Conditional UPDATE: the WHERE clause is the guard against two
        // tabs spending the same balance twice.
        $st = q('UPDATE customers SET wallet_naira = wallet_naira - ? WHERE id = ? AND wallet_naira >= ?',
            [$amount, $customer['id'], $amount]);
        if ($st->rowCount() !== 1) {
            throw new RuntimeException('Balance changed, try again');
        }

        q("INSERT INTO transactions (customer_id, plan_id, amount_naira, method, reference, status, approved_at)
           VALUES (?,?,?,'wallet',?, 'success', NOW())",
            [$customer['id'], $planId, $amount, make_reference('IBW')]);

        $subId = activate_subscription((int) $customer['id'], $planId, last_id());
        tx_commit();
    } catch (Throwable $ex) {
        tx_rollback();
        fail('Could not complete that: ' . $ex->getMessage(), 400);
    }

    ok(['settled' => true, 'subscription_id' => $subId, 'message' => 'Plan is active. Connect to IB Gadgets WiFi.']);
}

// ---------------------------------------------------------------------
// Everything else — pending until proof is approved.
// ---------------------------------------------------------------------
$reference = make_reference($method === 'bank_transfer' ? 'IB' : 'IBU');

q("INSERT INTO transactions (customer_id, plan_id, amount_naira, method, reference, status)
   VALUES (?,?,?,?,?, 'pending')",
    [$customer['id'], $planId, $amount, $method, $reference]);

$txId = last_id();
$s    = public_settings();

$response = [
    'transaction_id' => $txId,
    'reference'      => $reference,
    'amount'         => $amount,
    'amount_text'    => format_naira($amount),
    'plan'           => plan_public($plan),
    'method'         => $method,
];

if ($method === 'bank_transfer') {
    $response['bank'] = [
        'bank_name'    => $s['bank_name'],
        'account_name' => $s['bank_account_name'],
        'account_no'   => $s['bank_account_no'],
        'note'         => $s['bank_note'],
    ];
    $response['instructions'] = "Transfer {$response['amount_text']} and put {$reference} in the narration, "
        . 'then upload the receipt. We confirm and your plan goes live.';
}

if ($method === 'usdt_bsc' || $method === 'usdt_tron') {
    $rate = max(1.0, (float) $s['ngn_per_usdt']);
    $response['usdt'] = [
        'chain'   => $method === 'usdt_bsc' ? 'BSC (BEP-20)' : 'TRON (TRC-20)',
        'address' => $method === 'usdt_bsc' ? $s['wallet_bsc'] : $s['wallet_tron'],
        'amount'  => round($amount / $rate, 2),
        'rate'    => $rate,
    ];
    $response['instructions'] = 'Send the USDT, then paste the transaction hash so we can verify it on-chain.';
}

ok($response);
