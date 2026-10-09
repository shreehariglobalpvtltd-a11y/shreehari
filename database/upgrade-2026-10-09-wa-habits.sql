-- =====================================================================
--  9 Oct 2026 - WaHabit: the WhatsApp bot learns repeated work
--  (includes/wahabit.php, cron/wa-habits.php every 15 min).
--  Re-running this file is harmless.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `wa_habits` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone`         VARCHAR(16)  NOT NULL COMMENT 'international digits, the Meta number window key',
  `role`          VARCHAR(10)  NOT NULL DEFAULT 'customer',
  `intent`        VARCHAR(30)  NOT NULL,
  `ask`           VARCHAR(120) NOT NULL COMMENT 'the words they use',
  `hour`          TINYINT UNSIGNED NOT NULL DEFAULT 9,
  `days_seen`     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `last_day`      DATE NULL,
  `status`        ENUM('seen','offered','on','off') NOT NULL DEFAULT 'seen',
  `lang`          VARCHAR(5) NOT NULL DEFAULT 'ne',
  `last_sent_day` DATE NULL,
  `last_note`     VARCHAR(80) NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_phone_intent` (`phone`, `intent`),
  KEY `ix_status_hour` (`status`, `hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('wa_habits_on', '1', 'bool', 'whatsapp', 'WhatsApp bot learns repeated work: the same question around the same hour on 3 days -> it offers to send that answer daily by itself (free, on the Meta number inside the window). "habit band" stops it', 0);
