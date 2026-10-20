-- =====================================================================
--  upgrade-2026-10-route-geometry.sql - the road line of a route
--  (20 Oct 2026, live tracking phase 2: includes/routegeo.php).
--
--  One row per route: the road the coach drives, built ONCE from the
--  Valhalla router through the route's stops that have coordinates (in
--  travel order), simplified to ~15 m and stored as an encoded polyline
--  (precision 6). The customer map draws it (grey = driven, blue = still
--  to go) and EtaAlerts measures "km to your stop" along it instead of a
--  straight stop-to-stop line x 1.25.
--
--  Built by the owner from Admin -> Live Map -> "Road line". Rebuilding
--  bumps `version`, which is how a phone knows its cached line is old.
--
--  Additive and re-runnable. No route without a row changes behaviour:
--  everything falls back to the old line of stops.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `route_geometry` (
  `route_id`   BIGINT UNSIGNED NOT NULL,
  `version`    INT UNSIGNED NOT NULL DEFAULT 1,
  `polyline6`  MEDIUMTEXT NOT NULL,
  `length_m`   INT UNSIGNED NOT NULL,
  `source`     VARCHAR(40) NOT NULL DEFAULT 'valhalla',
  `built_at`   DATETIME NOT NULL,
  PRIMARY KEY (`route_id`),
  CONSTRAINT `fk_rg_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
