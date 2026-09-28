-- =====================================================================
--  upgrade-2026-09-wa-advance.sql — the WhatsApp seller, further on.
--  28 Sep 2026.
--
--  The on-VPS booking conversation (includes/wabooking.php +
--  includes/ticketbot.php) already reads a messy line, asks for what is
--  missing one thing at a time, confirms, sells through the same engine
--  the counter uses and sends the ticket back as a picture. Three gaps
--  were found in it, and only the third needs a switch of its own.
--
--   1. TIME was never read. "bihana ko bus" and "beluka ko bus" planned
--      identically. Worse, the words fell through every pass into the
--      residual, which is the passenger's NAME — so "beluka 7 baje 2
--      seat nepal" printed a ticket for BELUKA BAJE. That is a bug, not
--      a feature, so the fix ships ON with no setting: a name that is a
--      time of day was never correct.
--
--   2. A bare "hi" reached the canned menu, which lists what the number
--      can do and asks for nothing back. wa_greet_booking below answers
--      it with the one line that books a ticket instead.
--
--   3. The pickup alias list is generous but finite, so one slipped
--      letter ("rupaydiha") matched nothing. wa_fuzzy_stops below adds a
--      timid near-miss fallback.
--
--  Both switches ship OFF: each changes what a live passenger is shown.
-- =====================================================================
SET NAMES utf8mb4;

-- NOTE: no semicolons inside string literals — tests/apply-sql.php splits on them.
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('wa_greet_booking','0','bool','ai','Answer a bare hello on WhatsApp with the one line that books a ticket, instead of the fixed menu. Only an exact greeting - a hello WITH a request is read as the request',0),
('wa_fuzzy_stops',  '0','bool','ai','Accept a pickup whose spelling is one or two letters out (rupaydiha - rupaidiha). Refuses whenever two towns are equally close, so it never guesses between them. Watch the desk cards for a day after switching on',0);
