-- =====================================================================
--  Upgrade 2026-09 — Counter locations (NPJ, MSA, RPD …)
--  24 Sep 2026
--
--  Run ONCE on the live database (phpMyAdmin -> SQL tab -> paste -> Go),
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-09-counter-locations.sql
--
--  Fully idempotent: the ALTER is guarded against information_schema and
--  skipped when the column is already there, and every INSERT is an
--  INSERT IGNORE — so re-running is a no-op and it is safe while live.
--
--  OWNER ASK (24 Sep 2026): "counter mode lai location haru ni add garna
--  milos, like NPJ … company ko name ko tala location lekhne thau … ticket
--  [ma] by name ra counter ko location hos, dekhine gari."
--
--  WHAT THIS ADDS, and why it is shaped this way
--  ---------------------------------------------
--  admin_profiles.counter_name already existed and already held the town
--  a desk sits in ("Mehsana", "Nepalgunj") — but only agents could edit
--  it (admin/agents.php), staff.php showed it read-only, and it never
--  reached a ticket. Two things were missing:
--
--    counter_code      the SHORT code the owner actually says out loud —
--                      NPJ, MSA, RPD. A ticket header has room for three
--                      letters where it has none for "Nepalgunj — Bus
--                      Park", and a code is what a clerk reads back over
--                      the phone. NULL for every existing row, so nothing
--                      changes anywhere until a desk is given one.
--
--    counter_locations a settings row, not a table. The owner wants to ADD
--                      locations, and the whole list is a dozen short
--                      lines that only a superadmin ever edits — a table
--                      would buy referential integrity nobody needs and
--                      cost a CRUD screen. One line per desk:
--
--                          CODE|Name
--
--                      Parsed by Settings::counterLocations(). A desk may
--                      still be given a location that is not on the list
--                      (the field is free text with the list as
--                      suggestions), so adding a counter never waits on
--                      editing this.
--
--  The seed below is the real network: the Gujarat desks the company
--  already prints on its tickets (company_counters), plus the border and
--  the Nepal side, whose stops route_stops has carried since day one —
--  Rupaidiha Border, Nepalgunj Bus Park, Nepalgunj Dhamboji Chowk,
--  Kohalpur Chowk.
--
--  NB: no semicolon inside any quoted ALTER string — tests/apply-sql.php
--  splits this file on ";" and would cut the statement in half.
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'counter_code');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `counter_code` VARCHAR(16) NULL COMMENT ''short desk code printed on the ticket — NPJ, MSA, RPD'' AFTER `counter_name`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT IGNORE INTO settings (skey,svalue,stype,sgroup,label,is_public) VALUES
 ('counter_locations',
  'MSA|Mehsana — Head Office\nAMD|Ahmedabad — Paldi\nBRD|Baroda\nSRT|Surat\nGDH|Godhra\nHMT|Himatnagar\nRPD|Rupaidiha — India/Nepal Border\nNPJ|Nepalgunj — Bus Park\nNPJD|Nepalgunj — Dhamboji Chowk\nKHL|Kohalpur Chowk',
  'text','company',
  'Counter locations — one per line as CODE|Name (e.g. NPJ|Nepalgunj — Bus Park). Offered when a counter or agent is given a location, and printed on their tickets.',
  0);
