<?php
/**
 * tools/contact-lang-backfill.php — seed contact_lang from the WhatsApp
 * lines already received (19 Nov 2026, unit u8 / AI-2).
 *
 * Reads the text messages in wa_inbound (payload.text.body) in id order and
 * counts each through CustomerDirectory::noteLang(). Only a language code
 * and counters are written — never the words. Idempotent: the last id done
 * is kept in kv_store (contact_lang / backfill_last_id), so a second run
 * counts only newer messages. It also stops below contact_lang /
 * hook_start_id — the wa_inbound id the live WhatsApp hook started counting
 * at — so a line is never counted by both. CLI only.
 *
 *   php tools/contact-lang-backfill.php [--dry] [--limit=50000]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/customerdirectory.php';

$args  = array_slice($argv ?? [], 1);
$dry   = in_array('--dry', $args, true);
$limit = 50000;
foreach ($args as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m) === 1) {
        $limit = max(1, min(500000, (int) $m[1]));
    }
}

try {
    Database::scalar('SELECT 1 FROM contact_lang LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "contact_lang is missing - apply database/upgrade-2026-11-19-u8-contact-lang.sql first\n");
    exit(1);
}

$last = (int) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'contact_lang' AND kkey = 'backfill_last_id'", [], 0);
/* Fix of 19 Nov (review): once the live hook (wabot → noteLang) has counted
   its first line it records hook_start_id; everything from that id on is the
   hook's, so the backfill stops below it and never counts a line twice. */
$hookStart = (int) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'contact_lang' AND kkey = 'hook_start_id'", [], 0);
$ceil = $hookStart > 0 ? $hookStart : PHP_INT_MAX;
$seen = 0; $votes = ['ne' => 0, 'hi' => 0, 'en' => 0]; $none = 0; $maxId = $last;
do {
    $rows = Database::fetchAll(
        "SELECT id, from_digits, payload FROM wa_inbound WHERE id > :i AND id < :c AND msg_type = 'text' ORDER BY id LIMIT 500",
        ['i' => $maxId, 'c' => $ceil]
    );
    foreach ($rows as $r) {
        $maxId = (int) $r['id'];
        $seen++;
        $j = json_decode((string) $r['payload'], true);
        $body = is_array($j) ? (string) ($j['text']['body'] ?? '') : '';
        $v = $dry ? (CustomerDirectory::e164((string) $r['from_digits']) !== '' ? CustomerDirectory::voteFor($body) : '')
                  : CustomerDirectory::noteLang((string) $r['from_digits'], $body, 'backfill');
        if ($v === '') {
            $none++;
        } else {
            $votes[$v]++;
        }
        if ($seen >= $limit) {
            break 2;
        }
    }
} while ($rows !== []);

if (!$dry && $maxId > $last) {
    Database::run(
        "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('contact_lang', 'backfill_last_id', :v, 'backfill')
         ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = 'backfill'",
        ['v' => (string) $maxId]
    );
}
$contacts = (int) Database::scalar('SELECT COUNT(*) FROM contact_lang', [], 0);
echo json_encode(['dry' => $dry, 'from_id' => $last, 'to_id' => $maxId, 'hook_start_id' => $hookStart > 0 ? $hookStart : null,
                  'messages' => $seen, 'votes' => $votes,
                  'no_vote' => $none, 'contacts_now' => $contacts], JSON_UNESCAPED_UNICODE), "\n";
exit(0);
