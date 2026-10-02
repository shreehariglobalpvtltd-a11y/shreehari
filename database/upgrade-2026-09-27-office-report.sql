-- =====================================================================
--  upgrade-2026-09-27-office-report.sql — the office's WhatsApp report
--  at set times (cron/office-report.php, includes/officereport.php).
--
--  Idempotent (INSERT IGNORE). Safe while live. Ships OFF: the report
--  starts only when office_report_on is switched on in Admin -> Settings
--  -> Reports and the cron line is in the crontab.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
 ('office_report_on','0','bool','reports',
  'अफिस रिपोर्ट WhatsApp मा तोकिएको समयमा पठाउने · Send the office report on WhatsApp at the set times (buses today/tomorrow, seats, money, payments waiting).',0),
 ('office_report_times','07:00,13:00,20:00','string','reports',
  'रिपोर्ट पठाउने समय (IST, अल्पविरामले छुट्याउनुहोस्) · Report times, India time, comma separated.',0),
 ('office_report_numbers','','string','reports',
  'थप नम्बर (admin_whatsapp र ceo_whatsapp बाहेक) · Extra numbers besides admin_whatsapp and ceo_whatsapp.',0),
 ('office_report_last','','string','reports',
  'अन्तिम पठाइएको समय (आफैं भरिन्छ) · Last slot sent (filled by the cron).',0);
