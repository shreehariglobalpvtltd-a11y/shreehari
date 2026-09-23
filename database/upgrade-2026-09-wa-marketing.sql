-- WhatsApp marketing: explicit consent, reviewed campaigns and durable delivery.
-- Additive / repeatable. No existing booking implies marketing consent.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS wa_marketing_consents (
  phone VARCHAR(20) NOT NULL,
  country CHAR(2) NOT NULL,
  state ENUM('opted_in','opted_out') NOT NULL,
  evidence VARCHAR(200) NOT NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'whatsapp_inbound',
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (phone),
  KEY ix_wam_consent (state,country)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wa_marketing_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_by BIGINT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  template_name VARCHAR(100) NOT NULL,
  template_lang VARCHAR(20) NOT NULL,
  body_vars TEXT NOT NULL,
  country VARCHAR(3) NOT NULL DEFAULT 'all',
  state ENUM('draft','preview','queued','complete') NOT NULL DEFAULT 'draft',
  preview_token VARCHAR(24) NULL,
  preview_admin_id BIGINT UNSIGNED NULL,
  preview_turn INT UNSIGNED NULL,
  preview_at DATETIME NULL,
  template_hash CHAR(64) NULL,
  rendered_body TEXT NULL,
  recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
  confirmed_by BIGINT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_wam_campaign_state (state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wa_marketing_recipients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  state ENUM('preview','queued','sending','accepted','delivered','read','failed','unknown','skipped') NOT NULL DEFAULT 'preview',
  provider_ref VARCHAR(191) NULL,
  error VARCHAR(255) NULL,
  attempted_at DATETIME NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wam_recipient (campaign_id,phone),
  KEY ix_wam_queue (state,id),
  KEY ix_wam_provider_ref (provider_ref),
  KEY ix_wam_attempted (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (skey,svalue,stype,sgroup,label,is_public) VALUES
('wa_marketing_on','0','bool','ai','Enable confirmed WhatsApp marketing campaigns (Meta only, explicit opt-in required)',0),
('wa_marketing_templates','','string','ai','Allowed Meta MARKETING template names, comma separated. Must be APPROVED and include STOP opt-out wording',0),
('wa_marketing_max_recipients','200','int','ai','Maximum recipients in one reviewed marketing campaign (hard limit 5000)',0),
('wa_marketing_daily_cap','100','int','ai','Maximum marketing delivery attempts per day (hard limit 2000)',0),
('wa_marketing_batch_size','10','int','ai','Marketing worker batch size (hard limit 20)',0);
