<?php
/**
 * Offline tests for the guided booking on Facebook Messenger (19 Nov 2026,
 * WA-3, switch fb_msgr_form_on): the same WaBooking conversation as
 * WhatsApp, keyed by the PROVEN number, its tappable answers as Messenger
 * quick replies. No DB, no Facebook, no model, no WhatsApp: the Graph API,
 * the assistant, the code sender and the booking engine are seams.
 *
 *    php tests/u2-fb-booking-test.php
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
    public static function rateLimit(string $b, string $k, int $max, int $win): bool { return true; }
    public static function isValidPhone(string $p, bool $staff = false): bool { return preg_match('/^[6-9]\d{9}$/', $p) === 1; }
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/fbmessenger.php';
require_once dirname(__DIR__) . '/includes/messenger.php';

$PASS = 0; $FAIL = 0;
function check(bool $ok, string $label): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "PASS $label\n"; } else { $FAIL++; echo "FAIL $label\n"; }
}

/* ---- seams ---- */
$sent = [];
FbMessenger::$transport = static function (string $m, string $path, array $body) use (&$sent): array {
    $sent[] = ['m' => $m, 'path' => $path, 'body' => $body];
    return ['ok' => true, 'http' => 200, 'body' => '{"recipient_id":"1","message_id":"m"}'];
};
$asked = [];
FbMessenger::$brain = static function (array $ctx, string $text) use (&$asked): ?array {
    $asked[] = $text;
    return ['text' => 'assistant answer'];
};
$codes = [];
FbMessenger::$issueCode = static function (string $phone, string $country) use (&$codes): array {
    $codes[] = [$phone, $country];
    return ['ok' => true, 'error' => ''];
};
FbMessenger::$checkCode = static fn(string $phone, string $code): array
    => $code === '123456' ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'That code is incorrect.'];
$booked = [];
$bookReply = null;
FbMessenger::$booker = static function (string $intl, string $text, string $country) use (&$booked, &$bookReply): ?array {
    $booked[] = [$intl, $text, $country];
    return $bookReply;
};
FbMessenger::$memStore = [];
FbMessenger::$memLog   = [];

$messages = static function () use (&$sent): array {
    $out = [];
    foreach ($sent as $s) {
        if (isset($s['body']['message'])) { $out[] = $s['body']['message']; }
    }
    return $out;
};
$ev = static function (string $text, string $mid, string $payload = '', string $psid = 'P1'): array {
    return ['psid' => $psid, 'page' => 'PAGE1', 'text' => $text, 'payload' => $payload, 'mid' => $mid, 'attachment' => false];
};
Settings::$v = ['fb_msgr_on' => 1, 'fb_page_token' => 'EAAtest', 'fb_app_secret' => 'sekret', 'fb_page_id' => 'PAGE1'];

/* ---- 1. small pieces ---- */
check(FbMessenger::isBookWord('ticket') && FbMessenger::isBookWord('Ticket book') && FbMessenger::isBookWord('टिकट') && FbMessenger::isBookWord('book ticket'), 'the ways people ask to book');
check(!FbMessenger::isBookWord('ticket kati ho?') && !FbMessenger::isBookWord('mero ticket kaha cha') && !FbMessenger::isBookWord(''), 'a question about a ticket is not an order');
$qr = Messenger::quickReplies(['kind' => 'buttons', 'buttons' => [['id' => 'shg:1', 'title' => 'आज · Today'], ['id' => 'shg:2', 'title' => 'Tomorrow, 20 Nov and more words']]]);
check(count($qr) === 2 && $qr[0]['payload'] === 'shg:1' && $qr[0]['content_type'] === 'text' && mb_strlen($qr[1]['title']) <= 20, 'reply buttons become quick replies (titles ≤ 20)');
$rows = [];
for ($i = 1; $i <= 15; $i++) { $rows[] = ['id' => 'shg:' . $i, 'title' => 'Stop ' . $i]; }
check(count(Messenger::quickReplies(['kind' => 'list', 'rows' => $rows])) === 13, 'a long list is cut to Messenger\'s 13');
check(Messenger::quickReplies(['kind' => 'flow', 'flow' => ['id' => '1']]) === [] && Messenger::quickReplies(null) === [], 'a WhatsApp form has no Messenger shape');
check(Messenger::textOf(['message' => ['text' => 'Tomorrow', 'quick_reply' => ['payload' => 'shg:2']]]) === '2', 'old Messenger webhook: a tapped booking answer reads as the answer');
check(Messenger::textOf(['message' => ['quick_reply' => ['payload' => 'BOOK_NOW']]]) === 'BOOK_NOW', 'other payloads unchanged');

/* ---- 2. switched OFF: nothing changes ---- */
FbMessenger::handle($ev('ticket', 'm1'));
check($booked === [] && $asked === ['ticket'], 'fb_msgr_form_on off: "ticket" still goes to the assistant');

/* ---- 2b. the form switch alone never sells: fb_msgr_sell must be on too (review 19 Nov) ---- */
Settings::$v['fb_msgr_form_on'] = 1;
$asked = [];
FbMessenger::handle($ev('ticket', 'm1b'));
check($booked === [] && $asked === ['ticket'] && !FbMessenger::formOn(), 'fb_msgr_form_on with fb_msgr_sell off: still the assistant, no booking');

/* ---- 3. ON, number not proven yet ---- */
Settings::$v['fb_msgr_sell'] = 1;
$sent = []; $asked = [];
FbMessenger::handle($ev('ticket', 'm2'));
$m = $messages();
check($booked === [] && $asked === [] && $m !== [] && str_contains((string) end($m)['text'], 'मोबाइल नम्बर'), 'unproven + "ticket": asked for the mobile first, nothing booked');
FbMessenger::handle($ev('namaste, bus kati baje?', 'm3'));
check($asked === ['namaste, bus kati baje?'] && $booked === [], 'unproven + a question: the assistant as before');

/* ---- 4. the number is proven → the order starts by itself ---- */
FbMessenger::handle($ev('+977 9812345678', 'm4'));
check($codes === [['9812345678', 'NP']], 'the WhatsApp code goes to the +977 number');
$bookReply = ['text' => "*Name, pickup, day?*\nExample: Ram, Surat, bholi, 2", 'media' => null,
              'ui' => ['kind' => 'buttons', 'buttons' => [['id' => 'shg:1', 'title' => 'Today'], ['id' => 'shg:2', 'title' => 'Tomorrow']]]];
$sent = [];
FbMessenger::handle($ev('123456', 'm5'));
check($booked === [['9779812345678', 'ticket', 'NP']], 'code right → the booking starts on the proven +977 number');
$m = $messages();
$last = end($m);
check(isset($last['quick_replies']) && array_column($last['quick_replies'], 'payload') === ['shg:1', 'shg:2'], 'the question carries its answers as quick replies');
check(!str_contains((string) $last['text'], '*'), 'WhatsApp bold marks are not shown on Messenger');

/* ---- 5. a tap answers with what the button meant ---- */
$booked = []; $asked = [];
FbMessenger::handle($ev('Tomorrow', 'm6', 'shg:2'));
check($booked === [['9779812345678', '2', 'NP']] && $asked === [], 'tapping "Tomorrow" sends the answer "2" to the same order');

/* ---- 6. the engine has nothing to say → the assistant ---- */
$bookReply = null; $booked = [];
FbMessenger::handle($ev('Surat bata kati ghanta lagcha?', 'm7'));
check(count($booked) === 1 && $asked === ['Surat bata kati ghanta lagcha?'], 'not a booking line: the assistant answers');

/* ---- 7. a sale with a picture: picture first, words after ---- */
$bookReply = ['text' => "Booked SHG-2026-1\nPay: https://www.shreehariglobal.in/pay.php?pnr=SHG-2026-1", 'media' => '/pay-image.php?pnr=SHG-2026-1', 'ui' => null];
$sent = [];
FbMessenger::handle($ev('ho', 'm8'));
$m = $messages();
check(($m[0]['attachment']['payload']['url'] ?? '') === 'https://www.shreehariglobal.in/pay-image.php?pnr=SHG-2026-1', 'the pay QR picture goes first, as a full https link');
check(str_contains((string) ($m[1]['text'] ?? ''), 'SHG-2026-1') && !isset($m[1]['quick_replies']), 'then the words, no buttons when there are none');

/* ---- 8. "number change" forgets the number: booking asks for it again ---- */
FbMessenger::handle($ev('number change', 'm9'));
$booked = []; $sent = [];
FbMessenger::handle($ev('ticket', 'm10'));
$m = $messages();
$lastM = end($m);
check($booked === [] && str_contains((string) ($lastM['text'] ?? ''), 'मोबाइल'), 'after "number change" the mobile is asked again before any booking');

echo "\nu2-fb-booking: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
