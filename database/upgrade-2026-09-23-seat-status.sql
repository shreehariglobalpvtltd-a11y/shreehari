-- =====================================================================
--  upgrade-2026-09-23-seat-status.sql - the live seat-status PNG card sent
--  to the office / agent WhatsApp on every confirmed or cancelled booking
--  (includes/seatstatuspng.php, hooked in includes/events.php).
--  UI/UX v3 brief §9, 23 Sep 2026.
--
--  Additive and re-runnable: three settings rows. Ships OFF.
-- =====================================================================
SET NAMES utf8mb4;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('seat_status_wa_on',       '0', 'bool',   'whatsapp', 'Send the seat-status card (PNG) to the office / agent WhatsApp numbers on every confirmed or cancelled booking', 0),
('seat_status_wa_numbers',  '',  'string', 'whatsapp', 'Seat-status card recipients: WhatsApp numbers with country code, comma-separated (blank = the admin WhatsApp number)', 0),
('seat_status_wa_template', '',  'string', 'whatsapp', 'Approved template name with an IMAGE header for the seat-status card (blank = plain image message; needed outside the 24h window on Meta / Gupshup)', 0);
