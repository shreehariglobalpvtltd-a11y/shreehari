# Homepage animation scenes — 4 Oct 2026 (release-20261004-r2)

Owner brief: three premium homepage animations from the supplied pack
(`shg-premium-animation-pack.zip`) in the existing site — no new site, no
change to booking, seats, fares, payments, WhatsApp, agents/admin or the DB.
Base: release-20261004-r1 (the redesign). Asset stamp 20261004d, sw shg-v169.

## What is on the home page
Order on every width: booking panel → QuickBot → brand band → the scenes.

| Section | Scene | Loop |
|---|---|---|
| `#animJourney` | branded AC sleeper coach, moving road, rotating rims, soft shadow, slow scenery; AC / Mobile charging / First-aid–Medicine chips | 7 s |
| `#animRoute` | Gujarat (Surat · Vadodara · Ahmedabad) → Rupaidiha corridor lights up, coach marker travels, border pulse; "India ⇄ Nepal" chip | 10 s |
| `#animBooking` | phone: route → sleeper berth (B6) → details + payment check → demo ticket → sample WhatsApp card; 4 steps lit in sync | 8 s |

They replace the old hidden §6 highway scene (`#routeAnim`) and the hidden
route card (`#homeRouteMap`); `renderHomeRouteMap()` now returns early.

## Files
- `assets/css/anim.css` — every rule scoped under `.shg-animation`
  (order rules: `#view-home > #anim…`). Transform/opacity only.
- `assets/js/20-anim.js` — controller: IntersectionObserver (artwork requested
  ~400 px before a scene is on screen; plays only while ≥15 % visible),
  pauses in a background tab, honours `prefers-reduced-motion` (final frame,
  Pause hidden), one Pause/Play button per scene (translated label),
  `window.shgAnim.seek(kind, ms)` / `destroy()` for inspection. No network,
  storage or booking state.
- `assets/img/anim/` — only the files used (683 KB on disk; a phone
  downloads ≈125 KB: 800 px WebP via `<picture>` media query, SVG road,
  shadow, map and phone frame). No PNG fallbacks, GIF, video or library.
- `app.template.html` — the three sections, 3 sprite icons (`i-ac`,
  `i-charge`, `i-aid`), stylesheet + script tags.
- `assets/js/04-i18n.js` — 70 `anim*` keys in en, hi, ne and the Gujarati
  override; map labels are inline SVG `<text data-i18n>`.
- `assets/css/redesign.css` — tablet app bar (761–979 px) no longer scrolls
  sideways (link padding 13 → 7 px).
- `tests/home-animation-test.php` (registered in run-all).

## Rules kept (from the brief and the pack's handoff)
- Coach: one official logo before the name, white/navy/gold; left-facing
  render for फर्किने (`#dirBack`), right-facing for जाने — two renders, never
  mirrored. Rim overlays measured on the art (r = 41 px of 1440×720).
- Map: "Illustrative corridor — not a road map, distance or live GPS
  position"; Rupaidiha labelled below the border point, on the India side;
  "Nepalgunj is just across, in Nepal".
- Booking: "Sample animation only — it does not book a seat, take a payment
  or send a message"; "Demo ticket", "Preview only · no booking created",
  WhatsApp card = "This is how a ticket message looks · not sent" (WhatsApp
  delivery currently depends on Meta billing, so no delivery promise);
  passenger-facing seat labels (B6 = storage L12), "Lower Floor (1F)".
- Pack fixes: the road and shadow SVGs carry `preserveAspectRatio="none"`
  (the 6:1 road was letterboxed in the 21:9 scene, leaving bare strips); the
  map's INDIA / NEPAL text moved out of `shg-map-bg.svg` into translatable
  text; the Rupaidiha label no longer sits under the marker.

## Verified
- 360 / 390 / 430 / 768 / 1280 px: no sideways scroll; text, map labels and
  ticket readable in en / hi / ne / gu; 4 step chips on one row at 360 px.
- Headless Chrome: nothing runs at the top of the page; each scene runs only
  on screen; Pause/Resume; background tab pauses; reduced motion = final
  frame; language switch re-labels SVG text; direction swap left/right.
- Suites: home-animation 51/51, home-entry 55/55, asset-version 9/9,
  i18n parity OK, e2e 45/45 and role-gates 73/73 on the worktree DB.
