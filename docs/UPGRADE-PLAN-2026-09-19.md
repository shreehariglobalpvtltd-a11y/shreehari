# Upgrade plan — 19 Sep 2026 (6 gaps + "fewer clicks, more automatic")

Owner brief (19 Sep 2026, in Nepali): *solve all six gaps; make the app that already exists
faster — fewer clicks, fewer pages, more automation, AI where it helps; newest sound
architecture; data must be 100 % correct; plan first, 5–6 sessions is fine.*

Source prompt: "Master Upgrade Prompt v3.0" (owner's artifact). It describes a Supabase +
`booking.html` app. **This repo is PHP 8.3 + MySQL `shari`, at `shg-v128`**, and already has
nearly all ten modules of that prompt. So this plan keeps the real stack and builds only what
is missing. Nothing here creates Supabase tables or new stand-alone HTML apps.

## Rules for every session (do not skip)

1. `git status` first. One feature = one commit. Never touch `config/config.php` or Twilio/Meta secrets.
2. Every sale still goes through `BookingService::create()` / `counterSale()` and
   `Seats::assertAvailable()`. No new code path may write a seat.
3. Schema changes are **additive only**: a new `database/upgrade-2026-09-*.sql`, `CREATE TABLE IF NOT
   EXISTS` / `ADD COLUMN`, never drop or rewrite. Every feature ships **behind a settings switch,
   default OFF**, so deploy and switch-on are two separate steps.
4. Every feature gets a suite in `tests/` and is added to `tests/run-all.php`. Full battery green
   before release.
5. Release = bump `VERSION` in `sw.js` + one asset stamp everywhere (see `tests/asset-version-test.php`).
6. New user-facing strings go in **all** languages of `assets/js/04-i18n.js` in the same commit.
7. End each session with: STATUS / CHANGES / TEST / NEXT, and tick the boxes below.

## P0 — blockers found on 19 Sep (need the owner)

- [ ] **No PHP on this Windows machine and no `.deploy.env`.** Tests cannot run here and nothing
      can be deployed from here. Pick one: (a) install PHP 8.3 + MariaDB locally with a copy of the
      schema/seed, or (b) run the sessions on the VPS checkout like the earlier sessions did.
      Until then code can be written but **not verified** — and unverified code must not go live.
- [ ] **Six uncommitted files from the 18 Sep session**: a real bug fix in
      `includes/booking.php` (`counterSale()` closure did not capture `$data`, so every counter
      ticket took the first open stop — test cases 5c–5e added) and Nepali wording for OTP /
      WhatsApp-bot / notification texts (`api/otp.php`, `includes/auth.php`, `includes/notify.php`,
      `includes/wabot.php`). Run `tests/boarding-other-test.php` + the notify/wabot suites, then commit
      as two commits (fix, wording).
- [ ] **Brand colours**: the prompt asks for navy `#1A237E` + orange + gold; live is `#1A3A6A`
      (design system v2, shipped 18 Sep). Recommendation: keep the live navy, add orange only as the
      CTA accent token. Owner to say yes/no before Session 6.

## Session 1 — foundation: verify, measure, data audit

- [ ] Clear P0 (test environment, commit pending work).
- [ ] **Click audit**: count taps and screens for the 8 everyday jobs — customer books 1 seat,
      customer re-books, counter sells 1 / sells 5, agent sells, cancel + refund, reschedule,
      send ticket on WhatsApp, print chalan. Write the table into this file. Every later session
      must lower these numbers, never raise them.
- [ ] **`cron/data-audit.php` (read-only, nightly)** — the "100 % correct data" guard. Checks:
      every confirmed seat has exactly one live booking; no bed sold in both Sharing and Private;
      `total_amount` = sum of passenger fares − discount; agent wallet balance = sum of its ledger;
      refunds ≤ paid; holds older than the timeout. Any mismatch → one `Health` incident in owner
      language (same channel as `cron/health-delivery.php`). It never repairs by itself.
- [ ] Test: `tests/data-audit-test.php` with planted bad rows.

## Session 2 — counter shift + cash count (gap 2)

- [ ] `database/upgrade-2026-09-counter-shifts.sql`: `counter_shifts` (admin_id, opened_at,
      opening_cash, closed_at, expected_cash, counted_cash, variance, upi_total, tickets, note,
      closed_by) + `counter_shift_counts` (denomination rows).
- [ ] `includes/countershift.php`: open / current / expected totals **computed from bookings**
      (`sold_by_admin_id`, payment method, between open and now, minus cash refunds) — staff type
      only the counted cash, the system does the rest.
- [ ] `admin/shift.php`: one screen. Open = one number. Close = denomination pad → variance shown
      in green/red → close. Counter bar chip "Shift ₹12,400 · 9 tickets". Optional setting
      `shift_required` (OFF) blocks a counter sale without an open shift.
- [ ] On close: PDF slip (`includes/reportpdf.php`) + WhatsApp summary to the office number
      (`watemplates.php`). Admin list with filters + CSV through `admin/_export.php`.
- [ ] Tests: totals, refunds, two staff at once, variance, scoping (a counter sees only its own).

## Session 3 — "bus is 30 minutes from your stop" (gap 5)

- [ ] Server-side, not in the passenger's browser: when the driver publishes a fix (existing
      livebus store, 10 s) a throttled step (at most once / 2 min / trip) works out distance
      along the route to each remaining boarding stop from `route_stops` coordinates, and ETA from
      a smoothed speed (floor 25 km/h, cap 80).
- [ ] `trip_eta_alerts` (schedule_id, stop, booking_id, sent_at) so each booking is told **once**.
      Threshold `eta_alert_minutes` (30). Channel order: web push (`includes/webpush.php`) →
      WhatsApp template → nothing. Quiet if the fix is older than 5 min or accuracy > 500 m
      (better silent than wrong).
- [ ] `cron/eta-alerts.php` every 5 min as the fallback when no browser tab triggers it.
- [ ] Ticket page + Trip Companion show the same ETA line so the app and the alert never disagree.
- [ ] Tests: synthetic GPS track over the Surat → Rupaidiha stops; no duplicate; stale fix = silent.

## Session 4 — off-site backup (gap 3)

> **Gujarati (gap 4) is DROPPED** — owner, 19 Sep 2026: "Gujarati bhasha chhod deu". The app stays
> en / hi / ne. Do not add a `gu` block. (TicketBot still *reads* Gujarati text; leave that.)

- [ ] **Backup**: `cron/backup.php` already writes `backup_*.sql.gz`. Add `cron/backup-offsite.php`:
      encrypt the newest dump (openssl AES-256, key in `config.php`, never in git) → upload to
      Google Drive with a **service account** + folder shared to the owner (pure PHP JWT, the same
      way `webpush.php` signs — no Composer). Keep 30 daily + 12 monthly. Heartbeat via
      `cron_done()`; a missed or failed upload raises a Health incident.
- [ ] `docs/RESTORE.md` + one real restore drill into a scratch database. A backup that was never
      restored is not a backup.
- [ ] Spare time in this session goes to the **agent mode** work below (start with the audit).

## Session 5 — offline counter queue (gap 1) — the careful one

A seat cannot be sold honestly without the server: two offline desks would sell the same bed.
So offline does **not** sell a seat number. It records a **ticket request** and the server
seats it on sync.

- [ ] Counter / agent only (never the public app). Setting `offline_queue_on` (OFF).
- [ ] Offline at the desk: name + mobile + pickup + count + cash taken → IndexedDB row with a
      client UUID (idempotency key) → printed/shared **"Request slip — seat on confirmation"**, clearly
      not a ticket.
- [ ] Sync: Background Sync where it exists, `online` event + app open elsewhere (iOS has no
      SyncManager). `api/offline-sync.php` → `QuickTicket::plan()` → `counterSale()`; the UUID
      makes a retry harmless. Bus full → request marked **failed — refund / re-seat**, red badge
      on the counter bar until a human clears it. Nothing is ever dropped silently.
- [ ] `offline_requests` table keeps every request and its outcome for the audit trail.
- [ ] Tests: duplicate sync, two desks racing for the last bed, bus full, date passed, clock skew.

## Session 6 — agent mode, fewer clicks, AI, polish, release

Owner, 19 Sep 2026: *Quick Ticket and the map must work well in agent mode; colourful buttons;
it should feel like a person is helping; fast, effective, easy in real life.*

- [ ] **Agent mode audit first**: sign in as an agent on a 360 px phone and walk Quick Ticket, the
      map / navigator, seat map, ticket share. List what is missing or slower than the customer
      or office view.
- [ ] **Quick Ticket in agent mode**: the same one smart line, opened from the agent home in one
      tap, agent code + commission shown on the confirm card, "same as last sale", ticket →
      WhatsApp to the passenger in one tap, wallet balance updated on the spot.
- [ ] **Map in agent mode**: today's buses with live position, "my passengers on this bus" per
      pickup, tap a passenger → call / WhatsApp, ETA to each pickup (from Session 3).
- [ ] **Colourful bottom buttons**: one colour per job across the app (Book = orange, Ticket =
      green, Map = blue, Money = gold, Danger = red) as design-system tokens, 48 px, icon + word,
      same place on every screen. Contrast checked in light and dark.
- [ ] **Human tone**: short spoken-style lines in en / hi / ne ("Seat L12 is yours. Ticket sent on
      WhatsApp."), one question at a time, every error says what to do next. Reuse the SHG
      Sahayak voice; no new chatbot.

- [ ] Work down the click-audit table: defaults from `TicketBot::suggest`, one-screen confirm,
      "same as last sale" at the counter, merge thin admin pages into their hubs (pattern:
      Agent 360, Chalan hub), global `/` search (PNR / phone / name) from any admin page,
      `Enter`/`Esc` everywhere a dialog has one obvious answer.
- [ ] AI: keep the Governor rule — AI never writes booking, seat, fare or refund. Use it for
      reading (free-text → plan), the owner's daily digest, and anomaly notes on the data audit.
- [ ] Brand accent (if approved in P0), Lighthouse pass on a mid-range Android, full battery,
      one release: `shg-v129+`, one asset stamp, `docs/UPGRADE-2026-09-xx.md` in plain language.

## Progress log

| Date | Session | Done | Commit |
|---|---|---|---|
| 19 Sep 2026 | 0 | Codebase survey vs prompt v3.0, this plan | — |
