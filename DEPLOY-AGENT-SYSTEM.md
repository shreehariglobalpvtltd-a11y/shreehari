# Deploy guide — agent / agreement system + 3 new features

**Branch:** `claude/interactive-pdf-form-system-7bd71d` (commit `9807432`)
**Base:** `core-20261023` (`bc52e2e`)
**Date:** 2026-10-24

---

## ⚠️ READ THIS FIRST — it is a BIG, first-time deploy

Your live site (**shreehariglobal.in**) right now runs **only** the ~Sept-20 bus-booking
app. The whole agent / agreement / apply / KYC / passkey / team-portal system
**has never been deployed** — every agent URL (`/agreements/apply.html`,
`/agreements/form.php`, `/api/agreement-apply.php`, `/team/`, …) is 404 or falls
back to the booking site.

This package deploys the **entire `core-20261023` version of the site** + 3 new
features. That means it **replaces your whole live site**, including the customer
**booking app** (core changed it too: `assets/js/01-boot.js` went 168 → 309 lines),
and needs **156 database migrations**.

> Deploying this blind to production can break live bookings (your revenue).
> **Do NOT deploy without: (1) a full backup of live files AND the live DB, and
> (2) ideally testing on a staging copy first.**

### The 3 new features in this branch
- **Scan-back OCR** (agent / partner / staff): print the blank A4 → fill by hand →
  scan → AI reads the whole form, fills the empty boxes, and attaches the scan.
- **Fingerprint-first login**: a one-tap phone-lock (passkey) hero leads `/team/`
  and `admin/login.php`; password is folded last.
- **Company name**: the agent-commission PDF now uses **S HARI GLOBAL PRIVATE LIMITED**
  (Devanagari optional via `company_legal_ne`).

---

## 0. VPS prerequisites
- PHP 8.3-FPM, MySQL/MariaDB, nginx (you have these).
- Agreement **vector** PDF needs: PHP FFI + HarfBuzz + `assets/fonts/NotoSansDevanagari.ttf`.
- OCR / face checks need `gemini_api_key` in settings and `agr_doc_verify = strict`.

## 1. BACK UP (do not skip)
```bash
# files
tar czf ~/backup-live-$(date +%F).tar.gz -C /path/to/public_html .
# database (db name + user are in config/config.php)
mysqldump -u <user> -p <db> > ~/backup-db-$(date +%F).sql
# remember the current live commit, to roll back to
cd /path/to/public_html && git rev-parse HEAD > ~/live-commit-before.txt
```

## 2. Put the code on the VPS
Your source-of-truth repo is `/var/repos/shg.git`. Push this branch there (from
your dev box or via GitHub), then check it out into the web root:
```bash
git push vps claude/interactive-pdf-form-system-7bd71d        # adapt remote name
# then on the VPS, in the web root:
cd /path/to/public_html
git fetch --all
git checkout claude/interactive-pdf-form-system-7bd71d
git reset --hard <that-branch-tip>
chown -R www-data:www-data /path/to/public_html
```
**Keep your live `config/config.php`.** It is gitignored, so git will not touch it —
but confirm it is still present after checkout (it holds the real DB creds + APP_KEY).

## 3. Run the database migrations
Your live DB is on the ~Sept-20 schema; the agent system needs ~2 months of
migrations. They are mostly guarded (re-running a done one is a no-op), but
**back up the DB first (step 1).** If a migration errors, it is usually already
applied — read the message; the backup is your safety net.
```bash
cd /path/to/public_html
# SQL migrations in date order (156 of them):
for f in database/upgrade-*.sql; do echo "== $f"; php tests/apply-sql.php "$f" || echo "   !! check $f"; done
# PHP migrations:
for f in database/upgrade-*.php; do echo "== $f"; php "$f" || echo "   !! check $f"; done
```
Key tables this brings in: `agent_applications`, `staff_applications`,
`team_passkeys`, `agreement_points`, plus the new scan columns
(`upgrade-2026-10-24-scan-autofill.sql`: `scan_doc` / `scan_ocr` /
`scan_ocr_status` / `scanned_at`).

## 4. Routing (nginx)
The agent pages are **real files** now (`/agreements/apply.html`,
`/agreements/form.php`, `/api/*.php`, `/team/`). Make sure nginx serves existing
files + PHP **before** the SPA catch-all, so `/agreements/apply.html` serves the
real form, not the booking app. Typical:
```nginx
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```
After reload, confirm `/agreements/apply.html` and (GET) `/api/agreement-apply.php`
resolve to the real files, not the fallback.

## 5. Settings (Admin → Company details / settings)
- `gemini_api_key` — ID OCR + scan-back OCR + face check.
- `agr_doc_verify` = `strict`.
- `company_legal` = `S Hari Global Private Limited`; `company_legal_ne` = your
  Devanagari name (optional — without it, the commission PDF shows the Roman name).

## 6. Verify after deploy
1. **Customer booking still works** (search → seat → checkout) — this is the risk area.
2. `/agreements/apply.html` shows the agent form (staff / agent / partner) **with the
   "Scan a filled paper form" card**.
3. `/team/` and `admin/login.php` show the **fingerprint hero** at the top.
4. `/agreements/form.php?kind=agent&auto=pdf` downloads the A4 PDF.
5. Upload an ID photo / scan a filled form → boxes auto-fill (needs `gemini_api_key`).

## 7. Rollback (if anything breaks)
```bash
cd /path/to/public_html
git reset --hard $(cat ~/live-commit-before.txt)
mysql -u <user> -p <db> < ~/backup-db-$(date +%F).sql
chown -R www-data:www-data .
# reload nginx / php-fpm
```

---

## Can Claude deploy this for you?
Not from the Windows machine this was built on — it cannot reach your VPS. Options:
- **You** run the steps above on the VPS (safest; you control the backup + rollback).
- **Authorize the Hostinger connector** in the app (`/mcp` → Hostinger) and Claude
  can help deploy through it — still back up the DB + files first.

If unsure, deploy to a **staging copy** of the site first and click through the
booking flow + the agent form before touching production.
