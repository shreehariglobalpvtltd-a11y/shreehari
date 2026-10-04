-- =====================================================================
--  Upgrade 2026-10-04 — WhatsApp ticket by "hi" (free 24 h window),
--  a second number (India / Nepal), and a copy to the counter that sold it.
--
--      mysql shari < database/upgrade-2026-10-04-wa-hi-queue.sql
--
--  Idempotent and additive. Owner (4 Oct 2026): "AiSensy bata WhatsApp …
--  Nepal India 2 tai number ma jane … customer lai nagaye kun counter bata
--  katyo tyaslai gayeni hunchha … free automatic … ek choti hi lekhne bas,
--  24 free msg."
--
--  WHY A QUEUE. WhatsApp delivers a free-form message (the ticket picture)
--  only inside the 24 h after that person last wrote to our AiSensy number.
--  Outside it the send is accepted and then fails (131047). So a ticket that
--  cannot go now WAITS here, and goes the moment that number says "hi".
-- =====================================================================

SET NAMES utf8mb4;

-- The second WhatsApp number on a booking (the other country's SIM).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'alt_phone');
SET @s := IF(@c = 0,
  'ALTER TABLE `bookings` ADD COLUMN `alt_phone` VARCHAR(20) NULL COMMENT ''second WhatsApp number (the other country) - the ticket goes to both'' AFTER `contact_country_code`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'alt_country_code');
SET @s := IF(@c = 0,
  'ALTER TABLE `bookings` ADD COLUMN `alt_country_code` VARCHAR(4) NULL COMMENT ''91 or 977 for alt_phone'' AFTER `alt_phone`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- When each number last wrote to the AiSensy number (opens its free 24 h window).
CREATE TABLE IF NOT EXISTS `wa_windows` (
  `phone`      VARCHAR(16)  NOT NULL COMMENT 'international digits, no + (91…, 977…)',
  `last_in_at` DATETIME     NOT NULL COMMENT 'last message FROM this number - the free window runs 24 h from here',
  `last_text`  VARCHAR(160) NULL,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per ticket per number: waiting, sent, delivered or failed.
CREATE TABLE IF NOT EXISTS `wa_ticket_queue` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id` BIGINT UNSIGNED NOT NULL,
  `pnr`        VARCHAR(40)  NOT NULL,
  `phone`      VARCHAR(16)  NOT NULL COMMENT 'international digits, no +',
  `role`       VARCHAR(10)  NOT NULL DEFAULT 'customer' COMMENT 'customer | alt | seller',
  `status`     VARCHAR(12)  NOT NULL DEFAULT 'pending' COMMENT 'pending | sent | delivered | read | failed | expired',
  `via`        VARCHAR(20)  NULL COMMENT 'template | image | text',
  `wamid`      VARCHAR(190) NULL,
  `attempts`   INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(255) NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`    DATETIME     NULL,
  `checked_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_waq_booking_phone` (`booking_id`, `phone`),
  KEY `ix_waq_phone_status` (`phone`, `status`),
  KEY `ix_waq_status_sent` (`status`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
 ('aisensy_wa_number','919157001507','string','aisensy','The AiSensy WhatsApp number customers send "hi" to (digits with country code)',1),
 ('wa_hi_queue_on','1','bool','aisensy','A ticket that cannot go now waits and goes free when that number says "hi"',0),
 ('wa_seller_copy_on','1','bool','aisensy','Also send the ticket to the counter / agent who sold it when the customer has not got it',0);
