-- =====================================================================
--  Upgrade 2026-08 — Sequential ticket numbers + 'official' office role
--  Run ONCE on the live database (phpMyAdmin → SQL tab, or
--  `mysql shari_db < database/upgrade-2026-08-ticketno-official.sql`).
--
--  Safe & idempotent: CREATE ... IF NOT EXISTS, and the ALTER just widens
--  an ENUM. Existing bookings/PNRs are untouched — only NEW bookings get
--  the SHG-<year>-<00001> format. Until this runs, checkout keeps working
--  and simply issues the old-style PNR (nextTicketNo() falls back).
-- =====================================================================

-- 1) Sequential ticket-number counter — one row per calendar year.
--    The first booking of a year auto-seeds it; the number then reads
--    SHG-2026-00001, SHG-2026-00002, … and resets to 00001 each new year.
CREATE TABLE IF NOT EXISTS `pnr_counters` (
  `yr`  SMALLINT UNSIGNED NOT NULL,
  `seq` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`yr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: to start this year somewhere other than 00001, seed the row.
-- e.g. start the next ticket at SHG-2026-00143:
--   INSERT INTO `pnr_counters` (`yr`,`seq`) VALUES (2026, 142)
--     ON DUPLICATE KEY UPDATE `seq` = 142;


-- 2) Add the 'official' staff role to the admins.role ENUM.
-- NOTE: 'agent' MUST stay in this list even though this migration is about
-- the 'official' role. upgrade-2026-08-agent-role.sql sets the same column to
-- the same ENUM *including* 'agent'; MODIFY replaces the whole definition, so
-- if this file ran second with 'agent' missing it would strip that value and
-- orphan every counter-agent account. Keeping both lists identical makes the
-- order these two are applied in irrelevant.
ALTER TABLE `admins`
  MODIFY `role` ENUM('superadmin','manager','accountant','support','scanner','official','agent')
  NOT NULL DEFAULT 'support';


-- 3) Give someone the official role. CHOOSE ONE of 3a / 3b.

-- ---- 3a) EASIEST — promote an EXISTING staff account (no new password):
--   UPDATE `admins` SET `role` = 'official' WHERE `username` = 'PUT_USERNAME_HERE';

-- ---- 3b) Create a NEW dedicated office account.
--   A bcrypt password CANNOT be written from SQL. First generate a hash on
--   the server (or any PHP machine):
--
--       php -r "echo password_hash('choose-a-password', PASSWORD_BCRYPT), PHP_EOL;"
--
--   Copy the full 60-character $2y$… string it prints and paste it in place
--   of the placeholder below, THEN run this statement. Until a real hash is
--   set, the account exists but cannot sign in (safe).
--
--   INSERT INTO `admins` (`username`,`password_hash`,`full_name`,`role`,`is_active`,`must_change_pw`)
--   VALUES ('office', 'PASTE_THE_$2y$_HASH_HERE', 'Office Staff', 'official', 1, 1)
--   ON DUPLICATE KEY UPDATE `role` = 'official';
