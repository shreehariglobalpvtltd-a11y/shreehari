-- =====================================================================
--  Upgrade: directional sharing fares become ADMIN-EDITABLE + new rates
--           (V4.0 / V5.0 business rules, 2026-08)
--  Run this ONCE on an existing live database (hPanel -> phpMyAdmin ->
--  select your database -> SQL tab -> paste -> Go).
--
--  WHAT IT CHANGES  (owner-confirmed 25 Aug 2026 — reverted to these rates)
--    Gujarat -> Rupaidiha (toNepal, "jane"/going)   : 2000   (online 1900)
--    Rupaidiha -> Gujarat (toIndia, "aune"/return)  : 1800   (online 1710)
--
--  The 5% online discount is UNCHANGED and still applies to sharing.
--  PRIVATE CABIN PRICING IS DELIBERATELY NOT TOUCHED (stays 3600 / 7000) —
--  private remains the VIP tier.
--
--  WHY THIS MIGRATION EXISTS
--    The `cabin_pricing` setting never contained a `sharingByDir` key, so the
--    direction rates lived only in a PHP fallback and could NOT be edited from
--    Admin -> Settings. This adds the key, so from now on an admin can change
--    both direction fares in one click without touching code.
--
--  Safe to re-run: JSON_SET simply overwrites that one key and leaves every
--  other part of cabin_pricing (sharing tiers, private rates) untouched.
--  Requires MySQL 5.7+ / MariaDB 10.2+ (JSON functions) — standard on Hostinger.
--
--  TO CHANGE THE FARES LATER: Admin -> Settings -> cabin_pricing, edit
--    "sharingByDir":{"toNepal":1800,"toIndia":2000}
--  ROLLBACK (restore the old rates): re-run this file with the two numbers
--  swapped back to toNepal=2000, toIndia=1800.
-- =====================================================================

SET NAMES utf8mb4;

UPDATE `settings`
   SET `svalue` = JSON_SET(
         `svalue`,
         '$.sharingByDir', JSON_OBJECT('toNepal', 2000, 'toIndia', 1800)
       )
 WHERE `skey` = 'cabin_pricing'
   AND JSON_VALID(`svalue`);

-- Clear any legacy `main_fares` override so it can't shadow the rates above
-- (dirFares() lets main_fares win over cabin_pricing.sharingByDir). Setting it
-- to the same values keeps both stores in agreement whether or not the row
-- exists. Safe to re-run.
UPDATE `settings`
   SET `svalue` = JSON_SET(
         `svalue`,
         '$.toNepal', 2000,
         '$.toIndia', 1800
       )
 WHERE `skey` = 'main_fares'
   AND JSON_VALID(`svalue`);
