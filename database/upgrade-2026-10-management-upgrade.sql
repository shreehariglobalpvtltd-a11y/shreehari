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
;

-- Phase 2.3 — itemised expenses from a counter drawer (includes/countershift.php).
CREATE TABLE IF NOT EXISTS counter_shift_expenses (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shift_id    INT UNSIGNED  NOT NULL,
    category    VARCHAR(20)   NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    note        VARCHAR(255)  NULL,
    created_by  INT UNSIGNED  NULL,
    created_at  DATETIME      NOT NULL,
    KEY idx_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 2.3 — the closer's typed name and the handover (MariaDB syntax).
ALTER TABLE counter_shifts ADD COLUMN IF NOT EXISTS signed_name VARCHAR(120) NULL;
ALTER TABLE counter_shifts ADD COLUMN IF NOT EXISTS handover_to VARCHAR(120) NULL;

-- Phase 3.3 — the challan list the driver has (includes/challanlock.php).
CREATE TABLE IF NOT EXISTS challan_locks (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    schedule_id   INT UNSIGNED NOT NULL,
    version       INT UNSIGNED NOT NULL,
    snapshot      MEDIUMTEXT   NOT NULL,
    pax_count     INT UNSIGNED NOT NULL DEFAULT 0,
    note          VARCHAR(255) NULL,
    finalized_by  INT UNSIGNED NULL,
    finalized_at  DATETIME     NOT NULL,
    released_at   DATETIME     NULL,
    released_by   INT UNSIGNED NULL,
    UNIQUE KEY uq_sched_ver (schedule_id, version),
    KEY idx_sched (schedule_id, released_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 3.5 — the office's to-do list (includes/officetasks.php).
CREATE TABLE IF NOT EXISTS office_tasks (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(191) NOT NULL,
    details      VARCHAR(1000) NULL,
    assigned_to  INT UNSIGNED NOT NULL,
    created_by   INT UNSIGNED NULL,
    due_date     DATE         NULL,
    priority     VARCHAR(10)  NOT NULL DEFAULT 'normal',
    status       VARCHAR(10)  NOT NULL DEFAULT 'open',
    link         VARCHAR(255) NULL,
    done_at      DATETIME     NULL,
    created_at   DATETIME     NOT NULL,
    updated_at   DATETIME     NOT NULL,
    KEY idx_assignee (assigned_to, status),
    KEY idx_due (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
