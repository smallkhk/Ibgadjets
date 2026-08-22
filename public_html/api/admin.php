<?php
/**
 * api/admin.php?action=...
 *
 * login, logout, me
 * overview
 * customers, customer_update      <- ban, unban, device allowance
 * plans, plan_save, plan_delete   <- price, data, both speeds, validity, devices
 * transactions, approve, reject
 * sessions, devices, device_block
 * settings, setting_save
 * sync_log
 */

declare(strict_types=1);
require __DIR__ . '/../../private/bootstrap.php';

/** Settings the panel may write but must never read back. */
const SECRET_SETTINGS = ['opay_secret_key', 'opay_public_key'];
const SECRET_MASK     = '••••••••  (unchanged)';

$action = (string) input('action', 'me');

// Login is the only action that runs without a session.
if ($action === 'login') {
    require_method('POST');
    csrf_check();

    $email = strtolower(trim((string) want('email')));
    rate_limit('adminlogin:' . client_ip(), 8, 900);

    $a = one('SELECT * FROM admins WHERE email = ?', [$email]);
    if (!$a || !check_password((string) want('password'), $a['password_hash'])) {
        fail('Wrong email or password', 401);
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $a['id'];
    ok(['admin' => ['email' => $a['email'], 'role' => $a['role']]]);
}

if ($action === 'me') {
    $a = current_admin();
    ok(['authenticated' => (bool) $a, 'admin' => $a, 'csrf' => csrf_token()]);
}

$admin = require_admin();
csrf_check();

switch ($action) {

case 'logout':
    require_method('POST');
    unset($_SESSION['admin_id']);
    session_regenerate_id(true);
    ok();

// =====================================================================
// Overview
// =====================================================================
case 'overview':
    ok(['stats' => [
        'customers'        => (int) scalar('SELECT COUNT(*) FROM customers'),
        'active_subs'      => (int) scalar("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND expires_at > NOW()"),
        'online_now'       => (int) scalar('SELECT COUNT(*) FROM sessions WHERE last_seen > (NOW() - INTERVAL 5 MINUTE)'),
        'pending_payments' => (int) scalar("SELECT COUNT(*) FROM transactions WHERE status='pending'"),
        'revenue_today'    => (float) scalar("SELECT COALESCE(SUM(amount_naira),0) FROM transactions WHERE status='success' AND DATE(created_at)=CURDATE()"),
        'revenue_month'    => (float) scalar("SELECT COALESCE(SUM(amount_naira),0) FROM transactions WHERE status='success' AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())"),
        'awaiting_sync'    => (int) scalar("SELECT COUNT(*) FROM subscriptions WHERE sync_state='pending'"),
        'last_router_call' => scalar("SELECT MAX(created_at) FROM sync_log WHERE action IN ('report','desired')"),
        'tether_flags'     => (int) scalar('SELECT COUNT(*) FROM sessions WHERE tethered_hits > ? AND last_seen > (NOW() - INTERVAL 1 DAY)',
                                    [setting_int('tether_grace_hits', 30)]),
    ]]);

/**
 * Delete a customer and everything attached to them.
 *
 * Owner only, and deliberately hard to do by accident, because it is the
 * one action here that destroys records rather than changing them.
 *
 * A customer who has paid you is refused unless `force` is passed. Their
 * transactions are your revenue history: delete them and the Reports tab
 * quietly starts reporting a smaller number for a month that has already
 * been and gone, with nothing to show what changed. Suspension exists for
 * "this person should not be online" — this is for clearing out test
 * accounts and mistakes.
 *
 * The router needs no telling. Removing the subscriptions takes the
 * customer off the desired-state list, and the next poll revokes the
 * hotspot account and cuts any live session.
 */
case 'customer_delete':
    require_method('POST');
    csrf_check();
    require_owner();

    $id = (int) want('id');
    $c  = one('SELECT * FROM customers WHERE id = ?', [$id]);
    if (!$c) {
        fail('No such customer', 404);
    }

    $paidCount = (int) scalar(
        "SELECT COUNT(*) FROM transactions WHERE customer_id = ? AND status = 'success'", [$id]);
    $paidTotal = (float) scalar(
        "SELECT COALESCE(SUM(amount_naira),0) FROM transactions WHERE customer_id = ? AND status = 'success'", [$id]);

    $force = in_array((string) input('force', '0'), ['1', 'true', 'yes'], true);

    if ($paidCount > 0 && !$force) {
        fail('This customer has paid you before. Deleting them removes that from your records too.', 409, [
            'needs_force'  => true,
            'payments'     => $paidCount,
            'amount_naira' => $paidTotal,
        ]);
    }

    tx_begin();
    try {
        // Order matters: children before parents, or the foreign keys
        // refuse. sessions has no constraint but would be left pointing
        // at a customer that no longer exists.
        q('DELETE FROM sessions      WHERE customer_id = ?', [$id]);
        q('DELETE FROM devices       WHERE customer_id = ?', [$id]);
        q('DELETE FROM transactions  WHERE customer_id = ?', [$id]);
        q('DELETE FROM subscriptions WHERE customer_id = ?', [$id]);
        q('DELETE FROM customers     WHERE id = ?',          [$id]);

        // Written inside the transaction so the audit trail cannot
        // survive a rollback, or be missing after a commit.
        q('INSERT INTO sync_log (action, payload, result) VALUES (?,?,?)', [
            'customer_delete',
            json_encode([
                'phone'     => $c['phone'],
                'name'      => $c['full_name'],
                'payments'  => $paidCount,
                'amount'    => $paidTotal,
                'forced'    => $force,
                'by'        => current_admin()['email'] ?? '?',
            ]),
            'customer and all records removed',
        ]);
        tx_commit();
    } catch (Throwable $e) {
        tx_rollback();
        throw $e;
    }

    ok([
        'deleted'  => $c['phone'],
        'payments' => $paidCount,
    ]);

// =====================================================================
// Free trial
// =====================================================================
case 'trial_status':
    $plan = one('SELECT * FROM plans WHERE is_trial = 1 ORDER BY id LIMIT 1');
    ok([
        'enabled' => setting('trial_enabled', '0') === '1',
        'plan'    => $plan ? [
            'id'      => (int) $plan['id'],
            'name'    => $plan['name'],
            'data_mb' => $plan['data_mb'] === null ? null : (int) $plan['data_mb'],
            'hours'   => (int) $plan['validity_hours'],
            'active'  => (bool) $plan['active'],
        ] : null,
        'live'    => (int) scalar(
            "SELECT COUNT(*) FROM subscriptions s
               JOIN plans p ON p.id = s.plan_id
              WHERE p.is_trial = 1 AND s.status = 'active' AND s.expires_at > NOW()"),
        'claimed_total' => (int) scalar('SELECT COUNT(*) FROM customers WHERE trial_claimed_at IS NOT NULL'),
    ]);

/**
 * Turn trials on or off, and optionally end the ones already running.
 *
 * Switching off only stops NEW claims — people already on a trial keep
 * what they were given, which is the fair default. `end_live` is the
 * separate, deliberate act of taking it back from everyone at once, for
 * when the giveaway has to stop now rather than over the next week.
 */
case 'trial_set':
    require_method('POST');
    csrf_check();
    require_admin();

    $on = in_array((string) input('enabled', '0'), ['1', 'true', 'yes'], true);
    q("INSERT INTO settings (k, v) VALUES ('trial_enabled', ?)
       ON DUPLICATE KEY UPDATE v = VALUES(v)", [$on ? '1' : '0']);

    $ended = 0;
    if (in_array((string) input('end_live', '0'), ['1', 'true', 'yes'], true)) {
        // revoke_pending is what the router acts on: the customer leaves
        // the desired-state list on the next poll, their session is cut
        // and the account removed. Within a minute, nobody is on a trial.
        $ended = q(
            "UPDATE subscriptions s
               JOIN plans p ON p.id = s.plan_id
                SET s.status = 'cancelled', s.sync_state = 'revoke_pending'
              WHERE p.is_trial = 1 AND s.status = 'active'"
        )->rowCount();

        q('INSERT INTO sync_log (action, payload, result) VALUES (?,?,?)',
            ['trial_stop', json_encode(['ended' => $ended]), 'all live trials cancelled']);
    }

    ok(['enabled' => $on, 'ended' => $ended]);

/**
 * Edit the trial's size without touching the shop.
 *
 * It is a plan row, so this is really just a plan edit — but routing it
 * through its own action keeps the trial off the Bundles screen, where
 * a giveaway sitting among the priced bundles invites an accidental sale.
 */
case 'trial_plan_save':
    require_method('POST');
    csrf_check();
    require_admin();

    $plan = one('SELECT * FROM plans WHERE is_trial = 1 ORDER BY id LIMIT 1');
    if (!$plan) {
        fail('No trial plan exists. Run db/migrations/004-free-trial.sql.', 404);
    }

    $mb    = max(1, min(1024 * 1024, (int) input('data_mb', 5120)));
    $hours = max(1, min(24 * 365, (int) input('hours', 168)));

    q('UPDATE plans SET data_mb = ?, validity_hours = ? WHERE id = ?', [$mb, $hours, (int) $plan['id']]);

    // Existing trials keep the size they were given. Changing a plan
    // under someone mid-bundle would move their goalposts, and the
    // router is already enforcing the old figure.
    ok(['data_mb' => $mb, 'hours' => $hours]);

// =====================================================================
// Reports
//
// Everything here counts only status='success' transactions. A pending
// payment is somebody's claim that they sent money; counting it as
// revenue means the numbers say you earned more than reached your bank.
//
// The window is a whole number of days ending today, so "30 days"
// includes today's takings so far rather than stopping at midnight.
// =====================================================================
case 'reports':
    $days = max(1, min(365, (int) input('days', 30)));

    // Interpolated, not bound: MySQL will not accept a placeholder
    // inside INTERVAL. Safe because it has been through (int) and is
    // clamped to a range above.
    $since = "(CURDATE() - INTERVAL " . ($days - 1) . " DAY)";

    ok([
        'days' => $days,

        'totals' => [
            'revenue'       => (float) scalar("SELECT COALESCE(SUM(amount_naira),0) FROM transactions WHERE status='success' AND created_at >= $since"),
            'payments'      => (int)   scalar("SELECT COUNT(*) FROM transactions WHERE status='success' AND created_at >= $since"),
            'buyers'        => (int)   scalar("SELECT COUNT(DISTINCT customer_id) FROM transactions WHERE status='success' AND created_at >= $since"),
            'revenue_today' => (float) scalar("SELECT COALESCE(SUM(amount_naira),0) FROM transactions WHERE status='success' AND DATE(created_at)=CURDATE()"),
            // What people did not pay for. A pile of rejected payments is
            // either fraud or a confusing payment page — both worth seeing.
            'rejected'      => (int)   scalar("SELECT COUNT(*) FROM transactions WHERE status='failed' AND created_at >= $since"),
            // Sold, not used. This is what you owe Starlink against what
            // you charged for, and the gap is your actual margin.
            'data_sold_gb'  => round((float) scalar(
                "SELECT COALESCE(SUM(p.data_mb),0)/1024
                   FROM transactions t JOIN plans p ON p.id = t.plan_id
                  WHERE t.status='success' AND t.created_at >= $since"), 2),
        ],

        // One row per day, oldest first, with zero-revenue days present
        // rather than missing — a chart with gaps in it lies about trend.
        'daily' => all(
            "SELECT DATE(created_at) AS day,
                    SUM(amount_naira) AS revenue,
                    COUNT(*)          AS payments
               FROM transactions
              WHERE status='success' AND created_at >= $since
              GROUP BY DATE(created_at)
              ORDER BY day"),

        'by_plan' => all(
            "SELECT COALESCE(p.name,'(deleted plan)') AS plan,
                    COUNT(*)              AS sold,
                    SUM(t.amount_naira)   AS revenue
               FROM transactions t
               LEFT JOIN plans p ON p.id = t.plan_id
              WHERE t.status='success' AND t.created_at >= $since
              GROUP BY t.plan_id, p.name
              ORDER BY revenue DESC"),

        'by_method' => all(
            "SELECT method, COUNT(*) AS payments, SUM(amount_naira) AS revenue
               FROM transactions
              WHERE status='success' AND created_at >= $since
              GROUP BY method
              ORDER BY revenue DESC"),

        'top_customers' => all(
            "SELECT c.phone, c.full_name, c.type, c.flat_no,
                    COUNT(*)            AS payments,
                    SUM(t.amount_naira) AS spent
               FROM transactions t
               JOIN customers c ON c.id = t.customer_id
              WHERE t.status='success' AND t.created_at >= $since
              GROUP BY c.id, c.phone, c.full_name, c.type, c.flat_no
              ORDER BY spent DESC
              LIMIT 10"),

        // Compound versus visitor. These are different businesses with
        // different pricing, and knowing which one pays the bills tells
        // you which one to grow.
        'by_audience' => all(
            "SELECT c.type, COUNT(*) AS payments, SUM(t.amount_naira) AS revenue
               FROM transactions t
               JOIN customers c ON c.id = t.customer_id
              WHERE t.status='success' AND t.created_at >= $since
              GROUP BY c.type"),
    ]);

// =====================================================================
// Customers
// =====================================================================
case 'customers':
    $search = trim((string) input('q', ''));
    $args   = [];
    $where  = '';
    if ($search !== '') {
        $where = 'WHERE c.phone LIKE ? OR c.full_name LIKE ? OR c.flat_no LIKE ?';
        $like  = '%' . $search . '%';
        $args  = [$like, $like, $like];
    }

    ok(['customers' => all(
        "SELECT c.id, c.phone, c.full_name, c.type, c.flat_no, c.status,
                c.wallet_naira, c.device_limit, c.created_at,
                (SELECT p.name FROM subscriptions s JOIN plans p ON p.id = s.plan_id
                  WHERE s.customer_id = c.id AND s.status='active' AND s.expires_at > NOW()
                  ORDER BY s.expires_at DESC LIMIT 1) AS current_plan,
                (SELECT s.expires_at FROM subscriptions s
                  WHERE s.customer_id = c.id AND s.status='active' AND s.expires_at > NOW()
                  ORDER BY s.expires_at DESC LIMIT 1) AS expires_at,
                (SELECT COUNT(*) FROM devices d WHERE d.customer_id = c.id) AS device_count
           FROM customers c
           $where
          ORDER BY c.id DESC LIMIT 300", $args)]);

/**
 * The per-customer device allowance lives here.
 *
 * device_limit NULL = whatever the plan sells. A number overrides it, so
 * a family on a 1-device plan can be allowed 3 without changing the plan
 * or the price. It takes effect on the router within one poll, because
 * the sync endpoint reads it live.
 */
case 'customer_update':
    require_method('POST');
    $id = (int) want('id');
    $c  = one('SELECT * FROM customers WHERE id = ?', [$id]);
    if (!$c) {
        fail('No such customer', 404);
    }

    if (($v = input('status')) !== null) {
        if (!in_array($v, ['active', 'suspended'], true)) {
            fail('Bad status', 422);
        }
        q('UPDATE customers SET status = ? WHERE id = ?', [$v, $id]);

        // Suspending pulls them off the desired-state list immediately;
        // the router drops the session on its next pass.
        if ($v === 'suspended') {
            q("UPDATE subscriptions SET sync_state='revoke_pending'
                WHERE customer_id = ? AND status='active'", [$id]);
        }
    }

    if (array_key_exists('device_limit', body()) || isset($_GET['device_limit'])) {
        $raw = input('device_limit');
        if ($raw === '' || $raw === null || $raw === 'null') {
            q('UPDATE customers SET device_limit = NULL WHERE id = ?', [$id]);
        } else {
            $n = max(1, min(16, (int) $raw));
            q('UPDATE customers SET device_limit = ? WHERE id = ?', [$n, $id]);
            // Push it onto the running subscription too, otherwise the
            // change would not show until their next purchase.
            q("UPDATE subscriptions SET device_limit = ?, sync_state='pending'
                WHERE customer_id = ? AND status='active' AND expires_at > NOW()", [$n, $id]);
        }
    }

    foreach (['full_name', 'flat_no'] as $field) {
        if (($v = input($field)) !== null) {
            q("UPDATE customers SET {$field} = ? WHERE id = ?", [trim((string) $v) ?: null, $id]);
        }
    }

    if (($v = input('wallet_credit')) !== null && (float) $v != 0.0) {
        q('UPDATE customers SET wallet_naira = wallet_naira + ? WHERE id = ?', [(float) $v, $id]);
        q("INSERT INTO transactions (customer_id, amount_naira, method, reference, status, approved_by, approved_at, note)
           VALUES (?,?, 'manual', ?, 'success', ?, NOW(), 'wallet top-up by admin')",
            [$id, (float) $v, make_reference('IBM'), $admin['id']]);
    }

    ok(['customer' => one('SELECT * FROM customers WHERE id = ?', [$id])]);

// =====================================================================
// Plans — price, data, speed limits, time limit, devices
// =====================================================================
case 'plans':
    ok(['plans' => all('SELECT * FROM plans ORDER BY audience, sort_order, price_naira')]);

case 'plan_save':
    require_method('POST');

    $fields = [
        'name'            => trim((string) input('name', '')),
        'subtitle'        => trim((string) input('subtitle', '')),
        'audience'        => input('audience') === 'visitor' ? 'visitor' : 'compound',
        'price_naira'     => max(0, (float) input('price_naira', 0)),
        'data_mb'         => data_mb_input(),
        'speed_down_mbps' => max(1, min(1000, (int) input('speed_down_mbps', 10))),
        'speed_up_mbps'   => max(1, min(1000, (int) input('speed_up_mbps', 3))),
        'validity_hours'  => max(1, (int) input('validity_hours', 24)),
        'max_devices'     => max(1, min(16, (int) input('max_devices', 1))),
        'tier'            => max(1, min(4, (int) input('tier', 2))),
        'featured'        => (int) (bool) input('featured', 0),
        'active'          => (int) (bool) input('active', 1),
        'sort_order'      => (int) input('sort_order', 0),
    ];

    if ($fields['name'] === '') {
        fail('Plan needs a name', 422);
    }

    $id = (int) input('id', 0);

    if ($id > 0) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        q("UPDATE plans SET $set WHERE id = ?", [...array_values($fields), $id]);
    } else {
        $cols = implode(', ', array_keys($fields));
        $marks = implode(', ', array_fill(0, count($fields), '?'));
        q("INSERT INTO plans ($cols) VALUES ($marks)", array_values($fields));
        $id = last_id();
        q('UPDATE plans SET um_profile = ? WHERE id = ?', ['ib-plan-' . $id, $id]);
    }

    // Speed and device changes must reach people who already bought it.
    // Bumping them to 'pending' makes the next poll re-apply the limits.
    q("UPDATE subscriptions SET sync_state = 'pending'
        WHERE plan_id = ? AND status = 'active' AND expires_at > NOW()", [$id]);

    ok(['plan' => one('SELECT * FROM plans WHERE id = ?', [$id])]);

case 'plan_delete':
    require_method('POST');
    $id = (int) want('id');
    // Never hard-delete: subscriptions and receipts point at it.
    q('UPDATE plans SET active = 0 WHERE id = ?', [$id]);
    ok(['message' => 'Plan retired. Existing subscribers keep running until expiry.']);

// =====================================================================
// Payments
// =====================================================================
case 'transactions':
    $status = input('status');
    $where  = in_array($status, ['pending', 'success', 'failed'], true) ? 'WHERE t.status = ?' : '';
    $args   = $where ? [$status] : [];

    ok(['transactions' => all(
        "SELECT t.*, c.phone, c.full_name, c.flat_no, p.name AS plan_name
           FROM transactions t
           JOIN customers c ON c.id = t.customer_id
           LEFT JOIN plans p ON p.id = t.plan_id
           $where
          ORDER BY t.id DESC LIMIT 200", $args)]);

/**
 * The button that runs the business until OPay is live: confirm the
 * money landed, and the plan goes on. Activation is the same call every
 * other payment method makes.
 */
case 'approve':
    require_method('POST');
    $id = (int) want('id');

    $tx = one('SELECT * FROM transactions WHERE id = ?', [$id]);
    if (!$tx) {
        fail('No such transaction', 404);
    }
    if ($tx['status'] === 'success') {
        fail('Already approved', 409);
    }

    $planId = (int) ($tx['plan_id'] ?: (int) input('plan_id', 0));
    if (!$planId) {
        fail('Which plan is this payment for?', 422);
    }

    tx_begin();
    try {
        q("UPDATE transactions SET status='success', approved_by=?, approved_at=NOW(), plan_id=? WHERE id=?",
            [$admin['id'], $planId, $id]);
        $subId = activate_subscription((int) $tx['customer_id'], $planId, $id);
        tx_commit();
    } catch (Throwable $ex) {
        tx_rollback();
        fail('Could not activate: ' . $ex->getMessage(), 400);
    }

    ok(['subscription_id' => $subId, 'message' => 'Approved. The router picks it up within 60 seconds.']);

case 'reject':
    require_method('POST');
    $id = (int) want('id');
    q("UPDATE transactions SET status='failed', approved_by=?, approved_at=NOW(), note=? WHERE id=?",
        [$admin['id'], substr((string) input('note', 'rejected by admin'), 0, 255), $id]);
    ok();

// =====================================================================
// Live view
// =====================================================================
case 'sessions':
    ok([
        'sessions' => all(
            'SELECT s.*, c.phone, c.full_name, c.flat_no
               FROM sessions s
               LEFT JOIN customers c ON c.id = s.customer_id
              WHERE s.last_seen > (NOW() - INTERVAL 15 MINUTE)
              ORDER BY s.last_seen DESC'),
        'tether_grace' => setting_int('tether_grace_hits', 30),
    ]);

case 'devices':
    ok(['devices' => all(
        'SELECT d.*, c.phone, c.full_name
           FROM devices d JOIN customers c ON c.id = d.customer_id
          WHERE (? = 0 OR d.customer_id = ?)
          ORDER BY d.last_seen DESC LIMIT 300',
        [(int) input('customer_id', 0), (int) input('customer_id', 0)])]);

case 'device_block':
    require_method('POST');
    $id = (int) want('id');
    $blocked = (int) (bool) input('blocked', 1);
    q('UPDATE devices SET blocked = ? WHERE id = ?', [$blocked, $id]);
    ok(['message' => $blocked ? 'Device blocked at the router on next poll.' : 'Device unblocked.']);

// =====================================================================
// Settings
// =====================================================================
case 'settings':
    // Secrets are writable but never readable back. The panel shows a
    // placeholder; leaving that placeholder untouched on save keeps the
    // stored value, so a secret cannot leak into a browser, a screenshot
    // or a support session.
    $out = settings_all(true);
    foreach (SECRET_SETTINGS as $k) {
        if (($out[$k] ?? '') !== '') {
            $out[$k] = SECRET_MASK;
        }
    }
    ok(['settings' => $out]);

case 'setting_save':
    require_method('POST');
    require_owner();

    // Never writable over HTTP — it lives in private/config.php.
    $locked = ['sync_key'];

    $pairs = body()['settings'] ?? null;
    if (!is_array($pairs)) {
        $pairs = [(string) want('k') => (string) input('v', '')];
    }
    foreach ($pairs as $k => $v) {
        $k = substr((string) $k, 0, 60);
        if (in_array($k, $locked, true)) {
            continue;
        }
        // The mask came from us, not the admin — it means "unchanged".
        if ($v === SECRET_MASK && in_array($k, SECRET_SETTINGS, true)) {
            continue;
        }
        setting_set($k, (string) $v);
    }
    ok(['saved' => true]);

case 'sync_log':
    ok(['log' => all('SELECT * FROM sync_log ORDER BY id DESC LIMIT 100')]);

default:
    fail('Unknown action', 404);
}

/** "5120", "5 GB", "unlimited" or empty -> int MB or NULL. */
function data_mb_input(): ?int
{
    $raw = trim((string) input('data_mb', ''));
    if ($raw === '' || strcasecmp($raw, 'unlimited') === 0 || strcasecmp($raw, 'null') === 0) {
        return null;
    }
    if (preg_match('/^\s*([\d.]+)\s*(gb|g)\s*$/i', $raw, $m)) {
        return (int) round((float) $m[1] * 1024);
    }
    return max(0, (int) preg_replace('/\D/', '', $raw));
}
