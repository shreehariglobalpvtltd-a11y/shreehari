<?php
/**
 * =====================================================================
 *  u1-wa-reply-pdf-test.php — WA-4 (19 Nov 2026): a bot reply that carries
 *  a PDF (the agent statement) goes out on the Meta number as a WhatsApp
 *  DOCUMENT - and never as a picture on any other reply path.
 *
 *    A. ApWa::$lastDocument + plain reply: the words first (they carry the
 *       link), then the document with the PDF's own file name; the side
 *       channel is cleared after one reply
 *    B. buttons + PDF: the interactive message has no picture header, the
 *       document follows it
 *    C. the words fail -> no document is sent alone
 *    D. a picture reply keeps the old path (image with the words as caption)
 *    E. wa_reply_pdf_doc_on = 0: the PDF is not adopted (text only, no
 *       PDF-as-image)
 *    F. ApWa::statement() no longer puts the PDF in the reply's 'media'
 *       (AiSensy / Messenger / Twilio would send it as a picture)
 *
 *  No network: whatsapp/api.php's $GLOBALS['WA_GRAPH_HOOK'] sees every payload.
 *  No DB rows are written. Settings are overridden in-process only.
 *    php -c .claude/php-dev.ini tests/u1-wa-reply-pdf-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('META_ACCESS_TOKEN', 'U1_TEST_TOKEN');
define('META_PHONE_NUMBER_ID', '555000111000999');
define('META_WABA_ID', '555000111000888');
define('SHG_APP', true);
define('SHG_WA_INBOUND_REPLAY', true);          // functions only, never the HTTP part
require_once dirname(__DIR__) . '/whatsapp/webhook.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/agentpro-wa.php';

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

$calls = [];
$failText = false;
$GLOBALS['WA_GRAPH_HOOK'] = static function (string $to, array $body, string $type) use (&$calls, &$failText): array {
    $calls[] = ['to' => $to, 'type' => $type, 'body' => $body];
    if ($failText && $type === 'text') {
        return ['success' => false, 'message_id' => '', 'error' => '(#131026) undeliverable', 'http' => 400, 'code' => 131026];
    }
    return ['success' => true, 'message_id' => 'wamid.U1PDF' . count($calls), 'error' => '', 'http' => 200];
};
$kinds = static function () use (&$calls): array {
    return array_map(static fn($c) => (string) ($c['body']['type'] ?? $c['type']), $calls);
};

tset('wa_reply_pdf_doc_on', true);
tset('wa_buttons_on', true);
$to  = '919000012399';
$pdf = 'https://shreehariglobal.in/download-statement.php?f=agent-5-2026-11.pdf&t=abc';
$txt = "📄 Statement · 30 days\nPDF: " . $pdf;

echo "\n=== WA-4: bot replies with a PDF go as a WhatsApp document ===\n\n";

/* A */
echo "-- A. plain reply + statement PDF --\n";
$calls = [];
ApWa::$lastDocument = $pdf;
$r = wh_send_reply($to, ['text' => $txt, 'media' => null]);
$k = $kinds();
check('reply reported sent', $r['success'] === true);
check('the words go first, then the document', $k === ['text', 'document'], implode(',', $k));
check("document link is the statement PDF", (string) ($calls[1]['body']['document']['link'] ?? '') === $pdf);
check("file name comes from ?f= (agent-5-2026-11.pdf)", (string) ($calls[1]['body']['document']['filename'] ?? '') === 'agent-5-2026-11.pdf', (string) ($calls[1]['body']['document']['filename'] ?? ''));
check('the text still carries the link', str_contains((string) ($calls[0]['body']['text']['body'] ?? ''), $pdf));
check('the side channel is cleared after one reply', ApWa::$lastDocument === null);
$calls = [];
wh_send_reply($to, ['text' => 'next question', 'media' => null]);
check('the next reply sends no stale document', $kinds() === ['text'], implode(',', $kinds()));

/* B */
echo "\n-- B. buttons + PDF --\n";
$calls = [];
ApWa::$lastDocument = $pdf;
$ui = ['kind' => 'buttons', 'body' => 'Statement ready', 'buttons' => [['id' => 'menu', 'title' => 'Menu']]];
$r = wh_send_reply($to, ['text' => $txt, 'media' => null, 'ui' => $ui]);
$k = $kinds();
check('interactive first, then the document', $k === ['interactive', 'document'], implode(',', $k));
check('the interactive message has no picture header', !isset($calls[0]['body']['interactive']['header']));
check('document after the buttons keeps the real file name', (string) ($calls[1]['body']['document']['filename'] ?? '') === 'agent-5-2026-11.pdf');

/* C */
echo "\n-- C. the words fail --\n";
$calls = [];
$failText = true;
ApWa::$lastDocument = $pdf;
$r = wh_send_reply($to, ['text' => $txt, 'media' => null]);
$failText = false;
check('reply reported failed', $r['success'] === false);
check('no document went out alone', !in_array('document', $kinds(), true), implode(',', $kinds()));

/* D */
echo "\n-- D. a picture reply is unchanged --\n";
$calls = [];
$img = 'https://shreehariglobal.in/ticket-image.php?pnr=SHG-2099-90999';
$r = wh_send_reply($to, ['text' => 'Your ticket', 'media' => $img]);
check('one image with the words as caption', $kinds() === ['image'] && (string) ($calls[0]['body']['image']['caption'] ?? '') === 'Your ticket', implode(',', $kinds()));

/* E */
echo "\n-- E. wa_reply_pdf_doc_on = 0 --\n";
tset('wa_reply_pdf_doc_on', false);
$calls = [];
ApWa::$lastDocument = $pdf;
wh_send_reply($to, ['text' => $txt, 'media' => null]);
check('switched off: text only (never the PDF as a picture)', $kinds() === ['text'], implode(',', $kinds()));
check('side channel still cleared', ApWa::$lastDocument === null);
tset('wa_reply_pdf_doc_on', true);

/* F */
echo "\n-- F. ApWa::statement() keeps the PDF out of 'media' --\n";
$src = (string) file_get_contents(INCLUDE_PATH . '/agentpro-wa.php');
check("statement() sets ApWa::\$lastDocument", str_contains($src, 'self::$lastDocument = (string) $pdf[\'url\'];'));
check("statement() never returns the PDF as media", !str_contains($src, "return self::out(implode(\"\\n\", \$lines), (string) \$pdf['url']);"));
check('reply() clears the side channel first', preg_match('/function reply\(string \$fromRaw[^{]*\{\s*self::\$lastDocument = null;/', $src) === 1);

unset($GLOBALS['WA_GRAPH_HOOK']);
echo "\n" . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . "\n";
exit($FAIL === 0 ? 0 : 1);
