# AI Employee — Phase A audit (state, gaps, plan B–I)

24 Sep 2026. Read-only audit of the LIVE system against the owner's
"Advanced AI Employee & WhatsApp Booking" master prompt. Nothing in this
document was changed on LIVE.

## 0. How this was produced (and what is NOT verified)

| Step | Run | Result |
|---|---|---|
| First audit workflow | `wf_ff24d06f-ba3` (task `wybr3c0rd`, 23 Sep 20:30 IST) | Hit the account session limit: 3 of 8 area reads finished (homepage, identity, voice); all 3 verifiers and the plan failed. No findings were produced by it beyond those 3 raw reads. |
| Completion workflow | `wf_84cf0d04-890` (23–24 Sep) | All 8 areas re-read from a clean export of `8b6b82e` (= live `5cd9ad5` + seat labels). Adversarial verifiers finished for **homepage, identity, voice**. The verifiers for auth, staffops, correction, admin_delivery, tests and the plan synthesis **failed on the session limit again**. |
| Hand verification | this session, 24 Sep 09:00–09:45 IST | The critical/high claims of the 5 unverified areas were checked by hand against LIVE `de0af3a` (code over ssh, read-only SQL, HTTP HEAD/GET). |

Markers used below:

- **V** — confirmed by an adversarial verifier that tried to refute it.
- **H** — checked by hand on LIVE `de0af3a` on 24 Sep (file:line or query given).
- **R** — reported by one reader only; not re-verified. Treat as a lead.

LIVE moved during the audit: the overnight "premium shell" and "counter
location" deploys (`5cd9ad5` → `de0af3a`, 24 Sep 00:34 and 01:49 IST) also
shipped the two-floor seat labels and fixed the 11 WhatsApp-card i18n keys.
Findings below are restated against `de0af3a` where that changed them.

## 1. Current system state (LIVE `de0af3a`, 24 Sep 09:00 IST)

| Area | State |
|---|---|
| Deploy | `vps main` = live worktree = `de0af3a`, clean; sw `shg-v158`, assets `20260924c`. Backups: `/root/backups/pre-premium-*-20260924-0034`, `pre-counterloc-*-20260924-0149`. |
| WhatsApp switches | `wa_agent_on=1`, `wa_agent_sell=0`, `wa_local_first=1`, `allow_cod=1` (H). `wa_booking_on` has **no row** → defaults ON (H, `includes/wabooking.php:53`). |
| Who sells on WhatsApp today | The local engine `WaBooking` runs **before** FAQ and AI for every sender (`includes/wabot.php:123-133`, H) and confirms pay-on-boarding tickets on "ho". `wa_agent_sell=0` only gates the AI's sell tools, not this engine (H). |
| Staff on WhatsApp | `AiTools::whoIs()` = phone match only, and requires `must_change_pw=0` (`aitools.php:117-127`, H). Live: 29 active agents, **all 29** still on a forced password change → treated as customers; the one superadmin has **no phone** → nobody is "office"; the one counter login is "staff" (H, SQL). |
| Seat labels | Two-floor grid live (A1–F6 / A7–F12). Two follow-up defects found (§3, SEAT-1/2) and fixed on the branch. |
| Delivery | Meta Cloud API, template `shg_ticket_confirmed_v3`. SMS fallback **dead**: 12 of 12 SMS since 18 Sep failed, last success 17 Sep 19:35 (H, `message_logs`). |
| Backups | MariaDB `log_bin=OFF`, `backup_offsite_on=0` (H) — a bad day still loses everything since the last nightly dump. |
| Cron | 14 jobs in root's crontab (H). `data-audit.php`, `eta-alerts.php`, `backup-offsite.php` are **not** there, so the nightly seat/money/wallet integrity check never runs on live (H). |

## 2. Completed functionality — reuse, do not rebuild

- **Booking engine** — every channel ends in `BookingService::create` (`includes/booking.php`); seat-level DB firewall `UNIQUE uq_seat_per_schedule` + real multi-process race test (`tests/double-booking-race-test.php`).
- **Customer entries** — seat-map search card (`#search-anchor`, `initSimpleSearch`), customer QuickBot one-tap (`initQuickTicket`, `api/quick-ticket.php` customer_plan/customer_sell/undo, `quick_ticket_customer_on=1`), booking-mode switch `initBookMode()` (`05-router.js`), WhatsApp request sheet (`#waSheet`, `officeNum()`, `composeBooking()`, `waUrl()`).
- **Staff desks** — admin QuickBot desk (`admin/quick-ticket.php`), counter mode in the SPA (`14-counter.js`), agent portal (wallet, ledger, statement, register, passengers), counter shift/cash drawer (`includes/countershift.php`, switched off), counter locations (`admin_profiles.counter_code`, `Settings::counterLocations()`, printed on tickets — live, no desk assigned yet).
- **WhatsApp stack** — signed webhook (X-Hub-Signature-256, fail-closed, `whatsapp/webhook.php:74-86`), wamid de-dup 48 h, local-first `WaBooking` (TicketBot parse/suggest + QuickTicket), `WaFaq` answers on the VPS, AI agent (Gemini ladder, bounded tool loop `aiturn.php`, per-call audit `ai_agent_calls`), knowledge base (`AiKb`, `ai_kb_on`), seat picture, office report chart, voice transcription (`includes/wavoice.php`, `wa_voice_on=1`, **never used by a real note yet** — V).
- **Corrections (desk)** — `admin/booking-view.php` edit passenger/contact/boarding/drop, seat transfer (`Seats::transferSeat`), date change and missed bus (`BookingService::rebookLeg` / `rebookMissedLeg`: PNR, fare, payment and commission kept), ticket reissue (`Ticket::reissue`, QR re-mint).
- **Corrections (WhatsApp)** — `quote_ticket_fix` → `fix_ticket` (staged, fingerprinted, consumed once), `WaBooking` 15-minute name fix, AI `rename_passenger`.
- **Money** — commission exactly once (`AgentWallet::accrue`, `UNIQUE uq_ledger_booking_type`), offers (`Fare::runningOffers`), NPR conversion aid at Nepal desks.
- **Delivery** — template ticket with PNG header, retry cron every 15 min, `admin/wa-pending.php` desk fallback, delivery sentinel `cron/health-delivery.php`.
- **Security** — bcrypt portal login with throttle/lockout/idle timeout/forced change, CSRF, role map `Auth::ROLE_PERMISSIONS`, OTP engine (CSPRNG, hashed, one-time, rate-limited).
- **Tests** — `tests/run-all.php` (CORE + HTTP + NODE, refuses a non-test DB).

## 3. Verified gaps

Severity is the reader's, corrected by the verifier where one ran.

### Seat labels (from the pre-deploy review of the seatmap commits)

| id | gap | sev | mark | status |
|---|---|---|---|---|
| SEAT-1 | `admin/quick-ticket.php` kept its own `seatLabel()` with the old grid: the counter desk said "UA4" while the ticket in the same card said "A10" | major | V (2/2) + H | **fixed on branch** `3e7356b` |
| SEAT-2 | `Ticket::pdfPath()` computed `$stale` but never redrew an existing PDF | major | V (2/2) + H | **fixed on branch** `3e7356b` |
| SEAT-3 | Layout stamps read in **IST** (bootstrap sets `Asia/Kolkata`), not UTC as the code note said: `2026-09-23 20:10` left SHG-2026-00427/-00428 (drawn 23:43 IST, old labels) un-redrawable | major | H | **fixed on branch** `decf7d3` |
| SEAT-4 | Private cabins shown as a sharing bed in the app when loaded from the server (`bookingFromServer` has no mode) | minor, pre-existing | V | open |
| SEAT-5 | Stored ids (U4) still printed by: `pay-image.php`, `wabooking.php:313` post-sale reply, `watemplates.php` booking_confirmation, AI tool payloads, `offline.html`, agent paper register; staff search cannot find a printed label | minor, pre-existing | V | open (plan C) |
| SEAT-6 | Floor range pill / dark 2F heading below 4.5:1; dark-mode emergency/held/women colours differ from legend | minor | V | open |

### Homepage (req 3) — all V

| id | gap | sev | status |
|---|---|---|---|
| homepage-1 | 17 WhatsApp card/sheet i18n keys undefined → raw key names on screen | high | 11 markup keys fixed live by `47add93`; the 6 JS error keys + `copied` fixed on branch `90523f1` |
| homepage-2 | "Send to the office" shows "✅ Sent!" when the send failed (`api/wa-request.php:81`: `$sent = $r !== false`, but a refused send returns a wa.me string) | high | open |
| homepage-3 | The sheet's wa.me link opens `company_whatsapp` (Mehsana office), not the bot line; other WhatsApp doors use up to four different numbers | high (partial) | **owner decision** |
| homepage-4 | No three-card row near the top | medium | **built on branch** `90523f1` |
| homepage-5/6 | Gujarati UI only an English-fallback override; `default_lang` setting read by nothing | medium/low | owner decision |
| homepage-7 | Prefilled WhatsApp text says "Direction: toNepal" | low | open |
| homepage-8 | No test for entry points / number / relay semantics | medium | entry points + number now covered by `tests/home-entry-test.php` |
| + | Country picker defaults to +91; a 98… number passes as Indian | medium | open (plan C) |
| + | Staff QuickBot desk link carries customer name/phone in the URL | low | open |

### Name + mobile accuracy (req 4, 11) — all V

| id | gap | sev |
|---|---|---|
| identity-1 | WhatsApp sale drops the sender's country (`wabot.php:47` keeps 10 digits); a +977 customer with no history gets tickets/SMS sent to +91 + the same digits | **critical** |
| identity-2 | Desk/register sales store Nepal only as a fake ID type "Nepali Citizenship"; `contact_country_code` stays NULL | high |
| identity-3 | India defaults in `auth.php`, `quickticket.php`, `notify.php`, customer Quick Ticket payload | high |
| identity-4 | Paper register has no phone check; NP patterns disagree between files | high |
| identity-5 | `api/book.php` stamps one number's country on another number's digits; checkout copies the account holder into the lead passenger | high |
| identity-6 | One lead name copied onto every seat (web, desk "Name (2)", WhatsApp) — live 157 of 197 multi-seat bookings carry one name | high |
| identity-7 | No server check stops a staff/office number being saved as the customer contact | high |
| identity-8 | WhatsApp summary and web recap never show the mobile | medium |
| identity-9 | Bikram Sambat "gate" dates read as Western dates, confidence 1.0 | medium |
| identity-10 | Voice transcript indistinguishable from typed text | medium |
| identity-11 | Name lookups query non-existent `bookings.full_name` | low |
| + | Name question skipped when `suggest()` pre-filled a (guessed/old) name | high |
| + | `isYes()` accepts "ok date 25", "book 3 seat", "ho tara 3 jana" as a confirmation of the OLD summary | high |
| + | Web checkout turns the +91 default into a stored "trusted" country | high |

### Voice (req 5) — all V

| id | gap | sev |
|---|---|---|
| voice-2 | Spoken name sentences stored verbatim as the passenger name ("मेरो नाम राम बहादुर थापा हो") | **critical** |
| voice-1/3/4 | Voice origin lost; mobile never read back; spoken "ho + change" sells the old summary | high |
| voice-5 | Name fixes (WaBooking + AI rename) write without a confirm step | high |
| voice-6..10 | No finishReason/confidence check; no per-sender transcription budget; staff numbers sell on the customer path; Nepali-only fallback text | medium–low |
| + | Every WaBooking sale loses the sender's country (same root as identity-1) | **critical** |

### Auth, staff, correction, delivery, tests — hand-checked (H) or reported (R)

| id | gap | sev | mark |
|---|---|---|---|
| staffops-1 / deploy-1 | Staff messages are sold on the **customer** path by WaBooking (no seller, no commission, staff's own number as contact); `wa_agent_sell=0` does not govern it; `wa_booking_on` row missing | **critical** | H (`wabot.php:123-133`, `wabooking.php:53`, settings) |
| staffops-2 / auth-1 | Staff identity = phone only, no link/proof/expiry; 29/29 agents excluded by `must_change_pw`; no office number | **critical** | H (`aitools.php:117-127`, SQL) |
| auth-2 | `normalisePhone()` strips both 91 and 977 to the same 10 digits → a +977 98… sender matches an Indian staff 98… | high | H (`helpers.php:320-336`) |
| correction-1 / admin_delivery-1 / idem-3 | Revised tickets are **never re-sent**: `Notify::whatsapp` returns true without sending when the booking's last ticket-purpose row is `sent` (`notify.php:326-333`); resend and ticketChanged both use purpose `ticket` (`:822`, `:946`); the desk still shows "re-sent" | **critical** | H |
| admin_delivery-2 | Delivery state is only sent/failed: `message_logs.status` enum has no delivered/read; the webhook maps delivered/read to `sent` (`webhook.php:136`) | high | H |
| admin_delivery-3 | SMS fallback dead since 18 Sep (Twilio account inactive) — no automatic website-ticket fallback when WhatsApp fails | high | H |
| auth-7 | Customer "quick" sign-in logs in as **any** mobile with just a name — or with **no name** when the account already has one (`api/otp.php:59-76`), marks the phone verified, and My Bookings then lists that number's tickets | high | H |
| auth-7b | `api/cancel.php:43` cancels on PNR + typed phone, no OTP | high | H |
| auth-3 | Admin 2FA degrades to password-only when the OTP cannot be minted (`auth.php:247`) — latent, 2FA is off for everyone | high (latent) | H |
| SEC-1 | `https://shreehariglobal.network/admin/_guard.php.bak.20260828-153536` (and `booking-view.php.bak…`, `index.php.bak…`) answer **200** as downloads: admin PHP source public (no credentials in them) | high | H — **fixed on branch** `d7ac32d` |
| SEC-2 | `deploy.sh`, `deploy/bump-asset-ver.js` and `AOA Shree Hari Global.pdf` are publicly downloadable | low–medium | H — owner decision |
| deploy-2 | `log_bin=OFF`, off-site backup off | high | H |
| deploy-3 | Nightly `data-audit`, `eta-alerts`, `backup-offsite` crons missing from crontab | medium | H |
| tests-1 | `meta-cloud-api-test.php` and `gupshup-send-test.php` never registered in `run-all.php` | high | H (grep = 0) |
| auth-4 / staffops-7 / correction-7 | WhatsApp writes audited as `actor_type='system'`, no actor id (`Logger::audit` reads `$_SESSION` only) | high | R |
| staffops-3..6 | WhatsApp staff mode skips `assertMaySell`, has no name+mobile review before `staff_sell`, drops +977, uses its own permission buckets instead of `Auth::can` | high | R |
| correction-2..5 | No REV band on most desk edits; old QR indistinguishable from the current one; no OTP to a new mobile; AI rename writes with no preview (and can rename passenger #1 by mistake) | high | R |
| admin_delivery-4..9 | `cancel_ticket` does not read the actual reply; office reports incomplete (counter, commission across agents); office prompt promises marketing tools that do not exist; memory stored unredacted with no purge; memory split across 4 stores | medium–high | R |
| idem-1/2 | Two parallel "ho" can both sell (no atomic claim); AI quote never consumed | medium | R |
| sec-1 (tests) | ~60 writing test suites have no test-DB guard and `tests/` sits in the live worktree | high | R |

## 4. Phase plan B–I

Rules for every phase: additive schema only; every new behaviour behind a
default-OFF setting; `wa_agent_sell` stays OFF until the owner approves;
stored seat ids / fares / payments never rewritten; reuse `BookingService`,
`QuickTicket`, `AiTools`, `Notify`; new files CRLF; app asset changes bump
the stamp with `deploy/bump-asset-ver.js` (never `sed -i`); a suite
registered in `tests/run-all.php`; shg-test battery green; owner "yes";
backup; `git merge --ff-only`; rollback = `git revert`.

| Phase | Goal | Key work (reusing what exists) | Owner approvals |
|---|---|---|---|
| **B** (done on branch) | Three booking cards + WhatsApp sheet labels | See §5 | deploy yes; which number the green card opens |
| **C** | Name + mobile accuracy (req 4, 11) — the owner's first priority | Keep the sender's E.164 through `WaBot::reply` → `WaBooking::sell` → `QuickTicket` (`contact.country`); store `contact_country_code` on desk/register sales instead of the fake ID type; show name + masked mobile + date + route + seats + fare in every final review; `isYes()` only on a bare yes (punctuation-tolerant), anything longer re-plans; never pre-fill a guessed name without asking; staff/office numbers refused as customer contact; per-seat names ("names pending" if not given); BS date shown beside AD; `WaBooking::sell` passes `expect` like `sellCustomer`. Tests: returning +977 number, "ok date 25", staff number as contact. | ask-or-default rule for unknown country; per-seat names mandatory or "pending" |
| **D** | Ticket correction + delivery (req 10, 12) | Split the duplicate-send guard (first confirmation vs deliberate resend/revision: new purpose `ticket_revised`); REV band + `reissue_count` on every correction; QR `k` tied to `tickets.qr_hash` so an old copy verifies as "superseded"; OTP to the new mobile (SMS or WhatsApp auth template) before a contact change; preview + explicit confirm for rename; `delivered`/`read` states (additive enum/columns) from the status webhook; `wa-request` reports sent only on a real send; website-ticket link offered when WhatsApp fails. | resend cost; OTP channel; old links behaviour; "ticket changed" template to Meta |
| **E** | Staff identity on WhatsApp (req 6) | `wa_links` table (admin_id, E.164, role, status, verified_at, expires_at, revoked_by); link by a one-time code shown in the portal and sent FROM the staff WhatsApp; `whoIs` reads links only (country-aware, no `must_change_pw` hack); unlink / re-verify / admin revoke; `Logger::audit` gets an explicit actor for webhook writes; `WaBooking` hands linked staff to the staff path instead of selling to them as customers. | link validity (e.g. 30 days); who gets office power |
| **F** | Agent + counter mode (req 7, 8) | WhatsApp tools gated by `Auth::can()` (same as portal); `staff_sell` through `assertMaySell` with the linked admin; `bookings.channel` ('whatsapp') + counter location (`counter_code`, already live) stamped on sale; commission via the existing ledger; own register / wallet / statement tools; review card with name + mobile before sale. | counter scope (own sales vs company-wide); payment methods agents may mark received |
| **G** | Admin mode (req 9) | Office number via linking (E); counter report, commission across agents, pending payments, correction queue using existing queries; `cancel_ticket` checks the actual reply like `fix_ticket`; remove marketing promises from the office prompt or wire `WaMarketing`. | today's sales by booking vs travel date; `wa_agent_admin_write` meaning |
| **H** | Voice booking (req 5) | Carry `origin=voice` to `WaBooking`/`AiAgent`; read back name and number digit by digit; refuse to sell from a transcript cut short (finishReason) or without a typed/said yes on the read-back; strip "my name is"; per-sender daily transcription budget; Gujarati only if the owner wants it. | spoken yes allowed?; Devanagari vs Latin names |
| **I** | Memory, privacy, tests, deploy (req 13, 15, 17) | Redact OTP/card/password patterns before storing memory; purge `kv_store` wa_* / `ai_agent_calls` / `message_logs` by retention; one memory store; register the two webhook suites; test-DB guard in every writing suite; move `tests/`, `deploy/`, `deploy.sh` out of the web root; missing crons back; `log_bin` + off-site backup; migration ledger; scripted deploy. | retention days; backup password + Hostinger daily backups |

Security items that should not wait for their phase: quick sign-in without
OTP and cancel-by-PNR+phone (auth-7) — owner must choose between keeping the
4 Sep "no OTP" rule and adding an OTP before My Bookings / cancel.

## 5. Phase B specification (as built)

Branch `phase-b-home-cards-20260924` in the worktree
`…/81d2ff12-…/scratchpad/phaseb`, commit `90523f1` on top of LIVE `de0af3a`.
Tested on shg-test; **not on LIVE**.

- **Markup** (`app.template.html`): `<section class="container entry-cards" id="entryCards">` inserted directly after the hero, before the green "Ticket with WhatsApp" block. One visible `<h2 id="entryCardsTitle" data-i18n="ecTitle">`, three `<button type="button">` cards:
  - `.ec-normal` `data-bkmode="regular"` → `initBookMode()` (existing delegated handler): folds QuickBot, scrolls to `#search-anchor`, flashes the search card.
  - `.ec-quick` `data-bkmode="quick"` → `initBookMode()`: unfolds and scrolls to `#quick-ticket` (a selling staff session already sees the desk door there).
  - `.ec-wa` `data-wa-open="booking"` `aria-controls="waSheet"` → the existing per-element opener (`querySelectorAll('[data-wa-open]')`, bound once at load): sheet in booking mode, focus on the name; "Continue in WhatsApp" builds `wa.me/<officeNum()>` = `Settings::bookingWhatsApp()`.
  - No inline handler, no new JS, no phone number in the markup.
- **CSS** (`views.css`): `#view-home > #entryCards{order:0}` inside the existing `@media (max-width:760px)` home block (cards lead; QuickBot 1, search 2, hero 3). Cards: 3 equal columns from 320 px, icon + label + one line; desktop 3 horizontal cards. Blue `#2E5FA8→#1A3A6A` (6.3:1), orange `#C2410C→#9A3412` (5.2:1), green `#15803D→#0E5C2F` (5.0:1) with white text; navy focus ring (amber in dark); `min-height` on `.ec-grid .ec-card` so `button[type=button]{min-height:44px}` cannot win; hover lift only under `(hover:hover)`; reduced-motion respected.
- **i18n** (`04-i18n.js`): `ecTitle, ecNormal, ecNormalSub, ecQuick, ecQuickSub, ecWa, ecWaSub` in en/hi/ne (+ Gujarati override); the sheet's missing `waReqPhoneError, waReqDateError, waReqPointError, waReqPnrError, waReqFixNote, waReqUnavailable` and `copied`; `waReqFail` now names the real fallback button ("Continue in WhatsApp →", above). The WhatsApp card never implies a confirmed seat ("Request · office confirms"; the sheet keeps "A request does not confirm a seat").
- **Stamps**: `20260924c → 20260924d`, sw `shg-v158 → shg-v159` (`deploy/bump-asset-ver.js`).
- **Tests**: `tests/home-entry-test.php` (57 checks: the three cards and their handlers, handlers bound once, no number/inline JS, phone order, min-height/hover rules, contrast incl. focus ring, every home/sheet label in en/hi/ne, Gujarati card labels, the published number = `Settings::bookingWhatsApp()`).
- **Unchanged**: booking engine, QuickBot, seat locks, WhatsApp bot, `officeNum()`, the green "Ticket with WhatsApp" block, `#heroQtCta`.
- **Known limits** (review, minor): focus stays on the card after the scroll; the scroll is smooth even with reduced motion (inherited from `initBookMode`); tapping Normal remembers seat-map mode on that phone (existing switch behaviour).

## 6. Questions only the owner can answer

1. Deploy "yes" for branch `phase-b-home-cards-20260924` (Phase B + seat-label fixes + removal of the public `.bak` sources).
2. Green card number: keep the Mehsana office (`company_whatsapp`, today) or the bot line +91 87358 81507 so WhatsApp bookings reach the automatic engine? (Set `whatsapp_booking_number`; no code change.)
3. Put the owner's own (unique) WhatsApp number on a superadmin/manager account — or wait for linking (phase E)?
4. 29 agents never changed their temporary password, so WhatsApp treats them as customers — onboard them?
5. Twilio SMS inactive since 18 Sep: renew, replace, or switch SMS off?
6. Quick sign-in without OTP (anyone can open any number's My Bookings): keep, or add OTP before My Bookings / cancel?
7. Should the local engine keep selling on WhatsApp while `wa_agent_sell=0`, and should it sell to staff numbers?
8. Remove `deploy.sh` / `deploy/` from the web root? Is the AOA PDF meant to be public?
9. Re-add the missing crons (data-audit nightly, backup-offsite 02:45, eta-alerts if wanted); turn on `log_bin` / off-site backup / Hostinger daily backups?
10. Gujarati: full UI translation, or keep the English-fallback override?
