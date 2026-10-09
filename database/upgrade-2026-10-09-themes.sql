-- =====================================================================
-- 9 Oct 2026 - Pixel / Material You themes (THEME work package)
-- Owner: "dherai colour theme option, UI Android Google Pixel bata inspired,
-- smooth colours, sound haru pani testai".
--
-- Four public settings = the owner's DEFAULTS for a visitor who has not
-- chosen anything on their own phone (a passenger's own choice in the
-- "🎨 रूप · Appearance" sheet always wins). They are public, so index.php
-- stamps them on <html> before the first paint (data-palette / data-ui /
-- data-motion) and 19-premium.js reads the sound pack from SHG_BOOT.
--
--   app_theme_palette  ''        = the S Hari brand colours (navy / royal / red);
--                                  or one of: himalaya laligurans saffron tulsi phewa
--                                  lotus lavender marigold monsoon everest terracotta peacock
--   app_ui_style       'pixel'   = rounded Material 3 look (owner's wish); 'classic' = 3D depth
--   app_sound_pack     'pixel'   = new soft Pixel-style sounds; 'classic' = the original tones
--   app_motion_on      1         = springy motion; 0 = calm screen (same key and meaning as
--                                  the unmerged release-20260927 branch, so an existing row
--                                  keeps working)
--
-- Idempotent: INSERT IGNORE keeps a value the owner already set; the UPDATE
-- only makes sure the rows are public and filed under Website & app.
-- =====================================================================

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('app_theme_palette', '',      'string', 'app', 'Default colour theme for visitors (blank = S Hari brand colours)', 1),
 ('app_ui_style',      'pixel', 'string', 'app', 'Default look: Pixel (rounded, soft) or Classic 3D', 1),
 ('app_sound_pack',    'pixel', 'string', 'app', 'Default sound style: Pixel (soft chimes) or Classic', 1),
 ('app_motion_on',     '1',     'bool',   'app', 'Smooth springy motion on the website (off = calm screen for everyone)', 1);

UPDATE `settings` SET `is_public` = 1
 WHERE `skey` IN ('app_theme_palette', 'app_ui_style', 'app_sound_pack', 'app_motion_on');
