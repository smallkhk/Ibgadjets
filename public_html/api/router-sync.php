<?php
/**
 * api/router-sync.php — the only bridge between the site and the router.
 *
 * Starlink is CGNAT, so nothing can dial into the box. Every exchange is
 * router -> site. The router polls this endpoint on a 60s scheduler.
 *
 * PROTOCOL: desired state, not deltas.
 *
 *   GET  -> "here is every account that should be able to browse right now"
 *   POST -> "here is what I applied, and here is what everyone has used"
 *
 * The router reconciles: create what is missing, update what drifted,
 * remove anything not on the list. That is idempotent by construction, so
 * a lost reply, a reboot mid-cycle or a factory reset all heal on the
 * next poll instead of leaving the two sides silently disagreeing.
 *
 * Auth is the X-Sync-Key header. No cookie, so no CSRF applies.
 */

declare(strict_types=1);

define('IBG_NO_SESSION', true);
require __DIR__ . '/../../private/bootstrap.php';

require_sync_key((string) ($CONFIG['sync_key'] ?? ''));

if (method() === 'GET') {
    send_desired_state();
}
if (method() === 'POST') {
    receive_report();
}
fail('Method not allowed', 405);


// =====================================================================
// GET — desired state
// =====================================================================

function send_desired_state(): never
{
    // Anything paid-for and still inside its window. Expiry is wall-clock
    // and the server owns it: when the time passes, the row stops being
    // listed and the router deletes the account on its next pass.
    $rows = all(
        "SELECT s.id AS sub_id,
                s.expires_at,
                s.data_used_mb,
                s.device_limit,
                c.id   AS customer_id,
                c.router_username,
                c.router_password,
                c.status AS customer_status,
                p.data_mb,
                p.speed_down_mbps,
                p.speed_up_mbps,
                p.name AS plan_name
           FROM subscriptions s
           JOIN customers c ON c.id = s.customer_id
           JOIN plans     p ON p.id = s.plan_id
          WHERE s.status = 'active'
            AND s.expires_at > NOW()
            AND c.status = 'active'
          ORDER BY s.id"
    );

    $users = [];
    foreach ($rows as $r) {
        if (empty($r['router_username']) || empty($r['router_password'])) {
            continue;
        }

        // Send what is LEFT, never the original allowance. This is what
        // makes a router wipe survivable: re-provisioning from scratch
        // restores the remaining balance instead of handing out the whole
        // bundle again.
        $remaining = null;
        if ($r['data_mb'] !== null) {
            $remaining = (int) max(0, (int) $r['data_mb'] - (int) round((float) $r['data_used_mb']));
            if ($remaining === 0) {
                continue;   // spent — drop them off the list
            }
        }

        $users[] = [
            'username'     => $r['router_username'],
            'password'     => $r['router_password'],
            'sub_id'       => (int) $r['sub_id'],
            'data_mb'      => $remaining,                       // null = unlimited
            'rate_limit'   => sprintf('%dM/%dM', (int) $r['speed_down_mbps'], (int) $r['speed_up_mbps']),
            'shared_users' => max(1, (int) $r['device_limit']), // simultaneous devices
            'seconds_left' => max(0, strtotime($r['expires_at']) - time()),
        ];
    }

    // Individual phones an admin has killed without touching the account.
    $blocked = array_column(
        all('SELECT mac FROM devices WHERE blocked = 1 AND mac IS NOT NULL'),
        'mac'
    );

    ok([
        'server_time'   => date('c'),
        'poll_seconds'  => 60,
        'tether_policy' => setting('tether_policy', 'flag'),
        'users'         => $users,
        'blocked_macs'  => $blocked,
    ]);
}


// =====================================================================
// POST — the router reports back
// =====================================================================

function receive_report(): never
{
    $in = body();

    $applied = is_array($in['applied'] ?? null) ? $in['applied'] : [];
    $usage   = is_array($in['usage']   ?? null) ? $in['usage']   : [];
    $note    = (string) ($in['note'] ?? '');

    $syncedCount = 0;
    $usageCount  = 0;

    tx_begin();
    try {
        // ---- confirmations -------------------------------------------
        $ids = array_values(array_filter(array_map('intval', $applied)));
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            q("UPDATE subscriptions
                  SET sync_state = 'synced', synced_at = NOW()
                WHERE id IN ($marks) AND sync_state <> 'synced'", $ids);
            $syncedCount = count($ids);
        }

        // Anything the router did not list is, by definition, gone from it.
        q("UPDATE subscriptions
              SET sync_state = 'revoked'
            WHERE sync_state = 'revoke_pending'
              AND (status <> 'active' OR expires_at <= NOW())");

        // ---- usage + live sessions -----------------------------------
        foreach ($usage as $u) {
            if (!is_array($u) || empty($u['username'])) {
                continue;
            }
            $username = substr((string) $u['username'], 0, 60);
            $usedMb   = max(0.0, (float) ($u['used_mb'] ?? 0));
            $mac      = normalize_mac($u['mac'] ?? null);
            $ip       = filter_var((string) ($u['ip'] ?? ''), FILTER_VALIDATE_IP) ?: null;
            $tether   = max(0, (int) ($u['tethered_hits'] ?? 0));

            $customer = one('SELECT id FROM customers WHERE router_username = ?', [$username]);
            $customerId = $customer['id'] ?? null;

            // GREATEST(): the site's figure only ever climbs within a
            // subscription. A router reset zeroes its counters, and we
            // must not let that erase what someone already spent.
            if ($customerId) {
                q("UPDATE subscriptions
                      SET data_used_mb = GREATEST(data_used_mb, ?)
                    WHERE customer_id = ? AND status = 'active'
                    ORDER BY id DESC LIMIT 1",
                    [$usedMb, $customerId]);

                if ($mac) {
                    q('INSERT INTO devices (customer_id, mac, last_seen)
                       VALUES (?, ?, NOW())
                       ON DUPLICATE KEY UPDATE last_seen = NOW()',
                        [$customerId, $mac]);
                }
            }

            q('INSERT INTO sessions (customer_id, username, mac, ip, started_at, last_seen, used_mb, tethered_hits)
               VALUES (?,?,?,?,?,NOW(),?,?)
               ON DUPLICATE KEY UPDATE
                    customer_id   = VALUES(customer_id),
                    ip            = VALUES(ip),
                    last_seen     = NOW(),
                    used_mb       = VALUES(used_mb),
                    -- accumulates: the router reports 1 per poll it saw
                    -- the TTL anomaly, so this is minutes of sharing
                    tethered_hits = tethered_hits + VALUES(tethered_hits)',
                [
                    $customerId,
                    $username,
                    $mac,
                    $ip,
                    isset($u['uptime_s']) ? date('Y-m-d H:i:s', time() - (int) $u['uptime_s']) : null,
                    $usedMb,
                    $tether,
                ]);

            $usageCount++;
        }

        q('INSERT INTO sync_log (action, payload, result) VALUES (?,?,?)', [
            'report',
            json_encode(['applied' => $ids, 'usage_rows' => $usageCount, 'note' => $note]),
            "synced={$syncedCount} usage={$usageCount}",
        ]);

        tx_commit();
    } catch (Throwable $ex) {
        tx_rollback();
        q('INSERT INTO sync_log (action, payload, result) VALUES (?,?,?)',
            ['report_error', substr(json_encode($in) ?: '', 0, 2000), substr($ex->getMessage(), 0, 200)]);
        fail('Could not record report', 500);
    }

    ok(['synced' => $syncedCount, 'usage_rows' => $usageCount]);
}
