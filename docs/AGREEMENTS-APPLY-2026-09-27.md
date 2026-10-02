# Agreement applications and the "Agreements & agents" page — 27 Sep 2026

## Chhoto ma (saral Nepali)

- Website ko menu ma **"एजेन्ट / साझेदार सम्झौता"** thapiyo, ra home page ko tala **"एजेन्ट वा साझेदार बन्नुहोस् — आवेदन दिनुहोस्"** button chha. Tyo thichda `/agreements/` khulchha.
- Manchhe le samjhauta padhchha, form bharchha ra pathaunchha. Office ma **AGR-000123** jasto number sahit aawedan aauchha, ra office ko WhatsApp ma euta line aauchha.
- Admin ma **Agents → Agreements & KYC** (wa Dashboard → *Agreements & agents*) kholnuhos. Naya aawedan, KYC baki, samjhauta baki, sign bhayeko — sabai eutai page ma.
- Public list ma **naam, kisim (agent / partner), thau, phone matra** dekhinchha. Phone lukeko hunchha (98•••••123) — manchhe le "dekhauna hunchha" bhaneko bhaye matra pura number.
- Status, KYC, samjhauta badalna **super-admin wa manager** matra milchha.

## What changed

| Where | What |
|---|---|
| Website, phone menu | "एजेन्ट / साझेदार सम्झौता · Agent agreements" → `/agreements/` (the footer already had "Agent agreements"). |
| Website, home | One card at the foot of the visible home, just above "About S Hari Global & the route": "Become an agent or partner — apply now" → `/agreements/`. Nepali / Hindi / English. The three booking doors at the top are unchanged. |
| `/agreements/` pages | Send the filled form to `POST /api/agreement-apply.php` (built by the design stream against the contract below). |
| Admin | New page `admin/agreements.php` — **Agreements & agents**. Linked from the Agents register toolbar (🤝 Agreements & KYC) and the dashboard's quick actions. |
| Public list | `GET /api/agent-directory.php` — names and numbers only, for the agreement pages. |
| Database | `database/upgrade-2026-09-27-agent-applications.sql` — new table `agent_applications`, five new columns on `admin_profiles`. No settings switch. |

## What the office sees

Open **Admin → Agents → 🤝 Agreements & KYC**. Anyone who holds `customers.view` can look (super-admin, manager, support, counter staff). Only a **super-admin or a manager** can change anything; a change is a POST with the page's security token, and every change is written to the activity log.

**Top cards**

- **New applications** — sent from the website, not looked at yet (tap to filter).
- **KYC pending** — applications whose ID is not yet checked, plus active agents with no KYC or KYC waiting.
- **Not signed yet** — applications and active agents without a signed agreement.
- **Signed / approved** — applications approved or with a signed agreement, plus agents with a signed agreement.
- **Agents & partners** — active agent accounts (partners = organization agents, agents = individual agents).

**Tab 1 — Applications.** Search by name, mobile, AGR number or area; filter by status and by kind. **Open** shows everything the person filled in, in plain words (organization type and ID type as words, not numbers), the photo, the bank details and the witnesses, and a form to change:

- **Status** — New → Reviewing → KYC pending → Agreement pending → Approved, or Rejected.
- **KYC** — Not given / Submitted / Verified / Rejected. Mark **Verified** only after seeing the original ID.
- **Agreement** — Not sent / Sent / Signed / Expired, with **Signed on**. The end date is worked out: signed + 11 months (31 March → end of February). "Signed" with no date = today.
- **Agent account** — link the application to the agent's login account (see below).
- **Office note** — e.g. "called on 28 Sep, coming Monday with the original citizenship".

**Tab 2 — Existing agents & partners.** Every agent account with its KYC (checked on Agent 360 — tap the name), agreement status and signed date, and two public-list switches: **Listed** (on the public list or not) and **Full** (show the full phone instead of the masked one). Inactive accounts are shown faded and are never on the public list.

## Statuses, one line each

| Field | Values | Meaning |
|---|---|---|
| `status` | new, reviewing, kyc_pending, agreement_pending, approved, rejected | Where the application stands. Only **approved** applications appear on the public list. |
| `kyc_status` | none, submitted, verified, rejected | A new application starts at **submitted** (the form carries the ID details). Only **verified** shows the public "KYC verified" badge. |
| `agreement_status` | none, sent, signed, expired | Only **signed**, with an end date still ahead, shows the public "Agreement signed" badge. |

## Privacy — what the public list shows

`/api/agent-directory.php` returns, for each person, exactly: **name, kind (agent or partner), area, phone, verified (yes/no), signed (yes/no)**. Nothing else ever leaves: no ID number, address, bank detail, email, photo, date of birth or any other status.

- The phone is **masked** — first 2 and last 3 digits, e.g. `98•••••123` — unless the person agreed to show it (the tick box on the application) or the office ticked **Full** for an existing agent.
- Who is on it: **approved** applications that are not yet linked to an account, and **active** agent accounts with **Listed** on. Once an application is linked to an account, the account's own switches decide.
- Every existing agent starts **Listed, phone masked**. The office should untick **Listed** for accounts that are not real public agents (for example the company's own account or a test account).
- The photo is stored in the database, not as a file under `/uploads` (nginx serves that folder to anyone). It is shown only inside the admin page.

## Protection on the apply form

- Needs the page's session token (419 without it).
- 5 sends an hour per internet address, 3 a day per phone number (429 after that).
- A hidden "website" box: if it is filled, it is a robot — it gets a normal-looking answer and nothing is saved.
- The same phone and kind again within 24 hours gets the first AGR number back instead of a second application.
- Only the fields the agreement pages really have are stored, each cut at 300 characters. A photo is kept only when it really is a JPEG / PNG / WEBP of at most 600 KB; otherwise it is dropped and the person is asked to bring one.

## How to turn a person into an agent account

1. Open the application, call the person, check the original ID → set **KYC: Verified**, **Status: Agreement pending**.
2. Sign the agreement (print from `/agreements/` or the PDF) → **Agreement: Signed**, **Signed on:** the date. The end date fills itself.
3. Make the login on **Staff & Agents → New agent** (`admin/staff.php#addStaff`) with the same mobile number: Organization for a partner, Person for an individual agent.
4. Back on the application, choose that account under **Agent account** and **Save**. The link copies the signed agreement and the applicant's phone choice onto the agent's profile (it never downgrades an agreement the agent already has).
5. Set **Status: Approved**. From now on the agent is managed on Agents / Agent 360 and in tab 2 here.

## API contract (for the agreement pages)

- `GET /api/agreement-apply.php` → `{"ok":true,"csrf":"<token>"}` (starts the session).
- `POST /api/agreement-apply.php`, `Content-Type: application/json`, `X-CSRF-Token: <token>`, body `{"type":"org"|"ind","lang":"ne"|"hi"|"en","fields":{...},"photo":"data:image/jpeg;base64,..."|null,"public_ok":true|false,"website":""}` →
  `200 {"ok":true,"ref":"AGR-000123","message":"...","duplicate":false,"photo_saved":true}`, `422 {"ok":false,"error":"...","code":422,"fields":{"p_phone":"..."}}`, `419`, `429`.
  Required: `p_name`, `p_phone` (10–15 digits), `p_area`; organization also `p_rep`; individual also `p_idno`. Messages come back in the form's language.
- `GET /api/agent-directory.php` → `{"ok":true,"count":N,"items":[{"name","kind","area","phone","verified","signed"}]}`, cached 5 minutes.

## Going live

1. Apply the SQL once (idempotent, safe while live):
   `mysql shari < database/upgrade-2026-09-27-agent-applications.sql`
2. Deploy the code (the integrator's release: asset stamp + bundle rebuild, because `app.template.html` and `assets/js/04-i18n.js` changed).
3. On **Agreements & agents → Existing agents & partners**, untick **Listed** for any account that should not be on the public list.

## Tests

- `tests/agent-directory-test.php` (battery, CORE) — the rules of the class: mask, end date, field and photo cleaning, validation, dedupe, office changes, the public list.
- `tests/agreement-apply-test.php` (battery, `--http`) — the endpoints and the admin page over real HTTP.
