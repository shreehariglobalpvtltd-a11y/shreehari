-- =====================================================================
--  Upgrade: Quick-Booking enquiries capture (2026-08)
--  Run this ONCE on an existing live database (hPanel -> phpMyAdmin ->
--  select your database -> SQL tab -> paste -> Go).
--
--  Safe to run more than once: CREATE TABLE IF NOT EXISTS never drops or
--  overwrites an existing table, so already-captured leads are preserved.
--
--  What it powers: the homepage "Quick Booking" card saves the
--  passenger's intent straight to the database (no OTP) so staff can
--  follow up, while the visitor continues into the normal search flow.
--  Leads appear in the admin panel under "Enquiries".
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `enquiries` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(120) NOT NULL,
  `phone`        VARCHAR(20)  NOT NULL,
  `gender`       ENUM('Male','Female','Other') NULL,
  `nationality`  VARCHAR(60)  NULL,
  `age`          TINYINT UNSIGNED NULL,
  `travel_date`  DATE NULL,
  `seats`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `note`         VARCHAR(255) NULL,
  `source`       VARCHAR(40)  NOT NULL DEFAULT 'quick_booking' COMMENT 'which card/form captured the lead',
  `status`       ENUM('new','contacted','converted','closed') NOT NULL DEFAULT 'new',
  `ip`           VARCHAR(45)  NULL,
  `user_agent`   VARCHAR(255) NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_enquiries_created` (`created_at`),
  KEY `ix_enquiries_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
