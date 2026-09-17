-- =====================================================================
--  Upgrade 2026-09 — Agent rules become editable from Admin -> Settings
--  17 Sep 2026
--
--  Run ONCE on the live database (phpMyAdmin -> SQL tab -> paste -> Go),
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-09-agent-rules.sql
--
--  Fully idempotent: INSERT IGNORE only. A row that already exists (for
--  example agent_flat_direct, which the Agent Panel may have created
--  lazily on first save) keeps its current value untouched.
--
--  WHY
--  ---
--  admin/settings.php only renders and saves rows that EXIST — unknown
--  keys are ignored by Settings::setMany so a stray form field can never
--  create configuration. That safety had a side effect: the commission
--  mode (agent_commission_mode) was read by the engine with a code default
--  and had no row, so it could not be changed from the panel at all, and
--  the newer money rules had nowhere to live. Seeding the rows is what
--  makes the "Agent rules" panel work.
--
--  EVERY DEFAULT BELOW IS TODAY'S BEHAVIOUR, so running this changes
--  nothing until the office edits a value:
--    agent_commission_mode     flat_per_seat  (₹200 / ₹400 per passenger)
--    agent_flat_direct/joint   200 / 400
--    agent_advance_max         0   = no cap on advances
--    agent_advance_recover_pct 100 = commission nets an advance in full
--    agent_cash_limit_enforce  0   = cash limit warns, never blocks
--    agent_payout_min          0   = any payout amount
--    agent_settlement_due_days 0   = no "settlement overdue" flag
--    agent_kyc_required        0   = KYC is informational only
--    agent_notify_settlement   1   = WhatsApp the agent on payout/handover
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('agent_commission_mode',     'flat_per_seat', 'string', 'agent', 'Commission scheme: flat_per_seat (₹ per passenger by tier) or percent (% of ticket value)', 0),
('agent_flat_direct',         '200',           'float',  'agent', 'Flat commission per passenger — direct agent (₹)', 0),
('agent_flat_joint',          '400',           'float',  'agent', 'Flat commission per passenger — team / organisation agent (₹)', 0),
('agent_advance_max',         '0',             'float',  'agent', 'Maximum advance/loan outstanding per agent in ₹ (0 = no cap)', 0),
('agent_advance_recover_pct', '100',           'float',  'agent', 'Share of each payout used to recover an advance, % (100 = today: commission nets the advance in full)', 0),
('agent_cash_limit_enforce',  '0',             'bool',   'agent', 'Block selling once cash in hand exceeds the agent cash limit (off = warn only)', 0),
('agent_payout_min',          '0',             'float',  'agent', 'Minimum payout amount in ₹ (0 = any amount; paying the full balance is always allowed)', 0),
('agent_settlement_due_days', '0',             'int',    'agent', 'Days an agent may hold cash before it is flagged as settlement overdue (0 = never flag)', 0),
('agent_kyc_required',        '0',             'bool',   'agent', 'Agents must be KYC-verified before they can sell (off = KYC is informational only)', 0),
('agent_notify_settlement',   '1',             'bool',   'agent', 'WhatsApp the agent when a payout or cash handover is recorded', 0);
