-- =====================================================================
--  upgrade-2026-09-offline-requests.sql - the counter's offline queue
--  (includes/offlinequeue.php, api/offline-sync.php, offline-desk.html).
--  19 Sep 2026. Additive and re-runnable: one table, one setting. Ships OFF.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `offline_requests` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`         CHAR(36) NOT NULL COMMENT 'made on the phone when the request was written - the idempotency key',
  `admin_id`     BIGINT UNSIGNED NOT NULL COMMENT 'admins.id of the desk that took it',
  `name`         VARCHAR(120) NOT NULL,
  `phone`        VARCHAR(20)  NULL,
  `country`      VARCHAR(2)   NULL,
  `gender`       VARCHAR(10)  NULL,
  `seats`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `direction`    VARCHAR(10)  NULL,
  `travel_date`  DATE NULL,
  `boarding`     VARCHAR(191) NULL,
  `pay`          VARCHAR(10)  NULL,
  `cash_taken`   DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'what the desk says it collected',
  `note`         VARCHAR(255) NULL,
  `taken_at`     DATETIME NULL COMMENT 'the phone clock when it was written - informational only',
  `received_at`  DATETIME NOT NULL,
  `status`       ENUM('received','ticketed','failed','void') NOT NULL DEFAULT 'received',
  `booking_id`   BIGINT UNSIGNED NULL,
  `pnr`          VARCHAR(40) NULL,
  `total_amount` DECIMAL(10,2) NULL COMMENT 'what the ticket really cost - compare with cash_taken',
  `fail_reason`  VARCHAR(255) NULL,
  `resolved_by`  BIGINT UNSIGNED NULL,
  `resolved_at`  DATETIME NULL,
  `resolve_note` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offline_uuid` (`uuid`),
  KEY `ix_offline_status` (`status`,`received_at`),
  KEY `ix_offline_admin` (`admin_id`,`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('offline_queue_on', '0', 'bool', 'agent', 'Offline desk: when the internet is down a counter writes a ticket REQUEST on the phone and the server seats it when the internet is back', 0);
