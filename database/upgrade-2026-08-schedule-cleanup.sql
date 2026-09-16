-- ============================================================
-- upgrade-2026-08-schedule-cleanup.sql
-- Batch D: canonical production schedule reset
-- Generated 2026-08-28 15:47 IST from live discovery.
--
-- Preserves ALL bookings, payments and customer records (touches
-- ONLY the `schedules` table). Every DELETE below carries a NOT
-- EXISTS safety re-check against booking_legs, so even if a new
-- booking landed on one of these ids since discovery, its schedule
-- row is spared.
--
-- Wrap in a transaction. Review counts before COMMIT.
-- ============================================================

START TRANSACTION;

-- Step 1. Silent-close 7 stale schedules that carry real confirmed
-- bookings. Setting status='departed' directly (not via
-- TripNotify::markTrip) means NO WhatsApp/SMS is fired. Bookings
-- themselves are untouched. Owner may fire retro-notifications later
-- via admin/trips.php -> "Bus started" per row if desired.
--
--   sched #2   2026-08-05 13:00  r2  confirmed=1
--   sched #3   2026-08-07 11:00  r1  confirmed=1  (r1 is inactive now)
--   sched #6   2026-08-12 13:00  r2  confirmed=1
--   sched #14  2026-08-23 13:00  r2  confirmed=1
--   sched #19  2026-08-24 13:00  r2  confirmed=1
--   sched #45  2026-08-26 13:00  r2  confirmed=1
--   sched #73  2026-08-28 13:00  r2  confirmed=2  (today's real trip)
UPDATE schedules
   SET status = 'departed'
 WHERE id IN (2, 3, 6, 14, 19, 45, 73)
   AND status = 'scheduled';
-- expected: 7 rows affected

-- Step 2. Delete 42 empty stale schedules (past + zero booking_legs
-- + older than 1 day). The extra NOT EXISTS clause guards against
-- a booking landing in the race window between discovery and this
-- statement — such a schedule is preserved even though we listed it.
DELETE FROM schedules
 WHERE id IN (1,4,5,7,8,9,10,11,12,13,15,16,17,18,20,21,22,23,24,25,26,27,33,35,36,37,44,46,47,48,54,55,57,59,64,65,66,67,68,69,70,71)
   AND NOT EXISTS (SELECT 1 FROM booking_legs bl WHERE bl.schedule_id = schedules.id);
-- expected: 42 rows affected

-- Step 3. Post-condition audit (informational — this SELECT is
-- consumed by the harness / owner and never mutates anything).
--
-- Expected AFTER the two statements above:
--   schedules total:              80 - 42 = 38
--   schedules status='scheduled': (was 80, minus 42 deleted, minus 7
--                                  updated) = 31
--   bookings total:               13   (unchanged)
--   bookings confirmed:           11   (unchanged)
--   payments total:               13   (unchanged)
--   booking_legs total:           13   (unchanged)
SELECT
  (SELECT COUNT(*) FROM schedules)                       AS schedules_total,
  (SELECT COUNT(*) FROM schedules WHERE status = 'scheduled') AS still_scheduled,
  (SELECT COUNT(*) FROM schedules WHERE status = 'departed')  AS marked_departed,
  (SELECT COUNT(*) FROM bookings)                        AS bookings_total,
  (SELECT COUNT(*) FROM bookings WHERE status = 'confirmed') AS bookings_confirmed,
  (SELECT COUNT(*) FROM payments)                        AS payments_total,
  (SELECT COUNT(*) FROM booking_legs)                    AS booking_legs_total;

-- If the counts above match expectation, COMMIT.
-- If anything is off (bookings/payments/legs changed at all, or
-- schedules changed by more than 42+0 rows), ROLLBACK and investigate.
--
--   COMMIT;
--   -- or --
--   ROLLBACK;
