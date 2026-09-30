# WhatsApp sahayak = company ko manager — 24 Sep 2026

Owner ko bhanai (24 Sep): *"Mero shreehariglobal.in ko AI lai powerful banau. Naam, mobile
number ma mistake nahos, ticket katne fully independent. Agent haru lai ni WhatsApp bata ticket
katna deu, bulk ticket support garos — euta format bata ek click ma ticket aaos. Agent ra counter le
jati pani ticket katun, edit garun, cancel garun, manage garun, aafno account ko commission herun.
Admin le tyo sabai + agent ra customer maathi full power — jun Admin portal ma milcha. Login system
ni hos, WhatsApp bata password/ID le. Employee lai bot le hamro company ko manager jasto kaam garos."*

Yo file ma: k banyo, kasari ON garne, k dhyan dinu parne. Sabai kura **live cha tara OFF cha** —
20 Sep ko sahayak (docs/UPGRADE-2026-09-20-wa-agent.md) jasto chalirahos, switch on nagarunjel
kehi badlidaina.

---

## 1. Ek line ma

| Owner le mageko | K banyo |
|---|---|
| naam, number ma mistake nahos | quote ma naam + number **pin** huncha, tehi padhera "ho" bhanepachi matra ticket; pin bhanda farak naam/number aayo bhane bikri **refuse**; "Mehsana", "ho", "2 seat" jasto kura naam ma jaana **sakdaina**; +977 harauna sakdaina |
| agent lai WhatsApp bata ticket, bulk | **FORMAT** lekhepachi template aaucha; list paste garne → sahayak le sabai naam/number/bhaau padhera sunaucha → euta **"ho"** ma sabai ticket katcha, harek ticket yatru kai WhatsApp ma, commission agent ko wallet ma |
| agent/counter le jati pani ticket, edit, cancel, manage | `my_sales` (aafnai bikri), `rename_passenger`, `quote_ticket_fix` + `fix_ticket`, `resend_ticket`, `refund_quote` + `cancel_ticket` — aafnai bikri ma matra |
| aafno commission herna | `my_wallet` — commission due, yo mahina, cash due, KYC, limit, payout request; `request_payout` (switch on garepachi) |
| admin lai sabai + agent/customer maathi power | `office_agent`, `office_agents`, `office_customer`, `office_payout_requests`; ra (write switch on garepachi) `office_settle_cod`, `office_reject`, `office_agent_status` — sabai **2 step** (preview, "ho", confirm) |
| WhatsApp ma login | `login SHG-0027 <password>` → (code) → staff ban-cha; `logout`; `ma ko hu`; Admin → AI Activity ma ko-ko signed in cha dekhincha, revoke garna milcha |
| bot = employee ko manager | staff sanga kura garda sahayak "branch manager ko awaj" ma bolcha: aaja ko hisab, cash bujhauna baaki, KYC, payout — aafai bhancha |

---

## 2. Naam ra number kasari surakshit bhayo

Pahila: model le naam lekheko thiyo, code le ₹ matra check garthyo. Aba **code le naam pani check garcha**:

- `includes/personname.php` — "yo naam ho ki hoina?" ek thau ma. Digit bhayo, bus stop bhayo (Mehsana,
  Surat, Rupaidiha…), "ho / thik cha / yes" bhayo, "Female" matra bhayo → naam **hoina**, ticket ma
  jaana paudaina. `ram thapa` → `Ram Thapa` (border manifest ma sano akshar galat jasto dekhincha).
- `plan_ticket` ma naam ra number dina milcha → quote sangai **pin** huncha. `issue_ticket` /
  `staff_sell` le pin bhanda farak naam ya number lyayo bhane **refuse** garcha ra feri quote garna
  bhancha. Euta quote le **euta matra** ticket katcha (pahila quote parked rahanthyo — dosro "ho" ma
  feri bikri hune bug thiyo, aba consumed huncha).
- Number: 10 digit nai hunuparcha, 9 digit ma umer joddaina (`987654321 2` → refuse, join hoina).
  Sender ko `+977` / `+91` webhook bata padhincha (`whoIs()['country']`) ra booking ma
  `contact_country_code` stamp huncha — pahila 10 digit banayepachi Nepali number ko ticket +91 ma
  jaanthyo. `staff_sell` ma pani `+977 98…` ya `country: NP` dina milcha.
- Local booking engine (`WaBooking`) le pani bechnu agadi PersonName sodhcha.

---

## 3. Bulk ticket (agent/counter, `wa_bulk_on`)

Agent le **FORMAT** lekhcha, template aaucha:

```
TICKET
Date: 5 Oct
From: Mehsana
Pay: cash
1. Ram Thapa 9876543210 M
2. Sita Thapa 9876543211 F 32
3. Maya Thapa 9876543211 F 8
4. Hari Gurung +977 9812345678 M
```

- Ek line = ek yatru: naam, ani 10 digit mobile. M/F, umer optional.
- **Eutai number dui line ma = eutai booking** (pariwar) — harek berth ma aafnai naam.
- Nepali number ma `+977` lekhne.
- `Date:` bholi / 5 Oct / 2026-10-05 / 5/10 — sabai bujhcha. `From:` chadne thau. `Pay:` cash/upi/esewa/bank.
- Key nabhaye pani "bholi Mehsana bata" jasto line bujhcha.

Sahayak le **BULK QUOTE** pathaucha — harek naam, number, seat, bhaau, jamma; galat line number
sahit bhancha ("लाइन 4: मोबाइल नम्बर मिलेन"). Agent le **thyakkai "ho"** lekhepachi matra sabai
booking `QuickTicket::sell()` bata katincha — desk ko jastai seat lock, cut-off, bhaau, commission —
harek ticket yatru kai WhatsApp ma. "no" le radda. "ho tara line 2 galat cha" jasto kura **yes
hoina**: quote khasin-cha, sachyaera feri paste garnus. Quote khulla hunda arko kunai kura lekhyo
bhane pani quote khasin-cha (pachi ko "ho" cancel/fix ko lagi bhayo bhane list nabikos bhanera).

Suraksha: list **code le padhcha, model le hoina** (12 naam ko list ma model le 2 number saatidina
sakcha). Quote **pin** huncha — "ho" aaunda kunai booking ko bus/din/pickup/bhaau badliyeko cha bhane
tyo booking refuse, aru katincha. "ho" le quote **kharcha garcha** — dosro "ho" le kehi katdaina.
Ek sandesh ma `wa_bulk_max_rows` (30) yatru samma. Customer lai yo bato **chhaina**.

Model lai pani `bulk_quote` / `bulk_issue` tool cha (tehi code), tara wabot.php le staff ko list
model bhanda pahila nai code bata samatcha.

---

## 4. WhatsApp login (`wa_login_on`)

Staff record ma bhako number bata lekhda pahila dekhi nai staff chinincha. Aba **arko number** bata pani:

```
login SHG-0027 mypassword      (agent code, username ya email + password)
otp 482913                     (code registered mobile ma aaucha)
logout  ·  ma ko hu
```

- Tehi `admins.password_hash`, tehi generic failure (galat password, unknown id, inactive, locked —
  sabai euta line, kinaki agent code ticket ma chhapiyeko huncha), LoginLog, audit. WhatsApp ko
  guess le website ko lock (`failed_logins` / `locked_until`) **chhudaina** — natra ticket bhako
  jo koi le agent lai portal bata lock garna sakthyo. Guess ko budget aafnai: 5 per number, 5 per
  account, 15 minute.
- **Dosro factor**: `wa_login_otp` on (default) bhaye agent lai pani registered mobile ma code
  jaancha; **manager/superadmin lai sadhai** code chahincha, setting jasto bhaye pani. Aafnai
  registered number bata login garda code chahidaina.
- Session = `wa_logins` table ko row, **+91/+977 sahit** ko number ma (`9779812345678`) — +91 98… ra
  +977 98… euta session share gardainan; `wa_login_ttl_hours` (12) pachi aafai sakincha; harek message
  ma account feri check huncha (inactive/lock/must_change_pw bhaye turantai customer). Office le
  **Admin → AI Activity → WhatsApp sign-ins** ma herera **Revoke** garna sakcha. Agent deactivate
  garda uska sabai WhatsApp login pani revoke.
- Password bhako message **sabai bhanda pahila** wabot.php le samatcha — model, drafts, log kahi
  jaadaina; reply le tyo message metauna bhancha. Switch OFF bhaye pani "login …" line swallow
  huncha (password agadi jaadaina), "band cha" bhancha.
- must_change_pw bhako (office le dieko temporary password) khata le WhatsApp bata login garna
  paudaina — pahila website ma badalnus.

---

## 5. Staff ra office ko naya tools

**Staff (agent/counter):** `my_sales` (aafnai bikri, PNR/naam/number/din/seat/status; `date` diye
tyo din), `my_wallet` (commission due, mahina, lifetime, cash due, cash limit, KYC, daily limit,
deposit, khula payout request, pachhillo 6 ledger), `request_payout` (`wa_agent_payout` on, agent
role matra, 2 step). Staff ko briefing: *"You are their manager's voice"* — greeting ma aafai
`agent_day` + `my_wallet` bolayera 3 line ma din bhancha, euta reminder (cash limit, KYC, payout,
deposit). Bikri: `plan_ticket` ma naam+number → "ho" → `staff_sell`. Jati pani — ek pachi ek.

**Office (manager/superadmin):** `office_agent` (code/naam/number bata — din, wallet, cash due,
pachhillo bikri, khula payout), `office_agents` (sabai — aaja ko ticket, commission due, cash due),
`office_customer` (number bata — trip, kharcha, aaune yatra, pachhillo booking, kasle becheko),
`office_payout_requests`. Padhne tool sadhai. **Write** (`wa_agent_admin_write` on):
`office_settle_cod` (cash bujhiyo), `office_reject` (pending booking reject, karan sahit),
`office_agent_status` (agent on/off — aafai lai ra superadmin/manager lai hoina) — **sabai 2 step**:
preview → office le arko message ma **thyakkai "ho"** → confirm. Model ko confirm flag matra le
kehi hudaina — office ko aafnai message "ho" hunuparcha (`request_payout` ra `bulk_issue` ma pani). Office le jun pani booking `cancel_ticket`,
`rename_passenger`, `fix_ticket`, `resend_ticket` garna sakcha (pahila dekhi nai).

Staff/admin le WhatsApp bata lekhda aba **local customer engine skip huncha** (sahayak on bhaye):
pahila agent le "bholi 2 seat" bhanda agent kai number ma customer ticket katinthyo.

---

## 6. ON kasari garne (owner ko kaam)

**Step 1 — database** (ek choti):

```bash
php tests/apply-sql.php database/upgrade-2026-09-24-wa-manager.sql
```

**Step 2 — Admin → Settings → `Ai` panel**, yo kram ma, hatar nagari:

| Switch | Default | Kahile on garne |
|---|---|---|
| `wa_agent_on`, `wa_agent_sell` | OFF | 20 Sep ko doc anusar — pahila padhne, ani bechne |
| `wa_bulk_on` | OFF | agent haru lai list bata ticket katna dina. Pahila 2-3 agent lai FORMAT sikaunus |
| `wa_bulk_max_rows` | 30 | ek sandesh ma kati yatru samma |
| `wa_login_on` | OFF | staff le arko number bata login garna. `wa_login_otp` ON nai rakhnus |
| `wa_login_ttl_hours` | 12 | login kati ghanta chalne |
| `wa_agent_payout` | OFF | agent le WhatsApp bata payout magna |
| `wa_agent_admin_write` | OFF | office le WhatsApp bata cash bujhne, reject, agent on/off garna |

**OFF garna:** tyo switch 0. Turantai purano bewahar fercha.

**Live ma lagne (deploy) — VPS ma, owner le:**

```bash
# 1. live folder ma (LIVE cha — git status pahila)
cd /var/www/shreehariglobal.in/public_html      # ya jun folder live cha
git status
git fetch origin claude/mero-ai-ticket-system-keatp1
git merge --ff-only origin/claude/mero-ai-ticket-system-keatp1   # ya: git checkout main && git merge ...

# 2. database (ek choti, additive — kehi mettidaina)
mysql shari < database/upgrade-2026-09-24-wa-manager.sql

# 3. file owner ra syntax
chown -R www-data:www-data includes/personname.php includes/walogin.php includes/wabulk.php \
      database/upgrade-2026-09-24-wa-manager.sql docs/UPGRADE-2026-09-24-wa-manager.md tests/wa-*.php
for f in includes/aitools.php includes/aiagent.php includes/wabot.php includes/wabooking.php \
         includes/quickticket.php includes/walogin.php includes/wabulk.php includes/personname.php admin/ai-activity.php; do php -l $f; done
curl -s -o /dev/null -w "%{http_code}\n" https://www.shreehariglobal.in/     # 200 aunuparcha

# 4. test DB ma battery (live DB ma kahilyai hoina)
cd /root/shg-test && php tests/wa-login-test.php && php tests/wa-bulk-test.php && php tests/wa-manager-tools-test.php
```

Deploy pachi pani **sabai switch OFF** hunchan — maathi ko table anusar ek-ek on garnus.

**Ek click deploy — GitHub Actions bata (`.github/workflows/deploy.yml`, manual matra):**

1. GitHub → repo → Settings → Secrets and variables → Actions → New repository secret:
   `VPS_SSH_KEY` (laptop ko `~/.ssh/shg_deploy` file ko pura text), `VPS_HOST` (93.127.167.249),
   `VPS_USER` (root), `VPS_PATH` (live folder, jastai `/var/www/shreehariglobal.in/public_html`).
   Key chat ma kahilyai nahalnus — GitHub ko secret ma matra.
2. GitHub → Actions → **Deploy to VPS** → Run workflow → branch `claude/mero-ai-ticket-system-keatp1`,
   mode **dry** → herne k k jaancha (kehi badlidaina).
3. Thik lagyo bhane feri Run workflow → mode **full**, migrate = `database/upgrade-2026-09-24-wa-manager.sql`.
   Yesle: file sync (deploy.sh, config/uploads/tickets/tests chhudaina) → server ma `php -l` + `chown www-data`
   → DB backup → migration → site HTTP 200 check.

Yo sandbox (Claude ko cloud environment) bata sidhai deploy garna mildaina: SSH client, VPS key, ra
VPS ko port 22 tinai chhainan — tyasaile maathi ko workflow banaieko ho.

> Pahile jastai: WhatsApp ticket template ko naam milaunu (docs/UPGRADE-PLAN-2026-09-19.md, OWNER
> ACTION) — nabhaye bulk le pani ticket katcha tara photo yatru samma pugdaina.

---

## 7. Test

Tin naya suite, `tests/run-all.php` ma registered, `shari_test` ma sabai pass (24 Sep):

| Suite | Check | K hercha |
|---|---|---|
| `tests/wa-login-test.php` | 72 | switch off ma password swallow; galat password, unknown id euta line; failed_logins/lock; code registered number ma; manager lai sadhai code; session expire/revoke/logout/deactivate; 5 try pachi wait |
| `tests/wa-bulk-test.php` | 73 | naam/number tehi; +977; pariwar euta booking; galat line number sahit; 9 digit + umer join hoina; bus stop naam hoina; customer lai chhaina; quote le bechdaina; "ho" le sabai bechcha, yatru kai number ma, agent lai credit; dosro "ho" le kehi hoina; bhaau badliyo bhane refuse; tool 2-message rule |
| `tests/wa-manager-tools-test.php` | 91 | PersonName; role anusar catalogue; naam/number pin ra refuse; euta quote euta bikri; +977 stamp; my_sales/my_wallet aafnai; request_payout 2 step; office_agent/agents/customer/payout_requests; settle_cod/reject/agent_status 2 step, aafai lai hoina |

`tests/wa-agent-test.php` (79) pahila jastai pass.

---

### File haru

| File | Kaam |
|---|---|
| `database/upgrade-2026-09-24-wa-manager.sql` | `wa_logins` table + 6 settings (sabai OFF / default) |
| `includes/personname.php` | naam ho ki hoina — ek niyam sabai bato ko lagi |
| `includes/walogin.php` | WhatsApp login: command, password, code, session, revoke |
| `includes/wabulk.php` | list padhne, quote, "ho" ma bikri |
| `includes/aitools.php` | `whoIs()` ma session + country; pin; `my_sales`, `my_wallet`, `request_payout`, `bulk_*`, `office_*`; quote consumed on sale |
| `includes/aiagent.php` | naam/number niyam, staff = manager ko awaj, office ko people tools, login note |
| `includes/wabot.php` | login sabai bhanda pahila; staff ko list WaBulk lai; sender country; staff lai local engine skip |
| `includes/wabooking.php` | sender country; naam check |
| `includes/quickticket.php` | country booking contact ma |
| `admin/ai-activity.php` | WhatsApp sign-ins panel + Revoke |
