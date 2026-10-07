-- =====================================================================
--  7 Oct 2026 - Customer login with WhatsApp OTP + profile, and named
--  drop points (with their own fare) on the way back to Gujarat.
--
--  Owner ask: "customer ko lagi login - name, address, number liyera OTP
--  WhatsApp (AiSensy) bata. Photo bhaye jhan ramro. Tyo data ticket ma
--  use hune, arko ko lagi ticket katna milne. Return ma pani thau ko naam
--  rakhdeu, price editable."
--
--  1. users.address / gender / location / photo_path / profile_verified_at
--     (email, id_type, id_number already exist on users)
--     (photo files live in tickets/profiles/, which nginx never serves,
--     and are handed only to their owner by api/profile.php).
--  2. Settings: customer OTP on/off, "continue without code" when WhatsApp
--     could not deliver, and an optional dedicated OTP template name.
--  3. Return routes (Rupaidiha -> Gujarat, active) get one DROP stop per
--     Gujarat town, priced at the direction default 1800 so every fare is
--     visible and editable in Admin -> Routes. The old catch-all "Gujarat"
--     drop is switched OFF (never deleted - old tickets carry its name).
--     A stop that already exists by name is left exactly as it is.
--
--  Additive. Re-running this file is harmless.
-- =====================================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `address` VARCHAR(255) NULL DEFAULT NULL AFTER `full_name`,
  ADD COLUMN IF NOT EXISTS `photo_path` VARCHAR(191) NULL DEFAULT NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `profile_verified_at` DATETIME NULL DEFAULT NULL AFTER `photo_path`,
  ADD COLUMN IF NOT EXISTS `gender` ENUM('Male','Female','Other') NULL DEFAULT NULL AFTER `full_name`,
  ADD COLUMN IF NOT EXISTS `location` VARCHAR(255) NULL DEFAULT NULL AFTER `address`;

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('customer_otp_on',        '1', 'bool',   'customers', 'Customer login: send a one-time code on WhatsApp', 0),
 ('customer_otp_skip',      '1', 'bool',   'customers', 'Customer login: allow "continue without code" when WhatsApp could not deliver it', 0),
 ('aisensy_tpl_login_otp',  '',  'string', 'aisensy',   'AiSensy AUTHENTICATION template for the login code (blank = shg_message)', 0);

-- ---------------------------------------------------------------------
--  Return drop points with their own fare
-- ---------------------------------------------------------------------
INSERT INTO `route_stops`
  (`route_id`, `stop_type`, `stop_name`, `landmark`, `stop_time`, `fare`, `latitude`, `longitude`, `is_border`, `is_meal_halt`, `sort_order`, `is_active`)
SELECT r.id, 'drop', s.name, s.landmark, NULL, 1800.00, s.lat, s.lng, 0, 0, s.ord, 1
  FROM `routes` r
  JOIN (
        SELECT 1 AS ord, 'S Hari Parking, Nana Chiloda (Amd)' AS name, NULL AS landmark, 23.1710000 AS lat, 72.6230000 AS lng
  UNION ALL SELECT 2, 'Emli Bhupal', 'Taj Hotel', NULL, NULL
  UNION ALL SELECT 3, 'Nadiad', 'Nadiad Bridge - under bridge', NULL, NULL
  UNION ALL SELECT 4, 'Anand', 'Pipal Chautra', NULL, NULL
  UNION ALL SELECT 5, 'Vadodara', 'Golden Chokdi', 22.3072000, 73.1812000
  UNION ALL SELECT 6, 'Bharuch', 'Somnath Mahadev Mandir', NULL, NULL
  UNION ALL SELECT 7, 'Ankleshwar', 'Ada Bridge', NULL, NULL
  UNION ALL SELECT 8, 'Kamrej', 'Shiv Shakti Hotel', 21.2729662, 72.9555969
  UNION ALL SELECT 9, 'Surat', 'Bus stand / designated point', 21.1702000, 72.8311000
  UNION ALL SELECT 10, 'Rajkot', 'Rajkot office (+91 94268 27507)', NULL, NULL
  ) s
 WHERE r.is_active = 1
   AND r.from_city = 'Rupaidiha'
   AND NOT EXISTS (SELECT 1 FROM `route_stops` x
                    WHERE x.route_id = r.id AND x.stop_type = 'drop' AND x.stop_name = s.name);

UPDATE `route_stops` rs
  JOIN `routes` r ON r.id = rs.route_id
   SET rs.is_active = 0, rs.sort_order = 99
 WHERE r.is_active = 1 AND r.from_city = 'Rupaidiha'
   AND rs.stop_type = 'drop' AND rs.stop_name = 'Gujarat'
   AND (SELECT COUNT(*) FROM (SELECT route_id FROM `route_stops`
                               WHERE stop_type = 'drop' AND is_active = 1 AND stop_name <> 'Gujarat') t
         WHERE t.route_id = r.id) > 0;
