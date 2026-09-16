-- =====================================================================
--  Paid-but-pending bookings must never auto-expire
--  27 Aug 2026
--
--  THE BUG THIS REPAIRS
--  --------------------
--  A pending booking carries `expires_at = created + booking_expiry_minutes`
--  (default 120). cron/expire.php sweeps anything past that: status becomes
--  'expired' and the seats are released back into the pool.
--
--  Nothing cleared that clock when the CUSTOMER submitted payment proof. So
--  a passenger who transferred the fare and sent the UTR at minute 10 was
--  still expired at minute 120 if no admin had verified them yet. Their
--  seats were freed and resellable while their money sat in the company UPI
--  account — and because 'expired' is a terminal state, their re-submitted
--  proof was refused as well.
--
--  Owner's rule: "admin le accept nagarne bela samma pending ma basnu parne."
--  Once proof exists the booking is waiting on the OFFICE, not the customer.
--
--  The code fix (includes/booking.php + cron/expire.php) stops this
--  happening again. This file repairs rows that were already at risk when
--  the fix shipped.
--
--  SAFE BY DESIGN
--    * Touches ONLY `bookings.expires_at`, and only for rows that are
--      still 'pending' AND already carry a UTR or an uploaded screenshot.
--    * Sets it to NULL. Status is deliberately NOT changed — these stay
--      pending so the admin still verifies them by hand. Nothing is
--      confirmed, cancelled, priced or refunded by this script.
--    * Idempotent: re-running it changes nothing further.
--    * No table is created, dropped or altered.
--
--  ⚠️ TAKE A DATABASE BACKUP FIRST
--     hPanel -> Databases -> phpMyAdmin -> Export.
-- =====================================================================


-- ---------------------------------------------------------------------
-- STEP 1 — LOOK BEFORE YOU LEAP.
-- Run this on its own first. It changes nothing; it lists exactly which
-- bookings step 2 will touch. If it returns 0 rows, nothing was at risk
-- and step 2 is a no-op you can still run safely.
-- ---------------------------------------------------------------------
SELECT  b.id,
        b.pnr,
        b.contact_phone,
        b.total_amount,
        b.created_at,
        b.expires_at,
        (SELECT p.utr_number FROM payments p
          WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1)      AS utr,
        (SELECT COUNT(*) FROM payment_screenshots s
          WHERE s.booking_id = b.id)                                 AS screenshots,
        CASE WHEN b.expires_at < NOW() THEN 'ALREADY OVERDUE'
             ELSE 'still in window' END                              AS risk
  FROM  bookings b
 WHERE  b.status = 'pending'
   AND  b.expires_at IS NOT NULL
   AND  ( EXISTS (SELECT 1 FROM payments p
                   WHERE p.booking_id = b.id
                     AND TRIM(COALESCE(p.utr_number, '')) <> '')
       OR EXISTS (SELECT 1 FROM payment_screenshots s
                   WHERE s.booking_id = b.id) )
 ORDER BY b.expires_at ASC;


-- ---------------------------------------------------------------------
-- STEP 2 — THE REPAIR.
-- Takes every paid-but-unverified booking off the expiry clock. They stay
-- 'pending' and still need an admin to accept or reject them.
-- ---------------------------------------------------------------------
UPDATE  bookings b
   SET  b.expires_at = NULL
 WHERE  b.status = 'pending'
   AND  b.expires_at IS NOT NULL
   AND  ( EXISTS (SELECT 1 FROM payments p
                   WHERE p.booking_id = b.id
                     AND TRIM(COALESCE(p.utr_number, '')) <> '')
       OR EXISTS (SELECT 1 FROM payment_screenshots s
                   WHERE s.booking_id = b.id) );


-- ---------------------------------------------------------------------
-- STEP 3 — VERIFY. This must return 0.
-- ---------------------------------------------------------------------
SELECT COUNT(*) AS still_at_risk
  FROM bookings b
 WHERE b.status = 'pending'
   AND b.expires_at IS NOT NULL
   AND ( EXISTS (SELECT 1 FROM payments p
                  WHERE p.booking_id = b.id
                    AND TRIM(COALESCE(p.utr_number, '')) <> '')
      OR EXISTS (SELECT 1 FROM payment_screenshots s
                  WHERE s.booking_id = b.id) );


-- ---------------------------------------------------------------------
-- STEP 4 — bookings ALREADY killed by the old sweep, if any.
--
-- Reviewed BY HAND, deliberately: reviving one of these is only correct if
-- the seat has not since been resold, and only a human can decide that.
-- The script will not do it for you. `seats_now_taken` tells you whether
-- the berths are free; if it is 0 the booking can safely be reopened from
-- Admin -> Bookings.
-- ---------------------------------------------------------------------
SELECT  b.id, b.pnr, b.contact_phone, b.total_amount, b.cancel_reason,
        (SELECT p.utr_number FROM payments p
          WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS utr,
        bl.travel_date,
        (SELECT COUNT(*) FROM booking_seats bs2
           JOIN booking_passengers bp2 ON bp2.booking_id = b.id
                                      AND bp2.seat_no = bs2.seat_no
          WHERE bs2.schedule_id = bl.schedule_id)               AS seats_now_taken
  FROM  bookings b
  LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
 WHERE  b.status = 'expired'
   AND  ( EXISTS (SELECT 1 FROM payments p
                   WHERE p.booking_id = b.id
                     AND TRIM(COALESCE(p.utr_number, '')) <> '')
       OR EXISTS (SELECT 1 FROM payment_screenshots s
                   WHERE s.booking_id = b.id) )
 ORDER BY b.created_at DESC;
