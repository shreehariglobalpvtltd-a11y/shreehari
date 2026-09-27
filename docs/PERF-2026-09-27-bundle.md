# One script, one stylesheet — the bundle pipeline (27 Sep 2026)

Owner ko kura: site "instant" lagnu parcha. Phone le pahilo screen dekhaunu
aghi aaja 1 HTML + 4 CSS + 18 JS = **22 requests** tancha. Yo kaam le tyo
**2 requests** banauchha. Switch `bundle_assets_on` OFF chha — on garda
matra badlincha, ra bundle purano bhaye aafai purano tarika ma farkincha.

## What it is

| Piece | Job |
|---|---|
| `tools/build.mjs` | Reads the `<script src="/assets/js/…">` and `<link rel="stylesheet" href="/assets/css/…">` tags out of `app.template.html`'s head **in order** (never a list kept in the script) and writes `assets/dist/app.min.js`, `assets/dist/app.min.css`, `assets/dist/manifest.json`. |
| `assets/dist/manifest.json` | Every source file with its bytes and sha256, the two outputs with theirs, the `?v=` stamp the template carried, and which "minifier" each half got. Committed. |
| `includes/assetbundle.php` | At serve time, when `bundle_assets_on` = 1 **and** the template's tags are exactly the manifest's sources in the same order **and** every source still has the recorded size **and** no source is more than 2 s newer than the built files: replaces the run of tags with one `<script defer src="/assets/dist/app.min.js?v=STAMP">` and one `<link rel="stylesheet" href="/assets/dist/app.min.css?v=STAMP">`. Any doubt → the page is served exactly as before and `Logger::warning` says why. |
| `index.php` | `require_once INCLUDE_PATH . '/assetbundle.php'` and `$html = AssetBundle::apply($html)` after the comment strip, before the boot payload is injected. |
| `sw.js` PRECACHE | Both dist files, beside the separate files (the fallback), so a phone that installed with the switch either way opens offline. |
| `database/upgrade-2026-09-25-asset-bundle.sql` | `INSERT IGNORE` of the `bundle_assets_on` row, svalue 0. The row already exists on live. |
| `tests/bundle-test.php` | Registered in `tests/run-all.php` (CORE_SUITES; its HTTP parts skip themselves without :8899). See "What the test proves". |

## Honest numbers (measured on the committed build, stamp 20260926m)

| | Before | After | Saved |
|---|---|---|---|
| Requests before first paint (CSS + JS) | 22 | 2 | **20 requests** |
| CSS raw | 511 KB in 4 files | 401 KB in 1 file | 110 KB |
| CSS gzip / brotli-5 (what the phone downloads) | 131 KB / 123 KB | **82 KB / 76 KB** | **49 KB / 47 KB** |
| JS raw | 1136 KB in 18 files | 1136 KB in 1 file | 0 (joined, not minified) |
| JS gzip / brotli-5 | 365 KB / 340 KB | 354 KB / 308 KB | 11 KB / 32 KB (one compression context instead of eighteen) |
| **Total gzip / brotli** | **496 KB / 463 KB** | **436 KB / 384 KB** | **60 KB / 79 KB** |

Live nginx serves brotli 5 with `brotli_static on`, so the brotli column is
the real one. The CSS half is where the bytes went: the four stylesheets
carry a lot of engineering prose in comments and those cost real bytes even
compressed.

### Why the JS is not minified

This tree vendors nothing: no `node_modules`, no `package.json`, and the VPS
has no node at all. The 25 Sep version of this pipeline (commit d16daf5 on
`live/current`) shelled out to esbuild, which is not here, and the house rule
for this stream was "if esbuild/terser are not installed, do NOT npm install".
A hand-rolled JavaScript minifier would need a real lexer (regex literals,
template literals with nested `${}`, strings containing `//`) and a bug there
would corrupt the whole site the moment the switch is turned on — so the JS
half is a **byte-for-byte join** with `;` between files. Each file is a
classic script talking to the others through globals, and every
`'use strict'` in them sits inside its own IIFE, so joining changes nothing
about how they run; the build refuses a file whose *top-level* directive
prologue is `'use strict'`, because joined that would change the other
files' mode.

The CSS half gets a conservative strip (comments out, whitespace runs to one
space, no space around `{ } ; ,` and `>`, no `;` before `}`; strings and
`url(...)` copied verbatim; `:` and `+`/`-` never touched). The build then
normalises source and result (comments and all whitespace removed) and
refuses to write the strip unless the two are byte-identical — it falls back
to a plain join and records `"css": "concat"` in the manifest. The test
suite repeats that proof in PHP.

Want the other ~40 % on JS? On a **dev machine** (never the VPS) install
esbuild and minify `assets/dist/app.min.js` in place, then re-run
`node tools/build.mjs --check` — it will report that the output differs from
the manifest, which is your cue to record the new sha256 by extending the
build, not by editing the manifest by hand.

## Rebuild — when and how

Run this after **any** edit to a file `app.template.html` loads from its
head (today: 01-boot, 02-config, 03-accounts, 04-i18n, 05-router,
06-results, 07-checkout, 14-counter, 08-signin, 10-track, 11-pdf-ticket,
13-admin-routes, 17-pwa, 18-journey, 19-premium, 20-feature-story, 21-me,
22-vip; app.css, views.css, premium.css, vip.css), after adding or removing
a head tag, and after `deploy/bump-asset-ver.js` (the sources' embedded
`?v=` stamps change, so their sha256 changes):

```
node tools/build.mjs            # writes assets/dist/{app.min.js,app.min.css,manifest.json}
node tools/build.mjs --check    # exit 0 = committed bundle is current, exit 1 = rebuild
git add assets/dist/app.min.js assets/dist/app.min.css assets/dist/manifest.json
```

Commit the three dist files **in the same commit** as the source edit.
`tests/bundle-test.php` fails the battery otherwise (sha256 of every source,
and the git-ancestry check: the commit that last touched a source must be an
ancestor of the commit that last touched the manifest).

The `?v=` stamp is **not** written by the build. `includes/assetbundle.php`
copies it off the tags it replaces, so the bundle URL always carries the
release stamp; the manifest records it only for the test to compare.

## Turning it on (live)

1. Deploy this tree; run `php tests/bundle-test.php` in the test copy (all
   green, including B/C/F over :8899).
2. `UPDATE settings SET svalue='1' WHERE skey='bundle_assets_on';` — or
   Admin → Settings → performance.
3. `curl -s https://www.shreehariglobal.in/ | grep -c 'assets/dist/'` → 2.
4. Roll back = set it to 0. No deploy, no cache to purge: the separate files
   are still on the phone (PRECACHE keeps both sets).

If the log ever shows `Asset bundle is out of date — serving the separate
files`, someone edited a head script without rebuilding; the site is fine,
run the rebuild and commit.

## What the test proves (`tests/bundle-test.php`)

- **A.** manifest readable; scripts and stylesheets listed in the template's
  order; the recorded stamp is the template's; every source has the recorded
  sha256 and size; both outputs match the manifest; JS is sources + headers
  only; CSS is smaller; no source committed after the last build.
- **B.** [HTTP] off → all 18 separate scripts, no `/assets/dist/`; on → one
  bundle script (with `defer`) and one bundle stylesheet, none of the 22
  separate files, the release stamp, and the rest of the head untouched.
- **C.** [HTTP] a manifest missing a script is refused; a source touched in
  the same second (fresh checkout) is **not** called stale, one 10 minutes
  newer is; a source whose size changed is refused whatever its clock says;
  restoring brings the bundle back.
- **D.** every script's own string literals are in the bundle, in order;
  the globals `t toast inr fmtDate CONFIG I18N SFX` survived; no top-level
  `'use strict'`; every stylesheet contributed; stripped CSS normalises to
  exactly the joined sources.
- **E.** sw.js precaches both dist files and still the separate ones; the
  worker and the template agree on the stamp.
- **F.** [HTTP] both files served, as JavaScript and as CSS.

## Not in this pass

- 15-nav.js, 16-lazy.js, journey.css, feature-story.css, terms-data.js stay
  separate on purpose — fetched by URL when first needed.
- `.github/workflows/checks.yml` could run `node tools/build.mjs --check`
  (no npm install needed any more); not edited here, not this stream's file.
