# AI Sahayak Pro — website, app, admin panel and WhatsApp on ONE assistant · 24 Sep 2026

Owner ko bhanai (24 Sep): *"Mero website ko AI lai advance banau — AI le sabai kaam garna sakos,
sabai kura ko answer deos, image, report, graph, real-time data (kati customer le visit gare,
kati ticket bikri bhayo). Agent / Admin / Customer — teen wotai WhatsApp bata pani, app bata
pani. Hindi, English, Nepali ma; travel ra company reputation ma dhyan. Deploy pani gardeu.
Application ko color haru ajhai rich, logo matching theme."*

Branch: `claude/ai-website-analytics-upgrade-6slchb`. Live is **untouched** until you deploy (§6).

---

## 0. Nepali summary (romanized) — chhoto

- **Ek AI, teen thau.** Ahile samma WhatsApp ko sahayak sanga matra *haat* thiyo (ticket katne,
  naam sachyaune, agent ko hisab). Website ko chat ta plain question-answer matra thiyo. Aba
  website ko 🤖 chat, admin panel ko naya **"AI Sahayak"** page, ra WhatsApp — **tinai wota
  euta agent** (`includes/aiagent.php`) chalauchan. Website ma *ko ho* bhanne kura sign-in le
  bhancha (office → admin, counter agent → aafnai book, OTP customer → aafnai number, guest →
  sodhna ra bhaau matra), WhatsApp ma number le.
- **Naya report / graph tools** (sabai channel ma): `sales_report` (aaja / hapta / mahina /
  custom, din / route / payment / pickup / agent anusar), `site_visitors` (aaja website ma kati
  manche, live "ahile ko cha", visits per day, search, checkout drop), `occupancy_report` (kun
  bus kati bhariyo), `agent_leaderboard`, `record_feedback` (rating / gunaso — office ko
  Ratings ra Enquiries mai jancha). Website ma graph **Chart.js** le bancha (⬇ PNG download),
  WhatsApp ma **PNG photo** bhayera jancha (`includes/aichart.php`, GD, server mai).
- **Bhasa:** Nepali (Devanagari / romanised), Hindi, English — jun ma lekhyo tyahi ma jawaf.
  Widget ko dropdown le hint dincha. Gujarati lekhe matra Gujarati.
- **Focus:** travel + yo company matra. Reputation ko niyam prompt mai cha: garva sanga tara
  satya matra, kahilyai jhuto award/number nabhanne, aru company lai naramro nabhanne, rishayeko
  manche sanga jhagada nagarne — sorry, gunaso record, office number.
- **Theme:** logo ka rang (navy `#0C306C`, royal `#0054A8`, orange `#F07800`, gold `#FFB703`)
  ajhai gahiro; hero, button, nav, footer, admin top bar, chat panel — sabai ma brand gradient.
  Chat panel WhatsApp-green bata brand ma aayo. Manifest theme colour pani.
- **Suraksha:** website bata ticket **katne switch OFF** (`ai_web_sell = 0`) — quote garcha,
  booking screen ma pathaucha. Key server mai; CSRF; per-IP ra per-person rate limit; sabai
  call `ai_agent_calls` ma (`channel = web`), Admin → AI Activity ma dekhincha.
- **Deploy yo sandbox bata sambhav bhayena** (VPS ma SSH pugena) — §6 ma exact command haru.

---

## 1. What changed (the map)

| Layer | File | What |
|---|---|---|
| Identity | `includes/aitools.php` `whoIsWeb()` | role from the PHP session: superadmin/manager → **admin**, agent/counter → **staff** (scoped by `sold_by_admin_id`), OTP user → **customer** on their number, nobody → guest (customer, no number, hashed session `stageKey`) |
| Catalogue | `AiTools::catalogue()` | channel-aware selling switch (`ai_web_sell` on web, `wa_agent_sell` on WhatsApp); a guest is never offered `issue_ticket`; new report/feedback tools by role |
| Tools | `AiTools::salesReport / siteVisitors / occupancyReport / recordFeedback` (+ `agent_leaderboard`) | read-only reports from the same tables and INR peg as Admin → Analytics; every report returns a `chart` block |
| Loop | `includes/aiturn.php` | returns `outcomes` so the caller can lift charts/actions out of a turn |
| Agent | `includes/aiagent.php` | `webEnabled()`, `handleWeb()`, web channel rules (languages, subject fence, reputation, reports), chart PNG for WhatsApp, `actions[]` for the widget |
| Chart | `includes/aichart.php` | bar / line / doughnut / dual-axis PNG, 1200×700, brand colours, Devanagari; files under `uploads/ai-charts/<date>/` (random names), swept by `cron/rotate.php` after `ai_chart_keep_days` |
| Endpoint | `api/ai-chat.php` | POST `{message, lang?, reset?}` → `{text, media, charts[], actions[], role, name}`; CSRF + POST + rate limits; 503 when off |
| Boot | `index.php` | `SHG_BOOT.aiAgent` (bool only) |
| Widget | `assets/js/16-lazy.js`, `app.template.html`, `assets/css/views.css` | agent-first with the rule engine as fallback, light markdown, Chart.js (lazy, ⬇ PNG), pictures, action buttons, role badge, ⛶ expand, role-aware chips and greeting, server-side reset |
| Admin | `admin/ai-copilot.php`, `admin/_guard.php` (nav: Reports → 🤖 AI Sahayak), `admin/ai-activity.php` (channel column) | full-screen copilot for the office and agents |
| Theme | `assets/css/app.css`, `assets/css/views.css`, `admin/_guard.php`, `manifest.webmanifest`, `app.template.html` | logo palette enriched + brand gradients (§4) |
| Settings | `database/upgrade-2026-09-24-ai-sahayak-pro.sql` | `ai_web_agent_on` (1), `ai_web_sell` (0), `ai_web_daily_cap`, `ai_web_max_tools`, `ai_reply_langs`, `ai_chart_keep_days` |
| Tests | `tests/ai-chart-test.php` (no DB), `tests/ai-web-agent-test.php` (DB), registered in `tests/run-all.php`; CI runs the two DB-free AI suites | |

Nothing on the money path changed: the AI still only presses the desk's own buttons
(`QuickTicket`, `BookingService`, `Notify`). The WhatsApp switches and behaviour are untouched
except that a report question now comes back with a picture.

---

## 2. What each role can do now

| Who | Website / app chat | Admin → AI Sahayak | WhatsApp |
|---|---|---|---|
| **Guest** | fares, timings, border, luggage, company story, complaint / rating, a fare **quote** → sent to Book | — | (a number is always a customer) |
| **Customer** (OTP at #/my) | + their own tickets: status, resend, payment QR, name / date / phone correction, refund quote & cancel; ticket **issue** only if `ai_web_sell = 1` | — | same, by number |
| **Agent / counter** | + `agent_day`, `agent_passengers`, `sales_report` (own), `occupancy_report`, fare quotes; `staff_sell` if `ai_web_sell = 1` | ✅ same, full screen with charts | same |
| **Office** (superadmin / manager) | + `office_day`, `office_search`, `office_alerts`, `sales_report` (all, by agent), `site_visitors` (live), `agent_leaderboard`; `office_confirm` if `wa_agent_admin_write = 1` | ✅ | same |

Support / accountant / scanner sessions get the customer tools (same door as WhatsApp).

---

## 3. Reports, graphs, pictures

Ask in words — the model picks the tool:

- *"aaja kati bikri bhayo?"* → `sales_report today` — totals + bar chart
- *"yo hapta ko graph"* / *"इस हफ्ते का ग्राफ"* → by day, revenue + tickets on two axes
- *"kun route le badi kamayo?"* → `group_by route`; *"payment kasari aayo?"* → `method`
- *"website ma aaja kati manche?"* / *"ahile ko cha site ma?"* → `site_visitors` — **onSiteNow**
  (last 5 min), visits per day, searches, empty searches, checkout drop-offs, tickets per 100 visits
- *"bholi ko bus kati bhariyo?"* → `occupancy_report` — sold / capacity per departure, % chart
- *"top agent ko ho?"* → `agent_leaderboard`
- *"gunaso cha"* / *"5 star"* → `record_feedback` → `feedback` table (+ `enquiries` as a
  complaint when rating ≤ 2, with a reference the office quotes back)

Website: the chart is drawn under the reply (Chart.js, lazy from the CDN already allowed by the
CSP), with **⬇ PNG**. WhatsApp: the first chart of the turn is painted to a PNG on the server and
sent as an image (a ticket picture always wins the single media slot). Visits are the beacon's
anonymous per-visit keys — nothing joins them to a person.

---

## 4. Theme — logo-matching, richer

Measured from `assets/img/logo.png`: globe navy `#0C306C` → royal `#0054A8` → sky `#2D7BE0`;
arrow orange `#F07800` → gold `#FFB703`. Applied as:

- base tokens in `app.css` (first paint matches before any script runs) and the enriched
  `[data-day="logo"]` palette in `views.css` (the default; the palette picker still offers the rest)
- `--grad-brand` (navy → royal → sky) on `.btn-blue`, the hero, the footer; `--grad-warm`
  (orange → gold) on `.btn-orange`, chat send, action buttons; a 3 px brand stripe under the nav
- the assistant panel: navy/royal header with an orange rule, royal user bubbles, brand chips,
  the 🤖 button wearing the whole logo gradient; dark mode kept
- admin panel: top bar navy → royal with an orange rule; tokens aligned
- `manifest.webmanifest` + `<meta name="theme-color">` → `#0C306C`

---

## 5. Switch it on (owner)

1. **Database** (once): `bash deploy.sh --migrate database/upgrade-2026-09-24-ai-sahayak-pro.sql`
   (or on the VPS: `php tests/apply-sql.php database/upgrade-2026-09-24-ai-sahayak-pro.sql`).
2. **Key**: Admin → Settings → `ai` → `anthropic_api_key` (Claude, best with tools) and/or
   `gemini_api_key`. `ai_provider = auto` tries Claude first, Gemini as the net.
3. That is all for READING: the website chat and Admin → Reports → **🤖 AI Sahayak** answer at
   once (`ai_web_agent_on` ships 1). Watch Admin → **AI Activity** (channel `web`).
4. Selling from the website chat: `ai_web_sell = 1` only when you want it (customers already
   have the booking screen; staff have ⚡ Quick Ticket). WhatsApp keeps its own `wa_agent_on` /
   `wa_agent_sell`.
5. Languages: `ai_reply_langs = ne,hi,en` (order of preference). Chart pictures are kept
   `ai_chart_keep_days` (3) days.

OFF: `ai_web_agent_on = 0` → the widget is exactly the 13 Sep rule engine + plain relay again.

---

## 6. Deploy — what this branch could NOT do

This work was built and tested in a sandbox with **no route to the VPS** (SSH to 93.127.167.249
refused, the site unreachable), so the branch is **pushed but not deployed**. From a machine
with the deploy key:

```bash
git fetch origin claude/ai-website-analytics-upgrade-6slchb
git checkout claude/ai-website-analytics-upgrade-6slchb
bash deploy.sh --backup                     # rsync + remote DB backup
bash deploy.sh --migrate database/upgrade-2026-09-24-ai-sahayak-pro.sql
```

Or on the VPS (`/root/shg-site.git` is the live repo, per CLAUDE.md):

```bash
cd /var/www/shreehariglobal.in/public_html
git fetch origin claude/ai-website-analytics-upgrade-6slchb
git merge --no-ff origin/claude/ai-website-analytics-upgrade-6slchb   # or cherry-pick
php tests/apply-sql.php database/upgrade-2026-09-24-ai-sahayak-pro.sql
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax'
chown -R www-data:www-data .
mkdir -p uploads/ai-charts && chown www-data:www-data uploads/ai-charts
curl -s -o /dev/null -w "%{http_code}\n" https://www.shreehariglobal.in/
```

Then a first look: open the site, tap 🤖, ask *"aaja ko bus kati baje?"* as a guest; sign in to
Admin, open **AI Sahayak**, ask *"aaja kati bikri bhayo, graph dekhau"*. Nginx already serves
`/uploads/` (the `ai-charts` folder is not in its deny list), so WhatsApp pictures work as soon as
`uploads/ai-charts/` is writable by `www-data`.

---

## 7. Tests

- `php tests/ai-chart-test.php` — 15 checks, **no database** (also in CI)
- `php tests/ai-web-agent-test.php` — 69 checks on `shari_test`: identity by session, per-channel
  selling switch, a real sale counted in the right reports and hidden from another agent, the
  beacon read back, feedback → Ratings + Enquiries, audit channel, entry point, wiring
- unchanged: `tests/wa-agent-test.php` 79/79, `tests/ai-turn-test.php` 9/9, `tests/ai-kb-test.php`
  27/27, `tests/asset-version-test.php` 9/9 (all `?v=` on `20260924a`, SW `shg-v155`)

Not exercised here: a live model call (no key in the sandbox). The loop, the tools and the
prompt builders are covered; the first live turns should be read in AI Activity.

---

## 8. Next, if wanted

- a **daily WhatsApp digest** to the office with the day's chart (`cron/daily-summary.php` +
  `AiChart::png`) — the pieces exist now
- **voice** in the widget already exists (🎤); WhatsApp voice notes need transcription
- a **"share this chart"** button that posts the PNG to a WhatsApp number from the admin copilot
