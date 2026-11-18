-- =====================================================================
--  19 Nov 2026 (u5: AG-1 / AG-2 / AG-3 / NP-4 member) — agents & staff.
--
--  AG-2 withdraw for every agent:
--    ap_members.payout_qr_path      the member's own bank / UPI QR image
--                                   (private, uploads/agents-kyc/, office only)
--    ap_members.payout_verified_at  the office's "payout details checked" tick
--    ap_members.payout_verified_by  (cleared again whenever UPI / bank / QR change)
--    agent_payout_requests.pay_method / pay_to / pay_qr_path
--                                   where the member asked to be paid — a
--                                   snapshot, so a later edit cannot redirect it
--  Settings (code defaults are the same; the rows make them visible / editable):
--    staff_commission_off           1  staff / office logins earn no NEW commission (AG-3)
--    staff_commission_counter_staff_off
--                                   1  a counter login holding a SHG-S staff number is
--                                      staff too (0 = counter desks always earn)
--    agent_payout_verify_required   0  a request needs no prior tick; the office's
--                                      approval (UTR / reference) is the check (AG-2)
--    ap_card_nepal_first            1  +977 members: Nepal company first (NP-4)
--    np_agent_desk_on               0  approve a +977 agent → attach a Nepal desk (NP-1)
--    np_agent_default_desk          NPJ
--
--  Idempotent: safe to run twice. Rollback: set staff_commission_off = 0
--  (commission as before); the columns can stay (nothing breaks without them).
-- =====================================================================
SET NAMES utf8mb4;

-- ---- ap_members ------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members' AND COLUMN_NAME = 'payout_qr_path');
SET @t := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members');
SET @s := IF(@t = 1 AND @c = 0,
  'ALTER TABLE `ap_members` ADD COLUMN `payout_qr_path` VARCHAR(255) NULL AFTER `khalti_id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members' AND COLUMN_NAME = 'payout_verified_at');
SET @s := IF(@t = 1 AND @c = 0,
  'ALTER TABLE `ap_members` ADD COLUMN `payout_verified_at` DATETIME NULL AFTER `payout_qr_path`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members' AND COLUMN_NAME = 'payout_verified_by');
SET @s := IF(@t = 1 AND @c = 0,
  'ALTER TABLE `ap_members` ADD COLUMN `payout_verified_by` BIGINT UNSIGNED NULL AFTER `payout_verified_at`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---- agent_payout_requests -------------------------------------------
SET @t := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_payout_requests');
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_payout_requests' AND COLUMN_NAME = 'pay_method');
SET @s := IF(@t = 1 AND @c = 0,
  'ALTER TABLE `agent_payout_requests` ADD COLUMN `pay_method` VARCHAR(12) NULL AFTER `note`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_payout_requests' AND COLUMN_NAME = 'pay_to');
SET @s := IF(@t = 1 AND @c = 0,
  'ALTER TABLE `agent_payout_requests` ADD COLUMN `pay_to` VARCHAR(160) NULL AFTER `pay_method`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_payout_requests' AND COLUMN_NAME = 'pay_qr_path');
SET @s := IF(@t = 1 AND @c = 0,
  'ALTER TABLE `agent_payout_requests` ADD COLUMN `pay_qr_path` VARCHAR(255) NULL AFTER `pay_to`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---- settings ----------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('staff_commission_off', '1', 'bool', 'agent',
  'Staff / office logins (not agents): count passengers only, no new commission', 0),
 ('staff_commission_counter_staff_off', '1', 'bool', 'agent',
  'Counter desk logins that hold a SHG-S staff number count as staff too (no commission); 0 = counter desks always earn', 0),
 ('agent_payout_verify_required', '0', 'bool', 'agent',
  'Withdraw request only after the office ticks "payout details checked" (0 = office approval is the check)', 0),
 ('ap_card_nepal_first', '1', 'bool', 'agentpro',
  'Nepal members (+977): cards and /me/ show the Nepal company, number and address first', 0),
 ('np_agent_desk_on', '0', 'bool', 'agent',
  'Approving a Nepal (+977) agent attaches them to the Nepal counter desk below', 0),
 ('np_agent_default_desk', 'NPJ', 'string', 'agent',
  'Nepal desk code a newly approved Nepal agent sells from (np_agent_desk_on)', 0);
