-- =====================================================================
--  5 Oct 2026 - SHARI NAV for all of India + Nepal: nav_places grows from
--  ~53 thousand (Gujarat + Nepalgunj) to ~1-2 million rows. The "which
--  town am I in" lookup and the town-name search filter by kind first.
--  Additive only. Re-running this file is harmless. Apply BEFORE
--  import-places.php (the import copies nav_places' indexes).
-- =====================================================================

ALTER TABLE `nav_places` ADD INDEX IF NOT EXISTS `ix_np_kind_ll` (`kind`, `lat`, `lng`);
