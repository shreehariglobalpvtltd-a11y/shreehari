-- =====================================================================
--  Upgrade 2026-09 — Agent payout requests as durable records
--  17 Sep 2026
--
--  Run ONCE on the live database (phpMyAdmin -> SQL tab -> paste -> Go),
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-09-agent-payout-requests.sql
--
--  Fully idempotent and additive: CREATE TABLE IF NOT EXISTS only.
--
--  WHY
--  ---
--  A counter agent's "please pay out my commission" used to be an
--  audit_logs row, and "is it still pending" was inferred by comparing its
--  timestamp with the last payout. That gave the office no list of open
--  requests and no way to decline one. This table is the request itself:
--  open until a supervisor records the payout (status paid, ledger_id =
--  the agent_ledger row that settled it) or declines it with a note.
--
--  NOT the legacy schema.sql `payout_requests` table — that one belongs to
--  CUSTOMER referral agents (FK to users), not counter agents (admins).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `agent_payout_requests` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_admin_id` BIGINT UNSIGNED NOT NULL COMMENT 'admins.id of the counter agent',
  `amount`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `note`           VARCHAR(255) NULL COMMENT 'what the agent wrote',
  `status`         ENUM('open','paid','declined') NOT NULL DEFAULT 'open',
  `decided_by`     BIGINT UNSIGNED NULL COMMENT 'admins.id who paid / declined',
  `decided_at`     DATETIME NULL,
  `decided_note`   VARCHAR(255) NULL COMMENT 'what the office wrote back',
  `ledger_id`      BIGINT UNSIGNED NULL COMMENT 'agent_ledger.id of the payout that settled it',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_payout_req_agent`  (`agent_admin_id`,`status`,`created_at`),
  KEY `ix_payout_req_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
