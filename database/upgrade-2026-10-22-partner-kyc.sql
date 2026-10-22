-- =====================================================================
--  upgrade-2026-10-22-partner-kyc.sql
--
--  Owner, 22 Oct 2026: "proper KYC verification, company ko lagi alag
--  requirement". A Pvt. Ltd. partner sends the company's own papers with
--  the quick form, not only the representative's ID:
--
--    agent_applications.reg_cert   company / firm registration certificate
--    agent_applications.tax_cert   PAN / VAT / GST certificate
--
--  Both are photos as data: URIs (like `doc_photo`), checked by their bytes
--  and size on the server, read only by a super-admin / manager on the
--  agreements dashboard and the A4 print. Never served to the website.
--
--  Additive and re-runnable: ADD COLUMN IF NOT EXISTS only.
--      mysql shari < database/upgrade-2026-10-22-partner-kyc.sql
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `agent_applications` ADD COLUMN IF NOT EXISTS `reg_cert` MEDIUMTEXT NULL
  COMMENT 'partner company registration certificate photo, data: URI (type org), office only' AFTER `logo`;
ALTER TABLE `agent_applications` ADD COLUMN IF NOT EXISTS `tax_cert` MEDIUMTEXT NULL
  COMMENT 'partner PAN / VAT / GST certificate photo, data: URI (type org), office only' AFTER `reg_cert`;
