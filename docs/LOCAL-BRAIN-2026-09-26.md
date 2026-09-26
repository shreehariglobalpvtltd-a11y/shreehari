# The local brain — Sahayak that runs on our own server

**Owner ask, 25 Sep 2026:** *"chatbot lai fully offline ni jati sakdo dherai
kaam garna sakne … VPS ma 8 GB RAM cha, tesma chalne khalko euta model jasto
bandiye, jasle aafai data haru feed gardai sikos … aafai independently."*

This is what was built, what it can and cannot do, and the measurements
behind every claim. Nothing here is switched on for the live website yet:
`ai_local_on` ships `0`.

---

## 1. What is on the server now

| | |
|---|---|
| Engine | llama.cpp `b11184`, prebuilt CPU build (no compiler was installed on the live box) |
| Model | Qwen3-4B-Instruct-2507, Q4_K_M, 2.5 GB |
| Where | `/opt/shg-brain`, systemd unit `shg-brain` |
| Listens on | `127.0.0.1:8081` **only** — `IPAddressDeny=any`, so nothing outside this machine can reach it |
| Priority | `CPUWeight=20`, `Nice=10` — nginx, php-fpm and mariadb all sit at 100 and win every fight for the two cores |
| Memory | `MemoryMax=5G`; measured ~3.1 GB resident |
| Rebuildable | `deploy/install-local-brain.sh` |

The website talks to it as one more provider row in `AiAgent`. llama.cpp
speaks the same OpenAI-shaped API that `askOpenAICompat()` was already
written for, tool calls included — so this needed no new model code, only a
provider that is allowed to have no API key.

## 2. What the hardware can actually do

Measured on the live VPS (2 vCPU AMD EPYC 9354P, 8 GB, AVX-512) on
25–26 September 2026. These are the numbers everything else follows from:

| | |
|---|---|
| Reading a prompt | **22.2 tokens/sec** (12.7 before the KV cache was moved from q8_0 to f16) |
| Writing an answer | **4.2 tokens/sec** at a 2 600-token context |
| Nepali in Devanagari | **good** — correct, grounded, right register |
| Nepali in roman letters | **poor** — invents facts, repeats itself |
| Tool calling | **works** — right tool, right argument, right stop reason |

Two vCPUs is the ceiling, not the 8 GB of RAM. RAM was never the constraint.

Two findings worth keeping:

**The KV cache type matters more than it looks.** q8_0 saves memory and costs
a dequantisation on every token; f16 made prompt intake 75% faster for about
600 MB more. On a box with RAM to spare and no GPU, f16 wins.

**Qwen3-1.7B was measured head to head and rejected.** It is genuinely faster
— 60.5 tok/s prompt, 8.4 tok/s generation, roughly 2.5x — but it is a *hybrid
reasoning* model: it spends its tokens thinking before it answers. Every call
burned the whole reply budget inside the think block and came back with
nothing. Two real turns, both null. Speed is no use if the answer never
arrives. If a smaller model is tried again it has to be a **non-thinking
instruct build**.

## 3. The thing that nearly sank it

The assistant's normal briefing is **4 843 tokens**, plus **1 561 tokens** of
tool schema for a customer. A cloud API reads 6 404 tokens in well under a
second and nobody ever noticed. This box reads it in **eight and a half
minutes**.

The first end-to-end run timed out on every question at exactly 70 seconds,
while the health check, the provider ladder and the enable check all passed.
The plumbing was right; the briefing was a cloud-shaped briefing.

Two changes fixed it:

**A short, stable briefing.** `localPrompt()` is ~900 tokens and the local
tool list is six tools instead of twelve. Just as important, it is *stable* —
llama.cpp keeps a prefix in its cache only while that prefix stays
byte-identical, and the full briefing carries live fares and today's offers,
so it changes during the day and throws the cache away each time. Nothing
volatile goes in the local briefing. Facts come from tools, which is where
they were always supposed to come from: a fare read at the moment of the
question cannot be a stale fare.

**Warming the cache in cron, not in front of a customer.** The cold read is a
per-*restart* cost, not a per-question cost. `cron/ai-warm.php` pays it at
4 a.m. by sending one tiny question through the exact prefix the real traffic
will use. It is also the health check — a brain that is down shows up in the
log there instead of in front of a passenger.

## 4. What it can do

- Answer in Nepali, Hindi or English, about the company, the route, the
  service, and ordinary questions that have nothing to do with buses.
- Use its tools for anything factual: find a ticket by PNR, list a number's
  bookings, quote a fare, give the payment link, say where the bus is, say
  what a cancellation would refund.
- Work with the internet unplugged, with no API key, at no cost per message,
  and without a single passenger question leaving the building.

## 4b. The finding that decides how you should use it

Asked, in Nepali, on the real path:

> **Q:** नेपालको राजधानी कुन हो?
> **A:** नेपालको राजधानी **जयपुर** हो। Nepal's capital is Kathmandu.

Wrong in Nepali and right in English, in one breath. Confident, fluent and
wrong, in the language the customer actually reads.

A fence was then written into the local briefing telling it to answer only
about travel and to decline everything else. **It did not hold.** The same
question afterwards produced the same wrong sentence, word for word. A 4B
model does not reliably obey an instruction that argues against what it
thinks it knows.

So this is not a prompt problem to keep tuning. It is the honest boundary of
the tool, and it splits the work cleanly:

| Question | Who should answer | Why it is safe |
|---|---|---|
| Fares, seats, a PNR, where the bus is, payment | **The local brain** | It does not answer from memory — every fact comes back from a tool reading the live database |
| Anything else — a capital city, the news, a joke | **The cloud brain** (live already holds a Gemini key) | A frontier model can be trusted with general knowledge; this one cannot |

`ai_local_first` is shipped **on**, because the owner asked for offline-first
and because company questions — the overwhelming majority — are exactly the
ones the local brain is safe for. If general-knowledge accuracy matters more
than cost and independence, set `ai_local_first = 0` and the cloud leads with
our own box as the free fallback for when it is unreachable.

One more open item from the same run: a plain company question,
"नमस्ते, बस कहाँबाट कहाँ जान्छ?", came back **null** after 35s — the turn
used its tool budget and never produced text. Not yet diagnosed.

## 5. What it cannot do — honestly

- **It is not Claude.** It is a 4B model. It will be duller, will occasionally
  misread a long question, and is not the one to trust with a subtle
  complaint. Its safety net is that facts come from tools, so being dull is
  not the same as being wrong about a fare.
- **It is not instant.** Measured end to end on a warm cache: a normal answer
  is **20–50 seconds**, because a 200-token Nepali reply at 4.2 tokens/sec is
  48 seconds of writing however fast the prompt was read. That is fine on
  WhatsApp, where nobody watches the screen. On the website widget it needs a
  visible "thinking" state, and the instant rule engine should stay the front
  line with the brain behind it.
- **Its roman-letter Nepali is poor**, so it is instructed to reply in
  Devanagari unless the customer writes in roman letters first.
- **It does not sell.** Cancelling, renaming and date corrections are not in
  its tool list — those stay with the desk, which is the safer place for a
  small model to leave a write.
- **It does not "train itself" on our data in the sense of changing its own
  weights.** No model is fine-tuned and nothing is uploaded anywhere. It
  learns the company the way the cloud assistant always did: by *reading* it
  live at the moment of the question. That is a feature, not a shortfall — a
  model trained on last month's fares would quote last month's fares with
  total confidence.

## 6. Turning it on

```bash
# on the VPS, test copy first
cd /root/shg-test
php tests/apply-sql.php database/upgrade-2026-09-25-local-brain.sql
mysql shari_test -e "UPDATE settings SET svalue='1' WHERE skey='ai_local_on'"
php cron/ai-warm.php          # pays the cold read once, ~2-3 min
php tests/local-brain-test.php
```

Live is the same, with the live database and the owner's yes. Add the cron
line so a restart is picked up within half an hour:

```
*/30 * * * * www-data php /var/www/shreehariglobal.in/public_html/cron/ai-warm.php --quiet
```

To stop it at any time: `systemctl stop shg-brain`. The assistant falls back
to its rule engine exactly as it does today.


## 8. Still to build on top of this

From the 25 Sep brief (`docs/OWNER-BRIEF-2026-09-25.md` §6):

- voice in and voice out (browser speech APIs — no model needed)
- guessing what the customer is about to write (rule engine + history)
- fixing wrong names and numbers (`includes/personname.php` already exists)
- feeding the knowledge base from the company's own data on a schedule, which
  is the honest version of "aafai sikos"

## 9. Four models measured on the real path — 26 Sep 2026

The owner asked for a faster free model. Four were installed and run through
`tests/brain-bench.php`, which goes through `AiAgent::handleWeb()` — the real
2 600-token briefing, the real tools, the real loop. That matters: a 1.7B
benchmarked against a 150-token prompt looked better than everything here and
fell apart completely on the real one.

| Model | Avg | What actually happened |
|---|---|---|
| Qwen3-4B-Instruct-2507 | 15–30 s | Rambles to the token ceiling, failed the tool call, sometimes returns nothing at all |
| Qwen3-1.7B (thinking off) | 8.4 s | Fast, and **answers by repeating the question back**; degenerates into a loop on anything else |
| Granite-4.2-3B | 37.8 s | **All five answers empty** — it reasons until the budget is gone |
| **Gemma-3-4B-it** | **13.0 s** | Best Nepali, shortest answers, and the only one that **obeyed the refusal rule**. But it invented a fare of ₹2800 (it is ₹2000) and invented a booking status, instead of calling the tool |

### The conclusion, plainly

**None of them is safe in front of a customer**, and they all fail the same
way: asked something factual they answer from memory instead of calling the
tool. For a company that sells tickets, a confidently invented fare is the
worst possible failure — worse than a slow answer, worse than no answer.

Gemma is the best of the four and is now the installed local brain, as the
**fallback**. Gemini leads (`ai_local_first = 0`) because Gemini answers
correctly in two seconds and calls its tools.

### What would actually change this

Not a different 3–4B model; they were four different families and they failed
alike. Either **more cores** (a 4-8 vCPU box would let a 7-8B model run, and
that size does use tools reliably), or accept the local brain as what it is:
a free, private, offline **safety net** for when the cloud is unreachable.

All four are kept in `/opt/shg-brain/models/` (7 GB of 88 GB free), so
swapping one in is a line in the systemd unit and a restart.
