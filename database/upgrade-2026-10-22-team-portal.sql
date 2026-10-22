-- =====================================================================
--  upgrade-2026-10-22-team-portal.sql
--
--  Owner, 22 Oct 2026: one "team portal" for the three kinds of people
--  who work with S Hari Global - Pvt. Ltd. partner companies, agents and
--  S Hari staff.
--
--    staff_applications.base_location   the office / branch a person
--                                       works from (Nepalgunj, Surat ...),
--                                       set by the office; "Our team" on
--                                       the home page is grouped by it.
--                                       A company branch, never the home
--                                       address, so it is always public.
--    agent_applications.logo            a partner company's logo (data:
--                                       URI like `photo`), shown on the
--                                       home page's partner cards.
--
--  Additive and re-runnable: ADD COLUMN / INDEX IF NOT EXISTS only.
--      mysql shari < database/upgrade-2026-10-22-team-portal.sql
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `staff_applications` ADD COLUMN IF NOT EXISTS `base_location` VARCHAR(60) NULL
  COMMENT 'office / branch the person works from, set by the office' AFTER `district`;
ALTER TABLE `staff_applications` ADD INDEX IF NOT EXISTS `ix_staff_app_base` (`base_location`);

ALTER TABLE `agent_applications` ADD COLUMN IF NOT EXISTS `logo` MEDIUMTEXT NULL
  COMMENT 'partner company logo, data: URI (type org)' AFTER `photo`;
