-- =====================================================================
--  Upgrade 2026-09-26 — one-time WhatsApp ticket codes (wa_chat_tokens)
--
--  Run ONCE on the live database (phpMyAdmin -> SQL tab -> paste -> Go),
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-09-26-wa-chat-tokens.sql
--
--  Fully idempotent: CREATE TABLE IF NOT EXISTS, settings rows are
--  INSERT IGNORE. Safe to re-run while the site is live.
--
--  WHAT THIS ADDS
--  --------------
--  wa_chat_tokens   a 6-character code the passenger sends to our WhatsApp
--                   number ("TICKET K7QM2P") to receive their ticket. The
--                   customer always writes first (that opens WhatsApp's 24 h
--                   window), we never message first. Only the SHA-256 of the
--                   code is stored, a code lives 30 minutes and works once.
--  settings         wa_chat_on (office switch), wa_chat_token_ttl_min and
--                   wa_ticket_number (the WhatsApp number the code is sent
--                   to, blank = company_whatsapp / company_phone).
--
--  NB: no semicolon inside any quoted string — tests/apply-sql.php splits
--  this file on ";".
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `wa_chat_tokens` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL COMMENT 'sha256 of the 6-char code, never the code',
  `source`     VARCHAR(16) NOT NULL DEFAULT 'customer' COMMENT 'customer or staff',
  `admin_id`   BIGINT UNSIGNED NULL COMMENT 'staff member who showed the QR',
  `expires_at` DATETIME NOT NULL,
  `used_at`    DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_chat_hash` (`token_hash`),
  KEY `idx_wa_chat_booking` (`booking_id`),
  KEY `idx_wa_chat_expires` (`expires_at`),
  CONSTRAINT `fk_wa_chat_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('wa_chat_on',            '1',  'bool',   'whatsapp', 'One-time WhatsApp ticket codes (customer writes first, we never message first)', 0),
('wa_chat_token_ttl_min', '30', 'int',    'whatsapp', 'Minutes a WhatsApp ticket code stays valid', 0),
('wa_ticket_number',      '',   'string', 'whatsapp', 'WhatsApp number the ticket code is sent to (blank = company WhatsApp)', 0);
