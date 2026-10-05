-- =====================================================================
--  6 Oct 2026 - AI marketing autopilot + AI learning
--  includes/mkautopilot.php, includes/ailearn.php, admin/ai-marketing.php,
--  cron/campaigns.php (already every minute - no crontab change).
--  Settings only. Additive; re-running this file is harmless.
-- =====================================================================

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('mk_auto_mode',       'auto', 'string', 'marketing', 'AI marketing: off | draft (you launch) | auto (sends by itself)', 0),
 ('mk_auto_daily',      '25',   'int',    'marketing', 'AI marketing: at most this many messages a day', 0),
 ('mk_auto_gap_days',   '10',   'int',    'marketing', 'AI marketing: never the same person again within N days', 0),
 ('mk_auto_reserve',    '25',   'int',    'marketing', 'AI marketing: rupees always kept in the AiSensy wallet for tickets', 0),
 ('mk_auto_hour',       '10',   'int',    'marketing', 'AI marketing: daily run starts at this hour', 0),
 ('mk_auto_nepal',      '0',    'bool',   'marketing', 'AI marketing: also +977 numbers (Meta charges ~7x)', 0),
 ('mk_auto_ai_copy',    '1',    'bool',   'marketing', 'AI marketing: the AI writes the offer line', 0),
 ('mk_auto_lang',       'ne',   'string', 'marketing', 'AI marketing: ne | hi', 0),
 ('mk_auto_offer',      '',     'string', 'marketing', 'AI marketing: the only offer the AI may mention', 0),
 ('mk_auto_link',       '',     'string', 'marketing', 'AI marketing: link in the message (blank = website)', 0),
 ('mk_auto_template',   'shg_offer', 'string', 'marketing', 'AI marketing: approved MARKETING template {{1}} name {{2}} line {{3}} link', 0),
 ('mk_auto_festivals',  '2026-10-21 | दशैं (विजया दशमी)\n2026-11-08 | तिहार / दीपावली\n2026-11-15 | छठ\n2027-03-22 | होली', 'string', 'marketing', 'AI marketing: festivals, one per line YYYY-MM-DD | name', 0),
 ('ai_learn_on',          '1', 'bool', 'ai', 'AI learns from customer questions (drafts knowledge articles nightly)', 0),
 ('ai_learn_autopublish', '0', 'bool', 'ai', 'Publish learned answers without an OK (asked 3+ times)', 0),
 ('ai_learn_hour',        '2', 'int',  'ai', 'AI learning: nightly run after this hour', 0),
 ('ai_learn_per_night',   '6', 'int',  'ai', 'AI learning: questions studied per night', 0);
