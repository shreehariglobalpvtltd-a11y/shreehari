-- =====================================================================
--  upgrade-2026-11-16b-W1-devices.sql  (master v2.0, P1-5b + P1-5c, 16 Nov 2026)
--
--  Per-role device limit for staff sign-in. AdminSessions::deviceLimit()
--  reads device_limit_<role> (default 0 = no limit, exactly how sign-in
--  has always worked). Over the limit, a sign-in is held and the door asks
--  to sign one of the account's other devices out first (admin/login.php).
--  A superadmin is never limited, so there is no row for that role.
--
--  This file only adds the rows, all at 0, so they show in
--  Admin -> Settings -> Security. Nothing signs in differently after it
--  runs. Choosing a limit (e.g. agent 2, counter 1) is the owner's call.
--
--  Additive and safe to re-run: INSERT IGNORE keeps any value already
--  stored. Code that runs before this file keeps reading the default 0.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('device_limit_agent',      '0', 'int', 'security', 'Device limit - agent: devices one account may be signed in on at once (0 = no limit)', 0),
  ('device_limit_counter',    '0', 'int', 'security', 'Device limit - counter: devices one account may be signed in on at once (0 = no limit)', 0),
  ('device_limit_scanner',    '0', 'int', 'security', 'Device limit - scanner: devices one account may be signed in on at once (0 = no limit)', 0),
  ('device_limit_manager',    '0', 'int', 'security', 'Device limit - manager: devices one account may be signed in on at once (0 = no limit)', 0),
  ('device_limit_accountant', '0', 'int', 'security', 'Device limit - accountant: devices one account may be signed in on at once (0 = no limit)', 0),
  ('device_limit_operations', '0', 'int', 'security', 'Device limit - operations: devices one account may be signed in on at once (0 = no limit)', 0),
  ('device_limit_support',    '0', 'int', 'security', 'Device limit - support: devices one account may be signed in on at once (0 = no limit)', 0),
  ('device_limit_official',   '0', 'int', 'security', 'Device limit - official: devices one account may be signed in on at once (0 = no limit)', 0),
  ('device_limit_viewer',     '0', 'int', 'security', 'Device limit - viewer: devices one account may be signed in on at once (0 = no limit)', 0);
