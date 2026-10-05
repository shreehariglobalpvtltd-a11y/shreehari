<?php
/**
 * =====================================================================
 *  AiLearn — the assistant learns from what customers ask (6 Oct 2026).
 *
 *  Owner (6 Oct): "AI afai sikne bujhne."
 *
 *    observe()  every customer question on WhatsApp / the web chat that
 *               the knowledge base (AiKb) has no article for is written
 *               down (redacted, counted) in ai_unanswered_questions —
 *               the same queue the 📚 AI Knowledge page already shows.
 *    tick()     once a night (cron/campaigns.php, after ai_learn_hour)
 *               the one AI (AiBrain) drafts an article for the most-asked
 *               of them, using ONLY the live company facts the chat
 *               assistant itself is given (ai_system_prompt()).
 *
 *  A learned answer may NOT carry a fare, a time, a date or a count —
 *  those change, and the assistant already reads them live — so a draft
 *  with any number other than the office phone is thrown away. If the
 *  facts do not answer the question it is marked needs_owner instead of
 *  guessed. Drafts wait for one tap on 🤖 AI Marketing → "AI ले सिकेको"
 *  unless ai_learn_autopublish is on (then a question asked 3+ times is
 *  published straight away).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/aiknowledge.php';

final class AiLearn
{
    private const SCOPE = 'ai_learn';

    private function __construct() {}

    public static function on(): bool
    {
        return Settings::getBool('ai_learn_on', true);
    }

    /** Does this look like a question worth an article (not "hi", "ok", a PNR or a seat order)? */
    public static function isQuestion(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if (mb_strlen($t) < 8 || mb_strlen($t) > 400) {
            return false;
        }
        if (preg_match('/\bshg[- ]?\d|\b\d{10}\b/iu', $t) === 1) {
            return false;                      // a booking number / phone = their own case, not general knowledge
        }
        return preg_match('/[?？]|(?:^|\s)(?:k[ae]ti|kitna|kitne|kahile|kab|kaha|kahan|kasari|kaise|kun|kaun|kya|ke\s|k\s|how|when|where|what|which|can i|do you|is there|milcha|milchha|milega|huncha|hunchha|hoga|parcha|padcha|chahincha|chaiyo)(?:\s|$)|कति|कहिले|कहाँ|कसरी|कुन|के\s|कितना|कब|कहां|कैसे|क्या|मिल्छ|हुन्छ|पर्छ|चाहिन्छ|मिलेगा/u', $t) === 1;
    }

    /** Record a customer question the knowledge base cannot answer yet. Never throws. */
    public static function observe(string $text): void
    {
        try {
            if (!self::on() || !self::isQuestion($text) || !Settings::getBool('ai_kb_on', false)) {
                return;
            }
            if (self::known($text)) {
                return;
            }
            $lang = preg_match('/\p{Devanagari}/u', $text) === 1 ? 'ne' : 'en';
            AiKb::logUnknown($text, $lang);
        } catch (Throwable $e) {
            // learning must never cost a reply
        }
    }

    /** Words that say nothing about the topic (roman Nepali / Hindi / English / Devanagari). */
    private const STOP = ['bus', 'gadi', 'gaadi', 'cha', 'chha', 'huncha', 'hunchha', 'milcha', 'milchha', 'nai', 'lai', 'pani', 'kati', 'keti',
        'kaha', 'kahan', 'kahile', 'kasari', 'kun', 'kaun', 'kya', 'kitna', 'kitne', 'kab', 'kaise', 'hai', 'hoga', 'milega', 'sakincha', 'parcha',
        'padcha', 'chahincha', 'chaiyo', 'the', 'and', 'for', 'you', 'your', 'can', 'how', 'what', 'when', 'where', 'which', 'with', 'are', 'does',
        'there', 'this', 'that', 'please', 'sir', 'dai', 'bhai', 'hajur', 'mero', 'hamro', 'tapai', 'tapaai', 'ticket', 'टिकट', 'बस', 'गाडी',
        'हुन्छ', 'मिल्छ', 'कति', 'कहाँ', 'कहिले', 'कसरी', 'कुन', 'लाई', 'पनि', 'हुनुहुन्छ', 'सकिन्छ', 'पर्छ', 'चाहिन्छ', 'कितना', 'कहां', 'कैसे',
        'क्या', 'है', 'मिलेगा', 'होगा', 'हामी', 'तपाईं', 'मेरो'];

    /**
     * Is there a published article that is really about this question?
     * AiKb::search counts any one shared word (it is the assistant's wide
     * net); here at least half of the question's topic words must be in it.
     */
    public static function known(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        $words = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{M}\p{N}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $w): bool => mb_strlen($w) >= 3 && !in_array($w, self::STOP, true)
        )));
        $hits = AiKb::search($text, 'public', 3);
        if ($hits === []) {
            return false;
        }
        if ($words === []) {
            return true;                       // nothing topical to learn from
        }
        $need = max(1, (int) ceil(count($words) / 2));
        foreach ($hits as $a) {
            $hay = mb_strtolower(implode(' ', array_map(static fn ($k) => (string) ($a[$k] ?? ''),
                ['canonical_title', 'keywords', 'synonyms', 'roman_nepali_examples', 'roman_hindi_examples', 'canonical_answer', 'nepali_content', 'hindi_content', 'english_content'])));
            $terms = array_flip(preg_split('/[^\p{L}\p{M}\p{N}]+/u', $hay, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $got = 0;
            foreach ($words as $w) {
                if (isset($terms[$w])) {
                    $got++;
                }
            }
            if ($got >= $need) {
                return true;
            }
        }
        return false;
    }

    /**
     * Nightly: draft articles for the most-asked open questions.
     *
     * @return array<string,mixed>
     */
    public static function tick(): array
    {
        if (!self::on()) {
            return ['learn' => 'off'];
        }
        if ((int) date('G') < max(0, min(23, Settings::getInt('ai_learn_hour', 2))) || self::kv('day') === date('Y-m-d')) {
            return ['learn' => 'waiting'];
        }
        self::kvSet('day', date('Y-m-d'));
        return ['learn' => 'ran'] + self::learn(max(1, min(20, Settings::getInt('ai_learn_per_night', 6))));
    }

    /**
     * @return array{drafted:int, published:int, needs_owner:int, skipped:int}
     */
    public static function learn(int $max): array
    {
        $out = ['drafted' => 0, 'published' => 0, 'needs_owner' => 0, 'skipped' => 0];
        try {
            require_once __DIR__ . '/aibrain.php';
            if (!AiBrain::configured()) {
                return $out;
            }
            $rows = Database::fetchAll(
                "SELECT id, normalized_question, language, frequency FROM ai_unanswered_questions
                  WHERE status = 'new' ORDER BY frequency DESC, updated_at DESC LIMIT " . max(1, $max)
            );
        } catch (Throwable $e) {
            Logger::exception($e, 'ai');
            return $out;
        }
        foreach ($rows as $q) {
            $r = self::draft($q);
            $out[$r]++;
        }
        $h = json_decode(self::kv('log'), true);
        $h = is_array($h) ? $h : [];
        array_unshift($h, ['at' => date('Y-m-d H:i:s')] + $out);
        self::kvSet('log', json_encode(array_slice($h, 0, 30)) ?: '[]');
        return $out;
    }

    /** One question → 'drafted' | 'published' | 'needs_owner' | 'skipped'. */
    public static function draft(array $q): string
    {
        $id = (int) $q['id'];
        $question = (string) $q['normalized_question'];
        require_once __DIR__ . '/aiprompt.php';
        $phone = Settings::officePhone();
        $sys = ai_system_prompt() . "\n\n=== TASK: WRITE A KNOWLEDGE-BASE ARTICLE ===\n"
             . "A customer asked the question below and we had no written answer. Using ONLY the facts above, write one.\n"
             . "Reply with JSON only: {\"answerable\":true|false,\"title\":\"short English title\",\"answer_en\":\"2-5 sentences\","
             . "\"answer_ne\":\"same in natural Nepali (Devanagari)\",\"keywords\":\"comma separated words a customer might type, incl. roman Nepali/Hindi\"}\n"
             . "RULES: answerable=false when the facts above do not clearly answer it — never guess.\n"
             . "Do NOT put any fare, price, time, date, seat number or count in the answer: those change and the assistant reads them live. "
             . "Say 'see the current fare on the website / ask on WhatsApp' instead. The only number allowed is the office phone"
             . ($phone !== '' ? ' ' . $phone : '') . ". No links except " . appUrl('') . '.';
        $r = null;
        try {
            $r = AiBrain::text($sys, [['role' => 'user', 'content' => 'Customer question: ' . $question]], 900, 'learn');
        } catch (Throwable $e) {
            $r = null;
        }
        if ($r === null) {
            return 'skipped';
        }
        $raw = trim((string) $r['text']);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw) ?? $raw;
        $j = json_decode($raw, true);
        if (!is_array($j) && preg_match('/\{.*\}/s', $raw, $m) === 1) {
            $j = json_decode($m[0], true);
        }
        if (!is_array($j)) {
            return 'skipped';
        }
        if (empty($j['answerable'])) {
            self::mark($id, 'needs_owner', null);
            return 'needs_owner';
        }
        $title = trim((string) ($j['title'] ?? ''));
        $en = trim((string) ($j['answer_en'] ?? ''));
        $ne = trim((string) ($j['answer_ne'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200 || mb_strlen($en) < 20 || mb_strlen($en) > 2000 || mb_strlen($ne) > 3000) {
            return 'skipped';
        }
        if (!self::numbersOk($en . ' ' . $ne, $phone)) {
            self::mark($id, 'needs_owner', null);       // it wanted a number we will not freeze into an article
            return 'needs_owner';
        }
        $publish = Settings::getBool('ai_learn_autopublish', false) && (int) $q['frequency'] >= 3;
        try {
            $aid = AiKb::save([
                'category'           => 'learned',
                'canonical_title'    => $title,
                'canonical_answer'   => $en,
                'english_content'    => $en,
                'nepali_content'     => $ne,
                'keywords'           => mb_substr(trim((string) ($j['keywords'] ?? '')) . ', ' . $question, 0, 2000),
                'applicable_roles'   => ['public', 'customer'],
                'source_reference'   => 'AI learned from customer question #' . $id . ' (' . date('Y-m-d') . ', ' . $r['provider'] . ')',
                'publication_status' => $publish ? 'published' : 'draft',
            ], 0);
            Database::update('ai_kb_articles', ['source_type' => 'ai_learned'], 'id = :id', ['id' => $aid]);
            self::mark($id, $publish ? 'answered' : 'drafted', $aid);
            return $publish ? 'published' : 'drafted';
        } catch (Throwable $e) {
            Logger::exception($e, 'ai');
            return 'skipped';
        }
    }

    /** Only the office phone may appear as a number in a learned article. */
    public static function numbersOk(string $text, string $phone): bool
    {
        $t = strtr($text, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
        $p = preg_replace('/\D/', '', $phone) ?? '';
        if ($p !== '') {
            $t = str_replace([$phone, '+' . $p, $p, substr($p, -10)], ' ', $t);
            $t = preg_replace('/\+?' . implode('[\s\-]?', str_split(substr($p, -10))) . '/', ' ', $t) ?? $t;
            $t = preg_replace('/\+?9\s?1[\s\-]*/', ' ', $t) ?? $t;
        }
        $t = str_replace(['24/7', '24x7', '24 घण्टा', '24 hours'], ' ', $t);
        return preg_match('/\d/', $t) !== 1;
    }

    private static function mark(int $id, string $status, ?int $articleId): void
    {
        Database::update('ai_unanswered_questions', ['status' => $status, 'article_id' => $articleId], 'id = :id', ['id' => $id]);
    }

    /** @return array{open:int, drafted:int, needs_owner:int, learned_live:int, learned_draft:int} */
    public static function counts(): array
    {
        $o = ['open' => 0, 'drafted' => 0, 'needs_owner' => 0, 'learned_live' => 0, 'learned_draft' => 0];
        try {
            foreach (Database::fetchAll('SELECT status, COUNT(*) n FROM ai_unanswered_questions GROUP BY status') as $r) {
                $k = ['new' => 'open', 'drafted' => 'drafted', 'needs_owner' => 'needs_owner'][$r['status']] ?? null;
                if ($k !== null) {
                    $o[$k] = (int) $r['n'];
                }
            }
            foreach (Database::fetchAll("SELECT publication_status s, COUNT(*) n FROM ai_kb_articles WHERE source_type = 'ai_learned' GROUP BY s") as $r) {
                if ($r['s'] === 'published') {
                    $o['learned_live'] = (int) $r['n'];
                } elseif ($r['s'] === 'draft') {
                    $o['learned_draft'] = (int) $r['n'];
                }
            }
        } catch (Throwable $e) {
            // tables missing → zeros
        }
        return $o;
    }

    public static function history(): array
    {
        $h = json_decode(self::kv('log'), true);
        return is_array($h) ? $h : [];
    }

    private static function kv(string $key): string
    {
        try {
            return (string) (Database::fetch('SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => self::SCOPE, 'k' => $key])['kvalue'] ?? '');
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function kvSet(string $key, string $value): void
    {
        Database::run(
            'INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES (:s, :k, :v, :u)
             ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = VALUES(updated_by)',
            ['s' => self::SCOPE, 'k' => $key, 'v' => $value, 'u' => 'ai_learn']
        );
    }
}
