-- =====================================================================
--  Upgrade 2026-09 — Challan PNG + audit reason/role (SHG AI BRAIN Phase 1)
--
--  Run ONCE on the live database:
--      php -c .claude/php-dev.ini tests/apply-sql.php database/upgrade-2026-09-challan-png.sql
--
--  Idempotent: CREATE TABLE IF NOT EXISTS + ADD COLUMN IF NOT EXISTS
--  (MariaDB 10.2+, the same construct upgrade-2026-09-png-ticket.sql relies
--  on). Nothing here changes how a seat is sold or a fare is computed. Drop
--  the table and the two columns and every screen still works: ChallanPng
--  simply stops caching, Logger::audit falls back to the old column set.
--
--  1. challan_reports — one row per rendered bus challan PNG: which
--     departure, which file, the data fingerprint it was drawn from, who
--     asked and why (manual / booking change / daily). The newest row for
--     a departure is the current picture as long as its fingerprint still
--     matches the booking data (ChallanPng::render).
--  2. audit_logs.actor_role — the role behind the actor (counter, agent,
--     superadmin ...), so the audit report can be filtered by desk kind.
--  3. audit_logs.reason — the free-text reason a staff member typed when
--     editing a ticket (date, seat, name, phone, pickup) — the one column
--     the prompt's booking_edit_log had that the existing trail lacked.
-- =====================================================================

CREATE TABLE IF NOT EXISTS challan_reports (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  schedule_id   INT UNSIGNED NOT NULL,
  bus_label     VARCHAR(40)  NOT NULL DEFAULT '',
  report_date   DATE         NOT NULL,
  file_path     VARCHAR(255) NOT NULL,
  fingerprint   CHAR(32)     NOT NULL,
  width         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  height        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  trigger_event VARCHAR(30)  NOT NULL DEFAULT 'manual',
  generated_by  INT UNSIGNED NULL,
  generated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_challan_sched (schedule_id, id),
  KEY ix_challan_date (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS actor_role VARCHAR(30) NULL AFTER actor_name;

ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS reason VARCHAR(255) NULL AFTER detail;
