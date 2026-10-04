# Upgrade 4 Oct 2026 — desks as data, the sale frozen on the ticket, two companies

Branch `desks-20261004`. **LIVE** on 4 Oct 2026 (owner: "deploy ni gardinu"):

| Release | Time (IST) | What |
|---|---|---|
| release-20261004-d1 | 12:58 | 37 files: desks, sale stamp, per-company tickets, security wins. Base = `live-20261004-m3` (r2 + another session's SellerMail / Brevo m1–m3, rebuilt because the drift guard caught it). |
| release-20261004-d2 | 13:01 | Migration tolerates the OLDER live `counter_locations` table (26 Sep build had left it without entity / lang / map_url / hours). |
| release-20261004-d3 | 13:03 | Live had `payments.local_*` + `counter_shifts.counter_code` already, so the grouped ALTERs skipped `payments.entity` / `counter_shifts.entity`; every stamp now asks `CounterDesk::hasColumn()` first and the migration adds each column alone. No counter sale or error happened between d1 and d3. |
| release-20261004-d4 | 13:06 | Design: route pages + travel-info on ds.css tokens, route corridor scene on every route page. |
| release-20261004-d5 | 13:23 | Speed: no entrance fade on the first view + heading/subtitle in the visitor's language at first paint (LCP was the late i18n swap). Campaign deep links `?src=&dir=&town=&date=` + `landing` beacon. Stamp 20261004g / shg-v172. |

| release-20261004-d6 | 13:38 | **Admin → Settings → Company details** (both companies, Nepal logo upload, Nepal eSewa / bank; blank = India) and **Agents & counters → My desk** (a counter edits its own desk's phone / hours / address / map). Edits redraw cached tickets. Stamp 20261004h / shg-v173. |

| release-20261004-d7 | 14:33 | Desk isolation wired (switch on Counters & collection, still OFF): a counter sees only its desk's register / payments / passengers. Accounting: read-only **By company** panel. Home: reviews marked public on Admin → Ratings show above "More about us" (none yet → hidden). Stamp 20261004i / shg-v174. |

Live already carried `nepal_company` = "Shree Hari Global Pvt Ltd Nepal" and `nepal_office` = "Puspalal Chowk, Nepalgunj"
(set 26 Sep), so Nepal-desk tickets print that letterhead since d1. Left as is; the office edits it on Company details.

**Speed, measured on live** (headless Chrome, 375 px, 1.6 Mbps / 150 ms RTT, 4x CPU): LCP was the booking
heading at 6.2–7.2 s (it faded in from opacity 0, so Chrome only counted the Nepali swap after every deferred
script). After d5 the heading paints in Nepali at first paint; LCP is now the note under the search button at
~4.7–5.8 s. What is left is bandwidth: 20 deferred scripts (475 KB) download beside the page. Next step: load
only the active language from 04-i18n.js (~205 KB raw, all languages) and lazy-load 13-admin-routes.js /
11-pdf-ticket.js on the customer home.

**Campaign links:** `https://www.shreehariglobal.in/?src=fb-surat&dir=back&town=chiloda&date=2026-10-20`
(`dir` = go | back, `town` = any part of a stop name, `date` = YYYY-MM-DD, today or later). Not `?ref=` — that is
the agent code.

All three migrations are applied on live. Backups: `/root/backups/pre-deploy-release-20261004-d{1..4}-*`.
Also done on the VPS: the `github-actions-deploy` SSH key was removed from root's authorized_keys
(backup `/root/backups/authorized_keys.pre-github-removal-20261004-130702`) — the stale GitHub
"Deploy to VPS" workflow can no longer revert live.

Live NPJ row came from the 26 Sep build: name "Nepalgunj — Puspalal Chowk", allowed methods **cash only**
(kept — it was the office's earlier rule; tick eSewa on Admin → Counters when the eSewa account is ready).
Built from the owner's 10-point prompt (docs/PROMPT-2026-10-04-fable-nepalgunj-desks.md).
Asset stamp `20261004e`, service worker `shg-v170`.

## What is built (4 commits)

| Point | State | What |
|---|---|---|
| 2 Desks as data | done | `counter_locations` TABLE (code, name, country, currency, fx_rate, allowed_methods, default_direction, default_from, entity, lang, phone, address, map_url, hours, is_active). `includes/counterdesk.php`. Admin → **Counters & collection** adds/edits/closes a desk — no deploy needed for a new desk. `Settings::counterLocations()/counterLabel()/isNepalDesk()` remain as wrappers. Desk select on the Staff *create* form. A desk rename now reaches staff already sitting there. |
| 3 Freeze the sale | done | `bookings.counter_code / entity / fx_currency / fx_rate / fx_total`, `payments.local_currency / local_amount / fx_rate / entity`, `counter_shifts.counter_code / currency / entity`. Stamped from the **clerk** before the sale is re-credited to an agent. Ticket reads the stamp; old rows fall back to the seller profile. Moving staff never changes an old ticket. |
| 1 Nepalgunj desk | mostly | NPJ / NPJD / KHL = Nepal, NPR, Nepal company, cash + eSewa only (enforced at the register, chips hidden in counter mode), open on the return run, board at Rupaidiha. NPR quoted and NPR received are **stored** (the office report's NPR line now has data). NPR cash drawer (1000…1 notes) on Shift & Cash. Per-desk book + by-company split on Counters & collection. **Open:** owner data (phone, hours, address), the login itself, a separate desk slip (the PNG ticket is the receipt today). |
| 4 Tickets per company | done | `Settings::companyFor(desk, entity)` → one letterhead answer. PNG, PDF, invoice, verify page, WhatsApp ticket texts, app fallback ticket. Other side's help number, desk's own phone/address when filled, `IST (… NPT)` on a Nepal-company ticket, "TAX INVOICE" only with a GSTIN. No office number is typed into code any more. |
| 5 Two companies | partly | Entity is stamped on bookings / payments / shifts and shown on Counters & collection. One NPR rate source (app + AI tools read `npr_per_inr`). **Not built** (needs the owner's rule + the CA): entity on agent_ledger / trip_expenses / payroll, invoice numbering per entity + financial year, inter-company ledger, entity filters in Accounting / Analytics / daily summary, `entity.NP` scoped access. |
| 6 Security | code part done | export needs `reports.export` when unscoped (+ audit row; manager gains the permission); offers edits need `coupons.edit`; one secret-key rule (push private key no longer printed in the Settings form, `smtp_pass` masked in audit); `clientIp()` trusts proxy headers only from `TRUSTED_PROXIES`; a temporary password is refused by `/api/` too; Agent Panel rate edits need a reason; desk isolation switch ships OFF (setting only — the scoping itself is not wired yet); `deploy/deploy-prod.sh`. **Not done (VPS / owner):** firewall (ufw is inactive), removing the `github-actions-deploy` key, off-site encrypted backups + config/uploads tarball, off-box uptime check, admin 2FA push. |
| 7 Design / motion, 8 Speed, 9 Marketing | not started | |
| 10 Verify | partly | Suites below; both letterheads force-rendered and inspected (PNG + PDF + invoice). Phone-width screenshots not taken (no customer-facing layout changed). |

## Decisions taken (owner can change each on Admin → Counters)

- **Company of a sale = the desk's company**, frozen on the ticket. Nepal desks → Nepal company; Rupaidiha and every Gujarat desk → India company; online sales → India company. *This is a default until the owner confirms the rule.*
- **Nepal desks take cash + eSewa**, not UPI / bank.
- Until **Settings → company → Nepal entity name** is typed in, a Nepal desk's ticket keeps the India letterhead (nothing about the Nepal company is invented). PAN, address, phone, WhatsApp, logo: each prints only when filled.
- Help numbers on the PDF now come from the desk table, else the office's `company_counters` line. That line's Baroda / Surat numbers differ from the four that were typed into the old code — **owner must confirm which are right.**
- `TRUSTED_PROXIES` stays undefined on the VPS: nginx talks to PHP-FPM directly (checked read-only on 4 Oct), so the visitor IP is `REMOTE_ADDR`.

## Deploy (only after the owner says yes)

1. Push `desks-20261004` to `/var/repos/shg.git`; on the VPS:
   `bash deploy-prod.sh release-20261004-r2 desks-20261004 release-20261004-d1 --dry-run`, read the list, then without `--dry-run`.
2. Database, in this order (all additive and idempotent; code works before and after):
   ```
   php tests/apply-sql.php database/upgrade-2026-10-04-counter-desks.sql
   php database/upgrade-2026-10-04-counter-desks.php      # copies the office's own desk names (live NPJ = "Puspalal Chowk")
   php tests/apply-sql.php database/upgrade-2026-10-04-two-companies.sql
   ```
   `tests/` is not on live — run `apply-sql.php` from a checkout of the release, or pipe the `.sql` through `mysql shari`.
3. Check: home + `/admin/login.php` = 200; Admin → Counters & collection lists 11 desks; cut one test ticket at a desk and open its PNG.

Every ticket PNG / PDF redraws once on its next open (layout stamps moved to 4 Oct 12:15 IST).

## Owner: making the Nepalgunj counter login

1. Admin → **Counters & collection** → Edit **NPJ**: phone, address, opening hours (NPT), map link; tick the money it may take; Save.
2. Admin → **Staff & Approvals** → Add staff: username, full name, phone, role **Counter**, **Counter / desk = NPJ**, temporary password → Create account.
3. The clerk signs in at `shreehariglobal.network/admin/login.php`, changes the password, then **+ New Booking**. The screen opens Nepal → India, shows NPR beside every rupee total and offers Cash / eSewa only.
4. Admin → Settings → company: type the Nepal company name, PAN/VAT, office address, phone — from then on NPJ tickets carry the Nepal letterhead.
5. Optional: Shift & Cash → switch ON for the NPR cash-drawer count.

## Tests

`php tests/run-all.php --http` (PHPRC → a dir holding the dev ini as `php.ini`; `SHG_TEST_BASE`):
new `counter-desk-test` (83) and `desk-identity-test` (36); `counter-location`, `counter-shift`, `nepal-desk` adapted.
Only the known environment failures remain: `route-pages` / `travel-info` (sitemaps under `php -S`), `agreement-pdf` (HarfBuzz on Windows), `e2e-booking` / `role-gates` (hard-coded `shari_test` DB name).

## Traps met

- The worktree was cut from the stale 86-file `master` again → `git reset --hard origin/release-20261004-r2` (662 files).
- `git fetch origin` needs `GIT_SSH_COMMAND="ssh -i ~/.ssh/shg_vps_ed25519 -o IdentitiesOnly=yes"`.
- `PHPRC` must point at a directory containing a file named `php.ini`, or every child process of the battery fatals at `mb_internal_encoding`.
- Files are CRLF / LF / mixed: patch with a script that tries both, never a bare multi-line Edit.
- The finish-branch `reopen` used `$row + ['is_active' => 1]` (array union keeps the LEFT value) — fixed with `array_merge`.
