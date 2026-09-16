-- =====================================================================
--  Upgrade: counter bulk-booking cap (2026-09-05). Run ONCE on an
--  existing live database; safe to re-run (INSERT IGNORE).
--
--  Adds counter_max_seats_per_booking (default 20): the per-booking seat
--  cap for a STAFF sale (counter mode, seat-map walk-in, paper register).
--  Anonymous customers keep max_seats_per_booking (6). Enforced in
--  BookingService::create()/counterSale(); the customer app raises its
--  picker cap only for a signed-in selling staff session (SHG_BOOT.staff.
--  maxSeats). is_public=0 — guests must never learn the staff cap.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`)
VALUES ('counter_max_seats_per_booking','20','int','booking',
        'Max seats per booking for a staff/counter sale (bulk booking)',0);
