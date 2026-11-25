-- 25 Nov 2026 (wx1-a, owner: "Nepal bata kholda Nepal -> India rute khulos; payment ma NPR ki INR choose garna milos"):
-- two switches for the SmartBook card, both ON by default in code (Settings::getBool(..., true)),
-- so the site behaves the same before and after this file runs. The rows only make them
-- visible / switchable in Admin -> Settings. Public: the classic search form reads
-- geo_direction_on from SHG_BOOT.settings.
-- No schema change: a customer's NPR choice uses the existing bookings.fx_currency / fx_rate /
-- fx_total columns (upgrade-2026-10-04-counter-desks.sql); without them nothing is frozen.
-- Idempotent: INSERT IGNORE on the settings primary key; an existing value is never touched.

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
('geo_direction_on', '1', 'bool', 'booking',
 'नेपालबाट खोल्दा नेपाल → भारत · A browser on Nepal time (Asia/Kathmandu) opens the booking card on Rupaidiha → Gujarat (after a saved choice and the mobile''s own history). Off = as before', 1),
('customer_currency_choice_on', '1', 'bool', 'booking',
 'ग्राहकले ₹ INR वा रू NPR छान्न पाउने · Customer chooses ₹ INR or रू NPR on the booking card; NPR = the NPR total (npr_per_inr) is frozen on the ticket and Online opens eSewa first. Books stay INR. Off = INR only, as before', 1);
