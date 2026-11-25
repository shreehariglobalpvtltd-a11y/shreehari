-- 25 Nov 2026 (mp2 "bank Gmail"): the owner adds the bank's Gmail box from the admin panel itself.
--   "bank ko lagi gmail account add garxu admin panel bataoi login garn milos browser jasto ...
--    tesma update haru sab aos; indian ko laini credential naaya samma same system hos"
--
-- bank_mail_accounts - one row per Gmail box that receives bank credit alerts:
--   country NP -> NPR (Global IME alerts), IN -> INR (ICICI / HDFC / SBI ... alerts, until the bank API
--   credentials come). method 'google' = signed in with Google (OAuth, gmail.readonly; secret_enc = the
--   refresh token, access_enc = the short-lived access token), 'app_password' = IMAP with a Google app
--   password (secret_enc = the app password). Every secret is sealed with includes/secretbox.php (sodium
--   secretbox, key from APP_KEY) - never plaintext.
--   senders / dkim_domain / account_hint = this box's own rules (BankMail + PayCredits read them, not the
--   global bankmail_* settings): an account is "ready" (makes suggestions) only with a DKIM domain AND our
--   account's last digits; until then it logs what arrives and suggests nothing.
--   last_uid / uidvalidity = IMAP position, last_seen_ms = Gmail API internalDate position.
-- bank_mail_log - the per-account "updates" feed (the last 200 per account are kept): every mail the reader
--   looked at and what became of it (credit / suggestion / unmatched / debit-ignored / not-bank-sender /
--   unreadable ...). msg_ref = the Gmail API message id or "uid:<n>", so a mail is read once.
-- bank_credits.mail_account_id = the box a Gmail credit came from (NULL = the old single-box settings);
--   match_kind = how the booking was found: 'pnr' (the PNR in the alert) or 'amount' (no PNR, exactly one
--   booking owes exactly this amount - always a suggestion, never auto).
-- Code checks that these tables / columns exist, so nothing breaks before this file is applied. MariaDB 10.11.
CREATE TABLE IF NOT EXISTS bank_mail_accounts (
    id                INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    country           ENUM('NP','IN') NOT NULL,
    currency          ENUM('NPR','INR') NOT NULL,
    label             VARCHAR(80)     NULL,
    email             VARCHAR(190)    NOT NULL,
    method            ENUM('google','app_password') NOT NULL,
    secret_enc        TEXT            NULL,
    access_enc        TEXT            NULL,
    access_expires_at DATETIME        NULL,
    senders           VARCHAR(500)    NULL,
    dkim_domain       VARCHAR(120)    NULL,
    account_hint      VARCHAR(12)     NULL,
    is_active         TINYINT(1)      NOT NULL DEFAULT 1,
    last_uid          BIGINT UNSIGNED NULL,
    uidvalidity       VARCHAR(20)     NULL,
    last_seen_ms      BIGINT UNSIGNED NULL,
    last_poll_at      DATETIME        NULL,
    last_ok           TINYINT(1)      NULL,
    last_result       VARCHAR(255)    NULL,
    created_by        INT UNSIGNED    NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bank_mail_accounts (country, email),
    KEY idx_bank_mail_accounts_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_mail_log (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id  INT UNSIGNED  NOT NULL,
    msg_ref     VARCHAR(64)   NULL,
    received_at DATETIME      NULL,
    subject     VARCHAR(190)  NULL,
    outcome     VARCHAR(40)   NOT NULL,
    credit_id   INT UNSIGNED  NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bank_mail_log_acct (account_id, id),
    KEY idx_bank_mail_log_ref (account_id, msg_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bank_credits
    ADD COLUMN IF NOT EXISTS mail_account_id INT UNSIGNED NULL AFTER source,
    ADD COLUMN IF NOT EXISTS match_kind VARCHAR(12) NULL AFTER booking_id;
ALTER TABLE bank_credits ADD INDEX IF NOT EXISTS idx_bank_credits_mail_acct (mail_account_id);
