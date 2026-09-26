# VIP private sleeper · the fare board · the advance-booking offer
### 26 September 2026 — built and tested, **not deployed**

---

## 1. What changed for a passenger

**The sharing fare now depends on which pickup they board at.** It used to be
one number per direction.

| Journey | Was | Now |
|---|---|---|
| Surat / Kamrej / Ankleshwar / Bharuch / Vadodara / Anand / Nadiad / Emli Bhupal → Rupaidiha | ₹2,000 | **₹2,200** |
| Ahmedabad (S Hari Parking, Nana Chiloda) → Rupaidiha | ₹2,000 | **₹2,000** |
| Rupaidiha → Ahmedabad | ₹1,800 | **₹1,800** |
| Rupaidiha → Surat and every town below Ahmedabad | ₹1,800 | **₹2,200** |

Owner-confirmed on 26 Sep 2026. A VIP private cabin keeps its own price list
(single ₹3,800, double ₹7,600 — both editable).

**An advance-booking offer exists but is switched OFF.** When the office turns
it on, booking at least 24 hours before departure takes 10% off — on a sharing
berth and on a VIP private cabin alike.

**The home page leads with VIP private.** A drawn cabin (inline SVG, no
images) shows the curtain closing, air leaving the AC vent, a phone charging
and three people resting. Both its buttons open the **private** seat map, not
the sharing one. A separate Dashain/Tihar offer card appears only while the
offer is actually running.

**Before payment the checkout prints four lines**: Original fare, Discount %,
Discount amount, Final fare.

---

## 2. Where the office changes it

**Admin → Payments → 💰 Fares & offers** (`admin/pricing.php`). One screen:

- **The board** — an ordered list of rules, `from → to → ₹`. First match wins,
  so exact towns go **above** the `@india` catch-alls. `@india` means the whole
  India side, `@nepal` the Nepal side, `*` anything. "Ahmedabad" is understood
  as *S Hari Parking, Nana Chiloda*.
- **What each pickup pays now** — every bookable pair, worked out by those
  rules, so a change can be read before a passenger meets it.
- **Fallback fares + the VIP cabin rates.**
- **The advance offer** — ON/OFF, the hours, the percentage, a ₹ ceiling, the
  window, which modes it covers, its name, and the sentence the home page
  shows. With a worked example underneath.
- **WhatsApp control** — off by default.

Every save writes an audit row with the old value and the new one.

A single departure's own fare still lives on the **Bus Calendar** (one bus, one
price) and typed coupon codes still live on **Offers & Discounts**. Those are
different objects from "the board".

---

## 3. Changing a fare from WhatsApp

Shipped **OFF** with an empty number list. To use it:

1. Admin → Fares & offers → tick *Allow fare / offer changes by WhatsApp*.
2. Put the numbers in. `9726401507, 9104801507` are the two the owner named.
   The screen then says, per number, whether it really can command — it must
   also be an **active manager or super-admin staff account**.

Then, from one of those numbers:

> **Ahmedabad to Rupaidiha fare 2100 gara**
> → *"S Hari Parking, Nana Chiloda → Rupaidiha: ₹2,000 becomes ₹2,100. Nothing
> is saved yet — reply ho to confirm."*
> **ho**
> → *"Saved. … It applies to the next search and the next sale; tickets already
> sold keep their price."*

Also understood: `advance offer 15% gara`, `advance booking 48 ghanta gara`,
`offer band gara`, `fare settings dekhau`.

**Three gates, all of which must be open** — an office account, the
`wa_rules_control` switch, and that number being on the list. A customer
number fails two of them. The model only reads the sentence: validation, the
preview, the write and the audit row are all plain PHP in
`includes/airules.php`, against a whitelist of nine settings keys. A confirm
flag is not consent — the admin's own next message has to say *ho*, and the
change must be the same one that was previewed.

---

## 4. One coach, one seat list

VIP private is **not** a second booking engine. It is the booking mode that
was already there, on the physical-bed namespace in `includes/seats.php` that
already stops the coach being oversold. Nothing in that file changed.

A private cabin label expands to the beds it occupies; one sharing berth sold
closes the cabin above it, and a cabin sold closes every berth inside it. The
new suite pins the mapping (no bed in two cabins, the cabins cover exactly the
sharing berths, the translation works both ways) alongside the three
`cross-mode-*` suites that already exercise it against the database.

---

## 5. Deploying it

```bash
bash deploy/go-live.sh vip-advance          # prints what it would REMOVE first
php database/upgrade-2026-09-vip-advance.php
```

The migration adds `bookings.advance_discount`, seeds the board and seeds the
offer **off**. `BookingService` probes for that column at run time, so the code
may be deployed before the SQL is run — no ticket fails either way.

Asset stamp `20260926k`, `sw-v173`.

**Running the migration changes prices.** A Surat → Rupaidiha sharing seat goes
from ₹2,000 to ₹2,200 the moment it lands. That is the owner's instruction, but
it is the one thing to say out loud before pressing it. Tickets already sold
keep the price they were sold at.

Rollback: `bash deploy/go-live.sh --rollback`.

---

## 6. What was tested

`php tests/vip-advance-test.php` — **98 passed, 0 failed**. The board from every
spelling of a pickup, first-match-wins ordering, a malformed rule being dropped
rather than taking the board down, the physical-bed mapping, the 24-hour
boundary measured against the real departure clock, 10% on sharing and on VIP,
the four display lines, every editable field, all three WhatsApp gates, a
preview writing nothing, a mismatched confirmation writing nothing, and the
audit row.

Full battery on `/root/shg-test`: the suites that were green before are green.
Two suites had to be corrected rather than fixed — `quick-ticket-test` and
`agent-login-bulk-cancel-test` were re-asserting the directional fare the owner
replaced, and now ask the board for the journey they themselves made.

Still red on this branch **and identically red on the base commit**, so not
caused by this work: `login-name-mobile-test`, `home-entry-test`,
`fares-settings-test`, `export-filters-test`. The last two render admin pages
over HTTP and need the dev server on `:8899`.

---

## 7. Known gaps

1. `bookings.advance_discount` is stored but not itemised on the ticket, the
   invoice, `admin/booking-view.php` or the passenger's My Bookings — the total
   is right, the line is not shown.
2. Gujarati carries only the short new labels; the long VIP sentences fall back
   to English.
3. The pricing screen has no "what would this journey on this date cost" box.
4. The advance offer is company-wide. A discount limited to one route or one
   departure still needs a `coupons` row on Offers & Discounts.
5. A WhatsApp fare change does not notify the other authorised admin, and
   `rules_change` has no attempt rate limit of its own.
6. `admin/quick-ticket.php` and the agent panel show the discounted total but
   do not print the offer as its own line.
7. `advance_offer_max_inr` is a ceiling per **booking**, not per passenger.
