# S Hari Global — continuation after session 1 (2 Oct 2026)

## Where the code lives
- Branch `claude/shreehari-global-upgrade-746a70`, pushed to the VPS bare repo as **`release-20261002`** (`/var/repos/shg.git`). Built on `github-live-20261001` (5e63651 = GitHub 489af7d + PR #16 + buses.php festival panel + Gujarat drop).
- **NOT on GitHub yet** — this machine has no GitHub push credentials. Until the branch reaches GitHub, a "Deploy to VPS" workflow run of any GitHub branch will REVERT this release (pickup fares, Rajkot, TicketSync, counter memory). Push it from a machine with access:
  `git fetch <vps-remote> release-20261002 && git push github release-20261002` and open a PR into `claude/project-thread-rg55gt` lineage.

## LIVE now (deployed 2 Oct 2026 10:18–10:25 IST)
| Commit | What |
|---|---|
| 427d2b0 | Agent code optional for direct customers; 'OTHER' sentinel normalised to no agent |
| 521800b | Battery runs on Windows (contact-layer jar, devshape env); automation-test back in CORE |
| f616a93 / 23391e2 | Counter desk: agent picked once and remembered per clerk (shared with QuickBot), payment chip + boarding remembered, Same bus / Same date / Sell another, Confirm from seat view |
| 6c9d9ab | TicketSync: one hook regenerates the ticket (QR, PNG, link, audit, honest change notice) after every booking change; cancel/reject voids the ticket |
| c8ed410 | Pickup points: fare + ON/OFF + reorder in Admin → Routes; Gujarat→Rupaidiha Emli Bhupal & S Hari Parking Chiloda 2000, other pickups 2200; Rajkot added (last, 2200, no time); one location list everywhere; OFF pickups refused server-side |
| ab7fc7d | Kept the overnight live additions: refund-policy.php (+ /refund-policy route), footer link, tile/mask icons |
| eeb7326 | Asset stamp 20261002b, service worker shg-v165 |

Migration applied on live: `database/upgrade-2026-10-01-stop-fares.sql` (route_stops.fare + is_active, fares seeded, Rajkot row, Rajkot in company_counters; RJT already in counter_locations).

## Backups + rollback
- Session start (1 Oct 20:13): `/root/backups/pre-upgrade-session1-20261001-201352/` — full DB, full site tar, nginx/php/cron config, `ROLLBACK.md`.
- Pre-deploy (2 Oct 10:18): `/root/backups/pre-deploy-20261002-101856/` — `db-shari.sql.gz`, `code.tar.gz` (webroot without uploads/tickets/logs/backup/invoice), SHA256SUMS, rsync change lists.
- Code rollback: `tar -xzf /root/backups/pre-deploy-20261002-101856/code.tar.gz -C /var/www/shreehariglobal.in/public_html && chown -R www-data:www-data /var/www/shreehariglobal.in/public_html && systemctl reload php8.3-fpm` (NB: that tar is the BROKEN overnight state — for a clean pre-release tree use `git --git-dir=/var/repos/shg.git archive github-live-20261001`, see the 1 Oct ROLLBACK.md).
- The stop-fares migration only ADDS two columns + one Rajkot row; rollback is not needed for the old code (old code ignores the columns). To remove Rajkot: Admin → Routes → Pickup points → OFF.

## ⚠️ Incident found during deploy
At 00:19 on 2 Oct another session deployed SEO files to live from the stale 13-Sep branch (`production-booking-upgrade` 707f57d): `index.php`, `app.template.html`, `sw.js` became 13-Sep copies (/bus and /privacy-policy served the homepage; old template + current JS; sw shg-v126) and it used stamp 20261002a. This release restored the current files and carried over the verifiable additions. Not carried (owner to confirm): a Facebook link `profile.php?id=100028518powerful` (not a valid id), a rewritten company JSON-LD, WhatsApp link wa.me/919173401507. Left on live untouched: `og-gen.php` (web-reachable generator script — consider deleting), `assets/img/og-share.png`, static `sitemap-pages.xml` (shadows the dynamic one served by index.php). **Any session working in `C:/S HARI PRJECT'` (main checkout, 13-Sep branch) must not deploy again.**

## Tests
Full battery `SHG_TEST_BASE=http://localhost:8951 php tests/run-all.php --http`: 113 passed / 1 failed (an order expectation then corrected → locations 55/0). New suites: ticket-sync (55), counter-desk-memory (55), locations-consistency (55). Battery now 115 suites.

## Owner decisions / inputs still needed
1. Rajkot pickup **time and landmark** (Admin → Routes → Pickup points & fares → ✎). Until set, Rajkot follows the 13:00 departure for the booking cut-off.
2. Trip-level changes (departure time / bus swap / trip cancel): should every passenger get a re-issued "CORRECTED" ticket + WhatsApp? (`trip_change` kind exists in TicketSync; not wired.)
3. Change notices now really send (they were silently suppressed before) — costs WhatsApp messages; `notify_ticket_edit` setting turns them off.
4. Return-route fare stays 1800 (only outbound priced).
5. `commission_agent_only` is 0 on live (staff sellers still accrue commission).

## Session 2 — remaining scope (from the owner brief)
Audit maps with path:line evidence are in `docs/audit-2026-10-02/*.json` and `docs/AUDIT-2026-10-02-perf-domain-tests.md`.
- **R2 domains**: .network = same webroot + db; plan = pure 301 vhost to www (bare / → /admin/), serve sw-reset.js at .network /sw.js; snapshot `nginx -T` first; fix 15-nav.js:821 text; gate SW/PWA install to the canonical origin. Do NOT empty .network before owner approval.
- **R5 login**: email password recovery (otp_codes `pwreset:` + signed link, Notify::email), optional email OTP, 2FA must not strand when WhatsApp is down, accounting/offers/export gating for counter role, trusted-proxy clientIp.
- **R6 AI**: intent layer (route/fare/status/change/commission/ops), consume web staged quotes once (resell bug), require the person's own "yes" for issue/sell/cancel/office_confirm, provider-failure diagnostics panel.
- **R4 UI/PWA**: readable full logo lockup, consistent icon set, compact home (cut duplicated entry points, #homeMore lazy), animation trim, manual install entry + honest platform limits.
- **R7 perf**: per-language i18n split, minified bundle, sw.js 42 KB comment, occupancy 69 queries, booking_legs travel_date index, logo webp.
- **R3h money**: per-seat refund ledger (processed refunds can reopen as due), staff cancels use customer slabs, SeatVersion after transfer.
- Counter: seat-map walk-in pane + new-booking legacy form have no agent picker (CA-2); desk sales accrue staff commission before reassignment (CA-7).
