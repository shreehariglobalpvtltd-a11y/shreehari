# WhatsApp templates the code sends — 26 Nov 2026 (fx1)

Read from the code of release `d2cca50a`. This paper says **which template names the code can send, from
where, and how the code itself decides a template may go**. It does not say what Meta shows today —
that cannot be read from a developer machine and is on the owner's list at the bottom.

A "template" is only needed **outside the 24-hour window** (the person has not written to our number in
the last 24 h). Inside the window every message here goes as free text / picture, no template at all.

## 1. How the code decides "this template may be used"

| Rule | Where | Meaning |
|---|---|---|
| `WaTplMeta::ready($name)` | `includes/watplmeta.php` | status cached from Meta is `APPROVED` **and** `wa_tpl_meta_on` is on **and** Meta did not refuse this template in the last `wa_tpl_refused_hours` (default 6 h) |
| Refusal codes | `WaTplMeta::REFUSAL_CODES` | `132000, 132001, 132015, 132016` — "this template cannot be used right now". The template is parked for `wa_tpl_refused_hours` and the caller's fallback runs |
| Status cache | `kv_store`, scope `wa_tpl_meta` (`status.<name>`, `refused.<name>`, `last_sync`) | filled by the template sync (`admin/wa-templates.php`); `MISSING` = Meta does not list it |
| Ticket template choice | `WaTplMeta::ticketTemplate()` | the first READY of [`whatsapp_template_name` setting, `shg_ticket_v2`]; a name the office typed by hand and never synced is still tried (old behaviour) unless just refused |
| Meta templates switch | `WaRoute::metaTemplatesOn()` — `wa_meta_templates_on` | off = no template is tried on the Meta number at all |
| Paid cap | `wa_paid_daily_limit` (`WaRoute::metaTemplate`) | a template is a paid message; past the cap it is not attempted |
| Ticket rescue | `WaRoute::ticketRescue()` — `wa_ticket_rescue_on` (default 1) | when Meta refuses the ticket template with one of the codes above, the ticket link is sent on an **older** template instead: `shg_booking_received` (passenger) or `shg_office_alert` (seller copy) |
| AiSensy | `WaRoute::aisensyOn()` + `AiSensyAPI::enabled()` | the old provider's templates (section 4) are used only while its key is set and the routing policy allows it. **The AiSensy plan expired on 14 Nov 2026**, so today these are a dead path kept for a rollback |

Every sender keeps a fallback: free text in the window → the template → (ticket only) the rescue
template → the retry queue (`cron/whatsapp-retry.php`, outbox `includes/wasend.php`) → the click-to-chat
link the office forwards by hand. Nothing in this slice changes template sending.

## 2. Templates on our own Meta WhatsApp Business Account

### 2a. Already in the account, posted by hand (`WaTplMeta::KNOWN`) — the code never re-submits them

| Template | Shape | Sent from | Purpose key | Notes |
|---|---|---|---|---|
| `shg_ticket_image` | IMAGE header + 6 vars (name, from, to, date, seat, PNR) | `Notify::bookingConfirmed` / `resendTicketWhatsApp` through `Notify::whatsapp` → `WaTplMeta::forPurpose('ticket' …)`; outbox `WaSend`; auto-activation in `cron/whatsapp-retry.php` | `ticket`, `ticket_resend`, `seller_copy`, `ticket_alt` | used when it is the `whatsapp_template_name` setting and READY |
| `shg_booking_received` | text, 4 vars (PNR, route, date, amount) | `Notify` payment-request message (`template_name` meta, `includes/notify.php` ~3174); `WaRoute::ticketRescueSpec` (passenger rescue) | `booking_received` | **the working fallback that carries the ticket link** when the ticket template is refused |
| `shg_office_alert` | text, 2 vars (label, text) | `Notify::staffBookingWhatsApp` (when `wa_cloud_tpl_message` = this name); `admin/api/wa-send.php` (agent / office / crew audiences); `cron/wa-monthly-statements.php` (fallback for the statement); `WaRoute::ticketRescueSpec` (seller copy); `admin/wa-outbox.php` | `office_alert` | the generic "office → staff" template; `cron/whatsapp-retry.php` switches it on by itself once Meta lists it APPROVED |
| `shg_booking_cancelled` | text, 4 vars (PNR, route, date, refund) | `Notify` cancellation notice (~2979) | `booking_cancelled` | the refund shown is the figure the OFFICE registered — nothing is refunded by a message |
| `shg_payment_rejected` | text, 3 vars (PNR, reason, office phone) | `Notify` payment rejected (~2866) | `payment_rejected` | |
| `shg_payment_received` | text, 2 vars (PNR, amount) | `Notify` "payment proof received" (~3352) | `payment_received` | |
| `shg_booking_start` | text, no vars | `WaFlow::STARTER_TEMPLATE` (`includes/waflow.php`) | `booking_start` | opens the chat for the booking form |

### 2b. Templates the site can submit itself (`WaTplMeta::DEFS`) — sent only when READY

| Template | Shape | Sent from | Purpose key(s) | Fallback when not READY |
|---|---|---|---|---|
| `shg_ticket_v2` | IMAGE header + 6 vars (same as `shg_ticket_image`) | same ticket path, second choice in `ticketTemplate()` | `ticket`, `ticket_resend`, `seller_copy` | `shg_ticket_image`; then the rescue |
| `shg_pay_request` | 3 vars (amount, seat, date) + URL button "Pay now" (`pay.php?pnr=`) | `Notify` payment request (`forPurpose('pay' …)`, ~3178) | `pay`, `booking_received`, `pay_request` | `shg_booking_received` |
| `shg_trip_reminder` | 5 vars (name, route, date, time, boarding) | `Notify::tripTemplate` (reminder_24h / 12h / 2h events) | `reminder`, `reminder_24h`, `reminder_12h`, `reminder_2h` | free text in the window; else not sent |
| `shg_bus_live` | 2 vars (name, route) + URL button "Track bus" | `Notify::tripTemplate` (departed / bus_near) | `bus_live`, `departed`, `bus_near` | free text in the window |
| `shg_ticket_changed` | IMAGE header + 5 vars (name, PNR, change, date, seat) | `Notify` ticket update (~1302) | `ticket_update` | the ticket template again |
| `shg_agent_statement` | DOCUMENT header (PDF) + 6 vars | `cron/wa-monthly-statements.php` (`forPurpose('agent_statement')`) | `agent_statement` | `shg_office_alert` with the link |
| `shg_withdraw_update` | 4 vars (name, amount, status, note) | `AgentPro::payoutTemplate` → `WaTplMeta::sendNamed` | `withdraw`, `withdraw_update` | the page's own text message (never both) |
| `shg_daily_collection` | 7 vars (date, counter, tickets, passengers, cash, online, total) | `OfficeReport` counter day (`forPurpose('counter_day')`) | `daily_collection`, `counter_day`, `counter_report` | free text to office numbers in the window |
| `shg_driver_manifest` | DOCUMENT header (chalani) + 3 vars (bus, date, passengers) | `DriverList::deliver` (`forPurpose('driver_list')`) | `driver_list`, `driver_manifest` | free text; only goes with the chalani attached |
| `shg_booking_form` | no vars + FLOW button "Book ticket" | `WaFlow::TEMPLATE` (`WaFlow::templateReady`), `admin/owner-setup.php` | `booking_form` | needs the Flow published; else the wa.php form link |

### 2c. Authentication template (its own class)

| Template | Shape | Sent from | Rule |
|---|---|---|---|
| `shg_login_code` | Meta AUTHENTICATION: "<code> is your verification code" + Copy-code button | `WaAuthOtp::send` / `sendFromText` (`includes/waauthotp.php`), called by `Notify::whatsapp` for a code no free window carried, `admin/login-requests.php` (test send), `whatsapp/aisensy.php` | switch `wa_auth_otp_on` (default on); status in `wa_auth_tpl_status`; "Active" may be ticked by hand on Login setup. Used for staff sign-in codes and customer OTPs alike |

## 3. Templates whose NAME is a setting (the office can point them elsewhere)

| Setting | Default | Used by |
|---|---|---|
| `whatsapp_template_name` (+ `whatsapp_template_lang`) | set by `cron/whatsapp-retry.php` to `shg_ticket_image` once APPROVED | the ticket on the Cloud API |
| `wa_cloud_tpl_message` (+ `wa_cloud_tpl_lang`) | `''` → `shg_office_alert` once APPROVED | the generic office / staff message outside the window (`includes/wasend.php`) |
| `helpdesk_wa_template` | `shg_help_ticket` | `HelpDesk::acknowledge` — the "your complaint is recorded" note. **AiSensy only** (`AiSensyAPI::sendMessage`); with AiSensy off it falls back to free text in the window. The fx1 "talk to a human" request passes `ack => false`, so it sends no template |
| `mk_auto_template` | `shg_offer` | `MkAutopilot::template()` — marketing campaign text template (AiSensy) |
| `mk_auto_image_template` | `shg_offer_image` | `MkAutopilot::templateAndPhoto()` — same 3 vars + IMAGE header, used only while AiSensy reports it APPROVED |
| `twilio_content_sid` | `''` | the Twilio driver's ticket template (`shg_ticket_confirmed`, 7 vars, media header) — only on the Twilio driver |

## 4. Old provider (AiSensy campaign names, `whatsapp/aisensy.php`) — dead path since 14 Nov 2026

| Constant | Default name | Override setting | Use |
|---|---|---|---|
| `TPL_TICKET` | `shg_ticket_confirmed` | `aisensy_tpl_ticket` | ticket text template |
| `TPL_TICKET_IMAGE` | `shg_ticket_image` | `aisensy_tpl_ticket_image` | ticket with the PNG header |
| `TPL_REMINDER` | `shg_departure_reminder` | `aisensy_tpl_reminder` | departure reminder |
| `TPL_CANCEL` | `shg_booking_cancelled` | `aisensy_tpl_cancel` | cancellation notice |
| `TPL_AGENT` | `shg_agent_statement` | `aisensy_tpl_agent` | agent statement / summaries (`admin/api/wa-send.php`) |
| `TPL_CHALAN` | `shg_bus_chalani` | `aisensy_tpl_chalan` | chalani to the crew |
| `TPL_OFFICE` | `shg_office_update` | `aisensy_tpl_office` | office summaries |
| `TPL_MESSAGE` | `shg_message` | `aisensy_tpl_message` | any typed message (name + text) |
| `TPL_TRIP` | `shg_trip_update` | `aisensy_tpl_trip` | trip notices |

`shg_book_form` was the booking-form template in the previous WhatsApp account only (comment in
`includes/waflow.php`); the code now sends `shg_booking_form`.

## 5. Names that look like templates but are NOT

`shg_wa_outbox`, `shg_wa_agent_route`, `shg_wa_marketing`, `shg_mk_worker`, `shg_mk_autopilot`,
`shg_fb_comments` are database lock names; `shg_cust_otp` is the customer's session key (the customer
OTP itself travels on `shg_login_code` or free text). None of them is ever sent to Meta.

## 6. What the bot (this slice) sends

The WhatsApp bot (`includes/wabot.php` and the classes behind it) only **replies** — the person has just
written, so the 24-hour window is open and no template is used. The only bot paths that reach a number
that did not just write are: the office note of a cancel request (`WaEdit::tellOffice`, free text to the
office number inside ITS window, plus the panel bell), and "resend the ticket to the booking's own
number" (`Notify::resendTicketWhatsApp`, the ticket path of section 2). The fx1 changes add no new
template and no new outbound send.

## 7. Known issue (recorded, not solved here)

**Meta error 132001** ("template name / language not found") is returned for the newer templates even
though Meta shows them APPROVED, while the **old AiSensy app is still attached to the WhatsApp Business
Account**. Until the owner removes that app in Meta, `shg_ticket_v2` and the other section-2b templates
can be refused, and tickets outside the window travel on the rescue path (`shg_booking_received` /
`shg_office_alert` with the ticket link — the code comment in `includes/waroute.php` records it as
tested on the office number on 20 Nov 2026). The code already treats 132001 as a refusal and falls
back; nothing is lost, but the passenger gets a link instead of the ticket picture. Whether removing
the app clears 132001 can only be seen on the live account.

## 8. Waiting for the owner

1. In Meta: remove the old AiSensy app from the WhatsApp Business Account (error 132001 above).
2. In Meta (or the sync on `admin/wa-templates.php`): confirm which of the section 2 templates are
   APPROVED today. From the code alone this cannot be known.
3. `shg_login_code`: confirm it is Active (Login setup page shows the status).
4. Decide whether `shg_help_ticket`, `shg_offer`, `shg_offer_image` (AiSensy names) should be created on
   our own account — today they can only go through AiSensy, whose plan has expired.
