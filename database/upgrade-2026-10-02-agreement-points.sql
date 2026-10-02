-- 2 Oct 2026 - agreement points the office can correct from admin
--
-- The /agreements/ pages (Agent + Partner agreement, Nepali / Hindi /
-- English / Gujarati) are static files. This table keeps only the office's
-- changes to their points - an edited wording, a hidden point, or a point
-- added under a section - and assets/js/agreement-live.js applies them on the
-- page (see includes/agreementtext.php). No row = the original wording.
--
--   doc       agent | partner
--   lang      ne | hi | en | gu
--   num       the point number as printed, e.g. 2.1
--   section   the section number the point sits under
--   body      the edited wording with its {{blank}} boxes, NULL = original
--   hidden    1 = taken off the page
--   is_added  1 = a point the office added (not in the built page)
--
-- Additive, idempotent.
CREATE TABLE IF NOT EXISTS `agreement_points` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc`         VARCHAR(10)  NOT NULL,
  `lang`        VARCHAR(5)   NOT NULL,
  `num`         VARCHAR(12)  NOT NULL,
  `section`     VARCHAR(8)   NULL,
  `body`        TEXT         NULL,
  `hidden`      TINYINT(1)   NOT NULL DEFAULT 0,
  `is_added`    TINYINT(1)   NOT NULL DEFAULT 0,
  `updated_by`  BIGINT UNSIGNED NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agreement_point` (`doc`, `lang`, `num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
