# Responsive and loading maintenance — deployed

Live release: `shg-v133`, asset stamp `20260919d`.
Application commit: `c6fef4d89d2baf1fa6e9cd6d92f72109a91cae64`.
Deployed on 19 September 2026, approximately 22:13 IST (16:43 UTC).

## Changes

- First-visit branding minimum reduced from 1500 ms to 600 ms. This removes
  900 ms of artificial waiting when data is ready; it is not a measured reduction
  in server response time. Returning visitors retain the existing 250 ms floor.
- Blocked session storage no longer throws out of splash initialization.
- Assistant/voice/application lazy triggers remain usable after a script download
  fails. Repeated taps share the pending download and replay once on success.
- Travel-style and visiting-card grids shrink below their former 290 px minimum.
- Modal content scrolls within the current viewport, including landscape phones.
- Journey JavaScript, CSS and coach image are precached. The image now uses the
  same versioned URL for warming, display and precaching.
- GitHub Actions checks prepared: PHP/JavaScript syntax, asset stamps, offline
  ticket behavior, lazy-load recovery, and translation completeness.

## Verification

- Headless Edge, public live document with local asset interception (no live writes):
  320, 360, 375, 768 and 1366 px. No page JavaScript errors.
- The two grids were cloned into visible, padded containers because their original
  sections are collapsed. At 320 px, before: 272 px available / 290 px content;
  after: 272 / 272. Wider sizes also fit. This is a component smoke test, not
  an audit of every admin page or checkout state.
- Synthetic long modal at 375 × 320: previously top extended above viewport;
  now contained with vertical scrolling.
- Offline ticket behavior: 21 passed, 0 failed.
- Lazy download failure/retry/rapid-tap behavior: passed.
- Translations: 709 keys in each of English, Hindi and Nepali.
- All application JavaScript and service worker syntax: passed.
- Node equivalent of asset-version inspection: 39 stamps agree; precached files exist.
- Diff whitespace check passed with CRLF recognized (repository uses CRLF).

## Deployment and verification

- The Hostinger connector and SSH both confirmed the existing VPS at
  `93.127.167.249`; this deployment uses its PHP 8.3 application, not shared
  hosting or a new Supabase app. Live root:
  `/var/www/shreehariglobal.in/public_html`.
- Fetched `vps/main` and confirmed base `be6544d`. Pushed the candidate to
  `codex/release-v133-20260919`, refreshed `/root/shg-test` using the existing
  helper, and tested against the isolated local `shari_test` database.
- All application PHP files linted successfully on the VPS. Asset-version test:
  9 passed, 0 failed, with 39 matching stamps and 21 precached paths.
- Core PHP battery: **70 suites passed, 0 failed**. The runner exited 1 solely
  because Node is absent from the VPS (one missing-runtime entry). Both listed
  Node suites ran successfully on Windows: offline tickets 21/21, translations
  709 keys per language. The new lazy-load retry suite and JavaScript syntax
  checks also passed locally. No runner check was removed or bypassed.
- All five additional HTTP suites passed on the isolated test server:
  role gates 62/62, counter HTTP 33/33, feedback 15/15, beacon 20/20,
  PWA HTTP 47/47. The two legacy suites explicitly excluded by the existing
  runner (`automation-test.php`, `boarding-stop-fix-test.php`) remain excluded.
- Before release, archived every changed file that existed, recorded new paths,
  Git HEAD and live working-tree changes, and recorded config checksums in
  `/root/backups/pre-v133-20260919-164330/`. The archive is `files-before.tgz`.
- Fast-forwarded the live checkout to the tested candidate. Existing unrelated
  deletions remained byte-for-byte identical in Git status; config checksums
  matched before and after. No production database/schema migration, credential
  update, webhook change, or service restart was performed.
- Live HTTPS checks passed for the homepage, agent login, new worker/assets,
  offline page and offline desk. The homepage serves asset stamp `20260919d`
  and `sw.js` declares `shg-v133`. The webhook's unauthenticated GET remains
  HTTP 403, as before release; this is not an outbound WhatsApp delivery test.
- Repeated the responsive component smoke test against actual live assets at
  320, 360, 375, 768 and 1366 px: no horizontal overflow or page JavaScript
  errors; the long modal remained contained and scrollable at 375 × 320.

## Remaining follow-up

Git has only `vps` pointing at `shari-vps:/root/shg-site.git`; no GitHub remote
or repository URL is configured. The prepared workflow has not run on GitHub.
This release was deployed directly to the existing VPS; mirror and Netlify
deployments were not changed.

Prior upgrade notes report a WhatsApp ticket-template mismatch. This task did
not inspect current account settings, so that incident remains unverified.
