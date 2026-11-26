-- =====================================================================
--  26 Nov 2026 (fx1, agent slice): agent home + sub-agent rules.
--
--  Settings rows only - no table, no column. Additive and idempotent
--  (INSERT IGNORE: an existing row, and whatever the owner set, is kept).
--  The code reads every key with Settings::getBool(key, true), so it
--  behaves the same before this file is applied; the rows only make the
--  four switches show in Settings with a label.
--
--    agent_home_on                     1 = admin/agent-home.php (the agent's wallet-style
--                                      home). 0 = that page forwards to admin/agent.php.
--    agent_subagent_office_approve_on  1 = counter staff an agent adds start PAUSED and only
--                                      the office (staff.manage) can switch them on / back on.
--                                      0 = active at once and the agent may resume them, as
--                                      before. (Who may add / pause / take cash is a fix and
--                                      has no switch.)
--    agent_credit_parent_hold_on       1 = a sub-agent cannot sell while the MAIN agent is on
--                                      hold or at the credit limit. 0 = each ledger alone.
--    agent_list_verified_first_on      1 = KYC-verified agents first on the agents register
--                                      and Agents & Staff. 0 = serial / name order as before
--                                      (the "KYC बाँकी" marker stays either way).
-- =====================================================================

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('agent_home_on',                    '1', 'bool', 'agent', 'एजेन्टको गृह पृष्ठ (मेरो हिसाब) · Agent home page (admin/agent-home.php)', 0),
  ('agent_subagent_office_approve_on', '1', 'bool', 'agent', 'एजेन्टले थपेको काउन्टर स्टाफ अफिसले खोलेपछि मात्र चल्छ · Counter staff added by an agent start paused until the office switches them on', 0),
  ('agent_credit_parent_hold_on',      '1', 'bool', 'agent', 'मुख्य एजेन्ट रोकिएको बेला सब-एजेन्टले पनि टिकट काट्न नपाउने · A sub-agent cannot sell while the main agent is on hold / at the limit', 0),
  ('agent_list_verified_first_on',     '1', 'bool', 'agent', 'एजेन्ट सूचीमा KYC प्रमाणित एजेन्ट पहिले · KYC-verified agents first on the agent lists', 0);
