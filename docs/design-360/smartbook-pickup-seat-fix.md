# SmartBook: pickup and seat shown vs booked — 14 Nov 2026

LOCAL ONLY (branch `shari/vigilant-margulis-ffde0a`, on top of the d360 pass `31cd5b72`).
Not deployed, not pushed. Follows items 1 and 2 of section 6 in [README.md](README.md).

Local test site: DB `shari_test`, `http://localhost:8973` (`shg-sbfix` in `.claude/launch.json`),
preview browser at 360x640, Nepali. No WhatsApp / SMS / mail credentials in the test DB, so nothing was sent.

## 1. Reproduced before any change

### Pickup (seen 15:01 IST; Surat 13:00 was already past, cut-off 30 min)

1. Clear `localStorage['sb:v1']`, open the home page.
2. Tap "✏️ बदल्नुहोस्" (full form).
3. The pickup box `#sbPoint` shows **Surat · 13:00**, hint "धेरै यात्रु यहीँबाट चढ्छन्".
4. The quote (`SmartBook.state.q`) says `point: "Surat"`, `boarding: "Barauda @ 17:00"`; the quick chip,
   the summary rail and the confirm sheet say **Baroda 17:00**.

### Seat (ticket `SHG-2026-01749`)

1. Full form, mobile `9812300451`, name `Sbfix Test`, gender key tapped twice → पुरुष. Card: **F9 · माथिल्लो तला** (auto).
2. Tap "बुक - नगद" → the seat map opens with F9 marked → OK. Card: F9 · "तपाईंको रोजाइ" (hand pick).
3. Tap the gender key (→ महिला) and tap "बुक - नगद" straight away.
4. The confirm sheet `#sbCv` opens at once and says **सिट: F9 · माथिल्लो तला**.
   Half a second later the card behind it says **A3 · तल्लो तला** (auto); the sheet still says F9,
   and the "Choose seats" switch still shows the hand-pick mode.
5. Fill address + ID, Confirm → the ticket is **A3** (`booking_seats.seat_no = L1`, a women-only berth),
   pickup `Barauda @ 17:00` — while the form had said Surat 13:00 and the sheet F9.

## 2. What was wrong

**Pickup.** For a passenger, `QuickTicket::nextDeparture()` keeps today's bus and drops every pickup that is
past or inside the boarding cut-off; if the asked pickup is one of them it boards the passenger at the first
pickup still ahead (the same rule `BookingService::create()` enforces). `SmartBook::quote()` returned the asked
pickup as `point` and the real one only as `boarding` / `boardingName`. The chips, the sheet and the ticket read
`boardingName`; the pickup box kept `S.point`.

**Seat.** The server did nothing wrong: `bookNow()` sells exactly the berths the request carries.
The card did two things:

- A gender tap (also an age-band tap, a date / pickup change, +/-) empties the hand pick (`S.manual = []`) but
  left `S.seatMode = 'manual'`. The next quote was an AutoBerth quote, yet the "seat map first" step
  (`seat_pick_manual_first`) was skipped because the mode still said manual.
- The new quote takes 260 ms + the request. Until it lands `S.q` is the old quote. Cash opened the confirm sheet
  from that old quote (F9); by the time Confirm was pressed `S.q` was the new one (A3), and `book()` sends
  `S.q.seats`. The sheet is static HTML, so it kept showing F9.

## 3. What changed

Decision for the pickup: **the box follows the quote, with the reason** — the closed pickup is not removed from
the list. The list is the same for every date and the pickup is open again tomorrow; which pickups are closed
depends on the date and the clock, and only the server's plan knows that. The server keeps the passenger on
today's bus rather than moving them to tomorrow, so the form has to say where today's bus will take them from.

| File | Change |
|------|--------|
| `includes/smartbook.php` `quote()` | two new fields: `boardingStop` (the list name of the pickup really booked) and `pointNote` (`{en,hi,ne}`, only when the plan moved the asked pickup). Nothing else in the quote, the price or the sale is touched. |
| `assets/js/27-smartbook.js` `syncPoint()` | after every quote the pickup box shows `boardingStop`; the line under it shows `pointNote` ("⚠️ Surat 13:00 आजका लागि बन्द भयो। आज Baroda 17:00 बाट चढ्नुहोस् — Surat बाट चढ्न पछिको मिति छान्नुहोस्।"). `S.point` stays the asked pickup, so a later date brings Surat back with its normal hint. |
| `27-smartbook.js` `requote()` | a hand pick that was dropped goes back to Auto, so the seat map opens again before the sale with the new berth marked. |
| `27-smartbook.js` `book()` | waits for a quote that is still on the way (`qPend`) before it opens the seat map / a confirm sheet or sells. |
| `27-smartbook.js` `sheetFresh()` | both Cash sheets: Confirm is refused when the quote is no longer the one the sheet shows (berths, pickup, drop, date, total). The sheet is drawn again with the new summary and "⚠️ सिट बदलियो: A5 · तल्लो तला → F9 · माथिल्लो तला। माथिको विवरण हेरेर फेरि पक्का गर्नुहोस्।", or the seat map opens if the pick was dropped. Nothing is sold until the passenger confirms what is on the screen. |
| `assets/css/smartbook.css` | `.sb-why.sb-why-moved` — the reason line as a warning. |
| stamp | `20261114b`, `sw.js` `shg-v274` (separate commit). |

New strings (en / hi / ne) are in the `tr()` table of `27-smartbook.js`: `seatMoved`, `cvAgain`, `cvMoved`.
Fares, payment, ticket rendering, Sharing / Private and lower / upper berth rules are not touched.

## 4. After

| Case | Form | Confirm sheet | Ticket |
|------|------|---------------|--------|
| Pickup + seat, same steps as section 1 (`SHG-2026-01759`) | Baroda · 17:00 + reason; after the gender tap the seat map opens with A4 marked (A3 was taken by the first test) | चढ्ने Baroda · 17:00, सिट A4 | A4 (`L2`), `Barauda @ 17:00` |
| Gender changed while the confirm sheet was open (`SHG-2026-01760`) | — | Confirm sold nothing: toast "सिट बदलियो: A5 → F9", seat map with F9; then sheet F9, Mehsana · 23:00 | F9, Mehsana @ 23:00 |
| Pickup changed while the confirm sheet was open | — | Confirm sold nothing: sheet redrawn with the new pickup and "बुकिङको विवरण बदलियो…" | — |
| Tomorrow chip | Surat · 13:00, hint "धेरै यात्रु यहीँबाट चढ्छन्" | — | — |
| Return direction (drop list) | no note, list unchanged | — | — |

Tests, before → after: `smartbook-discount-test` 20/0 → 20/0, `smartbook-who-test` 10/0 → 10/0,
`quick-ticket-test` 84/6 → 84/6 (same six), `asset-version-test` 8/1 → 8/1 (same three old stamps),
`two-apps-test` 53 pass, `bs-calendar-test` 28 pass, `i18n-check.js` OK, `offline-ticket-test.js` 21 pass.

## 5. Not verified / left

- Staff desks (`admin/smartbook.php`, agent / counter / admin) were not opened in the browser. They share the
  code: the one-button confirm sheet goes through the same `sheetFresh()`, and a dropped hand pick now reopens
  the seat map there too.
- The Online (QR) path was not paid through; it now also waits for a quote that is on the way.
- A real phone was not used; only the emulated 360x640 viewport.
- The reason line is two lines at 360px and pushes the fields below it down by ~45px while it shows.
- The ticket card prints the stop's stored name ("Barauda @ 17:00") where the form says "Baroda · 17:00" —
  same pickup, two spellings; ticket text was out of scope.
- The first pickup "still ahead" is taken in the route's stop order, not by time: at 15:01 the quote chose
  Baroda 17:00 although Bharuch 16:00 was still open. Not changed (pickup choice rule).
- Three confirmed test tickets are in `shari_test`: `SHG-2026-01749`, `-01759`, `-01760` (mobiles `98123004 51-53`).
