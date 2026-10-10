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
- Order 5 in `showcase.css` (after QuickBot, before the demo) — it is **visible again**, as
  the owner's pack intends. `#homeHero` stays first; the hero image is lazy, nothing new is
  eager or `fetchpriority=high`.

## 2. Heritage band + content-page heroes
- `#homeIndiaNepal .hx-band` → `nepal-heritage` (800 / 1600 w), `aspect-ratio: 2 / 1`
  (max-height 460 px) instead of the fixed-height crop, so the Buddha and the stupa at the
  edges survive; flags overlay unchanged.
- `/india-nepal` and `/explore` heroes → the same montage (`.hero-art.art-2x1`); the
  Article JSON-LD image follows. `nepal-band.*` files stay (ticket / chalani art).
- `/india-nepal`: `gautam-buddha` portrait beside the timeline from 720 px (contained,
  max-height 340 px, sticky), under it on a phone, captioned "Illustration".
- `/offices` hero: `bus-hero` render — its one place on the site — captioned as a
  promotional render in en / hi / ne.

## 3. Home place cards, travel guide, tours
- `#hxPlaces`: a real `<img>` per card (lumbini-t / pokhara-t / bardiya-t, lazy, sized,
  "Illustration" tag); the CSS art stays underneath as the fallback.
- `database/upgrade-2026-10-10-hd.sql`: 5 `travel_places` + 4 `tour_packages` rows get
  `image` (the `-m` files) and `image_credit` = "Illustration - inspired by the place, not a
  photograph" — **only where image is NULL/''** (guarded with information_schema + PREPARE,
  idempotent, never over an office upload). The credit label changed from "Photo: %s" to
  "Image: %s" / "चित्र: %s" in the three word tables; the tour detail page now prints it too.
  `tools/content-seed.php` never writes `image`, so a re-seed keeps these.

## 4. Illustrations in context (each once, lazy, honest alt)
| Artwork | Where |
|---|---|
| support-guide | `help.php` title row; `#bookHelp` guide header (hidden under 480 px) |
| family-travel | `#homeExtra` "For every passenger" card (beside the chips from 761 px) |
| quick-ticket | `#quickTicket` banner, right side from 761 px; hidden on phones (form first) |
| track-journey | `track.php` title row; the new `#homeLive` section |
| private-sleeper / sharing-sleeper | top of each `#styleHighlight` card, "Interior concept - design sample" |
| gautam-buddha | `/india-nepal` beside the timeline |
| bus-hero | `/offices` hero; the OG card |
| bus-cutout | the home hero |
| nepal-heritage | home band, `/india-nepal`, `/explore` |

## 5. `#homeLive` — live bus tracking, honestly
New section (order 10, between `#homeRoute` 9 and `#homeIndiaNepal` 11, inside the HOMEINFO
block): row 1 "GPS tracking from your ticket — live today whenever the driver's phone is
sharing" (`data-track-bus`); row 2 "Fleet GPS unit + CCTV live view" with the badge
"Coming soon · छिट्टै". Keys `hxLive*`, `hxIllus`, `hdNextDep`, `hdConcept` in en / hi / ne.
Pinned in `showcase-test.php` (direct child, order map) and `homeinfo-test.php` (five
sections, source order).

## 6. Icon sprite
The 31 icons are `<symbol id="s-<name>" viewBox="0 0 48 48">` in the inline sprite
(`stroke="#154FAA"` → `currentColor`; the gold accent and the five self-coloured seat
symbols kept). They replace emoji in `.hero-feats`, `.style-feats`, the `#homeExtra` amenity
chips (chips with no matching symbol keep their emoji), the seat legend (decorative,
`aria-hidden`), the nav / mobile-menu Help Desk + QuickBot rows. Admin pages untouched.

## 7. Open Graph card
`tools/make-og.php` (CLI, GD, run once) composes `assets/img/og-shg.png` 1200x630: navy →
royal gradient, the `bus-hero` render contained on the right (never cropped, gold rule),
the emblem via `DocArt::mark()`, the company name from `Settings::company()`, flags via
`DocArt::flagPair()`, "India ⇄ Nepal · AC Sleeper" (arrows drawn — Noto has no U+21C4),
the web address. Under 400 KB (palette fallback). `brand-flags-test.php` checks it.

## Tests (all green in the HD clone, server on :8962 for the HTTP suites)
showcase-test, homeinfo-test, home-premium-test, brand-flags-test, public-files-test,
content-pages-test, asset-version-test, site-facts-test, `node tests/i18n-check.js`,
`node --check` on the touched JS, `php -l` on every touched PHP file.

## Deploy notes
1. Apply `database/upgrade-2026-10-10-hd.sql` on live (`php tests/apply-sql.php …` or
   phpMyAdmin) — safe before or after the files.
2. Files: `app.template.html`, `assets/css/hd.css` (new), `app.css`, `homeinfo.css`,
   `premium.css`, `redesign.css`, `showcase.css`, `views.css`, `assets/js/04-i18n.js`,
   `05-router.js`, `sw.js`, `includes/contentpages.php`, `help.php`, `track.php`,
   `assets/img/og-shg.png`, `tools/make-og.php`, the three tests.
3. The integrator bumps the asset stamp + `sw.js` VERSION once for all packages.
