-- =====================================================================
--  9 Oct 2026 - Meta-first WhatsApp routing + the local (no-AI) brain
--
--  Owner: "sabai kaam jaha badi jaso Meta ko direct free API use garos,
--  ticket ko bela matra AiSensy; AiSensy ma sasto utility template matra;
--  AI le website ko har detail Nepali ma bhanos, VPS ma free chalos".
--
--  Settings rows (group whatsapp -> the WhatsApp tab in Admin -> Settings):
--    wa_policy               meta_first | aisensy_only | meta_only
--                            (includes/waroute.php)
--    wa_meta_templates_on    templates on the Meta number first - switch ON
--                            when the Meta number's payment method / a new
--                            number works (until then Meta refuses them, 131042)
--    aisensy_utility_only    AiSensy never sends a MARKETING template by
--                            itself (campaigns still may, on purpose)
--    wa_local_brain_on       the local answers (bus live, seats left,
--                            timetable, bus/driver, my ticket, payment,
--                            refund rule, office numbers, staff summaries)
--                            answered on the VPS with no AI call
--    wa_staff_lang           the language staff are answered in when their
--                            message does not say (ne | hi | en | auto)
--    wa_driver_phone_on      a ticketed passenger may be told the driver's
--                            number on the travel day
--
--  No table changes. Re-running this file is harmless.
-- =====================================================================

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('wa_policy',             'meta_first', 'string', 'whatsapp', 'WhatsApp routing: meta_first = free replies on the Meta number inside the 24 h window, AiSensy utility template only when needed · aisensy_only = everything on AiSensy (before 9 Oct) · meta_only = never AiSensy', 0),
 ('wa_meta_templates_on',  '0',          'bool',   'whatsapp', 'Templates on the Meta number FIRST (switch ON once the Meta number has a working payment method / the new number is connected - until then Meta refuses templates with 131042)', 0),
 ('aisensy_utility_only',  '1',          'bool',   'whatsapp', 'AiSensy: send only UTILITY / AUTHENTICATION templates automatically - a MARKETING template (6x the price) goes out only from Campaigns', 0),
 ('aisensy_scope',         'ticket_only','string', 'whatsapp', 'AiSensy pays for: ticket_only = the customer''s ticket to a number with no open Meta window, nothing else (OTP, bus updates, staff notes, compose, seller copies and campaigns go free on Meta or wait for a hi) - all = every send as before 9 Oct 2026', 0),
 ('wa_local_brain_on',     '1',          'bool',   'whatsapp', 'Local brain: bus live position, seats left, timetable, bus & driver, my ticket, how to pay, refund rule, office numbers, staff summaries - answered on the VPS, no AI, no cost', 0),
 ('wa_staff_lang',         'ne',         'string', 'whatsapp', 'Language for agents / office on WhatsApp when their message does not say: ne (Nepali) · hi · en · auto (follow the message)', 0),
 ('wa_driver_phone_on',    '1',          'bool',   'whatsapp', 'A passenger with a confirmed ticket may be told the driver / crew number on the travel day (staff always may)', 0);
