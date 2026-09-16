-- =====================================================================
--  Upgrade 2026-09 — Contact country code on every booking
--
--  Run ONCE on the live database (hPanel -> phpMyAdmin -> your database ->
--  SQL tab -> paste -> Go), or from the CLI:
--      php -c .claude/php-dev.ini tests/apply-sql.php database/upgrade-2026-09-booking-country.sql
--
--  Fully idempotent: the ADD COLUMN is guarded against information_schema and
--  the backfill only touches rows whose country is still unknown, so re-running
--  is a no-op. Safe to run while the site is live.
--
--  WHY THIS EXISTS
--  ---------------
--  India and Nepal share the same 10-digit mobile format, so a stored
--  contact_phone ("9812345678") is a valid number in BOTH countries. The
--  notifier used to prepend a single default country code (91 = India) to every
--  bare number, which meant a Nepali customer's ticket — name, seat, PNR — was
--  WhatsApped to whoever owns that number in India. The country cannot be
--  recovered from the digits; it must be captured. This column records the
--  dialing code chosen for THIS ticket's contact number so the send routes to
--  the right person. NULL keeps the old default-country behaviour for rows that
--  predate the capture.
-- =====================================================================

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'contact_country_code');
SET @sql := IF(@have = 0,
  'ALTER TABLE `bookings` ADD COLUMN `contact_country_code` VARCHAR(5) NULL COMMENT ''dialing code for contact_phone (977 or 91) — India and Nepal share 10-digit mobiles so it is captured, not guessed'' AFTER `contact_phone`',
  'SELECT ''bookings.contact_country_code already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- One-time backfill: for bookings whose country is still unknown, adopt the
-- country the customer chose on their account (users.country_code) when the
-- contact number matches an account. Only 977/91 are copied; anything else is
-- left NULL so the send falls back to the configured default. Never overwrites
-- a value already set, so this line is safe to re-run.
UPDATE `bookings` b
  JOIN `users` u ON u.`phone` = b.`contact_phone`
   SET b.`contact_country_code` = u.`country_code`
 WHERE b.`contact_country_code` IS NULL
   AND u.`country_code` IN ('977', '91');
