<?php
/**
 * upgrade-2026-09-vip-advance.php — the point-to-point fare board, the
 * advance-booking offer, and the WhatsApp admin-control switch (26 Sep 2026).
 *
 * WHY
 * ---
 * The sharing fare had two values, one per direction. The owner's real board
 * is finer: a pickup below Ahmedabad pays 2200, Ahmedabad itself 2000, and
 * the return leg is not the mirror of the outbound. Encoding that as more
 * fare_to_* rows would have meant one settings key per town per direction —
 * and a second place for the website, the counter, the chatbot and WhatsApp
 * each to get wrong. So the BOARD is the setting: an ordered rule list read
 * by Fare::fareRules().
 *
 * Alongside it, the Dashain / Tihar advance-booking offer: book at least N
 * hours before departure and save P%. Hours, percentage, window, wording,
 * cap, which modes it covers and the ON switch are all rows, so the office
 * can move 24 to 48 or 10% to 0% with no deploy.
 *
 * WHAT RUNNING THIS CHANGES FOR A PASSENGER
 * -----------------------------------------
 * The fare board: yes — a Surat → Rupaidiha sharing seat goes from ₹2,000 to
 * ₹2,200, which is the owner's instruction of 26 Sep 2026. Ahmedabad stays
 * ₹2,000. The advance offer: nothing, because advance_offer_on is seeded OFF.
 * Turn it on from Admin → Fares & offers when the festival campaign starts.
 *
 * Idempotent: every row is only seeded when absent, and the ALTER is skipped
 * when the column already exists.
 *
 *   php database/upgrade-2026-09-vip-advance.php            (VPS)
 *   php -c .claude/php-dev.ini database/…                   (local)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';

$log = static function (string $s): void { echo $s . "\n"; };

/* ---------------------------------------------------------------------
 *  1. bookings.advance_discount — the offer in its own column.
 *
 *  BookingService already grants the discount without it (the column is
 *  probed at run time), so the code may be deployed before this runs.
 * ------------------------------------------------------------------- */
$hasCol = Database::fetch("SHOW COLUMNS FROM bookings LIKE 'advance_discount'") !== null;
if ($hasCol) {
    $log('bookings.advance_discount already exists.');
} else {
    Database::query(
        "ALTER TABLE bookings
           ADD COLUMN `advance_discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00
               COMMENT 'advance-booking offer taken off this sale'
           AFTER `coupon_discount`"
    );
    $log('Added bookings.advance_discount.');
}

/* ---------------------------------------------------------------------
 *  2. The fare board.
 *
 *  Seeded from Fare::FARE_RULES_DEFAULT, which holds exactly the four
 *  rules the owner confirmed on 26 Sep 2026. An existing row is left
 *  alone — once the office has edited the board, this file must never
 *  overwrite it.
 * ------------------------------------------------------------------- */
if (Settings::get('fare_rules', null) === null) {
    Settings::set('fare_rules', Fare::FARE_RULES_DEFAULT, 'json', 'pricing', true);
    $log('Seeded fare_rules with the four owner-confirmed rules.');
} else {
    $log('fare_rules already set — left untouched.');
}

if (Settings::get('fare_point_aliases', null) === null) {
    // Empty on purpose: the packaged aliases in Fare::pointAliases() already
    // map "Ahmedabad" to the long official stop name. This row is only for
    // spellings the office wants to add later.
    Settings::set('fare_point_aliases', [], 'json', 'pricing', true);
    $log('Seeded fare_point_aliases (empty — the packaged aliases stand).');
}

/* ---------------------------------------------------------------------
 *  3. The advance-booking offer. Shipped OFF.
 * ------------------------------------------------------------------- */
$offer = [
    'advance_offer_on'      => ['bool',   '0',
        'Advance booking offer — ON / OFF'],
    'advance_offer_hours'   => ['int',    '24',
        'Book at least this many hours before departure'],
    'advance_offer_percent' => ['float',  '10',
        'Advance booking discount (%)'],
    'advance_offer_from'    => ['string', '',
        'Offer runs from (YYYY-MM-DD, blank = no start)'],
    'advance_offer_to'      => ['string', '',
        'Offer runs until (YYYY-MM-DD, blank = no end)'],
    'advance_offer_max_inr' => ['float',  '0',
        'Largest discount in ₹ (0 = no ceiling)'],
    'advance_offer_modes'   => ['string', 'all',
        'Which bookings: all / sharing / private'],
    'advance_offer_title'   => ['string', 'Dashain · Tihar advance offer',
        'Offer name shown on the fare breakdown'],
    'advance_offer_text'    => ['string', 'Book 24 hours before departure and save 10% — VIP private sleeper included.',
        'Offer line shown on the home page'],
];
foreach ($offer as $key => [$type, $val, $label]) {
    if (Settings::get($key, null) === null) {
        Settings::set($key, $val, $type, 'pricing', true);
        $log("Seeded {$key} = " . ($val === '' ? '(blank)' : $val));
    } else {
        $log("{$key} already set.");
    }
    // Settings::set() only touches svalue/stype on an existing row.
    Database::query(
        'UPDATE settings SET label = :l, sgroup = :g, is_public = 1 WHERE skey = :k',
        ['l' => $label, 'g' => 'pricing', 'k' => $key]
    );
}

/* ---------------------------------------------------------------------
 *  4. WhatsApp admin control of the money rules. Shipped OFF, with an
 *     EMPTY number list — two independent gates, both of which must be
 *     opened deliberately.
 *
 *  wa_rules_numbers is NOT public: the office's own numbers must not ride
 *  in the browser bootstrap.
 * ------------------------------------------------------------------- */
$ctrl = [
    'wa_rules_control' => ['bool',   '0',
        'WhatsApp: let authorised admins change fares / offers'],
    'wa_rules_numbers' => ['string', '',
        'WhatsApp numbers allowed to change fares (comma separated)'],
];
foreach ($ctrl as $key => [$type, $val, $label]) {
    if (Settings::get($key, null) === null) {
        Settings::set($key, $val, $type, 'whatsapp', false);
        $log("Seeded {$key} = " . ($val === '' ? '(blank)' : $val));
    } else {
        $log("{$key} already set.");
    }
    Database::query(
        'UPDATE settings SET label = :l, sgroup = :g, is_public = 0 WHERE skey = :k',
        ['l' => $label, 'g' => 'whatsapp', 'k' => $key]
    );
}

/* ---------------------------------------------------------------------
 *  5. What the board now says, so the person running this can read the
 *     new prices before a passenger does.
 * ------------------------------------------------------------------- */
Settings::flush();

$log('');
$log('Fare board now in force:');
foreach (Fare::fareBoard() as $row) {
    $log(sprintf(
        '  %-32s -> %-32s  %s  (%s)',
        $row['from'],
        $row['to'],
        str_pad(inr($row['amount']), 9, ' ', STR_PAD_LEFT),
        $row['source']
    ));
}

$adv = Fare::advanceOffer();
$log('');
$log(sprintf(
    'Advance offer: %s — %s%% when booked %d h before departure%s.',
    $adv['on'] ? 'ON' : 'OFF',
    rtrim(rtrim(number_format($adv['percent'], 2, '.', ''), '0'), '.'),
    $adv['hours'],
    $adv['from'] !== '' || $adv['to'] !== ''
        ? ' (' . ($adv['from'] !== '' ? $adv['from'] : 'any') . ' … ' . ($adv['to'] !== '' ? $adv['to'] : 'any') . ')'
        : ''
));
$log('WhatsApp rule control: ' . (Settings::getBool('wa_rules_control', false) ? 'ON' : 'OFF')
    . ', numbers: ' . (trim(Settings::getString('wa_rules_numbers', '')) !== '' ? 'set' : 'none'));

exit(0);
