-- =====================================================================
--  upgrade-2026-11-16-ai-turns.sql
--
--  P7-9a (16 Nov 2026): one row per answered assistant turn, so the
--  office can see which branch answered (the PNR lookup, the local
--  booking engine, the FAQ, the AI agent ...), which tools ran, whether
--  the reply went out and how long the whole turn took.
--
--    whatsapp/webhook.php      a row right after WaBot::reply() is sent
--    AiAgent::handleWeb()      a row for each website / Messenger turn
--
--  NO message text is stored here. The passenger's words stay where they
--  already are (wa_inbound, message_logs) under their 7-day rule; the
--  sender is only a sha256 of the phone digits (a website guest: of the
--  session key). reply_log_id points at the reply's message_logs row.
--  feedback is for the review loop (thumbs up / down), empty for now.
--
--  Additive and re-runnable. The code checks that the table exists and
--  writes nothing until it does, so the deploy may come before this file.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_turns` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at`   DATETIME        NOT NULL,
  `channel`      VARCHAR(20)     NOT NULL DEFAULT 'whatsapp' COMMENT 'whatsapp | web | messenger',
  `role`         VARCHAR(12)     NOT NULL DEFAULT 'customer' COMMENT 'customer | staff | admin',
  `owner_key`    CHAR(64)        NOT NULL DEFAULT '' COMMENT 'sha256 of the sender phone digits (web guest: of the session key) - never the number',
  `router`       VARCHAR(20)     NOT NULL DEFAULT '' COMMENT 'which branch answered: pnr | waedit | walocal | wabooking | wafaq | agent | apwa | ...',
  `tools`        VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'assistant tools called this turn, comma-separated',
  `ok`           TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = the reply went out',
  `ms`           INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'whole turn, reply built and sent',
  `wamid`        VARCHAR(80)     NULL COMMENT 'the inbound WhatsApp message id',
  `reply_log_id` BIGINT UNSIGNED NULL COMMENT 'message_logs.id of the reply',
  `feedback`     ENUM('up','down') NULL,
  PRIMARY KEY (`id`),
  KEY `ix_aiturn_created` (`created_at`),
  KEY `ix_aiturn_router` (`router`, `created_at`),
  KEY `ix_aiturn_owner` (`owner_key`, `created_at`),
  KEY `ix_aiturn_wamid` (`wamid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
