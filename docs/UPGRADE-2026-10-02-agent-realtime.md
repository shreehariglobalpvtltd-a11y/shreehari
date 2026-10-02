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

## Tests

`tests/live-sync-test.php` (33 checks, registered in run-all CORE): version moves on
reseat / name fix / agent change / bus swap / cancel, not across agents; SeatVersion
moves on a reseat; scope pinning; seat card privacy over HTTP; bus tabs; journey vs
sale basis.
