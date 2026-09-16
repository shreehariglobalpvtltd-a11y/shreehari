-- =====================================================================
--  Upgrade: real SMS gateway (Twilio SMS) + outbound message log (2026-08)
--  Run this ONCE on an existing live database (hPanel -> phpMyAdmin ->
--  select your database -> SQL tab -> paste -> Go).
--
--  Safe to re-run: CREATE TABLE IF NOT EXISTS never drops an existing
--  table, and INSERT IGNORE skips settings keys that already exist.
--
--  What it powers:
--   * A real SMS channel over Twilio (reuses the same twilio_account_sid /
--     twilio_auth_token already used for WhatsApp; only the *sender* differs,
--     so add an SMS-capable Twilio number as twilio_sms_from).
--   * A persistent `message_logs` table recording every outbound SMS /
--     WhatsApp send (success AND failure) with recipient, body, provider,
--     provider message id and any error — the "Store SMS logs" requirement.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `message_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`   BIGINT UNSIGNED NULL COMMENT 'related booking, if any (OTP sends have none)',
  `channel`      ENUM('sms','whatsapp','email') NOT NULL DEFAULT 'sms',
  `provider`     VARCHAR(40)  NOT NULL DEFAULT '' COMMENT 'twilio / cloud_api / ...',
  `to_number`    VARCHAR(32)  NOT NULL DEFAULT '',
  `body`         VARCHAR(1000) NULL,
  `status`       ENUM('sent','failed','skipped','queued') NOT NULL DEFAULT 'sent',
  `provider_ref` VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'provider message SID',
  `error`        VARCHAR(255) NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_msg_created` (`created_at`),
  KEY `ix_msg_channel` (`channel`,`status`),
  KEY `ix_msg_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS settings (shown on Admin -> Settings; group "notify" alongside Twilio).
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('sms_enabled',        '0','bool',  'notify','SMS channel enabled (Twilio SMS)',0),
('sms_notify_customer','1','bool',  'notify','Send booking-confirmation SMS to the customer',0),
('sms_send_otp',       '1','bool',  'notify','Send OTP login codes by SMS',0),
('twilio_sms_from',    '', 'string','notify','Twilio SMS sender — SMS-capable number e.g. +14155550123 (NOT the WhatsApp sender)',0);
