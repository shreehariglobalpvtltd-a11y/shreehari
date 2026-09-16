-- =====================================================================
--  Sharing-service route stops — Sep-2 launch model
--
--  Business change (Aug 22 2026):
--    The sharing bus now runs a straight leg between Gujarat and the
--    India–Nepal border at Rupaidiha. Passengers travelling deeper into
--    Nepal cross the border on their own (or take our onward taxi).
--
--    Outbound  (Gujarat → Nepal):    4 boarding + 1 drop (Rupaidiha)
--    Return    (Nepal → Gujarat):    1 boarding (Rupaidiha) + 4 drops
--
--    The 4 stops on the Gujarat side, north-to-south along the highway:
--      Mehsana (SHG head office) → Ahmedabad → Vadodara → Surat
--
--  This migration is idempotent — it deletes and re-seeds the stops for
--  every route that starts or ends in Gujarat, so re-running it is
--  harmless. Routes we don't touch (any future non-Gujarat services)
--  are left alone.
-- =====================================================================

SET NAMES utf8mb4;

-- Wipe existing stops for the affected routes (idempotent — the
-- re-INSERT below rebuilds them from scratch).
DELETE FROM `route_stops`
 WHERE `route_id` IN (
   SELECT `id` FROM `routes`
    WHERE (`from_city` IN ('Ahmedabad','Nepalgunj','Rupaidiha') AND
           `to_city`   IN ('Ahmedabad','Nepalgunj','Rupaidiha'))
 );

-- Outbound routes: Gujarat → Nepal (Ahmedabad → Nepalgunj / Rupaidiha).
-- 4 boarding stops N→S along NH-48, single drop at the border.
INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`, 'boarding', 'Mehsana — SHG Head Office',   'Sliver Complex, Near Shilpa Garage',
       ADDTIME(r.`dep_time`, '00:00:00'), 23.5880000, 72.3690000, 0, 0, 1
  FROM `routes` r
 WHERE r.`from_city` = 'Ahmedabad' OR (r.`from_city` = 'Mehsana' AND r.`to_city` IN ('Nepalgunj','Rupaidiha'));

INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`, 'boarding', 'Ahmedabad — Paldi Bus Stand', 'SHG Counter',
       ADDTIME(r.`dep_time`, '01:00:00'), 23.0230000, 72.5710000, 0, 0, 2
  FROM `routes` r
 WHERE r.`from_city` IN ('Ahmedabad','Mehsana') AND r.`to_city` IN ('Nepalgunj','Rupaidiha');

INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`, 'boarding', 'Vadodara — Ajwa Cross', 'NH-48 Junction',
       ADDTIME(r.`dep_time`, '03:00:00'), 22.3072000, 73.1812000, 0, 0, 3
  FROM `routes` r
 WHERE r.`from_city` IN ('Ahmedabad','Mehsana') AND r.`to_city` IN ('Nepalgunj','Rupaidiha');

INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`, 'boarding', 'Surat — Kamrej Circle', 'NH-48 Kamrej Junction',
       ADDTIME(r.`dep_time`, '05:00:00'), 21.2711000, 72.9575000, 0, 0, 4
  FROM `routes` r
 WHERE r.`from_city` IN ('Ahmedabad','Mehsana') AND r.`to_city` IN ('Nepalgunj','Rupaidiha');

-- Outbound drop: the border (final)
INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`, 'drop', 'Rupaidiha — India / Nepal Border', 'SSB Checkpoint',
       r.`arr_time`, 28.0600000, 81.6170000, 1, 0, 1
  FROM `routes` r
 WHERE r.`from_city` IN ('Ahmedabad','Mehsana') AND r.`to_city` IN ('Nepalgunj','Rupaidiha');


-- Return routes: Nepal → Gujarat (Nepalgunj / Rupaidiha → Ahmedabad).
-- Single boarding at the border, 4 drops S→N along NH-48.
INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`, 'boarding', 'Rupaidiha — India / Nepal Border', 'SSB Checkpoint',
       r.`dep_time`, 28.0600000, 81.6170000, 1, 0, 1
  FROM `routes` r
 WHERE r.`from_city` IN ('Nepalgunj','Rupaidiha') AND r.`to_city` IN ('Ahmedabad','Mehsana');

INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`, 'drop', 'Surat — Kamrej Circle', 'NH-48 Kamrej Junction',
       SUBTIME(r.`arr_time`, '05:00:00'), 21.2711000, 72.9575000, 0, 0, 1
  FROM `routes` r
 WHERE r.`from_city` IN ('Nepalgunj','Rupaidiha') AND r.`to_city` IN ('Ahmedabad','Mehsana');

INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`, 'drop', 'Vadodara — Ajwa Cross', 'NH-48 Junction',
       SUBTIME(r.`arr_time`, '03:00:00'), 22.3072000, 73.1812000, 0, 0, 2
  FROM `routes` r
 WHERE r.`from_city` IN ('Nepalgunj','Rupaidiha') AND r.`to_city` IN ('Ahmedabad','Mehsana');

INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`, 'drop', 'Ahmedabad — Paldi Bus Stand', 'SHG Counter',
       SUBTIME(r.`arr_time`, '01:00:00'), 23.0230000, 72.5710000, 0, 0, 3
  FROM `routes` r
 WHERE r.`from_city` IN ('Nepalgunj','Rupaidiha') AND r.`to_city` IN ('Ahmedabad','Mehsana');

INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`, 'drop', 'Mehsana — SHG Head Office', 'Sliver Complex, Near Shilpa Garage',
       r.`arr_time`, 23.5880000, 72.3690000, 0, 0, 4
  FROM `routes` r
 WHERE r.`from_city` IN ('Nepalgunj','Rupaidiha') AND r.`to_city` IN ('Ahmedabad','Mehsana');


-- =====================================================================
--  Settings switch — the fare quote engine reads this to decide when a
--  passenger who elects a *short* leg (e.g. drop only at Surat) qualifies
--  for the 1800 rate instead of the 2000 full-run rate.
--
--  Business rule from the owner:
--    ≥ 3 Gujarat stops used ("full run")  → base fare
--    ≤ 2 Gujarat stops used ("short run") → base fare − ₹200 discount
--    Online booking gets a further 5% off (existing behaviour).
-- =====================================================================
INSERT INTO `settings`
  (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`)
VALUES
  ('sharing_short_run_min_stops',   '3',   'int',   'pricing', 'Min Gujarat stops considered a full run', 1),
  ('sharing_short_run_discount_inr','200', 'int',   'pricing', 'Discount off base fare when only 1–2 stops used', 1)
ON DUPLICATE KEY UPDATE
  `svalue` = VALUES(`svalue`),
  `stype`  = VALUES(`stype`),
  `sgroup` = VALUES(`sgroup`),
  `label`  = VALUES(`label`),
  `is_public` = VALUES(`is_public`);
