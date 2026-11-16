-- =====================================================================
--  upgrade-2026-11-16b-W2-offline.sql  (P2-4e + P2-4g, 16 Nov 2026)
--
--  offline_callback_on (default OFF): with no signal the SmartBook card
--  shows ONE "Call-back request" button in place of Book (name, mobile,
--  date, seats). The request is saved on the phone in the offline queue
--  the Sahayak complaint desk already uses and goes to /api/enquiry.php
--  (source 'callback') when the network returns: the Enquiries inbox + a
--  Help Desk number. It is NOT a ticket - nothing is held, sold or paid;
--  the office phones the passenger. The offline pill also counts what is
--  waiting ("· 2 queued").
--
--  index.php reads the switch with a default of 0, so the code behaves
--  the same before this file runs; the row only makes it visible in
--  Admin -> Settings -> App. Additive and safe to re-run: INSERT IGNORE
--  keeps any value already stored.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('offline_callback_on', '0', 'bool', 'app', 'Offline call-back: with no internet the booking card offers "Call-back request" (name, mobile, date, seats) instead of Book, sent to Enquiries when back online. Not a ticket', 1);
