-- =====================================================================
--  Upgrade 2026-10-09 — Website content: places to visit, tour packages
--  and tour enquiries (the "S Hari Global ecosystem" pages).
--
--      php tests/apply-sql.php database/upgrade-2026-10-09-content.sql
--
--  Serves /explore (travel_places), /tours + /tours/<slug> (tour_packages)
--  and api/tour-enquiry.php (new columns on enquiries). The India-Nepal
--  timeline is a PHP data file (includes/content/indianepal.php), not a
--  table - it is a dozen reviewed paragraphs, edited with the copy.
--
--  Fully idempotent: CREATE TABLE IF NOT EXISTS, every ADD COLUMN / ADD
--  INDEX guarded against information_schema. Additive only - no existing
--  column or row changes, so the code may ship before OR after this file
--  (the pages show a "coming soon" state and the enquiry API stores the
--  basic columns until it has run).
--
--  NB: no semicolon inside any comment or quoted string - tests/apply-sql.php
--  splits this file on the statement terminator.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Places to visit (Nepal, India, near the border)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `travel_places` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`            VARCHAR(80)  NOT NULL COMMENT 'url-safe key - lumbini, pokhara',
  `country`         CHAR(2)      NOT NULL DEFAULT 'NP' COMMENT 'NP or IN',
  `region`          VARCHAR(80)  NULL COMMENT 'province / state / district as written',
  `category`        VARCHAR(20)  NOT NULL DEFAULT 'heritage' COMMENT 'pilgrimage, nature, heritage, wildlife, city, adventure, lake, hill',
  `name_en`         VARCHAR(120) NOT NULL,
  `name_hi`         VARCHAR(160) NULL,
  `name_ne`         VARCHAR(160) NULL,
  `blurb_en`        TEXT         NULL,
  `blurb_hi`        TEXT         NULL,
  `blurb_ne`        TEXT         NULL,
  `best_season_en`  VARCHAR(160) NULL,
  `best_season_ne`  VARCHAR(200) NULL,
  `how_to_reach_en` TEXT         NULL,
  `how_to_reach_ne` TEXT         NULL,
  `unesco`          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = UNESCO World Heritage site',
  `lat`             DECIMAL(10,7) NULL,
  `lng`             DECIMAL(10,7) NULL,
  `near_stop`       VARCHAR(80)  NULL COMMENT 'nearest point of our route - Rupaidiha, Nepalgunj, Ahmedabad',
  `km_hint`         INT          NULL COMMENT 'approximate road km from near_stop - a hint, never a promise',
  `image`           VARCHAR(190) NULL COMMENT 'uploads/places/... re-encoded by admin/places.php',
  `image_credit`    VARCHAR(190) NULL,
  `is_featured`     TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`      INT          NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 hides it from the website - never deleted',
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_place_slug` (`slug`),
  KEY `ix_place_list` (`is_active`, `country`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Tour packages (partner-operated beyond the Rupaidiha border)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tour_packages` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`          VARCHAR(80)  NOT NULL,
  `status`        ENUM('draft','published','archived') NOT NULL DEFAULT 'draft' COMMENT 'only published rows are public',
  `country`       ENUM('Nepal','India','Both') NOT NULL DEFAULT 'Nepal',
  `title_en`      VARCHAR(160) NOT NULL,
  `title_hi`      VARCHAR(200) NULL,
  `title_ne`      VARCHAR(200) NULL,
  `summary_en`    TEXT         NULL,
  `summary_hi`    TEXT         NULL,
  `summary_ne`    TEXT         NULL,
  `route_en`      VARCHAR(255) NULL,
  `route_ne`      VARCHAR(255) NULL,
  `days`          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `nights`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `highlights_en` TEXT         NULL COMMENT 'JSON array of strings',
  `highlights_ne` TEXT         NULL COMMENT 'JSON array of strings',
  `itinerary_json` TEXT        NULL COMMENT 'JSON list of days - day, en, ne, hi',
  `price_from`    DECIMAL(10,2) NULL COMMENT 'INR per person - NULL means price on request',
  `price_basis`   VARCHAR(60)  NULL COMMENT 'per person, twin sharing',
  `coach_leg`     ENUM('toNepal','toIndia','both','none') NOT NULL DEFAULT 'both' COMMENT 'which of our bus legs the package uses - our bus ends at Rupaidiha',
  `partner_name`  VARCHAR(120) NULL COMMENT 'who operates the part beyond the border',
  `min_pax`       TINYINT UNSIGNED NULL,
  `image`         VARCHAR(190) NULL,
  `image_credit`  VARCHAR(190) NULL,
  `is_featured`   TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`    INT          NOT NULL DEFAULT 0,
  `created_by`    INT UNSIGNED NULL COMMENT 'admins.id',
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_package_slug` (`slug`),
  KEY `ix_package_list` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Tour enquiries land in the existing enquiries inbox (source = tour).
--    status stays the old ENUM - never re-ALTERed here.
-- ---------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'enquiries' AND COLUMN_NAME = 'package_id');
SET @s := IF(@c = 0, 'ALTER TABLE `enquiries` ADD COLUMN `package_id` INT UNSIGNED NULL COMMENT ''tour_packages.id for a tour enquiry''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'enquiries' AND COLUMN_NAME = 'ref');
SET @s := IF(@c = 0, 'ALTER TABLE `enquiries` ADD COLUMN `ref` VARCHAR(16) NULL COMMENT ''TRV-000123 for a tour enquiry''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'enquiries' AND COLUMN_NAME = 'details');
SET @s := IF(@c = 0, 'ALTER TABLE `enquiries` ADD COLUMN `details` TEXT NULL COMMENT ''the full enquiry - note stays the 255 char summary''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'enquiries' AND COLUMN_NAME = 'pax_adults');
SET @s := IF(@c = 0, 'ALTER TABLE `enquiries` ADD COLUMN `pax_adults` TINYINT UNSIGNED NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'enquiries' AND COLUMN_NAME = 'pax_children');
SET @s := IF(@c = 0, 'ALTER TABLE `enquiries` ADD COLUMN `pax_children` TINYINT UNSIGNED NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'enquiries' AND COLUMN_NAME = 'from_city');
SET @s := IF(@c = 0, 'ALTER TABLE `enquiries` ADD COLUMN `from_city` VARCHAR(80) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'enquiries' AND COLUMN_NAME = 'lang');
SET @s := IF(@c = 0, 'ALTER TABLE `enquiries` ADD COLUMN `lang` VARCHAR(5) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'enquiries' AND INDEX_NAME = 'ix_enquiries_ref');
SET @s := IF(@c = 0, 'ALTER TABLE `enquiries` ADD INDEX `ix_enquiries_ref` (`ref`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'enquiries' AND INDEX_NAME = 'ix_enquiries_source_phone');
SET @s := IF(@c = 0, 'ALTER TABLE `enquiries` ADD INDEX `ix_enquiries_source_phone` (`source`, `phone`, `created_at`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
