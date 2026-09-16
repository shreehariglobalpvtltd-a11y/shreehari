-- =====================================================================
--  Upgrade 2026-08 — Schedule Management (Admin Control Center Phase 3)
--
--  Adds five columns to `schedules` that the Admin -> Trips control room
--  needs but the base schema does not carry:
--    * dep_time_override  — per-date TIME override that wins over the
--                           route's dep_time for a single trip (§4).
--    * is_blocked         — admin temp-block flag: the trip is hidden
--                           from customer search but not cancelled (§3),
--                           so bookings already sold stay valid.
--    * cancel_reason      — free-text reason recorded when a trip is
--                           cancelled from the control room.
--    * cancelled_by       — admins.id who cancelled it (SET NULL if the
--                           staff account is later deleted, so the trip
--                           history survives the account).
--    * cancelled_at       — DATETIME of the cancellation click.
--
--  Run ONCE on the live database (hPanel -> phpMyAdmin -> your database ->
--  SQL tab -> paste -> Go), or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-08-schedule-management.sql
--
--  Idempotent and additive: every ALTER is guarded by an information_schema
--  COUNT + PREPARE/EXECUTE/DEALLOCATE, so re-running is a no-op and it is
--  safe to run while the site is live. No data is backfilled or mutated.
--
--  NB tests/apply-sql.php splits this file on ";" — never put a semicolon
--  inside a string literal or a COMMENT here, or the statement is cut in two.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) dep_time_override — per-date absolute departure TIME.
--    COALESCE(s.dep_time_override, r.dep_time) is the rule the customer
--    search and the ticket use; a NULL here means "use the route default".
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'schedules'
                AND COLUMN_NAME = 'dep_time_override');
SET @sql := IF(@col = 0,
  'ALTER TABLE `schedules` ADD COLUMN `dep_time_override` TIME NULL COMMENT ''per-date absolute departure time (§4); COALESCE(dep_time_override, r.dep_time) wins'' AFTER `travel_date`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------
-- 2) is_blocked — admin temp-block flag.
--    Sits AFTER `status` so the block state reads next to the lifecycle
--    state. The customer-search hide-blocked query is served by the
--    (is_blocked, travel_date) index below.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'schedules'
                AND COLUMN_NAME = 'is_blocked');
SET @sql := IF(@col = 0,
  'ALTER TABLE `schedules` ADD COLUMN `is_blocked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''admin temp-block: hidden from customer search but not cancelled (§3)'' AFTER `status`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------
-- 3) cancel_reason / cancelled_by / cancelled_at — cancellation audit.
--    Kept on the schedule row (not in a separate ledger) because there
--    is exactly one cancellation event per schedule, and every read path
--    that shows the trip already selects from `schedules`.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'schedules'
                AND COLUMN_NAME = 'cancel_reason');
SET @sql := IF(@col = 0,
  'ALTER TABLE `schedules` ADD COLUMN `cancel_reason` VARCHAR(255) NULL COMMENT ''free-text reason recorded at cancellation''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'schedules'
                AND COLUMN_NAME = 'cancelled_by');
SET @sql := IF(@col = 0,
  'ALTER TABLE `schedules` ADD COLUMN `cancelled_by` BIGINT UNSIGNED NULL COMMENT ''admins.id who cancelled the trip''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'schedules'
                AND COLUMN_NAME = 'cancelled_at');
SET @sql := IF(@col = 0,
  'ALTER TABLE `schedules` ADD COLUMN `cancelled_at` DATETIME NULL COMMENT ''when the cancel button was clicked''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------
-- 4) Index for the customer-search hide-blocked query.
--    "WHERE is_blocked = 0 AND travel_date = ?" is on every customer
--    result page — a covering index on (is_blocked, travel_date) keeps
--    the hot path cheap once trips start carrying block flags.
-- ---------------------------------------------------------------------
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'schedules'
                AND INDEX_NAME = 'ix_schedules_blocked');
SET @sql := IF(@idx = 0,
  'ALTER TABLE `schedules` ADD KEY `ix_schedules_blocked` (`is_blocked`,`travel_date`)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------
-- 5) Foreign key on cancelled_by — SET NULL so removing a staff account
--    never deletes the schedule row, just anonymises who cancelled it.
--    Guarded so a re-run is a no-op.
-- ---------------------------------------------------------------------
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'schedules'
               AND CONSTRAINT_NAME = 'fk_sched_cancelled_by');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'schedules'
                AND COLUMN_NAME = 'cancelled_by');
SET @sql := IF(@fk = 0 AND @col = 1,
  'ALTER TABLE `schedules` ADD CONSTRAINT `fk_sched_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
