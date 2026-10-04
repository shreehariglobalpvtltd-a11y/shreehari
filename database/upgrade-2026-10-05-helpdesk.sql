-- =====================================================================
--  5 Oct 2026 - Help Desk: numbered tickets (HD-0051 + today's token),
--  SLA due time, escalation, auto-assign, customer acknowledgement.
--  includes/helpdesk.php, admin/support-inbox.php, help.php,
--  api/helpdesk.php, cron/helpdesk.php
--
--  Every column is guarded on its own (MariaDB ADD COLUMN IF NOT EXISTS)
--  because live databases have drifted from schema.sql before.
--  Re-running this file is harmless.
-- =====================================================================

-- columns from the 24 Sep handoff migration, in case it never ran
ALTER TABLE `support_tickets` ADD COLUMN IF NOT EXISTS `source` VARCHAR(20) NOT NULL DEFAULT 'web' COMMENT 'web, chat, callback, desk, admin, whatsapp_ai';
ALTER TABLE `support_tickets` ADD COLUMN IF NOT EXISTS `sender_role` VARCHAR(20) NULL;
ALTER TABLE `support_tickets` ADD COLUMN IF NOT EXISTS `language` VARCHAR(8) NULL;
ALTER TABLE `support_tickets` ADD COLUMN IF NOT EXISTS `dedupe_key` CHAR(64) NULL;
ALTER TABLE `support_tickets` ADD COLUMN IF NOT EXISTS `evidence_path` VARCHAR(255) NULL;
ALTER TABLE `support_tickets` ADD COLUMN IF NOT EXISTS `office_notified_at` DATETIME NULL;
ALTER TABLE `support_messages` ADD COLUMN IF NOT EXISTS `is_internal` TINYINT(1) NOT NULL DEFAULT 0;

-- help desk
ALTER TABLE `support_tickets` ADD COLUMN IF NOT EXISTS `due_at` DATETIME NULL COMMENT 'SLA - answer before this, else cron escalates once';
ALTER TABLE `support_tickets` ADD COLUMN IF NOT EXISTS `day_token` SMALLINT UNSIGNED NULL COMMENT 'Nth ticket of the day, said as #7';
ALTER TABLE `support_tickets` ADD COLUMN IF NOT EXISTS `first_reply_at` DATETIME NULL COMMENT 'first reply that reached the customer';
ALTER TABLE `support_tickets` ADD COLUMN IF NOT EXISTS `escalated_at` DATETIME NULL;
ALTER TABLE `support_tickets` ADD COLUMN IF NOT EXISTS `ack_sent_at` DATETIME NULL COMMENT 'a provider ACCEPTED the number message - not proof of delivery';
ALTER TABLE `support_tickets` ADD COLUMN IF NOT EXISTS `counter_code` VARCHAR(16) NULL COMMENT 'desk of the linked booking - auto-assign prefers its staff';
ALTER TABLE `support_tickets` ADD COLUMN IF NOT EXISTS `created_by` BIGINT UNSIGNED NULL COMMENT 'staff who typed it in at the desk';

CREATE INDEX IF NOT EXISTS `ix_support_due` ON `support_tickets` (`status`, `due_at`);
CREATE INDEX IF NOT EXISTS `ix_support_assignee` ON `support_tickets` (`assigned_to`, `status`);
CREATE INDEX IF NOT EXISTS `ix_support_dedupe` ON `support_tickets` (`dedupe_key`, `created_at`);

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('helpdesk_on',             '1',    'bool', 'helpdesk', 'Help desk: customers can open a numbered ticket (Help page, chat, call-me-back)', 0),
 ('helpdesk_autoassign',     '1',    'bool', 'helpdesk', 'Help desk: give each new ticket to the least-busy support person (desk staff first)', 0),
 ('helpdesk_ack',            '1',    'bool', 'helpdesk', 'Help desk: send the ticket number to the customer (WhatsApp, else SMS)', 0),
 ('helpdesk_office_alert',   '1',    'bool', 'helpdesk', 'Help desk: alert the office WhatsApp for urgent/high and overdue tickets', 0),
 ('helpdesk_sla_urgent',     '60',   'int',  'helpdesk', 'Help desk SLA minutes - urgent', 0),
 ('helpdesk_sla_high',       '240',  'int',  'helpdesk', 'Help desk SLA minutes - high', 0),
 ('helpdesk_sla_normal',     '1440', 'int',  'helpdesk', 'Help desk SLA minutes - normal', 0),
 ('helpdesk_sla_low',        '2880', 'int',  'helpdesk', 'Help desk SLA minutes - low', 0),
 ('helpdesk_autoclose_days', '3',    'int',  'helpdesk', 'Help desk: close resolved tickets after this many days', 0),
 ('helpdesk_wa_template',    'shg_help_ticket', 'string', 'helpdesk', 'AiSensy template for the ticket-number message (name, number, topic, link)', 0);
