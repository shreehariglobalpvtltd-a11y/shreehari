-- =====================================================================
--  Upgrade: round-trip gate (2026-09-17). Run ONCE on an existing live
--  database; safe to re-run (INSERT IGNORE).
--
--  Adds round_trip_on (default 0 = off). The customer app offers the
--  "Round trip" pill only when this is on. Today the booking engine sells
--  ONE leg per ticket (api/book.php builds no returnLeg), so a round trip
--  chosen in the app would silently drop its return; until the engine
--  books both legs the app hides the pill and, on the confirmed ticket,
--  offers "Book return journey" (a second search with from/to swapped).
--  is_public=1 — the browser reads it as SHG_BOOT.settings.round_trip_on
--  (Settings::publicSettings()) and mirrors it into CONFIG.roundTripOn.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO settings (skey,svalue,stype,sgroup,label,is_public)
VALUES ('round_trip_on','0','bool','booking',
        'Offer the Round trip option in the app (return leg is booked separately until the engine sells it)',1);
