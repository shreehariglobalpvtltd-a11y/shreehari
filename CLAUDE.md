# shreehariglobal.in — LIVE PRODUCTION

This layout is the live site (nginx + PHP 8.3-FPM + MySQL db `shari`): this folder's `index.php` is the webroot's `index.php`.
Saving a file is live immediately **only on the VPS, in `/var/www/shreehariglobal.in/public_html`**. In a local clone or a git
worktree nothing you save is live — code reaches production only through the route in **[DEPLOY.md](DEPLOY.md)**, which also says
which branch is live and how to prove it.

- Git history is stored in `/root/shg-site.git` (`.git` here is only a pointer). Run `git status` before editing and commit after
  each working change. Undo with `git checkout -- <file>` or `git revert <sha>`. The bare repo the deploy route builds from is a
  different one, `/var/repos/shg.git` on the VPS — that, not GitHub, is where the `release-*` branches live.
- Full tarball snapshots (including config) are in `/root/backups/`.
- After editing a PHP file run `php -l <file>`, then check `curl -s -o /dev/null -w "%{http_code}" https://www.shreehariglobal.in/`.
  A 200 only means nginx answered; to prove *which* code is being served, grep a release marker (see [DEPLOY.md](DEPLOY.md)).
- Never print, copy or commit `config/config.php` (live DB credentials, APP_KEY) or the Twilio values in the `settings` table.
- Files belong to `www-data`. Claude runs as root, so after creating a file run `chown www-data:www-data <file>`.
- Cron jobs in `cron/` run every 5–30 minutes against the live DB — test changes there carefully.
