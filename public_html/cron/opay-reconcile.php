<?php
/**
 * cron/opay-reconcile.php — the safety net under the callbacks.
 *
 * cPanel cron, every 5 minutes:
 *   /usr/local/bin/php /home/USER/public_html/cron/opay-reconcile.php
 *
 * Webhooks get lost. They get retried into a server that was restarting,
 * they get eaten by a proxy, they arrive out of order. A customer who
 * paid and never got switched on is the worst failure this system has,
 * and it is the one they will tell their neighbours about.
 *
 * So we also ask. Any OPay transaction still pending a few minutes after
 * it was created gets its status pulled directly, and anything OPay calls
 * SUCCESS is activated exactly as the callback would have.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !isset($_GET['key'])) {
    http_response_code(403);
    exit('CLI only');
}

define('IBG_NO_SESSION', true);
require __DIR__ . '/../../private/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    if (!hash_equals((string) $CONFIG['sync_key'], (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$report = ['checked' => 0, 'activated' => 0, 'failed' => 0, 'expired' => 0];

if (!opay_enabled()) {
    finish($report, 'OPay disabled, nothing to do');
}

// Old enough that the callback has had its chance, young enough that the
// generated account has not long expired.
$pending = all(
    "SELECT * FROM transactions
      WHERE status = 'pending'
        AND method = 'opay'
        AND opay_order_no IS NOT NULL
        AND created_at < (NOW() - INTERVAL 3 MINUTE)
        AND created_at > (NOW() - INTERVAL 3 DAY)
      ORDER BY id ASC
      LIMIT 40"
);

foreach ($pending as $tx) {
    $report['checked']++;

    $res = opay_query_status((string) $tx['reference']);
    if (!$res['ok']) {
        continue;                       // network blip; try again next run
    }

    $status = strtoupper((string) ($res['data']['status'] ?? ''));

    if ($status === 'SUCCESS') {
        $planId = (int) $tx['plan_id'];
        if (!$planId) {
            continue;                   // needs a human, leave it in the queue
        }

        // Amount check, same as the callback path. Never activate on
        // status alone.
        $paidKobo     = (int) round((float) ($res['data']['amount']['total'] ?? 0));
        $expectedKobo = (int) round(((float) $tx['amount_naira']) * 100);
        if ($paidKobo > 0 && $paidKobo < $expectedKobo) {
            q("UPDATE transactions SET note = ? WHERE id = ?",
                [sprintf('Underpaid: got %d kobo, expected %d', $paidKobo, $expectedKobo), $tx['id']]);
            continue;
        }

        tx_begin();
        try {
            q("UPDATE transactions
                  SET status = 'success', approved_at = NOW(), note = 'auto-confirmed by reconcile'
                WHERE id = ? AND status = 'pending'", [$tx['id']]);
            activate_subscription((int) $tx['customer_id'], $planId, (int) $tx['id']);
            tx_commit();
            $report['activated']++;
        } catch (Throwable $ex) {
            tx_rollback();
            opay_log('reconcile', (string) $tx['reference'], 'activation failed: ' . $ex->getMessage());
        }
        continue;
    }

    if (in_array($status, ['FAIL', 'CLOSE'], true)) {
        q("UPDATE transactions SET status = 'failed', note = ? WHERE id = ?",
            ['OPay reported ' . $status, $tx['id']]);
        $report['failed']++;
        continue;
    }

    // INITIAL or PENDING — the customer has not transferred yet. If the
    // generated account has expired they never will.
    if ($tx['pay_expires_at'] && strtotime((string) $tx['pay_expires_at']) < time()) {
        q("UPDATE transactions SET status = 'failed', note = 'account number expired unpaid' WHERE id = ?",
            [$tx['id']]);
        $report['expired']++;
    }
}

finish($report, 'ok');


function finish(array $report, string $note): never
{
    q('INSERT INTO sync_log (action, payload, result) VALUES (?,?,?)',
        ['opay_reconcile', json_encode($report), $note]);

    if (PHP_SAPI === 'cli') {
        foreach ($report as $k => $v) {
            printf("%-12s %d\n", $k, $v);
        }
        echo $note, PHP_EOL;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'note' => $note] + $report);
    }
    exit;
}
