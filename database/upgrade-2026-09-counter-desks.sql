-- =====================================================================
--  Upgrade 2026-09 — Counter desks: a real location list, the desk stamped
--  on every sale, and NPR recorded beside the rupee.
--  26 Sep 2026
--
--  Run ONCE on the live database:
--      php tests/apply-sql.php database/upgrade-2026-09-counter-desks.sql
--
--  Fully idempotent: CREATE TABLE IF NOT EXISTS, every ADD COLUMN guarded
--  against information_schema, every seed an INSERT IGNORE. Safe while live.
--
--  OWNER ASK (26 Sep 2026)
--  -----------------------
--   "sabai ko lagi alag alag add garna milos jati pani — sabko hisab kitab
--    admin le herna milos, chalani ma ni chuttinu paryo, har ek thau ma
--    change huna paryo"   -> every desk is its own place with its own books.
--   "NPR ma bech, dubai record"  -> a Nepal desk sells in NPR and BOTH
--    numbers are kept: the rupee the company accounts in, and the NPR the
--    customer actually handed over, with the rate used that day.
--
--  WHY A TABLE NOW
--  ---------------
--  The list has lived since 24 Sep in ONE settings row (counter_locations,
--  "CODE|Name" per line). That was right while a location was only a label
--  printed on a ticket. It cannot carry what a desk now needs — its country,
--  its currency, its own rate, its phone, whether it is still open — and a
--  text box cannot be the key of a per-desk ledger. The settings row is kept
--  in sync by the admin screen as a human-readable mirror, so anything still
--  reading it keeps working.
--
--  WHY THE CODE IS STAMPED ON THE BOOKING
--  --------------------------------------
--  bookings.sold_by_admin_id already says WHO sold; the desk was only ever
--  read back through admin_profiles, which is TODAY's desk for that person.
--  Move a clerk from Surat to Rajkot and every ticket they ever sold moves
--  town with them, and last month's Surat sheet changes. counter_code on the
--  booking freezes the place at the moment of sale. Old rows stay NULL and
--  fall back to the profile, exactly as before.
--
--  WHY THE MONEY IS TWO NUMBERS
--  ----------------------------
--  Fares are quoted, discounted, refunded, commissioned and accounted in INR
--  everywhere in this codebase (includes/fare.php). Changing that for one
--  desk would touch every ledger. So INR stays canonical — amount,
--  total_amount, the agent wallet, the statement — and the local money is
--  recorded ALONGSIDE it: what the customer was told (bookings.fx_*) and what
--  the drawer received (payments.local_*). A Nepalgunj day sheet then adds up
--  in NPR without the company total ever leaving the rupee.
--
--  NB: no semicolon inside any quoted ALTER string — tests/apply-sql.php
--  splits this file on ";" and would cut the statement in half.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. The desks themselves
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `counter_locations` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(16)  NOT NULL COMMENT 'short code a clerk reads out - NPJ, SRT, RJT',
  `name`       VARCHAR(120) NOT NULL COMMENT 'Nepalgunj - Bus Park',
  `country`    CHAR(2)      NOT NULL DEFAULT 'IN' COMMENT 'IN or NP - decides the flag and the default currency',
  `currency`   CHAR(3)      NOT NULL DEFAULT 'INR' COMMENT 'money this desk collects in - INR or NPR',
  `fx_rate`    DECIMAL(10,4) NULL COMMENT 'local units per 1 INR for this desk - NULL means use the npr_per_inr setting',
  `phone`      VARCHAR(40)  NULL COMMENT 'printed on the ticket and offered to the customer',
  `address`    VARCHAR(190) NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 hides it from new assignments - never deleted, so old tickets keep their place',
  `sort_order` INT          NOT NULL DEFAULT 0,
  `note`       VARCHAR(255) NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_counter_code` (`code`),
  KEY `ix_counter_active` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The desks the settings row already carries, with the two facts a text line
-- could not hold, plus Rajkot. Rupaidiha is the INDIAN side of the border, so
-- it stays INR; Nepalgunj and Kohalpur are Nepal and collect NPR.
INSERT IGNORE INTO `counter_locations` (`code`,`name`,`country`,`currency`,`sort_order`) VALUES
 ('MSA','Mehsana — Head Office','IN','INR',10),
 ('AMD','Ahmedabad — Paldi','IN','INR',20),
 ('BRD','Baroda','IN','INR',30),
 ('SRT','Surat','IN','INR',40),
 ('GDH','Godhra','IN','INR',50),
 ('HMT','Himatnagar','IN','INR',60),
 ('RJT','Rajkot','IN','INR',70),
 ('RPD','Rupaidiha — India/Nepal Border','IN','INR',80),
 ('NPJ','Nepalgunj — Bus Park','NP','NPR',90),
 ('NPJD','Nepalgunj — Dhamboji Chowk','NP','NPR',100),
 ('KHL','Kohalpur Chowk','NP','NPR',110);

-- ---------------------------------------------------------------------
-- 2. The desk, frozen on the sale
-- ---------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'counter_code');
SET @s := IF(@c = 0,
  'ALTER TABLE `bookings` ADD COLUMN `counter_code` VARCHAR(16) NULL COMMENT ''desk this was sold at, frozen at the moment of sale - NULL falls back to the seller profile'' AFTER `sold_by_admin_id`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'bookings' AND INDEX_NAME = 'ix_bookings_counter');
SET @s := IF(@c = 0,
  'ALTER TABLE `bookings` ADD KEY `ix_bookings_counter` (`counter_code`,`created_at`)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 3. What the customer was quoted, in their own money
-- ---------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'fx_currency');
SET @s := IF(@c = 0,
  'ALTER TABLE `bookings` ADD COLUMN `fx_currency` CHAR(3) NULL COMMENT ''money the customer was quoted at the desk - NPR at a Nepal desk, NULL when the sale was plain INR'' AFTER `currency`, ADD COLUMN `fx_rate` DECIMAL(10,4) NULL COMMENT ''local units per 1 INR used for THIS sale - frozen, so a later rate change never rewrites an old ticket'' AFTER `fx_currency`, ADD COLUMN `fx_total` DECIMAL(12,2) NULL COMMENT ''total_amount expressed in fx_currency - what the ticket printed'' AFTER `fx_rate`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 4. What the drawer actually received
-- ---------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'local_currency');
SET @s := IF(@c = 0,
  'ALTER TABLE `payments` ADD COLUMN `local_currency` CHAR(3) NULL COMMENT ''money physically taken - NPR at a Nepal desk. amount stays the INR the books run on'' AFTER `currency`, ADD COLUMN `local_amount` DECIMAL(12,2) NULL COMMENT ''cash counted in local_currency'' AFTER `local_currency`, ADD COLUMN `fx_rate` DECIMAL(10,4) NULL COMMENT ''local units per 1 INR used when this money was taken'' AFTER `local_amount`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 5. A drawer belongs to a desk, and counts its own notes
-- ---------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'counter_shifts' AND COLUMN_NAME = 'counter_code');
SET @s := IF(@c = 0,
  'ALTER TABLE `counter_shifts` ADD COLUMN `counter_code` VARCHAR(16) NULL COMMENT ''desk this drawer belongs to, frozen at open'' AFTER `admin_id`, ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT ''INR'' COMMENT ''money this drawer counts - NPR at a Nepal desk'' AFTER `counter_code`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
