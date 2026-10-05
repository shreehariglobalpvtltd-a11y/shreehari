# 5 Oct 2026 — Agent & financial management add-on

Owner pasted the 13-point "SHG Admin — Agent & Financial Management System" prompt (Point 0 … Point 12).
The prompt's facts about the stack were stale (it says Supabase and `git push vps main`; the site is PHP + MariaDB and
deploys with `deploy/deploy-prod.sh`), and about 70 % of what it asks for was already live. So the work was:
audit every point against live `release-20261005-b6`, then build only the real gaps.

## What was already live (not rebuilt)

Agent profile / KYC / photo / code / login, the agent's own panel, per-agent commission override, flat + percent
commission, reversal on cancel, advances and loans with a cap, cash-in-hand tracking, payout requests, statements
(screen / PNG / PDF / CSV), receipts, Accounting (collections by method, cash flow by day), Analytics (bookings and
revenue per day, route earnings, website funnel), the bookings register with 12 filters and CSV / Excel / PDF,
activity log, backups (daily + Google Drive), opt-in 2FA, 45-minute idle timeout, light / dark / AMOLED displays,
phone bottom bar, the daily WhatsApp summary.

## Built in this release

| Point | What | Where |
|---|---|---|
| 0 | Nepal office card on the home page: CEO / Operations / Parking, tap-to-call + WhatsApp, a drawn Nepal flag (Windows prints "NP" for the emoji). Same strip on the admin dashboard. Numbers typed on Admin → Company details. | `Settings::nepalTeam()`, `admin/company.php`, `05-router.js renderNepalTeam()`, `redesign.css` |
| 11, 1, 2 | **One channel tag per booking** — Online / Agent / Counter / Admin / WhatsApp / Google Ads. Derived from what a booking already stores; the only stored fact is `bookings.channel_tag = 'WHATSAPP'`. An agent's sale through the public page with their code now counts as Agent (it was hidden under Online on every count). | `includes/bookingchannel.php` |
| 11 | "Came from a WhatsApp message" tick on the counter checkout and Quick Ticket; the WhatsApp assistant's own sales are tagged; correctable on the booking page. | `api/book.php`, `api/quick-ticket.php`, `includes/wabooking.php`, `admin/booking-view.php` |
| 1 | Dashboard: Confirmed / Online customers / Agent bookings / WhatsApp requests tiles, six-tag donut (each row opens that list). Analytics: bookings by channel (chart + table), online customers per day, top-10 agents, seat fill per departure, Yesterday / Last month, Refresh + optional 5-minute auto refresh. | `admin/index.php`, `admin/analytics.php` |
| 2 | Bookings register: WhatsApp + Google Ads in "Sold via", Today / Yesterday / This week / This month / Last month chips, click-to-sort (Booked, Travel, Amount, Status), 25 / 50 / 100 rows. Export and PDF use the same channel filter. | `admin/bookings.php`, `admin/export.php`, `includes/reportpdf.php` |
| 3 | Agent **search box** (name / code / city) over the agent lists on Quick Ticket, the counter checkout, the seat-map quick row and New settlement. Agent is now compulsory on Quick Ticket on the server too. | `assets/js/agent-picker.js`, `14-counter.js`, `admin/quick-ticket.php` |
| 4 | Agent ranking: passengers, cancel %, repeat customers, three more rankings, and **side-by-side comparison** of 2–3 agents with a trend chart. | `admin/agent-ranking.php` |
| 5, 8 | Commission earned / paid / due per agent per month, CSV + Excel. | `admin/settlements.php?tab=grid` |
| 7 | **Settlements**: waiting list (days since last paid), draft → approved → paid → agent confirms or disputes, itemised deductions, bonus, cash net-off, cash received, printable receipt, batch drafts and batch approve, register with filters + export. | `includes/agentsettlement.php`, `admin/settlements.php`, table `agent_settlements` |
| 9 | **Notification centre** with a bell and unread count; alerts for cash held too long, commission unpaid, advance above limit, low bookings, cancellation spike, idle agents, new online customer — each with its own switch and numbers. WhatsApp copy is a separate switch, off by default. | `includes/adminalerts.php`, `admin/notifications.php`, `cron/alerts.php` |
| 10 | Two roles: **operations** (no settlements / money reports / settings) and **viewer** (read-only, six pages, every non-GET request refused). Accountant = finance admin. | `includes/auth.php`, `admin/staff.php` |
| 12 | Dark is the default display, Poppins across the panel, figures count up, buttons ripple, `.fx-skel` shimmer block. | `admin/_guard.php`, `assets/css/admin.css` |

Fixed on the way: the bookings export dropped the "Completed" status filter; the ads table on Analytics used "last N
days from now" instead of the window shown.

## Money rules (unchanged)

Nothing is automatic. A settlement draft, an approval and a cancellation change only the settlement row. The agent
ledger is written at one moment — when someone with `commissions.pay` presses **Mark paid** — through
`AgentWallet::record()`, in one transaction, and a second press finds the row already paid. If the ledger moved after
approval (a cancelled ticket, a payout typed on Agent 360) Mark paid is refused. Alerts only read.

## Database

`database/upgrade-2026-10-05-agent-finance.sql` (additive, safe to repeat): `bookings.channel_tag`, table
`agent_settlements`, `admins.role` + `operations`, `viewer`, and old admin notifications marked read once.

## Tests

`tests/agent-finance-test.php` — 72 checks (channel tags = register filters, Nepal contacts, the whole settlement
life including the no-double-payout and ledger-moved cases, alerts, roles).

## Not built — needs the owner

- **Nepal numbers**: Operations (Lokesh Mama) and Parking (Vishal Bhai) show on the site once their phones are typed
  on Admin → Company details. The CEO line uses +91 97264 01507 until a Nepal number is typed.
- **Passenger name optional at the desk**: every ticket, the chalani and the border manifest print the name. A
  nameless ticket is a policy change, not a code change — say yes and what should print ("Passenger"?).
- **Route-wise / slab / monthly-bonus commission rates**: the rule engine is one function
  (`AgentWallet::commissionForBooking`) but the rates themselves are the owner's to give. Until then a bonus is added
  by hand on a settlement.
- **GST report**: `tax_percent` is 0 and no GSTIN is filled — nothing to report yet.
- **Bus-wise P&L**: expenses are recorded per route and date, not per bus; needs a "which bus" field on expenses first.
- **Colour scheme in the prompt** (#0f0f1a / #e94560): kept the navy + orange brand the site, tickets and logo share;
  dark is now the default. Say so if the red palette is really wanted.
- OTP login for office staff, IP allow-list, scheduled e-mail of reports, swipe actions, pull-to-refresh.
