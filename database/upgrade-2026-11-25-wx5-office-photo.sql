-- 25 Nov 2026 (wx5, owner: "a photo for each office" - he sent the Ahmedabad office signboard):
-- the OFFICE photo (the building / signboard), separate from the in-charge's round photo
-- (incharge_photo, wx1-c1). Uploaded on Admin -> Counters ("On the website" block), re-encoded
-- by ContentPages::saveUpload into uploads/locations; empty = the card looks exactly as before.
-- CounterDesk reads it through hasColumn(), so the code works before this runs.
-- Idempotent (MariaDB ADD COLUMN IF NOT EXISTS) - safe to apply twice.
ALTER TABLE counter_locations
    ADD COLUMN IF NOT EXISTS office_photo VARCHAR(255) NULL DEFAULT NULL;
