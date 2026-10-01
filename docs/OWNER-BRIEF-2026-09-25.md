# Owner brief — 25 September 2026

The owner sent one long voice-style message (plus a follow-up about sound)
covering the whole app. This file is the working list: every ask, what it
actually means in this codebase, and where it stands. Nothing here is on the
live site yet — every commit below is local on `main` and **not pushed to
`vps main`**, which is the live worktree.

Status key: **DONE** = committed locally and checked · **NEXT** = planned,
not started · **BLOCKED** = needs something from the owner first.

---

## 1. Look and feel

| # | Ask (owner's words) | What it means here | Status |
|---|---|---|---|
| 1.1 | "yo bus ko animation hataidinus" — the bus animation where the dates are shown | The journey strip on the results / seat views ran six animations at once | **DONE** `15c9a49` |
| 1.2 | "ali ramro image rakhdinus taki smooth chalos" | Snow on the peaks, a tree line, a graded verge — detail that costs one paint | **DONE** `15c9a49` |
| 1.3 | "homepage ta ajhai ekdam smooth chalos … hang nahos" | A phone perf pass already exists (11 Sep, views.css §PERF). The splash's 8 floating emblems and several hero loops are still outside it | **NEXT** |
| 1.4 | "UI pani ekdam advance smooth … mobile ko lagi suitable" | Premium shell shipped 24 Sep. Remaining: the 41 `backdrop-filter` rules, which are the expensive ones on a budget Android | **NEXT** |
| 1.5 | "bhagwan lai pani dedicate garirakhos" | The mantra ribbon and the devotional background setting already exist; the Ganesh artwork in `assets/generated/` is not wired to anything | **NEXT** — needs the owner to say which image and where |

## 2. Maps

| # | Ask | Meaning | Status |
|---|---|---|---|
| 2.1 | "Nepal ra India ko map … clear original map jasto" | The splash outline was 48 hand-placed segments in a box that stretched it. Now projected from real coordinates | **DONE** `15c9a49` |
| 2.2 | "bus ko navigation … rodharu ramro, Nepal jane jasto scene" | The journey scene is the "road to Nepal"; the live navigator (`15-nav.js`, MapLibre) is separate and untouched | **PARTLY DONE** — scene done, navigator **NEXT** |
| 2.3 | "map flow ali sajilo … light load hos" | `16-lazy.js` is 3 173 lines and loads MapLibre + 35 Wikimedia photos | **NEXT** |
| 2.4 | "thau thau ma Nepal ko jhanda, India jhanda highlight" | Flags on the journey milestones | **DONE** `15c9a49` · on the ticket **NEXT** |

## 3. Nepali language

| # | Ask | Meaning | Status |
|---|---|---|---|
| 3.1 | "pura application lai Nepali bhasama" | Nepali has been the default since 13 Sep, and all 815 keys exist in en/hi/ne — but 28 messages were written in English at the call site and no language switch could reach them | **DONE** `bdce88c` |
| 3.2 | ratchet so it does not come back | `tests/i18n-scan.js` — new; exits 1 on any English sentence handed to a human-readable sink | **DONE** `bdce88c` |
| 3.3 | "ticket bookma … Nepali bata guide" | Step-by-step Nepali guidance inside the booking flow | **NEXT** |
| 3.4 | "ticket haru … Nepali bhasabata" | Ticket PNG/PDF already shape Devanagari (HarfBuzz, 24 Sep). Which lines stay English needs a pass | **NEXT** |
| 3.5 | admin / agent panels | These are English on purpose today (`12-admin-panel.js` header). The owner wants Nepali everywhere — this is a decision, not a bug | **BLOCKED** — confirm with owner |

## 4. Seats

| # | Ask | Meaning | Status |
|---|---|---|---|
| 4.1 | "suru suru ma bich bich 50-50 ko seat, tyaspachi balla agadi" | Auto-pick walked rows in DOM order, filling the coach nose-first. Now middle-out with a forward tie-break (6 rows → 3,4,2,5,1,6) | **DONE** `15c9a49` |
| 4.2 | "A1 A2 A3, B1 B2 B3 … L ra agadi ko aaunu bhaena" | The label scheme was already right (A1–F6 lower, A7–F12 upper). Two surfaces still printed the raw `L1`/`U5`: the payment PNG sent to the customer's WhatsApp, and the dormant in-app admin table | **DONE** `5c1c2ca` |
| 4.3 | "second floor ma 7 8 9 10 11 12 … 6 bai 6" | Already the shipped geometry: 6 rows × 6 berths per deck, upper floor columns 7–12 | **ALREADY TRUE** — verified, no change needed |

## 5. Sound

| # | Ask | Meaning | Status |
|---|---|---|---|
| 5.1 | "khulne bela ma" — a sound when the app opens | New `welcome` voice, once per session. A browser will not let a page make a noise before the first gesture, so it is armed at load and released on that gesture | **DONE** `15c9a49` |
| 5.2 | "ticket katne bela ma" | The confirmed booking now plays `ticket` (a 70 ms band-passed noise tear under the bells) instead of the generic success chime | **DONE** `15c9a49` |
| 5.3 | "notification ma sound" | Already shipped — every toast plays `notify` | **ALREADY TRUE** |
| 5.4 | off switch | Already shipped in the mobile menu (Sound / Vibration), and the new sounds obey it | **ALREADY TRUE** |

## 6. Chatbot ("SHG Sahayak")

The chat widget has two engines. The rule engine (`16-lazy.js`) is always on
and answers offline. The real assistant (`api/ai-chat.php` → `AiAgent`) can
book, correct a name and read reports — but `AiAgent::webEnabled()` needs an
**Anthropic or Gemini key in settings**, and there is none on live.

| # | Ask | Needs a key? | Status |
|---|---|---|---|
| 6.1 | "hareko kuraako answer … bahiro kuraako ni … majak garna sakos" | Yes | **BLOCKED** |
| 6.2 | "aafai train hune jasto dataharubata" | Yes (the knowledge base `includes/aiknowledge.php` feeds it) | **BLOCKED** |
| 6.3 | "awaj / voice pani sunna sakos" | No — browser speech API | **NEXT** |
| 6.4 | "customer le ke bhanla chha guess pani garna sakos" | No — suggestions from the rule engine + history | **NEXT** |
| 6.5 | "lekheko pani chhutauna sakos" | No — inline completion | **NEXT** |
| 6.6 | "naam / number mistake … traser aaunchha … tyaslai fix garna sakos" | No — `includes/personname.php` already exists | **NEXT** |

## 7. Tickets

| # | Ask | Status |
|---|---|---|
| 7.1 | "QR code lai highlight gardinu" | **NEXT** |
| 7.2 | "payment agadi bhandinu" — say the payment terms before, not after | **NEXT** |
| 7.3 | Nepal / India flags on the ticket | **NEXT** |
| 7.4 | Ticket text in Nepali | **NEXT** (see 3.4) |

## 8. People and business

| # | Ask | Status |
|---|---|---|
| 8.1 | "number ra bus ko contact list" | **NEXT** |
| 8.2 | "marketing … application baata system" | **NEXT** — `includes/promocard.php` exists but is unwired (see the 22 Sep audit) |
| 8.3 | "admin ra agent ani customer — tinota ko connection ramro hos, agent ra admin lai bishesh focus" | **NEXT** |
| 8.4 | "QuickBot le tyakka parera sabai kuraako answer" | **NEXT** |

---

## Deploy

Nothing goes to `vps main` without the owner saying yes, per
`docs/` and the standing rule. The route when they do:

1. `git push vps main:wip`
2. `ssh shari-vps '/root/shg-test-refresh.sh wip'` — this is also where
   `pay-image.php` gets its first real PHP lint and render, because this
   Windows checkout has no PHP.
3. `php tests/run-all.php` on the test copy
4. snapshot → `git merge --ff-only wip` on the live worktree → `chown` →
   bump `sw.js` VERSION + the asset stamp → curl `/` and `/admin/login.php`

## Open questions for the owner

1. **AI key** — Anthropic or Gemini? Everything in §6 that makes the bot
   "answer anything and joke" waits on this one setting.
2. **Admin / agent panels in Nepali too?** (§3.5) Today they are English by
   design; changing that is a bigger job than the customer app was.
3. **Images** — `docs/IMAGE-REFRESH-HANDOFF.md` is still *pending selection*.
   No approved image pack exists, so "ramro image" was answered with
   code-drawn detail, not new artwork.
