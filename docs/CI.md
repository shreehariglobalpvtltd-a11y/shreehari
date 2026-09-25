# Automated checks

The `Application checks` workflow runs on pushes, pull requests, and manual
dispatch. It uses PHP 8.3 and Node 24 with read-only repository permissions.

It checks:

- PHP syntax throughout the source tree.
- JavaScript syntax in the app, tests, service worker and recovery worker.
- PWA manifest JSON, asset-version consistency and precached file existence.
- Offline ticket behavior, failed-download retry behavior and English/Hindi/Nepali
  translation completeness.

These checks need no database, production configuration or API credentials.
The workflow does not deploy or send messages. Pushes to the existing `vps/main`
remote remain a separate production deployment operation.

The full booking and agent regression battery requires the existing isolated
`shari_test` database and PHP HTTP server. It must not run against production:

```bash
cd /root/shg-test
bash tests/ci-setup.sh                                  # rebuild shari_test from the repository
php -S 127.0.0.1:8899 -t . tests/dev-router.php &       # the dev server, routed like nginx
php tests/run-all.php --http
```

Start the server **with `tests/dev-router.php`**. Without a router script the
built-in server decides for itself which URIs are static files, and that
decision differs between PHP releases: on 8.3 the CI runner answered
`/sitemap-routes.xml` with its own 404 page, so three route-page checks went
red while the same battery was green on 8.4. The router states nginx's
`try_files $uri $uri/ /index.php` rule explicitly, and refuses the same paths
nginx refuses (`/config/`, `/includes/`, `/tests/`, `app.template.html`,
dotfiles), so a suite cannot pass here by reaching something production
blocks.

Run the battery against a **freshly built** database. Every suite writes real
bookings, and a database that has already run the battery a few times drifts
far enough that suites start failing on each other's leftovers — which is why
a local pass on a reused database says nothing about CI.

A real browser check runs after the battery, driving the app the way a
passenger does — home page, language switch, search, seat map — and failing on
any console error, any failed request from this site, or any floating button
parked on top of a seat:

```bash
node tests/browser-smoke.mjs
```

It needs Chromium. It uses `CHROME_PATH`, then `/usr/bin/google-chrome` or
`/usr/bin/chromium`, then a Playwright browsers directory, and if it finds
none it says so and exits 0 rather than failing a machine that simply has no
browser. Install the driver with `npm install --no-save playwright-core`.

Node must be on PATH for that runner to finish with zero missing suites. Its
three Node suites can also run on the development machine:

```bash
node tests/offline-ticket-test.js
node tests/lazy-retry-test.js
node tests/i18n-check.js
```

The two historical suites excluded explicitly by `tests/run-all.php` remain
excluded; a successful workflow does not imply those suites passed.

## GitHub connection

The connected GitHub account has an empty repository at
`https://github.com/shreehariglobalpvtltd-a11y/shreehari`.
The local `origin` remote points to that repository; the existing `vps` remote
and `main` tracking configuration are preserved.
Source publication and GitHub workflow execution are pending the repository
visibility decision. No source has been uploaded to that public repository.
Keep production config, local credentials, generated passenger files and
backup copies out of any GitHub upload.

Preparation verified on 19 September 2026: actionlint 1.7.12 accepted the
workflow; PHP 8.3 accepted the updated runner; JavaScript syntax and manifest
JSON passed; offline tickets passed 21 checks, lazy retry passed, and all 709
translation keys were present in each language. GitHub-hosted execution has
not yet been verified.
