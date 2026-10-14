-- =====================================================================
--  15 Oct 2026 - SmartBook v5: an idempotency key answers only the SAME
--  request. smartbook_requests.fingerprint = sha256 of what was asked
--  (mobile, name, party, berths, payment, date, pickup); a key replayed with
--  a different request is refused ("tap again" = a new key), so a counter
--  that retries after a timeout can never hand the next customer the
--  previous customer's ticket.
--
--      php tests/apply-sql.php database/upgrade-2026-10-15-smartbook-fingerprint.sql
--
--  Additive, re-runnable.
-- =====================================================================

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'smartbook_requests' AND column_name = 'fingerprint');
SET @s := IF(@c = 0,
  'ALTER TABLE `smartbook_requests` ADD COLUMN `fingerprint` CHAR(64) NULL DEFAULT NULL AFTER `mode`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
