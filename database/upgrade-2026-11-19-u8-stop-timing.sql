-- =====================================================================
--  upgrade-2026-11-19-u8-stop-timing.sql  (unit u8, AI-4)
--
--  trip_stop_times: when a coach really reached (and left) each stop / halt
--  on one live trip, derived at night from its GPS trail (trip_locations)
--  or a driver "stop reached" tap. No FK to live_trips: GPS pruning must
--  not take the learned passes with it.
--
--  route_stop_learned: per route stop / halt, the median and p80 offset
--  from the scheduled departure over the last trips, and a suggested time.
--  SUGGESTIONS ONLY: route_stops / route_halts are never written; the
--  office presses "Use this time" on admin/map.php. Idempotent.
-- =====================================================================

CREATE TABLE IF NOT EXISTS trip_stop_times (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  trip_id     BIGINT UNSIGNED NOT NULL,
  schedule_id BIGINT UNSIGNED NULL,
  route_id    BIGINT UNSIGNED NOT NULL,
  stop_kind   ENUM('stop','halt') NOT NULL DEFAULT 'stop',
  stop_ref    BIGINT UNSIGNED NOT NULL COMMENT 'route_stops.id or route_halts.id',
  dep_at      DATETIME NOT NULL,
  reached_at  DATETIME NOT NULL,
  left_at     DATETIME NULL,
  offset_min  INT NOT NULL,
  source      ENUM('gps','driver') NOT NULL,
  dist_m      SMALLINT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tst (trip_id, stop_kind, stop_ref, source),
  KEY ix_tst_route (route_id, stop_kind, stop_ref, reached_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_stop_learned (
  route_id          BIGINT UNSIGNED NOT NULL,
  stop_kind         ENUM('stop','halt') NOT NULL,
  stop_ref          BIGINT UNSIGNED NOT NULL,
  samples           SMALLINT UNSIGNED NOT NULL,
  median_offset_min INT NOT NULL,
  p80_offset_min    INT NOT NULL,
  median_dwell_min  SMALLINT UNSIGNED NULL,
  suggested_time    TIME NULL,
  day_offset        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_trip_at      DATETIME NULL,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (route_id, stop_kind, stop_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label) VALUES
  ('stop_learn_on',    '1',  'bool', 'automation', 'Learn real stop times from GPS at night (own tables only)'),
  ('stop_learn_trips', '10', 'int',  'automation', 'Stop timing: trips to learn from'),
  ('stop_learn_min',   '3',  'int',  'automation', 'Stop timing: fewest trips before a time is suggested'),
  ('eta_learned_on',   '0',  'bool', 'automation', 'Live ETA uses learned stop times (off = flat speed rule)');
