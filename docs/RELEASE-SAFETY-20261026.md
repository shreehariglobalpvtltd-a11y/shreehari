# Release safety increment 1 — 26 October 2026

Module identifier: `feature-management.release-safety`; version `1.0.0`.
This extends the current deployer. No alternate deploy system or DB is created.

## Problem and resulting behavior

The existing deployer repeatedly resolved mutable base/target refs, forcibly
reassigned release branches and allowed simultaneous invocations. A mistyped
fourth argument also silently became a real deploy instead of a dry run.

This increment:

- Validates arguments and `release-YYYYMMDD-label` names before backup or code writes.
- Resolves base and target once; prints both full commit IDs and uses them for
  ancestry, drift comparison, staging archive, release ref and verification.
- Rejects older or divergent targets relative to the supplied base.
- Holds one `flock` for the complete operation; concurrent invocations abort.
- Rejects used release names and compare-and-creates the ref with `update-ref`;
  another Git writer cannot cause an existing release to be overwritten.
- Rechecks touched live files after backup/lint, before writes, to preserve edits
  made directly to those files while preparation was in progress.
- Stops on a failed staging copy; bounds HTTP health calls and reports transport
  errors as unhealthy with the rollback location.
- Records exact commit IDs in rollback guidance and forbids blindly restoring
  a pre-release DB dump over newer transactions.

## Contract, permission and compatibility

Read-only helper, Bash + Git:

```bash
bash deploy/release-preflight.sh <git-dir> <base-ref> <target-ref> <release-name>
```

Exit 0 prints exactly two full commit IDs (base then target). Invalid refs,
ancestry, repository or release name return nonzero (normally 2), diagnostics
go to stderr. The helper reads Git only; it does not approve or execute releases.

The existing production CLI retains its three arguments and optional `--dry-run`.
An already-used release name must now be replaced with a new unique name; no
`branch -f` escape hatch is provided. Bash, Git and `flock` are required on VPS.
Deploy authority is the existing VPS operator plus explicit owner approval.

The two scripts must be reviewed/shipped together. Run the reviewed checkout's
deployer rather than assuming a previously installed script includes this guard.
No web/Android API, navigation registration or localization keys are needed for
this operator-only increment. Existing clients and URLs remain untouched.
There is no remotely toggleable flag: a production safety check cannot be disabled
by client configuration. Execution itself remains manual and owner-approved.
No database migration is required.

## Verification

```bash
bash -n deploy/deploy-prod.sh deploy/release-preflight.sh
python3 tests/release-safety-test.py
node tests/offline-ticket-test.js
node tests/lazy-retry-test.js
node tests/i18n-check.js
git diff --check
```

19 new tests pass locally. They create temporary Git repositories and filesystem
webroots and run a path-substituted copy of the actual deployer. VPS commands
(`mysqldump`, `systemctl`, `chown`, `curl`) are stubs. This verifies guard behavior,
not real backup/reload/HTTP correctness. CI registration was added to the existing
`Application checks` workflow; hosted execution is not yet verified.

Before production approval, run the normal PHP/DB battery, test on staging with
the actual service binaries, verify root-owned lock permissions and that every
operator uses this deployer, and perform a staging dry run/health failure/code
rollback. Check both public and authenticated booking/finance paths. Record
the current base and exact candidate SHA; never use the old default branch.

## Limits and remaining work

- No authoritative complete live commit inventory exists yet. The operator must
  supply the real base; touched-file drift checks do not validate untouched files.
  No claim is made that a whole-site downgrade is now impossible.
- The lock serializes this deployer only. A direct SCP/editor can still write
  after the final drift check. Eliminate bypass deployment paths operationally;
  later add an authenticated release manifest and approved atomic directory swap.
- Existing atomicity is per file, not a transaction across the whole release.
  Failures midway may leave a partial code release. Health failure is reported,
  not automatically rolled back. Release refs remain reserved after such failures.
- Existing manual migration/deletion behavior is preserved; this patch does not
  automate migrations, restore DB snapshots or run finance corrections.
- SW/cache version discipline is unchanged because no frontend file changed.
  This does not yet provide native Android version negotiation or feature registry.

## Release and rollback

No production release has been executed. This branch is for review and staging.
After all required evidence is available, request owner approval for that exact
base, target and release label. Do not equate approval of the architecture with
approval to release this or later code.

To discard this development change, revert its commit on a development branch.
For a deployed release, follow its generated `ROLLBACK.txt`: restore only the
backed-up code files, review newly added files before removal, restore ownership,
reload PHP-FPM and recheck the relevant app workflows. No database restore is
required. Do not reuse or force an existing release label after a failed attempt.

## Changelog

- `1.0.0`: pinned commit planning, deploy serialization, unique release names,
  second live drift check, strict dry-run parsing, bounded health checks,
  explicit data-preserving rollback guidance and offline integration tests.
