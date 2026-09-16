-- =====================================================================
--  upgrade-2026-09-pwa-master.sql  (13 Sep 2026 - PWA master upgrade)
--
--  1. push_subscriptions  - one row per browser that asked for Web Push
--                           (delay alerts, reminders, ticket ready). Keyed
--                           by the endpoint hash so a re-subscribe updates
--                           in place. phone is the normalised mobile the
--                           subscription belongs to, booking_id the ticket
--                           it was taken on.
--  2. push_log            - every push attempt with the HTTP outcome, so a
--                           silent push-service failure is visible the way
--                           message_logs makes WhatsApp failures visible.
--  3. payment_shares      - group booking split-pay: one row per friend,
--                           amount, and the UTR they typed on the share page.
--                           Informational for the office (the payments row
--                           stays the record of money) - the desk verifies
--                           the total exactly as before.
--  4. settings            - push master switch + VAPID keys (generated on
--                           first use, never public), border card window,
--                           split-pay switch.
--
--  Apply:  php tests/apply-sql.php database/upgrade-2026-09-pwa-master.sql
--  (the applier splits on semicolons - keep them out of comments)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `endpoint`      TEXT NOT NULL,
  `endpoint_hash` CHAR(40) NOT NULL COMMENT 'sha1 of the endpoint URL - the row key',
  `p256dh`        VARCHAR(120) NOT NULL COMMENT 'browser public key, base64url',
  `auth`          VARCHAR(40)  NOT NULL COMMENT 'browser auth secret, base64url',
  `phone`         VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'normalised mobile this phone belongs to',
  `user_id`       BIGINT UNSIGNED NULL,
  `booking_id`    BIGINT UNSIGNED NULL COMMENT 'the ticket the subscription was taken on',
  `lang`          VARCHAR(5)   NOT NULL DEFAULT 'ne',
  `ua`            VARCHAR(160) NOT NULL DEFAULT '',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `fail_count`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_status`   SMALLINT UNSIGNED NULL COMMENT 'HTTP status of the last send',
  `last_sent_at`  DATETIME NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_endpoint` (`endpoint_hash`),
  KEY `ix_push_phone` (`phone`),
  KEY `ix_push_booking` (`booking_id`),
  KEY `ix_push_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_log` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_id` BIGINT UNSIGNED NULL,
  `booking_id`      BIGINT UNSIGNED NULL,
  `event`           VARCHAR(40)  NOT NULL DEFAULT '',
  `title`           VARCHAR(191) NOT NULL DEFAULT '',
  `http_status`     SMALLINT UNSIGNED NULL,
  `ok`              TINYINT(1)   NOT NULL DEFAULT 0,
  `error`           VARCHAR(255) NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_pushlog_booking` (`booking_id`),
  KEY `ix_pushlog_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_shares` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`  BIGINT UNSIGNED NOT NULL,
  `share_no`    TINYINT UNSIGNED NOT NULL,
  `amount`      DECIMAL(10,2) NOT NULL DEFAULT 0,
  `payer_name`  VARCHAR(120) NULL,
  `payer_phone` VARCHAR(20)  NULL,
  `utr`         VARCHAR(60)  NULL COMMENT 'UPI UTR / eSewa code the friend typed',
  `status`      ENUM('open','claimed','verified') NOT NULL DEFAULT 'open',
  `claimed_at`  DATETIME NULL,
  `note`        VARCHAR(255) NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_share_booking_no` (`booking_id`, `share_no`),
  KEY `ix_share_status` (`status`),
  CONSTRAINT `fk_share_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('push_enabled','1','bool','notify','Web Push notifications to installed apps (delay alerts, reminders, ticket ready)',0),
('push_vapid_public','','string','notify','VAPID public key (generated automatically on first use - leave blank)',0),
('push_vapid_private','','string','notify','VAPID private key (generated automatically - never share)',0),
('push_vapid_subject','mailto:shreehariglobalpvtltd@gmail.com','string','notify','VAPID contact (mailto: or https:) sent to the push services',0),
('border_card_hours','24','int','booking','Hours before departure when the Border Crossing Prep card opens itself on India to Nepal tickets',1),
('split_pay_enabled','1','bool','payment','Group bookings: show Split with friends (per-person UPI share links)',1);
