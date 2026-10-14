# SmartBook v5 — Step 0 Audit + Plan (15 Oct 2026)

Base: live release `release-20261014-vp1` (shg-v211, 937 files). Live DB `shari` padheko (read-only SELECT matra).
Kunai code wa DB change gareko chhaina.

---

## 1. Stack ra folder map

| Kura | Ke chha |
|---|---|
| Server | Hostinger VPS 8 GB, Ubuntu 24.04, nginx + PHP 8.3-FPM + MariaDB `shari` (121 tables). Disk 51% used |
| Domains | `shreehariglobal.in` (customer) ra `shreehariglobal.network` — **dubai eutai webroot**, `.network` ko `/` → `/admin/` (nginx ma already chha) |
| Customer SPA | `index.php` (front controller, `SHG_BOOT` inject) + `app.template.html` + `assets/js/01..26-*.js` (hash routes `#/`, `#/my`, `#/nav`, `#/terms`) |
| API | `api/*.php` (41 file) — book, lock, seats, search, quote, quick-ticket, payment, seat-events (SSE) … |
| Business logic | `includes/*.php` (~110 class) — `booking.php` (BookingService), `seats.php`, `fare.php`, `boarding.php`, `quickticket.php`, `ticketbot.php`, `ticket.php`, `notify.php`, `agentwallet.php` |
| Admin/agent/counter | `admin/*.php` (100 page), counter mode = `assets/js/14-counter.js` |
| Online gateway | `payment/` (ICICI UAT) — **live ma matra chha, git ma chhaina** ⚠️ |
| Cron | 29 job (root + www-data crontab). Hold/expiry release: `cron/expire.php` har 5 min |
| Tests | `tests/` 185 file, `tests/run-all.php` |
| Deploy | `deploy/deploy-prod.sh` — drift guard + backup + DB dump + atomic write + ROLLBACK.txt (yo nai prayog garne) |

### DB — mukhya table ra rows (live)
`bookings` 715 · `booking_passengers` 1,585 · `booking_seats` 1,225 · `booking_legs` 715 · `payments` 715 · `tickets` 713 ·
`schedules` 403 · `route_stops` 70 · `routes` 13 (2 active: r2 Surat→Rupaidiha 13:00, r7 Rupaidiha→Surat 18:00) ·
`seat_locks` 2 · `schedule_unit_locks` 711 · `user_profiles` 312 · `users` 66 · `admins` 39 (33 agent, 4 counter) ·
`counter_locations` 11 · `settings` 460 · `agent_ledger` 1,169.

Booking mix (last 45 din): counter 535, web 146 — **web ko 145 / 146 already COD** (Cash). Online UPI verify bhaeko jamma 2 wata.
Matlab aile ticket dhilo hune mukhya karan online verify bhanda pani **4-screen flow** ho; customer le already Cash nai roj-chhan.

### Booking flow files
- Customer: `06-results.js` (seat map + Seat AI) → `05-router.js` (`/seats.php`, `/lock.php`, poll) → `07-checkout.js` → `api/book.php` → `BookingService::create()`.
- **Booking lekhne function 2 wata matra**: `BookingService::create()` (`includes/booking.php:189`) ra `BookingService::counterSale()` (`:901`).
  10 channel (web, counter, admin seatmap, admin new-booking, agent paper register, QuickBot desk/customer, WhatsApp, Sahayak, offline queue) yi dui mai pugchhan.
- QuickBot: `TicketBot::parse` (server, rule-based, AI chhaina) → `QuickTicket::plan` → `create()`.
- Sahayak: `AiChat` le book gardaina; `AiAgent` tool (`issue_ticket` / `staff_sell`) → `QuickTicket` → `create()`.
- Ticket: `Ticket::issue` idempotent; PNG (GD, `renderTicketPng`) ra PDF (`renderTicketPdf`) **alag-alag renderer** — content milena (reporting time PDF ma matra, 30 min hard-coded).
- WhatsApp: `Notify::whatsapp` → `WaRoute` (`wa_policy=meta_first`); SMS off (`sms_enabled`).
- Realtime: `api/seat-events.php` SSE chha tara **`seat_events_on=0`** (off) → browser 8 s ma poll.

## 2. Berth lock — ek berth dui janale paauna sakchha?

**Sakdaina (code proof):**
- Hold: `seat_locks` `UNIQUE(schedule_id, seat_no)`, physical bed ma store; `Seats::lock` upsert + owner read-back.
- Sale: `booking_seats` `UNIQUE(schedule_id, seat_no)` + `claim()` `INSERT IGNORE` + owner check.
- Private "L3" ra sharing "L5" eutai bed hune case: `assertAvailable()` le schedule row `SELECT … FOR UPDATE` garera booked beds check garchha. Sabai write path (create, counterSale, reschedule, transfer, reclaim) yo check bata janchhan.
- Cancel/expire ma seat row DELETE hunchha (`released_at` kahile set hudaina) → unique key le cancelled lai block gardaina. Live: 553 confirmed sabai ma seat row chha, 157 cancelled ma 0.
- Deadlock retry 3 choti (`db.php:344`).

**Kamjori:**
1. Book garna hold token chahidaina (aru le hold gareko bed matra refuse hunchha).
2. **Idempotency key kunai booking/payment endpoint ma chhaina.** Double-tap = button disable + 10-min duplicate guard (lock bina) matra.
3. Gender NULL bhaeko passenger le cabin gender-lock set/break gardaina — ra live ma **1,431 / 1,585 passenger ko gender NULL** chha.
4. Seat AI browser ma matra (`pickSeatsSmart`, `06-results.js:702`); server ma QuickBot ko lagi chhuttai `QuickTicket::pickSeats`. Dui engine.

## 3. Data quality

| Samasya | Pramaan |
|---|---|
| Boarding naam free-text | `booking_legs.boarding_stop` ma ~36 rup: "S Hari Parking, Nana Chiloda @ 21:00 [..]", "…(Amd) @ 23:00", "Chiloda (AMD) · …", "amd s hari global parking", "Baroda" vs "Vadodara", "Ankeshwar" (typo) |
| Stop coordinates | 10 boarding stop madhye 4 ma matra lat/lng (Surat, Kamrej, Vadodara, Nana Chiloda). Rajkot, Ankleshwar, Bharuch, Anand, Nadiad, Emli = NULL → "nearest point" ko lagi coordinate chahinchha |
| Gender | 90% passenger ko gender chhaina (history bata prefill garna mildaina) |
| Duplicate customer | `user_profiles` mobile PK bhaekole duplicate chhaina. 64 mobile ma alag-alag primary naam (agent/counter le eutai number halne + arko ko lagi book) |
| Fare | 5 thau ma copy: `Fare::cabinFare/quote`, `api/quote.php`, `QuickTicket::fareFor`, `counterSale` (staff le typed amount rakhna milchha), client `calcFare` |
| Sharing tier | Single/Double/Triple browser ma matra; server ma sabai sharing eutai per-person fare. Live ma 543 sharing booking sabai `cabin_type=single`, `sharing_tier` NULL |
| Policy | `refund_slabs` setting (live): 96h→90, 48h→75, 24h→50, **6h→25**, 0→0. "Paisa kati din ma" ko text: 5–7, 3–5, 7–10, 7–21, 14–21 — 5 alag kura |
| Distance | `routes.distance_km`=1375; tara `route_stops.km_from_origin`: Surat→Rupaidiha **1,688**, Ahmedabad→Rupaidiha **1,372**; FAQ ma "1,300"; home graphic ma 1,635 |

## 4. D8 / D9 / D10 ra agent-field — badalnu parne thau

### D8 cancellation (48h 75% / 24–48h 50% / <24h 0 / 5–7 working days)
- DB: `settings.refund_slabs` → `[{"minHrs":48,"pct":75},{"minHrs":24,"pct":50},{"minHrs":0,"pct":0}]`
- Fallback copy (5-tier) hataune: `includes/fare.php:789-795`, `refund-policy.php:28-36, 47, 49, 170-171`, `includes/legal.php:171-187`, `includes/routepages.php:110`, `includes/walocal.php:962`, `includes/aiprompt.php:90-107`, `assets/js/02-config.js:414-419, 1518-1520`, `assets/js/07-checkout.js:752-770`, `database/upgrade-2026-09-counter-role.sql` (record matra)
- Hand-typed text: `app.template.html:2399, 2542, 3094`; `assets/js/04-i18n.js:300, 326, 888, 910, 1466, 1488` (`payPolicy`, `refundLine`); `assets/js/16-lazy.js:1011-1014, 2135` ("3–5 days"); `assets/js/terms-data.js:6` (96/90 + "7–21 days", Gujarati); `includes/notify.php:3354, 3368-3375`; `agreements/*-agreement-*.html:294` (herne)
- Ticket PNG/PDF: aile policy text chhaina (link matra) → D8 line thapne

### D9 distance 1,375 km
- Thik chha: `app.template.html:1451, 1919`, `16-lazy.js:30`, `terms-data.js`, `routes.distance_km`
- Badalne: `app.template.html:1653` (1,300), `app.template.html:1462-1468` (graphic 1,635), `16-lazy.js:217, 250`, `10-track.js:32`, `22-routeline.js:81-90` (1,688/1,372 dekhaune thau), `13-admin-routes.js:406-440` (1330/1370/1400)
- `Boarding::journeySummary()` (`includes/boarding.php:1208-1226`) le stop km lai pahile rojchha → Surat passenger lai 1,688 dekhinchha

### D10 Rajkot
- DB ma **already chha**: route r2 boarding sort 7 (Anand ra Nadiad ko bichma), fare 2200, **time NULL, coordinates NULL, km NULL**; r7 ma drop sort 10. Live ma 11 booking Rajkot bata ("Rajkot office pickup (+91 94268 27507)").
- `visible_to` jasto mode flag **chhaina** — sabai mode ma eutai list (ON/OFF matra).
- Hardcoded list ma Rajkot chhaina: `02-config.js:488, 1186-1230`, `includes/fare.php:90`, `admin/routes.php:59-70`, `10-track.js`, `22-routeline.js`, `app.template.html:225, 1552, 1577`.

### D1/D13 customer page bata agent kura hataune
- Search: `app.template.html:612-620` `#sAgentCode` ("Agent? Enter code to book a departed bus") → `13-admin-routes.js:1400-1406`, `03-accounts.js:77-91`, `05-router.js:2674`, `api/search.php:30-43, 298` (departed-bus mode)
- Checkout: `app.template.html:2425-2436` `#cAgentCode` → `07-checkout.js:261-266, 1314-1322, 1512-1515, 1598, 1629-1639`; server `api/book.php:115-117, 236-242`, `includes/booking.php:258-264` (agent grace)
- QuickBot: `#qtAgentIn` `05-router.js:1674, 1732, 1747`; `api/quick-ticket.php:190`, `quickticket.php:919-969`
- Ticket ma agent code: `07-checkout.js:2860, 2896`, `08-signin.js:803`, `11-pdf-ticket.js:341, 833`
- "Become an Agent" section (`app.template.html:1966-2001`) — yo marketing ho, **rakhne** (signup page ma lane) bhanne mero suggestion
- API leak: chhaina — `SHG_BOOT.staff` admin login bhae matra; search/seats/config ma commission/wallet chhaina ✅
- ⚠️ **Prayog:** last 30 din ma web ko 65 madhye **24 booking agent code bata** (5 Oct ma matra 16). Hataye pachhi agent haru `.network` panel bata bechnu parchha → agent mode tayar bhae pachhi matra hataune (same release).

## 5. Plan (phase, file, migration, risk)

Siddhanta: **purano engine (BookingService::create) rewrite nagarne**; mathi `bookingService` facade banaune, duplicate logic tyasma sarne. Migration sabai ADDITIVE.

| Phase | Ke garne | Mukhya file | Migration |
|---|---|---|---|
| **2 Content** | D8/D9/D10 eutai source: `SiteFacts` ma `policy` (slabs + days + NE/HI/EN text) ra `route_km=1375`; sabai literal hatayera tyaha bata padhne; Rajkot ko time/address/coords/order milaune; "facts ratchet" test (96/90/1,300/1,688 kahi dekhiye fail) | `includes/sitefacts.php`, ~25 file mathi list | `settings.refund_slabs` UPDATE; `route_stops` Rajkot row UPDATE |
| **3 Engine** | `includes/bookingservice.php` facade: nextDeparture, boardingPoints(mode), fareQuote (eutai), availability, autoBerth (PHP, H1–H5 + S1–S7, <50 ms), holdBerths (all-or-nothing, hold token), confirm(cash\|online, idempotency key), cancel, ticket. `counterSale` typed amount = admin override + reason + audit | `includes/bookingservice.php`, `includes/autoberth.php`, `api/book.php`, `api/quote.php`, `quickticket.php` | `booking_requests(idem_key UNIQUE, booking_id, …)`; `seat_rules`; `route_stops.visible_to SET('customer','agent','counter')` default sabai; `route_stops.stop_key`; `booking_legs.boarding_key` (backfill, purano text na-chhoi) |
| **4 UI** | Claude Design mockup (customer phone/desktop, agent, counter, light+dark) → OK → `BookingCard(mode)` home hero ma; purano `#/results` flow fallback ko rup ma kehi din rakhne | `assets/js/27-bookcard.js`, `assets/css/bookcard.css`, `app.template.html`, `04-i18n.js` | — |
| **5 Payment** | Cash: COD instant → `/#/?ticket=PNR` modal/rail, PDF auto-download, action bar. Cash book page (Pending/Collected, kun counter/agent, din ko total, Excel/PDF). Online: 15-min hold → branded QR page + countdown; `PaymentAdapter` (ManualQr + ICICI) | `includes/payadapter.php`, `admin/cash-book.php`, `pay.php`, `payment/*` (pahile git ma lyaune) | `payments.collected_at_counter`, `payments.collected_note` (additive) |
| **6 Bot** | QuickBot + Sahayak → facade; details pura bhae autoBerth + hold aafai; "Card ma herau" | `ticketbot.php`, `quickticket.php`, `aitools.php`, `05-router.js` | — |
| **7 Panel** | `.network` ma Agent mode (code locked, wallet, departed-bus), Counter mode (keyboard, 80 mm print, regular top 20), Admin settings + Default Rules screen (IF→THEN, preview, audit). Customer bata agent field yahi release ma hataune | `admin/book.php` (BookingCard mode), `admin/default-rules.php`, `admin/seat-rules.php`, `includes/defaultrules.php` | `default_rules` table |
| **8 Release** | Pura test battery + nayā tests (section 13 ko list), `deploy-prod.sh`, migration haath le, rollback | `tests/smartbook-*.php` | — |

### Risk
1. **Agent code hataunu** — 37% web booking agent bata; agent mode tayar nabhai hataye bikri ghatchha.
2. **Refund slab** badalda `Fare::refundFor` ko output badalchha (6h→25% hatchha). Refund ta haath le nai garne (tapai ko niyam) — policy text matra badalinchha.
3. **360×640 ma scroll bina 9 field** dherai tight chha — compact chips + collapsed section le garne; mockup ma pahile dekhaunchhu.
4. Lighthouse mobile ≥ 90: SPA thulo chha (app.template.html + 26 JS) — card lai pahile load, baki lazy.
5. Gender history chhaina → pahilo booking ma gender chip required huncha (1 tap).
6. `payment/` (ICICI) git ma chhaina — deploy garda chhoidaina tara rollback/track hudaina; pahile commit garne.
7. Arko parallel session le pani live ma deploy garna sakchha — har deploy agadi VPS branch fetch + drift guard.

### Rollback
`deploy-prod.sh` le har release ma `/root/backups/pre-deploy-<release>-<ts>/` (touched file + DB dump + ROLLBACK.txt) banauchha.
Migration additive bhaekole purano code naya column lai bewasta garchha — code rollback matra pugchha.

## 6. Backup command (suru garnu agadi)

```bash
ssh -i ~/.ssh/shg_vps_ed25519 root@93.127.167.249
TS=$(date +%Y%m%d-%H%M); B=/root/backups/pre-smartbook-$TS; mkdir -p $B
mysqldump --single-transaction --routines --triggers --events shari | gzip > $B/shari.sql.gz
tar czf $B/webroot.tgz --exclude=public_html/logs -C /var/www/shreehariglobal.in public_html
cp -a /var/repos/shg.git $B/shg.git
ls -lh $B && gunzip -t $B/shari.sql.gz && echo BACKUP-OK
```
PC ma copy (off-site):
```bash
scp -i ~/.ssh/shg_vps_ed25519 -r root@93.127.167.249:/root/backups/pre-smartbook-* "C:/S HARI PRJECT'/backups/"
```
