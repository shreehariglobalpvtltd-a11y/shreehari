-- ============================================================
--  upgrade-2026-09-agent-login.sql  (4 Sep 2026)
--
--  Agents sign in with EMAIL + USERNAME + PASSWORD.
--
--  `admins.email` and `admins.password_hash` already exist in the schema, so
--  nothing is added or rewritten here. The one thing the new login needs is a
--  guarantee that an email address identifies at most ONE staff account:
--
--    uq_admins_email  UNIQUE (email)
--
--  MySQL/MariaDB allow any number of NULLs in a UNIQUE index, so every staff
--  account that has no email on file (all the current agents) is unaffected.
--
--  Idempotent and SAFE BY REFUSAL: the index is only created when the table
--  actually has no duplicate email today. If two accounts share an address the
--  script leaves the table exactly as it is and prints a note naming the
--  problem, rather than failing halfway through a deploy — clean the duplicate
--  up in Admin -> Agents and re-run.
--
--    local: "C:\Program Files\MariaDB 12.3\bin\mysql.exe" -h127.0.0.1 -P3307 -uroot shari_test < database/upgrade-2026-09-agent-login.sql
--    VPS:   mysql shari < database/upgrade-2026-09-agent-login.sql
-- ============================================================

-- How many addresses are held by more than one account right now?
SET @dupes := (SELECT COUNT(*) FROM (
    SELECT LOWER(email) AS e
      FROM `admins`
     WHERE email IS NOT NULL AND email <> ''
     GROUP BY LOWER(email)
    HAVING COUNT(*) > 1
) d);

SET @have := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND INDEX_NAME = 'uq_admins_email');

SET @sql := IF(@have > 0,
  'SELECT ''uq_admins_email already present'' AS note',
  IF(@dupes > 0,
    'SELECT ''SKIPPED: two or more staff accounts share an email — fix them in Admin -> Agents, then re-run'' AS note',
    'ALTER TABLE `admins` ADD UNIQUE KEY `uq_admins_email` (`email`)'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Name the offenders when there are any, so the office knows where to look.
SELECT LOWER(email) AS duplicated_email, COUNT(*) AS accounts,
       GROUP_CONCAT(username ORDER BY id SEPARATOR ', ') AS usernames
  FROM `admins`
 WHERE email IS NOT NULL AND email <> ''
 GROUP BY LOWER(email)
HAVING COUNT(*) > 1;
