-- =====================================================================
--  Upgrade 2026-10-04 — Counter desks as data, the sale frozen on the
--  booking, NPR recorded beside the rupee, and the company (entity) a
--  sale belongs to.
--
--  Run ONCE on the live database, then the PHP step that copies the desk
--  names the office already typed into Settings:
--      php tests/apply-sql.php database/upgrade-2026-10-04-counter-desks.sql
--      php database/upgrade-2026-10-04-counter-desks.php
--
--  Fully idempotent: CREATE TABLE IF NOT EXISTS, every ADD COLUMN guarded
--  against information_schema, every seed an INSERT IGNORE / conditional
--  UPDATE. Additive only — no existing column or row is changed, so the
--  code may be deployed before OR after this file.
--
--  Ported (by reading) from the unmerged release/finish-20260927 line:
--  upgrade-2026-09-counter-desks.sql + upgrade-2026-09-26-desk-direction.sql,
--  with four more desk facts (map_url, hours, lang, entity) and the entity
--  stamp on the sale.
--
--  WHY THE CODE IS STAMPED ON THE BOOKING
--  bookings.sold_by_admin_id says WHO is credited; for a clerk's sale that
--  is re-pointed to an agent right after the sale, and the desk was only
--  ever read back through admin_profiles — today's desk for that person.
--  counter_code freezes the place at the moment of sale. Old rows stay NULL
--  and fall back to the seller profile exactly as before.
--
--  WHY THE MONEY IS TWO NUMBERS
--  INR stays canonical everywhere (fares, refunds, commission, ledgers).
--  The local money is recorded ALONGSIDE it: what the customer was quoted
--  (bookings.fx_*) and what the drawer received (payments.local_*), each
--  with the rate used at that second.
--
--  NB: no semicolon inside any quoted ALTER string — tests/apply-sql.php
--  splits this file on the statement terminator.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. The desks themselves
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `counter_locations` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(16)  NOT NULL COMMENT 'short code a clerk reads out - NPJ, SRT, RJT',
  `name`       VARCHAR(120) NOT NULL COMMENT 'Nepalgunj - Pushpalal Chowk',
  `country`    CHAR(2)      NOT NULL DEFAULT 'IN' COMMENT 'IN or NP - where the desk physically stands',
  `currency`   CHAR(3)      NOT NULL DEFAULT 'INR' COMMENT 'money this desk collects in - INR or NPR',
  `fx_rate`    DECIMAL(10,4) NULL COMMENT 'local units per 1 INR for this desk - NULL means use the npr_per_inr setting',
  `allowed_methods` VARCHAR(80) NULL COMMENT 'comma list of cash,upi,esewa,bank this desk may take - NULL means all',
  `default_direction` VARCHAR(10) NULL COMMENT 'toNepal | toIndia - the run this desk opens on. NULL = both',
  `default_from` VARCHAR(80) NULL COMMENT 'the town this desk passengers board at - prefills the counter search, never a new stop',
  `entity`     CHAR(2)      NOT NULL DEFAULT 'IN' COMMENT 'IN or NP - the COMPANY whose ticket this desk issues',
  `lang`       VARCHAR(5)   NULL COMMENT 'language the desk screen opens in - ne, hi, en, gu. NULL = site default',
  `phone`      VARCHAR(40)  NULL COMMENT 'printed on the ticket and offered to the customer',
  `address`    VARCHAR(190) NULL,
  `map_url`    VARCHAR(255) NULL COMMENT 'maps link shown on the desk card',
  `hours`      VARCHAR(120) NULL COMMENT 'opening hours as the office writes them, local time',
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 hides it from new assignments - never deleted, so old tickets keep their place',
  `sort_order` INT          NOT NULL DEFAULT 0,
  `note`       VARCHAR(255) NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_counter_code` (`code`),
  KEY `ix_counter_active` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The seeded network. Rupaidiha is the INDIAN side of the border: it sells
-- the return run (passengers board there) but collects INR for the India
-- company. Nepalgunj, Dhamboji and Kohalpur are Nepal: NPR, Nepal company,
-- Nepali screen. Phone / address / hours stay NULL until the owner gives
-- them — a blank is never printed. Names are overwritten from the office's
-- own Settings list by the PHP step.
INSERT IGNORE INTO `counter_locations`
  (`code`,`name`,`country`,`currency`,`entity`,`lang`,`allowed_methods`,`default_direction`,`default_from`,`sort_order`) VALUES
 ('MSA','Mehsana — Head Office','IN','INR','IN',NULL,NULL,NULL,NULL,10),
 ('AMD','Ahmedabad — Paldi','IN','INR','IN',NULL,NULL,NULL,NULL,20),
 ('BRD','Baroda','IN','INR','IN',NULL,NULL,NULL,NULL,30),
 ('SRT','Surat','IN','INR','IN',NULL,NULL,NULL,NULL,40),
 ('RJT','Rajkot','IN','INR','IN',NULL,NULL,NULL,NULL,50),
 ('GDH','Godhra','IN','INR','IN',NULL,NULL,NULL,NULL,60),
 ('HMT','Himatnagar','IN','INR','IN',NULL,NULL,NULL,NULL,70),
 ('RPD','Rupaidiha — India/Nepal Border','IN','INR','IN',NULL,NULL,'toIndia','Rupaidiha',80),
 ('NPJ','Nepalgunj — Pushpalal Chowk','NP','NPR','NP','ne','cash,esewa','toIndia','Rupaidiha',90),
 ('NPJD','Nepalgunj — Dhamboji Chowk','NP','NPR','NP','ne','cash,esewa','toIndia','Rupaidiha',100),
 ('KHL','Kohalpur Chowk','NP','NPR','NP','ne','cash,esewa','toIndia','Rupaidiha',110);

-- ---------------------------------------------------------------------
-- 2. The desk and the company, frozen on the sale
-- ---------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'counter_code');
SET @s := IF(@c = 0,
  'ALTER TABLE `bookings` ADD COLUMN `counter_code` VARCHAR(16) NULL COMMENT ''desk this was sold at, frozen at the moment of sale - NULL falls back to the seller profile'' AFTER `sold_by_admin_id`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'entity');
SET @s := IF(@c = 0,
  'ALTER TABLE `bookings` ADD COLUMN `entity` CHAR(2) NULL COMMENT ''IN or NP - the company that issued this ticket, frozen at sale. NULL = India company (every row before 4 Oct 2026)'' AFTER `counter_code`',
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
  'ALTER TABLE `bookings` ADD COLUMN `fx_currency` CHAR(3) NULL COMMENT ''money the customer was quoted at the desk - NPR at a Nepal desk, NULL when the sale was plain INR'' AFTER `currency`, ADD COLUMN `fx_rate` DECIMAL(10,4) NULL COMMENT ''local units per 1 INR used for THIS sale - frozen'' AFTER `fx_currency`, ADD COLUMN `fx_total` DECIMAL(12,2) NULL COMMENT ''total_amount expressed in fx_currency - what the ticket printed'' AFTER `fx_rate`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 4. What the drawer actually received
-- ---------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'local_currency');
SET @s := IF(@c = 0,
  'ALTER TABLE `payments` ADD COLUMN `local_currency` CHAR(3) NULL COMMENT ''money physically taken - NPR at a Nepal desk. amount stays the INR the books run on'' AFTER `currency`, ADD COLUMN `local_amount` DECIMAL(12,2) NULL COMMENT ''cash counted in local_currency'' AFTER `local_currency`, ADD COLUMN `fx_rate` DECIMAL(10,4) NULL COMMENT ''local units per 1 INR used when this money was taken'' AFTER `local_amount`, ADD COLUMN `entity` CHAR(2) NULL COMMENT ''IN or NP - the company that received this money. NULL = India company'' AFTER `fx_rate`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 5. A drawer belongs to a desk, and counts its own notes
-- ---------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'counter_shifts' AND COLUMN_NAME = 'counter_code');
SET @s := IF(@c = 0,
  'ALTER TABLE `counter_shifts` ADD COLUMN `counter_code` VARCHAR(16) NULL COMMENT ''desk this drawer belongs to, frozen at open'' AFTER `admin_id`, ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT ''INR'' COMMENT ''money this drawer counts - NPR at a Nepal desk'' AFTER `counter_code`, ADD COLUMN `entity` CHAR(2) NULL COMMENT ''IN or NP - the company whose drawer this is'' AFTER `currency`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 6. Settings rows the desks need
-- ---------------------------------------------------------------------
-- The 24 Sep migration seeded counter_locations with stype 'text', which the
-- settings ENUM does not have. Settings::set() refuses to write such a row.
UPDATE `settings` SET `stype` = 'string'
 WHERE `skey` = 'counter_locations'
   AND `stype` NOT IN ('string','int','float','bool','json');

-- Desk isolation ships OFF: every counter login reads the company register
-- exactly as before until the office switches it on.
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
 ('counter_desk_isolation','0','bool','agent',
  'A counter login sees only its own desk''s ticket register, payments and passenger list. The bus manifest and seat map stay shared.',0);
