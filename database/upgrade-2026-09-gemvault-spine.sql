-- =====================================================================
--  Upgrade 2026-09 — Gem Vault + Governor + Reliability Spine (Phase 0)
--
--  Run ONCE on the live database:
--      php -c .claude/php-dev.ini tests/apply-sql.php database/upgrade-2026-09-gemvault-spine.sql
--
--  Every statement is CREATE TABLE IF NOT EXISTS, so re-running is a no-op
--  and it is safe to run while the site is live. Nothing here alters an
--  existing table: a booking, a seat and a fare behave exactly as before
--  whether these tables exist or not. Drop all four and the desk still
--  sells — that is the design, not an accident.
--
--  WHAT THESE ARE FOR
--  ------------------
--  1. user_profiles   — the Gem Vault. Every name + phone that reaches the
--                       system (app, WhatsApp, counter, agent) becomes a
--                       durable, enriched profile so a returning passenger
--                       re-types nothing. DERIVED from confirmed bookings
--                       only; holds no gender, no ID number, no free text.
--  2. app_events      — the product beacon (view transitions, failed
--                       parses, checkout drop-off). Redacted at the writer,
--                       pruned on a retention window by cron/rotate.php.
--  3. health_incidents— one row per FAULT CLASS (not per failure), so a
--                       WhatsApp outage is one card with a count, not 400
--                       log lines nobody reads.
--  4. cron_runs       — the heartbeat ledger. A job that stops running is
--                       otherwise invisible; this makes silence loud.
-- =====================================================================


-- ---------------------------------------------------------------------
--  1. USER PROFILES — the Gem Vault
--
--  Keyed by the normalised 10-digit phone, which is the identity across
--  every channel (users.phone, bookings.contact_phone and the WhatsApp
--  From: header all normalise to the same string).
--
--  DELIBERATELY ABSENT: gender. The seat engine locks a shared cabin on
--  gender recorded per passenger, and a REMEMBERED gender would let a
--  stale guess drive that lock. Gender is read from booking_passengers at
--  sale time, every time, and is never learned here.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_profiles` (
  `phone`                  VARCHAR(20)  NOT NULL COMMENT 'normalisePhone() output — 10 digits, no country code',
  `user_id`                BIGINT UNSIGNED NULL COMMENT 'users.id once the customer signs in, NULL for a walk-in we only know by phone',
  `full_name`              VARCHAR(120) NULL,
  `country_code`           VARCHAR(5)   NULL COMMENT '977 or 91 — CAPTURED at booking/sign-in, never guessed from the digits',
  `preferred_lang`         ENUM('en','hi','ne') NULL,
  `travel_count`           INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'confirmed/completed bookings',
  `lifetime_value`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `avg_party_size`         DECIMAL(4,2) NULL,
  `last_route_id`          BIGINT UNSIGNED NULL,
  `last_direction`         VARCHAR(16)  NULL COMMENT 'toNepal / toIndia',
  `last_travel_date`       DATE         NULL,
  `last_booked_at`         DATETIME     NULL,
  `preferred_boarding`     VARCHAR(120) NULL COMMENT 'the canonical stop string the booking stored - render it through Boarding::stopDisplay()',
  `preferred_boarding_key` VARCHAR(60)  NULL COMMENT 'Boarding::townKey() of the same',
  `preferred_deck`         ENUM('lower','upper') NULL,
  `preferred_position`     ENUM('window','aisle') NULL,
  `preferred_payment`      VARCHAR(20)  NULL,
  `channels`               VARCHAR(120) NULL COMMENT 'comma list: app,whatsapp,counter,agent',
  `is_placeholder`         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = 0000000000 and friends — never message these',
  `merged_into`            VARCHAR(20)  NULL COMMENT 'set by gem hygiene when this phone is a typo variant of another',
  `first_seen_at`          DATETIME     NULL,
  `last_seen_at`           DATETIME     NULL,
  `enriched_at`            DATETIME     NULL COMMENT 'last time enrich() recomputed the travel block',
  `created_at`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`phone`),
  KEY `ix_gem_user`      (`user_id`),
  KEY `ix_gem_lastseen`  (`last_seen_at`),
  KEY `ix_gem_active`    (`is_placeholder`,`last_travel_date`),
  KEY `ix_gem_merged`    (`merged_into`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  2. APP EVENTS — the product beacon
--
--  RETENTION IS PART OF THE SCHEMA, not an afterthought: cron/rotate.php
--  deletes rows older than Settings 'events_retention_days' (default 90).
--  The writer redacts — no phone, no name, no PNR, no free-text search
--  term longer than a short token. session_key is a rotating random id,
--  NOT a user id, so a row identifies a visit and not a person.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(60)  NOT NULL COMMENT 'allow-listed event name',
  `session_key` CHAR(32)     NULL COMMENT 'rotating per-visit id, not a user id',
  `user_id`     BIGINT UNSIGNED NULL COMMENT 'only when the visitor is signed in',
  `props`       VARCHAR(1000) NULL COMMENT 'redacted JSON — scalars only, allow-listed keys',
  `path`        VARCHAR(160) NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ev_name_time` (`name`,`created_at`),
  KEY `ix_ev_time`      (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  3. HEALTH INCIDENTS — one row per fault class
--
--  dedupe_key is UNIQUE so a fault that is still happening bumps count and
--  last_seen_at on the SAME row. Without that, a WhatsApp outage writes a
--  new incident every ten minutes and the one card that matters is buried
--  under its own repeats.
--
--  NO PII: detail/fix_steps are owner-language explanations, sample_ids
--  holds booking IDs (not phones or names).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `health_incidents` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`          VARCHAR(60)  NOT NULL COMMENT 'wa_disabled / wrong_country / no_callbacks / cron_overdue / invariant_*',
  `dedupe_key`    VARCHAR(120) NOT NULL,
  `severity`      ENUM('info','warn','critical') NOT NULL DEFAULT 'warn',
  `title`         VARCHAR(160) NOT NULL,
  `detail`        TEXT         NULL COMMENT 'owner-language explanation — no PII',
  `fix_steps`     TEXT         NULL,
  `sample_ids`    VARCHAR(255) NULL COMMENT 'booking ids only',
  `occurrences`   INT UNSIGNED NOT NULL DEFAULT 1,
  `status`        ENUM('open','ack','resolved') NOT NULL DEFAULT 'open',
  `first_seen_at` DATETIME     NOT NULL,
  `last_seen_at`  DATETIME     NOT NULL,
  `resolved_at`   DATETIME     NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_incident_dedupe` (`dedupe_key`),
  KEY `ix_incident_open` (`status`,`severity`,`last_seen_at`),
  KEY `ix_incident_kind` (`kind`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  4. CRON RUNS — the heartbeat ledger
--
--  cron_done() upserts the row for its job on every run. A job that dies
--  (PHP fatal, crontab edited away, disk full) simply stops updating, and
--  cron/health-heartbeat.php turns that silence into an incident.
--
--  One row per job, not one per run: this is a heartbeat, not a history.
--  The history already exists in logs/<date>.log.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cron_runs` (
  `job`          VARCHAR(60) NOT NULL COMMENT 'basename of the cron script, e.g. expire.php',
  `last_run_at`  DATETIME    NOT NULL,
  `last_ok_at`   DATETIME    NULL,
  `last_ms`      INT UNSIGNED NOT NULL DEFAULT 0,
  `last_result`  VARCHAR(500) NULL COMMENT 'the JSON line cron_done() printed',
  `ok_streak`    INT UNSIGNED NOT NULL DEFAULT 0,
  `fail_streak`  INT UNSIGNED NOT NULL DEFAULT 0,
  `runs_total`   INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`job`),
  KEY `ix_cron_last` (`last_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
