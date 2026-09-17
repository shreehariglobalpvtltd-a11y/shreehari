-- =====================================================================
--  Upgrade 2026-09 — Passenger photo / ID-document store
--  17 Sep 2026
--
--  Run ONCE on the live database (phpMyAdmin -> SQL tab -> paste -> Go),
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-09-passenger-documents.sql
--
--  Fully idempotent and additive: CREATE TABLE IF NOT EXISTS + INSERT
--  IGNORE only. Nothing existing is altered.
--
--  WHY A TABLE AND NOT A COLUMN
--  ----------------------------
--  A passenger can carry several images (a face photo, the front and the
--  back of a citizenship / Aadhaar / passport, an old paper ticket), each
--  a different kind and each replaceable on its own. One `photo_path`
--  column on booking_passengers would force the office to choose which
--  one to keep. So it is one row per file, keyed on the passenger row,
--  and it CASCADES: when a booking (or one of its passengers) is deleted
--  the document rows go with it, exactly like payment_screenshots.
--
--  WHERE THE FILES LIVE
--  --------------------
--  uploads/passengers/YYYY/MM/<random>.<ext> — `file_path` is stored
--  RELATIVE to uploads/ like payment_screenshots.file_path. These are
--  identity documents (PII): they are NEVER linked by their /uploads URL.
--  The only way to see one is admin/passenger-doc.php?id=<row>, which
--  requires a signed-in staff session with bookings.view and applies the
--  same sold_by_admin_id scope a counter agent gets everywhere else.
--
--    kind     photo | id_front | id_back | other
--    sha256   fingerprint of the stored bytes (duplicate / tamper check)
--    uploaded_by_admin_id  admins.id of the desk that attached it (NULL
--             is reserved for a future customer self-upload)
--
--  Master switch: settings.passenger_docs_on (bool, default 1) hides the
--  upload / list block on the ticket page when the office does not want
--  documents collected. The reader page keeps serving what already exists.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `passenger_documents` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`           BIGINT UNSIGNED NOT NULL,
  `passenger_id`         BIGINT UNSIGNED NOT NULL,
  `kind`                 ENUM('photo','id_front','id_back','other') NOT NULL DEFAULT 'photo',
  `file_path`            VARCHAR(255) NOT NULL COMMENT 'relative to uploads/',
  `mime_type`            VARCHAR(60) NOT NULL,
  `file_size`            INT UNSIGNED NOT NULL DEFAULT 0,
  `sha256`               CHAR(64) NULL,
  `uploaded_by_admin_id` BIGINT UNSIGNED NULL,
  `uploaded_ip`          VARCHAR(45) NULL,
  `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_pdoc_pax` (`passenger_id`),
  KEY `ix_pdoc_booking` (`booking_id`),
  CONSTRAINT `fk_pdoc_pax` FOREIGN KEY (`passenger_id`)
    REFERENCES `booking_passengers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pdoc_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Master switch for the ticket-page upload block (1 = on, today's default).
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('passenger_docs_on','1','bool','booking','Passenger photo / ID uploads on the ticket page',0);
