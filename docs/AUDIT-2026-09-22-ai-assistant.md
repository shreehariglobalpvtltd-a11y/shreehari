# AUDIT — AI Travel Assistant / Booking Agent (Phase 1)

Date: 22 Sep 2026 · Branch `codex/ai-company-manager-20260921` · Live = `vps main` (shg-v132), **untouched**.

This is the "read the code first, do not guess" pass the owner asked for (spec point 20–21:
AUDIT → PLAN → IMPLEMENT → TEST → VERIFY → DEPLOY). Nothing here changes live.

---

## 0. Nepali summary (romanized) — chhoto

- **Sabse thulo kura: dherai kaam pahilai baneko cha.** Tapai ko 21-bunda ko spec ko lagbhag
  70% ta code ma agadi nai cha — WhatsApp AI sahayak (ticket katne, naam sachyaune, cancel,
  agent/office report), 3 role (customer/agent/office) number bata chinne, quote-ani-confirm,
  audit, rate-limit, Claude+Gemini dubai. Yo sab **live cha tara OFF cha** (sabai switch band).
- **3 wota asli blocker (live ma customer lai asar):**
  1. WhatsApp ticket **template ko naam galat** — Meta le "shg_ticket_hi_v1 hi ma chhaina"
     bhancha. Yesle ticket ko photo customer lai **pugdaina**. Yo **tapai le** WhatsApp
     Manager bata template ko exact naam+bhasha herera Settings ma halne kaam ho.
  2. AI sahayak **OFF cha ra API key halieko chhaina** — key na halne samma bot bolna
     thaldaina.
  3. Gupshup driver taiyaar cha, **API key + app name** kurirahyo (purano note).
- **2 wota safai chahine kura (live ma asar chhaina, tara branch safa banaunu parcha):**
  1. Naya `includes/ai/*` (dosro generation) **aadhaa baneko cha, kahi jodieko chhaina** —
     mareko code. Recommend: hataune ya park garne, gen-1 sanga jaane.
  2. Marketing engine **surakshit chha tara jodieko chhaina** — customer le "START OFFERS"
     pathayo bhane process garne bato chhaina, ra `ai-turn-test` battery ma register bhako
     chhaina.
- **Mero salla:** naya thulo code nalekhaun. Pahila (a) yo aadha-baneko branch lai shg-test
  ma chalayera regression herum, (b) tespachi ek-ek module pakka garum. **Live ko #1 kaam**
  ta template naam milaune ho — tyo bina AI le ticket katcha pani photo pugdaina.

---

## 1. What actually runs (architecture map)

### 1a. Booking core (the money path — do not touch)
Every sale, from any channel, goes through the same functions:
`QuickTicket::plan()` / `QuickTicket::sellCustomer()` / `counterSale()` →
`Seats::assertAvailable()` → `BookingService`. Seat lock, cut-off, fare, cap and audit are
all enforced there. **The AI never writes a seat, fare or refund itself** — it only chooses
which of these buttons to press, and the result is read back from the live register.

### 1b. AI generation 1 — SHIPPED and working (all behind OFF switches)
This is the real assistant, from the 20–21 Sep work (`docs/UPGRADE-2026-09-20-wa-agent.md`).

| File | Role |
|---|---|
| `includes/aiagent.php` | The loop: message → role → model (Claude first, Gemini ladder fallback) → tools → one short Nepali reply + ticket picture. Never throws. |
| `includes/aitools.php` | The hands: ~17 tools + 4 gates, each a call into QuickTicket/BookingService/Notify. Role decides which tools exist. Writes the `ai_agent_calls` audit row. |
| `includes/aiturn.php` | Bounded orchestration: tool budget, deadline, and a **fingerprint that stops a retry from repeating a write** (idempotency). |
| `includes/aigovernor.php` | The rail for *learning* jobs: an allow-list + deny-patterns so no tuning job can ever move a fare/refund/seat key; every write reversible + audited; shadow mode. |
| `includes/wabot.php` | Inbound router: a bare PNR takes the old fast path; anything else → `AiAgent::handle()`. |
| `includes/wabooking.php` | WhatsApp booking helpers (party names, corrections, "ho" = yes). |
| `includes/aiprompt.php` | `ai_system_prompt()` — the live routes/fares/refund facts, read fresh per question (never "trained", never stale). |

**Role model (server-side RBAC):** `AiTools::whoIs($number)` matches the sender against
`admins`. Saying "I am admin" in a message does nothing. Customer sees only their own
number's bookings; agent sees only their own sales; office sees the company. Confirmation
("ho") is taken from the authenticated message, never from model arguments.

**Selling is two messages:** quote (date+pickup+seats+total pinned) → customer replies "ho"
→ ticket. Re-checked before issue; a changed price never issues. Corrections: `quote_ticket_fix`
→ show → confirm → `fix_ticket`; name fix max twice per booking, before departure, name only.

### 1c. Marketing engine — NEW, safe, but not yet wired
`includes/wamarketing.php` + `cron/wa-marketing.php`. Genuinely production-grade safety:
double opt-in (customer must send **START OFFERS** themselves), APPROVED MARKETING templates
only + must contain a **STOP** line, manager/owner only, confirm-token + turn-gate + TTL,
consent re-checked at queue/claim/send, daily cap, worker-death → "unknown" (never resend).
See §3 for the wiring gap.

### 1d. AI generation 2 — ORPHANED, incomplete (dead code)
`includes/ai/` (`Identity`, `Privacy`, `Language`, `Knowledge`, `bootstrap`) is a cleaner
second-generation rewrite (RBAC, KB articles `ai_kb_articles`, `domain_events`, encrypted
private memory). **But `ai/bootstrap.php` requires 10 modules and only 5 exist** — the other
6 (`Provider`, `Tools`, `Conversation`, `Queue`, `Assets`, `Gateway`) are missing, and
**nothing anywhere requires `ai/bootstrap.php`.** So it is half-built and disconnected. It
would fatal *if* it were ever included — it never is, so live/test are unaffected.

### 1e. WhatsApp inbound path
`whatsapp/webhook.php` (signature-verified) → `wabot.php` → PNR fast path OR `AiAgent::handle()`.
Outbound send functions in `whatsapp/api.php` (Meta Cloud API; retries once; delivery arrives
later on the webhook). `whatsapp/gupshup.php` is an alternate driver, ready, waiting on keys.

---

## 2. Spec coverage — owner's 21 points vs. reality

| # | Spec ask | State |
|---|---|---|
| 2 | Semantic intent, 1000+ phrasings | ✅ model-driven understanding; `AiLanguage::intent` patterns in gen-2 |
| 3 | Multilingual + roman + typos | ✅ `TicketBot::detectLang`, `AiLanguage::normalize`, prompt rules (Gujarati dropped per owner) |
| 4 | Three roles, server-side RBAC | ✅ `AiTools::whoIs` / `AiIdentity`, number-based, scoped |
| 5 | Ticket correction workflow | ✅ quote→confirm→fix, rename ≤2, audit, re-send |
| 6 | Booking safety, real-time seats, idempotency | ✅ quote-then-confirm, `Seats::assertAvailable`, `AiTurn` fingerprints |
| 7 | WhatsApp AI flow | ✅ webhook → intent → identify → tools → reply |
| 8/9 | Human tone + context memory | ✅ prompt tone rules; history kept as plain words only (re-reads register each turn) |
| 10 | Marketing, no fake offers | ✅ `WaMarketing`, consent-gated (see §3 wiring gap) |
| 11/12 | Support classify + escalate | ⚠️ handoff intent + office tools exist; no dedicated support-queue screen |
| 13 | Answer-quality self-check | ✅ prompt "use a tool, never guess a number"; refusals surfaced |
| 14 | Admin AI copilot | ⚠️ `office_day`/`office_search`/`office_alerts` tools exist; **no admin panel screen** |
| 15 | Error recovery / idempotency | ✅ `AiTurn` + marketing worker (no double send) |
| 16 | Audit log, AI vs human traceable | ✅ `ai_agent_calls`, `audit_logs`, `domain_events`, Governor journal |
| 17 | Performance, caps, cache safe reads | ✅ rate limits, tool budget, deadline, model ladder |
| 18 | Security, RBAC, prompt-injection, no secrets to model | ✅ deny-intent, redaction, keys server-side, no card/OTP asks |
| 19 | Never fake confirm / leak / guess | ✅ Governor + prompt fences + ownership checks |

**Verdict: the bulk of the spec is already implemented in gen-1.** The remaining work is
finishing/hardening a few modules and — mostly — the OWNER switching it on safely, not a rewrite.

---

## 3. Findings (bugs / gaps / risks)

### Blockers affecting live customers now
- **B1 — WhatsApp ticket template name is wrong.** `whatsapp_template_name = shg_ticket_hi_v1`,
  Meta answers "does not exist in hi". Confirmed bookings get **no ticket photo**. This is an
  **owner action** (WhatsApp Manager → read the approved template's exact name + language →
  Admin → Settings → Notifications). Nothing else the assistant does matters until this is fixed,
  because even a perfect AI sale can't deliver the ticket.
- **B2 — Assistant is OFF + no keys on live.** `wa_agent_on` unset; no `anthropic_api_key` /
  `gemini_api_key`. The bot cannot speak until keys are set and it is switched on gradually
  (read-only → sell → admin-write), per the wa-agent doc §6.
- **B3 — Gupshup** driver ready, waiting on API key + app name.

### Gaps in the in-progress (uncommitted) work
- **G1 — Marketing consent is not wired.** `WaMarketing::consentMessage()` (handles START
  OFFERS / STOP) and `WaMarketing::deliveryStatus()` (webhook receipt) are **defined but called
  nowhere**. So no customer can opt in → `eligible()` is always empty → marketing can never send.
  Safe, but non-functional. Wiring = one call in `wabot.php` (inbound) + one in `webhook.php`
  (delivery status).
- **G2 — `tests/ai-turn-test.php` is not registered in `tests/run-all.php`** (rule 7 violation).
  No marketing test suite exists either. The battery does not exercise the new code.
- **G3 — Uncommitted mid-refactor.** `aiagent.php`, `aitools.php` (+520 lines), `settings.php`,
  `whatsapp/api.php`, `05-router.js`, `views.css`, `app.template.html` are modified in the tree,
  plus 2 new upgrade SQLs. This has **not been validated on shg-test** (no local PHP). Not live,
  so not dangerous — but the branch is in an unknown-good state and must be pinned before any commit.

### Cleanup
- **C1 — Gen-2 `includes/ai/*` is dead + incomplete** (§1d). Decision needed: (a) finish it as a
  planned migration (this is the "big rewrite" the owner said to avoid), or (b) remove/park it and
  keep gen-1. **Recommend (b)** — keep the working system, don't fork the AI codebase in the tree.
- **C2 — No admin "AI activity" screen** (spec 14/16). Today `ai_agent_calls` is SQL-only.

---

## 4. Recommended path (safe, phased, no big rewrite)

Order chosen for **most real-world benefit per unit of risk**:

1. **Owner action first (B1):** fix the WhatsApp ticket template name so *today's* bookings get
   their ticket photo. Highest impact, zero code, already blocking real passengers.
2. **Pin the branch (G3/G2):** push this branch to `vps wip` → `/root/shg-test`, run
   `php tests/run-all.php` + `wa-agent-test` + `ai-turn-test`; register `ai-turn-test` in
   run-all; fix whatever the battery finds. End state: a known-green branch. *(Test DB only — safe.)*
3. **Decide C1:** remove/park gen-2 `ai/*` so there is one AI codebase.
4. **Wire marketing (G1)** behind its OFF switches, add its test — only after an APPROVED
   MARKETING template with a STOP line exists.
5. **Admin "AI activity" screen (C2 / spec 14):** read-only view of `ai_agent_calls` +
   office copilot, so the office can see what the AI did.
6. **Then** switch the assistant on live, gradually (keys → `wa_agent_on` read-only → observe
   2–3 days → `wa_agent_sell` → `wa_agent_admin_write`), per the wa-agent doc.

Every step: `git status` first, one feature = one commit, additive schema only, feature behind an
OFF switch, full battery green, and **no push to `vps main` (LIVE) without the owner's explicit yes.**
