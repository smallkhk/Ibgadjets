-- =====================================================================
-- 004 — free trial
--
-- The trial is an ordinary plan with price 0 and is_trial = 1. Making it
-- a plan rather than a special case means subscriptions, the router
-- sync, expiry, the dashboard and the data cap all work on it already,
-- with no second code path to keep in step.
--
-- is_trial does two things: it hides the plan from the shop (nobody
-- should be able to "buy" it), and it is how the admin's stop button
-- finds live trials to end.
--
-- trial_claimed_at is on the customer, not the subscription, so one
-- trial per person survives them cancelling, expiring, or the trial plan
-- being edited later.
--
-- FOR AN EXISTING DATABASE ONLY.
--
-- A fresh install gets all of this from db/schema.sql and db/seed.sql.
-- Running this file on one will stop at the first ALTER with "Duplicate
-- column name" — which is harmless, but it also means the statements
-- after it never run, so do not do it and then assume the trial exists.
--
--   mysql -u USER -p DBNAME < db/migrations/004-free-trial.sql
-- =====================================================================

ALTER TABLE plans
  ADD COLUMN is_trial TINYINT(1) NOT NULL DEFAULT 0 AFTER featured;

ALTER TABLE customers
  ADD COLUMN trial_claimed_at DATETIME NULL AFTER wallet_naira;

-- Off until the owner turns it on. Switching a giveaway on by default
-- would hand out free data the moment this migration runs.
INSERT INTO settings (k, v) VALUES ('trial_enabled', '0')
  ON DUPLICATE KEY UPDATE k = k;

-- The trial plan itself. Inactive as well as disabled, so it cannot be
-- claimed until the owner has looked at it and decided the size is right.
INSERT INTO plans
  (name, subtitle, audience, price_naira, data_mb, speed_down_mbps, speed_up_mbps,
   validity_hours, max_devices, tier, featured, is_trial, active, sort_order)
SELECT 'Free trial', 'One free bundle to try the network.', 'visitor',
       0, 5120, 10, 3, 168, 1, 1, 0, 1, 1, 0
 WHERE NOT EXISTS (SELECT 1 FROM plans WHERE is_trial = 1);
