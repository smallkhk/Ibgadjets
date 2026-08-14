-- =====================================================================
-- IB Gadgets Telecom — seed data
-- Plans transcribed from the PLANS array in ib-gadgets-portal.html.
-- Display strings converted: GB x 1024 = data_mb, days x 24 = hours,
-- 'Unlimited' = NULL. Upload caps added (they were not on the page).
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO plans
  (id, name, subtitle, audience, price_naira, data_mb, speed_down_mbps, speed_up_mbps, validity_hours, max_devices, tier, um_profile, featured, active, sort_order)
VALUES
  -- COMPOUND — residents, short cycles, high speed
  (1,'Starter Day','Enough for WhatsApp, class group and small browsing.','compound',   500,    500, 10,  3,   24, 1, 1, 'ib-c-starter',   0, 1, 10),
  (2,'Everyday 2GB','The one most people in the compound buy.','compound',             1500,   2048, 20,  5,   24, 1, 2, 'ib-c-everyday',  0, 1, 20),
  (3,'Heavy 5GB','Streaming, downloads, video calls that actually hold.','compound',    2500,   5120, 30,  8,   24, 1, 3, 'ib-c-heavy',     1, 1, 30),
  (4,'Unlimited 24h','No cap for a full day. Shaped after 40 GB.','compound',           3500,   NULL, 50, 10,   24, 2, 4, 'ib-c-unlimited', 0, 1, 40),
  (5,'Weekly 15GB','Buy once, forget it till next week.','compound',                    6500,  15360, 25,  6,  168, 2, 3, 'ib-c-weekly',    0, 1, 50),
  (6,'Household Month','For a full flat. Phones, laptop, smart TV.','compound',        25000,   NULL, 60, 15,  720, 4, 4, 'ib-c-household', 0, 1, 60),

  -- VISITOR — outsiders, big data, long validity
  (7,'Visitor 5GB','Use it whenever you are around. No daily expiry.','visitor',        3000,   5120, 20,  5,  336, 1, 2, 'ib-v-5',         0, 1, 10),
  (8,'Visitor 20GB','Two weeks of comfortable browsing at your own pace.','visitor',    7000,  20480, 25,  6,  720, 1, 3, 'ib-v-20',        1, 1, 20),
  (9,'Visitor 50GB','Best rate per gig. For people who work online.','visitor',        15000,  51200, 30,  8, 1440, 2, 4, 'ib-v-50',        0, 1, 30),
  (10,'Visitor 100GB','Bulk data that sits until you finish it.','visitor',            27000, 102400, 35, 10, 2160, 2, 4, 'ib-v-100',       0, 1, 40);

-- ---------------------------------------------------------------------
-- settings
-- Edit these from the admin panel, not here. The bank block is what the
-- customer sees on the checkout screen.
-- ---------------------------------------------------------------------
INSERT INTO settings (k, v) VALUES
  ('business_name',      'IB Gadgets Telecom'),
  ('support_phone',      '08000000000'),
  ('support_whatsapp',   '2348000000000'),

  ('bank_name',          'Change me in Admin > Settings'),
  ('bank_account_name',  'IB GADGETS TELECOM'),
  ('bank_account_no',    '0000000000'),
  ('bank_note',          'Put your reference code in the transfer narration, then upload the receipt.'),

  ('ngn_per_usdt',       '1650'),
  ('wallet_bsc',         '0x7CE448e7f670f48CFA572126494Cf5D0b0bb5739'),
  ('wallet_tron',        'TQn9Y2khEsLJW1ChVWFMSMeRDow5KcbLSE'),
  ('usdt_confirmations', '3'),

  ('opay_enabled',       '0'),
  ('opay_merchant_id',   ''),
  ('opay_public_key',    ''),

  -- anti-sharing
  ('tether_policy',      'flag'),   -- flag | block
  ('tether_grace_hits',  '30'),     -- roughly minutes of sharing tolerated before the admin sees a warning

  ('live_count_floor',   '8')       -- cosmetic "online now" floor on the homepage
ON DUPLICATE KEY UPDATE v = VALUES(v);
