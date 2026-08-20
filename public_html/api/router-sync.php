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


// =====================================================================
// Account names on the router
//
// RouterOS parses the JSON we send, and its parser reads "08153329197"
// as a NUMBER. The leading zero is gone before the account is created,
// so the router ends up with 8153329197 and the customer's phone number
// never matches at the login page. Nothing on the router can recover the
// zero — it was lost in the parser.
//
// So the name we put on the wire carries a prefix that cannot be read as
// a number. The database still stores the plain phone number, the
// dashboard still shows the plain phone number, and the captive portal
// applies the same prefix to whatever the customer types. The prefix
// exists only between here and the router.
// =====================================================================

const ROUTER_ACCOUNT_PREFIX = 'ib';

function router_account(string $phone): string
{
    return ROUTER_ACCOUNT_PREFIX . $phone;
}

function router_account_to_phone(string $account): string
{
    // Tolerate a router that reports back without the prefix. Older
    // accounts predate it, and a usage report we cannot match is usage
    // silently not billed.
    if (str_starts_with($account, ROUTER_ACCOUNT_PREFIX)) {
        return substr($account, strlen(ROUTER_ACCOUNT_PREFIX));
    }
    return $account;
}

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

    $users    = [];
    $profiles = [];   // keyed by name so duplicates collapse
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

        // Speed and device count are NOT per-user settings on RouterOS.
        // /ip hotspot user has no rate-limit and no shared-users — both
        // live on /ip hotspot user profile, and setting them on a user
        // fails with "bad parameter". So each distinct combination needs
        // its own profile, and the user is pointed at it.
        //
        // The name is derived from the values rather than the plan id, so
        // two plans that happen to sell the same speed and device count
        // share one profile, and editing a plan's speed moves its
        // customers onto a different profile on the next poll instead of
        // silently rewriting one that other plans are also using.
        $down   = (int) $r['speed_down_mbps'];
        $up     = (int) $r['speed_up_mbps'];
        $shared = max(1, (int) $r['device_limit']);
        $profile = sprintf('ibg-%d-%d-%d', $down, $up, $shared);

        $profiles[$profile] = [
            'name'         => $profile,
            'rate_limit'   => sprintf('%dM/%dM', $down, $up),
            'shared_users' => $shared,
        ];

        $users[] = [
            'username'     => router_account($r['router_username']),
            'password'     => $r['router_password'],
            'sub_id'       => (int) $r['sub_id'],
            'data_mb'      => $remaining,                       // null = unlimited
            'profile'      => $profile,
            'rate_limit'   => sprintf('%dM/%dM', $down, $up),   // kept for the admin panel
            'shared_users' => $shared,
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
        // Profiles must be reconciled before users: a user cannot be
        // pointed at a profile that does not exist yet.
        'profiles'      => array_values($profiles),
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
            $username = router_account_to_phone(substr((string) $u['username'], 0, 60));
            $usedMb   = max(0.0, (float) ($u['used_mb'] ?? 0));
            $mac      = normalize_mac($u['mac'] ?? null);
            $ip       = filter_var((string) ($u['ip'] ?? ''), FILTER_VALIDATE_IP) ?: null;
            $tether   = max(0, (int) ($u['tethered_hits'] ?? 0));

            $customer = one('SELECT id FROM customers WHERE router_username = ?', [$username]);
            $customerId = $customer['id'] ?? null;

            // GREATEST(): the site's figure only ever climbs within a
            // subscription. A router reset zeroes its counters, and we
            // must not let that erase what someone already spent.
            //
            // sync_state='synced' is what stops a top-up being eaten.
            // The router's byte counters belong to the hotspot USER and
            // carry across bundles, so between a customer buying a new
            // bundle and the router being told about it, the router is
            // still reporting the OLD bundle's total. Writing that onto
            // the fresh subscription charged them for data they had
            // already paid for — a 5GB top-up after using 4GB arrived
            // with 1GB on it.
            //
            // Once the router confirms the new subscription it has also
            // reset its counters for that user, so anything it reports
            // from then on belongs to this bundle and only this bundle.
            if ($customerId) {
                q("UPDATE subscriptions
                      SET data_used_mb = GREATEST(data_used_mb, ?)
                    WHERE customer_id = ? AND status = 'active' AND sync_state = 'synced'
                    ORDER BY id DESC LIMIT 1",
                    [$usedMb, $customerId]);

                if ($mac) {
                    // customer_id is reassigned on conflict, not left alone.
                    // A MAC is unique to a physical device, and devices
                    // change hands — someone sells a phone, a relative
                    // signs in on a spare handset. The router has just
                    // authenticated this MAC as this customer, so this
                    // customer owns it now. Without the reassignment the
                    // phone stays welded to its first owner and the new
                    // one never sees it on their dashboard.
                    q('INSERT INTO devices (customer_id, mac, last_seen)
                       VALUES (?, ?, NOW())
                       ON DUPLICATE KEY UPDATE
                            customer_id = VALUES(customer_id),
                            last_seen   = NOW()',
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
