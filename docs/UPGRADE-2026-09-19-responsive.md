# Responsive and loading maintenance — prepared, not deployed

Candidate: `shg-v133`, asset stamp `20260919d`.

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

## Release blockers and next steps

1. Hostinger server registrations exist locally, but no Hostinger tools are exposed
   to this task. Reopen the app to try loading the configured connector, then verify
   account/site access. No hosting configuration or live code was changed.
2. Git has only `vps` pointing at `shari-vps:/root/shg-site.git`; no GitHub remote
   or repository URL was found in deployment docs. The owner does not know its URL.
   Identify the correct repository in the authenticated GitHub account before
   adding a remote or pushing. The workflow has not run on GitHub.
3. PHP is not available on this machine's PATH and no local test database was
   configured. Run PHP lint, `tests/asset-version-test.php`, and the full
   `tests/run-all.php` against the documented isolated `shari_test` environment.
   Never run the database test battery against production.
4. Fetch and compare the latest VPS commit before release: existing docs say a
   push to `vps/main` immediately deploys. Preserve any newer server changes and
   use the documented staging/backup/release process. No push was made here.

Prior upgrade notes report a WhatsApp ticket-template mismatch. This task did
not inspect current account settings, so that incident remains unverified.
