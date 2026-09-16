-- =====================================================================
--  Upgrade: counter staff role + refund slabs matched to the Terms
--  (2026-09-05). Run ONCE on an existing live database. Safe to re-run
--  (MODIFY is idempotent; the settings write is INSERT ... ON DUPLICATE).
--
--  1) Adds a 'counter' role to staff accounts — a company ticket window
--     (Mehsana / Ahmedabad / Baroda / Surat + spares, each with its OWN
--     login). Counter staff search/create/edit/reschedule/cancel/reprint
--     bookings and verify payments; they get NO revenue dashboard, NO
--     agent commission ledgers, NO staff/routes/fleet management.
--     Accounts are created from Admin -> Staff & Approvals (superadmin).
--
--  2) Aligns the ENFORCED refund slabs with the slabs the public
--     Terms & Conditions page has promised all along (section C):
--       >=96h -> 90% | 48-96h -> 75% | 24-48h -> 50% | 6-24h -> 25% | <6h -> 0%
--     Until now the setting/default only had the 48/24/0 tiers, so a
--     customer cancelling 5 days early was refunded 75% where the Terms
--     promise 90%. Fare::refundFor() reads this setting everywhere
--     (customer app, admin cancel, bulk cancel) — one calculator.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `admins`
  MODIFY `role` ENUM('superadmin','manager','accountant','support','scanner','official','agent','counter')
  NOT NULL DEFAULT 'support';

-- stype='json' + plain (single-encoded) JSON, per the settings storage rules.
INSERT INTO `settings` (`skey`,`svalue`,`stype`)
VALUES ('refund_slabs',
        '[{"minHrs":96,"pct":90},{"minHrs":48,"pct":75},{"minHrs":24,"pct":50},{"minHrs":6,"pct":25},{"minHrs":0,"pct":0}]',
        'json')
ON DUPLICATE KEY UPDATE `svalue` = VALUES(`svalue`), `stype` = VALUES(`stype`);
