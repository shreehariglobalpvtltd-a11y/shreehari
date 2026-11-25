-- 25 Nov 2026 (wx1-c1, owner: every office card shows the office in-charge - कार्यालय प्रमुख -
-- name, photo, phone and a WhatsApp button). The in-charge NAME is the existing
-- counter_locations.contact_person; these two add the in-charge's own phone and photo.
-- Filled by the office on Admin -> Counters ("On the website" block); empty = nothing shown.
-- CounterDesk reads them through hasColumn(), so the code works before this runs.
-- Idempotent (MariaDB ADD COLUMN IF NOT EXISTS) - safe to apply twice.
ALTER TABLE counter_locations
    ADD COLUMN IF NOT EXISTS incharge_phone VARCHAR(40)  NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS incharge_photo VARCHAR(255) NULL DEFAULT NULL;
