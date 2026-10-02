# Session 2 — 2 Oct 2026: AI Watch, phone comfort, 2-tap QuickBot, analytics range, travel info, staff payroll, VPS tidy

Owner brief (chat, 2 Oct): finish the remaining work from the "SHG Build Prompts"
artifact; make every feature easy from a phone for admin AND agents (bigger
buttons); AI should watch everything, help and suggest; a ticket in 2–3 taps;
VPS files in one place and quick access.

Built on `release-20261002` (35416c8, byte-identical to live before this deploy).

## Audit first — what the artifact asked for that ALREADY existed
| Artifact item | Already live as |
|---|---|
| Booking status lookup | `track.php` (keyed WhatsApp link), My Bookings → "Track with booking ID" (PNR + phone, no account), `verify-ticket.php` |
| WhatsApp confirm / 12 h reminder / cancel | `includes/notify.php` (Twilio + Meta Cloud API drivers), `cron/reminders.php` (12 h + 2 h) |
| EN / हिं / ने toggle | `assets/js/04-i18n.js` |
| Promo codes | `coupons` + `admin/offers.php` + `Fare::autoOffer` |
| WhatsApp float button | Admin → Settings |
| Passenger documents | `passenger_documents` + `admin/passenger-doc.php` |
| Agent commission / salary / loans / statements | `admin/agent.php`, `agent-360.php`, `agent_loans`, `download-statement.php` |
| Expenses | `trip_expenses` via `admin/accounting.php#expenses` |

Wrong facts in the artifact (ignored): `git push vps main` deploy, `booking.html` /
`counter-agent.html`, `config/whatsapp.php`, a new `admin/staff/` tree with
duplicate agent/commission tables.

## What this session added
- **AI Watch** — `includes/aiwatch.php`. A ranked "what needs you now" list read
  from live tables (rules, not an LLM: instant, free, exact, works when every AI
  provider is down). Office: payments to verify, trips without bus/driver (one
  line per day; driver check only once Drivers & Crew has drivers), departures
  under 40 % within 48 h (one folded line) or ≥ 90 % full, failed WhatsApp
  tickets, agents holding cash (past hand-over date if that rule is on), refunds
  due, urgent/open support requests, offline-desk sales not ticketed, new
  enquiries, yesterday's expenses not entered, pickups without a time, staff not
  paid last month. Seller (agent / counter): own pending payments, cash to hand
  over, passengers travelling tomorrow, free berths on the next bus (→ QuickBot),
  commission this month. Every line is gated on the permission of the page it
  links to. Shown on the dashboard (today view), the agent's own panel and the
  counter role's home (Tickets, unfiltered first page). Capacity = berths that
  physically exist on the coach (`Seats::seatIds`), never `schedules.total_seats`.
- **Phone comfort** (admin_css in `admin/_guard.php`, ≤ 820 px): 48 px buttons and
  fields, 16 px text, labels wrap, taller bottom bar + 58 px Ticket FAB, quick
  actions as 2-up tiles, KPI tiles 2-up, one-row top bar on small phones,
  register cards wrap long names (offers.php overflowed to 435 px).
  **Flash bug fixed everywhere**: `.flash` was `display:flex`, so every text run
  and `<b>` became its own squeezed column on a phone.
- **QuickBot desk 2 taps** (`admin/quick-ticket.php`): on a phone the hero is one
  slim row and the WhatsApp note one line — the QuickBot line moved from y≈1084 to
  y≈399; a summary (date · pickup · seat · fare) sits right above Confirm because
  the suggestion card is below the form on a phone. Type the line → Confirm.
- **Analytics** (`admin/analytics.php`): Today / This week / This month / Last 30
  days / Custom window on every KPI, chart and table; 4 KPI tiles; daily bookings
  line; lower vs upper deck fill in physical berths; boarding doughnut; CSV export
  (BOM, formula guard, `bookings.view`, agents scoped). Default = last 30 days.
- **Travel info** (`travel-info.php`, `/travel-info` via index.php): documents,
  border crossing, checklist, FAQ (+ FAQPage JSON-LD) in EN / हिं / ने; contact
  numbers from Settings; footer link; sitemap entry.
- **Staff payroll** (`admin/payroll.php`, `includes/payroll.php`, migration
  `upgrade-2026-10-02-staff-payroll.sql`): people without a panel login
  (helpers, conductors, office, cleaners…) — monthly salary, advances with
  automatic deduction, one settlement per person per month, WhatsApp slip, CSV.
- **Speed**: `booking_legs (travel_date, leg_type, booking_id)` index
  (`upgrade-2026-10-02-perf-indexes.sql`); `sw.js` 65 KB → 23 KB (the 42 KB
  release-notes comment moved to `docs/SW-VERSIONS.md`; `bump-asset-ver.js` now
  keeps one note on the VERSION line and prepends the old one to that file).
  Server side was already fast (TTFB 80–140 ms, brotli, 1-year immutable assets).
- **VPS in one place** (`deploy/vps-tidy.sh`, dry-run by default): old site
  copies → `/root/archive/<date>-old-copies/`, stray backups → `/root/backups/older/`,
  stray `php -S :8899` test server stopped, dead cron lines (scripts that do not
  exist) commented out, `/root/SERVER-MAP.md` written. `deploy.sh` DB dumps now
  go to `/root/backups/deploy-db`. Nothing deleted.
- **Security**: live nginx served `/deploy.sh` and `/deploy/*` (scripts + nginx
  conf). The repo's `deploy/nginx-add-deny-rules.sh` (backup + `nginx -t` +
  auto-rollback) had never been applied on live — applied in this deploy.

## Tests
New suites: `aiwatch-test.php` (CORE), `analytics-range-test.php` (CORE),
`payroll-test.php` (CORE), `travel-info-test.php` (HTTP).
`tests/render-admin.php` accepts `SHG_RENDER_AS=<username>` to render a page as
an agent / counter.

## Follow-ups noticed (not changed)
- Dashboard occupancy (`admin/index.php`, `admin/api/live-status.php`) still uses
  `schedules.total_seats` (bookkeeping column, default 40) — Analytics and AI
  Watch use real berths; the dashboard bar can disagree.
- Aadhaar: `includes/routepages.php` FAQ `a1` says Aadhaar with photo is accepted
  at the border; travel-info says it is often NOT accepted on its own for adults
  (carry passport or Voter ID). Owner to decide one answer.
- Reporting time differs by page (20 / 30 / 60 min); luggage wording in
  routepages `a6` differs from the Terms.
- `refund-policy.php` hard-codes WhatsApp +91 91734 01507; Settings resolve to
  +91 91048 01507.
- `.chip.on` is hard to read in dark mode on every admin page (fixed only on
  Analytics).
- `reports.export` permission exists but nothing checks it.
