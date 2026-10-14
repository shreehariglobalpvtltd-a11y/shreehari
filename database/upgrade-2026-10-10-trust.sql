-- =====================================================================
--  10 Oct 2026 - TRUST: the company's channels and its domain mailbox
--
--  Owner: "S Hari Global Pvt Ltd ko Gmail YouTube / Facebook sanga connect
--  xa, tyo mention gardeu - shreehariglobal.in ko email ni xa, dekhaideu".
--
--      php tests/apply-sql.php database/upgrade-2026-10-10-trust.sql
--
--  Settings rows (group company -> Admin -> Settings -> Company & offices).
--  Every value ships EMPTY on purpose: the website, the JSON-LD and the
--  content-page footers show a channel only once the office pastes its
--  full https:// link (includes/sitefacts.php brand.social), and the domain
--  mailbox only once it is typed (brand.email2). Nothing is invented.
--
--    company_email_alt   the official domain mailbox (info@shreehariglobal.in)
--                        - replaces the Gmail in the top bar, beside it in
--                          the footer / contact rows, first in the JSON-LD
--    company_youtube     full channel / page URL (https://...)
--    company_facebook    full page URL
--    company_instagram   full profile URL
--    company_tiktok      full profile URL
--    ceo_facebook        the Director's own profile - replaces the link that
--                        used to be typed into the home-page footer
--
--  The social keys already exist on live (upgrade-2026-09-21-brand-and-
--  brain.sql) - INSERT IGNORE leaves a filled row alone. No table changes.
--  Re-running this file is harmless.
--
--  14 Oct 2026 (review): ONE exception to "ships empty" - ceo_facebook.
--  The home footer has carried the Director's Facebook profile as a typed
--  link since September; moving it into Settings must not make it vanish on
--  deploy day. The UPDATE copies that same, already-published URL into the
--  row only while the row is still blank, so a value the office typed is
--  never overwritten and a re-run changes nothing.
-- =====================================================================

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('company_email_alt', '', 'string', 'company', 'Official domain email (e.g. info@shreehariglobal.in) - replaces the Gmail in the top bar, shown beside it in the footer once filled in', 1),
 ('company_youtube',   '', 'string', 'company', 'YouTube channel URL - paste the full https:// link, empty = not shown', 1),
 ('company_facebook',  '', 'string', 'company', 'Facebook page URL - paste the full https:// link, empty = not shown', 1),
 ('company_instagram', '', 'string', 'company', 'Instagram URL - paste the full https:// link, empty = not shown', 1),
 ('company_tiktok',    '', 'string', 'company', 'TikTok URL - paste the full https:// link, empty = not shown', 1),
 ('ceo_facebook',      '', 'string', 'company', 'Director Facebook profile URL - paste the full https:// link, empty = not shown', 1);

UPDATE `settings` SET `svalue` = 'https://www.facebook.com/sherbahadur.biswakarma.58'
 WHERE `skey` = 'ceo_facebook' AND (`svalue` IS NULL OR TRIM(`svalue`) = '');

-- 14 Oct 2026 (review): every cached ticket / invoice redraws ONCE with the
-- statutory lines. Ticket::staleBefore() takes the later of the code's
-- PNG/PDF_LAYOUT_CHANGED and this setting, so stamping it when the file is
-- applied (AFTER the new code is live) covers tickets cached between the
-- code stamp and the deploy. Guarded by the build moment (1791996000 =
-- 14 Oct 2026 22:10 IST): a re-run, or a later Company-details edit that
-- already moved it forward, leaves it alone.
INSERT INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `is_public`)
VALUES ('ticket_identity_changed_at', CAST(UNIX_TIMESTAMP() AS CHAR), 'int', 'company', 0)
ON DUPLICATE KEY UPDATE `svalue` = IF(CAST(COALESCE(`svalue`, '0') AS UNSIGNED) < 1791996000, CAST(UNIX_TIMESTAMP() AS CHAR), `svalue`);

UPDATE `settings` SET `label` = 'Official domain email (e.g. info@shreehariglobal.in) - replaces the Gmail in the top bar, shown beside it in the footer once filled in'
 WHERE `skey` = 'company_email_alt' AND `label` = 'Official domain email (e.g. info@shreehariglobal.in) - shown beside the Gmail once filled in';
