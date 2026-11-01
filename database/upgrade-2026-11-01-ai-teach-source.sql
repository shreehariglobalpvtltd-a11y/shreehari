-- Claude teacher examples for the local WhatsApp desk (1 Nov 2026).
--
-- includes/aiteach.php stores generated examples with source='teacher'. The
-- original 31 Oct table allowed only parser/confirmed/office, so MySQL rejected
-- every generated example while the deliberately best-effort learner kept the
-- desk running. Extend the enum without changing existing rows.

ALTER TABLE `ap_wa_examples`
  MODIFY `source` ENUM('parser','confirmed','office','teacher') NOT NULL DEFAULT 'parser';
