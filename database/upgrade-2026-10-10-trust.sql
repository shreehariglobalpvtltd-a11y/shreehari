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
--                        - shown beside the Gmail, first in the JSON-LD
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
-- =====================================================================

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('company_email_alt', '', 'string', 'company', 'Official domain email (e.g. info@shreehariglobal.in) - shown beside the Gmail once filled in', 1),
 ('company_youtube',   '', 'string', 'company', 'YouTube channel URL - paste the full https:// link, empty = not shown', 1),
 ('company_facebook',  '', 'string', 'company', 'Facebook page URL - paste the full https:// link, empty = not shown', 1),
 ('company_instagram', '', 'string', 'company', 'Instagram URL - paste the full https:// link, empty = not shown', 1),
 ('company_tiktok',    '', 'string', 'company', 'TikTok URL - paste the full https:// link, empty = not shown', 1),
 ('ceo_facebook',      '', 'string', 'company', 'Director Facebook profile URL - paste the full https:// link, empty = not shown', 1);
