# Handoff — 26 September 2026

For the next session. The owner went to sleep asking that the work be
finished, deployed, checked as a customer and as an admin, and reported.
This is that report, plus what is still open.

**Live right now:** `7adb700`, asset stamp `20260926e`, `sw-v167`.
**Rollback:** `bash deploy/go-live.sh --rollback` (backups in `/root/backups/`).

---

## 1. The live check, run after the last deploy

| | |
|---|---|
| Customer — `/`, `#/book`, `sw.js`, manifest, offline page | **200** |
| The new film files | **200** |
| Admin — `/admin/login.php` 200, `/admin/index.php` **302** (correctly redirects a stranger to the login) | **ok** |
| Agent — `/agent-signup.php` | **200** |
| Private uploads — challan, chalani, passengers, agents-kyc, wa-inbound, company | **all 404** |
| nginx · php-fpm · mariadb · shg-brain | all active |
| Disk 8.0 GB / 96 GB · RAM 5.1 GB free | healthy |

The films were played on the **real site**, on a phone viewport: Live GPS
ran its four beats, then auto-advanced to Charging, then to Safe Travel.

---

## 2. What went live today

**A security hole that was open.** `/uploads/challan/<date>/<bus>.png` —
every passenger's name and mobile — answered **HTTP 200** to anyone who
guessed the URL. The `.htaccess` guards that shipped protect Apache; this
server is nginx, and its config had deny rules for only two of the six
private folders. Four were added and all six now 404. **If nginx is ever
rebuilt, check that block first.**

**The 25 Sep feel pass** — still journey scene, real India–Nepal map,
middle-out seat picking, opening and ticket sounds, 28 messages that were
English at the call site now in all three languages.

**The local brain** — llama.cpp + Gemma-3-4B on the VPS, loopback only.
`ai_local_on = 1`, `ai_local_first = 0`.

**The four facility films** — each chip on the phone brand band plays an
eighteen-second film with narration, kinetic captions and a directed
camera. Nothing animates until a chip is tapped; the panel is removed on
close (verified: zero animations, zero panels, speech stopped).

---

## 3. Two things the owner should be told when they wake

**The narration is not ElevenLabs.** They asked for it. ElevenLabs is a
paid service with an API key and there is none here, so the films use the
voice the handset already has — free, offline, speaks Nepali and Hindi on
most Androids, and noticeably plainer. Every spoken line is also set large
on screen, so a phone with no Nepali voice still gets the whole film. If
they want the real thing: record or buy the audio, drop the files at
`/assets/audio/story-<key>-<lang>.mp3`, and `VOICE.play()` picks them up
with no other change.

**The local brain cannot lead.** Four models were measured through the
real assistant (`tests/brain-bench.php`): Qwen3-4B, Qwen3-1.7B,
Granite-4.2-3B, Gemma-3-4B. All four answer factual questions from memory
instead of calling the tool — Gemma invented a fare of ₹2800 when it is
₹2000. For a ticket seller that is the worst failure there is, so Gemini
leads and the local brain is the free offline fallback. What would change
this is more CPU cores, not a fifth model of the same size. Full numbers
in `docs/LOCAL-BRAIN-2026-09-26.md`.

---

## 4. Still open, in the order the owner asked for them

1. **The local brain returns null sometimes** — a legitimate question can
   come back empty after ~23s. Harmless today (Gemini leads), but it is
   the reason local-first is off.
2. **Tickets** — QR highlight, payment terms shown before rather than
   after, 🇮🇳/🇳🇵 flags, Nepali throughout. *Started, not finished.*
3. **Contact list** of numbers and buses.
4. **Marketing system** (`includes/promocard.php` exists but is unwired).
5. **Admin ⇄ agent ⇄ customer** connection, with the focus on agent and
   admin.
6. **Homepage** — the remaining always-running animations.
7. **Chatbot** — voice in, predicting what the customer will type, fixing
   wrong names and numbers.

---

## 5. Traps this session walked into, so the next one does not

- **`vps/main` was not what was live.** The site was on a GitHub branch
  with its own lineage and 13 commits the bare repo had never seen, one of
  them the security fix above. Always run
  `ssh shari-vps 'cd /var/www/shreehariglobal.in/public_html && git log --oneline -1'`
  before deciding what a deploy will do. `deploy/go-live.sh` now prints
  which commits a deploy would REMOVE, and asks.
- **Two Claude sessions in one folder.** Another session was building a
  ticket-payment engine here at the same time. `git commit` without a
  pathspec commits whatever is staged, so some of their files landed
  inside commits here and the release had to be rebuilt file by file.
  **One session per folder.** Their work is untouched on
  `upgrade/ticket-link-payment-engine-20260926`; see
  `docs/CLEANUP-LIST-2026-09-26.md` §4 before deleting anything.
- **A short benchmark lies.** A 1.7B model looked better than everything
  else against a 150-token prompt and fell apart on the real 2 600-token
  one. Benchmark through `AiAgent::handleWeb()`, never a toy prompt.
- **Colour typos in hand-written SVG do not throw.** `#081densité` and
  `#2B4membrane` both shipped; a browser drops the paint and carries on.
  `tests/feature-story-check.js` now catches them.
- **`set -e` plus a `grep` that finds nothing** killed the deploy script
  at step 5 of 7 on its first real run.
- **Three test suites are red without `--http`** and always have been —
  forced-password-change, fares-settings, export-filters. Confirmed
  identical on an untouched checkout. Not regressions.


---

## 6. Last pass before the session ended

The owner asked for plain Nepali and for three pictures to show the
benefit rather than the mechanism. Both done and live.

**The words.** Every line now says what the passenger gets, in the
shortest plain Nepali that carries it — "बस कहाँ पुग्यो? अब सोध्नु पर्दैन।"
rather than an explanation of what a GPS does. English loan words are
gone wherever Nepali has its own (ओढ्ने, मल्हमपट्टी, सितल); GPS, USB and
AC stay, because that is what people call them.

The four cards now read:

- ❄️ बाहिर घाम, भित्र सितल
- 📍 बस कहाँ छ, फोनमै हेर्नुहोस्
- ⚡ रातभरि फोनको चार्ज
- 🛡️ बाटोमा तपाईं एक्लै हुनुहुन्न

**The pictures.** The charging wire now fills end to end like a level
rising inside the cable, instead of a dash sliding along it. The berth
has a real vent with air blowing out of it, so the picture answers
where the cool is coming from. The first-aid box holds a bottle and a
tablet strip rather than two coloured lozenges.

Verified on the live site, on a phone viewport: all four films play, the
reel auto-advances, and after close there are zero panels and zero
animations left behind.


---

## 7. The brand film, v3 — one film instead of four

A written brief arrived after the owner slept: turn the four separate
eighteen-second films into one premium, realistic brand film — Earth in
space, a zoom to India and Nepal, the branded route with the coach moving,
the four facilities as scenes, and an end card with the logo, the coach and
the CTA "आजै आफ्नो यात्रा बुक गर्नुहोस्". Red, blue, white. Voice, music,
must work without sound, lightweight, reduced-motion.

Done and live. The full before/after is in
`docs/FEATURE-FILM-UPGRADE-2026-09-26.md`. The short version:

- **One film, eight acts, ~18 s**, any chip opens it. The stage carries
  `data-act="0..7"` and every visual change is a CSS rule keyed on it.
- **Real geography**: the same projected India/Nepal outlines and the
  Surat → Rupaidiha road the splash uses, with the coach travelling the
  road by SMIL `animateMotion`.
- **New scenes**: a passenger charging a phone in their seat; a highway in
  motion with a safety shield that ticks; an end card with logo, coach,
  both flags and a real CTA button that closes the film and clicks the nav
  "Book" link (`[data-scroll="search-anchor"]`).
- **Sound**: handset voice-over per act (Nepali first), a synthesised music
  bed (`SHGFeel.bed`) and a whoosh per scene, all behind the Sound switch.
  Every line is also on screen, so the film is whole without audio.
- **Still true**: nothing runs until a chip is tapped, `close()` removes the
  panel — verified zero panels, zero animations, speech stopped.

Two bugs found only by looking, now in memory so they are not repeated: a
caption class named `brand` collided with the navbar's `.brand{display:flex}`
(renamed `fs-brand`); and a CSS `transform` on an SVG group **replaces** its
`transform="translate(…)"` attribute — the safety shield sat in the corner
until the translate was moved to an outer group.

Live: asset stamp `20260926e`, `sw-v167`. Rollback unchanged:
`bash deploy/go-live.sh --rollback`.

---

## 8. The office pass — WhatsApp, help desk, quick book, the blessing

Owner (26 Sep, afternoon): *"WhatsApp API bata matra jaos, redirect sano side
ma option · help desk chalauna sajilo, msg optimise · ticketing kam click ma
automated · admin ma hi lekhera guide · error bhayo bhane 9104801507 ma data
send, yo name lai yo ticket send gardinu bhanera link ra number deu · boot ma
Bishnu Bhagwan ko naam mantra · saboi report admin le controllable."*

What shipped (branch `wip-office`, stamp `20260926f`, `sw-v168`):

- **WhatsApp request sheet** — the API send (`api/wa-request.php`, the
  office's own sender) is the one big green button; the passenger's wa.me
  chat is a small underlined line under it that only grows (dashed box)
  when the API send has failed. Success swaps the form for a ✅ card
  ("कार्यालयमा पठाइयो", office-chat link, ठीक छ). Reopening resets it.
- **Help desk on the home page** (`#helpDesk`, above the office cards) —
  six pre-written questions (ticket where, bus time, refund, luggage,
  change name/date, other). A tap opens the sheet in help/correction mode
  with the note already written in the passenger's language; a returning
  passenger only presses Send. WhatsApp / call buttons beside them.
- **⚡ Quick book** on every results card (`.bc-quick`) — picks the best
  seats the way the count picker does (middle of the lower deck first),
  shows a 3-second countdown toast, then continues to checkout by itself;
  any tap in the map keeps the passenger there. Verified on the test copy:
  results → quick → C1 picked → `#/checkout` with the leg filled.
- **Ticket not delivered → the office** — `Notify::deliveryFallback()`.
  Fires when the sender refuses a ticket, when Meta/Twilio/Gupshup report
  it failed, and when `cron/whatsapp-retry.php` gives up. The office
  WhatsApp (`admin_whatsapp` = 919104801507) and admin e-mail get: PNR,
  name, number, route/date, the ticket picture link, a one-tap
  **wa.me forward link** with the ticket text pre-written, the admin link
  and the reason. One per booking per `wa_delivery_fallback_hours` (24).
  The office's own rows (`delivery_fallback`, `admin_note`) are excluded
  from the ticket badge (`shg_wa_last`), the pending list and the retry
  cron so they can never mask a passenger's failed ticket.
  `tests/delivery-fallback-test.php` (14).
- **Staff "hi" → menu** — `WaBot::isStaffGreeting()` / `staffMenu()`:
  a staff number writing hi / namaste / menu / help / ? gets what the
  number does for them and links into Admin, Quick Ticket, bookings and the
  messages log. Assistant lines are listed only while the assistant is on.
  `tests/wa-staff-menu-test.php` (34).
- **The blessing** — on the first touch the app now plays a temple bell +
  conch swell (`VOICES.blessing`) and speaks "ॐ नमो भगवते वासुदेवाय" with
  the handset's Nepali/Hindi voice, once per visit. Switches: 🙏 मन्त्र in
  the app menu (per device) and `app_mantra_on` in Admin → Settings → Site
  (for everyone). The splash carries the mantra line in shimmering gold and
  a breathing golden aura behind the logo.
- **Reports on one panel** — `database/upgrade-2026-09-26-office-fallback-reports.sql`
  moves every digest / office-alert switch (daily digest, brain digest, WA
  chart, booking alerts, low-seat, pending-approval, the office number and
  e-mail) into the settings group **Reports**, plus the new switches.
  Apply on live by hand after go-live: `mysql shari < database/upgrade-2026-09-26-office-fallback-reports.sql`.

**Meta API cost, plainly.** The local model cannot send a WhatsApp message;
only the Meta Cloud API (or Gupshup) can. A reply inside a window the
customer opened (they messaged us within 24 h) is free. A business-initiated
utility template in India costs about ₹0.11–0.15 per message. The free path
is already on the ticket page: "🎫 WhatsApp मा टिकट पाउनुहोस्" makes the
customer message us first, and the bot answers with the ticket for free.

**Trap found today** (in memory as `mixed-eol-patch-trap`): `helpers.php`
is CRLF except its heredocs, which are LF on purpose; a patch tool that
normalised the file flipped them and only `uploads-private-test` noticed.
The scratchpad `patch.js` now keeps each line's own ending.
