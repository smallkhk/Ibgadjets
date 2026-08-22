<?php
/**
 * OPay signature tests.
 *
 * These matter because the two halves of the integration use DIFFERENT
 * algorithms, and the callback signature arrives in a field named
 * "sha512" that is not SHA-512. If someone later "fixes" that to look
 * consistent, these tests fail loudly instead of every callback being
 * silently rejected in production.
 *
 *   php tests/test-opay.php
 */

declare(strict_types=1);

// Stubs — no database, no HTTP.
function q($s, $a = []) { return null; }
function one($s, $a = []) { return null; }
function all($s, $a = []) { return []; }
function scalar($s, $a = []) { return null; }

$GLOBALS['STUB_SETTINGS'] = [
    'opay_secret_key'     => 'OPAYPRV0000000000000000000000000000',
    'opay_public_key'     => 'OPAYPUB0000000000000000000000000000',
    'opay_merchant_id'    => '256612345678901',
    'opay_enabled'        => '1',
    'opay_live'           => '0',
    'opay_auto_threshold' => '3000',
];
function setting(string $k, $d = null) { return $GLOBALS['STUB_SETTINGS'][$k] ?? $d; }
function setting_int(string $k, int $d = 0): int {
    $v = setting($k, null);
    return $v === null || $v === '' ? $d : (int) $v;
}

require __DIR__ . '/../private/lib/opay.php';

$fails = 0;
function is_eq($got, $want, $label) {
    global $fails;
    $ok = $got === $want;
    if (!$ok) { $fails++; }
    printf("%s %-46s %s\n", $ok ? 'ok  ' : 'FAIL', $label,
        $ok ? '' : ('got=' . var_export($got, true) . ' want=' . var_export($want, true)));
}

// A callback body in OPay's shape: lowercase keys, amount in kobo.
$payload = [
    'amount'        => '250000',
    'channel'       => 'BankTransfer',
    'country'       => 'NG',
    'currency'      => 'NGN',
    'reference'     => 'IB-4X9QM',
    'refunded'      => false,
    'status'        => 'SUCCESS',
    'timestamp'     => '2026-08-14T09:31:02Z',
    'token'         => '220507145820200226',
    'transactionId' => '220507145820200226',
];

// ---------------------------------------------------------------------
// The signed string must be rebuilt exactly, not taken from the body.
// ---------------------------------------------------------------------
$expectedSigned = '{Amount:"250000",Currency:"NGN",Reference:"IB-4X9QM",Refunded:f,'
    . 'Status:"SUCCESS",Timestamp:"2026-08-14T09:31:02Z",Token:"220507145820200226",'
    . 'TransactionID:"220507145820200226"}';

$expectedSig = hash_hmac('sha3-512', $expectedSigned, $GLOBALS['STUB_SETTINGS']['opay_secret_key']);

is_eq(opay_callback_signature($payload), $expectedSig, 'callback signature matches spec string');

// The algorithm is SHA3-512 despite the field being called sha512.
is_eq(
    opay_callback_signature($payload) === hash_hmac('sha512', $expectedSigned, $GLOBALS['STUB_SETTINGS']['opay_secret_key']),
    false,
    'callback is NOT plain sha512'
);

// ---------------------------------------------------------------------
// Verification
// ---------------------------------------------------------------------
is_eq(opay_verify_callback($payload, $expectedSig), true,  'valid signature accepted');
is_eq(opay_verify_callback($payload, strtoupper($expectedSig)), true, 'uppercase hex accepted');
is_eq(opay_verify_callback($payload, 'deadbeef'), false, 'forged signature rejected');
is_eq(opay_verify_callback($payload, ''), false, 'empty signature rejected');

$tampered = $payload;
$tampered['amount'] = '9999999';
is_eq(opay_verify_callback($tampered, $expectedSig), false, 'amount tampering rejected');

$tampered = $payload;
$tampered['status'] = 'SUCCESS ';
is_eq(opay_verify_callback($tampered, $expectedSig), false, 'status tampering rejected');

// ---------------------------------------------------------------------
// Key case and the t/f boolean
// ---------------------------------------------------------------------
$capitalised = [
    'Amount' => '250000', 'Currency' => 'NGN', 'Reference' => 'IB-4X9QM',
    'Refunded' => false, 'Status' => 'SUCCESS', 'Timestamp' => '2026-08-14T09:31:02Z',
    'Token' => '220507145820200226', 'TransactionID' => '220507145820200226',
];
is_eq(opay_callback_signature($capitalised), $expectedSig, 'key case does not matter');

$refunded = $payload;
$refunded['refunded'] = true;
is_eq(
    opay_callback_signature($refunded) === $expectedSig,
    false,
    'refunded flag changes the signature'
);

is_eq(opay_field($payload, 'TransactionID'), '220507145820200226', 'field lookup ignores case');
is_eq(opay_field($payload, 'Missing'), '', 'missing field is empty');

// ---------------------------------------------------------------------
// Request signing is the OTHER algorithm
// ---------------------------------------------------------------------
$body = '{"reference":"IB-4X9QM","country":"NG"}';
is_eq(
    opay_sign_request($body),
    hash_hmac('sha512', $body, $GLOBALS['STUB_SETTINGS']['opay_secret_key']),
    'requests sign with plain sha512'
);

// ---------------------------------------------------------------------
// The price threshold — which sale costs a fee and which does not
// ---------------------------------------------------------------------
is_eq(opay_use_auto(2500.0), false, 'below threshold stays manual');
is_eq(opay_use_auto(3000.0), true,  'at threshold goes automatic');
is_eq(opay_use_auto(25000.0), true, 'above threshold goes automatic');

$GLOBALS['STUB_SETTINGS']['opay_auto_threshold'] = '0';
is_eq(opay_use_auto(25000.0), false, 'threshold 0 disables the automatic path');

$GLOBALS['STUB_SETTINGS']['opay_auto_threshold'] = '3000';
$GLOBALS['STUB_SETTINGS']['opay_enabled'] = '0';
is_eq(opay_use_auto(25000.0), false, 'disabled OPay never goes automatic');

$GLOBALS['STUB_SETTINGS']['opay_enabled'] = '1';
$GLOBALS['STUB_SETTINGS']['opay_secret_key'] = '';
is_eq(opay_use_auto(25000.0), false, 'missing secret key never goes automatic');

// ---------------------------------------------------------------------
is_eq(opay_base(), OPAY_TEST, 'staging by default');
$GLOBALS['STUB_SETTINGS']['opay_live'] = '1';
is_eq(opay_base(), OPAY_LIVE, 'live when switched on');

echo $fails ? "\n{$fails} FAILURES\n" : "\nall green\n";
exit($fails ? 1 : 0);
