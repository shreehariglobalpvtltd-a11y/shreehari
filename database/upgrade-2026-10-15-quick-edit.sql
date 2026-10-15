-- =====================================================================
--  15 Oct 2026 - Quick Edit ticket (admin/booking-view.php #quick).
--
--      php tests/apply-sql.php database/upgrade-2026-10-15-quick-edit.sql
--
--  ADDITIVE ONLY - no column holding data is dropped or renamed, no row
--  is changed.
--
--  1. ticket_edit_audit - already live since the WhatsApp agent-routing
--     release (upgrade-2026-10-10-wa-agent-routing.sql). Repeated here with
--     IF NOT EXISTS so a database that skipped that file still gets the
--     table the Quick Edit "Request correction" queue writes to
--     (outcome 'requested' -> 'done' | 'refused', changed_by 'staff:<id>').
--  2. Settings (sgroup booking, edited on Admin -> Settings):
--     ticket_edit_rules  which ticket fields an AGENT / COUNTER login may
--                        correct on its own on the ticket page. A field not
--                        named for the counter keeps today's right
--                        (bookings.edit); a field not named for an agent
--                        stays closed. Pickup is off for agents by default
--                        because a pickup can change the fare.
--     agent_cancel_mode  'direct' = today's behaviour (an agent cancels
--                        their own sale); 'request' = an agent's Cancel
--                        becomes a request the office settles by hand.
--
--  Re-running is harmless.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `ticket_edit_audit` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`    BIGINT UNSIGNED NOT NULL COMMENT 'the ticket (bookings.id)',
  `pnr`           VARCHAR(40) NOT NULL,
  `field_changed` VARCHAR(40) NOT NULL COMMENT 'passenger_name | travel_date | agent | cancel_request',
  `old_value`     VARCHAR(255) NULL,
  `new_value`     VARCHAR(255) NULL,
  `changed_by`    VARCHAR(80) NOT NULL COMMENT 'whatsapp:<number> (customer / staff role)',
  `changed_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `otp_verified`  TINYINT(1) NOT NULL DEFAULT 0,
  `outcome`       VARCHAR(16) NOT NULL DEFAULT 'done' COMMENT 'done | requested | refused',
  `detail`        VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `ix_tea_booking` (`booking_id`, `id`),
  KEY `ix_tea_when` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('ticket_edit_rules', '{"agent":{"name":true,"phone":true,"pickup":false},"counter":{"name":true,"phone":true,"pickup":true,"seat":true,"payment":true}}', 'json', 'booking', 'Quick Edit - ticket fields an agent / counter may correct themselves (name, phone, email, pickup, gender, age, id, seat, payment, remark). Not named: counter keeps its right, agent stays closed', 0),
 ('agent_cancel_mode', 'direct', 'string', 'booking', 'Agent cancel on the ticket page: direct (agent cancels own sale) or request (the office settles it)', 0);
