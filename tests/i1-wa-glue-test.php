<?php
/**
 * =====================================================================
 *  i1-wa-glue-test.php — WhatsApp / Messenger glue of 19 Nov 2026 (i1)
 *
 *    A. whatsapp/webhook.php wh_send_flow_reply(): a filled form that comes
 *       back with a bad field ($reply['ui'], kind flow) goes as the form
 *       FIRST (body = ui.body, else the reply text); the picture / text are
 *       only the fall-back when Meta refuses it or the body is over 1024;
 *       any other reply (a seller's sale: ticket picture + 'Next ticket'
 *       buttons, or no ui) goes through wh_send_reply (picture as header)
 *    B. country hints: a bare Nepal 98xxxxxxxx stays +977 in WaInbox::intl,
 *       WaFlow::sendTo (given country / the spelling that wrote to us),
 *       WaAgentRoute::adminNumber, and the WaHabit / WaKeepAlive /
 *       WaBooking (form re-ask) paths use the country they know
 *    C. WaTplMeta::sendNamed(): only an APPROVED template, purpose + meta_tpl
 *       carried, vars in {{n}} order, AgentPro's call shape matches
 *    D. Notify::waAmount(): a Nepal NPR sale reads 'रू X (₹Y)', others '₹Y'
 *    E. Notify::whatsapp purpose 'otp' to a bare Nepal number with no open
 *       window: the record's country (977) and the shg_login_code template
 *    F. Messenger glue: the legacy endpoint passes $reply['ui'];
 *       BookingChannel knows MESSENGER; a sale on the legacy endpoint is
 *       noted as a Messenger sale
 *    G. WaBot language notes (CustomerDirectory::noteLang): customers only
 *
 *  No network: whatsapp/api.php's $GLOBALS['WA_GRAPH_HOOK'], WaRoute::$transport,
 *  WaAuthOtp::$graphHook and WaTplMeta::$sendHook see every send. Settings are
 *  overridden in-process only. The kv_store / message_logs rows written for
 *  the two test numbers are removed at the end (also on a crash).
 *    php -c .claude/php-dev.ini tests/i1-wa-glue-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('META_ACCESS_TOKEN', 'I1_TEST_TOKEN');
define('META_PHONE_NUMBER_ID', '555000111000777');
define('META_WABA_ID', '555000111000666');
define('SHG_APP', true);
define('SHG_WA_INBOUND_REPLAY', true);          // functions only, never the HTTP part
require_once dirname(__DIR__) . '/whatsapp/webhook.php';
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/wainbox.php';
require_once INCLUDE_PATH . '/waflow.php';
require_once INCLUDE_PATH . '/wabooking.php';
require_once INCLUDE_PATH . '/waagentroute.php';
require_once INCLUDE_PATH . '/watplmeta.php';
require_once INCLUDE_PATH . '/waauthotp.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/bookingchannel.php';
require_once INCLUDE_PATH . '/agentpro.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function tset(string $k, mixed $v): void {
    Settings::get('__load__');
    $r = new ReflectionProperty(Settings::class, 'cache');
    $c = (array) $r->getValue();
    $c[$k] = ['value' => is_bool($v) ? ($v ? '1' : '0') : (string) $v, 'type' => is_bool($v) ? 'bool' : 'string', 'public' => false];
    $r->setValue(null, $c);
}

$NP10 = '9867100219';     // a bare Nepal mobile (no account / staff row uses it - checked below)
$IN10 = '9867100231';     // a bare number that only ever wrote as +91
$TPL  = 'shg_withdraw_update';
$tplKeep = [];
foreach (['status.' . $TPL, 'refused.' . $TPL] as $k) {
    $tplKeep[$k] = Database::fetch("SELECT kvalue FROM kv_store WHERE kscope = 'wa_tpl_meta' AND kkey = :k", ['k' => $k]);
}
$cleanup = static function () use ($NP10, $IN10, $tplKeep): void {
    try {
        foreach ([$NP10, $IN10] as $d) {
            Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey IN (:a, :b)", ['a' => '977' . $d, 'b' => '91' . $d]);
            Database::run('DELETE FROM message_logs WHERE to_number LIKE :p', ['p' => '%' . $d]);
            Database::run('DELETE FROM users WHERE phone = :p', ['p' => $d]);   // the otpCountry account row (E)
        }
        foreach ($tplKeep as $k => $row) {
            if ($row === null) {
                Database::run("DELETE FROM kv_store WHERE kscope = 'wa_tpl_meta' AND kkey = :k", ['k' => $k]);
            } else {
                Database::run("UPDATE kv_store SET kvalue = :v WHERE kscope = 'wa_tpl_meta' AND kkey = :k", ['v' => (string) $row['kvalue'], 'k' => $k]);
            }
        }
    } catch (Throwable $e) {
        echo "cleanup: " . $e->getMessage() . "\n";
    }
};
register_shutdown_function($cleanup);
$seen = static function (string $intl, int $at): void {
    Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'i1test')
                   ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)", ['k' => $intl, 'v' => (string) $at]);
};

/* every Graph call (api.php), every WaRoute free send and every auth-template call is captured */
$graph = [];
$refuse = [];               // message types the fake Meta refuses
$GLOBALS['WA_GRAPH_HOOK'] = static function (string $to, array $body, string $type) use (&$graph, &$refuse): array {
    $graph[] = ['to' => $to, 'type' => (string) ($body['type'] ?? $type), 'body' => $body];
    if (in_array((string) ($body['type'] ?? $type), $refuse, true)) {
        return ['success' => false, 'message_id' => '', 'error' => '(#131009) refused in test', 'http' => 400, 'code' => 131009];
    }
    return ['success' => true, 'message_id' => 'wamid.I1' . count($graph), 'error' => '', 'http' => 200];
};
$route = [];
WaRoute::$transport = static function (string $to, string $text, ?string $image = null) use (&$route): array {
    $route[] = ['to' => $to, 'text' => $text];
    return ['success' => true, 'message_id' => 'wamid.I1R' . count($route), 'error' => '', 'http' => 200];
};
$auth = [];
WaAuthOtp::$graphHook = static function (string $m, string $path, ?array $body) use (&$auth): array {
    $auth[] = [$m, $path, $body];
    return ($m === 'POST' && str_contains($path, '/messages'))
        ? ['http' => 200, 'json' => ['messages' => [['id' => 'wamid.I1AUTH']]]]
        : ['http' => 400, 'json' => ['error' => ['message' => 'unexpected in test']]];
};

tset('whatsapp_driver', 'click_to_chat');
tset('whatsapp_default_country', '91');
tset('wa_buttons_on', true);
tset('wa_flow_on', false);
tset('wa_reply_pdf_doc_on', false);

echo "\n=== i1: WhatsApp / Messenger glue ===\n\n";
check('test numbers are not on any account / staff row',
    (int) Database::scalar('SELECT COUNT(*) FROM users WHERE phone LIKE :p', ['p' => '%' . $NP10], 0) === 0
    && (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE phone LIKE :p', ['p' => '%' . $NP10], 0) === 0);
$cleanup();

/* ---------------- A ---------------- */
echo "-- A. filled form with a bad field: the form goes first --\n";
$from = '977' . $NP10;
$ui = ['kind' => 'flow', 'body' => 'मोबाइल नम्बर मिलेन · Mobile number is wrong', 'footer' => '',
       'flow' => ['id' => '123456789', 'token' => 'tok', 'cta' => 'Book', 'screen' => 'BOOK', 'data' => ['mobile' => '98']]];
$graph = []; $refuse = [];
$r = wh_send_flow_reply($from, ['text' => 'Please check the mobile number.', 'media' => null, 'ui' => $ui]);
check('ui reply: sent', !empty($r['success']));
check('ui reply: ONE interactive message, nothing else', count($graph) === 1 && $graph[0]['type'] === 'interactive', implode(',', array_column($graph, 'type')));
check('ui reply: it is the form (flow) with ui.body', ($graph[0]['body']['interactive']['type'] ?? '') === 'flow'
    && ($graph[0]['body']['interactive']['body']['text'] ?? '') === $ui['body']);
check('ui reply: to the +977 sender', $graph[0]['to'] === $from, $graph[0]['to']);

$graph = [];
$r = wh_send_flow_reply($from, ['text' => 'Please check the mobile number.', 'media' => null, 'ui' => ['body' => ''] + $ui]);
check('empty ui.body: the reply text is the body', ($graph[0]['body']['interactive']['body']['text'] ?? '') === 'Please check the mobile number.');

$graph = []; $refuse = ['interactive'];
$r = wh_send_flow_reply($from, ['text' => 'QR below', 'media' => 'https://example.test/pay-image.php?pnr=X', 'ui' => $ui]);
check('form refused: falls back to the picture', array_column($graph, 'type') === ['interactive', 'image'] && !empty($r['success']), implode(',', array_column($graph, 'type')));
$graph = []; $refuse = ['interactive', 'image'];
$r = wh_send_flow_reply($from, ['text' => 'QR below', 'media' => 'https://example.test/pay-image.php?pnr=X', 'ui' => $ui]);
check('form + picture refused: the text goes', array_column($graph, 'type') === ['interactive', 'image', 'text'] && !empty($r['success']));
$graph = []; $refuse = [];
$r = wh_send_flow_reply($from, ['text' => 'Ticket ready', 'media' => 'https://example.test/ticket.png']);
check('no ui: the old picture path, no interactive', array_column($graph, 'type') === ['image']);
$graph = [];
$r = wh_send_flow_reply($from, ['text' => 'Only words', 'media' => null, 'ui' => null]);
check('no ui, no media: text only', array_column($graph, 'type') === ['text']);

/* review fix: a SELLER's form sale (WaBooking::sellAsSeller) = the ticket picture + a
   'Next ticket' buttons ui - the picture must still go (as the buttons' header). */
$sold = ['text' => 'Sold PNR SHG123', 'media' => 'https://example.test/ticket.php?pnr=SHG123&img=1',
         'ui' => ['kind' => 'buttons', 'body' => 'Sold PNR SHG123', 'buttons' => [['id' => 'next', 'title' => 'Next ticket']]]];
$graph = []; $refuse = [];
$r = wh_send_flow_reply($from, $sold);
check('seller sale (media + buttons ui): sent', !empty($r['success']));
check('seller sale: ONE interactive carrying the ticket picture as header',
    count($graph) === 1 && ($graph[0]['body']['interactive']['type'] ?? '') === 'button'
    && ($graph[0]['body']['interactive']['header']['image']['link'] ?? '') === $sold['media'],
    json_encode($graph[0]['body']['interactive']['header'] ?? null));
tset('wa_buttons_on', false);
$graph = [];
$r = wh_send_flow_reply($from, $sold);
check('seller sale, wa_buttons_on = 0: the picture with the caption, no buttons', array_column($graph, 'type') === ['image']
    && ($graph[0]['body']['image']['caption'] ?? '') === 'Sold PNR SHG123');
tset('wa_buttons_on', true);
$graph = []; $refuse = ['interactive'];
$r = wh_send_flow_reply($from, $sold);
check('seller sale, buttons refused: the picture still goes', in_array('image', array_column($graph, 'type'), true) && !empty($r['success']),
    implode(',', array_column($graph, 'type')));
$refuse = [];

/* review fix: a form body over Meta's 1024 characters is not cut - the words go as text */
$long = str_repeat('क', 1100) . "\n\nFIX LINE";
$graph = [];
$r = wh_send_flow_reply($from, ['text' => $long, 'media' => null, 'ui' => ['body' => $long] + $ui]);
check('form body > 1024: no interactive, the full text goes', array_column($graph, 'type') === ['text']
    && str_contains((string) ($graph[0]['body']['text']['body'] ?? ''), 'FIX LINE'), implode(',', array_column($graph, 'type')));
check('webhook: wh_send_flow_reply is not wedged inside wh_send_reply\'s docblock',
    preg_match('#\*/\s*\R/\*\*\s*\R \* The answer to a filled booking FORM#', (string) file_get_contents(ROOT_PATH . '/whatsapp/webhook.php')) !== 1
    && preg_match('#Returns the main send\'s result\.[\s\S]{0,200}?\*/\s*\Rfunction wh_send_reply\(#', (string) file_get_contents(ROOT_PATH . '/whatsapp/webhook.php')) === 1);
$src = (string) file_get_contents(ROOT_PATH . '/whatsapp/webhook.php');
check('webhook: the WaFlow::isReply branch sends through wh_send_flow_reply',
    preg_match('/WaFlow::handleReply\([^;]+;\s*\R\s*\$r = wh_send_flow_reply\(\$from, \$reply\);/', $src) === 1);

/* ---------------- B ---------------- */
echo "-- B. a bare Nepal 98xxxxxxxx never becomes +91 --\n";
check('WaInbox::intl with NP', WaInbox::intl($NP10, 'NP') === '977' . $NP10);
check('WaInbox::intl with 977', WaInbox::intl($NP10, '977') === '977' . $NP10);
check('WaInbox::intl unknown + never wrote = default +91 (as before)', WaInbox::intl($NP10) === '91' . $NP10);
$seen('977' . $NP10, time() - 60);
$seen('91' . $IN10, time() - 60);
check('seenCountry: the +977 spelling wrote', WaInbox::seenCountry($NP10) === '977');
check('seenCountry: the +91 spelling wrote', WaInbox::seenCountry($IN10) === '91');
check('WaInbox::intl takes the spelling that wrote (+977)', WaInbox::intl($NP10) === '977' . $NP10);
check('WaInbox::intl takes the spelling that wrote (+91)', WaInbox::intl($IN10) === '91' . $IN10);
check('a full +91 number is never re-written', WaInbox::intl('91' . $NP10) === '91' . $NP10);

$graph = [];
$r = WaFlow::sendTo($NP10, 'ne', false, 'NP');
check('WaFlow::sendTo(NP): sent to +977', !empty($r['success']) && ($graph[0]['to'] ?? '') === '977' . $NP10, json_encode([$r, $graph[0]['to'] ?? null]));
$graph = [];
$r = WaFlow::sendTo($NP10, 'ne');
check('WaFlow::sendTo(no country): the +977 that wrote to us', ($graph[0]['to'] ?? '') === '977' . $NP10, (string) ($graph[0]['to'] ?? 'none'));
$graph = [];
WaFlow::sendTo($IN10, 'ne');
check('WaFlow::sendTo(no country): an India number stays +91', ($graph[0]['to'] ?? '') === '91' . $IN10, (string) ($graph[0]['to'] ?? 'none'));
$wf = (string) file_get_contents(INCLUDE_PATH . '/waflow.php');
check('WaFlow booking starter checks the window with its hint', str_contains($wf, 'WaRoute::metaWindowOpen($to, $hint)'));

tset('wa_admin_number', $NP10);
check('WaAgentRoute::adminNumber: bare Nepal admin number = +977', WaAgentRoute::adminNumber() === '977' . $NP10, WaAgentRoute::adminNumber());
tset('wa_admin_number', '91' . $IN10);
check('WaAgentRoute::adminNumber: a full +91 stays', WaAgentRoute::adminNumber() === '91' . $IN10);
tset('wa_admin_number', '');

$hab = (string) file_get_contents(INCLUDE_PATH . '/wahabit.php');
check('WaHabit: a bare 10-digit row goes through WaInbox::intl', str_contains($hab, '$intl = WaInbox::intl($intl);'));
$ka = (string) file_get_contents(INCLUDE_PATH . '/wakeepalive.php');
check('WaKeepAlive: office numbers go through WaInbox::intl', str_contains($ka, '$intl = WaInbox::intl($raw);') && !str_contains($ka, '$intl = WaRoute::intl($raw);'));
$wb = (string) file_get_contents(INCLUDE_PATH . '/wabooking.php');
check('WaBooking form re-ask: no form country = the chat\'s own (+977 sender)',
    str_contains($wb, "\$formC = in_array(\$sc, ['NP', '977'], true) || (strlen(\$phoneDigits) >= 13 && str_starts_with(\$phoneDigits, '977')) ? 'NP' : '';"));
check('WaBooking pay button: only a full +91 / +977 number', str_contains($wb, 'only a full +91 / +977 number - never a guessed country'));

/* ---------------- C ---------------- */
echo "-- C. WaTplMeta::sendNamed --\n";
$sent = [];
WaTplMeta::$sendHook = static function (string $phone, string $text, string $country, array $meta) use (&$sent): bool {
    $sent[] = compact('phone', 'text', 'country', 'meta');
    return true;
};
tset('wa_tpl_meta_on', true);
Database::run("DELETE FROM kv_store WHERE kscope = 'wa_tpl_meta' AND kkey IN (:a, :b)", ['a' => 'status.' . $TPL, 'b' => 'refused.' . $TPL]);
check('not approved: false, nothing sent', WaTplMeta::sendNamed($NP10, $TPL, ['Ram', '₹5,000', 'paid', 'UPI 4312'], 'NP') === false && $sent === []);
Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_tpl_meta', :k, :v, 'i1test')
               ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)", ['k' => 'status.' . $TPL, 'v' => json_encode(['status' => 'APPROVED', 'at' => time()])]);
check('approved: ready()', WaTplMeta::ready($TPL));
$ok = WaTplMeta::sendNamed($NP10, $TPL, ['Ram Thapa', '₹5,000', 'paid', 'UPI ref 4312'], 'NP');
$s0 = $sent[0] ?? [];
check('approved: true, one send', $ok === true && count($sent) === 1);
check('purpose is set (withdraw)', ($s0['meta']['purpose'] ?? '') === 'withdraw');
check('meta_tpl names the template, vars in {{n}} order', ($s0['meta']['meta_tpl']['name'] ?? '') === $TPL
    && ($s0['meta']['meta_tpl']['vars'] ?? []) === ['Ram Thapa', '₹5,000', 'paid', 'UPI ref 4312']);
check('the in-window words are the template body filled in', str_contains((string) ($s0['text'] ?? ''), 'Hello Ram Thapa') && str_contains((string) ($s0['text'] ?? ''), 'Note: UPI ref 4312'));
check('country NP is the hint for the bare number', ($s0['country'] ?? '') === 'NP' && ($s0['phone'] ?? '') === $NP10);
$sent = [];
WaTplMeta::sendNamed($NP10, $TPL, ['amount' => '₹100', 'name' => 'Sita', 'status' => 'declined', 'note' => ''], '977');
check('keyed vars + empty note = "-"', ($sent[0]['meta']['meta_tpl']['vars'] ?? []) === ['Sita', '₹100', 'declined', '-'] && ($sent[0]['country'] ?? '') === 'NP');
$sent = [];
check('a template with a picture header is not sent this way', WaTplMeta::sendNamed($NP10, 'shg_ticket_image', ['a'], 'NP') === false && $sent === []);
check('an unknown name: false', WaTplMeta::sendNamed($NP10, 'shg_no_such', [], 'IN') === false);
WaTplMeta::noteRefused($TPL, 132001);
check('just refused by Meta: false (the caller sends its text)', WaTplMeta::sendNamed($NP10, $TPL, ['x', 'y', 'z', 'w'], 'NP') === false);
$m = new ReflectionMethod(WaTplMeta::class, 'sendNamed');
check('signature (string $phone, string $name, array $vars, string $country = IN): bool',
    $m->isStatic() && $m->isPublic() && (string) $m->getReturnType() === 'bool'
    && array_map(static fn($p) => $p->getName() . ':' . $p->getType(), $m->getParameters()) === ['phone:string', 'name:string', 'vars:array', 'country:string']);
$ap = (string) file_get_contents(INCLUDE_PATH . '/agentpro.php');
check("AgentPro::payoutTemplate calls it with 'shg_withdraw_update' + [name, amount, status, detail] + 'NP'|'IN'",
    preg_match("/call_user_func\(\['WaTplMeta', 'sendNamed'\], \(string\) \\\$m\['phone'\], 'shg_withdraw_update',\s*\[\(string\) \\\$m\['name'\], inr\(\\\$amount\), \\\$status, [^\]]+\], \\\$m\['countryCode'\] === '977' \? 'NP' : 'IN'\)/", $ap) === 1);
WaTplMeta::$sendHook = null;
/* review: the real callers (admin/agent.php, agent-360, team, tellPayout) never load
   watplmeta.php - payoutTemplate must load it itself. A FRESH php process with only
   bootstrap + agentpro.php (nothing preloaded): after payoutTemplate, is WaTplMeta there?
   (The fix lives in includes/agentpro.php - another unit's file.) */
$probeFile = tempnam(sys_get_temp_dir(), 'i1probe') . '.php';
file_put_contents($probeFile, '<?php define("SHG_APP", true); require ' . var_export(INCLUDE_PATH . '/bootstrap.php', true) . '; require_once INCLUDE_PATH . "/agentpro.php";'
       . ' $pre = class_exists("WaTplMeta", false) ? 1 : 0; AgentPro::payoutTemplate(0, "paid", 1.0, "");'
       . ' echo "PROBE:" . $pre . (class_exists("WaTplMeta", false) ? 1 : 0);');
$ini = php_ini_loaded_file();
$cmd = escapeshellarg(PHP_BINARY) . ($ini !== false ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg($probeFile) . ' 2>&1';
$out = (string) shell_exec($cmd);
@unlink($probeFile);
@unlink(substr($probeFile, 0, -4));
check('fresh process: AgentPro::payoutTemplate loads WaTplMeta itself (no preload)', str_contains($out, 'PROBE:01'),
    'probe said ' . (preg_match('/PROBE:\d\d/', $out, $pm) === 1 ? $pm[0] : trim(mb_substr($out, 0, 160))) . ' - fix is in includes/agentpro.php (cross-unit)');

/* ---------------- D ---------------- */
echo "-- D. NPR sale in the free-form ticket text --\n";
tset('ticket_npr_primary', true);
$npr = ['total_amount' => 2800, 'fx_currency' => 'NPR', 'fx_total' => 4480, 'fx_rate' => 1.6];
$inrB = ['total_amount' => 2800];
check('Nepal NPR sale: रू 4,480 (₹2,800)', Notify::waAmount($npr) === 'रू 4,480 (₹2,800)', Notify::waAmount($npr));
check('India sale: ₹2,800 as before', Notify::waAmount($inrB) === '₹2,800');
check('same NPR as the printed ticket (Ticket::money)', Ticket::money($npr, 2800.0) === 'NPR 4,480 (INR 2,800)');
tset('ticket_npr_primary', false);
check('ticket_npr_primary = 0: ₹ only', Notify::waAmount($npr) === '₹2,800');
tset('ticket_npr_primary', true);
$nsrc = (string) file_get_contents(INCLUDE_PATH . '/notify.php');
check('the free-form ticket / changed / paid texts use waAmount', substr_count($nsrc, '"जम्मा: " . self::waAmount($booking)') >= 3);
check('template variables are not built from waAmount', preg_match('/function ticketTemplateVars[\s\S]{0,4000}?waAmount/', $nsrc) !== 1);

/* ---------------- E ---------------- */
echo "-- E. login code to a bare Nepal number with no open window --\n";
Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey IN (:a, :b)", ['a' => '977' . $NP10, 'b' => '91' . $NP10]);
check('no record: otpCountry = null (default, as before)', Notify::otpCountry($NP10) === null);
$seen('977' . $NP10, time() - 3 * 86400);      // wrote as +977 three days ago: window CLOSED
check('wrote as +977: otpCountry = NP', Notify::otpCountry($NP10) === 'NP');
check('window is closed', !WaRoute::metaWindowOpen('977' . $NP10));
tset('wa_auth_otp_on', true);
tset('wa_auth_tpl_status', 'APPROVED');
tset('wa_auth_tpl_manual', true);
tset('wa_auth_trust_sync', false);
check('auth template ready (stubbed Meta)', WaAuthOtp::ready());
$auth = []; $route = []; $graph = [];
$jFrom = count(Notify::whatsappJournal());
$res = Notify::whatsapp($NP10, "S Hari लगइन कोड: 482611\nLogin code: 482611", null, null, [], null, ['purpose' => 'otp']);
$posted = $auth[0][2] ?? [];
check('sent', $res === true, var_export($res, true));
check('on the shg_login_code auth template', ($posted['template']['name'] ?? '') === WaAuthOtp::NAME && ($posted['template']['components'][0]['parameters'][0]['text'] ?? '') === '482611');
check('to +977, never +91', ($posted['to'] ?? '') === '977' . $NP10, (string) ($posted['to'] ?? 'none'));
$j = array_slice(Notify::whatsappJournal(), $jFrom);
check('journal: meta_auth_template', ($j[count($j) - 1]['reason'] ?? '') === 'meta_auth_template');
check('no free text was tried on a closed window', $route === []);
$auth = [];
tset('wa_auth_tpl_status', 'PENDING');
$res = Notify::whatsapp($NP10, "Login code: 482611", null, null, [], null, ['purpose' => 'otp']);
check('template not approved: the auth template is not used', $auth === []);
tset('wa_auth_tpl_status', 'APPROVED');
$auth = [];
$res = Notify::whatsapp($NP10, "Login code: 482611", null, 'IN', [], null, ['purpose' => 'otp']);
check('an explicit IN hint still wins', ($auth[0][2]['to'] ?? '') === '91' . $NP10);
/* review: a customer account that kept users.country_code's DEFAULT '91' must not beat
   the +977 spelling that actually wrote to us. */
$uid = 0;
try {
    Database::run('INSERT INTO users (phone) VALUES (:p)', ['p' => $NP10]);
    $uid = (int) Database::scalar('SELECT id FROM users WHERE phone = :p', ['p' => $NP10], 0);
} catch (Throwable $e) {
    echo "  (users insert: " . $e->getMessage() . ")\n";
}
check('test account row created with the column default', $uid > 0
    && (string) Database::scalar('SELECT country_code FROM users WHERE id = :i', ['i' => $uid], '') === '91');
check('account default 91 + only +977 wrote: otpCountry = NP', Notify::otpCountry($NP10) === 'NP', var_export(Notify::otpCountry($NP10), true));
Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey IN (:a, :b)", ['a' => '977' . $NP10, 'b' => '91' . $NP10]);
check('account only (nobody wrote): the account answers (IN), as before', Notify::otpCountry($NP10) === 'IN');
if ($uid > 0) {
    Database::run('DELETE FROM users WHERE id = :i', ['i' => $uid]);
}

/* ---------------- F ---------------- */
echo "-- F. Messenger glue --\n";
$ms = (string) file_get_contents(ROOT_PATH . '/messenger/webhook.php');
check('legacy Messenger endpoint passes $reply[ui] to Messenger::send', str_contains($ms, "is_array(\$reply['ui'] ?? null) ? \$reply['ui'] : null"));
require_once INCLUDE_PATH . '/messenger.php';
check('Messenger::send takes a 4th ?array $ui', (new ReflectionMethod('Messenger', 'send'))->getNumberOfParameters() >= 4);
check('BookingChannel knows MESSENGER', in_array('MESSENGER', BookingChannel::TAGS, true) && isset(BookingChannel::META['MESSENGER']) && BookingChannel::FILTERS['messenger'] === 'MESSENGER');
check('markChatPnr(WHATSAPP) = markWhatsappPnr; unknown PNR = false', BookingChannel::markChatPnr('SHG-NO-SUCH-I1', 'MESSENGER') === false);
check('WaBooking tags a Messenger sale MESSENGER, falls back to WHATSAPP', str_contains($wb, "BookingChannel::markChatPnr(\$pnr, \$chatTag, self::\$saleNote)") && str_contains($wb, 'never left untagged'));
check('legacy Messenger endpoint sets the Messenger sale note around WaBot::reply and restores it',
    preg_match("/WaBooking::\\\$saleNote = 'sold by the Facebook Messenger assistant';\s*try \{\s*\\\$reply = WaBot::reply\(\\\$psid, \\\$text, 'text', \[\]\);\s*\} finally \{\s*WaBooking::\\\$saleNote = \\\$saleNoteWas;/", $ms) === 1);
check('the note is one WaBooking reads as Messenger', stripos('sold by the Facebook Messenger assistant', 'messenger') !== false);

/* ---------------- G ---------------- */
echo "-- G. language notes: customer text only --\n";
$wbot = (string) file_get_contents(INCLUDE_PATH . '/wabot.php');
check('WaBot noteLang runs only when whoIs is not staff / admin',
    preg_match("/\\\$langWho = AiTools::whoIs\(\\\$from\);[\s\S]{0,200}?!in_array\(\(string\) \(\\\$langWho\['role'\] \?\? 'customer'\), \['staff', 'admin'\], true\)\)[\s\S]{0,300}?CustomerDirectory::noteLang\(/", $wbot) === 1);
$langAdmin = (int) Database::scalar("SELECT id FROM admins WHERE is_active = 1 AND phone IS NOT NULL AND phone <> '' AND role IN ('agent','counter','manager','superadmin') LIMIT 1", [], 0);
if ($langAdmin > 0) {
    $lp = (string) Database::scalar('SELECT phone FROM admins WHERE id = :i', ['i' => $langAdmin], '');
    require_once INCLUDE_PATH . '/aitools.php';
    check('a staff / agent number is not a customer for whoIs', in_array((string) (AiTools::whoIs($lp)['role'] ?? ''), ['staff', 'admin'], true));
}
check('a stranger is a customer for whoIs', (string) (AiTools::whoIs('977' . $NP10)['role'] ?? '') === 'customer');

$cleanup();
echo "\n" . ($FAIL === 0 ? "\033[32m" : "\033[31m") . "i1-wa-glue: $PASS passed, $FAIL failed\033[0m\n";
exit($FAIL === 0 ? 0 : 1);
