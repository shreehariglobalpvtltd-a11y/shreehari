# Meta-first WhatsApp routing + the local (no-AI) brain — 9 Oct 2026

Owner (9 Oct 2026, voice-typed Nepali): *"AiSensy ko subscription kinyo, templates ready, ticket
WhatsApp bata chalirako cha — yo sab FREE / automatic banau. Sabai kaam jaha badi jaso Meta ko
direct free API use garos; ticket ko bela matra AiSensy, naya number aayepachi tyo. AiSensy ma sasto
UTILITY template matra. AI lai yeti advance banau ki website ko har detail — timetable, naam, number,
ticket, bus live ma kaha cha, seat kati baki — Nepali ma agent/admin lai, customer lai full
assistance, website ma janai naparne, sabai WhatsApp bata automatic, offline/free mero VPS ma."*

Built on the existing PHP + vanilla-JS app. No JS/CSS change (no asset stamp bump), one settings
migration, no new dependency, no AI key needed for anything added here.

## Releases
| Release | What |
|---|---|
| release-20261009-wa1 | Everything below (branch `aiwa-20261009` on release-20261008-pr1) |

Migration applied by hand on live: `database/upgrade-2026-10-09-meta-first.sql` (6 settings rows, no table change).

## 1. What was already there (not rebuilt)
- Two senders: the **Meta number** +91 87358 81507 (`whatsapp/api.php`, bot + inbound webhook, free
  service replies inside 24 h, templates refused with 131042) and the **AiSensy number** +91 91570 01507
  (`whatsapp/aisensy.php`, approved UTILITY templates to any number, paid per message, no inbound).
- The bot (`includes/wabot.php`): sign-in, STOP, voice, order menu, guided sale (WaBooking), bulk
  (WaBulk), everyday answers (WaFaq: hello / fare / timing / offers / website / office / KB), the
  tool-using assistant (AiAgent, Gemini free tier), PNR reply.
- The "hi" queue (`includes/waqueue.php`) — until today it only ever knew the AiSensy number's window,
  and the AiSensy webhook is a paid add-on the owner never bought, so `wa_windows` had 0 rows on live.

## 2. Meta-first routing — `includes/waroute.php` (WaRoute)
One rule, setting `wa_policy`:

| Policy | Meaning |
|---|---|
| `meta_first` (default now) | 1. the Meta number, **free**, when OUR record says this number wrote to it in the last 23 h 30 min (kv `wa_inbound`, written by `whatsapp/webhook.php`); 2. else the AiSensy UTILITY template; 3. a Meta template only while `wa_meta_templates_on` |
| `aisensy_only` | exactly the behaviour before 9 Oct |
| `meta_only` | never AiSensy (the day Meta billing works) |

Why our own record and not a blind try: Meta ACCEPTS a text to a closed window and fails it a minute
later with 131047 (the 3 Oct lesson). A "window open" here is something the webhook saw.

Where it is wired (every call site falls back to the old ladder on any exception):
- `Notify::whatsapp()` — before the AiSensy ticket block: free on Meta → (templates-on) Meta template →
  old ladder. A Meta-free ticket is recorded in the hi-queue (`WaQueue::recordSent`) and in the journal
  (`reason meta_free`), so `AiSensyAPI::onBookingConfirmed` sends **no template on top** and only queues
  the second number / seller copy.
- `AiSensyAPI::sendAny / sendLoginOtp / sendStaffMessage / tripUpdate / cancellationFor` — Meta free
  first. `ticketTemplate()` is now the one ticket ladder (Meta free → Meta template → AiSensy image
  template → AiSensy text template); `onBookingConfirmed` and `resendTicket` use it.
- `WaQueue::deliver()` — Meta free first for customer / second number / seller copy.
- `whatsapp/webhook.php` — an inbound on the Meta number now flushes that number's waiting tickets at
  once (`WaQueue::onInbound(..., 'meta')`, never writes `wa_windows`); a short "hi"/PNR that got its
  ticket gets no menu on top. A **failed** status for a Meta-sent ticket hands it to the AiSensy template
  once (`WaQueue::onMetaFailed`, via = `ais after <code>`).
- `api/otp.php` — unchanged; the code goes free on Meta through `sendLoginOtp` (masked in `message_logs`).

### Cost guard
`aisensy_utility_only` (default ON): `AiSensyAPI::sendMessage()` refuses a MARKETING template
(category from the WhatsApp Manager's cached template list; names with "offer"/"promo" count as
marketing when unknown). Campaigns pass `allowMarketing = true` — the owner's deliberate spend.

### Admin
- WhatsApp Manager (`admin/wa-manager.php`): new card **Routing — who sends what**: policy, Meta
  connected / open windows / last inbound, free-on-Meta today and 7 days vs AiSensy templates, the
  rupees saved (free × utility rate), the two switches.
- Settings → WhatsApp: `wa_policy` and `wa_staff_lang` are dropdowns; `wa_meta_templates_on`,
  `aisensy_utility_only`, `wa_local_brain_on`, `wa_driver_phone_on` are switches.

### Tests — `tests/wa-route-test.php` (47)
Stubbed Meta transport (`WaRoute::$transport`), no network: window maths, freeFirst, policies,
marketing guard, sendAny / OTP / Notify / idempotency, the queue on a Meta inbound, Meta async failure →
AiSensy once.

## 3. The local brain — `includes/walocal.php` (WaLocal)
Sits in `WaBot::reply()` **before** the sale engine (so "khali seat bholi nepal" is a question, not an
order), steps aside while a sale conversation is open, and claims only what it recognises. Every answer
is read from the live tables, logged as `local:<intent>` in Admin → AI Activity, and joins the
assistant's memory. Switch `wa_local_brain_on`. Staff are answered in `wa_staff_lang` (Nepali) unless
they wrote in another script; customers in the language of their message (romanised Nepali cues count).

| Intent | Words (examples) | Who / what |
|---|---|---|
| help | help, madat, k k garna milcha | the list for that role |
| timetable | timetable, samay talika, sabai time | every pickup + time, arrival next day |
| seats_left | khali seat [bholi / nepal], kati seat baki, kitni seat hai | free berths per direction for the day; staff: today + tomorrow, with sold count; picture when `wa_seat_photo_on` |
| bus_live | bus kaha pugyo, location, track, kata cha | passenger with a ticket (today ± 1): own trip, delay, GPS km/ETA to THEIR pickup or honest "not live", TRACK link; staff: every running trip, next stop + ETA, map link |
| bus_crew | driver, bus number, gadi number, kun bus | staff: today/tomorrow buses + crew; ticketed passenger: their bus, driver number on the travel day (`wa_driver_phone_on`); stranger: the fleet |
| my_ticket_png | ticket pathau, ticket photo, resend ticket | the PNG again (free — they are writing to us); several → tap one |
| pay_how | kasari tirne, payment kaise, upi | their pending booking's QR + link, else UPI / cash / counters |
| refund_rule | cancel garda kati firta, refund policy | the published slabs + what THEIR ticket gets now; a cancel REQUEST is left to a person |
| nepal_office | nepal office, nepalgunj number | Settings::nepalTeam |
| summary (staff) | hisab, aaja kati ticket, bikri | office: tickets/seats/money, bus fill today+tomorrow, payments to verify; agent: own day + commission |
| passengers (staff) | yatri list [bholi], manifest | names, seats, pickup, phone; agents only their own sales |
| pending (office) | pending, baki payment | payments waiting, link to Payments |

The website chat (`api/ai-chat.php`) asks the same brain first with the signed-in identity
(`WaLocal::answerWeb`), so the widget answers these without an AI key; the ticket picture stays
WhatsApp-only.

### Tests — `tests/wa-local-test.php` (50)
Every intent per role, scoping (passenger sees own trip only, agent own passengers only, driver number
withheld when the switch is off), what is NOT claimed (greeting, fare, PNR, complaint, booking, cancel
request, small talk), logging, web, switch.

## 4. Verified
- Local battery (aiwa_test): wa-route 47, wa-local 50, wa-faq 26, wa-order 47, wa-hi-queue 29,
  wa-local-booking 74, ticket-bot 86, whatsapp-retry-policy 12, wa-manager 40, bot-ladder 37, wa-login 72,
  wa-bulk 73, wa-agent 80, trip-reminder 17, wa-manager-tools 91, notify-country 33, placeholder-phone 34,
  wa-ops-manager 75, wa-voice 14, tripstatus 38, eta-alerts 22, quick-ticket 158, customer-book 33 — all 0 failed.
  Pre-existing on the base (unchanged): smart-v2 "layout stamp moved", wa-request-result eval fatal,
  HTTP suites without a server.
- Admin pages rendered through `tests/render-admin.php`: WhatsApp Manager (Routing card), Settings.

## 5. Owner to-dos (nothing here can do these)
- **Recharge the AiSensy wallet** (₹9.39 on 9 Oct → every template fails "Insufficient WCC"). The
  subscription (PRO_MONTHLY) is active; the wallet is separate.
- The AiSensy number still has no inbound webhook (paid add-on) — replies to +91 91570 01507 never reach
  the bot. Tell customers to write to **+91 87358 81507** (the bot number) — every reply there is free.
- When the Meta number gets a working payment method (or the new number is connected and its
  templates approved): Settings → WhatsApp → `wa_meta_templates_on` = ON (templates go on Meta first,
  AiSensy stays the fallback), later `wa_policy` = `meta_only`.
- 6 pickups still lack coordinates (ETA / near-me / maps QR silent for them).

## Rollback
`/root/backups/pre-deploy-release-20261009-wa1-<ts>/ROLLBACK.txt`, or Settings → WhatsApp →
`wa_policy` = `aisensy_only` and `wa_local_brain_on` = OFF (no redeploy needed).
