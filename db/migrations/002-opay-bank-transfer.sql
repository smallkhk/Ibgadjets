-- =====================================================================
-- 002 — OPay dynamic bank account collection
--
-- Run against an existing install:
--   mysql -u USER -p DBNAME < db/migrations/002-opay-bank-transfer.sql
--
-- Fresh installs get all of this from db/schema.sql already.
-- =====================================================================

ALTER TABLE transactions
  ADD COLUMN opay_order_no  VARCHAR(64) NULL AFTER tx_hash,
  ADD COLUMN pay_account_no VARCHAR(20) NULL AFTER opay_order_no,
  ADD COLUMN pay_bank_name  VARCHAR(80) NULL AFTER pay_account_no,
  ADD COLUMN pay_expires_at DATETIME    NULL AFTER pay_bank_name,
  ADD UNIQUE KEY uq_tx_opay_order (opay_order_no);

INSERT INTO settings (k, v) VALUES
  ('opay_enabled',        '0'),
  ('opay_live',           '0'),
  ('opay_merchant_id',    ''),
  ('opay_public_key',     ''),
  ('opay_secret_key',     ''),
  ('opay_create_uses_public_key', '0'),
  ('opay_auto_threshold', '0')
ON DUPLICATE KEY UPDATE v = v;
