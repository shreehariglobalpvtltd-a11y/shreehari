-- =====================================================================
--  S HARI GLOBAL PVT LTD — Seed Data
--  Import AFTER schema.sql
--
--  Every value here is carried over from the original CONFIG object in
--  the single-file build, so pricing and routes behave identically.
--
--  NOTE ON THE ADMIN ACCOUNT
--  There is deliberately no admin row in this file. Passwords must be
--  hashed with PHP's password_hash(), which cannot be done from SQL.
--  Run /install.php once after import to create the first superadmin
--  with a password you choose. install.php then locks itself.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- ---------------------------------------------------------------------
--  SETTINGS
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
-- Company identity
('company_name','S Hari Global Pvt Ltd','string','company','Company name',1),
('company_tagline','India to Nepal Bus Transport | International Import & Export','string','company','Tagline',1),
('company_address','Near Shilpa Garage, Sliver Complex, Mehsana – 384002, Gujarat, India','string','company','Registered address',1),
('company_cin','U52291GJ2026PTC174029','string','company','CIN',1),
('company_gstin','','string','company','GSTIN (prints on tickets when set)',1),
('company_ceo','Sher Bahadur Bishwokarma','string','company','CEO name',1),
('company_mantra','ॐ नमो नारायणाय','string','company','Mantra shown on ticket',1),
('company_phone','+91 91048 01507','string','company','Public phone',1),
('company_email','shreehariglobalpvtltd@gmail.com','string','company','Public email',1),
('nepal_company','','string','company','Nepal entity name',1),
('nepal_reg','','string','company','Nepal registration number',1),

-- Payment receiving accounts
('upi_id','9104801507.eazypay@icici','string','payment','UPI VPA',1),
('upi_name','S HARI GLOBAL PRIVATE LIMITED','string','payment','UPI account name',1),
('esewa_id','dileepjeth15@gmail.com','string','payment','eSewa ID',1),
('esewa_name','Dileep Sunar','string','payment','eSewa account name',1),
('custom_esewa_qr','esewa-qr.jpg','string','payment','Static eSewa QR image',1),
('npr_per_inr','1.6','float','payment','INR to NPR peg',1),
('verify_time_text','1–3 minutes','string','payment','Stated verification time',1),
('allow_screenshot_upload','1','bool','payment','Allow payment proof upload',1),
('max_upload_mb','10','int','payment','Max upload size (MB)',1),
('allow_cod','1','bool','payment','Allow pay-at-counter',1),

-- Booking rules
('seat_hold_minutes','30','int','booking','Seat lock duration',1),
('max_seats_per_booking','6','int','booking','Max seats per booking',1),
('group_discount_min_seats','5','int','booking','Group discount threshold',1),
('group_discount_percent','5','float','booking','Group discount %',1),
('booking_fee','0','float','booking','Per-booking fee',1),
('online_discount_pct','5','float','booking','Online prepaid discount %',1),
('loyalty_trips','3','int','booking','Trips before welcome-back',1),
('assumed_speed_kmh','40','int','booking','Speed used for distance ETA',1),
('session_timeout_min','45','int','booking','Idle auto sign-out',1),
('booking_expiry_minutes','120','int','booking','Unpaid booking auto-expiry',1),
('tax_percent','0','float','booking','GST/VAT percent applied',1),

-- Refund slabs (hours before departure -> percent refunded)
('refund_slabs','[{"minHrs":48,"pct":75},{"minHrs":24,"pct":50},{"minHrs":0,"pct":0}]','json','booking','Refund slabs',1),

-- Cabin pricing — mirrors CONFIG.cabinPricing exactly
('cabin_pricing','{"onlineDiscountPct":5,"sharing":{"single_2pax":{"capacity":2,"offline":4400,"online":4180,"perPerson":2090,"label":"Single Sleeper (Sharing)","cabinType":"single","emoji":"🛏️"},"double_3pax":{"capacity":3,"offline":7500,"online":7125,"perPerson":2375,"label":"Double Sleeper (Sharing) · 3P","cabinType":"double","emoji":"🛏️🛏️"},"double_4pax":{"capacity":4,"offline":8800,"online":8360,"perPerson":2090,"label":"Double Sleeper (Sharing) · 4P","cabinType":"double","emoji":"🛏️🛏️"}},"private":{"single_1pax":{"capacity":1,"offline":3600,"online":3420,"label":"Single Sleeper (Private)","cabinType":"single","emoji":"🔒🛏️"},"double_2pax":{"capacity":2,"offline":7000,"online":6650,"label":"Double Sleeper (Private)","cabinType":"double","emoji":"🔒🛏️🛏️"}}}','json','pricing','Cabin pricing table',1),

-- Loyalty
('loyalty_points_per_100','1','int','loyalty','Points per ₹100',1),
('loyalty_referral_points','20','int','loyalty','Points per referral',1),
('loyalty_trip_bonus','10','int','loyalty','Bonus on trip completion',1),
('loyalty_redeem_points','100','int','loyalty','Points per redemption unit',1),
('loyalty_redeem_rupees','10','int','loyalty','Rupees per redemption unit',1),
('loyalty_tiers','[{"name":"Silver","min":0,"discountPct":0,"icon":"🥈"},{"name":"Gold","min":300,"discountPct":2,"icon":"🥇"},{"name":"Platinum","min":900,"discountPct":3,"icon":"⭐"},{"name":"Diamond","min":2000,"discountPct":5,"icon":"💎"}]','json','loyalty','Loyalty tiers',1),

-- Referral programme
('referral_mode','flat','string','referral','flat or percent',0),
('referral_flat','100','float','referral','₹ per confirmed booking',0),
('referral_percent','5','float','referral','% of fare when mode=percent',0),
('referral_window_days','7','int','referral','Confirm-within window',0),

-- Notifications
('admin_whatsapp','919104801507','string','notify','Admin WhatsApp number',0),
('admin_email','booking@shariglobal.com','string','notify','Admin notification email',0),
('whatsapp_driver','click_to_chat','string','notify','click_to_chat | twilio | cloud_api',0),
('whatsapp_api_token','','string','notify','WhatsApp Cloud API token',0),
('whatsapp_phone_id','','string','notify','WhatsApp Cloud API phone ID',0),
('twilio_account_sid','','string','notify','Twilio Account SID',0),
('twilio_auth_token','','string','notify','Twilio Auth Token',0),
('twilio_whatsapp_from','','string','notify','Twilio WhatsApp sender e.g. whatsapp:+14155238886',0),
('twilio_webhook_url','','string','notify','Public webhook URL override for signature check (optional)',0),
('whatsapp_send_pdf','1','bool','notify','Attach the ticket PDF to WhatsApp confirmations',0),
('whatsapp_default_country','91','string','notify','Default country code for 10-digit numbers',0),
('whatsapp_notify_customer','1','bool','notify','Send WhatsApp updates to customers',0),
('whatsapp_notify_admin','1','bool','notify','Send WhatsApp alerts to admin',0),
('smtp_host','','string','notify','SMTP host',0),
('smtp_port','587','int','notify','SMTP port',0),
('smtp_user','','string','notify','SMTP username',0),
('smtp_pass','','string','notify','SMTP password',0),
('smtp_secure','tls','string','notify','tls or ssl',0),
('mail_from_name','S Hari Global Pvt Ltd','string','notify','From name',0),

-- Site behaviour
('notice_on','0','bool','site','Show site notice banner',1),
('notice_text','','string','site','Notice banner text',1),
('default_lang','en','string','site','Default language',1),
('maintenance_mode','0','bool','site','Maintenance mode',0),
('temples_default_on','1','bool','site','Show temples on map by default',1),
('travel_checklist','["Original government photo ID (passport / voter ID / citizenship card)","Printed or downloaded PDF ticket (save it before you lose signal)","Phone + charger / power bank","Water & light snacks for the border halt","INR and NPR cash for small expenses"]','json','site','Ticket travel checklist',1);


-- ---------------------------------------------------------------------
--  BUSES
-- ---------------------------------------------------------------------
INSERT INTO `buses` (`id`,`bus_number`,`bus_name`,`coach_type`,`total_seats`,`amenities`,`is_active`) VALUES
(1,'GJ-02-T-4127','SHG Himalaya Express','seater',40,'["AC","Charging Point","Water Bottle","Border Assistance"]',1),
(2,'GJ-02-T-5580','SHG Gandaki Sleeper','sleeper',40,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]',1);


-- ---------------------------------------------------------------------
--  DRIVERS  (PWA PIN is set from the admin panel — never seeded plain)
-- ---------------------------------------------------------------------
INSERT INTO `drivers` (`id`,`full_name`,`phone`,`role`,`is_active`) VALUES
(1,'Rajesh Chaudhary','919099011223','driver',1),
(2,'Mahesh Rawal','919879044556','driver',1);


-- ---------------------------------------------------------------------
--  ROUTES
-- ---------------------------------------------------------------------
INSERT INTO `routes`
 (`id`,`route_code`,`from_city`,`to_city`,`bus_id`,`coach_type`,`path_id`,`dep_time`,`arr_time`,`day_offset`,`duration_text`,`distance_km`,`base_fare`,`amenities`,`crew_name`,`crew_phone`,`is_active`,`sort_order`)
VALUES
(1,'r1','Ahmedabad','Nepalgunj',1,'seater','via_gorakhpur','11:00:00','18:25:00',1,'31h 25m',1375,2199.00,'["AC","Charging Point","Water Bottle","Border Assistance"]','Rajesh Chaudhary','+91 90990 11223',1,1),
(2,'r2','Ahmedabad','Nepalgunj',2,'sleeper','via_bahraich','16:30:00','00:39:00',2,'31h 54m',1375,2799.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]','Mahesh Rawal','+91 98790 44556',1,2),
(3,'r3','Nepalgunj','Ahmedabad',1,'seater','via_gorakhpur','09:30:00','16:10:00',1,'30h 55m',1375,2199.00,'["AC","Charging Point","Water Bottle","Border Assistance"]','Rajesh Chaudhary','+91 90990 11223',1,3);


-- ---------------------------------------------------------------------
--  ROUTE STOPS — r1 Ahmedabad -> Nepalgunj (via Gorakhpur)
-- ---------------------------------------------------------------------
INSERT INTO `route_stops`
 (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
VALUES
(1,'boarding','Ahmedabad — Paldi Bus Stand','SHG Counter','09:00:00',23.0230000,72.5710000,0,0,1),
(1,'boarding','Chiloda Naka — Highway Junction','SH Junction Halt','09:40:00',23.1710000,72.6230000,0,0,2),
(1,'boarding','Mehsana — SHG Office','Sliver Complex','11:00:00',23.5880000,72.3690000,0,0,3),
(1,'boarding','Jay Ambe Restaurant — Highway Halt','🍽️ Scheduled Lunch Break (~30–45 min)','12:00:00',23.7000000,72.3800000,0,1,4),
(1,'boarding','Unjha — Highway Circle','Near APMC Gate','12:50:00',23.8040000,72.3910000,0,0,5),
(1,'boarding','Sidhpur — Bus Stand','Main Gate','13:30:00',23.9170000,72.3730000,0,0,6),
(1,'boarding','Palanpur — Aroma Circle','Highway Bridge','14:40:00',24.1710000,72.4380000,0,0,7),
(1,'drop','Lucknow — Alambagh Bus Stand',NULL,'09:10:00',26.8210000,80.9200000,0,0,1),
(1,'drop','Gorakhpur — Railway Station Road',NULL,'14:10:00',26.7610000,83.3730000,0,0,2),
(1,'drop','Rupaidiha Border','India–Nepal Checkpoint','17:10:00',28.0600000,81.6170000,1,0,3),
(1,'drop','Nepalgunj — Bus Park','Main Terminal','18:25:00',28.0500000,81.6160000,0,0,4),
(1,'drop','Nepalgunj — Dhamboji Chowk',NULL,'18:45:00',28.0580000,81.6180000,0,0,5),
(1,'drop','Kohalpur Chowk','Last Stop','19:10:00',28.1050000,81.7340000,0,0,6);

-- ---------------------------------------------------------------------
--  ROUTE STOPS — r2 Ahmedabad -> Nepalgunj (via Bahraich, sleeper)
-- ---------------------------------------------------------------------
INSERT INTO `route_stops`
 (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
VALUES
(2,'boarding','Ahmedabad — Paldi Bus Stand','SHG Counter','14:30:00',23.0230000,72.5710000,0,0,1),
(2,'boarding','Chiloda Naka — Highway Junction','SH Junction Halt','15:10:00',23.1710000,72.6230000,0,0,2),
(2,'boarding','Mehsana — SHG Office','Sliver Complex','16:30:00',23.5880000,72.3690000,0,0,3),
(2,'boarding','Jay Ambe Restaurant — Highway Halt','🍽️ Scheduled Meal Break (~30–45 min)','17:00:00',23.7000000,72.3800000,0,1,4),
(2,'boarding','Visnagar — Kansa Chokdi',NULL,'18:00:00',23.7000000,72.5540000,0,0,5),
(2,'boarding','Palanpur — Aroma Circle','Highway Bridge','19:40:00',24.1710000,72.4380000,0,0,6),
(2,'drop','Lucknow — Alambagh Bus Stand',NULL,'14:40:00',26.8210000,80.9200000,0,0,1),
(2,'drop','Bahraich — Bus Stand',NULL,'19:40:00',27.5730000,81.6010000,0,0,2),
(2,'drop','Rupaidiha Border','India–Nepal Checkpoint','23:20:00',28.0600000,81.6170000,1,0,3),
(2,'drop','Nepalgunj — Bus Park','Main Terminal','00:39:00',28.0500000,81.6160000,0,0,4),
(2,'drop','Nepalgunj — Dhamboji Chowk',NULL,'01:00:00',28.0580000,81.6180000,0,0,5),
(2,'drop','Kohalpur Chowk','Last Stop','01:30:00',28.1050000,81.7340000,0,0,6);

-- ---------------------------------------------------------------------
--  ROUTE STOPS — r3 Nepalgunj -> Ahmedabad (return)
-- ---------------------------------------------------------------------
INSERT INTO `route_stops`
 (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
VALUES
(3,'boarding','Kohalpur Chowk','First Stop','08:30:00',28.1050000,81.7340000,0,0,1),
(3,'boarding','Nepalgunj — Bus Park','Main Terminal','09:30:00',28.0500000,81.6160000,0,0,2),
(3,'boarding','Nepalgunj — Dhamboji Chowk',NULL,'09:50:00',28.0580000,81.6180000,0,0,3),
(3,'boarding','Rupaidiha Border','India–Nepal Checkpoint','10:40:00',28.0600000,81.6170000,1,0,4),
(3,'drop','Gorakhpur — Railway Station Road',NULL,'14:30:00',26.7610000,83.3730000,0,0,1),
(3,'drop','Lucknow — Alambagh Bus Stand',NULL,'20:00:00',26.8210000,80.9200000,0,0,2),
(3,'drop','Palanpur — Aroma Circle',NULL,'13:30:00',24.1710000,72.4380000,0,0,3),
(3,'drop','Sidhpur — Bus Stand',NULL,'14:20:00',23.9170000,72.3730000,0,0,4),
(3,'drop','Unjha — Highway Circle',NULL,'14:50:00',23.8040000,72.3910000,0,0,5),
(3,'drop','Jay Ambe Restaurant — Highway Halt','🍽️ Scheduled Meal Break (~30–45 min)','15:10:00',23.7000000,72.3800000,0,1,6),
(3,'drop','Mehsana — SHG Office','Sliver Complex','16:10:00',23.5880000,72.3690000,0,0,7),
(3,'drop','Chiloda Naka — Highway Junction','SH Junction Halt','17:00:00',23.1710000,72.6230000,0,0,8),
(3,'drop','Ahmedabad — Paldi Bus Stand',NULL,'18:10:00',23.0230000,72.5710000,0,0,9);


-- ---------------------------------------------------------------------
--  SAMPLE COUPON  (inactive by default — enable from the admin panel)
-- ---------------------------------------------------------------------
INSERT INTO `coupons`
 (`code`,`title`,`discount_type`,`discount_value`,`max_discount`,`min_amount`,`usage_limit`,`per_user_limit`,`is_active`)
VALUES
('WELCOME100','Welcome offer — ₹100 off first booking','flat',100.00,NULL,1500.00,500,1,0);


SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  SEED COMPLETE
--  Now open  https://your-domain.com/install.php  to create the first
--  admin account. Delete or rename install.php afterwards.
-- =====================================================================
