# S HARI AI master brief (Claude Opus 5.5): what was built, 29 Oct 2026

This build follows the owner's "AI System Prompt (Opus 5.5 Max Optimised)" document. Every
claim in that document was checked against the live code first (release-20261029-mg1).
About two thirds of it already existed. This file lists only what changed.

**Everything new starts OFF.** Nothing changes for a passenger, an agent or the office until
someone turns a switch on. The bug fixes in section 3 are the only exception: they apply on deploy.

## 1. New, behind switches

| Feature | Where to switch it on | Setting(s) |
|---|---|---|
| **S HARI AI master brief.** The section 12 prompt is placed in front of the existing tuned briefing and adds the 4 security gates and a mode per role. Its tool list is generated from the real tool catalogue, so it never claims tools that do not exist. The text can be edited. | Admin → One AI (WhatsApp brain) → "S HARI AI — master brief" | `ai_master_prompt_on`, `ai_master_prompt` (blank = built-in text) |
| **Claude Opus 5.5.** Adds the effort level, the refusal fallback and a refusal check. Opus 5.5 always thinks and rejects `temperature`. A "Use Claude Opus 5.5 for both" button sets the model for the chat and the WhatsApp agent together. | same page | `ai_claude_effort` (low…max, default low), `ai_claude_fallback` (default on), `ai_model` / `ai_agent_model` |
| **WhatsApp groups.** Office groups made with Meta's Groups API: every message is logged. The AI replies only when called (@S Hari AI / AI बताउ) or when someone asks about the timetable, seats, offices or the refund rule. It can optionally correct a wrong pickup time. It stays quiet 22:00–06:00. It never shares private data. | Admin → WhatsApp Inbox & Groups → Office groups | `wa_group_reply_on`, `wa_groups`, `wa_group_correct_on`, `wa_group_quiet_from/to`, `wa_group_mention_words` |
| **Payment screenshot reading.** The UTR and amount are read and matched to the sender's booking that is waiting for payment. The proof goes to **Verify Payments**. It is never confirmed automatically. | Settings → `wa_pay_ocr_on` | `wa_pay_ocr_on` (needs a Gemini key) |
| **7-day agent lessons.** A new agent gets one WhatsApp lesson a day, in the free 24-hour window only. Each lesson teaches phrases the bot really answers. | Settings → `wa_onboard_on` + crontab (below) | `wa_onboard_on` |
| **Data retention.** AI tool log 90 days (rename/fix rows kept); WhatsApp message log + group log 365 days. Bookings are never touched. | Settings → `data_retention_on` | `data_retention_on`, `ai_log_keep_days`, `wa_log_keep_days` |

Always on, but read-only or opt-in per use:
- **Admin → WhatsApp Inbox & Groups** (`admin/wa-inbox.php`). Every message to the Meta number,
  shown beside the bot's replies, with unread counts. The office can reply inside the free
  24-hour window; no paid template is used. "Pause bot 2 h / 24 h" stops the bot for one
  number while a person handles it. The Contacts tab shows role, bookings, last message and
  marketing opt-out.
- **Application form** (`agreements/apply.html`).
  - The photo is now a **live selfie**: front camera, 3 frames, and a liveness + same-person
    score for the office (`/api/face-check.php`, which nothing called before). If there is no
    camera, the old photo picker is used.
  - The **Aadhaar** number is checked with Verhoeff in the browser and on the server.
  - The **Indian passport** format gets a hint.

## 2. Security fixes in the assistant (live on deploy)

- **Web quote reuse:** a quote parked under a website session key was never consumed, so a second "ho" within 15 minutes could sell it again. It is now consumed under the key it was parked under.
- **"No" is never "yes":** issue_ticket, staff_sell and cancel_ticket refuse when the person's own message says no (hoina / नगर्नु / no …). "ho, Ram Thapa" still sells.
- **Agent phone lookup:** a scoped agent or counter could list any stranger's last 5 bookings by phone. Now they see only the tickets they sold.
- **Staff sale pinning:** a WhatsApp staff sale is pinned to the quoted day, direction, pickup and party, so it can never land on another bus. The price is deliberately not pinned (offers are per number).
- **WRITE_TOOLS:** now includes office_settle_cod / office_reject / office_agent_status / request_payout / bulk_issue, so a failed turn still reports what it changed.
- **Model setting:** a Claude model chosen on the AI Brain page now reaches the WhatsApp agent (it used to read only `ai_agent_model`).

## 3. Bug fixes (live on deploy)

- **Team & Approvals:** agent and partner applications never appeared. The query asked for a
  column `photo_data` that does not exist (it is `photo`), and a silent catch hid the error.
- **Staff applications:** the form sent `role_applied` and `phone_country`, but the server reads
  `role` and `cc`. Every staff application was saved as job "other" and the country was guessed.
- **"Read my ID" on the application form:** it posted to the office-only
  `/api/team-docread.php`, so every applicant got "Sign in required". It now uses the public
  `/api/agreement-docread.php`. The photo is shrunk first, and every field that gets filled
  is marked amber for the applicant to check.
- **Group messages:** a group message would have been answered privately by the 1:1 bot. It is
  now routed to the group door.

## 4. Not built, and why

- **Customer wallet with UPI/eSewa top-up and wallet credits from refunds.** The owner's rule
  is that refunds and cancellations are settled by hand. There is also no live payment gateway.
  The agent wallet already exists.
- **Face similarity from browser embeddings (cosine).** The browser FaceDetector gives boxes
  only, not identity vectors. A home-made score would give false confidence on KYC. The
  existing Gemini liveness + same-person check is now actually run, and KYC is never
  auto-approved.
- **Boarding face check / GPS on scan.** Passengers carry no selfie to compare against. The
  scan already records boarded time and who scanned.
- **Login methods per role.** This could lock people out. Leave it to the owner.
- **`verify_document` / `compare_faces` / `send_whatsapp` / `wallet_transact` as AI tools.**
  The master brief lists only the tools that exist.

## 5. Deploy notes

- Files only. No SQL step: new tables create themselves (`wa_group_log`, `agent_lessons`), and
  settings read their defaults.
- Optional crontab line, only after `wa_onboard_on` is set:
  `0 9-20 * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/wa-onboard.php >> /var/log/shg-wa-onboard.log 2>&1`
- `/uploads/agents-kyc/` has no nginx deny rule. File names are 64-bit random, but adding it
  next to `/uploads/staff/` in the server block is the safer choice.
- Test: `php -c .claude/php-dev.ini tests/master-brief-test.php` (88 checks). The full battery
  shows the same 17 environment failures with and without this build.
