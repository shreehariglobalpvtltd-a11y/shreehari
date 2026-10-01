-- =====================================================================
--  Upgrade 2026-09-26 — payment webhook, possible-match hint, WhatsApp
--  office commands, local AI (Prompt 2 of "SHG Claude Code Prompts")
--
--  Run ONCE on the live database (phpMyAdmin -> SQL tab -> paste -> Go),
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-09-26-payment-engine.sql
--
--  Fully idempotent: every ALTER is guarded against information_schema,
--  tables are CREATE IF NOT EXISTS, settings rows are INSERT IGNORE.
--  Safe to re-run while the site is live.
--
--  EVERYTHING HERE SHIPS SWITCHED OFF. With the defaults below the site
--  behaves exactly as before: no webhook URL answers, no WhatsApp office
--  command runs, no local model is called.
--
--  WHAT THIS ADDS
--  --------------
--  payments.match_hint   'possible_match' when a signed gateway / bank
--                        webhook reported money that matches this
--                        booking's UTR or PNR but could not confirm it by
--                        itself. A HINT for the office, never a status:
--                        the booking stays pending until a person verifies.
--  payments.match_note   one line saying why (amount, source, when).
--  webhooks_received     every signed payment webhook, once. The unique
--                        (provider, event_id) is the idempotency key, so a
--                        retried delivery can never credit a booking twice.
--                        Only a hash of the payload and of the UTR is kept.
--  settings              the switches and non-secret config. The webhook
--                        secret is read from the environment variable
--                        SHG_PAYMENT_WEBHOOK_SECRET first, and only falls
--                        back to payment_webhook_secret here.
--
--  NB: no semicolon inside any quoted string — tests/apply-sql.php splits
--  this file on ";".
-- =====================================================================

SET NAMES utf8mb4;

-- ---- payments.match_hint / match_note ---------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'match_hint');
SET @sql := IF(@col = 0,
  'ALTER TABLE `payments` ADD COLUMN `match_hint` VARCHAR(20) NULL COMMENT ''possible_match = signed webhook money matches, a person must still verify'' AFTER `admin_note`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'match_note');
SET @sql := IF(@col = 0,
  'ALTER TABLE `payments` ADD COLUMN `match_note` VARCHAR(255) NULL COMMENT ''why the hint was set'' AFTER `match_hint`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- webhooks_received --------------------------------------------------
CREATE TABLE IF NOT EXISTS `webhooks_received` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider`     VARCHAR(50) NOT NULL,
  `event_id`     VARCHAR(191) NOT NULL,
  `payload_hash` CHAR(64) NOT NULL COMMENT 'sha256 of the raw body, never the body',
  `utr_hash`     CHAR(64) NULL COMMENT 'sha256 of the upper-cased UTR',
  `amount`       DECIMAL(10,2) NULL,
  `pay_status`   VARCHAR(20) NULL COMMENT 'success / failed / pending as the provider said',
  `booking_id`   BIGINT UNSIGNED NULL COMMENT 'set once matched',
  `outcome`      VARCHAR(30) NULL COMMENT 'confirmed / possible_match / unmatched / ignored',
  `received_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_event` (`provider`, `event_id`),
  KEY `idx_webhook_utr` (`utr_hash`),
  KEY `idx_webhook_booking` (`booking_id`),
  KEY `idx_webhook_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- settings -----------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('payment_webhook_on',          '0',  'bool',   'payment',  'Accept signed payment gateway / bank webhooks at api/payment-webhook.php', 0),
('payment_webhook_provider',    'generic', 'string', 'payment', 'Name recorded for webhook events (generic HMAC-SHA256 of the raw body)', 0),
('payment_webhook_secret',      '',   'string', 'payment',  'Webhook HMAC secret (the env var SHG_PAYMENT_WEBHOOK_SECRET wins when set)', 0),
('payment_webhook_autoconfirm', '0',  'bool',   'payment',  'Let a signed SUCCESS webhook with the full amount confirm the booking by itself', 0),
('payment_webhook_admin_id',    '',   'string', 'payment',  'admins.id recorded as the verifier for webhook confirmations (required for auto-confirm)', 0),
('wa_admin_commands_on',        '0',  'bool',   'whatsapp', 'Office staff may VERIFY / REJECT / COD a booking by WhatsApp from their own number', 0),
('notify_rate_minutes',         '10', 'int',    'whatsapp', 'Minimum minutes between two identical office alerts for one booking', 0),
('ai_local_on',                 '0',  'bool',   'ai',       'Ask the local model (Ollama on this server) before any cloud AI', 0),
('ai_local_endpoint',           'http://127.0.0.1:11434', 'string', 'ai', 'Local Ollama address', 0),
('ai_local_model',              'llama3.1', 'string', 'ai',  'Local Ollama model name', 0),
('ai_local_timeout',            '8',  'int',    'ai',       'Seconds to wait for the local model before falling back to the cloud', 0);
