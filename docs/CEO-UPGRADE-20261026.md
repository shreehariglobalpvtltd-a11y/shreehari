# CEO command center and live navigation continuation — 26 October 2026

Status: development candidate. No application deployment, production migration,
financial posting, message delivery, credential reset or role change was performed.
This is a substantial safe increment; it does not certify all seven requested phases.

## Source and protection

Development started from PR #26 `b38d01a`, with independent checkpoint `e4cd60d`
and `work/baseline-20261026.bundle` outside the repository. The old default/main
branches must not be substituted for the current production source.

Hostinger browser inspection identified the running VPS and its actual webroot
`/var/www/shreehariglobal.in/public_html`, backed by `/var/repos/shg.git`.
The newest bare release ref remains unified4 `243411a`, but **24 existing
application/source files differ**. The live shared admin file has 934 lines versus
2,531 in unified4, the team page 1,803 versus 503, and navigation 8,518 lines.
These differences combine newer navigation additions with older/incompatible
business pages. A ref name or HTTP 200 alone cannot identify the deployed build.

A fresh Hostinger snapshot was created and verified at **26 October 23:08 IST**;
the panel lists expiry **27 October**. Existing weekly backups dated 24 and 15
October were observed. A restore rehearsal and transaction-consistency validation
have not been performed, so snapshot presence is not a tested recovery guarantee.

With the owner's explicit approval, a source backup encrypted with AES-256-GCM
and an EC recipient certificate was transferred over a temporary random HTTPS
path. Its SHA-256 matched
`fb6b1d2b10ebeee74c12115f3d13e1a29d258654f21f7194de0f2ec528a1b2ff`.
The temporary encrypted web file was moved back into the private backup folder;
subsequent HTTP returned the site's HTML fallback, not the encrypted file.
A final remote filesystem check printed `Temporary encrypted file in webroot: False`.
The decryption key remains in the task's private working directory. No plaintext
source, production records or credentials were publicly served. The principal
config directory and runtime record/upload folders were excluded. A separately
named payment configuration file was also excluded from the private Git checkpoint
and all candidate changes; it was never printed or published.

The recovered source has an independent, local-only Git checkpoint
`739f95ec26fc521ced560d369f7c86a4c03839a8`. Never push that recovery repository
wholesale. Candidate changes selectively reuse inspected source.

Read-only production metadata confirmed the presence of bookings, agent ledger,
staff applications, team identity/reviews/journal, trip expenses, GPS trackers and
live trips. Production PHP reports 8.3.6. This was table-presence inspection,
not a full column/trigger/data reconciliation or an authenticated workflow test.

## Implemented phases and boundaries

| Phase | Existing modules reused | Candidate changes and verification | Files / remaining work |
| --- | --- | --- | --- |
| 1 Audit and protection | Existing bare Git, Hostinger backups, existing schema | Live-source recovery, independent source/code checkpoints, encrypted transfer with checksum, table-presence audit | This document; full live inventory/restore rehearsal and exact release-base construction remain |
| 2 Admin organization | `_guard.php`, existing `admin_nav()`, dashboard, MoneySummary, AI Watch and search | Exactly three primary office categories in requested order; same authorized destinations, active-menu expansion/ARIA, directory and approval family filters; twelve owner-facing settings topics reuse all existing controls; no new management application | `includes/adminnavigation.php`, `admin/_guard.php`, `admin/index.php`, `admin/team.php`, `admin/settings.php`, `includes/settingsnavigation.php`; 78 dashboard checks, 26 manager checks and mobile/desktop rendering passed |
| 3 Team and finance | TeamRegistry/Manager/Finance, AgentWallet, Payroll, existing append-only journal and reports | Agent, Staff (including drivers) and Partner views retain existing codes/accounts; all-team withdrawal queue is explicitly labelled. Historical booking without an active leg now renders safely, including duplicate payment-proof warnings | `admin/booking-view.php`, `includes/bootstrap.php`; 112 consistency checks passed. Organisation applications do not establish legal shareholding. New capital/share-allocation models, legal entity views and a new ledger migration were not invented |
| 4 Navigation | Current `navigate.html`, MapLibre, existing timetable, route service and NavLive | Preserves live drawer/HUD/follow controls; replaces hardcoded corridor stops with current/cached official timetable; bus mode refuses a car fallback; offline rerouting limits are stated; report drawer delegates to the existing authenticated module and cannot claim success on failure | `navigate.html`, `assets/nav/nav-live.js`, small compatible `whereis.php` / `etaalerts.php` changes. Existing navigation contract suite 258/0; new real-JS routing/report/corridor/GPS tests pass. No truck-restriction data quality or physical road validation is claimed |
| 5 Android/GPS | Existing OwnTracks adapter/profile, tracker ingestion, GPS fleet/simulator | Both shipped bus-account/device-code and exact device-code/device-code profiles authenticate, with required matching secret and phone/bus/revocation checks. Passenger and fleet displays expire locally and reject replays; simulation is server-disabled in production | `includes/owntracks.php`, GPS simulator/fleet view/API/JS; 22 OwnTracks checks and new replay/expiry/environment tests pass. Native Android project identification and real device background, offline/reconnection and battery tests remain |
| 6 AI and alerts | Existing SHG Sahayak, AI Watch, AiTools, notification modules and WhatsApp flow | Keeps the same assistant and deterministic finance engines; preserves provider validation diagnostics from the live WhatsApp flow implementation | `includes/waflow.php`; no competing chatbot, automatic balance adjustment or outbound message was created. Provider credentials/delivery and multilingual business answers need authorized staging/device checks |
| 7 Verification/release | Existing battery, CI, asset versioner, guarded deployer | New tests registered, two HTTP suites bind to the configured test DB, source/asset stamps synchronized to `20261026d` / `shg-v261`; independent offline ticket cache retained | CI result is recorded separately below. Production deployment, staging rollback, real financial reconciliation and device acceptance remain pending |

## Verified defects and preservation decisions

- The recovered live team page invokes `StaffApps::setStatus` in approval/rejection,
  but that method is absent from the deployed class. The candidate keeps the
  existing reviewed unified services and their KYC, account-linking, reservation,
  idempotency and audit guards. No live approval or payout was attempted.
- The recovered OwnTracks receiver treats a nonempty `bus-N` username as the
  device UID, rejecting earlier imported profiles. Both actually shipped valid
  formats are supported while requiring the corresponding secret. Missing GPS
  timestamps remain invalid; server time does not fabricate a fix.
- The newer report drawer omitted device identity and claimed successful sharing
  even after failure. It now reuses NavLive's existing reporting path.
- Hardcoded corridor city pins/distance values are not treated as configured
  company pickups. Current timetable data and its existing offline cache win.
  A route preview is explicitly not proof that every operational pickup is visited.
- Historical bookings with no joined departure reached an undefined `seats` key,
  truncating the page before duplicate-UTR/screenshot warnings. The seat editor
  now explains unavailable allocation while the original record remains reviewable.
  Error rendering also avoids a second headers-already-sent exception.
- No deletion/archiving migration, price/payment/commission-policy change, new
  account numbering scheme, legal ownership inference or production data rewrite
  was carried out. Existing screens/services remain the destination for workflows
  not changed in this increment.

## Test evidence

Local isolated PHP 8.3.32 / MariaDB 12.3, task-owned
`shari_ceo_release_test`, HTTP server `127.0.0.1:8901`:

- Initial comprehensive run: **183 suites passed, 6 failed, 0 missing** in 1,005 s;
  one historical stale suite remained explicitly excluded. This was failure
  evidence, not a passing release. It predates the final integration/fixes.
- Follow-up fixes retain the safety rules: KYC test fixture explicitly reviewed;
  navigation assertions follow the three-category contract; managed-agent test
  follows the existing redirect; office-link assertion follows the actual intake.
- Subsequent targeted results: dashboard **78/0**, manager **26/0**, registration
  **32/0**, smart-v2 **112/0**, admin consistency **112/0**, content pages **178/0**,
  navigation **258/0**, OwnTracks **22** checks; new historical-booking, GPS,
  simulation-environment, official-corridor, report-truth and My Bus race tests pass.
- Browser checks at **1440px and 390px**: dashboard, three team views, fleet and
  navigation; no horizontal page overflow, fatal page text or JS page errors.
  Synthetic local records only; screenshots are preview evidence, not production.
- Remaining local limitations: native Hindi font shaping needs HarfBuzz unavailable
  in this Windows setup; one existing 50ms performance threshold measured 56.6ms.
  Neither runtime safety nor font-quality assertions were weakened to hide these.
- Hosted implementation checkpoint `355730e9fdcffee4e30257e2845796f05f611e96`:
  [run 40379443980](https://github.com/shreehariglobalpvtltd-a11y/shreehari/actions/runs/40379443980)
  passed both jobs and **191 suites, 0 failed, 0 missing**, in 224.4 s, including
  the required real Hindi shaping backend. The historical stale suite remains
  explicitly excluded. The Windows performance/shaping limitations did not recur
  in the hosted environment.
- The subsequent settings organization passes local smart-v2 **112/0**, fares
  **26/0**, JSON validation **15/0**, metadata classification and browser checks.
  Its final hosted status must be read from the latest head of
  [draft PR #27](https://github.com/shreehariglobalpvtltd-a11y/shreehari/pull/27),
  not inferred from the older passing checkpoint.

## Release boundary / next step

Do not merge into an obsolete default branch or deploy this directory wholesale.
Complete a feature-by-feature comparison of all live-only admin/intake changes,
construct a source-matched release base, inspect the full production schema,
validate isolated staging and code/asset rollback, reconcile finance, then obtain
owner approval for the exact base, target and unique release label. The existing
guarded deployer must refuse unexplained live drift. Never restore an old database
over newer valid transactions. The snapshot's short expiry must be accounted for.

Real driver device tests, legal partner-capital accounting, saved-setting rollback
and confirmation coverage, and all requested alert/AI acceptance scenarios remain unfinished.

## Twelve settings topics — 27 October follow-up

The existing Settings screen now presents the twelve requested topics, in order.
The original classification remains a metadata fallback, while service credentials
appear with their service (WhatsApp, AI, routing, payments, backup or security).
Every original setting key/group/type/value and the privileged save path remain
unchanged. No table, financial rule or backend permission was changed.

Staff/Partner topics link to authorized existing team/payroll workflows; zero
standalone partner settings are stated clearly. Organisation applications are not
relabelled as legal share ownership. Links are filtered through the existing
`admin_nav()` permission result. The existing settings audit viewer is linked.
A new Cancel button resets unsaved fields; it is not a saved-setting rollback.

At 390px and 1440px browser checks found **480 unique setting controls**, no
rendered secret values, no page overflow or JS page errors. All twelve topics,
legacy category hashes, cross-topic search, keyboard switching and Cancel passed.
The interaction check made zero POST requests after login. Existing fares tests
separately exercised and restored synthetic settings in the task-owned test DB.
The normal backup reminder was dismissed during QA and only the task-owned
localhost login throttle was reset between test runs; production safety controls
were not changed.

