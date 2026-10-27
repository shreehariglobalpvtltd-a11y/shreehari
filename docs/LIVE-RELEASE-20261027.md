# Reconciled live release candidate — 27 October 2026

This continues PR #26 after the owner requested a production deployment.
Production changes require completion of the staging and exact-release approval
steps below. This file records preparation, not a claim that deployment happened.

## Verified access and base

- Authenticated Hostinger inventory identified VPS 1933977, hostname
  `srv1933977.hstgr.cloud`, IP `93.127.167.249`. Existing SSH key
  `shg_vps_ed25519` successfully authenticated; no new key/password was created.
- Nginx and PHP 8.3-FPM serve `/var/www/shreehariglobal.in/public_html` directly.
  MariaDB is 10.11.14. Docker also hosts Valhalla; it is not evidence that PHP
  runs in a container. Existing services, test ports 8899/3307 and Valhalla must
  not be stopped or reused for this staging run.
- The webroot's own Git HEAD is old `2d76f26`; `/root/shg-site.git` is absent.
  `/var/repos/shg.git` contains unified4 and the previous candidate but has an
  unborn default HEAD. These facts supersede older path/ref assumptions.
- A normalized source inventory found 25 existing non-test/source differences
  from unified4, including private WhatsApp configuration which was excluded
  from transfer. Four extra live code paths were discovered. Twenty-eight
  reviewed code/instruction files were captured without configuration,
  uploads, records or credentials. Their private configuration remains outside
  the source manifest and deployment file set.
- The source-matched code base is `f28167cf7927cac4f5ebf98b700a5efea03ce938`,
  parent unified4 `243411a`. It captures the reviewed live differences and
  known undeployed source files. The deployment target merges that base with
  tested implementation `b38d01a` and the selective preservation described below.
- Hostinger action 121664785 is confirmed **success**, but a later backup restore
  occurred on 24 October. Current snapshot 425215 was created 26 October 23:08
  IST and expires 27 October 23:08 IST. Weekly backups dated 24/15 October exist.
  Snapshot presence is not a restore rehearsal; a fresh private code/DB backup
  is still required immediately before deployment.

## Preserved and corrected

Current live navigation/HUD, telemetry, routing-build adjustments, agent,
booking, map, WhatsApp-form and verification-page changes are retained. Existing
parent/sub-agent schema/data remain in place. Parent requests use the shared
active-agent code resolver, remain pending applications and never change account
ownership, approval or commission history by themselves.

The active unified application form, OCR receipt/review guards, private original
retrieval, existing services, A4/PDF changes and reports from PR #26 are retained.
The live replacement team page invoked a nonexistent `StaffApps::setStatus`;
the candidate uses the existing reviewed unified team controller/workbench.
The shared office layout/dashboard likewise use the compatible unified services.

The extra unauthenticated `api/application-status.php` could turn a letter-only
query into `phone LIKE '%%'`, returning an unrelated applicant's reference,
correction instructions and status. The legacy route now refuses disclosure and
directs to the existing WhatsApp-verified My application flow/team portal.
Regression checks cover letters, partial numbers and a valid reference. No
second authentication or financial engine was created.

The separate CEO/Settings branch is not part of this release scope. Its work is
preserved on its own branch. Configuration, identities, balances, documents,
bookings and financial history are not replaced by source reconciliation.

## Schema and verification contract

The read-only production inventory confirms the required nine tables/columns
(including existing role/parent metadata) and both immutable-journal triggers.
`trip_expenses` already exists with the expected columns. **No production
migration is required for this target.** Do not replay historical migrations or
run the recovered hierarchy migration against live merely because its source is
present. The legacy manual/backfill scripts are preserved source, not an
authorized deployment route; use the guarded deployer only.

Final candidate stamps: `20261027a` / `shg-v262`; the separate offline-ticket cache
is preserved. Exact commit CI results and isolated staging evidence are recorded
on the release PR and in the task's output evidence, not inferred from older CI.
Local Windows syntax, asset and intake checks are supplemental; mandatory native
Hindi/Nepali PDF shaping must run with real HarfBuzz.

## Release and rollback gate

Use a dedicated, synthetic isolated staging DB and local-only HTTP service;
never copy production config/data into staging or send real messages. Validate
booking/seats, cash/online state, ticket lifecycle, reports, team approval/cards,
delivery mocks, withdrawal/salary races, ledger history, private OCR originals,
A4/PDF and login presentation. Record real device/provider limits honestly.

Run the reviewed `deploy/deploy-prod.sh` and `release-preflight.sh` together from
the staged exact target, with the source-matched base and a new unique release
label. Dry-run must report no unexplained drift. Recheck drift before writing.
Obtain the repository-required owner approval after staging for that exact
base/target/label. Back up touched code and the current DB privately first.
Code rollback restores code/assets/manifests/SW together, never an old DB dump
over newer valid transactions. Read served versions and verify workflows after
deployment; HTTP 200 alone is insufficient.
