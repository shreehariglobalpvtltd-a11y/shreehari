# S Hari Global continuous platform state

27 October release continuation: authenticated VPS/SSH access is now working.
See `LIVE-RELEASE-20261027.md` for actual paths/runtime, the source-matched base,
preserved live changes, the closed public application-tracker disclosure and the
exact staging/release gate. Nine required schema tables/columns and immutable
triggers exist; no production migration is required. Older no-access entries
below are historical. Deployment status must come from the release evidence.

Latest continuation: see `CONTINUATION-RELEASE-20261026.md` for recovered source,
report/OCR integration, active-form fixes, verification and explicit release blockers.
The older observations below remain historical; this development work is not a deployment.
Implementation checkpoint `7c5dd08` passed both CI jobs and all 185 registered
suites in hosted run `40279499315` (0 failed, 0 missing; one legacy suite
explicitly excluded). It includes scoped intake UI/stale-response coverage and
final stamps `20261026c` / `shg-v260`. The continuation release note records the
evidence, migration boundary and remaining production-access/staging blockers.

Verified on 26 October 2026 (Asia/Kolkata). This is a development audit and
incremental implementation record, not a production readiness certificate.

## Source of truth and audit boundary

- Repository: `shreehariglobalpvtltd-a11y/shreehari`.
- Inspected baseline: `release-20261025-unified4`, commit
  `243411a604cceb2405ef360afc31b6361a668b09` (25 October, 20:59:59 IST).
  It includes the `release-20261025-gps6` history (ancestry check succeeded).
- Development branch: `codex/platform-release-safety-20261026`.
- Default branch `codex/ai-company-manager-20260921` points to September code.
  `main` is also older; `live` points to `f1f62e5` dated 24 October. None is
  sufficient evidence of the complete current webroot. Do not deploy them
  wholesale or merge this work into the old default branch.
- Fresh isolated checkout, initially empty workspace. No VPS shell, live DB,
  authenticated admin session, staging access or Android device was used.
- No production configuration, credentials, documents, balances or transactions
  were copied, edited or restored. Public HTML was read without submitting forms.

## Live observations

| URL | Verified observation | Limit |
| --- | --- | --- |
| `https://www.shreehariglobal.in/` | HTTP 200; booking and team/navigation entry points in rendered HTML | No booking or payment was submitted; rendered PHP output cannot identify a whole release |
| `https://shreehariglobal.in/agreements/apply.html` | Redirects to www, HTTP 200, application page present | Public file differs from baseline even after CRLF normalization; reconcile before deployment; submission/OCR untested |
| `https://shreehariglobal.in/navigate.html` | Redirects to www, HTTP 200; downloaded bytes exactly match baseline file | GPS freshness, route services, permission prompts and offline navigation untested |
| `https://shreehariglobal.network/admin/team.php` | HTTP 200 after redirect; sign-in form displayed | Team/finance actions blocked by authentication, not proven working or broken |

Navigation SHA-256, live and baseline:
`a6cc65972599d7a0550ac087c6b41ae7f0fc6417896bff72d13e0ad7c776340a`.
Application SHA-256 live:
`5b75f4bfe359e79e23f8b70e89aedd03ee6673883197b7a2eb169af8559f691d`;
baseline:
`f2d4f35087b856e2a06491ee23eaea7923ba6bf2a7d55d87b515829d9882b6fc`.
These observations are a point-in-time sample, not a persistent monitor.

## Feature-to-module audit

"Partially working" below means a public surface and/or existing source contract
was inspected, with end-to-end behavior still unverified. "Blocked" denotes
missing verification access; it does not mean the feature itself is broken.
No feature is declared missing solely because an authenticated page was blocked.

| Feature | Existing implementation | Classification and remaining verification |
| --- | --- | --- |
| SmartBook | `api/smartbook.php`, `includes/smartbook.php`, `includes/booking.php`, `assets/js/27-smartbook.js` | Partially working: public entry/code present; run quote, idempotency, changed-fare and cash/online tests on isolated DB |
| QuickBot | `includes/smartbookbot.php`, `includes/ticketbrain.php`, existing parse action | Partially working: parsing implementation present; test multilingual requests against current route data |
| SHG Sahayak | `api/ai-chat.php`, `includes/aiagent.php`, `aitools.php`, `aiturn.php`, `aibrain.php` | Partially working: shared web/WhatsApp tools and role gates exist; provider credentials, confirmation rules and runtime responses untested |
| Seat AI / inventory | `includes/seats.php`, SmartBook quote/book path, seat-event APIs | Blocked: source has transactions/row locks; double-booking and mixed-group behavior need DB-backed tests |
| Cash / Online | SmartBook, `api/payment.php`, `includes/payadapter.php`, `paymentrisk.php` | Partially working: proof-submission/verification path exists; no evidence all gateway callbacks are configured; never label a submitted proof paid |
| Ticket / PNR / downloads | `includes/booking.php`, `ticket.php`, ticket-live/track APIs | Partially working: rendering/source present; offline-ticket JS suite passed 21 checks; issue/reprint/cancel still need backend testing |
| WhatsApp | `includes/notify.php`, `api/whatsapp-webhook.php`, existing workers and journals | Blocked: delivery credentials, consent, callbacks/retries not exercised; no messages sent |
| SHARI NAV | `navigate.html`, `api/nav-*.php`, `includes/navlive.php`, `gpsfleet.php`, `gpstrackers.php` | Partially working: public file matches baseline; source filters public GPS by freshness/opt-in; real fix and ETA tests pending |
| Agent / Staff / Partner / approvals | `admin/team.php`, `includes/teammanager.php`, `teamregistry.php`, `teamportal.php`, application classes | Blocked: unified source exists; authenticated KYC/approval/account-linking tests pending |
| Wallet / commission / loans | `includes/agentwallet.php`, `teamfinance.php`, `admin/ledger-check.php` | Blocked: existing deterministic operations, row locks and reconciliation code present; balances/history/withdrawal races unverified |
| Payroll | `includes/payroll.php`, existing payroll tests | Blocked: retain existing payroll; no salary posting was performed |
| Forms / OCR | `agreements/apply.html`, application/import/docread APIs, `includes/applicationimport.php` | Partially working: public form and reviewed-OCR contract exist; live form drift and provider behavior need review |
| ID / visiting cards | `includes/teamregistry.php`, card job/event tables, team-card/photo/design APIs | Blocked: source present; approved recipient, signature/QR and delivery verification pending |
| Reports / performance | `includes/agentreport.php`, `officereport.php`, `reportchart.php`, AI report tools | Blocked: reuse current calculations; totals/rankings require reconciled test data |
| Authentication | `includes/auth.php`, `security.php`, `teamportal.php`, admin guard | Partially working: admin sign-in wall observed; role isolation, reset, CSRF and step-up tests pending |
| Smart Tools / external services | `includes/teamservices.php`, `api/team-services.php` | Blocked by design: readiness returns false; no operational provider/reconciliation adapter; keep transactions disabled |
| Native Android | No tracked Gradle, AndroidManifest, Kotlin or Java source at baseline | Missing in this inspected repository; PWA manifests are present; another native repository may exist and has not been identified |
| Deployment safety | `deploy/deploy-prod.sh`, new `release-preflight.sh`, existing SW/version checks | Partially working: new guards verified offline; not staged or installed on VPS |
| Daily module registration / API versioning | Existing PHP endpoints and Settings; no unified versioned registry identified in inspected entry points | Partially working: established APIs must be preserved; registry and v1 adapters are future work, not implemented |

Multiple entry points such as `ai-proxy.php`/`ai-chat.php` and legacy team screens
exist. They may be compatibility paths: no deletion or duplicate-system migration
is justified until their callers and live usage are traced.

## Five highest-value improvements, in order

1. **Release safety**: freeze exact commits, serialize deploys, forbid release-ref
   overwrites and recheck live drift after preparation. First increment implemented
   here; staging, whole-release identity and approval evidence remain pending.
2. **Critical regression execution**: validate a fresh `shari_test` and register
   newly added team/finance suites. Exact filenames `ledger-journal-test.php`,
   `unified-team-test.php`, `team-manager-test.php`, `team-registration-test.php`
   and `application-import-test.php` were not found in `tests/run-all.php`.
   Test-file presence or PHP lint does not mean these tests execute in CI.
   Prioritize concurrent seats, idempotency, withdrawals, immutable journal,
   reconciliation, identity linking and phone/role isolation.
3. **Client/API compatibility**: capture current response fixtures and error
   contracts; add opt-in versioned adapters that call existing engines. Identify
   native Android source before claiming mobile compatibility. Preserve all
   current URLs and session/CSRF behavior throughout migration.
4. **Sahayak tool guarantees**: validate existing role catalogue and step-up /
   later-message confirmations for every financial mutation. Ground responses in
   tool results; extend the existing tool layer instead of adding another agent.
   Local deterministic answers already exist (`WaLocal`); no local generative
   model adapter was verified in the inspected `AiBrain` provider list.
5. **Module extension/readiness**: add a reviewed manifest schema with permission,
   navigation, localization, web/native compatibility, feature flags, tests,
   migration and rollback declarations, registered against existing Settings.
   First candidate is read-only capabilities; external money-moving services
   remain disabled. Do not introduce remote executable Android updates.

## Authoritative architecture and logical boundaries

The current PHP bootstrap, existing database and services remain authoritative.
These names map ownership; they are not new classes, databases or microservices.

| Logical module | Reuse | Boundary |
| --- | --- | --- |
| BookingEngine | SmartBook + BookingService | Server quote, idempotent book, cancellation; session-derived selling role |
| TicketEngine | Ticket + existing download/track APIs | Owner/token-gated ticket access, existing PNR IDs |
| SeatAllocationEngine | Seats + current booking transactions | Authoritative inventory/holds and gender/group rules |
| PaymentEngine | SmartPay/payadapter + payment proof/risk + existing verification | Pending proof is distinct from confirmed payment |
| AgentWalletEngine | AgentWallet + TeamFinance | Existing commission/payout/loan balances and reservations |
| FinanceLedgerEngine | Existing agent ledger, LedgerCheck, Payroll, journal migration | Existing IDs and append-only journal policy; no AI SQL writer |
| TeamAndAgreementEngine | TeamManager/Registry/Portal + application classes | Three agreement families, KYC review, approval and account linking |
| DigitalIdentityEngine | Existing cards, verification/passkey/photo APIs | Private source files; approved cards and status QR |
| NavigationEngine | NavLive/GpsFleet/GpsTrackers + current nav APIs | Freshness/accuracy and public opt-in; no invented GPS |
| NotificationEngine | Notify + existing WhatsApp jobs/journals | Consent, queue/delivery distinction and retries |
| SahayakAIEngine | AiAgent/AiTools/AiTurn/AiBrain/WaLocal | Backend permission catalogue; verified tool results |
| PerformanceAnalyticsEngine | Existing agent/office reports and chart code | Role-scoped calculations, then AI explanation |
| BusinessToolsEngine | Existing service contracts and readiness gates | Keep unconfigured transactions disabled |
| FeatureManagementEngine | Existing Settings + deployment/version tooling | Runtime registry still planned; release-safety submodule implemented |

Verified existing switches include `agent_wallet_enabled`, `allow_cod`,
`cod_verify_on`, `ai_web_agent_on`, `ai_web_sell`, `wa_agent_on`, `wa_agent_sell`,
`gps_trackers_on`, `ai_governor_on` and `ai_governor_shadow`. Their live values
were not read. There is not yet evidence of a distinct switch for every logical
module. Mandatory finance/auth/deployment guards must not be client-disableable.

## Current contracts and compatibility decisions

- `POST /api/smartbook.php`: boot/quote/book/parse/payinfo (and existing actions);
  POST + CSRF; role inferred from session, not trusted from request. Booking uses
  `idem`; changed expected fare/date can return 409. Preserve the present cash
  verification policy instead of replacing it with a rule remembered from chat.
- `POST /api/ai-chat.php`: message/lang/reset; session-derived AiTools context,
  CSRF, rate limits; existing `text/media/charts/actions/role/name` response.
  `AiTools::run` checks catalogue and `AiVerify::gate` before dispatch.
- `POST /api/payment.php`: existing ownership checks and payment proof flow;
  claimed amount/UTR/image is advisory, verification remains server-side.
- `GET /api/nav-buses.php`: rate-limited, no-store snapshot through NavLive.
  GPS filtering is code evidence, not proof devices are currently reporting.
- Existing `Response::success` envelope uses `ok` and optional `data/message`.
  No v1 envelope or mobile auth method was added in this increment.
- New release preflight contract is CLI-only, described in the release note.
  No API, DB migration, fare, cache stamp, UI or Android behavior changed.

## Session verification and open tasks

Implemented: release guard helper, pinned commits in existing deployer,
nonblocking deploy lock, immutable release-ref creation, second drift check,
strict optional argument handling, bounded health checks and code-only rollback
guidance. CI now invokes its Python integration suite.

Passed locally: 19 release-safety integration tests (real temporary Git and
files; simulated dump/reload/ownership/HTTP services), Bash syntax, 21 existing
offline-ticket checks, lazy-load recovery, translation-key parity (1,484 keys
each in EN/HI/NE) and Git whitespace checks.

Hosted follow-up on implementation commit `380948782f0261b478230538d2d5194ad27072cf`:
[Application checks run](https://github.com/shreehariglobalpvtltd-a11y/shreehari/actions/runs/40231475509)
passed whole-tree PHP syntax, the new 19 release tests, JavaScript syntax and
manifest JSON. The checks job then failed at asset-version discipline: 7 checks
passed, 2 failed. `manifest.webmanifest` and `premium3d.css` carry `20261022z`
while SW ASSET_VER is `20261023j`; the version-format assertion also rejects
`shg-v257-team20261025-2`. These files/tests are unchanged from the inspected
baseline. They are real release blockers; do not suppress the assertions to
claim a green build. Later steps in that failed job were skipped.

The separate MariaDB battery completed on fresh `shari_test`: **157 suites
passed, 16 failed, 1 missing** (208.5 seconds). `nav-pro-test.php` is referenced
but missing. Failing suites: asset-version, seatmap-premium, home-premium,
agent-advance, agents-upgrade, wa-manager-tools, depth-display, perf-hints,
whereis, agreement-pages, theme-palette, smartbook-engine, smartbook-facts,
counter-mode-http, pwa-http and agreement-apply. The runtime/test files behind
these failures are unchanged by this PR. This is failure evidence, not proof
that every failing assertion is a production defect; fixture/policy drift and
actual bugs must be separated before fixing. Local PHP/MySQL remain unavailable.

Priority failure groups:

| Area | Hosted evidence | Required follow-up |
| --- | --- | --- |
| Cash booking | SmartBook assertions report missing required verification fields still book; another path hits the per-mobile COD limit | Reproduce individually with isolated settings/data; establish current approved policy; preserve server-side validation |
| Finance / agent | Payout reference/recipient guards disagree with older expectations; agent quick-add/register assertions fail | Keep deterministic guards; inspect fixtures, permissions and current unified UI before changing behavior |
| Agreements | Required field expectations differ between legacy HTML/API; two application assertions fail | Trace active form callers and owner-approved requirements; reconcile current live application drift |
| Navigation | `whereis` test encounters a 2099 timestamp error; `nav-pro-test.php` missing | Separate test-date/schema issue from live GPS behavior; restore/register appropriate coverage |
| Cache / layout | Version drift/format, CSS ordering, route/markup/performance assertions fail | Repair cache stamps with the existing workflow; distinguish outdated UI tests from regressions |

Hosted PHP syntax and release-safety tests succeeded, but **the complete CI
workflow failed**. Authenticated production workflows, staging, real Android /
weak networks, VPS backup/reload and live deploy behavior remain unverified.
No production release occurred. Approval is not a substitute for these checks.

Next session: fetch all upstream changes, reconcile public application drift and
obtain a current authenticated release inventory; inspect the complete booking
battery failures individually, prioritizing cash/finance, and repair baseline
cache/version discipline with the existing asset-bump workflow plus regression
evidence. Then complete staging and
validate/register critical team/ledger tests on `shari_test`.
Before every release, produce the exact target/base IDs, test evidence, changed
files, migration assessment, release notes and rollback plan for owner review.

## Website increment 2 — 26 October 2026

The owner requested an actual website update after the initial audit. This
increment changes the existing homepage and fixes the verified asset-cache
failures; it does not replace the website or business engines.

- The existing three core links now appear in the requested order: 10-Second
  Ticket, Digital ID & Agreements, Live Map & Navigation. The identity subtitle
  explicitly includes Agent, Staff and Partner. Existing destinations and the
  booking scroll handler are preserved. Decorative emoji are hidden from screen
  readers; six new translation keys exist in English, Hindi and Nepali.
- The existing asset-bump tool synchronized the main app's cache stamp to
  `20261026a`, service-worker version `shg-v258`, and the stale manifest/CSS icon
  references. Both previous asset-version failures now pass all 9 assertions.
  No cache assertion was disabled or broadened to obtain that result.
- The nonexistent `nav-pro-test.php` registration is replaced with the actual
  current `nav-ai1-test.php` suite. Its obsolete Windows-only line-ending rule
  now requires consistent LF or CRLF, rejects bare CR and mixed endings, and
  retains UTF-8/module/endpoint/syntax checks. The map file itself is untouched.
  All 258 navigation checks pass locally.
- Existing GPS health/public privacy tests and marker behavior are registered
  in CI/the main runner so stale fixes, private metadata and unexpected map
  recentering are covered. These tests previously existed outside that runner.
- The public application page differs substantially from the inspected Git
  version. Its incidental cache-only edit from the asset tool was deliberately
  removed; `agreements/apply.html` is unchanged by this increment. It must not
  be replaced with an older form to ship the homepage update. A fresh HTTP
  attempt timed out, so the earlier public snapshot remains point-in-time only.

Local verification now uses an isolated PHP 8.3.6 CLI prepared for testing,
without installing or connecting any database. Passed: asset versions 9,
navigation 258, AI tool loop 11, AI charts 15, GPS health/quality 16, public GPS
privacy/freshness cases, browser marker behavior, offline-ticket 21, lazy retry,
release safety 19, both manifest JSON parses, EN/HI/NE translation parity
(1,490 keys each), PHP syntax for touched PHP/tests, JS syntax, the three
homepage destinations/order and whitespace checks with CRLF preservation.

No DB migration, live data change, booking/payment rule or wallet mutation was
introduced. The earlier database battery's other failures still require triage;
they are not declared resolved by these frontend/test registration changes.
Real Android, weak-network visual testing, authenticated staging and production
deployment remain unverified.

Live deployment remains blocked by the absence of an active VPS/Hostinger
operation tool/session here. Hostinger servers are registered in the local
configuration, but no hosting/VPS tool is exposed; connector search returned
mailbox functionality only, which cannot deploy this site. No production write,
release approval or successful deployment is claimed. Next: verify this exact
PR on connected staging, reconcile the actual live file inventory, obtain
owner approval for a concrete release, then run the guarded deployer and check
the live homepage, APIs, forms and both domains.
