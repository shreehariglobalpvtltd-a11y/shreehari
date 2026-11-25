-- upgrade-2026-11-25-mp1-ticket.sql — the CLEAN ticket + the cash / country pay-QR rule (mp1 "tk", 25 Nov 2026).
-- Idempotent: INSERT IGNORE on the settings primary key, nothing else. Every key has the
-- SAME default in code (Settings::getBool(..., true)), so tickets behave the same before
-- and after this file runs; the rows only make the switches visible on Admin -> Settings
-- (Tickets tab) for the owner to turn OFF. No new table.

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
('ticket_clean_v2_on', '1', 'bool', 'tickets',
 'सफा टिकट · Clean ticket layout (one big flag by direction, big From -> To, pickup QR + phone, PAID stamp, Booked by). Off = the old layout', 0),
('ticket_cash_no_payqr', '1', 'bool', 'tickets',
 'भुक्तानी भएको टिकटमा QR छैन · A fully paid ticket (cash or online) shows NO payment QR anywhere - only PAID (cash: नगद प्राप्त भयो / Cash received)', 0),
('ticket_pay_qr_by_country', '1', 'bool', 'tickets',
 'देश अनुसार भुक्तानी QR · While money is due: a trip from the Nepal side / Nepal sale gets the Nepal eSewa QR (रू), India gets UPI (₹). Needs the Nepal QR uploaded on Owner setup; off = UPI unless the sale is in NPR', 0);
