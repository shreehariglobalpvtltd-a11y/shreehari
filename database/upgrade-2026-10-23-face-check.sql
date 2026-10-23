-- 23 Oct 2026: live selfie KYC check (includes/facecheck.php) - one line for the office per application.
-- Idempotent. The code works without it (FaceCheck::columnReady) and starts writing once it is applied.
ALTER TABLE `agent_applications` ADD COLUMN IF NOT EXISTS `face_check` VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `staff_applications` ADD COLUMN IF NOT EXISTS `face_check` VARCHAR(255) NULL DEFAULT NULL;
