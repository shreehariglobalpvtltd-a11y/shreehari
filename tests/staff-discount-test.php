<?php
/**
 * =====================================================================
 *  staff-discount-test.php — 15 Nov 2026 (owner: "staff agent haru le
 *  ticket katda percentage ya amount ko hisab le discount add garna milne").
 *
 *  1. The WhatsApp desk reads a discount from the order line ("chhut 10%",
 *     "discount 200", "10% off", "रु 150 छुट") without mistaking its number
 *     for seats, a date or a phone.
 *  2. A real sale through QuickTicket::sell (the path the WhatsApp desk, the
 *     Quick Ticket page and the assistant use):
 *       - office / counter: % or ₹, capped at counter_max_discount_pct, stored
 *         on the ticket (coupon_discount) and the total drops by it;
 *       - agent: Cash only; capped at the commission the sale earns and taken
 *         from it (agent_ledger desk-discount row), as on the SmartBook desk.
 *
 *  TEST database only. Two throw-away sellers, far-future dates and a
 *  phone prefix of its own; everything it makes is removed.
 *
 *    php tests/staff-discount-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/agentpro-wa.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
if (!str_contains((string) DB_NAME, 'test')) { exit("Refusing to run: DB_NAME is not a test database.\n"); }

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what, string $extra = '') use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what" . ($extra !== '' ? " — $extra" : '') . "\n"; }
};

echo "\n1. Reading the discount from a WhatsApp order line\n";
$cases = [
    ['chhut 10%', ['percent', 10.0]],
    ['discount 200', ['flat', 200.0]],
    ['10% chhut', ['percent', 10.0]],
    ['200 off', ['flat', 200.0]],
    ['रु 150 छुट', ['flat', 150.0]],
    ['छुट 5 प्रतिशत', ['percent', 5.0]],
    ['disc: 7.5%', ['percent', 7.5]],
    ['chhut 500 percent', ['percent', 100.0]],
];
foreach ($cases as [$line, $want]) {
    $t = $line;
    $got = ApWa::discountParse($t);
    $ok($got === $want, '"' . $line . '" → ' . json_encode($want), json_encode($got));
}
foreach (['Ram 9800000000 bholi Surat', 'office ko 1 seat bholi', '2 jana 15 tarik Baroda', 'offer cha?'] as $line) {
    $t = $line;
    $ok(ApWa::discountParse($t) === null && $t === $line, '"' . $line . '": no discount, line untouched');
}
$sale = ApWa::saleParse('Ram Thapa 9800012345 2 jana bholi Surat chhut 10%');
$ok($sale !== null && $sale['discType'] === 'percent' && (float) $sale['discValue'] === 10.0, 'a full order line keeps its discount', json_encode($sale));
$ok($sale !== null && (int) $sale['seats'] === 2 && str_ends_with((string) $sale['phone'], '9800012345'), 'and its seats (2, not 10) and phone are read as before', json_encode($sale));
$plain = ApWa::saleParse('Ram Thapa 9800012345 2 jana bholi Surat');
$ok($plain !== null && $plain['discType'] === '' && (float) $plain['discValue'] === 0.0, 'an order without a discount has none');

echo "\n2. A real sale (QuickTicket::sell)\n";
const SD_PHONE = '98775';
/* nothing in this suite may reach a real phone: notifications off, the WhatsApp
   driver on click-to-chat (journal only), AiSensy off - put back afterwards */
$PINNED = ['whatsapp_notify_customer', 'whatsapp_notify_admin', 'whatsapp_driver', 'aisensy_mode', 'sms_enabled', 'daily_service_on', 'allow_cod'];
$prior = [];
foreach ($PINNED as $k) { $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]); }
Settings::set('whatsapp_notify_customer', false, 'bool', 'notify', false);
Settings::set('whatsapp_notify_admin', false, 'bool', 'notify', false);
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);
Settings::set('aisensy_mode', 'off', 'string', 'whatsapp', false);
Settings::set('sms_enabled', false, 'bool', 'notify', false);
Settings::set('daily_service_on', true, 'bool', 'booking', true);
Settings::set('allow_cod', true, 'bool', 'payment', true);
Settings::flush();
$restoreSettings = static function () use ($PINNED, $prior): void {
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        if ($row === null) {
            try { Database::delete('settings', 'skey = :k', ['k' => $k]); } catch (Throwable $e) {}
        } else {
            try { Database::update('settings', ['svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'], 'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public']], 'skey = :k', ['k' => $k]); } catch (Throwable $e) {}
        }
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};
$route = null;
foreach (QuickTicket::routes() as $r) {
    if ((string) $r['direction'] === 'toNepal' && (string) ($r['coach_type'] ?? 'sleeper') === 'sleeper') { $route = $r; break; }
}
if ($route === null) {
    echo "  SKIP  no active sleeper route towards Nepal in the test database\n";
} else {
    $dates = [addDaysISO(todayISO(), 55), addDaysISO(todayISO(), 56), addDaysISO(todayISO(), 57)];
    $mk = static function (string $user, string $role): int {
        $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $user], 0);
        if ($id === 0) {
            $id = (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
                'full_name' => 'Discount test ' . $role, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
        } else {
            Database::update('admins', ['role' => $role, 'is_active' => 1], 'id = :i', ['i' => $id]);
        }
        return $id;
    };
    $ctrId = $mk('sd-counter', 'counter');
    $agtId = $mk('sd-agent', 'agent');
    $hadProfile = Database::fetch('SELECT admin_id, commission_percent FROM admin_profiles WHERE admin_id = :a', ['a' => $agtId]);
    if ($hadProfile === null) {
        Database::insert('admin_profiles', ['admin_id' => $agtId, 'commission_percent' => 10]);
    } else {
        Database::update('admin_profiles', ['commission_percent' => 10], 'admin_id = :a', ['a' => $agtId]);
    }
    $cleanup = static function () use ($dates): void {
        foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . SD_PHONE . "%'") as $r) {
            $bid = (int) $r['id'];
            foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications'] as $t) {
                try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
            }
            Database::delete('bookings', 'id = :i', ['i' => $bid]);
            @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
            @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
            @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf');
        }
        foreach ($dates as $d) {
            foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $d]) as $s) {
                $sid = (int) $s['id'];
                try { Database::delete('seat_locks', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
                try { Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
                try { Database::delete('booking_seats', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
            }
            try { Database::delete('booking_legs', 'travel_date = :d', ['d' => $d]); } catch (Throwable $e) {}
            try { Database::delete('schedules', 'travel_date = :d', ['d' => $d]); } catch (Throwable $e) {}
        }
        try { Database::pdo()->exec('DELETE FROM rate_limits'); } catch (Throwable $e) {}
    };
    $asStaff = static function (int $id, string $user, string $role): array {
        $_SESSION[ADMIN_SESSION_KEY] = ['id' => $id, 'username' => $user, 'role' => $role, 'permissions' => [], 'last_seen' => time(), 'logged_in_at' => time()];
        return ['id' => $id, 'username' => $user, 'role' => $role, 'full_name' => 'Discount test ' . $role];
    };
    $cap = max(0.0, Settings::getFloat('counter_max_discount_pct', 15.0));
    $cleanup();
    try {
        $sell = static function (array $staff, string $phoneTail, string $date, string $type, float $val, string $pay = 'cash'): array {
            return QuickTicket::sell(['name' => 'Disc Test', 'phone' => SD_PHONE . $phoneTail, 'date' => $date, 'direction' => 'toNepal',
                'pay' => $pay, 'discountType' => $type, 'discountValue' => $val, 'agentCode' => 'Other'], $staff);
        };
        $row = static fn(string $pnr): array => Database::fetch('SELECT id, total_amount, coupon_discount, group_discount, tier_discount FROM bookings WHERE pnr = :p', ['p' => $pnr]) ?? [];

        // a reference sale with no discount, to know the full price on this bus
        $ctr = $asStaff($ctrId, 'sd-counter', 'counter');
        $ref = $sell($ctr, '00001', $dates[0], '', 0);
        $full = (float) ($row((string) $ref['pnr'])['total_amount'] ?? 0);
        $ok($full > 0, 'a counter sale with no discount: full fare ' . inr($full));

        $r10 = $sell($ctr, '00002', $dates[0], 'percent', 10);
        $b10 = $row((string) $r10['pnr']);
        $want10 = round(min($full * 10 / 100, $full * $cap / 100), 2);
        $ok(abs((float) $b10['coupon_discount'] - $want10) < 0.02 && abs((float) $b10['total_amount'] - ($full - $want10)) < 0.02,
            'counter 10%: ' . inr($want10) . ' off, stored on the ticket', json_encode($b10));

        $r200 = $sell($ctr, '00003', $dates[0], 'flat', 200);
        $b200 = $row((string) $r200['pnr']);
        $want200 = round(min(200, $full * $cap / 100), 2);
        $ok(abs((float) $b200['coupon_discount'] - $want200) < 0.02, 'counter ₹200: ' . inr($want200) . ' off', json_encode($b200));

        $rBig = $sell($ctr, '00004', $dates[0], 'percent', 90);
        $bBig = $row((string) $rBig['pnr']);
        $ok(abs((float) $bBig['coupon_discount'] - round($full * $cap / 100, 2)) < 0.02, 'counter 90% is held to the ' . $cap . '% cap', json_encode($bBig));
        $ok((float) Database::scalar("SELECT COUNT(*) FROM agent_ledger WHERE booking_id = :b AND ref LIKE 'DISCOUNT %'", ['b' => (int) $b10['id']], 0) === 0.0,
            'a counter discount takes nothing from any agent');

        // the WhatsApp quote shows the same discount the sale then charges
        $prev = new ReflectionMethod('ApWa', 'discountPreview');
        $prev->setAccessible(true);
        $plan = QuickTicket::plan(['seats' => 1, 'date' => $dates[0], 'direction' => 'toNepal']);
        $pv = $prev->invoke(null, ['discType' => 'percent', 'discValue' => 10.0, 'pay' => 'cash'], $plan, ['id' => $ctrId, 'role' => 'counter']);
        $ok($pv !== null && abs($pv['cut'] - $want10) < 0.02 && abs($pv['total'] - ($full - $want10)) < 0.02,
            'the WhatsApp quote for "chhut 10%" shows ' . inr($want10) . ' off — what the sale charged', json_encode($pv));
        $pvA = $prev->invoke(null, ['discType' => 'flat', 'discValue' => 100000.0, 'pay' => 'cash'], $plan, ['id' => $agtId, 'role' => 'agent']);
        $ok($pvA !== null && $pvA['cut'] <= AgentWallet::commissionForBooking((float) $plan['fare']['total'], $agtId, 1) + 0.02 && $pvA['capped'],
            'an agent\'s quote shows the commission limit', json_encode($pvA));
        $pvU = $prev->invoke(null, ['discType' => 'percent', 'discValue' => 5.0, 'pay' => 'upi'], $plan, ['id' => $agtId, 'role' => 'agent']);
        $ok($pvU !== null && $pvU['cashOnly'] === true, 'an agent\'s UPI quote says "Cash only" before any yes');

        // the agent: Cash only, capped at and taken from their commission
        $agt = $asStaff($agtId, 'sd-agent', 'agent');
        $threw = '';
        try { $sell($agt, '00005', $dates[1], 'percent', 5, 'upi'); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
        $ok(str_contains($threw, 'Cash'), 'agent + UPI + discount: refused, "Cash only"', $threw);
        $ok((int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone = :p", ['p' => SD_PHONE . '00005'], 0) === 0, 'and no ticket was made');

        $ra = $sell($agt, '00006', $dates[1], 'flat', 100000);
        $ba = $row((string) $ra['pnr']);
        $commission = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM agent_ledger WHERE booking_id = :b AND account = 'commission' AND ref NOT LIKE 'DISCOUNT %'", ['b' => (int) $ba['id']], 0);
        $charge = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM agent_ledger WHERE booking_id = :b AND entry_type = 'adjustment' AND ref LIKE 'DISCOUNT %'", ['b' => (int) $ba['id']], 0);
        $earned = AgentWallet::commissionForBooking($full, $agtId, 1);
        $ok((float) $ba['coupon_discount'] > 0 && (float) $ba['coupon_discount'] <= round($earned, 2) + 0.02,
            'agent asks ₹1,00,000: given at most the commission this sale earns (' . inr($earned) . ')', json_encode($ba) . ' commission ' . $commission);
        $ok(abs(abs($charge) - (float) $ba['coupon_discount']) < 0.02,
            'and exactly that much is taken from the agent\'s commission (ledger)', 'charge ' . $charge . ' discount ' . $ba['coupon_discount']);

        $rn = $sell($agt, '00007', $dates[2], '', 0);
        $bn = $row((string) $rn['pnr']);
        $ok((float) $bn['coupon_discount'] === 0.0 && (float) Database::scalar("SELECT COUNT(*) FROM agent_ledger WHERE booking_id = :b AND entry_type = 'adjustment' AND ref LIKE 'DISCOUNT %'", ['b' => (int) $bn['id']], 0) === 0.0,
            'an agent sale without a discount: nothing taken');
    } catch (Throwable $e) {
        $ok(false, 'sale run finished', get_class($e) . ': ' . $e->getMessage());
    } finally {
        unset($_SESSION[ADMIN_SESSION_KEY]);
        $cleanup();
        foreach (['wa_outbox' => 'to_number', 'message_logs' => 'to_number'] as $t => $c) {
            try { Database::run("DELETE FROM {$t} WHERE {$c} LIKE :p", ['p' => '%' . SD_PHONE . '%']); } catch (Throwable $e) {}
        }
        if ($hadProfile === null) {
            Database::delete('admin_profiles', 'admin_id = :a', ['a' => $agtId]);
        } else {
            Database::update('admin_profiles', ['commission_percent' => $hadProfile['commission_percent']], 'admin_id = :a', ['a' => $agtId]);
        }
    }
}

$restoreSettings();
echo "\n" . ($fail === 0 ? "ALL $pass PASSED" : "$fail FAILED, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
