-- =====================================================================
--  Upgrade: Agent Panel — per-agent sales attribution + commission
--           (V4.0 / V5.0 "Agent Enterprise Module", 2026-08)
--  Run this ONCE on an existing live database (hPanel -> phpMyAdmin ->
--  select your database -> SQL tab -> paste -> Go).
--
--  WHY: counter/agent sales recorded only `source` ('agent' / 'counter' /
--  'admin') — never WHICH staff member made the sale. So "my bookings",
--  "my collection" and "commission earned" could not be computed at all.
--  This adds the seller reference, so every counter sale is attributable.
--
--  Existing rows keep sold_by_admin_id = NULL (unattributed history) — they
--  still appear in admin totals, just not under any single agent.
--
--  Safe to re-run: each statement is guarded, so re-running is a no-op.
--  ON DELETE SET NULL means removing a staff account never deletes or
--  breaks the bookings they sold.
-- =====================================================================

SET NAMES utf8mb4;

-- 1. Seller column (guarded so a re-run does not error) ----------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'bookings'
                AND COLUMN_NAME = 'sold_by_admin_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE `bookings` ADD COLUMN `sold_by_admin_id` BIGINT UNSIGNED NULL COMMENT ''admins.id who sold this at the counter'' AFTER `user_id`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Index for the agent dashboard queries ------------------------------
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'bookings'
                AND INDEX_NAME = 'ix_bookings_soldby');
SET @sql := IF(@idx = 0,
  'ALTER TABLE `bookings` ADD KEY `ix_bookings_soldby` (`sold_by_admin_id`,`created_at`)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Foreign key — deleting staff never deletes their sales -------------
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'bookings'
               AND CONSTRAINT_NAME = 'fk_bookings_soldby');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `bookings` ADD CONSTRAINT `fk_bookings_soldby` FOREIGN KEY (`sold_by_admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. Commission settings (admin-editable) -------------------------------
--    Commission is a % of the ticket value on sales the agent made.
--    Default 5% — CHANGE THIS to your real rate in Admin -> Settings.
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('agent_commission_percent','5','float','pricing','Ticketing-agent commission (% of ticket value)',0),
('agent_target_monthly','0','int','pricing','Monthly sales target per agent (0 = no target)',0);
