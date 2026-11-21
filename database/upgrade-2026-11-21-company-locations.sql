-- 21 Nov 2026: the company's own places (parkings, offices, pickup yards) - admin/locations.php.
-- CompanyLocations::ensure() also creates it and seeds the owner's six places on first use.
CREATE TABLE IF NOT EXISTS company_locations (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(160)  NOT NULL,
    name_ne        VARCHAR(160)  NULL,
    kind           VARCHAR(12)   NOT NULL DEFAULT 'parking',
    town           VARCHAR(60)   NULL,
    address        VARCHAR(255)  NULL,
    address_ne     VARCHAR(255)  NULL,
    map_url        VARCHAR(500)  NULL,
    phone          VARCHAR(60)   NULL,
    whatsapp       VARCHAR(30)   NULL,
    contact_person VARCHAR(120)  NULL,
    hours          VARCHAR(120)  NULL,
    note           VARCHAR(255)  NULL,
    photos         TEXT          NULL,
    show_public    TINYINT(1)    NOT NULL DEFAULT 0,
    sort_order     INT           NOT NULL DEFAULT 0,
    updated_by     INT UNSIGNED  NULL,
    created_at     DATETIME      NOT NULL,
    updated_at     DATETIME      NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
