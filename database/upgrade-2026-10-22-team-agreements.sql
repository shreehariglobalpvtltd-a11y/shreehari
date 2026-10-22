-- =====================================================================
--  upgrade-2026-10-22-team-agreements.sql
--
--  Owner, 22 Oct 2026 (team portal): agreements fully digital. After the
--  office approves them, agents and Pvt. Ltd. partners sign the full
--  agreement in the team portal (/team/agreement.php), like staff already
--  do on the staff agreement page. One row per signature in
--  agreement_signatures (doc = staff | agent | partner, event = portal):
--
--    agreement_signatures.party_ref        STF-000005 / AGR-000035
--    agreement_signatures.signature_data   the agent / partner signature
--                                          (data: URI PNG; staff keep a
--                                          file under uploads/staff/)
--
--  Additive and re-runnable: ADD COLUMN IF NOT EXISTS only.
--      mysql shari < database/upgrade-2026-10-22-team-agreements.sql
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `agreement_signatures` ADD COLUMN IF NOT EXISTS `party_ref` VARCHAR(16) NULL
  COMMENT 'STF-000005 / AGR-000035' AFTER `party_id`;
ALTER TABLE `agreement_signatures` ADD COLUMN IF NOT EXISTS `signature_data` MEDIUMTEXT NULL
  COMMENT 'agent / partner signature, data: URI PNG (staff keep a file)' AFTER `signature_path`;
ALTER TABLE `agreement_signatures` ADD INDEX IF NOT EXISTS `ix_agr_sign_party` (`party_type`, `party_id`);
