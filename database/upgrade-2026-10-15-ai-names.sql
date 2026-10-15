-- =====================================================================
--  15 Oct 2026 - One AI for all three booking doors (includes/nameguess.php)
--
--  Owner ask: "AI le naam bata gender guess garos, database bata sikdai
--  jaos; WhatsApp ma ek message ma ticket".
--
--  Run ONCE on the live database (safe to re-run - IF NOT EXISTS / IGNORE):
--      php tests/apply-sql.php database/upgrade-2026-10-15-ai-names.sql
--
--  WHAT
--    1. ai_name_gender - first name -> how many Male / Female passengers
--       carried it. Rebuilt every night from booking_passengers
--       (cron/brain-nightly.php -> NameGuess::rebuild) and taught one row
--       per sale (SmartBook::book). Read by NameGuess::gender().
--    2. settings
--         ai_name_guess_on  1      guess F / M from the passenger's name
--                                  (SmartBook card, QuickBot line, WhatsApp)
--         wa_instant_sale   staff  a COMPLETE order in ONE WhatsApp message is
--                                  sold at once, no "yes" step:
--                                  off = never, staff = agents / counter /
--                                  office numbers only, all = customers too
--
--  Additive only.
--
--  ROLLBACK
--    DROP TABLE ai_name_gender
--    DELETE FROM settings WHERE skey IN ('ai_name_guess_on','wa_instant_sale')
-- =====================================================================

CREATE TABLE IF NOT EXISTS `ai_name_gender` (
  `name`       VARCHAR(40)  NOT NULL COMMENT 'first name, lower case, Roman letters',
  `male`       INT UNSIGNED NOT NULL DEFAULT 0,
  `female`     INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('ai_name_guess_on', '1',     'bool',   'ai',       'AI guesses Female / Male from the passenger name (SmartBook, QuickBot, WhatsApp). Staff can always tap to change', 0),
 ('wa_instant_sale',  'staff', 'string', 'whatsapp', 'WhatsApp - a complete order in ONE message is sold at once without the yes step: off / staff (agents, counter) / all (customers too)', 0);
