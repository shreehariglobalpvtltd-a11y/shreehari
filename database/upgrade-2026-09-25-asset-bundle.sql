-- =====================================================================
--  upgrade-2026-09-25-asset-bundle.sql — one script, one stylesheet
--
--  Additive, idempotent. One switch, shipped OFF:
--
--   bundle_assets_on   index.php serves assets/dist/app.min.js and
--                      assets/dist/app.min.css (built by tools/build.mjs
--                      and committed) instead of the fourteen scripts and
--                      two stylesheets in app.template.html. The swap is
--                      refused automatically if the committed bundle no
--                      longer matches the template, so turning this on can
--                      never drop a script (includes/assetbundle.php).
--
--  Not public: the app never reads it, index.php does.
-- =====================================================================

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
  ('bundle_assets_on', '0', 'bool', 'performance', 'Serve one bundled JS + CSS file instead of sixteen (run tools/build.mjs first)', 0);
