-- =====================================================================
-- Create trip_expenses — auto-applied re-ship.
--
-- The original database/upgrade-2026-10-trip-expenses.sql got BASELINED on
-- the first auto-deploy (recorded as applied without running, because the
-- deploy baselines historical migrations on a fresh schema_migrations ledger
-- to avoid replaying ~90 files on the live DB). So the table was never
-- actually created and the Trip Expenses / Profit & Loss pages show a
-- "run this migration" notice.
--
-- This is a NEW migration filename, so the deploy's migration loop will apply
-- it on the next deploy. CREATE ... IF NOT EXISTS is a safe no-op where the
-- table already exists and never drops, so it is harmless to re-run.
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
