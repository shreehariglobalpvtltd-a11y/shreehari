-- =====================================================================
--  upgrade-2026-11-19-u8-contact-lang.sql  (unit u8, AI-2)
--
--  contact_lang: the language each WhatsApp contact writes in, one row per
--  E.164 number (digits only: 91… / 977…). Only a language code and vote
--  counters are stored — never message text. 'chosen' (the portal's own
--  language switch) is never overwritten by chat votes.
--  CustomerDirectory::lang() / noteLang() / setLang(); backfill with
--  tools/contact-lang-backfill.php. Idempotent.
--
--  mk_campaigns.template_lang: the language of an autopilot campaign's
--  line (MkAutopilot splits each group by language). Sending is unchanged.
-- =====================================================================

CREATE TABLE IF NOT EXISTS contact_lang (
  phone      VARCHAR(16) NOT NULL PRIMARY KEY COMMENT 'E.164 digits 91…/977…',
  lang       ENUM('ne','hi','en') NOT NULL DEFAULT 'ne',
  source     ENUM('chosen','chat','default') NOT NULL DEFAULT 'chat',
  ne_n       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  hi_n       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  en_n       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'mk_campaigns' AND column_name = 'template_lang');
SET @t := (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'mk_campaigns');
SET @sql := IF(@t = 1 AND @c = 0,
  'ALTER TABLE mk_campaigns ADD COLUMN template_lang VARCHAR(12) NULL AFTER template_params',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label) VALUES
  ('mk_default_lang', 'ne',       'string', 'marketing', 'Language for a contact whose language is not known'),
  ('mk_auto_langs',   'ne,hi,en', 'string', 'marketing', 'Autopilot writes one line per language in this list');
