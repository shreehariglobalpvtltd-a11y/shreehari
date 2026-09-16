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
('company_address','Near Shilpa Garage, Silver Complex, Mehsana – 384002, Gujarat, India','string','company','Registered address',1),
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
('counter_max_seats_per_booking','20','int','booking','Max seats per booking for a staff/counter sale (bulk booking, 5 Sep 2026) — NOT public, only the staff boot payload ships it',0),
('counter_max_discount_pct','15','float','booking','Max discount % an agent/admin can apply at the counter (Task 9)',1),
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
('cabin_pricing','{"onlineDiscountPct":5,"sharingByDir":{"toNepal":1800,"toIndia":2000},"sharing":{"single_2pax":{"capacity":2,"offline":4400,"online":4180,"perPerson":2090,"label":"Single Sleeper (Sharing)","cabinType":"single","emoji":"🛏️"},"double_3pax":{"capacity":3,"offline":7500,"online":7125,"perPerson":2375,"label":"Double Sleeper (Sharing) · 3P","cabinType":"double","emoji":"🛏️🛏️"},"double_4pax":{"capacity":4,"offline":8800,"online":8360,"perPerson":2090,"label":"Double Sleeper (Sharing) · 4P","cabinType":"double","emoji":"🛏️🛏️"}},"private":{"single_1pax":{"capacity":1,"offline":3800,"online":3800,"label":"Single Sleeper (Private)","cabinType":"single","emoji":"🔒🛏️"},"double_2pax":{"capacity":2,"offline":7600,"online":7600,"label":"Double Sleeper (Private)","cabinType":"double","emoji":"🔒🛏️🛏️"}}}','json','pricing','Cabin pricing table',1),

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
('whatsapp_driver','twilio','string','notify','click_to_chat | twilio | cloud_api',0),
('whatsapp_api_token','','string','notify','WhatsApp Cloud API token',0),
('whatsapp_phone_id','','string','notify','WhatsApp Cloud API phone ID',0),
-- Credentials are deliberately EMPTY here. seed.sql is committed to git and
-- gets copied around; a live Auth Token in it leaks the moment the repo is
-- shared, zipped or pushed. Set the real values by running the git-ignored
-- database/set-twilio-credentials.sql, or from Admin -> Settings -> Notify.
('twilio_account_sid','','string','notify','Twilio Account SID',0),
('twilio_auth_token','','string','notify','Twilio Auth Token',0),
('twilio_whatsapp_from','','string','notify','Twilio WhatsApp sender e.g. whatsapp:+14155238886',0),
-- Approved WhatsApp template for the ticket confirmation. WhatsApp refuses
-- free-form business-initiated messages (Twilio 21654), so auto-sending a
-- ticket needs one. Empty = free-form, which only works inside a 24h window.
-- Template variable order: {{1}} PNR {{2}} route {{3}} date {{4}} boarding
-- point + time {{5}} seats {{6}} total fare, with an IMAGE header.
('twilio_content_sid','','string','notify','Approved WhatsApp template SID for ticket confirmations (HX...)',0),
('twilio_webhook_url','','string','notify','Public webhook URL override for signature check (optional)',0),
('whatsapp_send_pdf','1','bool','notify','Attach the ticket PDF to WhatsApp confirmations',0),
('whatsapp_default_country','91','string','notify','Default country code for 10-digit numbers',0),
('whatsapp_notify_customer','1','bool','notify','Send WhatsApp updates to customers',0),
('whatsapp_notify_admin','1','bool','notify','Send WhatsApp alerts to admin',0),
('sms_enabled','1','bool','notify','SMS channel enabled (Twilio SMS)',0),
('sms_notify_customer','1','bool','notify','Send booking-confirmation SMS to the customer',0),
('sms_send_otp','1','bool','notify','Send OTP login codes by SMS',0),
('twilio_sms_from','','string','notify','Twilio SMS sender — SMS-capable number e.g. +14155550123 (NOT the WhatsApp sender)',0),
('smtp_host','','string','notify','SMTP host',0),
('smtp_port','587','int','notify','SMTP port',0),
('smtp_user','','string','notify','SMTP username',0),
('smtp_pass','','string','notify','SMTP password',0),
('smtp_secure','tls','string','notify','tls or ssl',0),
('mail_from_name','S Hari Global Pvt Ltd','string','notify','From name',0),

-- Automation control panel (EventBus layer — see upgrade-2026-08-automation.sql)
('email_enabled','1','bool','automation','Email notifications (master switch)',0),
('email_attach_ics','1','bool','automation','Attach calendar event (.ics) to ticket emails',0),
('email_attach_pdf','1','bool','automation','Attach ticket PDF to ticket emails',0),
('notify_customer_on_create','1','bool','automation','WhatsApp customer when a booking is placed',0),
('notify_proof_uploaded','1','bool','automation','Alert admin when payment proof is uploaded',0),
('notify_cod_settled','1','bool','automation','Notify customer + agent when COD cash is settled',0),
('agent_notify_enabled','1','bool','automation','WhatsApp the selling agent on approve / reject / cancel',0),
('agent_daily_summary_enabled','1','bool','automation','Agent daily summary at 9 PM (cron/daily-summary.php)',0),
('admin_daily_digest_enabled','1','bool','automation','Admin daily revenue digest at 9 PM',0),
('low_seat_alert_enabled','1','bool','automation','Low-seat alert to admin (cron/alerts.php)',0),
('low_seat_threshold','10','int','automation','Low-seat alert threshold (seats remaining)',0),
('pending_approval_alert_enabled','1','bool','automation','Remind admin about unreviewed payment proofs',0),
('approval_reminder_minutes','30','int','automation','Remind after payment proof waits (minutes)',0),
('automation_log_days','30','int','automation','Keep automation log for (days)',0),

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
(2,'GJ-02-T-5580','SHG Gandaki Sleeper','sleeper',40,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]',1),
(3,'GJ-02-T-6011','SHG Lumbini Deluxe','sleeper',40,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]',1),
(4,'GJ-02-T-6022','SHG Bheri Express','seater',40,'["AC","Charging Point","Water Bottle","Border Assistance"]',1),
(5,'GJ-02-T-6033','SHG Karnali AC Sleeper','sleeper',40,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]',1);


-- ---------------------------------------------------------------------
--  DRIVERS  (PWA PIN is set from the admin panel — never seeded plain)
-- ---------------------------------------------------------------------
INSERT INTO `drivers` (`id`,`full_name`,`phone`,`role`,`is_active`) VALUES
(1,'Rajesh Chaudhary','919099011223','driver',1),
(2,'','919879044556','driver',1);


-- ---------------------------------------------------------------------
--  ROUTES
-- ---------------------------------------------------------------------
INSERT INTO `routes`
 (`id`,`route_code`,`from_city`,`to_city`,`bus_id`,`coach_type`,`path_id`,`dep_time`,`arr_time`,`day_offset`,`duration_text`,`distance_km`,`base_fare`,`amenities`,`crew_name`,`crew_phone`,`is_active`,`sort_order`)
VALUES
(1,'r1','Ahmedabad','Nepalgunj',1,'seater','via_gorakhpur','11:00:00','18:25:00',1,'31h 25m',1375,2199.00,'["AC","Charging Point","Water Bottle","Border Assistance"]','Rajesh Chaudhary','+91 90990 11223',1,1),
(2,'r2','Ahmedabad','Nepalgunj',2,'sleeper','via_bahraich','16:30:00','00:39:00',2,'31h 54m',1375,2799.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]','',NULL,1,2),
(3,'r3','Nepalgunj','Ahmedabad',1,'seater','via_gorakhpur','09:30:00','16:10:00',1,'30h 55m',1375,2199.00,'["AC","Charging Point","Water Bottle","Border Assistance"]','Rajesh Chaudhary','+91 90990 11223',1,3),
(4,'r4','Ahmedabad','Nepalgunj',3,'sleeper','via_bahraich','19:30:00','03:39:00',2,'32h 09m',1375,2899.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]','','',1,4),
(5,'r5','Ahmedabad','Nepalgunj',4,'seater','via_gorakhpur','07:30:00','14:55:00',1,'31h 25m',1375,2149.00,'["AC","Charging Point","Water Bottle","Border Assistance"]','','',1,5),
(6,'r6','Ahmedabad','Nepalgunj',5,'sleeper','via_bahraich','21:00:00','05:09:00',2,'32h 09m',1375,2999.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]','','',1,6),
-- Return leg (Nepalgunj -> Ahmedabad): each bus also runs back, so both
-- directions offer 5 buses (3 sleeper + 2 seater), mirroring the forward fleet.
(7,'r7','Nepalgunj','Ahmedabad',2,'sleeper','via_bahraich','17:00:00','00:54:00',2,'31h 54m',1375,2799.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]','','',1,7),
(8,'r8','Nepalgunj','Ahmedabad',3,'sleeper','via_bahraich','17:30:00','01:39:00',2,'32h 09m',1375,2899.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]','','',1,8),
(9,'r9','Nepalgunj','Ahmedabad',4,'seater','via_gorakhpur','18:30:00','01:55:00',2,'31h 25m',1375,2149.00,'["AC","Charging Point","Water Bottle","Border Assistance"]','','',1,9),
(10,'r10','Nepalgunj','Ahmedabad',5,'sleeper','via_bahraich','19:00:00','03:09:00',2,'32h 09m',1375,2999.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]','','',1,10);


-- ---------------------------------------------------------------------
--  ROUTE STOPS — Sep-2 launch model
--    Gujarat → Nepal: 4 boarding (Mehsana, Ahmedabad, Vadodara, Surat)
--    Nepal   → Gujarat: 4 drops in reverse
--    In every direction the trans-border half is a single stop at
--    Rupaidiha (India-side border). Passengers going deeper into Nepal
--    cross the border themselves and take a local vehicle onward.
--
--  All 10 seeded routes share this pattern. If you add a new Gujarat
--  route, either replicate this block for it or re-run
--  upgrade-2026-08-sharing-stops.sql which fills every Gujarat route.
-- ---------------------------------------------------------------------

-- Outbound (routes 1, 2, 4, 5, 6): Gujarat → border
INSERT INTO `route_stops`
 (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
VALUES
(1,'boarding','Mehsana — SHG Head Office','Sliver Complex, Near Shilpa Garage','11:00:00',23.5880000,72.3690000,0,0,1),
(1,'boarding','Ahmedabad — Paldi Bus Stand','SHG Counter','12:00:00',23.0230000,72.5710000,0,0,2),
(1,'boarding','Vadodara — Ajwa Cross','NH-48 Junction','14:00:00',22.3072000,73.1812000,0,0,3),
(1,'boarding','Surat — Kamrej Circle','NH-48 Kamrej Junction','16:00:00',21.2711000,72.9575000,0,0,4),
(1,'drop','Rupaidiha — India / Nepal Border','SSB Checkpoint','18:25:00',28.0600000,81.6170000,1,0,1),

(2,'boarding','Mehsana — SHG Head Office','Sliver Complex, Near Shilpa Garage','16:30:00',23.5880000,72.3690000,0,0,1),
(2,'boarding','Ahmedabad — Paldi Bus Stand','SHG Counter','17:30:00',23.0230000,72.5710000,0,0,2),
(2,'boarding','Vadodara — Ajwa Cross','NH-48 Junction','19:30:00',22.3072000,73.1812000,0,0,3),
(2,'boarding','Surat — Kamrej Circle','NH-48 Kamrej Junction','21:30:00',21.2711000,72.9575000,0,0,4),
(2,'drop','Rupaidiha — India / Nepal Border','SSB Checkpoint','00:39:00',28.0600000,81.6170000,1,0,1),

(4,'boarding','Mehsana — SHG Head Office','Sliver Complex, Near Shilpa Garage','19:30:00',23.5880000,72.3690000,0,0,1),
(4,'boarding','Ahmedabad — Paldi Bus Stand','SHG Counter','20:30:00',23.0230000,72.5710000,0,0,2),
(4,'boarding','Vadodara — Ajwa Cross','NH-48 Junction','22:30:00',22.3072000,73.1812000,0,0,3),
(4,'boarding','Surat — Kamrej Circle','NH-48 Kamrej Junction','00:30:00',21.2711000,72.9575000,0,0,4),
(4,'drop','Rupaidiha — India / Nepal Border','SSB Checkpoint','03:39:00',28.0600000,81.6170000,1,0,1),

(5,'boarding','Mehsana — SHG Head Office','Sliver Complex, Near Shilpa Garage','07:30:00',23.5880000,72.3690000,0,0,1),
(5,'boarding','Ahmedabad — Paldi Bus Stand','SHG Counter','08:30:00',23.0230000,72.5710000,0,0,2),
(5,'boarding','Vadodara — Ajwa Cross','NH-48 Junction','10:30:00',22.3072000,73.1812000,0,0,3),
(5,'boarding','Surat — Kamrej Circle','NH-48 Kamrej Junction','12:30:00',21.2711000,72.9575000,0,0,4),
(5,'drop','Rupaidiha — India / Nepal Border','SSB Checkpoint','14:55:00',28.0600000,81.6170000,1,0,1),

(6,'boarding','Mehsana — SHG Head Office','Sliver Complex, Near Shilpa Garage','21:00:00',23.5880000,72.3690000,0,0,1),
(6,'boarding','Ahmedabad — Paldi Bus Stand','SHG Counter','22:00:00',23.0230000,72.5710000,0,0,2),
(6,'boarding','Vadodara — Ajwa Cross','NH-48 Junction','00:00:00',22.3072000,73.1812000,0,0,3),
(6,'boarding','Surat — Kamrej Circle','NH-48 Kamrej Junction','02:00:00',21.2711000,72.9575000,0,0,4),
(6,'drop','Rupaidiha — India / Nepal Border','SSB Checkpoint','05:09:00',28.0600000,81.6170000,1,0,1);

-- Return (routes 3, 7, 8, 9, 10): border → Gujarat
INSERT INTO `route_stops`
 (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
VALUES
(3,'boarding','Rupaidiha — India / Nepal Border','SSB Checkpoint','09:30:00',28.0600000,81.6170000,1,0,1),
(3,'drop','Surat — Kamrej Circle','NH-48 Kamrej Junction','11:10:00',21.2711000,72.9575000,0,0,1),
(3,'drop','Vadodara — Ajwa Cross','NH-48 Junction','13:10:00',22.3072000,73.1812000,0,0,2),
(3,'drop','Ahmedabad — Paldi Bus Stand','SHG Counter','15:10:00',23.0230000,72.5710000,0,0,3),
(3,'drop','Mehsana — SHG Head Office','Sliver Complex','16:10:00',23.5880000,72.3690000,0,0,4),

(7,'boarding','Rupaidiha — India / Nepal Border','SSB Checkpoint','17:00:00',28.0600000,81.6170000,1,0,1),
(7,'drop','Surat — Kamrej Circle','NH-48 Kamrej Junction','19:54:00',21.2711000,72.9575000,0,0,1),
(7,'drop','Vadodara — Ajwa Cross','NH-48 Junction','21:54:00',22.3072000,73.1812000,0,0,2),
(7,'drop','Ahmedabad — Paldi Bus Stand','SHG Counter','23:54:00',23.0230000,72.5710000,0,0,3),
(7,'drop','Mehsana — SHG Head Office','Sliver Complex','00:54:00',23.5880000,72.3690000,0,0,4),

(8,'boarding','Rupaidiha — India / Nepal Border','SSB Checkpoint','17:30:00',28.0600000,81.6170000,1,0,1),
(8,'drop','Surat — Kamrej Circle','NH-48 Kamrej Junction','20:39:00',21.2711000,72.9575000,0,0,1),
(8,'drop','Vadodara — Ajwa Cross','NH-48 Junction','22:39:00',22.3072000,73.1812000,0,0,2),
(8,'drop','Ahmedabad — Paldi Bus Stand','SHG Counter','00:39:00',23.0230000,72.5710000,0,0,3),
(8,'drop','Mehsana — SHG Head Office','Sliver Complex','01:39:00',23.5880000,72.3690000,0,0,4),

(9,'boarding','Rupaidiha — India / Nepal Border','SSB Checkpoint','18:30:00',28.0600000,81.6170000,1,0,1),
(9,'drop','Surat — Kamrej Circle','NH-48 Kamrej Junction','20:55:00',21.2711000,72.9575000,0,0,1),
(9,'drop','Vadodara — Ajwa Cross','NH-48 Junction','22:55:00',22.3072000,73.1812000,0,0,2),
(9,'drop','Ahmedabad — Paldi Bus Stand','SHG Counter','00:55:00',23.0230000,72.5710000,0,0,3),
(9,'drop','Mehsana — SHG Head Office','Sliver Complex','01:55:00',23.5880000,72.3690000,0,0,4),

(10,'boarding','Rupaidiha — India / Nepal Border','SSB Checkpoint','19:00:00',28.0600000,81.6170000,1,0,1),
(10,'drop','Surat — Kamrej Circle','NH-48 Kamrej Junction','22:09:00',21.2711000,72.9575000,0,0,1),
(10,'drop','Vadodara — Ajwa Cross','NH-48 Junction','00:09:00',22.3072000,73.1812000,0,0,2),
(10,'drop','Ahmedabad — Paldi Bus Stand','SHG Counter','02:09:00',23.0230000,72.5710000,0,0,3),
(10,'drop','Mehsana — SHG Head Office','Sliver Complex','03:09:00',23.5880000,72.3690000,0,0,4);


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
