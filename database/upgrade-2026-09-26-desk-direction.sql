-- =====================================================================
--  Upgrade 2026-09-26 — the run a desk sells
--
--      php tests/apply-sql.php database/upgrade-2026-09-26-desk-direction.sql
--
--  Idempotent: the ADD COLUMN is guarded, the seed is a conditional UPDATE.
--
--  WHY
--  ---
--  The owner, looking at a demo ticket from the Nepalgunj window that read
--  "Surat -> Rupaidiha": "yesto sano mistake nahunu paryo."
--
--  He is right, and it is not really the clerk's mistake to make. A window at
--  Nepalgunj serves passengers who are standing in Nepal: they board at
--  RUPAIDIHA and travel INTO India. A Gujarat window serves the opposite run.
--  Until now every desk opened on "Auto" and the direction was one more thing
--  to get right by hand, every sale, forever.
--
--  NULL = this desk sells both and the screen stays on Auto, which is every
--  Indian desk today. The chip is never disabled: a Nepalgunj customer buying
--  a relative's outbound leg is a real sale, not a mistake.
--
--  NB: Nepalgunj is a SELLING window, not a boarding stop — the passenger
--  still boards at Rupaidiha ("Nepalgunj ticket katne matra ho, chadne
--  Rupaidiha nai ho", 26 Sep). default_from is the town the search opens on,
--  not a new stop.
-- =====================================================================

SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'counter_locations' AND COLUMN_NAME = 'default_direction');
SET @s := IF(@c = 0,
  'ALTER TABLE `counter_locations` ADD COLUMN `default_direction` VARCHAR(10) NULL COMMENT ''toNepal | toIndia - the run this desk opens on. NULL = both, screen stays on Auto'' AFTER `allowed_methods`, ADD COLUMN `default_from` VARCHAR(80) NULL COMMENT ''the town this desk''''s passengers board at - prefills the counter search, never a new stop'' AFTER `default_direction`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- The three Nepal desks sell the return run and their passengers board at
-- Rupaidiha. Only fills a desk that has not been given its own answer.
UPDATE `counter_locations`
   SET `default_direction` = 'toIndia',
       `default_from`      = 'Rupaidiha'
 WHERE `code` IN ('NPJ','NPJD','KHL')
   AND `default_direction` IS NULL;

-- Rupaidiha itself is the border desk on the Indian side: it also sells the
-- run into India, and its passengers board where it stands.
UPDATE `counter_locations`
   SET `default_direction` = 'toIndia',
       `default_from`      = 'Rupaidiha'
 WHERE `code` = 'RPD'
   AND `default_direction` IS NULL;
