-- =====================================================================
--  S HARI GLOBAL PVT LTD — Bus Reservation System
--  Production MySQL Schema
--  Target: MySQL 5.7+ / MariaDB 10.3+ (Hostinger Premium shared hosting)
--  Charset: utf8mb4 (required — stores Hindi + Nepali Devanagari text)
--
--  IMPORT ORDER:  schema.sql  ->  seed.sql
--
--  Design notes
--   * Every table is InnoDB so foreign keys + transactions work.
--   * Human-facing identifiers (PNR, ticket no) are UNIQUE VARCHAR columns;
--     internal relationships use BIGINT surrogate keys for speed.
--   * booking_seats carries UNIQUE(schedule_id, seat_no). This makes
--     double-booking impossible at the database level, not merely in PHP.
--   * JSON-ish payloads use LONGTEXT (not the JSON type) so the schema
--     imports cleanly on older MariaDB builds still common on shared hosts.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';


-- =====================================================================
--  1. SETTINGS  — runtime configuration editable from the admin panel
-- =====================================================================
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skey`        VARCHAR(100) NOT NULL,
  `svalue`      LONGTEXT NULL,
  `stype`       ENUM('string','int','float','bool','json') NOT NULL DEFAULT 'string',
  `sgroup`      VARCHAR(50) NOT NULL DEFAULT 'general',
  `label`       VARCHAR(191) NULL,
  `is_public`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = safe to expose to the browser',
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`skey`),
  KEY `ix_settings_group` (`sgroup`),
  KEY `ix_settings_public` (`is_public`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  2. ADMINS  — staff accounts with role based permissions
-- =====================================================================
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`       VARCHAR(60) NOT NULL,
  `password_hash`  VARCHAR(255) NOT NULL COMMENT 'password_hash() bcrypt',
  `full_name`      VARCHAR(120) NOT NULL,
  `email`          VARCHAR(191) NULL,
  `phone`          VARCHAR(20) NULL,
  `role`           ENUM('superadmin','manager','accountant','support','scanner','official','agent') NOT NULL DEFAULT 'support',
  `permissions`    LONGTEXT NULL COMMENT 'JSON array of extra grants',
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at`  DATETIME NULL,
  `last_login_ip`  VARCHAR(45) NULL,
  `failed_logins`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`   DATETIME NULL,
  `must_change_pw` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_username` (`username`),
  KEY `ix_admins_role` (`role`),
  KEY `ix_admins_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  3. USERS  — customers and referral agents (phone is the identity)
-- =====================================================================
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone`            VARCHAR(20) NOT NULL COMMENT 'digits only, no + prefix',
  `country_code`     VARCHAR(5) NOT NULL DEFAULT '91',
  `full_name`        VARCHAR(120) NULL,
  `email`            VARCHAR(191) NULL,
  `password_hash`    VARCHAR(255) NULL COMMENT 'NULL = OTP-only account',
  `role`             ENUM('customer','agent') NOT NULL DEFAULT 'customer',
  `ref_code`         VARCHAR(20) NULL COMMENT 'agents only — referral code',
  `referred_by`      VARCHAR(20) NULL,
  `id_type`          VARCHAR(60) NULL,
  `id_number`        VARCHAR(60) NULL,
  `preferred_lang`   ENUM('en','hi','ne') NOT NULL DEFAULT 'en',
  `loyalty_points`   INT NOT NULL DEFAULT 0,
  `lifetime_points`  INT NOT NULL DEFAULT 0,
  `loyalty_tier`     VARCHAR(20) NOT NULL DEFAULT 'Silver',
  `wallet_balance`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_trips`      INT NOT NULL DEFAULT 0,
  `is_blocked`       TINYINT(1) NOT NULL DEFAULT 0,
  `blocked_reason`   VARCHAR(255) NULL,
  `phone_verified`   TINYINT(1) NOT NULL DEFAULT 0,
  `email_verified`   TINYINT(1) NOT NULL DEFAULT 0,
  `last_login_at`    DATETIME NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_phone` (`phone`),
  UNIQUE KEY `uq_users_refcode` (`ref_code`),
  KEY `ix_users_role` (`role`),
  KEY `ix_users_email` (`email`),
  KEY `ix_users_blocked` (`is_blocked`),
  KEY `ix_users_referred` (`referred_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  4. BUSES
-- =====================================================================
DROP TABLE IF EXISTS `buses`;
CREATE TABLE `buses` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bus_number`    VARCHAR(30) NOT NULL COMMENT 'e.g. GJ-02-T-4127',
  `bus_name`      VARCHAR(120) NOT NULL,
  `coach_type`    ENUM('seater','sleeper') NOT NULL DEFAULT 'sleeper',
  `total_seats`   SMALLINT UNSIGNED NOT NULL DEFAULT 40,
  `seat_layout`   LONGTEXT NULL COMMENT 'JSON override of generated seat map',
  `amenities`     LONGTEXT NULL COMMENT 'JSON array',
  `registration`  VARCHAR(60) NULL,
  `insurance_exp` DATE NULL,
  `permit_exp`    DATE NULL,
  `fitness_exp`   DATE NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_buses_number` (`bus_number`),
  KEY `ix_buses_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  5. DRIVERS  (also used by the Driver PWA for GPS broadcast)
-- =====================================================================
DROP TABLE IF EXISTS `drivers`;
CREATE TABLE `drivers` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`      VARCHAR(120) NOT NULL,
  `phone`          VARCHAR(20) NOT NULL,
  `licence_no`     VARCHAR(60) NULL,
  `licence_exp`    DATE NULL,
  `pin_hash`       VARCHAR(255) NULL COMMENT 'Driver PWA login PIN',
  `role`           ENUM('driver','co-driver','conductor','host') NOT NULL DEFAULT 'driver',
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_drivers_phone` (`phone`),
  KEY `ix_drivers_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  6. ROUTES
-- =====================================================================
DROP TABLE IF EXISTS `routes`;
CREATE TABLE `routes` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_code`     VARCHAR(20) NOT NULL COMMENT 'legacy id e.g. r1 — used in PNR',
  `from_city`      VARCHAR(80) NOT NULL,
  `to_city`        VARCHAR(80) NOT NULL,
  `bus_id`         BIGINT UNSIGNED NULL,
  `coach_type`     ENUM('seater','sleeper') NOT NULL DEFAULT 'sleeper',
  `path_id`        VARCHAR(40) NULL COMMENT 'via_gorakhpur / via_bahraich',
  `dep_time`       TIME NOT NULL,
  `arr_time`       TIME NOT NULL,
  `day_offset`     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `duration_text`  VARCHAR(30) NULL,
  `distance_km`    INT UNSIGNED NULL,
  `base_fare`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amenities`      LONGTEXT NULL COMMENT 'JSON array',
  `crew_name`      VARCHAR(120) NULL,
  `crew_phone`     VARCHAR(20) NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`     INT NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_routes_code` (`route_code`),
  KEY `ix_routes_search` (`from_city`,`to_city`,`is_active`),
  KEY `ix_routes_bus` (`bus_id`),
  CONSTRAINT `fk_routes_bus` FOREIGN KEY (`bus_id`)
    REFERENCES `buses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  7. ROUTE STOPS  — normalized boarding / drop points with geo + time
-- =====================================================================
DROP TABLE IF EXISTS `route_stops`;
CREATE TABLE `route_stops` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_id`      BIGINT UNSIGNED NOT NULL,
  `stop_type`     ENUM('boarding','drop') NOT NULL,
  `stop_name`     VARCHAR(191) NOT NULL,
  `landmark`      VARCHAR(191) NULL,
  `stop_time`     TIME NULL,
  `latitude`      DECIMAL(10,7) NULL,
  `longitude`     DECIMAL(10,7) NULL,
  `is_border`     TINYINT(1) NOT NULL DEFAULT 0,
  `is_meal_halt`  TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order`    INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_stops_route` (`route_id`,`stop_type`,`sort_order`),
  CONSTRAINT `fk_stops_route` FOREIGN KEY (`route_id`)
    REFERENCES `routes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  8. SCHEDULES  — one row per route per travel date (the sellable unit)
-- =====================================================================
DROP TABLE IF EXISTS `schedules`;
CREATE TABLE `schedules` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_id`          BIGINT UNSIGNED NOT NULL,
  `travel_date`       DATE NOT NULL,
  `dep_time_override` TIME NULL COMMENT 'per-date absolute departure time; COALESCE(dep_time_override, r.dep_time) wins',
  `bus_id`            BIGINT UNSIGNED NULL,
  `driver_id`         BIGINT UNSIGNED NULL,
  `fare_override`     DECIMAL(10,2) NULL,
  `total_seats`       SMALLINT UNSIGNED NOT NULL DEFAULT 40,
  `seats_booked`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `status`            ENUM('scheduled','departed','arrived','cancelled') NOT NULL DEFAULT 'scheduled',
  `is_blocked`        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'admin temp-block: hidden from customer search but not cancelled',
  `delay_minutes`     INT NOT NULL DEFAULT 0,
  `delay_note`        VARCHAR(255) NULL,
  `cancel_reason`     VARCHAR(255) NULL COMMENT 'free-text reason recorded at cancellation',
  `cancelled_by`      BIGINT UNSIGNED NULL COMMENT 'admins.id who cancelled the trip',
  `cancelled_at`      DATETIME NULL COMMENT 'when the cancel button was clicked',
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schedule_route_date` (`route_id`,`travel_date`),
  KEY `ix_schedules_date` (`travel_date`,`status`),
  KEY `ix_schedules_blocked` (`is_blocked`,`travel_date`),
  KEY `ix_schedules_bus` (`bus_id`),
  KEY `ix_schedules_driver` (`driver_id`),
  CONSTRAINT `fk_sched_route` FOREIGN KEY (`route_id`)
    REFERENCES `routes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sched_bus` FOREIGN KEY (`bus_id`)
    REFERENCES `buses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sched_driver` FOREIGN KEY (`driver_id`)
    REFERENCES `drivers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sched_cancelled_by` FOREIGN KEY (`cancelled_by`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  9. SEAT LOCKS  — temporary hold while a visitor is at checkout
--     UNIQUE(schedule_id, seat_no) stops two people holding one seat.
-- =====================================================================
DROP TABLE IF EXISTS `seat_locks`;
CREATE TABLE `seat_locks` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_id`  BIGINT UNSIGNED NOT NULL,
  `seat_no`      VARCHAR(10) NOT NULL,
  `lock_token`   VARCHAR(64) NOT NULL COMMENT 'per-visitor session token',
  `expires_at`   DATETIME NOT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lock_seat` (`schedule_id`,`seat_no`),
  KEY `ix_locks_expiry` (`expires_at`),
  KEY `ix_locks_token` (`lock_token`),
  CONSTRAINT `fk_locks_sched` FOREIGN KEY (`schedule_id`)
    REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 10. BOOKINGS  — PNR is the customer facing reference
-- =====================================================================
DROP TABLE IF EXISTS `bookings`;
CREATE TABLE `bookings` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pnr`              VARCHAR(40) NOT NULL COMMENT 'SHG-<route>-<ts>-<rand>',
  `user_id`          BIGINT UNSIGNED NULL,
  `sold_by_admin_id` BIGINT UNSIGNED NULL COMMENT 'admins.id who sold this at the counter (agent dashboard / commission)',
  `trip_type`        ENUM('oneway','round') NOT NULL DEFAULT 'oneway',
  `booking_mode`     ENUM('sharing','private') NULL,
  `cabin_type`       VARCHAR(30) NULL,
  `sharing_tier`     VARCHAR(20) NULL,
  `cabin_label`      VARCHAR(120) NULL,
  `contact_phone`    VARCHAR(20) NOT NULL,
  `contact_country_code` VARCHAR(5) NULL COMMENT 'dialing code for contact_phone (977 or 91) - India and Nepal share 10-digit mobiles so it is captured, not guessed',
  `contact_email`    VARCHAR(191) NULL,
  `id_type`          VARCHAR(60) NULL,
  `id_number`        VARCHAR(60) NULL,
  `fare_per_seat`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `base_total`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `group_discount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tier_discount`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tier_name`        VARCHAR(20) NULL,
  `coupon_code`      VARCHAR(40) NULL,
  `coupon_discount`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `points_used`      INT NOT NULL DEFAULT 0,
  `points_value`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `booking_fee`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax_amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`         ENUM('INR','NPR') NOT NULL DEFAULT 'INR',
  `referral_code`    VARCHAR(20) NULL,
  `status`           ENUM('pending','confirmed','cancelled','rejected','completed','expired') NOT NULL DEFAULT 'pending',
  `is_cod`           TINYINT(1) NOT NULL DEFAULT 0,
  `cancel_reason`    VARCHAR(255) NULL,
  `refund_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `refund_status`    ENUM('none','pending','processed','denied') NOT NULL DEFAULT 'none',
  `refund_ref`       VARCHAR(80) NULL,
  `source`           ENUM('web','app','counter','agent','admin') NOT NULL DEFAULT 'web',
  `ip_address`       VARCHAR(45) NULL,
  `user_agent`       VARCHAR(255) NULL,
  `expires_at`       DATETIME NULL COMMENT 'unpaid booking auto-expiry',
  `confirmed_at`     DATETIME NULL,
  `cancelled_at`     DATETIME NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bookings_pnr` (`pnr`),
  KEY `ix_bookings_phone` (`contact_phone`),
  KEY `ix_bookings_status` (`status`,`created_at`),
  KEY `ix_bookings_user` (`user_id`),
  KEY `ix_bookings_referral` (`referral_code`),
  KEY `ix_bookings_created` (`created_at`),
  KEY `ix_bookings_expiry` (`expires_at`,`status`),
  KEY `ix_bookings_confirmed` (`status`,`confirmed_at`),
  KEY `ix_bookings_soldby` (`sold_by_admin_id`,`created_at`),
  CONSTRAINT `fk_bookings_soldby` FOREIGN KEY (`sold_by_admin_id`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bookings_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 11. BOOKING LEGS  — outbound + optional return under one PNR
-- =====================================================================
DROP TABLE IF EXISTS `booking_legs`;
CREATE TABLE `booking_legs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`     BIGINT UNSIGNED NOT NULL,
  `schedule_id`    BIGINT UNSIGNED NOT NULL,
  `leg_type`       ENUM('outbound','return') NOT NULL DEFAULT 'outbound',
  `travel_date`    DATE NOT NULL,
  `boarding_stop`  VARCHAR(191) NULL,
  `drop_stop`      VARCHAR(191) NULL,
  `boarding_time`  TIME NULL,
  `fare_per_seat`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `seat_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `leg_total`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `ix_legs_booking` (`booking_id`),
  KEY `ix_legs_schedule` (`schedule_id`,`travel_date`),
  CONSTRAINT `fk_legs_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_legs_sched` FOREIGN KEY (`schedule_id`)
    REFERENCES `schedules` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 12. BOOKING SEATS  — the double-booking firewall
--     UNIQUE(schedule_id, seat_no) is enforced by InnoDB itself, so a
--     race between two concurrent checkouts fails loudly instead of
--     silently selling the same berth twice.
-- =====================================================================
DROP TABLE IF EXISTS `booking_seats`;
CREATE TABLE `booking_seats` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_id`  BIGINT UNSIGNED NOT NULL,
  `seat_no`      VARCHAR(10) NOT NULL,
  `booking_id`   BIGINT UNSIGNED NOT NULL,
  `leg_id`       BIGINT UNSIGNED NOT NULL,
  `released_at`  DATETIME NULL COMMENT 'set when booking is cancelled',
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seat_per_schedule` (`schedule_id`,`seat_no`),
  KEY `ix_bseats_booking` (`booking_id`),
  KEY `ix_bseats_leg` (`leg_id`),
  CONSTRAINT `fk_bseats_sched` FOREIGN KEY (`schedule_id`)
    REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bseats_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bseats_leg` FOREIGN KEY (`leg_id`)
    REFERENCES `booking_legs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 13. PASSENGERS
-- =====================================================================
DROP TABLE IF EXISTS `booking_passengers`;
CREATE TABLE `booking_passengers` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`    BIGINT UNSIGNED NOT NULL,
  `leg_id`        BIGINT UNSIGNED NULL,
  `passenger_ref` VARCHAR(30) NOT NULL COMMENT 'unique passenger identifier',
  `seat_no`       VARCHAR(10) NOT NULL,
  `full_name`     VARCHAR(120) NOT NULL,
  `age`           TINYINT UNSIGNED NULL,
  `gender`        ENUM('Male','Female','Other') NULL,
  `id_type`       VARCHAR(60) NULL,
  `id_number`     VARCHAR(60) NULL,
  `nationality`   VARCHAR(60) NULL,
  `is_primary`    TINYINT(1) NOT NULL DEFAULT 0,
  `boarded_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_passenger_ref` (`passenger_ref`),
  KEY `ix_pax_booking` (`booking_id`),
  KEY `ix_pax_leg` (`leg_id`),
  KEY `ix_pax_name` (`full_name`),
  CONSTRAINT `fk_pax_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pax_leg` FOREIGN KEY (`leg_id`)
    REFERENCES `booking_legs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 14. PAYMENTS
-- =====================================================================
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`      BIGINT UNSIGNED NOT NULL,
  `payment_ref`     VARCHAR(40) NOT NULL COMMENT 'internal PAY-... reference',
  `method`          ENUM('upi','esewa','cash','bank','wallet','cod') NOT NULL DEFAULT 'upi',
  `mode`            ENUM('utr','screenshot','both','offline') NOT NULL DEFAULT 'utr',
  `amount`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`        ENUM('INR','NPR') NOT NULL DEFAULT 'INR',
  `utr_number`      VARCHAR(60) NULL COMMENT 'UPI UTR / eSewa txn code',
  `payer_name`      VARCHAR(120) NULL,
  `payer_phone`     VARCHAR(20) NULL,
  `status`          ENUM('pending','verified','rejected','refunded','cod_pending') NOT NULL DEFAULT 'pending',
  `verified_by`     BIGINT UNSIGNED NULL,
  `verified_at`     DATETIME NULL,
  `reject_reason`   VARCHAR(255) NULL,
  `admin_note`      VARCHAR(255) NULL,
  `gateway_payload` LONGTEXT NULL COMMENT 'reserved for future gateway integration',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_ref` (`payment_ref`),
  KEY `ix_payments_booking` (`booking_id`),
  KEY `ix_payments_status` (`status`,`created_at`),
  KEY `ix_payments_utr` (`utr_number`),
  KEY `ix_payments_verifier` (`verified_by`),
  CONSTRAINT `fk_payments_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_admin` FOREIGN KEY (`verified_by`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 15. PAYMENT SCREENSHOTS  — uploaded proof images
-- =====================================================================
DROP TABLE IF EXISTS `payment_screenshots`;
CREATE TABLE `payment_screenshots` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id`   BIGINT UNSIGNED NOT NULL,
  `booking_id`   BIGINT UNSIGNED NOT NULL,
  `file_path`    VARCHAR(255) NOT NULL COMMENT 'relative to uploads/',
  `thumb_path`   VARCHAR(255) NULL,
  `original_name` VARCHAR(191) NULL,
  `mime_type`    VARCHAR(60) NOT NULL,
  `file_size`    INT UNSIGNED NOT NULL DEFAULT 0,
  `sha256`       CHAR(64) NULL COMMENT 'duplicate-proof detection',
  `uploaded_ip`  VARCHAR(45) NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_shots_payment` (`payment_id`),
  KEY `ix_shots_booking` (`booking_id`),
  KEY `ix_shots_hash` (`sha256`),
  CONSTRAINT `fk_shots_payment` FOREIGN KEY (`payment_id`)
    REFERENCES `payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_shots_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 16. TICKETS  — issued only after payment verification
-- =====================================================================
DROP TABLE IF EXISTS `tickets`;
CREATE TABLE `tickets` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`     BIGINT UNSIGNED NOT NULL,
  `ticket_number`  VARCHAR(40) NOT NULL,
  `qr_payload`     VARCHAR(255) NOT NULL COMMENT 'SHG-TICKET|PNR|date|seats|...',
  `qr_hash`        CHAR(64) NOT NULL COMMENT 'HMAC — tamper detection at scan',
  `pdf_path`       VARCHAR(255) NULL,
  `invoice_path`   VARCHAR(255) NULL,
  `issued_at`      DATETIME NOT NULL,
  `scanned_at`     DATETIME NULL,
  `scanned_by`     BIGINT UNSIGNED NULL,
  `scan_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `is_void`        TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tickets_number` (`ticket_number`),
  UNIQUE KEY `uq_tickets_booking` (`booking_id`),
  KEY `ix_tickets_hash` (`qr_hash`),
  KEY `ix_tickets_scanner` (`scanned_by`),
  CONSTRAINT `fk_tickets_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tickets_scanner` FOREIGN KEY (`scanned_by`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  SEQUENTIAL TICKET-NUMBER COUNTER — one row per calendar year.
--  In BASE schema (not only in upgrade-2026-08-ticketno-official.sql) so a
--  FRESH install issues official SHG-<year>-<00001> numbers immediately;
--  without this table nextTicketNo() silently falls back to random PNRs.
--  The first booking of a year auto-seeds its row under a FOR UPDATE lock.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `pnr_counters`;
CREATE TABLE `pnr_counters` (
  `yr`  SMALLINT UNSIGNED NOT NULL,
  `seq` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`yr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 17. COUPONS
-- =====================================================================
DROP TABLE IF EXISTS `coupons`;
CREATE TABLE `coupons` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`           VARCHAR(40) NOT NULL,
  `title`          VARCHAR(120) NULL,
  `discount_type`  ENUM('flat','percent') NOT NULL DEFAULT 'percent',
  `discount_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `max_discount`   DECIMAL(10,2) NULL,
  `min_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `route_id`       BIGINT UNSIGNED NULL COMMENT 'NULL = all routes',
  `usage_limit`    INT UNSIGNED NULL COMMENT 'NULL = unlimited',
  `used_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `per_user_limit` INT UNSIGNED NOT NULL DEFAULT 1,
  `valid_from`     DATE NULL,
  `valid_until`    DATE NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupons_code` (`code`),
  KEY `ix_coupons_active` (`is_active`,`valid_from`,`valid_until`),
  KEY `ix_coupons_route` (`route_id`),
  CONSTRAINT `fk_coupons_route` FOREIGN KEY (`route_id`)
    REFERENCES `routes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `coupon_redemptions`;
CREATE TABLE `coupon_redemptions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id`   BIGINT UNSIGNED NOT NULL,
  `booking_id`  BIGINT UNSIGNED NOT NULL,
  `user_phone`  VARCHAR(20) NOT NULL,
  `amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupon_booking` (`coupon_id`,`booking_id`),
  KEY `ix_redeem_phone` (`user_phone`),
  CONSTRAINT `fk_redeem_coupon` FOREIGN KEY (`coupon_id`)
    REFERENCES `coupons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_redeem_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 18. REFERRAL COMMISSIONS + AGENT PAYOUTS
-- =====================================================================
DROP TABLE IF EXISTS `commissions`;
CREATE TABLE `commissions` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`   BIGINT UNSIGNED NOT NULL,
  `agent_id`     BIGINT UNSIGNED NOT NULL,
  `agent_code`   VARCHAR(20) NOT NULL,
  `amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `rule_mode`    ENUM('flat','percent') NOT NULL DEFAULT 'flat',
  `rule_value`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status`       ENUM('pending','confirmed','expired','paid','void') NOT NULL DEFAULT 'pending',
  `note`         VARCHAR(255) NULL,
  `confirmed_at` DATETIME NULL,
  `paid_at`      DATETIME NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_commission_booking` (`booking_id`),
  KEY `ix_comm_agent` (`agent_id`,`status`),
  CONSTRAINT `fk_comm_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_comm_agent` FOREIGN KEY (`agent_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payout_requests`;
CREATE TABLE `payout_requests` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id`     BIGINT UNSIGNED NOT NULL,
  `amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `method`       VARCHAR(40) NULL,
  `account_ref`  VARCHAR(120) NULL,
  `status`       ENUM('requested','approved','paid','rejected') NOT NULL DEFAULT 'requested',
  `processed_by` BIGINT UNSIGNED NULL,
  `processed_at` DATETIME NULL,
  `note`         VARCHAR(255) NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_payout_agent` (`agent_id`,`status`),
  CONSTRAINT `fk_payout_agent` FOREIGN KEY (`agent_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_payout_admin` FOREIGN KEY (`processed_by`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 19. LOYALTY LEDGER  — every point movement is auditable
-- =====================================================================
DROP TABLE IF EXISTS `loyalty_transactions`;
CREATE TABLE `loyalty_transactions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `booking_id`  BIGINT UNSIGNED NULL,
  `points`      INT NOT NULL COMMENT 'positive = earned, negative = redeemed',
  `balance_after` INT NOT NULL DEFAULT 0,
  `reason`      VARCHAR(191) NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_loyalty_user` (`user_id`,`created_at`),
  KEY `ix_loyalty_booking` (`booking_id`),
  CONSTRAINT `fk_loyalty_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_loyalty_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 20. OTP CODES  — hashed, single use, expiring
-- =====================================================================
DROP TABLE IF EXISTS `otp_codes`;
CREATE TABLE `otp_codes` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier`  VARCHAR(191) NOT NULL COMMENT 'phone or email',
  `channel`     ENUM('sms','email','whatsapp') NOT NULL DEFAULT 'sms',
  `purpose`     ENUM('login','register','reset','verify','cancel') NOT NULL DEFAULT 'login',
  `code_hash`   VARCHAR(255) NOT NULL COMMENT 'never store the plain OTP',
  `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `is_used`     TINYINT(1) NOT NULL DEFAULT 0,
  `expires_at`  DATETIME NOT NULL,
  `ip_address`  VARCHAR(45) NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_otp_lookup` (`identifier`,`purpose`,`is_used`,`expires_at`),
  KEY `ix_otp_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 21. NOTIFICATIONS
-- =====================================================================
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `audience`    ENUM('admin','user','agent','all') NOT NULL DEFAULT 'admin',
  `user_id`     BIGINT UNSIGNED NULL,
  `booking_id`  BIGINT UNSIGNED NULL,
  `icon`        VARCHAR(10) NULL,
  `title`       VARCHAR(191) NOT NULL,
  `body`        TEXT NULL,
  `link`        VARCHAR(255) NULL,
  `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
  `read_at`     DATETIME NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_notif_audience` (`audience`,`is_read`,`created_at`),
  KEY `ix_notif_user` (`user_id`,`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notif_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 22. AUDIT LOGS  — who changed what, immutable by convention
-- =====================================================================
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_type`  ENUM('admin','user','system','cron') NOT NULL DEFAULT 'system',
  `actor_id`    BIGINT UNSIGNED NULL,
  `actor_name`  VARCHAR(120) NULL,
  `action`      VARCHAR(80) NOT NULL,
  `entity_type` VARCHAR(60) NULL,
  `entity_id`   VARCHAR(60) NULL,
  `old_value`   LONGTEXT NULL,
  `new_value`   LONGTEXT NULL,
  `detail`      VARCHAR(500) NULL,
  `ip_address`  VARCHAR(45) NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_audit_actor` (`actor_type`,`actor_id`),
  KEY `ix_audit_entity` (`entity_type`,`entity_id`),
  KEY `ix_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 23. APPLICATION LOGS  — errors, warnings, integration failures
-- =====================================================================
DROP TABLE IF EXISTS `app_logs`;
CREATE TABLE `app_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `level`      ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
  `channel`    VARCHAR(40) NOT NULL DEFAULT 'app',
  `message`    TEXT NOT NULL,
  `context`    LONGTEXT NULL,
  `file`       VARCHAR(255) NULL,
  `line`       INT UNSIGNED NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_logs_level` (`level`,`created_at`),
  KEY `ix_logs_channel` (`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Outbound message log — every SMS / WhatsApp send (success AND failure),
-- the "Store SMS logs" requirement. Kept separate from app_logs so it is a
-- clean per-message record (recipient, body, provider SID, status, error).
DROP TABLE IF EXISTS `message_logs`;
CREATE TABLE `message_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`   BIGINT UNSIGNED NULL COMMENT 'related booking, if any (OTP sends have none)',
  `channel`      ENUM('sms','whatsapp','email') NOT NULL DEFAULT 'sms',
  `provider`     VARCHAR(40)  NOT NULL DEFAULT '',
  `to_number`    VARCHAR(32)  NOT NULL DEFAULT '',
  `body`         VARCHAR(1000) NULL,
  `status`       ENUM('sent','failed','skipped','queued') NOT NULL DEFAULT 'sent',
  `provider_ref` VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'provider message SID',
  `error`        VARCHAR(255) NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_msg_created` (`created_at`),
  KEY `ix_msg_channel` (`channel`,`status`),
  KEY `ix_msg_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 24. FEEDBACK + SUPPORT
-- =====================================================================
DROP TABLE IF EXISTS `feedback`;
CREATE TABLE `feedback` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`  BIGINT UNSIGNED NULL,
  `user_phone`  VARCHAR(20) NULL,
  `name`        VARCHAR(120) NULL,
  `rating`      TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `comfort`     TINYINT UNSIGNED NULL,
  `punctuality` TINYINT UNSIGNED NULL,
  `staff`       TINYINT UNSIGNED NULL,
  `comment`     TEXT NULL,
  `is_public`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_feedback_booking` (`booking_id`),
  KEY `ix_feedback_rating` (`rating`),
  CONSTRAINT `fk_feedback_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `support_tickets`;
CREATE TABLE `support_tickets` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_ref`  VARCHAR(30) NOT NULL,
  `booking_id`  BIGINT UNSIGNED NULL,
  `name`        VARCHAR(120) NOT NULL,
  `phone`       VARCHAR(20) NOT NULL,
  `email`       VARCHAR(191) NULL,
  `subject`     VARCHAR(191) NOT NULL,
  `message`     TEXT NOT NULL,
  `category`    VARCHAR(60) NULL,
  `priority`    ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `status`      ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `assigned_to` BIGINT UNSIGNED NULL,
  `resolved_at` DATETIME NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_support_ref` (`ticket_ref`),
  KEY `ix_support_status` (`status`,`priority`),
  KEY `ix_support_phone` (`phone`),
  CONSTRAINT `fk_support_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_support_admin` FOREIGN KEY (`assigned_to`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `support_messages`;
CREATE TABLE `support_messages` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   BIGINT UNSIGNED NOT NULL,
  `sender_type` ENUM('customer','admin') NOT NULL,
  `sender_name` VARCHAR(120) NULL,
  `message`     TEXT NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_supmsg_ticket` (`ticket_id`,`created_at`),
  CONSTRAINT `fk_supmsg_ticket` FOREIGN KEY (`ticket_id`)
    REFERENCES `support_tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 25. WAITLIST
-- =====================================================================
DROP TABLE IF EXISTS `waitlist`;
CREATE TABLE `waitlist` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_id`     BIGINT UNSIGNED NOT NULL,
  `travel_date`  DATE NOT NULL,
  `name`         VARCHAR(120) NOT NULL,
  `phone`        VARCHAR(20) NOT NULL,
  `seats_wanted` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `status`       ENUM('waiting','notified','converted','expired') NOT NULL DEFAULT 'waiting',
  `notified_at`  DATETIME NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_waitlist_lookup` (`route_id`,`travel_date`,`status`),
  KEY `ix_waitlist_phone` (`phone`),
  CONSTRAINT `fk_waitlist_route` FOREIGN KEY (`route_id`)
    REFERENCES `routes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 26. LIVE TRIPS + GPS BREADCRUMBS  (Driver PWA / trip companion)
-- =====================================================================
DROP TABLE IF EXISTS `live_trips`;
CREATE TABLE `live_trips` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trip_ref`      VARCHAR(40) NOT NULL,
  `schedule_id`   BIGINT UNSIGNED NULL,
  `route_id`      BIGINT UNSIGNED NOT NULL,
  `driver_id`     BIGINT UNSIGNED NULL,
  `bus_id`        BIGINT UNSIGNED NULL,
  `departure_at`  DATETIME NOT NULL,
  `status`        ENUM('pending','running','paused','completed','cancelled') NOT NULL DEFAULT 'pending',
  `current_stop`  VARCHAR(191) NULL,
  `last_lat`      DECIMAL(10,7) NULL,
  `last_lng`      DECIMAL(10,7) NULL,
  `last_bearing`  SMALLINT NULL,
  `last_speed`    DECIMAL(6,2) NULL,
  `last_ping_at`  DATETIME NULL,
  `share_token`   VARCHAR(64) NULL COMMENT 'public tracking link token',
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trip_ref` (`trip_ref`),
  KEY `ix_trips_status` (`status`,`departure_at`),
  KEY `ix_trips_token` (`share_token`),
  CONSTRAINT `fk_trips_route` FOREIGN KEY (`route_id`)
    REFERENCES `routes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_trips_sched` FOREIGN KEY (`schedule_id`)
    REFERENCES `schedules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_trips_driver` FOREIGN KEY (`driver_id`)
    REFERENCES `drivers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_trips_bus` FOREIGN KEY (`bus_id`)
    REFERENCES `buses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `trip_locations`;
CREATE TABLE `trip_locations` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trip_id`    BIGINT UNSIGNED NOT NULL,
  `latitude`   DECIMAL(10,7) NOT NULL,
  `longitude`  DECIMAL(10,7) NOT NULL,
  `bearing`    SMALLINT NULL,
  `speed_kmh`  DECIMAL(6,2) NULL,
  `accuracy_m` DECIMAL(8,2) NULL,
  `recorded_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_triploc_trip` (`trip_id`,`recorded_at`),
  CONSTRAINT `fk_triploc_trip` FOREIGN KEY (`trip_id`)
    REFERENCES `live_trips` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 27. TRIP EXPENSES + DELAYS  (live ops / profitability)
-- =====================================================================
DROP TABLE IF EXISTS `trip_expenses`;
CREATE TABLE `trip_expenses` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_id`     BIGINT UNSIGNED NOT NULL,
  `expense_date` DATE NOT NULL,
  `diesel`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `toll`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `driver_pay`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `misc`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `note`         VARCHAR(255) NULL,
  `created_by`   BIGINT UNSIGNED NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_expense_route_date` (`route_id`,`expense_date`),
  CONSTRAINT `fk_expense_route` FOREIGN KEY (`route_id`)
    REFERENCES `routes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 28. RATE LIMITS  — brute force + abuse protection
-- =====================================================================
DROP TABLE IF EXISTS `rate_limits`;
CREATE TABLE `rate_limits` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket`      VARCHAR(80) NOT NULL COMMENT 'action name',
  `identifier`  VARCHAR(191) NOT NULL COMMENT 'ip or phone',
  `hits`        INT UNSIGNED NOT NULL DEFAULT 1,
  `window_start` DATETIME NOT NULL,
  `blocked_until` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rate_bucket` (`bucket`,`identifier`),
  KEY `ix_rate_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 29. BACKUPS  — registry of generated database dumps
-- =====================================================================
DROP TABLE IF EXISTS `backups`;
CREATE TABLE `backups` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename`    VARCHAR(191) NOT NULL,
  `file_size`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `table_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `row_count`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `trigger_type` ENUM('manual','cron') NOT NULL DEFAULT 'manual',
  `created_by`  BIGINT UNSIGNED NULL,
  `status`      ENUM('success','failed') NOT NULL DEFAULT 'success',
  `note`        VARCHAR(255) NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_backups_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 30. CONTACT MESSAGES  (website contact form)
-- =====================================================================
DROP TABLE IF EXISTS `messages`;
CREATE TABLE `messages` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL,
  `phone`      VARCHAR(20) NULL,
  `email`      VARCHAR(191) NULL,
  `subject`    VARCHAR(191) NULL,
  `body`       TEXT NOT NULL,
  `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_messages_read` (`is_read`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  kv_store — server-side key/value bridge for the front-end app.
--
--  The single-file UI persists its working data through window.storage;
--  this table is the server backing for it. Writes to sensitive/global
--  keys are gated by api/kv.php (admin session required). Public
--  operational data (routes, settings) is world-readable so the site
--  renders for anonymous visitors.
-- =====================================================================
CREATE TABLE `kv_store` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kscope`     VARCHAR(64)  NOT NULL DEFAULT 'global' COMMENT 'global or user:<id>',
  `kkey`       VARCHAR(191) NOT NULL,
  `kvalue`     LONGTEXT     NULL,
  `updated_by` VARCHAR(120) NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kv` (`kscope`,`kkey`),
  KEY `ix_kv_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  enquiries — homepage "Quick Booking" leads (no OTP, save-only).
--
--  The redesigned homepage opens onto a passenger card; "Next →" saves
--  the intent here and the visitor continues into the search flow.
--  Staff follow up from the admin panel ("Enquiries").
-- =====================================================================
DROP TABLE IF EXISTS `enquiries`;
CREATE TABLE `enquiries` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(120) NOT NULL,
  `phone`        VARCHAR(20)  NOT NULL,
  `gender`       ENUM('Male','Female','Other') NULL,
  `nationality`  VARCHAR(60)  NULL,
  `age`          TINYINT UNSIGNED NULL,
  `travel_date`  DATE NULL,
  `seats`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `note`         VARCHAR(255) NULL,
  `source`       VARCHAR(40)  NOT NULL DEFAULT 'quick_booking' COMMENT 'which card/form captured the lead',
  `status`       ENUM('new','contacted','converted','closed') NOT NULL DEFAULT 'new',
  `ip`           VARCHAR(45)  NULL,
  `user_agent`   VARCHAR(255) NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_enquiries_created` (`created_at`),
  KEY `ix_enquiries_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 33. SCHEDULE UNIT LOCKS  — gender-aware shared-cabin state (Feature A)
--     One row per (schedule, physical cabin). It is the row the booking
--     transaction takes FOR UPDATE so two checkouts racing into the same
--     shared cabin are serialised, and it caches the cabin's gender lock:
--       none | female_only | male_only | mixed_allowed (whole-cabin group)
--     A "unit" defaults to two adjacent berths (L1+L2 = L-1); admins can
--     re-map it per bus from the seat-map editor.
-- =====================================================================
DROP TABLE IF EXISTS `schedule_unit_locks`;
CREATE TABLE `schedule_unit_locks` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_id`       BIGINT UNSIGNED NOT NULL,
  `unit_key`          VARCHAR(20) NOT NULL COMMENT 'physical cabin key e.g. L-1 (berths L1+L2)',
  `gender_lock`       ENUM('none','female_only','male_only','mixed_allowed') NOT NULL DEFAULT 'none',
  `locked_by_booking` BIGINT UNSIGNED NULL COMMENT 'booking that group-booked the whole cabin (mixed_allowed)',
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sched_unit` (`schedule_id`,`unit_key`),
  KEY `ix_sul_lock` (`gender_lock`),
  CONSTRAINT `fk_sul_sched` FOREIGN KEY (`schedule_id`)
    REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 34. SEAT BLOCKS  — admin "out of service" berths (Feature C)
--     A berth taken out of sale (broken / reserved) without a booking
--     record. Counted as unavailable everywhere, so it can never be sold
--     online or at the counter until an admin unblocks it.
-- =====================================================================
DROP TABLE IF EXISTS `seat_blocks`;
CREATE TABLE `seat_blocks` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_id` BIGINT UNSIGNED NOT NULL,
  `seat_no`     VARCHAR(10) NOT NULL,
  `reason`      VARCHAR(191) NULL,
  `blocked_by`  BIGINT UNSIGNED NULL COMMENT 'admins.id who blocked it',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_block_seat` (`schedule_id`,`seat_no`),
  KEY `ix_block_sched` (`schedule_id`),
  CONSTRAINT `fk_block_sched` FOREIGN KEY (`schedule_id`)
    REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 35. AUTOMATION LOG  — EventBus journal + exactly-once claim ledger
--     Every emitted lifecycle event lands here (slim payload), and the
--     cron alerts/digests claim their one-shot sends against the UNIQUE
--     dedupe_key with INSERT IGNORE (same pattern as trip_events).
-- =====================================================================
DROP TABLE IF EXISTS `automation_log`;
CREATE TABLE `automation_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event`      VARCHAR(50)  NOT NULL,
  `dedupe_key` VARCHAR(120) NULL COMMENT 'exactly-once claims: low-seat:<sid>, agent-summary:<date>, ...',
  `data`       LONGTEXT     NULL COMMENT 'slim JSON payload (ids + scalars)',
  `status`     ENUM('ok','fail') NOT NULL DEFAULT 'ok',
  `error`      VARCHAR(500) NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_automation_dedupe` (`dedupe_key`),
  KEY `idx_event_date` (`event`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  END OF SCHEMA — 35 tables
--  Next step: import seed.sql for routes, settings and the admin login.
-- =====================================================================
