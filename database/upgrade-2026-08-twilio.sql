-- =====================================================================
--  Upgrade: Twilio WhatsApp integration (2026-08)
--  Run this ONCE on an existing live database (hPanel -> phpMyAdmin ->
--  select your database -> SQL tab -> paste -> Go).
--
--  Safe to run more than once: INSERT IGNORE skips keys that already
--  exist, so it only adds the new Twilio settings and never overwrites
--  anything you have already configured.
-- =====================================================================

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('twilio_account_sid','','string','notify','Twilio Account SID',0),
('twilio_auth_token','','string','notify','Twilio Auth Token',0),
('twilio_whatsapp_from','','string','notify','Twilio WhatsApp sender e.g. whatsapp:+14155238886',0),
('twilio_webhook_url','','string','notify','Public webhook URL override for signature check (optional)',0),
('whatsapp_send_pdf','1','bool','notify','Attach the ticket PDF to WhatsApp confirmations',0),
('whatsapp_default_country','91','string','notify','Default country code for 10-digit numbers',0);

-- Refresh the driver hint so the Settings screen documents the new option.
UPDATE `settings`
   SET `label` = 'click_to_chat | twilio | cloud_api'
 WHERE `skey` = 'whatsapp_driver';
