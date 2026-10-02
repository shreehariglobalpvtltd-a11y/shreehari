-- upgrade-2026-10-02-discount-switches.sql
-- Three ON/OFF switches for automatic discounts (group, loyalty tier, short-run).
-- All default to OFF so nothing fires until the owner turns them on.
-- Idempotent: INSERT IGNORE skips rows that already exist.

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public)
VALUES
  ('group_discount_on',         '0', 'bool', 'booking', 'Group discount ON/OFF',         1),
  ('loyalty_tier_discount_on',  '0', 'bool', 'booking', 'Loyalty tier discount ON/OFF',  1),
  ('sharing_short_run_on',      '0', 'bool', 'booking', 'Short-run sharing cut ON/OFF',  1);
