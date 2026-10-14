-- =====================================================================
--  15 Oct 2026 - SmartBook v5, phase 2: ONE source of truth for the
--  cancellation policy (D8) and the corridor distance (D9).
--
--      php tests/apply-sql.php database/upgrade-2026-10-15-smartbook-facts.sql
--
--  D8 (owner, final): keep ONLY this policy everywhere -
--      >= 48 hrs before departure   75 % refund
--      24 - 48 hrs                  50 %
--      under 24 hrs                 no refund
--      refund reaches the account in 5-7 working days
--  The old 96 h / 90 % and 6 h / 25 % tiers are removed. refund_slabs is
--  what Fare::refundFor() prices a cancellation with AND what every page,
--  the ticket, the WhatsApp bot and the AI print (SiteFacts policy.*).
--  Refunds themselves stay manual (the office settles them by hand).
--
--  D9 (owner, final): the site states 1,375 km everywhere. Measured stop km
--  (route_stops.km_from_origin) stays as it is - it only places markers and
--  measures live GPS progress; it is never printed as the route total.
--
--  Settings rows only. No table changes. Re-running is harmless.
-- =====================================================================

UPDATE `settings`
   SET `svalue` = '[{"minHrs":48,"pct":75},{"minHrs":24,"pct":50},{"minHrs":0,"pct":0}]',
       `label`  = 'Refund slabs (D8: the one cancellation policy - every page, ticket, bot and AI prints this)'
 WHERE `skey` = 'refund_slabs';

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('refund_slabs',      '[{"minHrs":48,"pct":75},{"minHrs":24,"pct":50},{"minHrs":0,"pct":0}]', 'json', 'booking', 'Refund slabs (D8: the one cancellation policy - every page, ticket, bot and AI prints this)', 1),
 ('refund_days',       '5-7',  'string', 'booking', 'Working days for a refund to reach the customer (printed in the policy everywhere)', 1),
 ('route_distance_km', '1375', 'int',    'booking', 'Gujarat <-> Rupaidiha distance the website states (km) - one figure everywhere', 1);
