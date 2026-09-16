-- =====================================================================
--  Upgrade: admin seat management — out-of-service blocks (Part 2 · Feature C)
--  Run this ONCE on an existing live database (hPanel -> phpMyAdmin ->
--  select your database -> SQL tab -> paste -> Go).
--
--  Safe to re-run: CREATE TABLE IF NOT EXISTS never drops an existing table.
--
--  What it powers: from Admin -> Seat Map an admin can take a berth out of
--  sale (a broken/reserved seat) without inventing a fake booking. A blocked
--  seat is treated as unavailable everywhere availability is computed, so it
--  can never be sold online or at the counter until it is unblocked.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `seat_blocks` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_id` BIGINT UNSIGNED NOT NULL,
  `seat_no`     VARCHAR(10) NOT NULL,
  `reason`      VARCHAR(191) NULL,
  `blocked_by`  BIGINT UNSIGNED NULL COMMENT 'admins.id who blocked it',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_block_seat` (`schedule_id`,`seat_no`),
  KEY `ix_block_sched` (`schedule_id`),
  CONSTRAINT `fk_block_sched` FOREIGN KEY (`schedule_id`)
    REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
