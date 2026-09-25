# LIVE deploy checklist — Phase B home cards + seat-label follow-ups + .bak removal

Written 24 Sep 2026 (09:50 IST). **Nothing here has been run on LIVE.**
Every step needs the owner's separate, explicit "yes" for this deploy.

## Where things stand

- LIVE: `vps main` = live worktree `/var/www/shreehariglobal.in/public_html` = **`de0af3a`**, clean, sw `shg-v158`, assets `20260924c`.
- The two-floor seat map (`29c3b29`, `8b6b82e`) is **already live** — it shipped inside the overnight premium-shell deploy (24 Sep 00:34 IST, backups `/root/backups/pre-premium-{site,db}-20260924-0034.*`).
- Branch `phase-b-home-cards-20260924` (worktree `…/81d2ff12-…/scratchpad/phaseb`), on top of `de0af3a`:

| Commit | What | Runtime change |
|---|---|---|
| `90523f1` | home: three booking doors (Normal blue / Quick orange / WhatsApp green) + the WhatsApp sheet's 6 missing error texts + `copied` + `waReqFail` wording; stamps `20260924d`, sw `shg-v159` | customer SPA (template, CSS, i18n) |
| `3e7356b` | QuickBot desk prints the two-floor seat labels (was "UA4" beside a ticket saying "A10"); stale ticket PDFs are redrawn (`pdfPath` passes `$stale`) | `admin/quick-ticket.php` (display only), `includes/ticket.php` (1 line) |
| `decf7d3` | ticket layout stamps are India time: `2026-09-24 01:50:00` (the old "UTC" note left 2 tickets with old seat names un-redrawable) | `includes/ticket.php` (2 constants) |
| `d7ac32d` | remove 8 tracked `*.php.bak.20260828-*` files; `admin/*.bak.*` were downloadable source on shreehariglobal.network | file deletions |
| docs commit | this file + `docs/AI-EMPLOYEE-PHASE-A-2026-09-24.md` (`.md` is 404 on the web) | none |

No SQL migration, no cron change, no config or settings change.

## Test evidence (shg-test, `shari_test`, refreshed from `wip`)

| Check | Result |
|---|---|
| `php tests/run-all.php --http` at `d7ac32d` | **89 passed, 0 failed**, 1 missing = node (not installed on the VPS) |
| same at `decf7d3` / `8d80a52`-era v1 | 88 / 0 and 86 / 0 |
| node suites, run on Windows at `d7ac32d` | i18n-check OK (en/hi/ne complete), seat-label-parity 17/0 (incl. the desk copy), offline-ticket 21/0, lazy-retry pass |
| `home-entry-test.php` | 57 / 0 |
| `ticket-cache-test.php` | 7 / 0 — and 6 / 1 on the OLD `pdfPath` (it catches the bug) |
| `public-files-test.php` | 2 / 0 |
| `seat-label-test.php`, `asset-version-test.php`, `counter-location-test.php`, `chalani-png-test.php`, `quick-ticket-test.php` | all ok |
| `render-admin.php quick-ticket.php` | renders, 0 PHP errors, seat map injected |
| Browser (shg-test, 375 px, ne/en/hi) | cards first (top 148 px), then QuickBot (1), search (2), hero (3); no horizontal scroll; no raw key names; WhatsApp card opens the sheet in booking mode with focus on the name; a bad number shows the Nepali error text; "Continue in WhatsApp" = `wa.me/<whatsapp_booking_number>` |

Not observable in the hidden browser pane: the smooth scroll after the
Normal/Quick taps (programmatic scrolling does nothing in that pane, even
`window.scrollTo`). The scroll is the existing, unchanged `initBookMode()`.

## Steps (only after the owner says yes)

### 0. Stop if anything is off
Another session deploying, a counter mid-sale on a big group, or any check
below not printing what is expected → stop. Never improvise on LIVE.

### 1. Verify LIVE and the intended commit (read-only)
```bash
cd "C:/JayAmbe_POS/s harti pvt lt/shreehariglobal.in" && git fetch vps
git rev-parse --short vps/main
git rev-parse --short vps/wip
git log --oneline vps/main..vps/wip
git diff --name-only vps/main vps/wip | grep -E '\.sql$|^cron/|^config/'
```
`vps/main` must be `de0af3a`; `vps/wip` the tested head T; the log exactly
the commits above; the grep empty. If `vps/main` has moved (another session
deployed), stop: rebase the branch onto it, push to `wip`, re-run the
battery, and start again.
```bash
ssh shari-vps 'cd /var/www/shreehariglobal.in/public_html && git rev-parse --short HEAD && git status --porcelain | head'
```
HEAD `de0af3a`, status empty.

### 2. Verified backup
```bash
ssh shari-vps 'set -e; S=$(date +%Y%m%d-%H%M); cd /var/www/shreehariglobal.in; tar -czf /root/backups/pre-phaseb-site-$S.tar.gz public_html; mysqldump --single-transaction --routines shari | gzip > /root/backups/pre-phaseb-db-$S.sql.gz; gzip -t /root/backups/pre-phaseb-*-$S.*gz; tar -tzf /root/backups/pre-phaseb-site-$S.tar.gz | wc -l; zcat /root/backups/pre-phaseb-db-$S.sql.gz | grep -c "^CREATE TABLE"; ls -la /root/backups/pre-phaseb-*-$S.*'
```
Expect: gzip -t silent, thousands of files, more than 50 tables, two
non-empty files. Note the file names.

### 3. Fast-forward only
```bash
ssh shari-vps 'cd /var/www/shreehariglobal.in/public_html && git merge-base --is-ancestor HEAD wip && echo FF-OK && git merge --ff-only <T> && git rev-parse --short HEAD'
```
Never `push --force`, never `reset --hard` on LIVE.

### 4. Ownership
```bash
ssh shari-vps 'cd /var/www/shreehariglobal.in/public_html && git diff --name-only --diff-filter=AM de0af3a HEAD | xargs -r chown www-data:www-data && stat -c "%U:%G %n" app.template.html sw.js admin/quick-ticket.php includes/ticket.php tests/home-entry-test.php'
```

### 5. Service-worker stamps
```bash
curl -s https://www.shreehariglobal.in/sw.js | grep -oE "var VERSION = 'shg-v[0-9]+'|var ASSET_VER = '[0-9a-z]+'"
curl -s https://www.shreehariglobal.in/ | grep -oE '(views|premium)\.css\?v=[0-9a-z]+|04-i18n\.js\?v=[0-9a-z]+' | sort -u
```
Expect `shg-v159` and `20260924d` everywhere.

### 6. Smoke tests (read-only)
```bash
for u in https://www.shreehariglobal.in/ https://shreehariglobal.network/admin/login.php https://www.shreehariglobal.in/sw.js; do printf "%s " "$u"; curl -s -o /dev/null -w "%{http_code}\n" "$u"; done
for f in _guard.php.bak.20260828-153536 booking-view.php.bak.20260828-151549 index.php.bak.20260828-152722; do printf "%s " "$f"; curl -s -o /dev/null -w "%{http_code}\n" "https://shreehariglobal.network/admin/$f"; done
curl -s https://www.shreehariglobal.in/ | grep -c 'id="entryCards"'
ssh shari-vps 'cd /var/www/shreehariglobal.in/public_html && for f in admin/quick-ticket.php includes/ticket.php; do php -l "$f"; done; sudo -u www-data php tests/seat-label-test.php | tail -1; sudo -u www-data php tests/home-entry-test.php | tail -2; sudo -u www-data php tests/public-files-test.php | tail -2'
```
Expect 200 / 200 / 200, the three `.bak` URLs **404**, `entryCards` 1, the
three read-only suites green. Do NOT run `ticket-cache-test.php` on LIVE (it
refuses anyway: it redraws ticket files).

On a phone: three cards first; Normal → seat-map search; Quick → QuickBot;
WhatsApp → the sheet, "Continue in WhatsApp" opens the configured booking
number. Staff: open the QuickBot desk, plan one ticket, the Seat line reads
A1–F12 style (no "LA"/"UA"). Open one old ticket PDF: it redraws once with
the new labels.

### 7. Rollback (typed out before step 3)
```bash
ssh shari-vps 'cd /var/www/shreehariglobal.in/public_html && T=$(git rev-parse HEAD) && git revert --no-edit de0af3a..HEAD && git diff --name-only --diff-filter=AM $T HEAD | xargs -r chown www-data:www-data && git status --porcelain | head'
```
The revert changes `sw.js` again, so installed phones re-fetch. It also puts
the `.bak` files back (they would be public again). Last resort: the step-2
tarball. The database is not touched by this deploy.

## Partial approval
The commits are stacked. If the owner approves only some of them (for
example only the `.bak` removal, the most urgent), cherry-pick those onto a
fresh branch from LIVE, push to `wip`, re-run the battery, then deploy that.
