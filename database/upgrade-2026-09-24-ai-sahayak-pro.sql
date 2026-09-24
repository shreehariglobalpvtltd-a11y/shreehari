-- =====================================================================
--  upgrade-2026-09-24-ai-sahayak-pro.sql — SHG Sahayak on the WEBSITE
--  and in the APP, with the same hands it has on WhatsApp (24 Sep 2026).
--
--  Owner ask (24 Sep): "mero website ko AI lai advance banau — sabai kura
--  ko answer deos, image, report, graph, real-time data (kati customer le
--  visit gare, kati ticket bikri bhayo), Agent / Admin / Customer teen
--  wotai role, WhatsApp bata pani, app bata pani; Hindi / English /
--  Nepali; travel ra company reputation ma dhyan".
--
--  Until now the website chat (api/ai-proxy.php) was a plain text relay:
--  no tools, no role, no register — a passenger could ask the fare but
--  not "mero ticket kaha cha?", and the office could not ask "aaja kati
--  bikri bhayo?" from the same box. The WhatsApp assistant (20 Sep) had
--  all of that. This upgrade points the website and the admin panel at
--  the SAME agent loop (includes/aiagent.php + includes/aitools.php),
--  with the signed-in session deciding the role instead of the phone
--  number, and adds the REPORT tools every channel now shares:
--  sales_report, site_visitors, occupancy_report, agent_leaderboard,
--  record_feedback. Charts come back as data (Chart.js in the browser)
--  and as a PNG on WhatsApp (includes/aichart.php).
--
--  Additive and re-runnable: settings rows only. Reads work the moment
--  a key is present; SELLING from the website chat stays OFF until
--  ai_web_sell = 1, exactly like wa_agent_sell on WhatsApp.
-- =====================================================================
SET NAMES utf8mb4;

-- NOTE: no semicolons inside string literals — tests/apply-sql.php splits on them.
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('ai_web_agent_on',   '1',  'bool',  'ai','WEBSITE + APP assistant with tools (reads the register, reports, graphs, role by sign-in). OFF = the plain question-answer relay of before',0),
('ai_web_sell',       '0',  'bool',  'ai','Let the website / app chat ISSUE a ticket after the passenger confirms the fare it quoted. OFF = it quotes and hands over to the booking screen',0),
('ai_web_daily_cap',  '80', 'int',   'ai','Website assistant answers per visitor per day (staff get five times this)',0),
('ai_web_max_tools',  '6',  'int',   'ai','How many tool calls one website message may cost before the assistant answers with what it has',0),
('ai_reply_langs',    'ne,hi,en','string','ai','Languages the assistant answers in, in order of preference (ne = Nepali, hi = Hindi, en = English, gu = Gujarati)',0),
('ai_chart_keep_days','3',  'int',   'ai','Days a chart PNG made for WhatsApp stays under uploads/ai-charts before cron/rotate.php removes it',0);

-- The WhatsApp assistant is the only place a passenger can be sold a
-- ticket by the AI today. Its switches are untouched here.
