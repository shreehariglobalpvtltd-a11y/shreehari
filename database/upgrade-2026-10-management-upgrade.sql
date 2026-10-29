-- =====================================================================
--  Management upgrade (29 Oct 2026) — new tables, additive only.
--
--  Every table here is ALSO created by its PHP class on first use
--  (CREATE TABLE IF NOT EXISTS), so a deploy without this file still
--  works. Running it again is harmless: nothing is dropped or altered.
-- =====================================================================

-- Phase 1.2 — a monthly sales target per agent (includes/agenttargets.php).
-- No row = the company default (setting agent_target_monthly) applies.
-- bonus_pct = % of the confirmed sales ABOVE target, posted by hand from
-- Agent 360 → Targets after the month ends (ledger ref BONUS-YYYY-MM).
CREATE TABLE IF NOT EXISTS agent_targets (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agent_admin_id  INT UNSIGNED  NOT NULL,
    month           CHAR(7)       NOT NULL,
    target_amount   DECIMAL(12,2) NOT NULL DEFAULT 0,
    target_bookings INT UNSIGNED  NOT NULL DEFAULT 0,
    bonus_pct       DECIMAL(5,2)  NOT NULL DEFAULT 0,
    note            VARCHAR(255)  NULL,
    set_by          INT UNSIGNED  NULL,
    created_at      DATETIME      NOT NULL,
    updated_at      DATETIME      NOT NULL,
    UNIQUE KEY uq_agent_month (agent_admin_id, month),
    KEY idx_month (month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
