-- =====================================================================
--  upgrade-2026-09-23-ux-flags.sql - feature switches for the optional
--  "AI extras" of the UI/UX v3 upgrade (brief §10). Each one is an isolated
--  module in assets/js/19-ux.js that does nothing while its switch is off,
--  so first load is unchanged. Additive, re-runnable, ships OFF.
-- =====================================================================
SET NAMES utf8mb4;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('ux_voice_search_on',  '0', 'bool', 'app', 'Search card: 🎤 voice search ("Surat to Rupaidiha kal" fills direction, town and date; Chrome Android / iOS 14.5+)', 1),
('ux_nearest_stop_on',  '0', 'bool', 'app', 'Search card: 📍 "Nearest pickup" chip (GPS → nearest boarding point, through the Help bot)', 1);
