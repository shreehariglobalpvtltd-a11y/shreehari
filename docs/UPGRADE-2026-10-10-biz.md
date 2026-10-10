# Business portfolio page + tour package demo set (BIZ) — 10 Oct 2026

Owner (10 Oct 2026): *"the app is a business portfolio with the bus tickets: import-export, truck
reservation, bulk orders, international packaging service, and in future Amazon / Flipkart style
online orders redirected to us, received here and sent to Nepal the legal way with GST paid — all as
coming soon; tourism destination packages with 3-4 demo packages, solo and group introduced;
import-export coming soon."*

Built on the existing PHP + vanilla-JS app. No new CSS/JS file, no new dependency, one additive
migration. Payments, bookings and refunds are untouched. Branch `vis-biz` (cut from a7641c7).

## Honesty rule
Every line on `/business` carries a status badge: **LIVE** (bus tickets, tour packages on enquiry) or
**COMING SOON** (import-export, truck & bulk, packaging & courier, shop-to-Nepal). A coming-soon card
never shows a date or a price and says in words that nothing is accepted yet; the shop-to-Nepal card
asks people **not** to send any parcel yet. The "notify me" form only files interest — nothing is
booked or charged, and the answer says so in the page's language.

## 1. `/business` (+ `/hi/business`, `/ne/business`) — `includes/contentpages.php`
- `ContentPages::PAGES` gains `business`; `index.php` and the dispatch regex route it; `PublicLayout`
  nav tab (💼), site list and crumbs; `sitemap-content.xml` lists it in three languages.
- `ContentPages::BIZ` = the six lines in page order (`key => [section id, icon, live?, service key]`).
  Section ids: `#bus #tours #import-export #truck-bulk #packaging #shop-to-nepal`.
- Bus card facts come from the shell (`PublicLayout::words()['tag']`, open office counts from
  `PublicLayout::offices()`); the company name from `PublicLayout::company()` — nothing typed.
- Tours card: the four demo packages as mini cards with their audience chip (`demoPackages()`:
  packages with an audience, featured first, max 4; the first published ones stand in when none has
  an audience yet).
- Each coming-soon card: two honest lines + `<details>` "Notify me · मलाई खबर गर्नुहोस्" form
  (name, mobile, hidden service key, optional note, honeypot `website`). One shared CSRF token
  (GET `/api/tour-enquiry.php`, as the tour form does), POST JSON to `/api/enquiry.php`.
- JSON-LD: Organization + WebPage + `ItemList` of six `Service` nodes (`provider` → `#org`,
  `offers.availability = https://schema.org/PreOrder` only on the four coming-soon ones) + BreadcrumbList.
- Words: `bz*` keys in `words()` for en / hi / ne.

## 2. `api/enquiry.php` — source `business`
- Whitelist gains `business`. `note` is stored as `Service: <key> · <note>` where key ∈
  `import_export | truck_bulk | packaging | shop_to_nepal` (anything else → `general`).
- Honeypot `website`: a filled box gets the same ok answer and **nothing is stored** (logged).
- Same CSRF + `enquiry_create` IP rate limit (20 / 5 min) as every other enquiry.
- `admin/enquiries.php` source label: **Business interest** (filter + CSV).

## 3. Tour package audience — `database/upgrade-2026-10-10-biz.sql`
- `tour_packages.audience VARCHAR(20) NULL` (information_schema + PREPARE guard, idempotent).
- Demo set (only rows still `is_featured = 0 AND audience IS NULL` are touched, so an office edit is
  never overwritten):

  | slug | audience | featured | sort |
  |---|---|---|---|
  | lumbini-pokhara-kathmandu-circuit | group | 1 | 1 |
  | bardiya-jungle-safari-from-nepalgunj | solo | 1 | 2 |
  | gujarat-heritage-trail | family | 1 | 3 |
  | buddhist-circuit-lumbini-kushinagar-sarnath-shravasti | pilgrim | 1 | 4 |

- `ContentPages::AUDIENCES` (solo / couple / family / group / pilgrim → icon + en / hi / ne).
  Chip rendered on `/tours`, `/tours/<slug>`, the home featured cards (`api/explore.php`
  `packages[].audience`, drawn by `24-homeinfo.js`) and the `/business` mini cards.
- `admin/tours.php`: an **Audience** select; shown read-only until the column exists, so a save on an
  older database cannot fail.
- The code reads `$row['audience'] ?? ''`, so it may ship before or after the migration.

## 4. Home — `#homeBiz` (`app.template.html`)
Right after `#homeExplore`: eyebrow "S Hari Global · beyond the bus", six tiles (Bus LIVE, Tours
LIVE, Import-Export SOON, Truck & bulk SOON, Packaging SOON, Shop-to-Nepal SOON) linking to
`/business#<section>` (localised by `data-hx-href`), badges translated (`hxBiz*` keys in
`04-i18n.js`), styles in `homeinfo.css` §8b, order 12 in `showcase.css` (ties with `#homeExplore`,
DOM order keeps it after). Footer "Explore" nav and the mobile menu get **Business services · व्यापार सेवा**.

## 5. FAQ
The FAQ is static markup in `app.template.html`. The luggage answer no longer claims an import-export
service; a new translated item "Do you do cargo or parcels to Nepal?" → "Not yet … register interest
on the Business services page" (`faqCargo*` keys).

## Tests
- `tests/content-pages-test.php` — new section G (/business in three languages, six ids, badges,
  forms, no date/price, JSON-LD PreOrder count, audience chips, featured payload) and G2
  (source `business`: 419 without token, honeypot stores nothing, stored note, unknown key → general);
  sitemap and source pins updated (`'callback', 'tour', 'business']`).
- `tests/homeinfo-test.php`, `tests/showcase-test.php` — `#homeBiz` pinned (five sections, order 12,
  six tiles, 2 + 4 badges, no date/price).
- Also green: `home-premium-test`, `asset-version-test`, `node tests/i18n-check.js`.

## Deploy
1. `php tests/apply-sql.php database/upgrade-2026-10-10-biz.sql` (idempotent; safe to re-run).
2. Files; the integrator bumps the asset stamp + `sw.js` VERSION once for the whole upgrade.
3. Check `/business`, `/ne/business`, `/tours` chips, Admin → Enquiries filter **Business interest**.

## Owner decisions still open
- The receiving address for shop-to-Nepal, licensed customs partner, GST registration for the
  cargo lines — none is on the page; it only says "coming soon".
- Which services open first: the office sees the interest per service in Enquiries (`Service: …`).
- Audience of the other six packages (Admin → Tour packages → Audience).
