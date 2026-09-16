-- ============================================================
--  upgrade-2026-09-quickbot-brain.sql  (7 Sep 2026)
--
--  QuickBot Passive Brain — the three tables the nightly job writes and
--  the desk reads. Owner ask: "Raat: VPS thinks. Bihana: agent opens the
--  desk and 5 ready cards are waiting."
--
--    brain_predictions  who is likely to call in the next 7 days, why,
--                       and the pre-filled card the desk confirms.
--                       One row per (predict_date, phone) — the nightly
--                       run REPLACEs its own day, so a re-run is safe.
--
--    brain_drafts       a booking request that arrived on its own
--                       (WhatsApp today; any channel later), already
--                       parsed into plan() options and waiting for one
--                       confirmation. Never auto-sells.
--
--    brain_alerts       the standing intelligence the desk should see:
--                       a bus filling too fast, a demand echo from last
--                       year, a passenger who keeps cancelling.
--                       One row per (alert_date, kind, ref).
--
--  Nothing here touches bookings, seats or money. These are advisory
--  tables: drop them and the ticket desk still sells exactly as today.
--
--  Idempotent (CREATE TABLE IF NOT EXISTS). Additive only, no drops.
--
--    local: "C:\Program Files\MariaDB 12.3\bin\mysql.exe" -h127.0.0.1 -P3307 -uroot shari_test < database/upgrade-2026-09-quickbot-brain.sql
--    VPS:   mysql shari < database/upgrade-2026-09-quickbot-brain.sql
-- ============================================================

-- ------------------------------------------------------------
--  1. Tonight's ranked call list.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `brain_predictions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- The day this row is FOR (the desk's "today"), not the day it was computed.
  `predict_date`  DATE         NOT NULL,
  `phone`         VARCHAR(20)  NOT NULL,
  `passenger`     VARCHAR(120) NOT NULL DEFAULT '',
  -- 0..1000 (score * 1000) so ORDER BY is exact integer maths, never a float tie.
  `score`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `band`          ENUM('hot','warm','cool') NOT NULL DEFAULT 'cool',
  -- The trip the brain expects them to ask for.
  `travel_date`   DATE         NULL DEFAULT NULL,
  `direction`     VARCHAR(24)  NOT NULL DEFAULT '',
  `boarding`      VARCHAR(160) NOT NULL DEFAULT '',
  `seats`         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  -- Human-readable "why" lines + the raw signal values, for the card and for audit.
  `reason`        TEXT         NULL DEFAULT NULL,
  `signals`       TEXT         NULL DEFAULT NULL,
  -- open → the card is in the queue; confirmed → it became this booking;
  -- dismissed → the desk said no; expired → the day passed unused.
  `status`        ENUM('open','confirmed','dismissed','expired') NOT NULL DEFAULT 'open',
  `booking_id`    INT UNSIGNED NULL DEFAULT NULL,
  `resolved_by`   INT UNSIGNED NULL DEFAULT NULL,
  `resolved_at`   DATETIME     NULL DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- One card per passenger per day: the nightly re-run overwrites, never doubles.
  UNIQUE KEY `uq_brain_pred_day_phone` (`predict_date`, `phone`),
  KEY `ix_brain_pred_queue` (`predict_date`, `status`, `score`),
  KEY `ix_brain_pred_phone` (`phone`, `predict_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  2. Requests that arrived on their own, already parsed.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `brain_drafts` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel`     VARCHAR(24)  NOT NULL DEFAULT 'whatsapp',
  `phone`       VARCHAR(20)  NOT NULL,
  `passenger`   VARCHAR(120) NOT NULL DEFAULT '',
  -- Exactly what they sent, kept verbatim: the desk reads the original words
  -- before trusting the parse, and it is the training set for later tuning.
  `raw_text`    TEXT         NULL DEFAULT NULL,
  -- TicketBot::parse() output + the suggest() options, as JSON.
  `parsed`      TEXT         NULL DEFAULT NULL,
  `travel_date` DATE         NULL DEFAULT NULL,
  `direction`   VARCHAR(24)  NOT NULL DEFAULT '',
  `boarding`    VARCHAR(160) NOT NULL DEFAULT '',
  `seats`       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  -- Anything the desk must look at before confirming: unclear date, no route,
  -- a same-day duplicate of an existing booking. Newline-separated.
  `flags`       VARCHAR(255) NOT NULL DEFAULT '',
  -- 0..100: how much of a bookable request the parser actually found.
  `confidence`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `status`      ENUM('new','confirmed','dismissed','expired') NOT NULL DEFAULT 'new',
  `booking_id`  INT UNSIGNED NULL DEFAULT NULL,
  `resolved_by` INT UNSIGNED NULL DEFAULT NULL,
  `resolved_at` DATETIME     NULL DEFAULT NULL,
  -- Set once the "ticket confirmed" reply actually goes back out.
  `replied_at`  DATETIME     NULL DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_brain_draft_queue` (`status`, `id`),
  KEY `ix_brain_draft_phone` (`phone`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  3. Standing intelligence for the desk.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `brain_alerts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_date` DATE         NOT NULL,
  -- fill | demand_echo | cancel_risk | departure | revenue
  `kind`       VARCHAR(32)  NOT NULL,
  -- What the alert is about (schedule id, phone, date...) — free-form, part of
  -- the dedupe key so one bus filling up cannot be reported twice in a day.
  `ref`        VARCHAR(64)  NOT NULL DEFAULT '',
  `severity`   ENUM('info','warn','high') NOT NULL DEFAULT 'info',
  `title`      VARCHAR(160) NOT NULL,
  `body`       TEXT         NULL DEFAULT NULL,
  `meta`       TEXT         NULL DEFAULT NULL,
  `status`     ENUM('open','done','dismissed') NOT NULL DEFAULT 'open',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brain_alert` (`alert_date`, `kind`, `ref`),
  KEY `ix_brain_alert_open` (`status`, `alert_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
