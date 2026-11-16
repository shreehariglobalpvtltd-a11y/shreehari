<?php
/**
 * =====================================================================
 *  u8-W3-ai-staff-test.php — Master v2 unit W3 (16 Nov 2026).
 *
 *  P3-3b  AI Watch "Later" (4 h / tomorrow 08:00 IST) and "Ignore":
 *         AiWatch::snooze / state / isHidden / resetState, forOffice()
 *         leaves the hidden cards out, render() gives the buttons (not to
 *         a viewer), and admin/api/ai-suggestion.php (POST only, CSRF,
 *         dashboard.view, JSON for fetch, 303 back to Home for a form)
 *  P3-4b  ai_change_wa_on: one "🤖 <who> changed: …" line to the office
 *         after AiOps::confirm, only when ON, audited, refused lines left
 *         out (the WhatsApp send is caught by AiOps::$waSend — nothing
 *         leaves the machine)
 *  P3-7a/7d ai_settings_engine: 'local' default, 'cloud' read, anything
 *         else = 'local'
 *  P8-10d trip_delay: "<date> 13:00 jane bus 2 ghanta late" → one bus +
 *         passenger count; two buses / no date / no "how late" asked;
 *         switch OFF = said so and refused everywhere; office only;
 *         "Yes" = the trips board's Delay (delay_minutes + the EXISTING
 *         TripNotify::notifyDelay) on a bus with no passengers
 *  Switches: every key of the SWITCH CONTRACT is in AiOps::EASY_KEYS
 *         with a bilingual label, OFF by the code default.
 *
 *  shari_test only. The switches are flipped in THIS process's Settings
 *  cache only (no setting row written). Every row it makes (fixture
 *  schedules on a far 2097 date, one booking, a knob, audit / automation
 *  rows, rate-limit buckets) is deleted, and 'ai_watch_state' is put back
 *  exactly as it was (no row = no row).
 *
 *    php -c .claude/php-dev.ini tests/u8-W3-ai-staff-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child: admin/api/ai-suggestion.php as one staff member ---- */
if (($argv[1] ?? '') === '--child') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $_SERVER['REQUEST_METHOD'] = (string) ($cfg['method'] ?? 'POST');
    $_SERVER['SCRIPT_NAME']    = '/admin/api/ai-suggestion.php';
    $_SERVER['REQUEST_URI']    = '/admin/api/ai-suggestion.php';
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.83';
    if (!empty($cfg['json'])) {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
    }
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if (isset($cfg['role'])) {   // a hand-built session: this role, no extra grant
        $_SESSION[ADMIN_SESSION_KEY]['role'] = (string) $cfg['role'];
        $_SESSION[ADMIN_SESSION_KEY]['permissions'] = [];
    }
    register_shutdown_function(static function (): void { fwrite(STDERR, "\nHTTP " . var_export(http_response_code(), true) . "\n"); });
    $_POST = (array) ($cfg['post'] ?? []);
    if (($cfg['csrf'] ?? 'valid') === 'valid') {
        $_POST[CSRF_TOKEN_NAME] = Security::csrfToken();
    } elseif ($cfg['csrf'] === 'header') {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = Security::csrfToken();
    } else {
        $_POST[CSRF_TOKEN_NAME] = 'not-the-token';
    }
    require dirname(__DIR__) . '/admin/api/ai-suggestion.php';
    exit(0);
}

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/aiwatch.php';
require_once INCLUDE_PATH . '/aiops.php';
require_once INCLUDE_PATH . '/tripnotify.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; } else { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
/** @return array{json: ?array, http: string, raw: string} */
function call(array $cfg): array {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ['json' => null, 'http' => '', 'raw' => 'proc_open failed']; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    preg_match('/HTTP (\S+)\s*$/', $err, $m);
    $j = json_decode(trim($out), true);
    return ['json' => is_array($j) ? $j : null, 'http' => trim((string) ($m[1] ?? ''), "'"), 'raw' => $out . $err];
}
/** Flip settings in THIS process only (the Settings cache) — nothing is written. */
function cacheSet(array $kv): void {
    Settings::get('company_name');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    foreach ($kv as $k => [$v, $t]) {
        $c[$k] = ['value' => $v, 'type' => $t, 'public' => false];
    }
    $rp->setValue(null, $c);
}
function signIn(array $a, ?string $role = null): void {
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => $role ?? (string) $a['role'], 'permissions' => $role !== null ? [] : jsonColumn($a['permissions'] ?? null), 'last_seen' => time()];
}

echo "\n=== W3 AI staff: AI Watch Later / Ignore, AI change WhatsApp, engine, trip delay, switches ===\n\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test — refusing to write\n"; exit(0); }
$superRow = Database::fetch("SELECT * FROM admins WHERE role = 'superadmin' AND is_active = 1 AND must_change_pw = 0 ORDER BY id LIMIT 1");
if ($superRow === null) { echo "  SKIP  no active super-admin\n"; exit(0); }
$super = (int) $superRow['id'];

$tag       = substr(str_shuffle('abcdefghjkmnpqrstvwxyz'), 0, 5);
$startA    = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$startAuto = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM automation_log', [], 0);
$stateRow  = Database::fetch("SELECT * FROM settings WHERE skey = 'ai_watch_state'");
$made      = ['sched' => [], 'book' => [], 'knob' => false];
$cleanup   = static function () use (&$made, $startA, $startAuto, $stateRow, $super): void {
    AiOps::$waSend = null;
    foreach ($made['book'] as $id) {
        Database::delete('booking_passengers', 'booking_id = :b', ['b' => $id]);
        Database::delete('booking_legs', 'booking_id = :b', ['b' => $id]);
        Database::delete('bookings', 'id = :b', ['b' => $id]);
    }
    foreach ($made['sched'] as $sid) {
        Database::delete('automation_log', "id > :s AND (data LIKE :d1 OR dedupe_key LIKE :d2)", ['s' => $startAuto, 'd1' => '%"schedule_id":' . $sid . '%', 'd2' => '%:' . $sid . ':%']);
        Database::delete('audit_logs', "id > :s AND entity_type = 'schedule' AND entity_id = :e", ['s' => $startA, 'e' => (string) $sid]);
        Database::delete('schedules', 'id = :i', ['i' => $sid]);
    }
    if ($made['knob']) {
        Database::delete('settings', "skey = 'zu8w3_knob'");
    }
    // ai_watch_state exactly as it was (no row = no row)
    Database::delete('settings', "skey = 'ai_watch_state'");
    if (is_array($stateRow)) {
        Database::insert('settings', $stateRow);
    }
    Database::delete('audit_logs', "id > :s AND (action LIKE 'ai_watch.%' OR action = 'settings.ai_change_wa' OR entity_id = 'zu8w3_knob'
                                     OR (action IN ('settings.ai_proposed', 'settings.ai_command', 'settings.ai_declined') AND (new_value LIKE '%zu8w3%' OR new_value LIKE '%trip_delay%')))",
        ['s' => $startA]);
    Database::delete('rate_limits', 'bucket = :b AND identifier = :i', ['b' => 'ai_suggestion', 'i' => 'admin:' . $super]);
    Settings::flush();
};
register_shutdown_function($cleanup);

try {
    /* ================= Switch registration (the SWITCH CONTRACT) ================= */
    echo "-- switches\n";
    $contract = ['device_limit_agent', 'device_limit_counter', 'offline_callback_on', 'ai_change_wa_on', 'ai_settings_engine', 'ai_trip_delay_on', 'wa_ai_cancel_mode',
                 'wa_handoff_msg_on', 'wa_typing_on', 'reminder_24h_enabled', 'trip_alert_agents_on', 'wa_global_daily_cap', 'social_publish_on',
                 'group_discount_on', 'group_discount_min_seats', 'group_discount_percent', 'auto_arrive_hours', 'agent_settlement_due_days',
                 'agent_settle_reminder_days', 'wa_good_morning_tpl_on', 'ap_ai_on'];
    $missing = array_values(array_diff($contract, array_keys(AiOps::EASY_KEYS)));
    check('every SWITCH CONTRACT key (and the listed old knobs) is in Easy Settings', $missing === [], implode(', ', $missing));
    $noBi = array_values(array_filter($contract, static fn(string $k): bool => isset(AiOps::EASY_KEYS[$k]) && !str_contains(AiOps::EASY_KEYS[$k][0], ' · ')
        && preg_match('/[\x{0900}-\x{097F}]/u', AiOps::EASY_KEYS[$k][0]) !== 1));
    check('their labels are bilingual (नेपाली · English)', $noBi === [], implode(', ', $noBi));
    // the code default each new key has — read with a cache that does NOT hold them
    Settings::flush();
    Settings::get('company_name');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    foreach ($contract as $k) { unset($c[$k]); }
    $rp->setValue(null, $c);
    $def = [];
    foreach ($contract as $k) { $def[$k] = AiOps::easyCurrent($k); }
    $notOff = array_keys(array_filter($def, static fn(string $v, string $k): bool => !in_array($v, ['0', 'local', 'request'], true)
        && !in_array($k, ['group_discount_min_seats', 'group_discount_percent'], true), ARRAY_FILTER_USE_BOTH));
    check('with no row every new switch reads OFF / 0 / local / request', $notOff === [], implode(', ', $notOff));
    check('group discount knobs read the code defaults (5 seats, 5 %)', $def['group_discount_min_seats'] === '5' && $def['group_discount_percent'] === '5');
    Settings::flush();

    $sw = AiOps::parseSwitches('24 ghanta reminder on karo');
    check('"24 ghanta reminder on karo" → reminder_24h_enabled ON, not the trip-reminder master switch', ($sw['reminder_24h_enabled'] ?? '') === '1' && !isset($sw['trip_reminders_enabled']));
    check('"24 ghanta ko reminder band" / "२४ घण्टा reminder" too', (AiOps::parseSwitches('24 ghanta ko reminder band')['reminder_24h_enabled'] ?? '') === '0'
        && !isset(AiOps::parseSwitches('24 ghanta ko reminder band')['trip_reminders_enabled']) && (AiOps::parseSwitches('२४ घण्टा reminder on')['reminder_24h_enabled'] ?? '') === '1');
    check('"reminders band" is still the master switch', (AiOps::parseSwitches('reminders band')['trip_reminders_enabled'] ?? '') === '0' && !isset(AiOps::parseSwitches('reminders band')['reminder_24h_enabled']));
    check('"device limit agent 2" → device_limit_agent = 2', AiOps::parseSwitches('device limit agent 2') === ['device_limit_agent' => '2']);
    check('"agent ko device limit 3 karo" → 3; "device limit agent 2, counter 1" keeps the agent\'s 2', (AiOps::parseSwitches('agent ko device limit 3 karo')['device_limit_agent'] ?? '') === '3'
        && (AiOps::parseSwitches('device limit agent 2, counter 1')['device_limit_agent'] ?? '') === '2');
    check('"device limit agent 500" is out of range → nothing', AiOps::parseSwitches('device limit agent 500') === []);
    check('"device_limit_agent 2" (the key itself) works', (AiOps::parseSwitches('device_limit_agent 2')['device_limit_agent'] ?? '') === '2');
    check('"whatsapp daily cap 500" / "wa daily limit band" = 500 / 0', (AiOps::parseSwitches('whatsapp daily cap 500')['wa_global_daily_cap'] ?? '') === '500'
        && (AiOps::parseSwitches('wa daily limit band')['wa_global_daily_cap'] ?? '') === '0');
    check('"group discount 10 percent" → %, "group discount 6 seat" → seats, "group discount on" → the switch',
        AiOps::parseSwitches('group discount 10 percent') === ['group_discount_percent' => '10']
        && AiOps::parseSwitches('group discount 6 seat dekhi') === ['group_discount_min_seats' => '6']
        && AiOps::parseSwitches('group discount on karo') === ['group_discount_on' => '1']);
    check('"facebook post band" is social publish, not Messenger; "fb messenger band" still Messenger',
        AiOps::parseSwitches('facebook post band') === ['social_publish_on' => '0'] && AiOps::parseSwitches('fb messenger band') === ['fb_msgr_on' => '0']);
    check('"ai engine cloud karo" / "settings ai local" → ai_settings_engine', AiOps::parseSwitches('ai engine cloud karo') === ['ai_settings_engine' => 'cloud']
        && AiOps::parseSwitches('settings ai local') === ['ai_settings_engine' => 'local']);
    check('"whatsapp cancel self" → wa_ai_cancel_mode; "agent cancel request matra" stays agent_cancel_mode only',
        AiOps::parseSwitches('whatsapp cancel self') === ['wa_ai_cancel_mode' => 'self'] && AiOps::parseSwitches('agent cancel request matra') === ['agent_cancel_mode' => 'request']);
    foreach (['typing on' => 'wa_typing_on', 'handoff message on' => 'wa_handoff_msg_on', 'trip alert agent on' => 'trip_alert_agents_on', 'social publish on' => 'social_publish_on',
              'offline callback on' => 'offline_callback_on', 'ai change whatsapp on' => 'ai_change_wa_on', 'ai trip delay on' => 'ai_trip_delay_on',
              'good morning template on' => 'wa_good_morning_tpl_on', 'vps ai on' => 'ap_ai_on'] as $s => $k) {
        check('"' . $s . '" → ' . $k, AiOps::parseSwitches($s) === [$k => '1']);
    }
    check('"auto arrive 40 ghanta", "settle reminder 3 din", "settlement due 7 din"', AiOps::parseSwitches('auto arrive 40 ghanta') === ['auto_arrive_hours' => '40']
        && AiOps::parseSwitches('settle reminder 3 din') === ['agent_settle_reminder_days' => '3'] && AiOps::parseSwitches('settlement due 7 din') === ['agent_settlement_due_days' => '7']);
    AiOps::registerEasyKeys();
    check('a number knob is range-checked in clean() too (the AI path): 99 devices refused, 2 kept',
        AiOps::clean(['type' => 'setting', 'key' => 'device_limit_agent', 'value' => '99']) === null
        && (AiOps::clean(['type' => 'setting', 'key' => 'device_limit_agent', 'value' => '2'])['value'] ?? '') === '2');
    check('easyShow: 0 = no limit, 500 /day, 10 %', str_contains(AiOps::easyShow('device_limit_agent', '0'), 'no limit') && AiOps::easyShow('wa_global_daily_cap', '500') === '500/day'
        && AiOps::easyShow('group_discount_percent', '10') === '10%');

    /* ================= P3-7a / P3-7d: engine ================= */
    echo "-- settings engine\n";
    cacheSet(['ai_settings_engine' => ['local', 'string']]);
    check('ai_settings_engine local → local', AiOps::settingsEngine() === 'local');
    cacheSet(['ai_settings_engine' => ['cloud', 'string']]);
    check('ai_settings_engine cloud → cloud', AiOps::settingsEngine() === 'cloud');
    cacheSet(['ai_settings_engine' => ['gpt9', 'string']]);
    check('anything else → local', AiOps::settingsEngine() === 'local');
    Settings::flush();
    require_once INCLUDE_PATH . '/aibrain.php';
    require_once INCLUDE_PATH . '/agentpro-ai.php';
    if (!AiBrain::configured() && !ApAi::enabled()) {
        cacheSet(['ai_settings_engine' => ['cloud', 'string']]);
        $t0 = microtime(true);
        $r  = AiOps::parseAi('kuch bhi samajh nahi aaya xyz');
        check('cloud first with no brain configured: falls back quietly, nothing proposed', $r === [] && microtime(true) - $t0 < 5);
        Settings::flush();
    } else {
        echo "  SKIP  an AI brain is configured on this DB — no live AI call from a test\n";
    }

    /* ================= P3-4b: AI change WhatsApp ================= */
    echo "-- AI change WhatsApp\n";
    $me = ['id' => $super, 'full_name' => 'Zu8W3 Office', 'username' => 'zu8w3'];
    check('changeMessage: "🤖 <who> changed: a · b", refused lines left out',
        AiOps::changeMessage($me, ['💰 1 pickup (Surat) → ₹2,300', '⛔ commission: not allowed', '⚠️ fare_delta: out of range', '⚙️ Zu8W3 knob → 9'])
        === '🤖 Zu8W3 Office changed: 💰 1 pickup (Surat) → ₹2,300 · ⚙️ Zu8W3 knob → 9');
    check('nothing changed → no message', AiOps::changeMessage($me, ['⛔ x: not allowed']) === '' && AiOps::changeMessage($me, []) === '');
    $sent = [];
    AiOps::$waSend = static function (string $to, string $msg) use (&$sent): bool { $sent[] = [$to, $msg]; return true; };
    cacheSet(['ai_change_wa_on' => ['0', 'bool'], 'whatsapp_notify_admin' => ['1', 'bool'], 'admin_whatsapp' => ['919990001234', 'string']]);
    check('switch OFF (default): nothing sent', AiOps::notifyChange($me, ['⚙️ Zu8W3 knob → 9']) === false && $sent === []);
    cacheSet(['ai_change_wa_on' => ['1', 'bool'], 'whatsapp_notify_admin' => ['0', 'bool']]);
    check('switch ON but office alerts OFF: nothing sent', AiOps::notifyChange($me, ['⚙️ Zu8W3 knob → 9']) === false && $sent === []);
    cacheSet(['whatsapp_notify_admin' => ['1', 'bool']]);
    check('switch ON: one line to the office number', AiOps::notifyChange($me, ['⚙️ Zu8W3 knob → 9']) === true && count($sent) === 1
        && $sent[0][0] === '919990001234' && $sent[0][1] === '🤖 Zu8W3 Office changed: ⚙️ Zu8W3 knob → 9');
    $au = Database::fetch("SELECT new_value, detail FROM audit_logs WHERE id > :s AND action = 'settings.ai_change_wa' ORDER BY id DESC LIMIT 1", ['s' => $startA]);
    check('the send is audited (settings.ai_change_wa), number masked', $au !== null && str_contains((string) $au['new_value'], '…1234')
        && !str_contains((string) $au['new_value'], '919990001234') && str_contains((string) $au['detail'], 'Zu8W3 knob'));
    check('only refused lines: nothing sent', AiOps::notifyChange($me, ['⛔ route: not allowed']) === false && count($sent) === 1);
    // through AiOps::confirm — the path admin/easy-settings.php act=yes and the API confirm share
    Database::insert('settings', ['skey' => 'zu8w3_knob', 'svalue' => '4', 'stype' => 'int', 'sgroup' => 'general', 'label' => 'Zu8W3 knob', 'is_public' => 0]);
    $made['knob'] = true;
    cacheSet(['zu8w3_knob' => ['4', 'int']]);
    signIn($superRow);
    $sent = [];
    $done = AiOps::confirm(['switches' => [], 'ops' => [['type' => 'setting', 'key' => 'zu8w3_knob', 'value' => '9']], 'cmd' => 'zu8w3_knob 9', 'via' => 'words'], $superRow, true);
    check('confirm() writes and then sends the one line', (string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'zu8w3_knob'", [], '') === '9'
        && count($sent) === 1 && str_starts_with($sent[0][1], '🤖 ') && str_contains($sent[0][1], 'changed: ') && str_contains($sent[0][1], '9'));
    AiOps::$waSend = null;
    Settings::flush();

    /* ================= P8-10d: trip delay ================= */
    echo "-- trip delay\n";
    $go   = Database::fetch("SELECT id FROM routes WHERE is_active = 1 AND to_city = 'Rupaidiha' ORDER BY sort_order, id LIMIT 1");
    $back = Database::fetch("SELECT id FROM routes WHERE is_active = 1 AND from_city = 'Rupaidiha' ORDER BY sort_order, id LIMIT 1");
    $date = '';
    for ($i = 0; $i < 40 && $date === ''; $i++) {
        $d = sprintf('2097-%02d-%02d', random_int(1, 12), random_int(1, 28));
        if (!Database::exists('SELECT 1 FROM schedules WHERE travel_date = :d', ['d' => $d])) { $date = $d; }
    }
    check('delayMinutes: 2 ghanta / 1.5 hours / 90 min / 1 ghanta 30 minat / dedh ghanta / none',
        AiOps::delayMinutes(AiOps::norm('2 ghanta')) === 120 && AiOps::delayMinutes(AiOps::norm('1.5 hours')) === 90 && AiOps::delayMinutes(AiOps::norm('90 min')) === 90
        && AiOps::delayMinutes(AiOps::norm('1 ghanta 30 minat')) === 90 && AiOps::delayMinutes(AiOps::norm('dedh ghanta')) === 90 && AiOps::delayMinutes(AiOps::norm('bus late')) === null);
    check('"late cancel fine 500" is still the fine rule', (AiOps::parseWords('late cancel fine 500')[0]['key'] ?? '') === 'cancel_fine_default');
    cacheSet(['ai_trip_delay_on' => ['0', 'bool']]);
    check('switch OFF: "bholi jane bus 2 ghanta late" → nothing, and the note says the switch is off',
        AiOps::parseWords('bholi jane bus 2 ghanta late') === [] && str_contains(AiOps::lastNote(), 'ai_trip_delay_on'));
    if ($go !== null && $date !== '') {
        $mk = static function (int $route, int $slot, string $time) use (&$made, $date): int {
            $id = (int) Database::insert('schedules', ['route_id' => $route, 'travel_date' => $date, 'slot' => $slot, 'dep_time_override' => $time,
                'total_seats' => 40, 'status' => 'scheduled', 'is_blocked' => 1]);
            $made['sched'][] = $id;
            return $id;
        };
        $sA = $mk((int) $go['id'], 97, '13:00:00');   // going, 1 booking / 2 passengers
        $sB = $mk((int) $go['id'], 96, '18:00:00');   // going, nobody on it
        $sC = $back !== null ? $mk((int) $back['id'], 97, '13:00:00') : 0;
        $bid = (int) Database::insert('bookings', ['pnr' => 'SHG-ZU8W3-' . $tag, 'contact_phone' => '9812345678', 'contact_country_code' => '977',
            'booking_mode' => 'sharing', 'fare_per_seat' => 2500, 'base_total' => 5000, 'total_amount' => 5000, 'status' => 'confirmed', 'source' => 'admin',
            'confirmed_at' => date('Y-m-d H:i:s')]);
        $made['book'][] = $bid;
        $leg = (int) Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sA, 'travel_date' => $date, 'boarding_stop' => 'Surat', 'drop_stop' => 'Rupaidiha',
            'fare_per_seat' => 2500, 'seat_count' => 2, 'leg_total' => 5000]);
        foreach (['L1', 'L2'] as $seat) {
            Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $leg, 'passenger_ref' => 'ZU8' . $tag . $seat, 'seat_no' => $seat,
                'full_name' => 'Zu8 Test ' . $seat, 'gender' => 'Other', 'is_primary' => $seat === 'L1' ? 1 : 0]);
        }
        $day = substr($date, 0, 10);
        check('switch OFF: a dated sentence is refused too, and clean() refuses the action',
            AiOps::parseWords($day . ' 13:00 jane bus 2 ghanta late') === [] && AiOps::clean(['type' => 'trip_delay', 'schedule_id' => $sA, 'date' => $date, 'minutes' => 120]) === null);

        cacheSet(['ai_trip_delay_on' => ['1', 'bool']]);
        check('two buses going that day → asked which one (both listed)', AiOps::parseWords($day . ' jane bus 2 ghanta late') === []
            && str_contains(AiOps::lastNote(), '13:00') && str_contains(AiOps::lastNote(), '18:00'));
        $a = AiOps::parseWords($day . ' 13:00 jane bus 2 ghanta late');
        check('"<date> 13:00 jane bus 2 ghanta late" → that one bus, 120 min', count($a) === 1 && $a[0]['type'] === 'trip_delay' && (int) $a[0]['schedule_id'] === $sA
            && (int) $a[0]['minutes'] === 120);
        $b = AiOps::parseWords($day . ' 18:00 bus 1.5 ghanta late');
        check('"<date> 18:00 bus 1.5 ghanta late" → the 18:00 bus, 90 min', (int) ($b[0]['schedule_id'] ?? 0) === $sB && (int) ($b[0]['minutes'] ?? 0) === 90);
        if ($sC > 0) {
            $c = AiOps::parseWords($day . ' aaune bus 90 min dhilo');
            check('"aaune … dhilo" → the bus coming back', (int) ($c[0]['schedule_id'] ?? 0) === $sC);
        }
        check('no "how late" → asked', AiOps::parseWords($day . ' 13:00 jane bus late') === [] && str_contains(AiOps::lastNote(), 'How late'));
        check('no date → asked', AiOps::parseWords('13:00 jane bus 2 ghanta late') === [] && str_contains(AiOps::lastNote(), 'Which date'));
        check('20 hours → out of range, asked', AiOps::parseWords($day . ' 13:00 jane bus 20 ghanta late') === [] && AiOps::lastNote() !== '');
        $pv = AiOps::preview($a[0]);
        check('preview: the bus and who would be told (1 booking, 2 passengers), on time → 2 h late',
            count($pv) === 1 && str_contains($pv[0][0], '13:00') && str_contains($pv[0][0], '1 booking (2 ') && str_contains($pv[0][1], 'on time') && str_contains($pv[0][2], '2 h late'));
        check('clean() keeps it; a wrong date or 0 minutes is refused', AiOps::clean($a[0]) === $a[0]
            && AiOps::clean(['type' => 'trip_delay', 'schedule_id' => $sA, 'date' => date('Y-m-d', strtotime($date . ' +1 day')), 'minutes' => 120]) === null
            && AiOps::clean(['type' => 'trip_delay', 'schedule_id' => $sA, 'date' => $date, 'minutes' => 0]) === null);

        signIn($superRow, 'counter');
        check('a counter clerk may not (office only)', AiOps::allowed($a[0]) === false);
        signIn($superRow);
        check('the office (super-admin) may', AiOps::allowed($a[0]) === true);
        $pr = AiOps::propose($day . ' 13:00 jane bus 2 ghanta late', true, false);
        check('propose: one op, words, nothing written yet', count($pr['ops']) === 1 && $pr['via'] === 'words' && AiOps::changes($pr) !== []
            && (int) Database::scalar('SELECT delay_minutes FROM schedules WHERE id = :i', ['i' => $sA], 0) === 0);

        // "Yes" on the bus with nobody on it: the trips board's Delay, no passenger to message
        $lines = AiOps::apply([['type' => 'trip_delay', 'schedule_id' => $sB, 'date' => $date, 'minutes' => 90]], $superRow);
        check('apply: delay_minutes saved and TripNotify::notifyDelay ran (no one to tell)', count($lines) === 1 && str_starts_with($lines[0], '⏰')
            && str_contains($lines[0], 'No confirmed passengers') && (int) Database::scalar('SELECT delay_minutes FROM schedules WHERE id = :i', ['i' => $sB], 0) === 90);
        check('audited trip.set_delay with old / new, via S Hari AI', Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'trip.set_delay' AND entity_id = :e
            AND old_value LIKE '%\"delay_minutes\":0%' AND new_value LIKE '%\"delay_minutes\":90%' AND detail LIKE '%S Hari AI%'", ['s' => $startA, 'e' => (string) $sB]));
        cacheSet(['ai_trip_delay_on' => ['0', 'bool']]);
        $lines = AiOps::apply([['type' => 'trip_delay', 'schedule_id' => $sB, 'date' => $date, 'minutes' => 120]], $superRow);
        check('switch turned OFF before "Yes": nothing applied', $lines === [] && (int) Database::scalar('SELECT delay_minutes FROM schedules WHERE id = :i', ['i' => $sB], 0) === 90);
        Database::update('schedules', ['status' => 'departed'], 'id = :i', ['i' => $sA]);
        cacheSet(['ai_trip_delay_on' => ['1', 'bool']]);
        check('a bus that has left: nothing to send, said so', AiOps::parseWords($day . ' 13:00 jane bus 2 ghanta late') === [] && str_contains(AiOps::lastNote(), 'left'));
        unset($_SESSION[ADMIN_SESSION_KEY]);
        Settings::flush();
    } else {
        echo "  SKIP  no active route to Rupaidiha / no free 2097 date for the trip-delay fixtures\n";
    }

    /* ================= P3-3b: AI Watch Later / Ignore ================= */
    echo "-- AI Watch Later / Ignore\n";
    $now = 1794816000;   // a fixed instant, so the test never depends on the hour it runs
    $t8  = (new DateTimeImmutable('@' . AiWatch::laterUntil('tomorrow', $now)))->setTimezone(new DateTimeZone('Asia/Kolkata'));
    $n8  = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Kolkata'));
    check('Later "tomorrow" = the next day 08:00 IST', $t8->format('H:i') === '08:00' && $t8->format('Y-m-d') === $n8->modify('+1 day')->format('Y-m-d'));
    check('Later "4h" = four hours', AiWatch::laterUntil('4h', $now) === $now + 14400);
    $all = static fn(string $p): bool => true;
    Database::delete('settings', "skey = 'ai_watch_state'");
    Settings::flush();
    $list = AiWatch::forOffice($all);
    if (count($list) >= 2) {
        [$c1, $c2] = [$list[0], $list[1]];
        check('office cards carry snooze = true', !empty($c1['snooze']) && !empty($c2['snooze']));
        AiWatch::snooze((string) $c1['key'], '4h', '', $super);
        AiWatch::snooze((string) $c2['key'], 'ignore', AiWatch::sig($c2), $super);
        Settings::flush();
        $keys = array_column(AiWatch::forOffice($all), 'key');
        check('a card put off ("Later") and one ignored are left out', !in_array($c1['key'], $keys, true) && !in_array($c2['key'], $keys, true));
        $st = AiWatch::state();
        check('state: {key: {status, until}} in ai_watch_state', ($st[$c1['key']]['status'] ?? '') === 'later' && ($st[$c2['key']]['status'] ?? '') === 'ignored'
            && (int) $st[$c1['key']]['until'] > time() + 14000);
        check('an ignored card comes back when it says something new', AiWatch::isHidden(['title' => $c2['title'] . ' +1'] + $c2, $st) === false
            && AiWatch::isHidden($c2, $st) === true);
        check('audited ai_watch.later / ai_watch.ignore', Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'ai_watch.later' AND entity_id = :k", ['s' => $startA, 'k' => (string) $c1['key']])
            && Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'ai_watch.ignore' AND entity_id = :k", ['s' => $startA, 'k' => (string) $c2['key']]));
        // an expired entry is pruned
        $raw = Settings::getArray('ai_watch_state', []);
        $raw['zz-old'] = ['status' => 'later', 'until' => time() - 5];
        Settings::set('ai_watch_state', $raw, 'json', 'ai');
        check('expired entries are pruned', !isset(AiWatch::state()['zz-old']));
        // render: Later / Ignore buttons for the office, not for a viewer; "↺ show" when something is hidden
        signIn($superRow);
        AiWatch::forOffice($all);
        $h = AiWatch::render(AiWatch::forOffice($all));
        check('render: Later 4 h / Tomorrow / Ignore buttons with the CSRF field and the card\'s sig', str_contains($h, 'action="/admin/api/ai-suggestion.php"')
            && str_contains($h, 'name="do" value="4h"') && str_contains($h, 'name="do" value="tomorrow"') && str_contains($h, 'name="do" value="ignore"')
            && str_contains($h, 'name="' . CSRF_TOKEN_NAME . '"') && preg_match('/name="sig" value="[a-f0-9]{12}"/', $h) === 1);
        check('render: "↺ 2 hidden — show"', str_contains($h, 'value="reset"') && str_contains($h, '2 लुकाइएको'));
        check('render: the card still only OPENS a staff page', preg_match_all('/class="btn aw-go" href="\/admin\//', $h) === count(AiWatch::forOffice($all)));
        signIn($superRow, 'viewer');
        $hv = AiWatch::render(AiWatch::forOffice($all));
        check('a viewer (read-only) gets no Later / Ignore buttons', !str_contains($hv, 'ai-suggestion.php'));
        unset($_SESSION[ADMIN_SESSION_KEY]);

        // the endpoint
        $r = call(['admin' => $super, 'method' => 'GET', 'json' => true]);
        check('endpoint: GET refused (405)', $r['http'] === '405' && ($r['json']['ok'] ?? true) === false);
        $r = call(['admin' => $super, 'json' => true, 'csrf' => 'bad', 'post' => ['do' => '4h', 'key' => 'pay']]);
        check('endpoint: bad CSRF refused (419), nothing stored', $r['http'] === '419' && !isset(AiWatch::state()['pay']));
        $r = call(['admin' => $super, 'json' => true, 'role' => 'agent', 'post' => ['do' => '4h', 'key' => 'pay']]);
        check('endpoint: a login without dashboard.view refused', in_array($r['http'], ['401', '403'], true) && ($r['json']['ok'] ?? false) !== true && !isset(AiWatch::state()['pay']), $r['http']);
        $r = call(['admin' => $super, 'json' => true, 'csrf' => 'header', 'post' => ['do' => 'tomorrow', 'key' => 'zz-card']]);
        Settings::flush();
        check('endpoint: Later tomorrow (X-CSRF-Token) → JSON ok, stored until 08:00', ($r['json']['ok'] ?? false) === true && ($r['json']['status'] ?? '') === 'later'
            && (new DateTimeImmutable('@' . (int) (AiWatch::state()['zz-card']['until'] ?? 0)))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('H:i') === '08:00', $r['raw']);
        $r = call(['admin' => $super, 'json' => true, 'post' => ['do' => 'ignore', 'key' => 'zz-card2', 'sig' => 'nothex']]);
        check('endpoint: Ignore with a bad sig → 422', $r['http'] === '422');
        $r = call(['admin' => $super, 'json' => true, 'post' => ['do' => 'later', 'key' => 'Bad Key!']]);
        check('endpoint: an unknown choice / bad key → 422', $r['http'] === '422');
        $r = call(['admin' => $super, 'post' => ['do' => '4h', 'key' => 'zz-form']]);
        Settings::flush();
        check('endpoint: a plain form post (no JavaScript) → 303 back to Home, stored', $r['http'] === '303' && isset(AiWatch::state()['zz-form']));
        $r = call(['admin' => $super, 'json' => true, 'post' => ['do' => 'reset']]);
        Settings::flush();
        check('endpoint: "↺ show" brings every card back (reload)', ($r['json']['ok'] ?? false) === true && ($r['json']['reload'] ?? false) === true && AiWatch::state() === []
            && in_array($c1['key'], array_column(AiWatch::forOffice($all), 'key'), true));
        check('audited ai_watch.reset', Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'ai_watch.reset'", ['s' => $startA]));
    } else {
        echo "  SKIP  fewer than two AI Watch cards on this DB\n";
    }
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
}
check('every test row was removed', !Database::exists("SELECT 1 FROM bookings WHERE pnr = :p", ['p' => 'SHG-ZU8W3-' . $tag])
    && !Database::exists("SELECT 1 FROM settings WHERE skey = 'zu8w3_knob'")
    && ($stateRow === null ? !Database::exists("SELECT 1 FROM settings WHERE skey = 'ai_watch_state'") : true));
echo "\n  u8-W3-ai-staff-test: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
