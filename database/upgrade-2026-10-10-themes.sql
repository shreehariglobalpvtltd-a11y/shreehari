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
-- Idempotent: the row itself is inserted by upgrade-2026-10-09-themes.sql
-- (INSERT IGNORE, blank = S Hari brand colours). This UPDATE only moves a
-- row that is STILL blank, so an owner who already picked a theme in
-- Admin -> Settings keeps it, and re-running the file changes nothing.
-- The second UPDATE keeps the row public (index.php reads it before paint).
-- =====================================================================

UPDATE `settings` SET `svalue` = 'professional'
 WHERE `skey` = 'app_theme_palette' AND `svalue` = '';

UPDATE `settings` SET `is_public` = 1
 WHERE `skey` = 'app_theme_palette';
