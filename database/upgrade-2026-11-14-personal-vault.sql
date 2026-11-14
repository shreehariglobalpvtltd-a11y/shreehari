-- =====================================================================
--  Personal vault (14 Nov 2026) - the owner's and the CEO's own papers on
--  WhatsApp (includes/wavault.php). WaVault::ensure() also creates it on
--  first use; this file is the record. Additive only. Safe to re-run.
-- =====================================================================
CREATE TABLE IF NOT EXISTS personal_vault (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_key   VARCHAR(20)     NOT NULL,
    title       VARCHAR(120)    NOT NULL,
    mime        VARCHAR(40)     NOT NULL,
    ext         VARCHAR(8)      NOT NULL,
    size_bytes  INT UNSIGNED    NOT NULL DEFAULT 0,
    sha256      CHAR(64)        NOT NULL,
    file_path   VARCHAR(190)    NOT NULL,
    source      VARCHAR(20)     NOT NULL DEFAULT 'whatsapp',
    status      VARCHAR(10)     NOT NULL DEFAULT 'active',
    created_at  DATETIME        NOT NULL,
    deleted_at  DATETIME        NULL,
    KEY idx_vault_owner (owner_key, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
