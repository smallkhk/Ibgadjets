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
    ok(['settings' => settings_all(true)]);

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
        if (in_array($k, $locked, true)) {
            continue;
        }
        setting_set(substr((string) $k, 0, 60), (string) $v);
    }
    ok(['settings' => settings_all(true)]);

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
