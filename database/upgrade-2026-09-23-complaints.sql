-- =====================================================================
--  upgrade-2026-09-23-complaints.sql - the Help bot's "Raise complaint"
--  desk (includes/complaints.php, api/complaint.php, admin/complaints.php).
--  UI/UX v3 brief §8, 23 Sep 2026.
--
--  Additive and re-runnable: one table, two settings rows. Ships OFF
--  (complaints_on = 0): until it is switched on the bot keeps filing
--  complaints into the Enquiries inbox exactly as before.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `complaints` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_no`   VARCHAR(24)  NOT NULL COMMENT 'SHG-C-yymmdd-XXXX, what the passenger is told',
  `name`        VARCHAR(120) NOT NULL,
  `phone`       VARCHAR(20)  NOT NULL,
  `pnr`         VARCHAR(40)  NULL,
  `category`    VARCHAR(20)  NOT NULL DEFAULT 'other' COMMENT 'late,staff,luggage,seat,refund,border,ac,other',
  `message`     VARCHAR(1000) NOT NULL,
  `lang`        CHAR(2)      NOT NULL DEFAULT 'en',
  `status`      ENUM('open','in_progress','resolved') NOT NULL DEFAULT 'open',
  `wa_sent`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = an API driver accepted the office WhatsApp',
  `wa_link`     VARCHAR(600) NULL COMMENT 'wa.me fallback the passenger was offered',
  `admin_note`  VARCHAR(500) NULL,
  `handled_by`  INT UNSIGNED NULL,
  `resolved_at` DATETIME     NULL,
  `ip`          VARCHAR(45)  NULL,
  `user_agent`  VARCHAR(255) NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_complaints_ticket` (`ticket_no`),
  KEY `ix_complaints_status` (`status`),
  KEY `ix_complaints_phone` (`phone`),
  KEY `ix_complaints_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('complaints_on',       '0',            'bool',   'support', 'Help bot files complaints as tickets (SHG-C-…) with an Open / In progress / Resolved board and a WhatsApp to the office (off = the old Enquiries inbox)', 1),
('complaint_whatsapp',  '918735881507', 'string', 'support', 'WhatsApp number that receives every new complaint (digits with country code)', 1);
