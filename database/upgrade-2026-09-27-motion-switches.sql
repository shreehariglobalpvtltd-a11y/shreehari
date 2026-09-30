-- =====================================================================
--  upgrade-2026-09-27-motion-switches.sql
--
--  Owner, 26 Sep 2026 (evening, in Nepali): the site must feel instant
--  and luxurious, with an entrance animation on every screen and ONE
--  signature opening with marketing words - yet the home page stays still
--  at rest (25 Sep rule); everything ON that can safely be ON.
--
--  Two PUBLIC switches for the motion pass (assets/css/premium.css §9-§12,
--  assets/js/02-config.js shgSwitchOn, 13-admin-routes.js Splash,
--  19-premium.js, 22-vip.js). Both ride in SHG_BOOT.settings; the browser
--  treats an ABSENT row as ON, so a site that has not run this file behaves
--  exactly like one that has and switched both on. They ship as 0 and the
--  integrator flips them to 1 on live (the owner asked for ON).
--
--    app_motion_on       1 = the shared enter motion on every screen, the
--                            1.4 s signature opening with its three lines,
--                            the loops that run while the visitor is active.
--                        0 = every entrance instant, the splash lasts only as
--                            long as the boot (the behaviour before 27 Sep).
--    app_load_sound_on   1 = one soft tick when a request passes 600 ms
--                            (still behind the per-device Sound switch).
--                        0 = the loading bar alone, no tick.
--
--  Safe to re-run: INSERT IGNORE leaves an existing row untouched. Labels
--  use no semicolons (tests/apply-sql.php splits on them).
-- =====================================================================

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, is_public, label) VALUES
  ('app_motion_on', '0', 'bool', 'site', 1,
   'एपको चाल (motion) - हरेक स्क्रिनको प्रवेश एनिमेसन र सुरुको तीन-लाइन ओपनिङ · App motion - the enter animation on every screen and the 1.4 s signature opening (visitors who asked for reduced motion never see it either way)'),
  ('app_load_sound_on', '0', 'bool', 'site', 1,
   'लोडिङ आवाज - नेटवर्क ढिलो हुँदा एउटा हलुका टिक · Loading tick - one soft sound when a request takes longer than 0.6 s (visitors can still switch Sound off in the app menu)');
