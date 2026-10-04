# AMOLED Pop-out depth — 4 Oct 2026 (release-20261004-p1 … p3)

Owner brief: `SHG_Complete_3D_Blueprint (2).zip` (master plan, 5 demos, 6 prompts).
The plan carried two master prompts (§9 "light/subtle" and §18 "AMOLED
Pop-out"); the owner asked for ONE, so **§18 + the A–E role prompts were the
spec**, §9 only where §18 is silent. Base: release-20261004-w1 (live).
Files only, no database change.

## What it is
`assets/css/depth.css` loads LAST in both apps (customer: after anim.css;
company: after admin.css; staff login, route pages and travel-info carry an
inlined copy of the few rules they need).

- **Depth in every theme.** One light source, top-left. Major surfaces: lit
  rim (bright top/left border + inset highlight), a stacked 8px lower edge
  (the visible side thickness) and a detached cast shadow. List cards/tiles:
  a thinner 5–6px slab. Primary keys: royal face on a 6px royal edge; on
  press they travel 3px into it. Inputs: inset wells. A selected date /
  direction / seat / tab lifts on a royal edge; a booked or held seat is
  pressed flat. Text and numbers are never shadowed, rotated or blurred;
  nothing loops.
- **AMOLED display.** A third, explicit choice — light · dark · AMOLED.
  Stamped as `data-theme="dark"` + `data-display="amoled"`, so every existing
  dark rule (and the two chart scripts that test `data-theme==='dark'`) keep
  working; depth.css re-points the design-system tokens: ground `#000`,
  face `#101822`, lit face `#213146`, rim `#7489A6`, text `#EEF4FF` /
  `#ACBBD0`, accent `#9ABFFF`, action fill `#28579C`. Every declared pair is
  ≥ 4.5:1 (tested). Never switched on automatically — a browser cannot tell
  whether a screen is AMOLED. No battery / burn-in / refresh-rate claim.
- **Where you choose it.** Customer: the topbar button cycles
  light → dark → AMOLED (desktop/tablet); phones get a **Display** row in the
  ☰ menu next to Sound and Vibration. Company app: the moon button in the top
  bar cycles the same three (saved as `shg_admin_theme`); the staff login
  page follows the saved choice.
- **Sound / vibration (SHGFeel, 19-premium.js — still the only engine).**
  Default **OFF** now (brief); a saved "on" is kept. The contact sheet's raw
  `navigator.vibrate(8)` now goes through the haptics switch. Reduced motion
  still blocks vibration.

## Bugs found and fixed on the way
1. A saved dark theme came back **light** on every reload: `applyTheme()`
   also called `store.set('shg:theme')`, which JSON-encoded the value
   (`"dark"` with quotes) and the pre-paint check missed it. Stored raw now;
   old quoted values are read and rewritten.
2. Phones had **no theme control** (the topbar is hidden under 761px).
3. The phone ☰ menu is sticky and ~1,070px tall: its last rows (Sound,
   Vibration, now Display) were **below the screen, unreachable**. It
   scrolls inside itself, stops above the bottom bar, and the floating
   WhatsApp / assistant / SOS buttons step aside while it is open.
4. Dark-mode **white boxes / unreadable text**: checkout cards, payment
   picker, proof card, contact, My Tickets header, terms sections and the
   quick-summary tiles (light text on pastel), the on-screen ticket hero,
   offers / wa-pending pages, inline status pills, QuickBot desk numbers
   (navy on navy).
5. **320px seat map scrolled sideways** (the 1–6 seat-count keys): keys are
   3 × 2 there now.

Copy from the brief (en/hi/ne/gu): heading "आफ्नो यात्रा बुक गर्नुहोस्" /
"Book your journey"; search key "बस र सिट हेर्नुहोस्" / "See buses & seats".

## Coverage
Method: headless Chrome, real pages on the local copy (`amoled_test` DB),
light / dark / AMOLED, 320 / 360 / 390 / 1440px, plus an automated audit on
every page (light surfaces left in AMOLED, dark-on-dark text, sideways
scroll, PHP notices, JS errors).

| Area | Screens checked | Result |
|---|---|---|
| Customer home | booking panel (direction, 30-day strip, date input, town, passengers, search), other ways to book, QuickBot plan card, facilities, scenes, footer, bottom bar, ☰ menu + Display/Sound/Vibration | depth + AMOLED ✔, 320–1440 no sideways scroll |
| Results → seats | single-bus auto-open, seat map lower/upper, sharing/private, seat-count, held/booked/ladies/selected, legend, sticky continue bar | ✔ (320 fixed) |
| Ticket / My Tickets | recovered test ticket (PNR + phone), status ladder, ticket card, notes, My Tickets list + actions, "not found" state | ✔ ticket follows dark/AMOLED; print stays white |
| Checkout / payment | driven end-to-end on the local test DB: seat → details → payment picker → Cash/COD → submit → confirmed ticket + border-prep card (light + AMOLED). No live booking. | ✔ — the payment picker lost to a `:root[data-theme="dark"] .pm-tab.on {!important}` rule in dark; fixed in p3 |
| Help / chat | assistant panel, bubbles, quick replies, composer, contact sheet, WhatsApp sheet, toast | CSS covered; bubbles AMOLED-specific |
| Map | customer S Hari Nav (`#view-nav`, MapLibre) controls + bottom sheet + "location blocked" state; admin Live Map controls/popups | ✔ — map renders (live + local: style loaded, tiles loaded, 111 rendered features, canvas pixels are map colours); the blank in screenshots is only the capture of a software-GL canvas. Basemap/attribution unchanged, canvas never filtered |
| Company app | all 53 shell pages (dashboard, tickets, new booking, QuickBot desk, seat map, manifest, chalan, passengers, payments, refunds, offers, agents, agent sales/ranking/offline, staff, customers, enquiries, support inbox, message log, WA pending, missed bus, buses, drivers, calendar, schedule, trips, trip dashboard, routes, accounting, analytics, feedback, settings, activity, health, documents, company, counters, my desk, shift, offline requests, map, payroll, AI pages, ledger pages, agreements, promo card, scan, change password, search) | AMOLED + dark audit: 0 sideways scroll, 0 PHP notices, 0 JS errors; light surfaces left: the decorative phone mock-up in the booking scene, the logo tile, MapLibre's own attribution |
| Agent / counter roles | logged in locally as an agent (21 menu pages: panel, sales, statement, passengers, paper tickets, QuickBot, shift, WA queue, my desk…) and as a counter clerk (26 pages incl. payments, refunds, offers, support inbox) — AMOLED + light | ✔ 0 sideways scroll / PHP notices / JS errors; last two pastel pills themed in p3 |
| Staff login | light / dark / AMOLED | ✔ |
| Route pages, travel-info | /bus + travel-info | ✔ (light + system dark) |

Not done / outside this pass: physical AMOLED phones and real vibration
hardware; a real (live) booking and real WhatsApp delivery; logged-in live
admin pages (no live password used). PNG/PDF ticket and statement renderers
are server images and were not touched (they keep their own white layout).

## Verified
- `tests/depth-display-test.php` (new, in run-all): 49/49 — load order,
  stamps, three states on both shells, raw storage, Display radios, AMOLED
  pairs ≥ 4.5:1 (16 pairs), no text shadow/rotate/blur, map canvas untouched,
  press travel, reduced motion keeps depth, forced colours + print flat,
  sound/vibration default off, no raw vibrate.
- Full battery (`run-all.php --http`, local): 120 suites ok, 10 fail — the
  same 10 that fail on the unmodified base here (env/data: role-gates,
  e2e-booking, route-pages, travel-info sitemaps, agreement-pdf, payroll,
  office-report, delivery-sentinel, wa-fail-alert; quick-ticket/brain flip
  between runs and pass alone).
- Size: depth.css 56 KB, 12 KB gzipped (CSS total ≈ 163 → 175 KB gz).
- Speed on live (throttled phone 1.6 Mbps / 150 ms / 4× CPU, cold, same
  server, runs interleaved, depth.css blocked vs loaded): LCP median
  3.81 s → 3.84 s (no change within noise), FCP noise ±0.5 s, DCL
  5.65 → 6.03 s (+0.4 s style/paint work of the extra layer under 4× CPU).
  Field (CrUX) data still to be collected.
- Live after deploy: home + admin login 200; depth.css byte-identical to the
  commit; sw shg-v176 / stamp 20261004p served; live home, seat map
  (searched, no seat tapped — no hold created), My Tickets, map, terms and
  the staff login checked in AMOLED; 0 sideways scroll, 0 JS errors.
  Logged-in live admin pages were not opened (no live staff password used).

## p3 (style cost + leftovers)
- Style-recalc cost measured on live (Performance.getMetrics, phone, 4× CPU,
  3 runs each, depth.css blocked vs loaded): RecalcStyle ≈ 620 → 700 ms
  (+80 ms); layout ≈ 3.2 s with or without the layer (an existing cost of the
  home page, not this pass); DCL showed no consistent change (the +0.4 s in
  the first A/B was noise).
- Selector lists flattened (no `:is()`: Chrome can bucket every rule by its
  class/id), every lift/press now reads `--dp-lift` / `--dp-press` /
  `--dp-press-sm`, so reduced motion stops ALL movement; coloured company
  buttons keep their own pressed edge; the admin focus ring now outranks the
  well style; payment picker and the last pastel pills follow dark/AMOLED.
- depth-display-test: +2 checks (no comment fragment inside a selector
  list — such a fragment makes the browser drop the whole rule; no `:is()`).
  Every rule verified to parse in Chrome (240/240).

## Rollback
deploy-prod.sh backup: `/root/backups/pre-deploy-release-20261004-p1-*/`
(ROLLBACK.txt). Removing the two `depth.css` links alone reverts the look;
the theme/menu/sound changes are in 03-accounts.js, 19-premium.js,
17-pwa.js, app.template.html, admin/_guard.php, admin/login.php.
