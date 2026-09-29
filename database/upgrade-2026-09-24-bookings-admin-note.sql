-- 24 Sep 2026 — bookings.admin_note
--
-- BookingService::cancelSeat() writes the staff reason for a per-seat cancel
-- to bookings.admin_note (booking.php, "Per-seat cancel" update). The column
-- exists on the live register but was never in schema.sql or any upgrade, so
-- a database built from the repository (CI, a fresh install, a restore drill
-- into a new host) failed the second a staff member cancelled one seat with
-- a reason. Additive, idempotent.
ALTER TABLE `bookings` ADD COLUMN IF NOT EXISTS `admin_note` VARCHAR(255) NULL AFTER `cancel_reason`;
