-- =====================================================================
--  10 Oct 2026 - WhatsApp agent routing, ticket links, ticket edits by OTP
--  (includes/waagentroute.php, includes/ticketlink.php, includes/waedit.php,
--  includes/wateach.php, ticket/view.php, admin/wa-routing.php,
--  admin/customer-lists.php, cron/wa-agent-route.php, cron/wa-good-morning.php).
--
--  Owner rule (10 Oct): paid WhatsApp credit only for the customer's own
--  ticket. Everything this file switches on talks for FREE (the Meta number's
--  24 h window) - closed window = the next agent, a web push, or the admin
--  panel, never a paid template unless a *_tpl_on switch is turned on.
--
--  Agents are NOT copied into a new table: an agent is an `admins` row (role
--  agent / counter) and its routes are `agent_route_permissions`. Only the
--  live presence is new. Offers / discounts stay in `coupons`
--  (auto_apply 1 = applied by itself, 0 = only when the customer asks / staff
--  sends it). Additive and idempotent.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `wa_agent_presence` (
  `admin_id`            BIGINT UNSIGNED NOT NULL COMMENT 'admins.id of the agent / counter',
  `availability_status` ENUM('available','busy','offline') NOT NULL DEFAULT 'available',
  `busy_until`          DATETIME NULL COMMENT 'busy lapses back to available at this time',
  `last_active`         DATETIME NULL COMMENT 'last message from this agent on the Meta number',
  `last_offer_at`       DATETIME NULL,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`),
  KEY `ix_wap_status` (`availability_status`, `busy_until`),
  CONSTRAINT `fk_wap_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `delivery_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`  BIGINT UNSIGNED NOT NULL COMMENT 'the ticket (bookings.id)',
  `pnr`         VARCHAR(40) NOT NULL,
  `agent_id`    BIGINT UNSIGNED NULL COMMENT 'admins.id tried, NULL for customer / admin rows',
  `channel`     VARCHAR(20) NOT NULL DEFAULT 'watch' COMMENT 'watch | wa_free | push | admin | customer',
  `status`      VARCHAR(24) NOT NULL COMMENT 'watching delivered offered accepted declined timeout skipped exhausted admin_notified customer_direct changed',
  `detail`      VARCHAR(255) NULL,
  `wamid`       VARCHAR(128) NULL,
  `deadline_at` DATETIME NULL COMMENT 'watching / offered rows: when the cron looks again',
  `closed`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = this row needs no more cron work',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_dl_booking` (`booking_id`, `id`),
  KEY `ix_dl_open` (`closed`, `deadline_at`),
  KEY `ix_dl_agent` (`agent_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_links` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`  BIGINT UNSIGNED NOT NULL COMMENT 'the ticket (bookings.id)',
  `short_code`  VARCHAR(16) NOT NULL,
  `full_url`    VARCHAR(500) NOT NULL COMMENT 'the keyed ticket image link it stands for',
  `expires_at`  DATETIME NOT NULL,
  `hits`        INT UNSIGNED NOT NULL DEFAULT 0,
  `last_hit_at` DATETIME NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tl_code` (`short_code`),
  KEY `ix_tl_booking` (`booking_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
 ('wa_route_on',          '1',  'bool',   'whatsapp', 'Agent routing - a ticket the customer did not get goes to the route agent (free WhatsApp only)', 0),
 ('wa_route_grace_min',   '3',  'int',    'whatsapp', 'Agent routing - minutes to wait for the customer ticket before an agent is asked', 0),
 ('wa_route_timeout_min', '5',  'int',    'whatsapp', 'Agent routing - minutes an agent has to answer before the next agent is asked', 0),
 ('wa_route_busy_min',    '60', 'int',    'whatsapp', 'Agent routing - minutes an agent who did not answer stays busy', 0),
 ('wa_route_max_agents',  '4',  'int',    'whatsapp', 'Agent routing - agents tried per ticket before the admin is told', 0),
 ('wa_admin_number',      '',   'string', 'whatsapp', 'Agent routing - admin WhatsApp when no agent answers (empty = admin_whatsapp)', 0),
 ('wa_hi_meta',           '1',  'bool',   'whatsapp', 'Customers send hi to the Meta number (webhook live) instead of the AiSensy number', 0),
 ('meta_wa_number',       '918735881507', 'string', 'whatsapp', 'The Meta Cloud API WhatsApp number as digits', 0),
 ('wa_ticket_buttons_on', '1',  'bool',   'whatsapp', 'Edit name / date / agent / cancel buttons after a free WhatsApp ticket', 0),
 ('wa_edit_otp_on',       '1',  'bool',   'whatsapp', 'Ticket edits on WhatsApp need a 6 digit code first', 0),
 ('ticket_link_days',     '30', 'int',    'whatsapp', 'Short ticket link (/ticket/view.php) lifetime in days after travel', 0),
 ('wa_good_morning_on',   '1',  'bool',   'whatsapp', 'Good morning message to route agents at 6 AM (free window only)', 0),
 ('wa_good_morning_tpl_on','0', 'bool',   'whatsapp', 'Good morning - use the paid good_morning_agent template for closed windows', 0),
 ('wa_teach_on',          '1',  'bool',   'ai',       'Admin can teach the bot on WhatsApp (sikau / prashna / jawaf)', 0);

-- whatsapp/webhook.php now marks a queued ticket delivered / read by its wamid on every Meta status.
ALTER TABLE `wa_ticket_queue` ADD INDEX IF NOT EXISTS `ix_waq_wamid` (`wamid`);
