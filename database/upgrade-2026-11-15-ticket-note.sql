-- =====================================================================
--  upgrade-2026-11-15-ticket-note.sql
--
--  A short note the desk writes FOR THE PASSENGER, printed on the ticket
--  (owner, 15 Nov 2026: "customer lai discount amount / percentage ya kei
--  note haru lekhna milos ticket ma"). One nullable column on bookings:
--    ticket_note   VARCHAR(100)  "Diwali chhut · family of 4"  (may be NULL)
--
--  Not payments.admin_note: that one is the office's own remark and never
--  reaches the passenger. Only a signed-in desk (SmartBook Admin / Counter /
--  agent's own login) writes it; a customer never does.
--
--  Additive and re-runnable: added only when missing. Code that runs before
--  this file sees no column and stores nothing (CounterDesk::hasColumn).
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'ticket_note');
SET @sql := IF(@col = 0,
  'ALTER TABLE `bookings` ADD COLUMN `ticket_note` VARCHAR(100) NULL DEFAULT NULL COMMENT ''desk note printed on the ticket (optional)''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
