-- 5 Oct 2026 - agent & financial management add-on (owner's 13-point prompt).
-- Additive only. Every column / table is guarded on its own, so the file can
-- be run again safely and a half-applied run can simply be repeated.

-- Point 11: the one stored channel fact - a sale that began as a WhatsApp
-- request. Every other channel tag is derived (includes/bookingchannel.php).
ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS channel_tag VARCHAR(16) NULL DEFAULT NULL
  COMMENT 'WHATSAPP = sale began as a WhatsApp request (includes/bookingchannel.php)';

-- Point 7: the settlement record (includes/agentsettlement.php, admin/settlements.php).
-- A settlement is paperwork around the agent ledger - the ledger itself is only
-- written when an admin presses Mark paid.
CREATE TABLE IF NOT EXISTS agent_settlements (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_admin_id     BIGINT UNSIGNED NOT NULL COMMENT 'admins.id of the agent',
  period_from        DATE NOT NULL,
  period_to          DATE NOT NULL,
  opening_balance    DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'commission balance carried into the period',
  gross_commission   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reversals          DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'signed - cancelled tickets',
  advance_adjustment DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'signed - advances given or repaid in the period',
  other_adjustments  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_in_period     DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'signed - payouts already made in the period',
  commission_due     DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'what this settlement may pay',
  cash_held          DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'ticket cash the agent held at the snapshot',
  tickets            INT UNSIGNED NOT NULL DEFAULT 0,
  seats              INT UNSIGNED NOT NULL DEFAULT 0,
  deductions_json    TEXT NULL COMMENT 'list of label and amount',
  deductions_total   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  bonus              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  bonus_note         VARCHAR(160) NULL,
  cash_offset        DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'commission netted off against cash held',
  cash_received      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'ticket cash handed over with the settlement',
  net_amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'paid to the agent',
  payment_mode       VARCHAR(12) NULL,
  payment_ref        VARCHAR(80) NULL,
  status             VARCHAR(12) NOT NULL DEFAULT 'draft' COMMENT 'draft approved paid confirmed disputed cancelled',
  notes              VARCHAR(500) NULL,
  dispute_note       VARCHAR(500) NULL,
  disputed_at        DATETIME NULL,
  resolved_note      VARCHAR(500) NULL,
  ledger_ids         VARCHAR(255) NULL COMMENT 'agent_ledger ids written by Mark paid',
  created_by         BIGINT UNSIGNED NULL,
  approved_by        BIGINT UNSIGNED NULL,
  approved_at        DATETIME NULL,
  paid_by            BIGINT UNSIGNED NULL,
  paid_at            DATETIME NULL,
  confirmed_at       DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_settle_agent (agent_admin_id, status, period_to),
  KEY ix_settle_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Point 10: two more staff roles - operations (bookings, agents, seats - no
-- settlements or money reports) and viewer (read-only, for partners).
-- MODIFY with the full list is safe to repeat.
ALTER TABLE admins
  MODIFY role ENUM('superadmin','manager','accountant','support','scanner','official','agent','counter','operations','viewer') NOT NULL DEFAULT 'support';

-- Point 9: the panel now has a notification centre that READS this table.
-- Until today nothing ever read it, so every old admin row is still "unread" -
-- mark that backlog read once, or the bell would open at several thousand.
UPDATE notifications SET is_read = 1, read_at = NOW()
 WHERE audience = 'admin' AND is_read = 0 AND created_at < '2026-10-05 00:00:00';
