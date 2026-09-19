-- =====================================================================
--  upgrade-2026-09-counter-shifts.sql - counter shift + cash count
--  (includes/countershift.php, admin/shift.php). 19 Sep 2026.
--
--  Additive and re-runnable: one new table, three settings rows.
--  Nothing here is on the sale path. The shift only READS payments.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `counter_shifts` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`      BIGINT UNSIGNED NOT NULL COMMENT 'admins.id - whose till this is',
  `is_open`       TINYINT(1) NULL DEFAULT 1 COMMENT '1 while open, NULL once closed - UNIQUE(admin_id,is_open) allows one open shift per person and any number of closed ones',
  `opened_at`     DATETIME NOT NULL,
  `opening_cash`  DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'float in the drawer at the start',
  `closed_at`     DATETIME NULL,
  `cash_sales`    DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'frozen at close: verified cash + pay-on-boarding settled by this person in the shift',
  `upi_sales`     DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'frozen at close: upi / esewa / bank / wallet verified by this person',
  `tickets`       INT UNSIGNED NOT NULL DEFAULT 0,
  `seats`         INT UNSIGNED NOT NULL DEFAULT 0,
  `cash_paid_out` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'typed at close: cash refunds / expenses given from the drawer',
  `expected_cash` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'opening + cash_sales - cash_paid_out',
  `counted_cash`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `variance`      DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'counted - expected: negative = short',
  `denominations` VARCHAR(500) NULL COMMENT 'JSON {"500":12,"200":3,...} when the note pad was used',
  `note`          VARCHAR(255) NULL,
  `closed_by`     BIGINT UNSIGNED NULL COMMENT 'admins.id - differs from admin_id when the office closes a forgotten shift',
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shift_one_open` (`admin_id`,`is_open`),
  KEY `ix_shift_admin` (`admin_id`,`opened_at`),
  KEY `ix_shift_closed` (`closed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('counter_shift_on',        '0', 'bool',  'agent', 'Counter shift and cash count: staff open a shift, the system adds up the cash they took, they count the drawer at the end', 0),
('counter_shift_notify',    '1', 'bool',  'agent', 'Counter shift: send the closing summary to the office WhatsApp', 0),
('counter_shift_tolerance', '0', 'float', 'agent', 'Counter shift: a difference up to this many rupees is shown as OK', 0);
