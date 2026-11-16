-- =====================================================================
--  upgrade-2026-11-16b-W4-wa-bot.sql  (W4, 16 Nov 2026 - SHG Network
--  Master Upgrade v2.0, P6-2d / P6-3b / P7-8d)
--
--  Three WhatsApp-bot switches. The code reads every one with the same
--  default it is seeded with here, so nothing behaves differently whether
--  this file has run or not; it only makes the switches visible in
--  Admin -> Settings -> WhatsApp.
--
--    wa_ai_cancel_mode  'request'  a passenger asking the WhatsApp AI to
--                                  cancel only files a REQUEST to the office
--                                  (reason + quoted refund); the owner
--                                  cancels and settles any refund by hand.
--                                  'self' = the AI cancels the ticket itself
--                                  (the refund is still settled by hand).
--    wa_handoff_msg_on  0          inbox "Pause bot" offers a "Tell the
--                                  customer" tick: ONE fixed line, free, only
--                                  inside their 24 h window.
--    wa_typing_on       0          blue ticks + "typing..." while the bot
--                                  thinks (Cloud API read + typing indicator).
--
--  Additive and safe to re-run: INSERT IGNORE keeps any value already stored.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('wa_ai_cancel_mode', 'request', 'string', 'whatsapp', 'WhatsApp AI, passenger cancel: request = only a request to the office (cancel + refund by hand); self = the AI cancels the ticket itself', 0),
  ('wa_handoff_msg_on', '0', 'bool', 'whatsapp', 'Inbox Pause bot: offer a Tell the customer tick - one fixed line (a person from the office will help now), free, in the 24 h window only', 0),
  ('wa_typing_on', '0', 'bool', 'whatsapp', 'Show the passenger blue ticks + typing... while the WhatsApp bot thinks (Cloud API read + typing indicator)', 0);
