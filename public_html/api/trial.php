<?php
/**
 * api/trial.php?action=status|claim
 *
 * The free trial is an ordinary plan carrying is_trial = 1. Everything
 * that already works on a paid bundle — the router sync, the data cap,
 * expiry, the dashboard, suspension — works on it with no second code
 * path, which is the whole reason it is modelled this way.
 *
 * Three gates stand between a customer and free data:
 *   1. the owner has switched trials on at all,
 *   2. a trial plan exists and is active,
 *   3. this person has never claimed one before.
 */

declare(strict_types=1);
require __DIR__ . '/../../private/bootstrap.php';

$action = (string) input('action', 'status');

switch ($action) {

    // -----------------------------------------------------------------
    case 'status':
        $plan     = trial_plan();
        $customer = current_customer();

        ok([
            // Deliberately says nothing about WHY it is unavailable when
            // the answer is "the owner turned it off" — a customer does
            // not need to know a giveaway exists but is closed.
            'available' => trial_open() && $plan !== null,
            'claimed'   => $customer ? $customer['trial_claimed_at'] !== null : false,
            'plan'      => $plan ? [
                'name'      => $plan['name'],
                'data'      => format_data($plan['data_mb'] === null ? null : (int) $plan['data_mb']),
                'speed'     => (int) $plan['speed_down_mbps'] . ' Mbps',
                'valid'     => format_validity((int) $plan['validity_hours']),
            ] : null,
        ]);

    // -----------------------------------------------------------------
    case 'claim':
        require_method('POST');
        csrf_check();
        $customer = require_customer();

        // Cheap to call and free to succeed, which is exactly what makes
        // it worth limiting — without this a script could hammer it.
        rate_limit('trial:' . client_ip(), 10, 600);

        if (!trial_open()) {
            fail('The free trial is not available right now', 403);
        }

        $plan = trial_plan();
        if (!$plan) {
            fail('The free trial is not available right now', 403);
        }

        if ($customer['trial_claimed_at'] !== null) {
            fail('You have already used your free trial', 409);
        }

        // Claim the trial by UPDATE ... WHERE trial_claimed_at IS NULL
        // and check the affected row count. Two taps on a slow connection
        // both pass the check above; only one can win this, so nobody
        // gets two trials from double-tapping.
        $claimed = q(
            'UPDATE customers SET trial_claimed_at = NOW()
              WHERE id = ? AND trial_claimed_at IS NULL',
            [(int) $customer['id']]
        )->rowCount();

        if ($claimed === 0) {
            fail('You have already used your free trial', 409);
        }

        try {
            $subId = activate_subscription((int) $customer['id'], (int) $plan['id']);
        } catch (Throwable $e) {
            // Give the claim back. A trial that failed to start is not a
            // trial used, and the customer should be able to try again.
            q('UPDATE customers SET trial_claimed_at = NULL WHERE id = ?', [(int) $customer['id']]);
            throw $e;
        }

        q('INSERT INTO sync_log (action, payload, result) VALUES (?,?,?)', [
            'trial',
            json_encode(['customer' => (int) $customer['id'], 'sub' => $subId, 'plan' => (int) $plan['id']]),
            'free trial claimed',
        ]);

        ok([
            'message' => 'Your free trial is on. Connect to IB Gadgets and log in with your phone number.',
            'sub_id'  => $subId,
        ]);

    // -----------------------------------------------------------------
    default:
        fail('Unknown action', 404);
}

/** Has the owner switched trials on? */
function trial_open(): bool
{
    return setting('trial_enabled', '0') === '1';
}

/**
 * The plan the trial hands out.
 *
 * Lowest id wins if somebody creates a second one, so the answer is
 * stable rather than depending on row order.
 */
function trial_plan(): ?array
{
    return one('SELECT * FROM plans WHERE is_trial = 1 AND active = 1 ORDER BY id LIMIT 1');
}
