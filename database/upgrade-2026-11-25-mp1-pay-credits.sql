-- 25 Nov 2026 (mp1 "pay"): one ledger for money the BANK says arrived.
--   source 'gmail'   - a Global IME Bank credit-alert e-mail read by cron/bank-mail.php (BankMail)
--   source 'bankapi' - a signed India bank / UPI API webhook (payment/bank-webhook.php, BankApi)
-- PayCredits (includes/paycredits.php) matches a row to ONE pending booking and only then
-- confirms it through BookingService (the same gate a person uses). Anything else stays here
-- as 'unmatched' for admin/payment-center.php ("Link to PNR" / Ignore).
-- dkim_ok = the source was authenticated: gmail = DKIM pass from the bank's domain,
--           bankapi = the HMAC signature on the webhook body was verified.
-- credit_account = the account the bank alert says was credited, masked to its last digits
--           (XXXXXXX789012); a Gmail credit auto-confirms only when it ends with
--           bankmail_account_hint ('?' = two different accounts in one alert).
-- Code checks that this table (and credit_account) exists, so nothing breaks before this file is applied.
CREATE TABLE IF NOT EXISTS bank_credits (
    id           INT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source       ENUM('gmail','bankapi') NOT NULL,
    currency     ENUM('NPR','INR')       NOT NULL,
    amount       DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    txn_id       VARCHAR(80)    NULL,
    ref_text     VARCHAR(255)   NULL,
    pnr          VARCHAR(40)    NULL,
    sender       VARCHAR(190)   NULL,
    credit_account VARCHAR(40)  NULL,
    dkim_ok      TINYINT(1)     NOT NULL DEFAULT 0,
    message_id   VARCHAR(255)   NULL,
    received_at  DATETIME       NULL,
    raw_excerpt  TEXT           NULL,
    booking_id   INT UNSIGNED   NULL,
    state        ENUM('new','matched','unmatched','linked','ignored','test') NOT NULL DEFAULT 'new',
    note         VARCHAR(255)   NULL,
    acted_by     INT UNSIGNED   NULL,
    acted_at     DATETIME       NULL,
    created_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bank_credits_txn (source, txn_id),
    UNIQUE KEY uq_bank_credits_msg (message_id),
    KEY idx_bank_credits_state (state, created_at),
    KEY idx_bank_credits_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- a database that already had bank_credits from an earlier copy of this file
ALTER TABLE bank_credits ADD COLUMN IF NOT EXISTS credit_account VARCHAR(40) NULL AFTER sender;
