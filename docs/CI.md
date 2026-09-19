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
php tests/run-all.php --http
```

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
