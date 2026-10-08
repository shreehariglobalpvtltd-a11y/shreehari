-- =====================================================================
--  8 Oct 2026 - Smart booking v2 (owner master prompt 2.0)
--
--  Smart Settings rows, one group per area so Admin -> Settings shows
--  them as their own sections (Seat AI / Near-me pickup / Marketing):
--    seat_ai_*   the seat recommendation engine (06-results.js
--                pickSeatsSmart + QuickTicket::pickSeats) - row order
--                rear-to-front by default, as the owner asked.
--    nearme_*    "Use my location" on the seat page: the nearest
--                configured pickup with coordinates is suggested, never
--                chosen silently, and the position is never stored.
--    ticket_maps_qr  a Google Maps QR for the passenger's pickup on the
--                PNG ticket (only when that pickup has coordinates).
--    mk_require_optin  campaigns may reach only numbers that opted in.
--
--  wa_tpl_library holds the office's edits to the built-in WhatsApp
--  template drafts (includes/watpllib.php); a row exists only once a
--  draft has been edited or its Meta status recorded.
--
--  Re-running this file is harmless.
-- =====================================================================

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('seat_ai_on',             '1',    'bool',   'seat_ai',   'Seat AI: recommend and auto-pick seats (OFF = passengers pick every seat by hand)', 1),
 ('seat_ai_row_order',      'rear', 'string', 'seat_ai',   'Seat AI row order: rear = fill from the back of the bus towards the front, front = front rows first', 1),
 ('seat_ai_default_deck',   'L',    'string', 'seat_ai',   'Seat AI first deck: L = lower deck first, U = upper deck first (a patient is always seated lower)', 1),
 ('seat_ai_party_together', '1',    'bool',   'seat_ai',   'Seat AI: keep a family / group in one cabin or row', 1),
 ('nearme_on',              '1',    'bool',   'gps',       'Near-me pickup: show "Use my location" on the seat page', 1),
 ('nearme_max_km',          '150',  'int',    'gps',       'Near-me pickup: suggest a pickup only when it is within this many km', 1),
 ('ticket_maps_qr',         '1',    'bool',   'gps',       'Ticket: print a Google Maps QR for the pickup point (only pickups with coordinates)', 0),
 ('mk_require_optin',       '0',    'bool',   'marketing', 'Campaigns: send only to numbers that opted in to offers (OFF = every past customer who has not said STOP)', 0);

CREATE TABLE IF NOT EXISTS `wa_tpl_library` (
  `tkey`        VARCHAR(60)  NOT NULL,
  `lang`        VARCHAR(5)   NOT NULL DEFAULT 'en',
  `body`        TEXT         NOT NULL,
  `meta_name`   VARCHAR(80)  NULL DEFAULT NULL COMMENT 'name of the template as submitted to Meta / AiSensy',
  `meta_status` ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  `updated_by`  BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tkey`, `lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mk_optin` (
  `phone`      VARCHAR(20)  NOT NULL,
  `source`     VARCHAR(40)  NOT NULL DEFAULT 'office' COMMENT 'office | whatsapp | web',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
