-- =====================================================================
--  Upgrade 2026-10-09 - Gold ticket + chalani: the blessing lines
--
--      php tests/apply-sql.php database/upgrade-2026-10-09-doc-gold.sql
--
--  Owner ask: every ticket and chalani carries a mantra line at the very
--  top and a "shubh yatra" blessing near the footer. Both are read through
--  DocArt::mantraLine() / DocArt::blessingLine(). The defaults below are
--  the same text the code falls back to when the row is missing, and an
--  EMPTY value hides that line on every document.
--
--  Fully idempotent: INSERT IGNORE only. Additive - no existing row
--  changes, so the code may ship before OR after this file.
--
--  NB: no semicolon inside any comment or quoted string - tests/apply-sql.php
--  splits this file on the statement terminator.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('doc_mantra_line',   'ॐ श्री गणेशाय नमः · ॐ नमो नारायणाय · जय श्री राम · जय अम्बे', 'string', 'documents', 'Ticket + chalani: mantra line printed at the very top in gold (blank = no line)', 0),
 ('doc_blessing_line', 'शुभ यात्रा · सुरक्षित यात्रा · सुखद यात्रा',                         'string', 'documents', 'Ticket + chalani: blessing line printed near the footer (blank = no line)', 0);
