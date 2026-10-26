# WhatsApp ma AI sahayak — 20 Sep 2026

Owner ko bhanai (20 Sep): *"10 sec ma ticket nikalne bot mero WhatsApp API number sanga
jodnu paryo. 1–2 message mai customer ko ticket katos, agent lai data herna help hos,
admin lai all-rounder help garos, har kura Nepali ma jawaf deos, ani aayeko ticket
rewrite ni garna milos. Gemini ra Claude dubai ko API cha."*

Yo file ma: k banyo, kasari on garne, ani k k dhyan dinu parne.

---

## 1. Ek line ma

Pahila WhatsApp bot le **padhne** matra sakthyo — PNR ko status, ra ek draft jun desk le
haat le confirm garnu parthyo. Aba tyo bot sanga **haat** cha: usle aafnai register bata
padhcha ra desk kai button thicha — ticket katcha, naam sachyaucha, agent ko hisab
dekhaucha, office lai din ko report dincha. Sabai Nepali ma.

**Tara ek kura clear cha:** AI le kunai naya code bata seat lekhdaina. Jun function
counter ko screen le chalauncha, teii function AI le chalaucha — `QuickTicket::plan()`,
`QuickTicket::sellCustomer()`, `BookingService::cancel()`. Tyesaile seat lock, cut-off,
fare, cap ra audit — sabai uhi ho. AI le matra **kun button** bhanne chhancha.

---

## 2. Customer sanga kasto dekhincha (2 message ma ticket)

```
Customer:  bhai bholi 2 seat chahiyo, Mehsana bata
Sahayak:   🙏 नमस्ते! भोलि (शुक्र, ३ अक्टो) को बस — Mehsana बाट बेलुका ८:०० मा।
           सिट: L7, L8 · जम्मा ₹4,000 (बसमै तिर्न मिल्छ)
           नाम भन्नुहोस् र "हो" लेख्नुहोस्, म टिकट पठाइदिन्छु।

Customer:  ho, Sita Thapa
Sahayak:   ✅ टिकट बन्यो! PNR SHG-2026-02651 · सिट L7, L8
           Mehsana बाट बेलुका ८:०० — २० मिनेट अगाडि पुग्नुहोला।
           [ticket ko photo yahi chat ma]
```

**Kina 2 message?** Paisa ra seat ko kura ho. Pahilo message ma bot le **bhaau
bhancha** (quote), dosro message ma customer le "ho" bhanepachi matra ticket katcha.
Quote ma din, pickup, seat sankhya ra total pin garincha — feri check huncha, farak
bhaye ticket katdaina. Yesle "galat bhaau ma ticket kateyo" bhanne kura hunai
sakdaina.

Chahiye bhane `wa_agent_oneshot` on garera 1 message mai katne banauna sakincha —
tara **maile recommend gardina**: customer le bhaau napadhikai seat liinchha.

---

## 3. Kasle ke sodhna sakcha (role — number bata chincha)

Number `admins` table sanga milaincha. Message ma "ma admin hu" lekhera kehi hundaina.

| Kasle | Ke garna sakcha |
|---|---|
| **Customer** (jun pani number) | ticket herne, bhaau sodhne, ticket katne, naam sachyaune, ticket feri mangne, payment QR, cancel + refund, bus kaha pugyo |
| **Agent / counter** (`admins.role = agent`) | maathi ko sabai + **aafnai** din ko hisab (ticket, seat, commission, cash), **aafnai** passenger list pickup anusar, passenger ko naam-number bata ticket bechne |
| **Office** (superadmin/manager/...) | pura company ko din — ticket, revenue, kun bus kati bhariyo, pending payment, alert, jun pani booking khojne |

Customer le arko ko PNR pathayo bhane **status matra** paucha — naam, seat, paisa kehi
dekhdaina. Agent le arko agent ko book kahile dekhdaina.

---

## 4. Ticket rewrite (naam sachyaune)

Border ma naam galat bhayo bhane thulo samasya huncha. Tyesaile:

- customer le **aafnai** ticket ko naam sachyauna sakcha, WhatsApp bata
- **ek booking ma 2 choti samma** (tyespachi office ma matra — ticket bechbikhan na hos bhanera)
- bus **uddnu agadi** matra
- din, seat, bus, bhaau **kehi pani badlidaina** — naam matra
- ticket feri banincha (`CORRECTED · REV n` lekhera aaucha) ra naya photo turantai jancha
- `audit_logs` ma `booking.rename_whatsapp` bhanera record huncha

---

## 5. "Mero data le train hos" — yo kasari bhayo

Kunai model train bhayeko chaina, ra kunai data bahira pathaiyeko chaina. Data
**tyahi belaa padhincha** — jun bela prashna aaucha:

- aajako route, aajako bhaau, aajako refund rule (`ai_system_prompt()` — website ko
  sahayak le padhne tehi tables)
- tyo booking ko seat, tyo bus ma kati seat bachyo — live
- tyo agent ko aafnai ledger

Kina yo tarika ramro? Gaeko mahina train gareko model le **gaeko mahina ko bhaau**
bhancha, bishwas sanga. Yo sahayak le bhaau bokekai chaina — sodhepachi register
bata padcha. Tyesaile galat bhanna sakdaina.

---

## 6. ON kasari garne (owner ko kaam)

Sabai kura **live cha tara OFF cha**. Yo kram ma garnu:

**Step 1 — database** (ek choti matra):

```bash
php tests/apply-sql.php database/upgrade-2026-09-wa-agent.sql
```

**Step 2 — Admin → Settings → `Ai` panel ma key halne:**

| Setting | Ke halne |
|---|---|
| `anthropic_api_key` | Claude ko API key (sk-ant-…) |
| `ai_agent_model` | `claude-sonnet-5` (default — chhito ra tool ramro chalaucha) |
| `gemini_api_key` | Gemini ko key — Claude nachaleko bela yo chalcha |
| `ai_provider` | `auto` (Claude pahila, feri Gemini) |

**Step 3 — bistarai on garne. Ek pachi ek, hatar nagarnu:**

1. `wa_agent_on = 1` — sahayak bolna suru garcha (padhne matra: status, bhaau, ETA,
   agent/office ko report). **Ticket katdaina.** 2–3 din yehi ma chalaunu, `ai_agent_calls`
   table herera k k sodhyo hercha.
2. Thik cha bhane → `wa_agent_sell = 1` — aba ticket katna thalcha (2 message ma).
3. Office le WhatsApp bata payment confirm garna chahyo bhane → `wa_agent_admin_write = 1`.
   (Yo nabhaye pani office le padhna sabai sakcha.)

**OFF garna:** `wa_agent_on = 0`. Turantai 19 Sep kai purano bot fercha — kehi bigrdaina.

> ⚠️ **Pahila yo milaunu:** ticket WhatsApp ma pathaune kaam ahile **live ma fail
> bhairacha** — `whatsapp_template_name` galat cha (Meta le "shg_ticket_hi_v1 does not
> exist in hi" bhancha). AI le ticket katyo pani photo pugdaina jaba samma tyo
> template ko naam milcha. Hernus: `docs/UPGRADE-PLAN-2026-09-19.md` ko "OWNER ACTION".

---

## 7. Kharcha ra suraksha

- **Rate limit:** ek number lai dinko 40 jawaf (staff lai 5 guna), 5 minute ma 15.
  Manche le kahilyai bhetdaina; loop le bhetcha.
- **Tool budget:** ek message ma badhima 6 tool call (`wa_agent_max_tools`).
- **Sabai record huncha:** `ai_agent_calls` table ma — kasle, kun role ma, kun tool,
  bhayo ki bhayena, kati millisecond, kun booking. Refusal pani record huncha.
  Customer ko message tyaha rakhiddaina (tyo `message_logs` mai cha).
- Sahayak le **kahilyai** OTP, card number, CVV, password, citizenship number
  magdaina — prompt ma spasta manaa cha.
- Bare PNR aayo bhane AI lai pathaidaina — purano turantai jawaf dine bato jancha
  (chhito ra sittai).

---

## 8. Test

`tests/wa-agent-test.php` — 79 check, sabai pass (`shari_test`, 20 Sep):
role chinne, catalogue role anusar sano huney, off bhaeko tool naam lera pani na-chalne,
quote-ani-confirm (ek hi message ma bechna nadine), arko ko PNR ma naam/seat/paisa
nachuhine, naam sachyaune 2 choti matra, cancel ma refund pahila, agent ko scope,
office ko figure, ra audit row.

`tests/run-all.php` ma registered cha.

---

## 9. Aba k garna sakincha (chahiye bhane bhannus)

- **Admin ma "AI le k garyo" screen** — ahile `ai_agent_calls` SQL bata matra herincha.
- **Voice message** — WhatsApp ma voice aayo bhane transcribe garera tehi ticket katne.
- **Bot le aafai message pathaune** — bus late bhayo, bus bharincha jasto cha bhane
  aafai khabar dine (ahile sahayak le **jawaf matra** dincha, aafai lekhdaina).
- **Office lai bihana ko digest WhatsApp ma** — `cron/daily-summary.php` sanga jodne.

---

### File haru

| File | Kaam |
|---|---|
| `database/upgrade-2026-09-wa-agent.sql` | `ai_agent_calls` table + settings (sabai OFF) |
| `includes/aitools.php` | 17 wota tool, 4 wota gate, audit row |
| `includes/aiagent.php` | model loop, Claude + Gemini, Nepali bolne niyam, memory |
| `includes/wabot.php` | vakya → sahayak, khali PNR → purano chhito bato |
| `includes/quickticket.php` | `sell()` le seller row pahila padcha (agent ko sale agent kai ma jancha) |
| `tests/wa-agent-test.php` | 79 check |
