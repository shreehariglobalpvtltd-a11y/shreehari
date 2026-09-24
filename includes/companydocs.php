<?php
/**
 * =====================================================================
 *  CompanyDocs — the company's approved knowledge and document vault
 *  (24 Sep 2026).
 *
 *  Owner ask: the WhatsApp assistant should be a company operations
 *  manager — it should know the company profile, services, routes,
 *  boarding points, fares, luggage rules, the refund policy, the staff
 *  procedures, the agent and counter instructions, the emergency
 *  contacts, and hold the registration / tax / identity papers — and
 *  hand each one ONLY to a person who is allowed to have it.
 *
 *  AiKb (includes/aiknowledge.php) already holds short written answers.
 *  This is the store for the DOCUMENTS themselves and their approved
 *  summaries, with the rules the papers need:
 *
 *   • Only a manager or the owner uploads, classifies, replaces or
 *     approves. Nothing unapproved, archived or expired ever reaches the
 *     assistant.
 *   • The file bytes are ENCRYPTED at rest (AES-256-GCM, key derived
 *     from APP_KEY) under uploads/company/. Even if the static path
 *     leaked, the bytes are useless without the key. The two readers
 *     are admin/company-doc-file.php (staff session + role) and
 *     company-doc-share.php (a single-use, short-lived token minted for
 *     one WhatsApp number). No public URL to a file is ever printed.
 *   • Every document carries a SENSITIVITY (public / internal /
 *     confidential / restricted) and an AUDIENCE (which roles). Both
 *     must allow the reader. Confidential and restricted papers also
 *     need step-up verification and an explicit confirmation before a
 *     send (enforced by AiTools).
 *   • What leaves in ordinary text is MASKED: PAN, GSTIN, CIN, Aadhaar,
 *     passport numbers, account numbers and anything that looks like a
 *     secret are dotted out by mask(). The full paper goes only as the
 *     file, to an authorised recipient, after confirmation.
 *   • Every search, view, send, link fetch and refusal writes a row to
 *     company_document_access (and the sends / denials an audit_logs
 *     line), so "who saw the GST certificate" is one query.
 *   • search_text (the extracted words) is used ONLY to match a
 *     question. The assistant is handed the approved SUMMARY, never the
 *     raw extraction, so a scan of an identity card is never quoted.
 *
 *  Every read degrades to []/null while the table is missing, so a live
 *  server that has not run the migration keeps every page rendering.
 *  Switch wa_ops_docs_on off and the assistant never sees the tools.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class CompanyDocs
{
    /** doc_type => label, in the order the admin picker offers them. */
    public const TYPES = [
        'profile'       => 'Company profile',
        'services'      => 'Services',
        'routes'        => 'Routes & boarding points',
        'schedule'      => 'Schedules & timings',
        'fares'         => 'Fares',
        'luggage'       => 'Luggage rules',
        'refund_policy' => 'Cancellation & refund policy',
        'procedure'     => 'Staff procedure / checklist',
        'agent_guide'   => 'Agent instructions',
        'counter_guide' => 'Counter instructions',
        'support_faq'   => 'Customer support answers',
        'marketing'     => 'Marketing material',
        'emergency'     => 'Emergency contacts',
        'registration'  => 'Company registration (CIN / incorporation)',
        'tax'           => 'Tax registration (PAN / GST)',
        'identity'      => 'Identity document',
        'other'         => 'Other',
    ];

    /** Least to most sensitive. Index = rank. */
    public const SENSITIVITY = ['public', 'internal', 'confidential', 'restricted'];

    /** The audiences a document may be tagged with. */
    public const AUDIENCE = ['public', 'customer', 'agent', 'counter', 'support', 'manager', 'superadmin'];

    public const STATES = ['draft', 'approved', 'archived'];

    /** Sub-folder of UPLOAD_PATH; file_path is stored relative to UPLOAD_PATH. */
    private const SUBDIR = 'company';

    /** Leading bytes of every stored blob, so a foreign file is refused before decryption. */
    private const MAGIC = 'SHGD1';

    private const MAX_TEXT    = 60000;
    private const MAX_SUMMARY = 8000;

    /** ext => acceptable finfo MIME types. Office papers and scans only. */
    private const ALLOWED = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'txt'  => ['text/plain'],
        'md'   => ['text/plain', 'text/markdown'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ];

    private function __construct() {}

    /* -----------------------------------------------------------------
     *  Switches
     * ----------------------------------------------------------------- */

    /** Office switch AND the table exists. */
    public static function enabled(): bool
    {
        return Settings::getBool('wa_ops_docs_on', false) && self::available();
    }

    /** Has the migration run on this database? Checked once per request. */
    public static function available(bool $recheck = false): bool
    {
        static $has = null;
        if ($has === null || $recheck) {
            try {
                $has = Database::fetch("SHOW TABLES LIKE 'company_documents'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /* -----------------------------------------------------------------
     *  Who may see what
     * ----------------------------------------------------------------- */

    /**
     * The audiences a WhatsApp sender may read, from the role AiTools::whoIs
     * resolved ('customer' | 'staff' | 'admin') and the exact admins.role.
     *
     * @return list<string>
     */
    public static function audienceFor(string $role, string $adminRole = ''): array
    {
        return match ($role) {
            'admin' => $adminRole === 'superadmin'
                ? self::AUDIENCE
                : ['public', 'customer', 'agent', 'counter', 'support', 'manager'],
            'staff' => ['public', 'customer', 'agent', 'counter', 'support'],
            default => ['public', 'customer'],
        };
    }

    /** The most sensitive class this role may ever see. */
    public static function maxSensitivityFor(string $role, string $adminRole = ''): string
    {
        return match ($role) {
            'admin' => $adminRole === 'superadmin' ? 'restricted' : 'confidential',
            'staff' => 'internal',
            default => 'public',
        };
    }

    public static function sensitivityRank(string $s): int
    {
        $i = array_search($s, self::SENSITIVITY, true);
        return $i === false ? count(self::SENSITIVITY) : (int) $i;   // unknown = most sensitive
    }

    public static function isExpired(array $doc): bool
    {
        $e = (string) ($doc['expires_at'] ?? '');
        return $e !== '' && $e !== '0000-00-00' && $e < date('Y-m-d');
    }

    /** Approved, in date, audience includes the role, sensitivity within the role's ceiling. */
    public static function roleMaySee(array $doc, string $role, string $adminRole = ''): bool
    {
        if ((string) ($doc['status'] ?? '') !== 'approved' || self::isExpired($doc)) {
            return false;
        }
        if (self::sensitivityRank((string) ($doc['sensitivity'] ?? 'restricted'))
            > self::sensitivityRank(self::maxSensitivityFor($role, $adminRole))) {
            return false;
        }
        $aud = json_decode((string) ($doc['audience'] ?? '[]'), true);
        return is_array($aud) && array_intersect(self::audienceFor($role, $adminRole), $aud) !== [];
    }

    /** Confidential and restricted papers need a fresh step-up AND an explicit yes before a send. */
    public static function needsConfirm(array $doc): bool
    {
        return self::sensitivityRank((string) ($doc['sensitivity'] ?? 'restricted')) >= self::sensitivityRank('confidential');
    }

    /* -----------------------------------------------------------------
     *  Search (read-only)
     * ----------------------------------------------------------------- */

    /**
     * The best few approved documents this role may see, for these words.
     * Matching uses title, summary, type label and the extracted text; the
     * caller is handed present() rows, never the extraction.
     *
     * @return array{hits: list<array<string,mixed>>, expired: int}
     */
    public static function search(string $query, string $role, string $adminRole = '', int $limit = 5): array
    {
        $out = ['hits' => [], 'expired' => 0];
        if (!self::available()) {
            return $out;
        }
        $q = self::normalize($query);
        if (mb_strlen($q) < 2) {
            return $out;
        }
        $words = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{M}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn(string $w): bool => mb_strlen($w) >= 3          // "ko", "ma", "ka" match everything
        )));
        if ($words === []) {
            return $out;
        }

        try {
            $rows = Database::fetchAll(
                "SELECT id, title, doc_type, sensitivity, audience, summary, search_text, file_path, file_name,
                        mime_type, file_size, version, status, review_at, expires_at, updated_at
                   FROM company_documents
                  WHERE status = 'approved'
                  ORDER BY id DESC LIMIT 500"
            );
        } catch (Throwable $e) {
            return $out;
        }

        $ranked = [];
        foreach ($rows as $d) {
            $expired = self::isExpired($d);
            // Visibility is judged as if the paper were in date, so an expired
            // match can be COUNTED (the assistant then says "expired, asking the
            // office") without ever being shown.
            $probe = $d;
            $probe['expires_at'] = null;
            if (!self::roleMaySee($probe, $role, $adminRole)) {
                continue;
            }
            $hay = self::normalize(implode(' ', [
                (string) $d['title'], self::typeLabel((string) $d['doc_type']), (string) ($d['summary'] ?? ''),
                mb_substr((string) ($d['search_text'] ?? ''), 0, 20000),
            ]));
            $terms   = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $hay, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $termSet = array_flip($terms);
            $titleN  = self::normalize((string) $d['title']);
            $score   = 0;
            foreach ($words as $w) {
                if (isset($termSet[$w])) {
                    $score += 2;
                    if (str_contains($titleN, $w)) {
                        $score += 2;                       // a word in the title counts double
                    }
                } elseif (preg_match('/^[a-z]{5,}$/', $w)) {
                    foreach ($terms as $t) {
                        if (strlen($t) > 4 && strlen($t) < 30 && levenshtein($w, $t) === 1) {
                            $score++;
                            break;
                        }
                    }
                }
            }
            if ($q === $titleN) {
                $score += 20;
            }
            if ($score <= 0) {
                continue;
            }
            if ($expired) {
                $out['expired']++;
                continue;
            }
            $d['_score'] = $score;
            $ranked[]    = $d;
        }

        usort($ranked, static fn(array $x, array $y): int
            => ($y['_score'] <=> $x['_score']) ?: ((int) $y['id'] <=> (int) $x['id']));

        $out['hits'] = array_slice($ranked, 0, max(1, $limit));
        return $out;
    }

    /**
     * One document as the assistant may describe it. The summary is masked
     * unless $unmasked (an office number that passed step-up asking for the
     * figure itself); the extraction is never included.
     *
     * @return array<string,mixed>
     */
    public static function present(array $doc, bool $unmasked = false): array
    {
        $summary = trim((string) ($doc['summary'] ?? ''));
        return [
            'id'          => (int) $doc['id'],
            'title'       => (string) $doc['title'],
            'type'        => (string) $doc['doc_type'],
            'typeLabel'   => self::typeLabel((string) $doc['doc_type']),
            'sensitivity' => (string) $doc['sensitivity'],
            'summary'     => $summary === '' ? '' : mb_substr($unmasked ? $summary : self::mask($summary), 0, 1500),
            'hasFile'     => (string) ($doc['file_path'] ?? '') !== '',
            'fileName'    => (string) ($doc['file_name'] ?? ''),
            'version'     => (int) ($doc['version'] ?? 1),
            'reviewAt'    => (string) ($doc['review_at'] ?? ''),
            'expiresAt'   => (string) ($doc['expires_at'] ?? ''),
            'needsConfirmation' => self::needsConfirm($doc),
        ];
    }

    /* -----------------------------------------------------------------
     *  Masking — what ordinary text may carry
     * ----------------------------------------------------------------- */

    /**
     * Dot out the identifiers that must never travel in a chat line:
     * GSTIN, CIN, PAN, Aadhaar, passport and account numbers, and anything
     * written as a secret. Phone numbers are left alone — the office
     * number is the one thing people are meant to be told. Order matters:
     * a GSTIN contains a PAN, so it is masked first.
     */
    public static function mask(string $text): string
    {
        $rules = [
            // GSTIN 22ABCDE1234F1Z5 → 22•••••••••••Z5
            ['/\b(\d{2})([A-Z]{5}\d{4}[A-Z])([1-9A-Z]Z[0-9A-Z])\b/',
                static fn(array $m): string => $m[1] . '•••••••••••' . substr($m[3], -2)],
            // CIN U12345GJ2020PTC123456 → U••••••••••••••••3456
            ['/\b([LU])(\d{5}[A-Z]{2}\d{4}[A-Z]{3})(\d{6})\b/',
                static fn(array $m): string => $m[1] . '••••••••••••••••' . substr($m[3], -4)],
            // PAN ABCDE1234F → ABCDE••••F
            ['/\b([A-Z]{5})(\d{4})([A-Z])\b/',
                static fn(array $m): string => $m[1] . '••••' . $m[3]],
            // Aadhaar written in groups 1234 5678 9012 → •••• •••• 9012
            ['/\b\d{4}[ \-]\d{4}[ \-](\d{4})\b/',
                static fn(array $m): string => '•••• •••• ' . $m[1]],
            // Aadhaar as one run of 12 digits that is not an Indian / Nepali phone
            ['/(?<!\d)(?!91\d{10}|977\d{9})(\d{8})(\d{4})(?!\d)/',
                static fn(array $m): string => '••••••••' . $m[2]],
            // Passport A1234567 → A•••••67
            ['/\b([A-Z])(\d{7})\b/',
                static fn(array $m): string => $m[1] . '•••••' . substr($m[2], -2)],
            // Account numbers named as such: "A/c 123456789012" → A/c ••••9012
            ['/\b(a\/c|acct?|account(?:\s+no\.?|\s+number)?|ifsc)\s*[:#.\-]?\s*([A-Z0-9]{6,20})\b/iu',
                static fn(array $m): string => $m[1] . ': ••••' . substr($m[2], -4)],
            // Anything written as a secret
            ['/\b(password|passcode|otp|pin|token|secret|api[_ ]?key)\s*[:=]\s*\S+/iu',
                static fn(array $m): string => $m[1] . ': [hidden]'],
        ];
        foreach ($rules as [$re, $fn]) {
            $text = preg_replace_callback($re, $fn, $text) ?? $text;
        }
        return $text;
    }

    /* -----------------------------------------------------------------
     *  Reads
     * ----------------------------------------------------------------- */

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        if ($id <= 0 || !self::available()) {
            return null;
        }
        try {
            return Database::fetch('SELECT * FROM company_documents WHERE id = :id LIMIT 1', ['id' => $id]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Every document for the admin screen (no search_text). */
    public static function listAll(): array
    {
        if (!self::available()) {
            return [];
        }
        try {
            return Database::fetchAll(
                "SELECT d.id, d.title, d.doc_type, d.sensitivity, d.audience, d.summary, d.file_name, d.mime_type,
                        d.file_size, d.version, d.status, d.review_at, d.expires_at, d.updated_at, d.approved_at,
                        d.notes, a.full_name AS approved_by_name, u.full_name AS uploaded_by_name
                   FROM company_documents d
                   LEFT JOIN admins a ON a.id = d.approved_by
                   LEFT JOIN admins u ON u.id = d.uploaded_by
                  ORDER BY (d.status = 'approved') DESC, d.updated_at DESC LIMIT 500"
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** The last access rows for the admin screen. */
    public static function recentAccess(int $limit = 100): array
    {
        if (!self::available()) {
            return [];
        }
        try {
            return Database::fetchAll(
                "SELECT x.*, d.title FROM company_document_access x
                   LEFT JOIN company_documents d ON d.id = x.document_id
                  ORDER BY x.id DESC LIMIT " . max(1, min(500, $limit))
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Absolute path of the encrypted blob. Refuses anything outside uploads/company/YYYY/MM/. */
    public static function absolutePath(array $doc): string
    {
        $rel = ltrim(str_replace('\\', '/', (string) ($doc['file_path'] ?? '')), '/');
        self::assertSafePath($rel);
        return UPLOAD_PATH . '/' . $rel;
    }

    /** The decrypted bytes of a document's file. */
    public static function plaintext(array $doc): string
    {
        $abs = self::absolutePath($doc);
        if (!is_file($abs) || !is_readable($abs)) {
            throw new RuntimeException('The document file is no longer available.');
        }
        return self::open((string) file_get_contents($abs));
    }

    /* -----------------------------------------------------------------
     *  Admin rights
     * ----------------------------------------------------------------- */

    /** Only a manager or the owner may upload, classify, replace or approve. */
    public static function mayManage(array $admin): bool
    {
        return (int) ($admin['is_active'] ?? 1) === 1
            && in_array((string) ($admin['role'] ?? ''), ['superadmin', 'manager'], true);
    }

    /** Which papers this signed-in staff member may open in the panel. */
    public static function adminMaySee(array $admin, array $doc): bool
    {
        $role = (string) ($admin['role'] ?? '');
        $rank = self::sensitivityRank((string) ($doc['sensitivity'] ?? 'restricted'));
        return match ($role) {
            'superadmin' => true,
            'manager'    => $rank <= self::sensitivityRank('confidential'),
            'accountant', 'support', 'official' => $rank <= self::sensitivityRank('internal'),
            default      => false,
        };
    }

    /* -----------------------------------------------------------------
     *  Writes
     * ----------------------------------------------------------------- */

    /**
     * Create or update a document from the admin screen; $file is one
     * $_FILES entry (or null to keep the current file).
     */
    public static function save(array $in, ?array $file, int $adminId, ?int $id = null): int
    {
        $blob = null;
        if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $v = self::validateUpload($file);
            if (!$v['ok']) {
                throw new RuntimeException((string) $v['error']);
            }
            $blob = [
                'bytes' => (string) file_get_contents((string) $file['tmp_name']),
                'ext'   => (string) $v['ext'],
                'mime'  => (string) $v['mime'],
                'name'  => (string) ($file['name'] ?? ''),
            ];
        }
        return self::saveWithBytes($in, $blob, $adminId, $id);
    }

    /**
     * The single writer. $blob = ['bytes','ext','mime','name'] or null.
     * On an update the previous row is snapshotted into
     * company_document_versions first (with its file kept), so nothing is
     * ever silently overwritten. Approving requires a manager / owner;
     * approving a RESTRICTED paper requires the owner.
     *
     * @param array<string,mixed> $in
     */
    public static function saveWithBytes(array $in, ?array $blob, int $adminId, ?int $id = null): int
    {
        if (!self::available()) {
            throw new RuntimeException('Company documents are not set up on this database yet — run database/upgrade-2026-09-24-wa-ops-manager.sql.');
        }
        $actor = self::adminRow($adminId);
        if ($actor === null || !self::mayManage($actor)) {
            throw new RuntimeException('Only a manager or the owner may manage company documents.');
        }

        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            throw new RuntimeException('Give a title of 1–200 characters.');
        }
        $type = (string) ($in['doc_type'] ?? 'other');
        if (!isset(self::TYPES[$type])) {
            throw new RuntimeException('Choose a document type.');
        }
        $sens = (string) ($in['sensitivity'] ?? 'internal');
        if (!in_array($sens, self::SENSITIVITY, true)) {
            throw new RuntimeException('Choose a sensitivity.');
        }
        $aud = array_values(array_intersect(self::AUDIENCE, array_map('strval', (array) ($in['audience'] ?? []))));
        if ($aud === []) {
            throw new RuntimeException('Choose at least one audience.');
        }
        if ($sens !== 'public' && array_intersect(['public', 'customer'], $aud) !== []) {
            throw new RuntimeException('An internal, confidential or restricted document cannot have a public or customer audience.');
        }
        if ($sens === 'restricted' && (string) $actor['role'] !== 'superadmin') {
            throw new RuntimeException('Only the owner may file a restricted document.');
        }
        $summary = trim((string) ($in['summary'] ?? ''));
        if (mb_strlen($summary) > self::MAX_SUMMARY) {
            throw new RuntimeException('The summary is too long (max ' . self::MAX_SUMMARY . ' characters).');
        }
        $status = (string) ($in['status'] ?? 'draft');
        if (!in_array($status, self::STATES, true)) {
            throw new RuntimeException('Choose draft, approved or archived.');
        }
        if ($status === 'approved' && $sens === 'restricted' && (string) $actor['role'] !== 'superadmin') {
            throw new RuntimeException('Only the owner may approve a restricted document.');
        }
        $review  = self::cleanDate((string) ($in['review_at'] ?? ''));
        $expires = self::cleanDate((string) ($in['expires_at'] ?? ''));
        $notes   = mb_substr(trim((string) ($in['notes'] ?? '')), 0, 500);

        $data = [
            'title'       => $title,
            'doc_type'    => $type,
            'sensitivity' => $sens,
            'audience'    => json_encode($aud, JSON_UNESCAPED_UNICODE),
            'summary'     => $summary !== '' ? $summary : null,
            'status'      => $status,
            'review_at'   => $review !== '' ? $review : null,
            'expires_at'  => $expires !== '' ? $expires : null,
            'notes'       => $notes !== '' ? $notes : null,
        ];

        $stored = null;
        if ($blob !== null) {
            $stored = self::storeBytes($blob);
            $data += [
                'file_path' => $stored['rel'],
                'file_name' => $stored['name'],
                'mime_type' => $stored['mime'],
                'file_size' => $stored['size'],
                'sha256'    => $stored['sha256'],
                'encryption' => 'aes-256-gcm',
                'search_text' => self::extractText($blob['bytes'], $stored['mime'], $stored['ext']),
            ];
        }
        // A document with no file (or a scan with no words) is still findable
        // by its title and summary.
        if (($data['search_text'] ?? '') === '' && ($blob !== null || $id === null)) {
            $data['search_text'] = mb_substr($title . "\n" . $summary, 0, self::MAX_TEXT);
        }

        try {
            return Database::transaction(static function () use ($data, $id, $adminId, $status, $stored): int {
                if ($id !== null && $id > 0) {
                    $old = Database::fetchForUpdate('SELECT * FROM company_documents WHERE id = :id', ['id' => $id])[0] ?? null;
                    if ($old === null) {
                        throw new RuntimeException('Document not found.');
                    }
                    $snap = $old;
                    unset($snap['search_text']);
                    Database::run(
                        'INSERT IGNORE INTO company_document_versions (document_id, version, snapshot, file_path, created_by)
                         VALUES (:d, :v, :s, :f, :u)',
                        ['d' => $id, 'v' => (int) $old['version'], 's' => json_encode($snap, JSON_UNESCAPED_UNICODE),
                         'f' => $stored !== null ? (string) ($old['file_path'] ?? '') : null, 'u' => $adminId]
                    );
                    $data['version'] = (int) $old['version'] + 1;
                    if ($status === 'approved' && ((string) $old['status'] !== 'approved' || $stored !== null)) {
                        $data['approved_by'] = $adminId;
                        $data['approved_at'] = date('Y-m-d H:i:s');
                    } elseif ($status !== 'approved') {
                        $data['approved_by'] = null;
                        $data['approved_at'] = null;
                    }
                    if ($stored !== null) {
                        $data['uploaded_by'] = $adminId;
                    }
                    // A summary/title/audience edit re-indexes without a new file.
                    if ($stored === null) {
                        $data['search_text'] = mb_substr(
                            (string) $data['title'] . "\n" . (string) ($data['summary'] ?? '') . "\n"
                            . self::stripIndexedHeader((string) ($old['search_text'] ?? '')),
                            0, self::MAX_TEXT
                        );
                    }
                    Database::update('company_documents', $data, 'id = :id', ['id' => $id]);
                    return $id;
                }
                $data['owner_admin_id'] = $adminId;
                $data['uploaded_by']    = $adminId;
                if ($status === 'approved') {
                    $data['approved_by'] = $adminId;
                    $data['approved_at'] = date('Y-m-d H:i:s');
                }
                return (int) Database::insert('company_documents', $data);
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                @unlink(UPLOAD_PATH . '/' . $stored['rel']);   // a row that was never written leaves no file
            }
            throw $e;
        }
    }

    /** Approve / archive / back to draft. */
    public static function setStatus(int $id, string $status, int $adminId): void
    {
        if ($id <= 0 || !in_array($status, self::STATES, true)) {
            throw new RuntimeException('Invalid document or state.');
        }
        $actor = self::adminRow($adminId);
        if ($actor === null || !self::mayManage($actor)) {
            throw new RuntimeException('Only a manager or the owner may approve or archive company documents.');
        }
        $doc = self::get($id);
        if ($doc === null) {
            throw new RuntimeException('Document not found.');
        }
        if ($status === 'approved' && (string) $doc['sensitivity'] === 'restricted' && (string) $actor['role'] !== 'superadmin') {
            throw new RuntimeException('Only the owner may approve a restricted document.');
        }
        Database::update('company_documents', [
            'status'      => $status,
            'approved_by' => $status === 'approved' ? $adminId : null,
            'approved_at' => $status === 'approved' ? date('Y-m-d H:i:s') : null,
        ], 'id = :id', ['id' => $id]);
    }

    /* -----------------------------------------------------------------
     *  Share links — how a file reaches WhatsApp
     * ----------------------------------------------------------------- */

    /**
     * Mint a single-use, short-lived link for ONE document to ONE number.
     * Meta fetches the media from this URL while sending; a handful of
     * fetches are allowed because the provider may retry, then it dies.
     *
     * @return array{url: string, expires: int}
     */
    public static function mintShareLink(array $doc, string $phone, string $role): array
    {
        $token = bin2hex(random_bytes(24));
        $ttl   = max(2, Settings::getInt('wa_ops_doc_link_minutes', 10)) * 60;
        $exp   = time() + $ttl;
        Database::insert('wa_share_links', [
            'token_hash'  => hash('sha256', $token),
            'document_id' => (int) $doc['id'],
            'version'     => (int) ($doc['version'] ?? 1),
            'phone'       => substr($phone, 0, 20),
            'actor_role'  => substr($role, 0, 20),
            'expires_at'  => date('Y-m-d H:i:s', $exp),
            'max_uses'    => 3,
        ]);
        return ['url' => appUrl('company-doc-share.php?t=' . $token), 'expires' => $exp];
    }

    /**
     * Redeem a share token: unexpired, under its use cap, pointing at a
     * document that is still approved. Counts the use atomically.
     *
     * @return array{doc: array<string,mixed>, link: array<string,mixed>}|null
     */
    public static function consumeShareLink(string $token): ?array
    {
        if (!self::available() || preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
            return null;
        }
        try {
            $row = Database::fetch('SELECT * FROM wa_share_links WHERE token_hash = :h LIMIT 1', ['h' => hash('sha256', $token)]);
        } catch (Throwable $e) {
            return null;
        }
        if ($row === null || strtotime((string) $row['expires_at']) < time() || (int) $row['uses'] >= (int) $row['max_uses']) {
            return null;
        }
        // Expiry was judged above on the clock that wrote expires_at (PHP);
        // the database only guards the use count atomically.
        $n = Database::run(
            'UPDATE wa_share_links SET uses = uses + 1, last_used_at = NOW()
              WHERE id = :id AND uses < max_uses',
            ['id' => (int) $row['id']]
        );
        if ($n !== 1) {
            return null;
        }
        $doc = self::get((int) $row['document_id']);
        if ($doc === null || (string) $doc['status'] !== 'approved' || (int) $doc['version'] !== (int) $row['version']) {
            return null;   // replaced or withdrawn since the link was minted
        }
        return ['doc' => $doc, 'link' => $row];
    }

    /* -----------------------------------------------------------------
     *  The access trail
     * ----------------------------------------------------------------- */

    /**
     * One row per search / view / download / send / link fetch / refusal.
     * Sends and refusals also write the usual audit_logs line.
     *
     * @param array<string,mixed> $ctx role, admin.role, adminId, phone, channel
     */
    public static function logAccess(?array $doc, string $action, array $ctx, bool $ok, string $detail = '', string $purpose = ''): void
    {
        $role = (string) ($ctx['role'] ?? '');
        if (is_array($ctx['admin'] ?? null) && (string) ($ctx['admin']['role'] ?? '') !== '') {
            $role = (string) $ctx['admin']['role'];
        }
        try {
            Database::insert('company_document_access', [
                'document_id'    => isset($doc['id']) ? (int) $doc['id'] : null,
                'version'        => isset($doc['version']) ? (int) $doc['version'] : null,
                'action'         => substr($action, 0, 20),
                'channel'        => substr((string) ($ctx['channel'] ?? 'whatsapp'), 0, 20),
                'actor_role'     => $role !== '' ? substr($role, 0, 20) : null,
                'actor_admin_id' => (int) ($ctx['adminId'] ?? 0) > 0 ? (int) $ctx['adminId'] : null,
                'phone'          => (string) ($ctx['phone'] ?? '') !== '' ? substr((string) $ctx['phone'], 0, 20) : null,
                'purpose'        => $purpose !== '' ? mb_substr($purpose, 0, 200) : null,
                'ok'             => $ok ? 1 : 0,
                'detail'         => $detail !== '' ? mb_substr($detail, 0, 255) : null,
                'ip_address'     => Security::clientIp(),
            ]);
        } catch (Throwable $e) {
            Logger::warning('company_document_access write failed: ' . $e->getMessage(), ['action' => $action], 'whatsapp');
        }
        if (in_array($action, ['send', 'download', 'link_fetch', 'deny'], true)) {
            Logger::audit('company_doc.' . $action, 'company_document', (string) ($doc['id'] ?? ''), null, [
                'title'   => (string) ($doc['title'] ?? ''),
                'version' => (int) ($doc['version'] ?? 0),
                'role'    => $role,
                'phone'   => (string) ($ctx['phone'] ?? ''),
                'ok'      => $ok,
            ], $detail, $purpose);
        }
    }

    /* -----------------------------------------------------------------
     *  Encryption at rest
     * ----------------------------------------------------------------- */

    private static function key(): string
    {
        return hash('sha256', APP_KEY . ':company-docs-v1', true);
    }

    /** MAGIC ‖ iv(12) ‖ tag(16) ‖ ciphertext. */
    public static function seal(string $plain): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $c   = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($c === false || strlen($tag) !== 16) {
            throw new RuntimeException('The document could not be encrypted.');
        }
        return self::MAGIC . $iv . $tag . $c;
    }

    public static function open(string $blob): string
    {
        if (strlen($blob) < 33 || !str_starts_with($blob, self::MAGIC)) {
            throw new RuntimeException('Not a company document blob.');
        }
        $p = openssl_decrypt(substr($blob, 33), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($blob, 5, 12), substr($blob, 17, 16));
        if ($p === false) {
            throw new RuntimeException('The document could not be decrypted.');
        }
        return $p;
    }

    /* -----------------------------------------------------------------
     *  Text extraction — for matching only
     * ----------------------------------------------------------------- */

    /**
     * The words of a file, for search: plain text as is, .docx through
     * ZipArchive, PDF through pdftotext when the server has it, images
     * nothing (the office writes the summary). Never quoted to anyone.
     */
    public static function extractText(string $bytes, string $mime, string $ext): string
    {
        $text = '';
        try {
            if (in_array($ext, ['txt', 'md', 'csv'], true) || str_starts_with($mime, 'text/')) {
                $text = $bytes;
            } elseif ($ext === 'docx' && class_exists('ZipArchive')) {
                $tmp = tempnam(sys_get_temp_dir(), 'shgdocx');
                if ($tmp !== false) {
                    file_put_contents($tmp, $bytes);
                    $zip = new ZipArchive();
                    if ($zip->open($tmp) === true) {
                        $xml = (string) $zip->getFromName('word/document.xml');
                        $zip->close();
                        $xml  = preg_replace('~</w:p>~', "\n", $xml) ?? $xml;
                        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                    @unlink($tmp);
                }
            } elseif ($ext === 'pdf') {
                $bin = null;
                foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $cand) {
                    if (is_file($cand) && is_executable($cand)) {
                        $bin = $cand;
                        break;
                    }
                }
                if ($bin !== null && function_exists('exec')) {
                    $tmp = tempnam(sys_get_temp_dir(), 'shgpdf');
                    if ($tmp !== false) {
                        file_put_contents($tmp, $bytes);
                        $out = [];
                        // Fixed binary, fixed flags, escaped path — nothing here comes from a message.
                        @exec($bin . ' -layout -enc UTF-8 ' . escapeshellarg($tmp) . ' - 2>/dev/null', $out);
                        @unlink($tmp);
                        $text = implode("\n", $out);
                    }
                }
            }
        } catch (Throwable $e) {
            $text = '';
        }
        $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        return mb_substr(trim($text), 0, self::MAX_TEXT);
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ----------------------------------------------------------------- */

    /**
     * Encrypt and write one file under uploads/company/YYYY/MM/.
     *
     * @param array{bytes: string, ext: string, mime: string, name: string} $blob
     * @return array{rel: string, name: string, mime: string, ext: string, size: int, sha256: string}
     */
    private static function storeBytes(array $blob): array
    {
        $ext = strtolower((string) $blob['ext']);
        if (!isset(self::ALLOWED[$ext])) {
            throw new RuntimeException('Only PDF, JPG, PNG, WEBP, TXT, MD, CSV or DOCX files are accepted.');
        }
        $bytes = (string) $blob['bytes'];
        if ($bytes === '' || strlen($bytes) > MAX_UPLOAD_BYTES) {
            throw new RuntimeException('The file is empty or larger than ' . (int) (MAX_UPLOAD_BYTES / 1048576) . ' MB.');
        }
        $ym  = date('Y') . '/' . date('m');
        $dir = self::storageDir() . '/' . $ym;
        if (!ensureDir($dir)) {
            throw new RuntimeException('Could not create the document folder.');
        }
        $fname = Security::safeFilename('bin');
        $abs   = $dir . '/' . $fname;
        $rel   = self::SUBDIR . '/' . $ym . '/' . $fname;
        if (file_put_contents($abs, self::seal($bytes), LOCK_EX) === false) {
            throw new RuntimeException('Could not save the document.');
        }
        @chmod($abs, 0640);
        $orig = trim(preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', (string) $blob['name']) ?? '');
        if ($orig === '' || !str_ends_with(strtolower($orig), '.' . $ext)) {
            $orig = ($orig !== '' ? preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $orig) : 'document') . '.' . $ext;
        }
        return [
            'rel'    => $rel,
            'name'   => mb_substr($orig, 0, 160),
            'mime'   => (string) $blob['mime'],
            'ext'    => $ext,
            'size'   => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
        ];
    }

    /** uploads/company, created on first use with an Apache deny for good measure (nginx denies by config). */
    private static function storageDir(): string
    {
        $dir = UPLOAD_PATH . '/' . self::SUBDIR;
        if (ensureDir($dir) && !is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return $dir;
    }

    /**
     * Same discipline as Security::validateUpload, with this store's own
     * allow-list: real MIME via finfo, extension must agree, an image must
     * decode, a size cap.
     *
     * @return array{ok: bool, error?: string, ext?: string, mime?: string, size?: int}
     */
    private static function validateUpload(array $file): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['ok' => false, 'error' => 'Invalid upload.'];
        }
        switch ((int) $file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return ['ok' => false, 'error' => 'No file was selected.'];
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['ok' => false, 'error' => 'That file is too large.'];
            default:
                return ['ok' => false, 'error' => 'Upload failed. Please try again.'];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
            return ['ok' => false, 'error' => 'The file must be between 1 byte and ' . (int) (MAX_UPLOAD_BYTES / 1048576) . ' MB.'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Upload could not be verified.'];
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$ext])) {
            return ['ok' => false, 'error' => 'Only PDF, JPG, PNG, WEBP, TXT, MD, CSV or DOCX files are accepted.'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        if (!in_array($mime, self::ALLOWED[$ext], true)) {
            return ['ok' => false, 'error' => 'The file content does not match its extension (' . $ext . ').'];
        }
        if (str_starts_with($mime, 'image/') && @getimagesize($tmp) === false) {
            return ['ok' => false, 'error' => 'That image could not be read.'];
        }
        return ['ok' => true, 'ext' => $ext, 'mime' => $mime, 'size' => $size];
    }

    private static function assertSafePath(string $rel): void
    {
        if (preg_match('#^company/\d{4}/\d{2}/[A-Za-z0-9_-]+\.bin$#', $rel) !== 1) {
            throw new RuntimeException('Invalid document path.');
        }
    }

    private static function cleanDate(string $raw): string
    {
        $raw = trim($raw);
        return ($raw !== '' && Security::isValidDate($raw)) ? $raw : '';
    }

    private static function normalize(string $text): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($text))) ?? mb_strtolower(trim($text));
    }

    /** search_text starts with "title\nsummary\n" when it was re-indexed; drop that before re-adding. */
    private static function stripIndexedHeader(string $text): string
    {
        $parts = explode("\n", $text, 3);
        return count($parts) === 3 ? $parts[2] : $text;
    }

    /** @return array<string,mixed>|null */
    private static function adminRow(int $adminId): ?array
    {
        if ($adminId <= 0) {
            return null;
        }
        try {
            return Database::fetch('SELECT id, role, is_active, full_name FROM admins WHERE id = :id LIMIT 1', ['id' => $adminId]);
        } catch (Throwable $e) {
            return null;
        }
    }
}
