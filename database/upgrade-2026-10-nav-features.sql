-- =====================================================================
--  SHARI NAV Phase 3 (22 Oct 2026): road features from our own OpenStreetMap
--  extract that the place index (nav_places) does not carry: speed cameras
--  (with their limit), CNG / LPG fuel stations and EV chargers.
--  Filled by deploy/navdata/features-import.php from region.osm.pbf.
--  New table only. Apply by hand:  mysql shari < database/upgrade-2026-10-nav-features.sql
-- =====================================================================
CREATE TABLE IF NOT EXISTS nav_features (
  id        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  kind      VARCHAR(16)   NOT NULL,
  osm_ref   VARCHAR(24)   NOT NULL DEFAULT '',
  name      VARCHAR(150)  NOT NULL DEFAULT '',
  lat       DECIMAL(9,6)  NOT NULL,
  lng       DECIMAL(9,6)  NOT NULL,
  maxspeed  SMALLINT      NULL,
  extra     VARCHAR(120)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY ix_nf_kind_ll (kind, lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
