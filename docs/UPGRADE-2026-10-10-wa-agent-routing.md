# 10 Oct 2026 — WhatsApp agent routing, ticket link, ticket changes by code, teach-the-bot

Owner's prompt (6 phases, 19 items) + owner's rule: **paid WhatsApp credit only to deliver the
customer's own ticket — everything else free**.

## What was true before this change (inspection, 10 Oct ~11:30 IST)

- `whatsapp/webhook.php` never sent tickets — it handles Meta statuses + inbound only.
- The ticket ladder is AiSensy (`whatsapp_driver=aisensy`, `aisensy_mode=always`): template
  `shg_ticket_image` / `shg_ticket_confirmed` on the AiSensy WABA. **AiSensy wallet empty
  (ERR402 WCC): 193 failed in 24 h.**
- Meta number +91 87358 81507 (phone id 1256122924259996): CONNECTED, GREEN, TIER_250, 15 approved
  templates (ticket = `shg_ticket_confirmed_v3`). One test template to the owner on 10 Oct 12:08
  → FAILED "no payment method is set up" (131042) → `wa_meta_templates_on` stays 0.
- The site's "send hi" button pointed at the AiSensy number, which has no inbound webhook.
- No `agents` table: agents are `admins` (role agent / counter); routes `agent_route_permissions`
  (empty on live).

## What this adds

| Prompt item | Where |
|---|---|
| 1 inspect webhook | (report) — reused `sendWhatsAppText/Interactive/Image` from `whatsapp/api.php` |
| 2 agents table | **not duplicated**: `admins` + `agent_route_permissions`; new `wa_agent_presence` (availability_status, busy_until, last_active) |
| 3 admin_number | setting `wa_admin_number` (empty = `admin_whatsapp`) |
| 4–6 routing | `includes/waagentroute.php` + `cron/wa-agent-route.php` (every minute) |
| 7 delivery_log | table `delivery_log` (ticket, agent tried, channel, status, time) |
| 8–9 ticket link | `includes/ticketlink.php` (`ticket_links`), `ticket/view.php?code=` |
| 10 new templates | `tools/wa-meta-templates.php` — ticket_delivery_failed, otp_verification, discount_offer, good_morning_agent (all behind OFF switches) |
| 11 shg_ticket_confirmed | untouched |
| 12–14 edit + OTP + audit | `includes/waedit.php`, table `ticket_edit_audit` |
| 15 voice | already live (WaVoice, Gemini); the transcript now reaches the edit intents |
| 16 customer lists | `admin/customer-lists.php` (CEO all / staff own routes / agent own sales) |
| 17–18 discounts | already `coupons` (`auto_apply` 1 = automatic, 0 = only when asked) — no new table |
| 19 6 AM cron | `cron/wa-good-morning.php` (agents + admin digest, free only) |
| extra: "hi" number | `WaQueue::hiNumber()` + `index.php` → Meta number, button always shown |
| extra: teach the bot | `includes/wateach.php` — `sikau: q = a`, `prashna`, `jawaf 12: …`, `bot help` |

## Flow

1. Ticket confirmed → existing ladder (unchanged) → `delivery_log` row `watching`.
2. After `wa_route_grace_min` (3): customer got it? (`wa_ticket_queue` delivered/read or Meta free
   send) → `delivered`. Else → the seller, then route agents, then agents who sell on that route —
   only `available` ones, only inside their free Meta window (closed → web push nudge → next).
3. Agent gets: ticket PNG, passenger number, wa.me link with the message typed, short link,
   [✅ Done] [⏳ Busy]. `wa_route_timeout_min` (5) without answer → `timeout`, agent busy for
   `wa_route_busy_min` (60), next agent.
4. Nobody → `exhausted`, passenger sent the link directly (free if their window is open, else it
   waits for their "hi"), admin told (free WhatsApp in its window, else admin bell + push).

Agents write `available` / `busy` / `offline` / `mero status` to the Meta number.

## Ticket changes (customer, on WhatsApp)

After a free ticket: [✏️ Name] [📅 Date] [⋯ More → Agent / Cancel] — or words / voice
("naam galat cha", "miti badalnu cha", "agent change", "ticket cancel garnu cha").
Every action → 6-digit code in the chat (otp_codes, hashed, 10 min, 5 tries) → then:
name = `AiTools rename_passenger`, date = `quote_ticket_fix` preview → `fix_ticket` (same as the
desk), agent = next route agent, **cancel = a request to the office only** (owner settles by hand).
WaBooking's own just-sold name fix still answers first inside a sale conversation.

## Live steps

1. `database/upgrade-2026-10-10-wa-agent-routing.sql` by hand.
2. www-data crontab: `* * * * *` cron/wa-agent-route.php, 6 AM IST cron/wa-good-morning.php.
3. `sudo -u www-data php tools/wa-meta-templates.php` (submits the 4 templates).

## Owner decisions still open

- Meta Business → Billing: add a payment method → then `wa_meta_templates_on = 1` and tickets go
  on `shg_ticket_confirmed_v3` from the Meta number (and `wa_policy = meta_only` drops AiSensy).
- Or recharge the AiSensy wallet.
- Assign routes to agents (Admin → Assign Agents) so routing asks the right people first.
- Agents must write to +91 87358 81507 once a day (free window) to receive hand-overs.
