-- SHG WhatsApp delivery evidence, 10 Oct 2026.
-- Does not change legacy message_logs.status; records real provider delivery proof.
-- No historic replay, no outbound calls.
CREATE TABLE IF NOT EXISTS wa_delivery_receipts (
  provider_ref VARCHAR(128) NOT NULL,
  message_log_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NULL,
  provider VARCHAR(40) NOT NULL DEFAULT '',
  delivery_state VARCHAR(20) NOT NULL DEFAULT 'accepted',
  sent_at DATETIME NULL,
  delivered_at DATETIME NULL,
  read_at DATETIME NULL,
  failed_at DATETIME NULL,
  last_event_at DATETIME NOT NULL,
  PRIMARY KEY (provider_ref),
  KEY ix_wa_receipt_booking (booking_id, message_log_id),
  KEY ix_wa_receipt_msg (message_log_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
