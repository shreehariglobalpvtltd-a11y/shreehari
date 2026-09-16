-- =====================================================================
--  Upgrade: ticketing-agent staff role (Part 1 net-new, 2026-08)
--  Run this ONCE on an existing live database (hPanel -> phpMyAdmin ->
--  select your database -> SQL tab -> paste -> Go).
--
--  Adds an 'agent' role to staff accounts — a ticketing agent who sells
--  seats and transfers passengers from the admin Seat Map / Trips board,
--  with NO access to payments, routes, settings or finances. Also seeds one
--  demo agent (username agent1 / password Agent@2026 — it must be changed on
--  first login). Safe to re-run (INSERT IGNORE; MODIFY is idempotent).
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `admins`
  MODIFY `role` ENUM('superadmin','manager','accountant','support','scanner','official','agent')
  NOT NULL DEFAULT 'support';

INSERT IGNORE INTO `admins`
  (`username`,`password_hash`,`full_name`,`role`,`is_active`,`must_change_pw`)
VALUES
  ('agent1','$2y$11$lqHSCLBPiGi3vq9yrADCtuWIDGjE90b4o5kLGC23.VI8gf/p.K75a','Agent One (demo)','agent',1,1);
