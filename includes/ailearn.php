<?php
/**
 * =====================================================================
 *  AiLearn — learning from corrections, with a human in the loop
 *  (24 Sep 2026).
 *
 *  How the assistant gets better without anyone training a model:
 *
 *    1. Feedback lands here: 👍 / 👎 from the web chat, and on WhatsApp the
 *       words a person uses when an answer was wrong ("galat", "hoina",
 *       "wrong", "नहीं", "गलत") — logged against the reply they got.
 *    2. A 👎 or a correction becomes an example CANDIDATE: what was asked,
 *       what we said, and (when the office writes it) what we should have
 *       said, distilled to one rule.
 *    3. A manager approves it in Admin → AI Manager. That click is the
 *       learning: approved examples are read into the prompt as "how we
 *       answer this". Nothing unapproved ever reaches the assistant.
 *
 *  Behind ai_examples_on (OFF) for the prompt side; feedback is always
 *  logged so the office can see what went wrong.
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiLearn
{
    /** Words that mean "that answer was wrong" in the languages our customers write. */
    private const CORRECTION = '/(^|[^\p{L}])(galat|ghalat|wrong|incorrect|hoina|haina|nahi|nahin|nai|गलत|ग़लत|होइन|हैन|नहीं|नही|छैन|मिलेन|ठिक छैन|thik chaina|thik chhaina|sahi nahi|sahi nai)([^\p{L}]|$)/iu';

    public static function enabled(): bool
    {
        return Settings::getBool('ai_examples_on', false);
    }

    public static function looksLikeCorrection(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        return $t !== '' && mb_strlen($t) <= 160 && (bool) preg_match(self::CORRECTION, $t);
    }

    /** A rough script/language guess for grouping examples. */
    public static function languageOf(string $text): string
    {
        if (preg_match('/\p{Devanagari}/u', $text)) {
            // Nepali-only letters/words vs Hindi-only; default Nepali (most customers).
            return preg_match('/(है|हैं|नहीं|क्या|आप|कृपया|चाहिए)/u', $text) ? 'hi' : 'ne';
        }
        if (preg_match('/\p{Gujarati}/u', $text)) {
            return 'gu';
        }
        if (preg_match('/\b(cha|chha|chaina|hoina|tapai|paryo|garnu|ho|xa|xaina|hajur|malai|hami)\b/i', $text)) {
            return 'ne';
        }
        if (preg_match('/\b(hai|hain|nahi|kya|aap|chahiye|kar|karo|mujhe|hum)\b/i', $text)) {
            return 'hi';
        }
        return 'en';
    }

    /**
     * Record feedback; a 👎 or a correction also opens an example candidate.
     * @return int the feedback row id (0 when nothing could be stored)
     */
    public static function feedback(string $phoneOrKey, string $channel, string $verdict, string $userText, string $replyText, string $note = ''): int
    {
        $verdict = in_array($verdict, ['up', 'down', 'correction'], true) ? $verdict : 'down';
        $key = strlen($phoneOrKey) === 64 && ctype_xdigit($phoneOrKey) ? $phoneOrKey : self::ownerKey($phoneOrKey);
        try {
            $id = (int) Database::insert('ai_feedback_log', [
                'owner_key'  => $key,
                'channel'    => mb_substr($channel, 0, 20),
                'verdict'    => $verdict,
                'user_text'  => mb_substr($userText, 0, 2000),
                'reply_text' => mb_substr($replyText, 0, 4000),
                'note'       => mb_substr($note, 0, 500),
            ]);
        } catch (Throwable $e) {
            Logger::warning('AiLearn::feedback failed', ['e' => $e->getMessage()]);
            return 0;
        }
        if ($verdict !== 'up' && trim($userText) !== '') {
            try {
                Database::insert('ai_examples', [
                    'intent'     => 'general',
                    'language'   => self::languageOf($userText),
                    'user_text'  => mb_substr(trim($userText), 0, 2000),
                    'bad_reply'  => mb_substr(trim($replyText), 0, 4000),
                    'good_reply' => $verdict === 'correction' && trim($note) !== '' ? mb_substr(trim($note), 0, 4000) : '',
                    'rule_text'  => null,
                    'source'     => 'feedback',
                    'source_ref' => (string) $id,
                    'status'     => 'candidate',
                ]);
            } catch (Throwable $e) {
                Logger::warning('AiLearn candidate failed', ['e' => $e->getMessage()]);
            }
        }
        return $id;
    }

    public static function ownerKey(string $phoneOrIp): string
    {
        require_once __DIR__ . '/aimemory.php';
        $k = AiMemory::key($phoneOrIp);
        return $k !== '' ? $k : hash('sha256', 'ai-anon|' . $phoneOrIp . '|' . APP_KEY);
    }

    /* ---------------------------------------------------------------
     *  The office curates
     * ------------------------------------------------------------- */

    /** @param array<string,mixed> $in intent, language, user_text, good_reply, rule_text, bad_reply */
    public static function save(array $in, int $adminId, ?int $id = null): int
    {
        $user = trim((string) ($in['user_text'] ?? ''));
        $good = trim((string) ($in['good_reply'] ?? ''));
        if ($user === '' || $good === '') {
            throw new RuntimeException('An example needs what the person asked and what we should answer.');
        }
        $data = [
            'intent'     => mb_substr(trim((string) ($in['intent'] ?? 'general')) ?: 'general', 0, 50),
            'language'   => in_array($in['language'] ?? '', ['en', 'hi', 'ne', 'gu'], true) ? (string) $in['language'] : self::languageOf($user),
            'user_text'  => mb_substr($user, 0, 2000),
            'bad_reply'  => mb_substr(trim((string) ($in['bad_reply'] ?? '')), 0, 4000) ?: null,
            'good_reply' => mb_substr($good, 0, 4000),
            'rule_text'  => mb_substr(trim((string) ($in['rule_text'] ?? '')), 0, 500) ?: null,
        ];
        if ($id !== null && $id > 0) {
            Database::update('ai_examples', $data, 'id = :id', ['id' => $id]);
            Logger::audit('ai_example.edit', 'ai_example', (string) $id, null, $data, 'by admin #' . $adminId);
            return $id;
        }
        $data += ['source' => 'office', 'source_ref' => (string) $adminId, 'status' => 'candidate'];
        $new = (int) Database::insert('ai_examples', $data);
        Logger::audit('ai_example.new', 'ai_example', (string) $new, null, $data, 'by admin #' . $adminId);
        return $new;
    }

    public static function setStatus(int $id, string $status, int $adminId): void
    {
        if (!in_array($status, ['candidate', 'approved', 'retired'], true)) {
            throw new RuntimeException('Unknown status.');
        }
        $row = Database::fetch('SELECT id, good_reply, status FROM ai_examples WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw new RuntimeException('Example not found.');
        }
        if ($status === 'approved' && trim((string) $row['good_reply']) === '') {
            throw new RuntimeException('Write the right answer before approving.');
        }
        Database::update('ai_examples', [
            'status'      => $status,
            'approved_by' => $status === 'approved' ? $adminId : null,
            'approved_at' => $status === 'approved' ? date('Y-m-d H:i:s') : null,
        ], 'id = :id', ['id' => $id]);
        Logger::audit('ai_example.' . $status, 'ai_example', (string) $id, ['status' => $row['status']], ['status' => $status], 'by admin #' . $adminId);
    }

    /** @return list<array<string,mixed>> */
    public static function approved(string $language = '', int $limit = 6): array
    {
        $sql = "SELECT id, intent, language, user_text, good_reply, rule_text FROM ai_examples WHERE status = 'approved'";
        $p = [];
        if ($language !== '') {
            $sql .= ' AND language IN (:l, \'en\')';
            $p['l'] = $language;
        }
        $sql .= ' ORDER BY approved_at DESC, id DESC LIMIT ' . max(1, min(20, $limit));
        return Database::fetchAll($sql, $p);
    }

    /** The prompt block, or '' when off / nothing approved. */
    public static function examplesBlock(string $language = '', int $limit = 6): string
    {
        if (!self::enabled()) {
            return '';
        }
        $rows = self::approved($language, $limit);
        if ($rows === []) {
            return '';
        }
        $lines = [];
        foreach ($rows as $r) {
            $rule = trim((string) ($r['rule_text'] ?? ''));
            $lines[] = '• ' . ($rule !== '' ? $rule . ' — e.g. ' : '') . 'they say: "' . mb_substr((string) $r['user_text'], 0, 160) . '" → we answer: "' . mb_substr((string) $r['good_reply'], 0, 300) . '"';
        }
        try {
            Database::run('UPDATE ai_examples SET hits = hits + 1 WHERE id IN (' . implode(',', array_map(static fn(array $r): int => (int) $r['id'], $rows)) . ')');
        } catch (Throwable $e) { /* counting is optional */ }
        return "=== HOW WE ANSWER THESE (corrections the office approved; follow them) ===\n" . implode("\n", $lines) . "\n\n";
    }

    /** @return array<string,int> */
    public static function stats(int $days = 30): array
    {
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $fb = Database::fetch("SELECT SUM(verdict='up') up, SUM(verdict='down') down, SUM(verdict='correction') corrections FROM ai_feedback_log WHERE created_at >= :s", ['s' => $since]) ?? [];
        $ex = Database::fetch("SELECT SUM(status='candidate') candidates, SUM(status='approved') approved, SUM(status='retired') retired FROM ai_examples") ?? [];
        return [
            'up' => (int) ($fb['up'] ?? 0), 'down' => (int) ($fb['down'] ?? 0), 'corrections' => (int) ($fb['corrections'] ?? 0),
            'candidates' => (int) ($ex['candidates'] ?? 0), 'approved' => (int) ($ex['approved'] ?? 0), 'retired' => (int) ($ex['retired'] ?? 0),
        ];
    }
}
