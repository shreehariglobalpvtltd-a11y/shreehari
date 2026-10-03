-- 3 Oct 2026 - customer master record (owner prompt "Customer Master / Ledger / 360")
--
-- The customer vault (user_profiles, keyed by the normalised 10-digit phone)
-- already holds name, country, travel habits and merged_into. This adds the
-- contact facts the office keeps by hand and a stable customer number.
-- Every COUNT and TOTAL (bookings, passengers, spend, first / last booking,
-- last journey) is still computed live from the bookings tables by
-- includes/customerbook.php - nothing here stores a total that could go stale.
--
--   customer_no     stable number printed as CUS-00042 (auto, never reused)
--   alt_phone       second mobile the office may call
--   whatsapp_phone  number tickets should go to on WhatsApp when different
--   email           office-kept email (users.email is the app sign-in one)
--   preferred_drop  office override - blank = learnt from bookings
--   notes           free text for the office
--   name_locked     1 = the office fixed the spelling - a later sale typing
--                   a different spelling no longer replaces it
--
-- customer_dup_dismissed remembers "these two numbers are NOT the same
-- person" so the duplicate finder stops showing the pair.
--
-- Additive, idempotent (MariaDB IF NOT EXISTS).

ALTER TABLE `user_profiles`
  ADD COLUMN IF NOT EXISTS `customer_no`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD UNIQUE KEY IF NOT EXISTS `uq_profile_customer_no` (`customer_no`);

ALTER TABLE `user_profiles`
  ADD COLUMN IF NOT EXISTS `alt_phone`      VARCHAR(20)  NULL,
  ADD COLUMN IF NOT EXISTS `whatsapp_phone` VARCHAR(20)  NULL,
  ADD COLUMN IF NOT EXISTS `email`          VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS `preferred_drop` VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS `notes`          TEXT         NULL,
  ADD COLUMN IF NOT EXISTS `name_locked`    TINYINT(1)   NOT NULL DEFAULT 0,
  ADD KEY IF NOT EXISTS `ix_profile_alt_phone` (`alt_phone`),
  ADD KEY IF NOT EXISTS `ix_profile_name` (`full_name`);

CREATE TABLE IF NOT EXISTS `customer_dup_dismissed` (
  `phone_a`      VARCHAR(20)     NOT NULL,
  `phone_b`      VARCHAR(20)     NOT NULL,
  `dismissed_by` BIGINT UNSIGNED NULL,
  `created_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`phone_a`, `phone_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
