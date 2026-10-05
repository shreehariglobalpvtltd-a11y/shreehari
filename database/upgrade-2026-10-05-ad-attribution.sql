-- 5 Oct 2026: Google Ads / campaign attribution for online bookings.
-- Additive and idempotent (safe to re-run). See includes/adattribution.php.

CREATE TABLE IF NOT EXISTS booking_ad_source (
  booking_id  INT UNSIGNED NOT NULL PRIMARY KEY,
  src         VARCHAR(48)  NOT NULL,
  campaign    VARCHAR(80)  NULL,
  click_id    VARCHAR(80)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bas_src (src, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('google_ads_conversion',         '', 'string', 'site', 'Google Ads conversion — booking (AW-…/label)', 0),
('google_ads_contact_conversion', '', 'string', 'site', 'Google Ads conversion — call / WhatsApp tap (optional)', 0);
