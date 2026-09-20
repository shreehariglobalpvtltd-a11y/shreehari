-- Payment intent trail: who opened the QR / tapped the pay link (20 Sep 2026)
CREATE TABLE IF NOT EXISTS payment_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id  BIGINT UNSIGNED NULL,
  pnr         VARCHAR(40)  NOT NULL DEFAULT '',
  kind        VARCHAR(20)  NOT NULL,
  is_bot      TINYINT(1)   NOT NULL DEFAULT 0,
  ip          VARCHAR(45)  NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pe_booking (booking_id),
  KEY idx_pe_pnr (pnr),
  KEY idx_pe_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
