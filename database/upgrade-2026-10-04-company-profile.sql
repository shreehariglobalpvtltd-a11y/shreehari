-- =====================================================================
--  Upgrade 2026-10-04 — the Nepal company's own payment account.
--
--      mysql shari < database/upgrade-2026-10-04-company-profile.sql
--
--  Idempotent (INSERT IGNORE). Every value ships EMPTY: until the office
--  fills them in on Admin -> Company details, a Nepal desk uses the India
--  company's account exactly as today.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
 ('nepal_esewa_id','','string','company','Nepal company eSewa ID (blank = the India account esewa_id)',0),
 ('nepal_esewa_name','','string','company','Name on the Nepal eSewa account',0),
 ('nepal_bank','','string','company','Nepal company bank account (bank, branch, account name, number)',0);
