-- 25 Nov 2026 (mp4, owner: "qr code haru sab upload garne system bandinus, admin panel baata update garn milos"):
-- the payment QR library behind Admin -> Payment Management (🔳 QR कोडहरू · Payment QR codes).
--
-- pay_qr_codes - one row per uploaded QR picture:
--   country    NP | IN
--   kind       esewa | fonepay | khalti | gibl (Global IME bank) | bank | upi
--   label      the office's own name ("NPJ counter eSewa")
--   payee      the id / number / VPA printed under the QR
--   file       uploads/payqr/<random>.png (re-encoded PNG, never the file as sent)
--   desk_code  the counter (counter_locations.code) the QR belongs to, NULL = any desk
--   is_default the QR a ticket of that country prints when its desk has none (one per country)
--   is_active  0 = kept in the list, never printed
-- Ticket::esewaQrFile($booking) reads it through includes/payqr.php: the issuing desk's QR, else the
-- country default, else the old single setting nepal_esewa_qr - so the code works before this runs.
-- The NP default's file is written back into nepal_esewa_qr, so older readers show the same QR.
--
-- The old single Nepal QR (settings nepal_esewa_qr; nepal_esewa_id -> payee, nepal_esewa_name -> label) is copied in
-- as the NP default below (only when the library has no row with that file yet; PayQr::importLegacy
-- does the same from PHP the first time the page opens). Idempotent - safe to apply twice. MariaDB 10.11.
CREATE TABLE IF NOT EXISTS pay_qr_codes (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
    country     CHAR(2)          NOT NULL DEFAULT 'NP',
    kind        VARCHAR(16)      NOT NULL DEFAULT 'esewa',
    label       VARCHAR(80)      NOT NULL DEFAULT '',
    payee       VARCHAR(160)     NOT NULL DEFAULT '',
    file        VARCHAR(190)     NOT NULL,
    desk_code   VARCHAR(16)      NULL DEFAULT NULL,
    is_default  TINYINT(1)       NOT NULL DEFAULT 0,
    is_active   TINYINT(1)       NOT NULL DEFAULT 1,
    sort        SMALLINT         NOT NULL DEFAULT 0,
    created_by  BIGINT UNSIGNED  NULL DEFAULT NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_pqc_country (country, is_active, is_default),
    KEY ix_pqc_desk (desk_code),
    KEY ix_pqc_file (file)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO pay_qr_codes (country, kind, label, payee, file, desk_code, is_default, is_active, sort, created_at, updated_at)
SELECT 'NP', 'esewa',
       LEFT(CONCAT('eSewa', IF(COALESCE(nm.svalue, '') <> '', CONCAT(' · ', nm.svalue), ' (NPJ)')), 80),
       LEFT(COALESCE(id.svalue, ''), 160),   -- the id only: it is the line printed under the QR (the name stays in the label)
       q.svalue, NULL,
       IF(EXISTS (SELECT 1 FROM pay_qr_codes d WHERE d.country = 'NP' AND d.is_default = 1), 0, 1),
       1, 0, NOW(), NOW()
  FROM settings q
  LEFT JOIN settings id ON id.skey = 'nepal_esewa_id'
  LEFT JOIN settings nm ON nm.skey = 'nepal_esewa_name'
 WHERE q.skey = 'nepal_esewa_qr'
   AND q.svalue REGEXP '^uploads/payqr/[a-z0-9-]+\\.png$'
   AND NOT EXISTS (SELECT 1 FROM pay_qr_codes p WHERE p.file = q.svalue);
