-- =====================================================================
--  upgrade-2026-11-16c-X1-money.sql  (16 Nov 2026, Master v2.0 unit X1)
--
--  P4-5d  RETURN-JOURNEY discount. bookings.return_discount holds the
--         money taken off because the same mobile already holds a live
--         ticket the other way (Gujarat <-> Rupaidiha) within
--         return_discount_days of this one. Counted in the total exactly
--         like group_discount. Switch: return_discount_pct - 0 = off (the
--         default). is_public = 1 so the website checkout knows whether to
--         ask the server at all (no number, no request).
--  P8-3f / P4-4d  EXTRA LUGGAGE at the staff desk only (SmartBook desk,
--         Quick Ticket): bookings.luggage_pieces x luggage_fee_per_piece =
--         bookings.luggage_fee, added to the total, never commissionable,
--         printed on the ticket. luggage_fee_per_piece 0 = off (the
--         default; the desk field stays hidden).
--         INFANT on the lap (no berth): bookings.infant_count, charged
--         infant_seat_fare each (default 0 = free) into bookings.infant_fee.
--         Switch: infant_seat_on - 0 = off (the default; the desk field
--         stays hidden). The existing EX1..EX20 extra seats (a real seat at
--         the full fare, extra_seats_per_bus) are untouched.
--
--  Nothing changes for any passenger, agent or total after this runs:
--  every new column is 0 and every switch is stored at its off value.
--  The code works before this runs (it asks for each column first and
--  then simply never applies the rule). Additive and safe to re-run:
--  ADD COLUMN IF NOT EXISTS, INSERT IGNORE keeps any value already stored.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `return_discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `group_discount`,
  ADD COLUMN IF NOT EXISTS `luggage_pieces` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `booking_fee`,
  ADD COLUMN IF NOT EXISTS `luggage_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `luggage_pieces`,
  ADD COLUMN IF NOT EXISTS `infant_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `luggage_fee`,
  ADD COLUMN IF NOT EXISTS `infant_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `infant_count`;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('return_discount_pct', '0', 'float', 'booking', 'Return-journey discount % (same mobile already holds a ticket the other way within the days below). 0 = off', 1),
  ('return_discount_days', '30', 'int', 'booking', 'Return-journey discount: the other-way ticket must travel within this many days', 0),
  ('luggage_fee_per_piece', '0', 'int', 'booking', 'Extra luggage fee per piece at the staff desk (Rs). 0 = off, the desk field stays hidden. Not commissionable', 0),
  ('infant_seat_on', '0', 'bool', 'booking', 'Desk may add an infant on the lap (no berth) to a ticket. Off = the field stays hidden', 0),
  ('infant_seat_fare', '0', 'int', 'booking', 'Fare for each infant on the lap (Rs). 0 = free. Used only while infant_seat_on is on', 0);
