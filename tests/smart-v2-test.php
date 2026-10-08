<?php
/**
 * =====================================================================
 *  smart-v2-test.php — Smart booking v2 (owner master prompt 2.0, 8 Oct 2026)
 *
 *    • Smart Settings rows exist (seat_ai_*, nearme_*, ticket_maps_qr,
 *      mk_require_optin) in their own groups, public where the app reads them
 *    • QuickTicket seat order: rear-to-front by default, front-first and
 *      upper-first when the setting says so
 *    • gender is re-checked when a rejected booking's seats are re-claimed
 *      at payment confirmation (a man took the other berth meanwhile)
 *    • ticket pickup map: coordinates from the booking label / stop row,
 *      none when the stop has none
 *    • WhatsApp template library: 30+ drafts, every variable known, values
 *      only from records, missing stays visible, Meta numbering, the AI
 *      wording guard refuses invented numbers / offers / changed variables
 *    • campaign consent: opt-in required only when the setting is on
 *    • the seat page wiring (seatAiCfg, Recommended legend, near-me) and
 *      i18n keys in en / hi / ne
 *
 *    php -c .claude/php-dev.ini tests/smart-v2-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/watpllib.php';
require_once INCLUDE_PATH . '/customerdirectory.php';

const SV_PHONE = '910000772';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Smart booking v2 ===\n\n";
$root = dirname(__DIR__);

echo "-- 1. Smart Settings rows --\n";
$need = ['seat_ai_on' => ['seat_ai', 1], 'seat_ai_row_order' => ['seat_ai', 1], 'seat_ai_default_deck' => ['seat_ai', 1],
         'seat_ai_party_together' => ['seat_ai', 1], 'nearme_on' => ['gps', 1], 'nearme_max_km' => ['gps', 1],
         'ticket_maps_qr' => ['gps', 0], 'mk_require_optin' => ['marketing', 0]];
foreach ($need as $k => [$grp, $pub]) {
    $r = Database::fetch('SELECT sgroup, is_public, label FROM settings WHERE skey = :k', ['k' => $k]);
    check("setting $k in group $grp" . ($pub ? ' (public)' : ''), $r !== null && $r['sgroup'] === $grp && (int) $r['is_public'] === $pub && trim((string) $r['label']) !== '');
}
check('row order ships rear-first', Settings::getString('seat_ai_row_order', '') === 'rear');
$pubS = Settings::publicSettings();
check('the app receives seat_ai_on / nearme_on', array_key_exists('seat_ai_on', $pubS) && array_key_exists('nearme_on', $pubS));
check('the app never receives the opt-in switch', !array_key_exists('mk_require_optin', $pubS));
check('settings page titles the new groups', str_contains((string) file_get_contents($root . '/admin/settings.php'), "'seat_ai'    => ['🪑 Seat AI'"));

echo "-- 2. Server seat order --\n";
$rank = new ReflectionMethod(QuickTicket::class, 'seatRank');
$rank->setAccessible(true);
$order = static function (array $seats) use ($rank): array { usort($seats, static fn($a, $b) => $rank->invoke(null, $a) <=> $rank->invoke(null, $b)); return $seats; };
$prevOrder = Settings::getString('seat_ai_row_order', 'rear');
$prevDeck  = Settings::getString('seat_ai_default_deck', 'L');
try {
    Settings::set('seat_ai_row_order', 'rear', 'string', 'seat_ai', true); Settings::set('seat_ai_default_deck', 'L', 'string', 'seat_ai', true); Settings::flush();
    check('rear: L12 before L1, lower before upper', $order(['L1', 'U12', 'L12', 'U1', 'L6']) === ['L12', 'L6', 'L1', 'U12', 'U1'], implode(',', $order(['L1', 'U12', 'L12', 'U1', 'L6'])));
    check('rear seater: row 10 before row 1', $order(['1A', '10B', '5C']) === ['10B', '5C', '1A']);
    Settings::set('seat_ai_row_order', 'front', 'string', 'seat_ai', true); Settings::flush();
    check('front: the old walk L1, L6, L12', $order(['L12', 'L1', 'L6']) === ['L1', 'L6', 'L12']);
    Settings::set('seat_ai_default_deck', 'U', 'string', 'seat_ai', true); Settings::flush();
    check('upper first when set', $order(['L1', 'U2']) === ['U2', 'L1']);
} finally {
    Settings::set('seat_ai_row_order', $prevOrder, 'string', 'seat_ai', true);
    Settings::set('seat_ai_default_deck', $prevDeck, 'string', 'seat_ai', true);
    Settings::flush();
}

echo "-- 3. Gender re-checked at payment confirmation --\n";
$w = bookingWindow(); $D = addDaysISO($w['from'], 27);
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
$adminId = (int) Database::scalar("SELECT id FROM admins WHERE role='superadmin' ORDER BY id LIMIT 1", [], 0);
$cleanup = function () use ($D): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . SV_PHONE . "%'") as $r) {
        try { Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => $D]);
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
        try { Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => (int) $s['id']]); } catch (Throwable $e) {}
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => $D]);
};
if ($route === null || $adminId === 0) {
    echo "  SKIP  no active sleeper route / superadmin\n";
} else {
    $cleanup();
    unset($_SESSION[ADMIN_SESSION_KEY]);
    Database::run('UPDATE coupons SET auto_apply = 7 WHERE auto_apply = 1');
    try {
        $rid = (int) $route['id'];
        $sched = Seats::schedule($rid, $D);
        $sid = (int) $sched['id'];
        $av = Seats::availabilityForSchedule($sid, 'sharing');
        $fem = array_map('strval', $av['female'] ?? []);
        $free = array_map('strval', $av['available'] ?? []);
        $pair = null;
        foreach ($free as $s) {
            $unit = Seats::unitSeats(Seats::unitKey($s), 'sleeper');
            if (count($unit) === 2 && !array_intersect($unit, $fem) && !array_diff($unit, $free)) { $pair = array_values($unit); break; }
        }
        if ($pair === null) {
            echo "  SKIP  no free two-berth cabin without women-only berths\n";
        } else {
            $req = static fn(string $seat, string $g, string $sfx): array => [
                'routeId' => $rid, 'travelDate' => $D, 'seats' => [$seat],
                'passengers' => [['seat' => $seat, 'name' => 'Smart Pax ' . $sfx, 'age' => 30, 'gender' => $g]],
                'contact' => ['phone' => SV_PHONE . $sfx], 'bookingMode' => 'sharing',
                'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '', 'referralCode' => '',
            ];
            $w1 = BookingService::create($req($pair[0], 'Female', '01'));
            check('woman books ' . $pair[0] . ' (payment pending)', ($w1['status'] ?? '') === 'pending', (string) ($w1['status'] ?? ''));
            BookingService::reject((int) $w1['id'], $adminId, 'test: proof unclear');
            check('rejected → her berth released', !Database::exists('SELECT 1 FROM booking_seats WHERE booking_id = :b', ['b' => (int) $w1['id']]));
            $m1 = BookingService::create($req($pair[1], 'Male', '02'));
            check('a man then books the other berth ' . $pair[1], !empty($m1['pnr']));
            $refused = '';
            try { BookingService::confirm((int) $w1['id'], $adminId, 'test re-approve'); }
            catch (Throwable $e) { $refused = $e->getMessage(); }
            check('re-approving her payment is refused (would seat her beside a man)', $refused !== '' && stripos($refused, 'male') !== false, $refused);
            check('her booking stays rejected, seat not re-claimed', (string) Database::scalar('SELECT status FROM bookings WHERE id = :b', ['b' => (int) $w1['id']], '') === 'rejected'
                && !Database::exists('SELECT 1 FROM booking_seats WHERE booking_id = :b', ['b' => (int) $w1['id']]));
            // the ordinary case still works: once he cancels, her approval goes through
            BookingService::reject((int) $m1['id'], $adminId, 'test: free the cabin');
            $ok = BookingService::confirm((int) $w1['id'], $adminId, 'test re-approve');
            check('with the cabin free again her approval re-claims the berth', (string) ($ok['status'] ?? '') === 'confirmed'
                && Database::exists('SELECT 1 FROM booking_seats WHERE booking_id = :b', ['b' => (int) $w1['id']]));
        }
    } catch (Throwable $e) {
        check('gender reclaim scenario ran', false, $e->getMessage());
    } finally {
        Database::run('UPDATE coupons SET auto_apply = 1 WHERE auto_apply = 7');
        $cleanup();
    }
}

echo "-- 4. Ticket pickup map --\n";
$g = Ticket::pickupGeo(['boarding_stop' => 'Surat · Departure @ 13:00 [21.1702,72.8311]', 'route_id_for_stop' => 0]);
check('coordinates read from the booking label', $g !== null && abs($g['lat'] - 21.1702) < 1e-6 && abs($g['lng'] - 72.8311) < 1e-6);
check('maps link is a directions link to the pickup', $g !== null && Ticket::pickupMapsUrl($g) === 'https://www.google.com/maps/dir/?api=1&destination=21.1702,72.8311');
check('no coordinates → no map (never guessed)', Ticket::pickupGeo(['boarding_stop' => 'Nowhere Town @ 10:00', 'route_id_for_stop' => 0]) === null);
check('[0,0] is not a place', Ticket::pickupGeo(['boarding_stop' => 'X [0,0]', 'route_id_for_stop' => 0]) === null);
$tsrc = (string) file_get_contents($root . '/includes/ticket.php');
check('the PNG draws the PICKUP MAP QR only without the pay QR', str_contains($tsrc, "\$mapsQr = \$payUpi === '' && \$geo !== null && Settings::getBool('ticket_maps_qr', true)"));
check('issued tickets re-render once (layout stamp moved)', str_contains($tsrc, "PNG_LAYOUT_CHANGED = '2026-10-08 12:00:00'"));

echo "-- 5. WhatsApp template library --\n";
$cats = array_count_values(array_column(WaTplLib::BUILTIN, 'cat'));
check('at least 30 drafts', count(WaTplLib::BUILTIN) >= 30, (string) count(WaTplLib::BUILTIN));
check('customer 12 · marketing 9 · agent 6 · staff 5', ($cats['customer'] ?? 0) >= 12 && ($cats['marketing'] ?? 0) >= 9 && ($cats['agent'] ?? 0) >= 6 && ($cats['staff'] ?? 0) >= 5, json_encode($cats));
$badVars = []; $langGap = [];
foreach (WaTplLib::BUILTIN as $k => $t) {
    foreach (['en', 'ne', 'hi'] as $l) {
        if (trim($t[$l] ?? '') === '') { $langGap[] = "$k/$l"; continue; }
        foreach (WaTplLib::unknownVars($t[$l]) as $v) { $badVars[] = "$k/$l:$v"; }
        $a = WaTplLib::varsIn($t['en']); $b = WaTplLib::varsIn($t[$l]); sort($a); sort($b);
        if ($a !== $b) { $badVars[] = "$k/$l: variables differ from English"; }
    }
}
check('every draft in English, Nepali and Hindi', $langGap === [], implode(' ', $langGap));
check('every variable is a known one, same set in each language', $badVars === [], implode(' | ', array_slice($badVars, 0, 5)));
check('marketing drafts are filed as MARKETING', count(array_filter(WaTplLib::BUILTIN, static fn($t) => $t['cat'] === 'marketing' && $t['meta'] !== 'MARKETING')) === 0);

$pnr = (string) Database::scalar("SELECT b.pnr FROM bookings b JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type='outbound' WHERE b.status='confirmed' ORDER BY b.id DESC LIMIT 1", [], '');
if ($pnr === '') {
    echo "  SKIP  no confirmed booking in the test database to fill a template from
";
} else {
    $res = WaTplLib::resolve(WaTplLib::body('booking_confirmation', 'en'), ['pnr' => $pnr]);
    check('a real PNR fills the booking confirmation', str_contains($res['text'], $pnr) && !in_array('booking_id', $res['missing'], true), implode(',', $res['missing']));
}
$f = Fare::dirFares();
$res2 = WaTplLib::resolve('Fare {{fare_to_nepal}} / {{fare_to_india}}');
check('fares come from the live settings', $res2['text'] === 'Fare ' . inr((float) $f['toNepal']) . ' / ' . inr((float) $f['toIndia']), $res2['text']);
$res3 = WaTplLib::resolve('Hi {{customer_name}}, seat {{seat_number}}');
check('no PNR → booking variables stay visible as missing', $res3['missing'] === ['customer_name', 'seat_number'] && str_contains($res3['text'], '{{customer_name}}'));
$res4 = WaTplLib::resolve('Hi {{customer_name}}', ['pnr' => 'SHG-NOPE-0000']);
check('an unknown PNR answers nothing', $res4['missing'] === ['customer_name']);
$res5 = WaTplLib::resolve('{{agent_name}} {{commission_amount}}', ['agent_id' => 999999]);
check('an unknown agent answers nothing', count($res5['missing']) === 2);
$m = WaTplLib::forMeta('Hi {{customer_name}}, PNR {{booking_id}}. Bye {{customer_name}}');
check('Meta numbering reuses a number for a repeated variable', $m['body'] === 'Hi {{1}}, PNR {{2}}. Bye {{1}}' && $m['map'] === ['customer_name', 'booking_id']);
check('unknown placeholder is caught', WaTplLib::unknownVars('Hi {{custmer_name}}') === ['custmer_name']);

$src = WaTplLib::BUILTIN['payment_pending']['en'];
check('AI guard: a faithful rewording passes', WaTplLib::checkSuggestion($src, "Hello {{customer_name}}! Booking {{booking_id}} still needs {{amount_due}}. Please pay to keep seat {{seat_number}}. Call {{support_phone}}") === '');
check('AI guard: an invented price is refused', str_contains(WaTplLib::checkSuggestion($src, "Hello {{customer_name}}, pay 1999 for {{booking_id}} {{amount_due}} seat {{seat_number}} {{support_phone}}"), 'number'));
check('AI guard: a dropped variable is refused', str_contains(WaTplLib::checkSuggestion($src, "Hello {{customer_name}}, please pay for {{booking_id}}."), 'variables'));
check('AI guard: a new discount promise is refused', str_contains(WaTplLib::checkSuggestion($src, "{{customer_name}} get 10% off! {{booking_id}} {{amount_due}} {{seat_number}} {{support_phone}}"), 'number')
    || str_contains(WaTplLib::checkSuggestion($src, "{{customer_name}} get a discount! {{booking_id}} {{amount_due}} {{seat_number}} {{support_phone}}"), 'promises'));
check('AI guard: a free promise is refused', str_contains(WaTplLib::checkSuggestion($src, "{{customer_name}} free meal! {{booking_id}} {{amount_due}} {{seat_number}} {{support_phone}}"), 'promises'));
check('AI guard: an added link is refused', str_contains(WaTplLib::checkSuggestion($src, "{{customer_name}} pay at https://x.io {{booking_id}} {{amount_due}} {{seat_number}} {{support_phone}}"), 'link'));

// save / reset round trip
$prev = Database::fetch("SELECT * FROM wa_tpl_library WHERE tkey = 'welcome' AND lang = 'en'");
try {
    WaTplLib::save('welcome', 'en', 'Namaste {{customer_name}} — test copy', 'shg_welcome', 'submitted', $adminId);
    check('an edit is stored with its Meta status', (string) Database::scalar("SELECT meta_status FROM wa_tpl_library WHERE tkey='welcome' AND lang='en'", [], '') === 'submitted');
    $threw = false;
    try { WaTplLib::save('welcome', 'en', 'Hi {{nope}}', '', 'draft', $adminId); } catch (InvalidArgumentException $e) { $threw = true; }
    check('an unknown variable cannot be saved', $threw);
    WaTplLib::reset('welcome', 'en');
    check('reset returns to the built-in draft', !Database::exists("SELECT 1 FROM wa_tpl_library WHERE tkey='welcome' AND lang='en'"));
} finally {
    if ($prev !== null) {
        Database::run('INSERT INTO wa_tpl_library (tkey, lang, body, meta_name, meta_status, updated_by) VALUES (:k,:l,:b,:n,:s,:u) ON DUPLICATE KEY UPDATE body=VALUES(body)',
            ['k' => 'welcome', 'l' => 'en', 'b' => $prev['body'], 'n' => $prev['meta_name'], 's' => $prev['meta_status'], 'u' => $prev['updated_by']]);
    }
}

echo "-- 6. Campaign consent --\n";
$prevReq = Settings::getBool('mk_require_optin', false);
try {
    Settings::set('mk_require_optin', '0', 'bool', 'marketing'); Settings::flush();
    check('opt-in not required → optedIn() is null (old rule)', CustomerDirectory::optedIn() === null);
    Settings::set('mk_require_optin', '1', 'bool', 'marketing'); Settings::flush();
    CustomerDirectory::optIn('+91 ' . SV_PHONE . '9', 'test', $adminId);
    $in = CustomerDirectory::optedIn();
    check('opt-in required → recorded number is in the set', is_array($in) && isset($in[substr(SV_PHONE . '9', -10)]));
    check('a bad number is refused', CustomerDirectory::optIn('12345', 'test', $adminId) === false);
    $csrc = (string) file_get_contents($root . '/includes/campaigns.php');
    check('the sender skips a number that did not opt in', str_contains($csrc, '($in !== null && !isset($in[substr((string) $r[\'phone\'], -10)]))'));
} finally {
    Database::run('DELETE FROM mk_optin WHERE phone = :p', ['p' => substr(SV_PHONE . '9', -10)]);
    Settings::set('mk_require_optin', $prevReq ? '1' : '0', 'bool', 'marketing');
    Settings::flush();
}

echo "-- 7. Seat page wiring --\n";
$js  = (string) file_get_contents($root . '/assets/js/06-results.js');
$tpl = (string) file_get_contents($root . '/app.template.html');
$i18 = (string) file_get_contents($root . '/assets/js/04-i18n.js');
check('one settings reader for Seat AI', str_contains($js, 'function seatAiCfg()'));
check('row score follows the setting', str_contains($js, '(cfg.rear ? (s.rn - 1 - s.ri) : s.ri) * 1.5'));
check('Seat AI OFF hides the bar and needs a hand pick', str_contains($js, "aiBar.classList.toggle('hide', !seatAiCfg().on)") && str_contains($js, "if (!Flow.seats.length && !seatAiCfg().on) { toast(t('tPickSeat')); return; }"));
check('Recommended ring is a mark, cleared once a seat is picked', str_contains($js, 'function seatAiRecommendPaint') && str_contains($js, "if (!seatAiCfg().on || !Flow.route || Flow.seats.length) return;"));
check('legend shows Recommended', str_contains($tpl, 'data-i18n="lgRec"'));
check('near-me button on the seat page', str_contains($tpl, 'id="bpNearMe"') && str_contains($js, 'function nearMeWire(sel)'));
check('near-me never stores the position (no fetch / storage in it)', (static function () use ($js): bool {
    $a = strpos($js, 'function nearMeWire(sel)'); $b = strpos($js, 'function otherStopText', $a);
    $body = substr($js, (int) $a, (int) $b - (int) $a);
    return $a !== false && !str_contains($body, 'fetch(') && !str_contains($body, 'localStorage') && !str_contains($body, 'watchPosition');
})());
foreach (['lgRec', 'nearMeBtn', 'nearMeFound', 'nearMeNone', 'nearMeDenied', 'nearMeMaps'] as $k) {
    check("i18n key $k in en / hi / ne", substr_count($i18, $k . ':') >= 3, (string) substr_count($i18, $k . ':'));
}
check('manifest: call + open pickup in Maps', str_contains((string) file_get_contents($root . '/admin/manifest.php'), 'href="tel:') && str_contains((string) file_get_contents($root . '/admin/manifest.php'), 'mf-map'));
check('sidebar lists WhatsApp Templates', str_contains((string) file_get_contents($root . '/admin/_guard.php'), "'wa-templates.php'"));
check('run-all lists this suite', str_contains((string) file_get_contents(__DIR__ . '/run-all.php'), 'smart-v2-test.php'));

echo "\n----------------------------------------\n";
echo "$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
