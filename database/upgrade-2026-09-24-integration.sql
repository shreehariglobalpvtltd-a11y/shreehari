-- ============================================================
--  Integration of the four 24 Sep 2026 branches (owner: "sabai merge garera
--  milayera deploy gardeu"). Only what the merge itself needed: one switch
--  so that two features that did the same job never show at once.
--  Re-runnable: INSERT IGNORE only. Every switch ships OFF.
-- ============================================================
SET NAMES utf8mb4;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('contact_dial_on', '0', 'bool', 'app', 'One contact button (call / WhatsApp / Sahayak / call me back) in place of the green WhatsApp button', 1);

-- M2 of the money guards: only a counter AGENT accrues commission and cash_due.
-- OFF keeps today's books (the owner's and the counter's sales write rows too).
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('commission_agent_only', '0', 'bool', 'agents', 'Only agents earn commission / owe cash in the agent ledger (off = office and counter sales are recorded there too, as before)', 0);
