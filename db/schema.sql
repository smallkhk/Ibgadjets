-- =====================================================================
-- IB Gadgets Telecom — schema
-- MySQL 8 / MariaDB 10.4+  (Hostinger cPanel)
--
-- Import once:  mysql -u USER -p DBNAME < db/schema.sql
-- Then seed:    mysql -u USER -p DBNAME < db/seed.sql
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+01:00';   -- West Africa Time

-- ---------------------------------------------------------------------
-- customers
-- Login identifier is the Nigerian phone number, never email.
-- router_username is what User Manager knows the person as; we keep it
-- equal to the phone number so support calls are easy to trace.
-- device_limit is the ADMIN OVERRIDE: NULL means "use whatever the
-- active plan allows", a number means "this person may run N devices"
-- regardless of plan.
-- ---------------------------------------------------------------------
CREATE TABLE customers (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  phone           VARCHAR(20)  NOT NULL UNIQUE,
  full_name       VARCHAR(120),
  password_hash   VARCHAR(255) NOT NULL,
  type            ENUM('compound','visitor') NOT NULL DEFAULT 'compound',
  flat_no         VARCHAR(20)  NULL,
  wallet_naira    DECIMAL(10,2) NOT NULL DEFAULT 0,
  status          ENUM('active','suspended') NOT NULL DEFAULT 'active',
  device_limit    TINYINT      NULL,
  router_username VARCHAR(60)  UNIQUE,
  router_password VARCHAR(60),
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_customers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- plans
-- Everything the admin can edit lives here: price, data, both speed
-- limits, validity and device count. data_mb NULL = unlimited.
-- um_profile is the User Manager profile name the router creates.
-- ---------------------------------------------------------------------
CREATE TABLE plans (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(80)  NOT NULL,
  subtitle        VARCHAR(200),
  audience        ENUM('compound','visitor') NOT NULL,
  price_naira     DECIMAL(10,2) NOT NULL,
  data_mb         INT          NULL,
  speed_down_mbps INT          NOT NULL,
  speed_up_mbps   INT          NOT NULL,
  validity_hours  INT          NOT NULL,
  max_devices     TINYINT      NOT NULL DEFAULT 1,
  tier            TINYINT      NOT NULL DEFAULT 2,
  um_profile      VARCHAR(60)  NULL,
  featured        TINYINT(1)   NOT NULL DEFAULT 0,
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order      SMALLINT     NOT NULL DEFAULT 0,
  INDEX idx_plans_listing (active, audience, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- subscriptions
-- sync_state is a LOG of what the router last confirmed, not the thing
-- that drives correctness. api/router-sync.php serves desired state
-- (every active row, every poll) and the router reconciles against it.
-- data_used_mb is written back by the router; it is what makes recovery
-- after a router wipe correct, because re-provisioning sends
-- (data_mb - data_used_mb) rather than the full allowance.
-- ---------------------------------------------------------------------
CREATE TABLE subscriptions (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  customer_id  INT NOT NULL,
  plan_id      INT NOT NULL,
  started_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at   DATETIME NOT NULL,
  data_used_mb DECIMAL(12,2) NOT NULL DEFAULT 0,
  device_limit TINYINT NOT NULL DEFAULT 1,   -- snapshot at purchase time
  status       ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  sync_state   ENUM('pending','synced','revoke_pending','revoked') NOT NULL DEFAULT 'pending',
  synced_at    DATETIME NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (plan_id)     REFERENCES plans(id),
  INDEX idx_subs_desired (status, expires_at),
  INDEX idx_subs_customer (customer_id, status),
  INDEX idx_subs_sync (sync_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- devices
-- The device roster behind max_devices. MACs are learned from the
-- router (it is the only thing that can see them), never from the
-- browser. blocked lets an admin kill one phone without touching the
-- rest of the account.
-- ---------------------------------------------------------------------
CREATE TABLE devices (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  customer_id  INT NOT NULL,
  mac          VARCHAR(17) NOT NULL,
  label        VARCHAR(60) NULL,
  first_seen   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_seen    DATETIME NULL,
  blocked      TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_devices_mac (mac),
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX idx_devices_customer (customer_id, blocked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- sessions  (live view for the admin panel)
-- Rewritten from the router's usage POST every cycle. tethered_hits is
-- the TTL-anomaly counter the router reports — see docs/anti-sharing.md.
-- ---------------------------------------------------------------------
CREATE TABLE sessions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT NULL,
  username      VARCHAR(60) NOT NULL,
  mac           VARCHAR(17) NULL,
  ip            VARCHAR(45) NULL,
  started_at    DATETIME NULL,
  last_seen     DATETIME NOT NULL,
  used_mb       DECIMAL(12,2) NOT NULL DEFAULT 0,
  tethered_hits INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sessions_username_mac (username, mac),
  INDEX idx_sessions_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- transactions
-- reference is what the customer types into the bank transfer narration,
-- so it MUST be unique. tx_hash unique stops ten people pasting the same
-- valid USDT hash and all ten getting credited.
-- ---------------------------------------------------------------------
CREATE TABLE transactions (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  customer_id  INT NOT NULL,
  plan_id      INT NULL,
  amount_naira DECIMAL(10,2) NOT NULL,
  method       ENUM('bank_transfer','opay','usdt_bsc','usdt_tron','wallet','manual') NOT NULL,
  reference    VARCHAR(120) NOT NULL,
  tx_hash      VARCHAR(160) NULL,
  proof_path   VARCHAR(255) NULL,
  status       ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
  approved_by  INT NULL,
  approved_at  DATETIME NULL,
  note         VARCHAR(255) NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tx_reference (reference),
  UNIQUE KEY uq_tx_hash (tx_hash),
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX idx_tx_queue (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admins (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('owner','staff') NOT NULL DEFAULT 'staff',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  k VARCHAR(60) PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sync_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  action     VARCHAR(40),
  payload    TEXT,
  result     VARCHAR(200),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_synclog_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- rate_limits — brute-force protection for login, signup and sync.
-- Swept by cron/expire.php.
-- ---------------------------------------------------------------------
CREATE TABLE rate_limits (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  bucket   VARCHAR(80) NOT NULL,
  hit_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rl (bucket, hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
