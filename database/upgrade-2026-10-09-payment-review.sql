-- =====================================================================
--  9 Oct 2026 - Payment review + anti-fake (owner: "ticket fake badi hun
--  layo, approval system easy ra kam risk banaunu paryo")
--
--  includes/paymentrisk.php (PaymentRisk / TicketLive), admin/payments.php,
--  admin/booking-view.php (risk panel), admin/scan.php, api/ticket-live.php,
--  api/report-fake.php.
--
--  payment_reviews  one row per decision on a payment (first approval of a
--                   two-person check, approval, rejection, hold - ask for a
--                   clearer proof) with the risk the reviewer saw at that
--                   moment. Nothing here confirms, rejects, cancels or
--                   refunds anything by itself - a person always decides.
--  payment_screenshots.phash      64-bit difference hash of the image
--  payment_screenshots.edit_tool  photo-editor name found in the file
--                   (Photoshop, PicsArt ...) - a genuine phone screenshot
--                   carries none
--  payments.claimed_amount        what the customer says they paid
--  bookings ix_bookings_ip        the same-device velocity check
--
--  Every statement is guarded, re-running this file is harmless.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `payment_reviews` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`   BIGINT UNSIGNED NOT NULL,
  `payment_id`   BIGINT UNSIGNED NULL DEFAULT NULL,
  `step`         ENUM('approve1','approve','reject','hold','escalate') NOT NULL,
  `risk_score`   SMALLINT NOT NULL DEFAULT 0,
  `risk_level`   VARCHAR(8) NOT NULL DEFAULT 'low' COMMENT 'low, medium, high, skip',
  `risk_codes`   VARCHAR(255) NULL DEFAULT NULL COMMENT 'comma list of signal codes seen at decision time',
  `reason_code`  VARCHAR(40) NULL DEFAULT NULL,
  `note`         VARCHAR(255) NULL DEFAULT NULL,
  `amount_seen`  DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'amount the reviewer read on the proof or bank statement',
  `bank_checked` TINYINT(1) NOT NULL DEFAULT 0,
  `via`          VARCHAR(16) NULL DEFAULT NULL COMMENT 'queue, booking, bulk, whatsapp',
  `admin_id`     BIGINT UNSIGNED NULL DEFAULT NULL,
  `admin_role`   VARCHAR(30) NULL DEFAULT NULL,
  `ip`           VARCHAR(45) NULL DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_payrev_booking` (`booking_id`, `created_at`),
  KEY `ix_payrev_step` (`step`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `payment_screenshots` ADD COLUMN IF NOT EXISTS `phash` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT '64-bit difference hash, compared by BIT_COUNT';
ALTER TABLE `payment_screenshots` ADD COLUMN IF NOT EXISTS `edit_tool` VARCHAR(40) NULL DEFAULT NULL COMMENT 'photo editor named inside the file, if any';
CREATE INDEX IF NOT EXISTS `ix_shots_phash` ON `payment_screenshots` (`phash`);

ALTER TABLE `payments` ADD COLUMN IF NOT EXISTS `claimed_amount` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'amount the customer typed on the proof form';

CREATE INDEX IF NOT EXISTS `ix_bookings_ip` ON `bookings` (`ip_address`, `created_at`);

-- Owner switches and weights (Admin -> Settings -> Payment risk). A weight of
-- 0 switches a signal off. Levels: below risk_medium_at = low, from
-- risk_high_at = high. near_shot ships at 0 - a look-alike hash cannot tell
-- two receipts from the same UPI app apart, so it would flag honest people.
INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('pay_two_step_on',          '0',    'bool',   'payment_risk', 'Payment review: a HIGH-risk payment needs a second person (super-admin, manager or accountant) to approve it within 24 h', 0),
 ('risk_high_value',          '5000', 'int',    'payment_risk', 'Payment risk: a booking at or above this amount (Rs) counts as high value', 0),
 ('risk_medium_at',           '20',   'int',    'payment_risk', 'Payment risk: score from which a payment is MEDIUM (needs the bank-statement tick)', 0),
 ('risk_high_at',             '50',   'int',    'payment_risk', 'Payment risk: score from which a payment is HIGH (needs a written reason)', 0),
 ('risk_w_dup_utr',           '50',   'int',    'payment_risk', 'Risk weight: the same UTR on another booking', 0),
 ('risk_w_dup_shot',          '50',   'int',    'payment_risk', 'Risk weight: the very same screenshot file on another booking', 0),
 ('risk_w_near_shot',         '0',    'int',    'payment_risk', 'Risk weight: a look-alike screenshot on another booking (0 = off)', 0),
 ('risk_w_edited_shot',       '25',   'int',    'payment_risk', 'Risk weight: the screenshot names a photo editor (Photoshop, PicsArt ...)', 0),
 ('risk_w_utr_format',        '15',   'int',    'payment_risk', 'Risk weight: the UTR does not look like a real one (UPI = 12 digits)', 0),
 ('risk_w_utr_old',           '15',   'int',    'payment_risk', 'Risk weight: the UPI UTR date code is days older than the booking', 0),
 ('risk_w_amount_mismatch',   '40',   'int',    'payment_risk', 'Risk weight: the amount paid differs from the fare', 0),
 ('risk_w_too_fast',          '15',   'int',    'payment_risk', 'Risk weight: proof within 45 seconds of a WhatsApp booking', 0),
 ('risk_w_qr_never_opened',   '10',   'int',    'payment_risk', 'Risk weight: a WhatsApp booking whose pay QR / link was never opened', 0),
 ('risk_w_reopened',          '20',   'int',    'payment_risk', 'Risk weight: re-opened after a rejection', 0),
 ('risk_w_velocity_phone',    '15',   'int',    'payment_risk', 'Risk weight: 3 or more bookings from one mobile in 24 h', 0),
 ('risk_w_velocity_ip',       '15',   'int',    'payment_risk', 'Risk weight: one device / IP used by 3 or more mobiles in 24 h', 0),
 ('risk_w_new_agent',         '15',   'int',    'payment_risk', 'Risk weight: sold by an agent added in the last 14 days', 0),
 ('risk_w_cancel_flag',       '10',   'int',    'payment_risk', 'Risk weight: the mobile has many cancellations', 0),
 ('risk_w_high_value',        '10',   'int',    'payment_risk', 'Risk weight: high value or 6 or more seats', 0),
 ('risk_w_no_proof',          '20',   'int',    'payment_risk', 'Risk weight: approving with no UTR and no screenshot', 0),
 ('risk_w_bank_signed',       '-40',  'int',    'payment_risk', 'Risk weight: bank-verified ICICI gateway payment (negative = lowers the risk)', 0),
 ('fraud_scan_whatsapp',      '0',    'bool',   'payment_risk', 'Gate scanner: also WhatsApp the office when a fake / cancelled / used ticket is scanned', 0),
 ('fraud_alert_high_risk',    '1',    'bool',   'payment_risk', 'Payment proof: put a panel alert when a HIGH-risk proof arrives', 0),
 ('ticket_live_code_on',      '1',    'bool',   'payment_risk', 'On-screen ticket: show the moving security band with the live 4-digit code', 1);
