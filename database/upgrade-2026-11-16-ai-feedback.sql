-- =====================================================================
--  upgrade-2026-11-16-ai-feedback.sql
--
--  The office's verdict on a bot reply (SHG Network Master Upgrade v2.0,
--  P7-9b): 👍 / 👎 beside each bot_reply bubble in admin/wa-inbox.php, and
--  the "👎 last 7 days" list on admin/ai-knowledge.php (write the article
--  the bot was missing).
--
--  ai_feedback_log ALREADY EXISTS on live (0 rows, from an earlier line);
--  the CREATE below is that exact table, for a server that lacks it. Two
--  optional columns are added so one person's second vote on the same reply
--  replaces the first and the inbox can show what was pressed:
--    msg_id    message_logs.id of the bot reply judged
--    admin_id  admins.id who judged it (NULL = not an office vote)
--  The customer's number is never stored - only sha256 in owner_key.
--
--  Additive and re-runnable. Code that runs before this file still works:
--  with the live table it stores the verdict without the two columns
--  (WaInbox::feedbackReady() = 1); with no table the buttons are hidden.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_feedback_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_key` char(64) NOT NULL,
  `channel` varchar(20) NOT NULL,
  `verdict` enum('up','down','correction') NOT NULL,
  `user_text` text DEFAULT NULL,
  `reply_text` text DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_fb_time` (`created_at`),
  KEY `ix_fb_verdict` (`verdict`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'ai_feedback_log' AND COLUMN_NAME = 'msg_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE `ai_feedback_log` ADD COLUMN `msg_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT ''message_logs.id of the bot reply judged''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'ai_feedback_log' AND COLUMN_NAME = 'admin_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE `ai_feedback_log` ADD COLUMN `admin_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT ''admins.id who judged it (NULL = not an office vote)''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'ai_feedback_log' AND INDEX_NAME = 'ix_fb_msg');
SET @sql := IF(@idx = 0,
  'ALTER TABLE `ai_feedback_log` ADD KEY `ix_fb_msg` (`msg_id`, `admin_id`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
