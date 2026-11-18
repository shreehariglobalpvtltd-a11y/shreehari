<?php
/**
 * =====================================================================
 *  i4-easy-settings-test.php — 19 Nov 2026 (integrator i4): the
 *  "🆕 New settings" card on admin/easy-settings.php.
 *
 *    - every 19 Nov setting the units asked for is on the page, in its
 *      group, with the hints the owner asked for (login_mobile_first_on
 *      blocked until shg_login_code is live, wa_flow_cron_recreate off/on
 *      meaning, fb_msgr_form_on needs fb_msgr_sell, driver_list_phones)
 *    - act=nset flips a switch, saves a number in range and refuses one
 *      outside it, takes '{}' for the sales clock and refuses broken JSON,
 *      takes only known roles, refuses a map link with no pin, and never
 *      writes a key outside the card; every save is audited settings.easy
 *    - a page with no CSRF token writes nothing
 *
 *  shari_test only. Every row it touches is put back exactly (no row =
 *  no row) and its audit rows are deleted.
 *
 *    php -c .claude/php-dev.ini tests/i4-easy-settings-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

if (($argv[1] ?? '') === '--child') {
    $cfg  = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $post = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    $_SERVER['SCRIPT_NAME']    = '/admin/easy-settings.php';
    $_SERVER['REQUEST_URI']    = '/admin/easy-settings.php';
    $_SERVER['HTTP_HOST']      = 'localhost';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if ($post !== []) {
        $_POST = $post;
        if (empty($cfg['no_csrf'])) {
            $_POST[CSRF_TOKEN_NAME] = Security::csrfToken();
        }
    }
    require dirname(__DIR__) . '/admin/easy-settings.php';
    exit(0);
}

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; } else { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function page(array $cfg): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out;
}

echo "\n=== i4 easy-settings: the 19 Nov settings card ===\n\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n0 passed, 0 failed\n"; exit(0); }
$super = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 AND must_change_pw = 0 ORDER BY id LIMIT 1", [], 0);
if ($super <= 0) { echo "  SKIP  no super-admin\n0 passed, 0 failed\n"; exit(0); }

$TOUCH   = ['driver_list_phones', 'driver_list_min_pax', 'sales_close_clock', 'login_remember_roles', 'nepal_office_map', 'ap_vc_back_qr', 'quick_unlock_on', 'zi4_es_outside',
            'ai_review_usd', 'ai_review_month_usd', 'counter_day_report_numbers', 'wa_flow_on'];
$startId = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$was     = [];
foreach ($TOUCH as $k) { $was[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]); }
$cleanup = static function () use ($TOUCH, $was, $startId): void {
    foreach ($was as $k => $row) {
        Database::delete('settings', 'skey = :k', ['k' => $k]);
        if (is_array($row)) { Database::insert('settings', $row); }
    }
    $in = implode(',', array_map(static fn(string $k): string => Database::pdo()->quote($k), $TOUCH));
    Database::delete('audit_logs', "id > :s AND action = 'settings.easy' AND entity_id IN ($in)", ['s' => $startId]);
};
register_shutdown_function($cleanup);
$val   = static fn(string $k): ?string => ($r = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k])) === null ? null : (string) $r['svalue'];
$audit = static fn(string $k): bool => Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.easy' AND entity_id = :k", ['s' => $startId, 'k' => $k]);

try {
    echo "1. The card and its settings\n";
    $h = page(['admin' => $super]);
    check('page renders with the new card', str_contains($h, 'id="es-new"') && str_contains($h, 'name="act" value="nset"'));
    check('no PHP warning / error in the page', !preg_match('/(<b>(Warning|Notice|Fatal error|Deprecated)<\/b>|PHP (Warning|Notice|Fatal|Deprecated)|(Warning|Notice|Deprecated): .* on line \d|Application error)/', $h),
        (preg_match('/.{0,120}(Warning|Notice|Fatal error|Deprecated).{0,160}/', $h, $m) === 1 ? $m[0] : ''));
    $want = [
        // u1
        'wa_queue_meta_on', 'wa_ticket_alt_on', 'wa_pay_cta_on', 'wa_tpl_auto_submit', 'wa_tpl_meta_on', 'wa_tpl_refused_hours', 'wa_tpl_sync_minutes', 'wa_reply_pdf_doc_on', 'wa_stmt_pdf_doc_on', 'wa_auth_trust_sync', 'whatsapp_app_id',
        // u2
        'wa_flow_tpl_category', 'fb_msgr_form_on', 'wa_flow_cron_recreate', 'wa_flow_on',
        // u3
        'ticket_pay_qr_upi', 'ticket_verify_qr', 'ticket_npr_primary', 'nepal_office_map', 'india_hub_map', 'ticket_maps_qr',
        // u4
        'sales_close_clock',
        // u5
        'staff_commission_off', 'staff_commission_counter_staff_off', 'agent_payout_verify_required', 'ap_card_nepal_first', 'np_agent_desk_on', 'np_agent_default_desk',
        // u6
        'ap_id_front_style', 'ap_vc_style', 'ap_vc_back_qr', 'ap_card_route_np', 'ap_card_route_in', 'ap_card_cache', 'ap_card_prerender', 'ap_card_harfbuzz',
        'ap_card_prerender_print', 'ap_card_bilingual', 'ap_card_signature_np', 'ap_card_classic',
        // u7
        'login_mobile_first_on', 'login_remember_days', 'login_remember_roles', 'login_remember_office_hours', 'login_phone_id_on', 'customer_remember_days',
        // u8
        'ai_review_on', 'ai_review_hour', 'ai_review_model', 'ai_review_usd', 'ai_review_month_usd', 'ai_review_max_tokens', 'ai_review_autolearn', 'ai_review_in_digest',
        'ai_teach_max_tokens', 'ai_staff_provider', 'ai_staff_model', 'mk_default_lang', 'mk_auto_langs', 'stop_learn_on', 'stop_learn_trips', 'stop_learn_min', 'eta_learned_on',
        // u9
        'pay_upi_app_buttons', 'pay_app_chooser_on', 'pay_claim_screenshot_on', 'wa_pay_dup_utr_block', 'wa_office_words_on', 'wa_customer_words_on', 'wa_find_resend_on',
        'wa_ticket_word_link_on', 'search_low_seats_at',
        // u10
        'chalani_auto_desk_currency', 'counter_day_close_on', 'counter_day_report_on', 'counter_day_report_time', 'counter_day_report_numbers', 'office_report_email_fallback',
        'driver_list_on', 'driver_list_min_pax', 'driver_list_step', 'driver_list_final_hours', 'driver_list_window_hours', 'driver_list_phones',
    ];
    $missing = array_values(array_filter($want, static fn(string $k): bool => !str_contains($h, 'data-esn="' . $k . '"')));
    check(count($want) . ' new settings are on the card', $missing === [], implode(', ', $missing));
    $gOk = true;
    foreach (['💬 ह्वाट्सएप · WhatsApp', '🎫 टिकट · Ticket', '🇳🇵 नेपाल / काउन्टर · Nepal / Desk', '🤝 एजेन्ट · Agents', '🪪 कार्ड · Cards', '🔐 लगइन · Login',
              '🧠 एआई · AI', '💳 भुक्तानी / खोज · Payments / Search', '🧾 चलानी / रिपोर्ट · Chalani / Reports'] as $g) {
        if (!str_contains($h, '<summary>' . Security::e($g))) { $gOk = false; check("group $g is shown", false); }
    }
    check('the nine groups are shown, titles Nepali · English', $gOk && substr_count($h, '<details class="esn-g">') === 9);
    // every label on the card: "<Nepali> · <English>" (a Devanagari letter before the " · ")
    preg_match_all('/<form method="post" class="num" data-esn="([a-z0-9_]+)">.*?<span>(.*?)<\/span>/s', $h, $lm, PREG_SET_ORDER);
    $noNe = [];
    foreach ($lm as $m) {
        $lbl = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        $pos = mb_strpos($lbl, ' · ');
        if ($pos === false || preg_match('/\p{Devanagari}/u', mb_substr($lbl, 0, $pos)) !== 1) { $noNe[] = $m[1]; }
    }
    check(count($lm) . ' labels read, every one Nepali · English', count($lm) >= count($want) && $noNe === [], implode(', ', $noNe));
    check('the two review money caps stop at the AI Control Center limits (10 / 300)',
        (bool) preg_match('/id="esx-ai_review_usd"[^>]*max="10"/', $h) && (bool) preg_match('/id="esx-ai_review_month_usd"[^>]*max="300"/', $h));
    check('wa_flow_on links to wa-form.php to switch ON', (bool) preg_match('/data-esn="wa_flow_on".{0,1200}href="wa-form\.php"/s', $h));
    check('login_mobile_first_on says it stays blocked until shg_login_code is live', (bool) preg_match('/data-esn="login_mobile_first_on".{0,900}shg_login_code/s', $h));
    check('wa_flow_cron_recreate: off = only drop / on = creates + publishes (Meta write)', str_contains($h, 'off = only drop the old form; on = the cron creates and publishes a new one (Meta write)'));
    check('fb_msgr_form_on says it needs fb_msgr_sell on', (bool) preg_match('/data-esn="fb_msgr_form_on".{0,900}Needs fb_msgr_sell on/s', $h));
    check('driver_list_phones: on = driver also gets the chalani picture with phones', str_contains($h, 'on = the driver also gets the chalani picture with passenger phones'));
    check("sales_close_clock explains '{}' = off", (bool) preg_match('/data-esn="sales_close_clock".{0,1200}\{\} = off/s', $h));
    check('a never-saved switch shows its code default (driver_list_phones OFF when no row)',
        $was['driver_list_phones'] !== null || (bool) preg_match('/data-esn="driver_list_phones".{0,700}pill off/s', $h));

    echo "\n2. Saves: CSRF, ranges, JSON, lists, maps, audit\n";
    $p0 = $val('driver_list_phones');
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'driver_list_phones'], 'no_csrf' => true]);
    check('no CSRF token: nothing written', $val('driver_list_phones') === $p0 && !$audit('driver_list_phones'));
    $cur = Settings::getBool('driver_list_phones', false);
    $h = page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'driver_list_phones']]);
    check('a switch flips and is audited settings.easy', $val('driver_list_phones') === ($cur ? '0' : '1') && $audit('driver_list_phones') && str_contains($h, '✅'));

    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'driver_list_min_pax', 'value' => '500']]);
    check('a number outside its range (500 > 60) is refused', $val('driver_list_min_pax') === ($was['driver_list_min_pax']['svalue'] ?? null));
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'driver_list_min_pax', 'value' => '7']]);
    check('a number in range is saved as int', $val('driver_list_min_pax') === '7'
        && (string) Database::scalar("SELECT stype FROM settings WHERE skey = 'driver_list_min_pax'", [], '') === 'int');

    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'sales_close_clock', 'value' => '{"r7":{"all":"17:00"']]);
    check('broken JSON for the sales clock is refused', $val('sales_close_clock') === ($was['sales_close_clock']['svalue'] ?? null));
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'sales_close_clock', 'value' => '["17:00"]']]);
    check('a JSON list (not an object) is refused', $val('sales_close_clock') === ($was['sales_close_clock']['svalue'] ?? null));
    $h = page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'sales_close_clock', 'value' => '{}']]);
    require_once INCLUDE_PATH . '/boarding.php';
    Settings::flush();
    check("'{}' switches the clock off (Boarding::salesCloseClock() is empty)", Boarding::salesCloseClock() === [] && $audit('sales_close_clock'));
    check("…and the box shows {} again", (bool) preg_match('/data-esn="sales_close_clock".{0,1500}<textarea[^>]*>\{\}<\/textarea>/s', page(['admin' => $super])));

    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'login_remember_roles', 'value' => 'agent, superadmin']]);
    check('a role outside the list (superadmin) is refused', $val('login_remember_roles') === ($was['login_remember_roles']['svalue'] ?? null));
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'login_remember_roles', 'value' => 'agent, counter']]);
    Settings::flush();
    check('field roles are saved as a JSON list', Settings::getArray('login_remember_roles', []) === ['agent', 'counter']);

    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'nepal_office_map', 'value' => 'Hotel Darbar']]);
    check('a map value that is not a link or "lat, lng" is refused', $val('nepal_office_map') === ($was['nepal_office_map']['svalue'] ?? null));
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'nepal_office_map', 'value' => '28.0507, 81.6159']]);
    check('"lat, lng" is taken', $val('nepal_office_map') === '28.0507, 81.6159');

    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'ap_vc_back_qr', 'value' => 'evil']]);
    check('a choice outside the list is refused', $val('ap_vc_back_qr') === ($was['ap_vc_back_qr']['svalue'] ?? null));
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'ap_vc_back_qr', 'value' => 'webref']]);
    check('a listed choice is saved', $val('ap_vc_back_qr') === 'webref');

    echo "\n3. Review money caps, report numbers, WhatsApp form\n";
    $u0 = $val('ai_review_usd');
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'ai_review_usd', 'value' => '50']]);
    check('review cap per night 50 is refused (AI Control Center caps it at 10)', $val('ai_review_usd') === $u0);
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'ai_review_usd', 'value' => '10']]);
    check('review cap per night 10 is saved', $val('ai_review_usd') === '10');
    $m0 = $val('ai_review_month_usd');
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'ai_review_month_usd', 'value' => '301']]);
    check('review cap per month 301 is refused (limit 300)', $val('ai_review_month_usd') === $m0);
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'ai_review_month_usd', 'value' => '299.50']]);
    check('review cap per month 299.50 is saved', $val('ai_review_month_usd') === '299.50');

    $n0 = $val('counter_day_report_numbers');
    foreach (['9812345678' => 'a bare Nepal mobile (would go to +91)', '9876543210' => 'a bare India mobile', '919812345678, 98123' => 'one good and one short number',
              '+977 98123 4567' => 'a Nepal number one digit short', '12345678901234' => 'no 91 / 977 prefix'] as $bad => $why) {
        $h = page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'counter_day_report_numbers', 'value' => $bad]]);
        check("report numbers: $why is refused", $val('counter_day_report_numbers') === $n0 && str_contains($h, 'Country code required'));
    }
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'counter_day_report_numbers', 'value' => "+977 981-234-5678; +91 98765 43210\n919812345679 9779712345678, +9779812345678"]]);
    check('report numbers: + and spaces taken, stored clean and once each',
        $val('counter_day_report_numbers') === '9779812345678,919876543210,919812345679,9779712345678', (string) $val('counter_day_report_numbers'));
    if (!class_exists('OfficeReport')) { require_once INCLUDE_PATH . '/officereport.php'; }
    Settings::flush();
    $cdn = OfficeReport::counterDayNumbers();
    check('OfficeReport::counterDayNumbers() gets the 977 number with its country code', in_array('9779812345678', $cdn, true) && !in_array('9812345678', $cdn, true));
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'counter_day_report_numbers', 'value' => '']]);
    check('report numbers: blank = none', $val('counter_day_report_numbers') === '');

    Settings::set('wa_flow_on', '0', 'bool', 'whatsapp');
    $h = page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'wa_flow_on']]);
    check('wa_flow_on cannot be switched ON here (wa-form.php checks Meta first)', $val('wa_flow_on') === '0' && str_contains($h, 'can only be switched ON on wa-form.php')
        && !$audit('wa_flow_on'));
    Settings::set('wa_flow_on', '1', 'bool', 'whatsapp');
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'wa_flow_on']]);
    check('…but it can be switched OFF here, audited', $val('wa_flow_on') === '0' && $audit('wa_flow_on'));

    $q0 = $val('quick_unlock_on');
    $h = page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'quick_unlock_on']]);
    check('act=nset never writes a key outside the card (quick_unlock_on)', $val('quick_unlock_on') === $q0 && str_contains($h, 'not on this page'));
    page(['admin' => $super, 'post' => ['act' => 'nset', 'key' => 'zi4_es_outside', 'value' => '1']]);
    check('…nor an unknown key', $val('zi4_es_outside') === null);
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    $cleanup();
}
$back = true;
foreach ($was as $k => $row) {
    if (($row === null) !== ($val($k) === null) || ($row !== null && (string) $row['svalue'] !== $val($k))) { $back = false; echo "        not restored: $k\n"; }
}
check('every touched row is back as it was', $back);
echo "\n  i4-easy-settings-test: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
