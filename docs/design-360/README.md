# Small-phone booking pass ("d360") — 14 Nov 2026

Customer home + booking flow, measured and fixed for a 360x640 phone.
LOCAL ONLY (branch `shari/booking-design-360-7f7e08`). Not deployed, not pushed.
Presentation only: no booking, fare, seat, payment or ticket logic, no API call and no
Sharing / Private or lower / upper berth behaviour was changed.

Screenshots: `before/` and `after/` (720x1280 px = 360x640 CSS px at 2x, dark theme unless named).

## 1. What was measured before any change (360x640, dark theme, Nepali)

| # | Problem | Measured |
|---|---------|----------|
| 1 | Hero band is tall, the booking card starts low | hero 192px (y 56-248); card starts at y=254 |
| 2 | An invisible strip pushes the card down | `.sb-head` is screen-reader-only on a phone, but `premium3d.css` made it `position:relative` again: 6px padding + 10px margin of nothing |
| 3 | The "?" help button has a row of its own | 44px row (y 394-430) |
| 4 | Quick form: date / pickup / seat chips under the book bar | chips y 483-632, book bar top y=507 |
| 5 | Full form: phone + name fields hidden | fields y 604-645, under the book bar (507) and the bottom nav (561) |
| 6 | Typing into a hidden field | focusing the phone field at y=599 did not scroll: the browser counts it as visible, the fixed bars cover it (`before/passenger-form-360x640-typing-under-bar.jpg`) |
| 7 | Book bar sits under the bottom nav's top edge | bar `bottom:64px` (`--bottomnav-h`), nav is really 79px tall: 15px of the bar (the lower edge of both Book keys) is covered |
| 8 | Error toast lands on the Book keys | toast y 501-549 over the bar (`before/passenger-form-360x640-toast-over-buttons.jpg`) |
| 9 | WhatsApp / S Hari AI buttons flash over the form on load | visible from first paint until the observer runs: ~0.3 s warm, ~3 s cold (`before/home-360x640-load-3s-fabs-over-form.jpg`) |
| 10 | Vibration pill covers the coach image | top-right of the hero, on the bus |
| 11 | Seat map: state by colour only, berths under 44px | berths 43x44; booked = stripes, women-only = pink, yours = blue, no mark, no spoken state; rows peek under the sheet footer |
| 12 | Tap targets under 44px on the card | inputs 41, mode buttons 36, date chips 38, gender / age 38, +/- 34x38, segments 32, Private? / Change 32, help 37, terms link 15 |
| 13 | Track page: clock sits on the headline | `.clk` overlaps the last word of the hero title at 360px |
| 14 | My Ticket: focus ring drawn around the page heading | the router focuses the `h2[tabindex=-1]`; a 3px ring is drawn on a non-control |
| 15 | Text contrast under 4.5:1 | light: chip grey 4.4, "Private?" 3.9, "♀ महिला" 3.0, "सिफारिस" 3.4; dark: terms link 2.3, "♀ महिला" 4.46; both: white on the orange Cash key 2.8 |

No horizontal overflow was found on any screen at 360px (before or after).
At 390x844 the whole quick form already fitted; items 2, 3, 7-12 applied there too.

## 2. What changed

All CSS is one dated block, `assets/css/polish.css` section 7 (the last stylesheet), plus six lines in
`assets/css/track-live.css`. JS is three small presentation hooks.

| Fix | Where |
|-----|-------|
| Compact hero on short phones (`max-width:859px` and `max-height:740px`): smaller headline, coach kept, the five badges become one slim scrolling row; taller phones and desktop unchanged | polish.css 7.2 |
| Vibration switch: icon-only, parked at the end of the badge row, 44px tap area, a strike line when off | polish.css 7.2 |
| `.sb-head` really screen-reader-only again on phones | polish.css 7.3 |
| Mode switch + "?" help + progress ring on one row (help keeps its accessible name) | polish.css 7.3 (`max-width:520px`) |
| Book bar sits on top of the bottom nav; slimmer on short phones | polish.css 7.2 / 7.3 |
| Tap targets: inputs, date chips, gender / age, +/- now 44px; segments, mode, direction, Private? / Change 40px; terms link has a padded hit area | polish.css 7.3 |
| A focused field scrolls clear of the nav + book bar (also 450 ms later, once the keyboard is up) | `27-smartbook.js` `clearOfBar()` + `scroll-padding-bottom` |
| Error toast is lifted above the SmartBook book bar (same rule the toast already had for the bottom nav and the checkout bar) | `02-config.js` `toast()` |
| Floating buttons stay hidden until the card observer has reported (`html.sb-fab-ok`); 10 s CSS fallback if the bundle never runs | polish.css 7.1 + `27-smartbook.js` |
| Seat sheet: berths 45x46, marks ♀ / ✕ / ✓ on berths and in the legend, booked label struck through, state in `aria-label`, solid footer to the bottom edge, bolder deck titles and row letters | polish.css 7.4 + `27-smartbook.js` `cell()` |
| No focus ring on a heading the router focuses | polish.css 7.5 |
| Contrast fixes listed in item 15 (not the Cash key - see section 5) | polish.css 7.6 |
| Track page: the clock takes its own line under 420px | track-live.css |
| Asset stamp `20261114a`, `sw.js` `shg-v273` | separate commit |

## 3. After (360x640)

| | Before | After |
|---|--------|-------|
| Hero height | 192px | 102px |
| Booking card starts at | y=254 | y=164 |
| Quick form: phone + name | y 440-481 | y 288-332 |
| Quick form: last chip row ends | y=632 (under the bar) | y=496 (bar top 504) |
| Full form: phone + name | y 604-645 (hidden) | y 451-495 (visible, bar top 504) |
| Book bar | y 507-576, 15px under the nav | y 504-561, on the nav |
| Floating buttons on load | flash 0.3-3 s | none |
| Seat berth | 43x44, colour only | 45x46, colour + mark + spoken state |

## 4. Verified

- Local test site (`shari_test`, port 8967), preview browser at 360x640, 390x844 and 1280x800.
- Two bookings end to end on the cash path: home -> seat sheet -> passenger details -> confirm sheet -> ticket:
  `SHG-2026-01320` (before the change) and `SHG-2026-01441` (after; seat F4 picked on the map = `L32` in `booking_seats`).
  The test DB has no WhatsApp / SMS / mail credentials, so nothing was sent.
- No horizontal overflow at 360px: home (quick + full), seat sheet, confirm sheet, ticket, My Ticket, Track.
- Light and dark theme at 360x640; contrast re-measured after the fix.
- Desktop (1280x800): head, help button, book bar and scroll padding unchanged.
- Tests that read the touched files, run on this branch and on an untouched checkout of HEAD (`f56bb227`):

| Suite | This branch | HEAD before |
|-------|-------------|-------------|
| bs-calendar-test | 28 pass | - |
| two-apps-test | 53 pass | - |
| i18n-check.js | OK | - |
| offline-ticket-test.js | 21 pass | - |
| asset-version-test | 8 pass, 1 fail | 8 pass, 1 fail (same: `?v=full`, `manifest.webmanifest`, `premium3d.css` carry older stamps) |
| quick-ticket-test | 84 pass, 6 fail | 84 pass, 6 fail (same) |
| ticket-bot-test | 85 pass, 1 fail | 85 pass, 1 fail (same) |
| ticket-png-test | 36 pass, 2 fail | 36 pass, 2 fail (same) |
| preflight | 43 pass, 9 fail | 43 pass, 9 fail (same; local config checks) |
| counter-role-test | stops on a DB error | stops on the same DB error |

## 5. Not verified / left as it is

- A real phone and a real on-screen keyboard: only the emulated viewport was used. The keyboard re-check
  (450 ms after focus) is written but not exercised.
- AMOLED display mode and the colour palettes were not opened; Hindi and English strings were not walked
  (the mode row falls back to an ellipsis if a label is longer).
- The preview pane was in the background, so entrance animations and smooth scrolling ran slowly there; timings
  in section 1 item 9 are from that pane.
- White on the orange Cash key measures 2.8:1 (below 4.5:1). It is the brand CTA, so the colour was not changed;
  a soft dark edge was added to the letters. Options for the owner: navy text on the orange, or a deeper orange.
- My Ticket: the S Hari AI pill and the WhatsApp button still float over the right side of a booking card
  (the trip date can sit under the pill until the page is scrolled).
- Track page language buttons are 40x40.

## 6. Found on the way, not touched (logic, outside this pass)

Items 1 and 2 were fixed afterwards on branch `shari/vigilant-margulis-ffde0a` —
see [smartbook-pickup-seat-fix.md](smartbook-pickup-seat-fix.md).

1. Pickup shown vs pickup booked. At ~12:35 IST the full form's pickup select showed "Surat · 13:00" while the
   quick chips, the confirm sheet and both tickets said "Baroda · 17:00" (Surat was inside the boarding cut-off,
   so the quote moved the pickup). The select is not updated to the quote's pickup.
2. Seat on the confirm sheet vs seat on the ticket. In the first test booking the confirm sheet said
   "F3 · तल्लो तला" and the ticket was issued for A3 (the passenger had just been set to female; A3 is women-only).
3. `asset-version-test` has failed since before this branch (three files carry older stamps).
