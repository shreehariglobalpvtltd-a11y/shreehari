-- =====================================================================
--  upgrade-2026-10-22-gps-trackers.sql - track a bus by its NUMBER
--  (includes/gpstrackers.php, api/gps.php, admin/gps-trackers.php).
--
--  Owner, 22 Oct 2026: "mobile ya GPS device trace garera gaadi number
--  wise assign garna milos". A tracker belongs to a BUS (buses.bus_number),
--  not to a driver or a trip:
--
--    phone     the free Traccar Client app on a phone that stays in the
--              bus, identified by a code made in Admin -> GPS trackers
--    hardware  a GPS box (IMEI) talking to a Traccar server, which
--              forwards every fix to /api/gps.php with the forward key
--
--  Every fix finds the departure that bus is running now and feeds the
--  same live_trips row the driver app uses, so the track page, the "bus
--  is near" WhatsApp and the SHARI NAV map show it with no change.
--
--  Additive and re-runnable. Kill switch: gps_trackers_on (default on).
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `gps_trackers` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bus_id`       BIGINT UNSIGNED NOT NULL,
  `label`        VARCHAR(80)  NOT NULL DEFAULT '' COMMENT 'e.g. driver cabin phone or GPS box',
  `device_uid`   VARCHAR(64)  NOT NULL COMMENT 'Traccar device identifier - SHG code or the IMEI',
  `kind`         ENUM('phone','hardware') NOT NULL DEFAULT 'phone',
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `last_seen`    DATETIME     NULL COMMENT 'when the server last heard from it',
  `last_fix_at`  DATETIME     NULL COMMENT 'GPS time of the newest position below',
  `last_lat`     DECIMAL(10,7) NULL,
  `last_lng`     DECIMAL(10,7) NULL,
  `last_speed`   DECIMAL(6,2) NULL COMMENT 'km/h',
  `last_batt`    TINYINT UNSIGNED NULL COMMENT 'battery percent',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gps_tracker_uid` (`device_uid`),
  KEY `ix_gps_tracker_bus` (`bus_id`, `is_active`),
  CONSTRAINT `fk_gps_tracker_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('gps_trackers_on', '1', 'bool', 'automation', 'GPS trackers (/api/gps.php): a phone with Traccar Client or a GPS box sends the position of its bus, found by bus number', 0);
