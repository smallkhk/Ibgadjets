<?php
/**
 * Health snapshot from the command line.
 *
 *   php ~/private/status.php
 *
 * Answers the questions that actually come up when something looks
 * wrong: is the router talking to us, is usage being recorded, is anyone
 * suspended who should not still be online, and does the trial look sane.
 *
 * Read-only. It changes nothing, so it is always safe to run.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/bootstrap.php';

function heading(string $s): void { echo "\n", $s, "\n", str_repeat('-', strlen($s)), "\n"; }
function yn(bool $b): string { return $b ? 'yes' : 'NO'; }

// ---------------------------------------------------------------------
heading('Router');

$last = scalar("SELECT MAX(created_at) FROM sync_log WHERE action IN ('desired','report')");
if ($last === null) {
    echo "  The router has NEVER called in.\n";
    echo "  Check the sync key and that the scheduler is enabled.\n";
} else {
    $ago = time() - strtotime((string) $last);
    printf("  Last call: %s (%d seconds ago)%s\n", $last, $ago,
        $ago > 180 ? '   <-- STALE, the router is not reporting' : '');
}

$reports = (int) scalar("SELECT COUNT(*) FROM sync_log WHERE action='report' AND created_at > (NOW() - INTERVAL 10 MINUTE)");
printf("  Usage reports in the last 10 minutes: %d%s\n", $reports,
    $reports === 0 ? '   <-- the router is not POSTing usage' : '');

// ---------------------------------------------------------------------
heading('Live bundles');

$rows = all(
    "SELECT s.id, c.phone, c.status AS customer_status, p.name AS plan,
            s.data_used_mb, p.data_mb, s.sync_state, s.expires_at
       FROM subscriptions s
       JOIN customers c ON c.id = s.customer_id
       JOIN plans p     ON p.id = s.plan_id
      WHERE s.status = 'active' AND s.expires_at > NOW()
      ORDER BY s.id DESC LIMIT 20");

if (!$rows) {
    echo "  Nobody has an active bundle.\n";
} else {
    printf("  %-13s %-9s %-16s %10s %8s\n", 'PHONE', 'CUSTOMER', 'PLAN', 'USED/MB', 'SYNC');
    foreach ($rows as $r) {
        printf("  %-13s %-9s %-16s %10s %8s%s\n",
            $r['phone'],
            $r['customer_status'],
            substr((string) $r['plan'], 0, 16),
            rtrim(rtrim((string) $r['data_used_mb'], '0'), '.') ?: '0',
            $r['sync_state'],
            // Suspended people must not hold a live bundle: the sync
            // endpoint filters on customer status, so if this ever
            // prints, the site is telling the router to keep them.
            $r['customer_status'] === 'suspended' ? '   <-- SUSPENDED BUT STILL LISTED' : '');
    }

    $zero = 0;
    foreach ($rows as $r) { if ((float) $r['data_used_mb'] == 0.0) { $zero++; } }
    if ($zero === count($rows)) {
        echo "\n  Every bundle reads 0 MB. Either nobody has browsed yet, or the\n";
        echo "  router's usage POST is not reaching the site.\n";
    }

    $pending = 0;
    foreach ($rows as $r) { if ($r['sync_state'] !== 'synced') { $pending++; } }
    if ($pending) {
        echo "\n  $pending bundle(s) not yet confirmed by the router. Usage is only\n";
        echo "  recorded once a bundle is confirmed, so these will read 0 until it is.\n";
    }
}

// ---------------------------------------------------------------------
heading('What the router is being told right now');

$desired = all(
    "SELECT c.phone, c.status
       FROM subscriptions s
       JOIN customers c ON c.id = s.customer_id
      WHERE s.status = 'active' AND s.expires_at > NOW() AND c.status = 'active'");

echo '  ', count($desired), " account(s) should be able to browse:\n";
foreach ($desired as $d) { echo '    ib', $d['phone'], "\n"; }
echo "\n  Anyone browsing who is NOT on this list is either on a hotspot\n";
echo "  account the sync does not own, or holding a session that outlived\n";
echo "  its account. /ip hotspot active print on the router will say which.\n";

// ---------------------------------------------------------------------
heading('Free trial');

$plan = one('SELECT * FROM plans WHERE is_trial = 1 ORDER BY id LIMIT 1');
printf("  Switched on: %s\n", yn(setting('trial_enabled', '0') === '1'));
printf("  Trial plan:  %s\n", $plan ? $plan['name'] . ' (' . (int) $plan['data_mb'] . ' MB)' : 'MISSING — run migration 004');
printf("  Claimed:     %d ever, %d running now\n",
    (int) scalar('SELECT COUNT(*) FROM customers WHERE trial_claimed_at IS NOT NULL'),
    (int) scalar("SELECT COUNT(*) FROM subscriptions s JOIN plans p ON p.id = s.plan_id
                   WHERE p.is_trial = 1 AND s.status = 'active' AND s.expires_at > NOW()"));

// ---------------------------------------------------------------------
heading('Settings that stop you being paid');

$bank = setting('bank_account_no', '');
$name = setting('bank_name', '');
printf("  Bank account: %s%s\n", $bank ?: '(empty)',
    ($bank === '0000000000' || $bank === '') ? '   <-- still the placeholder' : '');
printf("  Bank name:    %s%s\n", $name ?: '(empty)',
    str_contains(strtolower($name), 'change me') ? '   <-- still the placeholder' : '');

echo "\n";
