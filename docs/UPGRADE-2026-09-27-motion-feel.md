# Motion & feel — the client pass (27 Sep 2026)

Owner's brief (26 Sep, evening, Nepali): the site must feel instant and
luxurious — an entrance animation on every screen and ONE signature opening
with marketing words — yet the home page stays still at rest (25 Sep rule);
delete dead code; everything ON that can safely be ON.

Branch stream: **Motion & feel (client)**. Base `85ddde6`. Seven commits, in
this order; each is independently revertable.

## What changed

| # | Commit | What the visitor sees |
|---|--------|-----------------------|
| 1 | splash: signature opening | The opening lasts at least **1.4 s** (still never shorter than the boot). It is shown on a cold start and **again when the last showing is more than 6 hours old** (`localStorage shg:splashAt`); within a session it is shown once as before; deep links still skip it. The static promise line became **three lines** that enter 450 ms apart — `heroTitle` (markup stripped), `bbTag`, `premiumPromise` — in the app's language, Nepali when `lang=ne`. Title and tagline are translatable (`splashTitle`, `splashTagline` in en/hi/ne). |
| 2 | motion: one enter keyframe | `.view.active`, the modal, the menus and the sheets all arrive on one keyframe, **`shgEnter`** (opacity + translateY 10 px, 190 ms, `--m-spring`). `#view-seats` / `#view-checkout` keep `flowViewIn` on phones (the fixed action bar). New public switch **`app_motion_on`**. |
| 3 | home: still at rest | Every looping decoration on the home page runs only while its block is **on screen** (`.is-live`, IntersectionObserver in 22-vip.js) **and** the page is **awake** (`html.shg-awake`: 6 s after the splash and after every touch / key / wheel / scroll). Otherwise it is paused in place. The four chips show a **▶** once, when the band scrolls into view. |
| 4 | network: loading bar + tick | A 2 px navy → saffron bar along the top after a request has been pending 250 ms (`html.net-busy::after`), gone at zero. One soft tick (peak 0.024) per request that passes 600 ms — behind the Sound switch, the first gesture and the new public switch **`app_load_sound_on`**. |
| 5 | splash: dead code removed | The 8-scene trailer, the portal strip, the sound chip, the intro7 road and the hidden floating-emoji layer: 412 lines of markup, CSS and JS that nothing could reach. |
| 6 | tests | `tests/motion-check.js` (61 checks) + `tests/motion-baseline.json`; `feature-story-check.js` and `motion-check.js` registered in `tests/run-all.php`. |
| 7 | SQL + admin help | `database/upgrade-2026-09-27-motion-switches.sql` (both rows, `svalue 0`, public, group `site`, Nepali + English label). Help text on Admin → Settings. |

## The two switches

Both are read by `shgSwitchOn()` (02-config.js) from `SHG_BOOT.settings`.
**An absent row means ON**, so a site that has not run the SQL behaves like
one that has and switched both on. Only `false` / `0` / `'0'` mean off — the
same test 19-premium.js applies to `app_mantra_on`.

- `app_motion_on = 0` → `html.no-motion`: every entrance instant (1 ms, one
  pass — the same rules the app applies for `prefers-reduced-motion`), the
  splash keeps no floor and no rotating lines. Stamped in the second script,
  so the very first view already renders without motion.
- `app_load_sound_on = 0` → the bar alone, no tick.

Live action for the integrator: **set `app_motion_on = 1` and
`app_load_sound_on = 1`** (the owner asked for ON). Nothing in this stream
touches crontab, nginx, the asset stamp or `sw.js`.

## How "still at rest" is decided

```
runs  =  block.is-live  AND  html.shg-awake
```

- `.is-live` is toggled by one IntersectionObserver over: the hero, the brand
  band, QuickBot, the road scene (`#routeAnim`), the route strip
  (`.shg-hero`), the founder card, the footer CEO, the flags, the live
  ticker, the VIP cabin and the offer card.
- `html.shg-awake` is set for 6 s after `shg:splashdone` (dispatched by
  `Splash.finish()`), and re-armed for 6 s by every pointerdown / touchstart /
  keydown / wheel / scroll (throttled to once a second). A hidden tab sleeps
  at once.
- premium.css §11 pauses the loops otherwise with `animation-play-state:
  paused` — compositor-only, no restart jump. Every paused keyframe rests at
  its 0 % pose (the four chip keyframes in vip.css were re-timed for this).
  Two exceptions are **switched off and parked** because frame 0 would hide
  them: the metallic sheens (`background-position: 40% 0`, the same pose the
  11 Sep phone pass uses) and the coach of the road scene (parked mid-road,
  as under reduced motion).
- Fixed chrome (the two fabs, the ribbon divider, the nav logo) has no block
  to be inside and follows the awake window alone.

Reading of the brief: "touched OR first 6 s" is implemented as a **re-armed
window**, not a sticky flag — a sticky flag would let the hero loop for ever
after the first tap, which is the very thing the 25 Sep rule forbids.

## Guardrails (docs/UPGRADE-2026-09-23-ui-ux-v3.md) — kept

- Every new keyframe is opacity + transform only (`shgEnter`, `spLineIn`,
  `spLineOut`, `netBusy`, `bbHint`) — asserted by the test.
- No new animation or transition of left / top / width / height / box-shadow
  / filter / background-position. The 88 the tree already carried are listed
  in `tests/motion-baseline.json`; anything new fails
  `node tests/motion-check.js`. Regenerate with `--write-baseline` only after
  reading the diff.
- No blur on a scrolling surface was added; nothing was added to `sw.js`.

## Traps met

- **`grep -c $'\r$'` lies in this shell** — it reported every line as CRLF
  on LF files. `git ls-files --eol` and a node count are the truth. The tree
  mixes both: `app.template.html`, `views.css`, `04-i18n.js`,
  `13-admin-routes.js`, `index.php`, `run-all.php` are CRLF; `premium.css`,
  `vip.css`, `feature-story.css`, `02-config.js`, `19-premium.js`,
  `22-vip.js`, the SQL and the docs are LF. Every edit here kept the file's
  own ending (`git diff --numstat` == `--ignore-space-at-eol --numstat`, and
  `git ls-files --eol` shows no `mixed`).
- `applyLang()` runs AFTER `Splash.init()` in `init()`. The splash title
  therefore carries **no** `data-i18n` (innerHTML would wipe the letter spans
  mid-reveal); `Splash.localise()` translates it and sets the element's own
  `lang` so premium.css §6 (Devanagari face, no tracking) applies before
  `<html lang>` is set. The rotator element has no `data-i18n` either.
- A paused animation freezes its **current** frame. `heroFloat`,
  `ra3Drive` and `hd-sheen` are invisible at 0 % — see "parked" above.
- The `.brand-band` chips are moved inside `<button class="bb-open">` by
  20-feature-story.js at DOMContentLoaded; they stay descendants of
  `.bb-anim` and `.brand-band`, so the selectors hold.
- `feature-story.css` is injected by 20-feature-story.js at script load (not
  on tap), so the ▶ hint rule can live there.

## Verify without a browser

```
node tests/motion-check.js            # 61 checks
node tests/i18n-check.js              # key parity, splashTitle / splashTagline
node tests/feature-story-check.js
```

In a browser: open the site in a fresh tab — the opening holds ~1.4 s and the
three lines enter one after another; reload within the same tab — no splash;
set `localStorage.shg:splashAt` to `0` and reload — the splash is back. On
the home page, stop touching for 6 s: the chip icons, the fab pulse and the
road scene stop; touch — they run again. Throttle the network in devtools:
the 2 px bar appears after 250 ms of a pending request.

## Rollback

Each commit reverts cleanly on its own. Reverting commit 3 alone brings the
loops back; reverting commit 1 alone restores the boot-only splash. The SQL
rows are harmless if left behind (absent = on, 0 = off, 1 = on).
