-- upgrade-2026-09-seat-mode-map.sql
--
-- Seed the `seat_mode_map` settings row (owner ask, point 7, 8 Sep 2026):
-- the private↔sharing bed mapping is a configurable rule set instead of a
-- literal `2` in three PHP methods.
--
-- WHY A MIGRATION AND NOT THE ADMIN FORM
-- Settings::setMany() skips keys that have no row yet (includes/settings.php),
-- so the Settings page can never CREATE this key — it can only edit it once it
-- exists. Seeding here is what makes it editable at all. As a stype=json row it
-- gets the textarea editor for free.
--
-- The value below is the geometry the coach runs TODAY and is byte-identical in
-- meaning to Seats::DEFAULT_MODE_MAP, so applying this migration changes no
-- behaviour whatsoever. It exists to be edited, and to document the shape.
--
--   perDeck       canonical (sharing) beds per deck
--   bedsPerLabel  how many canonical beds one label of that mode occupies;
--                 the canonical mode is always 1. A mode's own seat count is
--                 perDeck / bedsPerLabel, so counts cannot drift from mapping.
--   across        [left, right] columns, for the rendered layout
--   explicit      optional per-label override for an IRREGULAR cabin, e.g.
--                   "explicit": {"L1": ["L1","L2","L3"]}
--                 It wins over the ratio in BOTH directions.
--
-- ⚠️ BEFORE CHANGING A RATIO ON A COACH THAT HAS SALES, run
--    Seats::mapChangeImpact($proposed). Occupancy is re-derived from the stored
--    label on every read, so raising sleeper private from 2 to 3 does not only
--    affect new sales — it moves an already-sold "L6" off beds L11/L12 and onto
--    L16..L18, which puts the passenger somewhere other than their printed
--    ticket and returns the bed they are on to sale. mapChangeImpact() lists
--    every future booking a proposed rule set would move, with its PNR.
--
-- is_public = 1: the browser bundle reads this row out of SHG_BOOT.settings
-- (assets/js/06-results.js seatModeRuleJS), so the mapping is no longer written
-- out a second time in JS. It is pure geometry — the same shape the seat map
-- already draws — so there is nothing here that was not already visible.

INSERT INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`)
VALUES (
  'seat_mode_map',
  '{"sleeper":{"canonical":"sharing","decks":["L","U"],"perDeck":36,"modes":{"sharing":{"bedsPerLabel":1,"across":[4,2]},"private":{"bedsPerLabel":2,"across":[2,1]}}}}',
  'json',
  'seats',
  'Seat mode map — how a private cabin maps onto physical beds',
  1
)
ON DUPLICATE KEY UPDATE `skey` = `skey`;   -- never clobber a tuned live value
