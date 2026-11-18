-- =====================================================================
-- 19 Nov 2026 (u10, WA-6 + WA-5): counter DAY CLOSE + a default driver per bus.
--
-- 1. counter_day_close - "आजको हिसाब बुझाउने · Day close": one row per
--    desk (or per seller with no desk, key 'AG:<admin id>') per business
--    day. The register figures (CounterBook::day) are FROZEN on the row with
--    what the person counted, so a payment corrected next week cannot rewrite
--    the night's count. A correction the same day UPDATES the row (unique
--    day_key) and is audited (Logger::audit 'dayclose.save' with before/after).
--    Separate from counter_shifts on purpose: shift history, the shift CSV and
--    admin/reconcile.php keep reading shifts only.
-- 2. buses.default_driver_id - the driver who normally drives that coach, so
--    the passenger list follows the bus on rotation days. No FK: a deleted
--    driver cannot block anything (DriverList checks drivers.is_active = 1).
--
-- Idempotent: safe to run twice. Apply:
--   mysql ... shari < database/upgrade-2026-11-19-u10-day-close.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS counter_day_close (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    day_key         VARCHAR(48)     NOT NULL,              -- 'YYYY-MM-DD|<desk or AG:id or ONLINE>'
    business_date   DATE            NOT NULL,
    desk_key        VARCHAR(24)     NOT NULL DEFAULT '',   -- desk code, 'AG:<admin id>', or '' (online)
    currency        CHAR(3)         NOT NULL DEFAULT 'INR',
    tickets         INT UNSIGNED    NOT NULL DEFAULT 0,
    pax             INT UNSIGNED    NOT NULL DEFAULT 0,
    cash_inr        DECIMAL(12,2)   NOT NULL DEFAULT 0.00, -- register: verified cash + cod (INR)
    online_inr      DECIMAL(12,2)   NOT NULL DEFAULT 0.00, -- register: verified UPI / eSewa / bank (INR)
    npr_register    DECIMAL(12,2)   NOT NULL DEFAULT 0.00, -- register: NPR physically taken (payments.local_amount)
    pending_inr     DECIMAL(12,2)   NOT NULL DEFAULT 0.00, -- pay-at-boarding still to collect
    commission_inr  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    refund_inr      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    net_inr         DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    expenses        DECIMAL(12,2)   NOT NULL DEFAULT 0.00, -- cash paid out of the drawer (desk money)
    counted_cash    DECIMAL(12,2)   NOT NULL DEFAULT 0.00, -- what the person counted (desk money: NPR at an NPR desk)
    counted_npr     DECIMAL(12,2)   NULL,                  -- optional NPR count at an INR desk that also took NPR
    expected_cash   DECIMAL(12,2)   NOT NULL DEFAULT 0.00, -- register cash (desk money) - expenses
    variance        DECIMAL(12,2)   NOT NULL DEFAULT 0.00, -- counted - expected
    note            VARCHAR(255)    NULL,
    signed_name     VARCHAR(120)    NULL,
    closed_by       BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL,
    updated_at      DATETIME        NOT NULL,
    UNIQUE KEY uq_day_close (day_key),
    KEY idx_day (business_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE buses ADD COLUMN IF NOT EXISTS default_driver_id BIGINT UNSIGNED NULL AFTER total_seats;
