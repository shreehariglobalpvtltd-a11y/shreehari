-- =====================================================================
--  upgrade-2026-10-31-idc-counter.sql — who CUT the ticket (31 Oct 2026)
--
--  Owner: "agent, staff counter mode bata ticket katna, chalani herna,
--  ticket management garna, aafule kateko ticket ko seat shift garna milos".
--
--  bookings.sold_by_admin_id is the agent credited with a sale; a counter
--  clerk's desk sale is credited to the agent picked (or SHG-0001) right
--  after it is made (Booking::attributeDeskSale), so "the tickets I cut"
--  was lost for counter staff. booked_by_admin_id keeps the person who
--  actually sold it at the counter / agent desk and is never rewritten.
--  NULL = a customer's own online booking (or history before this column).
--
--  Backfill: the cash a desk sale took stays on the clerk who took it
--  (agent_ledger account 'cash' is never moved by reassignSeller), so that
--  row names the clerk; other staff sales fall back to sold_by.
--  Idempotent: safe to run twice.
-- =====================================================================
SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'booked_by_admin_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE `bookings` ADD COLUMN `booked_by_admin_id` BIGINT UNSIGNED NULL COMMENT ''the staff member / agent who cut the ticket, never rewritten - NULL = online'' AFTER `sold_by_admin_id`, ADD INDEX `ix_bookings_booked_by` (`booked_by_admin_id`)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE `bookings` b
  JOIN (SELECT booking_id, MIN(agent_admin_id) AS clerk
          FROM agent_ledger
         WHERE account = 'cash' AND booking_id IS NOT NULL
         GROUP BY booking_id) c ON c.booking_id = b.id
   SET b.booked_by_admin_id = c.clerk
 WHERE b.booked_by_admin_id IS NULL;

UPDATE `bookings`
   SET booked_by_admin_id = sold_by_admin_id
 WHERE booked_by_admin_id IS NULL
   AND sold_by_admin_id IS NOT NULL
   AND source IN ('agent', 'counter', 'admin');
