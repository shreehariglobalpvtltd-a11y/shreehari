# Nepalgunj counter, per-desk books, NPR — 26 Sep 2026

Branch `feat/nepal-counter-npr` (on the VPS bare repo as `wip`).
Base = the live commit `058f3aa` (`release/deploy-local-brain-20260926-050442`).
Nothing here is live yet.

---

## 0. मालिकलाई छोटोमा (owner, in short)

**के भयो:**

- अब **काउन्टर एउटा ठाउँ हो**, नाम मात्र होइन। जति वटा काउन्टर चाहिन्छ थप्न मिल्छ
  (Admin → **Counters & collection**), हरेकको आफ्नै देश, आफ्नै पैसा र आफ्नै रेट।
- **नेपालगञ्ज NPR मा बेच्छ।** टिकटमा `INR 2,000.00` सँगै `NPR 3,200` छापिन्छ, र
  त्यो दिनको रेट त्यही टिकटमा **फ्रिज** हुन्छ — भोलि रेट बदलिए पनि पुरानो टिकट बदलिँदैन।
- **हरेक ठाउँको आफ्नै हिसाब।** टिकट, सिट, बिक्री, दराजमा आएको नगद (नेपालगञ्जको NPR मा पनि),
  उठाउन बाँकी, फिर्ता — ठाउँ अनुसार, अनि कम्पनीको जम्मा तलको लाइनमा।
- **तीन वटा ढोका बन्द भयो** — काउन्टर लगइनले पहिले (१) पूरा कम्पनीको दिनभरिको हिसाब,
  (२) सबै यात्रुको फोन/इमेल भएको CSV, (३) कम्पनी भरिको छुट बनाउने अधिकार पाउँथ्यो।
- **ग्राहकको कार्ड**: गोलो फोटो (**फोटो त्यही मोबाइलमै बस्छ**, VPS मा जाँदैन), अगाडि झण्डा —
  थिचेर भारत/नेपाल छान्न मिल्ने, र झण्डा फेर्दा टिकटको WhatsApp पनि सही देशमा जान्छ।
- **नेपाली एजेन्टको लगइन कोड भारत जान्थ्यो** — मिलाइयो।
- दराज (cash drawer) अब त्यही काउन्टरको पैसामा गनिन्छ — नेपालमा **१००० को नोट** छ, २०० छैन।

**अझै बाँकी (क्रममा):** §5.

**खतरा भन्नै पर्ने कुरा:** §6 — खास गरी नाम+नम्बरले मात्र लगइन हुने नियम।

---

## 1. What was asked (26 Sep 2026)

> "counter mode बनेर टेस्ट गरेर के के requirement चाहिन्छ त्यो ध्यानमा देऊ … नेपालको लागि
> Nepalgunj को location … Nepal to India ticket system, counter system, ticket काट्ने
> system, payment collection … एउटै seat मा real time … सबैको लागि अलग अलग add गर्न मिलोस्
> जति पनि, सबको हिसाब किताब admin ले हेर्न मिलोस्, चलानीमा नि छुट्टिनु पर्‍यो …
> NPR मा बेच, दुबै record … जो जसले first time खोल्छ त्यसको profile pic, name, number …
> photo offline मोबाइलमा safe होस्, सानो गोलो frame … फोटोको अगाडि झण्डा …
> ticket काटिसकेपछि WhatsApp मा आफैं जाओस्, केही लेख्नु नपरोस्"

---

## 2. What the four modes actually do today (tested, not assumed)

Ran on the isolated test copy (`/root/shg-test`, db `shari_test`) at the live commit:

| suite | result |
|---|---|
| counter-mode-test | 46 / 0 |
| counter-role-test | 49 / 0 |
| counter-location-test | 21 / 0 |
| counter-shift-test | 24 / 0 |
| cross-mode-seat-sync-test | 28 / 0 |
| cross-mode-hold / integrity | 32 / 0, 10 / 0 |
| **double-booking-race-test** | **10 / 0** |
| role-gates-test | 62 / 0 |
| agent-isolation / agent-wallet | 35 / 0, 62 / 0 |
| money-guards-test | 22 / 0 |
| notify-country-test | 33 / 0 |
| quick-ticket-test | 157 / 0 |

**One seat inventory, guaranteed by the database.** `booking_seats`, `seat_locks` and
`seat_blocks` are keyed by `schedule_id` only — there is no per-desk inventory anywhere.
A `UNIQUE(schedule_id, seat_no)` plus `SELECT … FOR UPDATE` on the schedule row inside the
booking transaction means Nepalgunj and Surat **cannot** sell the same berth, even in the
same second. That half of the owner's ask was already true; it is now also proven by a
multi-process race test that was already in the tree.

What is weak is not safety but **freshness**: live seat push (`seat_events_on`) ships OFF, so
one desk learns of another's sale after 8–15 s of polling, and the admin-side sale screens
take no hold at all while a clerk types. See §5.

Full battery on the branch: **96 suites pass, 3 fail, 1 missing** — and those same three
(`uploads-private`, `home-entry`, `route-pages`) fail identically on the live base commit, so
they are not regressions.

---

## 3. What shipped on this branch

### 3.1 A desk is a place (`database/upgrade-2026-09-counter-desks.sql`)

New table `counter_locations`: `code, name, country(IN/NP), currency(INR/NPR), fx_rate,
phone, address, is_active, sort_order, note`. Seeded with the ten desks the old settings row
carried, **plus Rajkot**, with Nepalgunj / Dhamboji / Kohalpur as NP+NPR and Rupaidiha
correctly left as the **Indian** side of the border.

`includes/counterdesk.php` (`CounterDesk`) reads it, and falls back to the old
`counter_locations` settings row when the migration has not been applied — so the code can be
deployed before the SQL, in either order, with no failed ticket. The settings row is kept in
sync as a human-readable mirror, so everything still reading it keeps working.

### 3.2 The sale freezes where and in what money

| column | what it holds |
|---|---|
| `bookings.counter_code` | the desk that cut the ticket, at the moment of sale |
| `bookings.fx_currency / fx_rate / fx_total` | what the customer was quoted, and the rate used |
| `payments.local_currency / local_amount / fx_rate` | what the drawer physically received |
| `counter_shifts.counter_code / currency` | which desk's drawer, counting which money |

**INR stays the company's currency.** Fares, discounts, refunds, commissions and every
existing ledger are untouched, so nothing had to be re-audited. The NPR is recorded *beside*
the rupee, never instead of it — so no figure ever has to be converted back, and none can be
converted wrongly.

Before this, the desk on a ticket was read at print time from the seller's `admin_profiles`
row — i.e. *today's* desk for that person. Moving a clerk from Surat to Rajkot moved every
ticket they had ever sold, and last month's Surat sheet changed.

### 3.3 Admin → Counters & collection (`admin/counters.php`)

Add/edit/close any number of desks, and the book split by place for any date range: tickets,
seats, rupees sold, cash in, online, still to collect, refunded, staff on the desk. A Nepal
desk's row also carries the NPR quoted and the NPR in the drawer.

**Sold** is the desk that cut the ticket. **Cash in** is the desk whose drawer the money went
into (it follows `payments.verified_by`) — a ticket sold at Nepalgunj and settled at Surat is
Surat's cash, and crediting it to Nepalgunj would leave both drawers wrong.

Rows written before the stamp existed fall back to the seller's current desk and are marked
"from profile".

### 3.4 Three doors closed (found by reading the role map against the pages)

| page | was | now |
|---|---|---|
| `admin/accounting.php` | `payments.view` — held by role `counter`, which is **not** scoped — so any window read the whole company's day, agent commission owed and who holds our cash | `commissions.view` |
| `admin/export.php` | `bookings.view` + a null agent scope → one window could download every passenger's phone and e-mail, ever | anyone unscoped must hold `reports.export` |
| `admin/offers.php` | said "only a manager or super-admin", then checked `payments.verify` (held by counter/official) | `coupons.edit` |

### 3.5 Desk isolation — `counter_desk_isolation`, ships **OFF**

`Auth::deskScopeCode()` pins a counter login to its own desk's **ticket register, payments
and passenger list**. The manifest, seat map and trips board are deliberately **not** scoped:
the bus is shared, and the clerk boarding it must see everyone on board. The office is never
scoped. Switched from Counters & collection, which says exactly that on screen.

**Known limit:** it is applied to the three LIST queries. Detail pages (`booking-view.php`)
and POST actions are not scoped, so a determined clerk can still open another desk's ticket
by URL. It separates the *books*, not the building.

### 3.6 The ticket, the desk screen, the drawer

- PNG + PDF print `NPR 3,200` beside `INR 2,000.00` for a Nepal-desk sale, with the frozen
  rate. Layout stamps bumped so cached tickets re-render.
- Quick Ticket's NPR line now belongs to the desk (only where the desk collects NPR, at
  **that desk's** rate), the badge is flagged, and a clerk with **no desk** is warned.
- The drawer counts the desk's own notes — Nepal has a 1000 and no 200, so a Nepalgunj
  cashier could not previously enter their largest note and every close read as short. The
  closing slip to the office says NPR where the drawer holds NPR, and names the desk.

### 3.7 The traveller's card (`assets/js/21-me.js`)

Round photo + flag + name + number, in the sign-in sheet and on My Bookings.
**The photo never leaves the handset**: scaled to 256 px in the browser and written to that
device's `localStorage`. Not uploaded, not in a backup, not on the VPS, invisible to staff.
Name and number are the account and stay on the server, as before.

The flag is not decoration: tapping it re-signs the account in with that country, so
`users.country_code` is corrected and the next ticket's WhatsApp goes to +977 rather than to
a stranger on the same ten digits in India. Flags are **drawn as SVG** — Windows ships no
glyph for a flag emoji and renders it as the two letters.

### 3.8 The chalani separates the desks

One bus carries tickets cut at Mehsana, Surat and Nepalgunj, and the office settling the trip
has to know whose money is whose. The Nepali waybill now prints a **काउन्टर अनुसार संकलन**
block — per desk: passengers, ticket value, cash, online — and a Nepal desk's line also carries
the NPR it actually took. Printed only when a desk is involved, so an online-only sheet is
unchanged. (`admin/manifest.php` carries `counter_code` on every row; `includes/chalanipdf.php`
draws the block. The PNG variant still prints `Rs` — §5.)

### 3.9 A desk with no bank account takes cash

`counter_locations.allowed_methods` (NULL = no rule). Seeded `cash` for NPJ / NPJD / KHL,
because the company has no Nepali gateway and no Nepali account. Enforced in
`BookingService::create()` — the one path every app sale goes through, so it cannot be worked
around from a browser — and the chips for methods a desk may not take are not drawn at all.

### 3.10 Counter mode knows which window it is

The staff boot in `index.php` now carries the desk (code, name, country, currency, rate,
allowed methods), so the main seat-map sale screen shows **📍 Nepalgunj — Bus Park (NPJ) ·
NPR @ 1.6** on its bar and puts the NPR beside the rupee on the "to collect" line. A clerk with
no desk assigned is told so, there and on Quick Ticket. Asset stamp `20260926h`, `sw-v170`.

### 3.11 The WhatsApp message says the NPR too

The ticket picture carried the NPR; every WhatsApp body still said only the rupee, so a
Nepalgunj passenger read "₹2,000" in the message and "NPR 3,200" on the image attached to it.
`Notify::bookingMoney()` now puts both on the approved template's `{{6}}` and on every
free-text ticket, reissue and payment body.

### 3.12 A Nepali agent's (and admin's) login code no longer goes to India

`Auth::agentLoginStart()` sent the OTP with no country hint after `normalisePhone()` had
stripped the 977, so the sender prepended the default 91. The country now comes from the
stored number, and failing that from the desk the agent sits at.

---

## 4. Switching it on (the order matters)

1. `php tests/apply-sql.php database/upgrade-2026-09-counter-desks.sql` (idempotent, safe live).
2. Admin → **Counters & collection**: check NPJ's rate, phone, and that Rupaidiha is still IN.
3. Admin → **Staff & Approvals**: create the Nepalgunj login(s) with role `counter` and give
   them the **NPJ** desk. *Until a desk is assigned, a sale carries no place and no NPR.*
4. Admin → **Routes**: add the Nepal-side stops (Nepalgunj Bus Park / Dhamboji / Kohalpur) to
   the routes that should sell from them — today every route stops at Rupaidiha, the Indian
   side. **Do not re-run** `upgrade-2026-08-sharing-stops.sql`: it deletes and rebuilds stops.
5. Optional: switch **Desk isolation** on once the Nepalgunj staff are real people.
6. Optional: `counter_shift_on` is already 1 on live but no drawer has ever been opened
   (`counter_shifts` = 0 rows). A Nepal desk is the first place it will earn its keep.

---

## 5. Not done yet, in the order I would do it

| # | what | why it matters | size |
|---|---|---|---|
| 1 | **The chalani PNG** still prints `Rs` in its summary (the PDF is done); `challanpng.php` likewise | the PNG is what goes out on WhatsApp | S |
| 2 | **NPR on the rest of admin** — booking-view, payments, accounting, analytics show only the rupee; analytics/accounting still divide by a hardcoded 1.6 instead of the setting | the owner will look for the NPR where the money is | M |
| 3 | **Live seat push on** (`seat_events_on`) + a hold on the admin sale screens | two desks are 8–15 s out of step, and the admin screens hold nothing while a clerk types — a lost seat at the last press | M |
| 4 | **Photo at the counter** — `PassengerDocs::attach()` has exactly one caller (booking-view, after the sale) | a window that must capture a face has to finish the sale, find the booking and re-open it | M |
| 5 | **Agent ledger in NPR** — `agent_ledger` has no currency column, so a Nepali agent's commission and cash-in-hand are rupees only | M |
| 6 | **Desk isolation for detail pages and POSTs** (§3.5's known limit) | M |
| 7 | Public site's NPR is a browser hardcode (`CONFIG.nprPerInr = 1.6`) that Settings cannot change | the three pegs (config constant, settings row, JS literal) should be one | S |

---

### 3.13 Three more, found by the adversarial pass over this branch's own work

- The traveller's photo was filed under one global key, so on a counter machine or a family
  phone the previous person's face sat on the next person's card. It is keyed by the NUMBER
  now; a photo added before signing in is a draft that is promoted to the account, and signing
  out drops the draft. Verified in a browser.
- The **admin second factor** had the same country bug as the agent login — fixed the same way.
- The OTP redaction in `message_logs` matched the word "code", while every code this app sends
  is labelled **कोड** — so login codes were being kept in clear. The pattern now covers
  कोड / ओटिपी / OTP.

## 6. Live risks worth the owner's decision

1. **Sign-in is name + mobile, with no OTP** (`api/otp.php action=quick`). This is the owner's
   own rule from 4 Sep and it is what makes the app one tap. The consequence, stated plainly:
   anyone who knows a customer's mobile number can sign in as them and read their whole
   booking history. A middle way exists — one tap for a new number, OTP only when the number
   already has tickets on it. **Owner's call.**
2. **Payment screenshots are served publicly** from `/uploads/<year>/<month>/`. The nginx deny
   list covers `challan`, `chalani`, `passengers`, `agents-kyc`, `company`, `wa-inbound` — not
   this tree. The file name carries 16 random hex characters, so it cannot be guessed; but it
   is an unauthenticated URL holding a customer's payment proof. Moving new uploads to
   `/uploads/payments/` and adding the deny rule is ~20 lines plus a one-time file move.
3. **WhatsApp delivery is working** (83 sent / 25 failed in the last 7 days on live; the
   failures are mostly code 131026 — the number is not on WhatsApp). The ticket path is
   automatic already: `whatsapp_driver=cloud_api`, `whatsapp_notify_customer=1`,
   `whatsapp_send_pdf=1`, template `shg_ticket_confirmed_v3`. Nothing is typed by hand.
4. **`Settings::setMany()` silently ignores a key whose row is missing**, so a switch whose
   migration never ran cannot be turned on from the panel — it looks like the panel is broken.
   Worth a pass over which 2026-09 migrations are actually applied on live.

---

## 7. Files

**New:** `database/upgrade-2026-09-counter-desks.sql`, `includes/counterdesk.php`,
`admin/counters.php`, `assets/js/21-me.js`, `tests/counter-desk-test.php` (50 checks).

**Changed:** `includes/booking.php` (desk + tender stamp), `includes/bootstrap.php`,
`includes/auth.php` (desk scope, agent OTP country), `includes/countershift.php` (desk,
currency, notes), `includes/ticket.php` (NPR on PNG + PDF, layout stamps),
`admin/quick-ticket.php`, `admin/shift.php`, `admin/staff.php`, `admin/bookings.php`,
`admin/passengers.php`, `admin/payments.php`, `admin/accounting.php`, `admin/export.php`,
`admin/offers.php`, `admin/_guard.php`, `admin/manifest.php`, `includes/chalanipdf.php`,
`index.php`, `assets/js/08-signin.js`, `assets/js/14-counter.js`, `app.template.html`,
`sw.js` (asset stamp `20260926h`, `sw-v170`), `tests/counter-shift-test.php`.
