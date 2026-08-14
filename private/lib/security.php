<?php
/**
 * Sessions, CSRF, rate limiting, input normalisation, auth guards.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// session
// ---------------------------------------------------------------------

function session_boot(array $cfg): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name($cfg['session_name'] ?? 'ibg_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (bool) ($cfg['session_secure'] ?? true),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ?? '';
}

/**
 * Enforced on every state-changing request that rides on a cookie
 * session. The router sync endpoint is exempt — it authenticates with a
 * bearer secret and carries no cookie, so CSRF does not apply there.
 */
function csrf_check(): void
{
    if (method() === 'GET') {
        return;
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (string) input('csrf', '');
    if (!hash_equals((string) csrf_token(), (string) $sent)) {
        fail('Session expired, reload the page', 419);
    }
}

// ---------------------------------------------------------------------
// rate limiting
// ---------------------------------------------------------------------

/**
 * Sliding window counter kept in MySQL — shared hosting has no APCu or
 * Redis to lean on.
 */
function rate_limit(string $bucket, int $max, int $seconds): void
{
    $bucket  = substr($bucket, 0, 80);
    // Interpolated, not bound: MySQL will not take a placeholder inside
    // INTERVAL on every version. It is an int we cast ourselves, so there
    // is nothing for a caller to inject.
    $seconds = max(1, (int) $seconds);

    $hits = (int) scalar(
        "SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND hit_at > (NOW() - INTERVAL {$seconds} SECOND)",
        [$bucket]
    );

    if ($hits >= $max) {
        fail('Too many attempts. Wait a minute and try again.', 429);
    }

    q('INSERT INTO rate_limits (bucket) VALUES (?)', [$bucket]);
}

// ---------------------------------------------------------------------
// input normalisation
// ---------------------------------------------------------------------

/**
 * Nigerian mobile numbers, stored one way only: 11 digits, leading zero.
 * Accepts 0803…, 234803…, +234803…, 803…
 */
function normalize_phone(string $raw): ?string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';

    if (str_starts_with($d, '234') && strlen($d) === 13) {
        $d = '0' . substr($d, 3);
    } elseif (strlen($d) === 10 && $d[0] !== '0') {
        $d = '0' . $d;
    }

    if (!preg_match('/^0[789][01]\d{8}$/', $d)) {
        return null;
    }
    return $d;
}

function normalize_mac(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $h = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $raw) ?? '');
    if (strlen($h) !== 12) {
        return null;
    }
    return implode(':', str_split($h, 2));
}

// ---------------------------------------------------------------------
// passwords
// ---------------------------------------------------------------------

function hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_BCRYPT);
}

function check_password(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

/** Short, unambiguous, readable over a phone call — no O/0 or I/l/1. */
function random_code(int $len = 6): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

// ---------------------------------------------------------------------
// guards
// ---------------------------------------------------------------------

function current_customer(): ?array
{
    if (empty($_SESSION['customer_id'])) {
        return null;
    }
    return one('SELECT * FROM customers WHERE id = ?', [(int) $_SESSION['customer_id']]);
}

function require_customer(): array
{
    $c = current_customer();
    if (!$c) {
        fail('Please log in', 401);
    }
    if ($c['status'] === 'suspended') {
        fail('This account is suspended. Contact support.', 403);
    }
    return $c;
}

function current_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    return one('SELECT id, email, role FROM admins WHERE id = ?', [(int) $_SESSION['admin_id']]);
}

function require_admin(): array
{
    $a = current_admin();
    if (!$a) {
        fail('Admin login required', 401);
    }
    return $a;
}

function require_owner(): array
{
    $a = require_admin();
    if ($a['role'] !== 'owner') {
        fail('Owner only', 403);
    }
    return $a;
}

/**
 * The router's credential. Constant-time compare, and a hard rate limit
 * so a leaked endpoint URL cannot be brute-forced.
 */
function require_sync_key(string $expected): void
{
    $sent = $_SERVER['HTTP_X_SYNC_KEY'] ?? '';

    rate_limit('sync:' . client_ip(), 240, 60);

    if ($expected === '' || $expected === 'CHANGE-ME-64-hex-characters') {
        fail('Sync key not configured', 500);
    }
    if (!is_string($sent) || !hash_equals($expected, $sent)) {
        q('INSERT INTO sync_log (action, payload, result) VALUES (?,?,?)',
            ['auth_fail', client_ip(), 'bad or missing X-Sync-Key']);
        fail('Forbidden', 403);
    }
}
