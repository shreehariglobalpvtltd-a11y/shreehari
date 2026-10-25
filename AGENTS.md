# shreehariglobal.in — LIVE PRODUCTION

This folder is the live site (nginx + PHP 8.3-FPM + MySQL db `shari`). Every saved file is live immediately.

- Git history is stored in `/root/shg-site.git` (`.git` here is only a pointer). Run `git status` before editing and commit after each working change. Undo with `git checkout -- <file>` or `git revert <sha>`.
- Full tarball snapshots (including config) are in `/root/backups/`.
- After editing a PHP file run `php -l <file>`, then check `curl -s -o /dev/null -w "%{http_code}" https://www.shreehariglobal.in/`.
- Never print, copy or commit `config/config.php` (live DB credentials, APP_KEY) or the Twilio values in the `settings` table.
- Files belong to `www-data`. Codex runs as root, so after creating a file run `chown www-data:www-data <file>`.
- Cron jobs in `cron/` run every 5–30 minutes against the live DB — test changes there carefully.

## Continuous platform work (26 October 2026)

- At session start read `docs/PLATFORM-STATE.md` and inspect Git status, current
  branch, upstream changes and the relevant code. Older docs are historical,
  not evidence of what is currently live. A separate development checkout is
  not the production webroot described above.
- Update that document with verified changes, tests and unresolved limitations
  at session end. Never infer runtime health from source/test-file presence.
- Preserve concurrent work and all existing identities/financial records.
  Production release requires explicit owner approval after staging validation.
- Release tooling and its current limits are documented in
  `docs/RELEASE-SAFETY-20261026.md`; never force an existing release ref.
