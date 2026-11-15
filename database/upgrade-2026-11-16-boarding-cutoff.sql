-- =====================================================================
--  upgrade-2026-11-16-boarding-cutoff.sql  (P4-2g, 16 Nov 2026)
--
--  Boarding::cutoffMinutes() has always read the setting
--  boarding_cutoff_minutes (default 30): each pickup stops selling that
--  many minutes before its OWN pickup time. Live never had the row, and
--  Settings::setMany() ignores unknown keys, so no admin screen could
--  change it. This file only adds the row, at the value the code already
--  uses (30), so it shows up in Admin -> Settings -> Booking & seats.
--  Nothing sells differently after it runs. Raising it (e.g. 120 = two
--  hours) is the owner's call, made in Settings.
--
--  Additive and safe to re-run: INSERT IGNORE keeps any value already
--  stored. Code that runs before this file keeps reading the default 30.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('boarding_cutoff_minutes', '30', 'int', 'booking', 'Booking closes this many minutes before each pickup''s own time (30 = half an hour, 120 = 2 hours)', 0);
