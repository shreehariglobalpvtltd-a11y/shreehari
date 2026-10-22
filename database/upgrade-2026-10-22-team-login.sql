-- =====================================================================
--  upgrade-2026-10-22-team-login.sql
--
--  Owner, 22 Oct 2026 (team portal): one login for Pvt. Ltd. partners,
--  agents and S Hari staff at /team/, tied to the mobile they registered
--  with - mobile + password, and "forgot password" by a WhatsApp code.
--
--  Agents, partners and counter staff sign in with their existing account
--  (admins). S Hari staff who do not sell tickets have no account, so their
--  approved application carries its own password:
--
--    staff_applications.password_hash   set by the person after a WhatsApp
--                                       code (never by the office)
--    staff_applications.failed_logins / locked_until / last_login_at
--                                       the same lockout as an account
--    staff_applications.admin_id        a ticket-selling account (agent /
--                                       counter) the office linked
--    staff_applications.cv_json         education + experience (team portal)
--    agent_applications.cv_json         the same for agents; a partner's
--                                       company profile
--
--  Additive and re-runnable: ADD COLUMN IF NOT EXISTS only.
--      mysql shari < database/upgrade-2026-10-22-team-login.sql
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `staff_applications` ADD COLUMN IF NOT EXISTS `password_hash` VARCHAR(255) NULL
  COMMENT 'team portal login, set by the person through a WhatsApp code' AFTER `web_key`;
ALTER TABLE `staff_applications` ADD COLUMN IF NOT EXISTS `failed_logins` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `password_hash`;
ALTER TABLE `staff_applications` ADD COLUMN IF NOT EXISTS `locked_until` DATETIME NULL AFTER `failed_logins`;
ALTER TABLE `staff_applications` ADD COLUMN IF NOT EXISTS `last_login_at` DATETIME NULL AFTER `locked_until`;
ALTER TABLE `staff_applications` ADD COLUMN IF NOT EXISTS `admin_id` BIGINT UNSIGNED NULL
  COMMENT 'admins.id of a ticket-selling account the office linked' AFTER `last_login_at`;
ALTER TABLE `staff_applications` ADD COLUMN IF NOT EXISTS `cv_json` TEXT NULL
  COMMENT 'team portal CV: education, experience, languages, skills' AFTER `admin_id`;

ALTER TABLE `agent_applications` ADD COLUMN IF NOT EXISTS `cv_json` TEXT NULL
  COMMENT 'team portal CV / a partner company profile';
