-- 31 Oct 2026 - Meta Facebook Messenger credentials.
--
-- New, non-public settings rows so admin/settings.php exposes masked fields
-- for the Facebook Page token + App Secret the Messenger webhook needs. The
-- WhatsApp Cloud API keys already exist (whatsapp_api_token, whatsapp_phone_id,
-- whatsapp_app_secret, whatsapp_webhook_verify_token) and are left untouched.
--
-- INSERT IGNORE: creates each key only when absent, so re-running or deploying
-- over a filled value never wipes what the owner saved. All is_public = 0, so
-- Settings::publicSettings() never exports them to the browser, and every key
-- whose name matches token/secret renders write-only (masked) in the form.
INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, is_public) VALUES
  ('fb_page_token',         '',  'string', 'messenger', 0),
  ('fb_app_secret',         '',  'string', 'messenger', 0),
  ('fb_verify_token',       '',  'string', 'messenger', 0),
  ('fb_page_id',            '',  'string', 'messenger', 0),
  ('messenger_bot_enabled', '0', 'bool',   'messenger', 0);
