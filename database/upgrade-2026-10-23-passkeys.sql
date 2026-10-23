-- =====================================================================
--  upgrade-2026-10-23-passkeys.sql
--
--  Owner, 23 Oct 2026 (digital identity): "optional device-based Face ID /
--  fingerprint login via WebAuthn/passkeys. Never collect raw fingerprint or
--  face biometric templates."
--
--  A passkey is the phone's own lock (fingerprint / Face ID / PIN) used to
--  sign a challenge. Only the PUBLIC key reaches us; the biometric never
--  leaves the device. One row per device a team member added at /team/.
--
--    owner_kind  'admin'  = a selling account (agents, partners, counter)
--                'staff'  = a staff_applications row with a portal password
--    credential_id  the authenticator's id (base64url), unique
--    public_key     PEM (EC P-256 or RSA), from the COSE key at registration
--    sign_count     the authenticator's counter; a lower one is a clone
--
--  Additive and re-runnable: CREATE TABLE IF NOT EXISTS only.
--      mysql shari < database/upgrade-2026-10-23-passkeys.sql
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `team_passkeys` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_kind`    ENUM('admin','staff') NOT NULL,
  `owner_id`      INT UNSIGNED NOT NULL,
  `phone10`       CHAR(10) NOT NULL COMMENT 'the registered mobile, for the office list',
  `credential_id` VARCHAR(700) NOT NULL COMMENT 'base64url',
  `public_key`    TEXT NOT NULL COMMENT 'PEM',
  `alg`           SMALLINT NOT NULL DEFAULT -7 COMMENT 'COSE: -7 ES256, -257 RS256',
  `sign_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `transports`    VARCHAR(120) NULL,
  `label`         VARCHAR(60) NOT NULL DEFAULT '' COMMENT 'phone / browser name the person typed',
  `created_ip`    VARCHAR(45) NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cred` (`credential_id`(191)),
  KEY `ix_owner` (`owner_kind`, `owner_id`),
  KEY `ix_phone` (`phone10`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='WebAuthn passkeys for the team portal (public keys only)';
