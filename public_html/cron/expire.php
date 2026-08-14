<?php
/**
 * cron/expire.php — housekeeping sweep.
 *
 * cPanel cron, hourly:
 *   /usr/local/bin/php /home/USER/public_html/cron/expire.php
 *
 * Expiry does not actually depend on this running. The sync endpoint
 * only ever lists subscriptions that are still inside their window, so a
 * lapsed plan stops working within one poll whether or not the cron
 * fired. This job keeps the database tidy and the reporting honest.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !isset($_GET['key'])) {
    http_response_code(403);
    exit('CLI only');
}

define('IBG_NO_SESSION', true);
require __DIR__ . '/../../private/bootstrap.php';

// Web-triggered runs (cPanel "cron via URL") must present the sync key.
if (PHP_SAPI !== 'cli') {
    if (!hash_equals((string) $CONFIG['sync_key'], (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$report = [];

// 1. Time is up.
$st = q("UPDATE subscriptions
            SET status = 'expired', sync_state = 'revoke_pending'
          WHERE status = 'active' AND expires_at <= NOW()");
$report['expired_by_time'] = $st->rowCount();

// 2. Bundle spent. Unlimited plans (data_mb NULL) are never caught here.
$st = q("UPDATE subscriptions s
           JOIN plans p ON p.id = s.plan_id
            SET s.status = 'expired', s.sync_state = 'revoke_pending'
          WHERE s.status = 'active'
            AND p.data_mb IS NOT NULL
            AND s.data_used_mb >= p.data_mb");
$report['expired_by_data'] = $st->rowCount();

// 3. Stale live sessions — the router stopped reporting them.
$st = q('DELETE FROM sessions WHERE last_seen < (NOW() - INTERVAL 1 HOUR)');
$report['sessions_cleared'] = $st->rowCount();

// 4. Rate limit counters.
$st = q('DELETE FROM rate_limits WHERE hit_at < (NOW() - INTERVAL 1 DAY)');
$report['rate_limits_cleared'] = $st->rowCount();

// 5. Sync log — keep a month.
$st = q('DELETE FROM sync_log WHERE created_at < (NOW() - INTERVAL 30 DAY)');
$report['sync_log_trimmed'] = $st->rowCount();

// 6. Unpaid transactions older than 48h are dead weight in the queue.
$st = q("UPDATE transactions
            SET status = 'failed', note = 'expired without payment'
          WHERE status = 'pending'
            AND proof_path IS NULL AND tx_hash IS NULL
            AND created_at < (NOW() - INTERVAL 48 HOUR)");
$report['abandoned_transactions'] = $st->rowCount();

q('INSERT INTO sync_log (action, payload, result) VALUES (?,?,?)',
    ['cron_expire', json_encode($report), 'ok']);

if (PHP_SAPI === 'cli') {
    foreach ($report as $k => $v) {
        printf("%-24s %d\n", $k, $v);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true] + $report);
}
