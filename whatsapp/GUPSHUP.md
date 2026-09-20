# Gupshup WhatsApp driver — S Hari Global

Gupshup is the third WhatsApp driver this app supports, alongside `twilio`
and `cloud_api` (Meta). Flip `whatsapp_driver` to `gupshup` and the ticket
sender uses Gupshup; the Meta Cloud API path stays wired and switching
`whatsapp_driver` back to `cloud_api` is the rollback.

## What ships in this integration

| Layer | File | Role |
| --- | --- | --- |
| Send layer | `whatsapp/gupshup.php` | HTTP helpers (`sendGupshupTemplate`, `sendGupshupImage`, `sendGupshupDocument`, `sendGupshupText`) with a one-retry-on-5xx policy and per-day log. |
| Send integration | `includes/notify.php` :: `whatsappGupshup()` | Same payload rules as the Meta path — template + IMAGE header, else IMAGE / DOCUMENT / TEXT — plus template-fallback for `code 1002/1004/1005/2010`. |
| Driver switch | `includes/notify.php` :: `whatsapp()` | New `elseif ($driver === 'gupshup')` branch; also the `whatsappTest()` diagnostic. |
| Idempotency | `includes/notify.php` :: `whatsapp()` prologue | `purpose = ticket` + `booking_id` with a `sent` prior row short-circuits and returns `true` without hitting Gupshup. |
| Webhook | `whatsapp/gupshup-webhook.php` | HMAC-SHA256 signature check, status → `message_logs.provider_ref`, inbound → `WaBot::reply()`. |

## Required env / settings

Every value resolves **env first, then the `settings` table**, same pattern as
`whatsapp/config.php`. Nothing secret lives in Git.

| Env var | Settings key | What it is | Required |
| --- | --- | --- | --- |
| `GUPSHUP_API_KEY` | `gupshup_api_key` | The account API key from Gupshup Console → Overview → API Key. | ✅ |
| `GUPSHUP_APP_NAME` | `gupshup_app_name` | The `src.name` of the Gupshup app that owns your WABA (case-sensitive). | ✅ |
| `GUPSHUP_SOURCE_NUMBER` | `gupshup_source` | Sender number in E.164 digits (no `+`), e.g. `918735881507`. | ✅ |
| `GUPSHUP_WEBHOOK_SECRET` | `gupshup_webhook_secret` | Any long random string. Paste the SAME value into Gupshup Console → App → Webhook so it signs each POST. Without it the webhook fails closed. | ✅ |
| `GUPSHUP_API_BASE` | `gupshup_api_base` | Only override for a dedicated cluster. Default: `https://api.gupshup.io`. | — |
| — | `whatsapp_template_name_gupshup` | If Gupshup uses a different template name than Meta (e.g. because you cloned rather than synced). Defaults to `whatsapp_template_name` when empty. | — |
| — | `whatsapp_driver` | Set to `gupshup` to flip the sender. Set back to `cloud_api` to roll back to Meta. | ✅ |

Fill them either through the admin Settings screen (writes to the `settings`
table) or through `php-fpm` pool env (`env[GUPSHUP_API_KEY] = …`) — env wins.

## Ticket template

The existing Meta-approved template **`shg_ticket_confirmed_v3`** (Utility,
IMAGE header, six body variables — see `whatsapp/TEMPLATES.md`) is what the
booking confirmation uses. Two paths from here:

1. **Sync (preferred):** in the Gupshup Console link the app to the SAME WABA
   (`2550012892113424`). All approved templates appear automatically and the
   name `shg_ticket_confirmed_v3` works unchanged. `whatsapp_template_name`
   is already set — no new setting to add. **This is the cheapest path: no
   new review, no new template.**
2. **Clone:** submit the same body/vars through the Gupshup Console.
   Approvals typically come back the same day. When the approved name
   differs, put it in `whatsapp_template_name_gupshup` and leave
   `whatsapp_template_name` pointing at the Meta name so a rollback still
   works.

The variables are built by `Notify::ticketTemplateVars()`:
`[pnr, route, date, boarding_point · time, seats, total_amount]`,
with `{{7}}` carrying the ticket PNG URL as the header IMAGE.

## Webhook

Gupshup Console → App → **Webhook**:

- URL: `https://www.shreehariglobal.in/whatsapp/gupshup-webhook.php`
- Method: `POST`
- Events: `message`, `message-event` (inbound + status)
- Signature secret: paste the value of `GUPSHUP_WEBHOOK_SECRET`. Gupshup
  will then send an `X-Hub-Signature-256` header (same scheme as Meta).

Status updates land in `message_logs` by their `provider_ref` (the Gupshup
`messageId` we stored on send). That is what makes:

- `cron/whatsapp-retry.php` re-send tickets whose async status turned failed;
- `admin/messages-log.php` show sent / delivered / failed per booking;
- the Delivery Sentinel report include Gupshup rows.

## India +91 and Nepal +977 numbers

The number cleaner (`waCleanPhone` in `whatsapp/api.php`, reused here) turns
`0980-123-4567` / `+91 91048 01507` / `whatsapp:+919104…` into the digits
Gupshup wants (`91…` / `977…`). A bare 10-digit number uses the country
hint (`IN` / `NP`), which the booking layer resolves from the ID type — the
same rule Meta and Twilio use, so a Nepali customer's ticket never lands on
an Indian number.

## Idempotency

Inside `Notify::whatsapp()`, when `purpose = ticket` and `booking_id` is set,
the newest `message_logs` row for that booking with `purpose IN (NULL,
'ticket')` is checked. If it is `sent`, the second call returns `true`
without touching Gupshup — a Razorpay duplicate webhook or a double admin
Resend cannot cost the customer a duplicate ticket.

Retry stays automatic: when the delivery callback flips the row to `failed`,
`cron/whatsapp-retry.php` re-sends it. `whatsappGupshup()` also does a
one-shot retry on network / 5xx before returning, mirroring the Meta path.

## Rollback

- `whatsapp_driver = cloud_api` → Meta Cloud API sends immediately; nothing
  else needs to change.
- `whatsapp_driver = twilio` → the legacy Twilio path.
- Turning Gupshup off never rewrites message_logs — historical rows keep
  their `provider = gupshup` marker.

## Test

`tests/gupshup-send-test.php` pins the phone-cleaner branch, template /
image / document payload shape, config-missing behaviour, and driver
integration through `Notify::whatsapp()`. Runs against the TEST database.
No live Gupshup call is made (the API key is deliberately invalid), so it
is safe to run in CI.

For an end-to-end sandbox check the owner needs to hand-fire:

```bash
# on the VPS, with GUPSHUP_API_KEY / _APP_NAME / _SOURCE exported:
curl -sS -X POST https://api.gupshup.io/wa/api/v1/msg \
     -H "apikey: $GUPSHUP_API_KEY" \
     -d "channel=whatsapp&source=$GUPSHUP_SOURCE_NUMBER&destination=91XXXXXXXXXX" \
     -d "src.name=$GUPSHUP_APP_NAME" \
     -d 'message={"type":"text","text":"gupshup wiring test"}'
```

A `2xx` with `"status":"submitted"` proves the credentials are good; the
delivery status then rides back through the webhook and updates the log.
