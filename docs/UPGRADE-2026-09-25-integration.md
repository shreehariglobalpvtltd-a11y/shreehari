# Char branch eutai ma — integration, 25 Sep 2026

Owner (24–25 Sep): *"sabai branch merge garera, 2 thau upgrade bhayeko kura code herera milayera, deploy gardeu. Arko session ko upgrade lai effect nagaros."*

Live: `f3376e5` → `7bead74` (ops-manager, 24 Sep) → **`7086f44`** (yo integration, 25 Sep 14:10 IST).
GitHub copy: `claude/integration-2026-09-25`.

## 1. K live gayo (sabai naya switch OFF)

| Branch | Live ma aayo | Switch |
|---|---|---|
| hari-global-whatsapp-ops (24 Sep) | company docs vault, Support Inbox (SUP-), step-up, inbound media | `wa_ops_*_on` = 0 |
| mero-ai-ticket-system (wa-manager) | WhatsApp login, bulk ticket, office "people" tools, naam/number pin | `wa_login_on`, `wa_bulk_on` = 0 |
| shreehari-global-upgrade **up to 3ba1a33** | paisa ka 7 guard, session revalidate, secrets write-only, "Mero bus kaha?" (`track.php`), route pages `/bus/…`, SEO title, CI | `seat_events_on`, `contact_dial_on`, `commission_agent_only` = 0 |
| ai-website-analytics (AI Sahayak Pro) | website/app chat with tools, reports + chart, Admin → 🤖 AI Sahayak, chat panel brand colours | `ai_web_agent_on`, `ai_web_sell` = 0 |
| shg-app-ui-ux (UI v3) — **feature matra** | seat-status card (Bus Chalan), Promo card, "Verification pending", +91/+977 phone check, edit pachhi ticket REV + redraw | `seat_status_wa_on` = 0 |

Switch bina nai chalne (bug fix): paisa guard M1, M3–M7; admin session har request ma check; settings ma key haru lekhna matra; wa-pending counter lai aafno matra; CSV fix; route pages + sitemap; confirmed ticket ma "bus live herne" link; phone number ko shape check (customer matra); ticket edit garda naya QR + turuntai redraw.

## 2. Dui thau bhayeko kaam — kasari milayo

| Dohoro | Rakheko | Hatayeko / kina |
|---|---|---|
| 11 WhatsApp-sheet i18n label | live (premium) | branch ko copy — pahile nai thiyo |
| Contact button: live WhatsApp FAB + SOS + AI, upgrade ko 📞 dial, UI v3 ko Call sheet | live ko button | dial = `contact_dial_on` pachhi, ON garda WhatsApp FAB ko thau linchha (SOS ko thau hoina); UI v3 Call sheet hatayo |
| Complaint desk: Support Inbox (SUP-) vs UI v3 SHG-C | Support Inbox | SHG-C complaints (table, page, API) — eutai kaam |
| Theme: premium (live, approved) vs AI Sahayak gradients vs UI v3 (Poppins/Mukta, splash, header, home cards) | premium | AI Sahayak ko hero/button/footer gradient (premium ko contrast-checked CTA thichthyo); UI v3 ko sabai redesign (`ux.css`, `19-ux.js`, fonts) |
| Home 3 booking card | live (home-entry) | UI v3 channel cards |
| Ticket edit pachhi WhatsApp | live `bv_notify_edit` ("ke badliyo" sahit) | UI v3 ko auto re-send — customer lai 2 message janthyo |
| Nepali PNG | DevShape (HarfBuzz) | seat-status / promo card pani DevShape ma |
| AiAgent::handle() ko 4th argument (attachment vs who) | eutai `$extra` array | — |
| +977 number (`intl` vs `country`) | dubai (pathaune number / booking ma chhapne) | — |
| AI prompt: live ko bhasa/TONE niyam vs web/WhatsApp split | split + live ko niyam WhatsApp bhag ma | — |
| Report chart: ReportChart ("report" shabda, AI bina) vs AiChart (AI tool) | dubai — farak dhoka | — |

## 3. Test

Isolated VPS copy (`/root/shg-int`, DB `shari_int_test`, ref `int-wip` — `shg-test`/`shari_test`/`wip` chhoyeko chhaina):
`php tests/run-all.php --http` → **104 passed, 0 failed**; node suites (i18n 803 keys, offline 21, seat-label 17, lazy) green locally.
Live smoke: `/`, `/admin/login.php`, `/bus`, `/sitemap.xml`, `/robots.txt` = 200; `/uploads/company/…`, `company-doc-share.php?t=bad` = 404; 11 admin page render; naya PHP error 0.

## 4. Owner le garne (kram ma)

1. `commission_agent_only` — counter / office ko bikri agent ledger ma jana dine ki nadine: **tapai ko hisab ko nirnaya** (aile OFF = pahile jastai).
2. Switch ON (ek pachi ek): `ai_web_agent_on` (Claude/Gemini key chahinchha), `wa_login_on`, `wa_bulk_on`, `seat_status_wa_on`, `contact_dial_on`, `seat_events_on` (ON garnu agadi `deploy/nginx-shreehariglobal.in.conf` ko `/api/seat-events.php` block live nginx ma halnu), `wa_ops_*` (doc 24 Sep §4).
3. Arko session (`claude/shreehari-global-upgrade-myl45u`, AI Manager v2) aile pani chaldai chha — tyasko kaam live ma chhaina; deploy agadi yo integration merge garnu parchha. Tyasko "office copilot" ra AI Sahayak dohorina sakchha.

## 5. Rollback

Live worktree (`/var/www/shreehariglobal.in/public_html`) ma, purano tree lai naya commit banaune (history metidaina):

```bash
git read-tree -u --reset 7bead74 && git commit -m "rollback: back to 7bead74 (before the 25 Sep integration)"
chown -R www-data:www-data .
```

Pura backup: site `/root/backups/pre-integration-site-20260925-1408.tar.gz`, DB `backup/backup_20260925_140805.sql.gz`, nginx `/root/backups/nginx-shreehariglobal.in.pre-integration-20260925.conf`. Migration haru additive matra (naya table / column / setting) — rollback garda DB chhoddaa pani hunchha.
