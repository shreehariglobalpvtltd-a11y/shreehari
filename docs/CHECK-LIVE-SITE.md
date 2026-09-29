# Checking the live site yourself — "the data is gone" / "the daily bus is missing"

Written 29 Sep 2026, for the owner.

This is for the things a cloud session **cannot** see. The website's code lives
on GitHub and I can read all of it; the **live database on the VPS** — your
routes, your schedules, your bookings — I cannot reach at all. So when rows go
missing, nobody can tell you from GitHub what happened. You have to look on the
server. These are the steps, in order, easiest first.

Start with the screens. Only go to the command line if the screens do not
answer it.

---

## Step 1 — Admin → Health (start here, always)

    https://www.shreehariglobal.in/admin/health.php

This page is a **list of things to do**. Four monitors write to it — message
delivery, the cron heartbeat, the gem hygiene check, and the nightly data
audit. If a cron job has stopped running, or the night audit found something
wrong in the data, it says so here in plain words.

If this page is one green line, nothing is broken that the system knows about.

---

## Step 2 — Admin → Bus Calendar (this answers "where is tomorrow's bus")

    https://www.shreehariglobal.in/admin/calendar.php

Two things on this page matter:

- **how many days ahead the daily bus is already created** — it should be
  roughly 30 days, rolling forward every night
- **when the nightly job last ran** (`daily_schedule_last_run`)

Read them together:

| what you see | what it means | what to do |
|---|---|---|
| ~30 days ahead, ran last night | the automatic daily bus is healthy | nothing |
| ran last night, but 0–2 days ahead | the job **is** running but creating nothing | go to Step 3 — it is the routes, not the cron |
| last run is days or weeks old | the cron is **not running** | go to Step 4 |
| last run is empty / never | the cron was never installed | go to Step 4 |

---

## Step 3 — "The job runs but makes no buses" → check the routes

The nightly job only creates a bus for a route that is **both**:

- switched **on** (`is_active = 1`), and
- has a **departure time** set (`dep_time` is not empty), and
- has a **bus assigned** (or fleet rotation turned on)

If any one of those is missing, that route is skipped silently — no error
anywhere. That is the single most common reason for "the bus stopped
appearing by itself".

Open **Admin → Routes** and check each route has a departure time, a coach,
and is switched on.

To see it as the job sees it, on the server:

```bash
cd /var/www/shreehariglobal.in/public_html
mysql -u <user> -p <database> -e "
  SELECT id, route_code, is_active, dep_time, bus_id
    FROM routes
   ORDER BY sort_order, id;"
```

Take the username and database name from `config/config.php`. **Do not print
that file, do not paste it into a chat, and do not commit it** — it holds the
live database password and the app key.

A route with `is_active = 0`, or `dep_time = NULL`, or `bus_id = NULL` is a
route the nightly job will not create a bus for.

How far ahead schedules actually exist:

```bash
mysql -u <user> -p <database> -e "
  SELECT route_id, MIN(travel_date) AS first, MAX(travel_date) AS last, COUNT(*) AS rows_
    FROM schedules
   WHERE travel_date >= CURDATE()
   GROUP BY route_id;"
```

---

## Step 4 — "The cron is not running" → check and install it

Is it in the crontab at all?

```bash
crontab -l | grep -i daily-schedule
```

Run it by hand right now and read what it prints:

```bash
php /var/www/shreehariglobal.in/public_html/cron/daily-schedule.php
```

It prints one line, and that line is the whole diagnosis:

- `created 30 (skipped 0 existing) across 1 routes …`
  → it works. It was simply never being run. Install it (below).
- `created 0 (skipped 30 existing) …`
  → everything already exists. This is **healthy**, not a fault.
- `created 0 (skipped 0 existing) across 0 routes …`
  → it found **no eligible route**. Back to Step 3 — it is the routes.
- `… (error: …)`
  → read the error. A missing table means a migration in `database/` was
    never applied.

Running it twice changes nothing — it is safe to re-run as often as you like.

If it is missing from the crontab, add it with `crontab -e`:

```
30 0 * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/daily-schedule.php
```

(Check your PHP path first with `which php` — it may be `/usr/local/bin/php`.)

While you are in there, confirm the **other** jobs in `cron/` are scheduled
too — reminders, expiry, backups, the health heartbeat. If `daily-schedule`
had fallen out of the crontab, others may have as well.

---

## Step 5 — A feature is on the site but you cannot see it

Two different reasons, both normal:

**1. The switch is off.** Many features ship **deliberately switched off** and
have to be turned on in **Admin → Settings**. The deploy process leaves
switches off on purpose, so a deploy never changes behaviour on its own. The
animation work is exactly this case: it is controlled by `app_motion_on`, and
it ships **off**. Turning it on is what makes the motion appear.

**2. It was never deployed.** Check what is actually live:

```bash
cd /var/www/shreehariglobal.in/public_html
git log --oneline -1
```

Compare that to `main` on GitHub. If the live commit is behind, the work is
written but not shipped — deploy it with **Actions → Deploy to VPS → Run
workflow**.

---

## What is safe to do, and what is not

Safe, any number of times:

- opening `admin/health.php` or `admin/calendar.php`
- running `cron/daily-schedule.php` by hand — it never overwrites an existing
  day, never touches a booking, and a second run does nothing
- the `SELECT` queries above — they only read

Stop and ask before:

- any `DELETE`, `UPDATE`, `DROP` or `TRUNCATE` on the live database
- applying a file from `database/` you have not applied before — take a
  backup first (`/root/backups/` holds the nightly tarballs)

---

## The one thing to remember

**Missing rows in the database and missing features in the code are two
different problems.**

- A feature that is in GitHub but not on the site → a **deploy** or a
  **switch** problem (Step 5).
- Routes, schedules or bookings that used to be in the database and are not
  there now → nothing in the code can bring those back. Restore from
  `/root/backups/`, and see `docs/RESTORE.md`.
