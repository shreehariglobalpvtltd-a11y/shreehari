# Redesign — 4 Oct 2026 (release-20261004-r1)

Two owner briefs: (1) premium, mobile-first redesign of shreehariglobal.in and
shreehariglobal.network on one design system; (2) phone-first company app,
branded multi-page agent PNG statement, audited commission edits.
Base: release-20261003-a5. No database migration.

## Design system
- Source of truth: the "S Hari Global" Design System artifact (claude.ai) →
  `assets/css/ds.css` (tokens for light + dark, `sg-` components) and
  `assets/img/icons.svg` (one 24px line-icon sprite).
- Royal blue = the one action colour; navy = app bar / sidebar / ticket band;
  restrained red = a 3px accent rule only; orange only for verified offers.
- Every text pair ≥ 4.5:1 in both themes; status never by colour alone.

## Customer app (shreehariglobal.in)
- `assets/css/redesign.css` (loads last): legacy tokens → design system, looping
  decoration off, booking screens restyled. The 7 day-palettes are retired.
- Home leads with the booking panel: direction, date strip, town, passengers
  (default 1), "Find Bus / बस खोज्नुहोस्". Two other ways to book follow
  (Quick Ticket, WhatsApp). The splash is gone (no first-visit hold, no
  unprompted permission prompts). Bottom nav: Home · Book · My Tickets · Help.
- Seats: a tapped seat reads "holding…" until the server grants the hold;
  "not held yet" + retries when the server cannot be reached.
- Removed the seeded "N+ passengers travelled this week" ticker (invented figure).
- Hidden until the office confirms the facts: #whyUs, #facilities (deep),
  #testimonials ("Launching 2 Sep"), #styleHighlight (hard-coded ₹2,090).
- Verification promise unified: "within 30 minutes (9 AM–9 PM IST)".
- `sw.js` never stores a page marked `X-SHG-Personal` (signed-in customer or
  staff) as the offline shell; `index.php` marks those pages + `no-store`.

## Company app (shreehariglobal.network /admin)
- `admin/_guard.php`: ds.css + `assets/css/admin.css`; panel tokens alias the
  design system; no webfont download. Navigation in eight areas (Dashboard,
  Bookings & tickets, Seat management, Trips/buses/chalani, Agents & counters,
  Ledger & payments, Expenses & reports, Settings) — every link and permission
  gate unchanged.
- Phones: spreadsheet registers become cards; 44px targets; 16px inputs;
  keyboard-aware bottom bar (`interactive-widget=resizes-content`, body.kb-open).
- Save guard: a one-time `_once` token per form submission; `admin_boot()`
  rejects a repeated token (double tap, refresh, retry) and shows the first
  result with a note (?dup=1). "Saving…" state; typed values offered back when
  no confirmation arrived.
- Dashboard money tiles (`includes/moneysummary.php`, Accounting's exact
  definitions): Collected, Confirmed fares, Still to collect, To verify,
  Commission, Profit ("Not available" until expenses are recorded). Shown to
  reports.view roles only.
- Accounting now needs reports.view (same gate as Payroll): counter and
  official desks keep Verify Payments and Shift & Cash.
- Settings: jump list, plain group titles + one-line explanations, on/off
  switches that say "not saved yet", Save kept in reach. Keys/values unchanged.

## Agent statement (admin/agent-statement.php)
- `includes/agentstatement.php`: one dataset from `AgentWallet::statement()`
  feeds the screen and the PNG pages. Re-added summary must equal the ledger
  closing or no image is produced.
- PNG pages 1080px wide: logo, agent name/ID, period, PNR, journey date, जना,
  rate note, Dr/Cr, running "who owes whom", summary + closing on the last page.
  Download each / all, Web Share on phones. Rendered on demand, cached by data
  fingerprint under uploads/statements/agent-<id>/.
- An agent sees only their own statement; office roles need commissions.view.
- Agent 360: a reason is required for commission rate changes (server-checked);
  audit rows keep the effective rate before/after; adjustments keep the balance
  before/after. History listed on the statement page.

## Pre-production review fixes (asset stamp 20261004b, sw shg-v167)
- Save guard: JSON/AJAX posts (paper-ticket outbox, fetch saves) are never
  redirected; a form that answers with a file download opts out
  (`data-no-guard`, agreement PDF); the "restore what you typed" offer is keyed
  to the record (query string) and the form; back/forward cache resets the
  one-time token and a stuck "Saving…" button.
- sw.js never opens `?counter=1` on the stored anonymous shell.
- "Still to collect · now" = every cash-on-boarding fare not yet collected
  (same query as Verify Payments → COD) + ticket cash sellers still hold.
- Agent statement PNG: the summary box gets its real height (one line per kind
  present) and moves to its own page instead of covering rows; the cache key
  includes the rate note and company details (layout stmt-v2).
- ds.css type utilities renamed `.sg-t-*` so they cannot restyle existing
  markup (admin `.kpi` cards kept their look).
- QuickBot error/understood text readable, ticket buttons hidden in print,
  language pill visible on phones, home blocks full width, offer pill royal,
  seat legend swatches by class, register filter bar no longer sticky.

## Verified
- tests/run-all.php --http: 121/126 locally; the 5 others are environmental and
  pass where the environment exists (e2e-booking 45/45 and role-gates 73/73 on
  the worktree DB; sitemaps + HarfBuzz checked on the VPS staging copy).
- 23 admin pages + agent + counter screens at 360/390/430px: no sideways scroll,
  no PHP noise. Agent statement on live data (agent 47, 349 rows): closing
  ₹5,76,400 = wallet net.
- Speed on live (throttled phone, cold, median of 5): before FCP 4.0 s /
  LCP 8.5 s / 38 requests / 669 KB; after FCP 2.2 s / LCP 6.0 s / 36 requests /
  585 KB. (Staging showed LCP 2.0 s only because its banner was the largest
  paint; the live LCP element still paints late and is the next speed target.)

## Deploy (4 Oct 2026 10:20 IST)
- Production: release-20261004-r1 = 52b4dc6 on /var/repos/shg.git, files-only
  (28 files); every touched live file was checked equal to release-20261003-a5
  first. Backup + rollback notes: /root/backups/pre-deploy-redesign-20261004-102013
  (ROLLBACK.txt). No database change.
- Staging (:8443, shari_staging) retired after the deploy: nginx site, DB, user
  and code removed.
