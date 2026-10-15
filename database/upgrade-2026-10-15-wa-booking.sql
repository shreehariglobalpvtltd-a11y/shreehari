-- =====================================================================
--  15 Oct 2026 - WhatsApp super-fast booking, second wave
--  (includes/wabooking.php, includes/waroute.php, includes/msgledger.php,
--  whatsapp/webhook.php, cron/wa-inbound.php, admin/wa-manager.php).
--
--  Run ONCE on the live database (safe to re-run - IF NOT EXISTS / IGNORE):
--      deploy.sh --migrate database/upgrade-2026-10-15-wa-booking.sql
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-10-15-wa-booking.sql
--
--  WHAT
--    1. wa_inbound - every inbound Meta message is written here FIRST
--       (wamid UNIQUE = the dedupe), answered inline as before, marked done.
--       cron/wa-inbound.php answers a row left pending / failed by a crash
--       (2 min old, at most 3 attempts, never older than an hour).
--    2. message_logs(provider_ref) index - every Meta delivered / read /
--       failed status looks its row up by wamid. Without it each status
--       scanned the whole table.
--    3. settings - all default to TODAY's behaviour:
--         wa_book_combo_on        1  customers get the one combined question
--                                    (0 = the guided one-question-at-a-time order)
--         wa_paid_daily_limit     '' no cap on paid templates (0 = never pay,
--                                    N = at most N paid messages a day)
--         wa_meta_free_allowance  0  Meta free messages per month to track
--                                    against (0 = not tracked)
--         wa_booking_on           1  WhatsApp chat selling (already read with
--                                    default ON - the row makes it switchable)
--
--  Additive only: no column is changed, no row is removed. wa_faq_on is NOT
--  touched here (see the release notes - an owner decision).
--
--  ROLLBACK
--    DROP TABLE wa_inbound
--    ALTER TABLE message_logs DROP INDEX ix_msg_ref
--    DELETE FROM settings WHERE skey IN ('wa_book_combo_on','wa_paid_daily_limit','wa_meta_free_allowance')
-- =====================================================================

CREATE TABLE IF NOT EXISTS `wa_inbound` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `wamid`       VARCHAR(128) NOT NULL COMMENT 'Meta message id - UNIQUE, the dedupe key',
  `from_digits` VARCHAR(20)  NOT NULL COMMENT 'sender, international digits',
  `msg_type`    VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'text, interactive, button, audio, image ...',
  `payload`     MEDIUMTEXT   NULL COMMENT 'the message as Meta sent it (JSON) - blanked 7 days after done',
  `status`      ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
  `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'the inline answer is attempt 1, replays add one each',
  `next_at`     DATETIME NULL COMMENT 'not replayed before this time (2, 4, 8 minutes)',
  `last_error`  VARCHAR(255) NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wi_wamid` (`wamid`),
  KEY `ix_wi_due` (`status`, `next_at`),
  KEY `ix_wi_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `message_logs`
  ADD INDEX IF NOT EXISTS `ix_msg_ref` (`provider_ref`);

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('wa_book_combo_on',       '1', 'bool',   'whatsapp', 'WhatsApp booking - customers are asked name, boarding, date and how many in ONE message (0 = one question at a time)', 0),
 ('wa_paid_daily_limit',    '',  'string', 'whatsapp', 'Paid WhatsApp messages per day (AiSensy + Meta templates). Empty = no limit, 0 = never pay', 0),
 ('wa_meta_free_allowance', '0', 'int',    'whatsapp', 'Meta free messages per month to count against (0 = not tracked)', 0),
 ('wa_booking_on',          '1', 'bool',   'whatsapp', 'WhatsApp chat selling (WaBooking) - customers book inside the chat', 0);
