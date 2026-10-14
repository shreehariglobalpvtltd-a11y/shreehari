-- =====================================================================
--  15 Oct 2026 - owner: "Rajkot office ho, aafai bichma milayera lekha,
--  jati ho teti lekha" + "reschedule approx 96 hours lekha".
--
--      php tests/apply-sql.php database/upgrade-2026-10-15-rajkot-reschedule.sql
--
--  1. Rajkot is an OFFICE pickup (no timetable clock, no map pin yet - none
--     is invented). In every list it now sits next to Ahmedabad, where its
--     passengers join the coach, instead of between Anand and Nadiad:
--        r2 (to Nepal)   ... Nadiad 7 · Emli Bhupal 8 · Rajkot 9 · Ahmedabad 10
--        r7 (to Gujarat) Ahmedabad 1 · Rajkot 2 · Emli Bhupal 3 · Nadiad 4 ...
--     Matched by stop NAME (ids differ between installs). Fares untouched.
--  2. The Rajkot office row gets the phone the pickup already publishes,
--     only while its phone is empty.
--  3. Rescheduling wording = the Terms' clause: once, free > 96 h before
--     departure, 10 % fee 24-96 h, not allowed under 24 h (Fare::rescheduleLine).
--
--  Data only, no table changes. Re-running is harmless.
-- =====================================================================

UPDATE `route_stops` rs JOIN `routes` r ON r.id = rs.route_id
   SET rs.sort_order = CASE rs.stop_name
         WHEN 'Nadiad'      THEN 7
         WHEN 'Emli Bhupal' THEN 8
         WHEN 'Rajkot'      THEN 9
         ELSE rs.sort_order END
 WHERE r.route_code = 'r2' AND rs.stop_type = 'boarding'
   AND rs.stop_name IN ('Nadiad', 'Emli Bhupal', 'Rajkot');

UPDATE `route_stops` rs JOIN `routes` r ON r.id = rs.route_id
   SET rs.sort_order = CASE rs.stop_name
         WHEN 'Rajkot'      THEN 2
         WHEN 'Emli Bhupal' THEN 3
         WHEN 'Nadiad'      THEN 4
         WHEN 'Anand'       THEN 5
         WHEN 'Vadodara'    THEN 6
         WHEN 'Bharuch'     THEN 7
         WHEN 'Ankleshwar'  THEN 8
         WHEN 'Kamrej'      THEN 9
         WHEN 'Surat'       THEN 10
         ELSE rs.sort_order END
 WHERE r.route_code = 'r7' AND rs.stop_type = 'drop'
   AND rs.stop_name IN ('Rajkot', 'Emli Bhupal', 'Nadiad', 'Anand', 'Vadodara', 'Bharuch', 'Ankleshwar', 'Kamrej', 'Surat');

UPDATE `route_stops` rs JOIN `routes` r ON r.id = rs.route_id
   SET rs.landmark = 'Rajkot office pickup (+91 94268 27507)'
 WHERE r.route_code IN ('r2', 'r7') AND rs.stop_name = 'Rajkot' AND (rs.landmark IS NULL OR rs.landmark = '');

UPDATE `counter_locations`
   SET `phone` = '+91 94268 27507', `whatsapp` = '+91 94268 27507'
 WHERE `code` = 'RJT' AND (`phone` IS NULL OR `phone` = '');

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('reschedule_free_hrs', '96', 'int', 'booking', 'Reschedule is free when asked this many hours (or more) before departure', 1),
 ('reschedule_min_hrs',  '24', 'int', 'booking', 'No reschedule closer than this many hours to departure', 1),
 ('reschedule_fee_pct',  '10', 'int', 'booking', 'Reschedule fee (% of base fare) between the two limits', 1);
