# S Hari Global — Work Note (updated 2026-08-02)

छोटो: यो session मा homepage + checkout + Jay Ambe + animation सुधार भयो। **GPS live-tracking (ticket पछि)** अझै बाँकी — त्यो ठूलो काम भएकोले अर्को focused turn मा गर्नुपर्छ। तल पूरा हिसाब।

---

## ✅ DONE this session (all in `app.template.html` unless noted)

1. **Homepage restructure (Part 1 §1,3,4)**
   - Removed old "Nepal/India Operations" letterhead card.
   - **Quick Booking** passenger card `#quickBook` above the search card — Name·Mobile·Gender·Nationality·Age·Date·Seats → "Next →". No OTP. Saves to DB via `POST /api/enquiry.php`, then pre-fills search date + scrolls to search. Stashes `window.SHG_QUICK`.
   - CEO/Founder card already sits after booking (unchanged).
   - Premium animated header was built then **removed** at user request (redundant with hero).

2. **Backend for Quick Booking**
   - `enquiries` table — in `database/schema.sql` AND run-once `database/upgrade-2026-08-enquiries.sql`.
   - `api/enquiry.php` — CSRF + rate-limit, no auth, `Database::insert`.
   - Admin `admin/enquiries.php` (perm `bookings.view`) — list, search/filter, status (New→Contacted→Converted→Closed), Call/WhatsApp links. Nav link added in `admin/_guard.php`.

3. **Checkout fix (`#view-checkout`, JS `renderCheckout()`)**
   - IndiGo-style **boarding-pass summary** `#coSummary` at top of `.pay-card` (near QR): live name·route·date·seats·amount. Updates on `.pxName` input and in `updatePayment()`.
   - Quick-Booking hand-off: pre-fills first passenger name/phone/gender/age from `window.SHG_QUICK`.
   - **CEO/company official strip** `#coOperator` in pay-card footer.

4. **§5 Jay Ambe** — `.ja-partner` premium 3D glass card (in `.ja-menu`): photo slot, "Official Restaurant Partner" badge, Google-reviews link, Call/WhatsApp, 3 map buttons (Open Maps / Navigate / Street View).

5. **§6 Bus animation** — `#routeAnim` pure-CSS 60fps banner between hero and Quick Booking (bus drives Ahmedabad🇮🇳→Nepalgunj🇳🇵, road/hills/clouds, reduced-motion safe).

6. **Hero compacted (~half size)** — `.hero-grid` padding 74/150→34/44; `heroSub` shortened in EN/HI/NE; smaller fonts/margins.

---

## ⚙️ MUST DO on the live server (Hostinger)

- **Run once in phpMyAdmin:** `database/upgrade-2026-08-enquiries.sql` — else Quick Booking cannot save (table missing).
- Optional: drop a real photo `jayambe.jpg` into `public_html/` — it auto-replaces the Jay Ambe placeholder.
- Optional: put your **real Google rating** into the Jay Ambe card (search `ja-rate` in `app.template.html`; a comment marks the spot). Currently NO fake number — just a "See reviews" link.

---

## ⏳ NOT DONE — do next (priority order)

### 1) 🛰️ Advanced GPS live tracking AFTER ticketing  ✅ **DONE (2026-08-02)**
Built a **self-contained live GPS timeline** inside the Trip Companion (`#/trip/<pnr>`):
- New card `#tcLiveTrack` in `#view-trip` → all 16 `ROUTE_STOPS` with **km markers + amenities + flags**, a moving 🚌 marker + progress fill.
- **Bus position = passenger's own phone GPS** (`navigator.geolocation.watchPosition`), projected onto the route corridor (equirectangular point-to-segment → cumulative km). NO Firebase / NO map library, so it always works (offline too) and can't break the MapLibre section.
- Live **progress % · speed (device or derived) · distance-remaining · ETA · next halt** with amenity icons.
- Entry points fixed: ticket view now shows **"🛰️ Track My Bus · Live GPS"** + **"🚌 My Trip Companion"** (both → `#/trip/<pnr>`); My Bookings row has **🚌 Trip / 🛰️ Live**. The old broken `#/track/<pnr>` links (silently redirected to `#/nav`, losing the PNR) are gone.
- Code: JS module `[JS] 13b-LT` (`tcInitLiveTimeline`, `ltProject`, `ltOnFix`, `ltMarkProgress`, …) + `.lt-*` CSS; `renderTrip()` calls it, `cleanupTrip()` stops the GPS watch. `node --check` clean; all IDs/helpers verified.

_Original ask (for reference):_ after a ticket is issued, a **timeline of ALL ~16 stops with km markers**, the **bus position driven by the user's phone GPS** (`navigator.geolocation`), plus speed · distance-remaining · ETA. "Advanced bus tracking."

**IMPORTANT — the app already has a lot of tracking; DO NOT build a new one blindly (will conflict/break):**
- `#/track` route currently **redirects to `#/nav`** — see `router()` (~line 6998). To enable a real track view, change that line to `showView('track'); renderTracker(pnr)`.
- `showView()` (~line 6977) already guards `cleanupTracker()` for `name==='track'`.
- `cleanupTracker()` already exists (~line 12197) — tears down a **Leaflet** map tracker `LFT` and a Full-Map `FM` instance.
- **Trip Companion `TC`** (~line 12213) — an ixigo-style per-PNR live-tracking experience with Firebase + a **phase timeline**, reachable at `#/trip/<pnr>` (router ~line 6991, `renderTrip()`). **This is probably the right place to add the all-stops + km timeline** rather than a new view.
- MapLibre navigator: `renderNav()` ~14148, `initNavApp()` ~14173 (view `#view-nav`, `#/nav`).
- Existing homepage route timeline: `.shg-stops` (~line 1806) shows only a few stops — user also wants ALL stops + km here.
- Single-shot geo helper: ~line 6207. Data: `route_stops` table in `database/schema.sql`; stop names listed ~line 2354 (Chiloda, Mehsana, Udaipur, Jaipur, Agra, Lucknow, Bahraich, Nanpara…).

**Suggested plan:** (a) build a stop array with cumulative **approx** km + real city lat/lng (label km "approx" — the site's ≈1,375 km headline is marketing, keep it consistent, don't invent false precision); (b) `watchPosition` → haversine to nearest stop → progress %; (c) render/enhance the timeline (prefer extending `TC`'s trip view) with a bus marker + speed/remaining/ETA; (d) add a "🛰️ Track my bus" button on the **issued-ticket / status view** (`#/ticket/<pnr>` → `renderStatus`) and near homepage "Open Navigator" (`.shg-nav` ~line 1820). **Read `TC` and `renderTrip` fully before editing.**

### 2) Part 2 — seat selection polish (real bus layout, glow/hover, sleeper view). `#view-seats`, `renderSeats()`.
### 3) Part 4 — admin analytics/export (Excel/PDF), dark mode, "edit everything" CMS.
### 4) Part 6 — chatbot upgrade (already a floating rule-based bot; wants NLP-ish + forward-to-WhatsApp on miss).
### 5) Part 7 — accessibility section (priority boarding, wheelchair, high-contrast, voice).
### 6) Part 8 — 12 premium themes (theme system exists via `data-day`/`data-theme`; extend).
### 7) Part 9/10 — PWA/perf hardening + premium micro-polish (sw.js/manifest already added).

---

## Verify status
- All new PHP lints clean (`php -l`).
- All new element IDs unique; landmarks intact.
- Visual: premium header (removed), hero, Quick Booking confirmed rendering in browser. Full DB-backed run NOT possible in dev env here (no local `pdo_mysql`), so test Quick Booking + admin Enquiries on the live/staging server after running the upgrade SQL.
