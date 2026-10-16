# WhatsApp Operations Manager — stage 1 (24 Sep 2026)

Owner ko brief (24 Sep): *"S Hari Global AI lai 24/7 WhatsApp-first Company Operations
Manager banau — customer, agent, counter, employee, admin le aafno anumati bhitra ko kaam
Nepali / Hindi / English / Gujarati ma message garera garna sakun. Company ko documents
(profile, policy, CIN/PAN/GST) surakshit rakha, sahi manche lai matra deu, jahan approval
chahincha tyahan manche lai deu."*

Yo file ma: **k banyo, kasari on garne, k test bhayo, k baki cha.** Live (`vps main`)
chhoieko chhaina — sabai kura branch `claude/hari-global-whatsapp-ops-c8iiof` ma cha, ra
har feature **OFF switch** pachhadi cha.

---

## 1. Ek line ma

20–22 Sep ko sahayak (`aiagent.php` + `aitools.php`) le ticket katna, sachyauna, agent/office
lai report dina janthyo. Aba usle **company ko kagaj** (approved documents) padhna ra sahi
manche lai pathauna, **manche lai handoff** garna (SUP- number sanga), ra **staff ko number
verify** garna (paisa ko kaam agadi) janyo — sabai audit sanga.

AI le kunai naya bato bata seat, bhaau, refund lekhdaina. Purano niyam uhi cha: AI le
**kun button** bhanne chhancha, button chai desk kai ho.

---

## 2. K banyo (file anusar)

| File | Kaam |
|---|---|
| `database/upgrade-2026-09-24-wa-ops-manager.sql` | `company_documents` (+ `_versions`, `_access`), `wa_identity_links`, `wa_share_links`; `support_tickets` ma handoff columns; 9 wota setting — **sabai OFF** |
| `includes/companydocs.php` | **CompanyDocs** — documents vault: AES-256-GCM encrypted file (`uploads/company/`), type / sensitivity / audience, approve / archive / expiry / review date, version snapshot, masked summary (PAN, GSTIN, CIN, Aadhaar, passport, account dotted), single-use share link, access trail |
| `admin/company-docs.php` | Office screen: upload, classify, approve, replace, archive, kasle k khole/pathayo herne. Manager / owner le matra lekhne; RESTRICTED owner le matra |
| `admin/company-doc-file.php` | Staff session + role clearance sanga file kholne (decrypt). Log huncha |
| `company-doc-share.php` | Ek document, ek number, 10 minute, 3 fetch — Meta le file yahi bata linchha. Token hashed matra store. Purano/khatam link = 404 |
| `includes/aihandoff.php` | **AiHandoff** — complaint / payment dispute / refund exception / identity / document request / safety / policy exception / approval → `support_tickets` ma **SUP-yymmdd-xxxxx**. Redacted (OTP, password, card hataincha), retry ma euta matra ticket (dedupe 15 min), aafno booking matra attach, office WhatsApp lai khabar (driver bhaye), audit |
| `admin/support-inbox.php` | Support Inbox: filter, Take, Reply (WhatsApp ma pani pathauna milne), Resolve, attachment (evidence) kholne. `support.view` / `support.reply` |
| `includes/aiverify.php` | **AiVerify** — staff/office number ko step-up: ek-choti link `admin/wa-verify.php?t=…` → staff panel ma login bhayera kholnu parchha → tyo account = tyo phone bhaye matra 30 min "fresh". Token hashed, ek choti, 10 min, galat account = burn + security audit |
| `admin/wa-verify.php` | Link kholne page (login pachhi) |
| `includes/wamedia.php` | **WaMedia** — inbound photo/PDF/voice: metadata sanitize, Graph API bata download (size cap, MIME allow-list), encrypted stash (`uploads/wa-inbound/`) as evidence, Gemini bata voice transcript (`wa_ops_voice_on`) |
| `includes/aitools.php` | Naya tools: `company_docs_search`, `company_doc_send`, `handoff_to_staff`, `handoff_status`, `verify_identity`; office lai `marketing_draft/preview/send/status` (wa_marketing_on bhaye). **Gate 5**: step-up (setting `wa_ops_stepup_actions`). Confidential paper = dekhau → arko message ma "ho" → pathau |
| `includes/aiagent.php` | Prompt ma DOCUMENTS / HUMAN HANDOFF / VERIFICATION / SENSITIVE NUMBERS; Gujarati pani; attachment aayo bhane model lai "photo aayo, timi dekhna sakdainau" bhanne data note; marketing line `wa_marketing_on` bhaye matra |
| `includes/wabot.php` | START OFFERS / STOP consent record (marketing on bhaye); voice transcript → "मैले सुनेँ: … ठिक हो?" → "हो" pachhi matra kaam; attachment → assistant (media on bhaye), natra purano fixed reply |
| `whatsapp/webhook.php` | Attachment metadata bot lai; delivery status marketing engine lai pani |
| `whatsapp/api.php` | `sendWhatsAppDocument()` — Meta `document` message |
| `deploy/nginx-shreehariglobal.in.conf` | `/uploads/company/` ra `/uploads/wa-inbound/` deny (owner le VPS ma lagaunu parne) |
| `tests/apply-sql.php` | Guarded ALTER (PREPARE/EXECUTE) chalda "unbuffered queries" error aauthyo — fix. Aba `upgrade-2026-09-24` yo helper bata pani chalchha |
| `tests/company-docs-test.php` | 105 check |
| `tests/wa-ops-manager-test.php` | 65 check |

---

## 3. Kasle ke garna sakcha (role — number bata, pahile jastai)

| Kasle | Documents | Handoff | Step-up |
|---|---|---|---|
| **Customer** | `public` document matra (luggage rule, refund policy, profile…). File pathauna milchha, confirm chahidaina | complaint, payment dispute, refund exception, booking help → SUP- number | kahilyai chahidaina (booking ko number + arko message ma "ho" = purano niyam) |
| **Agent / counter** | + `internal` (procedure, counter guide, agent guide) | + aafno passenger ko kura | `agent_day` (aafno hisab), `cancel_ticket`, `fix_ticket` ma chahinchha (setting anusar) |
| **Manager** | + `confidential` (GST, registration) — verified + "ho" pachhi | sabai | `office_confirm`, `office_day`, `company_doc_send` … |
| **Owner (superadmin)** | + `restricted` (PAN, identity) — verified + "ho" pachhi | sabai | uhi |

Summary ma PAN/GSTIN/CIN **sadhai masked** (`24•••••••••••Z5`). Pura number chai (a) file
bhitra, verified manche lai matra, ya (b) office ko verified number le `company_docs_search`
garda. Kasaile pani chat ma pura number padhera sundaina.

---

## 4. ON kasari garne (owner ko kaam — kram ma)

**Step 0 — test ma pahila.** `vps wip` → `/root/shg-test`:
```bash
mysql shari_test < database/upgrade-2026-09-24-wa-ops-manager.sql
php tests/company-docs-test.php && php tests/wa-ops-manager-test.php
php tests/run-all.php
```

**Step 1 — live ma migration** (owner ko yes pachhi matra):
```bash
mysql shari < database/upgrade-2026-09-24-wa-ops-manager.sql
```
Yo le kehi badaldaina — table banauchha, setting OFF ma halchha.

**Step 2 — nginx** (`deploy/nginx-shreehariglobal.in.conf` ko naya 2 line live conf ma halera
`nginx -t && systemctl reload nginx`). File encrypted cha tara path nai band garne.

**Step 3 — documents halne.** Admin → Settings → **Company Documents**: company profile,
refund policy, luggage rule, routes/boarding points (public); staff procedure, agent/counter
guide (internal); GST/registration (confidential); PAN/identity (restricted, owner le). Har
ek ma **summary** lekhnu — AI le tyo matra bolchha. Approve garnu. **Expiry / review date**
halnu (GST, insurance jasta).

**Step 4 — switch, ek pachi ek:**

| Setting (Admin → Settings → ai) | Kaam |
|---|---|
| `wa_ops_handoff_on = 1` | complaint/dispute → SUP- number → Support Inbox. `wa_ops_handoff_notify` (default 1) le office WhatsApp lai khabar — `whatsapp_driver = cloud_api` chahinchha |
| `wa_ops_docs_on = 1` | approved documents AI le khojchha ra pathaunchha |
| `wa_ops_stepup_on = 1` | staff/office number lai paisa ko kaam agadi link kholnu parne. `wa_ops_stepup_minutes` (30), `wa_ops_stepup_actions` (default: `office_confirm,cancel_ticket,fix_ticket,company_doc_send,agent_day,office_day`) |
| `wa_ops_media_on = 1` | photo/PDF aayo bhane AI lai bhanne + evidence rakhne (Meta token chahinchha). Ek number lai dinko 6 file samma, `wa_ops_media_keep_days` (30) pachhi `cron/rotate.php` le hataunchha (support request le samatirakheko file bahek) |
| `wa_ops_voice_on = 1` | voice note → Gemini transcript → confirm → kaam (`gemini_api_key` chahinchha) |
| `wa_marketing_on = 1` | START OFFERS / STOP record + office lai marketing tools (APPROVED MARKETING template + `wa_marketing_templates` allow-list chahinchha) |

`wa_agent_on` ra API key ta pahile dekhi nai chahinchha (20 Sep ko doc §6).

**OFF garna:** jun switch ho tyo 0. Turantai purano jasto.

---

### Go-live — exact commands (owner ko machine bata, `vps` remote sanga)

Yo container bata VPS (93.127.167.249) ra live site pugdaina (network policy) ra SSH key
pani chhaina — tyesaile deploy owner ko machine bata, ya yo environment ma VPS host allow
+ SSH key secret halera. Kram:

```bash
# 0. branch tanne
git fetch origin claude/hari-global-whatsapp-ops-c8iiof

# 1. shg-test ma pahila (test DB, live chhoidaina)
git push vps claude/hari-global-whatsapp-ops-c8iiof:wip
ssh shari-vps '/root/shg-test-refresh.sh wip'
ssh shari-vps 'cd /root/shg-test && mysql shari_test < database/upgrade-2026-09-24-wa-ops-manager.sql \
  && php tests/company-docs-test.php && php tests/wa-ops-manager-test.php && php tests/run-all.php'

# 2. sabai green bhaye — LIVE (yo push nai deploy ho)
ssh shari-vps 'cd /var/www/shreehariglobal.in/public_html && php cron/backup.php'   # DB backup pahila
git fetch vps && git push vps claude/hari-global-whatsapp-ops-c8iiof:main
ssh shari-vps 'cd /var/www/shreehariglobal.in/public_html && mysql shari < database/upgrade-2026-09-24-wa-ops-manager.sql \
  && chown -R www-data:www-data includes admin company-doc-share.php \
  && find . -name "*.php" -newer CLAUDE.md -print0 | xargs -0 -n1 php -l | grep -v "No syntax" ; \
  curl -s -o /dev/null -w "%{http_code}\n" https://www.shreehariglobal.in/'

# 3. nginx: deploy/nginx-shreehariglobal.in.conf ko /uploads/company/ ra /uploads/wa-inbound/
#    deny line live conf ma halne, tespachhi:  nginx -t && systemctl reload nginx

# 4. Admin → Settings → Company Documents ma kagaj halne, approve garne; switch ek pachi ek (tala ko table)
```

Migration le kunai row badaldaina (CREATE IF NOT EXISTS, guarded ALTER, INSERT IGNORE, sabai
switch OFF) — apply garepachhi pani site ra bot pahile jastai chalchha.

## 5. Test (24 Sep, local `shari_test`, MariaDB 10.11, PHP 8.4)

- `company-docs-test.php` — **123/123**: seal/open + tamper, masking (PAN/GSTIN/CIN/Aadhaar/
  passport/account/password; phone number chhoidaina), kasle file garna sakchha, audience rule,
  role × sensitivity matrix, draft/archived/expired luki, search scope, version snapshot + purano
  file rakhne, share link (3 fetch, expiry, withdraw = dead), tools (switch, role, honest "NOT sent",
  access trail + audit_logs), confidential quote-then-confirm (same turn = refuse, stage single-use),
  step-up (link hashed, galat account = burn + audit, sahi account = fresh, stale pachhi fresh hoina),
  manager le owner ko restricted kagaj id bata chhuna nasakne, confirm=true model le bhane pani manche ko
  aafnai "ho" chahine, wa_agent_oneshot le confidential ko 2-message niyam natodne, +977 sender lai +977 mai
  pathaune, token audit/message_logs ma nabasne, "A/C sleeper" / "account" jasta sabda mask le nabigarne, lowercase
  PAN/GSTIN pani mask, office lai pani chat ma GSTIN masked (file matra pura), re-index le file ko shabda nakhosne.
- `wa-ops-manager-test.php` — **75/75**: handoff switch, SUP- ref, redaction, dedupe, aafno/aruko
  PNR, office alert honesty, status ownership, step-up needs/gate/audit/consume/revoke, catalogue
  by switch & role (marketing pani), media sanitize, fixed replies bahal, voice "हो" replay,
  START OFFERS / STOP (STOP le adhuro booking chat pani band garchha), +977 handoff ko number country code sahit,
  evidence retention sweep, Support Inbox ko internal note customer lai kahilyai napadhine, OTP jasari lekhe pani redact.
- Purano battery: **uhi 62 pass**, uhi 4 purano fail (fares-settings / export-filters — :8899 dev
  server chahine; chalani-png channel label; trip-reminder, whatsapp-retry-policy — baseline ma
  pani fail). `wa-agent-test` 79/79, `ai-kb-test` 27/27, `wa-local-booking` 54/54 — kehi bigreko
  chhaina.
- HTTP smoke (php -S): share link 200×3 → 404; galat token 404; admin page login ma redirect
  (token `next` ma surakshit); `company-docs.php`, `support-inbox.php`, `wa-verify.php` superadmin
  ko naam ma render, POST path chalchha.
- CI ko `php -l` sabai file pass.

**Test hoina (model chahine):** Claude/Gemini le tool sahi kram ma call garchha ki — yo live key
sanga `shg-test` ma 2–3 din hernu parchha (`ai-activity.php`).

---

## 6. K baki cha / owner le garnu parne

1. Live ma migration + nginx line (§4 step 1–2) — **owner ko yes pachhi**.
2. Documents halne ra approve garne (§4 step 3). Bina document `company_docs_search` le
   "approved document chhaina" bhanchha — imandaar, tara kaam lagdaina.
3. `whatsapp_driver = cloud_api` + Meta token: file pathaune ra office alert ko lagi. Twilio /
   click-to-chat ma document send hudaina (AI le spasta bhanchha).
4. Voice ko lagi `gemini_api_key`; Gujarati bolne customer lai model le Gujarati ma jawaf dinchha
   (app UI ma Gujarati nai chhaina — owner ko 19 Sep ko nirnaya uhi).
5. 20 Sep ko purano blocker uhi: `whatsapp_template_name` galat (ticket photo pugdaina).
6. Aghi ko session ko note: `wa-agent-test` etc. sabai green; `ticket-bot-test` ko 2 fail purano ho.

---

## 7. Suraksha ko sar

- Password / OTP WhatsApp ma kahilyai magidaina, pathaidaina. Step-up = staff panel ko login.
- Document file public URL ma kahilyai chhaina: encrypted blob + ek-choti token.
- Model lai file ko content dekhaidaina; summary matra, tyo pani masked.
- Har search / view / send / refuse → `company_document_access` + `audit_logs`.
- Har handoff → `support_tickets` + `support_messages` + audit; OTP/card redacted.
- "Pathayo" bhanne AI le tab matra bhanchha jaba Meta le accept garyo (`sendWhatsAppDocument`
  success). Office lai "khabar garyo" tab matra jaba driver le `true` diyo.
- AI le SQL / shell chalaudaina; sabai tool desk kai function ho.
