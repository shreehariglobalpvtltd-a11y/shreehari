-- =====================================================================
--  Upgrade 2026-09 — Agent loans / advances register
--  17 Sep 2026
--
--  Run ONCE on the live database (phpMyAdmin -> SQL tab -> paste -> Go),
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-09-agent-loans.sql
--
--  Fully idempotent and additive: CREATE TABLE IF NOT EXISTS + INSERT
--  IGNORE only. Nothing existing is altered.
--
--  WHY A REGISTER AND NOT A NEW LEDGER ACCOUNT
--  --------------------------------------------
--  An advance has always been a plain 'adjustment' DEBIT on the agent's
--  commission account tagged ref 'ADVANCE …' (AgentWallet::recordAdvance).
--  That is the right money model — future commission nets it off by the
--  same SUM() the panel shows — but it gave the office no per-item view:
--  no principal, no "how much of THIS advance is still out", no dated
--  repayment rows, and no distinction between a short advance and a
--  formal loan.
--
--  This table is that per-item view. THE MONEY STAYS IN agent_ledger,
--  exactly as before: issuing a loan writes the usual recordAdvance()
--  debit with ref 'ADVANCE L<id>', a cash repayment writes the usual
--  recordAdvance() credit with the same ref, and the `recovered` column
--  here is a DISPLAY figure recomputed from the ledger
--  (AgentWallet::syncLoanRecovery). No balance is ever derived from this
--  table, so a wrong row here can never pay anyone the wrong amount.
--
--    kind           advance (short, against next commission) | loan
--    recover_mode   full    = every rupee of commission nets it off (today)
--                   fixed   = the office intends ₹recover_value per payout
--                   percent = recover_value % of each payout
--                   (fixed/percent are the office's stated intent, shown
--                   on the panel; the ledger netting itself is unchanged)
--    status         open | settled | written_off
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `agent_loans` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_admin_id` BIGINT UNSIGNED NOT NULL COMMENT 'admins.id of the counter agent',
  `kind`           ENUM('advance','loan') NOT NULL DEFAULT 'advance',
  `principal`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `issued_on`      DATE NOT NULL,
  `recover_mode`   ENUM('full','fixed','percent') NOT NULL DEFAULT 'full',
  `recover_value`  DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'rupees per payout (fixed) or % of payout (percent)',
  `recovered`      DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'cash repaid + commission allocated (display figure, synced from the ledger)',
  `status`         ENUM('open','settled','written_off') NOT NULL DEFAULT 'open',
  `note`           VARCHAR(255) NULL,
  `created_by`     BIGINT UNSIGNED NULL COMMENT 'admins.id who issued it',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `settled_at`     DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_loans_agent` (`agent_admin_id`,`status`,`issued_on`),
  KEY `ix_loans_status` (`status`,`issued_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cap on advances (0 = no cap, which is today's behaviour) — also seeded
-- by upgrade-2026-09-agent-rules.sql; INSERT IGNORE keeps whichever ran first.
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('agent_advance_max','0','float','agent','Maximum advance/loan outstanding per agent in ₹ (0 = no cap)',0),
('agent_advance_recover_pct','100','float','agent','Share of each payout used to recover an advance, % (100 = today: commission nets the advance in full)',0);
