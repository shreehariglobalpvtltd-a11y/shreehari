-- ============================================================
--  upgrade-2026-09-agent-kind.sql  (5 Sep 2026)
--
--  Agent CRM: two kinds of agent on admin_profiles.
--
--    agent_kind      'org'    = Organization agent (travel agency / counter)
--                             serial band 1-20
--                    'person' = Person agent (individual)
--                             serial band 21+
--    contact_person  the contact name inside an organization agent
--    whatsapp        WhatsApp number when it differs from the mobile
--
--  Existing agents default to 'org' (every agent on the live system on
--  5 Sep 2026 is a travel agency holding serial 1-10, which already sits in
--  the org band). Nothing else changes: the serial (agent_codes setting),
--  the commission tier (agent_types setting) and the wallet ledger are
--  untouched.
--
--  Idempotent and additive: guarded ALTERs skipped when the column exists.
--
--    local: "C:\Program Files\MariaDB 12.3\bin\mysql.exe" -h127.0.0.1 -P3307 -uroot shari_test < database/upgrade-2026-09-agent-kind.sql
--    VPS:   mysql shari < database/upgrade-2026-09-agent-kind.sql
-- ============================================================

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'agent_kind');
SET @sql := IF(@have = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `agent_kind` ENUM(''org'',''person'') NOT NULL DEFAULT ''org'' COMMENT ''org = organization agent (serial 1-20), person = individual agent (serial 21+)'' AFTER `counter_name`',
  'SELECT ''admin_profiles.agent_kind already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'contact_person');
SET @sql := IF(@have = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `contact_person` VARCHAR(120) NULL COMMENT ''contact name inside an organization agent'' AFTER `agent_kind`',
  'SELECT ''admin_profiles.contact_person already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'whatsapp');
SET @sql := IF(@have = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `whatsapp` VARCHAR(20) NULL COMMENT ''WhatsApp number when it differs from the mobile'' AFTER `display_phone`',
  'SELECT ''admin_profiles.whatsapp already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
