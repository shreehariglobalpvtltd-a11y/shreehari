<?php
/**
 * includes/wateach.php — the owner teaches the bot on WhatsApp (10 Oct 2026).
 *
 * Owner: "AI lai upgrade … self dependent training … website ma nagari
 * WhatsApp ko through". The bot already answers most things on this VPS
 * without any paid AI (WaLocal, WaFaq + the knowledge base). What it could
 * not do was LEARN without someone opening Admin → AI knowledge. Now the
 * owner / manager does it from WhatsApp, and every taught answer is used at
 * once, locally, for free (WaFaq answers a strong knowledge-base match with
 * no AI call):
 *
 *   sikau: <question> = <answer>      teach a new answer (published at once)
 *   prashna                           the questions customers asked that the
 *                                     bot could not answer (AiLearn records them)
 *   jawaf 12: <answer>                answer question #12 → it becomes knowledge
 *   chhod 12                          drop question #12 (not worth an answer)
 *   sikeko                            how much the bot knows + the latest lessons
 *   bot help                          every WhatsApp command for the office
 *
 * Only the office: a superadmin / manager number (AiTools::whoIs role admin)
 * or the admin / company WhatsApp numbers in Settings. Null for everyone
 * else, so a customer typing "sikau" just gets the normal bot.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaTeach
{
    private function __construct() {}

    public static function on(): bool
    {
        if (!Settings::getBool('wa_teach_on', true)) {
            return false;
        }
        try {
            return Database::fetch("SHOW TABLES LIKE 'ai_kb_articles'") !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Is this sender the office? */
    public static function isBoss(string $fromRaw, ?array $who): bool
    {
        if (is_array($who) && (string) ($who['role'] ?? '') === 'admin') {
            return true;
        }
        $me = normalisePhone($fromRaw);
        if ($me === '') {
            return false;
        }
        foreach (['wa_admin_number', 'admin_whatsapp', 'company_whatsapp'] as $k) {
            $n = normalisePhone(Settings::getString($k, ''));
            if ($n !== '' && $n === $me) {
                return true;
            }
        }
        return false;
    }

    /** @return array{text:string, media?:?string, ui?:?array}|null */
    public static function handle(string $fromRaw, string $body, ?array $who): ?array
    {
        $body = trim($body);
        if ($body === '' || !self::on() || !self::isBoss($fromRaw, $who)) {
            return null;
        }
        $t = mb_strtolower($body);

        // sikau: question = answer
        if (preg_match('/^\s*(?:sikau|sikaau|sikhau|teach|सिकाउ|सिकाऊ|सिखाओ)\s*[:\-]\s*(.+?)\s*=\s*(.+)$/isu', $body, $m) === 1) {
            return self::teach(trim($m[1]), trim($m[2]), $fromRaw, $who, null);
        }
        if (preg_match('/^\s*(?:sikau|sikaau|teach|सिकाउ)\s*$/iu', $body) === 1) {
            return self::say("सिकाउने तरिका:\nsikau: बसमा charging छ? = छ, हरेक सिटमा USB charging छ।\n(= भन्दा अगाडि प्रश्न, पछाडि जवाफ)");
        }

        // jawaf 12: answer
        if (preg_match('/^\s*(?:jawaf|jawab|javab|answer|जवाफ|जवाब)\s*#?(\d{1,9})\s*[:\-=]?\s*(.+)$/isu', $body, $m) === 1) {
            return self::answerQuestion((int) $m[1], trim($m[2]), $fromRaw, $who);
        }

        // chhod 12
        if (preg_match('/^\s*(?:chhod|chod|skip|chhodnus|छोड)\s*#?(\d{1,9})\s*$/iu', $body, $m) === 1) {
            $n = Database::run("UPDATE ai_unanswered_questions SET status = 'ignored' WHERE id = :i AND status = 'new'", ['i' => (int) $m[1]]);
            return self::say($n > 0 ? '🗑️ प्रश्न #' . $m[1] . ' हटाइयो।' : 'प्रश्न #' . $m[1] . ' भेटिएन।');
        }

        if (in_array($t, ['prashna', 'prasna', 'naya prashna', 'prashna haru', 'unknown', 'questions', 'प्रश्न', 'नयाँ प्रश्न', 'sawal', 'सवाल'], true)) {
            return self::questions();
        }
        if (in_array($t, ['sikeko', 'sikyo', 'kb', 'knowledge', 'ke sikyo', 'सिकेको'], true)) {
            return self::learned();
        }
        if (in_array($t, ['bot help', 'admin help', 'office help', 'commands', 'sabai command', 'सबै command'], true)) {
            return self::help();
        }
        return null;
    }

    private static function teach(string $q, string $a, string $fromRaw, ?array $who, ?int $questionId): array
    {
        if (mb_strlen($q) < 3 || mb_strlen($a) < 2) {
            return self::say('प्रश्न र जवाफ दुवै लेख्नुहोस्: sikau: प्रश्न = जवाफ');
        }
        require_once INCLUDE_PATH . '/aiknowledge.php';
        $deva  = preg_match('/\p{Devanagari}/u', $q . $a) === 1;
        $actor = is_array($who) ? (int) ($who['adminId'] ?? 0) : 0;
        $words = implode(' ', array_slice(array_unique(preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: []), 0, 30));
        try {
            $id = AiKb::save([
                'category'              => 'whatsapp-taught',
                'canonical_title'       => mb_substr($q, 0, 200),
                'canonical_answer'      => $a,
                'english_content'       => $deva ? '' : $a,
                'nepali_content'        => $deva ? $a : '',
                'roman_nepali_examples' => $deva ? '' : $q,
                'keywords'              => $words,
                'applicable_roles'      => ['public', 'customer', 'agent', 'counter', 'support', 'manager'],
                'source_reference'      => mb_substr('Taught on WhatsApp by +' . (preg_replace('/\D+/', '', $fromRaw) ?? '') . ' on ' . date('Y-m-d H:i'), 0, 255),
                'publication_status'    => 'published',
            ], $actor);
        } catch (Throwable $e) {
            return self::say('❌ सिकाउन सकिएन: ' . $e->getMessage());
        }
        if ($questionId !== null) {
            Database::run("UPDATE ai_unanswered_questions SET status = 'answered', article_id = :a WHERE id = :i", ['a' => $id, 'i' => $questionId]);
        } else {
            // The same question waiting in the queue is answered too.
            try {
                Database::run("UPDATE ai_unanswered_questions SET status = 'answered', article_id = :a WHERE status = 'new' AND normalized_question = :q",
                    ['a' => $id, 'q' => mb_strtolower(trim($q))]);
            } catch (Throwable $ignored) {
            }
        }
        Logger::audit('ai_kb.taught_whatsapp', 'ai_kb_article', (string) $id, [], ['title' => mb_substr($q, 0, 120)], 'Taught on WhatsApp');
        return self::say("✅ सिकें! (#" . $id . ")\nअबदेखि \"" . mb_substr($q, 0, 60) . "\" सोध्नेलाई म आफैं जवाफ दिन्छु — AI बिना, निःशुल्क।");
    }

    private static function answerQuestion(int $id, string $answer, string $fromRaw, ?array $who): array
    {
        $q = Database::fetch('SELECT * FROM ai_unanswered_questions WHERE id = :i', ['i' => $id]);
        if ($q === null) {
            return self::say('प्रश्न #' . $id . ' भेटिएन। "prashna" लेखेर सूची हेर्नुहोस्।');
        }
        return self::teach((string) $q['normalized_question'], $answer, $fromRaw, $who, $id);
    }

    private static function questions(): array
    {
        $rows = Database::fetchAll(
            "SELECT id, normalized_question, frequency FROM ai_unanswered_questions WHERE status = 'new'
              ORDER BY frequency DESC, updated_at DESC LIMIT 8"
        );
        if ($rows === []) {
            return self::say("✅ अहिले बाँकी प्रश्न छैन — ग्राहकले सोधेका सबैको जवाफ म दिन सक्छु।\nनयाँ कुरा सिकाउन: sikau: प्रश्न = जवाफ");
        }
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = '#' . $r['id'] . ' (' . (int) $r['frequency'] . 'x) ' . mb_substr((string) $r['normalized_question'], 0, 90);
        }
        return self::say("❓ ग्राहकले सोधेका, मलाई नआएका प्रश्न:\n" . implode("\n", $lines)
            . "\n\nजवाफ सिकाउन: jawaf 12: तपाईंको जवाफ\nहटाउन: chhod 12");
    }

    private static function learned(): array
    {
        $n = (int) Database::scalar("SELECT COUNT(*) FROM ai_kb_articles WHERE publication_status = 'published'", [], 0);
        $taught = Database::fetchAll("SELECT id, canonical_title FROM ai_kb_articles WHERE category = 'whatsapp-taught' AND publication_status = 'published' ORDER BY id DESC LIMIT 5");
        $open = (int) Database::scalar("SELECT COUNT(*) FROM ai_unanswered_questions WHERE status = 'new'", [], 0);
        $lines = array_map(static fn(array $r): string => '#' . $r['id'] . ' ' . mb_substr((string) $r['canonical_title'], 0, 70), $taught);
        return self::say("🧠 मलाई थाहा भएका जवाफ: " . $n . "\nबाँकी प्रश्न: " . $open
            . ($lines !== [] ? "\n\nपछिल्लो सिकेको:\n" . implode("\n", $lines) : ''));
    }

    private static function help(): array
    {
        return self::say(
            "🤖 WhatsApp बाटै सबै काम · Office commands\n\n"
            . "🧠 सिकाउने\n• sikau: प्रश्न = जवाफ\n• prashna — नआएका प्रश्न\n• jawaf 12: जवाफ · chhod 12\n• sikeko\n\n"
            . "🎫 टिकट\n• SHG-2026-00123 — टिकट/स्थिति\n• SHG-… naam badal / miti badal — कोडसहित सच्याउने\n• aaja ko hisab · passenger list · pending\n• seat kati · bus kaha pugyo · timetable\n\n"
            . "🧑‍💼 एजेन्ट (एजेन्टको नम्बरबाट)\n• available · busy · offline\n• mero status\n\n"
            . "टिकट नपुगे रुटको एजेन्टलाई आफैं जान्छ (निःशुल्क), कसैले नलिए तपाईंलाई खबर आउँछ।"
        );
    }

    private static function say(string $text, ?array $ui = null): array
    {
        return ['text' => $text, 'media' => null, 'ui' => $ui];
    }
}
