-- =====================================================================
--  upgrade-2026-11-16d-Y5-agents.sql  (16 Nov 2026, Master v2.0 unit Y5)
--
--  P8-5d  agent_followups_on (bool, 0 = off, the default). While on, the
--         Agent Panel (admin/agent.php) shows "मेरा फलो-अप · My follow-ups":
--         the open SERVICE help-desk tickets on bookings THIS agent sold -
--         number, category, status, due time and the PNR. Never the message
--         or the customer's phone, never a complaint / fake-ticket / staff /
--         safety / payment case (HelpDesk::SELLER_CATEGORIES). Read-only.
--  P8-12e helpdesk_cancel_escalate_inr (int Rs, 0 = off, the default). When
--         a cancelled ticket's total is at or above this AND money is owed
--         back (a refund pending on it - only a verified payment earns one),
--         BookingService::cancel opens one 'refund' help-desk ticket so the
--         office follows the refund up by hand. An unpaid / auto / undo /
--         0%-slab cancel owes nothing and opens nothing. No message goes to
--         the customer (ack off); one open ticket per booking. Refunds stay
--         manual.
--  P8-6c  wa_agent_settle_cod_on (bool, 0 = off, the default). While on, an
--         AGENT may record on WhatsApp that they collected the cash on their
--         OWN pay-at-boarding booking (office_settle_cod: preview, then "ho"
--         in a later message). The cash then shows as cash the agent owes
--         the office (agent_ledger cash_due), same as a desk cash sale.
--
--  Nothing changes after this runs: every switch is stored at its off
--  value. The code works before this runs too (Settings::get* defaults).
--  Additive and safe to re-run: INSERT IGNORE keeps any value stored.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('agent_followups_on', '0', 'bool', 'agent', 'मेरा फलो-अप · Agent follow-ups: the Agent Panel lists open service help-desk tickets on the agent''s own sales (ref, category, status, due, PNR - no message, phone or complaints). Off = hidden', 0),
  ('helpdesk_cancel_escalate_inr', '0', 'int', 'helpdesk', 'रद्द टिकट रिफन्ड फलो-अप · Cancel follow-up: a cancelled ticket at or above this total (Rs) with a refund owed opens a refund help-desk ticket (no customer message). 0 = off', 0),
  ('wa_agent_settle_cod_on', '0', 'bool', 'ai', 'Agent ले WhatsApp मा नगद लियो · Agent records on WhatsApp the cash on their OWN pay-at-boarding sale (preview, then ho); it shows as cash the agent owes the office. Off = office only', 0);
