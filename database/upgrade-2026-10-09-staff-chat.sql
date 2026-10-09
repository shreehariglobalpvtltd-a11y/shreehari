-- =====================================================================
--  9 Oct 2026 - Office chat: admin <-> agent / counter messages about
--  tickets, inside the panel (owner ask 14).
--  includes/staffmsg.php, admin/api/staff-msg.php, admin/staff-chat.php,
--  admin/staff-msg-file.php, admin/api/push.php, cron/staff-msg.php
--
--  staff_threads       one conversation between the office and ONE field
--                      staff member (role agent or counter): their general
--                      chat, or one ticket / settlement / payout / report.
--  staff_messages      the lines - text, photo, PDF, voice note, system line.
--  staff_thread_reads  per person read pointer (unread badge, seen ticks).
--  staff_presence      "online now / last seen" - written at most once a
--                      minute from the panel poll.
--  push_subscriptions  + admin_id so a staff phone can get a push.
--
--  Every statement is idempotent (IF NOT EXISTS / INSERT IGNORE). Re-running
--  this file is harmless. No semicolons inside comments (tests/apply-sql.php
--  splits on them).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `staff_threads` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`           VARCHAR(16) NOT NULL DEFAULT 'general' COMMENT 'general, booking, settlement, payout, verify',
  `agent_admin_id` BIGINT UNSIGNED NOT NULL COMMENT 'the field-side staff member (admins.role agent or counter)',
  `booking_id`     BIGINT UNSIGNED NULL COMMENT 'booking threads only',
  `pnr`            VARCHAR(40) NULL,
  `ref_id`         BIGINT UNSIGNED NULL COMMENT 'settlement id / payout request id',
  `subject`        VARCHAR(160) NOT NULL DEFAULT '',
  `status`         ENUM('open','closed') NOT NULL DEFAULT 'open',
  `priority`       ENUM('normal','urgent') NOT NULL DEFAULT 'normal',
  `assigned_to`    BIGINT UNSIGNED NULL COMMENT 'office person who took it',
  `created_by`     BIGINT UNSIGNED NULL,
  `uniq_key`       VARCHAR(64) NULL COMMENT 'g:agent / b:agent:booking / s:agent:id / p:agent:id / v:agent:hash - race-free find-or-create',
  `last_msg_id`    BIGINT UNSIGNED NULL,
  `last_msg_at`    DATETIME NULL,
  `last_side`      ENUM('office','staff','system') NULL,
  `last_preview`   VARCHAR(140) NULL,
  `msg_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `closed_at`      DATETIME NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_thread_key` (`uniq_key`),
  KEY `ix_staff_thread_agent` (`agent_admin_id`, `last_msg_id`),
  KEY `ix_staff_thread_status` (`status`, `last_side`, `last_msg_id`),
  KEY `ix_staff_thread_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_messages` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_id`       BIGINT UNSIGNED NOT NULL,
  `sender_admin_id` BIGINT UNSIGNED NULL COMMENT 'NULL = system line',
  `side`            ENUM('office','staff','system') NOT NULL,
  `kind`            VARCHAR(12) NOT NULL DEFAULT 'text' COMMENT 'text, image, pdf, voice, system, announce',
  `body`            TEXT NULL,
  `attach_path`     VARCHAR(255) NULL COMMENT 'relative staff-msg/YYYY/MM/<random> - never sent to a browser',
  `attach_mime`     VARCHAR(60) NULL,
  `attach_size`     INT UNSIGNED NULL,
  `attach_secs`     SMALLINT UNSIGNED NULL COMMENT 'voice note length',
  `urgent`          TINYINT(1) NOT NULL DEFAULT 0,
  `client_uuid`     CHAR(36) NULL COMMENT 'double-tap guard - the same uuid makes one message',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_msg_uuid` (`client_uuid`),
  KEY `ix_staff_msg_thread` (`thread_id`, `id`),
  CONSTRAINT `fk_staff_msg_thread` FOREIGN KEY (`thread_id`) REFERENCES `staff_threads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_thread_reads` (
  `thread_id`    BIGINT UNSIGNED NOT NULL,
  `admin_id`     BIGINT UNSIGNED NOT NULL,
  `last_read_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `read_at`      DATETIME NULL,
  PRIMARY KEY (`thread_id`, `admin_id`),
  KEY `ix_staff_reads_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_presence` (
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `seen_at`  DATETIME NOT NULL,
  PRIMARY KEY (`admin_id`),
  KEY `ix_staff_presence_seen` (`seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- staff web push: the subscription row knows which staff member it belongs to
ALTER TABLE `push_subscriptions` ADD COLUMN IF NOT EXISTS `admin_id` BIGINT UNSIGNED NULL COMMENT 'staff member this phone belongs to (office chat push)';
CREATE INDEX IF NOT EXISTS `ix_push_admin` ON `push_subscriptions` (`admin_id`, `is_active`);

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('staffmsg_on',             '1',   'bool',   'staffmsg', 'Office chat: agents and counters can message the office about tickets inside the panel', 0),
 ('staffmsg_push_on',        '1',   'bool',   'staffmsg', 'Office chat: phone push for a new message (only staff who allowed notifications)', 0),
 ('staffmsg_attach_on',      '1',   'bool',   'staffmsg', 'Office chat: photos, PDFs and voice notes', 0),
 ('staffmsg_wa_nudge_on',    '0',   'bool',   'staffmsg', 'Office chat: ONE WhatsApp template nudge when an agent has not read an office message (needs the approved template)', 0),
 ('staffmsg_wa_nudge_min',   '15',  'int',    'staffmsg', 'Office chat: minutes unread before the WhatsApp nudge', 0),
 ('staffmsg_wa_template',    'shg_staff_msg', 'string', 'staffmsg', 'Office chat: AiSensy template for the nudge (name, link)', 0),
 ('staffmsg_autoclose_days', '14',  'int',    'staffmsg', 'Office chat: close a conversation after this many quiet days (0 = never)', 0);
