-- Additive, re-runnable. Existing booking, wallet and notification tables stay authoritative.
CREATE TABLE IF NOT EXISTS ai_kb_categories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(80) NOT NULL UNIQUE, title VARCHAR(160) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_kb_sources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(200) NOT NULL, checksum CHAR(64) NOT NULL UNIQUE,
 mime VARCHAR(100) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'needs_review', warnings TEXT NULL,
 created_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_kb_articles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category VARCHAR(80) NOT NULL, slug VARCHAR(160) NOT NULL UNIQUE,
 canonical_title VARCHAR(200) NOT NULL, canonical_answer TEXT NOT NULL, nepali_content TEXT NULL, hindi_content TEXT NULL,
 english_content TEXT NULL, roman_nepali_examples TEXT NULL, roman_hindi_examples TEXT NULL, keywords TEXT NULL, synonyms TEXT NULL,
 applicable_roles JSON NOT NULL, source_type VARCHAR(40) NOT NULL, source_reference VARCHAR(255) NOT NULL,
 verification_status VARCHAR(30) NOT NULL DEFAULT 'unverified', publication_status VARCHAR(30) NOT NULL DEFAULT 'draft',
 effective_from DATETIME NULL, review_after DATETIME NULL, version INT NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY ix_ai_kb_status(publication_status,verification_status,category), KEY ix_ai_kb_review(review_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_kb_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, article_id BIGINT UNSIGNED NOT NULL, version INT NOT NULL,
 snapshot JSON NOT NULL, created_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_ai_kb_version(article_id,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_kb_chunks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, article_id BIGINT UNSIGNED NOT NULL, version INT NOT NULL,
 content TEXT NOT NULL, embedding JSON NULL, model VARCHAR(120) NULL, UNIQUE KEY uq_ai_kb_chunk(article_id,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_conversations (
 id CHAR(32) PRIMARY KEY, owner_key CHAR(64) NOT NULL, channel VARCHAR(20) NOT NULL, actor_type VARCHAR(30) NOT NULL,
 actor_id BIGINT UNSIGNED NULL, language VARCHAR(10) NOT NULL DEFAULT 'en', state VARCHAR(30) NOT NULL DEFAULT 'AI_ACTIVE',
 contact_cipher TEXT NULL, context_cipher MEDIUMTEXT NULL, assigned_to BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY ix_ai_owner(owner_key,channel,updated_at), KEY ix_ai_inbox(state,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, conversation_id CHAR(32) NOT NULL, request_key CHAR(64) NOT NULL UNIQUE,
 role VARCHAR(15) NOT NULL, redacted_text TEXT NOT NULL, response_cipher MEDIUMTEXT NULL, intent VARCHAR(50) NULL,
 language VARCHAR(10) NOT NULL DEFAULT 'en', confidence DECIMAL(5,4) NULL, elapsed_ms INT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_ai_messages(conversation_id,id), KEY ix_ai_message_time(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_feedback (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, message_id BIGINT UNSIGNED NOT NULL, owner_key CHAR(64) NOT NULL,
 rating VARCHAR(20) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_ai_feedback(message_id,owner_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_unanswered_questions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, question_hash CHAR(64) NOT NULL UNIQUE, normalized_question TEXT NOT NULL,
 language VARCHAR(10) NOT NULL, frequency INT NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'new',
 article_id BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY ix_ai_unanswered(status,frequency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_handoffs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, conversation_id CHAR(32) NOT NULL, reason VARCHAR(255) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'open', assigned_to BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY ix_ai_handoff(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_action_confirmations (
 id CHAR(32) PRIMARY KEY, owner_key CHAR(64) NOT NULL, actor_type VARCHAR(30) NOT NULL, actor_id BIGINT UNSIGNED NULL,
 action_type VARCHAR(40) NOT NULL, entity_id BIGINT UNSIGNED NULL, payload_cipher MEDIUMTEXT NOT NULL,
 current_hash CHAR(64) NOT NULL, financial_effect DECIMAL(12,2) NOT NULL DEFAULT 0,
 expires_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, confirmed_at DATETIME NULL, KEY ix_ai_confirmation(owner_key,status,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_generated_assets (
 id CHAR(32) PRIMARY KEY, owner_key CHAR(64) NOT NULL, template_type VARCHAR(40) NOT NULL,
 filename VARCHAR(100) NOT NULL, mime VARCHAR(60) NOT NULL, checksum CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY ix_ai_assets(owner_key,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_provider_usage (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(30) NOT NULL, model VARCHAR(120) NOT NULL,
 operation VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
 output_tokens INT UNSIGNED NOT NULL DEFAULT 0, reserved_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
 estimated_cost DECIMAL(12,6) NOT NULL DEFAULT 0, elapsed_ms INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY ix_ai_usage(created_at,provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_provider_state (
 provider VARCHAR(30) PRIMARY KEY, failures INT NOT NULL DEFAULT 0, open_until DATETIME NULL, last_success DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_daily_metrics (
 day DATE PRIMARY KEY, requests INT UNSIGNED NOT NULL DEFAULT 0, reserved_cost DECIMAL(12,6) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS domain_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id CHAR(32) NOT NULL UNIQUE, event_type VARCHAR(70) NOT NULL,
 entity_type VARCHAR(30) NOT NULL, entity_id VARCHAR(50) NOT NULL, actor_type VARCHAR(30) NOT NULL, actor_id BIGINT UNSIGNED NULL,
 channel VARCHAR(20) NOT NULL, idempotency_key CHAR(64) NOT NULL UNIQUE, safe_payload JSON NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'processed', retry_count INT NOT NULL DEFAULT 0, failure_reason VARCHAR(100) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, processed_at DATETIME NULL,
 KEY ix_domain_entity(entity_type,entity_id,id), KEY ix_domain_status(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(30) NOT NULL, idempotency_key CHAR(64) NOT NULL UNIQUE,
 conversation_id CHAR(32) NULL, owner_key CHAR(64) NOT NULL, payload_cipher MEDIUMTEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0, available_at DATETIME NOT NULL,
 locked_at DATETIME NULL, lock_token CHAR(32) NULL, error_code VARCHAR(80) NULL, result_cipher MEDIUMTEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, finished_at DATETIME NULL,
 KEY ix_ai_jobs_claim(status,available_at,id), KEY ix_ai_jobs_owner(owner_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS whatsapp_delivery_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_key CHAR(64) NOT NULL UNIQUE,
 provider_ref VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, error_code INT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY ix_wa_delivery(provider_ref,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (skey,svalue,stype,sgroup,label,is_public) VALUES
 ('ai_manager_enabled','0','bool','ai','S Hari AI Helper enabled',0),
 ('ai_manager_customer','1','bool','ai','Customer AI',0),
 ('ai_manager_staff','1','bool','ai','Staff AI',0),
 ('ai_manager_whatsapp','0','bool','ai','WhatsApp AI gateway',0),
 ('ai_manager_provider','gemini','string','ai','AI provider',0),
 ('ai_manager_model','','string','ai','AI model (empty uses existing Gemini model)',0),
 ('ai_manager_external','1','bool','ai','Allow redacted intent classification with configured provider',0),
 ('ai_manager_daily_requests','500','int','ai','AI daily API request cap',0),
 ('ai_manager_daily_budget','5','string','ai','AI daily reserved budget USD',0),
 ('ai_manager_request_reserve','0.02','string','ai','Conservative per-call budget reservation USD',0),
 ('ai_manager_retention_days','30','int','ai','Redacted conversation retention days',0),
 ('ai_manager_confidence','0.80','string','ai','Minimum classifier confidence',0),
 ('ai_manager_timeout','15','int','ai','Provider timeout seconds',0),
 ('ai_manager_base','','string','ai','Approved OpenAI-compatible API base',0),
 ('ai_manager_api_key','','string','ai','Compatible provider key',0),
 ('ai_manager_embedding_model','','string','ai','Optional embedding model',0),
 ('ai_manager_embeddings','0','bool','ai','Enable approved knowledge embeddings',0);
