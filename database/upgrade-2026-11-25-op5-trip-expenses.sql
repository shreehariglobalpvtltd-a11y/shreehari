-- =====================================================================
-- 25 Nov 2026 (op5): TRIP EXPENSES per trip / per bus — "ट्रिप खर्च".
--
-- The trip_expenses table already exists on live (route + day, columns
-- diesel / toll / driver_pay / misc / total) and admin/pnl.php,
-- admin/accounting.php, MoneySummary, AiTools and AiWatch already read it.
-- So this does NOT make a second store: it adds the columns a per-trip
-- entry needs, and every new entry still fills route_id, expense_date, the
-- old category column (in INR) and total (in INR) — the existing readers
-- keep counting it exactly as before.
--
--   schedule_id   the trip (schedules.id); route_id + expense_date follow it
--   bus_id        the coach that ran, frozen at entry (rotation can change)
--   category      diesel | toll | driver_allowance | staff_allowance | repair
--                 | parking | food | border | other
--   amount        what was typed, in `currency`
--   currency      INR | NPR (NPR is turned into INR at npr_per_inr for total)
--   fx_rate       the npr_per_inr used, frozen on the row
--   litres        diesel litres, optional
--   receipt_path  receipt photo under uploads/trip-receipts/, optional
--   updated_by / updated_at   last edit (every change is in audit_log too)
--
-- Without this migration the page still works in the old shape (route +
-- day of the chosen trip) — TripExpense checks each column first.
--
-- Idempotent (CREATE ... IF NOT EXISTS, ADD ... IF NOT EXISTS). Never drops,
-- never changes an existing row. Apply:
--   mysql ... shari < database/upgrade-2026-11-25-op5-trip-expenses.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS `trip_expenses` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_id`     BIGINT UNSIGNED NOT NULL,
  `expense_date` DATE NOT NULL,
  `diesel`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `toll`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `driver_pay`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `misc`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `note`         VARCHAR(255) NULL,
  `created_by`   BIGINT UNSIGNED NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_expense_route_date` (`route_id`,`expense_date`),
  CONSTRAINT `fk_expense_route` FOREIGN KEY (`route_id`)
    REFERENCES `routes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `trip_expenses`
  ADD COLUMN IF NOT EXISTS `schedule_id`  BIGINT UNSIGNED NULL AFTER `expense_date`,
  ADD COLUMN IF NOT EXISTS `bus_id`       BIGINT UNSIGNED NULL AFTER `schedule_id`,
  ADD COLUMN IF NOT EXISTS `category`     ENUM('diesel','toll','driver_allowance','staff_allowance','repair','parking','food','border','other') NULL AFTER `bus_id`,
  ADD COLUMN IF NOT EXISTS `amount`       DECIMAL(10,2) NULL AFTER `category`,
  ADD COLUMN IF NOT EXISTS `currency`     CHAR(3) NOT NULL DEFAULT 'INR' AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `fx_rate`      DECIMAL(10,4) NULL AFTER `currency`,
  ADD COLUMN IF NOT EXISTS `litres`       DECIMAL(8,2) NULL AFTER `fx_rate`,
  ADD COLUMN IF NOT EXISTS `receipt_path` VARCHAR(255) NULL AFTER `note`,
  ADD COLUMN IF NOT EXISTS `updated_by`   BIGINT UNSIGNED NULL AFTER `created_at`,
  ADD COLUMN IF NOT EXISTS `updated_at`   DATETIME NULL AFTER `updated_by`;

ALTER TABLE `trip_expenses`
  ADD INDEX IF NOT EXISTS `ix_expense_schedule` (`schedule_id`),
  ADD INDEX IF NOT EXISTS `ix_expense_bus_date` (`bus_id`, `expense_date`);
