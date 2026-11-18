-- upgrade-2026-11-19-u3-ticket-qr.sql — ticket QR codes, map pins, NPR (TK-1..TK-6, 19 Nov 2026).
-- Idempotent: INSERT IGNORE on the settings primary key; the UPDATE only re-files
-- ticket_maps_qr under the Tickets tab (its value is never touched).
-- Every key also has the same default in code (Settings::getBool / getString), so the
-- ticket behaves the same before and after this file runs.

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
('ticket_pay_qr_upi', '1', 'bool', 'tickets',
 'भुक्तानी QR मा UPI रकम · Ticket pay QR = UPI with the EXACT due (any UPI app scanner pays it; stays payable on copies). Off = live pay link that closes after approval', 0),
('ticket_verify_qr', '1', 'bool', 'tickets',
 'टिकट QR (जाँच) · Ticket verify QR on the PNG + PDF (the barcode stays). Off = barcode only', 0),
('ticket_npr_primary', '1', 'bool', 'tickets',
 'नेपाली रुपैयाँ मुख्य · A ticket sold in NPR shows NPR as the main amount (INR small beside it)', 0),
('nepal_office_map', '', 'string', 'tickets',
 'नेपाल काउन्टर नक्सा · Nepal office map pin for the ticket QR (Google Maps link or lat,lng; blank = Hotel Darbar, Pushpalal Chowk, Nepalgunj search)', 0),
('india_hub_map', '', 'string', 'tickets',
 'S Hari Parking नक्सा · India hub (S Hari Parking, Nana Chiloda) map pin for the ticket QR (Google Maps link or lat,lng; blank = the stop pin)', 0);
-- sales_close_clock belongs to upgrade-2026-11-19-u4-desk.sql (it seeds the owner's rule);
-- admin/settings.php only files it under Booking and refuses broken JSON.

UPDATE settings
   SET sgroup = 'tickets',
       label  = 'चढ्ने ठाउँ नक्सा QR · Ticket carries a Google Maps QR of the boarding point (and the Nepal office for Nepal-issued / Nepal -> India tickets)'
 WHERE skey = 'ticket_maps_qr' AND sgroup <> 'tickets';
