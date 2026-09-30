-- =====================================================================
--  upgrade-2026-09-25-local-brain.sql
--
--  THE BRAIN THAT RUNS ON OUR OWN BOX.
--
--  Owner, 25 Sep 2026: "chatbot lai fully offline ni jati sakdo dherai
--  kaam garna sakne ... VPS ma 8 GB RAM cha, tesma chalne khalko euta
--  model jasto bandiye ... aafai independently".
--
--  Until now AiAgent could only think with somebody else's computer: a
--  key for Claude, Gemini, Grok, OpenRouter or Cerebras. With no key
--  the assistant was simply silent and the widget fell back to its
--  rule engine. That is the state the live site has been in.
--
--  deploy/install-local-brain.sh now puts llama.cpp and a 4B instruct
--  model on this VPS as the systemd unit `shg-brain`, listening on
--  127.0.0.1:8081 and nothing else. It speaks the same OpenAI-shaped
--  API that AiAgent::askOpenAICompat() already speaks, so it needed no
--  new model code — only the rows below.
--
--  MEASURED ON THIS BOX (2 vCPU AMD EPYC 9354P, 8 GB):
--    generation      ~8-12 tokens/sec
--    prompt intake   ~25 tokens/sec
--    resident memory ~3.1 GB with an 8192-token context and q8_0 KV
--    Devanagari Nepali: good.  Romanised Nepali: poor - the assistant
--    is told to answer Nepali in Devanagari for that reason.
--    Tool calling: works (verified with a booking_lookup fixture).
--
--  ai_local_on SHIPS OFF. Turning it on is the owner's decision, and it
--  is the only row here that changes behaviour. Every other row is a
--  tuning knob with a safe default.
--
--  Safe to re-run: INSERT ... ON DUPLICATE KEY UPDATE svalue = svalue
--  keeps whatever is already there. Nothing here touches a booking, a
--  seat, a payment or a ticket.
--
--  NOTE: tests/apply-sql.php splits this file on semicolons, so a
--  semicolon inside a label string silently cuts a statement in half.
--  It bit this file once already. Labels below use a comma or a dash.
-- =====================================================================

-- ---------------------------------------------------------------------
--  1. The switch, the address, the model
-- ---------------------------------------------------------------------
INSERT INTO settings (skey, svalue, stype, sgroup, is_public, label) VALUES
  ('ai_local_on', '0', 'bool', 'ai', 0,
   'Local brain: use the model running on this server (no API key needed)'),
  ('ai_local_url', 'http://127.0.0.1:8081', 'string', 'ai', 0,
   'Local brain: address of the llama.cpp server (loopback only)'),
  ('ai_local_model', 'local', 'string', 'ai', 0,
   'Local brain: model name to send - llama.cpp ignores it, kept for clarity')
ON DUPLICATE KEY UPDATE svalue = svalue;

-- ---------------------------------------------------------------------
--  2. Speed knobs
--
--  A cloud API answers a 700-token reply in a couple of seconds. This
--  one writes about ten tokens a second, so the same settings would
--  leave a passenger watching a spinner for over a minute. Three
--  separate limits keep that from happening:
--
--    ai_local_max_tokens  how long ONE reply may be
--    ai_local_timeout     how long we wait for it (nginx allows 120s)
--    ai_local_turn_budget how long the WHOLE message may take, tools
--                         included - AiAgent's normal budget is 45s,
--                         which is not enough for a local tool round
--    ai_local_max_tools   every tool round is another model call, so
--                         the cloud default of 6 would be minutes
-- ---------------------------------------------------------------------
INSERT INTO settings (skey, svalue, stype, sgroup, is_public, label) VALUES
  ('ai_local_max_tokens', '220', 'int', 'ai', 0,
   'Local brain: longest reply in tokens - 4.2 tokens/sec, so 220 is about 50 seconds'),
  ('ai_local_timeout', '110', 'int', 'ai', 0,
   'Local brain: seconds to wait for one answer (nginx allows 120)'),
  ('ai_local_turn_budget', '100', 'int', 'ai', 0,
   'Local brain: seconds for the whole message, tools included'),
  ('ai_local_max_tools', '3', 'int', 'ai', 0,
   'Local brain: tool rounds per message (each one is another model call)')
ON DUPLICATE KEY UPDATE svalue = svalue;

-- ---------------------------------------------------------------------
--  3. Order of brains
--
--  On by default: our own box is tried FIRST. It costs nothing per
--  message, it works with the internet down, and no passenger question
--  leaves the building. A cloud key, if the owner ever sets one, then
--  becomes the fallback for what the local model cannot finish - rather
--  than the thing every single message is spent on.
-- ---------------------------------------------------------------------
INSERT INTO settings (skey, svalue, stype, sgroup, is_public, label) VALUES
  ('ai_local_first', '1', 'bool', 'ai', 0,
   'Local brain: try it before any cloud key (off = cloud first)')
ON DUPLICATE KEY UPDATE svalue = svalue;

-- ---------------------------------------------------------------------
--  4. Which tools the local brain may hold
--
--  Every tool is a JSON schema the model must READ before each turn.
--  The full customer catalogue is twelve tools and 1561 tokens, which
--  at this box's 12.7 tokens/sec is two minutes of reading before a
--  word is written. These six are what people actually write in about.
--  Cancelling, renaming and date fixes stay with the desk, which is
--  the safer place for a small model to leave a write anyway.
--
--  Empty value = hand it the whole catalogue (for measuring).
-- ---------------------------------------------------------------------
INSERT INTO settings (skey, svalue, stype, sgroup, is_public, label) VALUES
  ('ai_local_tools', 'find_ticket,my_tickets,plan_ticket,payment_info,bus_eta,refund_quote', 'string', 'ai', 0,
   'Local brain: tools it may use - fewer is faster, empty means all')
ON DUPLICATE KEY UPDATE svalue = svalue;
