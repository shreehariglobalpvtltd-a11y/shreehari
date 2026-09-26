# Next prompts — Fable 5.1, continuing the 26 Sep counter upgrade

Paste **Block 0 first** in a new session, then **one** numbered prompt. One prompt
per session keeps each change small enough to test and deploy on its own.

Everything below builds on what went live on 26 Sep: desks are places
(`counter_locations`), a sale freezes its desk and its NPR, `admin/counters.php`
is the per-desk book, Nepalgunj sells the return run in cash, and the ticket
carries the desk's name, number and the minute it was cut.
Full context: `docs/UPGRADE-2026-09-26-nepal-counter.md`.

---

## Block 0 — the house rules (paste first, every time)

```
This is the LIVE site (nginx + PHP 8.3 + MySQL `shari`). Read CLAUDE.md first.
Another session edits this same working tree: NEVER `git add -A` — stage files by
name and check `git diff --cached --stat` before committing.
Test on the VPS: `git push vps <branch>:wip`, `ssh shari-vps /root/shg-test-refresh.sh wip`,
then `php tests/run-all.php --http` with a dev server on :8899 (102 pass / 3 fail is the
known-good baseline; those 3 fail on live's commit too).
Deploy only with my yes: `CONFIRM=yes bash deploy/go-live.sh nepal-counter`, then apply the
migration and `curl` the home + admin pages. New switches ship OFF. UI change = bump the
asset stamp with `node deploy/bump-asset-ver.js <old> <new> <sw-vNNN> "<note>"`.
```

---

## 1 · NPR admin bharika sabai screen ma

```
A Nepal desk's sale already stores fx_currency/fx_rate/fx_total on the booking and
local_currency/local_amount on the payment, but only admin/counters.php and the ticket read
them back. Show the NPR beside the rupee on admin/booking-view.php, admin/payments.php,
admin/accounting.php and admin/index.php, and replace the hardcoded NPR_PER_INR divisor in
accounting.php and analytics.php with Settings::getFloat('npr_per_inr'). Never convert a
stored figure — print the NPR that was frozen on that row, or nothing.
```

## 2 · Duita desk ek-arkako seat real time ma dekhun

```
seat_events_on ships 0, so two desks are 8–15 s out of step, and admin/seatmap.php,
admin/new-booking.php and quick-ticket take NO seat hold while the clerk types — a seat can
vanish at the last press. Switch the live push on (check the nginx location for
/api/seat-events.php has fastcgi_buffering off first), take a short hold from those three
screens under the clerk's own token, and add a counter_seat_hold_minutes setting (6–8) so a
desk hold is shorter than a customer's 30. Prove it with two parallel sessions.
```

## 3 · Counter mai photo khichne, bechda nai

```
PassengerDocs::attach() has exactly one caller — admin/booking-view.php, AFTER the sale — so
a window that must capture a face has to finish, find the booking and reopen it. Add an
optional camera capture to the QuickBot result card and the counter-mode ticket screen that
POSTs to a small endpoint wrapping PassengerDocs::attach() with the primary passenger id the
sale just returned. Reuse the existing validation and private storage; no new upload path.
Keep it one tap and skippable — a queue at the window must never wait on it.
```

## 4 · Nepali agent ko hisab NPR ma

```
agent_ledger has no currency column, so a Nepali agent's commission, cash-in-hand and
statement are rupees only even when they collected NPR. Add local_currency/local_amount/
fx_rate to agent_ledger with the same guarded ADD COLUMN shape as
database/upgrade-2026-09-counter-desks.sql, fill them in AgentWallet::accrue() from the
booking's frozen fx_*, and show the NPR line on admin/agent.php, agent-sales.php and the
statement. INR stays the accounting currency — the NPR is printed beside it, never instead.
```

## 5 · Desk isolation adhuro chha — pura garne

```
counter_desk_isolation (OFF) pins a counter login to its own desk on three LIST queries only:
admin/bookings.php, passengers.php, payments.php. admin/booking-view.php, customer-view.php,
customers.php and every POST action ignore it, so another desk's ticket is still one typed URL
away. Apply Auth::deskScopeCode() to those detail pages and to the edit/cancel/refund POSTs,
leave the manifest, seat map and trips board shared (the bus is shared), and add a
counter-role jar to tests/role-gates-test.php — no test signs in as that role today.
```

## 6 · Chalani PNG ra challan ma ni sahi paisa

```
The chalani PDF now prints a per-counter block and a Nepal desk's NPR, but the PNG variant
(includes/chalanipng.php) and includes/challanpng.php still hardcode 'Rs ' in their summary
lines, and the PNG is what actually goes out on WhatsApp. Give them the same per-counter
split and the same currency rule as includes/chalanipdf.php, reading totals['byCounter']
which admin/manifest.php already builds. Compare the PNG and the PDF side by side for one
trip that mixes a Gujarat desk and Nepalgunj before calling it done.
```

## 7 · Website ma NPR sahi rate ma

```
The public site prints "≈ NPR x" from CONFIG.nprPerInr = 1.6, a literal in
assets/js/02-config.js that Admin → Settings cannot change, while the desk and api/quote.php
read the npr_per_inr settings row. Publish the setting through SHG_BOOT (it is already
is_public) and have 02-config.js read it with the same live(k, d) fallback pattern the file
uses for fare_to_nepal. Then check the results cards, the checkout total and the eSewa panel
all move together when the rate is edited.
```

## 8 · Counter chhito — kam click, aafai bhariyos

```
Make the window faster without changing what it sells. A desk that sells one run already
opens on it; now remember per desk (not per browser) the last route, boarding point and party
size, and offer them as one-tap chips on quick-ticket.php. Show the passenger's last ticket
inline when the mobile matches. Measure before and after: count the taps from empty screen to
a confirmed ticket for a repeat passenger, and put both numbers in the commit message.
```

## 9 · Customer aafno bibaran aafai milaos

```
#/my shows a greeting, the loyalty card and the booking list — a customer cannot correct
their own name, e-mail or country, and admin/customers.php hardcodes country_code '91' when
it creates an account from a booking number. Add a small account card at the top of #/my
(name, e-mail, country flag, the offline photo already there) posting to a new
api/profile.php that updates only those columns for the signed-in user, and read the country
from the newest booking's contact_country_code instead of assuming India.
```

## 10 · WhatsApp Nepali ma, Nepal ko customer lai

```
whatsapp_template_lang is one global row, so a Nepali customer gets the English approved
template even when the ticket was cut at Nepalgunj. Pick the template language per booking
from contact_country_code (and the desk's country), submit the Nepali variant of
shg_ticket_confirmed_v3 to Meta, and fall back to the English one until it is approved.
While you are there: check whatsapp/TEMPLATES.md — four templates named in notify.php are
still recorded as awaiting review, and each costs a wasted Graph round trip.
```

---

## Not code — mine, and quick

- **Nepal phone + registration number.** Put them in Admin → Settings (`nepal_phone`,
  `nepal_reg`) and on the desk itself in Counters & collection. Until then a Nepalgunj
  ticket honestly prints the India office number.
- **The Nepalgunj login.** Staff & Approvals → role `counter` → desk **NPJ**. A sale by a
  clerk with no desk carries no place and no NPR.
- **Two live risks left open on purpose** (docs §6): payment screenshots sit on an
  unauthenticated URL, and `api/payment.php` accepts proof against any PNR.
