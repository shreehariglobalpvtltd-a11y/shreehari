-- =====================================================================
-- Trip expenses — per-route, per-date operating costs for profit/loss.
--
-- The `trip_expenses` table is defined in schema.sql (base install) but
-- was never shipped as its own upgrade, so a live DB installed from an
-- older base may not have it yet. This migration adds it idempotently:
-- CREATE ... IF NOT EXISTS is a safe no-op where the table already exists,
-- and it NEVER drops, so no data is ever lost by re-running it.
--
-- Apply:  php tests/apply-sql.php database/upgrade-2026-10-trip-expenses.sql
--         (or paste into phpMyAdmin -> SQL). Safe to re-run.
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
