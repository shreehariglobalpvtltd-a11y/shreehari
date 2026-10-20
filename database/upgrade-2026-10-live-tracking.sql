-- =====================================================================
--  upgrade-2026-10-live-tracking.sql - per-trip live GPS (20 Oct 2026)
--  (includes/livetrips.php, api/driver.php, driver/ app).
--
--  Until today the whole company had ONE position: the `livebus` kv key.
--  r2 Surat -> Rupaidiha leaves every day and runs ~20 h, so two coaches of
--  the same route are often on the road at once - and then the "bus is
--  near" alert and the track page stay silent, because one position cannot
--  say which coach it is. These tables give every running schedule its own
--  position, written by the driver's phone (driver/ app, phone + PIN).
--
--  Additive and re-runnable: live_trips / trip_locations already exist
--  (schema.sql section 26, unused until now) - only columns, one index each
--  and one new table are added. Nothing is dropped. Kill switch below.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `live_trips` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trip_ref`      VARCHAR(40) NOT NULL,
  `schedule_id`   BIGINT UNSIGNED NULL,
  `route_id`      BIGINT UNSIGNED NOT NULL,
  `driver_id`     BIGINT UNSIGNED NULL,
  `bus_id`        BIGINT UNSIGNED NULL,
  `departure_at`  DATETIME NOT NULL,
  `status`        ENUM('pending','running','paused','completed','cancelled') NOT NULL DEFAULT 'pending',
  `current_stop`  VARCHAR(191) NULL,
  `last_lat`      DECIMAL(10,7) NULL,
  `last_lng`      DECIMAL(10,7) NULL,
  `last_bearing`  SMALLINT NULL,
  `last_speed`    DECIMAL(6,2) NULL,
  `last_ping_at`  DATETIME NULL,
  `share_token`   VARCHAR(64) NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trip_ref` (`trip_ref`),
  KEY `ix_trips_status` (`status`,`departure_at`),
  KEY `fk_trips_sched` (`schedule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `trip_locations` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trip_id`     BIGINT UNSIGNED NOT NULL,
  `latitude`    DECIMAL(10,7) NOT NULL,
  `longitude`   DECIMAL(10,7) NOT NULL,
  `bearing`     SMALLINT NULL,
  `speed_kmh`   DECIMAL(6,2) NULL,
  `accuracy_m`  DECIMAL(8,2) NULL,
  `recorded_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_triploc_trip` (`trip_id`,`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- live_trips: what the latest fix said, and who sent it.
-- trip_ref is 'S<schedule_id>' so one schedule can only ever have one row.
ALTER TABLE `live_trips`
  ADD COLUMN IF NOT EXISTS `last_acc_m`  SMALLINT UNSIGNED NULL AFTER `last_speed`,
  ADD COLUMN IF NOT EXISTS `last_seq`    INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_ping_at`,
  ADD COLUMN IF NOT EXISTS `device_id`   BIGINT UNSIGNED NULL AFTER `driver_id`,
  ADD COLUMN IF NOT EXISTS `started_at`  DATETIME NULL AFTER `departure_at`,
  ADD COLUMN IF NOT EXISTS `ended_at`    DATETIME NULL AFTER `started_at`;

-- trip_locations: a per-phone counter makes a re-sent offline batch harmless.
ALTER TABLE `trip_locations`
  ADD COLUMN IF NOT EXISTS `seq`         INT UNSIGNED NOT NULL DEFAULT 0 AFTER `trip_id`,
  ADD COLUMN IF NOT EXISTS `received_at` DATETIME NULL AFTER `recorded_at`;
ALTER TABLE `trip_locations`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_triploc_seq` (`trip_id`, `seq`);

-- A signed-in driver phone. The raw token lives only on the phone.
CREATE TABLE IF NOT EXISTS `driver_devices` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `driver_id`   BIGINT UNSIGNED NOT NULL,
  `token_hash`  CHAR(64) NOT NULL COMMENT 'sha256 of the bearer token',
  `label`       VARCHAR(160) NULL COMMENT 'browser / phone, for the office',
  `last_seen`   DATETIME NULL,
  `revoked_at`  DATETIME NULL,
  `created_at`  DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dd_token` (`token_hash`),
  KEY `ix_dd_driver` (`driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('driver_app_on',  '1',  'bool', 'automation', 'Driver app (/driver/): drivers sign in with phone + PIN and send the bus position for their own trip', 0),
('live_ping_sec',  '15', 'int',  'automation', 'Driver app: seconds between GPS sends (10-30)', 0);
