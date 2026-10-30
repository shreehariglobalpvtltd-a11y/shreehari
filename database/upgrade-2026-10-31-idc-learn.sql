-- =====================================================================
--  upgrade-2026-10-31-idc-learn.sql
--
--  Owner, 31 Oct 2026: "yo sab application ko VPS ko model le herera
--  practice garos, pura VPS k k chha sikdai jaos, training lidai fast
--  hudai jaos."
--
--  The model on the VPS (qwen3.5:4b on 2 vCPUs) cannot be re-trained on
--  that box. What it CAN do is learn from examples and practise:
--
--    ap_wa_examples   WhatsApp-desk messages with the intent they turned
--                     out to mean, stored ONLY in normalised form (PNRs,
--                     phone numbers, amounts, dates, codes and names are
--                     placeholders - never one person's details).
--                       source  parser    = a typed command the desk knew
--                               confirmed = an AI reading the member then
--                                           confirmed with "ho"
--                               office    = taught on admin/ai-local.php
--                       hits / ok_hits    seen / confirmed with this intent
--                       fast_*            the no-model fast path today
--                       px_*              practice tries / correct
--    ap_ai_practice   one row per practice run (cron/agentpro-practice.php
--                     at night, or "Practice now" on the admin page):
--                     how many examples, how many the model read right,
--                     average and median seconds.
--
--  Additive and re-runnable (CREATE TABLE IF NOT EXISTS). The code works
--  without this file: ApLearn checks the table and does nothing.
--  (apply-sql.php splits on semicolons - none inside the strings.)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ap_wa_examples` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `text_hash`   CHAR(40)      NOT NULL COMMENT 'sha1 of text_norm',
  `text_norm`   VARCHAR(400)  NOT NULL COMMENT 'lower-case, placeholders for PNR / phone / amount / date / code / name',
  `intent_json` VARCHAR(1000) NOT NULL COMMENT 'intent + non-personal slots (field, direction, seats, days, pay)',
  `source`      ENUM('parser','confirmed','office') NOT NULL DEFAULT 'parser',
  `hits`        INT UNSIGNED  NOT NULL DEFAULT 0,
  `ok_hits`     INT UNSIGNED  NOT NULL DEFAULT 0,
  `fast_hits`   INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'answered by the fast path, all time',
  `fast_today`  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'answered by the fast path on fast_day',
  `fast_day`    DATE          NULL,
  `px_n`        INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'practice: times replayed',
  `px_ok`       INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'practice: times the model read it right',
  `last_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_wa_examples_hash` (`text_hash`),
  KEY `ix_ap_wa_examples_ok` (`ok_hits`, `last_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ap_ai_practice` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `model`    VARCHAR(80)  NOT NULL DEFAULT '',
  `n`        INT UNSIGNED NOT NULL DEFAULT 0,
  `correct`  INT UNSIGNED NOT NULL DEFAULT 0,
  `avg_ms`   INT UNSIGNED NOT NULL DEFAULT 0,
  `p50_ms`   INT UNSIGNED NOT NULL DEFAULT 0,
  `notes`    VARCHAR(500) NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ap_ai_practice_at` (`run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
