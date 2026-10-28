# Recovery increment — 28 October 2026

This isolated recovery checkout starts from 739f95ec26fc521ced560d369f7c86a4c03839a8, the recovered live source. It is not the production webroot. Existing release checkout work is preserved separately.

Production was inspected through authenticated browser and SSH. Its stale HEAD 2d76f26 does not capture the drifted webroot. Private full-webroot and transaction-consistent DB backups are in /root/backups/phase0-agent-erp-20261028-131045. Restore to a disposable database verified 147 tables, five triggers and matching core counts. Hostinger snapshot 427873 expires 29 October 2026 at 13:12 IST.

Prepared repairs: load existing LiveSync and Brand in the admin guard; restore existing admin helper functions and WhatsApp preview handler; derive staff references from staff application IDs; avoid a secondary headers-sent exception in HTML error handling. No production schema change or application deployment occurred.

Staging lives in /var/tmp/shg-agent-recovery-20261028 with separate Docker DB and internal network, synthetic data and no production configuration. Eight controller renders pass; 299 PHP syntax checks pass. Seat geometry: 32 passed. Agent isolation: 35 passed. Seat/hold races: 10 passed. HTTP live-sync suite: 31 passed, two failed (legacy agent.php lacks journey-date panel and refresh anchor). Wallet suite: 11 passed, one failed because an old payout fixture omits the required confirmed payment reference. Preserve the finance guard.

## Live recovery and reliability continuation

The owner authorized live recovery after reviewing the prepared patch. Guarded release `release-20261028-agent-recovery1` deployed b66096a at 19:35 IST. Dry-run reported four files, no deletions/migrations, no touched-file drift. Fresh code/DB backup: /root/backups/pre-deploy-release-20261028-agent-recovery1-20261028-193506. All four deployed file fingerprints match the commit. Public home/login return 200; production core counts remain 40 accounts, 747 bookings, five buses, 1,188 ledger rows. Browser admin session expired, so authenticated production UI verification awaits the owner's sign-in.

Reliability continuation restores a bounded, own-seller journey list and refresh anchor in the existing Agent Panel, preserves historical passenger rows without leg IDs, and pins agent scope even with custom dashboard permission. Read-only CLI preflight checks 20 dependencies/schema/seat-uniqueness/sync conditions; it refuses HTTP execution and incomplete checks exit nonzero. Run `php tools/admin-recovery-preflight.php` before and after future releases, plus the render and isolation suites. It does not automatically monitor or repair production.

Staging results after these changes: live-sync HTTP suite 35/35 including journey content and cross-agent privacy; wallet 65/65 after fixing its stale test fixture and adding missing-reference/duplicate-reference checks; agent isolation 35/35; controller rendering 8/8; read-only preflight 20/20. Application finance guards were not weakened. No schema migration is required.

Phase 0 deep control audit, policy protection of recovery branch, full financial reconciliation and mobile/exports/permission checks remain open. Advanced ERP implementation and broader polishing are incomplete. No live money, booking, refund, password-reset or notification action was performed.
