-- 3 Oct 2026 - AiSensy WhatsApp settings (Admin - Settings - aisensy group)
-- Data rows only, no schema change. Re-runnable (INSERT IGNORE keeps edited values).
-- Secrets: AISENSY_API_KEY and AISENSY_WEBHOOK_TOKEN live in public_html/.env, which wins over these rows.
INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
('aisensy_mode', 'fallback', 'string', 'aisensy', 'AiSensy ticket send: off | fallback (only when main driver missed) | always', 0),
('aisensy_bot_enabled', '1', 'bool', 'aisensy', 'AiSensy number: answer customers with the WhatsApp AI bot', 0),
('aisensy_template_lang', 'en', 'string', 'aisensy', 'AiSensy template language code (en / en_US)', 0),
('aisensy_project_id', '6ac0f921f6a7bf2dfb5d9594', 'string', 'aisensy', 'AiSensy project ID', 0),
('aisensy_test_to', '919104801507', 'string', 'aisensy', 'AiSensy test page - send test messages to this number', 0),
('aisensy_api_key', '', 'string', 'aisensy', 'AiSensy Project API password (blank = use .env)', 0),
('aisensy_webhook_token', '', 'string', 'aisensy', 'AiSensy webhook ?token= (blank = use .env)', 0),
('aisensy_webhook_secret', '', 'string', 'aisensy', 'AiSensy X-AiSensy-Signature secret (optional)', 0),
('aisensy_test_secret', '', 'string', 'aisensy', 'AiSensy test page ?secret= (blank = default)', 0);
