-- =====================================================================
--  Upgrade 2026-10-10 — HD visual pack: artwork for the travel guide and
--  the tour packages (owner: "the photos GPT made in HD - replace them").
--
--      php tests/apply-sql.php database/upgrade-2026-10-10-hd.sql
--
--  Nine rows get an image where they have NONE yet: five travel_places
--  (/explore) and four tour_packages (/tours). The files are the pack's
--  800 px mobile exports under assets/img/hd/ (ContentPages::imgUrl()
--  accepts assets/... paths). Every one is an inspired illustration, not a
--  photograph, so image_credit says so and the pages print it under the
--  picture ("Image: ..." label).
--
--  Fully idempotent and additive: each UPDATE touches a row only while its
--  image is NULL or empty, so an office upload (admin/places.php,
--  admin/tours.php) is never overwritten, and a second run changes nothing.
--  Both UPDATE blocks are guarded against information_schema so the file
--  also runs cleanly on a database that has not seen
--  upgrade-2026-10-09-content.sql yet (then it does nothing).
--
--  NB: no semicolon inside any comment or quoted string - tests/apply-sql.php
--  splits this file on the statement terminator.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. travel_places (/explore)
-- ---------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'travel_places');

SET @s := IF(@c = 1, 'UPDATE `travel_places` SET `image` = ''assets/img/hd/lumbini-m.webp'', `image_credit` = ''Illustration - inspired by the place, not a photograph'' WHERE `slug` = ''lumbini'' AND (`image` IS NULL OR `image` = '''')', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@c = 1, 'UPDATE `travel_places` SET `image` = ''assets/img/hd/pokhara-m.webp'', `image_credit` = ''Illustration - inspired by the place, not a photograph'' WHERE `slug` = ''pokhara-phewa-lake'' AND (`image` IS NULL OR `image` = '''')', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@c = 1, 'UPDATE `travel_places` SET `image` = ''assets/img/hd/bardiya-m.webp'', `image_credit` = ''Illustration - inspired by the place, not a photograph'' WHERE `slug` = ''bardiya-national-park'' AND (`image` IS NULL OR `image` = '''')', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@c = 1, 'UPDATE `travel_places` SET `image` = ''assets/img/hd/nepalgunj-temple-m.webp'', `image_credit` = ''Illustration - inspired by the place, not a photograph'' WHERE `slug` = ''bageshwori-temple-nepalgunj'' AND (`image` IS NULL OR `image` = '''')', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@c = 1, 'UPDATE `travel_places` SET `image` = ''assets/img/hd/gujarat-city-m.webp'', `image_credit` = ''Illustration - inspired by the place, not a photograph'' WHERE `slug` = ''ahmedabad-old-city-sabarmati-ashram'' AND (`image` IS NULL OR `image` = '''')', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 2. tour_packages (/tours, /tours/<slug>)
-- ---------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tour_packages');

SET @s := IF(@c = 1, 'UPDATE `tour_packages` SET `image` = ''assets/img/hd/pokhara-m.webp'', `image_credit` = ''Illustration - inspired by the place, not a photograph'' WHERE `slug` = ''lumbini-pokhara-kathmandu-circuit'' AND (`image` IS NULL OR `image` = '''')', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@c = 1, 'UPDATE `tour_packages` SET `image` = ''assets/img/hd/lumbini-m.webp'', `image_credit` = ''Illustration - inspired by the place, not a photograph'' WHERE `slug` = ''buddhist-circuit-lumbini-kushinagar-sarnath-shravasti'' AND (`image` IS NULL OR `image` = '''')', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@c = 1, 'UPDATE `tour_packages` SET `image` = ''assets/img/hd/bardiya-m.webp'', `image_credit` = ''Illustration - inspired by the place, not a photograph'' WHERE `slug` = ''bardiya-jungle-safari-from-nepalgunj'' AND (`image` IS NULL OR `image` = '''')', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@c = 1, 'UPDATE `tour_packages` SET `image` = ''assets/img/hd/gujarat-city-m.webp'', `image_credit` = ''Illustration - inspired by the place, not a photograph'' WHERE `slug` = ''gujarat-heritage-trail'' AND (`image` IS NULL OR `image` = '''')', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
