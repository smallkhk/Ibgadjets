<?php
/**
 * The activation path.
 *
 * Every payment method — bank transfer, OPay, USDT, wallet, or an admin
 * typing it in by hand — ends in activate_subscription(). There is one
 * road in, so there is one thing to test.
 */

declare(strict_types=1);

/**
 * How many simultaneous devices this person may run.
 * customers.device_limit is the admin override; NULL falls back to what
 * the plan sells. This is the number that becomes shared-users on the
 * User Manager account.
 */
function device_allowance(array $customer, array $plan): int
{
    $override = $customer['device_limit'];
    $n = ($override === null || $override === '') ? (int) $plan['max_devices'] : (int) $override;
    return max(1, min(16, $n));
}

/** Make sure the customer has router credentials before we provision. */
function ensure_router_identity(array $customer): array
{
    if (!empty($customer['router_username']) && !empty($customer['router_password'])) {
        return $customer;
    }
    $username = $customer['phone'];
    $password = random_code(6);

    q('UPDATE customers SET router_username = ?, router_password = ? WHERE id = ?',
        [$username, $password, $customer['id']]);

    $customer['router_username'] = $username;
    $customer['router_password'] = $password;
    return $customer;
}

/**
 * Human-readable, unique, and short enough to type into a bank transfer
 * narration on a feature phone.
 */
function make_reference(string $prefix = 'IB'): string
{
    for ($try = 0; $try < 12; $try++) {
        $ref = $prefix . '-' . strtoupper(random_code(5));
        $taken = scalar('SELECT 1 FROM transactions WHERE reference = ?', [$ref]);
        if (!$taken) {
            return $ref;
        }
    }
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(5)));
}

function get_plan(int $planId): ?array
{
    return one('SELECT * FROM plans WHERE id = ? AND active = 1', [$planId]);
}

/**
 * Create the live subscription and hand it to the router.
 *
 * Stacking rule: buying while a plan is still running does not throw the
 * remaining time away. The new expiry is (whichever is later of now and
 * the current expiry) + the new validity, and the old row is closed.
 * Data does not stack — the new bundle replaces the old allowance.
 *
 * @return int new subscription id
 */
function activate_subscription(int $customerId, int $planId, ?int $transactionId = null): int
{
    $customer = one('SELECT * FROM customers WHERE id = ?', [$customerId]);
    if (!$customer) {
        throw new RuntimeException('Unknown customer');
    }
    $plan = get_plan($planId);
    if (!$plan) {
        throw new RuntimeException('Unknown or inactive plan');
    }

    $customer = ensure_router_identity($customer);
    $devices  = device_allowance($customer, $plan);

    $current = one(
        "SELECT * FROM subscriptions
          WHERE customer_id = ? AND status = 'active' AND expires_at > NOW()
          ORDER BY expires_at DESC LIMIT 1",
        [$customerId]
    );

    $base = ($current && strtotime($current['expires_at']) > time())
        ? strtotime($current['expires_at'])
        : time();

    $expires = date('Y-m-d H:i:s', $base + ((int) $plan['validity_hours'] * 3600));

    if ($current) {
        // Superseded, not revoked — the replacement row carries the time forward.
        q("UPDATE subscriptions
              SET status = 'cancelled', sync_state = 'revoke_pending'
            WHERE id = ?", [$current['id']]);
    }

    q("INSERT INTO subscriptions
          (customer_id, plan_id, expires_at, device_limit, status, sync_state)
       VALUES (?, ?, ?, ?, 'active', 'pending')",
        [$customerId, $planId, $expires, $devices]);

    $subId = last_id();

    if ($transactionId) {
        q("UPDATE transactions SET status = 'success', plan_id = ? WHERE id = ?", [$planId, $transactionId]);
    }

    q('INSERT INTO sync_log (action, payload, result) VALUES (?,?,?)', [
        'activate',
        json_encode(['sub' => $subId, 'customer' => $customerId, 'plan' => $planId, 'expires' => $expires]),
        'queued for router',
    ]);

    return $subId;
}

/** MB -> "5 GB" for display. NULL is unlimited. */
function format_data(?int $mb): string
{
    if ($mb === null) {
        return 'Unlimited';
    }
    if ($mb >= 1024) {
        $gb = $mb / 1024;
        return rtrim(rtrim(number_format($gb, 1, '.', ''), '0'), '.') . ' GB';
    }
    return $mb . ' MB';
}

/** Hours -> "24 hours" / "7 days". */
function format_validity(int $hours): string
{
    if ($hours % 24 === 0) {
        $d = intdiv($hours, 24);
        return $d === 1 ? '1 day' : "{$d} days";
    }
    return $hours === 1 ? '1 hour' : "{$hours} hours";
}

function format_naira(float $n): string
{
    return '₦' . number_format($n, 0, '.', ',');
}

/** RouterOS native rate limit string, e.g. "20M/5M" (down/up). */
function rate_limit_string(array $plan): string
{
    return sprintf('%dM/%dM', (int) $plan['speed_down_mbps'], (int) $plan['speed_up_mbps']);
}

/** Shape a plan row for the public API / the pricing grid. */
function plan_public(array $p): array
{
    return [
        'id'       => (int) $p['id'],
        'aud'      => $p['audience'],
        'name'     => $p['name'],
        'sub'      => $p['subtitle'],
        'price'    => (float) $p['price_naira'],
        'data'     => format_data($p['data_mb'] === null ? null : (int) $p['data_mb']),
        'data_mb'  => $p['data_mb'] === null ? null : (int) $p['data_mb'],
        'speed'    => (int) $p['speed_down_mbps'] . ' Mbps',
        'speed_up' => (int) $p['speed_up_mbps'] . ' Mbps',
        'valid'    => format_validity((int) $p['validity_hours']),
        'devices'  => ((int) $p['max_devices']) === 1 ? '1 device' : (int) $p['max_devices'] . ' devices',
        'tier'     => (int) $p['tier'],
        'featured' => (bool) $p['featured'],
    ];
}
