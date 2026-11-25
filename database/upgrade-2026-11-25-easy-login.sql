-- 25 Nov 2026 (easy login, owner: "default password = pahilo naam@2026; user le chahe badalna milne;
-- admin le reset garera pheri yei"). 1 = FirstName@2026 still opens the account (never for the
-- superadmin, see includes/easylogin.php); the person's own password change sets 0; the office
-- reset (admin/login-reset.php) sets 1 again. Idempotent - safe to apply twice.
ALTER TABLE admins  ADD COLUMN IF NOT EXISTS pw_is_default TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE drivers ADD COLUMN IF NOT EXISTS pw_is_default TINYINT(1) NOT NULL DEFAULT 1;
INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('login_easy_on', '1', 'bool', 'security', 'Easy login door: mode -> code or mobile -> password (admin/login.php)', 0),
  ('login_default_pw_on', '1', 'bool', 'security', 'FirstName@2026 opens an account until its owner changes the password (never superadmin)', 0);
