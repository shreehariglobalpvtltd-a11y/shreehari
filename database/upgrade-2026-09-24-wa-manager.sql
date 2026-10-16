-- =====================================================================
--  upgrade-2026-09-24-wa-manager.sql — the WhatsApp assistant as the
--  company's manager for staff (24 Sep 2026).
--
--  Owner ask (24 Sep, Nepali): "agent haru lai ni WhatsApp bata ticket
--  katna deu, bulk ticket support garos, euta format bata ek click ma
--  ticket aaos, agent le aafno commission heros, admin le sabai heros,
--  WhatsApp bata login garna milos, bot le employee lai manager jasto
--  kaam garos, naam ra mobile number ma mistake nahos."
--
--  Three new powers, every one OFF until the office switches it on:
--
--    wa_login_on   a member of staff writing from a number that is NOT on
--                  their staff record signs in with
--                      login <agent code | username | email> <password>
--                  (+ a one-time code to their registered mobile when
--                  wa_login_otp is on). The session is a row in wa_logins
--                  below, expires by itself, and can be revoked from
--                  Admin → AI Activity.
--    wa_bulk_on    staff paste a list — one passenger per line, name and
--                  10-digit mobile — the assistant quotes every ticket,
--                  and one "ho" cuts them all through QuickTicket::sell(),
--                  each ticket to its own passenger's WhatsApp, the
--                  commission to the seller. (Send FORMAT for the template.)
--    wa_agent_payout  an agent may ask for a commission payout from
--                  WhatsApp (AgentWallet::requestPayout — the same row the
--                  agent panel writes, decided by the office as before).
--
--  Additive and re-runnable: one table, settings rows that ship OFF.
--  Nothing changes on deploy until a switch is turned on.
--  NOTE: no semicolons inside string literals — tests/apply-sql.php splits on them.
-- =====================================================================
SET NAMES utf8mb4;

-- A staff sign-in made over WhatsApp: which number, which account, until
-- when. One live row per number (a new sign-in revokes the older one).
-- No foreign key on admin_id on purpose: a deleted account must not take
-- its sign-in history with it (same reasoning as admin_login_events).
CREATE TABLE IF NOT EXISTS `wa_logins` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone`        VARCHAR(20) NOT NULL COMMENT 'normalised sender number, the identity every channel agrees on',
  `admin_id`     BIGINT UNSIGNED NOT NULL COMMENT 'admins.id that was signed in',
  `method`       VARCHAR(20) NOT NULL DEFAULT 'password' COMMENT 'password or password+otp',
  `created_at`   DATETIME NOT NULL,
  `expires_at`   DATETIME NOT NULL,
  `last_seen_at` DATETIME NULL,
  `revoked_at`   DATETIME NULL,
  `revoked_by`   BIGINT UNSIGNED NULL COMMENT 'admins.id of the office user who revoked it, NULL = self / expiry',
  PRIMARY KEY (`id`),
  KEY `ix_wal_phone` (`phone`, `revoked_at`, `expires_at`),
  KEY `ix_wal_admin` (`admin_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('wa_login_on',        '0',  'bool', 'ai', 'WhatsApp LOGIN - staff may sign in from any number with "login <agent code or username> <password>" and then use the staff tools. OFF = only a number on the staff record is staff',0),
('wa_login_otp',       '1',  'bool', 'ai', 'WhatsApp login also needs a one-time code sent to the REGISTERED mobile of that staff account (recommended - a password alone is not enough from a strange number). Office roles always need it',0),
('wa_login_ttl_hours', '12', 'int',  'ai', 'How many hours a WhatsApp login stays valid before the person must sign in again',0),
('wa_bulk_on',         '0',  'bool', 'ai', 'BULK TICKETS on WhatsApp - staff paste a list (send FORMAT for the template), the assistant quotes every ticket, one "ho" cuts them all. Each ticket goes to its own passenger, commission to the seller',0),
('wa_bulk_max_rows',   '30', 'int',  'ai', 'Most passengers one bulk message may carry',0),
('wa_agent_payout',    '0',  'bool', 'ai', 'Let an agent REQUEST a commission payout from WhatsApp (the office still decides it in the Agent Panel)',0);
