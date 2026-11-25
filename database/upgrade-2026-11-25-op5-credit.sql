-- 25 Nov 2026 (op5-credit): agent credit limit + "Stop selling" hold.
-- credit_limit NULL = follow the company default (agent_credit_limit_default, 0 = no limit);
-- 0 = no limit for this agent; > 0 = refuse new agent sales once what they owe reaches it.
-- The hold reuses admin_profiles.suspended_at / suspended_reason (already there).
-- AgentCredit::hasLimitColumn() keeps the app working (per-agent limit off) before this runs.
ALTER TABLE admin_profiles
    ADD COLUMN IF NOT EXISTS credit_limit DECIMAL(12,2) NULL DEFAULT NULL;

-- Seed rows so the Agent rules panel can edit them. Defaults = no change on deploy:
-- the block switch is ON but nothing is refused until a limit or a hold is set.
INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('agent_credit_block_on',      '1',  'bool',  'agent', 'Refuse new agent sales once dues reach the credit limit, or while the office has stopped selling', 0),
  ('agent_credit_limit_default', '0',  'float', 'agent', 'Company default agent credit limit (0 = no limit); a per-agent limit on Agent 360 wins', 0),
  ('agent_credit_warn_pct',      '80', 'int',   'agent', 'Warn the agent on their desk from this % of the credit limit', 0);
