<?php
/**
 * =====================================================================
 *  includes/staffmsg.php — Office chat (9 Oct 2026, owner ask 14).
 *
 *  "admin ra agent bich ma deep interconnection msg system ya direct call
 *  ... regarding ticket": the office and every agent / counter clerk talk
 *  INSIDE the panel, about a ticket, a settlement, a payout or just the day,
 *  with photos, PDFs and voice notes, read ticks, push and call buttons.
 *
 *  SHAPE
 *    staff_threads       one conversation between THE OFFICE (everyone who
 *                        holds staffmsg.office, plus the super-admin) and ONE
 *                        field staff member (admins.role agent or counter).
 *                        kind general (their "Office chat"), booking (one
 *                        ticket), settlement, payout, verify (a suspicious
 *                        ticket they reported). uniq_key makes find-or-create
 *                        race-free: g:<agent>, b:<agent>:<booking>, ...
 *    staff_messages      text / image / pdf / voice / system / announce.
 *    staff_thread_reads  a read pointer per person (badge + seen ticks).
 *    staff_presence      seen_at, written at most once a minute.
 *
 *  THE WALLS (an obligation, not a hint)
 *    · The office sees every thread.
 *    · A field user sees only threads where agent_admin_id = self. A ticket
 *      thread additionally needs the ticket to be theirs right now: an
 *      agent's own sale (sold_by_admin_id = self — a reassigned sale takes
 *      its thread with it), a counter's desk under desk isolation
 *      (CounterDesk::bookingVisible). A foreign PNR answers exactly like an
 *      unknown one ("not found") — no existence disclosure.
 *    · A viewer (and any role that is neither office nor field) gets nothing.
 *    · The stored file path never leaves the server: files stream through
 *      admin/staff-msg-file.php after the same mayOpen() check.
 *    · Push bodies never carry a passenger name or phone — only "new message
 *      about SHG-1234".
 *    · A request ("cancel this ticket") is a conversation. Nothing here
 *      cancels, refunds or changes a booking — owner rule: refunds and
 *      cancellations are settled by hand. The UI only deep-links to the
 *      existing booking page.
 *
 *  Degrades: every entry point checks available() (cached SHOW TABLES), so a
 *  database without upgrade-2026-10-09-staff-chat.sql simply shows no chat.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class StaffMsg
{
    public const FIELD_ROLES  = ['agent', 'counter'];
    public const OFFICE_ROLES = ['superadmin', 'manager', 'operations', 'support', 'accountant'];
    public const KINDS        = ['general', 'booking', 'settlement', 'payout', 'verify'];
    public const MAX_BODY     = 2000;
    public const VOICE_MAX    = 2097152;   // 2 MB
    public const FILE_MAX     = 6291456;   // 6 MB (photo / PDF)
    public const VOICE_SECS   = 180;

    /** finfo MIME => [kind, stored extension]. Video containers only for a declared voice note. */
    public const MIME = [
        'image/jpeg'      => ['image', 'jpg'],
        'image/png'       => ['image', 'png'],
        'image/webp'      => ['image', 'webp'],
        'application/pdf' => ['pdf', 'pdf'],
        'audio/webm'      => ['voice', 'webm'],
        'video/webm'      => ['voice', 'webm'],
        'audio/ogg'       => ['voice', 'ogg'],
        'application/ogg' => ['voice', 'ogg'],
        'audio/mp4'       => ['voice', 'm4a'],
        'video/mp4'       => ['voice', 'm4a'],
        'audio/x-m4a'     => ['voice', 'm4a'],
        'audio/mpeg'      => ['voice', 'mp3'],
        'audio/aac'       => ['voice', 'aac'],
    ];

    private static ?bool $ready = null;
    private static ?bool $pushCol = null;
    /** @var list<array{t:int,m:int,side:string,sender:int}> */
    private static array $queue = [];
    private static bool $hooked = false;
    /** Tests switch push off so nothing leaves the machine. */
    public static bool $pushEnabled = true;

    /* =================================================================
     *  Availability + who is asking
     * ================================================================= */

    /** Tables present and the office switch on. */
    public static function available(bool $recheck = false): bool
    {
        if (!self::tablesReady($recheck)) {
            return false;
        }
        try {
            return Settings::getBool('staffmsg_on', true);
        } catch (Throwable $e) {
            return true;
        }
    }

    public static function tablesReady(bool $recheck = false): bool
    {
        if (self::$ready === null || $recheck) {
            try {
                self::$ready = Database::fetch("SHOW TABLES LIKE 'staff_thread_reads'") !== null
                    && Database::fetch("SHOW TABLES LIKE 'staff_messages'") !== null;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function role(): string
    {
        return (string) (Auth::admin()['role'] ?? '');
    }

    public static function me(): int
    {
        return (int) (Auth::admin()['id'] ?? 0);
    }

    /** Agent or counter clerk — the field side of every thread. */
    public static function isField(): bool
    {
        return in_array(self::role(), self::FIELD_ROLES, true);
    }

    /** The office side: super-admin or staffmsg.office (manager, operations, support, accountant). */
    public static function isOffice(): bool
    {
        $role = self::role();
        if ($role === '' || $role === 'viewer' || in_array($role, self::FIELD_ROLES, true)) {
            return false;
        }
        return Auth::isSuperadmin() || Auth::can('staffmsg.office');
    }

    public static function canUse(): bool
    {
        return self::available() && (self::isField() || self::isOffice());
    }

    public static function sideFor(): string
    {
        return self::isOffice() ? 'office' : 'staff';
    }

    /** May the signed-in staff member broadcast to every agent / counter? */
    public static function canBroadcast(): bool
    {
        return self::isOffice() && (Auth::isSuperadmin() || Auth::can('staff.manage'));
    }

    /* =================================================================
     *  Threads
     * ================================================================= */

    /**
     * Find-or-create a thread. NO authorisation here — the caller decided
     * (hooks, the API after threadFor()). The field party must be an agent or
     * counter. Returns the thread id.
     */
    public static function ensureThread(string $kind, int $agentId, ?int $bookingId = null, ?int $refId = null,
        string $subject = '', ?int $createdBy = null, ?string $pnr = null): int
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unknown conversation kind.');
        }
        $who = $agentId > 0 ? Database::fetch('SELECT id, role FROM admins WHERE id = :a', ['a' => $agentId]) : null;
        if ($who === null || !in_array((string) $who['role'], self::FIELD_ROLES, true)) {
            throw new RuntimeException('That person is not an agent or counter. / यो व्यक्ति एजेन्ट वा काउन्टर होइन।', 404);
        }
        $bookingId = (int) ($bookingId ?? 0);
        $refId     = (int) ($refId ?? 0);
        $pnr       = $pnr !== null ? strtoupper(Security::clean($pnr, 40)) : '';
        if ($kind === 'booking' && $bookingId <= 0) {
            throw new InvalidArgumentException('A ticket conversation needs a booking.');
        }
        if (in_array($kind, ['settlement', 'payout'], true) && $refId <= 0) {
            throw new InvalidArgumentException('A settlement / payout conversation needs its id.');
        }
        $key = match ($kind) {
            'general'    => 'g:' . $agentId,
            'booking'    => 'b:' . $agentId . ':' . $bookingId,
            'settlement' => 's:' . $agentId . ':' . $refId,
            'payout'     => 'p:' . $agentId . ':' . $refId,
            'verify'     => 'v:' . $agentId . ':' . substr(sha1($pnr !== '' ? $pnr : mb_strtolower($subject)), 0, 24),
        };
        $found = (int) Database::scalar('SELECT id FROM staff_threads WHERE uniq_key = :k', ['k' => $key], 0);
        if ($found > 0) {
            return $found;
        }
        if ($subject === '') {
            $subject = match ($kind) {
                'general'    => 'Office chat',
                'booking'    => 'Ticket ' . $pnr,
                'settlement' => 'Settlement #' . $refId,
                'payout'     => 'Payout request #' . $refId,
                'verify'     => 'Suspicious ticket ' . $pnr,
            };
        }
        Database::insertIgnore('staff_threads', [
            'kind'           => $kind,
            'agent_admin_id' => $agentId,
            'booking_id'     => $bookingId > 0 ? $bookingId : null,
            'pnr'            => $pnr !== '' ? $pnr : null,
            'ref_id'         => $refId > 0 ? $refId : null,
            'subject'        => mb_substr(trim($subject), 0, 160),
            'created_by'     => $createdBy !== null && $createdBy > 0 ? $createdBy : null,
            'uniq_key'       => $key,
        ]);
        return (int) Database::scalar('SELECT id FROM staff_threads WHERE uniq_key = :k', ['k' => $key], 0);
    }

    /** @return array<string,mixed>|null the thread with its field party joined */
    public static function threadById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::fetch(
            'SELECT t.*, a.full_name AS agent_name, a.username AS agent_username, a.role AS agent_role,
                    a.phone AS agent_phone, a.is_active AS agent_active, a.last_login_at AS agent_last_login,
                    s.full_name AS assigned_name
               FROM staff_threads t
               LEFT JOIN admins a ON a.id = t.agent_admin_id
               LEFT JOIN admins s ON s.id = t.assigned_to
              WHERE t.id = :i',
            ['i' => $id]
        );
    }

    /**
     * May the signed-in staff member read / write this thread?
     *
     * @param array<string,mixed> $t
     */
    public static function mayOpen(array $t): bool
    {
        if (!self::canUse()) {
            return false;
        }
        if (self::isOffice()) {
            return true;
        }
        if ((int) ($t['agent_admin_id'] ?? 0) !== self::me()) {
            return false;
        }
        $bid = (int) ($t['booking_id'] ?? 0);
        return $bid <= 0 || self::fieldBookingVisible($bid);
    }

    /** A field user's view of a booking: an agent's own sale, a counter's desk. */
    public static function fieldBookingVisible(int $bookingId): bool
    {
        $role = self::role();
        $me   = self::me();
        if ($bookingId <= 0 || $me <= 0) {
            return false;
        }
        $row = Database::fetch('SELECT id, sold_by_admin_id FROM bookings WHERE id = :b', ['b' => $bookingId]);
        if ($row === null) {
            return false;
        }
        if ($role === 'agent') {
            return (int) ($row['sold_by_admin_id'] ?? 0) === $me;
        }
        if ($role === 'counter') {
            return CounterDesk::bookingVisible($bookingId, null);
        }
        return false;
    }

    /**
     * SQL that keeps a field user's lists to what mayOpen() would allow.
     * Appends its own placeholders to $params (each bound once).
     *
     * @param array<string,mixed> $params
     */
    private static function fieldScopeSql(array &$params, string $alias = 't'): string
    {
        $me = self::me();
        $params['fsMe'] = $me;
        $sql = " AND {$alias}.agent_admin_id = :fsMe";
        if (self::role() === 'agent') {
            $params['fsMe2'] = $me;
            $sql .= " AND ({$alias}.booking_id IS NULL OR EXISTS (SELECT 1 FROM bookings fb WHERE fb.id = {$alias}.booking_id AND fb.sold_by_admin_id = :fsMe2))";
        } elseif (self::role() === 'counter') {
            $desk = Auth::deskScopeCode();
            if ($desk !== null && CounterDesk::stampColumn()) {
                $params['deskScope'] = $desk;
                $sql .= " AND ({$alias}.booking_id IS NULL OR EXISTS (SELECT 1 FROM bookings b WHERE b.id = {$alias}.booking_id AND " . CounterDesk::scopeClause('b', 'deskScope') . '))';
            }
        }
        return $sql;
    }

    /**
     * Authorised find-or-create for the signed-in staff member.
     *
     *   threadFor('general')                          a field user's own office chat
     *   threadFor('general', ['agent_id' => 12])      the office -> that agent / counter
     *   threadFor('booking', ['pnr' => 'SHG-1234'])   field: own visible ticket / office: its seller
     *   threadFor('verify',  ['pnr' => 'SHG-1234'])   field: report a suspicious ticket
     *   threadFor('settlement'|'payout', ['ref_id' => 7])
     *
     * A ticket the caller may not see answers 404 "not found" — the same as a
     * ticket that does not exist. Returns the thread row (threadById shape).
     *
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    public static function threadFor(string $kind, array $o = []): array
    {
        if (!self::canUse()) {
            throw new RuntimeException('Office chat is not available for your role. / तपाईंको भूमिकामा अफिस च्याट छैन।', 403);
        }
        $me     = self::me();
        $office = self::isOffice();
        $notFound = static fn(): RuntimeException => new RuntimeException('No booking found with that reference. / त्यो टिकट भेटिएन।', 404);

        switch ($kind) {
            case 'general':
                $agentId = $office ? (int) ($o['agent_id'] ?? 0) : $me;
                $id = self::ensureThread('general', $agentId, null, null, '', $me);
                break;

            case 'booking':
                $pnr = strtoupper(Security::clean((string) ($o['pnr'] ?? ''), 40));
                $bid = (int) ($o['booking_id'] ?? 0);
                $b = $bid > 0
                    ? Database::fetch('SELECT id, pnr, sold_by_admin_id FROM bookings WHERE id = :b', ['b' => $bid])
                    : ($pnr !== '' ? Database::fetch('SELECT id, pnr, sold_by_admin_id FROM bookings WHERE pnr = :p', ['p' => $pnr]) : null);
                if ($b === null) {
                    throw $notFound();
                }
                if ($office) {
                    $agentId = (int) ($b['sold_by_admin_id'] ?? 0);
                    $seller  = $agentId > 0 ? Database::fetch('SELECT role FROM admins WHERE id = :a', ['a' => $agentId]) : null;
                    if ($seller === null || !in_array((string) $seller['role'], self::FIELD_ROLES, true)) {
                        throw new RuntimeException('This ticket was not sold by an agent or counter — there is nobody to message. / यो टिकट एजेन्ट/काउन्टरले बेचेको होइन।', 422);
                    }
                } else {
                    if (!self::fieldBookingVisible((int) $b['id'])) {
                        throw $notFound();
                    }
                    $agentId = $me;
                }
                $id = self::ensureThread('booking', $agentId, (int) $b['id'], null, 'Ticket ' . (string) $b['pnr'], $me, (string) $b['pnr']);
                break;

            case 'verify':
                if ($office) {
                    throw new RuntimeException('The office reports a suspicious ticket from the alert bell, not the chat.', 422);
                }
                $pnr = strtoupper(Security::clean((string) ($o['pnr'] ?? ''), 40));
                if ($pnr === '') {
                    throw new RuntimeException('Type the PNR / ticket code. / टिकट नम्बर लेख्नुहोस्।', 422);
                }
                $id = self::ensureThread('verify', $me, null, null, 'Suspicious ticket ' . $pnr, $me, $pnr);
                break;

            case 'settlement':
            case 'payout':
                $ref = (int) ($o['ref_id'] ?? 0);
                $owner = 0;
                try {
                    $owner = (int) Database::scalar(
                        $kind === 'settlement'
                            ? 'SELECT agent_admin_id FROM agent_settlements WHERE id = :r'
                            : 'SELECT agent_admin_id FROM agent_payout_requests WHERE id = :r',
                        ['r' => $ref], 0);
                } catch (Throwable $e) {
                    $owner = 0;
                }
                if ($owner <= 0 || (!$office && $owner !== $me)) {
                    throw new RuntimeException('Not found.', 404);
                }
                $id = self::ensureThread($kind, $owner, null, $ref, '', $me);
                break;

            default:
                throw new RuntimeException('Unknown conversation kind.', 422);
        }

        $t = self::threadById($id);
        if ($t === null || !self::mayOpen($t)) {
            throw $notFound();
        }
        return $t;
    }

    /* =================================================================
     *  Messages
     * ================================================================= */

    /** Trim, strip control characters, cap length, hide OTPs / card numbers. */
    public static function cleanBody(string $body): string
    {
        $body = str_replace("\r\n", "\n", $body);
        $body = Security::clean($body, self::MAX_BODY);
        $body = preg_replace("/\n{4,}/", "\n\n\n", $body) ?? $body;
        try {
            require_once INCLUDE_PATH . '/aihandoff.php';
            $body = AiHandoff::redact($body);
        } catch (Throwable $e) {
            // redaction is a courtesy — the message still goes
        }
        return trim($body);
    }

    /** One line for the inbox. */
    public static function preview(string $kind, string $body): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $body));
        $p = match ($kind) {
            'voice'    => '🎤 Voice note' . ($line !== '' ? ' · ' . $line : ''),
            'image'    => '📷 Photo' . ($line !== '' ? ' · ' . $line : ''),
            'pdf'      => '📄 PDF' . ($line !== '' ? ' · ' . $line : ''),
            'announce' => '📣 ' . $line,
            default    => $line,
        };
        return mb_substr($p, 0, 120);
    }

    /**
     * Post one message. Returns the message id — the existing one when the
     * same client_uuid already arrived (a double tap makes one message).
     *
     * @param array{path:string,mime:string,size:int,kind:string,secs?:int}|null $attach from storeUpload()
     */
    public static function post(int $threadId, string $body, string $side, ?int $senderId, ?array $attach = null,
        ?string $uuid = null, bool $urgent = false, string $kind = ''): int
    {
        if (!in_array($side, ['office', 'staff', 'system'], true)) {
            throw new InvalidArgumentException('Unknown side.');
        }
        $body = self::cleanBody($body);
        if ($body === '' && $attach === null) {
            throw new RuntimeException('Type a message first. / पहिले सन्देश लेख्नुहोस्।', 422);
        }
        $uuid = ($uuid !== null && preg_match('/^[A-Za-z0-9-]{8,36}$/', $uuid) === 1) ? $uuid : null;
        if ($uuid !== null) {
            $dup = (int) Database::scalar('SELECT id FROM staff_messages WHERE client_uuid = :u', ['u' => $uuid], 0);
            if ($dup > 0) {
                return $dup;
            }
        }
        if ($kind === '') {
            $kind = $attach !== null ? (string) $attach['kind'] : ($side === 'system' ? 'system' : 'text');
        }
        $kind = in_array($kind, ['text', 'image', 'pdf', 'voice', 'system', 'announce'], true) ? $kind : 'text';

        try {
            $id = Database::insert('staff_messages', [
                'thread_id'       => $threadId,
                'sender_admin_id' => $senderId !== null && $senderId > 0 ? $senderId : null,
                'side'            => $side,
                'kind'            => $kind,
                'body'            => $body !== '' ? $body : null,
                'attach_path'     => $attach['path'] ?? null,
                'attach_mime'     => $attach['mime'] ?? null,
                'attach_size'     => isset($attach['size']) ? (int) $attach['size'] : null,
                'attach_secs'     => isset($attach['secs']) && (int) $attach['secs'] > 0 ? min(self::VOICE_SECS * 2, (int) $attach['secs']) : null,
                'urgent'          => $urgent ? 1 : 0,
                'client_uuid'     => $uuid,
            ]);
        } catch (PDOException $e) {
            // Two taps that raced past the check above: the UNIQUE uuid decides.
            if ($uuid !== null && (string) $e->getCode() === '23000') {
                $dup = (int) Database::scalar('SELECT id FROM staff_messages WHERE client_uuid = :u', ['u' => $uuid], 0);
                if ($dup > 0) {
                    return $dup;
                }
            }
            throw $e;
        }

        $reopen = $side !== 'system' ? 1 : 0;
        Database::run(
            "UPDATE staff_threads
                SET msg_count = msg_count + 1,
                    status    = IF(:r1 = 1, 'open', status),
                    closed_at = IF(:r2 = 1, NULL, closed_at),
                    priority  = IF(:u = 1, 'urgent', priority)
              WHERE id = :t",
            ['r1' => $reopen, 'r2' => $reopen, 'u' => $urgent ? 1 : 0, 't' => $threadId]
        );
        Database::run(
            'UPDATE staff_threads
                SET last_msg_id = :m, last_msg_at = NOW(), last_side = :s, last_preview = :p
              WHERE id = :t AND COALESCE(last_msg_id, 0) < :m2',
            ['m' => $id, 's' => $side, 'p' => self::preview($kind, $body), 't' => $threadId, 'm2' => $id]
        );

        if ($senderId !== null && $senderId > 0) {
            self::markRead($threadId, $senderId, $id);
        }
        if ($side !== 'system') {
            self::queueNotify($threadId, $id, $side, (int) ($senderId ?? 0));
        }
        if ($urgent && $side === 'staff') {
            self::urgentAlert($threadId, $id);
        }
        return $id;
    }

    /** A line from the system into EVERY existing thread about this booking. Never creates one. */
    public static function system(int $bookingId, string $text): int
    {
        if ($bookingId <= 0 || !self::available()) {
            return 0;
        }
        $n = 0;
        foreach (Database::fetchAll('SELECT id FROM staff_threads WHERE booking_id = :b', ['b' => $bookingId]) as $t) {
            try {
                self::post((int) $t['id'], $text, 'system', null, null, null, false, 'system');
                $n++;
            } catch (Throwable $e) {
                Logger::warning('StaffMsg::system failed', ['err' => $e->getMessage()]);
            }
        }
        return $n;
    }

    public static function markRead(int $threadId, int $adminId, int $lastId = 0): void
    {
        if ($threadId <= 0 || $adminId <= 0) {
            return;
        }
        if ($lastId <= 0) {
            $lastId = (int) Database::scalar('SELECT COALESCE(last_msg_id, 0) FROM staff_threads WHERE id = :t', ['t' => $threadId], 0);
        }
        if ($lastId <= 0) {
            return;
        }
        Database::run(
            'INSERT INTO staff_thread_reads (thread_id, admin_id, last_read_id, read_at) VALUES (:t, :a, :l, NOW())
             ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id)), read_at = NOW()',
            ['t' => $threadId, 'a' => $adminId, 'l' => $lastId]
        );
    }

    /**
     * The newest N messages (afterId = 0), or everything after afterId.
     *
     * @return list<array<string,mixed>> raw rows, oldest first
     */
    public static function messages(int $threadId, int $afterId = 0, int $limit = 60, int $beforeId = 0): array
    {
        $limit = max(1, min(200, $limit));
        $base = 'SELECT m.id, m.side, m.kind, m.body, m.attach_mime, m.attach_size, m.attach_secs, m.urgent, m.created_at,
                        m.client_uuid, m.sender_admin_id, a.full_name AS sender_name, a.username AS sender_user
                   FROM staff_messages m
                   LEFT JOIN admins a ON a.id = m.sender_admin_id
                  WHERE m.thread_id = :t';
        if ($afterId > 0) {
            return Database::fetchAll($base . ' AND m.id > :x ORDER BY m.id ASC LIMIT ' . $limit, ['t' => $threadId, 'x' => $afterId]);
        }
        $params = ['t' => $threadId];
        if ($beforeId > 0) {
            $base .= ' AND m.id < :y';
            $params['y'] = $beforeId;
        }
        return array_reverse(Database::fetchAll($base . ' ORDER BY m.id DESC LIMIT ' . $limit, $params));
    }

    /**
     * The browser's view of one message. Never carries attach_path.
     *
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    public static function present(array $m, bool $viewerOffice, int $viewerId): array
    {
        $side = (string) $m['side'];
        $mine = $side !== 'system' && ($viewerOffice ? $side === 'office' : $side === 'staff');
        $name = trim((string) ($m['sender_name'] ?? '')) ?: (string) ($m['sender_user'] ?? '');
        $ts   = strtotime((string) $m['created_at']) ?: time();
        $out  = [
            'id'     => (int) $m['id'],
            'side'   => $side,
            'kind'   => (string) $m['kind'],
            'body'   => (string) ($m['body'] ?? ''),
            'ts'     => $ts,
            'mine'   => $mine,
            'self'   => (int) ($m['sender_admin_id'] ?? 0) === $viewerId && $viewerId > 0,
            'who'    => $side === 'system' ? '' : $name,
            'urgent' => (int) ($m['urgent'] ?? 0) === 1,
            'file'   => null,
            // the sender's own uuid only — lets their screen swap the "sending…" bubble
            'uuid'   => (int) ($m['sender_admin_id'] ?? 0) === $viewerId && $viewerId > 0 ? (string) ($m['client_uuid'] ?? '') : '',
        ];
        if ((string) ($m['attach_mime'] ?? '') !== '') {
            $out['file'] = [
                'url'  => '/admin/staff-msg-file.php?m=' . (int) $m['id'],
                'mime' => (string) $m['attach_mime'],
                'size' => (int) ($m['attach_size'] ?? 0),
                'secs' => (int) ($m['attach_secs'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * The other side's read pointer: what of MY side they have seen.
     *
     * @param array<string,mixed> $t
     */
    public static function peerRead(array $t, bool $viewerOffice): int
    {
        $agent = (int) $t['agent_admin_id'];
        if ($viewerOffice) {
            return (int) Database::scalar('SELECT last_read_id FROM staff_thread_reads WHERE thread_id = :t AND admin_id = :a',
                ['t' => (int) $t['id'], 'a' => $agent], 0);
        }
        return (int) Database::scalar('SELECT COALESCE(MAX(last_read_id), 0) FROM staff_thread_reads WHERE thread_id = :t AND admin_id <> :a',
            ['t' => (int) $t['id'], 'a' => $agent], 0);
    }

    /* =================================================================
     *  Lists, unread, poll
     * ================================================================= */

    /** Threads with something new from the other side, for the signed-in user. */
    public static function unreadFor(): int
    {
        if (!self::canUse()) {
            return 0;
        }
        $params = ['me' => self::me()];
        if (self::isOffice()) {
            $sql = "SELECT COUNT(*) FROM staff_threads t
                      LEFT JOIN staff_thread_reads r ON r.thread_id = t.id AND r.admin_id = :me
                     WHERE t.status = 'open' AND t.last_side = 'staff' AND t.last_msg_id > COALESCE(r.last_read_id, 0)";
        } else {
            $sql = "SELECT COUNT(*) FROM staff_threads t
                      LEFT JOIN staff_thread_reads r ON r.thread_id = t.id AND r.admin_id = :me
                     WHERE t.last_side IN ('office', 'system') AND t.last_msg_id > COALESCE(r.last_read_id, 0)"
                 . self::fieldScopeSql($params);
        }
        try {
            return (int) Database::scalar($sql, $params, 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * What the 15 s panel poll needs: unread threads, and the newest message
     * id that came from the other side (a rise = chime + toast).
     *
     * @return array{unread:int,last:int,t:int}
     */
    public static function pollInfo(): array
    {
        $out = ['unread' => 0, 'last' => 0, 't' => 0];
        if (!self::canUse()) {
            return $out;
        }
        $out['unread'] = self::unreadFor();
        $params = [];
        if (self::isOffice()) {
            $sql = "SELECT id, last_msg_id FROM staff_threads t WHERE t.last_side = 'staff' AND t.status = 'open'";
        } else {
            $sql = "SELECT id, last_msg_id FROM staff_threads t WHERE t.last_side IN ('office', 'system')" . self::fieldScopeSql($params);
        }
        try {
            $row = Database::fetch($sql . ' ORDER BY t.last_msg_id DESC LIMIT 1', $params);
            if ($row !== null) {
                $out['last'] = (int) $row['last_msg_id'];
                $out['t']    = (int) $row['id'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * The inbox for the signed-in user.
     *
     * @param array{filter?:string,q?:string,kind?:string,agent?:int,limit?:int} $o
     * @return list<array<string,mixed>> presentable threads, newest first
     */
    public static function inbox(array $o = []): array
    {
        if (!self::canUse()) {
            return [];
        }
        $office = self::isOffice();
        $me     = self::me();
        $limit  = max(1, min(150, (int) ($o['limit'] ?? 80)));
        $params = ['me' => $me];
        $where  = '1 = 1';
        if ($office) {
            $where .= ' AND t.last_msg_id IS NOT NULL';
            switch ((string) ($o['filter'] ?? 'open')) {
                case 'waiting': $where .= " AND t.status = 'open' AND t.last_side = 'staff'"; break;
                case 'urgent':  $where .= " AND t.status = 'open' AND t.priority = 'urgent'"; break;
                case 'mine':    $where .= ' AND t.assigned_to = :me3'; $params['me3'] = $me; break;
                case 'closed':  $where .= " AND t.status = 'closed'"; break;
                case 'all':     break;
                default:        $where .= " AND t.status = 'open'"; break;
            }
            if ((int) ($o['agent'] ?? 0) > 0) {
                $where .= ' AND t.agent_admin_id = :ag';
                $params['ag'] = (int) $o['agent'];
            }
        } else {
            $where .= " AND (t.last_msg_id IS NOT NULL OR t.kind = 'general')" . self::fieldScopeSql($params);
        }
        $kind = (string) ($o['kind'] ?? '');
        if (in_array($kind, self::KINDS, true)) {
            $where .= ' AND t.kind = :kd';
            $params['kd'] = $kind;
        }
        $q = trim(Security::clean((string) ($o['q'] ?? ''), 60));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where .= ' AND (t.pnr LIKE :q1 OR t.subject LIKE :q2 OR a.full_name LIKE :q3 OR a.username LIKE :q4 OR t.last_preview LIKE :q5)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $qk) {
                $params[$qk] = $like;
            }
        }
        $unreadSide = $office ? "m.side = 'staff'" : "m.side IN ('office', 'system')";
        $order = $office ? 't.last_msg_id DESC' : "(t.kind = 'general') DESC, COALESCE(t.last_msg_id, 0) DESC";
        $rows = Database::fetchAll(
            "SELECT t.*, a.full_name AS agent_name, a.username AS agent_username, a.role AS agent_role, s.full_name AS assigned_name,
                    COALESCE(r.last_read_id, 0) AS my_read,
                    (SELECT COUNT(*) FROM staff_messages m WHERE m.thread_id = t.id AND m.id > COALESCE(r.last_read_id, 0) AND $unreadSide) AS unread
               FROM staff_threads t
               LEFT JOIN admins a ON a.id = t.agent_admin_id
               LEFT JOIN admins s ON s.id = t.assigned_to
               LEFT JOIN staff_thread_reads r ON r.thread_id = t.id AND r.admin_id = :me
              WHERE $where
              ORDER BY $order
              LIMIT $limit",
            $params
        );
        $codes = self::agentCodes(array_map(static fn(array $r): int => (int) $r['agent_admin_id'], $rows));
        return array_map(static fn(array $r): array => self::presentThread($r, $office, $codes), $rows);
    }

    /**
     * @param list<int> $ids
     * @return array<int,string> admin id => desk / agent code
     */
    public static function agentCodes(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        try {
            $in = implode(',', array_map('intval', $ids));
            foreach (Database::fetchAll("SELECT admin_id, counter_code FROM admin_profiles WHERE admin_id IN ($in)") as $r) {
                if ((string) ($r['counter_code'] ?? '') !== '') {
                    $out[(int) $r['admin_id']] = (string) $r['counter_code'];
                }
            }
        } catch (Throwable $e) {
        }
        foreach ($ids as $id) {
            if (!isset($out[$id])) {
                try {
                    if (class_exists('AgentWallet') && method_exists('AgentWallet', 'agentCodeLabel')) {
                        $lbl = (string) AgentWallet::agentCodeLabel($id);
                        if ($lbl !== '') {
                            $out[$id] = $lbl;
                        }
                    }
                } catch (Throwable $e) {
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $t
     * @param array<int,string> $codes
     * @return array<string,mixed>
     */
    public static function presentThread(array $t, bool $viewerOffice, array $codes = []): array
    {
        $kind  = (string) $t['kind'];
        $agent = (int) $t['agent_admin_id'];
        $name  = trim((string) ($t['agent_name'] ?? '')) ?: (string) ($t['agent_username'] ?? ('#' . $agent));
        $pnr   = (string) ($t['pnr'] ?? '');
        if ($viewerOffice) {
            $title = $name;
        } else {
            $title = match ($kind) {
                'general'    => 'Office · कार्यालय',
                'booking'    => 'Ticket ' . $pnr,
                'settlement' => 'Settlement #' . (int) ($t['ref_id'] ?? 0),
                'payout'     => 'Payout request',
                'verify'     => 'Suspicious ticket ' . $pnr,
                default      => (string) $t['subject'],
            };
        }
        return [
            'id'          => (int) $t['id'],
            'kind'        => $kind,
            'title'       => $title,
            'subject'     => (string) ($t['subject'] ?? ''),
            'pnr'         => $pnr,
            'status'      => (string) $t['status'],
            'priority'    => (string) $t['priority'],
            'assignedTo'  => (int) ($t['assigned_to'] ?? 0),
            'assignedName'=> (string) ($t['assigned_name'] ?? ''),
            'agent'       => [
                'id'   => $agent,
                'name' => $name,
                'role' => (string) ($t['agent_role'] ?? ''),
                'code' => $codes[$agent] ?? '',
            ],
            'lastAt'      => (string) ($t['last_msg_at'] ?? '') !== '' ? (strtotime((string) $t['last_msg_at']) ?: 0) : 0,
            'lastSide'    => (string) ($t['last_side'] ?? ''),
            'lastPreview' => (string) ($t['last_preview'] ?? ''),
            'lastId'      => (int) ($t['last_msg_id'] ?? 0),
            'unread'      => (int) ($t['unread'] ?? 0),
            'count'       => (int) ($t['msg_count'] ?? 0),
        ];
    }

    /* =================================================================
     *  Office actions
     * ================================================================= */

    public static function close(int $threadId, int $by, bool $close = true): void
    {
        Database::run(
            $close
                ? "UPDATE staff_threads SET status = 'closed', closed_at = NOW() WHERE id = :t"
                : "UPDATE staff_threads SET status = 'open', closed_at = NULL WHERE id = :t",
            ['t' => $threadId]
        );
        Logger::audit($close ? 'staffmsg.close' : 'staffmsg.reopen', 'staff_thread', (string) $threadId, null, ['by' => $by]);
    }

    public static function assign(int $threadId, int $to, int $by): string
    {
        $name = '';
        if ($to > 0) {
            $row = Database::fetch('SELECT full_name, username, role FROM admins WHERE id = :a AND is_active = 1', ['a' => $to]);
            if ($row === null || in_array((string) $row['role'], array_merge(self::FIELD_ROLES, ['viewer']), true)) {
                throw new RuntimeException('Pick an office person.', 422);
            }
            $name = (string) ($row['full_name'] ?: $row['username']);
        }
        Database::run('UPDATE staff_threads SET assigned_to = :a WHERE id = :t', ['a' => $to > 0 ? $to : null, 't' => $threadId]);
        Logger::audit('staffmsg.assign', 'staff_thread', (string) $threadId, null, ['to' => $to, 'by' => $by]);
        return $name;
    }

    public static function setUrgent(int $threadId, bool $on): void
    {
        Database::run('UPDATE staff_threads SET priority = :p WHERE id = :t', ['p' => $on ? 'urgent' : 'normal', 't' => $threadId]);
    }

    /**
     * 📣 One announcement into every active agent's / counter's office chat.
     * Returns how many people it reached.
     */
    public static function broadcast(string $body, string $audience, int $senderId, ?string $uuid = null): int
    {
        $roles = match ($audience) {
            'agents'   => ['agent'],
            'counters' => ['counter'],
            default    => ['agent', 'counter'],
        };
        if (self::cleanBody($body) === '') {
            throw new RuntimeException('Type the announcement first. / घोषणा लेख्नुहोस्।', 422);
        }
        $in = "'" . implode("','", $roles) . "'";
        $ids = array_map('intval', array_column(
            Database::fetchAll("SELECT id FROM admins WHERE is_active = 1 AND role IN ($in) ORDER BY id LIMIT 1000"), 'id'));
        $n = 0;
        foreach ($ids as $aid) {
            try {
                $tid = self::ensureThread('general', $aid, null, null, '', $senderId);
                $u   = $uuid !== null ? self::derivedUuid($uuid, $aid) : null;
                self::post($tid, $body, 'office', $senderId, null, $u, false, 'announce');
                $n++;
            } catch (Throwable $e) {
                Logger::warning('StaffMsg broadcast: one recipient failed', ['agent' => $aid, 'err' => $e->getMessage()]);
            }
        }
        Logger::audit('staffmsg.broadcast', 'staff_thread', $audience, null, ['reached' => $n, 'by' => $senderId]);
        return $n;
    }

    /** A per-recipient uuid from one client uuid (36 chars, stable). */
    public static function derivedUuid(string $uuid, int $aid): string
    {
        $h = sha1($uuid . ':' . $aid);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    /* =================================================================
     *  Hooks other code calls (all best-effort — never break the caller)
     * ================================================================= */

    /** An agent disputed a settlement: the note lands in that settlement's thread, the bell rings. */
    public static function settlementDisputed(int $settlementId, int $agentId, string $note): void
    {
        try {
            if (!self::available() || $settlementId <= 0 || $agentId <= 0) {
                return;
            }
            $tid  = self::ensureThread('settlement', $agentId, null, $settlementId, '', $agentId);
            $text = '⚠️ Settlement #' . $settlementId . ' disputed / विवाद' . (trim($note) !== '' ? ":\n" . $note : '');
            self::post($tid, $text, 'staff', $agentId, null, null, true);
        } catch (Throwable $e) {
            Logger::warning('StaffMsg settlement hook failed', ['err' => $e->getMessage()]);
        }
    }

    /** An agent asked for a payout: a payout thread the office can answer in. */
    public static function payoutRequested(int $requestId, int $agentId, float $amount, string $note = ''): void
    {
        try {
            if (!self::available() || $requestId <= 0 || $agentId <= 0) {
                return;
            }
            $tid  = self::ensureThread('payout', $agentId, null, $requestId, '', $agentId);
            $text = '💸 Payout request ' . inr($amount) . ' / भुक्तानी अनुरोध' . (trim($note) !== '' ? "\n" . $note : '');
            self::post($tid, $text, 'staff', $agentId);
        } catch (Throwable $e) {
            Logger::warning('StaffMsg payout hook failed', ['err' => $e->getMessage()]);
        }
    }

    /**
     * "Report to office" from the scan page (FRAUD package) or the chat: a
     * field user gets a verify thread + an urgent line + the bell; an office
     * user rings the bell only. Never touches the booking.
     *
     * @return array{ok:bool,thread_id?:int,alert?:int,error?:string}
     */
    public static function reportSuspicious(string $pnrOrCode, string $note = '', ?array $attach = null): array
    {
        try {
            if (!self::canUse()) {
                return ['ok' => false, 'error' => 'not available'];
            }
            $code = strtoupper(Security::clean($pnrOrCode, 40));
            $text = '🛡️ Suspicious ticket / शंकास्पद टिकट: ' . $code . (trim($note) !== '' ? "\n" . $note : '');
            if (self::isOffice()) {
                require_once INCLUDE_PATH . '/adminalerts.php';
                $id = AdminAlerts::push('staff_msg', '🛡️ Suspicious ticket ' . $code, mb_substr(self::cleanBody($note), 0, 300), '/admin/scan.php', '🛡️');
                return ['ok' => true, 'alert' => $id];
            }
            $t = self::threadFor('verify', ['pnr' => $code]);
            self::post((int) $t['id'], $text, 'staff', self::me(), $attach, null, true);
            return ['ok' => true, 'thread_id' => (int) $t['id']];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Urgent line from the field: the notification bell (office-wide) gets one row. */
    private static function urgentAlert(int $threadId, int $msgId): void
    {
        try {
            $t = self::threadById($threadId);
            if ($t === null) {
                return;
            }
            require_once INCLUDE_PATH . '/adminalerts.php';
            $who   = trim((string) ($t['agent_name'] ?? '')) ?: (string) ($t['agent_username'] ?? 'Agent');
            $about = (string) ($t['pnr'] ?? '') !== '' ? 'About ' . (string) $t['pnr'] : (string) $t['subject'];
            AdminAlerts::push('staff_msg', '🔴 Urgent message — ' . $who, $about, '/admin/staff-chat.php?t=' . $threadId, '💬',
                'smsg:urgent:' . $threadId . ':' . $msgId);
        } catch (Throwable $e) {
        }
    }

    /* =================================================================
     *  Push (staff phones)
     * ================================================================= */

    private static function queueNotify(int $threadId, int $msgId, string $side, int $senderId): void
    {
        if (!self::$pushEnabled) {
            return;
        }
        self::$queue[] = ['t' => $threadId, 'm' => $msgId, 'side' => $side, 'sender' => $senderId];
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function([self::class, 'flushNotify']);
        }
    }

    /** Is push_subscriptions.admin_id there? */
    public static function pushReady(): bool
    {
        if (self::$pushCol === null) {
            try {
                self::$pushCol = Database::fetch("SHOW COLUMNS FROM push_subscriptions LIKE 'admin_id'") !== null;
            } catch (Throwable $e) {
                self::$pushCol = false;
            }
        }
        return self::$pushCol;
    }

    /**
     * Send the queued pushes — after the answer has left (fastcgi_finish_
     * request on php-fpm), so a slow push service never slows a send.
     */
    public static function flushNotify(): void
    {
        $queue = self::$queue;
        self::$queue = [];
        if ($queue === []) {
            return;
        }
        try {
            if (!Settings::getBool('staffmsg_push_on', true) || !self::pushReady()) {
                return;
            }
            if (PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request')) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                fastcgi_finish_request();
            }
            require_once INCLUDE_PATH . '/webpush.php';
            if (!WebPush::enabled()) {
                return;
            }
            $minute = date('YmdHi');
            foreach ($queue as $q) {
                $t = self::threadById($q['t']);
                if ($t === null) {
                    continue;
                }
                $recipients = $q['side'] === 'office'
                    ? [(int) $t['agent_admin_id']]
                    : self::officeRecipients((int) ($t['assigned_to'] ?? 0), $q['sender']);
                $payload = self::pushPayload($t, $q['side']);
                foreach ($recipients as $rid) {
                    if ($rid <= 0 || $rid === $q['sender']) {
                        continue;
                    }
                    if (!EventBus::claim('smsg:push:' . $q['t'] . ':' . $rid . ':' . $minute, 'staff_msg_push')) {
                        continue;
                    }
                    WebPush::sendToAdmin($rid, $payload, 'staff_msg');
                }
            }
        } catch (Throwable $e) {
            try { Logger::warning('StaffMsg push failed', ['err' => $e->getMessage()]); } catch (Throwable $ignored) {}
        }
    }

    /** @return list<int> office people to push for a field message */
    private static function officeRecipients(int $assigned, int $sender): array
    {
        if ($assigned > 0) {
            return [$assigned];
        }
        $in = "'" . implode("','", self::OFFICE_ROLES) . "'";
        try {
            return array_map('intval', array_column(Database::fetchAll(
                "SELECT DISTINCT p.admin_id FROM push_subscriptions p JOIN admins a ON a.id = p.admin_id
                  WHERE p.is_active = 1 AND p.admin_id IS NOT NULL AND a.is_active = 1 AND a.role IN ($in) AND a.id <> :s
                  LIMIT 25", ['s' => $sender]), 'admin_id'));
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Lock-screen text. No passenger name, no phone, no message text — only
     * who (staff) and which ticket.
     *
     * @param array<string,mixed> $t
     * @return array<string,string>
     */
    public static function pushPayload(array $t, string $side): array
    {
        $pnr   = (string) ($t['pnr'] ?? '');
        $about = match ((string) $t['kind']) {
            'booking'    => 'Ticket ' . $pnr,
            'verify'     => 'Suspicious ticket report',
            'settlement' => 'Settlement #' . (int) ($t['ref_id'] ?? 0),
            'payout'     => 'Payout request',
            default      => 'Office chat',
        };
        $who = $side === 'office'
            ? 'Office · कार्यालय'
            : (trim((string) ($t['agent_name'] ?? '')) ?: 'Agent');
        return [
            'title' => '💬 ' . mb_substr($who, 0, 40),
            'body'  => 'New message · नयाँ सन्देश — ' . $about,
            'url'   => '/admin/staff-chat.php?t=' . (int) $t['id'],
            'tag'   => 'smsg-' . (int) $t['id'],
            'lang'  => 'ne',
        ];
    }

    /* =================================================================
     *  Attachments
     * ================================================================= */

    /**
     * Validate and store one uploaded file under UPLOAD_PATH/staff-msg/Y/m/.
     * The type comes from the bytes (finfo), never from the browser.
     *
     * @param array<string,mixed> $file   a $_FILES entry
     * @param bool $voice                 the client says "voice note"
     * @param bool $isUpload              false only for CLI tests (no is_uploaded_file)
     * @return array{path:string,mime:string,size:int,kind:string}
     */
    public static function storeUpload(array $file, bool $voice = false, bool $isUpload = true): array
    {
        if (!Settings::getBool('staffmsg_attach_on', true)) {
            throw new RuntimeException('Photos and voice notes are switched off. / फाइल पठाउन बन्द छ।', 422);
        }
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('That file is too large. / फाइल धेरै ठूलो भयो।', 413);
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed — try again. / फेरि प्रयास गर्नुहोस्।', 422);
        }
        $tmp  = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp) || ($isUpload && !is_uploaded_file($tmp))) {
            throw new RuntimeException('Upload could not be verified.', 422);
        }
        $size = $size > 0 ? $size : (int) filesize($tmp);
        if ($size <= 0) {
            throw new RuntimeException('The file is empty.', 422);
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $map  = self::MIME[$mime] ?? null;
        if ($map === null || (str_starts_with($mime, 'video/') && !$voice) || ($voice && $map[0] !== 'voice')) {
            throw new RuntimeException('Only photos (JPG/PNG/WEBP), PDFs and voice notes can be sent. / फोटो, PDF वा भ्वाइस मात्र।', 415);
        }
        [$kind, $ext] = $map;
        $max = $kind === 'voice' ? self::VOICE_MAX : self::FILE_MAX;
        if ($size > $max) {
            throw new RuntimeException('Too large — the limit is ' . (int) ($max / 1048576) . ' MB. / ' . (int) ($max / 1048576) . ' MB भन्दा सानो।', 413);
        }
        if ($kind === 'image' && @getimagesize($tmp) === false) {
            throw new RuntimeException('That photo could not be read.', 415);
        }
        $root = UPLOAD_PATH . '/staff-msg';
        $sub  = date('Y') . '/' . date('m');
        $dir  = $root . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not store the file.', 500);
        }
        // Apache fallback (nginx denies /uploads/staff-msg/ in the vhost).
        if (!is_file($root . '/.htaccess')) {
            @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!is_file($root . '/index.html')) {
            @file_put_contents($root . '/index.html', '');
        }
        $name = Security::safeFilename($ext);
        $dest = $dir . '/' . $name;
        $ok = $isUpload ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
        if (!$ok) {
            throw new RuntimeException('Could not store the file.', 500);
        }
        @chmod($dest, 0640);
        return ['path' => 'staff-msg/' . $sub . '/' . $name, 'mime' => $mime, 'size' => $size, 'kind' => $kind];
    }

    /** Absolute path of a stored attachment, or null when the path is not inside staff-msg/. */
    public static function attachAbs(string $rel): ?string
    {
        if ($rel === '' || !str_starts_with($rel, 'staff-msg/') || str_contains($rel, '..') || str_contains($rel, "\0")) {
            return null;
        }
        $dir = realpath(UPLOAD_PATH . '/staff-msg');
        $abs = realpath(UPLOAD_PATH . '/' . $rel);
        if ($dir === false || $abs === false || !is_file($abs) || !str_starts_with($abs, $dir . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $abs;
    }

    /* =================================================================
     *  People: numbers, presence, pickers
     * ================================================================= */

    /** @return array<string,mixed> admin_profiles row (empty on an old database) */
    private static function profile(int $adminId): array
    {
        try {
            return Database::fetch('SELECT * FROM admin_profiles WHERE admin_id = :a', ['a' => $adminId]) ?? [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * wa.me digits from an admins row + profile — the logic agent-360.php used
     * (a360_wa_digits): profile WhatsApp first, then the login mobile, then the
     * display phone; a bare 10-digit number takes its country from a typed
     * prefix, a Nepali citizenship card, the profile's dialling code, else the
     * configured default.
     *
     * @param array<string,mixed> $agent
     * @param array<string,mixed> $profile
     */
    public static function waDigitsFrom(array $agent, array $profile, bool $phoneFirst = false): string
    {
        $chain = $phoneFirst
            ? [(string) ($agent['phone'] ?? ''), (string) ($profile['display_phone'] ?? ''), (string) ($profile['whatsapp'] ?? '')]
            : [(string) ($profile['whatsapp'] ?? ''), (string) ($agent['phone'] ?? ''), (string) ($profile['display_phone'] ?? '')];
        $raw = '';
        foreach ($chain as $c) {
            if (trim($c) !== '') { $raw = $c; break; }
        }
        $d = preg_replace('/\D/', '', $raw) ?? '';
        if ($d === '') {
            return '';
        }
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 10) {
            $c = resolvePhoneCountry('', $raw);
            if ($c === '' && (string) ($profile['id_type'] ?? '') === 'Citizenship') {
                $c = 'NP';
            }
            $cc = countryDialCode($c);
            if ($cc === '') {
                $pcc = preg_replace('/\D/', '', (string) ($profile['country_code'] ?? '')) ?? '';
                $cc  = in_array($pcc, ['91', '977'], true) ? $pcc : '';
            }
            if ($cc === '') {
                $cc = preg_replace('/\D/', '', Settings::getString('whatsapp_default_country', '91')) ?: '91';
            }
            $d = $cc . $d;
        }
        return $d;
    }

    public static function waDigits(int $adminId): string
    {
        $a = Database::fetch('SELECT id, phone FROM admins WHERE id = :a', ['a' => $adminId]);
        return $a === null ? '' : self::waDigitsFrom($a, self::profile($adminId));
    }

    /** Digits for tel: — the mobile first (a call), WhatsApp last. */
    public static function telDigits(int $adminId): string
    {
        $a = Database::fetch('SELECT id, phone FROM admins WHERE id = :a', ['a' => $adminId]);
        return $a === null ? '' : self::waDigitsFrom($a, self::profile($adminId), true);
    }

    /** "919104801507" from any typed office number. */
    private static function digitsWithCc(string $raw): string
    {
        $d = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 10) {
            $d = '91' . $d;
        }
        return strlen($d) >= 11 ? $d : '';
    }

    /**
     * Call / WhatsApp buttons for a thread header.
     *
     * @param array<string,mixed> $t
     * @return list<array{kind:string,label:string,href:string}>
     */
    public static function contactsFor(array $t, bool $viewerOffice): array
    {
        $out = [];
        if ($viewerOffice) {
            $aid = (int) $t['agent_admin_id'];
            $tel = self::telDigits($aid);
            $wa  = self::waDigits($aid);
            if ($tel !== '') { $out[] = ['kind' => 'tel', 'label' => 'Call', 'href' => 'tel:+' . $tel]; }
            if ($wa !== '')  { $out[] = ['kind' => 'wa', 'label' => 'WhatsApp', 'href' => 'https://wa.me/' . $wa]; }
            return $out;
        }
        $officeTel = self::digitsWithCc(Settings::officePhone());
        $officeWa  = Settings::officeWhatsApp();
        if ($officeTel !== '') { $out[] = ['kind' => 'tel', 'label' => 'Call office', 'href' => 'tel:+' . $officeTel]; }
        if ($officeWa !== '')  { $out[] = ['kind' => 'wa', 'label' => 'WhatsApp office', 'href' => 'https://wa.me/' . $officeWa]; }
        if (self::role() === 'agent') {
            $desk = CounterDesk::forAdmin(self::me());
            $row  = $desk['code'] !== '' ? CounterDesk::get($desk['code']) : null;
            $dTel = $row !== null ? self::digitsWithCc((string) ($row['phone'] ?? '')) : '';
            if ($dTel !== '' && $dTel !== $officeTel) {
                $out[] = ['kind' => 'tel', 'label' => 'Call desk ' . $desk['code'], 'href' => 'tel:+' . $dTel];
            }
        }
        return $out;
    }

    /** At most one write a minute per person (the session remembers the last). */
    public static function touchPresence(int $adminId): void
    {
        if ($adminId <= 0 || !self::tablesReady()) {
            return;
        }
        $k = 'shg_presence_at';
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[$k]) && time() - (int) $_SESSION[$k] < 60) {
            return;
        }
        try {
            Database::run('INSERT INTO staff_presence (admin_id, seen_at) VALUES (:a, NOW()) ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at)', ['a' => $adminId]);
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[$k] = time();
            }
        } catch (Throwable $e) {
        }
    }

    /** @return array{online:bool,at:int} at = unix seconds of the last sign of life (0 = never) */
    public static function presenceOf(int $adminId): array
    {
        $at = 0;
        try {
            $s = (string) Database::scalar('SELECT seen_at FROM staff_presence WHERE admin_id = :a', ['a' => $adminId], '');
            if ($s === '') {
                $s = (string) Database::scalar('SELECT last_login_at FROM admins WHERE id = :a', ['a' => $adminId], '');
            }
            $at = $s !== '' ? (strtotime($s) ?: 0) : 0;
        } catch (Throwable $e) {
        }
        return ['online' => $at > 0 && time() - $at <= 150, 'at' => $at];
    }

    /** Is anyone in the office online now? */
    public static function officePresence(): array
    {
        $at = 0;
        try {
            $in = "'" . implode("','", self::OFFICE_ROLES) . "'";
            $s  = (string) Database::scalar("SELECT MAX(p.seen_at) FROM staff_presence p JOIN admins a ON a.id = p.admin_id WHERE a.is_active = 1 AND a.role IN ($in)", [], '');
            $at = $s !== '' ? (strtotime($s) ?: 0) : 0;
        } catch (Throwable $e) {
        }
        return ['online' => $at > 0 && time() - $at <= 150, 'at' => $at];
    }

    /** @return list<array<string,mixed>> active agents + counters for the office "new message" picker */
    public static function fieldPeople(): array
    {
        try {
            $rows = Database::fetchAll(
                "SELECT a.id, a.full_name, a.username, a.role, p.counter_code, p.counter_name
                   FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id
                  WHERE a.is_active = 1 AND a.role IN ('agent', 'counter')
                  ORDER BY a.role, a.full_name LIMIT 1000"
            );
        } catch (Throwable $e) {
            $rows = Database::fetchAll("SELECT id, full_name, username, role FROM admins WHERE is_active = 1 AND role IN ('agent', 'counter') ORDER BY role, full_name LIMIT 1000");
        }
        return array_map(static fn(array $r): array => [
            'id'   => (int) $r['id'],
            'name' => trim((string) ($r['full_name'] ?? '')) ?: (string) $r['username'],
            'role' => (string) $r['role'],
            'code' => (string) ($r['counter_code'] ?? ''),
            'desk' => (string) ($r['counter_name'] ?? ''),
        ], $rows);
    }

    /** @return list<array<string,mixed>> office people a thread can be assigned to */
    public static function officePeople(): array
    {
        $in = "'" . implode("','", self::OFFICE_ROLES) . "'";
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'name' => trim((string) $r['full_name']) ?: (string) $r['username']],
            Database::fetchAll("SELECT id, full_name, username FROM admins WHERE is_active = 1 AND role IN ($in) ORDER BY full_name LIMIT 200"));
    }

    /* =================================================================
     *  Server-rendered entry points (booking page, agent home, 360)
     * ================================================================= */

    /** @return list<array<string,mixed>> threads about one booking the signed-in user may open */
    public static function threadsForBooking(int $bookingId): array
    {
        if ($bookingId <= 0 || !self::canUse()) {
            return [];
        }
        $params = ['b' => $bookingId];
        $where  = 't.booking_id = :b';
        if (!self::isOffice()) {
            $where .= self::fieldScopeSql($params);
        }
        $rows = Database::fetchAll(
            "SELECT t.*, a.full_name AS agent_name, a.username AS agent_username, a.role AS agent_role
               FROM staff_threads t LEFT JOIN admins a ON a.id = t.agent_admin_id
              WHERE $where ORDER BY t.last_msg_id DESC LIMIT 5", $params);
        return array_values(array_filter($rows, static fn(array $t): bool => self::mayOpen($t)));
    }

    /**
     * The "Office chat about this ticket" panel on admin/booking-view.php.
     *
     * @param array<string,mixed> $b BookingService::detail() row
     */
    public static function bookingPanel(array $b): string
    {
        try {
            if (!self::canUse()) {
                return '';
            }
            $office = self::isOffice();
            $me     = self::me();
            $pnr    = (string) ($b['pnr'] ?? '');
            $seller = is_array($b['agent'] ?? null) ? $b['agent'] : null;
            $sellerIsField = $seller !== null && in_array((string) ($seller['role'] ?? ''), self::FIELD_ROLES, true);
            $threads = self::threadsForBooking((int) ($b['id'] ?? 0));
            if ($office && !$sellerIsField && $threads === []) {
                return '';   // an online / office sale with no conversation: nobody to message
            }
            $e   = static fn($v): string => Security::e((string) $v);
            $url = '/admin/staff-chat.php?pnr=' . rawurlencode($pnr);
            $btn = $office
                ? '💬 Message seller · बेच्नेलाई सन्देश' . ($sellerIsField ? ' (' . (string) ($seller['full_name'] ?: $seller['username']) . ')' : '')
                : '💬 Ask the office about this ticket · अफिसलाई सोध्नुहोस्';
            $h  = '<div class="panel smsg-bv" id="officeChat"><h2 style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">'
                . '<svg class="a-ic"><use href="#a-msg"/></svg> Office chat about this ticket <span class="muted" style="font-weight:500;font-size:13px">· यो टिकटबारे कुराकानी</span></h2>'
                . '<div class="panel-body" style="display:grid;gap:10px">';
            $lines = [];
            foreach ($threads as $t) {
                $unread = (int) Database::scalar(
                    'SELECT COUNT(*) FROM staff_messages m LEFT JOIN staff_thread_reads r ON r.thread_id = m.thread_id AND r.admin_id = :me
                      WHERE m.thread_id = :t AND m.id > COALESCE(r.last_read_id, 0) AND m.side <> :s',
                    ['me' => $me, 't' => (int) $t['id'], 's' => $office ? 'office' : 'staff'], 0);
                $msgs = self::messages((int) $t['id'], 0, 3);
                $who  = trim((string) ($t['agent_name'] ?? '')) ?: (string) ($t['agent_username'] ?? '');
                $h .= '<a class="smsg-bv-t" href="/admin/staff-chat.php?t=' . (int) $t['id'] . '">'
                    . '<span class="smsg-bv-h"><b>' . ($office ? $e($who) . ' <span class="muted">· ' . $e(ucfirst((string) ($t['agent_role'] ?? ''))) . '</span>' : 'Office · कार्यालय') . '</b>'
                    . ($unread > 0 ? '<span class="smsg-bv-n">' . $unread . ' new</span>' : '')
                    . ((string) $t['status'] === 'closed' ? '<span class="pill st-muted">closed</span>' : '') . '</span>';
                foreach ($msgs as $m) {
                    $mine = (string) $m['side'] === ($office ? 'office' : 'staff');
                    $txt  = self::preview((string) $m['kind'], (string) ($m['body'] ?? ''));
                    $h .= '<span class="smsg-bv-m ' . ((string) $m['side'] === 'system' ? 'sys' : ($mine ? 'me' : 'them')) . '">'
                        . $e(mb_substr($txt, 0, 140)) . ' <small>' . $e(date('j M, H:i', strtotime((string) $m['created_at']) ?: time())) . '</small></span>';
                }
                $h .= '</a>';
                $lines[] = 1;
            }
            if ($lines === []) {
                $h .= '<p class="muted" style="margin:0;font-size:13px">'
                    . ($office ? 'No messages yet with the seller. Ask about the seat, name, payment — they answer here, not on WhatsApp.'
                               : 'No messages yet. Seat change, name fix, payment proof, cancel request — ask the office here. Cancellations and refunds are settled by the office by hand.')
                    . '</p>';
            }
            if ($office ? $sellerIsField : true) {
                $h .= '<div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn" href="' . $e($url) . '" style="min-height:44px">' . $e($btn) . '</a></div>';
            }
            $h .= '</div></div>';
            $h .= '<style>.smsg-bv-t{display:grid;gap:6px;padding:12px 14px;border:1px solid var(--line);border-radius:14px;background:var(--surface-sunken,var(--soft));color:var(--ink);text-decoration:none}'
                . '.smsg-bv-t:hover{border-color:var(--royal);color:var(--ink)}.smsg-bv-h{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:13.5px}'
                . '.smsg-bv-n{background:var(--royal);color:var(--on-royal,#fff);border-radius:999px;font-size:11px;font-weight:800;padding:1px 8px}'
                . '.smsg-bv-m{font-size:13px;line-height:1.35;padding:6px 10px;border-radius:12px;max-width:92%;background:var(--surface);border:1px solid var(--line)}'
                . '.smsg-bv-m.me{justify-self:end;background:var(--royal-tint,var(--soft));border-color:transparent}.smsg-bv-m.sys{justify-self:center;background:transparent;border-style:dashed;color:var(--muted);font-size:12px}'
                . '.smsg-bv-m small{color:var(--muted);font-size:10.5px;margin-left:4px;white-space:nowrap}</style>';
            return $h;
        } catch (Throwable $e) {
            Logger::warning('StaffMsg booking panel failed', ['err' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * The "Office chat" card on the agent home (admin/agent.php).
     */
    public static function agentCard(int $viewId, bool $isOwn): string
    {
        try {
            if (!self::canUse()) {
                return '';
            }
            $e = static fn($v): string => Security::e((string) $v);
            if (!$isOwn) {
                if (!self::isOffice()) {
                    return '';
                }
                $tel = self::telDigits($viewId);
                return '<div class="smsg-card" style="margin:0 0 18px"><div class="smsg-card-in">'
                    . '<span class="smsg-ic">💬</span><div style="flex:1;min-width:180px"><b>Office chat with this agent</b><div class="muted" style="font-size:12.5px">एजेन्टसँग कुराकानी — tickets, settlements, payouts</div></div>'
                    . '<a class="btn" style="min-height:44px" href="/admin/staff-chat.php?agent=' . $viewId . '">💬 Message this agent</a>'
                    . ($tel !== '' ? '<a class="btn ghost" style="min-height:44px" href="tel:+' . $e($tel) . '">📞 Call</a>' : '')
                    . '</div></div>' . self::cardCss();
            }
            $unread = self::unreadFor();
            $t      = self::threadFor('general');
            $last   = (string) ($t['last_preview'] ?? '');
            $off    = self::officePresence();
            $btns   = '';
            foreach (self::contactsFor($t, false) as $c) {
                $btns .= '<a class="btn ghost" style="min-height:44px" href="' . $e($c['href']) . '"' . ($c['kind'] === 'wa' ? ' target="_blank" rel="noopener"' : '') . '>'
                       . ($c['kind'] === 'wa' ? '🟢 ' : '📞 ') . $e($c['label']) . '</a>';
            }
            return '<div class="smsg-card" style="margin:0 0 18px"><div class="smsg-card-in">'
                . '<span class="smsg-ic">💬' . ($unread > 0 ? '<i>' . ($unread > 99 ? '99+' : $unread) . '</i>' : '') . '</span>'
                . '<div style="flex:1;min-width:200px"><b>Office chat · अफिस च्याट</b> '
                . '<span class="smsg-dot' . ($off['online'] ? ' on' : '') . '">' . ($off['online'] ? 'office online · अफिस अनलाइन' : 'office offline · अफिस अफलाइन — they answer when back') . '</span>'
                . '<div class="muted" style="font-size:12.5px;margin-top:2px">' . ($last !== '' ? $e(mb_substr($last, 0, 90)) : 'Ask about any ticket — seat, name, payment, pickup. सिट, नाम, भुक्तानी, चढ्ने ठाउँ — यहीँ सोध्नुहोस्।') . '</div></div>'
                . '<a class="btn" style="min-height:44px" href="/admin/staff-chat.php?t=' . (int) $t['id'] . '">' . ($unread > 0 ? '💬 Open · ' . $unread . ' new' : '💬 Open chat') . '</a>'
                . $btns
                . '</div></div>' . self::cardCss();
        } catch (Throwable $e) {
            Logger::warning('StaffMsg agent card failed', ['err' => $e->getMessage()]);
            return '';
        }
    }

    private static function cardCss(): string
    {
        return '<style>.smsg-card{border-radius:18px;padding:2px;background:linear-gradient(135deg,var(--royal),var(--success,#15803d));box-shadow:var(--shadow-2,0 6px 18px rgba(0,0,0,.12))}'
            . '.smsg-card-in{display:flex;gap:10px 12px;align-items:center;flex-wrap:wrap;background:var(--surface);border-radius:16px;padding:14px 16px}'
            . '.smsg-ic{position:relative;font-size:26px;width:48px;height:48px;border-radius:14px;display:inline-grid;place-items:center;background:var(--royal-tint,var(--soft));flex:0 0 auto}'
            . '.smsg-ic i{position:absolute;top:-6px;right:-6px;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#E5484D;color:#fff;font:800 11px/20px var(--font-sans,inherit);font-style:normal;text-align:center}'
            . '.smsg-dot{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;color:var(--muted);font-weight:600;margin-left:6px}'
            . '.smsg-dot::before{content:"";width:8px;height:8px;border-radius:50%;background:var(--muted)}.smsg-dot.on{color:var(--success)}.smsg-dot.on::before{background:var(--success);box-shadow:0 0 0 3px var(--success-soft,rgba(21,128,61,.2))}</style>';
    }

    /** @return list<array<string,mixed>> an agent's latest threads for the 360 page (office only) */
    public static function recentForAgent(int $agentId, int $limit = 3): array
    {
        if ($agentId <= 0 || !self::canUse() || !self::isOffice()) {
            return [];
        }
        $rows = Database::fetchAll(
            'SELECT t.*, a.full_name AS agent_name, a.username AS agent_username, a.role AS agent_role,
                    (SELECT COUNT(*) FROM staff_messages m WHERE m.thread_id = t.id AND m.side = \'staff\'
                        AND m.id > COALESCE((SELECT r.last_read_id FROM staff_thread_reads r WHERE r.thread_id = t.id AND r.admin_id = :me), 0)) AS unread
               FROM staff_threads t LEFT JOIN admins a ON a.id = t.agent_admin_id
              WHERE t.agent_admin_id = :a AND t.last_msg_id IS NOT NULL
              ORDER BY t.last_msg_id DESC LIMIT ' . max(1, min(10, $limit)),
            ['a' => $agentId, 'me' => self::me()]
        );
        return array_map(static fn(array $r): array => self::presentThread($r, true), $rows);
    }

    /* =================================================================
     *  Cron
     * ================================================================= */

    /**
     * Every 5 minutes: quiet threads close themselves, and (only when the
     * office switched it on) ONE WhatsApp template nudges an agent who has not
     * read an office message. No ticket details ever go in the nudge.
     *
     * @return array<string,int>
     */
    public static function tick(): array
    {
        $out = ['closed' => 0, 'nudged' => 0, 'nudge_failed' => 0];
        if (!self::tablesReady()) {
            return $out;
        }
        $days = Settings::getInt('staffmsg_autoclose_days', 14);
        if ($days > 0) {
            $out['closed'] = Database::run(
                "UPDATE staff_threads SET status = 'closed', closed_at = NOW()
                  WHERE status = 'open' AND last_msg_at IS NOT NULL AND last_msg_at < (NOW() - INTERVAL :d DAY)",
                ['d' => $days]
            );
        }
        if (!Settings::getBool('staffmsg_wa_nudge_on', false)) {
            return $out;
        }
        $mins = max(5, Settings::getInt('staffmsg_wa_nudge_min', 15));
        $tpl  = Settings::getString('staffmsg_wa_template', 'shg_staff_msg');
        $rows = Database::fetchAll(
            "SELECT t.id AS tid, t.last_msg_id, t.agent_admin_id, a.full_name, a.username
               FROM staff_threads t
               JOIN admins a ON a.id = t.agent_admin_id AND a.is_active = 1
               LEFT JOIN staff_thread_reads r ON r.thread_id = t.id AND r.admin_id = t.agent_admin_id
              WHERE t.status = 'open' AND t.last_side = 'office'
                AND t.last_msg_at < (NOW() - INTERVAL :m MINUTE) AND t.last_msg_at > (NOW() - INTERVAL 1 DAY)
                AND t.last_msg_id > COALESCE(r.last_read_id, 0)
              LIMIT 50",
            ['m' => $mins]
        );
        if ($rows === []) {
            return $out;
        }
        require_once ROOT_PATH . '/whatsapp/aisensy.php';
        if (!AiSensyAPI::enabled()) {
            return $out;
        }
        foreach ($rows as $r) {
            if (!EventBus::claim('smsg:nudge:' . (int) $r['last_msg_id'], 'staff_msg_nudge')) {
                continue;
            }
            $to = self::waDigits((int) $r['agent_admin_id']);
            if ($to === '') {
                continue;
            }
            $name = trim((string) ($r['full_name'] ?? '')) ?: (string) $r['username'];
            $res  = AiSensyAPI::sendMessage($to, $tpl, [mb_substr($name, 0, 40), rtrim(APP_URL, '/') . '/admin/staff-chat.php?t=' . (int) $r['tid']]);
            !empty($res['success']) ? $out['nudged']++ : $out['nudge_failed']++;
        }
        return $out;
    }
}
