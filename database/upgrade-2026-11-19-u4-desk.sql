-- =====================================================================
--  upgrade-2026-11-19-u4-desk.sql  (unit u4-desk, 19 Nov 2026)
--
--  1. bookings.alt_phone / alt_country_code - the passenger's optional second
--     WhatsApp number and its own country (already on live since 4 Oct 2026;
--     added here only where missing).
--  2. bookings.cash_note - the desk's optional "पैसा कहिले? · When will they
--     pay" on a cash sale: {"when":"full|partial|later","amount":..,"date":..,
--     "note":..}. A note only - it never confirms or changes a payment.
--  3. settings sales_close_clock - the sales closing clock (NP-3). Seeded with
--     the owner's rule: today's r7 closes 17:00 Nepal time for every
--     non-office channel, today's r2 closes 19:00 IST for online customers.
--     '{}' switches it off. INSERT IGNORE: an edited value is never replaced.
--
--  Idempotent: safe to run twice. MariaDB 10.3+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `alt_phone` VARCHAR(20) NULL DEFAULT NULL
    COMMENT 'second WhatsApp number of the booker (the other country SIM) - the ticket goes there too';

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `alt_country_code` VARCHAR(4) NULL DEFAULT NULL
    COMMENT '977 or 91 - dialling code of alt_phone';

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `cash_note` TEXT NULL DEFAULT NULL
    COMMENT 'JSON {when: full|partial|later, amount?, date?, note?} - desk note on a cash sale, never a payment';

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
  ('sales_close_clock',
   '{"r7":{"all":"17:00","tz":"Asia/Kathmandu"},"r2":{"online":"19:00","tz":"Asia/Kolkata"}}',
   'json', 'booking',
   'Sales closing clock for TODAY''s bus per route code (all/online/agent/counter/staff/whatsapp/ai = HH:MM, tz). superadmin + manager never blocked. {} = off',
   0);

-- A first run of this file stored a 200-character label that varchar(191) cut
-- (losing the '{} = off' hint): put the short one back. Label text only.
UPDATE `settings`
   SET `label` = 'Sales closing clock for TODAY''s bus per route code (all/online/agent/counter/staff/whatsapp/ai = HH:MM, tz). superadmin + manager never blocked. {} = off'
 WHERE `skey` = 'sales_close_clock' AND CHAR_LENGTH(`label`) >= 191;
