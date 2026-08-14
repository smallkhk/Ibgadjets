<?php
declare(strict_types=1);
// Stub the DB layer so the pure helpers can be exercised without MySQL.
function q($s,$a=[]){ return null; } function one($s,$a=[]){ return null; }
function all($s,$a=[]){ return []; } function scalar($s,$a=[]){ return null; }
function last_id(){ return 1; } function tx_begin(){} function tx_commit(){} function tx_rollback(){}
require __DIR__ . '/../private/lib/http.php';
require __DIR__ . '/../private/lib/security.php';
require __DIR__ . '/../private/lib/billing.php';

$fails = 0;
function is_eq($got, $want, $label) {
    global $fails;
    $ok = $got === $want;
    if (!$ok) { $fails++; }
    printf("%s %-42s got=%s want=%s\n", $ok ? 'ok  ' : 'FAIL', $label,
        var_export($got, true), var_export($want, true));
}

// --- phone normalisation ---------------------------------------------
is_eq(normalize_phone('08031112222'),      '08031112222', 'plain 0803');
is_eq(normalize_phone('0803 111 2222'),    '08031112222', 'spaced');
is_eq(normalize_phone('+2348031112222'),   '08031112222', '+234 form');
is_eq(normalize_phone('2348031112222'),    '08031112222', '234 form');
is_eq(normalize_phone('8031112222'),       '08031112222', 'missing leading zero');
is_eq(normalize_phone('0703-111-2222'),    '07031112222', '0703 dashed');
is_eq(normalize_phone('09011112222'),      '09011112222', '0901');
is_eq(normalize_phone('12345'),            null,          'too short');
is_eq(normalize_phone('06031112222'),      null,          'bad prefix 060');
is_eq(normalize_phone('080311122223'),     null,          'too long');

// --- mac normalisation -----------------------------------------------
is_eq(normalize_mac('aa:bb:cc:dd:ee:ff'), 'AA:BB:CC:DD:EE:FF', 'mac lower colon');
is_eq(normalize_mac('AA-BB-CC-DD-EE-FF'), 'AA:BB:CC:DD:EE:FF', 'mac dashed');
is_eq(normalize_mac('AABBCCDDEEFF'),      'AA:BB:CC:DD:EE:FF', 'mac bare');
is_eq(normalize_mac('nonsense'),          null,                'mac junk');
is_eq(normalize_mac(null),                null,                'mac null');

// --- display formatting ----------------------------------------------
is_eq(format_data(null),   'Unlimited', 'data unlimited');
is_eq(format_data(500),    '500 MB',    'data 500MB');
is_eq(format_data(2048),   '2 GB',      'data 2GB');
is_eq(format_data(5120),   '5 GB',      'data 5GB');
is_eq(format_data(15360),  '15 GB',     'data 15GB');
is_eq(format_data(102400), '100 GB',    'data 100GB');

is_eq(format_validity(24),   '1 day',    'validity 24h');
is_eq(format_validity(168),  '7 days',   'validity 7d');
is_eq(format_validity(720),  '30 days',  'validity 30d');
is_eq(format_validity(2160), '90 days',  'validity 90d');
is_eq(format_validity(1),    '1 hour',   'validity 1h');

// --- router rate limit string ----------------------------------------
is_eq(rate_limit_string(['speed_down_mbps'=>30,'speed_up_mbps'=>8]), '30M/8M', 'rate limit');

// --- device allowance: the admin override ----------------------------
is_eq(device_allowance(['device_limit'=>null], ['max_devices'=>2]), 2, 'null -> plan default');
is_eq(device_allowance(['device_limit'=>'' ], ['max_devices'=>4]), 4, 'empty -> plan default');
is_eq(device_allowance(['device_limit'=>3  ], ['max_devices'=>1]), 3, 'override beats plan');
is_eq(device_allowance(['device_limit'=>0  ], ['max_devices'=>1]), 1, 'clamped to >= 1');
is_eq(device_allowance(['device_limit'=>99 ], ['max_devices'=>1]), 16, 'clamped to <= 16');

// --- random code alphabet is unambiguous over the phone ---------------
$code = random_code(200);
is_eq((bool) preg_match('/^[a-z2-9]+$/', $code), true, 'code charset');
is_eq(strpbrk($code, 'oil01') === false, true, 'code avoids o i l 0 1');

echo $fails ? "\n{$fails} FAILURES\n" : "\nall green\n";
exit($fails ? 1 : 0);
