-- =====================================================================
--  Upgrade: PNG ticket cache column (2026-09-05). Run ONCE on live;
--  safe to re-run (checks information_schema first via the guarded
--  statement pattern below — plain ADD COLUMN errors on re-run, so use
--  IF NOT EXISTS, supported on MariaDB 10.2+).
--
--  tickets.png_path caches the rendered HD PNG ticket exactly like
--  pdf_path caches the PDF. Ticket::reissue() nulls it so a reschedule
--  re-renders; renderer changes clear it fleet-wide the same way as
--  pdf_path (UPDATE tickets SET png_path = NULL).
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `tickets`
  ADD COLUMN IF NOT EXISTS `png_path` VARCHAR(255) NULL AFTER `pdf_path`;
