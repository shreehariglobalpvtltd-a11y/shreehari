-- =====================================================================
--  Multi-provider AI ladder for the WhatsApp / website assistant
--  (23 Sep 2026).  Additive and re-runnable.
--
--  Adds settings for Grok (xAI), OpenRouter, and Cerebras API keys
--  alongside the existing Anthropic + Gemini keys.  The ai_model_ladder
--  setting controls which providers are tried and in what order.
--
--  Read by includes/aiagent.php.  Each key is masked in Admin Settings
--  (the settings.php page already treats any key matching "api_key" as
--  a password field).
-- =====================================================================

INSERT IGNORE INTO settings (skey,svalue,stype,sgroup,label,is_public) VALUES
 ('grok_api_key','','text','ai','Grok (xAI) API key — from console.x.ai',0),
 ('openrouter_api_key','','text','ai','OpenRouter API key — from openrouter.ai/keys',0),
 ('cerebras_api_key','','text','ai','Cerebras API key — from cloud.cerebras.ai',0),
 ('ai_model_ladder','auto','text','ai','AI provider order: auto (recommended) or comma list e.g. cerebras,grok,gemini,openrouter,anthropic',0),
 ('grok_model','','text','ai','Grok model pin (blank = grok-3-mini-fast)',0),
 ('openrouter_model','','text','ai','OpenRouter model pin (blank = meta-llama/llama-4-scout)',0),
 ('cerebras_model','','text','ai','Cerebras model pin (blank = llama-4-scout-17b-16e-instruct)',0),
 ('ai_timeout_message','1','bool','ai','Send a fallback message when all AI providers fail (instead of silence)',0);
