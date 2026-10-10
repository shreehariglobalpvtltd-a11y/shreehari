# Three brand looks: Professional (default), White & Blue, Golden & Orange — 10 Oct 2026

Owner (10 Oct 2026, voice-typed): *"this app should be default colour professional; white-blue
different version; golden and orange different version, inspired with logo colour."* The logo is a
blue globe with an orange / gold arrow, a blue ship, golden containers and a white / blue coach. The
HD visual guide that came with the owner's picture pack asks for a calm navy / mist-blue / ivory
palette with gold only as a small accent.

Built on the 9 Oct palette system (assets/css/themes.css, the "🎨 रूप · Appearance" sheet). One CSS
file, one JS file, the template's pre-paint whitelist, one guard line in index.php, the Admin
dropdown, one settings upsert. No new asset, no new dependency. The asset stamp is bumped once by
the integrator for the whole 10 Oct release — see **Deploy order** below, it matters here.

## 0. Deploy order (read this first)
1. Deploy the build that carries the three palettes **with the asset stamp bump** (every `?v=` in
   app.template.html + `ASSET_VER` and `VERSION` in sw.js). themes.css and 03-accounts.js changed;
   the service worker serves `/assets/*` cache-first by stamped URL, so without a bump a returning
   phone runs the new HTML against the old CSS/JS.
2. Only **after** that build is live, run `database/upgrade-2026-10-10-themes.sql` (or pick
   Professional in Admin → Settings → Website & app → Default colour theme).

What happens if the order is wrong: an older cached themes.css does not know `professional`; the
generic palette alias (`--royal: var(--md-primary)`) and the base alias (`--md-primary: var(--royal)`)
would point at each other, both go invalid, and every button / link would lose its blue. The
**stale-stylesheet guard** in index.php's head inject (10 Oct) runs after the stylesheets and reads
the computed `--md-primary`: a palette the CSS knows always resolves it; unknown → it drops
`data-palette` and the S Hari brand look paints exactly as before. So the worst case is "old colours
until the phone picks up the new stamp", never a broken page. The guard never fires on a normal deploy.

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
**top app bar (`.nav` / `.nav.scrolled`)**, footer, flow head, phone menu, five-tab bar, the hero's
keys and the language pop in the brand's blue-black as literals; section 7 of themes.css now
re-points those to the palette's own navy / surface / line under ANY palette (0,4,0 / 0,3,1 beats
depth.css whatever the load order), so goldenorange stays warm and professional stays mist-blue on a
black screen. The footer's red top rule and the nav's red bottom rule are untouched. Deliberately
NOT re-pointed: the animated scenes (`.sa-scene`, anim.css's own night sky `--sa-navy-2`) and the AI
chat head (a `--blue` gradient) — both are the same in light and dark under every palette, so they
are not strips and changing them only on AMOLED would make them inconsistent.

## 3. Where the three are registered
- `assets/js/03-accounts.js` — three new `SHG_PALETTES` rows FIRST (swatch tri-tone, status bar
  [light, dark], dark page, names ne / en / hi: "प्रोफेसनल (मूल) / Professional (default) /
  प्रोफ़ेशनल (मूल)", "सेतो-निलो / White & Blue / सफ़ेद-नीला", "सुनौलो-सुन्तला / Golden & Orange /
  सुनहरा-नारंगी"); `SHG_BRAND_LOOK_IDS`. The logo's own colours are now named **"S Hari (ब्रान्ड) /
  S Hari (brand) / S Hari (ब्रांड)"** — only professional says "(default)". The colour section holds
  TWO radiogroups, each named by its own label through `aria-labelledby` (`<p class="ap-grp"
  id="apGrpBrand">` "Brand looks · ब्रान्ड रूप" = S Hari + the three looks; `id="apGrpMore"`
  "More colours · Material You" = the 12), so a screen reader hears the grouping a sighted visitor
  sees. `rove()` gives each group one tab stop, arrow keys stay inside a group,
  `mark('[data-palette-set]')` clears the checked state across both. The whiteblue swatch's "white"
  wedge is the light primary container `#d9e7fa` (pure white vanished on the light card).
- `app.template.html` — the pre-paint whitelist `P` gains the three with [light status bar, dark
  status bar, dark page]; index.php's inject reads the same object (`window.SHG_PAL_TC`), so the
  owner default now applies before first paint wherever the device saved nothing.
- `index.php` — one line after the owner-default inject: the stale-stylesheet guard (section 0).
- `admin/settings.php` — dropdown entries (brand looks first) + help text.
- `assets/css/themes.css` — the nine palette lines, section 7 AMOLED strip rules (incl. `.nav`),
  `.shg-ap .ap-grp`.

## 4. Migration
`database/upgrade-2026-10-10-themes.sql` (applied to the local test DB; apply on live by hand,
AFTER the stamped build is live — section 0):
```sql
INSERT INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('app_theme_palette', 'professional', 'string', 'app', 'Default colour theme for visitors (blank = S Hari brand colours)', 1)
ON DUPLICATE KEY UPDATE
 `svalue` = IF(`svalue` = '', 'professional', `svalue`),
 `is_public` = 1;
```
Self-sufficient and idempotent: where upgrade-2026-10-09-themes.sql was never applied the INSERT
creates the row (same column list); where the row exists only a STILL-blank value moves to
professional, so an owner who already chose a theme in Admin keeps it, and re-running changes nothing.
A visitor who already picked a colour on their phone (localStorage `shg:palette`) keeps that too.
Verify: `SELECT skey, svalue, is_public FROM settings WHERE skey = 'app_theme_palette';` → one row,
`professional` (unless the owner chose another theme), `is_public = 1`.

## 5. Tests
`tests/theme-palette-test.php` now expects 15 palettes (12 + 3), checks the three ids exist, that the
brand looks lead `SHG_PALETTES`, the two labelled radiogroups + `.ap-grp` rule, that only professional
is "(default)" (S Hari is "(brand)"), the whiteblue swatch, the `.nav` AMOLED rule, the index.php
stale-stylesheet guard, and the 10 Oct SQL (INSERT with the 9 Oct column list, ON DUPLICATE KEY
moves only a blank value, deploy order + verify SELECT in the file; 9 Oct file still inserts the blank
default). Every other assertion is unchanged: 15 × 3 modes × 9 contrast pairs, AMOLED page black /
surface near-black, one id list in four places, status-bar colours equal, dark before AMOLED.

Also run (unchanged, must stay green): tests/depth-display-test.php, tests/showcase-test.php,
tests/home-premium-test.php, tests/asset-version-test.php, `node tests/i18n-check.js`.

## 6. Owner decisions still open
- Whether the Admin default should ever go back to blank (brand): choose it in the dropdown; the
  migration will not re-apply because it only touches a blank row.
- How much gold should show (today: the home-tile accent container and the footer's gold headings).
- Whether the Nepali / Hindi names of the three looks and the two group labels read naturally.
