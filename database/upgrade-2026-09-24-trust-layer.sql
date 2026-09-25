-- =====================================================================
--  upgrade-2026-09-24-trust-layer.sql — the trust layer (24 Sep 2026)
--
--  Additive, idempotent (INSERT IGNORE). Three switches, all OFF:
--
--   trust_card_on     home page: real numbers from our register
--                     (trips this year, passengers carried, public rating)
--   refund_ladder_on  the refund slabs next to Pay; "where is my refund"
--                     on a cancelled booking in My Bookings
--   women_layer_on    seat map: "N women already on this bus" + the
--                     24×7 helpline (women_helpline, else the office number)
--
--  All four rows are public: the app reads them from SHG_BOOT.settings.
-- =====================================================================

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
  ('trust_card_on',    '0', 'bool',   'trust', 'Home page shows real numbers (trips, passengers, rating)', 1),
  ('refund_ladder_on', '0', 'bool',   'trust', 'Refund slabs next to Pay + refund status in My Bookings', 1),
  ('women_layer_on',   '0', 'bool',   'trust', 'Seat map shows women already on the bus + 24×7 helpline', 1),
  ('women_helpline',   '',  'string', 'trust', 'Women helpline number (blank = office phone)', 1);
