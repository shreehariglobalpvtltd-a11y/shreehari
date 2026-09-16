# shreehariglobal.in — LIVE PRODUCTION

This folder is the live site (nginx + PHP 8.3-FPM + MySQL db `shari`). Every saved file is live immediately.

- Git history is stored in `/root/shg-site.git` (`.git` here is only a pointer). Run `git status` before editing and commit after each working change. Undo with `git checkout -- <file>` or `git revert <sha>`.
- Full tarball snapshots (including config) are in `/root/backups/`.
- After editing a PHP file run `php -l <file>`, then check `curl -s -o /dev/null -w "%{http_code}" https://www.shreehariglobal.in/`.
- Never print, copy or commit `config/config.php` (live DB credentials, APP_KEY) or the Twilio values in the `settings` table.
- Files belong to `www-data`. Claude runs as root, so after creating a file run `chown www-data:www-data <file>`.
- Cron jobs in `cron/` run every 5–30 minutes against the live DB — test changes there carefully.
