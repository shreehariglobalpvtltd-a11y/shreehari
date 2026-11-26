-- =====================================================================
--  upgrade-2026-11-26-fx1-aiwa.sql  (fx1, 26 Nov 2026 - S Hari AI +
--  WhatsApp gap pass)
--
--  Seven switches. The code reads every one with the same default it is
--  seeded with here, so nothing behaves differently whether this file has
--  run or not; it only makes the switches visible in Admin -> Settings ->
--  WhatsApp, so each change can be rolled back with one click.
--  No table, no column, nothing removed.
--
--    wa_change_words_on        1     "ticket cancel garnu cha", "seat badalnu
--                                    cha", "refund chahiyo" always get an answer
--                                    (how to ask + the office settles it by
--                                    hand); 0 = the old paths (could become a
--                                    new booking draft).
--    wa_human_ask_on           1     "manche sanga kura garnu cha" / "call me"
--                                    opens ONE call-back request in the Help
--                                    Desk and gives the office number; 0 = the
--                                    generic welcome, as before.
--    wa_office_ask_on          1     the owner's questions ("kun agent le kati
--                                    kate?", "pending payment?", "bus 3 kaha
--                                    cha?") are answered before the staff desk;
--                                    0 = every line goes to the desk, as before.
--                                    With wa_ops_stepup_on = 1 a staff number
--                                    gets the money answers only while its panel
--                                    verification is fresh (else the one-time
--                                    link, as the desk's "report"); bus = no gate.
--    wa_welcome_menu_on        1     a bare "hi" gets the order menu (buttons)
--                                    under the welcome; 0 = the welcome alone.
--    wa_date_change_mode       self  self = after the 6-digit code the date is
--                                    changed at once (today's behaviour);
--                                    request = only a request for the office
--                                    (admin/ticket-requests.php).
--    ai_cancel_request_all_on  1     the assistant never cancels a ticket for a
--                                    passenger on ANY channel (website and
--                                    Messenger too): a request to the office;
--                                    0 = the WhatsApp-only rule of 16 Nov.
--                                    Super-admin only.
--    wa_confirm_super_only_on  1     confirming a payment from WhatsApp: the
--                                    super-admin only, always behind a fresh
--                                    step-up link; 0 = any office number, as
--                                    before. Super-admin only. (The tool itself
--                                    stays OFF unless wa_agent_admin_write = 1.)
--
--  Additive and safe to re-run: INSERT IGNORE keeps any value already stored.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('wa_change_words_on', '1', 'bool', 'whatsapp', 'WhatsApp bot: cancel / seat change / refund words always get an answer (how to ask + the office settles it by hand), never a new booking draft', 0),
  ('wa_human_ask_on', '1', 'bool', 'whatsapp', 'WhatsApp bot: "talk to a person" / "call me" opens one call-back request in the Help Desk and gives the office number', 0),
  ('wa_office_ask_on', '1', 'bool', 'whatsapp', 'WhatsApp: the owner''s questions (which agent sold how many, pending payments, where is the bus, revenue, evening report) are answered before the staff desk', 0),
  ('wa_welcome_menu_on', '1', 'bool', 'whatsapp', 'WhatsApp bot: a bare hello gets the order menu (book / my ticket / office) under the welcome', 0),
  ('wa_date_change_mode', 'self', 'string', 'whatsapp', 'WhatsApp date change after the 6-digit code: self = changed at once; request = only a request for the office (Ticket requests)', 0),
  ('ai_cancel_request_all_on', '1', 'bool', 'whatsapp', 'S Hari AI never cancels a ticket for a passenger on any channel (WhatsApp, website, Messenger): a request to the office only. Super-admin only', 0),
  ('wa_confirm_super_only_on', '1', 'bool', 'whatsapp', 'Confirming a payment from WhatsApp: super-admin only, always behind a fresh verification link. Super-admin only', 0);
