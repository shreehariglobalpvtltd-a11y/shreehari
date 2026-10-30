<?php
/**
 * =====================================================================
 *  includes/agentpro-learn.php — the WhatsApp desk learns from what its
 *  members write, and the VPS model practises on it (31 Oct 2026).
 *
 *  Owner: "yo sab application ko VPS ko model le herera practice garos,
 *  pura VPS k k chha sikdai jaos, training lidai fast hudai jaos."
 *
 *  WHAT THIS IS — AND IS NOT
 *  -------------------------
 *  The model's weights are NOT re-trained here: the VPS has 2 vCPUs and
 *  ~8 GB RAM (qwen3.5:4b, Ollama capped at 4.8 GB); fine-tuning does not
 *  fit on it. The desk learns the honest way — from examples:
 *
 *   - examples (ap_wa_examples): a message the desk understood FOR SURE —
 *       parser    a typed command the desk knew ("balance", "SHG-… cancel")
 *       confirmed an AI reading the member then confirmed with "ho"
 *       office    taught on admin/ai-local.php
 *     Kept only NORMALISED (norm()): PNRs, phone numbers, amounts, dates,
 *     agent / staff codes, references and names are placeholders. An
 *     example never holds one person's details.
 *   - fast path (fast()): a message whose normalised words were confirmed
 *     ap_learn_fast_min (2) times skips the model — instant, even with the
 *     WhatsApp AI off. It only replaces READING the intent: the handler is
 *     the same, so money and tickets still wait for "ho".
 *   - few-shot (shots()): the most similar examples go into the model's
 *     prompt, so it reads the next message the way the desk was confirmed.
 *   - practice (practice()): at night (cron/agentpro-practice.php) the
 *     model re-reads a sample of examples while nobody needs it; accuracy
 *     and speed go to ap_ai_practice, and the examples it got wrong are
 *     shown first in later prompts.
 *
 *  NEVER THROWS from record() / fast() / shots(): learning is a convenience.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class ApLearn
{
    public const SOURCES = ['parser', 'confirmed', 'office'];

    /**
     * Intents the fast path may answer: read-only ones, and the ones whose
     * handler asks for "ho" before money or a ticket moves (withdraw,
     * cancel, book). Never: edit (the new value is free text), directory
     * (a name), deposit (files a request at once), resend, question / other.
     */
    public const FAST_INTENTS = ['balance', 'statement', 'withdraw', 'id_card', 'visiting_card', 'profile', 'company', 'bus', 'report',
                                 'passengers', 'tickets', 'portal', 'pending', 'help', 'status', 'cancel', 'book', 'form'];

    /** Never learnt: a free-text answer is no label. */
    private const SKIP = ['question', 'other'];

    /** The placeholders norm() writes. */
    public const SLOTS = ['PNR', 'PHONE', 'AMOUNT', 'DATE', 'CODE', 'NAME', 'REF', 'EMAIL', 'URL'];

    /** Tests: stands in for the model in practice() — fn(string $textNorm, string $shots, int $timeout): ?array. */
    public static ?Closure $reader = null;

    private static ?bool $ready = null;

    /* =================================================================
     *  Switches
     * ================================================================= */

    /** Is ap_wa_examples there (database/upgrade-2026-10-31-idc-learn.sql)? */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = (int) Database::scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                                                         AND TABLE_NAME IN ('ap_wa_examples', 'ap_ai_practice')", [], 0) === 2;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** Tests: look for the tables again. */
    public static function reset(): void
    {
        self::$ready = null;
    }

    /** Learning on (ap_learn_on, default on) and the tables there. */
    public static function on(): bool
    {
        return Settings::getBool('ap_learn_on', true) && self::ready();
    }

    /** How many confirmations make an example trusted for the fast path. */
    public static function fastMin(): int
    {
        return max(1, min(20, Settings::getInt('ap_learn_fast_min', 2)));
    }

    /* =================================================================
     *  Normalising — what an example may keep
     * ================================================================= */

    /**
     * The message without anyone's details: lower case, Devanagari digits
     * as ASCII, and placeholders for PNRs, codes, phone numbers, amounts,
     * dates, references, e-mails, links and names ("naam Sita Thapa" →
     * "naam <NAME>"). With the intent the personal values it names (the
     * passenger, the person searched for) are taken out too. Small numbers
     * (seats, days, a menu digit) stay — they are part of what was meant.
     * Idempotent: norm(norm(x)) === norm(x).
     *
     * @param array<string, mixed> $intent
     */
    public static function norm(string $text, array $intent = []): string
    {
        $s = mb_strtolower(strtr($text, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']));
        $s = (string) preg_replace_callback('/<(' . strtolower(implode('|', self::SLOTS)) . ')>/u', static fn(array $m): string => '<' . strtoupper($m[1]) . '>', $s);
        $nb = '(?<![\p{L}\p{M}\p{N}])';                      // not inside a word
        $na = '(?![\p{L}\p{M}\p{N}])';
        $rules = [
            '#(?:https?://|www\.)\S+#u'                                                       => ' <URL> ',
            '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[a-z]{2,}/u'                                 => ' <EMAIL> ',
            '/' . $nb . 'shg\s*-\s*s\s*-\s*\d+' . $na . '/u'                                   => ' <CODE> ',   // staff number
            '/' . $nb . 'shg-[a-z0-9]+(?:-[a-z0-9]+)+' . $na . '/u'                            => ' <PNR> ',
            '/' . $nb . '(?:shg|agent(?:\s*code)?)\s*[-:#]?\s*\d{1,6}' . $na . '/u'            => ' <CODE> ',   // agent code
            '/' . $nb . '[a-z]{1,5}-[a-z]*\d[a-z0-9]*' . $na . '/u'                             => ' <CODE> ',   // AG-RAM27 …
        ];
        foreach ($rules as $re => $to) {
            $s = (string) preg_replace($re, $to, $s);
        }
        // a reference after UTR / ref / txn (it has a digit in it)
        $s = (string) preg_replace_callback('/' . $nb . '(reference|utr|ref|txn|रेफ)\s*(?:no\.?|number)?\s*[:#-]?\s*([a-z0-9-]{6,30})' . $na . '/u',
            static fn(array $m): string => preg_match('/\d/', $m[2]) === 1 ? $m[1] . ' <REF> ' : $m[0], $s);
        // dates: 2026-11-02, 2/11, 02.11.2026, 2-11-2026, "2 Nov", "Nov 2"
        $mon = 'january|february|march|april|june|july|august|september|october|november|december|jan|feb|mar|apr|may|jun|jul|aug|sept|sep|oct|nov|dec';
        foreach (['/' . $nb . '\d{4}-\d{1,2}-\d{1,2}' . $na . '/u', '/' . $nb . '\d{1,2}[\/.]\d{1,2}(?:[\/.]\d{2,4})?' . $na . '/u',
                  '/' . $nb . '\d{1,2}-\d{1,2}-\d{2,4}' . $na . '/u',
                  '/' . $nb . '\d{1,2}\s*(?:st|nd|rd|th)?\s*(?:' . $mon . ')' . $na . '/u', '/' . $nb . '(?:' . $mon . ')\s*\d{1,2}' . $na . '/u'] as $re) {
            $s = (string) preg_replace($re, ' <DATE> ', $s);
        }
        // phone numbers and other long numbers: 10-13 digits a phone, longer a reference (account, Aadhaar …)
        $long = static function (array $m): string {
            $n = strlen((string) preg_replace('/\D/', '', $m[0]));
            return $n < 10 ? $m[0] : ($n <= 13 ? ' <PHONE> ' : ' <REF> ');
        };
        $s = (string) preg_replace_callback('/(?<!\d)\+?\d{10,}(?!\d)/u', $long, $s);
        $s = (string) preg_replace_callback('/(?<!\d)\+?\d{2,5}(?:[\s-]\d{2,5}){1,4}(?!\d)/u', $long, $s);
        // amounts: with a currency, or any number of 3+ digits
        $s = (string) preg_replace('/(?:' . $nb . '(?:rs\.?|inr|npr|रु\.?|रू\.?)|₹)\s*\d[\d,]*(?:\.\d+)?/u', ' <AMOUNT> ', $s);
        $s = (string) preg_replace('/' . $nb . '(?:\d{1,3}(?:,\d{2,3})+|\d{3,})(?:\.\d+)?' . $na . '/u', ' <AMOUNT> ', $s);
        // names after "name" / "naam": up to three words; after "names": the list
        $w = '[\p{L}\p{M}.\']+';
        $kw = '(?![\p{L}\p{M}])\s*(?:(?:is|are|ho|हो)(?![\p{L}\p{M}])|:|-|=)?\s*';
        $s = (string) preg_replace('/(' . $nb . '(?:names|naamharu|नामहरू)' . $kw . ')' . $w . '(?:(?:\s*,\s*|\s+(?:ra|र|and|और)\s+|\s+)' . $w . '){0,7}/u', '$1<NAME>', $s);
        $s = (string) preg_replace('/(' . $nb . '(?:name|naam|nam|नाम|नाउँ)' . $kw . ')' . $w . '(?:\s+' . $w . '){0,2}/u', '$1<NAME>', $s);
        // the personal values the intent names (the passenger, the person searched for)
        $vals = [];
        foreach (['name', 'query', 'which'] as $k) {
            $vals[] = (string) ($intent[$k] ?? '');
        }
        foreach ((array) ($intent['names'] ?? []) as $n) {
            $vals[] = is_scalar($n) ? (string) $n : '';
        }
        if (in_array((string) ($intent['field'] ?? ''), ['name', 'phone'], true)) {
            $vals[] = (string) ($intent['value'] ?? '');
        }
        foreach ($vals as $v) {
            foreach (preg_split('/[\s,.;:]+/u', mb_strtolower($v)) ?: [] as $word) {
                if (mb_strlen($word) >= 2 && preg_match('/^[\p{L}\p{M}\']+$/u', $word) === 1) {
                    $s = (string) preg_replace('/' . $nb . preg_quote($word, '/') . $na . '/u', ' <NAME> ', $s);
                }
            }
        }
        $s = (string) preg_replace('/[^\p{L}\p{M}\p{N}<>\s]+/u', ' ', $s);
        $s = (string) preg_replace('/(<(?:' . implode('|', self::SLOTS) . ')>)(?:\s*\1)+/u', '$1', $s);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return mb_substr($s, 0, 400);
    }

    /**
     * What an example keeps of an intent: the intent and the slots that are
     * no one's details (which field to change, the route direction, seats,
     * days, how they pay). PNR, phone, name, amount, date … come from the
     * NEXT message each time (fill()).
     *
     * @param array<string, mixed> $i
     * @return array<string, mixed>
     */
    public static function skeleton(array $i): array
    {
        $out = ['intent' => (string) ($i['intent'] ?? '')];
        $f = (string) ($i['field'] ?? '');
        if (in_array($f, ['name', 'phone', 'pickup', 'seat', 'date', 'gender', 'age'], true)) {
            $out['field'] = $f;
        }
        if (in_array((string) ($i['direction'] ?? ''), ['toNepal', 'toIndia'], true)) {
            $out['direction'] = (string) $i['direction'];
        }
        $seats = (int) ($i['seats'] ?? 0);
        if ($seats >= 1 && $seats <= 12) {
            $out['seats'] = $seats;
        }
        $days = (int) ($i['days'] ?? 0);
        if ($days >= 1 && $days <= 366) {
            $out['days'] = $days;
        }
        if (in_array((string) ($i['pay'] ?? ''), ['cash', 'upi', 'esewa', 'bank'], true)) {
            $out['pay'] = (string) $i['pay'];
        }
        return $out;
    }

    /** Same meaning: the intent, and for an edit the field. */
    public static function sameIntent(array $a, array $b): bool
    {
        return (string) ($a['intent'] ?? '') !== '' && (string) ($a['intent'] ?? '') === (string) ($b['intent'] ?? '')
            && ((string) ($a['intent'] ?? '') !== 'edit' || (string) ($a['field'] ?? '') === (string) ($b['field'] ?? ''));
    }

    /* =================================================================
     *  Learning
     * ================================================================= */

    /**
     * Remember that this message meant this intent. Called by ApWa when a
     * typed command resolved a member's message (parser), when an AI / fast
     * reading was confirmed with "ho" (confirmed), and by the office's
     * "teach" form (office). A different reading of the same words replaces
     * the old one unless the office taught it (then the doubt only pauses
     * the fast path). Never throws.
     *
     * @param array<string, mixed> $intent
     */
    public static function record(string $text, array $intent, string $source): void
    {
        try {
            if (!self::on() || !in_array($source, self::SOURCES, true)) {
                return;
            }
            require_once __DIR__ . '/agentpro-brain.php';
            $skel = self::skeleton($intent);
            if (!in_array($skel['intent'], ApBrain::INTENTS, true) || in_array($skel['intent'], self::SKIP, true)) {
                return;
            }
            $norm = self::norm($text, $intent);
            // only placeholders and digits ("3", "<PNR>"): nothing to learn from
            if (preg_match('/\p{L}{2,}/u', (string) preg_replace('/<[A-Z]+>/', ' ', $norm)) !== 1) {
                return;
            }
            $hash = sha1($norm);
            $json = (string) json_encode($skel, JSON_UNESCAPED_UNICODE);
            $trusted = self::fastMin();
            $row = Database::fetch('SELECT id, intent_json, source, ok_hits FROM ap_wa_examples WHERE text_hash = :h', ['h' => $hash]);
            if ($row === null) {
                $id = Database::insertIgnore('ap_wa_examples', ['text_hash' => $hash, 'text_norm' => $norm, 'intent_json' => $json, 'source' => $source,
                    'hits' => 1, 'ok_hits' => $source === 'office' ? $trusted : 1, 'last_at' => date('Y-m-d H:i:s')]);
                if ($id > 0) {
                    return;
                }
                $row = Database::fetch('SELECT id, intent_json, source, ok_hits FROM ap_wa_examples WHERE text_hash = :h', ['h' => $hash]);
                if ($row === null) {
                    return;
                }
            }
            $old = json_decode((string) $row['intent_json'], true);
            if (self::sameIntent(is_array($old) ? $old : [], $skel)) {
                Database::run('UPDATE ap_wa_examples SET hits = hits + 1, ok_hits = GREATEST(ok_hits + 1, :floor), last_at = NOW(),
                                      source = IF(:src = \'office\', \'office\', source) WHERE id = :i',
                    ['floor' => $source === 'office' ? $trusted : 0, 'src' => $source, 'i' => (int) $row['id']]);
                return;
            }
            $rank = ['parser' => 2, 'confirmed' => 2, 'office' => 3];
            if ($rank[$source] >= ($rank[(string) $row['source']] ?? 0)) {
                // the newer certain reading wins; its practice record starts again
                Database::run('UPDATE ap_wa_examples SET intent_json = :j, source = :src, hits = hits + 1, ok_hits = :ok, px_n = 0, px_ok = 0,
                                      last_at = NOW() WHERE id = :i',
                    ['j' => $json, 'src' => $source, 'ok' => $source === 'office' ? $trusted : 1, 'i' => (int) $row['id']]);
            } else {
                // the office taught something else for these words: keep it, but the doubt pauses its fast path
                Database::run('UPDATE ap_wa_examples SET hits = hits + 1, ok_hits = GREATEST(CAST(ok_hits AS SIGNED) - 1, 0), last_at = NOW() WHERE id = :i',
                    ['i' => (int) $row['id']]);
            }
        } catch (Throwable $e) {
            Logger::warning('ApLearn::record skipped: ' . $e->getMessage(), [], 'agent');
        }
    }

    /**
     * The intent for a message the desk has been confirmed on often enough
     * (exact normalised match, ok_hits ≥ ap_learn_fast_min), with its
     * details read from THIS message — or null (ask the model). Only the
     * reading is skipped: the handler still asks "ho" for money / tickets.
     *
     * @return array<string, mixed>|null
     */
    public static function fast(string $text): ?array
    {
        try {
            if (!self::on() || !Settings::getBool('ap_learn_fast', true)) {
                return null;
            }
            $norm = self::norm($text);
            if ($norm === '') {
                return null;
            }
            $row = Database::fetch('SELECT id, intent_json, ok_hits FROM ap_wa_examples WHERE text_hash = :h', ['h' => sha1($norm)]);
            if ($row === null || (int) $row['ok_hits'] < self::fastMin()) {
                return null;
            }
            $skel = json_decode((string) $row['intent_json'], true);
            if (!is_array($skel) || !in_array((string) ($skel['intent'] ?? ''), self::FAST_INTENTS, true)) {
                return null;
            }
            $intent = self::fill($skel, $text);
            // fast_today before fast_day: MySQL assigns left to right, so it still sees yesterday's day
            Database::run('UPDATE ap_wa_examples SET fast_hits = fast_hits + 1, fast_today = IF(fast_day = CURDATE(), fast_today + 1, 1),
                                  fast_day = CURDATE(), last_at = NOW() WHERE id = :i', ['i' => (int) $row['id']]);
            return $intent + ['learned' => (int) $row['id']];
        } catch (Throwable $e) {
            Logger::warning('ApLearn::fast skipped: ' . $e->getMessage(), [], 'agent');
            return null;
        }
    }

    /**
     * A learnt intent with the details of THIS message: PNR, phone, seats,
     * date, pickup, direction, amount, days — checked by ApBrain::clean()
     * exactly like a model's answer.
     *
     * @param array<string, mixed> $skel
     * @return array<string, mixed>
     */
    public static function fill(array $skel, string $text): array
    {
        require_once __DIR__ . '/agentpro-brain.php';
        $d = array_intersect_key($skel, array_flip(['intent', 'field', 'direction', 'pay']));
        $t = strtr($text, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
        try {
            require_once __DIR__ . '/quickticket.php';
            require_once __DIR__ . '/ticketbot.php';
            $p = TicketBot::parse($text);
            foreach (['seats', 'date', 'boarding', 'phone'] as $k) {
                if (!empty($p[$k])) {
                    $d[$k] = $p[$k];
                }
            }
            if (!empty($p['direction']) && empty($d['direction'])) {
                $d['direction'] = $p['direction'];
            }
        } catch (Throwable $e) {
            // the intent alone still answers; the handler asks for the rest
        }
        if (empty($d['seats']) && !empty($skel['seats'])) {
            $d['seats'] = (int) $skel['seats'];              // the words (and so the number) are the same as the example's
        }
        $rest = (string) preg_replace(['/\bSHG-[A-Z0-9]+(?:-[A-Z0-9]+)+\b/i', '/\+?\d[\d\s-]{8,}\d/'], ' ', $t);
        if (in_array($d['intent'], ['withdraw', 'deposit'], true)
            && preg_match('/(?:rs\.?|₹|inr|रु\.?)?\s*(?<![\d.])(\d{1,3}(?:,\d{2,3})+|\d{2,7})(?:\.\d{1,2})?(?![\d])/iu', $rest, $m) === 1) {
            $d['amount'] = (float) str_replace(',', '', $m[1]);
        }
        if ($d['intent'] === 'statement' && preg_match('/(?<![\d])(\d{1,3})(?![\d])/', $rest, $m) === 1) {
            $d['days'] = (int) $m[1];
        }
        return ApBrain::clean($d, $text);
    }

    /* =================================================================
     *  Few-shot examples for the model
     * ================================================================= */

    /** @return array<string, int> */
    private static function tokens(string $norm): array
    {
        $out = [];
        foreach (preg_split('/\s+/u', $norm) ?: [] as $w) {
            if (mb_strlen($w) >= 2) {
                $out[$w] = 1;
            }
        }
        return $out;
    }

    /**
     * The k confirmed examples most like this message (word overlap), the
     * ones the model got wrong in practice a little first.
     *
     * @return list<array{text: string, intent: array<string, mixed>}>
     */
    public static function shots(string $text, int $k = 6, string $skipHash = ''): array
    {
        try {
            if ($k <= 0 || !self::on()) {
                return [];
            }
            $mine = self::tokens(self::norm($text));
            if ($mine === []) {
                return [];
            }
            $rows = Database::fetchAll('SELECT text_hash, text_norm, intent_json, ok_hits, px_n, px_ok FROM ap_wa_examples
                                         WHERE ok_hits >= 1 ORDER BY last_at DESC LIMIT 600');
            $scored = [];
            foreach ($rows as $r) {
                if ($skipHash !== '' && hash_equals($skipHash, (string) $r['text_hash'])) {
                    continue;
                }
                $theirs = self::tokens((string) $r['text_norm']);
                $both = count(array_intersect_key($mine, $theirs));
                if ($both === 0) {
                    continue;
                }
                $skel = json_decode((string) $r['intent_json'], true);
                if (!is_array($skel) || (string) ($skel['intent'] ?? '') === '') {
                    continue;
                }
                $score = $both / count($mine + $theirs)
                    + ((int) $r['px_n'] > (int) $r['px_ok'] ? 0.05 : 0.0)      // the model got it wrong before: show it
                    + min(5, (int) $r['ok_hits']) * 0.004;
                $scored[] = ['s' => $score, 'text' => (string) $r['text_norm'], 'intent' => $skel];
            }
            usort($scored, static fn(array $a, array $b): int => $b['s'] <=> $a['s']);
            return array_map(static fn(array $x): array => ['text' => $x['text'], 'intent' => $x['intent']], array_slice($scored, 0, $k));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** shots() as compact prompt lines ('' when there are none). */
    public static function shotsText(string $text, int $k = 6, string $skipHash = ''): string
    {
        $k = max(0, min(12, Settings::getInt('ap_learn_shots', $k)));
        $shots = self::shots($text, $k, $skipHash);
        if ($shots === []) {
            return '';
        }
        $l = ['Messages like these were confirmed before (<PNR>, <PHONE>, <AMOUNT>, <DATE>, <CODE>, <NAME> stand for real values):'];
        foreach ($shots as $s) {
            $l[] = '- "' . mb_substr($s['text'], 0, 140) . '" → ' . json_encode($s['intent'], JSON_UNESCAPED_UNICODE);
        }
        return implode("\n", $l) . "\n";
    }

    /* =================================================================
     *  Practice — replay examples through the model while it is idle
     * ================================================================= */

    /** WhatsApp needs the model: a chat turn waits for it, or messages are queued for the worker. */
    private static function whatsappBusy(): bool
    {
        try {
            if (ApAi::someoneWaiting()) {
                return true;
            }
            return Database::exists("SELECT 1 FROM ap_jobs WHERE status IN ('queued','running') AND kind <> 'doc'");
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Re-read a sample of the examples with the VPS model (the same prompt a
     * member's message gets, few-shot included but never the example itself),
     * compare the intents, store one ap_ai_practice row and return the
     * summary. Never inside a WhatsApp turn: each call is a document-class
     * call (purpose "benchmark"), so it waits only while no chat turn waits,
     * hangs up the moment one starts waiting, and the run stops there.
     *
     * @param Closure|null $reader fn(string $textNorm, string $shots, int $timeout): ?array — tests; default the model
     * @return array{ok: bool, n: int, correct: int, pct: int, avg_ms: int, p50_ms: int, model: string, notes: string,
     *               skipped: string, misses: list<array{text: string, want: string, got: string}>}
     */
    public static function practice(int $seconds = 900, ?Closure $reader = null): array
    {
        $t0 = microtime(true);
        $seconds = max(10, min(3600, $seconds));
        $res = ['ok' => false, 'n' => 0, 'correct' => 0, 'pct' => 0, 'avg_ms' => 0, 'p50_ms' => 0, 'model' => '', 'notes' => '', 'skipped' => '', 'misses' => []];
        if (!self::ready()) {
            $res['skipped'] = 'the learning tables are not installed';
            return $res;
        }
        require_once __DIR__ . '/agentpro-ai.php';
        require_once __DIR__ . '/agentpro-brain.php';
        $reader ??= self::$reader;
        $model = $reader === null;
        if ($model) {
            if (ApAi::base() === '' || !ApAi::available(true)) {
                $res['skipped'] = 'the model server is not answering';
                return $res;
            }
            $res['model'] = ApAi::pickModel('chat');
            if ($res['model'] === '') {
                $res['skipped'] = 'no model is installed';
                return $res;
            }
            if (self::whatsappBusy()) {
                $res['skipped'] = 'WhatsApp is using the model';
                return $res;
            }
            $reader = static fn(string $text, string $shots, int $timeout): ?array => ApBrain::practiceRead($text, $shots, $timeout);
        } else {
            $res['model'] = 'stub';
        }
        try {
            // never-practised examples first, then a random sample
            $rows = Database::fetchAll('SELECT id, text_hash, text_norm, intent_json FROM ap_wa_examples WHERE ok_hits >= 1
                                         ORDER BY (px_n = 0) DESC, RAND() LIMIT 120');
        } catch (Throwable $e) {
            $res['skipped'] = 'examples could not be read';
            return $res;
        }
        if ($rows === []) {
            $res['skipped'] = 'no examples yet';
            return $res;
        }
        $ms = [];
        $correct = 0;
        $notes = [];
        $missCount = [];
        foreach ($rows as $r) {
            $want = json_decode((string) $r['intent_json'], true);
            if (!is_array($want) || in_array((string) ($want['intent'] ?? ''), array_merge(self::SKIP, ['']), true)) {
                continue;
            }
            $spent = microtime(true) - $t0;
            $each = $ms !== [] ? array_sum($ms) / count($ms) / 1000 : 15.0;      // seconds one reading takes on this box, so far
            if ($spent + $each > $seconds) {
                $notes[] = 'time budget used';
                break;
            }
            if ($model && self::whatsappBusy()) {
                $notes[] = 'gave way to WhatsApp';
                break;
            }
            $timeout = (int) max(10, min(ApAi::docTimeout(), $seconds - $spent));
            $s = microtime(true);
            $got = $reader((string) $r['text_norm'], self::shotsText((string) $r['text_norm'], 6, (string) $r['text_hash']), $timeout);
            $took = (int) round((microtime(true) - $s) * 1000);
            if ($got === null && $model && (ApAi::lastPreempted() || ApAi::lastBusy())) {
                $notes[] = 'gave way to WhatsApp';
                break;                                       // not the model's mistake: not counted
            }
            $ok = is_array($got) && self::sameIntent($want, $got);
            $ms[] = $took;
            if ($ok) {
                $correct++;
            } else {
                $w = (string) $want['intent'] . (isset($want['field']) ? ':' . $want['field'] : '');
                $g = is_array($got) ? (string) ($got['intent'] ?? '?') . (!empty($got['field']) ? ':' . $got['field'] : '') : 'no answer';
                $missCount[$w . '→' . $g] = ($missCount[$w . '→' . $g] ?? 0) + 1;
                if (count($res['misses']) < 10) {
                    $res['misses'][] = ['text' => (string) $r['text_norm'], 'want' => $w, 'got' => $g];
                }
            }
            try {
                Database::run('UPDATE ap_wa_examples SET px_n = px_n + 1, px_ok = px_ok + :ok WHERE id = :i', ['ok' => $ok ? 1 : 0, 'i' => (int) $r['id']]);
            } catch (Throwable $e) {
                // the run's own row below still says how it went
            }
        }
        $n = count($ms);
        if ($n === 0) {
            $res['skipped'] = $notes !== [] ? $notes[0] : 'nothing to practise';
            return $res;
        }
        sort($ms);
        arsort($missCount);
        $missLine = [];
        foreach (array_slice($missCount, 0, 6, true) as $k => $c) {
            $missLine[] = $k . ($c > 1 ? ' ×' . $c : '');
        }
        if ($missLine !== []) {
            $notes[] = 'missed: ' . implode(', ', $missLine);
        }
        $res = ['ok' => true, 'n' => $n, 'correct' => $correct, 'pct' => (int) round($correct * 100 / $n), 'avg_ms' => (int) round(array_sum($ms) / $n),
                'p50_ms' => $ms[intdiv($n - 1, 2)], 'notes' => implode('; ', array_unique($notes))] + $res;
        try {
            Database::insert('ap_ai_practice', ['model' => mb_substr($res['model'], 0, 80), 'n' => $n, 'correct' => $correct, 'avg_ms' => $res['avg_ms'],
                'p50_ms' => $res['p50_ms'], 'notes' => $res['notes'] !== '' ? mb_substr($res['notes'], 0, 500) : null]);
        } catch (Throwable $e) {
            Logger::warning('Practice row not saved: ' . $e->getMessage(), [], 'agent');
        }
        return $res;
    }

    /**
     * Start a practice run in the background now (the admin "Practice now"
     * button); false when this PHP may not start one.
     */
    public static function kick(int $seconds = 60): bool
    {
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)
            || DIRECTORY_SEPARATOR === '\\') {
            return false;
        }
        $php = trim(Settings::getString('ap_php_cli', ''));
        if ($php === '') {
            $php = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
        }
        @exec(escapeshellarg($php) . ' ' . escapeshellarg(ROOT_PATH . '/cron/agentpro-practice.php') . ' --now --seconds=' . max(10, min(900, $seconds))
            . ' > /dev/null 2>&1 &');
        return true;
    }

    /** Is $hour (0-23) inside "1-5" / "23-2" / "1-5,13"? */
    public static function inHours(string $spec, int $hour): bool
    {
        foreach (explode(',', $spec) as $part) {
            $part = trim($part);
            if (preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $part, $m) === 1) {
                [$a, $b] = [(int) $m[1], (int) $m[2]];
                if ($a <= $b ? ($hour >= $a && $hour <= $b) : ($hour >= $a || $hour <= $b)) {
                    return true;
                }
            } elseif (preg_match('/^\d{1,2}$/', $part) === 1 && (int) $part === $hour) {
                return true;
            }
        }
        return false;
    }

    /* =================================================================
     *  The office's view (admin/ai-local.php)
     * ================================================================= */

    /**
     * @return array{ready: bool, bySource: array<string, int>, total: int, trusted: int, fastToday: int, fastAll: int,
     *               runs: list<array<string, mixed>>}
     */
    public static function stats(): array
    {
        $out = ['ready' => self::ready(), 'bySource' => ['parser' => 0, 'confirmed' => 0, 'office' => 0], 'total' => 0, 'trusted' => 0,
                'fastToday' => 0, 'fastAll' => 0, 'runs' => []];
        if (!$out['ready']) {
            return $out;
        }
        try {
            foreach (Database::fetchAll('SELECT source, COUNT(*) AS n FROM ap_wa_examples GROUP BY source') as $r) {
                $out['bySource'][(string) $r['source']] = (int) $r['n'];
            }
            $out['total'] = array_sum($out['bySource']);
            $out['trusted'] = (int) Database::scalar('SELECT COUNT(*) FROM ap_wa_examples WHERE ok_hits >= :m', ['m' => self::fastMin()], 0);
            $out['fastToday'] = (int) Database::scalar('SELECT COALESCE(SUM(fast_today), 0) FROM ap_wa_examples WHERE fast_day = CURDATE()', [], 0);
            $out['fastAll'] = (int) Database::scalar('SELECT COALESCE(SUM(fast_hits), 0) FROM ap_wa_examples', [], 0);
            $out['runs'] = Database::fetchAll('SELECT * FROM ap_ai_practice ORDER BY id DESC LIMIT 14');
        } catch (Throwable $e) {
            // the card shows zeros
        }
        return $out;
    }

    /** The latest examples, for the office to check (and remove a wrong one). @return list<array<string, mixed>> */
    public static function latest(int $limit = 10): array
    {
        if (!self::ready()) {
            return [];
        }
        try {
            return Database::fetchAll('SELECT id, text_norm, intent_json, source, hits, ok_hits, fast_hits, px_n, px_ok, last_at
                                         FROM ap_wa_examples ORDER BY last_at DESC LIMIT ' . max(1, min(50, $limit)));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Remove one example (a wrong one the office spotted). */
    public static function forget(int $id): bool
    {
        return self::ready() && Database::delete('ap_wa_examples', 'id = :i', ['i' => $id]) === 1;
    }
}
