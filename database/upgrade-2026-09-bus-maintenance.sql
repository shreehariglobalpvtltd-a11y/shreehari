-- =====================================================================
--  Bus management — "Under maintenance" status (Point 3).
--
--  The fleet already has a binary is_active (0/1). Rather than reshape it
--  into a 3-value ENUM (which would ripple through every consumer that reads
--  is_active — customer search, rotation, trip-dashboard), maintenance is
--  modelled additively as a nullable note on top of is_active:
--
--     is_active = 1                     -> Active
--     is_active = 0 AND note IS NOT NULL -> Under maintenance (shows the note)
--     is_active = 0 AND note IS NULL     -> Inactive
--
--  A bus in maintenance is is_active = 0, so it is ALREADY excluded from
--  customer sales and rotation with no change to those code paths — this
--  column only distinguishes "temporarily off the road" from "retired" for
--  the admin's eyes. Fully additive and idempotent.
-- =====================================================================

SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'buses'
                AND column_name = 'maintenance_note');
SET @sql := IF(@col = 0,
    'ALTER TABLE `buses` ADD COLUMN `maintenance_note` VARCHAR(140) NULL COMMENT ''When is_active=0 and this is set, the bus is Under Maintenance rather than retired'' AFTER `is_active`',
    'SELECT ''buses.maintenance_note already present''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
