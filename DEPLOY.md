# DEPLOY.md — S Hari Global Booking App

This is the top-level deploy pointer. It records **which backend strategy the app
uses** and where the detailed, step-by-step guides live.

## Backend strategy: Path A — a real PHP + MySQL backend (already built)

The app is **not** a static/serverless page. A complete, dependency-free
PHP 8.1+ / MySQL backend ships in `public_html/` and is what the front end talks
to. There is nothing left to "wire up" — the endpoints the UI calls already exist:

| Front-end call (`shgApi.*`)        | Server file                          |
|------------------------------------|--------------------------------------|
| `POST /api/book.php`               | `public_html/api/book.php`           |
| `POST /api/payment.php`            | `public_html/api/payment.php`        |
| `GET  /api/search.php`             | `public_html/api/search.php`         |
| `POST /api/cancel.php`             | `public_html/api/cancel.php`         |
| `POST /api/otp.php`                | `public_html/api/otp.php`            |
| `POST /api/enquiry.php`            | `public_html/api/enquiry.php`        |
| Staff Admin Panel (RBAC)           | `public_html/admin/*.php`            |

Data is persisted in MySQL (see `public_html/database/schema.sql` +
`seed.sql`), **not** in browser memory.

### How the front end finds the backend
`index.php` injects `window.SHG_BOOT` into the served page (`app.template.html`),
including `apiBase` (defaults to `/api`) and `appUrl`. `shgApi.base()` reads
`SHG_BOOT.apiBase`, so no hardcoded URLs. Every `fetch()` in the app is wrapped
in `try/catch` and surfaces a readable `toast()` on failure — a network error
degrades gracefully, it never shows a raw "fetch failed".

### Admin panel (Section 7)
The authoritative admin surface is the **server-side, RBAC-protected panel at
`/admin/`** (sign-in: `admin/login.php`, guard: `admin/_guard.php`). Payment
approve/reject, ticket issuance and all money operations happen there, server
side. The in-app `#/shg-ctrl` route is now just a **launcher** that opens
`/admin/` — the old client-side PIN gate has been removed (it was bypassable and
only unlocked a local mirror).

## Deploy in 5 steps (Hostinger / cPanel shared hosting)
1. Upload the **contents of `public_html/`** into your domain's `public_html`.
2. Create a MySQL database + user (All Privileges) in cPanel.
3. Open `https://yourdomain.com/install.php` — the wizard imports the schema,
   creates your superadmin, writes `config/config.php`, and locks itself.
4. Lock down: delete `install.php`, confirm `config/`, `includes/`, `database/`
   are not web-readable (the shipped `.htaccess` already handles this).
5. Set up the cron jobs (expiry sweeps, WhatsApp retries) using the cron token
   the installer shows you.

## Full guides (do not duplicate — follow these)
- **`HOSTINGER-DEPLOY.md`** — the complete, authoritative walkthrough (uploads,
  DB, installer, `.htaccess` hardening, cron, troubleshooting).
- **`install.php`** — the first-run installer wizard itself.
- **`WHATSAPP-TWILIO-SETUP.md`** — enabling WhatsApp ticket delivery via Twilio.
- **`DEPLOY-STATUS.md`** — current deployment status / checklist.

**Requirements:** PHP 8.1+ with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`,
`gd`; one MySQL database. No VPS, Node, Composer, Docker or Firebase.
