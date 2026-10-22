-- =====================================================================
--  upgrade-2026-10-22-staff-applications.sql
--
--  Owner, 22 Oct 2026: the agent pattern for STAFF. Drivers, conductors,
--  helpers, office, accountants, mechanics, cleaners and security apply
--  online at /agreements/staff.html, the office approves in
--  Admin -> Staff applications, and an approved person appears on the
--  website ("Our team") with name, photo, phone, district and role.
--
--    staff_applications     one row per applicant (one per mobile).
--                           Files live in uploads/staff/<id>/ under random
--                           names and are served only by admin/staff-file.php
--                           (nginx denies /uploads/staff/).
--    agreement_signatures   who accepted which agreement version, when,
--                           from which IP, with the mobile proven by a
--                           WhatsApp code or not.
--
--  NOT the payroll register: staff_members (2 Oct 2026) keeps salary and
--  advances for people already working. Approval here does not create a
--  payroll row.
--
--  Additive and re-runnable: CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT
--  EXISTS and INSERT IGNORE only. Nothing existing is altered.
--      php tests/apply-sql.php database/upgrade-2026-10-22-staff-applications.sql
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `staff_applications` (
  `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`               VARCHAR(120) NOT NULL,
  `phone`                   VARCHAR(15)  NOT NULL COMMENT '10-digit mobile, no country code',
  `phone_country`           ENUM('IN','NP') NOT NULL DEFAULT 'NP' COMMENT 'picks +91 or +977 for WhatsApp',
  `phone_verified`          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = WhatsApp code entered on the form',
  `whatsapp`                VARCHAR(15)  NULL COMMENT 'only when different from phone',
  `email`                   VARCHAR(160) NULL,
  `dob`                     DATE         NULL,
  `gender`                  ENUM('male','female','other') NULL,
  `address_perm`            VARCHAR(300) NULL,
  `address_current`         VARCHAR(300) NULL,
  `district`                VARCHAR(80)  NULL,
  `province`                VARCHAR(80)  NULL COMMENT 'province (Nepal) or state (India)',
  `country`                 ENUM('NP','IN') NOT NULL DEFAULT 'NP',
  `role_applied`            ENUM('driver','conductor','helper','office','accountant','mechanic','cleaner','security','other') NOT NULL,
  `photo_path`              VARCHAR(160) NULL COMMENT 'staff/<id>/<random>.jpg',
  `id_type`                 ENUM('citizenship','aadhaar','passport','driving_license','pan') NOT NULL,
  `id_number`               VARCHAR(40)  NOT NULL,
  `id_front_path`           VARCHAR(160) NULL,
  `id_back_path`            VARCHAR(160) NULL,
  `qualification`           ENUM('below_see','see','plus2','bachelor','master','other') NULL,
  `qual_cert_path`          VARCHAR(160) NULL,
  `marksheet_path`          VARCHAR(160) NULL,
  `experience_years`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `prev_employer`           VARCHAR(120) NULL,
  `prev_role`               VARCHAR(80)  NULL,
  `experience_cert_path`    VARCHAR(160) NULL,
  `license_number`          VARCHAR(40)  NULL COMMENT 'drivers',
  `license_category`        VARCHAR(40)  NULL,
  `license_expiry`          DATE         NULL,
  `license_photo_path`      VARCHAR(160) NULL,
  `references_json`         TEXT         NULL COMMENT '[{name,phone,relation}] x2',
  `bank_name`               VARCHAR(80)  NULL,
  `bank_account`            VARCHAR(40)  NULL,
  `bank_holder`             VARCHAR(80)  NULL,
  `bank_branch`             VARCHAR(80)  NULL,
  `bank_ifsc_or_swift`      VARCHAR(20)  NULL,
  `emergency_contact_name`  VARCHAR(80)  NULL,
  `emergency_contact_phone` VARCHAR(15)  NULL,
  `emergency_relation`      VARCHAR(40)  NULL,
  `signature_path`          VARCHAR(160) NULL COMMENT 'finger signature PNG',
  `agreement_version`       VARCHAR(10)  NULL COMMENT 'staff agreement accepted on the form',
  `biometric_face_hash`        CHAR(64)  NULL COMMENT 'SHA-256 of a 256x256 greyscale JPEG - phase 2',
  `biometric_fingerprint_hash` CHAR(64)  NULL COMMENT 'reserved - phones never give a fingerprint template',
  `biometric_webauthn_cred`    TEXT      NULL COMMENT 'WebAuthn public credential JSON - phase 2',
  `doc_verify_json`         TEXT         NULL COMMENT 'DocVerify results for the ID and the licence',
  `doc_verify_status`       ENUM('pass','partial','fail','unchecked') NOT NULL DEFAULT 'unchecked',
  `status`                  ENUM('pending','under_review','approved','rejected','suspended','exited') NOT NULL DEFAULT 'pending',
  `rejected_reason`         VARCHAR(300) NULL,
  `office_note`             VARCHAR(500) NULL,
  `approved_by`             BIGINT UNSIGNED NULL COMMENT 'admins.id',
  `approved_at`             DATETIME     NULL,
  `status_changed_by`       BIGINT UNSIGNED NULL COMMENT 'admins.id',
  `status_changed_at`       DATETIME     NULL,
  `info_requested_at`       DATETIME     NULL,
  `reminded_at`             DATETIME     NULL COMMENT 'cron/staff-reminders.php nudged the applicant',
  `show_on_website`         TINYINT(1)   NOT NULL DEFAULT 1,
  `display_order`           INT          NOT NULL DEFAULT 0,
  `web_key`                 CHAR(20)     NULL COMMENT 'random key of the public photo URL',
  `ip_address`              VARCHAR(45)  NULL,
  `user_agent`              VARCHAR(255) NULL,
  `created_at`              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_app_phone` (`phone`),
  UNIQUE KEY `uq_staff_app_web_key` (`web_key`),
  KEY `ix_staff_app_status_role` (`status`, `role_applied`),
  KEY `ix_staff_app_district` (`district`),
  KEY `ix_staff_app_web` (`status`, `show_on_website`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Columns added after the first version go here, each guarded, so a server
-- that ran an earlier copy of this file catches up on the next run.
ALTER TABLE `staff_applications` ADD COLUMN IF NOT EXISTS `doc_verify_status` ENUM('pass','partial','fail','unchecked') NOT NULL DEFAULT 'unchecked' AFTER `doc_verify_json`;
ALTER TABLE `staff_applications` ADD COLUMN IF NOT EXISTS `reminded_at` DATETIME NULL AFTER `info_requested_at`;

CREATE TABLE IF NOT EXISTS `agreement_signatures` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc`             VARCHAR(30)  NOT NULL COMMENT 'staff (agent signatures stay on agent_applications)',
  `version`         VARCHAR(10)  NOT NULL,
  `event`           VARCHAR(20)  NOT NULL DEFAULT 'apply' COMMENT 'apply = on the form, approval = after approval',
  `party_type`      VARCHAR(20)  NOT NULL DEFAULT 'staff',
  `party_id`        BIGINT UNSIGNED NULL COMMENT 'staff_applications.id',
  `full_name`       VARCHAR(120) NOT NULL,
  `phone`           VARCHAR(15)  NOT NULL,
  `phone_verified`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = WhatsApp code entered',
  `signature_path`  VARCHAR(160) NULL,
  `ip_address`      VARCHAR(45)  NULL,
  `user_agent`      VARCHAR(255) NULL,
  `signed_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_agr_sig_party` (`doc`, `party_type`, `party_id`),
  KEY `ix_agr_sig_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('staff_apply_on',      '1', 'bool', 'staff', 'Staff applications: the online form /agreements/staff.html takes new applications (0 = the form says call the office)', 0),
 ('staff_doc_verify',    '1', 'bool', 'staff', 'Staff applications: read the ID and licence photos with the document checker (Gemini + Tesseract) and show the match score to the office (0 = off)', 0),
 ('staff_biometric_on',  '0', 'bool', 'staff', 'Staff applications: phase-2 face hash + phone passkey (WebAuthn) on the form (0 = off, the form works without it)', 0),
 ('staff_public_fields', '["full_name","photo_path","phone","district","role_applied"]', 'json', 'staff', 'Staff applications: which fields of an approved person the website shows (full_name, photo_path, phone, district, role_applied)', 0);
