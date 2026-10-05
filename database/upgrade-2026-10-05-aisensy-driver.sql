-- 5 Oct 2026: AiSensy is the only WhatsApp sender (owner: "only AiSensy default").
-- Meta (cloud_api) refuses every message (131042) and Brevo SMS has no credits,
-- so WhatsApp goes through AiSensy and the SMS channel is switched off.
-- Roll back: whatsapp_driver = 'cloud_api', sms_enabled = '1'.
UPDATE settings SET svalue = 'aisensy', label = 'aisensy | click_to_chat | twilio | cloud_api | gupshup' WHERE skey = 'whatsapp_driver';
UPDATE settings SET svalue = '0' WHERE skey = 'sms_enabled';
