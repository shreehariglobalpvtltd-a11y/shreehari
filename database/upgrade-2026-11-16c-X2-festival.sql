-- =====================================================================
--  upgrade-2026-11-16c-X2-festival.sql  (16 Nov 2026, Master v2.0 unit X2)
--
--  P5-3 / P5-7b / P5-7c  special dates (festival look).
--    festival_theme_on  bool, 0 = off (the default). While off the customer
--                       app gets nothing from the list below: no colour, no
--                       banner - exactly as before.
--    festival_dates     json list, [] (the default; nothing is prefilled -
--                       the office adds its own dates on Admin -> Colours &
--                       Themes): [{from:'YYYY-MM-DD', to:'YYYY-MM-DD', name,
--                       accent:<sun..sat or ''>, banner_ne, banner_en}],
--                       at most 30 rows of at most 15 days each. NOT public:
--                       index.php hands the page only the row covering today
--                       (India time), and only while the switch is on.
--
--  Nothing changes for any customer after this runs. Additive and safe to
--  re-run: INSERT IGNORE keeps any value already stored. The code works
--  before this runs too (Settings::get* defaults; admin/themes.php creates
--  the rows on its first save).
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('festival_theme_on', '0', 'bool', 'app', 'Special dates: on a date listed on Admin -> Colours & Themes, the customer app takes that row\'s colour and shows its banner (Nepali first). Off = weekly colours only, no banner', 0),
  ('festival_dates', '[]', 'json', 'app', 'Special dates (Admin -> Colours & Themes): from / to / name / colour / banner lines, max 30 rows of up to 15 days', 0);
