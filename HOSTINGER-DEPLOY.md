# Deploying S Hari Global to Hostinger (Premium / cPanel)

A complete, dependency-free PHP + MySQL build. No VPS, Node, Composer,
Docker or Firebase — everything runs on standard shared hosting.

**Requirements:** PHP 8.1+ with `pdo_mysql`, `mbstring`, `openssl`,
`fileinfo` and `gd` (all enabled by default on Hostinger), and one MySQL
database. That's it.

---

## 1. Upload the files

Upload **the contents of `public_html/`** into your account's
`public_html` (the web root for your domain). The final layout on the
server should be:

```
public_html/
├── index.php            ← front controller (serves the site)
├── install.php          ← run once, then delete
├── admin/               ← staff console
├── api/                 ← JSON endpoints
├── includes/            ← application code (protected)
├── config/              ← config.php is written by install.php (protected)
├── database/            ← schema.sql + seed.sql (protected)
├── cron/                ← scheduled jobs
├── uploads/ tickets/ invoice/ qr/ logs/ backup/   ← runtime (writable)
└── app.template.html    ← the UI markup (served through index.php)
```

Use the cPanel File Manager (upload the ZIP, then *Extract*) or FTP.

> Do **not** upload `config/config.local.php` or `.claude/` — those are
> local-development only and are already git-ignored.

## 2. Create the database

cPanel → **MySQL Databases**:

1. Create a database, e.g. `u123456_shari`.
2. Create a user, e.g. `u123456_shariuser`, with a strong password.
3. **Add the user to the database** with *All Privileges*.

Note the database name, user and password — you'll enter them next.

## 3. Run the installer

Open **`https://yourdomain.com/install.php`** in a browser. The wizard:

- checks PHP, extensions and folder permissions,
- takes the database details and your first admin username/password,
- imports `schema.sql` + `seed.sql`,
- creates the superadmin, generates a strong `APP_KEY` and `CRON_TOKEN`,
- writes `config/config.php`, and locks itself.

When it finishes it shows your **cron token** — keep it for step 5.

## 4. Lock it down

1. **Delete `install.php`** from the server (File Manager → Delete).
2. Confirm HTTPS is on (Hostinger provides free SSL); the app forces
   HTTPS and the `.htaccess` redirects HTTP automatically.
3. Folder permissions: `uploads/ tickets/ invoice/ qr/ logs/ backup/`
   should be `755` (writable by PHP). `config/` can be tightened to `755`
   and `config/config.php` to `644` once installed.

## 5. Schedule the cron jobs

cPanel → **Cron Jobs**. Use the PHP CLI path (usually
`/usr/local/bin/php`) — or, if your plan only allows URL crons, use the
`?token=` form with the token from step 3.

| Frequency    | Command (CLI)                                                    | or URL                                              |
|--------------|------------------------------------------------------------------|-----------------------------------------------------|
| Every 5 min  | `/usr/local/bin/php /home/USER/public_html/cron/expire.php`       | `https://yourdomain.com/cron/expire.php?token=TOKEN` |
| Daily 03:00  | `/usr/local/bin/php /home/USER/public_html/cron/rotate.php`       | `https://yourdomain.com/cron/rotate.php?token=TOKEN` |
| Daily 03:30  | `/usr/local/bin/php /home/USER/public_html/cron/backup.php`       | `https://yourdomain.com/cron/backup.php?token=TOKEN` |

- **expire** releases stale seat holds and unpaid bookings.
- **rotate** prunes old logs and expired rate-limit/OTP rows.
- **backup** writes a gzipped SQL dump to `/backup` (keeps the newest 14).

## 6. Configure business settings

Sign in at **`https://yourdomain.com/admin/login.php`** and open
**Settings** to set UPI/eSewa IDs, contact details, discounts and the
maintenance toggle. Routes, fares and stops are managed from the in-app
admin console on the website itself (your signed-in session authorises
the changes to save to the server).

### Optional: email + WhatsApp

- **Email:** add SMTP host/port/user/password in Settings. Without SMTP
  the app falls back to PHP `mail()`. No PHPMailer/Composer needed.
- **WhatsApp:** click-to-chat (`wa.me`) works out of the box. To send
  automatically, add WhatsApp Cloud API credentials in Settings.

---

## How it works (for maintainers)

- **`index.php`** boots the backend, injects a `SHG_BOOT` object (public
  settings, CSRF token, API base, signed-in user) and serves
  `app.template.html`, wiring the app's `window.storage` seam to
  `api/kv.php`. Shared data (routes, settings) lives on the server and is
  admin-managed; guests read public data and keep their own drafts
  locally, so no anonymous visitor can overwrite shared state.
- **`api/`** are the transactional endpoints — search, seat locking,
  quoting, booking, payment proof, tracking, cancellation, OTP. Bookings
  are race-safe via a `UNIQUE(schedule_id, seat_no)` firewall; pricing is
  always recomputed server-side.
- **`admin/`** is the staff console (RBAC): dashboard, payment
  verification queue, bookings, ticket scanner, routes, settings.
- **Tickets** are generated server-side — a pure-PHP PDF with an
  HMAC-signed QR (no libraries), downloadable at
  `download-ticket.php?pnr=...`.

## Troubleshooting

- **"Configuration file is missing"** — you haven't run `install.php`, or
  `config/config.php` wasn't written (make `config/` writable and re-run).
- **Blank/500 page** — check `logs/php_errors.log` and `logs/<date>.log`.
- **Ticket won't download** — it appears only once payment is *verified*.
- **Cron 403** — the `?token=` value must match `CRON_TOKEN` in
  `config/config.php`.
