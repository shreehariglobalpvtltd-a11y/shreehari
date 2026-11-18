-- =====================================================================
--  upgrade-2026-11-19-u8-ai-review.sql  (unit u8, AI-1)
--
--  ai_reviews: one row per closed IST day written by cron/ai-review.php
--  (includes/aireview.php). status ok | skipped | failed. The facts are
--  computed by code; review is Claude's JSON answer (what went wrong,
--  what to teach the bot, recommendations in Nepali, anomalies).
--
--  Settings rows mirror the code defaults (the code reads every one with
--  the same default, so this file is optional for behaviour). Idempotent.
-- =====================================================================

CREATE TABLE IF NOT EXISTS ai_reviews (
  day          DATE          NOT NULL PRIMARY KEY,
  status       ENUM('ok','skipped','failed') NOT NULL,
  model        VARCHAR(60)   NOT NULL DEFAULT '',
  note         VARCHAR(255)  NULL,
  cost_usd     DECIMAL(10,4) NOT NULL DEFAULT 0,
  in_tokens    INT UNSIGNED  NOT NULL DEFAULT 0,
  out_tokens   INT UNSIGNED  NOT NULL DEFAULT 0,
  facts        LONGTEXT      NULL,
  review       LONGTEXT      NULL,
  summary_ne   TEXT          NULL,
  kb_saved     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  kb_published SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME      NOT NULL,
  updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_air_status (status, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label) VALUES
  ('ai_review_on',        '1',               'bool',   'ai', 'Claude night review of the closed day (00:00 IST)'),
  ('ai_review_hour',      '0',               'int',    'ai', 'Night review hour (IST)'),
  ('ai_review_model',     'claude-opus-5-5', 'string', 'ai', 'Night review model'),
  ('ai_review_usd',       '1.50',            'string', 'ai', 'Night review: most to spend in one night (USD)'),
  ('ai_review_month_usd', '45',              'string', 'ai', 'Night review: most to spend in one month (USD)'),
  ('ai_review_autolearn', '0',               'bool',   'ai', 'Night review: publish its KB answers at once (0 = drafts)'),
  ('ai_staff_provider',   'anthropic',       'string', 'ai', 'AI brain that answers office / agent turns first'),
  ('ai_staff_model',      'claude-haiku-5-5','string', 'ai', 'Claude model for office / agent turns');
