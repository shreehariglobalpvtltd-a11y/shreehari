-- =====================================================================
--  upgrade-2026-09-ai-admin.sql — the assistant grows an office side.
--  28 Sep 2026.
--
--  Until now the tool-using assistant (includes/aiagent.php +
--  includes/aitools.php) could only be reached from WhatsApp, and only
--  by a number that matches a row in `admins`. The owner asked for an
--  ADVANCED agent that answers any office question, sends pictures,
--  keeps a record of the business, carries word back to the admin and
--  keeps watch — reachable from WhatsApp AND from inside the panel.
--
--  Almost none of that is new machinery. AiAgent, AiTools and AiTurn
--  already read the register, gate by role and record every press in
--  `ai_agent_calls`. What was missing was a way in that is a SESSION
--  rather than a phone number, a few more read buttons, and somewhere
--  to freeze each day's numbers so "this week against last week" is a
--  fact rather than a re-derivation.
--
--  Additive and re-runnable: one table, settings rows that all ship OFF.
--  Nothing changes until ai_panel_on = 1.
--
--  ---------------------------------------------------------------
--  Where the ai_* tables stand after this migration, so the next
--  person does not have to grep to find out:
--
--    used now      ai_day_facts (below), ai_agent_calls,
--                  ai_kb_articles, ai_unanswered_questions
--
--    reserved,     ai_generated_assets  — tracks files on disk with
--    deliberately    checksums and expiries. The chart endpoint renders
--    unused          on demand and stores nothing, so there is no file
--                    to track. Every mint is already in ai_agent_calls.
--                  ai_handoffs — shaped for "the AI gave up on a
--                    customer, a human takes over": a support queue
--                    with conversation_id and assigned_to. Proactive
--                    office reporting goes to health_incidents, which
--                    already has a screen (admin/health.php), a
--                    resolve-when-clean lifecycle and a reader (the
--                    office_alerts tool).
--                  ai_daily_metrics — day / requests / reserved_cost.
--                    That is AI COST metering, not business facts.
--                  ai_conversations, ai_messages, ai_jobs,
--                  ai_action_confirmations, ai_provider_usage,
--                  ai_provider_state, ai_feedback, domain_events,
--                  ai_kb_sources, ai_kb_categories — all from the
--                    half-built gen-2 layer. Left in place: they are
--                    empty and cost nothing, and dropping tables on a
--                    live box to tidy a diagram is risk without reward.
--  ---------------------------------------------------------------
-- =====================================================================
SET NAMES utf8mb4;

-- One frozen row per completed day. cron/daily-summary.php already works
-- these numbers out to send the digest and then throws them away; this is
-- where they are kept. It matters that they are FROZEN: a booking
-- cancelled next week silently changes any aggregate re-derived from
-- `bookings`, so a day re-counted later is not the day the office lived.
--
-- `detail` carries the per-route and per-agent lines as JSON, so the
-- agent can answer "which route fell" without a second table.
CREATE TABLE IF NOT EXISTS `ai_day_facts` (
  `day`           DATE NOT NULL COMMENT 'the completed day these numbers describe',
  `bookings`      INT UNSIGNED NOT NULL DEFAULT 0,
  `seats`         INT UNSIGNED NOT NULL DEFAULT 0,
  `revenue`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `refunds`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `cancellations` INT UNSIGNED NOT NULL DEFAULT 0,
  `incidents`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'health_incidents opened that day',
  `detail`        JSON NULL COMMENT 'per-route and per-agent lines',
  `created_at`    DATETIME NOT NULL,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: no semicolons inside string literals — tests/apply-sql.php splits on them.
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('ai_panel_on',            '0','bool',  'ai','MASTER SWITCH - the AI Assistant page inside the admin panel. OFF = the page explains itself and answers nothing',0),
('ai_panel_daily_cap',     '200','int', 'ai','Answers per admin per day in the panel (a person never reaches it, a loop does)',0),
('ai_panel_max_tools',     '8', 'int',  'ai','How many tool calls one panel question may cost before the assistant must answer with what it has',0),
('ai_admin_tools_deep',    '0','bool',  'ai','The deeper office read tools - money over a range, one agent, health, audit, logins, activity, trend. OFF = the four original office buttons only',0),
('ai_image_on',            '0','bool',  'ai','Let the assistant draw a chart and send it as a picture. OFF = the same answer in words',0),
('ai_image_ttl',           '900','int', 'ai','Seconds a signed chart link stays valid before it expires',0),
('ai_facts_on',            '0','bool',  'ai','Keep one frozen row of yesterday numbers so this week can be compared with last week',0),
('ai_relay_on',            '0','bool',  'ai','Let the assistant carry word to the office WhatsApp on its own (a counter agent asking for the boss, a critical watch finding)',0),
('ai_relay_daily_cap',     '10','int',  'ai','Messages the assistant may send the office in one day - the brake on a loop',0),
('ai_watch_on',            '0','bool',  'ai','The watchman - every 30 min it reads the company own records and opens an incident for anything that looks wrong',0),
('ai_watch_refund_spike_pct','200','int','ai','Refunds today above this percent of the 30-day usual opens an incident. 200 = twice the usual',0),
('ai_watch_empty_pct',     '25','int',  'ai','A bus under this percent sold 24 hours before it leaves opens an incident',0),
('ai_watch_login_fails',   '8', 'int',  'ai','Failed sign-ins for one username within an hour before an incident is opened',0),
('ai_provider_preferred',  'anthropic','string','ai','When BOTH keys are set and ai_provider is auto, which brain wins. Tool use is the panel whole job, so Claude by default',0);

-- Read by includes/aigovernor.php since it was written, but never seeded,
-- so they could not be seen or switched from Admin -> Settings. Seeded at
-- the code's own defaults: nothing changes, they only become visible.
-- Shadow ON means a settings write is recorded and compared, never applied.
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('ai_governor_on',    '1','bool','ai','The gate every automated settings write must pass - a 14-key allow-list that refuses anything to do with fares, refunds, commission, wallets or seats',0),
('ai_governor_shadow','1','bool','ai','Shadow mode - an automated settings change is written to the journal and compared, but NOT applied. Leave ON until the journal reads sensibly',0);

-- The gen-2 "ai manager" layer was never wired up: includes/ai/bootstrap.php
-- requires ten modules and only five were ever written, so including it
-- would fatal, and nothing does. Its 17 settings rows are read by no PHP at
-- all. Moving them to their own group stops dead knobs sitting beside live
-- ones in Admin -> Settings, where turning one does nothing and looks broken.
-- The rows are KEPT, not deleted: reversible with one UPDATE, and deleting
-- settings on a live box buys nothing.
UPDATE `settings` SET `sgroup` = 'ai-unused' WHERE `skey` LIKE 'ai\_manager\_%';
