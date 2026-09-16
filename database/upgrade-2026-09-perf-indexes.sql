-- ============================================================
--  upgrade-2026-09-perf-indexes.sql  (5 Sep 2026)
--
--  Five indexes, each backed by a real query the admin runs every day:
--
--    bookings(refund_status, cancelled_at)      admin/refunds.php list + tab counts
--    booking_legs(booking_id, leg_type, travel_date)
--                                               the "outbound leg" join on 8 admin
--                                               list pages + travel-date filters
--    audit_logs(action, id)                     admin/activity-log.php filter by action
--    message_logs(status, id)                   admin/messages-log.php status filter
--    bookings(status, cancelled_at)             dashboard "cancelled today" card
--
--  Additive only (no data change, no drops). Idempotent: each ADD is
--  skipped when the index already exists. Nothing here touches the seat
--  lock / booking_seats uniqueness firewall.
--
--    local: "C:\Program Files\MariaDB 12.3\bin\mysql.exe" -h127.0.0.1 -P3307 -uroot shari_test < database/upgrade-2026-09-perf-indexes.sql
--    VPS:   mysql shari < database/upgrade-2026-09-perf-indexes.sql
-- ============================================================

SET @have := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND INDEX_NAME = 'ix_bookings_refund');
SET @sql := IF(@have = 0, 'ALTER TABLE `bookings` ADD KEY `ix_bookings_refund` (`refund_status`, `cancelled_at`)', 'SELECT ''ix_bookings_refund present'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_legs' AND INDEX_NAME = 'ix_legs_booking_type');
SET @sql := IF(@have = 0, 'ALTER TABLE `booking_legs` ADD KEY `ix_legs_booking_type` (`booking_id`, `leg_type`, `travel_date`)', 'SELECT ''ix_legs_booking_type present'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND INDEX_NAME = 'ix_audit_action');
SET @sql := IF(@have = 0, 'ALTER TABLE `audit_logs` ADD KEY `ix_audit_action` (`action`, `id`)', 'SELECT ''ix_audit_action present'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_logs' AND INDEX_NAME = 'ix_msg_status');
SET @sql := IF(@have = 0, 'ALTER TABLE `message_logs` ADD KEY `ix_msg_status` (`status`, `id`)', 'SELECT ''ix_msg_status present'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND INDEX_NAME = 'ix_bookings_cancelled');
SET @sql := IF(@have = 0, 'ALTER TABLE `bookings` ADD KEY `ix_bookings_cancelled` (`status`, `cancelled_at`)', 'SELECT ''ix_bookings_cancelled present'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
