-- =====================================================================
--  upgrade-2026-11-15-desk-tools.sql  (P4 desk tools, 15 Nov 2026)
--
--  Two office switches for the booking page's "Email / SMS" resend, both
--  OFF. The code reads the same defaults (DeskTools::emailResendOn /
--  smsResendOn), so a server without these rows behaves exactly the same;
--  the rows only make the switches appear in Admin -> Settings ->
--  Notifications. SMS also needs the existing sms_enabled switch.
--
--  Additive and safe to re-run (INSERT IGNORE never overwrites a value
--  the office already set). No table or column changes.
-- =====================================================================

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('desk_resend_email_on', '0', 'bool', 'notify', 'Booking page: "Email ma pathau" button sends the ticket again by email', 0),
  ('desk_resend_sms_on',   '0', 'bool', 'notify', 'Booking page: "SMS pathau" button sends the ticket link by SMS (also needs sms_enabled)', 0);
