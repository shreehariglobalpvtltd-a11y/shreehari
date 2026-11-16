-- =====================================================================
--  upgrade-2026-11-16c-X4-fb-comments.sql  (16 Nov 2026, Master v2.0 X4)
--
--  P6-6b / P6-7b  the comments under our Facebook Page's posts.
--
--  1. fb_comments — one row per comment that cron/fb-comments.php read
--     from the Graph API (posts of the last 7 days). comment_id is Meta's
--     id, UNIQUE, so a comment read again is stored once. thread_id is the
--     top comment of its thread (parent_id, or the comment itself): a
--     reply always goes there, and the AI answers a thread at most once.
--     is_page = 1 is a comment written by our own Page (an office or AI
--     reply read back). No Facebook user id is kept, only the name shown.
--       status   new      waiting for the office
--                replied  answered (here, or by the Page in Meta Business Suite)
--                hidden   hidden on Facebook
--                flagged  a complaint / abuse / angry comment: never
--                         answered automatically, the office looks at it
--     admin/fb-comments.php lists them (Comments | Flagged | All) and
--     posts the office's PUBLIC reply, audited (fb.comment_reply).
--
--  2. Switches, both OFF:
--       fb_comments_on     the cron reads comments; the office may reply
--       fb_comment_ai_on   one short public reply to a fare question (the
--                          fare-page link) or a how-to-book question (the
--                          booking link + office number); max 1 per thread,
--                          20 an hour; complaints never answered
--     fb_comments_state  the last Graph permission error, written by the
--                        cron once ({} = none).
--
--  Additive and safe to re-run. Before this file runs the cron is idle
--  ("fb_comments table missing"), the page says the table is missing, and
--  nothing is read from or written to Facebook.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `fb_comments` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `comment_id`     VARCHAR(80)   NOT NULL COMMENT 'Meta comment id - UNIQUE',
  `post_id`        VARCHAR(80)   NOT NULL COMMENT 'the Page post it is under',
  `parent_id`      VARCHAR(80)   NULL DEFAULT NULL COMMENT 'the comment it answers (a reply), else NULL',
  `thread_id`      VARCHAR(80)   NOT NULL COMMENT 'top comment of the thread = parent_id or comment_id',
  `post_text`      VARCHAR(200)  NULL DEFAULT NULL COMMENT 'the start of the post, for the office',
  `from_name`      VARCHAR(120)  NULL DEFAULT NULL COMMENT 'name shown on Facebook (no user id kept)',
  `is_page`        TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = written by our own Page',
  `message`        TEXT          NULL DEFAULT NULL,
  `created_time`   DATETIME      NULL DEFAULT NULL COMMENT 'when it was written (server local time)',
  `status`         ENUM('new','replied','hidden','flagged') NOT NULL DEFAULT 'new',
  `flagged_reason` VARCHAR(190)  NULL DEFAULT NULL,
  `our_reply_id`   VARCHAR(80)   NULL DEFAULT NULL COMMENT 'Meta id of our public reply',
  `reply_kind`     ENUM('auto','office') NULL DEFAULT NULL COMMENT 'auto = fb_comment_ai_on; office = admin/fb-comments.php',
  `reply_text`     TEXT          NULL DEFAULT NULL,
  `replied_by`     BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'admins.id (office reply)',
  `replied_at`     DATETIME      NULL DEFAULT NULL,
  `reply_error`    VARCHAR(255)  NULL DEFAULT NULL COMMENT 'Meta refusal of our last reply',
  `fetched_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fbc_comment` (`comment_id`),
  KEY `ix_fbc_thread` (`thread_id`),
  KEY `ix_fbc_post` (`post_id`),
  KEY `ix_fbc_status` (`status`, `created_time`),
  KEY `ix_fbc_reply` (`reply_kind`, `replied_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- settings.label is VARCHAR(191) on live: each label below is kept under it.
INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('fb_comments_on', '0', 'bool', 'facebook', 'Read the comments under our Facebook Page posts (last 7 days) into Admin -> FB comments; the office may reply there IN PUBLIC. Token needs pages_read_engagement + pages_manage_engagement', 0),
  ('fb_comment_ai_on', '0', 'bool', 'facebook', 'FB comments: one short PUBLIC auto-reply to a fare question (fare-page link) or a how-to-book question (booking link + office number). Complaints never answered. Max 1/thread, 20/hour', 0),
  ('fb_comments_state', '{}', 'json', 'facebook', 'FB comments: the last Graph permission error, written by the cron ({} = none)', 0);
