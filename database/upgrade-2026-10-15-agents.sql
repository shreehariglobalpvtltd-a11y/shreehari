-- =====================================================================
--  15 Oct 2026 - Agent management (owner, Feature 5): quick add, never
--  delete, bulk settlement, two-person adjustments, cash-limit highlight,
--  low-performer flag.
--
--      php tests/apply-sql.php database/upgrade-2026-10-15-agents.sql
--
--  agent_adjust_two_step   OFF by default = today's behaviour exactly. On,
--                          for everyone but a super-admin: a commission
--                          add / deduct is staged on the agent's draft
--                          settlement (AgentSettlement::stageAdjustment)
--                          instead of hitting the ledger, a cash-account
--                          correction needs a super-admin, and the person
--                          who drafted or changed a settlement's money
--                          cannot approve it. Money still reaches the
--                          ledger only through "Mark paid".
--  agent_low_perf_pct      the ranking board's Low flag: an active agent
--                          below this % of agent_target_monthly (scaled to
--                          the window) - only when a target is set. Display
--                          only.
--  agent_settlements.adjusted_by
--                          who changed a draft's deductions / bonus / cash
--                          lines after it was drafted ("5,9") - the
--                          two-person rule refuses them as approver too.
--                          Nullable, written only while the rule's code
--                          runs - nothing reads it with the rule off.
--
--  Additive only: one new nullable column, two settings rows. Nothing is
--  updated or deleted. Re-running is harmless.
-- =====================================================================

ALTER TABLE `agent_settlements` ADD COLUMN IF NOT EXISTS `adjusted_by` VARCHAR(255) NULL DEFAULT NULL COMMENT 'admins.id list of who changed the money lines after drafting (two-person rule)' AFTER `created_by`;

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('agent_adjust_two_step', '0',  'bool', 'agent', 'Two-person rule: a commission add / deduct by anyone but a super-admin goes onto the agent draft settlement for a DIFFERENT person to approve, and cash-account corrections need a super-admin (0 = off, the behaviour before 15 Oct 2026)', 0),
 ('agent_low_perf_pct',    '30', 'int',  'agent', 'Agent ranking: flag an active agent as Low below this % of the monthly target for the window (only when agent_target_monthly is set - display only)', 0);
