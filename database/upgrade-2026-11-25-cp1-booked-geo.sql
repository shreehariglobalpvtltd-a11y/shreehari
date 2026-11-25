-- 25 Nov 2026 (cp1, owner: "customer location ticket ma nai, admin page ma chalani page ma aos"):
-- where the ticket was cut - the booking phone's GPS fix (only when the person allowed location).
-- BookGeo::stamp() writes these; BookGeo::hasColumns() keeps the app working before this runs.
ALTER TABLE bookings
    ADD COLUMN booked_lat   DECIMAL(9,6)  NULL DEFAULT NULL,
    ADD COLUMN booked_lng   DECIMAL(9,6)  NULL DEFAULT NULL,
    ADD COLUMN booked_acc   INT UNSIGNED  NULL DEFAULT NULL,
    ADD COLUMN booked_place VARCHAR(120)  NULL DEFAULT NULL;
