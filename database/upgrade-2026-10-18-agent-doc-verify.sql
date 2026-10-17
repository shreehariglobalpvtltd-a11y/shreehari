-- =====================================================================
--  upgrade-2026-10-18-agent-doc-verify.sql
--
--  Owner, 18 Oct 2026: the agent form's ID photo must show the typed name
--  and the typed document number. includes/docverify.php reads the card
--  (Gemini vision, Tesseract OCR as the fallback) and the form is sent
--  only on a match. doc_verify keeps the verdict line for the office,
--  e.g. "PASS - ai - card: RAM BAHADUR THAPA".
--
--  Additive and re-runnable.
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'doc_verify');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `doc_verify` VARCHAR(255) NULL COMMENT ''document check verdict, staff-only'' AFTER `geo`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
