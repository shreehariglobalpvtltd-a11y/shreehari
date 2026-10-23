-- =====================================================================
-- 23 Oct 2026: customer passkeys (api/customer-login.php pk-register) were
-- stored with owner_kind '' because the ENUM had no 'user' member, so every
-- later passkey sign-in answered "belongs to a staff account". Safe to run twice.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `team_passkeys`
  MODIFY `owner_kind` ENUM('admin','staff','user') NOT NULL;

-- rows written before the fix: no owner kind = a customer (staff / admin rows always carried theirs)
UPDATE `team_passkeys` SET `owner_kind` = 'user' WHERE `owner_kind` = '' OR `owner_kind` IS NULL;
