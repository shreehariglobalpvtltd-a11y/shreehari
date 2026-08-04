# ▶️ PASTE THIS INTO A NEW CLAUDE SESSION / ACCOUNT

Project: **S Hari Global** bus-booking app. Repo root: `C:\S HARI PRJECT'\public_html`.
Single-file frontend: `app.template.html` (~15k lines, served by `index.php` which injects `window.SHG_BOOT`+CSRF). PHP backend (no framework), MySQL, Hostinger. I speak Nepali/Hindi mix — reply likewise, practical & direct.

**First: read `REMAINING-WORK.md` in the project root — full done/not-done list with line-number pointers.**

## Already DONE (don't redo)
- Homepage: removed old ops letterhead; added **Quick Booking** card `#quickBook` (no OTP → `POST /api/enquiry.php` → `enquiries` table); `admin/enquiries.php`.
- Checkout `#view-checkout`: IndiGo boarding-pass summary `#coSummary` + CEO strip `#coOperator` (in `renderCheckout()`).
- Jay Ambe `.ja-partner` card; homepage `#routeAnim` bus animation; hero compacted; route timeline `.shg-stops` now shows km.
- ⚠️ Live server: run `database/upgrade-2026-08-enquiries.sql` once (else Quick Booking can't save).

## DO NEXT (priority)
1. **🛰️ GPS live tracking after ticketing** (top ask): timeline of ALL ~16 stops + km, bus position from phone `navigator.geolocation`, speed·distance-remaining·ETA, entry from issued-ticket view.
   **CRITICAL:** app already has tracking — Trip Companion `TC` (~line 12213, Firebase, per-PNR at `#/trip/<pnr>`, `renderTrip()`), Leaflet `LFT`+`FM`, `cleanupTracker()` (~12197), MapLibre `initNavApp()` (~14173). `#/track` route (~6998) currently redirects to `#/nav`. **Extend `TC`/trip view — do NOT build a new tracker. Read `TC`+`renderTrip` first.**
2. Part 2 seat-selection polish (`renderSeats()`), Part 4 admin analytics/export+dark, Part 6 chatbot+WhatsApp-forward, Part 7 accessibility, Part 8 themes, Part 9/10 PWA/perf/polish.

## House rules
- Surgical edits to the big file; verify with `grep`/`php -l`. No local `pdo_mysql` in dev — test DB features on live/staging.
- Don't fabricate Google ratings/records. Keep existing working features intact.
