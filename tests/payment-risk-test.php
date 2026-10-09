<?php
/**
 * =====================================================================
 *  payment-risk-test.php — anti-fake payment review + live ticket code
 *  (9 Oct 2026, owner ask #15: "ticket fake badi hun layo — approval
 *  system advanced, easy, kam risk banaunu paryo")
 *
 *    php -c .claude/php-dev.ini tests/payment-risk-test.php
 *    SHG_TEST_BASE=http://localhost:8991 php -c … (adds section 9, HTTP)
 *
 *  Pins:
 *    1. every PaymentRisk signal fires on the data that should trigger it
 *       (and BANK_SIGNED lowers the score); COD and seller sales are skipped
 *    2. the approval gate: low approves, medium needs the bank tick, high a
 *       written reason; bulk / WhatsApp never approve above low
 *    3. the two-person rule (pay_two_step_on): the first approval changes
 *       nothing, the SAME person cannot give the second, a counter clerk
 *       cannot either, a manager can
 *    4. every decision keeps a risk snapshot (payment_reviews + audit
 *       'payment.review'); hold sends a message and changes no status
 *    5. NO path rejects, cancels, refunds or expires by itself (behaviour +
 *       source scan of every file the package touched)
 *    6. the one gate: payments.php, booking-view.php and aitools.php all
 *       approve through BookingService::approveReviewed()
 *    7. the live ticket code: 4 digits, stable inside its 30 s window,
 *       rotating across windows, keyed per PNR
 *    8. report-a-fake, scan.php, ticket-live: contracts in the source
 *    9. over HTTP: ticket-live key / no key, bulk approve re-scores per row,
 *       report-fake CSRF / honeypot / 5-per-hour limit, scan audit row
 *
 *  Fixture bookings use mobiles 98111003xx and are deleted at the end.
 *  Settings are overridden in memory only (never written to the shared DB).
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
foreach (['fare', 'seats', 'qr', 'pdf', 'ticket', 'agentwallet', 'booking', 'notify', 'paymentrisk', 'helpdesk', 'adminalerts'] as $f) {
    require_once INCLUDE_PATH . '/' . $f . '.php';
}
restore_error_handler();

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
/** In-memory Settings override (the shared DB is never written). */
function setting_override(string $key, string $value, string $type = 'int'): void {
    Settings::get('company_name');   // make sure the cache is loaded
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c = $rp->getValue();
    $c[$key] = ['value' => $value, 'type' => $type, 'public' => false];
    $rp->setValue(null, $c);
}
function risk_of(int $id): array { return PaymentRisk::score($id) ?? ['codes' => '', 'level' => 'none', 'score' => 0, 'reasons' => []]; }
function has_code(array $r, string $c): bool { return in_array($c, explode(',', (string) $r['codes']), true); }

$ROOT = dirname(__DIR__);
$PHONES = [];
$madeAdmins = [];
$route = Database::fetch("SELECT * FROM routes WHERE route_code = 'r2'") ?? Database::fetch('SELECT * FROM routes ORDER BY id LIMIT 1');
$date  = date('Y-m-d', strtotime('+6 days'));
$seatPool = [];
foreach (['L', 'U'] as $d) { for ($i = 1; $i <= 40; $i++) { $seatPool[] = $d . $i; } }
$seatI = 0;

/** A fresh public booking (pending, UPI) on a fixture mobile. */
function fixture(string $phone, array $o = []): array {
    global $route, $date, $seatPool, $seatI, $PHONES;
    $PHONES[$phone] = true;
    $last = null;
    for ($try = 0; $try < 40; $try++) {
        $seat = $seatPool[$seatI++ % count($seatPool)];
        try {
            $b = BookingService::create([
                'routeId' => (int) $route['id'], 'travelDate' => $date, 'seats' => [$seat],
                'passengers' => [['name' => 'Risk Test ' . substr($phone, -2), 'age' => 33, 'gender' => 'Male']],
                'contact' => ['phone' => $phone], 'bookingMode' => 'sharing',
                'paymentMethod' => $o['method'] ?? 'upi', 'isCod' => !empty($o['cod']), 'boarding' => '',
            ]);
            return Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $b['id']]) ?? $b;
        } catch (Throwable $e) {
            $last = $e;
        }
    }
    throw new RuntimeException('could not create a fixture booking: ' . ($last ? $last->getMessage() : '?'));
}
function proof(array $b, string $utr, array $extra = []): void {
    BookingService::submitPaymentProof((string) $b['pnr'], ['utr' => $utr, 'method' => $extra['method'] ?? 'upi', 'payerName' => 'QA'] + $extra);
}
/** A UPI UTR whose date code is today (YDDDHH + 6). */
function fresh_utr(int $seq): string {
    return substr(date('Y'), -1) . str_pad((string) ((int) date('z') + 1), 3, '0', STR_PAD_LEFT) . date('H') . str_pad((string) (481000 + $seq), 6, '0', STR_PAD_LEFT);
}

PaymentRisk::flush();
$mgr = Database::fetch("SELECT id, role FROM admins WHERE role IN ('manager','superadmin') AND is_active = 1 ORDER BY role = 'manager' DESC, id LIMIT 1");
$alice = ['id' => 900001, 'role' => 'accountant'];
$bob   = ['id' => (int) ($mgr['id'] ?? 1), 'role' => (string) ($mgr['role'] ?? 'superadmin')];
$clerk = ['id' => 900003, 'role' => 'counter'];

try {
    foreach (['alice' => 'accountant', 'clerk' => 'counter'] as $var => $role) {
        $aid = (int) Database::insert('admins', ['username' => 'qa-risk-' . $var . '-' . bin2hex(random_bytes(3)),
            'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT), 'full_name' => 'QA ' . ucfirst($var), 'role' => $role, 'is_active' => 1]);
        $madeAdmins[] = $aid;
        $$var = ['id' => $aid, 'role' => $role];
    }
    echo "\n-- 0. Schema (migration applied) --\n";
    check('payment_reviews table', PaymentRisk::available());
    check('payment_screenshots.phash', PaymentRisk::hasCol('payment_screenshots', 'phash'));
    check('payment_screenshots.edit_tool', PaymentRisk::hasCol('payment_screenshots', 'edit_tool'));
    check('payments.claimed_amount', PaymentRisk::hasCol('payments', 'claimed_amount'));
    $mig = (string) file_get_contents($ROOT . '/database/upgrade-2026-10-09-payment-review.sql');
    check('migration is idempotent (IF NOT EXISTS / INSERT IGNORE everywhere)',
        !preg_match('/CREATE TABLE (?!IF NOT EXISTS)/', $mig) && !preg_match('/ADD COLUMN (?!IF NOT EXISTS)/', $mig)
        && !preg_match('/CREATE INDEX (?!IF NOT EXISTS)/', $mig) && !preg_match('/INSERT INTO/', $mig));
    $noComments = preg_replace("/'[^']*'/", "''", $mig) ?? '';
    preg_match_all("/COMMENT '([^']*)'/", $mig, $cm);
    check("no ';' inside a SQL COMMENT", !array_filter($cm[1], static fn($c) => str_contains($c, ';')));
    check('pay_two_step_on ships OFF, risk_high_value 5000, fraud_scan_whatsapp OFF',
        str_contains($mig, "('pay_two_step_on',          '0'") && str_contains($mig, "('risk_high_value',          '5000'") && str_contains($mig, "('fraud_scan_whatsapp',      '0'"));

    echo "\n-- 1. Signals --\n";
    $b1 = fixture('9811100301');                 // clean, fresh UTR -> low
    proof($b1, fresh_utr(1));
    $r = risk_of((int) $b1['id']);
    check('a clean UPI proof is LOW', $r['level'] === 'low', $r['level'] . ' ' . $r['score'] . ' ' . $r['codes']);

    $b2 = fixture('9811100302');
    $b3 = fixture('9811100303');
    $dup = fresh_utr(2);
    proof($b2, $dup); proof($b3, $dup);
    $r = risk_of((int) $b3['id']);
    check('DUP_UTR: the same UTR on another booking', has_code($r, 'DUP_UTR') && in_array((string) $b2['pnr'], $r['reasons'][array_search('DUP_UTR', array_column($r['reasons'], 'code'), true)]['pnrs'] ?? [], true), $r['codes']);
    check('DUP_UTR alone (+50) makes it HIGH', $r['level'] === 'high', (string) $r['score']);

    // DUP_SHOT / EDITED_SHOT: screenshot rows
    $sha = hash('sha256', 'qa-shot-' . microtime(true));
    foreach ([$b2, $b3] as $bb) {
        $pid = (int) Database::scalar('SELECT MAX(id) FROM payments WHERE booking_id = :b', ['b' => (int) $bb['id']]);
        Database::insert('payment_screenshots', ['payment_id' => $pid, 'booking_id' => (int) $bb['id'], 'file_path' => 'qa/none.jpg',
            'original_name' => 'x.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 10, 'sha256' => $sha, 'uploaded_ip' => '10.0.0.5']);
    }
    $r = risk_of((int) $b2['id']);
    check('DUP_SHOT: the very same screenshot file on another booking', has_code($r, 'DUP_SHOT'), $r['codes']);

    $b4 = fixture('9811100304');
    proof($b4, fresh_utr(4));
    $pid4 = (int) Database::scalar('SELECT MAX(id) FROM payments WHERE booking_id = :b', ['b' => (int) $b4['id']]);
    Database::insert('payment_screenshots', ['payment_id' => $pid4, 'booking_id' => (int) $b4['id'], 'file_path' => 'qa/ps.jpg',
        'original_name' => 'p.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 10, 'sha256' => hash('sha256', 'ps' . microtime(true)), 'edit_tool' => 'Photoshop']);
    $r = risk_of((int) $b4['id']);
    check('EDITED_SHOT: a photo editor named in the file', has_code($r, 'EDITED_SHOT'), $r['codes']);

    // fingerprint / editor detection on real files
    $tmp = sys_get_temp_dir() . '/qa_fp_' . getmypid();
    $im = imagecreatetruecolor(120, 200); imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 120, 40, imagecolorallocate($im, 90, 40, 160));
    imagefilledellipse($im, 60, 100, 40, 40, imagecolorallocate($im, 30, 160, 80));
    imagejpeg($im, $tmp . 'a.jpg', 90); imagejpeg($im, $tmp . 'b.jpg', 60);
    $jpg = (string) file_get_contents($tmp . 'a.jpg'); $c = 'Adobe Photoshop 25.0';
    file_put_contents($tmp . 'c.jpg', substr($jpg, 0, 2) . "\xFF\xFE" . pack('n', strlen($c) + 2) . $c . substr($jpg, 2));
    $fa = PaymentRisk::fingerprint($tmp . 'a.jpg', 'image/jpeg'); $fb = PaymentRisk::fingerprint($tmp . 'b.jpg', 'image/jpeg'); $fc = PaymentRisk::fingerprint($tmp . 'c.jpg', 'image/jpeg');
    $ham = $fa['phash'] !== null && $fb['phash'] !== null ? substr_count(decbin((int) $fa['phash'] ^ (int) $fb['phash']), '1') : 99;
    check('dHash: the same picture re-compressed stays within 4 bits', $ham <= 4, "distance $ham");
    check('editor detection: a COM segment naming Photoshop', $fc['editTool'] === 'Photoshop' && $fa['editTool'] === null);
    check('fingerprint never throws on a missing / non-image file', PaymentRisk::fingerprint($tmp . 'nope.jpg', 'image/jpeg') === ['phash' => null, 'editTool' => null]
        && PaymentRisk::fingerprint(__FILE__, 'application/pdf')['phash'] === null);
    @unlink($tmp . 'a.jpg'); @unlink($tmp . 'b.jpg'); @unlink($tmp . 'c.jpg');
    check('NEAR_SHOT ships OFF (weight 0)', PaymentRisk::weight('NEAR_SHOT') === 0);

    $b5 = fixture('9811100305');
    Database::update('bookings', ['total_amount' => 6000], 'id = :i', ['i' => (int) $b5['id']]);   // + HIGH_VALUE: MEDIUM
    proof($b5, 'ok' . random_int(10000000, 99999999));
    $r = risk_of((int) $b5['id']);
    check('UTR_FORMAT: a UPI UTR that is not 12 digits', has_code($r, 'UTR_FORMAT'), $r['codes']);
    check('UTR_FORMAT: an all-one-digit / counting number', PaymentRisk::utrLooksWrong('111111111111', 'upi') !== null && PaymentRisk::utrLooksWrong('123456789012', 'upi') !== null
        && PaymentRisk::utrLooksWrong(fresh_utr(9), 'upi') === null);

    $b6 = fixture('9811100306');
    proof($b6, substr(date('Y'), -1) . '001' . '10' . '445566');   // 1 Jan, 10:00 this year
    $r = risk_of((int) $b6['id']);
    check('UTR_OLD: a UPI date code days before the booking', (int) date('z') < 5 || has_code($r, 'UTR_OLD'), $r['codes']);

    $b7 = fixture('9811100307');
    proof($b7, fresh_utr(7), ['claimedAmount' => 100]);
    $r = risk_of((int) $b7['id']);
    check('AMOUNT_MISMATCH: the amount the customer typed differs from the fare', has_code($r, 'AMOUNT_MISMATCH'), $r['codes']);
    check('claimed_amount stored on the payment', (float) Database::scalar('SELECT claimed_amount FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $b7['id']]) === 100.0);

    if (PaymentRisk::hasCol('bookings', 'channel_tag')) {
        $b8 = fixture('9811100308');
        Database::update('bookings', ['channel_tag' => 'WHATSAPP'], 'id = :i', ['i' => (int) $b8['id']]);
        proof($b8, fresh_utr(8));
        $r = risk_of((int) $b8['id']);
        check('TOO_FAST: proof within 45 s of a WhatsApp booking', has_code($r, 'TOO_FAST'), $r['codes']);
        check('QR_NEVER_OPENED: a WhatsApp booking whose pay QR was never opened', has_code($r, 'QR_NEVER_OPENED'), $r['codes']);
        check('web bookings are never judged on TOO_FAST / QR', !has_code(risk_of((int) $b1['id']), 'TOO_FAST') && !has_code(risk_of((int) $b1['id']), 'QR_NEVER_OPENED'));
    }

    $b9 = fixture('9811100309');
    proof($b9, fresh_utr(10));
    Logger::audit('booking.reopen', 'booking', (string) $b9['pnr'], null, ['qa' => 1], 'qa');
    $r = risk_of((int) $b9['id']);
    check('REOPENED: re-opened after a rejection', has_code($r, 'REOPENED'), $r['codes']);

    $vel = [];
    for ($i = 0; $i < 3; $i++) { $vel[] = fixture('9811100310'); }
    proof($vel[2], fresh_utr(11));
    $r = risk_of((int) $vel[2]['id']);
    check('VELOCITY_PHONE: 3 bookings from one mobile in 24 h', has_code($r, 'VELOCITY_PHONE'), $r['codes']);

    $ipB = [];
    foreach (['9811100311', '9811100312', '9811100313'] as $p) {
        $x = fixture($p);
        Database::update('bookings', ['ip_address' => '49.36.211.77'], 'id = :i', ['i' => (int) $x['id']]);
        $ipB[] = $x;
    }
    proof($ipB[0], fresh_utr(12));
    $r = risk_of((int) $ipB[0]['id']);
    check('VELOCITY_IP: one public IP used by 3 mobiles in 24 h', has_code($r, 'VELOCITY_IP'), $r['codes']);
    check('private / office IPs are never compared', !PaymentRisk::publicIp('192.168.1.4') && !PaymentRisk::publicIp('127.0.0.1') && PaymentRisk::publicIp('49.36.211.77'));

    $agentId = (int) Database::insert('admins', ['username' => 'qa-risk-agent-' . bin2hex(random_bytes(3)), 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT), 'full_name' => 'QA New Agent', 'role' => 'agent', 'is_active' => 1]);
    $madeAdmins[] = $agentId;
    $b10 = fixture('9811100314');
    Database::update('bookings', ['sold_by_admin_id' => $agentId], 'id = :i', ['i' => (int) $b10['id']]);
    proof($b10, fresh_utr(13));
    $r = risk_of((int) $b10['id']);
    check('NEW_AGENT: sold by an agent added < 14 days ago', has_code($r, 'NEW_AGENT'), $r['codes']);

    for ($i = 0; $i < 3; $i++) {
        $cx = fixture('9811100315');
        Database::update('bookings', ['status' => 'cancelled'], 'id = :i', ['i' => (int) $cx['id']]);
    }
    $b11 = fixture('9811100315');
    proof($b11, fresh_utr(14));
    $r = risk_of((int) $b11['id']);
    check('CANCEL_FLAG: the mobile has brain_cancel_flag+ cancellations', has_code($r, 'CANCEL_FLAG'), $r['codes']);

    $b12 = fixture('9811100316');
    Database::update('bookings', ['total_amount' => 6200], 'id = :i', ['i' => (int) $b12['id']]);
    proof($b12, fresh_utr(15));
    $r = risk_of((int) $b12['id']);
    check('HIGH_VALUE: total >= risk_high_value', has_code($r, 'HIGH_VALUE'), $r['codes']);

    $b13 = fixture('9811100317');
    $r = risk_of((int) $b13['id']);
    check('NO_PROOF: no UTR and no screenshot', has_code($r, 'NO_PROOF') && !$r['hasProof'], $r['codes']);

    $b14 = fixture('9811100318');
    proof($b14, 'ICICI-' . random_int(1000000000, 9999999999), ['method' => 'bank']);
    $r = risk_of((int) $b14['id']);
    $w = array_column($r['reasons'], 'weight', 'code');
    check('BANK_SIGNED: an ICICI gateway proof carries a NEGATIVE weight', has_code($r, 'BANK_SIGNED') && ($w['BANK_SIGNED'] ?? 0) < 0 && $r['bankSigned'] === true, $r['codes']);

    $seen = PaymentRisk::withAmountSeen(risk_of((int) $b1['id']), (int) $b1['id'], 10);
    check('AMOUNT_SEEN_SHORT: the reviewer saw less than the fare (folds in, raises the level)', has_code($seen, 'AMOUNT_SEEN_SHORT') && $seen['level'] !== 'low');

    $cod = fixture('9811100319', ['cod' => true, 'method' => 'cod']);
    $r = risk_of((int) $cod['id']);
    check('COD bookings are skipped (never scored)', $r['level'] === 'skip' && $r['skip'] === 'cod' && $r['score'] === 0);
    $sel = fixture('9811100320');
    Database::update('bookings', ['sold_by_admin_id' => $agentId], 'id = :i', ['i' => (int) $sel['id']]);
    Database::query("UPDATE payments SET mode = 'offline', status = 'verified', verified_by = :a WHERE booking_id = :b", ['a' => $agentId, 'b' => (int) $sel['id']]);
    $r = risk_of((int) $sel['id']);
    check('seller sales (verified by the seller at the desk) are skipped', $r['level'] === 'skip' && $r['skip'] === 'seller');
    check('owner-tunable: a risk_w_* setting changes the weight', (function () { setting_override('risk_w_utr_format', '33'); $w = PaymentRisk::weight('UTR_FORMAT'); setting_override('risk_w_utr_format', '15'); return $w === 33; })());

    echo "\n-- 2. The approval gate --\n";
    $res = BookingService::approveReviewed((int) $b1['id'], $alice, ['via' => 'queue']);
    check('LOW: approves straight away (ticket issued)', $res['state'] === 'confirmed' && Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $b1['id']]) === 'confirmed');

    $gateNeed = static function (int $id, array $who, array $opts): string {
        try { BookingService::approveReviewed($id, $who, $opts); return 'approved'; }
        catch (PaymentRiskGate $g) { return $g->need; }
    };
    check('MEDIUM without the bank tick: refused (need=bank)', $gateNeed((int) $b5['id'], $alice, ['via' => 'queue']) === 'bank');
    check('… and the booking is untouched (still pending)', Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $b5['id']]) === 'pending');
    check('HIGH with the tick but no reason: refused (need=note)', $gateNeed((int) $b3['id'], $alice, ['via' => 'queue', 'bankChecked' => true]) === 'note');
    check('bulk never approves above LOW', $gateNeed((int) $b5['id'], $alice, ['via' => 'bulk', 'bankChecked' => true, 'note' => 'xxxx']) === 'level');
    check('WhatsApp office assistant never approves above LOW', $gateNeed((int) $b5['id'], $bob, ['via' => 'whatsapp', 'bankChecked' => true, 'note' => 'xxxx']) === 'level');
    $res = BookingService::approveReviewed((int) $b5['id'], $alice, ['via' => 'queue', 'bankChecked' => true]);
    check('MEDIUM with the bank tick: approves', $res['state'] === 'confirmed');

    echo "\n-- 3. Two-person rule (pay_two_step_on) --\n";
    setting_override('pay_two_step_on', '1', 'bool');
    $res = BookingService::approveReviewed((int) $b3['id'], $alice, ['via' => 'queue', 'bankChecked' => true, 'note' => 'saw it in SBI statement']);
    check('HIGH, first approval: recorded, booking NOT confirmed', $res['state'] === 'awaiting_second' && Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $b3['id']]) === 'pending');
    check('the open first approval is found', PaymentRisk::openFirstApproval((int) $b3['id']) !== null);
    check('the SAME person cannot give the second approval', $gateNeed((int) $b3['id'], $alice, ['via' => 'queue', 'bankChecked' => true, 'note' => 'again me']) === 'second');
    check('a counter clerk cannot give the second approval', $gateNeed((int) $b3['id'], $clerk, ['via' => 'queue', 'bankChecked' => true, 'note' => 'clerk ok']) === 'second');
    $res = BookingService::approveReviewed((int) $b3['id'], $bob, ['via' => 'booking', 'bankChecked' => true, 'note' => 'second look, bank ok']);
    check('a different manager / super-admin completes it: confirmed', $res['state'] === 'confirmed' && $res['first'] !== null);
    check('the confirm note names the first approver', str_contains((string) Database::scalar("SELECT detail FROM audit_logs WHERE action = 'booking.confirm' AND entity_id = :p ORDER BY id DESC LIMIT 1", ['p' => (string) $b3['pnr']], ''), '2nd approval')
        || str_contains((string) Database::scalar('SELECT admin_note FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $b3['id']], ''), '2nd approval'));
    setting_override('pay_two_step_on', '0', 'bool');

    echo "\n-- 4. Risk snapshot + hold --\n";
    $steps = array_column(PaymentRisk::reviewsFor((int) $b3['id']), 'step');
    check('payment_reviews keeps approve1 then approve', in_array('approve1', $steps, true) && in_array('approve', $steps, true));
    $snap = Database::fetch("SELECT * FROM payment_reviews WHERE booking_id = :b AND step = 'approve' ORDER BY id DESC LIMIT 1", ['b' => (int) $b3['id']]);
    check('the snapshot holds the score, level, codes, bank tick and note', $snap !== null && (int) $snap['risk_score'] >= 50 && $snap['risk_level'] === 'high'
        && str_contains((string) $snap['risk_codes'], 'DUP_UTR') && (int) $snap['bank_checked'] === 1 && (string) $snap['note'] !== '');
    check("audit row 'payment.review' written", (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.review' AND entity_id = :p", ['p' => (string) $b3['pnr']]) >= 2);

    $h = PaymentRisk::hold((int) $b2['id'], $alice, 'UTR cut off', 'queue');
    check('HOLD: booking stays pending', Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $b2['id']]) === 'pending');
    check('HOLD: a hold review row with a wa.me fallback link when WhatsApp is not configured', $h['sent'] || str_starts_with($h['link'], 'https://wa.me/'));
    check('HOLD text is bilingual and promises the seats stay held', str_contains(PaymentRisk::holdText('SHG-X'), 'UTR') && str_contains(PaymentRisk::holdText('SHG-X'), 'सिट सुरक्षित'));
    check('reject reason chips map to bilingual customer text', str_contains(PaymentRisk::customerText('duplicate_proof'), 'अर्को बुकिङ') && str_contains(PaymentRisk::customerText('duplicate_proof'), 'already used'));
    $rj = BookingService::rejectReviewed((int) $b2['id'], (int) $bob['id'], PaymentRisk::customerText('duplicate_proof'), ['reasonCode' => 'duplicate_proof', 'via' => 'queue']);
    check('a person pressing Reject: rejected + reject snapshot', ($rj['status'] ?? '') === 'rejected'
        && Database::scalar("SELECT reason_code FROM payment_reviews WHERE booking_id = :b AND step = 'reject' ORDER BY id DESC LIMIT 1", ['b' => (int) $b2['id']]) === 'duplicate_proof');

    echo "\n-- 5. Nothing rejects / cancels / refunds by itself --\n";
    $before = Database::fetchAll("SELECT id, status FROM bookings WHERE id IN (" . (int) $b4['id'] . ',' . (int) $b6['id'] . ',' . (int) $b9['id'] . ')');
    PaymentRisk::scoreMany(array_column($before, 'id'));
    foreach ($before as $row) { try { PaymentRisk::assertApprovable((int) $row['id'], $alice, ['via' => 'queue']); } catch (Throwable $e) {} }
    $after = Database::fetchAll("SELECT id, status FROM bookings WHERE id IN (" . (int) $b4['id'] . ',' . (int) $b6['id'] . ',' . (int) $b9['id'] . ')');
    check('scoring + a refused gate leave every booking exactly as it was', $before == $after);
    $forbidden = '/(BookingService::(reject|cancel|cancelBooking|expire|refund)\w*\s*\(|Refund::\w+\s*\(|Seats::releaseAll\s*\(|->reject\s*\()/';
    foreach (['includes/paymentrisk.php', 'api/ticket-live.php', 'api/report-fake.php', 'admin/scan.php', 'includes/aiwatch.php'] as $f) {
        $src = (string) @file_get_contents($ROOT . '/' . $f);
        check("$f never calls reject / cancel / refund / release", $src !== '' && preg_match($forbidden, $src) !== 1);
    }
    $bk = (string) file_get_contents($ROOT . '/includes/booking.php');
    preg_match('/public static function approveReviewed.*?\R    }\R/s', $bk, $ar);
    check('approveReviewed() never rejects or cancels', isset($ar[0]) && !preg_match('/self::(reject|cancel)\w*\(/', $ar[0]));
    $notify = (string) file_get_contents($ROOT . '/includes/notify.php');
    check('the high-risk proof alert only advises (no reject / hold call)', str_contains($notify, "HIGH RISK: ") && !preg_match('/HIGH RISK.{0,600}(::reject|::hold)\(/s', $notify));

    echo "\n-- 6. One gate for every approval path --\n";
    $pay = (string) file_get_contents($ROOT . '/admin/payments.php');
    $bv  = (string) file_get_contents($ROOT . '/admin/booking-view.php');
    $ait = (string) file_get_contents($ROOT . '/includes/aitools.php');
    check('payments.php approves through approveReviewed (single + bulk)', substr_count($pay, 'BookingService::approveReviewed(') >= 2 && !str_contains($pay, 'BookingService::confirm('));
    check('booking-view.php approves through approveReviewed', str_contains($bv, 'BookingService::approveReviewed(') && !preg_match('/BookingService::confirm\(\(int\) \$b\[\'id\'\], \(int\) \$admin/', $bv));
    check('aitools officeConfirm approves through approveReviewed', str_contains($ait, "BookingService::approveReviewed((int) \$detail['id']") && str_contains($ait, "'via' => 'whatsapp'"));
    check('bulk: re-scores, re-checks the desk per id, caps 25', str_contains($pay, "array_slice(\$ids, 0, 25)") && preg_match('/foreach \(\$ids as \$id\).{0,400}CounterDesk::bookingVisible\(\$id\).{0,400}PaymentRisk::score\(\$id\)/s', $pay) === 1);
    check('payments.php keeps Auth::deskScopeCode() and desk-scoped counts', str_contains($pay, 'Auth::deskScopeCode()') && str_contains($pay, "WHERE b.status='pending'\" . \$deskAnd"));
    check('payments.php keeps the cod_settle POST contract and the .pay-tabs / data-stat hooks', str_contains($pay, "\$action === 'cod_settle'") && str_contains($pay, 'class="pay-tabs"') && str_contains($pay, 'data-stat="pending"'));
    check('screenshot.php: &sid= scoped to the booking + desk check', str_contains((string) file_get_contents($ROOT . '/admin/screenshot.php'), 'WHERE id = :s AND booking_id = :b')
        && str_contains((string) file_get_contents($ROOT . '/admin/screenshot.php'), 'CounterDesk::bookingVisible($bookingId)'));
    check('booking-view keeps the display-only duplicate pills (admin-consistency pin)', str_contains($bv, 'WHERE p2.utr_number = :u AND p2.booking_id <> :b'));
    check('booking-view has the Risk & review history panel above Payment review', ($p1 = strpos($bv, 'id="riskPanel"')) !== false && ($p2 = strpos($bv, '<h2>Payment review</h2>')) !== false && $p1 < $p2);

    echo "\n-- 7. Live ticket code --\n";
    $t0 = 1760000010;   // a fixed instant
    $c0 = TicketLive::code('SHG-2026-00001', $t0);
    check('4 digits', preg_match('/^\d{4}$/', $c0) === 1, $c0);
    check('stable inside its 30 s window', TicketLive::code('SHG-2026-00001', $t0 - ($t0 % 30)) === TicketLive::code('SHG-2026-00001', $t0 - ($t0 % 30) + 29));
    $codes = [];
    for ($i = 0; $i < 12; $i++) { $codes[] = TicketLive::code('SHG-2026-00001', $t0 + 30 * $i); }
    check('rotates across windows', count(array_unique($codes)) >= 10, implode(',', $codes));
    check('keyed per PNR', TicketLive::code('SHG-2026-00002', $t0) !== $c0 || TicketLive::code('SHG-2026-00002', $t0 + 30) !== TicketLive::code('SHG-2026-00001', $t0 + 30));
    check('validFor counts down to the window edge', TicketLive::validFor($t0 - ($t0 % 30)) === 30 && TicketLive::validFor($t0 - ($t0 % 30) + 29) === 1);

    echo "\n-- 8. Contracts in the source --\n";
    $tl = (string) file_get_contents($ROOT . '/api/ticket-live.php');
    check('ticket-live: key OR customerMayOpen OR staff; rate limit 120/60', str_contains($tl, 'Ticket::checkDownloadToken(') && str_contains($tl, 'Auth::customerMayOpen($booking)') && str_contains($tl, "requireRateLimit('ticket_live', Security::clientIp(), 120, 60)"));
    check('ticket-live: unknown PNR and no access give the same 403', substr_count($tl, '$deny()') >= 3);
    $rf = (string) file_get_contents($ROOT . '/api/report-fake.php');
    check('report-fake: CSRF, honeypot, 5/h per IP, fake_ticket category, bell', str_contains($rf, 'Security::requireCsrf()') && str_contains($rf, "'website'")
        && str_contains($rf, "rateLimit('fake_report', \$ip, 5, 3600)") && str_contains($rf, "'category' => 'fake_ticket'") && str_contains($rf, "AdminAlerts::push('fake_report'"));
    check('report-fake: photo through Security::validateUpload into UPLOAD_PATH/fraud/Y/m', str_contains($rf, 'Security::validateUpload(') && str_contains($rf, "'fraud/' . date('Y') . '/' . date('m')"));
    check("HelpDesk has the 'fake_ticket' category (urgent)", (HelpDesk::CATEGORIES['fake_ticket'][2] ?? '') === 'urgent');
    $sc = (string) file_get_contents($ROOT . '/admin/scan.php');
    check("scan.php audits every outcome ('ticket.scan') and rings the bell for bad ones", str_contains($sc, "Logger::audit('ticket.scan'") && str_contains($sc, "AdminAlerts::push('fake_scan'"));
    check('scan.php: COD collect-cash, date / bus / trip override note, photo-ID tick, codes', str_contains($sc, "'cash_collected'") && str_contains($sc, "'ticket.board_override'")
        && str_contains($sc, "'id_checked'") && str_contains($sc, 'TicketLive::securityCode(') && str_contains($sc, 'TicketLive::code('));
    check('scan.php keeps markBoarded() as the one boarding write', substr_count($sc, 'BookingService::markBoarded($') === 1);
    check('scan.php: StaffMsg report only when available (contract)', str_contains($sc, "class_exists('StaffMsg') && StaffMsg::available()"));
    $co = (string) file_get_contents($ROOT . '/assets/js/07-checkout.js');
    check('on-screen ticket: live band (shg-secure-css), polls ticket-live, OFFLINE never shows a cached code',
        str_contains($co, "st.id = 'shg-secure-css'") && str_contains($co, '/api/ticket-live.php?pnr=') && str_contains($co, "if (s !== 'live') { const c = el('tkSecCode'); if (c) c.textContent = '····'"));
    check('on-screen band respects reduced motion', str_contains($co, 'prefers-reduced-motion:reduce') && str_contains($co, 'html[data-motion="reduced"] .shg-sec-holo'));
    check('proof form sends the optional amount', str_contains($co, "fd.append('amount'") && str_contains($co, 'amount: coClaimedAmount()'));
    check('api/payment.php passes claimedAmount through', str_contains((string) file_get_contents($ROOT . '/api/payment.php'), "'claimedAmount' => \$claimed"));
    check('AiWatch shows high-risk payments waiting', str_contains((string) file_get_contents($ROOT . '/includes/aiwatch.php'), "'payrisk', 'high'"));

    echo "\n-- 9. Over HTTP --\n";
    $base = rtrim((string) (getenv('SHG_TEST_BASE') ?: ''), '/');
    if ($base === '' || !function_exists('curl_init')) {
        echo "  (skipped: " . ($base === '' ? 'SHG_TEST_BASE not set' : 'curl not loaded') . ")\n";
    } else {
        $mkReq = static function (string $jar) use ($base): Closure {
            return static function (string $method, string $url, array $form = [], array $headers = []) use ($base, $jar): array {
                $ch = curl_init($base . $url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 90]);
                if ($form !== []) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
                if ($headers !== []) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
                $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
            };
        };
        try { Database::query("DELETE FROM rate_limits WHERE bucket IN ('ticket_live','fake_report','csrf_refresh') OR bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}

        // ticket-live
        $pub = $mkReq(sys_get_temp_dir() . '/shg_prt_pub_' . getmypid() . '.c');
        $pnr1 = (string) $b1['pnr'];
        $r = $pub('GET', '/api/ticket-live.php?pnr=' . rawurlencode($pnr1));
        check('ticket-live without key / session: 403', $r['code'] === 403, 'HTTP ' . $r['code']);
        $r2 = $pub('GET', '/api/ticket-live.php?pnr=SHG-2099-99999&k=' . str_repeat('a', 20));
        check('ticket-live for an unknown PNR: the same 403', $r2['code'] === 403 && ($r['json']['error'] ?? 'x') === ($r2['json']['error'] ?? 'y'));
        $r = $pub('GET', '/api/ticket-live.php?pnr=' . rawurlencode($pnr1) . '&k=' . Ticket::downloadToken($pnr1));
        $now = time();
        $okCodes = [TicketLive::code($pnr1, $now), TicketLive::code($pnr1, $now - 30), TicketLive::code($pnr1, $now + 30)];
        check('ticket-live with the ticket key: 200 + the live code + server time', $r['code'] === 200 && in_array((string) ($r['json']['data']['code'] ?? ''), $okCodes, true)
            && ($r['json']['data']['status'] ?? '') === 'confirmed' && !empty($r['json']['data']['serverTime']), 'HTTP ' . $r['code']);

        // admin: bulk approve re-scores per row
        $user = 'qa-payrisk'; $pw = 'PayRisk@' . bin2hex(random_bytes(4));
        $qaId = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $user], 0);
        if ($qaId === 0) {
            $qaId = (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash($pw, PASSWORD_BCRYPT), 'full_name' => 'Pay Risk QA', 'role' => 'superadmin', 'is_active' => 1, 'must_change_pw' => 0]);
        } else {
            Database::update('admins', ['password_hash' => password_hash($pw, PASSWORD_BCRYPT), 'role' => 'superadmin', 'is_active' => 1, 'must_change_pw' => 0, 'failed_logins' => 0, 'locked_until' => null], 'id = :i', ['i' => $qaId]);
        }
        $madeAdmins[] = $qaId;
        $adm = $mkReq(sys_get_temp_dir() . '/shg_prt_adm_' . getmypid() . '.c');
        $lp = $adm('GET', '/admin/login.php');
        preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m);
        $li = $adm('POST', '/admin/login.php', ['shg_csrf' => $m[1] ?? '', 'username' => $user, 'password' => $pw]);
        check('a super-admin signs in', $li['code'] === 302, 'HTTP ' . $li['code']);
        $pg = $adm('GET', '/admin/payments.php?tab=pending&sub=all');
        preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $pg['body'], $m2);
        $tok = $m2[1] ?? '';
        check('the queue renders with risk chips and the bottom sheet', $pg['code'] === 200 && str_contains($pg['body'], 'class="pq-card') && str_contains($pg['body'], 'id="pqSheet"'));
        $lowB = fixture('9811100321'); proof($lowB, fresh_utr(21));
        $medB = fixture('9811100322'); Database::update('bookings', ['total_amount' => 6000], 'id = :i', ['i' => (int) $medB['id']]); proof($medB, 'ok' . random_int(10000000, 99999999));
        $bulk = $adm('POST', '/admin/payments.php', ['shg_csrf' => $tok, 'action' => 'bulk_approve', 'ids' => [(int) $lowB['id'], (int) $medB['id']]], ['X-Requested-With: XMLHttpRequest']);
        $rows = array_column((array) ($bulk['json']['results'] ?? []), null, 'id');
        check('bulk: the LOW row is approved', !empty($rows[(int) $lowB['id']]['ok']) && Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $lowB['id']]) === 'confirmed', json_encode($rows[(int) $lowB['id']] ?? null));
        check('bulk: the MEDIUM row is re-scored and left for a person', isset($rows[(int) $medB['id']]) && empty($rows[(int) $medB['id']]['ok'])
            && Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $medB['id']]) === 'pending');
        $ap = $adm('POST', '/admin/payments.php', ['shg_csrf' => $tok, 'action' => 'approve', 'booking_id' => (int) $medB['id']], ['X-Requested-With: XMLHttpRequest']);
        check('single approve of MEDIUM without the tick: JSON need=bank', ($ap['json']['ok'] ?? true) === false && ($ap['json']['need'] ?? '') === 'bank');
        $hd = $adm('POST', '/admin/payments.php', ['shg_csrf' => $tok, 'action' => 'hold', 'booking_id' => (int) $medB['id'], 'note' => 'please resend'], ['X-Requested-With: XMLHttpRequest']);
        check('hold over HTTP: ok, still pending', ($hd['json']['ok'] ?? false) === true && Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $medB['id']]) === 'pending');
        $sh = $adm('GET', '/admin/screenshot.php?id=' . (int) $b4['id'] . '&sid=999999999');
        check('screenshot.php: a sid from another booking is a 404', $sh['code'] === 404);

        // scan.php audit row
        $fake = 'SHG-QA-' . strtoupper(bin2hex(random_bytes(3)));
        $sc1 = $adm('GET', '/admin/scan.php?pnr=' . rawurlencode($fake));
        check('scan.php: an unknown PNR reads NOT FOUND', $sc1['code'] === 200 && str_contains($sc1['body'], 'NOT FOUND'));
        check("scan.php: … and writes 'ticket.scan' outcome not_found", str_contains((string) Database::scalar("SELECT new_value FROM audit_logs WHERE action = 'ticket.scan' AND entity_id = :p ORDER BY id DESC LIMIT 1", ['p' => $fake], ''), 'not_found'));
        Database::query("DELETE FROM notifications WHERE title LIKE :t", ['t' => '%' . $fake . '%']);

        // report-fake
        $rep = $mkReq(sys_get_temp_dir() . '/shg_prt_rep_' . getmypid() . '.c');
        $g = $rep('GET', '/api/report-fake.php');
        $ctok = (string) ($g['json']['data']['csrf'] ?? '');
        check('report-fake GET hands a CSRF token', $g['code'] === 200 && strlen($ctok) >= 16);
        $PHONES['9811100330'] = true;
        $no = $rep('POST', '/api/report-fake.php', ['pnr' => (string) $b1['pnr'], 'phone' => '9811100330', 'message' => 'qa no token']);
        check('report-fake without CSRF: 419', $no['code'] === 419, 'HTTP ' . $no['code']);
        $nBefore = (int) Database::scalar("SELECT COUNT(*) FROM support_tickets WHERE phone LIKE '%9811100330'");
        $hp = $rep('POST', '/api/report-fake.php', ['shg_csrf' => $ctok, 'pnr' => (string) $b1['pnr'], 'phone' => '9811100330', 'message' => 'qa bot', 'website' => 'http://spam']);
        check('report-fake honeypot: quiet ok, nothing stored', ($hp['json']['ok'] ?? false) === true && (int) Database::scalar("SELECT COUNT(*) FROM support_tickets WHERE phone LIKE '%9811100330'") === $nBefore);
        $ok = $rep('POST', '/api/report-fake.php', ['shg_csrf' => $ctok, 'pnr' => (string) $b1['pnr'], 'phone' => '9811100330', 'message' => 'QA: the code on the paper does not match']);
        check('report-fake: opens a fake_ticket help-desk ticket and answers {ok, ref}', ($ok['json']['ok'] ?? false) === true && str_starts_with((string) ($ok['json']['ref'] ?? ''), 'HD-')
            && Database::scalar("SELECT category FROM support_tickets WHERE ticket_ref = :r", ['r' => (string) ($ok['json']['ref'] ?? '')]) === 'fake_ticket');
        $unk = $rep('POST', '/api/report-fake.php', ['shg_csrf' => $ctok, 'pnr' => 'SHG-2099-99998', 'phone' => '9811100330', 'message' => 'QA: the code on the paper does not match']);
        check('report-fake: the same answer shape for an unknown PNR (no enumeration)', ($unk['json']['ok'] ?? false) === true);
        $last = null;
        for ($i = 0; $i < 5; $i++) { $last = $rep('POST', '/api/report-fake.php', ['shg_csrf' => $ctok, 'pnr' => (string) $b1['pnr'], 'phone' => '9811100330', 'message' => 'QA: the code on the paper does not match']); }
        check('report-fake: more than 5 an hour from one IP is refused (429)', ($last['code'] ?? 0) === 429, 'HTTP ' . ($last['code'] ?? 0));
        try { Database::query("DELETE FROM rate_limits WHERE bucket IN ('ticket_live','fake_report')"); } catch (Throwable $e) {}
    }
} catch (Throwable $e) {
    check('suite ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    /* clean up every fixture */
    try {
        $in = "'" . implode("','", array_keys($PHONES)) . "'";
        foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone IN ($in)") as $row) {
            Database::delete('payment_reviews', 'booking_id = :i', ['i' => (int) $row['id']]);
            Database::query('DELETE FROM payment_screenshots WHERE booking_id = :i', ['i' => (int) $row['id']]);
            Database::delete('bookings', 'id = :i', ['i' => (int) $row['id']]);
            Database::query("DELETE FROM notifications WHERE link LIKE :l", ['l' => '%' . $row['pnr'] . '%']);
        }
        foreach (Database::fetchAll("SELECT id FROM support_tickets WHERE phone IN ('919811100330','9779811100330')") as $t) {
            Database::query('DELETE FROM support_messages WHERE ticket_id = :i', ['i' => (int) $t['id']]);
            Database::query('DELETE FROM support_tickets WHERE id = :i', ['i' => (int) $t['id']]);
        }
        foreach (array_filter($madeAdmins) as $aid) {
            if (Database::scalar('SELECT username FROM admins WHERE id = :i', ['i' => $aid], '') !== '') {
                Database::query('UPDATE bookings SET sold_by_admin_id = NULL WHERE sold_by_admin_id = :a', ['a' => $aid]);
                Database::query('DELETE FROM admins WHERE id = :a AND username LIKE :u', ['a' => $aid, 'u' => 'qa-%']);
            }
        }
    } catch (Throwable $e) {
        echo "  (cleanup warning: " . $e->getMessage() . ")\n";
    }
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
