<?php
/**
 * api/plans.php — the pricing grid, plus the public settings the
 * checkout screen needs (bank details, USDT wallets, rate).
 */

declare(strict_types=1);
require __DIR__ . '/../../private/bootstrap.php';
require_method('GET');

$rows = all(
    'SELECT * FROM plans WHERE active = 1 ORDER BY audience, sort_order, price_naira'
);

ok([
    'plans'    => array_map('plan_public', $rows),
    'settings' => public_settings(),
    'notes'    => [
        'compound' => 'For people living inside the compound. Short cycles, top speed, and a permanent account tied to your flat. Your device is registered once — no re-entering details every day.',
        'visitor'  => "For anyone outside the compound. Big data that doesn't expire at midnight — buy once and spend it across weeks whenever you're nearby.",
    ],
    'live_count' => live_count(),
]);

/**
 * "Online now" on the homepage. Real number of sessions the router
 * reported in the last five minutes, held above a floor so the page
 * never looks dead while the dish is up.
 */
function live_count(): int
{
    $n = (int) scalar('SELECT COUNT(*) FROM sessions WHERE last_seen > (NOW() - INTERVAL 5 MINUTE)');
    return max($n, setting_int('live_count_floor', 0));
}
