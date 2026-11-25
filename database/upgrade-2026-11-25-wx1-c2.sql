-- =====================================================================
--  upgrade-2026-11-25-wx1-c2.sql  (wx1-c2 passenger extras, 25 Nov 2026)
--
--  Switches and values for the customer home's people + passenger extras:
--  all agents, the bus crew card, the travel guide card, luggage +
--  facilities, the short WhatsApp booking, PNR -> ticket download + track,
--  and the onward buses from Nepalgunj on /travel-info.
--
--  The code reads the same defaults (TravelGuide, api/staff-list.php, the
--  cp1 home script, 05-router.js), so a server without these rows behaves
--  exactly the same. The rows make the switches show in Admin -> Settings,
--  and is_public = 1 puts the home ones in SHG_BOOT.settings so an "off"
--  reaches the page without a deploy (Settings::set never changes is_public
--  of an existing row).
--
--  onward_buses_json starts EMPTY on purpose: no bus time, operator or
--  phone is made up - the office fills it on admin/owner-setup.php#onward.
--
--  Additive and safe to re-run (INSERT IGNORE never overwrites a value the
--  office already set). No table or column changes.
-- =====================================================================

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('home_staff_list_on',   '1', 'bool', 'general', 'Customer home: "Bus crew" card (driver, co-driver, conductor, office) from the website staff list', 1),
  ('home_travel_guide_on', '1', 'bool', 'general', 'Customer home: "Travel guide" card (border documents + onward buses links)', 1),
  ('home_luggage_card_on', '1', 'bool', 'general', 'Customer home: "Luggage & facilities" card', 1),
  ('home_wa_easy_on',      '1', 'bool', 'general', 'Customer home: "Easy WhatsApp booking" button (name + phone + passengers only)', 1),
  ('wa_short_booking_on',  '1', 'bool', 'general', 'WhatsApp request sheet: date and pickup are optional (folded) - off = both required again', 1),
  ('cp_track_download_on', '1', 'bool', 'general', 'Customer home PNR box: PNR + mobile -> download ticket, track bus, share live location', 1),
  ('luggage_free_kg',      '20', 'int', 'booking', 'Free hold luggage per passenger (kg) - home card, /travel-info, WhatsApp bot (terms section G)', 1),
  ('cabin_bag_kg',         '7',  'int', 'booking', 'Free cabin / hand bag per passenger (kg) - home card, /travel-info, WhatsApp bot (terms section G)', 1),
  ('bus_facilities_json',  '["AC स्लिपर · AC sleeper","मोबाइल चार्जिङ · Mobile charging","पिउने पानी · Drinking water","CCTV सुरक्षा · CCTV security"]', 'json', 'booking', 'Bus facilities shown on the customer home (one line each)', 1),
  ('travel_onward_on',     '1', 'bool', 'company', '/travel-info: "Onward travel from Nepalgunj" card', 0),
  ('onward_buses_json',    '[]', 'json', 'company', 'Onward buses from Nepalgunj [{to,to_ne,times,operator,phone,note}] - filled on Owner setup', 0);
