# 5 Oct 2026 — Help Desk, All Customers + Campaigns, Google Drive backups, SHARI NAV on our own server

Owner brief (Nepali, 4 Oct night): help desk advanced + automatic + gives a number; SHARI NAV
like Google/Apple Maps for 1–2 km, map moves forward with the GPS, light and fast on our own
server/engine; customer list with everyone, date filter, agent filter (whose, how many), all
numbers in one place, campaign launch / message send for marketing; Google Drive backup that
asks on open (back up or Skip); deploy everything.

## 1. Help Desk (admin/support-inbox.php, help.php, api/helpdesk.php, includes/helpdesk.php, cron/helpdesk.php)
- Every ticket gets **HD-0051** (row id) + **today's token #7**. Older SUP-… refs still resolve.
- Doors: Help page `/help.php` (new, public), chat complaint desk + "call me back" (api/enquiry.php now
  opens a ticket and returns the number), WhatsApp assistant (AiHandoff → `HelpDesk::adopt()`),
  desk/phone ("+ New ticket" on the admin page).
- Automatic: topic + priority guessed from the words (safety = urgent), booking linked (PNR or latest
  trip on that number), SLA due time per priority, auto-assign to the least-busy support person
  (desk staff of the booking first, only people seen in 14 days, never the owner), number sent to the
  customer (AiSensy template `shg_help_ticket` → WhatsApp → SMS; `ack_sent_at` = accepted, not
  delivered), office WhatsApp alert for urgent/high, cron escalates an overdue ticket ONCE and closes
  resolved ones after N days.
- Desk page: KPIs (open / in progress / overdue / today / first-reply time), filters (Mine, Not taken,
  Overdue…), search by number/mobile/PNR, reassign dropdown, quick replies, WhatsApp/SMS/internal,
  "Send & resolve", rules panel (owner/manager).
- Migration: `database/upgrade-2026-10-05-helpdesk.sql`. Cron: `2-59/5 * * * *` cron/helpdesk.php (www-data).

## 2. All Customers & Numbers + Campaigns
- `admin/customer-directory.php`: one row per mobile (booking number + family 2nd number, optional
  leads), filters: booking/travel date, quick ranges, agent/counter/online, route, India/Nepal,
  status, min trips, "not travelled for N days", search. "Whose customers — how many" table.
  Export (reports.export): Excel, CSV, contacts `.vcf`, plain numbers. "No offers" block per number.
- `admin/campaigns.php` (marketing.send — manager + owner): audience = the directory filter, frozen
  into `mk_recipients`; channels: AiSensy approved template (automatic, cron `* * * * *`
  cron/campaigns.php, rate/min, quiet hours 21–08, daily cap 300, delivered/read read back), one-tap
  WhatsApp from your own phone (free), SMS (Brevo). Test send, schedule, pause/resume/cancel.
  STOP on WhatsApp → `mk_optout` (wabot hook). Staff / placeholder / opted-out never queued.
- Migration: `database/upgrade-2026-10-05-campaigns.sql`. New perm `marketing.send` (manager).

## 3. Google Drive backups (admin/backup.php, includes/gdrive.php, includes/dbdump.php, cron/backup-gdrive.php)
- Owner connects ONE Google account (OAuth, scope `drive.file` only). Needs a Google Cloud OAuth
  client (Web app, redirect `https://www.shreehariglobal.in/admin/backup.php?oauth=1`, app published
  "In production"). Refresh token stored AES-GCM with APP_KEY.
- Once a day the admin panel asks (owner/managers): **Back up now / Skip / every night**.
  Not connected → Set up / Save to phone (share sheet → Drive app, zero setup) / Skip.
- Copies go to "S Hari Global Backups", encrypted with the off-site password when set, newest
  `gdrive_keep` kept (older → Drive trash). Local "make a backup" + owner-only download.
- Migration: `database/upgrade-2026-10-05-gdrive-backup.sql`. Cron: `40 2 * * *` cron/backup-gdrive.php.
- Also fixed: admin/settings.php wiped `*_private` secrets (push_vapid_private) on every save.

## 4. SHARI NAV on our own server
- Data (outside the webroot, `/srv/shg-nav`): OSM extracts (Geofabrik) → Gujarat + Nepalgunj/Kohalpur/
  Rupaidiha (`deploy/navdata/01-fetch-extract.sh`), vector tiles z0-14 with tilemaker (100,604 tiles,
  443 MB), fonts + sprites + styles (`02-build.sh static`), 53k places → MySQL `nav_places`
  (`import-places.php`), Valhalla routing engine in docker `shg-valhalla` (127.0.0.1:8002, restart
  unless-stopped). nginx `location ^~ /navdata/` (`deploy/navdata/nginx-navdata.conf`).
- Client (`assets/js/15-nav.js`): own styles via `shgt://` protocol (own tiles inside the area,
  OpenFreeMap planet outside, so no holes), routing via `/api/nav-route.php` (own Valhalla, public
  only outside our area — header X-SHG-Nav), search via `/api/nav-search.php` first, current area
  name from our place index, MapLibre itself from `assets/vendor/maplibre-gl-4.7.1` (unpkg fallback).
- Driving / walking camera: heading-up along the route ahead, 52° tilt, arrow in the lower third,
  speed-based zoom, all chased per frame (map slides forward with the GPS); start = smooth fly-in;
  drag/rotate hands control back, 📍 takes it again. Tile requests deduped + 80-tile memory cache.
- Refresh the map data monthly: `nice bash 01-fetch-extract.sh && nice bash 02-build.sh tiles`,
  `02-build.sh places` + import, `02-build.sh valhalla` (rebuild ~5 min).

## Tests
`tests/helpdesk-campaign-nav-test.php` 42/42. `tests/wa-ops-manager-test.php` updated (HD numbers).
Battery: the 11 other red suites fail identically on the base tree (environment: cron subprocess
without php.ini, HTTP suites, stale fixtures).

## Owner to-do
1. Google Cloud OAuth client → paste on Backups page → Connect.
2. AiSensy: templates `shg_help_ticket` (UTILITY) and `shg_offer` (MARKETING) submitted for approval.
3. Help desk: make sure support staff log in (auto-assign only picks people seen in 14 days).
