-- =====================================================================
--  Super AI (13 Nov 2026) — one row per model call: tokens, cost, speed.
--  Read by admin/ai-center.php and the daily budget cap (AiMeter).
--  Additive only: no existing table or row is changed. Safe to re-run.
-- =====================================================================
CREATE TABLE IF NOT EXISTS ai_usage (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at   DATETIME        NOT NULL,
    provider     VARCHAR(20)     NOT NULL,
    model        VARCHAR(60)     NOT NULL DEFAULT '',
    channel      VARCHAR(20)     NOT NULL DEFAULT '',
    role         VARCHAR(12)     NOT NULL DEFAULT '',
    tag          VARCHAR(20)     NOT NULL DEFAULT '',
    in_tokens    INT UNSIGNED    NOT NULL DEFAULT 0,
    out_tokens   INT UNSIGNED    NOT NULL DEFAULT 0,
    cache_read   INT UNSIGNED    NOT NULL DEFAULT 0,
    cache_write  INT UNSIGNED    NOT NULL DEFAULT 0,
    cost_usd     DECIMAL(10,6)   NULL,
    ms           INT UNSIGNED    NOT NULL DEFAULT 0,
    ok           TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_ai_usage_created (created_at),
    KEY idx_ai_usage_provider (provider, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
