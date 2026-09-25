# Handoff — 26 September 2026

For the next session. The owner went to sleep asking that the work be
finished, deployed, checked as a customer and as an admin, and reported.
This is that report, plus what is still open.

**Live right now:** `1ae5cb6`, asset stamp `20260926d`, `sw-v166`.
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
