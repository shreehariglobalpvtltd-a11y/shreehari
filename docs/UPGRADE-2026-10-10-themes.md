# Three brand looks: Professional (default), White & Blue, Golden & Orange — 10 Oct 2026

Owner (10 Oct 2026, voice-typed): *"this app should be default colour professional; white-blue
different version; golden and orange different version, inspired with logo colour."* The logo is a
blue globe with an orange / gold arrow, a blue ship, golden containers and a white / blue coach. The
HD visual guide that came with the owner's picture pack asks for a calm navy / mist-blue / ivory
palette with gold only as a small accent.

Built on the 9 Oct palette system (assets/css/themes.css, the "🎨 रूप · Appearance" sheet). One CSS
file, one JS file, the template's pre-paint whitelist, the Admin dropdown, one settings UPDATE.
No new asset, no stamp bump (the integrator bumps once), no new dependency.

## 1. What was already there (not rebuilt)
- `html[data-palette="<id>"]` palettes in themes.css: 12 generated Material You looks, each a light,
  dark and AMOLED one-line block that re-points the Material 3 roles (`--md-*`) AND the design-system
  tokens the whole app reads (`--royal` family, `--navy`, `--page`, `--surface*`, `--line*`, `--ink*`,
  `--muted`, `--focus`) plus the classic-3D depth atoms (`--pl*` / `--pd*` / `--pa*`).
- The Appearance sheet (03-accounts.js `SHG_PALETTES`), the pre-paint whitelist `P` in
  app.template.html, the index.php owner-default inject (`app_theme_palette`, applied only where the
  device saved nothing), Admin → Settings → Website & app dropdown, and tests/theme-palette-test.php
  which pins all of it (one id list everywhere, every contrast pair ≥ 4.5:1, status-bar colours equal).

## 2. The three brand looks (hand-tuned, not generator output)
Ids: `professional`, `whiteblue`, `goldenorange`. They sit at the head of section 3 of themes.css with
a comment marking them as the exception to "edit the generator". Each carries EXACTLY the token set of
the himalaya lines; every design token was typed by hand, only the classic-3D depth atoms were re-hued
from the himalaya lines (OKLCH hue + chroma shift, lightness kept), so the Classic 3D style's slabs and
rims follow the new hue too.

| | professional (default) | whiteblue | goldenorange |
|---|---|---|---|
| primary / on | `#154faa` / white | `#0054a8` / white | `#b4530a` / white (5.0:1) |
| primary container / on | `#dce8fa` / `#0b2a5e` | `#d9e7fa` / `#002a5c` | `#ffdcc2` / `#3a1700` |
| tertiary (accent) | warm gold `#b97813`, container `#d59a37` / `#3b2600` | sky `#0b6fb8`, container `#d6ecff` | gold `#8a5b00`, container `#ffe1a3` |
| ink / muted | `#16334f` / `#526b7d` | `#0b1b33` / `#4f6079` | `#2b1d0e` / `#6b5440` |
| page / card / line | `#eff6fb` / `#fff` / `#d8e5ef` | `#fff` / `#fff` / `#e3e9f2` | `#fff8f0` / `#fff` / `#eed9c4` |
| navy (strips, hero) | `#0f2f66` | `#0c306c` | `#3a1f05` |
| dark page / surface / primary | `#0a1018` / `#121d29` / `#b8d5ff` on `#0b2a5e` | `#0b1220` / `#141e30` / `#9cc4ff` | `#15100b` / `#221a12` / `#ffb26e` on `#4a2300` |
| AMOLED page / surface | `#000` / `#0c141e` | `#000` / `#0d1522` | `#000` / `#160f09` |

Gold is an accent only: it reaches the page through `--md-tertiary-container` (homeinfo tiles) and the
footer's gold heading class (`--brand-gold` → `--on-navy-muted`), never as a text colour on white.
The goldenorange light hero ends a step darker than its primary (`#9a4408`) so the white title and the
muted tag keep ≥ 4.5:1 on the far corner; whiteblue's `--on-navy-muted` is `#c6d4ec` for the same
reason. Status, seat and brand-red tokens are never re-pointed (tested).

### The strips above and below the home page
Light + dark already read the tokens (redesign.css: `.nav` / `.flow-head` / `footer` = `--navy`,
`.topbar` / `.divine-bar` / `.bottom-nav` = `--surface*` + `--line`). On AMOLED depth.css painted the
footer, flow head, phone menu, five-tab bar, the hero's keys and the language pop in the brand's
blue-black as literals; section 7 of themes.css now re-points those to the palette's own navy /
surface / line under ANY palette (0,4,0 / 0,3,1 beats depth.css), so goldenorange stays warm and
professional stays mist-blue on a black screen. The footer's red top rule is untouched.

## 3. Where the three are registered
- `assets/js/03-accounts.js` — three new `SHG_PALETTES` rows FIRST (swatch tri-tone, status bar
  [light, dark], dark page, names ne / en / hi: "प्रोफेसनल (मूल) / Professional (default) /
  प्रोफ़ेशनल (मूल)", "सेतो-निलो / White & Blue / सफ़ेद-नीला", "सुनौलो-सुन्तला / Golden & Orange /
  सुनहरा-नारंगी"); `SHG_BRAND_LOOK_IDS`; the colour grid renders the S Hari (default) swatch, then
  a plain-text label "Brand looks · ब्रान्ड रूप", the three, a label "More colours · Material You",
  then the 12. Labels are `<span class="ap-grp" aria-hidden="true">` — not radios, so the roving
  tabindex and arrow keys skip them.
- `app.template.html` — the pre-paint whitelist `P` gains the three with [light status bar, dark
  status bar, dark page]; index.php's inject reads the same object (`window.SHG_PAL_TC`), so the
  owner default now applies before first paint wherever the device saved nothing.
- `admin/settings.php` — dropdown entries (brand looks first) + help text.
- `assets/css/themes.css` — the nine palette lines, section 7 AMOLED strip rules, `.shg-ap .ap-grp`.

## 4. Migration
`database/upgrade-2026-10-10-themes.sql` (applied to the local test DB; apply on live by hand):
```sql
UPDATE `settings` SET `svalue` = 'professional' WHERE `skey` = 'app_theme_palette' AND `svalue` = '';
UPDATE `settings` SET `is_public` = 1 WHERE `skey` = 'app_theme_palette';
```
Idempotent: the row is created by upgrade-2026-10-09-themes.sql (blank = brand colours); this only
moves a row that is STILL blank, so an owner who already chose a theme in Admin keeps it.
A visitor who already picked a colour on their phone (localStorage `shg:palette`) keeps that too.

## 5. Tests
`tests/theme-palette-test.php` now expects 15 palettes (12 + 3), checks the three ids exist, that the
brand looks lead `SHG_PALETTES`, the group labels + `.ap-grp` rule, the "(default)" names, and the
10 Oct SQL (UPDATE where blank, keeps public, 9 Oct file still inserts the blank default). Every
other assertion is unchanged: 15 × 3 modes × 9 contrast pairs, AMOLED page black / surface
near-black, one id list in four places, status-bar colours equal, dark before AMOLED.

Also run (unchanged, must stay green): tests/depth-display-test.php, tests/showcase-test.php,
tests/home-premium-test.php, tests/asset-version-test.php, `node tests/i18n-check.js`.

## 6. Owner decisions still open
- The "S Hari (मूल / default)" swatch keeps its name although professional is now the default for
  visitors; rename to "S Hari (ब्रान्ड / brand)" if that reads better.
- Whether the Admin default should ever go back to blank (brand): choose it in the dropdown; the
  migration will not re-apply because it only touches a blank row.
