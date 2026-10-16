<?php
/**
 * =====================================================================
 *  wa-voice-test.php — WhatsApp voice notes become text
 *  (includes/wavoice.php + whatsapp/webhook.php, 23 Sep 2026).
 *
 *  No network and no model: pins the request Gemini receives, the cleanup
 *  of its answer, the "what we heard" line, the switch, and that the
 *  webhook sends a transcript down the SAME path as a typed message.
 *
 *      php tests/wa-voice-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/wavoice.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 200) : '') . "\n"; }
}

echo "\n=== WhatsApp voice notes ===\n\n";

$prior = Database::fetch("SELECT svalue FROM settings WHERE skey = 'wa_voice_on'");
register_shutdown_function(static function () use ($prior): void {
    try {
        if ($prior === null) { Database::delete('settings', 'skey = :k', ['k' => 'wa_voice_on']); }
        else { Database::update('settings', ['svalue' => (string) $prior['svalue']], 'skey = :k', ['k' => 'wa_voice_on']); }
    } catch (Throwable $e) {}
});

/* ---- the request ----------------------------------------------------- */
$p = WaVoice::payload('QUJD', 'audio/ogg');
$parts = $p['contents'][0]['parts'] ?? [];
check('the audio travels inline with its type', ($parts[0]['inline_data']['mime_type'] ?? '') === 'audio/ogg'
    && ($parts[0]['inline_data']['data'] ?? '') === 'QUJD');
check('it asks for the spoken language and script, words only',
    str_contains((string) ($parts[1]['text'] ?? ''), 'Devanagari') && str_contains((string) ($parts[1]['text'] ?? ''), 'Output ONLY'));
check('deterministic (temperature 0)', ($p['generationConfig']['temperature'] ?? null) === 0);

/* ---- cleaning the answer ---------------------------------------------- */
check('"Transcript: …" label removed', WaVoice::clean('Transcript: bholi 2 seat chahiyo') === 'bholi 2 seat chahiyo');
check('wrapping quotes removed', WaVoice::clean('“भोलि २ सिट चाहियो”') === 'भोलि २ सिट चाहियो');
check('whitespace folded', WaVoice::clean("surat\n bata   jane") === 'surat bata jane');
check('capped at 500 chars', mb_strlen(WaVoice::clean(str_repeat('क', 900))) === 500);

/* ---- what we heard ----------------------------------------------------- */
$h = WaVoice::heardLine('bholi surat bata 2 jana');
check('the reply opens with 🎤 and the words', $h === '🎤 "bholi surat bata 2 jana"', $h);
check('a long transcript is shortened', mb_strlen(WaVoice::heardLine(str_repeat('a ', 200))) < 150);

/* ---- switch ------------------------------------------------------------ */
Settings::set('wa_voice_on', false, 'bool', 'ai');
Settings::flush();
check('switched off → never transcribes', WaVoice::enabled() === false && WaVoice::transcribe('123', '+919100000000') === null);

/* ---- webhook wiring ---------------------------------------------------- */
$hook = (string) file_get_contents(dirname(__DIR__) . '/whatsapp/webhook.php');
check('the webhook transcribes audio before the bot replies',
    str_contains($hook, 'WaVoice::transcribe') && strpos($hook, 'WaVoice::transcribe') < strpos($hook, 'WaBot::reply'));
check('  and the transcript takes the typed-message path', str_contains($hook, "\$type  = 'text';"));
check('  and the reply opens with what was heard', str_contains($hook, 'WaVoice::heardLine'));

$runAll = @file_get_contents(dirname(__DIR__) . '/tests/run-all.php') ?: '';
check('this suite is registered in the battery', str_contains($runAll, 'wa-voice-test.php'));

echo "\nwa-voice: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
