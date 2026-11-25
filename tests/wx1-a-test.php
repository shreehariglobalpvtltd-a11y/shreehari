<?php
/* wx1-a (25 Nov 2026): SmartBook - direction by the browser's timezone + ⇄ swap,
   ₹ INR | रू NPR choice (customer NPR frozen on the sale), BS + AD date chips.
   php -c .claude/php-dev.ini tests/wx1-a-test.php */
define('SHG_APP', true);
require __DIR__ . '/../includes/bootstrap.php';
require_once INCLUDE_PATH . '/smartbook.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/ticket.php';
$ok = 0; $bad = 0;
$chk = function ($name, $cond) use (&$ok, &$bad) { if ($cond) { $ok++; echo "  PASS  $name\n"; } else { $bad++; echo "  FAIL  $name\n"; } };
$root = dirname(__DIR__);
$src = static fn(string $f): string => (string) file_get_contents($root . '/' . $f);

/* keep the two switches (and the office default rules) as they were: the whole row is put
   back (value, type, group, label, public), because the test deletes the rows while it runs */
$keys = ['geo_direction_on', 'customer_currency_choice_on', 'default_rules'];
$saved = [];
foreach ($keys as $k) { $saved[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]); }
$restore = function () use ($keys, $saved) {
    foreach ($keys as $k) {
        Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        if ($saved[$k] !== null) { Database::insert('settings', $saved[$k]); }
    }
    Settings::flush();
};
foreach ($keys as $k) { Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]); }
Settings::flush();

try {
    /* 1. timezone -> direction */
    $chk('nepalTz Kathmandu', SmartBook::nepalTz('Asia/Kathmandu'));
    $chk('nepalTz old spelling', SmartBook::nepalTz('Asia/Katmandu'));
    $chk('nepalTz India = no', !SmartBook::nepalTz('Asia/Kolkata') && !SmartBook::nepalTz(''));
    $hasBack = in_array('toIndia', array_column(SmartBook::directions(), 'direction'), true);
    $np = SmartBook::defaults(['tz' => 'Asia/Kathmandu'], 'customer');
    if ($hasBack) {
        $chk('Nepal timezone opens Nepal -> India', $np['direction'] === 'toIndia');
        $chk('why = opened from Nepal', strpos((string) ($np['why']['direction']['ne'] ?? ''), 'नेपालबाट खोलिएको') === 0);
    } else {
        $chk('no return route: falls back', in_array($np['direction'], ['toNepal', 'toIndia'], true) && !isset($np['why']['direction']));
    }
    $ex = SmartBook::defaults(['tz' => 'Asia/Kathmandu', 'direction' => 'toNepal'], 'customer');
    $chk('explicit / saved direction wins over timezone', $ex['direction'] === 'toNepal');
    $in = SmartBook::defaults(['tz' => 'Asia/Kolkata'], 'customer');
    $plain = SmartBook::defaults([], 'customer');
    $chk('India timezone = unchanged (most used)', $in['direction'] === $plain['direction'] && !isset($in['why']['direction']));
    $st = SmartBook::defaults(['tz' => 'Asia/Kathmandu'], 'admin');
    $chk('staff desk ignores the timezone', ($st['why']['direction']['ne'] ?? '') === '' || strpos((string) $st['why']['direction']['ne'], 'नेपालबाट खोलिएको') !== 0);
    Settings::set('geo_direction_on', '0', 'bool', 'booking', true); Settings::flush();
    $off = SmartBook::defaults(['tz' => 'Asia/Kathmandu'], 'customer');
    $chk('geo_direction_on = 0: timezone ignored', $off['direction'] === $plain['direction'] && !isset($off['why']['direction']));
    Database::run('DELETE FROM settings WHERE skey = :k', ['k' => 'geo_direction_on']); Settings::flush();
    /* an office "fixed" direction (Admin -> Default Rules) beats the timezone */
    Settings::set('default_rules', ['fixed' => ['direction' => 'toNepal']], 'json', 'booking', false); Settings::flush();
    $fx1 = SmartBook::defaults(['tz' => 'Asia/Kathmandu'], 'customer');
    $chk('office fixed direction wins over the timezone', $fx1['direction'] === 'toNepal'
        && strpos((string) ($fx1['why']['direction']['ne'] ?? ''), 'नेपालबाट खोलिएको') !== 0);
    Database::run('DELETE FROM settings WHERE skey = :k', ['k' => 'default_rules']); Settings::flush();

    /* 2. NPR | INR */
    $chk('customer NPR', SmartBook::payCurrency(['cur' => 'NPR'], 'customer', null) === 'NPR');
    $chk('lower case npr', SmartBook::payCurrency(['cur' => 'npr'], 'customer', null) === 'NPR');
    $chk('INR / nothing = empty', SmartBook::payCurrency(['cur' => 'INR'], 'customer', null) === '' && SmartBook::payCurrency([], 'customer', null) === '');
    $chk('a desk never sends it', SmartBook::payCurrency(['cur' => 'NPR'], 'agent', null) === '' && SmartBook::payCurrency(['cur' => 'NPR'], 'customer', ['adminId' => 1]) === '');
    $m = new ReflectionMethod('BookingService', 'customerFxStamp');
    $m->setAccessible(true);
    $rate = Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6);
    $rate = $rate > 0 ? round($rate, 4) : 1.6;
    if (CounterDesk::frozenColumns()['bookings']) {
        $fx = $m->invoke(null, ['payCurrency' => 'NPR'], null, 2200.0);
        $chk('NPR frozen: currency', ($fx['fx_currency'] ?? '') === 'NPR');
        $chk('NPR frozen: rate = npr_per_inr', abs((float) ($fx['fx_rate'] ?? 0) - $rate) < 0.00001);
        $chk('NPR frozen: total rounded', (float) ($fx['fx_total'] ?? 0) === (float) round(2200 * $rate));
        $b = ['total_amount' => 2200] + $fx;
        $p = Ticket::moneyParts($b, 2200.0);
        $chk('ticket prints NPR big (ticket_npr_primary)', !Settings::getBool('ticket_npr_primary', true) || ($p['isNpr'] && strpos($p['main'], 'NPR ') === 0 && strpos($p['sub'], 'INR') !== false));
    } else {
        $chk('no fx columns: nothing frozen', $m->invoke(null, ['payCurrency' => 'NPR'], null, 2200.0) === []);
    }
    $chk('INR sale: nothing frozen', $m->invoke(null, ['payCurrency' => ''], null, 2200.0) === []);
    $chk('seller sale: customer branch stays out', $m->invoke(null, ['payCurrency' => 'NPR'], ['adminId' => 1], 2200.0) === []);
    Settings::set('customer_currency_choice_on', '0', 'bool', 'booking', true); Settings::flush();
    $chk('customer_currency_choice_on = 0: no NPR', SmartBook::payCurrency(['cur' => 'NPR'], 'customer', null) === ''
        && $m->invoke(null, ['payCurrency' => 'NPR'], null, 2200.0) === []);
    Database::run('DELETE FROM settings WHERE skey = :k', ['k' => 'customer_currency_choice_on']); Settings::flush();

    /* 2b. end to end: a guest SmartBook Online sale with cur=NPR freezes NPR; without it, nothing */
    if (CounterDesk::frozenColumns()['bookings']) {
        $date = addDaysISO(todayISO(), 44);
        $schedBefore = array_map('intval', array_column(Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $date]), 'id'));
        $PIN = ['whatsapp_notify_customer', 'whatsapp_notify_admin', 'whatsapp_driver', 'aisensy_mode', 'sms_enabled', 'daily_service_on'];
        $prior = [];
        foreach ($PIN as $k) { $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]); }
        Settings::set('whatsapp_notify_customer', false, 'bool', 'notify', false);
        Settings::set('whatsapp_notify_admin', false, 'bool', 'notify', false);
        Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);
        Settings::set('aisensy_mode', 'off', 'string', 'whatsapp', false);
        Settings::set('sms_enabled', false, 'bool', 'notify', false);
        Settings::set('daily_service_on', true, 'bool', 'booking', true);
        Settings::flush();
        $clean = static function () use ($date, $schedBefore): void {
            foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '98771%'") as $r) {
                $bid = (int) $r['id'];
                foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications', 'coupon_redemptions'] as $t) {
                    try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
                }
                try { Database::delete('schedule_unit_locks', 'locked_by_booking = :b', ['b' => $bid]); } catch (Throwable $e) {}
                Database::delete('bookings', 'id = :i', ['i' => $bid]);
                @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
                @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
            }
            try { Database::run("DELETE FROM smartbook_requests WHERE idem_key LIKE 'wx1atest%'"); } catch (Throwable $e) {}
            foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $date]) as $s) {
                $sid = (int) $s['id'];
                if (in_array($sid, $schedBefore, true)) { continue; }
                foreach (['seat_locks', 'schedule_unit_locks', 'booking_seats', 'booking_legs'] as $t) {
                    try { Database::delete($t, 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
                }
                try { Database::delete('schedules', 'id = :s', ['s' => $sid]); } catch (Throwable $e) {}
            }
            foreach (['wa_outbox', 'message_logs'] as $t) {
                try { Database::run("DELETE FROM {$t} WHERE to_number LIKE '%98771%'"); } catch (Throwable $e) {}
            }
            try { Database::run("DELETE FROM user_profiles WHERE phone LIKE '98771%' AND user_id IS NULL"); } catch (Throwable $e) {}
        };
        $clean();
        try {
            unset($_SESSION[ADMIN_SESSION_KEY]);
            $mode = SmartBook::sessionMode();
            $sell = static function (string $tail, string $cur) use ($date, $mode): array {
                $in = ['direction' => 'toIndia', 'date' => $date, 'name' => 'Wx Test ' . $tail, 'phone' => '98771' . $tail, 'country' => 'NP',
                    'pax' => [['gender' => 'Male', 'band' => 'adult']], 'type' => 'sharing'];
                if ($cur !== '') { $in['cur'] = $cur; }
                return SmartBook::book($in, 'online', $mode, null, 'wx1atest' . $tail . bin2hex(random_bytes(6)));
            };
            $fxOf = static fn(string $pnr): array => Database::fetch('SELECT total_amount, fx_currency, fx_rate, fx_total FROM bookings WHERE pnr = :p', ['p' => $pnr]) ?? [];
            $a = $sell('00001', 'NPR');
            $fa = $fxOf((string) ($a['pnr'] ?? ''));
            $chk('guest sale + NPR: fx_currency NPR', ($fa['fx_currency'] ?? '') === 'NPR');
            $chk('guest sale + NPR: fx_total = total x rate', (float) ($fa['fx_total'] ?? 0) === (float) round((float) ($fa['total_amount'] ?? 0) * $rate) && (float) ($fa['fx_total'] ?? 0) > 0);
            $b2 = $sell('00002', '');
            $fb = $fxOf((string) ($b2['pnr'] ?? ''));
            $chk('guest sale, no choice: nothing frozen (INR as before)', ($fb['fx_currency'] ?? null) === null && ($fb['fx_total'] ?? null) === null && $fb !== []);
        } catch (Throwable $e) {
            $chk('end to end ran (' . $e->getMessage() . ')', false);
        } finally {
            $clean();
            foreach ($PIN as $k) {
                $row = $prior[$k];
                if ($row === null) { try { Database::delete('settings', 'skey = :k', ['k' => $k]); } catch (Throwable $e) {} }
                else { try { Database::update('settings', ['svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'], 'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public']], 'skey = :k', ['k' => $k]); } catch (Throwable $e) {} }
            }
            Settings::flush();
        }
        $chk('cleanup: no wx1 rows left', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '98771%'", [], 0) === 0);
    }

    /* 3. the page: wiring that a browser test would otherwise need */
    $js = $src('assets/js/27-smartbook.js');
    $chk('boot sends the timezone', strpos($js, "direction: saved.dir || '', tz: TZ }") !== false);
    $chk('swap reuses the direction tap', strpos($js, "root.querySelector('.sb-dir-b:not(.on)')") !== false);
    $chk('book sends cur', strpos($js, "if (inNpr()) body.cur = 'NPR';") !== false);
    $chk('NPR opens eSewa first', strpos($js, "if (inNpr() && r.pay && r.pay.esewa) PAY.tab = 'esewa';") !== false);
    $chk('chips: BS वि.सं. + AD ई.सं.', strpos($js, "' वि.सं.</span>'") !== false && strpos($js, "' ई.सं.</span></button>'") !== false);
    $chk('quick line: full BS + AD', strpos($js, "bsQ + ' वि.सं. · ' : '') + q.dateLabel + ' ई.सं.'") !== false);
    /* review fixes: NPR on the Cash confirm sheet + the normal fare, desk card unchanged */
    $chk('confirm sheet total in the chosen money', strpos($js, "esc(q.price ? curAmt(Number(q.price.total) + dxS.amt, q) : '—')") !== false
        && strpos($js, "esc(altAmt(Number(q.price.total) + dxS.amt, q))") !== false);
    $chk('Confirm button / pay-at-boarding line use the chosen money', strpos($js, "var tot = q.price ? curAmt(Number(q.price.total) + deskExtras('cash').amt, q) : '';") !== false
        && strpos($js, "var tot = q.price ? curAmt(q.price.total, q) : '';") !== false);
    $chk('normal fare struck through in the chosen money', strpos($js, 'nfHtml(q.price.normal, function (n) { return curAmt(n, q); })') !== false
        && strpos($js, "curAmt(q.price.normal.normal, q)") !== false);
    $chk('desk keeps the old date chip + no Quick-line swap', strpos($js, "if (STAFF) {   // the staff desk keeps its compact one-line chip") !== false
        && strpos($js, "var swapQ = STAFF ? '' :") !== false);
    $adm = $src('admin/smartbook.php');
    $chk('desk card drops the swap and is marked data-staff', strpos($adm, 'class="sb-dir-swap"[^>]*>') !== false && strpos($adm, "'\$0 data-staff=\"1\"'") !== false);
    $tplCard = preg_match('~<section class="sb" id="smartBook"[^>]*>.*?</section>~s', $src('app.template.html'), $mm) === 1 ? $mm[0] : '';
    $deskCard = (string) preg_replace('~<button type="button" class="sb-dir-swap"[^>]*>.*?</button>\s*~s', '', $tplCard, 1);
    $deskCard = (string) preg_replace('~<section class="sb" id="smartBook"~', '$0 data-staff="1"', $deskCard, 1);
    $chk('desk card: no swap, both directions kept, data-staff set', $tplCard !== '' && strpos($deskCard, 'sb-dir-swap') === false
        && substr_count($deskCard, 'class="sb-dir-b') === 2 && strpos($deskCard, '<section class="sb" id="smartBook" data-staff="1"') === 0);
    $chk('3-column direction grid only on the customer card', strpos($src('assets/css/smartbook.css'), '#smartBook:not([data-staff]) .sb-dir{grid-template-columns:minmax(0,1fr) 44px minmax(0,1fr)}') !== false);
    $html = $src('app.template.html');
    $chk('swap button in the card', strpos($html, 'id="sbSwap" data-sbswap="1" aria-label="रुट साट्नुहोस् · Swap route"') !== false);
    $chk('currency chips in the card', strpos($html, 'id="sbCur"') !== false && strpos($html, 'data-cur="NPR"') !== false && strpos($html, 'data-cur="INR"') !== false);
    $chk('template keeps its BOM', substr($html, 0, 3) === "\xEF\xBB\xBF");
    $css = $src('assets/css/smartbook.css');
    $chk('swap is a 44px target', strpos($css, '.sb-dir-swap{width:44px;min-width:44px;min-height:44px') !== false);
    $chk('date chips may wrap (beats premium3d nowrap)', strpos($css, '#smartBook #sbDates .sb-chip-2d .sb-d-bs') !== false && strpos($css, 'white-space:normal') !== false);
    $chk('currency chips 44px', strpos($css, '#smartBook .sb-cur button{min-height:44px') !== false);
    $r = $src('assets/js/05-router.js');
    $chk('classic form: Nepal time -> back, behind the switch', strpos($r, "if (!applyCampaignLink() && !applyLastSearch()) {") !== false && strpos($r, 'st.geo_direction_on') !== false);
    $api = $src('api/smartbook.php');
    $chk('api passes tz + curChoice', strpos($api, "'tz'        => Security::clean") !== false && strpos($api, "'curChoice'") !== false);
    $sql = $src('database/upgrade-2026-11-25-wx1-a.sql');
    $chk('migration rows', strpos($sql, 'INSERT IGNORE INTO settings') !== false && strpos($sql, "'geo_direction_on'") !== false && strpos($sql, "'customer_currency_choice_on'") !== false);
} finally {
    $restore();
}
/* frozen NPR follows a new INR total (a seat cancelled / a fare corrected) at the same rate */
$chk('fxFollow: NPR 3,200 @1.6 -> INR 1,000 = NPR 1,600', BookingService::fxFollow(['fx_currency' => 'NPR', 'fx_rate' => '1.6000', 'fx_total' => '3200'], 1000.0) === ['fx_total' => 1600.0]);
$chk('fxFollow: nothing frozen = no change', BookingService::fxFollow(['fx_currency' => null, 'fx_rate' => null, 'fx_total' => null], 1000.0) === []);
$chk('fxFollow: INR row = no change', BookingService::fxFollow(['fx_currency' => 'INR', 'fx_rate' => '1', 'fx_total' => '2000'], 1000.0) === []);
$chk('fxFollow wired into cancelSeat + fare correction', str_contains($src('includes/booking.php'), 'self::fxFollow($locked, (float) $newTotal)')
    && str_contains($src('includes/ticketedit.php'), 'BookingService::fxFollow($b, (float) $total)'));
echo "\n  wx1-a-test: $ok passed, $bad failed\n";
exit($bad ? 1 : 0);
