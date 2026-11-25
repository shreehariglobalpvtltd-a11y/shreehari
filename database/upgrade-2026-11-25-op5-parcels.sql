-- 25 Nov 2026 (op5, item 5b): a SMALL parcel record - one row per parcel the desk
-- takes on a bus. Not a parcel list for pickup points (the owner cancelled that on
-- 20 Nov): no public page, no WhatsApp, no automatic "paid". Parcels::hasTable()
-- keeps admin/parcels.php quietly off until this file has run.
CREATE TABLE IF NOT EXISTS parcels (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    travel_date     DATE            NOT NULL,
    schedule_id     BIGINT UNSIGNED NULL DEFAULT NULL,
    bus_label       VARCHAR(60)     NULL DEFAULT NULL,
    from_place      VARCHAR(120)    NOT NULL,
    to_place        VARCHAR(120)    NOT NULL,
    sender_name     VARCHAR(120)    NOT NULL,
    sender_phone    VARCHAR(20)     NOT NULL,
    receiver_name   VARCHAR(120)    NOT NULL,
    receiver_phone  VARCHAR(20)     NOT NULL,
    items           VARCHAR(255)    NOT NULL,
    pieces          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    weight_kg       DECIMAL(7,2)    NULL DEFAULT NULL,
    amount          DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    currency        CHAR(3)         NOT NULL DEFAULT 'INR',
    pay             ENUM('paid','to_pay') NOT NULL DEFAULT 'to_pay',
    status          ENUM('booked','loaded','delivered','cancelled') NOT NULL DEFAULT 'booked',
    booked_by       BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    loaded_at       DATETIME        NULL DEFAULT NULL,
    delivered_at    DATETIME        NULL DEFAULT NULL,
    note            VARCHAR(255)    NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY ix_parcel_date (travel_date, schedule_id),
    KEY ix_parcel_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
