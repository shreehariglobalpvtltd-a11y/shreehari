-- =====================================================================
--  upgrade-2026-11-16b-W3-ai-staff.sql  (Master v2, unit W3, 16 Nov 2026)
--
--  The three S Hari AI switches of this unit, so they show in
--  Admin -> Settings (group "ai") as well as in Easy Settings. Every one
--  is at the value the code already uses without the row, so NOTHING
--  behaves differently after this file runs:
--
--    ai_change_wa_on     0      after a "Yes" on an AI change, WhatsApp the
--                               office one line "🤖 <who> changed: …"
--    ai_settings_engine  local  'cloud' = ask the cloud brain before the
--                               VPS model when reading a settings sentence
--    ai_trip_delay_on    0      "bholi jane bus 2 ghanta late" -> preview the
--                               bus + passenger count -> "Yes" runs the
--                               trips board's Delay (passengers messaged)
--
--  auto_arrive_hours has always been read by cron/lifecycle.php (default 0
--  = off) but live never had the row; it is added at 0 for the same reason.
--
--  The AI Watch "Later / Ignore" choices (ai_watch_state) need no row: the
--  first tap writes it.
--
--  Additive and safe to re-run: INSERT IGNORE keeps any value already
--  stored. Code that runs before this file reads the same defaults.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('ai_change_wa_on',    '0',     'bool',   'ai',      'S Hari AI: after an AI settings change is applied, WhatsApp the office (admin_whatsapp) one line: who changed what', 0),
  ('ai_settings_engine', 'local', 'string', 'ai',      'S Hari AI settings engine: local = the VPS model first (slow, free); cloud = the cloud brain first (1-2 s, small cost)', 0),
  ('ai_trip_delay_on',   '0',     'bool',   'ai',      'S Hari AI may send "bus late" to one bus''s passengers: office types e.g. "bholi jane bus 2 ghanta late", sees the bus and passenger count, then Yes', 0),
  ('auto_arrive_hours',  '0',     'int',    'booking', 'Mark a departed bus Arrived this many hours after it left (0 = off; Arrived messages every passenger)', 0);
