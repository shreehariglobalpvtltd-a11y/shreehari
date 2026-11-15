-- =====================================================================
--  upgrade-2026-11-16-ticket-seat-change.sql  (P8-3e, 16 Nov 2026)
--
--  A third passenger REQUEST on the ticket page: "change my seat".
--  Like the other two it is only a row for the office - nothing moves a
--  seat by itself. The office looks at the seat map, moves the passenger
--  with the existing booking tools if it can, and closes the request as
--  done or rejected (admin/ticket-requests.php).
--
--    ticket_requests.kind          + 'change_seat'
--    ticket_requests.wanted_seats  the seat label(s) the passenger asked
--                                  for, as printed on tickets ("A5,A6")
--
--  Additive and safe to re-run: each change runs only when it is still
--  missing, and nothing runs when ticket_requests itself is not there yet
--  (upgrade-2026-11-15-ticket-requests.sql first). Code that runs before
--  this file simply offers no "seat change" form.
-- =====================================================================

SET NAMES utf8mb4;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_requests');

SET @kind := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_requests'
                 AND COLUMN_NAME = 'kind' AND COLUMN_TYPE LIKE '%change_seat%');
SET @sql := IF(@tbl = 1 AND @kind = 0,
  'ALTER TABLE `ticket_requests` MODIFY COLUMN `kind` ENUM(''change_date'',''cancel_seats'',''change_seat'') NOT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_requests' AND COLUMN_NAME = 'wanted_seats');
SET @sql := IF(@tbl = 1 AND @col = 0,
  'ALTER TABLE `ticket_requests` ADD COLUMN `wanted_seats` VARCHAR(60) NULL DEFAULT NULL COMMENT ''change_seat: seat label(s) asked for, as printed (A5,A6)'' AFTER `seats`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
