# Upgrade plan — 24 Sep 2026: "world-top, contactable, fast, unique — a Claude-like mind"

Owner brief (24 Sep, Nepali/Hindi): *research the competition yourself; make the app better on
every page (colour, design, a feature where it helps) so customer, agent and office work with
fewer taps; smooth three-way connection with low latency because the server is ours; make the
ticket system the company's manager — marketing and social media too; an AI that remembers,
learns from what it sees, updates itself, thinks like a person; unique in the world; no small
mistakes; deploy when everything is done.*

## 0. Nepali summary (romanized)

- **Research bhayo** (5 reports, `scratchpad` bata yo file ma saar): India ka thula app (redBus,
  AbhiBus, Paytm, IntrCity, Zingbus) ra Nepal ka (BusSewa, eSewa/Khalti, GoMyGo). Hamro app ma
  pahilai dherai kura cha — PWA, offline ticket, WhatsApp AI, live map, agent wallet. Tara
  **bahira bata SHG dekhindaina** (Google ma "Surat to Nepal bus" ma redBus matra aaucha), ra
  keu **paisa ko bug** cha jun pahila banda garnu parcha.
- **Kram**: (1) paisa/suraksha ko bug, (2) speed, (3) unique feature — "mero bus kaha?" family
  share page, sadhai contact garna milne layer, route page haru 3 bhasha ma, trust card,
  (4) AI Manager — memory, sikne loop, office copilot, marketing queue, (5) deploy.
- **Har feature switch OFF ma aaucha** (rule 3). Deploy pachi Admin → Settings bata ON garne.

## 1. What the research says (one line each, sources in the reports)

| Source | Finding that changes the plan |
|---|---|
| India OTAs | Leaders sell *live tracking + family share, boarding-point photos, women-safety layer, WhatsApp Flows, same-day refund promises, loyalty*. None offers Nepali. An owner-operator can promise tracking on 100 % of trips and refunds in hours — aggregators cannot. |
| Nepal corridor | Demand is festival-spiked (Dashain Tika 21 Oct 2026, Tihar 6–11 Nov, Chhath 15 Nov, Holi 22 Mar 2027). Nepal-side wallets cannot pay Indian UPI yet; UPI-via-friend, cash at agent, family in Nepal via eSewa are the real rails. Complaints: no refund, no ticket photo, no tracking, wrong name. **SHG is invisible on Google and shadowed by "Shree Hari Travels".** |
| PWA performance | 2.3 MB unminified JS/CSS in 18 files + 210 KB shell. Bundling+minify+brotli (−1.0–1.4 s on 3G), lazy MapLibre, shell diet, immutable hashed assets, fonts (system Devanagari), HTTP/3 on nginx.org packages. 103 Early Hints is not reachable through FastCGI. |
| Real-time | Ship 1 s ETag/304 polling + nginx micro-cache first (p95 ≈ 1.2 s), then SSE from a small `[sse]` FPM pool fed by an events table (p50 ≈ 100 ms). WebSockets not needed (one-way). |
| AI | Anthropic ids today: `claude-sonnet-5` ($2/$10), `claude-haiku-4-5-20251001` ($1/$5), `claude-opus-5-5` ($4/$20). Sonnet 5 runs *adaptive thinking by default* — a request with `max_tokens: 700` and no `thinking` field truncates. Memory without training: episodic (per person), semantic (KB with provenance), procedural (approved examples from corrections). Human approval is the "learning". |
| Marketing | Facebook Page + Instagram publish needs **no App Review** for the owner's own page (system-user token). WhatsApp marketing ₹0.86/msg vs utility ₹0.115; opt-in required (DPDP). Google Business Profile posts need API access (apply). Telegram is free. SEO: one SPA URL is the gap — server-rendered route pages in en/hi/ne with hreflang + JSON-LD. |

## 2. Defects found by the code audit (fixed in this upgrade unless marked)

Money and safety first — the owner's "no small mistakes":

| # | Where | What | Status |
|---|---|---|---|
| M1 | `agentwallet.php` recordOfflineTicket | register-only paper ticket: uncapped pax_count → an agent could mint any commission | fixed: capped to the coach / max-seats rule, amount floor |
| M2 | `booking.php` create / `agentwallet.php` accrue | every staff seller (office, counter, manager) accrued agent commission + cash_due | fixed: accrue only for role = agent |
| M3 | `booking.php` submitPaymentProof | new proof on a CONFIRMED / verified booking downgraded payment to pending | fixed |
| M4 | `fare.php` coupons | coupons validated but never redeemed (used_count / coupon_redemptions never written) | fixed at booking create |
| M5 | `booking.php` cancelSeat | second per-seat cancel on one booking lost its commission void (UNIQUE) | fixed: the void row is topped up |
| M6 | `seats.php` lock | no per-visitor hold cap — one browser could hold the whole coach | fixed: cap = max seats per booking |
| M7 | `admin/quick-ticket.php` | Enter → failed plan left `submitAfterPlan` armed → next refresh sold a ticket unattended | fixed |
| S1 | `auth.php` | session never re-read the staff row (deactivate did nothing until next login) | fixed (24 Sep, first commit) |
| S2 | `admin/settings.php` | every API key/token printed into the HTML for any dashboard.view role | fixed: write-only |
| S3 | `admin/wa-pending.php` | counter agent saw every failed ticket | fixed: scoped |
| A1 | `aiagent.php` | Claude request shape wrong for 2026 models (thinking, max_tokens, replay) | fixed |
| A2 | `api/ai-proxy.php` | default model `claude-sonnet-4-6` (50 % dearer than Sonnet 5) | fixed |
| A3 | `aichat.php` | Gemini key in the query string | fixed: header |
| P1 | CSV exports | `fputcsv()` without `$escape` → 500 on PHP 8.4 | fixed (`csv_put`) |
| I1 | `04-i18n.js` | 11 WhatsApp-sheet labels in no language → CI red on every branch | fixed |
| — | `wamarketing.php` consent wiring, `aihandoff`, company docs | being built on PR #1 / #2 (other sessions) | not touched here |
| — | round trip (`round_trip_on`) half-implemented | left OFF; documented | owner decision |
| — | 8 tracked `*.bak` files, the AOA PDF at the web root | nginx already 404s them; delete by hand | owner |

## 3. The build, in waves (each item = one commit, its own test, switch OFF by default)

### Wave 1 — correct money, safe doors (above)

### Wave 2 — fast ("app-like on a ₹8,000 phone")
- `tools/build.mjs` (esbuild on the dev machine / CI): one minified, content-hashed JS bundle +
  CSS bundle, brotli + gzip pre-compressed, `assets/dist/manifest.json` carrying the **source
  hash**. `index.php` serves the bundle only when that hash matches the sources on disk, so a
  file edited straight on the VPS falls back to the 14 plain files and nothing breaks.
- nginx: `immutable` for hashed assets, `gzip_static`/`brotli_static`, `fastcgi_keep_conn`,
  JSON in gzip types; PHP-FPM/OPcache values in `deploy/php-fpm-shg.conf`.
- Seat map: ETag/304 on `api/seats.php` and `admin/api/seatmap-poll.php`; `api/seat-events.php`
  SSE stream (events table, `seat_events_on`), client falls back to polling.
- Splash ≤ 1.5 s, dead intro-trailer markup removed, brochure lazy-injected.

### Wave 3 — unique and contactable
- **"Mero bus kaha?"** public page `/track.php?pnr=…&k=…` — live position, ETA to *your* stop,
  "share with family on WhatsApp", honest "not live" state. Link on the ticket and in WhatsApp.
- **Contact layer** on every customer view: one floating button → Call · WhatsApp · Sahayak chat
  · "call me back" (writes an enquiry the office sees). Office header shows the on-duty number.
- **Route pages** `/bus/<slug>` in en/hi/ne (server-rendered, hreflang, Trip + LocalBusiness +
  FAQ JSON-LD), `robots.txt`, generated sitemap, retitled home. Content from the live routes
  and fares tables — never typed twice.
- **Trust card** on the home page from real numbers (trips this year, on-time %, ratings).
- **Refund ladder** before payment + refund status line in My Bookings.
- **Women-safety layer** visible (pink berths, "women on this bus", 24×7 office call).

### Wave 4 — AI Manager ("Claude ko jasto mindset")
The mindset, written into the system prompt and enforced in code: *honest about what it does
not know; reads the register instead of guessing; never touches money or a seat by itself;
explains in the customer's language; asks one question at a time; remembers people; learns
only from corrections a human approved.*
- One identity across web and WhatsApp (phone), server-side conversation memory; episodic memory
  (`ai_memory_episodes`), approved examples (`ai_examples`) injected into the prompt.
- Feedback loop: 👍/👎 and "galat/hoina/wrong" → `ai_feedback`; staff corrections → example
  candidates → **manager approves** in Admin → AI Manager. Nothing goes live unapproved.
- Nightly `cron/ai-refresh.php`: regenerates company facts from settings/routes/fares (with
  provenance and date), optional web refresh for festival dates/road news (`ai_web_refresh_on`).
- Admin → **AI Manager** hub: office copilot chat (same tools as WhatsApp office role), today's
  brief, cost/usage, examples to approve, unanswered questions, marketing queue.
- Marketing: `social_posts` queue + `cron/social-publish.php` (Facebook Page, Instagram,
  Telegram) with AI-drafted captions in three languages; festival calendar; seat-scarcity
  posts. Every publisher behind a switch with a daily cap and a Health heartbeat.

### Wave 5 — release and deploy
- `sw.js` VERSION bump + one asset stamp, `docs/UPGRADE-2026-09-24.md` in plain language.
- Deploy: `.github/workflows/deploy.yml` (manual, needs the four VPS secrets) **and**
  `deploy/vps-pull-deploy.sh` for a one-line deploy from the VPS itself; migrations listed.

## 4. Owner actions that no code can do
1. WhatsApp ticket template name/language in Settings (still the #1 blocker from 19 Sep).
2. Claude / Gemini API keys in Settings → then `wa_agent_on` (read-only first).
3. Facebook Page + Instagram business account + a Business Manager system-user token;
   Google Business Profile (apply for API access); a Telegram channel + bot token.
4. Deploy secrets in GitHub (or run `deploy/vps-pull-deploy.sh` on the VPS).
5. Delete the eight `*.bak` files and move `AOA Shree Hari Global.pdf` out of the web root.
