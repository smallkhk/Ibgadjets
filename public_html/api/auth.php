<?php
/**
 * api/auth.php?action=signup|login|logout|me
 *
 * The login identifier is the Nigerian phone number. No email anywhere
 * in this flow.
 */

declare(strict_types=1);
require __DIR__ . '/../../private/bootstrap.php';

$action = (string) input('action', 'me');

switch ($action) {

    // -----------------------------------------------------------------
    case 'signup':
        require_method('POST');
        csrf_check();
        rate_limit('signup:' . client_ip(), 5, 600);

        $phone = normalize_phone((string) want('phone'));
        if (!$phone) {
            fail('That does not look like a Nigerian phone number', 422);
        }

        $password = (string) want('password');
        if (strlen($password) < 6) {
            fail('Password must be at least 6 characters', 422);
        }

        $name = trim((string) input('full_name', ''));
        $type = input('type') === 'visitor' ? 'visitor' : 'compound';
        $flat = $type === 'compound' ? trim((string) input('flat_no', '')) : null;

        if ($type === 'compound' && $flat === '') {
            fail('Flat or house number is required for compound accounts', 422);
        }

        if (scalar('SELECT 1 FROM customers WHERE phone = ?', [$phone])) {
            fail('That number already has an account. Log in instead.', 409);
        }

        q('INSERT INTO customers (phone, full_name, password_hash, type, flat_no, router_username, router_password)
           VALUES (?,?,?,?,?,?,?)',
            [$phone, $name ?: null, hash_password($password), $type, $flat ?: null, $phone, random_code(6)]);

        $_SESSION['customer_id'] = last_id();
        session_regenerate_id(true);

        ok(['customer' => customer_public(current_customer())]);

    // -----------------------------------------------------------------
    case 'login':
        require_method('POST');
        csrf_check();

        $phone = normalize_phone((string) want('phone'));
        if (!$phone) {
            fail('Check the phone number', 422);
        }

        // Two buckets: one slows down a single targeted account, the
        // other slows down someone spraying many numbers from one IP.
        rate_limit('login:' . $phone, 6, 900);
        rate_limit('loginip:' . client_ip(), 25, 900);

        $c = one('SELECT * FROM customers WHERE phone = ?', [$phone]);

        // Same message either way — never confirm which numbers exist.
        if (!$c || !check_password((string) want('password'), $c['password_hash'])) {
            fail('Wrong phone number or password', 401);
        }
        if ($c['status'] === 'suspended') {
            fail('This account is suspended. Contact support.', 403);
        }

        session_regenerate_id(true);
        $_SESSION['customer_id'] = (int) $c['id'];

        ok(['customer' => customer_public($c)]);

    // -----------------------------------------------------------------
    case 'logout':
        require_method('POST');
        csrf_check();
        unset($_SESSION['customer_id']);
        session_regenerate_id(true);
        ok();

    // -----------------------------------------------------------------
    case 'me':
        $c = current_customer();
        ok([
            'authenticated' => (bool) $c,
            'customer'      => $c ? customer_public($c) : null,
            'csrf'          => csrf_token(),
        ]);

    // -----------------------------------------------------------------
    default:
        fail('Unknown action', 404);
}

/** Everything the browser is allowed to know about the logged-in person. */
function customer_public(array $c): array
{
    return [
        'id'        => (int) $c['id'],
        'phone'     => $c['phone'],
        'full_name' => $c['full_name'],
        'type'      => $c['type'],
        'flat_no'   => $c['flat_no'],
        'wallet'    => (float) $c['wallet_naira'],
        'status'    => $c['status'],
        // Shown on the dashboard so they know what to type at the hotspot.
        'wifi_username' => $c['router_username'],
        'wifi_password' => $c['router_password'],
    ];
}
