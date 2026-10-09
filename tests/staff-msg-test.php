<?php
/**
 * Office chat (admin <-> agent / counter messages about tickets) — 9 Oct 2026.
 *
 *   php -c .claude/php-dev.ini tests/staff-msg-test.php
 *
 * Pins the walls of includes/staffmsg.php and admin/api/staff-msg.php:
 *   · an agent cannot read or post into another agent's conversation, and a
 *     PNR they did not sell answers exactly like an unknown PNR (no existence
 *     disclosure);
 *   · a counter clerk under desk isolation cannot open another desk's ticket;
 *   · the office sees everything; a viewer gets nothing (API 403);
 *   · unread counts per person, markRead, seen ticks (peer read pointer);
 *   · the same client_uuid makes ONE message;
 *   · the stored attachment path never reaches a browser; uploads are typed by
 *     their bytes (a PHP file named .png is refused; a real Ogg/Opus voice note
 *     is accepted only as a voice note);
 *   · system lines land only in an existing ticket conversation;
 *   · push text carries no passenger name or phone;
 *   · pending-count.php keeps its pinned keys (pending, maxBooking, sync, ver).
 * The HTTP block drives the real API through the dev server (localhost:8991)
 * with hand-made session files; it is skipped (not failed) when no server runs.
 * Writes only throwaway rows and removes them. Exit 1 on any failure.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/staffmsg.php';

$PASS = 0; $FAIL = 0; $SKIP = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}
function skip(string $l): void { global $SKIP; $SKIP++; echo "  \033[33mSKIP\033[0m  $l\n"; }

/** Run $fn and return the exception's [code, message], or [0, ''] when it did not throw. */
function throws(callable $fn): array {
    try { $fn(); return [0, '']; } catch (Throwable $e) { return [(int) $e->getCode(), $e->getMessage()]; }
}

if (!StaffMsg::tablesReady(true)) {
    check('chat tables installed (php tests/apply-sql.php database/upgrade-2026-10-09-staff-chat.sql)', false);
    echo "\n  $PASS passed, $FAIL failed\n\n";
    exit(1);
}

StaffMsg::$pushEnabled = false;   // nothing leaves the machine
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$asAdmin = static function (int $id, string $role, string $user): void {
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => $id, 'username' => $user, 'full_name' => $user,
        'role' => $role, 'permissions' => [], 'must_change_pw' => false,
        'logged_in_at' => time(), 'last_seen' => time(),
    ];
};
/** Flip a setting in memory only (never in the shared DB). */
$memSetting = static function (string $key, string $value, string $type): void {
    $p = new ReflectionProperty(Settings::class, 'cache');
    $p->setAccessible(true);
    Settings::get('company_name');              // make sure the cache is loaded
    $c = (array) $p->getValue();
    $c[$key] = ['value' => $value, 'type' => $type];
    $p->setValue(null, $c);
};

$PFX   = 'smsgtest-';
$users = ['agentA' => 'agent', 'agentB' => 'agent', 'ctrC' => 'counter', 'ctrD' => 'counter', 'office' => 'support', 'viewer' => 'viewer'];
$ids   = [];
$bk    = [];
$files = [];
$sessFiles = [];
$tidMax = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM staff_threads', [], 0);

$cleanup = static function () use (&$ids, &$bk, &$files, &$sessFiles, $tidMax, $PFX): void {
    $idList = array_values(array_filter(array_map('intval', $ids)));
    $threadIds = [];
    if ($idList !== []) {
        $in = implode(',', $idList);
        $threadIds = array_map('intval', array_column(Database::fetchAll("SELECT id FROM staff_threads WHERE agent_admin_id IN ($in)"), 'id'));
        // broadcast lines this run wrote into real people's chats
        $threadIds = array_merge($threadIds, array_map('intval', array_column(Database::fetchAll(
            "SELECT DISTINCT thread_id FROM staff_messages WHERE sender_admin_id IN ($in)"), 'thread_id')));
    }
    $threadIds = array_values(array_unique($threadIds));
    foreach ($threadIds as $tid) {
        foreach (Database::fetchAll('SELECT attach_path FROM staff_messages WHERE thread_id = :t AND attach_path IS NOT NULL', ['t' => $tid]) as $r) {
            $abs = StaffMsg::attachAbs((string) $r['attach_path']);
            if ($abs !== null) { @unlink($abs); }
        }
        Database::delete('notifications', 'link = :l', ['l' => '/admin/staff-chat.php?t=' . $tid]);
        if ($idList !== []) {
            Database::run('DELETE FROM staff_messages WHERE thread_id = :t AND (sender_admin_id IN (' . implode(',', $idList) . ') OR sender_admin_id IS NULL)', ['t' => $tid]);
        }
        Database::delete('staff_thread_reads', 'thread_id = :t', ['t' => $tid]);
        // a thread this run created and nobody else wrote in goes; a real one keeps its own lines
        $left = (int) Database::scalar('SELECT COUNT(*) FROM staff_messages WHERE thread_id = :t', ['t' => $tid], 0);
        if ($left === 0 && $tid > $tidMax) {
            Database::delete('staff_threads', 'id = :t', ['t' => $tid]);
        } elseif ($left > 0) {
            $last = Database::fetch('SELECT id, side, created_at FROM staff_messages WHERE thread_id = :t ORDER BY id DESC LIMIT 1', ['t' => $tid]);
            Database::run('UPDATE staff_threads SET last_msg_id = :m, last_side = :s, msg_count = :n WHERE id = :t',
                ['m' => (int) $last['id'], 's' => (string) $last['side'], 'n' => $left, 't' => $tid]);
        }
    }
    foreach ($bk as $b) { Database::delete('bookings', 'id = :i', ['i' => (int) $b]); }
    foreach ($idList as $id) {
        Database::delete('staff_presence', 'admin_id = :a', ['a' => $id]);
        Database::delete('staff_thread_reads', 'admin_id = :a', ['a' => $id]);
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]);
        Database::delete('admins', 'id = :a', ['a' => $id]);
    }
    foreach ($idList as $id) { Database::run("DELETE FROM rate_limits WHERE bucket = 'staff_msg' AND identifier = :i", ['i' => 'admin:' . $id]); }
    foreach ($files as $f) { if (is_file($f)) { @unlink($f); } }
    foreach ($sessFiles as $f) { if (is_file($f)) { @unlink($f); } }
};

try {
    /* ------------------------------------------------------------ people */
    foreach ($users as $u => $role) {
        Database::delete('admins', 'username = :u', ['u' => $PFX . $u]);
        $ids[$u] = Database::insert('admins', [
            'username' => $PFX . $u, 'password_hash' => password_hash('x-' . bin2hex(random_bytes(6)), PASSWORD_DEFAULT),
            'full_name' => 'SMsg ' . $u, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0,
            'phone' => $u === 'agentA' ? '9800012345' : '',
        ]);
    }
    Database::insert('admin_profiles', ['admin_id' => $ids['ctrC'], 'counter_code' => 'SRT', 'counter_name' => 'Surat']);
    Database::insert('admin_profiles', ['admin_id' => $ids['ctrD'], 'counter_code' => 'RJT', 'counter_name' => 'Rajkot']);
    Database::insert('admin_profiles', ['admin_id' => $ids['agentA'], 'whatsapp' => '+977 9800012345']);

    $mk = static function (string $pnr, int $seller, ?string $desk) use (&$bk): int {
        Database::delete('bookings', 'pnr = :p', ['p' => $pnr]);
        $row = [
            'pnr' => $pnr, 'status' => 'confirmed', 'sold_by_admin_id' => $seller,
            'contact_phone' => '9876501234', 'contact_email' => 'smsg@example.test',
            'total_amount' => 2200, 'currency' => 'INR', 'source' => 'agent', 'confirmed_at' => date('Y-m-d H:i:s'),
        ];
        if ($desk !== null && CounterDesk::stampColumn()) { $row['counter_code'] = $desk; }
        $id = Database::insert('bookings', $row);
        $bk[] = $id;
        return $id;
    };
    $bkA = $mk('SHG-SMSGT-AAA', $ids['agentA'], null);
    $bkB = $mk('SHG-SMSGT-BBB', $ids['agentB'], null);
    $bkC = $mk('SHG-SMSGT-CCC', $ids['ctrC'], 'SRT');
    $bkD = $mk('SHG-SMSGT-DDD', $ids['ctrD'], 'RJT');
    $bkO = $mk('SHG-SMSGT-OOO', 1, null);    // an office / online sale

    /* ------------------------------------------------------------ roles */
    $asAdmin($ids['agentA'], 'agent', $PFX . 'agentA');
    check('an agent is the field side', StaffMsg::isField() && !StaffMsg::isOffice() && StaffMsg::canUse() && StaffMsg::sideFor() === 'staff');
    $asAdmin($ids['ctrC'], 'counter', $PFX . 'ctrC');
    check('a counter clerk is the field side', StaffMsg::isField() && StaffMsg::canUse());
    $asAdmin($ids['office'], 'support', $PFX . 'office');
    check('support holds staffmsg.office and is the office side', Auth::can('staffmsg.office') && StaffMsg::isOffice() && StaffMsg::sideFor() === 'office');
    check('support may not broadcast (no staff.manage)', StaffMsg::canBroadcast() === false);
    $asAdmin(1, 'superadmin', 'superadmin');
    check('super-admin is the office side and may broadcast', StaffMsg::isOffice() && StaffMsg::canBroadcast());
    $asAdmin($ids['viewer'], 'viewer', $PFX . 'viewer');
    check('a viewer gets nothing (canUse false)', StaffMsg::canUse() === false);
    check('a viewer: threadFor answers 403', throws(static fn() => StaffMsg::threadFor('general'))[0] === 403);
    check('a viewer: empty inbox, zero unread', StaffMsg::inbox() === [] && StaffMsg::unreadFor() === 0);
    $RP = (array) (new ReflectionClass(Auth::class))->getConstant('ROLE_PERMISSIONS');
    foreach (['counter', 'agent'] as $r) {
        check("ROLE_PERMISSIONS: $r does not get staffmsg.office", !in_array('staffmsg.office', $RP[$r] ?? [], true));
    }
    check('ROLE_PERMISSIONS: counter still has no commissions.view / dashboard.view',
        !in_array('commissions.view', $RP['counter'], true) && !in_array('dashboard.view', $RP['counter'], true));

    /* ------------------------------------------------------------ agent A */
    $asAdmin($ids['agentA'], 'agent', $PFX . 'agentA');
    $gA  = StaffMsg::threadFor('general');
    $gA2 = StaffMsg::threadFor('general');
    check('agent: own general chat is find-or-create (same id twice)', (int) $gA['id'] > 0 && (int) $gA['id'] === (int) $gA2['id'] && (int) $gA['agent_admin_id'] === $ids['agentA']);
    $tA = StaffMsg::threadFor('booking', ['pnr' => 'SHG-SMSGT-AAA']);
    check('agent: ticket conversation on own sale', (int) $tA['booking_id'] === $bkA && (string) $tA['pnr'] === 'SHG-SMSGT-AAA');
    [$cForeign, $mForeign] = throws(static fn() => StaffMsg::threadFor('booking', ['pnr' => 'SHG-SMSGT-BBB']));
    [$cNone, $mNone]       = throws(static fn() => StaffMsg::threadFor('booking', ['pnr' => 'SHG-NOPE-ZZZ999']));
    check('agent: another agent\'s PNR answers 404', $cForeign === 404);
    check('agent: foreign PNR reads exactly like an unknown PNR', $mForeign === $mNone && $cNone === 404);
    check('agent: cannot open a conversation by another agent\'s id', (int) StaffMsg::threadFor('general', ['agent_id' => $ids['agentB']])['agent_admin_id'] === $ids['agentA']);

    $u1  = StaffMsg::derivedUuid('smsg-test-uuid', 1);
    $m1  = StaffMsg::post((int) $tA['id'], "Seat change please — SHG-SMSGT-AAA\nOTP 123456", 'staff', $ids['agentA'], null, $u1);
    $m1b = StaffMsg::post((int) $tA['id'], 'Seat change please (double tap)', 'staff', $ids['agentA'], null, $u1);
    check('same client_uuid makes ONE message', $m1 > 0 && $m1 === $m1b
        && (int) Database::scalar('SELECT COUNT(*) FROM staff_messages WHERE client_uuid = :u', ['u' => $u1], 0) === 1
        && (int) Database::scalar('SELECT msg_count FROM staff_threads WHERE id = :t', ['t' => (int) $tA['id']], 0) === 1);
    $body1 = (string) Database::scalar('SELECT body FROM staff_messages WHERE id = :m', ['m' => $m1], '');
    check('an OTP typed into a message is redacted', !str_contains($body1, '123456'));
    check('sender\'s own message is read by them (no self-unread)', StaffMsg::unreadFor() === 0);
    check('empty message is refused', throws(static fn() => StaffMsg::post((int) $tA['id'], "   \n ", 'staff', $ids['agentA']))[0] === 422);

    /* ------------------------------------------------------------ agent B: the wall */
    $asAdmin($ids['agentB'], 'agent', $PFX . 'agentB');
    $tFull = StaffMsg::threadById((int) $tA['id']);
    check('agent B: mayOpen(agent A\'s ticket thread) is false', StaffMsg::mayOpen($tFull) === false);
    check('agent B: mayOpen(agent A\'s general chat) is false', StaffMsg::mayOpen(StaffMsg::threadById((int) $gA['id'])) === false);
    $inbB = array_column(StaffMsg::inbox(['filter' => 'all']), 'id');
    check('agent B: inbox holds none of agent A\'s threads', !in_array((int) $tA['id'], $inbB, true) && !in_array((int) $gA['id'], $inbB, true));
    check('agent B: zero unread', StaffMsg::unreadFor() === 0);
    check('agent B: booking panel for A\'s sale shows no thread', StaffMsg::threadsForBooking($bkA) === []);

    /* ------------------------------------------------------------ office */
    $asAdmin($ids['office'], 'support', $PFX . 'office');
    $inbO = StaffMsg::inbox(['filter' => 'waiting']);
    check('office: sees agent A\'s ticket thread as waiting', in_array((int) $tA['id'], array_column($inbO, 'id'), true));
    check('office: one unread conversation', StaffMsg::unreadFor() >= 1);
    $poll = StaffMsg::pollInfo();
    check('office: poll shape {unread,last,t} and last = A\'s message', isset($poll['unread'], $poll['last'], $poll['t']) && $poll['last'] >= $m1);
    StaffMsg::markRead((int) $tA['id'], $ids['office']);
    check('office: markRead clears it', !in_array((int) $tA['id'], array_column(array_filter(StaffMsg::inbox(['filter' => 'all']), static fn($t) => $t['unread'] > 0), 'id'), true));
    $m2 = StaffMsg::post((int) $tA['id'], 'Done — seat changed', 'office', $ids['office']);
    check('office: by-PNR conversation goes to the seller', (int) StaffMsg::threadFor('booking', ['pnr' => 'SHG-SMSGT-AAA'])['id'] === (int) $tA['id']);
    check('office: an office/online sale has nobody to message (422)', throws(static fn() => StaffMsg::threadFor('booking', ['pnr' => 'SHG-SMSGT-OOO']))[0] === 422);
    check('office: unknown PNR is 404', throws(static fn() => StaffMsg::threadFor('booking', ['pnr' => 'SHG-NOPE-ZZZ999']))[0] === 404);
    check('office: cannot open a chat with a non-field person', throws(static fn() => StaffMsg::threadFor('general', ['agent_id' => $ids['viewer']]))[0] === 404);
    check('office: close + reopen', (static function () use ($tA, $ids): bool {
        StaffMsg::close((int) $tA['id'], $ids['office'], true);
        $a = (string) Database::scalar('SELECT status FROM staff_threads WHERE id = :t', ['t' => (int) $tA['id']], '');
        StaffMsg::post((int) $tA['id'], 'one more thing', 'staff', $ids['agentA']);
        $b = (string) Database::scalar('SELECT status FROM staff_threads WHERE id = :t', ['t' => (int) $tA['id']], '');
        return $a === 'closed' && $b === 'open';
    })());
    check('office: assign refuses a field person', throws(static fn() => StaffMsg::assign((int) $tA['id'], $ids['agentB'], $ids['office']))[0] === 422);
    StaffMsg::assign((int) $tA['id'], $ids['office'], $ids['office']);
    check('office: assign to self', (int) Database::scalar('SELECT assigned_to FROM staff_threads WHERE id = :t', ['t' => (int) $tA['id']], 0) === $ids['office']);
    $m2 = StaffMsg::post((int) $tA['id'], 'Seat changed to L4 — check the ticket', 'office', $ids['office']);

    /* ------------------------------------------------------------ agent A reads */
    $asAdmin($ids['agentA'], 'agent', $PFX . 'agentA');
    check('agent A: office reply shows as unread', StaffMsg::unreadFor() === 1);
    StaffMsg::markRead((int) $tA['id'], $ids['agentA']);
    check('agent A: markRead -> zero unread', StaffMsg::unreadFor() === 0);
    $asAdmin($ids['office'], 'support', $PFX . 'office');
    check('office: peerRead = agent\'s read pointer (seen ticks)', StaffMsg::peerRead(StaffMsg::threadById((int) $tA['id']), true) >= $m2);

    /* ------------------------------------------------------------ present(): no path, PNR */
    $asAdmin($ids['agentA'], 'agent', $PFX . 'agentA');
    $rows = StaffMsg::messages((int) $tA['id']);
    $pres = array_map(static fn($m) => StaffMsg::present($m, false, $ids['agentA']), $rows);
    check('present(): mine / theirs / system sides are right',
        $pres[0]['mine'] === true && count(array_filter($pres, static fn($m) => $m['side'] === 'office' && !$m['mine'])) >= 1);
    check('present(): no attach_path key', !array_key_exists('attach_path', $pres[0]));

    /* ------------------------------------------------------------ uploads */
    $tmpDir = sys_get_temp_dir();
    $png = $tmpDir . '/smsg-' . bin2hex(random_bytes(4)) . '.png';
    $im = imagecreatetruecolor(24, 16); imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 200)); imagepng($im, $png); imagedestroy($im);
    $files[] = $png;
    $att = StaffMsg::storeUpload(['tmp_name' => $png, 'size' => filesize($png), 'error' => UPLOAD_ERR_OK], false, false);
    check('upload: a real PNG is stored as an image under staff-msg/', $att['kind'] === 'image' && $att['mime'] === 'image/png' && str_starts_with($att['path'], 'staff-msg/'));
    $absAtt = StaffMsg::attachAbs($att['path']);
    check('upload: stored file resolves inside staff-msg/ only', $absAtt !== null && is_file($absAtt));
    check('upload: a path outside staff-msg/ is refused', StaffMsg::attachAbs('staff-msg/../../config/config.php') === null && StaffMsg::attachAbs('passengers/x.jpg') === null);
    check('upload: staff-msg/ has an Apache deny file', is_file(UPLOAD_PATH . '/staff-msg/.htaccess'));
    $mImg = StaffMsg::post((int) $tA['id'], 'payment screenshot', 'staff', $ids['agentA'], $att);
    $pImg = StaffMsg::present(StaffMsg::messages((int) $tA['id'], $mImg - 1, 1)[0], false, $ids['agentA']);
    $json = json_encode($pImg, JSON_UNESCAPED_SLASHES);
    check('upload: the browser sees a streaming URL, never the stored path',
        is_array($pImg['file']) && $pImg['file']['url'] === '/admin/staff-msg-file.php?m=' . $mImg && !str_contains((string) $json, 'staff-msg/') && !str_contains((string) $json, $att['path']));

    $php = $tmpDir . '/smsg-' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($php, "<?php echo 'pwned'; ?>\n");
    $files[] = $php;
    check('upload: a PHP file named .png is refused (415)', throws(static fn() => StaffMsg::storeUpload(['tmp_name' => $php, 'size' => filesize($php), 'error' => UPLOAD_ERR_OK], false, false))[0] === 415);
    check('upload: a photo sent as a "voice note" is refused', throws(static fn() => StaffMsg::storeUpload(['tmp_name' => $png, 'size' => filesize($png), 'error' => UPLOAD_ERR_OK], true, false))[0] === 415);

    // A real Ogg/Opus first page (OpusHead) — what Firefox/Android MediaRecorder writes.
    $head = 'OpusHead' . chr(1) . chr(1) . pack('v', 312) . pack('V', 48000) . pack('v', 0) . chr(0);
    $page = 'OggS' . chr(0) . chr(2) . str_repeat(chr(0), 8) . pack('V', 0x1234) . pack('V', 0) . pack('V', 0) . chr(1) . chr(strlen($head)) . $head;
    $ogg = $tmpDir . '/smsg-' . bin2hex(random_bytes(4)) . '.ogg';
    file_put_contents($ogg, $page . str_repeat("\0", 64));
    $files[] = $ogg;
    $oggMime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($ogg);
    if (in_array($oggMime, ['audio/ogg', 'application/ogg'], true)) {
        $v = StaffMsg::storeUpload(['tmp_name' => $ogg, 'size' => filesize($ogg), 'error' => UPLOAD_ERR_OK], true, false);
        check('upload: an Ogg/Opus voice note is accepted as voice', $v['kind'] === 'voice' && str_ends_with($v['path'], '.ogg'));
        $mV = StaffMsg::post((int) $tA['id'], '', 'staff', $ids['agentA'], $v + ['secs' => 4]);
        check('voice note: kind voice, seconds kept, preview says voice', (string) Database::scalar('SELECT kind FROM staff_messages WHERE id = :m', ['m' => $mV], '') === 'voice'
            && (int) Database::scalar('SELECT attach_secs FROM staff_messages WHERE id = :m', ['m' => $mV], 0) === 4
            && str_contains((string) Database::scalar('SELECT last_preview FROM staff_threads WHERE id = :t', ['t' => (int) $tA['id']], ''), 'Voice'));
    } else {
        skip('Ogg voice note (finfo here reports ' . $oggMime . ')');
    }
    $big = $tmpDir . '/smsg-' . bin2hex(random_bytes(4)) . '.ogg';
    file_put_contents($big, $page . str_repeat("\0", StaffMsg::VOICE_MAX + 10));
    $files[] = $big;
    if (in_array($oggMime, ['audio/ogg', 'application/ogg'], true)) {
        check('upload: a voice note over 2 MB is refused (413)', throws(static fn() => StaffMsg::storeUpload(['tmp_name' => $big, 'size' => filesize($big), 'error' => UPLOAD_ERR_OK], true, false))[0] === 413);
    }

    /* ------------------------------------------------------------ reassigned sale */
    Database::update('bookings', ['sold_by_admin_id' => $ids['agentB']], 'id = :i', ['i' => $bkA]);
    check('a sale moved to another agent takes the old seller off the ticket thread', StaffMsg::mayOpen(StaffMsg::threadById((int) $tA['id'])) === false);
    check('...and it leaves their inbox', !in_array((int) $tA['id'], array_column(StaffMsg::inbox(), 'id'), true));
    Database::update('bookings', ['sold_by_admin_id' => $ids['agentA']], 'id = :i', ['i' => $bkA]);

    /* ------------------------------------------------------------ counter desk isolation */
    if (CounterDesk::stampColumn()) {
        $asAdmin($ids['ctrC'], 'counter', $PFX . 'ctrC');
        $memSetting('counter_desk_isolation', '1', 'bool');
        check('isolation on: clerk C is pinned to SRT', Auth::deskScopeCode() === 'SRT');
        $tC = StaffMsg::threadFor('booking', ['pnr' => 'SHG-SMSGT-CCC']);
        check('isolation on: clerk C opens a thread on own desk\'s ticket', (int) $tC['booking_id'] === $bkC);
        [$cD, $mD] = throws(static fn() => StaffMsg::threadFor('booking', ['pnr' => 'SHG-SMSGT-DDD']));
        check('isolation on: clerk C cannot open the Rajkot desk\'s ticket (404, same words)', $cD === 404 && $mD === $mNone);
        // a thread that exists on another desk's ticket stays invisible too
        $asAdmin($ids['ctrD'], 'counter', $PFX . 'ctrD');
        $tD = StaffMsg::threadFor('booking', ['pnr' => 'SHG-SMSGT-DDD']);
        StaffMsg::post((int) $tD['id'], 'Rajkot desk question', 'staff', $ids['ctrD']);
        $asAdmin($ids['ctrC'], 'counter', $PFX . 'ctrC');
        check('isolation on: clerk C cannot open clerk D\'s thread', StaffMsg::mayOpen(StaffMsg::threadById((int) $tD['id'])) === false);
        $memSetting('counter_desk_isolation', '0', 'bool');
        check('isolation off: clerk C may ask about any ticket (desk books are shared)', (int) StaffMsg::threadFor('booking', ['pnr' => 'SHG-SMSGT-DDD'])['agent_admin_id'] === $ids['ctrC']);
        check('isolation off: still never clerk D\'s own thread', StaffMsg::mayOpen(StaffMsg::threadById((int) $tD['id'])) === false);
        Settings::flush();
    } else {
        skip('desk isolation (bookings.counter_code not installed)');
    }

    /* ------------------------------------------------------------ system lines */
    $before = (int) Database::scalar('SELECT COUNT(*) FROM staff_threads WHERE booking_id = :b', ['b' => $bkB], 0);
    check('system(): no conversation about a ticket -> nothing is created', StaffMsg::system($bkB, 'x') === 0
        && (int) Database::scalar('SELECT COUNT(*) FROM staff_threads WHERE booking_id = :b', ['b' => $bkB], 0) === $before);
    $nSys = StaffMsg::system($bkA, '✅ Payment verified');
    check('system(): lands in the existing ticket conversation', $nSys === 1
        && (string) Database::scalar("SELECT side FROM staff_messages WHERE thread_id = :t ORDER BY id DESC LIMIT 1", ['t' => (int) $tA['id']], '') === 'system');
    check('system(): a system line never reopens a closed conversation', (static function () use ($tA, $bkA, $ids): bool {
        StaffMsg::close((int) $tA['id'], $ids['office'], true);
        StaffMsg::system($bkA, 'Ticket cancelled');
        $s = (string) Database::scalar('SELECT status FROM staff_threads WHERE id = :t', ['t' => (int) $tA['id']], '');
        StaffMsg::close((int) $tA['id'], $ids['office'], false);
        return $s === 'closed';
    })());

    /* ------------------------------------------------------------ push text */
    $pl = StaffMsg::pushPayload(StaffMsg::threadById((int) $tA['id']), 'office');
    $plJ = json_encode($pl, JSON_UNESCAPED_UNICODE);
    check('push: no passenger phone, no message text, links into the panel',
        !str_contains((string) $plJ, '9876501234') && !str_contains((string) $plJ, 'seat changed')
        && str_starts_with($pl['url'], '/admin/staff-chat.php?t=') && str_contains($pl['body'], 'SHG-SMSGT-AAA'));

    /* ------------------------------------------------------------ call buttons */
    $asAdmin($ids['office'], 'support', $PFX . 'office');
    $cs = StaffMsg::contactsFor(StaffMsg::threadById((int) $tA['id']), true);
    $hrefs = array_column($cs, 'href');
    check('office side: tel: and WhatsApp for the agent (+977 from the profile)', in_array('https://wa.me/9779800012345', $hrefs, true) && count(array_filter($hrefs, static fn($h) => str_starts_with($h, 'tel:+'))) === 1);
    $asAdmin($ids['agentA'], 'agent', $PFX . 'agentA');
    $csF = StaffMsg::contactsFor(StaffMsg::threadById((int) $gA['id']), false);
    check('field side: call / WhatsApp the office', count($csF) >= 1 && str_starts_with($csF[0]['href'], 'tel:+') || (count($csF) >= 1 && str_starts_with($csF[0]['href'], 'https://wa.me/')));

    /* ------------------------------------------------------------ broadcast */
    $asAdmin(1, 'superadmin', 'superadmin');
    $ids['__super'] = 0;   // (super-admin is real — its broadcast lines are removed by uuid below)
    $bu = 'smsg-test-bcast-' . bin2hex(random_bytes(3));
    $reached = StaffMsg::broadcast('📣 Test announcement — ignore', 'counters', $ids['office'], $bu);
    $again   = StaffMsg::broadcast('📣 Test announcement — ignore', 'counters', $ids['office'], $bu);
    $gC = (int) Database::scalar("SELECT id FROM staff_threads WHERE uniq_key = :k", ['k' => 'g:' . $ids['ctrC']], 0);
    check('broadcast: reaches every active counter clerk, once per uuid', $reached >= 2 && $again === $reached
        && (int) Database::scalar("SELECT COUNT(*) FROM staff_messages WHERE thread_id = :t AND kind = 'announce'", ['t' => $gC], 0) === 1);
    $asAdmin($ids['ctrC'], 'counter', $PFX . 'ctrC');
    check('broadcast: shows as unread for a clerk', StaffMsg::unreadFor() >= 1);

    /* ------------------------------------------------------------ hooks */
    check('settlement / payout hooks never throw on a missing row', (static function () use ($ids): bool {
        StaffMsg::settlementDisputed(0, $ids['agentA'], 'x');
        StaffMsg::payoutRequested(0, $ids['agentA'], 100.0);
        return true;
    })());

    /* ------------------------------------------------------------ pending-count contract */
    $pc = (string) file_get_contents(ROOT_PATH . '/admin/pending-count.php');
    check('pending-count.php keeps pending / maxBooking / sync / ver', str_contains($pc, "'pending'") && str_contains($pc, "'maxBooking'") && str_contains($pc, "\$out['sync']") && str_contains($pc, "\$out['ver']"));
    check('pending-count.php adds chat inside a try (never breaks the badge)', str_contains($pc, "\$out['chat'] = StaffMsg::pollInfo()") && str_contains($pc, 'catch (Throwable'));
    $ngx = (string) file_get_contents(ROOT_PATH . '/deploy/nginx-shreehariglobal.in.conf');
    $sh  = (string) file_get_contents(ROOT_PATH . '/deploy/nginx-add-deny-rules.sh');
    check('nginx: /uploads/staff-msg/ denied in the repo vhost and the live patch script',
        str_contains($ngx, 'location ^~ /uploads/staff-msg/') && str_contains($sh, 'location ^~ /uploads/staff-msg/'));

    /* ------------------------------------------------------------ HTTP: the real API */
    $base = 'http://localhost:8991';
    $up = @file_get_contents($base . '/admin/pending-count.php', false, stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]));
    if ($up === false) {
        skip('HTTP API checks (no dev server on :8991)');
    } else {
        $sess = static function (int $id, string $role, string $user) use (&$sessFiles): array {
            $sid = 'smsgt' . bin2hex(random_bytes(12));
            $tok = bin2hex(random_bytes(16));
            $data = 'shg_admin|' . serialize(['id' => $id, 'username' => $user, 'full_name' => $user, 'role' => $role, 'permissions' => [],
                    'must_change_pw' => false, 'logged_in_at' => time(), 'last_seen' => time()])
                  . 'shg_csrf|' . serialize($tok) . '_created|' . serialize(time());
            $dir = session_save_path() !== '' ? session_save_path() : sys_get_temp_dir();
            $f = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sid;
            file_put_contents($f, $data);
            $sessFiles[] = $f;
            return [$sid, $tok];
        };
        $http = static function (string $method, string $path, array $s, ?array $json = null, bool $csrf = true) use ($base): array {
            $h = ['Accept: application/json', 'Cookie: ' . SESSION_NAME . '=' . $s[0]];
            if ($csrf) { $h[] = 'X-CSRF-Token: ' . $s[1]; }
            $opt = ['method' => $method, 'header' => '', 'timeout' => 8, 'ignore_errors' => true];
            if ($json !== null) { $h[] = 'Content-Type: application/json'; $opt['content'] = json_encode($json); }
            $opt['header'] = implode("\r\n", $h);
            $body = (string) @file_get_contents($base . $path, false, stream_context_create(['http' => $opt]));
            $code = 0;
            foreach (($http_response_header ?? []) as $hl) { if (preg_match('~^HTTP/\S+\s+(\d{3})~', $hl, $m)) { $code = (int) $m[1]; } }
            return [$code, json_decode($body, true), $body];
        };
        [$c0, $j0] = $http('GET', '/admin/api/staff-msg.php?action=inbox', ['nosuchsession', '']);
        check('API: a guest gets JSON 401, not an HTML login page', $c0 === 401 && is_array($j0));
        $sA = $sess($ids['agentA'], 'agent', $PFX . 'agentA');
        $sB = $sess($ids['agentB'], 'agent', $PFX . 'agentB');
        $sV = $sess($ids['viewer'], 'viewer', $PFX . 'viewer');
        $sO = $sess($ids['office'], 'support', $PFX . 'office');
        [$c1, $j1, $raw1] = $http('GET', '/admin/api/staff-msg.php?action=thread&id=' . (int) $tA['id'], $sA);
        check('API: agent A reads own thread', $c1 === 200 && ($j1['ok'] ?? false) === true && count($j1['messages'] ?? []) >= 2);
        check('API: no stored path in the thread JSON', !str_contains($raw1, 'staff-msg/20') && !str_contains($raw1, 'attach_path'));
        [$c2, $j2] = $http('GET', '/admin/api/staff-msg.php?action=thread&id=' . (int) $tA['id'], $sB);
        [$c2b, $j2b] = $http('GET', '/admin/api/staff-msg.php?action=thread&id=99999999', $sB);
        check('API: agent B gets 404 on agent A\'s thread, same words as a missing one', $c2 === 404 && $c2b === 404 && ($j2['error'] ?? 'a') === ($j2b['error'] ?? 'b'));
        [$c3] = $http('POST', '/admin/api/staff-msg.php', $sB, ['action' => 'send', 'thread_id' => (int) $tA['id'], 'body' => 'intrusion']);
        check('API: agent B cannot post into agent A\'s thread (404)', $c3 === 404
            && (int) Database::scalar("SELECT COUNT(*) FROM staff_messages WHERE thread_id = :t AND body = 'intrusion'", ['t' => (int) $tA['id']], 0) === 0);
        [$c4, $j4] = $http('POST', '/admin/api/staff-msg.php', $sB, ['action' => 'open', 'kind' => 'booking', 'pnr' => 'SHG-SMSGT-AAA']);
        check('API: agent B opening agent A\'s PNR is 404', $c4 === 404);
        [$c5] = $http('GET', '/admin/api/staff-msg.php?action=inbox', $sV);
        check('API: a viewer is refused (403)', $c5 === 403);
        [$c6] = $http('POST', '/admin/api/staff-msg.php', $sA, ['action' => 'send', 'thread_id' => (int) $tA['id'], 'body' => 'no csrf'], false);
        check('API: a POST without the CSRF header is refused (419)', $c6 === 419);
        $uu = 'smsgt-' . bin2hex(random_bytes(8));
        [$c7, $j7] = $http('POST', '/admin/api/staff-msg.php', $sA, ['action' => 'send', 'thread_id' => (int) $tA['id'], 'body' => 'via API', 'client_uuid' => $uu]);
        [$c7b, $j7b] = $http('POST', '/admin/api/staff-msg.php', $sA, ['action' => 'send', 'thread_id' => (int) $tA['id'], 'body' => 'via API', 'client_uuid' => $uu]);
        check('API: send works and a repeated uuid returns the same message', $c7 === 200 && ($j7['ok'] ?? false) && ($j7['message_id'] ?? 0) > 0 && ($j7b['message_id'] ?? -1) === ($j7['message_id'] ?? -2));
        [$c8] = $http('POST', '/admin/api/staff-msg.php', $sA, ['action' => 'close', 'thread_id' => (int) $tA['id']]);
        check('API: a field user cannot close a conversation (403)', $c8 === 403);
        [$c9, $j9] = $http('POST', '/admin/api/staff-msg.php', $sO, ['action' => 'broadcast', 'body' => 'x', 'audience' => 'all']);
        check('API: support (no staff.manage) cannot broadcast (403)', $c9 === 403);
        [$c10, $j10] = $http('GET', '/admin/pending-count.php', $sA);
        check('pending-count: still pending + maxBooking, plus chat {unread,last}', $c10 === 200 && array_key_exists('pending', $j10 ?? []) && array_key_exists('maxBooking', $j10 ?? [])
            && isset($j10['chat']['unread'], $j10['chat']['last']));
        [$c11, , $raw11] = $http('GET', '/admin/staff-msg-file.php?m=' . $mImg, $sB);
        check('file: agent B cannot stream agent A\'s photo (404)', $c11 === 404 && !str_contains($raw11, "\x89PNG"));
        [$c12, , $raw12] = $http('GET', '/admin/staff-msg-file.php?m=' . $mImg, $sO);
        check('file: the office streams it as a PNG', $c12 === 200 && str_starts_with($raw12, "\x89PNG"));
    }
} catch (Throwable $e) {
    check('unexpected exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    try { $cleanup(); } catch (Throwable $e) { echo '  cleanup: ' . $e->getMessage() . "\n"; }
}

echo "\n  $PASS passed, $FAIL failed" . ($SKIP > 0 ? ", $SKIP skipped" : '') . "\n\n";
exit($FAIL > 0 ? 1 : 0);
