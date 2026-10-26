# Continuation candidate, 26 October 2026

Status: development candidate; production release is **not approved or executed**.

## Recovery and integration

The remote was fetched before editing. PR #26 remains based on unified4
`243411a604cceb2405ef360afc31b6361a668b09`; this continuation starts at
`a74fbc544d833cccd9a1231fdae944834b3f2765`. Remote live remains
`f1f62e5aad1b3083635016c89177f06ae079b7ca`, and the old OCR branch remains
`1ce9fae91bd3eda3dec144856745cdbec271bc63`. These are divergent histories.
No old branch was merged wholesale. The working copy is an independent local
clone, not the live webroot; the earlier local worktrees were not changed.

Ported the six report screens, report navigation, dashboard compliance/refund
alerts and the two idempotent trip-expense SQL files from the live lineage.
The old deployment workflow was deliberately excluded: use the guarded deployer.
OCR continues through ApplicationImport, the current form, existing passkeys,
TeamRegistry's encrypted vault and team_reviews. No parallel FormScan system,
second authentication engine or replacement finance ledger was introduced.

The uncommitted `team-workflow.sql` in the earlier `2026-10-25/sh/work/source`
checkout remains untouched. It proposes alternative table names superseded by
the unified implementation; it is not a production migration for this candidate.

## Feature checklist

| State | Evidence / work |
| --- | --- |
| Implemented and locally verified | 37 import checks; exact multi-page original retention, encrypted storage, session/kind isolation, expired receipt rejection, idempotent attachment, zero experience and date/phone normalization |
| Implemented and locally verified | Browser OCR suggestions on staff/agent/partner forms, preservation of trusted manual edits, original PDF upload and scope; mocked OCR responses and synthetic documents only |
| Implemented and locally verified | 74 unified-team checks, 26 manager checks; approval/linking, codes, recipient privacy, card generation/delivery states, reservations, reference requirements and immutable history |
| Implemented and locally verified | Four A4 pages each for blank staff, agent and partner forms; rendered and visually inspected. Active PDF wrapping uses CID font metrics, batches shaping and includes the recorded photo and signature |
| Implemented; verification in progress | Registered the six omitted suites and made the financial races independent of another suite's leftovers; full database battery and hosted checks must be recorded below before release |
| Implemented; authenticated staging pending | ID upload/review are enforced by submit APIs, applicant originals attach in the application's transaction, agent/partner edit requests use the existing verified edit session, old country identity is preserved, pending edits lock against concurrent approval |
| Implemented; device staging pending | Existing device passkey is the first login action, password/recovery remains available; UI distinguishes device biometrics from an uploaded thumbprint image |
| Blocked by access | Whole VPS inventory, VPS-only changes, current backups/snapshot completion, real service/runtime/schema, authenticated staging and deployment |
| Genuinely unfinished release work | Resolve remaining full-battery/hosted failures, reconcile live-only form differences, staging booking/payment/team/PWA/device checks and exact owner-approved guarded release |

## Actual changes

- Applicant form imports now retain their original bytes in the existing encrypted
  team vault, including PDF page two onward. The opaque receipt is bound to this
  browser session, application family and two-hour lifetime. Submit/retry attaches
  it to one application only; a completed form never creates an ID receipt.
- Office retrieval requires the existing manager permission and a real attached
  application. Pending anonymous uploads are unavailable through that route.
- Daily `cron/rotate.php` removes only anonymous pending uploads older than a day,
  in batches of 100. Submitted originals are retained. No added cron schedule.
- OCR suggestions cannot set consent, signature, verification, approval or payment.
  A provider outage retains the uploaded form and permits manual entry/review;
  it does not certify the document. File/MIME/pixel and existing rate budgets stay.
- Applicant edits use existing services; pending status and country cannot be
  bypassed, and other office fields/documents remain preserved.
- Fixed duplicate-review links that used an unsupported ref query parameter.
- CLI exceptions now exit nonzero instead of silently returning a successful
  process containing an HTML error. This can expose formerly hidden test/job failures.
- Client assets/SW are synchronized at `20261026c` / `shg-v260`; the independent
  offline ticket cache is preserved.

## Test triage

Each of the 15 known failures was first run on a restored synthetic baseline in
a task-owned MariaDB data directory. This local MariaDB is 12.3.2, PHP is 8.3,
and Node is 26; hosted CI retains MariaDB 10.11 and Node 24.

- Cash strict verification was disabled by the owner's later
  `upgrade-2026-10-16-cash-one-confirm.sql`. Its guards remain intact; strict-mode
  tests now enable the flag explicitly. The old hard-coded seller id 10 was not
  a reliable seed and is replaced with an actual synthetic seller.
- Payout tests supply the newly required payment reference and office-verified
  recipient fixture. The runtime guards are unchanged.
- Managed-role Quick Add pages intentionally redirect to the digital team queue.
  Tests follow the active page, enforce CSRF and retain core creation coverage.
- Agreement optional-field expectations and SHARI NAV links follow current active
  contracts. CSS tests verify the current depth-then-polish order. Decorative images
  are lazy and the unnecessary inline backdrop blur was removed.
- The whereis fixture no longer requires a post-2038 TIMESTAMP on MariaDB 10.11.
- The pre-existing deliberately excluded stale suite remains explicitly reported.

Local HarfBuzz/FFI is unavailable. Browser-printed A4 has correct Devanagari shaping;
the native PDF fallback can be visually inspected locally, but the mandatory hosted
HarfBuzz test and production runtime check are still required for final signoff.

## Production reconciliation and required next steps

Read-only public form observation in this session again produced SHA-256
`5b75f4bfe359e79e23f8b70e89aedd03ee6673883197b7a2eb169af8559f691d`.
It differs substantially from the unified candidate. Its public bytes were saved
outside the repository; this does not recover unknown private VPS changes.

No Hostinger VPS tool or SSH session is exposed in this session. The Hostinger
MCP servers were registered using the supplied connector skill; the app must
restart to load them. This is an access/setup limitation, not a new remote-exec
approval rejection. No claim is made about snapshot 121664785 or a complete live SHA.

After access is available, inventory the real webroot/git directories/services,
read current file differences and backup status, and preserve VPS-only edits.
Identify an authoritative base from that inventory; never substitute the branch
named live. Apply only reviewed missing migrations after schema checks. This
candidate relies on existing unified-team/team_reviews and ledger journal schema;
the recovered trip_expenses CREATE is idempotent, not a reason to replay history.

Run staging with that exact candidate, test rollback and verify booking, seat
concurrency, cash/online state, tickets/reprints/cancel, reports, recovery/passkeys,
KYC/cards/delivery, withdrawals, ledger reconciliation, OCR/private retrieval and
mobile/weak-network/PWA behavior. Then obtain owner approval for the exact base,
target and unique release label, as required by AGENTS.md and RELEASE-SAFETY.

Use the reviewed `deploy/deploy-prod.sh` plus `release-preflight.sh`. Back up code
and current DB, dry-run first and inspect all drift/migrations. Roll back code,
asset versions/SW/manifests together using the generated rollback instructions;
preserve the separate offline ticket cache. Never restore an old DB over valid
new transactions. HTTP 200 and Git ref equality do not prove a successful release.

## Final verification record

The local clone initially inherited shallow boundaries. It has now been fully
unshallowed and the complete graph confirms unified4 versus OCR = **33 ahead / 2
behind**, and versus live = **573 ahead / 16 behind**. The report integration
remains selective and no production branch was replaced.

Hosted run `40276715339`, source checkpoint
`1a94b292127bc4292262727ebd375f498aaad021`: the PHP/Node/release-safety job passed;
the MariaDB battery reported **181 passed, 3 failed, 0 missing**. Failures were
agents-upgrade fixture/harness assertions, a fixture date comment swallowing the
PHONE constant, and synthetic ID images reaching the strict provider-dependent
verification path. These have been corrected for the next checkpoint. The
application-contract suite explicitly restores its test-only verification mode;
the production strict verifier and receipt/review guards remain unchanged.

Current targeted local results: import **37**, agent upgrade **114**, application
HTTP **79**, manager **26**, registration **27**, financial races **2** all pass.
The local unified suite passes **74** checks including failure-closed behavior;
one positive native staff export is explicitly skipped without local HarfBuzz.
CI now additionally renders all nine active staff/agent/partner EN/HI/NE native
exports with the real HarfBuzz backend and requires embedded fonts and 2–4 pages.
Unshaped native agreements are refused; full browser print remains available.

All six recovered report screens also rendered through authenticated local HTTP
at 390px width, without page overflow or SQL/application errors. Blank A4 exports
and the mocked three-kind browser import/review paths were visually inspected.
The earlier full local battery additionally exposed Windows ini-path assumptions
and order-dependent delivery watches; fixtures now inherit the current PHP ini
and temporarily park/restore unrelated watch deadlines in the disposable test DB.

`php tools/release-schema-check.php` is a read-only schema inventory. It checks
the required table columns and immutable journal UPDATE/DELETE triggers without
printing credentials or application rows. The local synthetic schema passes.
Run it against the actual VPS before choosing any missing migrations.

Hosted run `40278536649` on `27220ad3572a9b226292b6c926805f1591ef395a`
passed both jobs: **184 suites passed, 0 failed, 0 missing**, with the one
pre-existing stale suite explicitly excluded. The nine native language exports
therefore ran with the required real HarfBuzz backend. No security or financial
guard was disabled to obtain that result.

Final browser follow-up found and repaired one additional active-caller issue:
switching agent/partner in the same page retained the original upload scope.
The widget now clears its receipts/review on a kind change and rejects a delayed
OCR response from the previous kind before applying fields. Real-browser
agent-to-partner switching, custom staff-position mapping, exact-PDF retries,
provider failure and manual corrections pass. A deterministic test runs the real
intake JavaScript and proves stale-response rejection, explicit review and kind
isolation without a provider/database; it is registered in both CI and the battery.
Final client stamps are `20261026c` / `shg-v260` and asset/offline checks pass.
Hosted run `40279499315` on implementation commit
`7c5dd08fd641faf471e87e3558e8e6421554c3ac` passed both jobs:
**185 suites passed, 0 failed, 0 missing** (145.8 seconds for the battery).
The one pre-existing stale suite remains explicitly excluded, not counted as a
pass. The following documentation-only checkpoint records these results;
runtime code and the final client stamps are unchanged.

Production remains blocked on actual live inventory, backup verification, staging
and owner approval. An Ubuntu/Dokploy label does not establish the site's runtime;
inspect the actual processes and webroot when access becomes available.
