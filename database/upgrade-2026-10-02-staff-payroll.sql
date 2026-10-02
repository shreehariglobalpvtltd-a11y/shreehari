-- =====================================================================
--  Upgrade 2026-10-02 — Staff Payroll register
--  2 Oct 2026
--
--  Run ONCE on the live database (phpMyAdmin -> SQL tab -> paste -> Go),
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-10-02-staff-payroll.sql
--
--  Fully idempotent and additive: CREATE TABLE IF NOT EXISTS only.
--  Nothing existing is altered.
--
--  WHO THIS IS FOR
--  ---------------
--  People who work for the company WITHOUT a panel login: bus helpers and
--  conductors, office boys, cleaners, supervisors, and drivers (the
--  drivers table carries no pay fields, so a payroll person may point at
--  a drivers row through driver_id instead of retyping the name).
--  Agents and counter staff who DO sign in keep their salary, advances
--  and loans in the agent wallet (admin/agent.php, agent_loans) exactly
--  as before. Salary paid here is NOT a trip_expenses row either, so the
--  Accounting day-book never counts it twice.
--
--  THE MONEY MODEL
--  ---------------
--    staff_advances.recovered is DERIVED. Payroll::syncRecovery() spreads
--    the SUM of advance_deduction over every salary payment of that person
--    across their advances oldest-first (given_on, id), capped at each
--    amount, and flips status to cleared when an advance is fully covered.
--    It runs inside the same transaction as the payment insert / correction,
--    so a corrected payment re-balances the advances and nothing drifts.
--
--    staff_salary_payments is one settlement per person per month
--    (UNIQUE staff_id + period). A mistake is corrected with an UPDATE
--    (audited old -> new), never delete + insert.
--
--    status on an advance:  open | cleared | cancelled
--    (cancelled = entered by mistake, only while nothing was recovered)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `staff_members` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`      VARCHAR(120) NOT NULL,
  `phone`          VARCHAR(15) NULL COMMENT '10-digit mobile, no country code',
  `phone_country`  ENUM('IN','NP') NOT NULL DEFAULT 'IN' COMMENT 'which country the mobile is in - picks +91 or +977 for WhatsApp',
  `role`           ENUM('helper','conductor','office','cleaner','supervisor','driver','other') NOT NULL DEFAULT 'helper',
  `base_location`  VARCHAR(80) NULL COMMENT 'free text e.g. Surat, Mehsana, Nepalgunj',
  `monthly_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`       CHAR(3) NOT NULL DEFAULT 'INR' COMMENT 'INR or NPR',
  `driver_id`      BIGINT UNSIGNED NULL COMMENT 'optional link to drivers.id',
  `joined_on`      DATE NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `notes`          VARCHAR(255) NULL,
  `created_by`     BIGINT UNSIGNED NULL COMMENT 'admins.id',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_staff_active` (`is_active`,`full_name`),
  KEY `ix_staff_driver` (`driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_advances` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id`          BIGINT UNSIGNED NOT NULL,
  `amount`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `given_on`          DATE NOT NULL,
  `reason`            VARCHAR(255) NULL,
  `monthly_deduction` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '0 = recover the whole balance at the next salary',
  `recovered`         DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'derived from salary payments by Payroll syncRecovery',
  `status`            ENUM('open','cleared','cancelled') NOT NULL DEFAULT 'open',
  `cleared_at`        DATETIME NULL,
  `created_by`        BIGINT UNSIGNED NULL COMMENT 'admins.id',
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_staff_adv_staff` (`staff_id`,`status`,`given_on`),
  CONSTRAINT `fk_staff_adv_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff_members` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_salary_payments` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id`          BIGINT UNSIGNED NOT NULL,
  `period`            CHAR(7) NOT NULL COMMENT 'YYYY-MM the salary is for',
  `gross`             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `advance_deduction` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `bonus`             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `other_deduction`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `net_paid`          DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'gross - advance_deduction + bonus - other_deduction',
  `currency`          CHAR(3) NOT NULL DEFAULT 'INR',
  `paid_on`           DATE NOT NULL,
  `method`            ENUM('cash','upi','bank') NOT NULL DEFAULT 'cash',
  `reference`         VARCHAR(80) NULL COMMENT 'UPI ref or bank txn no',
  `notes`             VARCHAR(255) NULL,
  `created_by`        BIGINT UNSIGNED NULL COMMENT 'admins.id',
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by`        BIGINT UNSIGNED NULL COMMENT 'admins.id of the last correction',
  `updated_at`        DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_pay_period` (`staff_id`,`period`),
  KEY `ix_staff_pay_period` (`period`),
  CONSTRAINT `fk_staff_pay_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff_members` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
