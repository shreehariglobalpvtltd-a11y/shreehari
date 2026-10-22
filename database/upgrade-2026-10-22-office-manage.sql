-- =====================================================================
--  upgrade-2026-10-22-office-manage.sql
--
--  Owner, 22 Oct 2026: "admin ko system sita merge, KYC password email id
--  haru sabai form bata manage garna milne". The office manages a staff
--  member from the one application record, like an agent's card: details,
--  KYC, the team-portal password. The automatic document check
--  (doc_verify_status) stays a score; this is the office's own decision.
--
--    staff_applications.kyc_status       none | submitted | verified | rejected
--                                        (verified = the office saw the originals)
--    staff_applications.kyc_verified_by  admins.id
--    staff_applications.kyc_verified_at  when
--
--  Additive and re-runnable: ADD COLUMN IF NOT EXISTS only.
--      mysql shari < database/upgrade-2026-10-22-office-manage.sql
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `staff_applications` ADD COLUMN IF NOT EXISTS `kyc_status` ENUM('none','submitted','verified','rejected') NOT NULL DEFAULT 'submitted'
  COMMENT 'the office: verified = originals seen' AFTER `doc_verify_status`;
ALTER TABLE `staff_applications` ADD COLUMN IF NOT EXISTS `kyc_verified_by` BIGINT UNSIGNED NULL COMMENT 'admins.id' AFTER `kyc_status`;
ALTER TABLE `staff_applications` ADD COLUMN IF NOT EXISTS `kyc_verified_at` DATETIME NULL AFTER `kyc_verified_by`;
