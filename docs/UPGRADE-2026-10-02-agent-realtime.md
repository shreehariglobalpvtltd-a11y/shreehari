# 2 Oct 2026 — Agent panel ↔ seat map in real time, seat detail card, two buses on one date

Owner (voice note, Nepali/Hindi): on 1 Oct two buses ran on the Surat route; seats
were credited to agents from the seat map, but "agent ko panel ma jodeko chhaina".
Example: agent code 07 (Shiv Shanti) has 16 passengers on yesterday's bus — the
agent panel did not show them. Wanted: whatever is edited / updated / changed
(seat, agent, name, quick ticket fix) shows at once in the agent AND admin panels;
clicking a seat shows its full ticket details.

## What the live data said (read-only, 2 Oct 12:00 IST)

- Code 07 = admins.id 42 "Shiv Shanti" (never logged in). sold_by_admin_id = 42 on
  4 live tickets / 13 seats for the 1 Oct bus (schedule 489) + 1 cancelled ticket
  (3 seats) + 2 tickets / 3 seats on 2 Oct. Ledger: 7 commission rows ₹4,000,
  2 voids −₹800 = ₹3,200 net — consistent. The data was right.
- They were cut by the office on **30 Sep** (source `counter`) and credited to 42 on
  **1 Oct 21:06** from the seat map ("Select seats → assign agent").
- 1 Oct route r2 had three schedule rows: 489 (slot 1, 69 seats), 2216 (slot 2,
  empty), 2217 (slot 3, bus gj2344, 31 seats sold that evening).

**Root cause:** every figure on the agent panel was keyed to the day the ticket was
CUT (`bookings.created_at`): "Today", "This month" (October → only the 2 Oct
tickets), "This week", and My Sales with its default "This month" / "Yesterday".
The owner counts by the bus a passenger RIDES. A ticket credited later keeps its
original sale date, so it never appeared under "yesterday". Nothing auto-refreshed
either, and seat map had no way to reach the second bus except through the Bus
Calendar's `?sid=`.

## What changed

- **Agent panel (`admin/agent.php`)** — new "Passengers by journey date" panel: one
  card per bus (last 7 days + next 30), जना / tickets / amount / cancelled, tap for
  PNR · names · seats, links to that bus's seat map and the day's tickets. Journey
  KPIs: Yesterday's bus / Today's bus / Upcoming (जना). Sale-date cards now also
  print जना. Recent sales + upcoming departures name the bus ("Bus 2 · GJ…") and the
  Sell link carries `sid` for an extra bus.
- **My Sales (`admin/agent-sales.php`)** — 🚌 Journey date (default) / 🧾 Sale date
  switch; journey "week/month/all" run to the end of the period; commission for the
  window read from the ledger by the ticket's travel day; a hint shows the other
  count when they differ ("by sale date: 0 tickets"); जना column; bus tag per row.
  PDF / CSV exports are still by sale date (button tooltip says so).
- **Seat map (`admin/seatmap.php`)** — "Bus 1 / Bus 2 / Bus 3" tabs whenever a route
  + date has more than one departure. Clicking a seat opens a **detail card**
  (`admin/api/seat-detail.php`): PNR + status, every passenger with berth (the
  clicked one highlighted, per-seat cancels struck through), mobile (call /
  WhatsApp), bus + departure, pickup / drop, seats on this bus, fare / paid / due /
  method, seller code + commission on this ticket, booked when, last 6 changes from
  the audit log, ticket image + PDF. Same privacy as the map: another agent's berth
  shows only "sold". Enter / Space open a tile.
- **Live sync** (`includes/livesync.php`) — `LiveSync::version(scope)` hashes what a
  screen prints (bookings, live seats, passengers, legs + the schedule's bus/slot/
  status, ledger). The existing 15 s admin poll (`admin/pending-count.php`) answers
  it when a page drops `LiveSync::anchor()`; on change a read-only board reloads
  itself when the viewer is idle (scroll + open cards kept, never re-POSTs), a page
  with forms shows a "Data changed — Refresh" pill. Agent panel / My Sales / My
  Passengers = auto; Tickets, Date View, Seat map = pill. Pages with the anchor poll
  every 10 s. A counter agent is always pinned to their own scope.
- **`includes/seatversion.php`** — the live seat stream watched count + max id only;
  a reseat UPDATEs `seat_no` in place, so it never fired for a seat change. Now
  includes a berth checksum.

## Round 2 (same day) — "13 jana haina, 16"

- The owner counted 16 for code 07 across the 1 Oct buses; the panel said 13. The
  audit log showed why: a seat-map bulk pick "Select seats → assign agent →
  SHG-0006" at **1 Oct 18:54:46** (10 tickets at once) also took
  **SHG-2026-00453 (D10)** and **SHG-2026-00454 (A11, A12)**, which had been
  credited to SHG-0007 at sale (30 Sep 20:40 / 20:47). Restored to 07 on the
  owner's request with the same AgentWallet::reassignSeller path (commission
  +₹600 to 07, −₹600 from 06, cash untouched, tickets re-made silently); audit
  rows `booking.agent_restore`. Backup of the rows:
  `/root/backups/agent07-restore-20261002-*`.
- **Guard (release-20261002-s4):** bulk assign on the seat map and on Assign
  Agents now SKIPS a ticket already credited to another agent (named in the
  flash) unless "also take tickets that belong to another agent" is ticked; the
  confirm lists those seats either way. The company "Other" code is free to take.
- Agent panel: "🔁 Tickets moved between agents" (30 days, from the audit log).
- Assign Agents search was broken: `:q` bound three times under native prepares.

## Round 3 — Agents page, agreements back, editable agreement points

- **Agents register (`admin/agents.php`)**: 16 columns and ten buttons per row
  made rows five lines tall with the actions off-screen. Now nine columns, one
  line per agent (code + type + name + status when not active, contact, sales,
  commission, wallet · net, salary · advance, KYC · papers) and **👁 View + ⋯**;
  the ⋯ menu (Edit, Wallet & 360, agent panel, sales, tickets, commission,
  login, WhatsApp statement / reminder, deactivate) is placed on `<body>` so the
  table's scroll box never clips it. Fits a 1366 px laptop without sideways scroll.
- **Agreements restored.** The 27 Sep release `release-agreements-v21b`
  (`/root/shg-site.git`, never in the GitHub lineage) had `/agreements/` — the
  Agent and the Partner (Pvt Ltd) agreement in Nepali / Hindi / English /
  Gujarati, an online application (`api/agreement-apply.php`, AGR-numbers),
  the office screen `admin/agreements.php` (applications, KYC, agreement
  status, public list), `api/agent-directory.php` and the Hindi agreement PDF
  (`admin/agreement-pdf.php`). The 1 Oct deploy from the GitHub lineage dropped
  all of it; the database side (`agent_applications`, `admin_profiles`
  agreement columns) was still live. Ported as-is, plus `Response::$maxInputBytes`.
  NOT ported: v21b's `Security::clientIp()` trusted-proxy change (behind the
  Hostinger front it would give every visitor the same rate-limit address).
- **Editable points**: Admin → Agents → **📜 Agreement points**
  (`admin/agreement-points.php`). Per agreement and language: every point in a
  box — change the words, hide, back to original, or add a point under a
  section. `{{name}}` marks a form box and must stay exactly once. Changes live
  in `agreement_points` (migration `upgrade-2026-10-02-agreement-points.sql`);
  `/assets/js/agreement-live.js` on each page fetches
  `/api/agreement-points.php` (60 s cache) and applies them, moving the page's own
  input boxes so typed values stay. The Hindi PDF generator has its own text and
  does not follow these edits.
- Links back on the website: side menu, a home card above "About", footer.
- `tools/agreements/` (content.js + build.js) is the source of the pages; build.js
  now emits the live script tag. `deploy.sh` excludes `tools/`.

## Round 4 — agreements v2.2 from the owner's 22222.docx

The owner sent his agent agreement (Word, Hindi/Nepali) "add these points, and
the same for Pvt Ltd". Both agreements now have the docx's 15 sections, in all
four languages (tools/agreements/content.js → build.js, document code v2.2):

| # | Section | Change |
|---|---|---|
| 2 | Pay | Agent: 2.2 advance now "13th–15th, up to 30%" (was "15th, 30–40%"); new 2.5 "30% of the travel turnover — not fixed; if 2.1's target + fixed pay is written, 2.1 decides" |
| 3 | Bus running schedule and minimum passengers | NEW: daily bus needs 40 per bus, else a bus every 2 days; grow the business, not only commission |
| 5 | Office attendance on the set timetable | NEW |
| 6 | Checks before every departure | NEW: passenger details, pickup + meal stops (Surat → Rupaidiha), staff phone |
| 7 | Online ticket system, fares … (old 4) | +3: automatic SMS / WhatsApp, the booked seat is the seat, self-booking on the website |
| 11 | Term (old 8) | 11.1 + "no other company / no breaking it in the 11 months"; NEW 11.2 business closed for a time → still paid, except government papers / legal problem / own negligence |
| 13 | Pay cycle | NEW: pay on the 5th; advance 13th–15th up to 30%; same for everyone (Partner: commission on the 2.2 day) |

Renumbered: old 3→4, 4→7, 5–7→8–10, 8→11, 9→12, 10→14, 11→15; the cross
references follow (Part A "11 months — clause 11.1", guarantors "clause 15.3",
secrecy "as in 9.1"). The Partner agreement got the same sections, adapted
(commission instead of salary; no 30%-turnover clause — its 2.1 has the rate
fields). lint-text.js / the page tests now expect 15 sections.

⚠️ agreement_points rows are keyed by point NUMBER. Before any future rebuild
that renumbers, check `SELECT * FROM agreement_points` (it was empty on live
when v2.2 shipped) and move rows to their new numbers. The Hindi agreement PDF
(includes/agreementpdf.php) has its own text and is unchanged.

## Tests

`tests/live-sync-test.php` (33 checks, registered in run-all CORE): version moves on
reseat / name fix / agent change / bus swap / cancel, not across agents; SeatVersion
moves on a reseat; scope pinning; seat card privacy over HTTP; bus tabs; journey vs
sale basis.
