-- =====================================================================
--  Upgrade 2026-10-09 — the PUBLIC office list (site facts).
--
--  Owner, 9 Oct 2026: "office haru kaha kaha xa sabai lai listed banau —
--  Mehsana head, NPJ, Rajkot, AMD, Surat … expand hudai xa vandinu paryo".
--  counter_locations becomes the ONE office list the website reads
--  (CounterDesk::publicOffices() -> SiteFacts -> home page, JSON-LD,
--  /offices). Every column below is WEBSITE-ONLY: none of them prints on a
--  ticket, so editing them never re-renders an issued ticket.
--
--      php tests/apply-sql.php database/upgrade-2026-10-09-site-facts.sql
--
--  Fully idempotent: every ADD COLUMN guarded against information_schema,
--  the seed written ONCE (only on the run that added show_public / lat), so
--  a later office edit is never overwritten by a re-run. Additive only —
--  the code reads these columns only when present (before the migration
--  the owner's five offices are listed from a built-in default).
--
--  PHONES ARE NOT SEEDED. An office's number is its desk phone, else the
--  town's number in the company line (company_counters), else — for the
--  Nepal partner office — the Nepal office line on Company details. The
--  website and the printed documents therefore agree by construction.
--
--  NB: no semicolon inside any quoted ALTER string — tests/apply-sql.php
--  splits this file on the statement terminator.
-- =====================================================================

SET NAMES utf8mb4;

-- show_public: 1 = listed on the website office list
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'counter_locations' AND COLUMN_NAME = 'show_public');
SET @added_show_public := IF(@c = 0, 1, 0);
SET @s := IF(@c = 0, 'ALTER TABLE `counter_locations` ADD COLUMN `show_public` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = listed on the website office list (site facts)''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- office_kind: head | branch | partner | border
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'counter_locations' AND COLUMN_NAME = 'office_kind');
SET @s := IF(@c = 0, 'ALTER TABLE `counter_locations` ADD COLUMN `office_kind` VARCHAR(12) NULL COMMENT ''head, branch, partner or border - the badge on the website''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- public_status: open | coming_soon
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'counter_locations' AND COLUMN_NAME = 'public_status');
SET @s := IF(@c = 0, 'ALTER TABLE `counter_locations` ADD COLUMN `public_status` VARCHAR(12) NOT NULL DEFAULT ''open'' COMMENT ''open or coming_soon - a coming-soon office shows no phone''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- name_ne / name_hi: the town as Nepali / Hindi readers write it
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'counter_locations' AND COLUMN_NAME = 'name_ne');
SET @s := IF(@c = 0, 'ALTER TABLE `counter_locations` ADD COLUMN `name_ne` VARCHAR(120) NULL COMMENT ''office name in Nepali (website)''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'counter_locations' AND COLUMN_NAME = 'name_hi');
SET @s := IF(@c = 0, 'ALTER TABLE `counter_locations` ADD COLUMN `name_hi` VARCHAR(120) NULL COMMENT ''office name in Hindi (website)''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- address_ne: the address in Nepali (website)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'counter_locations' AND COLUMN_NAME = 'address_ne');
SET @s := IF(@c = 0, 'ALTER TABLE `counter_locations` ADD COLUMN `address_ne` VARCHAR(255) NULL COMMENT ''address in Nepali (website)''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- lat / lng: the map pin
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'counter_locations' AND COLUMN_NAME = 'lat');
SET @added_lat := IF(@c = 0, 1, 0);
SET @s := IF(@c = 0, 'ALTER TABLE `counter_locations` ADD COLUMN `lat` DECIMAL(10,7) NULL COMMENT ''map pin latitude (website)''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'counter_locations' AND COLUMN_NAME = 'lng');
SET @s := IF(@c = 0, 'ALTER TABLE `counter_locations` ADD COLUMN `lng` DECIMAL(10,7) NULL COMMENT ''map pin longitude (website)''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- whatsapp / contact_person
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'counter_locations' AND COLUMN_NAME = 'whatsapp');
SET @s := IF(@c = 0, 'ALTER TABLE `counter_locations` ADD COLUMN `whatsapp` VARCHAR(40) NULL COMMENT ''the office WhatsApp, when it has one (website)''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'counter_locations' AND COLUMN_NAME = 'contact_person');
SET @s := IF(@c = 0, 'ALTER TABLE `counter_locations` ADD COLUMN `contact_person` VARCHAR(80) NULL COMMENT ''who answers at this office (website)''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- The owner's office list, written ONCE (the run that added show_public).
-- Mehsana = head office, Ahmedabad / Surat / Rajkot = branches, Nepalgunj
-- = the Nepal partner office. Baroda stays a pickup and a desk, not a
-- listed office. Names only - no phone, no address is invented here.
-- ---------------------------------------------------------------------
UPDATE `counter_locations` SET `show_public` = 1, `office_kind` = 'head',
       `name_ne` = 'मेहसाणा', `name_hi` = 'मेहसाणा'
 WHERE @added_show_public = 1 AND `code` = 'MSA';
UPDATE `counter_locations` SET `show_public` = 1, `office_kind` = 'branch',
       `name_ne` = 'अहमदाबाद', `name_hi` = 'अहमदाबाद'
 WHERE @added_show_public = 1 AND `code` = 'AMD';
UPDATE `counter_locations` SET `show_public` = 1, `office_kind` = 'branch',
       `name_ne` = 'सुरत', `name_hi` = 'सूरत'
 WHERE @added_show_public = 1 AND `code` = 'SRT';
UPDATE `counter_locations` SET `show_public` = 1, `office_kind` = 'branch',
       `name_ne` = 'राजकोट', `name_hi` = 'राजकोट'
 WHERE @added_show_public = 1 AND `code` = 'RJT';
UPDATE `counter_locations` SET `show_public` = 1, `office_kind` = 'partner',
       `name_ne` = 'नेपालगञ्ज', `name_hi` = 'नेपालगंज'
 WHERE @added_show_public = 1 AND `code` = 'NPJ';

-- The head office map pin the site's own map has always used (15-nav.js).
UPDATE `counter_locations` SET `lat` = 23.6008959, `lng` = 72.3807235
 WHERE @added_lat = 1 AND `code` = 'MSA' AND `lat` IS NULL;
