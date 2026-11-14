# Staff side at 360 x 640 — audit and fixes (14 Nov 2026)

Branch `shari/staff-mobile-360-46478b`, on top of `f56bb227`. LOCAL ONLY — nothing deployed, nothing pushed.
Layout and presentation only: no booking, fare, seat, payment, commission, permission or login code was changed.

## How it was measured

Not by eye. [`audit.js`](audit.js) is loaded in a signed-in staff page on the local server and opens every URL in a
same-origin iframe of 360 x 640 (touch pointer), then counts:

| Column | Meaning |
|---|---|
| over | px the whole page is wider than the screen |
| off | visible elements cut off at the right edge (count / worst px), not counting boxes that scroll sideways on purpose |
| card off | card-table cells whose right edge is past the screen or past their box (count / worst px), and the widest card-table |
| tap | buttons, selects, inputs, `<summary>` rows under 40px high (count / smallest px) |
| text | elements whose own text is under 12px (count / smallest) |
| bar | fixed bottom bar and how many px of the last content it hides |
| top | height of the top bar that stays stuck while scrolling |
| focus | form fields that end up off-screen or under a fixed bar when focused / fields tested |

Raw numbers for every page: `raw-before-*.txt` and `raw-after-*.txt` (superadmin 36 pages, agent 20, counter 18) plus the driver app.
Local test accounts: superadmin, one agent, one counter, one driver (git-ignored `.claude/local-test-logins.txt`).

## What was wrong — the shared causes

| # | Cause (file) | Reach | Before | After |
|---|---|---|---|---|
| 1 | Top bar wrapped into 4 rows and stayed stuck (`admin/_guard.php`) | every staff page | 234px stuck (199px agent / counter) = 37% of the screen; fields focused from below landed under it | 140px at the top of the page, **52px** stuck (menu + search); `scroll-padding-top` keeps a focused field clear |
| 2 | `table.dt{min-width:760px}` (and `table.pm` 860px) kept on phone card-tables (`_guard.php`) | 20 pages hard-code `dt card-table` | cards 760px wide in a 332px box, value of every row off-screen | cards = box width, 0 cells off-screen |
| 3 | A card cell is a flex row that could not wrap (`_guard.php`) | every card-table | Chalani cards 398–509px; Tickets 25 cells overflowing | pieces stay right-aligned and wrap |
| 4 | `<tfoot>` total row kept table layout beside block cards (`_guard.php`) | Accounting, Chalani, Agent 360, Settlements, Analytics | cards squeezed to 218px of 334, table wider than its panel | total row is one more card, labelled from the header |
| 5 | A `<select>` is as wide as its longest option; its label grew with it (`_guard.php`) | Seat Map, Chalani bus picker, any long select | Seat Map 68px wider than the screen; bus picker off-screen | field never wider than its box |
| 6 | Bare fields 25px, bare `<summary>` 22px, `button.pill` 20px, pop-up "Refresh" 26px, drawer rows 33px (`_guard.php`) | many pages | see tap column | 40px or more on a touch phone |
| 7 | Pro skin: `.pc-btn` 30/36px, `.pc-switch` 20px, page grid column 349px on a 336px phone (`admin/_pro.php`) | Bus Control, Ticket statement, other pro pages | Bus Control 1px overflow with the right gutter gone, 35 small controls | 0 overflow, 40px controls |

Tables that are meant to scroll sideways (`no-card`, `sm-table`) were measured before and after on Agents, Customers,
Analytics, Customer ledger, Passengers and Schedule: same table width, same scroll box, same column widths
(Agents 1176 / 334, Customers 1217 / 334, Customer ledger 1429 / 334, Passengers 843 / 334). They never get the card-table class.

## Page table — superadmin (36 pages)

Every page: stuck top bar 234px → 52px. Only what else was found is listed.

| Page | Problem found | Before | After |
|---|---|---|---|
| Management home `home.php` | search field under top bar; small controls | focus 1/2; tap 2 (33px) | focus 0/2; tap 0 |
| Dashboard `index.php` | small quick buttons | tap 4 (33px) | tap 0 |
| Hub `hub.php` | small controls | tap 2 | tap 0 |
| SmartBook desk `smartbook.php` | Pay bar floated 64px above the bottom edge and hid the last 88px of the card for good; field under the bar; small controls | bar covers 88px; focus 1/8; tap 28 (32px) | bar on the bottom edge, covers 0; focus 0/8; tap 11 (see "not fixed") |
| Tickets `bookings.php` | filters under the top bar; card values overflowing; "ID Baaki (Verify)" pill 20px high, 10px text | focus 6/9; 25 cells overflow; tap 29 (20px) | focus 0/9; 0; tap 0 |
| Ticket view `booking-view.php` | small rows and fields | tap 20 (30px) | tap 0 |
| Chalani list `manifest.php` | page wider than screen; cards wider than panel; small row buttons | over 15px; 26 cells off, worst 160px; card 509px; tap 20 (30px) | over 0; 0 cells; card 336px; tap 0 |
| Chalani send `chalan.php` | bus picker off-screen; date field under top bar; history row 22px | focus 2/8; tap 3 (22px) | focus 0/8; tap 0 |
| Seat chart `seatmap.php` | page 68px wider than screen (route select); small fields | over 68px; 1 element cut 68px; focus 1/13; tap 8 (25px) | over 0; 0; focus 0/13; tap 0 |
| Verify Payments `payments.php` | filter strip 4px past the screen | over 4px | over 0 |
| Agents `agents.php` | small controls (table scrolls sideways on purpose — unchanged) | tap 3 | tap 0 |
| Agent 360 `agent-360.php` | card wider than box; 18px "Edit details" row | 1 cell off 35px; card 382px; tap 12 (18px) | 0; 334px; tap 0 |
| Agent portal `agent.php` | small controls | tap 3 | tap 0 |
| Ticket statement `ticket-statement.php` | 30px buttons | tap 9 (30px) | tap 0 |
| Reconcile `reconcile.php` | every value off-screen | 234 cells off, worst 417px; cards 760px | 0; 332px |
| WhatsApp inbox `wa-inbox.php` | small controls (inbox empty in the test DB) | tap 3 | tap 0 |
| Settings `settings.php` | search under top bar; small controls | focus 1/7; tap 6 | focus 0/7; tap 0 |
| AI Easy Settings `easy-settings.php` | field under top bar; 27px example chips | focus 1/4; tap 12 (27px) | focus 0/4; tap 0 |
| Bus Control `bus-control.php` | 1px page overflow, right gutter lost; 20px switches | over 1px; tap 35 (20px) | over 0; tap 0 |
| Agents & Staff `people.php` | every value off-screen; 32px row buttons; "Edit" unreadable in dark theme | 128 cells off, worst 414px; focus 1/30; tap 135 (32px) | 0; card 332px; focus 0/30; tap 0 |
| Team `team.php` | small controls | tap 3 | tap 0 |
| Customers `customers.php` | small controls (table scrolls sideways on purpose — unchanged) | tap 3 | tap 0 |
| Accounting `accounting.php` | total rows squeezed the cards | cards 218px of 334 when a total row is present; tap 3 | cards 334px; tap 0 |
| Settlements `settlements.php` | values off-screen | 32 cells off, worst 413px | 0 |
| Cash book `cash-book.php` | date field under top bar | focus 1/6; tap 3 | focus 0; tap 0 |
| Trips `trips.php` | 29px fields | tap 138 (29px) | tap 0 |
| Schedule `schedule.php` | 32px icon buttons | tap 40 (32px) | tap 0 |
| Refunds `refunds.php` | 33–35px fields | tap 30 | tap 0 |
| My desk `my-desk.php` | small controls | tap 2 | tap 0 |
| Help desk `support-inbox.php` | values off-screen | 30 cells off, worst 414px | 0; tap 0 |
| Shift `shift.php` | small controls | tap 3 | tap 0 |
| Sessions `sessions.php` | values off-screen | 13 cells off, worst 414px | 0 |
| Permissions `permissions.php` | values off-screen | 388 cells off, worst 514px (860px table) | 0 |
| P&L `pnl.php` | values off-screen | 60 cells off, worst 414px | 0 |
| Agent statement `agent-statement.php` | fields under top bar; bare 22px buttons | focus 3/4; tap 6 (22px) | focus 0/4; tap 0 |
| Search `search.php` | small controls | tap 2 | tap 0 |

After, all 36 pages: page overflow 0, elements cut off 0, card-table cells off-screen 0, fields hidden on focus 0.

## Agent view (20 pages) and counter view (18 pages)

| Role | Before | After |
|---|---|---|
| Agent | top bar 199px stuck on all 20; Seat Map 68px wider than the screen; fields under the top bar on 8 pages (Tickets 5/8, offline ticket 3/13, statement 2/3, passengers 2/3, ticket statement 2/3, home 1/2, SmartBook 1/6); small controls on every page (1–14) | top bar 52px; overflow 0; hidden fields 0; small controls 0 except 11 inside the SmartBook card |
| Counter | top bar 199px; Chalani list 15px wider, 26 cells off (160px); Seat Map 68px; Verify Payments 4px; Help desk 30 cells off (414px); hidden fields on 5 pages; small controls on every page (1–28) | top bar 52px; overflow 0; cells off 0; hidden fields 0; small controls 0 except 11 inside the SmartBook card |

## Driver app (`driver/`)

| Screen | Before | After |
|---|---|---|
| Sign in | clean (0 overflow, 0 small controls) | same |
| Trip list (12 trips) | "Sign out" 22px high | 40px |
| Run screen | "Sign out" 22px high | 40px |

The run screen was measured as it stands before START TRIP (shown without starting a trip: starting one needs GPS and writes live-trip rows).

## Desktop (1280 x 800) — unchanged

Every change sits inside `@media (max-width:820px)`, `(max-width:560px)` or `(max-width:859px)`, except one dark-theme
colour on People (the "Edit" button text) and the 40px driver "Sign out". Checked, not assumed: the untouched code
(`git archive f56bb227`, port 8962) and the changed code (port 8961) were loaded side by side at 1280 x 800 and a
fingerprint (tag, class and box of every visible element) was compared on 25 pages: home, index, bookings, manifest,
chalan, seatmap, payments, agents, agent-360, ticket-statement, reconcile, people, booking-view, easy-settings,
schedule, agent-statement, bus-control, settings, accounting, permissions, wa-inbox, hub, settlements, support-inbox,
smartbook. All 25 identical on the final code when both are loaded at the same moment (the shared test DB changes while other sessions run tests,
so a pair loaded seconds apart can differ in row count — those were re-run together and matched).
Tablet 768 x 1024 (the phone rules apply up to 820px): 9 pages checked, 0 overflow, 0 cells off.

## Not fixed, and why

1. **Buttons inside the SmartBook card (11 controls, 32–38px):** mic, send, "Private?", "Change", Quick / Full form,
   "How?", − / +, gender and age chips. Their rules live in `assets/css/smartbook.css`, which the customer front-end
   session owns in parallel. The staff page's own controls around the card are 42px.
2. **Text under 12px (recorded, not changed):** small uppercase captions and chips at 10–11px on most pages, seat
   codes in Bus Control (629 elements at 10px), seat-chart labels 9–9.6px. Making dense seat grids bigger changes
   how many seats fit across a 360px screen — an owner decision, not a silent one. The name strip in the top bar
   went from 11px to 12px. (`index.php` "3px" is text inside SVG charts, not real 3px text.)
3. **Text links under 40px high** (PNR links, names in lists): counted in the `link` column, left as they are.
4. **Top bar between 561 and 820px (tablets)** still wraps as before (113px at 768px). Only phones ≤560px got the new bar.
5. **A long signed-in name** makes the name strip scroll sideways; "Sign out" stays pinned at its right end.

## Not verified

- A real phone with the on-screen keyboard open. Everything was measured in an emulated 360 x 640 touch viewport in
  the desktop app's browser; the focus check is "focus, then is the field visible and not under a fixed bar".
- Roles other than superadmin, agent, counter and driver (manager, accountant, support, operations, viewer, scanner,
  official). They share the same shell and pages.
- Pages with real volume: the test DB has ~25 tickets, 2 passengers on the measured trip, an empty WhatsApp inbox.
- Light theme (the test logins are on the dark theme). The changes are colour-neutral except the total-row card
  (`var(--head)`), the pinned "Sign out" (`var(--navy)`) and the dark-only "Edit" colour on People.
- The driver run screen while a trip is running.

## Tests

`php -l` clean on all 15 changed PHP files. Suites that cover the changed files were run on the untouched copy
and on the changed worktree — same result on both for all 18:

- Pass on both: counter-role, one-door (166), one-door-texts (41), per-seat-cancel (40/40), recovery-render,
  two-apps (53), smartbook-discount (20), smartbook-who (10), agentpro-web (66), live-sync (19), aiops (38),
  agent-isolation (35).
- Already failing before this work, identical after: agent-360-render (60 pass / 20 fail), quick-ticket (84 / 6),
  manifest (4 / 40), role-gates (7 / 55), asset-version (8 / 1), counter-mode-http (1 / 32). The HTTP ones report
  "HTTP 0" (they call a server address that is not this worktree's); asset-version is a `?v=` stamp in the
  front-end files.

## Files changed

`admin/_guard.php` (shared shell), `admin/_pro.php` (pro skin), and small scoped phone rules in `admin/people.php`,
`manifest.php`, `smartbook.php`, `payments.php`, `seatmap.php`, `booking-view.php`, `easy-settings.php`,
`agent-360.php`, `agent-statement.php`, `schedule.php`, `index.php`, `support-inbox.php`, and `driver/index.php`.

Screenshots (360 x 640): `before-*.jpg` / `after-*.jpg` for the top bar (Tickets), Reconcile, People, Chalani list,
Seat Map, and `after-smartbook-paybar.jpg`.
