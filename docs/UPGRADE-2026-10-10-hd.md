# HD visual pack in the site — 10 Oct 2026 (package HD)

Owner (10 Oct 2026): *"the photos GPT made in HD - replace them"*, with the pack's own guide
(00-guide/README-NE.md): calm navy / mist-blue / ivory, gold only as a small accent, body
16-18 px at line-height 1.6, 48 px+ touch targets, ONE eager image, less flashing / auto
motion, honest captions.

Built on the existing PHP + vanilla-JS app. One new stylesheet (`assets/css/hd.css`, before
`depth.css`, precached), one data migration, no new dependency. Asset stamp unchanged
(`?v=20261009g` — the integrator bumps it once with `sw.js` VERSION).

## What was already there
- The 16 artworks (`assets/img/hd/<id>.webp` 1600 px, `-m` 800 px, `-t` 480 px) and the
  31 blue-line icons (`assets/img/icons/*.svg`) were committed in a7641c7. Nothing used them.
- The home hero `#top` (light rays, particles, orbs, giant watermark) was hidden outright by
  `redesign.css` since 4 Oct — the page opened on `#homeHero` (logo card + search form).

## 1. The coach hero (`#top.hero-hd`)
- The decorative layers (`.hero-fx`, `.orb`, `.hero-wm`) and their keyframes are gone from
  `app.template.html`, `app.css` (sections 5 / 6b), `premium.css`, `views.css`,
  `redesign.css`; `05-router.js` starts its parallax loop only when something carries
  `data-parallax` (nothing does now).
- Two columns: copy left, the `bus-cutout` render right (`<picture>`, `-m` under 761 px,
  lazy, sized, alt "promotional render"). Every real element stays: eyebrow (SVG flags),
  brand row (`{{f:brand.name}}`), slogan, sub, the four chips (sprite icons), `#heroQuote`,
  `#nextDepBox` (`data-i18n="hdNextDep"`), the QuickBot key.
- **Staged, not shown (review fix).** `redesign.css` still hides `#view-home > .hero`, as the
  owner-approved booking-first layout of 8 Oct (D3-09) decided: shown, the card would repeat
  `#homeHero`, `#homeFeat` and QuickBot right above it. `showcase.css` gives `#top` no order;
  `showcase-test.php` pins both. To switch it on (owner's yes only): drop `#view-home > .hero`
  from that redesign.css list and add `#view-home > #top { order: 5 !important; }`.
- `#nextDepBox` stays hidden even then (`hd.css`): `startNextDeparture()` mixes both
  directions, reads the device clock against IST times and assumes a daily bus. Hidden, it
  never ticks (it checks `offsetParent`). The ne label now reads as a countdown
  ("अर्को प्रस्थानसम्म बाँकी").

## 2. Heritage band + content-page heroes
- `#homeIndiaNepal .hx-band` → `nepal-heritage` (800 / 1600 w), `aspect-ratio: 2 / 1`
  (max-height 460 px) instead of the fixed-height crop, so the Buddha and the stupa at the
  edges survive; flags overlay unchanged.
- `/india-nepal` and `/explore` heroes → the same montage (`.hero-art.art-2x1`); the
  Article JSON-LD image follows. `nepal-band.*` files stay (ticket / chalani art). The hero
  keeps `fetchpriority="high"` (it is each page's LCP image).
- The band's alt text is set at runtime by `syncAlt()` from `hxBandAlt`, so that key now
  describes the new montage and says "Illustration" in en / hi / ne.
- `/india-nepal`: `gautam-buddha` portrait beside the timeline from 720 px (contained,
  max-height 340 px, sticky), under it on a phone, captioned "Illustration".
- `/offices` hero: `bus-hero` render — its one place on the site — captioned as a
  promotional render in en / hi / ne.

## 3. Home place cards, travel guide, tours
- `#hxPlaces`: a real `<img>` per card (lumbini-t / pokhara-t / bardiya-t, lazy, sized,
  "Illustration" tag, `aria-hidden` so it does not join the link's name); the CSS art stays
  underneath as the fallback.
- The live cards: once `/api/explore.php?what=featured` answers, `24-homeinfo.js` redraws
  `#hxPlaces` from the database. The API now sends `imageIllus` (from
  `ContentPages::isIllustration()`: an `assets/img/hd/` picture, or a credit starting
  "Illustration"), and the JS draws the same `<img class="hx-card-img">` + "Illustration" tag
  (an `/assets/img/hd/` path counts too, for a cached old payload). A real uploaded photo gets
  no tag.
- `/tours` list cards print the tag too (`.ph-ill`: "Illustration", or the photo credit for a
  real photo).
- `database/upgrade-2026-10-10-hd.sql`: 5 `travel_places` + 4 `tour_packages` rows get
  `image` (the `-m` files) and `image_credit` = "Illustration - inspired by the place, not a
  photograph" — **only where image is NULL/''** (guarded with information_schema + PREPARE,
  idempotent, never over an office upload). The credit label changed from "Photo: %s" to
  "Image: %s" / "चित्र: %s" in the three word tables; the tour detail page now prints it too.
  `tools/content-seed.php` never writes `image`, so a re-seed keeps these.

## 4. Illustrations in context (each once, lazy, honest alt)
| Artwork | Where | Seen by visitors? |
|---|---|---|
| support-guide | `help.php` title row; `#bookHelp` guide header (hidden under 480 px) — the new 128 px `support-guide-s` export | yes |
| quick-ticket | `#quick-ticket` banner, right side from 761 px; hidden on phones (form first) | yes (desktop) |
| track-journey | `#homeLive` (`-t`, 480 px, shown at 220 px); `track.php` title row (new 128 px `track-journey-s`) | yes |
| gautam-buddha | `/india-nepal` beside the timeline | yes |
| bus-hero | `/offices` hero; the OG card | yes |
| nepal-heritage | home band, `/india-nepal`, `/explore` | yes |
| lumbini / pokhara / bardiya / nepalgunj-temple / gujarat-city | home place cards, `/explore`, `/tours` (via the migration) | yes |
| bus-cutout | the staged `#top` hero | **no** — until the owner says yes (see 1) |
| private-sleeper / sharing-sleeper / family-travel | **not placed** | no |

Review fix: the first build put the two sleeper concepts in `#styleHighlight` and the family
picture in the `#facilities` "For every passenger" card, and swapped the amenity-chip emoji
for sprite icons there. `redesign.css` hides both sections outright ("unverifiable claims …
stay out until the office confirms them"), so nobody would ever have seen them — that markup
and its `hd.css` rules were taken out again (the sections are byte-identical to before).
The three files wait in `assets/img/hd/` for a visible, honest place the owner approves.

The seat-legend icons were taken out as well: the hard-coded seat colours (purple
women-only, light grey booked) did not match the theme-token colour boxes beside them.
The colour box stays the key.

## 5. `#homeLive` — live bus tracking, honestly
New section (order 10, between `#homeRoute` 9 and `#homeIndiaNepal` 11, inside the HOMEINFO
block): row 1 "GPS tracking from your ticket — live today whenever the driver's phone is
sharing" (`data-track-bus`); row 2 "Fleet GPS unit + CCTV live view" with the badge
"Coming soon · छिट्टै". Keys `hxLive*`, `hxIllus`, `hdNextDep` in en / hi / ne
(`hdConcept` was dropped with the sleeper concepts). The picture's figure is `aria-hidden`.
Pinned in `showcase-test.php` (direct child, order map) and `homeinfo-test.php` (five
sections, source order).

## 6. Icon sprite
Only the icons the page uses are `<symbol id="s-<name>" viewBox="0 0 48 48">` in the inline
sprite (`stroke="#154FAA"` → `currentColor`; the gold accent on s-charging kept): s-support
and s-mobile-booking (nav / mobile-menu Help Desk + QuickBot rows, class `ic-s`, sized
1.15em by `hd.css` — the Lucide icons beside them keep 1em), s-location and s-cctv
(`#homeLive`), s-sleeper / s-charging / s-shield / s-clock (the staged `#top`). The other 23
stay in `assets/img/icons/` only (review fix: no dead SVG parsed on every load). Admin pages
untouched.

## 7. Open Graph card
`tools/make-og.php` (CLI, GD, run once) composes `assets/img/og-shg.png` 1200x630: navy →
royal gradient, the `bus-hero` render contained on the right (never cropped, gold rule),
the emblem via `DocArt::mark()`, the company name from `Settings::company()`, flags via
`DocArt::flagPair()`, "India ⇄ Nepal · AC Sleeper" (arrows drawn — Noto has no U+21C4),
the web address, and a small "Illustration" pill on the render (review fix: a share preview
must not pass the render off as the fleet). Under 400 KB (palette fallback).
`brand-flags-test.php` checks it. The URL is unchanged (`/assets/img/og-shg.png`), so
WhatsApp / Facebook keep a cached old card until the integrator gives the og:image a new
`?v=` (index.php, includes/publiclayout.php, includes/routepages.php, travel-info.php).

## Tests (all green in the HD clone, server on :8962 for the HTTP suites)
showcase-test, homeinfo-test, home-premium-test, brand-flags-test, public-files-test,
content-pages-test, asset-version-test, site-facts-test, `node tests/i18n-check.js`,
`node --check` on the touched JS, `php -l` on every touched PHP file.

## Deploy notes
1. Apply `database/upgrade-2026-10-10-hd.sql` on live (`php tests/apply-sql.php …` or
   phpMyAdmin) — safe before or after the files.
2. Files: `app.template.html`, `assets/css/hd.css` (new), `app.css`, `homeinfo.css`,
   `premium.css`, `redesign.css`, `showcase.css`, `views.css`, `assets/js/04-i18n.js`,
   `05-router.js`, `24-homeinfo.js`, `sw.js`, `api/explore.php`,
   `includes/contentpages.php`, `help.php`, `track.php`, `assets/img/og-shg.png`,
   `assets/img/hd/support-guide-s.webp` + `track-journey-s.webp` (new),
   `tools/make-og.php`, the three tests.
3. The integrator bumps the asset stamp + `sw.js` VERSION once for all packages.
