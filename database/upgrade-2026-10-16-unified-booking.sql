-- =====================================================================
--  16 Oct 2026 - One booking screen (SmartBook) for customer, agent and
--  counter, Cash verification, Online QR popup.
--
--  Owner ask: "3 ta booking system lai eutai ma ... customer ko cash wala
--  lai verification ko lagi details compulsory, online ma click garda
--  sidhai bank ko QR wala popup, 60 second timer".
--
--  Run ONCE on the live database (safe to re-run - IF NOT EXISTS / IGNORE):
--      mysql shari < database/upgrade-2026-10-16-unified-booking.sql
--
--  WHAT
--    1. bookings.contact_address - the village / city and district a Cash
--       (pay at boarding) customer gives for verification. NULL for every
--       other booking.
--    2. settings
--         cod_verify_on         1   Cash needs full name + address + an ID
--         smartbook_qr_seconds  60  the Online QR popup countdown
--
--  Additive only.
--
--  ROLLBACK
--    ALTER TABLE bookings DROP COLUMN contact_address
--    DELETE FROM settings WHERE skey IN ('cod_verify_on','smartbook_qr_seconds')
-- =====================================================================

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `contact_address` VARCHAR(191) NULL DEFAULT NULL
  COMMENT 'Cash pay-at-boarding customer: village or city and district (verification)'
  AFTER `alt_country_code`;

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('cod_verify_on',        '1',  'bool', 'booking', 'Cash (pay at boarding) asks the customer for full name, address and an ID for verification. Online stays name + mobile', 1),
 ('smartbook_qr_seconds', '60', 'int',  'booking', 'Online QR popup countdown in seconds (the berths stay held for smartbook_online_hold_min)', 1);
