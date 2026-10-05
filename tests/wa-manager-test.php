<?php
/**
 * =====================================================================
 *  WHATSAPP MANAGER + ONE AI — the message book and the AI order
 *
 *      php tests/wa-manager-test.php
 *
 *  Owner (5 Oct 2026): "WhatsApp manager in the admin panel — live, where
 *  each message went, who got what, A to Z, how much it cost" and "my AI
 *  is one: Gemini / Claude / GPT, use whichever key is there".
 *
 *  Pins down:
 *    1. Numbers → country (India / Nepal), Nepal's 98… never read as India.
 *    2. An AiSensy send lands in the book with the template, the words and
 *       the PNG; a bad number lands as "never left".
 *    3. message_logs rows are copied once; a row with the same wamid only
 *       ENRICHES the AiSensy row (one message = one row), and a later
 *       webhook status change is carried over.
 *    4. Cost: a delivered utility template costs the rate card, a failed
 *       one and a service reply cost nothing; the wallet drop counts only
 *       drops (a recharge is reported apart).
 *    5. Failure codes are explained in plain words.
 *    6. AiBrain: only keyed providers, the chosen one first, the rest as
 *       backup; AiAgent's ladder follows the same choice.
 *    7. The wiring: AiSensy post() records, the cron exists, the page is
 *       in the menu, ai-proxy no longer needs a Claude key.
 *
 *  Nothing is sent: rows are written straight into the test database and
 *  removed again.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/msgledger.php';
require_once INCLUDE_PATH . '/aibrain.php';
require_once ROOT_PATH . '/whatsapp/aisensy.php';

$PASS = 0;
$FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== WhatsApp Manager + One AI ===\n\n";

if (!MsgLedger::ready()) {
    echo "  \033[33mSKIP\033[0m  database/upgrade-2026-10-05-wa-manager.sql is not applied\n\n";
    exit(0);
}

$tag = 'wamtest' . bin2hex(random_bytes(3));
$cleanup = static function () use ($tag): void {
    Database::run("DELETE FROM msg_ledger WHERE provider_ref LIKE :t OR body LIKE :b", ['t' => 'wamid.' . $tag . '%', 'b' => '%' . $tag . '%']);
    Database::run("DELETE FROM message_logs WHERE provider_ref LIKE :t OR body LIKE :b", ['t' => 'wamid.' . $tag . '%', 'b' => '%' . $tag . '%']);
    Database::run("DELETE FROM wam_credit WHERE quality = :q", ['q' => substr($tag, 0, 12)]);
};

try {
    /* ---- 1. country ---------------------------------------------------- */
    check('91 + 10 digits is India', MsgLedger::countryOf('919104801507') === 'IN');
    check('977 is Nepal', MsgLedger::countryOf('+977 981-2345678') === 'NP');
    check('a bare 98… 10-digit number is read as Nepal', MsgLedger::countryOf('9812345678') === 'NP');
    check('a bare 91… 10-digit number is India', MsgLedger::countryOf('9104801507') === 'IN');
    check('nothing is nothing', MsgLedger::countryOf('') === '');

    /* ---- 2. an AiSensy send -------------------------------------------- */
    // catch up with whatever the test database already holds
    for ($i = 0; $i < 20 && MsgLedger::sync(2000)['copied'] >= 2000; $i++) {
    }
    $ref = 'wamid.' . $tag . 'A';
    MsgLedger::recordAiSensy('919800000123', 'shg_ticket_image', [
        'to' => '919800000123', 'type' => 'template',
        'template' => ['name' => 'shg_ticket_image', 'components' => [
            ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://x.test/t.png']]]],
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ram ' . $tag], ['type' => 'text', 'text' => 'Surat'], ['type' => 'text', 'text' => 'code 123456']]],
        ]],
    ], ['success' => true, 'message_id' => $ref]);
    $row = Database::fetch('SELECT * FROM msg_ledger WHERE provider_ref = :r', ['r' => $ref]);
    check('the send is in the book', $row !== null);
    check('…as AiSensy WhatsApp, India, accepted', $row !== null && $row['provider'] === 'aisensy' && $row['country'] === 'IN' && $row['status'] === 'accepted');
    check('…with the template name and the words', $row !== null && $row['label'] === 'shg_ticket_image' && str_contains((string) $row['body'], 'Ram ' . $tag) && str_contains((string) $row['body'], 'Surat'));
    check('…a one-time code in the words is hidden', $row !== null && !str_contains((string) $row['body'], '123456'));
    check('…and the ticket PNG', $row !== null && $row['media_url'] === 'https://x.test/t.png');
    check('a template is a utility message', $row !== null && $row['category'] === 'utility');

    MsgLedger::recordAiSensy('98000', 'shg_ticket_image', [], ['success' => false, 'error' => 'invalid phone number ' . $tag], 'skipped');
    $skip = Database::fetch("SELECT * FROM msg_ledger WHERE error LIKE :e", ['e' => '%' . $tag]);
    check('a bad number is in the book as "never left"', $skip !== null && $skip['status'] === 'skipped');
    Database::run('UPDATE msg_ledger SET body = :b WHERE id = :i', ['b' => $tag, 'i' => (int) ($skip['id'] ?? 0)]);

    MsgLedger::recordAiSensy('919800000124', 'text', ['type' => 'text', 'text' => ['body' => 'hello ' . $tag]], ['success' => true, 'message_id' => 'wamid.' . $tag . 'T']);
    $txt = Database::fetch('SELECT * FROM msg_ledger WHERE provider_ref = :r', ['r' => 'wamid.' . $tag . 'T']);
    check('a free-text reply is a (free) service message', $txt !== null && $txt['category'] === 'service' && str_contains((string) $txt['body'], 'hello'));

    /* ---- 3. sync from message_logs ------------------------------------- */
    $hasPurpose = (int) Database::scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_logs' AND COLUMN_NAME = 'purpose'", [], 0) > 0;
    $ml = ['channel' => 'whatsapp', 'provider' => 'aisensy', 'to_number' => '919800000123', 'body' => 'AiSensy ticket ' . $tag . ' (image template)',
           'status' => 'sent', 'provider_ref' => $ref];
    if ($hasPurpose) { $ml['purpose'] = 'aisensy_ticket'; }
    Database::insert('message_logs', $ml);
    $ml2 = ['channel' => 'whatsapp', 'provider' => 'cloud_api', 'to_number' => '9779812000111', 'body' => 'Meta ticket ' . $tag,
            'status' => 'sent', 'provider_ref' => 'wamid.' . $tag . 'M'];
    if ($hasPurpose) { $ml2['purpose'] = 'ticket'; }
    $mlId = Database::insert('message_logs', $ml2);
    $s = MsgLedger::sync(500);
    $n = (int) Database::scalar('SELECT COUNT(*) FROM msg_ledger WHERE provider_ref = :r', ['r' => $ref], 0);
    check('the same wamid from message_logs does not make a second row', $n === 1, 'rows=' . $n);
    $row = Database::fetch('SELECT * FROM msg_ledger WHERE provider_ref = :r', ['r' => $ref]);
    check('…it enriches the AiSensy row (log id' . ($hasPurpose ? ', purpose' : '') . ')', $row !== null && $row['log_id'] !== null && (!$hasPurpose || $row['purpose'] === 'aisensy_ticket'));
    $meta = Database::fetch('SELECT * FROM msg_ledger WHERE provider_ref = :r', ['r' => 'wamid.' . $tag . 'M']);
    check('a Meta row is copied once, Nepal, "sent"', $meta !== null && $meta['country'] === 'NP' && $meta['status'] === 'sent' && $meta['provider'] === 'cloud_api');
    MsgLedger::sync(500);
    check('a second sync copies nothing again', (int) Database::scalar('SELECT COUNT(*) FROM msg_ledger WHERE provider_ref = :r', ['r' => 'wamid.' . $tag . 'M'], 0) === 1);
    Database::run("UPDATE message_logs SET status = 'failed', error = 'WhatsApp failed (code 131042)' WHERE id = :i", ['i' => $mlId]);
    MsgLedger::sync(500);
    $meta = Database::fetch('SELECT * FROM msg_ledger WHERE provider_ref = :r', ['r' => 'wamid.' . $tag . 'M']);
    check('a webhook failure is carried over', $meta !== null && $meta['status'] === 'failed' && str_contains((string) $meta['error'], '131042'));

    /* ---- 4. money ------------------------------------------------------- */
    Database::run("UPDATE msg_ledger SET status = 'delivered' WHERE provider_ref = :r", ['r' => $ref]);
    Database::run("UPDATE msg_ledger SET status = 'read' WHERE provider_ref = :r", ['r' => 'wamid.' . $tag . 'T']);
    MsgLedger::price();
    $c1 = (float) Database::scalar('SELECT cost FROM msg_ledger WHERE provider_ref = :r', ['r' => $ref], 0);
    $c2 = (float) Database::scalar('SELECT cost FROM msg_ledger WHERE provider_ref = :r', ['r' => 'wamid.' . $tag . 'T'], 0);
    $c3 = (float) Database::scalar('SELECT cost FROM msg_ledger WHERE provider_ref = :r', ['r' => 'wamid.' . $tag . 'M'], 0);
    check('a delivered India ticket template costs the rate card', abs($c1 - MsgLedger::rate('IN', 'utility')) < 0.0001, (string) $c1);
    check('a read service reply costs nothing', $c2 == 0.0, (string) $c2);
    check('a failed message costs nothing', $c3 == 0.0, (string) $c3);
    check('Nepal is dearer than India (rate card)', MsgLedger::rate('NP', 'utility') > MsgLedger::rate('IN', 'utility'));

    $q = substr($tag, 0, 12);
    $t0 = time() - 3000;
    foreach ([[0, 100.00], [600, 99.50], [1200, 99.20], [1800, 150.00], [2400, 149.00]] as [$dt, $cr]) {
        Database::insert('wam_credit', ['at' => date('Y-m-d H:i:s', $t0 + $dt), 'credit' => $cr, 'quality' => $q, 'tier' => 't']);
    }
    $w = MsgLedger::walletSpend(date('Y-m-d H:i:s', $t0 - 1), date('Y-m-d H:i:s', time() + 60));
    check('wallet spend counts only the drops (0.50 + 0.30 + 1.00)', abs($w['spent'] - 1.80) < 0.001, json_encode($w) ?: '');
    check('…and reports the recharge apart', abs($w['recharged'] - 50.80) < 0.001, (string) $w['recharged']);

    /* ---- 5. reasons ----------------------------------------------------- */
    check('131047 = no open chat window', str_contains(MsgLedger::explain('(code 131047) Re-engagement')[1], '24 h'));
    check('131042 = Meta payment method', str_contains(MsgLedger::explain('WhatsApp failed (code 131042)')[1], 'payment'));
    check('131026 = not on WhatsApp', MsgLedger::explain('131026 undeliverable')[0] === '131026');
    check('a bad number is said plainly', MsgLedger::explain('invalid phone number')[0] === 'phone');

    $tot = MsgLedger::totals(date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day')));
    check('totals count the day', (int) $tot['total'] >= 4 && (int) $tot['delivered'] >= 2);
    $feed = MsgLedger::feed(['q' => $tag], 20);
    check('the feed finds rows by a word in the message', count($feed) >= 2);

    /* ---- 6. one AI ------------------------------------------------------ */
    $keep = [];
    foreach (['ai_provider', 'gemini_api_key', 'openai_api_key', 'anthropic_api_key'] as $k) {
        $keep[$k] = Settings::getString($k, '');
    }
    try {
        Settings::set('gemini_api_key', 'AIza-test-' . $tag, 'string', 'ai');
        Settings::set('openai_api_key', 'sk-test-' . $tag, 'string', 'ai');
        Settings::set('anthropic_api_key', '', 'string', 'ai');
        Settings::set('ai_provider', 'auto', 'string', 'ai');
        Settings::flush();
        check('auto: free Gemini first, then GPT; no key = not in the order', AiBrain::order() === ['gemini', 'openai'], implode(',', AiBrain::order()));
        Settings::set('ai_provider', 'openai', 'string', 'ai');
        Settings::flush();
        check('GPT chosen: GPT first, Gemini as backup', AiBrain::order() === ['openai', 'gemini'], implode(',', AiBrain::order()));
        Settings::set('ai_provider', 'anthropic', 'string', 'ai');
        Settings::flush();
        check('Claude chosen without a Claude key: the others still answer', AiBrain::order() === ['gemini', 'openai'], implode(',', AiBrain::order()));
        Settings::set('ai_provider', 'nonsense', 'string', 'ai');
        Settings::flush();
        check('a wrong choice reads as auto', AiBrain::choice() === 'auto');
        Settings::set('ai_provider', 'openai', 'string', 'ai');
        Settings::flush();
        require_once INCLUDE_PATH . '/aiagent.php';
        $lad = new ReflectionMethod('AiAgent', 'buildLadder');
        $lad->setAccessible(true);
        $ladder = $lad->invoke(null);
        check('the WhatsApp agent follows the same choice (GPT first)', ($ladder[0] ?? '') === 'openai', implode(',', $ladder));
    } finally {
        foreach ($keep as $k => $v) {
            Settings::set($k, $v, 'string', 'ai');
        }
        Settings::flush();
    }

    /* ---- 7. wiring ------------------------------------------------------ */
    $ais = (string) file_get_contents(ROOT_PATH . '/whatsapp/aisensy.php');
    check('AiSensy post() writes every send to the book', substr_count($ais, 'self::ledger(') >= 3);
    check('the cron exists', is_file(ROOT_PATH . '/cron/msg-ledger.php'));
    $guard = (string) file_get_contents(ROOT_PATH . '/admin/_guard.php');
    check('WhatsApp Manager and One AI are in the menu', str_contains($guard, "'wa-manager.php'") && str_contains($guard, "'ai-brain.php'"));
    $proxy = (string) file_get_contents(ROOT_PATH . '/api/ai-proxy.php');
    check('the website relay asks the one AI, not only Claude', str_contains($proxy, 'AiBrain::text(') && !str_contains($proxy, "getString('anthropic_api_key'"));
    $chat = (string) file_get_contents(INCLUDE_PATH . '/aichat.php');
    check('the WhatsApp reply asks the one AI', str_contains($chat, 'AiBrain::text('));
} finally {
    $cleanup();
}

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL > 0 ? 1 : 0);
