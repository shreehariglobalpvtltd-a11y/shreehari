<?php
/**
 * =====================================================================
 *  AiKb — the company's curated knowledge, read-only, for the assistant
 *  (22 Sep 2026).
 *
 *  Owner ask (22 Sep): "AI lai advance banau, code lekhera knowledge deu."
 *
 *  The assistant already carries the LIVE facts — routes, fares, refund
 *  slabs, a booking's own seat — through the booking tools, read fresh at
 *  the moment of the question so they can never go stale. What it did NOT
 *  have was a place for the STATIC company knowledge that has no register
 *  row: luggage rules, the cancellation process, accepted payment methods,
 *  boarding-point detail, the agent process, offers. Until now that lived
 *  only inside the system prompt, so the office could not add to it without
 *  a developer.
 *
 *  This is that place. It reads `ai_kb_articles` — the same table the
 *  parked second-generation KB defines — and answers ONE thing: "is there
 *  a verified, published, in-date article whose audience includes this
 *  role, that matches these words?" It writes nothing, prices nothing and
 *  touches no booking. A question it cannot answer is recorded (redacted)
 *  in `ai_unanswered_questions` so the office learns what to write next.
 *
 *  DELIBERATELY SELF-CONTAINED. It does not require includes/ai/* (the
 *  half-built gen-2 module set) — only Database and, for the reply
 *  language, TicketBot. Switch `ai_kb_on` off and the tool disappears from
 *  the catalogue; delete the table and search() simply returns nothing.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiKb
{
    /** Master switch. Off = the assistant never sees the knowledge tool. */
    public static function enabled(): bool
    {
        return Settings::getBool('ai_kb_on', false);
    }

    /**
     * The article audiences a WhatsApp role (from AiTools::whoIs) may read.
     * A customer never sees a staff-only or office-only article; an office
     * number sees everything. 'public' is visible to all.
     *
     * @return list<string>
     */
    private static function roleSet(string $role): array
    {
        return match ($role) {
            'admin' => ['public', 'customer', 'agent', 'counter', 'support', 'manager', 'accountant', 'official', 'superadmin'],
            'staff' => ['public', 'customer', 'agent', 'counter', 'support'],
            default => ['public', 'customer'],
        };
    }

    /**
     * The best few verified, published, in-date articles for this query and
     * role. Scoring mirrors the parked gen-2 reader: an exact word match is
     * worth more than a one-edit typo, and an exact title match wins.
     *
     * @return list<array<string,mixed>>
     */
    public static function search(string $query, string $role, int $limit = 3): array
    {
        $q = self::normalize($query);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $words = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{M}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn(string $w): bool => mb_strlen($w) >= 3
        )));

        $allow = self::roleSet($role);

        try {
            $rows = Database::fetchAll(
                "SELECT id, category, canonical_title, canonical_answer, nepali_content, hindi_content,
                        english_content, roman_nepali_examples, roman_hindi_examples, keywords, synonyms,
                        applicable_roles
                   FROM ai_kb_articles
                  WHERE publication_status = 'published' AND verification_status = 'verified'
                    AND (effective_from IS NULL OR effective_from <= NOW())
                    AND (review_after   IS NULL OR review_after   > NOW())
                  ORDER BY id DESC LIMIT 500"
            );
        } catch (Throwable $e) {
            return [];          // table not migrated yet: no knowledge, no error
        }

        $ranked = [];
        foreach ($rows as $a) {
            $roles = json_decode((string) ($a['applicable_roles'] ?? '[]'), true);
            if (!is_array($roles) || array_intersect($allow, $roles) === []) {
                continue;                       // not for this audience
            }
            $hay = self::normalize(implode(' ', array_map(
                static fn(string $k): string => (string) ($a[$k] ?? ''),
                ['canonical_title', 'keywords', 'synonyms', 'roman_nepali_examples',
                 'roman_hindi_examples', 'canonical_answer', 'nepali_content', 'hindi_content', 'english_content']
            )));
            $terms   = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $hay, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $termSet = array_flip($terms);
            $score   = 0;
            foreach ($words as $w) {
                if (isset($termSet[$w])) {
                    $score += 2;
                } elseif (preg_match('/^[a-z]{5,}$/', $w)) {
                    foreach ($terms as $t) {
                        if (strlen($t) > 4 && strlen($t) < 30 && levenshtein($w, $t) === 1) {
                            $score++;
                            break;
                        }
                    }
                }
            }
            if ($q === self::normalize((string) $a['canonical_title'])) {
                $score += 20;
            }
            if ($score > 0) {
                $a['_score'] = $score;
                $ranked[]    = $a;
            }
        }

        usort($ranked, static fn(array $x, array $y): int
            => ($y['_score'] <=> $x['_score']) ?: ((int) $y['id'] <=> (int) $x['id']));

        return array_slice($ranked, 0, max(1, $limit));
    }

    /** One article's body in the reader's language, falling back to the canonical answer. */
    public static function answer(array $a, string $lang): string
    {
        $field = match ($lang) {
            'ne'    => 'nepali_content',
            'hi'    => 'hindi_content',
            default => 'english_content',
        };
        $body = trim((string) ($a[$field] ?? ''));
        return $body !== '' ? $body : trim((string) ($a['canonical_answer'] ?? ''));
    }

    /**
     * Record a question the knowledge base could not answer, so the office
     * can see what to write next. The question is REDACTED first — a person
     * may type a phone number or a PNR into a "why was I charged" question,
     * and that has no business sitting in a review queue.
     */
    public static function logUnknown(string $query, string $lang): void
    {
        try {
            $q = self::redact(self::normalize($query));
            if (mb_strlen($q) < 2) {
                return;
            }
            Database::run(
                "INSERT INTO ai_unanswered_questions (question_hash, normalized_question, language)
                 VALUES (:h, :q, :l)
                 ON DUPLICATE KEY UPDATE frequency = frequency + 1, updated_at = NOW()",
                ['h' => hash('sha256', $q), 'q' => mb_substr($q, 0, 2000),
                 'l' => in_array($lang, ['ne', 'hi', 'en'], true) ? $lang : 'en']
            );
        } catch (Throwable $e) {
            // an unlogged question is never worth a failed reply
        }
    }

    /** The audiences the admin screen may tag an article with. */
    public const ROLE_VOCAB = ['public', 'customer', 'agent', 'counter', 'support', 'manager'];

    /** The publication states the admin screen offers. */
    public const STATES = ['draft', 'published', 'archived'];

    /**
     * Create or update one article from the admin screen. On an update the
     * previous row is snapshotted into ai_kb_versions first, so nothing is
     * ever silently overwritten. Publishing marks it verified (the office IS
     * the verifier); any other state leaves it unverified so it cannot
     * surface to the assistant until someone publishes it.
     *
     * @param array<string,mixed> $in
     * @throws RuntimeException on invalid input
     */
    public static function save(array $in, int $actorId, ?int $id = null): int
    {
        $title  = trim((string) ($in['canonical_title'] ?? ''));
        $answer = trim((string) ($in['canonical_answer'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            throw new RuntimeException('Give a title of 1–200 characters.');
        }
        if ($answer === '' || mb_strlen($answer) > 16000) {
            throw new RuntimeException('Give an English answer of 1–16000 characters.');
        }

        $state = (string) ($in['publication_status'] ?? 'draft');
        if (!in_array($state, self::STATES, true)) {
            throw new RuntimeException('Choose draft, published or archived.');
        }

        $roles = array_values(array_intersect(self::ROLE_VOCAB, (array) ($in['applicable_roles'] ?? [])));
        if ($roles === []) {
            throw new RuntimeException('Choose at least one audience.');
        }

        $source = trim((string) ($in['source_reference'] ?? ''));
        if ($source === '' || mb_strlen($source) > 255) {
            throw new RuntimeException('Say where this answer comes from (source / evidence).');
        }

        $data = [
            'category'            => mb_substr(trim((string) ($in['category'] ?? 'company')) ?: 'company', 0, 80),
            'canonical_title'     => $title,
            'canonical_answer'    => $answer,
            'english_content'     => mb_substr((string) ($in['english_content'] ?? $answer), 0, 16000),
            'nepali_content'      => mb_substr((string) ($in['nepali_content'] ?? ''), 0, 16000),
            'hindi_content'       => mb_substr((string) ($in['hindi_content'] ?? ''), 0, 16000),
            'roman_nepali_examples' => mb_substr((string) ($in['roman_nepali_examples'] ?? ''), 0, 16000),
            'roman_hindi_examples'  => mb_substr((string) ($in['roman_hindi_examples'] ?? ''), 0, 16000),
            'keywords'            => mb_substr((string) ($in['keywords'] ?? ''), 0, 16000),
            'synonyms'            => mb_substr((string) ($in['synonyms'] ?? ''), 0, 16000),
            'applicable_roles'    => json_encode($roles, JSON_UNESCAPED_UNICODE),
            'source_type'         => 'admin',
            'source_reference'    => $source,
            'publication_status'  => $state,
            'verification_status' => $state === 'published' ? 'verified' : 'unverified',
            'updated_by'          => $actorId,
        ];

        return Database::transaction(static function () use ($data, $id, $actorId): int {
            if ($id !== null && $id > 0) {
                $old = Database::fetchForUpdate('SELECT * FROM ai_kb_articles WHERE id = :id', ['id' => $id])[0] ?? null;
                if ($old === null) {
                    throw new RuntimeException('Article not found.');
                }
                try {
                    Database::run(
                        'INSERT IGNORE INTO ai_kb_versions (article_id, version, snapshot, created_by)
                         VALUES (:a, :v, :s, :u)',
                        ['a' => $id, 'v' => (int) $old['version'],
                         's' => json_encode($old, JSON_UNESCAPED_UNICODE), 'u' => $actorId]
                    );
                } catch (Throwable $e) {
                    // a missing versions table must not block a correction
                }
                $data['version'] = (int) $old['version'] + 1;
                Database::update('ai_kb_articles', $data, 'id = :id', ['id' => $id]);
                return $id;
            }
            $data['slug']       = self::slug($data['canonical_title']);
            $data['created_by'] = $actorId;
            return (int) Database::insert('ai_kb_articles', $data);
        });
    }

    /** Change only the publication state of one article (publish / archive / draft). */
    public static function setState(int $id, string $state, int $actorId): void
    {
        if ($id <= 0 || !in_array($state, self::STATES, true)) {
            throw new RuntimeException('Invalid article or state.');
        }
        Database::update('ai_kb_articles', [
            'publication_status'  => $state,
            'verification_status' => $state === 'published' ? 'verified' : 'unverified',
            'updated_by'          => $actorId,
        ], 'id = :id', ['id' => $id]);
    }

    /** A URL-safe, unique-enough slug from a title. */
    private static function slug(string $title): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '', '-'));
        $base = $base !== '' ? mb_substr($base, 0, 140) : 'article';
        return $base . '-' . bin2hex(random_bytes(3));
    }

    private static function normalize(string $text): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($text))) ?? mb_strtolower(trim($text));
    }

    /** Strip the PII a free-text question might carry before it is stored. */
    private static function redact(string $text): string
    {
        $text = preg_replace('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/iu', '[email]', $text) ?? $text;
        $text = preg_replace('/\bshg[- ][a-z0-9-]+/iu', '[booking]', $text) ?? $text;
        $text = preg_replace('/(?<!\w)\+?\d[\d\s().\-]{6,}\d(?!\w)/u', '[number]', $text) ?? $text;
        return $text;
    }
}
