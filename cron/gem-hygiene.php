<?php
/**
 * cron/gem-hygiene.php — keep the customer vault clean and complete.
 *
 * Recommended: nightly, before the brain.
 *   crontab:   30 1 * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/gem-hygiene.php
 *   or by URL: https://www.shreehariglobal.in/cron/gem-hygiene.php?token=CRON_TOKEN
 *
 * Three jobs, in order of how much they matter:
 *
 *  1. BACKFILL. Every phone that has ever bought a confirmed ticket should
 *     have a vault row. The live database has years of counter and agent
 *     sales made before the vault existed; without this pass they stay
 *     invisible and a long-standing customer is treated as a stranger.
 *  2. RE-ENRICH. Profiles whose travel block is older than their newest
 *     booking get recomputed. The post-commit hook already does this for
 *     fresh sales; this catches the ones where the hook could not run
 *     (imported rows, a cancelled-then-reinstated booking).
 *  3. REPORT. Count the vault — total gems, how many are named, how many
 *     carry a captured country, how many are active — and raise incidents
 *     for the two things that quietly rot it: placeholder numbers, and
 *     tickets going out with no country captured.
 *
 * WHAT THIS JOB DELIBERATELY DOES NOT DO
 * --------------------------------------
 * It does NOT auto-merge "duplicate" phone numbers. Two numbers that differ
 * by one digit and share a name are not evidence of a typo — India and Nepal
 * both have millions of ten-digit numbers and plenty of people called Ram.
 * Folding one into the other would point a real customer's tickets at a
 * stranger's phone, which is the single worst failure this system can have.
 * So duplicates are COUNTED and surfaced for a human to look at, and
 * user_profiles.merged_into is only ever set by that human.
 *
 * It also never deletes a gem. A customer who has not travelled in three
 * years is still a customer.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/gemvault.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/health.php';

const BACKFILL_BATCH = 400;   // new phones adopted per run
const REENRICH_BATCH = 300;   // stale profiles refreshed per run

/* ---------------------------------------------------------------------
 *  1. Backfill — phones with confirmed sales but no vault row
 * ------------------------------------------------------------------- */

$newPhones = Database::fetchAll(
    "SELECT b.contact_phone AS phone, MAX(b.created_at) AS seen
       FROM bookings b
  LEFT JOIN user_profiles p ON p.phone = b.contact_phone
      WHERE b.status IN ('confirmed','completed')
        AND p.phone IS NULL
      GROUP BY b.contact_phone
      ORDER BY seen DESC
      LIMIT " . BACKFILL_BATCH
);

$adopted = 0;
$skipped = 0;
foreach ($newPhones as $row) {
    $phone = (string) $row['phone'];
    if (GemVault::isPlaceholder($phone)) {
        $skipped++;
        continue;
    }
    if (GemVault::upsert($phone, '', '', 'counter') === '') {
        $skipped++;
        continue;
    }
    if (GemVault::enrich($phone) !== null) {
        $adopted++;
    }
}

/* ---------------------------------------------------------------------
 *  2. Re-enrich — profiles whose travel block is behind their bookings
 * ------------------------------------------------------------------- */

$stale = Database::fetchAll(
    "SELECT p.phone
       FROM user_profiles p
       JOIN bookings b ON b.contact_phone = p.phone
                      AND b.status IN ('confirmed','completed')
      WHERE p.merged_into IS NULL
      GROUP BY p.phone, p.enriched_at
     HAVING p.enriched_at IS NULL OR MAX(b.updated_at) > p.enriched_at
      ORDER BY MAX(b.updated_at) DESC
      LIMIT " . REENRICH_BATCH
);

$refreshed = 0;
foreach ($stale as $row) {
    if (GemVault::enrich((string) $row['phone']) !== null) {
        $refreshed++;
    }
}

/* ---------------------------------------------------------------------
 *  3. Flag the placeholders
 * ------------------------------------------------------------------- */

$flagged = 0;
$maybePlaceholder = Database::fetchAll(
    'SELECT phone FROM user_profiles WHERE is_placeholder = 0 LIMIT 5000'
);
foreach ($maybePlaceholder as $row) {
    if (GemVault::isPlaceholder((string) $row['phone'])) {
        Database::update('user_profiles', ['is_placeholder' => 1], 'phone = :p', ['p' => (string) $row['phone']]);
        $flagged++;
    }
}

/* ---------------------------------------------------------------------
 *  4. Report
 * ------------------------------------------------------------------- */

$stats = GemVault::stats();

/* Bookings in the last 30 days whose country was never captured. These are
   the ones at risk of being WhatsApped to the wrong country, because a bare
   ten-digit number is valid in BOTH India and Nepal. */
$noCountry = (int) Database::scalar(
    "SELECT COUNT(*) FROM bookings
      WHERE status IN ('confirmed','completed')
        AND created_at >= :since
        AND (contact_country_code IS NULL OR contact_country_code = '')",
    ['since' => date('Y-m-d H:i:s', strtotime('-30 days'))],
    0
);

if ($noCountry > 0) {
    Health::open(
        'gem_no_country',
        'gem.nocountry',
        $noCountry > 20 ? Health::WARN : Health::INFO,
        $noCountry . ' recent ticket(s) have no country captured',
        'India and Nepal share the same 10-digit mobile format, so a bare number carries no country of its own. '
            . 'These bookings will fall back to the default dialing code, and for a Nepali passenger that means the '
            . 'ticket goes to whoever owns that number in India.',
        "1. Check the checkout country picker is showing (app home -> book -> contact step).\n"
            . "2. At the counter, ask the passenger which country their number is from.\n"
            . '3. Existing rows self-correct once the customer books again with the picker set.',
    );
} else {
    Health::resolve('gem.nocountry');
}

/* Placeholder numbers are a desk habit, not a bug — but a desk that types
   0000000000 for half its walk-ins is a desk throwing away the company's
   most valuable asset, one customer at a time. */
if ($stats['placeholder'] > 0) {
    Health::open(
        'gem_placeholder',
        'gem.placeholder',
        Health::INFO,
        $stats['placeholder'] . ' contact(s) stored as a placeholder number',
        'These are walk-ins whose real phone was never recorded (0000000000 and similar). '
            . 'They can never be sent a ticket, never be recognised on their next visit, and never be counted as customers.',
        'Ask for a mobile number at the counter before issuing the ticket — one field, and the passenger gets their ticket on their own phone.',
    );
} else {
    Health::resolve('gem.placeholder');
}

/* Duplicate CANDIDATES only — counted, never merged. See the file header. */
$dupCandidates = (int) Database::scalar(
    "SELECT COUNT(*) FROM (
        SELECT full_name FROM user_profiles
         WHERE merged_into IS NULL AND full_name IS NOT NULL AND full_name <> ''
         GROUP BY full_name HAVING COUNT(*) > 1
     ) d",
    [],
    0
);

cron_done([
    'adopted'    => $adopted,
    'skipped'    => $skipped,
    'refreshed'  => $refreshed,
    'flagged'    => $flagged,
    'gems'       => $stats['gems'],
    'named'      => $stats['named'],
    'active90'   => $stats['active90'],
    'returning'  => $stats['returning'],
    'noCountry'  => $noCountry,
    'dupNames'   => $dupCandidates,
]);
