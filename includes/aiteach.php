<?php
/**
 * =====================================================================
 *  AiTeach — Claude teaches the free local bot (1 Nov 2026).
 *
 *  Owner: "Claude ko API cha, $100 jati cha — tesle mero app ko barema
 *  full training dirakhos, Hindi English Nepali ma; local model le free
 *  ma pathaos."
 *
 *  The cheapest answer the company can give is one from the knowledge
 *  base (ai_kb_articles): WaFaq answers a strong match on this VPS with no
 *  AI call at all, and the agents' knowledge_lookup reads the same rows.
 *  So the best use of a paid model is to WRITE that knowledge once — well,
 *  in three languages, with the romanised phrasings people really type —
 *  and let the free path answer it forever after.
 *
 *  One run (cron/ai-teach.php, nightly):
 *    write   Claude (Opus 5.5) reads the company facts — the same briefing
 *            the assistant answers from (ai_system_prompt()) plus every
 *            published article — and writes up to 6 NEW articles that are
 *            not there yet, for passengers, agents or staff, answering the
 *            open customer questions where the facts allow.
 *    check   AiLearn::numbersOk — no fare, time, date or count frozen into
 *            an article (the assistant reads those live), only the office
 *            phone; then a second Claude pass confirms every sentence is
 *            backed by the facts.
 *    save    checked → published at once (ai_teach_autopublish, default on:
 *            the owner asked for no approvals); anything doubtful → a draft
 *            on 🤖 AI Marketing → "AI ले सिकेको", the queue AiLearn uses.
 *
 *  Money: every response's usage is priced and added up in kv_store; a run
 *  stops at ai_teach_run_usd (2) and everything stops at ai_teach_budget_usd
 *  (95 — owner: "teach until the Claude credit is gone"). The facts block is prompt-cached, so the second and later calls of
 *  a run read it at a tenth of the price.
 *
 *  Never throws at its caller; a failed call ends the run and is logged.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiTeach
{
    private const SCOPE      = 'ai_teach';
    private const MAX_ROUNDS = 8;

    /** $ per million tokens: [input, output]. Cache write = 1.25x input, cache read = 0.1x. */
    private const PRICES = [
        'claude-opus-5-5'   => [4.0, 20.0],
        'claude-opus-5'     => [5.0, 25.0],
        'claude-opus-4-6'   => [5.0, 25.0],
        'claude-sonnet-5-5' => [2.0, 10.0],
        'claude-sonnet-4-6' => [3.0, 15.0],
    ];

    /** Test seams — null in production. */
    public static ?Closure $http  = null;   // fn(array $body): array{ok, http, body}
    public static ?Closure $facts = null;   // fn(): array{facts: string, titles: list<string>, questions: list<string>}
    public static ?Closure $store = null;   // fn(array $article, bool $publish): int
    public static ?array   $kv    = null;   // spend / log in memory instead of kv_store
    public static ?Closure $desk  = null;   // fn(): array{0: string desk guide, 1: array intent schema}
    public static ?Closure $learn = null;   // fn(string $text, array $intent): bool — store one desk example

    private function __construct() {}

    public static function on(): bool
    {
        return Settings::getBool('ai_teach_on', true) && self::key() !== '' && function_exists('curl_init');
    }

    public static function model(): string
    {
        $m = trim(Settings::getString('ai_teach_model', ''));
        return $m !== '' ? $m : 'claude-opus-5-5';
    }

    public static function budget(): float
    {
        return max(0.0, (float) Settings::getString('ai_teach_budget_usd', '95'));
    }

    public static function runBudget(): float
    {
        return max(0.05, (float) Settings::getString('ai_teach_run_usd', '3'));
    }

    public static function spent(): float
    {
        return (float) self::kvGet('spent', '0');
    }

    /** What one response cost, from the usage block the API returns. */
    public static function cost(array $usage, string $model): float
    {
        [$in, $out] = self::PRICES[$model] ?? self::PRICES['claude-opus-5-5'];
        $tokens = (int) ($usage['input_tokens'] ?? 0) * $in
            + (int) ($usage['cache_creation_input_tokens'] ?? 0) * $in * 1.25
            + (int) ($usage['cache_read_input_tokens'] ?? 0) * $in * 0.1
            + (int) ($usage['output_tokens'] ?? 0) * $out;
        return $tokens / 1_000_000;
    }

    /* =================================================================
     *  One run
     * ================================================================= */

    /**
     * @return array{ok: bool, rounds: int, written: int, published: int, drafted: int, rejected: int, cost: float, spent: float, note: string}
     */
    public static function run(?float $runBudget = null, bool $dry = false): array
    {
        $out = ['ok' => false, 'rounds' => 0, 'written' => 0, 'published' => 0, 'drafted' => 0, 'rejected' => 0, 'examples' => 0,
                'cost' => 0.0, 'spent' => self::spent(), 'note' => ''];
        if (!self::on()) {
            $out['note'] = 'off or no Anthropic key';
            return $out;
        }
        require_once __DIR__ . '/ailearn.php';
        $runBudget = $runBudget ?? self::runBudget();
        // By reference: the run's own spend must count as it grows (an arrow fn would freeze it at 0).
        $left = static function () use (&$out, $runBudget): float {
            return min($runBudget - $out['cost'], self::budget() - self::spent());
        };
        if ($left() <= 0.01) {
            $out['note'] = 'budget used up ($' . number_format(self::spent(), 2) . ' of $' . number_format(self::budget(), 2) . ')';
            return $out;
        }

        // FIRST (one cheap call, so the article rounds can never starve it): the VPS model's own lesson: example messages for the WhatsApp desk, each with
        // the reading the desk should make. Stored as source 'teacher' (one OK mark) — the
        // local model sees them as few-shot examples and in its nightly practice, and one
        // only skips the model after a real member confirms it.
        if ($left() > 0.1 && Settings::getBool('ai_teach_examples', true)) {
            $e = self::teachExamples($dry);
            $out['cost'] += $e['cost'];
            $out['examples'] = $e['stored'];
            if ($e['note'] !== '' && $out['note'] === '') {
                $out['note'] = $e['note'];
            }
        }

        $f = self::factsNow();
        $titles = array_map('mb_strtolower', $f['titles']);
        $phone = Settings::officePhone();

        for ($round = 1; $round <= self::MAX_ROUNDS && $left() > 0.05; $round++) {
            $out['rounds'] = $round;
            $w = self::ask($f['facts'], self::writeTask($f['titles'], $f['questions']), self::writeSchema(), 12000);
            $out['cost'] += $w['cost'];
            if ($w['json'] === null) {
                $out['note'] = $w['note'];
                break;
            }
            $batch = [];
            foreach ((array) ($w['json']['articles'] ?? []) as $a) {
                $title = trim((string) ($a['title'] ?? ''));
                $en    = trim((string) ($a['answer_en'] ?? ''));
                if ($title === '' || mb_strlen($en) < 20 || in_array(mb_strtolower($title), $titles, true)) {
                    continue;
                }
                $all = $en . ' ' . ($a['answer_ne'] ?? '') . ' ' . ($a['answer_hi'] ?? '');
                if (!AiLearn::numbersOk($all, $phone)) {
                    $out['rejected']++;                     // a fare / time / count would go stale
                    continue;
                }
                $batch[] = $a;
                $titles[] = mb_strtolower($title);
            }
            if ($batch === []) {
                $out['note'] = 'nothing new to write';
                break;
            }
            $out['written'] += count($batch);

            // Second pass: is every sentence backed by the facts?
            $supported = [];
            if ($left() > 0.02) {
                $v = self::ask($f['facts'], self::checkTask($batch), self::checkSchema(), 4000);
                $out['cost'] += $v['cost'];
                foreach ((array) ($v['json']['results'] ?? []) as $r) {
                    if (!empty($r['supported'])) {
                        $supported[(int) ($r['index'] ?? -1)] = true;
                    }
                }
            }
            foreach ($batch as $i => $a) {
                $publish = isset($supported[$i]) && Settings::getBool('ai_teach_autopublish', true);
                if (!$dry) {
                    try {
                        self::save($a, $publish);
                    } catch (Throwable $e) {
                        Logger::warning('AI teach: save failed: ' . $e->getMessage(), [], 'ai');
                        continue;
                    }
                }
                $out[$publish ? 'published' : 'drafted']++;
                $f['titles'][] = (string) $a['title'];
            }
        }

        if (!$dry) {
            self::kvSet('spent', (string) round(self::spent() + $out['cost'], 6));
            $log = json_decode(self::kvGet('log', '[]'), true);
            $log = is_array($log) ? $log : [];
            array_unshift($log, ['at' => date('Y-m-d H:i:s'), 'model' => self::model()] + array_diff_key($out, ['ok' => 1, 'spent' => 1]));
            self::kvSet('log', json_encode(array_slice($log, 0, 30)) ?: '[]');
        }
        $out['ok'] = true;
        $out['spent'] = self::spent();
        $out['cost'] = round($out['cost'], 4);
        return $out;
    }

    /** The last runs, newest first (admin / WhatsApp report). */
    public static function history(): array
    {
        $log = json_decode(self::kvGet('log', '[]'), true);
        return is_array($log) ? $log : [];
    }

    /**
     * One call: ~30 realistic desk messages with their intent JSON, cleaned
     * by the desk's own ApBrain::clean() and stored through ApLearn.
     *
     * @return array{stored: int, cost: float, note: string}
     */
    private static function teachExamples(bool $dry): array
    {
        $guide = '';
        $schema = null;
        try {
            if (self::$desk !== null) {
                [$guide, $raw] = (self::$desk)();
            } else {
                require_once __DIR__ . '/agentpro.php';          // ApBrain::system() reads AgentPro
                require_once __DIR__ . '/agentpro-brain.php';
                [$guide, $raw] = [ApBrain::system(false), ApBrain::schema()];
            }
            $schema = self::closed($raw);
        } catch (Throwable $e) {
            return ['stored' => 0, 'cost' => 0.0, 'note' => 'desk brain unavailable'];
        }
        $task = "TASK: write 30 realistic WhatsApp messages that our members (ticket agents, counter staff, office staff) send to this desk, "
            . "and for each the exact JSON reading this desk must produce, following the desk instructions above.
"
            . "Mix the ways real people write: romanised Nepali, Nepali in Devanagari, Hindi (Devanagari and romanised), English, and "
            . "mixtures, with the short forms and typos people really use (\"bal kati xa\", \"kal 2 seat surat se\", \"tkt cancel gardinu\"). "
            . "Cover every intent, a few each, but never 'question' or 'other'. Use invented placeholders only: PNRs like SHG-2026-10001, "
            . "phones like 9800000000, simple names. Fields the message does not give: empty string, 0 or an empty list.";
        $r = self::ask($guide, $task, ['type' => 'object', 'properties' => ['examples' => ['type' => 'array', 'items' => [
            'type' => 'object', 'properties' => ['text' => ['type' => 'string'], 'intent' => $schema],
            'required' => ['text', 'intent'], 'additionalProperties' => false]]],
            'required' => ['examples'], 'additionalProperties' => false], 12000);
        $stored = 0;
        foreach ((array) ($r['json']['examples'] ?? []) as $ex) {
            $text = trim((string) ($ex['text'] ?? ''));
            $intent = is_array($ex['intent'] ?? null) ? $ex['intent'] : [];
            if ($text === '' || mb_strlen($text) > 300 || in_array((string) ($intent['intent'] ?? ''), ['', 'question', 'other'], true)) {
                continue;
            }
            if ($dry || self::learnOne($text, $intent)) {
                $stored++;
            }
        }
        return ['stored' => $stored, 'cost' => $r['cost'], 'note' => $r['json'] === null ? $r['note'] : ''];
    }

    private static function learnOne(string $text, array $intent): bool
    {
        if (self::$learn !== null) {
            return (self::$learn)($text, $intent);
        }
        try {
            require_once __DIR__ . '/agentpro-learn.php';
            $before = (int) Database::scalar("SELECT COUNT(*) FROM ap_wa_examples WHERE source = 'teacher'", [], 0);
            ApLearn::record($text, ApBrain::clean($intent, $text), 'teacher');
            return (int) Database::scalar("SELECT COUNT(*) FROM ap_wa_examples WHERE source = 'teacher'", [], 0) > $before;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** A JSON schema the structured-output mode accepts: every object closed, every property required. */
    private static function closed(array $s): array
    {
        if (($s['type'] ?? '') === 'object' && isset($s['properties'])) {
            foreach ($s['properties'] as $k => $p) {
                $s['properties'][$k] = self::closed((array) $p);
            }
            $s['required'] = array_keys($s['properties']);
            $s['additionalProperties'] = false;
        } elseif (($s['type'] ?? '') === 'array' && isset($s['items'])) {
            $s['items'] = self::closed((array) $s['items']);
        }
        return $s;
    }

    /* =================================================================
     *  Prompts and schemas
     * ================================================================= */

    private static function writeTask(array $titles, array $questions): string
    {
        return "TASK: write NEW knowledge-base articles for this company's assistant.\n"
            . "The assistant answers passengers, our agents and our office staff on WhatsApp, Facebook and the website, "
            . "in Nepali, Hindi and English. Write up to 6 articles it does not have yet — the questions people really ask "
            . "about the company, booking, payment, tickets and corrections, boarding, luggage, travel between India and Nepal, "
            . "documents, complaints, how agents and counters work, and how to use the app. Prefer the open questions below "
            . "when the facts answer them.\n"
            . "RULES:\n"
            . "1. Use ONLY the facts above. If the facts do not cover something, do not write that article.\n"
            . "2. No fare, price, time, date, seat number or count — those change and the assistant reads them live; say "
            . "'see the live fare on the website or ask on WhatsApp' instead. The only number allowed is the office phone.\n"
            . "3. answer_ne in natural Nepali (Devanagari), answer_hi in natural Hindi (Devanagari) — the way a polite counter "
            . "person speaks, never word-for-word translation. roman_ne / roman_hi: 4-6 ways people TYPE the question in "
            . "romanised Nepali / Hindi, comma separated (\"ticket kasari katne\", \"kitne baje bus hai\").\n"
            . "4. audience: customer (any passenger), agent (our ticket agents and counters) or staff (office). An agent or "
            . "staff article must not reveal anything private to passengers.\n"
            . "5. 2-5 sentences per answer. Plain text, no markdown.\n\n"
            . "ALREADY WRITTEN (do not repeat these topics):\n- " . ($titles !== [] ? implode("\n- ", array_slice($titles, 0, 300)) : '(none)') . "\n\n"
            . "OPEN QUESTIONS PEOPLE ASKED:\n- " . ($questions !== [] ? implode("\n- ", array_slice($questions, 0, 40)) : '(none)');
    }

    private static function checkTask(array $batch): string
    {
        $lines = [];
        foreach ($batch as $i => $a) {
            $lines[] = '#' . $i . ' ' . (string) $a['title'] . "\n" . (string) $a['answer_en'];
        }
        return "TASK: fact-check these draft articles against the facts above. For each, supported=true only when EVERY "
            . "claim in it is stated in, or plainly follows from, the facts. Anything invented, guessed or stronger than "
            . "the facts → supported=false with the problem in one line.\n\n" . implode("\n\n", $lines);
    }

    private static function writeSchema(): array
    {
        $s = ['type' => 'string'];
        return [
            'type' => 'object',
            'properties' => ['articles' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'title' => $s, 'audience' => ['type' => 'string', 'enum' => ['customer', 'agent', 'staff']],
                    'category' => $s, 'answer_en' => $s, 'answer_ne' => $s, 'answer_hi' => $s,
                    'roman_ne' => $s, 'roman_hi' => $s, 'keywords' => $s,
                ],
                'required' => ['title', 'audience', 'category', 'answer_en', 'answer_ne', 'answer_hi', 'roman_ne', 'roman_hi', 'keywords'],
                'additionalProperties' => false,
            ]]],
            'required' => ['articles'],
            'additionalProperties' => false,
        ];
    }

    private static function checkSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['results' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['index' => ['type' => 'integer'], 'supported' => ['type' => 'boolean'], 'problem' => ['type' => 'string']],
                'required' => ['index', 'supported', 'problem'],
                'additionalProperties' => false,
            ]]],
            'required' => ['results'],
            'additionalProperties' => false,
        ];
    }

    /* =================================================================
     *  The call
     * ================================================================= */

    /**
     * One Messages API call with structured output. The facts block comes
     * first and carries the cache breakpoint, so every later call of the
     * run reads it from the cache.
     *
     * @return array{json: ?array, cost: float, note: string}
     */
    private static function ask(string $facts, string $task, array $schema, int $maxTokens): array
    {
        $model = self::model();
        $body = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'system'     => [
                ['type' => 'text', 'text' => "COMPANY FACTS — the only source you may use:\n\n" . $facts, 'cache_control' => ['type' => 'ephemeral']],
            ],
            'messages'      => [['role' => 'user', 'content' => $task]],
            'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            // A declined request is re-run on Anthropic's recommended model instead of failing.
            'fallbacks'     => 'default',
        ];
        $r = self::post($body);
        $j = json_decode((string) $r['body'], true);
        $cost = is_array($j) ? self::cost((array) ($j['usage'] ?? []), $model) : 0.0;
        if (!$r['ok'] || !is_array($j)) {
            $err = is_array($j) ? (string) ($j['error']['message'] ?? '') : '';
            Logger::warning('AI teach: Claude HTTP ' . $r['http'], ['error' => mb_substr($err, 0, 200)], 'ai');
            return ['json' => null, 'cost' => $cost, 'note' => 'Claude HTTP ' . $r['http'] . ($err !== '' ? ': ' . mb_substr($err, 0, 120) : '')];
        }
        if (($j['stop_reason'] ?? '') === 'refusal') {
            return ['json' => null, 'cost' => $cost, 'note' => 'Claude declined (' . (string) ($j['stop_details']['category'] ?? '') . ')'];
        }
        $text = '';
        foreach ((array) ($j['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') {
                $text .= (string) ($b['text'] ?? '');
            }
        }
        $data = json_decode($text, true);
        return ['json' => is_array($data) ? $data : null, 'cost' => $cost, 'note' => is_array($data) ? '' : 'unreadable answer'];
    }

    /** @return array{ok: bool, http: int, body: string} */
    private static function post(array $body): array
    {
        if (self::$http !== null) {
            return (self::$http)($body);
        }
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT_MS => 8000,
            CURLOPT_TIMEOUT           => 300,
            CURLOPT_HTTPHEADER        => [
                'x-api-key: ' . self::key(),
                'anthropic-version: 2023-06-01',
                'anthropic-beta: server-side-fallback-2026-07-01',
                'content-type: application/json',
            ],
        ]);
        $out  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['ok' => $out !== false && $http === 200, 'http' => $http, 'body' => is_string($out) ? $out : ''];
    }

    private static function key(): string
    {
        return trim(Settings::getString('anthropic_api_key', ''));
    }

    /* =================================================================
     *  Facts in, articles out
     * ================================================================= */

    /** @return array{facts: string, titles: list<string>, questions: list<string>} */
    private static function factsNow(): array
    {
        if (self::$facts !== null) {
            return (self::$facts)();
        }
        require_once INCLUDE_PATH . '/aiprompt.php';
        $facts = ai_system_prompt();
        $titles = [];
        $known = [];
        foreach (Database::fetchAll(
            "SELECT canonical_title, canonical_answer, publication_status FROM ai_kb_articles
              WHERE publication_status IN ('published', 'draft') ORDER BY id"
        ) as $a) {
            $titles[] = (string) $a['canonical_title'];
            if ($a['publication_status'] === 'published') {
                $known[] = '* ' . $a['canonical_title'] . ': ' . mb_substr((string) $a['canonical_answer'], 0, 800);
            }
        }
        if ($known !== []) {
            $facts .= "\n\nPUBLISHED ARTICLES (approved by the office):\n" . implode("\n", $known);
        }
        $questions = array_map(static fn($r) => (string) $r['normalized_question'], Database::fetchAll(
            "SELECT normalized_question FROM ai_unanswered_questions WHERE status IN ('new', 'needs_owner')
              ORDER BY frequency DESC, updated_at DESC LIMIT 40"
        ));
        return ['facts' => $facts, 'titles' => $titles, 'questions' => $questions];
    }

    private static function save(array $a, bool $publish): int
    {
        if (self::$store !== null) {
            return (self::$store)($a, $publish);
        }
        $roles = match ((string) ($a['audience'] ?? 'customer')) {
            'agent' => ['agent', 'counter', 'support', 'manager'],
            'staff' => ['support', 'manager'],
            default => ['public', 'customer'],
        };
        $id = AiKb::save([
            'category'              => 'teacher',
            'canonical_title'       => (string) $a['title'],
            'canonical_answer'      => (string) $a['answer_en'],
            'english_content'       => (string) $a['answer_en'],
            'nepali_content'        => (string) ($a['answer_ne'] ?? ''),
            'hindi_content'         => (string) ($a['answer_hi'] ?? ''),
            'roman_nepali_examples' => (string) ($a['roman_ne'] ?? ''),
            'roman_hindi_examples'  => (string) ($a['roman_hi'] ?? ''),
            'keywords'              => mb_substr((string) ($a['keywords'] ?? '') . ', ' . ($a['roman_ne'] ?? '') . ', ' . ($a['roman_hi'] ?? ''), 0, 4000),
            'applicable_roles'      => $roles,
            'source_reference'      => 'AI teacher ' . self::model() . ' · ' . date('Y-m-d') . ($publish ? ' · fact-checked' : ' · needs a look'),
            'publication_status'    => $publish ? 'published' : 'draft',
        ], 0);
        Database::update('ai_kb_articles', ['source_type' => 'ai_learned'], 'id = :id', ['id' => $id]);
        return $id;
    }

    /* ---- spend + log ---- */

    private static function kvGet(string $k, string $default): string
    {
        if (self::$kv !== null) {
            return (string) (self::$kv[$k] ?? $default);
        }
        $v = Database::scalar('SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => self::SCOPE, 'k' => $k], null);
        return $v === null ? $default : (string) $v;
    }

    private static function kvSet(string $k, string $v): void
    {
        if (self::$kv !== null) {
            self::$kv[$k] = $v;
            return;
        }
        Database::run(
            "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES (:s, :k, :v, 'ai')
             ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = 'ai'",
            ['s' => self::SCOPE, 'k' => $k, 'v' => $v]
        );
    }
}
