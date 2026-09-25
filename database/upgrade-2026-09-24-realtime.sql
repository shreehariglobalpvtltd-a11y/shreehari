-- 24 Sep 2026 — live seat events (Server-Sent Events) — everything OFF by default
--
--   seat_events_on       bool  1 = api/seat-events.php streams "seats" the
--                              moment a booking / hold / block lands on a
--                              departure; the customer, counter and office
--                              seat maps refresh within about a second.
--                              0 = the maps keep polling every 8–15 s. (public:
--                              the app reads it to decide whether to listen)
--   seat_events_seconds  int   how long one stream lives before the browser
--                              reconnects (25). Each open stream holds one
--                              PHP-FPM worker for that long — keep it short.
--
-- Additive, idempotent.
INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
  ('seat_events_on',      '0',  'bool', 'realtime', 'Live seat events (push, ~1 s)',        1),
  ('seat_events_seconds', '25', 'int',  'realtime', 'Live seat stream length (seconds)',   0);
