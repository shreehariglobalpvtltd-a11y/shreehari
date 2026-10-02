-- =====================================================================
--  Upgrade 2026-10-02 - one index for the travel-date lookups (PERF-10)
--
--  Run ONCE on the live database (safe to re-run - IF NOT EXISTS):
--      deploy.sh --migrate database/upgrade-2026-10-02-perf-indexes.sql
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-10-02-perf-indexes.sql
--
--  WHY
--    booking_legs had no index that STARTS with travel_date (only
--    booking_id-led and schedule_id-led ones), so every "who travels on
--    date X" question - the Tickets register's travel-date filter, the
--    hand-over list, tomorrow's passengers, the AI Watch panel, the
--    manifest by date - scanned the whole table. Adding a column-led index
--    is metadata + one pass over the table; InnoDB builds it online
--    (no table lock for reads or writes on MariaDB 10.x / 11.x).
--
--  ROLLBACK
--    ALTER TABLE booking_legs DROP INDEX ix_legs_travel
-- =====================================================================

ALTER TABLE `booking_legs`
  ADD INDEX IF NOT EXISTS `ix_legs_travel` (`travel_date`, `leg_type`, `booking_id`);
