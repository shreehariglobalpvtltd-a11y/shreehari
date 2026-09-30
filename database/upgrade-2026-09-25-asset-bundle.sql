-- =====================================================================
--  upgrade-2026-09-25-asset-bundle.sql — one script, one stylesheet
--
--  Additive, idempotent. One switch, shipped OFF:
--
--   bundle_assets_on   index.php serves assets/dist/app.min.js and
--                      assets/dist/app.min.css (built by tools/build.mjs
--                      and committed) instead of the eighteen scripts and
--                      four stylesheets in app.template.html. The swap is
--                      refused automatically if the committed bundle no
--                      longer matches the template, so turning this on can
--                      never drop a script (includes/assetbundle.php).
--
--  The row already exists on the live database (added 25 Sep 2026 by an
--  earlier lineage); this file is here so a fresh install gets it too.
--  Not public: the app never reads it, index.php does.
--
--      php tests/apply-sql.php database/upgrade-2026-09-25-asset-bundle.sql
-- =====================================================================

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
  ('bundle_assets_on', '0', 'bool', 'performance', 'Serve one bundled JS + CSS file instead of twenty-two (run node tools/build.mjs first)', 0);
