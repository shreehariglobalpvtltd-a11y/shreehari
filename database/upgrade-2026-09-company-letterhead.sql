-- =====================================================================
--  Upgrade 2026-09 — the company letterhead as editable rows
--
--  Run ONCE on the live database:
--      php -c .claude/php-dev.ini tests/apply-sql.php database/upgrade-2026-09-company-letterhead.sql
--
--  Owner ask, 10 Sep 2026: "logo name details haru ramro sita manage hos."
--
--  Settings::company() reads the company's identity for every printed
--  document (ticket, chalani PNG pages, chalani PDF, challan). Its
--  built-in fallbacks are exactly the strings those documents used to
--  carry hard-coded, so nothing on paper changes when this runs — it
--  only makes the facts EDITABLE: admin/settings.php renders whatever
--  rows exist, so a row that is missing simply cannot be changed from
--  the panel.
--
--  Idempotent: INSERT IGNORE keyed on the unique skey, so an existing
--  value (company_address, company_cin, company_email ... already live
--  here) is never overwritten. Re-running is a no-op.
--
--  Nothing here touches a booking, a seat, a fare or a commission. Drop
--  every row again and every document still prints — on the fallbacks.
-- =====================================================================

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('company_legal',        'S Hari Global Private Limited',
   'string', 'company', 'Registered (legal) name — printed on the chalani letterhead', 0),

  ('company_legal_ne',     'एस हरि ग्लोबल प्राइभेट लिमिटेड',
   'string', 'company', 'Registered name in Nepali — chalani PDF / print sheet letterhead', 0),

  ('company_address',      'Near Shilpa Garage, Silver Complex, Mehsana - 384002, Gujarat, India',
   'string', 'company', 'Head office address (English) — chalani PNG letterhead', 0),

  ('company_address_ne',   'प्रधान कार्यालय : शिल्पा ग्यारेज नजिक, सिल्भर कम्प्लेक्स, मेहसाणा – ३८४००२, गुजरात, भारत',
   'string', 'company', 'Head office address (Nepali) — chalani PDF letterhead', 0),

  ('company_operator',     'Sher Bahadur Bishwakarma',
   'string', 'company', 'Operator / proprietor name on documents (English)', 0),

  ('company_operator_ne',  'शेर बहादुर विश्वकर्मा',
   'string', 'company', 'Operator / proprietor name on documents (Nepali)', 0),

  ('company_web',          'shreehariglobal.in',
   'string', 'company', 'Website as printed on tickets and the chalani', 1),

  ('company_counters',     'Mehsana +91 91048 01507 · Ahmedabad +91 91570 01507 · Baroda +91 97264 01507 · Surat +91 73059 01507',
   'string', 'company', 'Booking counter numbers — the chalani footer line', 0);
