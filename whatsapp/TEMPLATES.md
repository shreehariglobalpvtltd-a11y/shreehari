# WhatsApp templates — Meta Cloud API

WABA `2550012892113424` · sender **+91 87358 81507** (phone number ID `1256122924259996`)

## Why templates

WhatsApp only delivers **free text** when the customer wrote to you in the last 24 hours. Anything the
business starts (ticket, OTP, reminder, alert) needs an **approved template**. Otherwise Meta accepts the send
and then fails it with **code 131047**. `whatsapp/webhook.php` writes that failure into `message_logs`, and the
retry cron and delivery health check pick it up from there.

Categories: **Utility** (transactional, cheapest after Authentication; free when sent inside an open 24 h chat),
**Authentication** (OTP only, fixed format), **Marketing** (promotions, most expensive). Check Meta's current
India rate card at *WhatsApp Manager → Overview → Pricing* before quoting per-message prices.

## Already approved (in use)

### `shg_ticket_confirmed_v2_hxf4fc47f327b75190543be8d9214e6c44`: ticket confirmation (OLD WABA, deprecated)
- Category: **Utility** · Language: `en`
- Header: **IMAGE**, the ticket PNG (`Ticket::imageUrl()`)
- Body:
  ```
  Namaste! Your S Hari Global bus ticket is confirmed.

  PNR: {{1}}
  Route: {{2}}
  Date: {{3}}
  Boarding: {{4}}
  Seat: {{5}}
  Total: {{6}}

  Your e-ticket is attached. Please carry a valid photo ID and reach the boarding point 30 minutes early.

  Safe journey!
  ```
- Variables: `[pnr, route, date, boarding_point · time, seats, total_amount]`, built by `Notify::ticketTemplateVars()`
- Settings: `whatsapp_template_name` = the name above, `whatsapp_template_lang` = `en`
- Used by: `Notify::bookingConfirmed()`, `resendTicketWhatsApp()`, `ticketChanged()`, `cron/whatsapp-retry.php`


### `shg_ticket_confirmed_v3`: ticket confirmation ✅ APPROVED (ACTIVE)
- Category: **Utility** · Language: `en`
- Header: **IMAGE**, the ticket PNG (`Ticket::imageUrl()`)
- Body: same as v2 (English)
- Variables: `[pnr, route, date, boarding_point · time, seats, total_amount]`, built by `Notify::ticketTemplateVars()`
- Settings: `whatsapp_template_name` = `shg_ticket_confirmed_v3`, `whatsapp_template_lang` = `en`
- Used by: `Notify::bookingConfirmed()`, `resendTicketWhatsApp()`, `ticketChanged()`, `cron/whatsapp-retry.php`
- Activated: 19 Sep 2026, replacing v2 after WABA migration to Cloud API

## To create (currently free text, so they reach people only inside a 24 h chat)

| # | Template name | Category | Trigger (code) | Body (suggested) | Variables |
|---|---|---|---|---|---|
| 1 | `shg_login_otp` | **Authentication** | `api/otp.php`, `Auth::agentLoginStart`, admin 2FA | Meta's fixed OTP format: *"{{1}} is your verification code."* + "Copy code" button | `[otp]` |
| 2 | `shg_booking_received` | Utility | `Notify::bookingReceived()` | "Namaste {{1}}! We received your booking {{2}} for {{3}} on {{4}}. Payment is being verified; your ticket will follow here." | `[name, pnr, route, date]` |
| 3 | `shg_payment_rejected` | Utility | `Notify::paymentRejected()` | "Booking {{1}}: payment could not be verified ({{2}}). Please re-upload proof or call {{3}}." | `[pnr, reason, office_phone]` |
| 4 | `shg_booking_cancelled` | Utility | `Notify::bookingCancelled()` | "Booking {{1}} ({{2}}, {{3}}) is cancelled. Refund: {{4}}." | `[pnr, route, date, refund_text]` |
| 5 | `shg_refund_update` | Utility | `refundProcessed()` / `refundDenied()` | "Refund for booking {{1}}: {{2}}. Amount {{3}}. Ref {{4}}." | `[pnr, status, amount, ref]` |
| 6 | `shg_trip_update` | Utility | `Notify::tripEvent()` (boarding / delay / departed) | "Booking {{1}}: {{2}}. Boarding {{3}} at {{4}}. Bus {{5}}." | `[pnr, event_text, stop, time, bus_no]` |
| 7 | `shg_payment_reminder` | Utility | `cron/wa-reminders.php`, WaTemplates `*_payment_reminder` | "Namaste {{1}}, a payment of {{2}} is due for {{3}}. Please pay by {{4}}." | `[name, amount, what, due_date]` |
| 8 | `shg_agent_statement` | Utility | WaTemplates `agent_statement` / summaries (header: DOCUMENT) | "Namaste {{1}}, your statement for {{2}} is attached. Bookings {{3}}, commission {{4}}, balance {{5}}." | `[agent, period, bookings, commission, balance]` |
| 9 | `shg_settlement_receipt` | Utility | WaTemplates `agent_settlement_done` / `agent_cash_settlement` | "Settlement received: {{1}} on {{2}}. New balance {{3}}. Ref {{4}}." | `[amount, date, balance, ref]` |
| 10 | `shg_agent_booking_update` | Utility | `agentBookingApproved/Rejected/Cancelled()`, `bookingPlaced()` | "Booking {{1}} for {{2}}: {{3}}." | `[pnr, passenger, status_text]` |
| 11 | `shg_office_alert` | Utility | admin alerts: new booking, payment proof, low seats, pending approvals, daily digest | "{{1}}: {{2}}" | `[alert_title, detail]` |

Rules Meta checks: no variable at the very start or end of the body, no two variables side by side,
give example values for every variable, and keep the wording transactional (no offers) for Utility.
Variable order must match the table, because the code sends `{{1}}…{{n}}` in that order.

**Wiring:** today only the ticket template has a Meta setting (`whatsapp_template_name`). Templates 1–11 need
a small code change each (a `sendWhatsAppTemplate()` call at the trigger) once Meta approves them. Until then
those messages still go as free text, and SMS (Twilio) keeps carrying the OTP and booking SMS.
