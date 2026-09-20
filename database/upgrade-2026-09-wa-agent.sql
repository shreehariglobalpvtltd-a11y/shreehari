-- =====================================================================
--  upgrade-2026-09-wa-agent.sql — SHG Sahayak on WhatsApp, with hands
--  (includes/aiagent.php + includes/aitools.php). 20 Sep 2026.
--
--  Until now the WhatsApp bot could only READ: a PNR gave a status, a
--  booking request became a draft the desk had to confirm by hand, and
--  every other sentence went to Claude as plain chat. The owner asked for
--  an assistant that finishes the job — cuts the ticket in one or two
--  messages, answers in Nepali, shows an agent their own book and the
--  office its own day — and that reads the company's OWN live data rather
--  than a paragraph typed into a prompt.
--
--  So the model is given TOOLS, and every tool is a call into the code
--  that already exists: QuickTicket::plan()/sellCustomer()/sell(),
--  BookingService::cancel(), Notify::resendTicketWhatsApp(),
--  AgentWallet::summary(). No new path writes a seat, prices a fare or
--  lifts a rule — the model only decides WHICH of the desk's own buttons
--  to press, and every press is recorded in `ai_agent_calls` below.
--
--  Additive and re-runnable: one table, one view-free index, settings
--  rows that all ship OFF. Nothing changes until wa_agent_on = 1.
-- =====================================================================
SET NAMES utf8mb4;

-- Every tool the assistant ran, for the owner to read back: who asked,
-- as what role, which tool, whether it worked, how long it took and the
-- booking it touched. Arguments are stored WITHOUT free text the
-- passenger typed (no message bodies here — those stay in message_logs).
CREATE TABLE IF NOT EXISTS `ai_agent_calls` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` DATETIME NOT NULL,
  `phone`      VARCHAR(20) NOT NULL COMMENT 'normalised sender, the identity every channel agrees on',
  `role`       ENUM('customer','staff','admin') NOT NULL DEFAULT 'customer',
  `channel`    VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
  `tool`       VARCHAR(40) NOT NULL,
  `args`       VARCHAR(500) NULL COMMENT 'JSON of the structured arguments only',
  `ok`         TINYINT(1) NOT NULL DEFAULT 0,
  `detail`     VARCHAR(255) NULL COMMENT 'refusal reason or short result',
  `booking_id` BIGINT UNSIGNED NULL,
  `ms`         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_aiac_phone` (`phone`,`created_at`),
  KEY `ix_aiac_tool` (`tool`,`created_at`),
  KEY `ix_aiac_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: no semicolons inside string literals — tests/apply-sql.php splits on them.
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('wa_agent_on',         '0','bool',  'ai','MASTER SWITCH - the WhatsApp assistant may use tools (read the register, plan a ticket, answer in Nepali). OFF = exactly the bot of 19 Sep',0),
('wa_agent_sell',       '0','bool',  'ai','Let the assistant ISSUE a ticket on WhatsApp after the passenger confirms the fare it quoted. OFF = it only prepares the request for the desk',0),
('wa_agent_oneshot',    '0','bool',  'ai','Allow a ticket in ONE message (no separate confirm). Leave OFF - the fare should be read before a seat is taken',0),
('wa_agent_rewrite',    '1','bool',  'ai','Let a passenger fix the NAME on their own ticket from WhatsApp (twice per booking, before departure). The corrected ticket is re-sent',0),
('wa_agent_admin_write','0','bool',  'ai','Let the office confirm a payment / cancel a booking from WhatsApp. Reading always works - this is only for the write buttons',0),
('wa_agent_daily_cap',  '40','int',  'ai','Assistant answers per number per day (a human never reaches it, a loop does)',0),
('wa_agent_max_tools',  '6', 'int',  'ai','How many tool calls one message may cost before the assistant must answer with what it has',0),
('ai_provider',         'auto','string','ai','Which brain: auto (Claude, then Gemini), anthropic, or gemini',0),
('ai_agent_model',      'claude-sonnet-5','string','ai','Claude model for the WhatsApp assistant (fast + tool use)',0),
('gemini_api_key',      '',  'string','ai','Google Gemini API key - the fallback brain when Claude is unreachable',0),
('gemini_model',        'gemini-2.5-flash','string','ai','Gemini model used as the fallback',0);

-- The two rows the 2026-08 AI upgrade seeded under "notify" belong with
-- the rest of the AI settings now; a plain regroup, no value is touched.
UPDATE `settings` SET `sgroup` = 'ai' WHERE `skey` IN ('anthropic_api_key','ai_model');

-- Present since 20 Sep in code (includes/aichat.php) but never seeded, so
-- it could not be switched off from Admin -> Settings.
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('wa_ai_enabled','1','bool','ai','Answer a plain WhatsApp question with the AI (off = the old fixed menu)',0);
