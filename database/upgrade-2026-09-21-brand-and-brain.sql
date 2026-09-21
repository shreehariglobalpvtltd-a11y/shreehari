-- =====================================================================
--  upgrade-2026-09-21-brand-and-brain.sql
--
--  Two gaps this closes, both found while wiring the WhatsApp assistant
--  on 21 Sep 2026:
--
--  1. THE ASSISTANT SWITCHES DO NOT EXIST. AiAgent::enabled() reads
--     wa_agent_on and defaults it to false. That row was never inserted
--     on production, so the assistant has never run there and no screen
--     could turn it on — the only way in was an INSERT by hand. The
--     three switches are created here with their safe values, so the
--     Settings screen can show them and the office can flip them.
--
--  2. THE BRAND FACTS STOP AT THE VISITING CARD. The bot can quote CIN
--     and the counters, but a customer who asks for our Facebook page,
--     the Nepal-side number, or to be put through to the director has
--     nowhere for the answer to come from — and a model with no fact
--     invents one. Every key below is created EMPTY on purpose: the
--     briefing prints a line only when its value is filled, so an
--     unanswered key stays an honest "we don't publish that" instead of
--     a hallucinated phone number.
--
--  Safe to re-run: every statement is INSERT ... ON DUPLICATE KEY UPDATE
--  that keeps an existing value. Nothing here touches a booking, a seat,
--  a payment or a ticket.
-- =====================================================================

-- ---------------------------------------------------------------------
--  1. WhatsApp assistant switches
-- ---------------------------------------------------------------------
INSERT INTO settings (skey, svalue, stype, sgroup, is_public, label) VALUES
  ('wa_agent_on', '0', 'bool', 'whatsapp', 0,
   'WhatsApp assistant: answer with AI'),
  ('wa_agent_sell', '0', 'bool', 'whatsapp', 0,
   'WhatsApp assistant: may issue tickets'),
  ('wa_agent_oneshot', '0', 'bool', 'whatsapp', 0,
   'WhatsApp assistant: confirm a sale in one message')
ON DUPLICATE KEY UPDATE svalue = svalue;

-- ---------------------------------------------------------------------
--  2. Auto model routing
-- ---------------------------------------------------------------------
INSERT INTO settings (skey, svalue, stype, sgroup, is_public, label) VALUES
  ('ai_route_auto', '1', 'bool', 'ai', 0,
   'Pick the AI model per message (off = always use gemini_model)')
ON DUPLICATE KEY UPDATE svalue = svalue;

-- ---------------------------------------------------------------------
--  3. Brand facts — the visiting card, in full
--     All EMPTY by design. The assistant prints only what is filled.
-- ---------------------------------------------------------------------
INSERT INTO settings (skey, svalue, stype, sgroup, is_public, label) VALUES
  ('company_facebook',  '', 'string', 'company', 1, 'Facebook page URL'),
  ('company_instagram', '', 'string', 'company', 1, 'Instagram URL'),
  ('company_youtube',   '', 'string', 'company', 1, 'YouTube channel URL'),
  ('company_tiktok',    '', 'string', 'company', 1, 'TikTok URL'),
  ('ceo_whatsapp',      '', 'string', 'company', 0,
   'Director WhatsApp — given out only on a real escalation, never on a routine question'),
  ('ceo_facebook',      '', 'string', 'company', 1, 'Director Facebook profile'),
  ('nepal_phone',       '', 'string', 'company', 1,
   'Nepal-side contact number (leave empty until one exists — the assistant will not invent one)'),
  ('nepal_office',      '', 'string', 'company', 1, 'Nepal-side office address')
ON DUPLICATE KEY UPDATE svalue = svalue;

-- ---------------------------------------------------------------------
--  4. Local-first routing (21 Sep 2026)
--     Selling runs on this VPS (WaBooking + TicketBot + QuickTicket) and
--     is tried BEFORE the model, so a ticket never waits on a third
--     party's quota. 0 puts the model back in front.
-- ---------------------------------------------------------------------
INSERT INTO settings (skey, svalue, stype, sgroup, is_public, label) VALUES
  ('wa_local_first', '1', 'bool', 'whatsapp', 0,
   'Cut tickets with the on-VPS engine before asking the AI (recommended)')
ON DUPLICATE KEY UPDATE svalue = svalue;
