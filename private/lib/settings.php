<?php
/**
 * key/value settings with a per-request cache.
 */

declare(strict_types=1);

function settings_all(bool $fresh = false): array
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (all('SELECT k, v FROM settings') as $row) {
            $cache[$row['k']] = $row['v'];
        }
    }
    return $cache;
}

function setting(string $key, $default = null)
{
    $s = settings_all();
    return array_key_exists($key, $s) ? $s[$key] : $default;
}

function setting_int(string $key, int $default = 0): int
{
    $v = setting($key, null);
    return $v === null || $v === '' ? $default : (int) $v;
}

function setting_set(string $key, ?string $value): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, $value]);
    settings_all(true);
}

/** The subset the public site is allowed to see. Never leak keys/secrets. */
function public_settings(): array
{
    return [
        'business_name'     => setting('business_name', 'IB Gadgets Telecom'),
        'support_phone'     => setting('support_phone', ''),
        'support_whatsapp'  => setting('support_whatsapp', ''),
        'bank_name'         => setting('bank_name', ''),
        'bank_account_name' => setting('bank_account_name', ''),
        'bank_account_no'   => setting('bank_account_no', ''),
        'bank_note'         => setting('bank_note', ''),
        'ngn_per_usdt'      => (float) setting('ngn_per_usdt', '1650'),
        'wallet_bsc'        => setting('wallet_bsc', ''),
        'wallet_tron'       => setting('wallet_tron', ''),
        'opay_enabled'      => setting_int('opay_enabled', 0) === 1,
    ];
}
