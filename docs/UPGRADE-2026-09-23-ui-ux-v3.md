# UI/UX v3 upgrade — 23 Sep 2026 (branch `claude/shg-app-ui-ux-upgrade-5parie`)

Owner brief: "SHG — FULL APP UI/UX UPGRADE" (premium mobile-app standard, every page, nothing
that works may break). Stack unchanged: PHP 8.3 + MySQL `shari`, single-file PWA
(`app.template.html` + `assets/js/01..19`). Every addition sits in two new files —
`assets/css/ux.css` and `assets/js/19-ux.js` — loaded last, plus small, commented edits in
the files named below. Release stamp: `ASSET_VER 20260923a`, `VERSION shg-v155`.

**DEV / TEST in this session, nothing deployed to PROD.** `vps main` is the live site — push
there only after the battery on `shari_test` and the owner's yes (docs/UPGRADE-PLAN-2026-09-19.md P0).

## What changed, by phase

| Phase | Files | Summary |
|---|---|---|
| 1 Theme · fonts · header · splash | app.css (tokens), views.css (dark tokens), app.template.html (font-face, splash, Call button + sheet), 13-admin-routes.js (Splash), assets/fonts/poppins-*.woff2 | Poppins everywhere (self-hosted, Cinzel retired); v3 tokens (navy/cta/sky/gold/wa/bot/seat/bp); header = logo + Call + menu, the 8 number pills live in the Call sheet; 4.5 s splash once per session, S › HARI › GLOBAL › PRIVATE LIMITED, bar, Skip at 2 s; the 11 missing WhatsApp-card i18n keys added |
| 2 Home · search · seat key | app.template.html, ux.css, 19-ux.js, 04-i18n.js | 3 booking-channel cards first on the home page (WhatsApp / Bot / Manual, double outline, glow, ripple); recent + popular route chips, Today/Tomorrow/Next bus, swap, sound switch; seat key that wraps/scrolls, boarding-point colour dots, 1F/2F segmented tabs, deck-head sticky overlap fixed |
| 3 One passenger form · payment · hold bar | 07-checkout.js (coPhoneShapeOk), admin/bookings.php (label), ux.css, 19-ux.js | #seatQuick hidden (duplicate name/mobile); Passengers (name · gender toggle) first, Contact second; +91 6-9 / +977 9x check; slim sticky hold bar; admin shows "Verification pending" once proof is in |
| 4 Help · complaints | includes/complaints.php, api/complaint.php, admin/complaints.php, admin/_guard.php, database/upgrade-2026-09-23-complaints.sql, 16-lazy.js, tests/complaints-test.php | Help FAB bottom-right (bottom-nav Help opens it); "Complaint" chip EN/HI/NE/GU; ticket SHG-C-yymmdd-XXXX, office WhatsApp via the configured driver or a wa.me fallback; Open / In progress / Resolved board |
| 5 Seat-status PNG · private marketing | includes/seatstatuspng.php, includes/events.php, download-chalan.php, admin/challan.php, admin/chalan.php, includes/promocard.php, admin/promo-card.php, database/upgrade-2026-09-23-seat-status.sql, tests/seat-status-png-test.php, 19-ux.js | Card (no passenger details) to office/agent WhatsApp on booking.approved / booking.cancelled; one-tap download in Bus Chalan; gold "Private · Comfort" band + Why private? + per-cabin prices; "Book full cabin" nudge; promo card PNG (EN/HI/NE) |
| 6 AI extras · polish | database/upgrade-2026-09-23-ux-flags.sql, 19-ux.js, ux.css | Voice search on the search card, "Nearest pickup" chip (both flagged); booked! tick + confetti at submit; dark mode verified for every new component |

## Switches (all ship OFF — deploy and switch-on are two steps)

| Setting | Effect when ON |
|---|---|
| `complaints_on` (public) | Help bot files to `api/complaint.php` (ticket + office WhatsApp) instead of the Enquiries inbox |
| `complaint_whatsapp` | Number that receives complaints (default 918735881507) |
| `seat_status_wa_on` | Seat-status PNG sent after every confirmed / cancelled booking |
| `seat_status_wa_numbers` | Recipients, comma-separated (blank = `admin_whatsapp`) |
| `seat_status_wa_template` (+`_lang`) | Approved image-header template for outside the 24 h window |
| `ux_voice_search_on` (public) | 🎤 on the search card |
| `ux_nearest_stop_on` (public) | 📍 Nearest pickup chip |

## Migrations to apply (additive, re-runnable)

```
php tests/apply-sql.php database/upgrade-2026-09-23-complaints.sql
php tests/apply-sql.php database/upgrade-2026-09-23-seat-status.sql
php tests/apply-sql.php database/upgrade-2026-09-23-ux-flags.sql
```

## Tests

- Green in this session (no database here): PHP syntax on every changed file, `node --check` on every
  bundle + sw.js, `tests/asset-version-test.php` (9/9), `tests/i18n-check.js` (was 11 problems on
  main, now 0), `tests/offline-ticket-test.js` (21/21), `tests/lazy-retry-test.js`.
- Rendered and click-tested in headless Chromium (phone 390px + desktop 1366px, light + dark):
  splash timeline, header + Call sheet, channel cards → their flows, chips / quick dates / swap /
  sound, seat key + dots + tabs, checkout form + gender toggle + hold bar + payment card, bot
  complaint flow (v3 and fallback), private band + upsell, celebration. Seat-status and promo PNGs
  drawn with stubbed data and inspected.
- **Not run here (need `shari_test`)**: `tests/complaints-test.php`, `tests/seat-status-png-test.php`
  (both registered in `tests/run-all.php`) and the full battery. Run `php tests/run-all.php` on the
  VPS test copy before any production push.
- Not measured: Lighthouse. Nothing new blocks first paint (fonts preloaded, ux.css 20 KB,
  19-ux.js deferred last); re-measure on the live URL after deploy.

## Known limits / follow-ups

- Gujarati exists in the Help bot only; the app UI stays EN/HI/NE (a full GU catalogue is ~750 keys).
- GD draws Devanagari without OpenType shaping (as the existing ticket / chalani PNGs do); the promo
  card's English variant is the safe default for sharing.
- Push departure reminders and live tracking already exist (17-pwa.js, #/nav); no change.

## Round 2 (owner feedback, 23 Sep 2026) — `20260923b`, `shg-v156`

- **Fonts**: Devanagari now set in **Mukta** 400/700 (`assets/fonts/mukta-devanagari-*.woff2`,
  unicode-range) beside Poppins for Latin; Poppins' own Devanagari files removed (too large and heavy
  next to English). `--f-display` / `--f-body` = `'Poppins','Mukta',…`.
- **Splash**: name words arrive at .35 s intervals (was .8 s), logo scales in faster, title
  reduced to clamp(21px, 5.6vw, 32px), a Nepali facilities row (AC स्लिपर · लाइभ GPS · USB चार्जिङ ·
  सुरक्षित यात्रा) lands after the name; total 4 s.
- **QuickBot card**: restyled as a clean light card (navy header strip, plain inputs, one CTA,
  chips/plan/steps inside the card) — CSS only, `initQuickTicket()` untouched.
- **Nepalgunj branch staff**: default contact slots raised to 10; Lokesh Sunar and Mahendra Singh are
  labelled "· Nepalgunj branch" with **+977**, a Bal Bahadur slot waits (hidden) for its number; the
  Call sheet groups 🇳🇵 NPJ staff / 🇮🇳 India and lists NPJ first for a Nepali-language visitor.
  If the owner had already saved contact numbers in Admin → Settings, that saved list wins — relabel
  and set +977 there once.
- **Ticket regenerate + replace** (`admin/booking-view.php` `bv_regen_ticket()`): every passenger,
  contact or payment edit now re-mints the QR (REV n), redraws the PNG immediately, and — if that
  ticket had already gone out on WhatsApp — re-sends the new one through the same path as the Resend
  button. `Ticket::imageUrl()` carries `&r=<rev>` so WhatsApp and browsers fetch the corrected picture
  instead of a cached copy.
- **Headings**: section titles carry a navy→orange gradient and an accent bar; `.hl` utility for
  highlighted words.

## Round 3 — performance pass (24 Sep 2026) — `20260923c`, `shg-v157`

Owner: "165 Hz jasto smooth, hardware-level optimisation". Measured on the phone home page in
headless Chromium (rAF frame timing + CDP Performance metrics, 3 s idle and 3 s scripted scroll):

| | before | after |
|---|---|---|
| layouts in 3 s idle | 1,522 | 3 |
| layout time | 64 ms | 1 ms |
| style recalculation time | 423 ms | 178 ms |
| main-thread task time | 3,027 ms | 1,492 ms |
| average frame cost | 2.02 ms | 0.23 ms |
| running animations at rest | 14 | 1 |

What changed (`ux.css` section 13, `19-ux.js` governor):
- Animations on `left`, `box-shadow`, `filter` and `background-position` (which force layout or
  paint every frame) replaced with transform/opacity equivalents (ring pulses on pseudo-elements,
  the rail bus on `translateX` with container units) or limited to two passes (sheens).
- `backdrop-filter` removed from surfaces that scroll (summary/payment cards, hero countdown,
  modals; panels on phones).
- Every top-level block of every view is watched with an IntersectionObserver: off screen ⇒
  `animation-play-state:paused`; hidden tab ⇒ everything paused.
- `content-visibility:auto` on below-the-fold home blocks; `contain:layout paint` on cards; the
  fixed chrome (nav, bottom nav, FABs, hold bar) promoted to its own layer; `touch-action:manipulation`.
