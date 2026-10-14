-- =====================================================================
-- 10 Oct 2026 - Brand looks (THEMES work package)
-- Owner: "this app should be default colour professional; white-blue
-- different version; golden and orange different version, inspired by the
-- logo colours".
--
-- assets/css/themes.css gains three hand-tuned palettes next to the twelve
-- Material You ones: professional (calm navy / mist blue / ivory, gold as a
-- small accent - the HD visual guide), whiteblue and goldenorange. This file
-- makes PROFESSIONAL the owner default for every visitor who has not chosen
-- a colour on their own phone. A passenger's own choice in the
-- "🎨 रूप · Appearance" sheet always wins (stamped before paint by
-- app.template.html; the owner default is applied by index.php only where
-- nothing is saved).
--
-- DEPLOY ORDER (docs/UPGRADE-2026-10-10-themes.md): run this file only AFTER
-- the build that carries the three palettes is live WITH its asset stamp
-- bump. A phone that still holds the older themes.css would not know
-- 'professional'; index.php's stale-stylesheet guard then falls back to the
-- brand look, so nothing breaks - but the owner would see the old colours
-- until the phone picks up the new stamp.
--
-- Self-sufficient and idempotent (review fix): the row is normally inserted
-- blank by upgrade-2026-10-09-themes.sql; on a database where that file was
-- never applied this INSERT creates it (same columns as the 9 Oct file), and
-- where it exists the ON DUPLICATE KEY branch only moves a value that is
-- STILL blank or NULL (svalue is a nullable longtext; COALESCE, review fix) - an owner who already picked a theme in Admin -> Settings
-- keeps it, and re-running the file changes nothing. is_public stays 1
-- (index.php reads the row before paint).
-- =====================================================================

INSERT INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('app_theme_palette', 'professional', 'string', 'app', 'Default colour theme for visitors (blank = S Hari brand colours)', 1)
ON DUPLICATE KEY UPDATE
 `svalue` = IF(COALESCE(`svalue`, '') = '', 'professional', `svalue`),
 `is_public` = 1;

-- Verify (expected: one row, svalue = professional unless the owner chose
-- another theme, is_public = 1):
--   SELECT `skey`, `svalue`, `is_public` FROM `settings` WHERE `skey` = 'app_theme_palette';
