# Start here — which files actually matter

You have two kinds of files in this project folder and they've been getting
mixed up. This page exists so that never happens again.

## Rule #1 — only `public_html/` is the real, live website

Everything the customer or admin sees comes from inside `public_html/`.
That folder alone is what you upload to Hostinger.

Everything sitting **next to** `public_html/` at the top level is old,
unused leftover from an earlier version of this project that was built on
Firebase (before it was rebuilt on PHP + MySQL). Ignore these — nothing in
the live app reads them:

| File / folder            | What it is                                          |
|---------------------------|------------------------------------------------------|
| `shreehari.html.html`     | The old single-file prototype (pre-PHP). Dead.       |
| `S HARI (1).zip`, `website.zip` | Old exported zips. Dead.                       |
| `firebase.json`, `database.rules.json` | Config for the old Firebase hosting. Dead. |
| `driver/`                 | An old separate driver-app prototype. Dead.          |
| `HOSTINGER-DEPLOY.md`     | **Keep** — the full deploy walkthrough (see below).  |
| `.claude/`                | Claude Code's own project settings. Not part of the app. |

I have not deleted any of these — just confirming they're safe to ignore.
Say the word if you want them cleaned up for real.

## Rule #2 — inside `public_html/`, here's what each thing does

```
public_html/
├── index.php            ← the real entry point. Every visit to your domain runs this.
├── app.template.html    ← the actual UI/design (booking flow, seat map, checkout).
│                            Edit THIS when you want to change how the site looks.
├── install.php          ← run ONCE the first time you deploy, then DELETE it.
├── config/config.php    ← database + domain settings. install.php writes this for you.
├── admin/                ← the staff dashboard (bookings, payments, settings, routes).
├── api/                  ← backend JSON endpoints (search, book, pay, OTP, etc).
├── includes/             ← backend PHP logic (booking rules, tickets, notifications).
├── database/             ← schema.sql + seed.sql — imported automatically by install.php.
├── cron/                 ← scheduled jobs (seat-hold cleanup, backups) — set up in hPanel.
├── uploads/ tickets/ invoice/ qr/ logs/ backup/  ← auto-generated at runtime. Don't touch.
```

If you're editing anything visual (colours, text, the booking form, seat
layout) → **`app.template.html`**. That's the one file. Everything else is
backend plumbing you shouldn't need to open day-to-day.

## The "3-4 photo upload places" — fixed today

There were genuinely two identical "upload payment screenshot" buttons
stacked on top of each other on the checkout page (an old one and a newer
one added later, never cleaned up) — that was the real confusion. Fixed:
now there is exactly **one** upload button on checkout. The other two
upload spots in the app are legitimate and different, not duplicates:

- The single checkout upload button — for payment proof.
- **"Resend payment proof"** on the booking-status page — only shows up
  later, if a pending booking's first screenshot needs replacing.
- **CEO/founder photo** on the homepage — unrelated to bookings at all,
  just lets you swap the founder's photo from the site itself.

## Deploying to Hostinger (short version)

Full walkthrough: [`HOSTINGER-DEPLOY.md`](HOSTINGER-DEPLOY.md) (repo root).
Short version:

1. Upload the **contents of `public_html/`** (not the folder itself) into
   your Hostinger account's `public_html`, via hPanel File Manager (zip +
   extract) or FTP.
2. hPanel → MySQL Databases → create a database + user, grant it full
   privileges.
3. Visit `https://yourdomain.com/install.php` once. It asks for the DB
   details + your admin login, imports everything, and writes
   `config/config.php` for you.
4. **Delete `install.php`** from the server immediately after.
5. hPanel → Cron Jobs → add the 3 jobs listed in HOSTINGER-DEPLOY.md.
6. Log in at `/admin/login.php` → **Settings** → fill in UPI/eSewa IDs,
   contact info, and (optional) WhatsApp automation — see below.

## Turning on automatic WhatsApp sending

The code is fully built — it really does send via the WhatsApp Cloud API,
not just a "click here to WhatsApp us" link. It's just switched off until
you fill in three things in **Admin → Settings → Notify**:

1. `whatsapp_driver` → change from `click_to_chat` to `cloud_api`
2. `whatsapp_api_token` → a token from your Meta WhatsApp Business/Cloud
   API app (business.facebook.com → WhatsApp → API Setup)
3. `whatsapp_phone_id` → the **Phone Number ID** from that same page
   (not your actual phone number)

`admin_whatsapp` (your own number, for new-booking alerts) is already set.
Once those three are filled and saved, every booking confirmation,
payment-rejected notice, cancellation, and OTP will WhatsApp itself
automatically to the customer and to you — no more manual click-to-chat.

**Note:** a normal Meta access token expires in ~24 hours. Ask in the Meta
developer console for a *System User* permanent token instead, or it'll
silently stop working after a day.

## What I fixed today (2026-07-31)

- **Checkout page**: removed the duplicate payment-screenshot upload
  button — one clear button now, and it correctly hides when you're on
  the "Transaction ref" tab instead of "Screenshot".
- **Admin Settings**: token/password-looking fields (WhatsApp token, SMTP
  password) are now masked instead of shown in plain text.
- **WhatsApp**: added the two missing on/off toggles
  (`whatsapp_notify_customer`, `whatsapp_notify_admin`) to the database
  seed so they're actually visible and controllable from Settings.
- **Ticket/invoice PDFs**: fixed two silent-failure spots (a failed save
  now logs an error instead of pretending the ticket exists), and added
  file locking so two people downloading the same ticket at the same
  moment can't cause a corrupted/duplicate render.
- **Dashboard**: today's/this month's revenue queries were doing a full
  table scan on every admin dashboard load; rewrote them to use the
  existing indexes (added one more) so the dashboard stays fast as
  bookings grow — this is the "smooth" part.
- **`.htaccess`**: removed a leftover rule that contradicted its own
  comment (harmless, but confusing to read).
- **Upload size**: added `.user.ini` raising the PHP-level upload ceiling
  above the app's 10 MB screenshot limit, since Hostinger's shared-hosting
  default is often lower than that and would otherwise reject valid
  screenshots with a confusing error.

## What still needs YOU specifically

I can't do these — they need your actual Hostinger login or your own
Meta/business accounts:

1. **Log into Hostinger** and actually upload the files / create the
   database / run `install.php` (I opened the login page in the browser
   for you, but typing your password is something you have to do — that's
   a hard security rule I don't break, even if you ask).
2. **Get a WhatsApp Cloud API token + Phone Number ID** from
   business.facebook.com (needs your business's Meta account).
3. **Decide real values** for the database password and admin login
   during the `install.php` wizard.
4. Since it's a **1-month domain plan** — put a reminder before renewal;
   if the plan lapses, seat-hold cleanup and backups silently stop.
