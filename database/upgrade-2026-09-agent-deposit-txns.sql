-- =====================================================================
--  Upgrade 2026-09 — Agent security-deposit transaction history
--  17 Sep 2026
--
--  Run ONCE on the live database (phpMyAdmin -> SQL tab -> paste -> Go),
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-09-agent-deposit-txns.sql
--
--  Fully idempotent and additive: CREATE TABLE IF NOT EXISTS only.
--
--  WHY
--  ---
--  The security deposit an agent lodges with the company lives as a
--  running {required, paid} pair in the `agent_deposits` setting
--  (AgentWallet::depositInfo). That pair is still the source of truth for
--  "is the deposit met" — nothing about it changes — but a pair has no
--  history: the office could not answer "when did they pay the second
--  ₹5,000, and who took it?". Every recordDeposit() call now also writes
--  one dated row here (a refund is a negative amount). Deliberately NOT
--  in agent_ledger: the ledger's `account` ENUM is commission|cash and a
--  deposit is neither (see the SECURITY DEPOSIT note in agentwallet.php).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `agent_deposit_txns` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_admin_id` BIGINT UNSIGNED NOT NULL COMMENT 'admins.id of the counter agent',
  `amount`         DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'SIGNED: + received from the agent, − refunded to them',
  `ref`            VARCHAR(80)  NULL COMMENT 'receipt / UPI ref',
  `note`           VARCHAR(255) NULL,
  `created_by`     BIGINT UNSIGNED NULL COMMENT 'admins.id who recorded it',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_deposit_agent` (`agent_admin_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
