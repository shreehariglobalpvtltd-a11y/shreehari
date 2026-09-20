-- Offers that apply without a code (Dashain / Tihar), 20 Sep 2026
ALTER TABLE coupons ADD COLUMN IF NOT EXISTS auto_apply TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
ALTER TABLE coupons ADD INDEX IF NOT EXISTS idx_coupons_auto (auto_apply, is_active);
