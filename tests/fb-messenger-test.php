<?php
/**
 * Offline tests for includes/fbmessenger.php (1 Nov 2026): no DB, no
 * Facebook, no model, no WhatsApp. The Graph API, the assistant and the
 * code sender are replaced through FbMessenger's test seams.
 *
 *    php tests/fb-messenger-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
define('SHG_APP', true);
define('APP_URL', 'https://www.shreehariglobal.in');
define('APP_NAME', 'Shree Hari Global');
define('OTP_LENGTH', 6);

final class Settings
{
    public static array $v = [];
    public static function getBool(string $k, bool $d = false): bool { return array_key_exists($k, self::$v) ? (bool) self::$v[$k] : $d; }
    public static function getString(string $k, string $d = ''): string { return (string) (self::$v[$k] ?? $d); }
    public static function getInt(string $k, int $d = 0): int { return (int) (self::$v[$k] ?? $d); }
    public static function set(string $k, mixed $val, string $t = 'string', string $g = 'general', bool $p = false): void { self::$v[$k] = $val; }
    public static function officeWhatsApp(): string { return '+91 98000 00000'; }
    public static function officePhone(): string { return '+91 98000 00000'; }
}
final class Logger
{
    public static array $lines = [];
    public static function __callStatic(string $n, array $a): void { self::$lines[] = $n . ': ' . (is_string($a[0] ?? null) ? $a[0] : ''); }
}
final class Security
{
    public static bool $allow = true;
    public static function rateLimit(string $b, string $k, int $max, int $win): bool { return self::$allow; }
    public static function isValidPhone(string $p, bool $staff = false): bool { return preg_match('/^[6-9]\d{9}$/', $p) === 1; }
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/fbmessenger.php';

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks; $checks++;
    if (!$condition) { throw new RuntimeException('FAIL ' . $label); }
    echo "PASS $label\n";
}

/* ---- seams ---- */
$sent = [];
$failImage = false;
FbMessenger::$transport = static function (string $m, string $path, array $body) use (&$sent, &$failImage): array {
    $sent[] = ['m' => $m, 'path' => $path, 'body' => $body];
    if ($failImage && (($body['message']['attachment']['type'] ?? '') === 'image')) {
        return ['ok' => false, 'http' => 400, 'body' => '{"error":{"message":"bad url"}}'];
    }
    if ($path === 'me?fields=id,name') { return ['ok' => true, 'http' => 200, 'body' => '{"id":"PAGE1","name":"Shree Hari Global"}']; }
    if ($path === 'me/subscribed_apps') { return ['ok' => true, 'http' => 200, 'body' => '{"success":true}']; }
    if ($path === 'me/messenger_profile') { return ['ok' => true, 'http' => 200, 'body' => '{"result":"success"}']; }
    return ['ok' => true, 'http' => 200, 'body' => '{"recipient_id":"1","message_id":"m"}'];
};
$asked = [];
$brainOut = null;
FbMessenger::$brain = static function (array $ctx, string $text) use (&$asked, &$brainOut): ?array {
    $asked[] = ['ctx' => $ctx, 'text' => $text];
    return $brainOut;
};
$codes = [];
FbMessenger::$issueCode = static function (string $phone, string $country) use (&$codes): array {
    $codes[] = [$phone, $country];
    return ['ok' => true, 'error' => ''];
};
FbMessenger::$checkCode = static fn(string $phone, string $code): array
    => $code === '123456' ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'That code is incorrect.'];
FbMessenger::$memStore = [];

$texts = static function () use (&$sent): array {
    $out = [];
    foreach ($sent as $s) {
        if (isset($s['body']['message']['text'])) { $out[] = $s['body']['message']['text']; }
    }
    return $out;
};
$ev = static function (string $text, string $mid, string $payload = '', string $psid = 'U1', bool $att = false): array {
    return ['psid' => $psid, 'page' => 'PAGE1', 'text' => $text, 'payload' => $payload, 'mid' => $mid, 'attachment' => $att];
};

/* ---- 1. webhook checks ---- */
Settings::$v = ['fb_verify_token' => 'shg-abc', 'fb_app_secret' => 'sekret123'];
check(FbMessenger::verifyChallenge(['hub_mode' => 'subscribe', 'hub_verify_token' => 'shg-abc', 'hub_challenge' => '98765']) === '98765', 'set-up check echoes the challenge');
check(FbMessenger::verifyChallenge(['hub_mode' => 'subscribe', 'hub_verify_token' => 'wrong', 'hub_challenge' => '98765']) === null, 'wrong verify token refused');
check(FbMessenger::verifyChallenge(['hub_mode' => 'subscribe', 'hub_verify_token' => 'shg-abc', 'hub_challenge' => '<script>']) === null, 'odd challenge never echoed');
$raw = '{"object":"page"}';
check(FbMessenger::signatureOk($raw, 'sha256=' . hash_hmac('sha256', $raw, 'sekret123')), 'correct signature accepted');
check(!FbMessenger::signatureOk($raw, 'sha256=' . hash_hmac('sha256', $raw, 'other')), 'wrong signature refused');
check(!FbMessenger::signatureOk($raw, ''), 'missing signature refused');
Settings::$v['fb_app_secret'] = '';
check(!FbMessenger::signatureOk($raw, 'sha256=' . hash_hmac('sha256', $raw, '')), 'no app secret saved → everything refused');
Settings::$v['whatsapp_app_secret'] = 'wa-secret-1';
check(FbMessenger::signatureOk($raw, 'sha256=' . hash_hmac('sha256', $raw, 'wa-secret-1')) && FbMessenger::secretFromWhatsApp(), 'no Facebook secret → the WhatsApp app secret (same Meta app) checks the signature');
Settings::$v['fb_app_secret'] = 'sekret123';
check(!FbMessenger::signatureOk($raw, 'sha256=' . hash_hmac('sha256', $raw, 'wa-secret-1')) && !FbMessenger::secretFromWhatsApp(), 'a saved Facebook secret wins over the WhatsApp one');
Settings::$v['fb_app_secret'] = '';
unset(Settings::$v['whatsapp_app_secret']);

/* ---- 2. parsing events ---- */
$evs = FbMessenger::events(['object' => 'page', 'entry' => [['id' => 'PAGE1', 'messaging' => [
    ['sender' => ['id' => 'U1'], 'message' => ['mid' => 'a', 'text' => ' namaste ']],
    ['sender' => ['id' => 'PAGE1'], 'message' => ['mid' => 'b', 'text' => 'our echo', 'is_echo' => true]],
    ['sender' => ['id' => 'U1'], 'delivery' => ['mids' => ['a']]],
    ['sender' => ['id' => 'U2'], 'postback' => ['title' => 'Get Started', 'payload' => 'GET_STARTED', 'mid' => 'c']],
    ['sender' => ['id' => 'U3'], 'message' => ['mid' => 'd', 'attachments' => [['type' => 'image']]]],
]]]]);
check(count($evs) === 3, 'echo and delivery receipt dropped');
check($evs[0]['text'] === 'namaste' && $evs[0]['psid'] === 'U1', 'text message parsed and trimmed');
check($evs[1]['payload'] === 'GET_STARTED', 'Get Started postback parsed');
check($evs[2]['attachment'] === true && $evs[2]['text'] === '', 'a photo is marked as attachment');
check(FbMessenger::events(['object' => 'instagram']) === [], 'non-page objects ignored');

/* ---- 3. small pieces ---- */
check(FbMessenger::phoneFrom('9812345678') === ['9812345678', ''], 'a bare mobile is a number');
check(FbMessenger::phoneFrom('+977 9812345678') === ['9812345678', 'NP'], '+977 → Nepal');
check(FbMessenger::phoneFrom('mero number +91 98765 43210') === ['9876543210', 'IN'], '"mero number +91 …" → India');
check(FbMessenger::phoneFrom('bholi 2 seat chahiyo, mero number 9812345678 ho hai') === null, 'a sentence holding a number is not a sign-in');
check(FbMessenger::phoneFrom('123456') === null, 'a code is not a phone');
check(FbMessenger::codeFrom('123456') === '123456' && FbMessenger::codeFrom('code: 123456') === '123456', 'the code is read');
check(FbMessenger::codeFrom('9812345678') === null, 'a phone is not a code');
check(FbMessenger::plain("**SHG-1** confirmed\n## Seats\nBook at #/ or see #/my") === "SHG-1 confirmed\nSeats\nBook at https://www.shreehariglobal.in/#/ or see https://www.shreehariglobal.in/#/my", 'markdown stripped, #/ links made full');
check(FbMessenger::absolute('/download-ticket.php?p=1') === 'https://www.shreehariglobal.in/download-ticket.php?p=1', 'relative ticket link made full');
$long = implode("\n", array_fill(0, 300, str_repeat('क', 20)));
$parts = FbMessenger::chunks($long);
check(count($parts) === 3 && max(array_map('mb_strlen', $parts)) <= 1900, 'long replies split into ≤ 3 Messenger-sized parts');

$tok = 'EAAGm0PX4ZCpsBAKZB' . str_repeat('a1B2', 20);
check(FbMessenger::cleanToken($tok) === $tok, 'a bare Page token is kept');
check(FbMessenger::cleanToken("Page token: " . $tok . "
") === $tok, 'a label and line break around the token are dropped');
check(FbMessenger::cleanToken('"' . substr($tok, 0, 40) . "
 " . substr($tok, 40) . '"') === $tok, 'a token broken by a line break / quotes is joined');
check(FbMessenger::cleanToken('abc123def456ghi789') === 'abc123def456ghi789', 'an app secret passes through');
check(FbMessenger::cleanToken('hello there') === '' && FbMessenger::cleanToken('') === '', 'text with no token gives nothing');

/* ---- 4. off = silent ---- */
Settings::$v = ['fb_msgr_on' => false, 'fb_page_token' => 'TOKEN_TOKEN_TOKEN_1', 'fb_app_secret' => 'sekret123', 'company_name' => 'Shree Hari Global'];
FbMessenger::handle($ev('namaste', 'm0'));
check($sent === [] && $asked === [], 'switched off → nothing sent, the AI not asked');

/* ---- 5. on: a visitor ---- */
Settings::$v['fb_msgr_on'] = true;
Settings::$v['fb_page_id'] = 'PAGE1';
FbMessenger::handle($ev('', 'm1', 'GET_STARTED'));
check(str_contains($texts()[0] ?? '', 'नमस्ते') && str_contains($texts()[0], 'मोबाइल नम्बर'), 'Get Started → Nepali welcome that explains the number step');
check($asked === [], 'the welcome needs no AI');

$sent = [];
$brainOut = ['text' => "**Bholi** Surat bata 2 seat cha. Book: #/\nTotal tool le bhanchha.", 'media' => null,
    'actions' => [['label' => '🎫 Book this seat', 'href' => '#/'], ['label' => '💬 WhatsApp', 'href' => 'https://wa.me/919800000000']]];
FbMessenger::handle($ev('bholi surat bata 2 seat', 'm2'));
$ctx = $asked[0]['ctx'] ?? [];
check(($ctx['channel'] ?? '') === 'web' && ($ctx['via'] ?? '') === 'facebook', 'the AI is asked as the web assistant, via facebook');
check(($ctx['phone'] ?? 'x') === '' && ($ctx['verified'] ?? true) === false && ($ctx['role'] ?? '') === 'customer', 'an unproven visitor: customer, no phone, not verified');
check(($ctx['stageKey'] ?? '') === 'fb-U1', 'conversation memory keyed on the Messenger account');
check(($sent[0]['body']['sender_action'] ?? '') === 'typing_on', '"typing…" shown first');
check(($texts()[0] ?? '') === "Bholi Surat bata 2 seat cha. Book: https://www.shreehariglobal.in/#/\nTotal tool le bhanchha.", 'reply sent as plain text with full links');
$tpl = end($sent)['body']['message']['attachment']['payload'] ?? [];
check(($tpl['template_type'] ?? '') === 'button' && count($tpl['buttons']) === 2, 'actions become Messenger buttons');
check($tpl['buttons'][0]['url'] === 'https://www.shreehariglobal.in/#/' && mb_strlen($tpl['buttons'][0]['title']) <= 20, 'button links are full https, titles ≤ 20');

/* ---- 6. duplicate delivery ---- */
$sent = []; $asked = [];
FbMessenger::handle($ev('bholi surat bata 2 seat', 'm2'));
check($sent === [] && $asked === [], 'the same message id twice is answered once');

/* ---- 7. proving the number ---- */
$sent = [];
FbMessenger::handle($ev('+977 9812345678', 'm3'));
check($codes === [['9812345678', 'NP']], 'a mobile number sends the WhatsApp code (Nepal)');
check(str_contains($texts()[0] ?? '', 'WhatsApp मा कोड'), 'the visitor is told to type the code here');
check($asked === [], 'the number step never reaches the AI');

$sent = [];
FbMessenger::handle($ev('111111', 'm4'));
check(str_contains($texts()[0] ?? '', 'कोड मिलेन') && (FbMessenger::$memStore['U1']['phone'] ?? '') === '', 'a wrong code proves nothing');

$sent = [];
FbMessenger::handle($ev('123456', 'm5'));
check((FbMessenger::$memStore['U1']['phone'] ?? '') === '9812345678' && empty(FbMessenger::$memStore['U1']['pending']), 'the right code proves the number');
check(str_contains($texts()[0] ?? '', 'पक्का भयो') && !str_contains($texts()[0], 'नयाँ टिकट'), 'confirmation does not offer selling while fb_msgr_sell is off');

/* ---- 8. proven: the ticket picture ---- */
$sent = []; $asked = [];
$brainOut = ['text' => 'Tapai ko ticket SHG-2026-1 confirm bhayo.', 'media' => '/download-ticket.php?pnr=SHG-2026-1&format=png',
    'actions' => [['label' => '🖼️ Open ticket', 'href' => '/download-ticket.php?pnr=SHG-2026-1&format=png']]];
FbMessenger::handle($ev('mero ticket pathaidinus', 'm6'));
$ctx = $asked[0]['ctx'] ?? [];
check(($ctx['phone'] ?? '') === '9812345678' && ($ctx['verified'] ?? false) === true, 'after the code the AI sees the proven number');
check(($ctx['intl'] ?? '') === '9779812345678', 'a Nepal number keeps +977 for WhatsApp sends (documents, tickets)');
$img = null;
foreach ($sent as $s) { if (($s['body']['message']['attachment']['type'] ?? '') === 'image') { $img = $s['body']['message']['attachment']['payload']['url']; } }
check($img === 'https://www.shreehariglobal.in/download-ticket.php?pnr=SHG-2026-1&format=png', 'the ticket picture is sent in Messenger');

$sent = []; $failImage = true;
FbMessenger::handle($ev('feri pathaidinus', 'm7'));
check((bool) array_filter($texts(), static fn($t) => str_contains($t, 'टिकट यहाँ खोल्नुहोस्: https://www.shreehariglobal.in/download-ticket.php')), 'picture refused → the ticket link is sent instead');
$failImage = false;

/* ---- 9. edges ---- */
$sent = []; $brainOut = null;
FbMessenger::handle($ev('k cha?', 'm8'));
check(str_contains($texts()[0] ?? '', 'https://www.shreehariglobal.in/') && str_contains($texts()[0], 'wa.me'), 'AI silent → a fallback with the site and WhatsApp');

$sent = [];
FbMessenger::handle($ev('', 'm9', '', 'U1', true));
check(str_contains($texts()[0] ?? '', 'लेखेको सन्देश मात्र'), 'a photo gets a polite "please write it" reply');

$sent = [];
FbMessenger::handle($ev('number change', 'm10'));
check(!isset(FbMessenger::$memStore['U1']['phone']) && str_contains($texts()[0] ?? '', 'हटाइयो'), '"number change" forgets the proven number');

$sent = []; $asked = [];
FbMessenger::handle(['psid' => 'U9', 'page' => 'OTHERPAGE', 'text' => 'hi', 'payload' => '', 'mid' => 'x1', 'attachment' => false]);
check($sent === [] && $asked === [], 'a message to another Page is ignored');

$sent = []; Security::$allow = false;
FbMessenger::handle($ev('hello', 'm11', '', 'U5'));
check($sent === [], 'rate-limited → silent');
Security::$allow = true;

/* ---- 10. page set-up ---- */
check(FbMessenger::testToken()['ok'] && Settings::$v['fb_page_id'] === 'PAGE1', 'token test keeps the Page id');
check(FbMessenger::subscribe()['ok'], 'Page subscription');
check(FbMessenger::setupProfile()['ok'], 'Get Started + greeting');
unset(Settings::$v['fb_verify_token']);
$vt = FbMessenger::verifyToken();
check(str_starts_with($vt, 'shg-') && FbMessenger::verifyToken() === $vt, 'verify token made once and kept');

/* ---- 11. the assistant's own rules ---- */
$tools = (string) file_get_contents(dirname(__DIR__) . '/includes/aitools.php');
check(str_contains($tools, "Settings::getBool('fb_msgr_sell', false)"), 'selling in Messenger has its own switch, default off');
$agent = (string) file_get_contents(dirname(__DIR__) . '/includes/aiagent.php');
check(str_contains($agent, 'self::webChannelRules($ctx) . self::facebookRules($ctx)'), 'Messenger rules are added to the web rules');

/* ---- 12. the conversation log + office reply (16 Nov 2026, P6-6a) ---- */
check($evs[0]['kind'] === 'message' && $evs[1]['kind'] === 'postback' && $evs[2]['kind'] === 'attachment', 'events carry their kind (message / postback / attachment)');
FbMessenger::$memLog = [];
FbMessenger::$memStore = [];
$sent = []; $asked = [];
$brainOut = ['text' => 'Bholi 2 seat cha.', 'media' => null, 'actions' => []];
FbMessenger::handle($ev('bholi 2 seat', 'L1', '', 'U7'));
$log = FbMessenger::$memLog;
check(count($log) === 2 && $log[0]['direction'] === 'in' && $log[0]['body'] === 'bholi 2 seat' && $log[0]['mid'] === 'L1', 'the person\'s message is logged first');
check($log[1]['direction'] === 'out' && $log[1]['purpose'] === 'bot_reply' && $log[1]['status'] === 'sent' && $log[1]['body'] === 'Bholi 2 seat cha.', 'the AI answer is logged as bot_reply');
check(($asked[0]['ctx']['psid'] ?? '') === 'U7', 'the AI gets the Messenger id in ctx (help desk without a number)');
FbMessenger::$memStore = [];
FbMessenger::handle($ev('bholi 2 seat', 'L1', '', 'U7'));
check(count(array_filter(FbMessenger::$memLog, static fn($r) => $r['direction'] === 'in')) === 1, 'a re-delivered message id is logged once');
FbMessenger::handle($ev('654321', 'L2', '', 'U7'));
check(end(FbMessenger::$memLog)['direction'] === 'out' && FbMessenger::$memLog[count(FbMessenger::$memLog) - 2]['body'] === '[code hidden]', 'a typed code is logged hidden');
Security::$allow = false;
$n = count(FbMessenger::$memLog);
FbMessenger::handle($ev('spam spam', 'L3', '', 'U7'));
check(count(FbMessenger::$memLog) === $n + 1, 'a rate-limited message is still logged for the office (no answer)');
Security::$allow = true;

$sent = [];
check(FbMessenger::windowOpen('U7') === true, 'window open after the person wrote');
$r = FbMessenger::inboxReply('U7', 'Namaste, office bata', 42);
$lastRow = end(FbMessenger::$memLog);
check($r['ok'] && count($sent) === 1 && ($sent[0]['body']['message']['text'] ?? '') === 'Namaste, office bata', 'the office reply is sent inside the window');
check($lastRow['purpose'] === 'inbox_reply' && $lastRow['admin_id'] === 42, 'the reply is logged as inbox_reply with the admin id');
check((bool) array_filter(Logger::$lines, static fn($l) => str_starts_with($l, 'audit: fb.inbox_reply')), 'the reply is audited');
$sent = [];
check(FbMessenger::inboxReply('U7', '', 42)['ok'] === false && FbMessenger::inboxReply('U7', str_repeat('a', 1001), 42)['ok'] === false && $sent === [], 'empty / too long replies refused');
check(FbMessenger::inboxReply('no such!', 'hi', 42)['ok'] === false, 'a malformed id refused');
check(FbMessenger::inboxReply('U8', 'hi', 42)['ok'] === false && $sent === [], 'nobody wrote from that id → window closed, nothing sent');
foreach (FbMessenger::$memLog as $i => $row) {
    FbMessenger::$memLog[$i]['created_at'] = date('Y-m-d H:i:s', time() - 25 * 3600);
}
check(FbMessenger::windowOpen('U7') === false && FbMessenger::inboxReply('U7', 'late', 42)['ok'] === false && $sent === [], 'after 24 hours the window is closed and nothing is sent');
$failImage = true;
FbMessenger::sendImage('U7', 'https://www.shreehariglobal.in/t.png');
check(end(FbMessenger::$memLog)['status'] === 'failed' && end(FbMessenger::$memLog)['error'] === 'bad url', 'a refused send is logged as failed with the reason');
$failImage = false;
FbMessenger::sendText('U7', 'Ticket: https://x.test/ticket/view.php?code=AB12CD34 · PDF https://x.test/t.php?p=1&k=abcdef123456');
$kept = (string) end(FbMessenger::$memLog)['body'];
check(!str_contains($kept, 'AB12CD34') && !str_contains($kept, 'abcdef123456') && str_contains($kept, 'view.php?code=[hidden]'), 'a ticket key / short code is never kept in the conversation log');
FbMessenger::$memLog = null;

/* ---- 13. help desk hand-off without a number (P6-7c) ---- */
check(AiHandoff::psidOf(['via' => 'facebook', 'psid' => '123456']) === '123456', 'a Messenger sender has a PSID for the help desk');
check(AiHandoff::psidOf(['via' => 'facebook', 'stageKey' => 'fb-555']) === '555', 'read from the conversation key too');
check(AiHandoff::psidOf(['channel' => 'web', 'psid' => '123456']) === '', 'a website guest has none');
check(AiHandoff::psidMarker('123') === '[Messenger PSID 123]', 'the ticket tag');

echo "OK $checks checks\n";
