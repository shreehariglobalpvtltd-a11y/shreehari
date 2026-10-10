-- =====================================================================
--  Upgrade 2026-10-10 - BIZ: tour package audience + the four demo packages
--  (owner: "tourism destination package service, 3-4 demo packages, solo
--  and group introduced").
--
--      php tests/apply-sql.php database/upgrade-2026-10-10-biz.sql
--
--  1. tour_packages.audience - who the package suits (solo, couple, family,
--     group, pilgrim). Rendered as a chip on /tours, /tours/<slug>, the home
--     featured cards (api/explore.php) and the /business tour mini cards.
--     NULL = no chip. Edited in Admin -> Website content -> Tour packages.
--  2. Four of the 9 Oct seeded packages become the demo set: audience set,
--     featured on the home page, sorted first. Only rows that are still
--     untouched (is_featured = 0 AND audience IS NULL) change, so an office
--     edit made after this file ran is never overwritten.
--
--  Fully idempotent: the ADD COLUMN is guarded against information_schema
--  (the 9 Oct pattern), every UPDATE is a no-op the second time. Additive
--  only - no existing column changes, no row deleted. The code reads
--  $row['audience'] ?? '' so it may ship before OR after this file.
--
--  NB: no semicolon inside any comment or quoted string - tests/apply-sql.php
--  splits this file on the statement terminator.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. tour_packages.audience
-- ---------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'tour_packages' AND COLUMN_NAME = 'audience');
SET @s := IF(@c = 0, 'ALTER TABLE `tour_packages` ADD COLUMN `audience` VARCHAR(20) NULL COMMENT ''solo, couple, family, group, pilgrim - NULL means no chip'' AFTER `min_pax`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 2. The four demo packages (group / solo / family / pilgrim)
-- ---------------------------------------------------------------------
UPDATE `tour_packages` SET `audience` = 'group', `is_featured` = 1, `sort_order` = 1
 WHERE `slug` = 'lumbini-pokhara-kathmandu-circuit' AND `is_featured` = 0 AND `audience` IS NULL;

UPDATE `tour_packages` SET `audience` = 'solo', `is_featured` = 1, `sort_order` = 2
 WHERE `slug` = 'bardiya-jungle-safari-from-nepalgunj' AND `is_featured` = 0 AND `audience` IS NULL;

UPDATE `tour_packages` SET `audience` = 'family', `is_featured` = 1, `sort_order` = 3
 WHERE `slug` = 'gujarat-heritage-trail' AND `is_featured` = 0 AND `audience` IS NULL;

UPDATE `tour_packages` SET `audience` = 'pilgrim', `is_featured` = 1, `sort_order` = 4
 WHERE `slug` = 'buddhist-circuit-lumbini-kushinagar-sarnath-shravasti' AND `is_featured` = 0 AND `audience` IS NULL;
