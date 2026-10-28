# Recovery increment — 28 October 2026

This isolated recovery checkout starts from 739f95ec26fc521ced560d369f7c86a4c03839a8, the recovered live source. It is not the production webroot. Existing release checkout work is preserved separately.

Production was inspected through authenticated browser and SSH. Its stale HEAD 2d76f26 does not capture the drifted webroot. Private full-webroot and transaction-consistent DB backups are in /root/backups/phase0-agent-erp-20261028-131045. Restore to a disposable database verified 147 tables, five triggers and matching core counts. Hostinger snapshot 427873 expires 29 October 2026 at 13:12 IST.

Prepared repairs: load existing LiveSync and Brand in the admin guard; restore existing admin helper functions and WhatsApp preview handler; derive staff references from staff application IDs; avoid a secondary headers-sent exception in HTML error handling. No production schema change or application deployment occurred.

Staging lives in /var/tmp/shg-agent-recovery-20261028 with separate Docker DB and internal network, synthetic data and no production configuration. Eight controller renders pass; 299 PHP syntax checks pass. Seat geometry: 32 passed. Agent isolation: 35 passed. Seat/hold races: 10 passed. HTTP live-sync suite: 31 passed, two failed (legacy agent.php lacks journey-date panel and refresh anchor). Wallet suite: 11 passed, one failed because an old payout fixture omits the required confirmed payment reference. Preserve the finance guard.

Phase 0 deep control audit, policy protection of recovery branch, last-working release proof, full financial reconciliation, staging rollback rehearsal and mobile/exports/permission checks remain open. Production release requires explicit owner approval after the exact commit passes its required checks. Advanced ERP implementation remains pending critical recovery.
