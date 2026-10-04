-- =====================================================================
--  5 Oct 2026 - SHARI NAV on our own server: place search index + engine
--  settings. api/nav-search.php, api/nav-route.php,
--  tools/navdata/import-places.php fills nav_places from OpenStreetMap.
--  Additive only. Re-running this file is harmless.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `nav_places` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `osm_ref` VARCHAR(24) NULL,
  `name`    VARCHAR(150) NOT NULL,
  `name_en` VARCHAR(150) NULL,
  `kind`    VARCHAR(60) NOT NULL COMMENT 'amenity:hospital, shop:bakery, road:residential ...',
  `lat`     DECIMAL(9,6) NOT NULL,
  `lng`     DECIMAL(9,6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_np_name` (`name`(40)),
  KEY `ix_np_name_en` (`name_en`(40)),
  KEY `ix_np_ll` (`lat`, `lng`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('nav_engine_url',      'http://127.0.0.1:8002', 'string', 'maps', 'SHARI NAV: our routing engine (Valhalla on this server)', 0),
 ('nav_fallback_public', '1', 'bool', 'maps', 'SHARI NAV: outside our map area, ask the public route server', 0);
