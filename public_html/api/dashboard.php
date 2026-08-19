<?php
/**
 * api/dashboard.php — everything the customer's account screen shows.
 */

declare(strict_types=1);
require __DIR__ . '/../../private/bootstrap.php';
require_method('GET');

$c = require_customer();

$sub = one(
    "SELECT s.*, p.name AS plan_name, p.data_mb, p.speed_down_mbps, p.speed_up_mbps,
            p.max_devices, p.tier, p.audience
       FROM subscriptions s
       JOIN plans p ON p.id = s.plan_id
      WHERE s.customer_id = ? AND s.status = 'active' AND s.expires_at > NOW()
      ORDER BY s.expires_at DESC LIMIT 1",
    [$c['id']]
);

$current = null;
if ($sub) {
    $usedMb  = (float) $sub['data_used_mb'];
    $totalMb = $sub['data_mb'] === null ? null : (int) $sub['data_mb'];
    $left    = $totalMb === null ? null : max(0, $totalMb - (int) round($usedMb));

    $current = [
        'subscription_id' => (int) $sub['id'],
        'plan_name'       => $sub['plan_name'],
        'tier'            => (int) $sub['tier'],
        'speed'           => (int) $sub['speed_down_mbps'] . ' Mbps',
        'rate_limit'      => sprintf('%dM/%dM', (int) $sub['speed_down_mbps'], (int) $sub['speed_up_mbps']),
        'unlimited'       => $totalMb === null,
        'data_total_mb'   => $totalMb,
        'data_used_mb'    => round($usedMb, 1),
        'data_left_mb'    => $left,
        'data_total_text' => format_data($totalMb),
        'data_used_text'  => format_data((int) round($usedMb)),
        'data_left_text'  => $left === null ? 'Unlimited' : format_data($left),
        'percent_used'    => $totalMb ? min(100, round($usedMb / $totalMb * 100, 1)) : null,
        'expires_at'      => $sub['expires_at'],
        'seconds_left'    => max(0, strtotime($sub['expires_at']) - time()),
        'device_limit'    => (int) $sub['device_limit'],
        // 'pending' here means the router has not picked it up yet. It
        // polls every 60 seconds, so this clears on its own.
        'live_on_router'  => $sub['sync_state'] === 'synced',
    ];
}

$devices = all(
    'SELECT mac, label, last_seen, blocked FROM devices WHERE customer_id = ? ORDER BY last_seen DESC',
    [$c['id']]
);

$history = all(
    "SELECT t.reference, t.amount_naira, t.method, t.status, t.created_at, p.name AS plan_name
       FROM transactions t
       LEFT JOIN plans p ON p.id = t.plan_id
      WHERE t.customer_id = ?
      ORDER BY t.id DESC LIMIT 25",
    [$c['id']]
);

ok([
    'customer' => [
        'phone'         => $c['phone'],
        'full_name'     => $c['full_name'],
        'type'          => $c['type'],
        'flat_no'       => $c['flat_no'],
        'wallet'        => (float) $c['wallet_naira'],
        'wifi_username' => $c['router_username'],
        'wifi_password' => $c['router_password'],
        'device_limit'  => $c['device_limit'] === null ? null : (int) $c['device_limit'],
        // Drives the "you cannot recover this account" prompt. The
        // question text is not sent: someone glancing at a logged-in
        // dashboard should not walk away with half the answer.
        'has_security_question' => ($c['security_answer_hash'] ?? null) !== null,
    ],
    'current'      => $current,
    'devices'      => $devices,
    'device_count' => count($devices),
    'history'      => $history,
]);
