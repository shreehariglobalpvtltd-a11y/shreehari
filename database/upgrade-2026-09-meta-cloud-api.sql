-- =====================================================================
--  upgrade-2026-09-meta-cloud-api.sql — Meta WhatsApp Cloud API (direct).
--
--  Adds the settings rows whatsapp/config.php reads. Idempotent
--  (INSERT IGNORE). NO secret values here: the access token, app secret and
--  verify token are entered in Admin -> Settings (or as php-fpm env vars
--  META_ACCESS_TOKEN / META_APP_SECRET / WA_WEBHOOK_TOKEN), never in git.
--
--  Switching the sender is ONE row: whatsapp_driver = 'cloud_api'.
--  Rolling back is the same row: whatsapp_driver = 'twilio'. Twilio SMS is
--  independent of this and keeps working either way.
-- =====================================================================

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('whatsapp_waba_id',              '', 'string', 'whatsapp', 'Meta Cloud API: WhatsApp Business Account ID', 0),
('whatsapp_app_secret',           '', 'string', 'whatsapp', 'Meta Cloud API: App Secret (verifies webhook signatures)', 0),
('whatsapp_webhook_verify_token', '', 'string', 'whatsapp', 'Meta Cloud API: webhook verify token (same value as in the Meta app)', 0),
('whatsapp_bot_enabled',          '1', 'bool',  'whatsapp', 'Meta Cloud API: auto-reply to incoming WhatsApp messages (PNR bot)', 0);

-- Graph API version the code is tested against.
UPDATE `settings` SET `svalue` = 'v25.0' WHERE `skey` = 'whatsapp_api_version' AND `svalue` IN ('', 'v21.0');
