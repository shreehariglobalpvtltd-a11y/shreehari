-- 26 Nov 2026 (fx1 counter desk: SmartBook staff face, Nepal counter home, desk-day list).
-- Six switches, all with the SAME default in code (Settings::getBool / getString), so the desk
-- behaves the same before and after this file runs. The rows only make the switches visible /
-- switchable in Admin -> Settings. None is public (staff pages only).
-- No schema change: the NPR an NPR desk collects is the existing bookings.fx_currency / fx_rate /
-- fx_total (upgrade-2026-10-04-counter-desks.sql); without those columns nothing new is shown.
-- Idempotent: INSERT IGNORE on the settings key; an existing VALUE is never touched.
--
-- settings.label is VARCHAR(191): every label below is under 191 characters and ends with what
-- "Off" does (a longer one is cut mid-sentence by INSERT IGNORE). The full story of each switch:
--   desk_day_clerk_on      1  a counter clerk who may see bookings + payments opens "Today's list"
--                             (admin/desk-day.php) for the ONE desk they sit on, whatever ?desk=
--                             says; an agent and a login with no desk never. 0 = office only.
--   desk_npr_first_on      1  at an NPR desk the SmartBook card (Counter / Admin faces), the Cash
--                             confirm sheet and the after-sale strip show the NPR to collect at the
--                             DESK's rate - the figure frozen on the ticket - big, INR x rate small.
--                             0 = INR big, "about NPR" at the company rate small.
--   desk_fast_entry_on     1  Enter in Mobile = Name; on a phone Enter in Name closes the keyboard
--                             (never adds a passenger); the cursor starts in Mobile on a keyboard
--                             desk; a Nepal desk starts on +977; the passenger being typed is kept
--                             for the open tab only (gone after the sale / when the tab closes),
--                             never in the shared browser store. 0 = as before.
--   desk_gender_two_btn_on 1  a passenger with no gender shows two buttons, one tap each, never
--                             pre-set. 0 = the single chip (first tap = Female).
--   desk_print80_touch_on  0  1 = the 80 mm print tab opens after every Cash sale on a phone too
--                             (as before). 0 = on a phone only after the clerk ticks "80 mm print"
--                             in the gear menu; desktops and tablets are unchanged.
--   staff_card_lang       ne  language of the desk card until the clerk picks one in the gear
--                             menu: ne | hi | en. A desk's own language (Counters) wins. en = as before.

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
('desk_day_clerk_on', '1', 'bool', 'counter',
 'काउन्टरले आफ्नै दिनको टिकट सूची हेर्ने · A counter clerk may open Today''s list for their OWN desk only (never an agent). Off = office only, as before', 0),
('desk_npr_first_on', '1', 'bool', 'counter',
 'नेपाल काउन्टरमा रू ठूलो, ₹ सानो · NPR desk: SmartBook shows the रू to collect (desk rate, as on the ticket) big, ₹ small. Off = ₹ big, ≈ NPR small, as before', 0),
('desk_fast_entry_on', '1', 'bool', 'counter',
 'छिटो इन्ट्री · Desk fast entry: Enter = next box, cursor starts in Mobile, a Nepal desk starts on +977, the typed passenger stays in the open tab only. Off = as before', 0),
('desk_gender_two_btn_on', '1', 'bool', 'counter',
 'F | M दुई बटन · Desk card: a passenger with no gender shows two buttons (♀ / ♂), never pre-set. Off = one chip (first tap = Female), as before', 0),
('desk_print80_touch_on', '0', 'bool', 'counter',
 'फोनमा 80 mm प्रिन्ट आफैं खुल्ने · On = the 80 mm print opens after each Cash sale on a phone too (as before). Off (default) = only after the clerk ticks it', 0),
('staff_card_lang', 'ne', 'string', 'counter',
 'डेस्क कार्डको भाषा · Language of the SmartBook desk card until the clerk picks one: ne | hi | en. The desk''s own language wins. en = as before', 0);

-- A database where the first draft of this file ran holds the long labels cut at 191 characters.
-- Only the LABEL of these six rows is rewritten (never the value); running it again changes nothing.
UPDATE settings SET label = 'काउन्टरले आफ्नै दिनको टिकट सूची हेर्ने · A counter clerk may open Today''s list for their OWN desk only (never an agent). Off = office only, as before'
 WHERE skey = 'desk_day_clerk_on';
UPDATE settings SET label = 'नेपाल काउन्टरमा रू ठूलो, ₹ सानो · NPR desk: SmartBook shows the रू to collect (desk rate, as on the ticket) big, ₹ small. Off = ₹ big, ≈ NPR small, as before'
 WHERE skey = 'desk_npr_first_on';
UPDATE settings SET label = 'छिटो इन्ट्री · Desk fast entry: Enter = next box, cursor starts in Mobile, a Nepal desk starts on +977, the typed passenger stays in the open tab only. Off = as before'
 WHERE skey = 'desk_fast_entry_on';
UPDATE settings SET label = 'F | M दुई बटन · Desk card: a passenger with no gender shows two buttons (♀ / ♂), never pre-set. Off = one chip (first tap = Female), as before'
 WHERE skey = 'desk_gender_two_btn_on';
UPDATE settings SET label = 'फोनमा 80 mm प्रिन्ट आफैं खुल्ने · On = the 80 mm print opens after each Cash sale on a phone too (as before). Off (default) = only after the clerk ticks it'
 WHERE skey = 'desk_print80_touch_on';
UPDATE settings SET label = 'डेस्क कार्डको भाषा · Language of the SmartBook desk card until the clerk picks one: ne | hi | en. The desk''s own language wins. en = as before'
 WHERE skey = 'staff_card_lang';
