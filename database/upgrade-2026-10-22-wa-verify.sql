-- =====================================================================
--  upgrade-2026-10-22-wa-verify.sql
--
--  Owner, 22 Oct 2026: "agent ra staff verification - WhatsApp ma hi
--  lekhne, redirect garera, ani back aune". The form opens WhatsApp with
--  "hi VERIFY K7Q2M9" already typed to the company number; the person taps
--  Send; the webhook sees that message come FROM their number and marks the
--  number proven (includes/waverify.php). No paid code template, nothing to
--  type back - the form turns green by itself.
--
--    wa_verify                      one row per button press (30 min life)
--    agent_applications.phone_verified  1 = the agent form's number was
--                                   proven this way (0 for every older row)
--
--  Additive and re-runnable.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `wa_verify` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token`         CHAR(6)      NOT NULL COMMENT 'the word after VERIFY in the WhatsApp message',
  `purpose`       VARCHAR(20)  NOT NULL DEFAULT 'staff' COMMENT 'staff | agent | sign',
  `phone`         VARCHAR(15)  NOT NULL COMMENT '10-digit mobile typed on the form',
  `intl`          VARCHAR(16)  NOT NULL COMMENT 'country code + mobile, the WhatsApp sender we expect',
  `expires_at`    DATETIME     NOT NULL,
  `verified_at`   DATETIME     NULL,
  `verified_from` VARCHAR(20)  NULL COMMENT 'the sender that proved it',
  `mismatch_from` VARCHAR(20)  NULL COMMENT 'a different number sent this token',
  `ip_address`    VARCHAR(45)  NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_verify_token` (`token`),
  KEY `ix_wa_verify_phone` (`phone`, `verified_at`),
  KEY `ix_wa_verify_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `agent_applications` ADD COLUMN IF NOT EXISTS `phone_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = number proven by WhatsApp hi VERIFY on the form';
