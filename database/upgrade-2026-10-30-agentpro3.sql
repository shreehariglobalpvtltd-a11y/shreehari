-- =====================================================================
--  upgrade-2026-10-30-agentpro3.sql
--
--  Owner, 30 Oct 2026: one WhatsApp number may carry up to 3 IDs
--  (setting ap_phone_max_accounts, default 3; the office form adds the
--  2nd / 3rd ID after "add another ID on the same number?").
--
--    ap_wa_state.admin_id   the ID this WhatsApp number picked on the
--                           desk ("which ID?" - asked once, remembered;
--                           "switch" asks again). NULL = not picked.
--                           Only used when the number has no OTP link
--                           (ap_wa_links) and several accounts carry it.
--
--  Additive and re-runnable: the column is added only when missing, and
--  nothing runs when ap_wa_state is not installed yet (DO 0). The code
--  works without this file too (ApWa then keeps a shared number on the
--  passenger bot, exactly as before).
--  (apply-sql.php splits on semicolons - none inside the strings.)
-- =====================================================================

SET NAMES utf8mb4;

SET @t := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_wa_state');

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_wa_state' AND COLUMN_NAME = 'admin_id');
SET @sql := IF(@t = 1 AND @col = 0,
  'ALTER TABLE `ap_wa_state` ADD COLUMN `admin_id` BIGINT UNSIGNED NULL COMMENT ''the ID picked on a WhatsApp number shared by several accounts''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ap_phone_max_accounts needs no row: AgentPro::phoneMax() reads 3 when it is not set.
