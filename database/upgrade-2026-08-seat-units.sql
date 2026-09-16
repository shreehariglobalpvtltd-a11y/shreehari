-- =====================================================================
--  Upgrade: gender-aware shared-cabin locking (Part 2 · Feature A, 2026-08)
--  Run this ONCE on an existing live database (hPanel -> phpMyAdmin ->
--  select your database -> SQL tab -> paste -> Go).
--
--  Safe to run more than once: CREATE TABLE IF NOT EXISTS never drops or
--  overwrites an existing table, so nothing already booked is disturbed.
--
--  What it powers: a shared sleeper cabin ("unit" = two adjacent berths by
--  default, e.g. L1+L2) may not seat an unrelated male and female together.
--  Once a female occupies a bed, the other bed(s) in that same physical
--  cabin are held for women only until the whole cabin is vacated (and the
--  mirror rule protects a lone male cabin from a stranger of the other sex).
--  A group travelling together may still book the WHOLE cabin in one
--  checkout. Enforcement lives server-side inside the booking transaction;
--  this table is the per-trip, per-cabin state it locks and caches.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `schedule_unit_locks` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_id`       BIGINT UNSIGNED NOT NULL,
  `unit_key`          VARCHAR(20) NOT NULL COMMENT 'physical cabin key e.g. L-1 (berths L1+L2)',
  `gender_lock`       ENUM('none','female_only','male_only','mixed_allowed') NOT NULL DEFAULT 'none',
  `locked_by_booking` BIGINT UNSIGNED NULL COMMENT 'booking that group-booked the whole cabin (mixed_allowed)',
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sched_unit` (`schedule_id`,`unit_key`),
  KEY `ix_sul_lock` (`gender_lock`),
  CONSTRAINT `fk_sul_sched` FOREIGN KEY (`schedule_id`)
    REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
