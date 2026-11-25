# Hostinger VPS setup — S Hari Global

Master prompt §3 and §39, as a runnable checklist.

**Status: not yet performed.** The live site is on Hostinger *shared*
hosting (Apache + `.htaccess`, no shell). These are the steps for the day
the VPS is ready. Nothing here has been executed against a VPS, and this
document does not claim otherwise.

The single most important thing to understand before you start:

> **nginx does not read `.htaccess`.** Every protection in this project's
> nine `.htaccess` files disappears the moment you serve it with nginx.
> `deploy/nginx-shreehariglobal.in.conf` restates all of them. If you
> deploy to nginx without that file, `/config/config.php` — your database
> password and `APP_KEY` — becomes a public URL.

---

## 1. Packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mariadb-server \
  php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-gd \
  php8.3-curl php8.3-xml php8.3-zip php8.3-intl \
  certbot python3-certbot-nginx unzip
```

`php8.3-gd` is not optional: QR codes and ticket PDFs are generated with
it, so without it every ticket fails to render.

## 2. Database

```bash
sudo mysql_secure_installation
```

```sql
CREATE DATABASE shari CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'shariuser'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON shari.* TO 'shariuser'@'localhost';
FLUSH PRIVILEGES;
```

Import the existing data — **from a fresh dump of the live shared host**,
not from `database/schema.sql`. `schema.sql` starts with 41 `DROP TABLE`
statements and would discard every real booking:

```bash
mysql shari < live-dump.sql
```

## 3. Application files

```bash
sudo mkdir -p /var/www/shreehariglobal.in
sudo chown -R $USER:www-data /var/www/shreehariglobal.in
# upload public_html/ here, then:
cd /var/www/shreehariglobal.in/public_html
cp config/config.sample.php config/config.php
```

Now edit `config/config.php`:

| Setting | Value |
|---|---|
| `DB_HOST` | `localhost` |
| `DB_NAME` / `DB_USER` / `DB_PASS` | from step 2 |
| `APP_URL` | `https://www.shreehariglobal.in` |
| `APP_ENV` | `production` (this switches `APP_DEBUG` off) |
| `APP_KEY` | **copy from the existing live config** — see the warning below |
| `CRON_TOKEN` | a fresh random string |

> ⚠️ **`APP_KEY` must be carried over from the current live server.**
> It signs the HMAC in every ticket QR code. A new key invalidates every
> ticket already in a customer's hands, and they will be refused at the
> boarding gate.

`config/config.php` is git-ignored on purpose, so it is never overwritten
by a deploy. Only `config.sample.php` is tracked.

## 4. Permissions

The app writes to six directories. Everything else stays read-only to the
web user.

```bash
cd /var/www/shreehariglobal.in/public_html
sudo chown -R $USER:www-data .
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;
sudo chmod -R 775 uploads tickets qr invoice logs backup
sudo chown -R www-data:www-data uploads tickets qr invoice logs backup
sudo chmod 640 config/config.php          # credentials: not world-readable
```

## 5. nginx + TLS

```bash
sudo cp deploy/nginx-shreehariglobal.in.conf \
        /etc/nginx/sites-available/shreehariglobal.in
sudo ln -s /etc/nginx/sites-available/shreehariglobal.in /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo certbot --nginx -d shreehariglobal.in -d www.shreehariglobal.in
sudo nginx -t && sudo systemctl reload nginx
```

Certbot installs its own renewal timer; confirm it with
`systemctl list-timers | grep certbot`.

## 6. PHP-FPM

In `/etc/php/8.3/fpm/php.ini`:

```ini
display_errors = Off          ; never leak a stack trace to a customer (§30)
log_errors = On
expose_php = Off
upload_max_filesize = 12M     ; MAX_UPLOAD_BYTES is 10M — leave headroom
post_max_size = 14M
max_execution_time = 120      ; ticket PDF + WhatsApp send
date.timezone = Asia/Kolkata  ; APP_TIMEZONE (§37)
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Lax
```

```bash
sudo systemctl restart php8.3-fpm
```

## 7. Cron (§23, §32)

`crontab -e` as the site user. The token must match `CRON_TOKEN`.

```cron
# Release stale seat holds and abandoned unpaid bookings — every 5 minutes.
# Never touches a booking that carries payment proof (see the paid-pending
# fix); those wait for an admin, however long that takes.
*/5 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/expire.php >/dev/null 2>&1

# Trip reminders (12h / 2h / departed / border / arrived).
*/10 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/reminders.php >/dev/null 2>&1

# Nightly gzipped database backup into backup/ (web-denied), 02:15.
15 2 * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/backup.php >/dev/null 2>&1

# Log rotation, Sundays 03:00.
0 3 * * 0 /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/rotate.php >/dev/null 2>&1

# Online payments (25 Nov 2026, mp1 + mp2): read new bank credit alerts from
# every active bank Gmail box (Admin -> Payment Management -> Nepal / India
# tab -> "Bank Gmail accounts"), every 2 minutes. Exits at once while no box
# is active (before the mp2 migration: while bankmail_on is off).
*/2 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/bank-mail.php >/dev/null 2>&1
# Evening day report on WhatsApp (release dy): from seller_day_report_hour
# (default 20 IST) each seller with a ticket today gets their own day once,
# free inside their 24 h window, else a web push, else nothing; the owner
# gets one all-desk total. Every 15 minutes; a claim per person per day
# stops reruns sending twice. Switch: seller_day_report_on.
*/15 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/seller-day.php >> /var/www/shreehariglobal.in/public_html/logs/seller-day.log 2>&1
```

Confirm the seat sweep is actually running — an unscheduled `expire.php`
means abandoned checkouts hold seats until their lock expires:

```bash
grep -c expire /var/log/syslog
```

### 7a. Bank Gmail (Google login) — 25 Nov 2026, mp2

The owner adds each bank's Gmail box on **Payment Management** (Nepal tab = NPR
Global IME alerts, India tab = INR alerts until the bank API credentials come):
"🔐 Google बाट Login गर्नुहोस् (browser जस्तै)" or an app password. Needs the
migration `database/upgrade-2026-11-25-mp2-bank-gmail.sql` and PHP `ext-sodium`
(tokens / app passwords are sealed with `APP_KEY`; without sodium nothing is
stored). The old single-box settings (`bankmail_user` + app password) become
account #1 by themselves on the first run.

One-time Google Cloud setup (the panel shows the same steps + a Copy button):

1. <https://console.cloud.google.com/> → create a project (e.g. "S Hari Bank Mail").
2. APIs & Services → Library → **Gmail API** → Enable.
3. **OAuth consent screen**: User type External, app name, your e-mail; Scopes:
   `https://www.googleapis.com/auth/gmail.readonly`; **Test users**: add the bank
   Gmail; then **Publish app** (In production) — otherwise Google signs the box
   out every 7 days.
4. Credentials → Create credentials → **OAuth client ID** → **Web application** →
   Authorized redirect URI, exactly:

   ```text
   https://<the .network admin host>/admin/gmail-oauth.php
   ```

   (live: `https://shreehariglobal.network/admin/gmail-oauth.php`; the panel prints
   the exact value — override with setting `google_oauth_redirect` only if needed).
5. Paste **Client ID** + **Client Secret** in the panel's setup box → Save
   (settings `google_oauth_client_id`, `google_oauth_client_secret`; super-admin only).

When signing in Google shows "Google hasn't verified this app": Advanced → Go to …
(it is the owner's own app). Only read-only Gmail access is asked for. Each box
then needs its bank **DKIM domain** and **our account's last 4–6 digits** (Edit)
before it makes suggestions; until then it only logs what arrives. Gmail credits
stay suggestions (`pay_bankmail_autoconfirm_on` off): a person checks the bank app
and presses Confirm.

## 8. Off-server backups

`cron/backup.php` writes into `backup/`, which the nginx config denies to
the web. That protects it from download but **not** from losing the whole
server. Copy them off the box:

```cron
30 3 * * * rsync -az /var/www/shreehariglobal.in/public_html/backup/ \
             backup@elsewhere:/srv/shg-backups/
```

A backup that only exists on the machine it is backing up is not a backup.

## 9. Verify before announcing

```bash
php tests/preflight.php
```

Then, from your own machine — every one of these must be refused:

```bash
for p in config/config.php includes/booking.php database/schema.sql \
         backup/ logs/ app.template.html install.php \
         tests/e2e-booking-test.php; do
  printf '%-34s %s\n' "$p" \
    "$(curl -s -o /dev/null -w '%{http_code}' https://www.shreehariglobal.in/$p)"
done
```

Anything that answers `200` is a hole. `403` or `404` is correct.

And confirm the redirects:

```bash
curl -sI http://www.shreehariglobal.in/  | head -1   # 301 -> https
curl -sI https://shreehariglobal.in/     | head -1   # 301 -> www
```

## 10. Booking correctness on the new box

Concurrency behaviour depends on the database engine, so re-run the race
test against the VPS database before taking real money on it:

```bash
php tests/double-booking-race-test.php
php tests/paid-pending-hold-test.php
```

Both must report `FAILED: 0`. If `double-booking-race-test.php` reports
more than one winner, **stop** — the tables were imported as MyISAM,
which has no transactions and no row locking. Check with:

```sql
SELECT table_name, engine FROM information_schema.tables
 WHERE table_schema = 'shari' AND engine <> 'InnoDB';
```

That query must return nothing.
