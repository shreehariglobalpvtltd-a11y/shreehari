# WhatsApp automation via Twilio — setup

This turns on two things:

1. **Auto-send** — every confirmed booking automatically WhatsApps the
   customer their ticket **PDF** (plus a text summary). Payment-rejected and
   cancellation messages go out automatically too, as before.
2. **Ticket-lookup bot** — a customer messages your WhatsApp number with their
   **PNR** and instantly gets back the booking status, and the ticket PDF if
   it's confirmed and they message from the number used at booking.

Nothing here needs code changes — it's all switches in **Admin → Settings →
Notify** plus a webhook URL you paste into Twilio.

---

## 1. One-time database step (existing live site only)

If your site is already live, run this once so the new settings appear in
Admin → Settings:

hPanel → **phpMyAdmin** → pick your database → **SQL** tab → paste the contents
of `database/upgrade-2026-08-twilio.sql` → **Go**.

(Fresh installs via `install.php` already include these — skip this step.)

---

## 2. Get your Twilio details

From the [Twilio Console](https://console.twilio.com):

- **Account SID** — starts with `AC…`
- **Auth Token** — the secret next to the SID
- **WhatsApp sender** — for testing use the **Sandbox** number
  `whatsapp:+14155238886` (Messaging → Try it out → WhatsApp). For production,
  a WhatsApp-enabled Twilio number, written as `whatsapp:+<your number>`.

> Sandbox note: each person must first send the join code (e.g. `join <word>`)
> to the sandbox number once before it can message them.

---

## 3. Fill in Admin → Settings → Notify

| Setting | Value |
|---|---|
| `whatsapp_driver` | `twilio` |
| `twilio_account_sid` | your `AC…` SID |
| `twilio_auth_token` | your auth token |
| `twilio_whatsapp_from` | `whatsapp:+14155238886` (sandbox) or your number |
| `whatsapp_send_pdf` | `1` (on) — attach the ticket PDF |
| `whatsapp_default_country` | `91` (India) — added to bare 10-digit numbers |

> **India vs Nepal numbers.** Both countries use 10-digit mobiles, so a bare
> 10-digit number is ambiguous. A booking made on a **Nepali Citizenship Card**
> is auto-detected and routed to **+977**; everything else uses
> `whatsapp_default_country`. So Nepali customers who don't use a citizenship
> card should enter their number **with the +977 code** to be sure their ticket
> reaches them.
| `whatsapp_notify_customer` | `1` |
| `whatsapp_notify_admin` | `1` |

Save. That's the auto-send done.

---

## 4. Wire up the bot webhook

In Twilio: **Messaging → Try it out → WhatsApp Sandbox Settings** (or, in
production, your WhatsApp Sender's **Messaging** config).

Set **"When a message comes in"** to:

```
https://YOURDOMAIN.com/api/whatsapp-webhook.php
```

Method: **HTTP POST**. Save.

The webhook verifies Twilio's signature using your `twilio_auth_token`, so a
forged request is rejected. If your host sits behind a proxy that rewrites the
URL and signatures start failing, set `twilio_webhook_url` in Settings to the
exact public URL above.

---

## 5. Test

1. From WhatsApp, send `join <code>` to the sandbox number (sandbox only).
2. Send your PNR, e.g. `SHG-R1-TIZTO3-4RGY`.
3. You should get the status back — and, for a confirmed booking made from
   that same number, the ticket PDF.

If nothing comes back, check `logs/` (channel `whatsapp`) — every send and
signature rejection is logged there.
