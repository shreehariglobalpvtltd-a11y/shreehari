-- =====================================================================
--  Upgrade 2026-10-04 — two companies, one letterhead each.
--
--      php tests/apply-sql.php database/upgrade-2026-10-04-two-companies.sql
--
--  Idempotent (INSERT IGNORE only). Adds the settings ROWS the Nepal
--  company's identity needs, so Admin -> Settings can edit them
--  (Settings::setMany skips a key that has no row). Every value ships
--  EMPTY: nothing about the Nepal company is invented here. A blank is
--  never printed — until nepal_company is filled in, a Nepal desk's ticket
--  keeps the India letterhead exactly as today.
--
--  nepal_company / nepal_reg / nepal_office / nepal_phone already exist
--  (seed.sql, upgrade-2026-09-21-brand-and-brain.sql) and are left alone.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
 ('nepal_company','','string','company','Nepal entity name',1),
 ('nepal_reg','','string','company','Nepal registration number',1),
 ('nepal_office','','string','company','Nepal-side office address',1),
 ('nepal_phone','','string','company','Nepal-side office phone',1),
 ('nepal_company_ne','','string','company','Nepal company name in Nepali (prints on Nepal-desk tickets)',1),
 ('nepal_office_ne','','string','company','Nepal office address in Nepali',1),
 ('nepal_pan','','string','company','Nepal company PAN / VAT number - printed only when filled in',1),
 ('nepal_whatsapp','','string','company','Nepal office WhatsApp, with country code (+977...)',1),
 ('nepal_email','','string','company','Nepal office email',1),
 ('nepal_logo','','string','company','Nepal company logo - path under the site root (PNG, max 600 KB). Blank = the site logo',0),
 ('company_gstin','','string','company','India company GSTIN - the invoice says TAX INVOICE only when this is filled in',1),
 ('company_city','','string','company','City printed when a desk has no location of its own',1),
 ('counter_nepal_desks','','string','agent','Legacy: desk codes that open on the Nepal -> India run. Blank = default. The desk table (Admin -> Counters) replaces this.',0);
