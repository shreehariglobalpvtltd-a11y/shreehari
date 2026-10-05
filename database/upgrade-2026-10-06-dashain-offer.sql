-- =====================================================================
--  6 Oct 2026 - Dashain-Tihar offer: 10% off when booked 24 hours or
--  more before departure (owner, 6 Oct).
--
--  coupons.min_hours_ahead is read by Fare::couponDiscount() (web quote,
--  web booking, counter desk form, WhatsApp bot QuickTicket). The offer is
--  an auto-apply coupon, so the passenger types nothing. Editable / OFF on
--  Admin -> Offers & Discounts. Re-running this file is harmless.
-- =====================================================================

ALTER TABLE `coupons`
  ADD COLUMN IF NOT EXISTS `min_hours_ahead` SMALLINT UNSIGNED NULL DEFAULT NULL
  COMMENT 'offer only when booked at least N hours before departure' AFTER `auto_apply`;

INSERT IGNORE INTO `coupons`
  (`code`, `title`, `discount_type`, `discount_value`, `max_discount`, `min_amount`, `route_id`,
   `usage_limit`, `used_count`, `per_user_limit`, `valid_from`, `valid_until`, `is_active`, `auto_apply`, `min_hours_ahead`)
VALUES
  ('DASHAIN10', 'Dashain-Tihar offer 10% (booked 24h ahead)', 'percent', 10.00, 2000.00, 0.00, NULL,
   NULL, 0, 0, '2026-10-06', '2026-11-12', 1, 1, 24);

-- The AI marketing line may now mention the offer (MkAutopilot::check allows it).
UPDATE `settings` SET `svalue` = 'दशैं-तिहार अफर: यात्रा भन्दा 24 घण्टा अगाडि बुक गर्दा 10% छुट'
 WHERE `skey` = 'mk_auto_offer' AND (`svalue` IS NULL OR `svalue` = '');

-- The older, never-read advance_offer_* rows: keep them truthful.
UPDATE `settings` SET `svalue` = '10' WHERE `skey` = 'advance_offer_percent';
