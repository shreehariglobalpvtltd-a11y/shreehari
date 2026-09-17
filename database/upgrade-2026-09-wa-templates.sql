-- =====================================================================
--  Upgrade 2026-09 — WhatsApp automation for the office
--  17 Sep 2026
--
--  Run ONCE on the live database (phpMyAdmin -> SQL tab -> paste -> Go),
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-09-wa-templates.sql
--
--  Fully idempotent: every ALTER is guarded against information_schema
--  and skipped when the column / index is already there; settings rows
--  are INSERT IGNORE. Safe to re-run while the site is live.
--
--  WHAT THIS ADDS
--  --------------
--  message_logs.purpose         which template a row came from (ticket,
--                               agent_statement, payment_reminder ...) so
--                               the log can be filtered and the retry cron
--                               only ever re-sends TICKETS.
--  message_logs.admin_id        the staff member who pressed "Send".
--  message_logs.agent_admin_id  the agent the message was addressed to.
--  message_logs.media_url       the PDF / PNG that travelled with it.
--  admin_profiles.country_code  977 or 91 for an AGENT's number — bookings
--                               already know their country; agents did not,
--                               so a Nepali agent's messages were dialled as
--                               +91. Backfilled from 13-digit 977 numbers.
--  settings                     the office switch, statement-link expiry,
--                               per-purpose Twilio Content SIDs (approved
--                               templates for business-initiated messages)
--                               and the Cloud-API keys that were read by the
--                               code but never seeded (so they never showed
--                               in Admin -> Settings).
--
--  NB: no semicolon inside any quoted string — tests/apply-sql.php splits
--  this file on ";".
-- =====================================================================

SET NAMES utf8mb4;

-- ---- message_logs columns -------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_logs' AND COLUMN_NAME = 'purpose');
SET @sql := IF(@col = 0,
  'ALTER TABLE `message_logs` ADD COLUMN `purpose` VARCHAR(40) NULL COMMENT ''template / reason: ticket, agent_statement, payment_reminder ...'' AFTER `channel`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_logs' AND COLUMN_NAME = 'admin_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE `message_logs` ADD COLUMN `admin_id` BIGINT UNSIGNED NULL COMMENT ''admins.id of the staff member who triggered the send'' AFTER `purpose`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_logs' AND COLUMN_NAME = 'agent_admin_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE `message_logs` ADD COLUMN `agent_admin_id` BIGINT UNSIGNED NULL COMMENT ''admins.id of the agent the message was addressed to'' AFTER `admin_id`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_logs' AND COLUMN_NAME = 'media_url');
SET @sql := IF(@col = 0,
  'ALTER TABLE `message_logs` ADD COLUMN `media_url` VARCHAR(255) NULL COMMENT ''PDF / PNG link that travelled with the message'' AFTER `body`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_logs' AND INDEX_NAME = 'ix_msg_purpose');
SET @sql := IF(@idx = 0, 'ALTER TABLE `message_logs` ADD KEY `ix_msg_purpose` (`purpose`, `id`)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_logs' AND INDEX_NAME = 'ix_msg_agent');
SET @sql := IF(@idx = 0, 'ALTER TABLE `message_logs` ADD KEY `ix_msg_agent` (`agent_admin_id`, `id`)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- admin_profiles.country_code ------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'country_code');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `country_code` VARCHAR(5) NULL COMMENT ''977 or 91 — dialling code for the agent phone / whatsapp'' AFTER `whatsapp`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- One-time backfill: a 13-digit number starting 977 is unmistakably Nepal.
-- A bare 10-digit number stays NULL (India and Nepal share the shape) and
-- the office sets the country once on the agent's profile.
UPDATE `admin_profiles` p
  JOIN `admins` a ON a.id = p.admin_id
   SET p.country_code = '977'
 WHERE p.country_code IS NULL
   AND ((a.phone LIKE '977%' AND LENGTH(a.phone) = 13)
        OR (p.whatsapp LIKE '977%' AND LENGTH(p.whatsapp) = 13));

-- ---- settings --------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('wa_admin_tools_enabled',               '1',     'bool',   'whatsapp', 'One-click WhatsApp sends from the admin panel (statements, reminders, tickets)', 0),
('wa_statement_link_days',               '7',     'int',    'whatsapp', 'Days a WhatsApp statement PDF link stays valid', 0),
('twilio_content_sid_agent_statement',   '',      'string', 'whatsapp', 'Twilio Content SID — approved template for agent statements / summaries (blank = free text, needs an open 24h chat)', 0),
('twilio_content_sid_payment_reminder',  '',      'string', 'whatsapp', 'Twilio Content SID — approved template for payment reminders (blank = free text)', 0),
('twilio_content_sid_settlement',        '',      'string', 'whatsapp', 'Twilio Content SID — approved template for settlement / payout receipts (blank = free text)', 0),
('twilio_content_sid_booking_detail',    '',      'string', 'whatsapp', 'Twilio Content SID — approved template for passenger / booking detail messages (blank = free text)', 0),
('whatsapp_template_name',               '',      'string', 'whatsapp', 'Meta Cloud API: approved template name for the ticket message', 0),
('whatsapp_template_lang',               'en',    'string', 'whatsapp', 'Meta Cloud API: template language code', 0),
('whatsapp_api_version',                 'v21.0', 'string', 'whatsapp', 'Meta Cloud API: Graph API version', 0),
('twilio_status_callback_url',           '',      'string', 'whatsapp', 'Twilio delivery-status callback URL (blank = https site URL + /api/twilio-status.php)', 0);
