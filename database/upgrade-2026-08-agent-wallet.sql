-- =====================================================================
--  Upgrade 2026-08 — Agent profile, wallet ledger and offline tickets
--
--  Run ONCE on the live database (hPanel -> phpMyAdmin -> your database ->
--  SQL tab -> paste -> Go), or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-08-agent-wallet.sql
--
--  Fully idempotent and additive: only CREATE TABLE IF NOT EXISTS and
--  INSERT IGNORE. No existing table is altered, so it is safe to re-run
--  and safe to run while the site is live.
--
--  WHY A LEDGER
--  ------------
--  Counter-agent commission used to be a number recomputed on every page
--  load (month sales x agent_commission_percent). That can be *shown* but
--  never *paid*: it has no history, it silently changes the day the
--  percentage is edited, and a cancelled booking quietly rewrites last
--  month's earnings. A ledger fixes all three — every rupee is a row,
--  a reversal is another row, and the balance is the sum.
--
--  TWO BALANCES PER AGENT, kept in one table via `account`:
--    commission — what the COMPANY OWES THE AGENT
--                 (+ commission earned, − payout paid)
--    cash       — what the AGENT OWES THE COMPANY
--                 (+ cash collected at the counter, − handed over)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) The wallet. Amounts are SIGNED — the sign carries the direction, so
--    a balance is a plain SUM() and can never disagree with its rows.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agent_ledger` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_admin_id` BIGINT UNSIGNED NOT NULL COMMENT 'admins.id of the counter agent',
  `booking_id`     BIGINT UNSIGNED NULL COMMENT 'the sale this entry came from, if any',
  `account`        ENUM('commission','cash') NOT NULL DEFAULT 'commission',
  `entry_type`     ENUM('commission','commission_void','payout',
                        'cash_due','cash_handover','adjustment') NOT NULL,
  `amount`         DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'SIGNED: + owed to agent / owed by agent, − settled',
  `note`           VARCHAR(255) NULL,
  `ref`            VARCHAR(80)  NULL COMMENT 'paper ticket no, UPI ref, voucher no',
  `created_by`     BIGINT UNSIGNED NULL COMMENT 'admins.id who recorded it (NULL = automatic)',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- One commission (and at most one void) per booking, enforced by the
  -- database rather than by a check-then-insert that two requests can race.
  UNIQUE KEY `uq_ledger_booking_type` (`booking_id`,`entry_type`),
  KEY `ix_ledger_agent`   (`agent_admin_id`,`account`,`created_at`),
  KEY `ix_ledger_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 2) The physical-ticket register. An agent who sold a paper ticket away
--    from the system records it here; when a seat was named, `booking_id`
--    points at the real booking that was created so seat inventory stays
--    honest and the passenger still gets a QR ticket.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `offline_tickets` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_admin_id`  BIGINT UNSIGNED NOT NULL,
  `paper_ticket_no` VARCHAR(60) NOT NULL COMMENT 'the number printed on the paper book',
  `booking_id`      BIGINT UNSIGNED NULL COMMENT 'set when seats were named and a real booking was made',
  `route_id`        BIGINT UNSIGNED NULL,
  `travel_date`     DATE NOT NULL,
  `passenger_name`  VARCHAR(120) NOT NULL,
  `passenger_phone` VARCHAR(20)  NULL,
  `seat_text`       VARCHAR(120) NULL COMMENT 'free text when no real seats were claimed',
  `pax_count`       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `amount`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `commission`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_mode`    ENUM('cash','upi','other') NOT NULL DEFAULT 'cash',
  `status`          ENUM('recorded','void') NOT NULL DEFAULT 'recorded',
  `note`            VARCHAR(255) NULL,
  `created_by`      BIGINT UNSIGNED NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- The same agent cannot enter the same paper ticket number twice —
  -- the commonest way a counter double-claims commission.
  UNIQUE KEY `uq_offline_paper` (`agent_admin_id`,`paper_ticket_no`),
  KEY `ix_offline_agent` (`agent_admin_id`,`created_at`),
  KEY `ix_offline_date`  (`travel_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 3) Per-staff profile. A separate table so this migration never has to
--    ALTER the live `admins` table. `commission_percent` here overrides
--    the global agent_commission_percent for that one agent.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_profiles` (
  `admin_id`           BIGINT UNSIGNED NOT NULL,
  `display_phone`      VARCHAR(20)  NULL,
  `display_email`      VARCHAR(191) NULL,
  `counter_name`       VARCHAR(120) NULL COMMENT 'branch / counter this agent works from',
  `address`            VARCHAR(255) NULL,
  `commission_percent` DECIMAL(5,2) NULL COMMENT 'NULL = use the global agent_commission_percent',
  `cash_limit`         DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '0 means no limit, else warn once cash held exceeds it',
  `payout_method`      VARCHAR(40)  NULL COMMENT 'upi / bank / cash',
  `payout_account`     VARCHAR(120) NULL,
  `joined_on`          DATE NULL,
  `notes`              VARCHAR(500) NULL,
  `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 4) Settings (Admin -> Settings, group "agent").
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('agent_commission_percent','5',  'float','agent','Counter-agent commission % of confirmed ticket value',0),
('agent_target_monthly',    '0',  'float','agent','Monthly sales target per agent (0 = off)',0),
('agent_wallet_enabled',    '1',  'bool', 'agent','Record agent commission + cash in the wallet ledger',0),
('agent_offline_tickets',   '1',  'bool', 'agent','Let agents record physical (paper) tickets they sold offline',0),
('agent_cash_limit_default','0',  'float','agent','Default cash-in-hand limit per agent (0 = no limit)',0);
