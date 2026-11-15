-- =====================================================================
--  upgrade-2026-11-15-ticket-requests.sql  (P6, 15 Nov 2026)
--
--  The passenger's "change my date" / "cancel one seat" REQUESTS, made on
--  the ticket page (ticket/view.php -> api/ticket-request.php) and worked
--  by the office on admin/ticket-requests.php. A row is only a request:
--  nothing here moves, cancels or refunds a ticket. The office does that
--  by hand with the existing booking tools, then marks the row done or
--  rejected with a note.
--
--    kind          change_date | cancel_seats
--    wanted_date   the date asked for (change_date only)
--    seats         stored seat ids of THIS booking, comma separated
--                  (cancel_seats only; every seat = the whole ticket)
--    status        open -> done | rejected (by the office, with staff_note)
--
--  Plus one switch row (ON, the code reads the same default) so the
--  office can hide the two buttons from Admin -> Settings.
--
--  Additive and safe to re-run. Code that runs before this file sees no
--  table: the ticket page shows no buttons and the API answers "not
--  available".
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ticket_requests` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`    BIGINT UNSIGNED NOT NULL COMMENT 'bookings.id',
  `pnr`           VARCHAR(40)  NOT NULL,
  `kind`          ENUM('change_date','cancel_seats') NOT NULL,
  `wanted_date`   DATE         NULL DEFAULT NULL COMMENT 'change_date: the travel date asked for',
  `seats`         VARCHAR(255) NULL DEFAULT NULL COMMENT 'cancel_seats: stored seat ids of this booking, comma separated',
  `note`          VARCHAR(255) NULL DEFAULT NULL COMMENT 'what the passenger wrote',
  `contact_phone` VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'number to call back (typed, else the ticket contact)',
  `status`        ENUM('open','done','rejected') NOT NULL DEFAULT 'open',
  `staff_note`    VARCHAR(255) NULL DEFAULT NULL COMMENT 'what the office did / why not - office only',
  `handled_by`    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'admins.id who closed it',
  `handled_at`    DATETIME     NULL DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`            VARCHAR(45)  NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_treq_status` (`status`, `created_at`),
  KEY `ix_treq_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('ticket_requests_on',       '1',  'bool', 'booking', 'Ticket page: passengers may REQUEST a date change or a seat cancel (the office does it by hand)', 0),
  ('ticket_request_max_days',  '60', 'int',  'booking', 'Ticket page: a date change may be asked for up to this many days ahead', 0);
