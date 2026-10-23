-- Customer Form Templates (23 Oct 2026)
-- Saved passenger groups so a repeat customer never retypes names / ages.
-- One customer (by phone) can have multiple templates ("family", "office trip").

CREATE TABLE IF NOT EXISTS `customer_form_templates` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone`        VARCHAR(20) NOT NULL COMMENT 'customer phone (digits only)',
  `label`        VARCHAR(120) NOT NULL DEFAULT 'My Group' COMMENT 'friendly name',
  `contact_name` VARCHAR(120) NULL COMMENT 'lead passenger / booker name',
  `contact_email`VARCHAR(191) NULL,
  `id_type`      VARCHAR(60) NULL,
  `id_number`    VARCHAR(60) NULL,
  `country_code` VARCHAR(5) NOT NULL DEFAULT '91',
  `passengers`   JSON NOT NULL COMMENT '[{name,age,gender}]',
  `use_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `last_used_at` DATETIME NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_cft_phone` (`phone`),
  KEY `ix_cft_used` (`phone`, `use_count` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A short token so a WhatsApp link can open a pre-filled booking page
-- without requiring the customer to sign in first.
CREATE TABLE IF NOT EXISTS `form_template_tokens` (
  `token`       VARCHAR(40) NOT NULL,
  `template_id` BIGINT UNSIGNED NOT NULL,
  `route_id`    INT UNSIGNED NULL COMMENT 'optional: lock to a route',
  `travel_date` DATE NULL COMMENT 'optional: lock to a date',
  `expires_at`  DATETIME NOT NULL,
  `used`        TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token`),
  KEY `ix_ftt_template` (`template_id`),
  KEY `ix_ftt_expiry` (`expires_at`),
  CONSTRAINT `fk_ftt_template` FOREIGN KEY (`template_id`)
    REFERENCES `customer_form_templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
