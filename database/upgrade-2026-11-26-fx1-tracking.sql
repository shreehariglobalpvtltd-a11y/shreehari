-- 26 Nov 2026 (fx1, live tracking): three switches, no table change.
-- Additive and safe to run twice (INSERT IGNORE keeps a value the office already set).
--
--   track_stale_sec        A screen that shows the bus (track.php, the "My bus" card in SHARI NAV,
--                          the office Live Map) stops calling it LIVE when ITS OWN connection has
--                          brought no fresh answer for this many seconds: the coach turns grey and
--                          the page says "last seen ... - not live". 0 = off (screens as before).
--   driver_net_timeout_sec Driver web app (driver/): a request stuck on a weak network is given up
--                          after this many seconds, so the next GPS upload and an SOS are not kept
--                          waiting behind it. 0 = no timeout (as before).
--   driver_clock_fix_on    Driver web app: when the phone's clock is wrong by more than 2.5 minutes,
--                          the page sends each GPS fix with its real time (the answers of
--                          api/driver.php carry the server's clock; the page measures the phone's
--                          clock against it). 0 = the phone's own times as sent (as before).
--                          The native Android app does not read that field and is not touched.

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('track_stale_sec',        '60', 'int',  'gps',      'Where-is-my-bus page, My bus card and office Live Map: after this many seconds without a fresh answer the screen says "not live" (0 = off)', 0),
  ('driver_net_timeout_sec', '20', 'int',  'gps',      'Driver app: give up a stuck request after this many seconds and try again (0 = no timeout)', 0),
  ('driver_clock_fix_on',    '1',  'bool', 'gps',      'Driver app: correct GPS times when the phone clock is wrong (0 = take the phone times as sent)', 0);
