-- =====================================================================
--  upgrade-2026-09-26-office-fallback-reports.sql
--
--  Owner, 26 Sep 2026:
--    "error bhayo bhane 9104801507 yo WhatsApp ma data send gardine, yo
--     name lai yo ticket send gardinu bhanera ticket link ra number deu"
--    "admin ma hi lekhera ... guide ni"
--    "saboi report admin le controllable"
--    "boot ma Bishnu Bhagwan ko naam mantra"
--
--  1. Two rows for the delivery fallback (Notify::deliveryFallback): when a
--     passenger's ticket cannot be delivered on WhatsApp, the office WhatsApp
--     and admin e-mail get the ticket link and a one-tap forward link.
--  2. The staff menu switch (WaBot::staffMenu).
--  3. The opening blessing switch, public so the app can read it.
--  4. Every report / office-alert switch moves into ONE settings group,
--     "reports", so the admin finds them on one panel of Admin -> Settings.
--     Only the grouping changes; no value changes.
--
--  Safe to re-run: INSERT ... ON DUPLICATE KEY UPDATE svalue = svalue keeps
--  whatever is already there. Labels use no semicolons (tests/apply-sql.php
--  splits on them).
-- =====================================================================

INSERT INTO settings (skey, svalue, stype, sgroup, is_public, label) VALUES
  ('wa_delivery_fallback_on', '1', 'bool', 'reports', 0,
   'Ticket not delivered on WhatsApp - alert the office WhatsApp and admin e-mail with the ticket link and a forward link'),
  ('wa_delivery_fallback_hours', '24', 'int', 'reports', 0,
   'Ticket-not-delivered alert - at most one per booking in this many hours'),
  ('wa_staff_menu_on', '1', 'bool', 'whatsapp', 0,
   'Staff who write hi / menu / help on WhatsApp get the staff menu with panel links'),
  ('app_mantra_on', '1', 'bool', 'site', 1,
   'App opening blessing - temple bell and the Vishnu mantra on the first touch (visitors can still switch it off in the menu)')
ON DUPLICATE KEY UPDATE svalue = svalue;

UPDATE settings SET sgroup = 'reports'
 WHERE skey IN ('admin_daily_digest_enabled', 'brain_digest_on', 'wa_report_chart_on',
                'whatsapp_notify_admin', 'low_seat_alert_enabled', 'pending_approval_alert_enabled',
                'admin_whatsapp', 'admin_email');
