<?php
/**
 * OPay — dynamic bank account collection.
 *
 * The customer transfers into an account number generated for their one
 * payment, so OPay knows exactly which order landed and tells us. No
 * reference in the narration, no receipt, no waiting for an admin.
 *
 * TWO DIFFERENT SIGNATURES. This is the thing that breaks integrations:
 *
 *   outbound requests  →  HMAC-SHA512   over the JSON body
 *   inbound callbacks  →  HMAC-SHA3-512 over a rebuilt field string
 *
 * and the callback signature arrives in a field named "sha512", which is
 * not SHA-512. Reading that field name and reaching for hash_hmac('sha512')
 * fails every verification with no useful error.
 *
 * Docs: https://documentation.opaycheckout.com/callback-signature
 *       https://documentation.opaycheckout.com/query-payment-status
 */

declare(strict_types=1);

const OPAY_LIVE = 'https://liveapi.opaycheckout.com';
const OPAY_TEST = 'https://testapi.opaycheckout.com';

function opay_base(): string
{
    return setting_int('opay_live', 0) === 1 ? OPAY_LIVE : OPAY_TEST;
}

function opay_enabled(): bool
{
    return setting_int('opay_enabled', 0) === 1
        && setting('opay_merchant_id', '') !== ''
        && setting('opay_secret_key', '') !== '';
}

/**
 * Bundles at or above this price get the automated account. Cheaper ones
 * stay on the free manual path, because a flat gateway fee against a
 * ₦500 bundle is a tenth of the sale.
 *
 * 0 disables the automated path entirely.
 */
function opay_threshold(): float
{
    return (float) setting('opay_auto_threshold', '0');
}

function opay_use_auto(float $amountNaira): bool
{
    $threshold = opay_threshold();
    return opay_enabled() && $threshold > 0 && $amountNaira >= $threshold;
}

// ---------------------------------------------------------------------
// signing
// ---------------------------------------------------------------------

/** Outbound: HMAC-SHA512 of the exact JSON body, keyed with the secret. */
function opay_sign_request(string $jsonBody): string
{
    return hash_hmac('sha512', $jsonBody, (string) setting('opay_secret_key', ''));
}

/**
 * Inbound: rebuild the signed string exactly as OPay does — eight fields,
 * alphabetical, unquoted keys, booleans as t/f — then HMAC-SHA3-512 it.
 *
 * Any deviation in order, quoting or that t/f and the hashes will not
 * match. Do not be tempted to sign the raw request body instead.
 */
function opay_callback_signature(array $payload): string
{
    // The callback body uses lowercase keys (amount, transactionId) while
    // the signed string uses capitalised ones (Amount, TransactionID), so
    // look them up without caring about case. Values go in exactly as
    // received — do not reformat the amount or the timestamp.
    $f = static function (array $p, string $key): string {
        foreach ($p as $k => $v) {
            if (strcasecmp((string) $k, $key) === 0) {
                return is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            }
        }
        return '';
    };

    $refunded = strtolower($f($payload, 'Refunded'));
    $isRefunded = ($refunded === 'true' || $refunded === '1' || $refunded === 't');

    $signed = sprintf(
        '{Amount:"%s",Currency:"%s",Reference:"%s",Refunded:%s,Status:"%s",Timestamp:"%s",Token:"%s",TransactionID:"%s"}',
        $f($payload, 'Amount'),
        $f($payload, 'Currency'),
        $f($payload, 'Reference'),
        $isRefunded ? 't' : 'f',
        $f($payload, 'Status'),
        $f($payload, 'Timestamp'),
        $f($payload, 'Token'),
        $f($payload, 'TransactionID')
    );

    return hash_hmac('sha3-512', $signed, (string) setting('opay_secret_key', ''));
}

/** Pull a field from a callback payload without caring about key case. */
function opay_field(array $payload, string $key): string
{
    foreach ($payload as $k => $v) {
        if (strcasecmp((string) $k, $key) === 0) {
            return is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }
    }
    return '';
}

function opay_verify_callback(array $payload, string $received): bool
{
    if ($received === '') {
        return false;
    }
    return hash_equals(opay_callback_signature($payload), strtolower(trim($received)));
}

// ---------------------------------------------------------------------
// transport
// ---------------------------------------------------------------------

/**
 * @param string $path   e.g. /api/v1/international/payment/create
 * @param bool   $usePublicKey  create endpoints may authorise with the
 *        public key rather than a signature — the docs are inconsistent
 *        on this, so it is a setting rather than a guess baked into code.
 * @return array{ok:bool, code:string, message:string, data:array}
 */
function opay_call(string $path, array $body, bool $usePublicKey = false): array
{
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'code' => 'LOCAL', 'message' => 'Could not encode request', 'data' => []];
    }

    $auth = $usePublicKey
        ? (string) setting('opay_public_key', '')
        : opay_sign_request($json);

    $ch = curl_init(opay_base() . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $auth,
            'MerchantId: ' . (string) setting('opay_merchant_id', ''),
        ],
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        opay_log('http_error', $path, $err);
        return ['ok' => false, 'code' => 'NETWORK', 'message' => $err ?: 'Request failed', 'data' => []];
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        opay_log('bad_response', $path, substr((string) $raw, 0, 400));
        return ['ok' => false, 'code' => (string) $http, 'message' => 'Unreadable response', 'data' => []];
    }

    $code = (string) ($decoded['code'] ?? '');
    opay_log('call', $path, $code . ' ' . (string) ($decoded['message'] ?? ''));

    return [
        // OPay signals success with code "00000".
        'ok'      => $code === '00000',
        'code'    => $code,
        'message' => (string) ($decoded['message'] ?? ''),
        'data'    => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
    ];
}

function opay_log(string $action, string $path, string $result): void
{
    q('INSERT INTO sync_log (action, payload, result) VALUES (?,?,?)',
        ['opay_' . $action, $path, substr($result, 0, 200)]);
}

// ---------------------------------------------------------------------
// operations
// ---------------------------------------------------------------------

/**
 * Ask OPay for a one-shot bank account for this order.
 *
 * NOTE: confirm the request field names against the live docs when your
 * merchant dashboard is open. The response fields used below are the ones
 * documented — transferAccountNumber, transferBankName, expiredTimestamp —
 * and those are what the rest of the system depends on.
 */
function opay_create_bank_transfer(array $tx, array $customer, array $plan, string $callbackUrl): array
{
    $res = opay_call('/api/v1/international/payment/create', [
        'country'     => 'NG',
        'reference'   => $tx['reference'],
        'amount'      => [
            // OPay takes the minor unit: kobo, not naira.
            'total'    => (int) round(((float) $tx['amount_naira']) * 100),
            'currency' => 'NGN',
        ],
        'payMethod'   => 'BankTransfer',
        'callbackUrl' => $callbackUrl,
        'product'     => [
            'name'        => substr((string) $plan['name'], 0, 60),
            'description' => substr((string) ($plan['subtitle'] ?? $plan['name']), 0, 120),
        ],
        'userInfo'    => [
            'userId'     => (string) $customer['id'],
            'userMobile' => (string) $customer['phone'],
            'userName'   => (string) ($customer['full_name'] ?: $customer['phone']),
        ],
    ]);

    if (!$res['ok']) {
        return $res;
    }

    $d = $res['data'];
    $res['account'] = [
        'account_no' => (string) ($d['transferAccountNumber'] ?? ''),
        'bank_name'  => (string) ($d['transferBankName'] ?? ''),
        'order_no'   => (string) ($d['orderNo'] ?? ''),
        'expires_at' => isset($d['expiredTimestamp'])
            ? date('Y-m-d H:i:s', (int) $d['expiredTimestamp'])
            : null,
    ];

    if ($res['account']['account_no'] === '') {
        $res['ok'] = false;
        $res['message'] = 'OPay did not return an account number';
    }

    return $res;
}

/**
 * Pull the truth for one order. Used by the reconciliation cron, because
 * callbacks get lost and a customer who paid but never got switched on is
 * the worst failure this system has.
 */
function opay_query_status(string $reference): array
{
    return opay_call('/api/v1/international/cashier/status', [
        'reference' => $reference,
        'country'   => 'NG',
    ]);
}
