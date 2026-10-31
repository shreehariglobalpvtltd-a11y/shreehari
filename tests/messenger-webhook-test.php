<?php
/**
 * =====================================================================
 *  messenger-webhook-test.php — 31 Oct 2026.
 *
 *  The Facebook Messenger channel's trust + routing logic, without any
 *  network: the GET verify-token handshake, the X-Hub-Signature-256 check
 *  (fails CLOSED with no App Secret), the messenger_bot_enabled gate, and
 *  the text extraction messenger/webhook.php feeds to WaBot::reply().
 *
 *  Uses throwaway dummy credentials and restores the (empty) live keys.
 *     php -c .claude/php-dev.ini tests/messenger-webhook-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/messenger.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Facebook Messenger webhook — trust & routing ===\n\n";

$SECRET = 'dummy_app_secret_7f3a';
$VERIFY = 'dummy_verify_tok_99';
Settings::set('fb_app_secret',   $SECRET, 'string', 'messenger', false);
Settings::set('fb_verify_token', $VERIFY, 'string', 'messenger', false);
Settings::set('fb_page_token',   'EAApagetoken_dummy', 'string', 'messenger', false);
Settings::set('messenger_bot_enabled', false, 'bool', 'messenger', false);

/* ---- GET verify-token handshake ---- */
check('verify handshake echoes challenge on the right token',
    Messenger::verifyChallenge('subscribe', $VERIFY, 'CHALLENGE123') === 'CHALLENGE123');
check('verify handshake refuses a wrong token',
    Messenger::verifyChallenge('subscribe', 'WRONG', 'CHALLENGE123') === null);
check('verify handshake refuses a non-subscribe mode',
    Messenger::verifyChallenge('unsubscribe', $VERIFY, 'CHALLENGE123') === null);

/* ---- X-Hub-Signature-256 ---- */
$raw = '{"object":"page","entry":[]}';
$good = 'sha256=' . hash_hmac('sha256', $raw, $SECRET);
check('a correct signature is accepted', Messenger::verifySignature($raw, $good) === true);
check('a tampered body is rejected',     Messenger::verifySignature($raw . ' ', $good) === false);
check('an empty signature is rejected',  Messenger::verifySignature($raw, '') === false);
Settings::set('fb_app_secret', '', 'string', 'messenger', false);
check('with NO app secret every POST fails closed',
    Messenger::verifySignature($raw, $good) === false);
Settings::set('fb_app_secret', $SECRET, 'string', 'messenger', false);

/* ---- messenger_bot_enabled gate ---- */
check('channel is OFF by default', Messenger::enabled() === false);
Settings::set('messenger_bot_enabled', true, 'bool', 'messenger', false);
check('channel is ON when switched on AND a Page token is set', Messenger::enabled() === true);
Settings::set('fb_page_token', '', 'string', 'messenger', false);
check('channel stays OFF when switched on but NO Page token', Messenger::enabled() === false);

/* ---- text extraction for WaBot ---- */
check('a typed message yields its text',
    Messenger::textOf(['message' => ['mid' => 'm1', 'text' => 'Surat to Rupaidiha']]) === 'Surat to Rupaidiha');
check('a quick-reply yields its payload',
    Messenger::textOf(['message' => ['quick_reply' => ['payload' => 'BOOK_NOW']]]) === 'BOOK_NOW');
check('a postback yields its payload',
    Messenger::textOf(['postback' => ['payload' => 'GET_STARTED']]) === 'GET_STARTED');
check('our own echo is ignored',
    Messenger::textOf(['message' => ['is_echo' => true, 'text' => 'ignore me']]) === '');
check('a read/delivery receipt has no text',
    Messenger::textOf(['read' => ['watermark' => 123]]) === '');

/* ---- restore the (empty) live keys ---- */
Settings::set('fb_app_secret',   '', 'string', 'messenger', false);
Settings::set('fb_verify_token', '', 'string', 'messenger', false);
Settings::set('fb_page_token',   '', 'string', 'messenger', false);
Settings::set('messenger_bot_enabled', false, 'bool', 'messenger', false);

echo "\n  " . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . "\n\n";
exit($FAIL === 0 ? 0 : 1);
