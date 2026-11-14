-- =====================================================================
--  upgrade-2026-11-15-trip-events.sql - what the driver app reports
--  from the road (15 Nov 2026, includes/tripevents.php, api/driver.php).
--
--  One row per tap on the driver app's trip screen:
--    stop_reached  "Stop pugyo"   - stop_name = the route stop reached
--    delay         "Dhilo"        - minutes late + a short reason (note)
--    breakdown     "Bus bigriyo"  - note = what broke
--    sos           SOS            - note optional
--    rest_start / rest_end        - the driver's rest, for the
--                                   continuous-driving timer
--  lat / lng = the phone's latest fix when the button was pressed.
--  An open SOS / breakdown = kind IN ('sos','breakdown') AND
--  resolved_at IS NULL; the office closes it (resolved_at, resolved_by =
--  admins.id).
--
--  NAME: the table is `driver_events`, NOT `trip_events`. A table called
--  trip_events already exists on every server (since Sep 2026): it is the
--  passenger-notification claim ledger of includes/tripnotify.php and
--  cron/reminders.php (booking_id + schedule_id + event ENUM, UNIQUE). A
--  "CREATE TABLE IF NOT EXISTS trip_events" would silently do nothing and
--  every insert would fail, so the driver's rows live here instead.
--
--  Additive only. No foreign keys: a trip row deleted by the office or a
--  test leaves its events readable as history. The code checks the table
--  exists (TripEvents::available()) and the buttons answer "not set up yet"
--  until this file has run.
--
--    mysql shari < database/upgrade-2026-11-15-trip-events.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS `driver_events` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `live_trip_id` BIGINT UNSIGNED NULL COMMENT 'live_trips.id',
  `schedule_id`  BIGINT UNSIGNED NULL COMMENT 'schedules.id',
  `bus_label`    VARCHAR(40)  NULL COMMENT 'bus number when pressed',
  `driver_ref`   VARCHAR(60)  NULL COMMENT 'D<drivers.id>',
  `kind`         VARCHAR(20)  NOT NULL COMMENT 'stop_reached | delay | breakdown | sos | rest_start | rest_end',
  `stop_name`    VARCHAR(120) NULL,
  `minutes`      INT          NULL COMMENT 'delay minutes',
  `note`         VARCHAR(255) NULL,
  `lat`          DECIMAL(10,7) NULL,
  `lng`          DECIMAL(10,7) NULL,
  `created_at`   DATETIME     NOT NULL,
  `resolved_at`  DATETIME     NULL,
  `resolved_by`  BIGINT UNSIGNED NULL COMMENT 'admins.id',
  PRIMARY KEY (`id`),
  KEY `ix_drvev_kind_open` (`kind`, `resolved_at`),
  KEY `ix_drvev_trip` (`live_trip_id`),
  KEY `ix_drvev_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
