<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/agentapplications.php';
require_once __DIR__ . '/staffapplications.php';
require_once __DIR__ . '/agentwallet.php';
require_once __DIR__ . '/agentpro.php';

/** A refusal meant for the office (safe to show on the page). */
final class TeamPurgeError extends RuntimeException {}

/**
 * TeamPurge — what the office may do to a whole person: suspend, archive,
 * restore, and the permanent delete (30 Oct 2026, owner: "admin le sabai
 * control garna paune — team ID, paisa, permanent delete, commercial grade").
 *
 *   suspend($aid, on/off)  card SUSPENDED + login OFF (admins.is_active = 0),
 *                          so the WhatsApp desk and /me/ stop too (ApWa::usable
 *                          and Auth both read is_active). Activate = both back.
 *   archive($aid, …)       login off, card suspended, off the website; a
 *                          snapshot of the previous state is kept in the
 *                          settings map team_manager_archive under the key
 *                          'account:<id>' (the key TeamManager uses), so
 *                          restore() puts back exactly what was there.
 *   history($kind, $id)    what stops a delete: tickets sold, ledger / money
 *                          rows, settlements, shifts, payouts, verified
 *                          payments, boarding scans … Read only. Every table
 *                          / column is checked through information_schema
 *                          first, so a database that lacks a table never throws.
 *   purge($kind, $id, …)   super-admin + typed DELETE + NO history. Writes a
 *                          gzip JSON snapshot of every row (and the files) to
 *                          BACKUP_PATH/team-purge/ FIRST, then deletes inside
 *                          one transaction, then removes the files.
 *
 * $kind: 'admin' = admins.id (a login, with every application behind it),
 *        'ap'    = ap_applications.id (an AgentPro form: a draft, a rejected
 *                  form, or a submitted one — with its inactive login),
 *        'agent' = agent_applications.id, 'staff' = staff_applications.id
 *                  (the older forms, kept so one rule covers every page).
 *
 * Anyone WITH history is never deleted by purge(): archive instead (login off,
 * off the website, money and booking records kept). The SHG agent number IS
 * freed (AgentWallet::setAgentCode(id, null), audited).
 *
 *   purgeWithHistory($kind, $id, $actor, 'DELETE ALL', $reason, $tickets)
 *                          31 Oct 2026, the super-admin's override for old,
 *                          disabled members: their money account goes; their
 *                          tickets stay in the office's name ('office') or the
 *                          old ones go too ('delete' — a trip still to come, or
 *                          a ticket another member is also on, always stays;
 *                          the customers on deleted tickets are saved into
 *                          user_profiles first). Snapshot v3 = before-image of
 *                          every deleted / changed row. forcePreview() counts it.
 */
final class TeamPurge
{
    public const KINDS = ['admin', 'ap', 'agent', 'staff'];

    /** Typed confirmation word. */
    public const CONFIRM = 'DELETE';

    /** Settings map shared with TeamManager. */
    public const ARCHIVE_KEY = 'team_manager_archive';

    /** Files larger than this (all together) are listed in the snapshot, not embedded. */
    private const SNAP_FILE_BYTES = 12_000_000;

    /**
     * Typed confirmation of the delete WITH history (31 Oct 2026, owner: "disable gareko purano
     * member — baki ticket haru ni chhan — sab delete gardeu, dhyan rakha").
     */
    public const CONFIRM_ALL = 'DELETE ALL';

    /** office = their tickets stay, in the office's name; delete = old tickets go too (upcoming trips always stay). */
    public const TICKET_MODES = ['office', 'delete'];

    /** A booking in one of these states is dead: an upcoming leg does not keep it. */
    private const DEAD_STATUSES = ['cancelled', 'rejected', 'expired'];

    /** The person's own account rows: deleted with them in both modes. [table, column, label] */
    private const OWN_ROWS = [
        ['agent_ledger',          'agent_admin_id', 'Wallet ledger entries · खाता (लेजर)'],
        ['agent_settlements',     'agent_admin_id', 'Settlements · हिसाब मिलान'],
        ['offline_tickets',       'agent_admin_id', 'Paper tickets · कागजी टिकट'],
        ['agent_payout_requests', 'agent_admin_id', 'Payout requests · भुक्तानी अनुरोध'],
        ['agent_loans',           'agent_admin_id', 'Loans / advances · ऋण / पेस्की'],
        ['agent_deposit_txns',    'agent_admin_id', 'Security deposits · धरौटी'],
        ['ap_deposit_requests',   'admin_id',       'Payments-in · जम्मा अनुरोध'],
        ['counter_shifts',        'admin_id',       'Counter cash shifts · काउन्टर सिफ्ट'],
        ['offline_requests',      'admin_id',       'Offline bookings / cash taken · अफलाइन बुकिङ'],
        ['staff_threads',         'agent_admin_id', 'Staff chat conversations · च्याट'],
        ['delivery_log',          'agent_id',       'Ticket delivery offers · टिकट डेलिभरी'],
        ['message_logs',          'agent_admin_id', 'Messages sent to them · सन्देश लग'],
        ['wa_logins',             'admin_id',       'WhatsApp sign-ins · WhatsApp लगइन'],
        ['wa_identity_links',     'admin_id',       'WhatsApp identity links · WhatsApp पहिचान'],
        ['push_subscriptions',    'admin_id',       'Phone notifications · सूचना'],
        ['ap_wa_state',           'admin_id',       'WhatsApp desk state · WhatsApp डेस्क'],
    ];

    /** Rows plan() already deletes for the login (and the applications subject() collects). */
    private const PLAN_PAIRS = [
        'admin_sessions.admin_id', 'agent_route_permissions.admin_id', 'agent_targets.agent_admin_id', 'agent_lessons.admin_id',
        'admin_devices.admin_id', 'admin_login_events.admin_id', 'wa_agent_presence.admin_id', 'staff_presence.admin_id',
        'staff_thread_reads.admin_id', 'admin_profiles.admin_id', 'ap_members.admin_id', 'ap_wa_links.admin_id',
        'ap_passkeys.admin_id', 'ap_applications.admin_id', 'agent_applications.admin_id', 'staff_applications.admin_id',
    ];

    /** Column names that only RECORD who did something (an admins.id): set NULL on other people's rows. */
    private const ACTOR_NAMES = [
        'created_by', 'updated_by', 'decided_by', 'approved_by', 'paid_by', 'verified_by', 'scanned_by', 'processed_by',
        'cancelled_by', 'assigned_to', 'reviewed_by', 'kyc_verified_by', 'ended_by', 'set_by', 'office_filled_by',
        'closed_by', 'resolved_by', 'finalized_by', 'released_by', 'generated_by', 'uploaded_by', 'dismissed_by',
        'activated_by', 'launched_by', 'blocked_by', 'status_changed_by', 'revoked_by', 'confirmed_by', 'adjusted_by',
        'uploaded_by_admin_id', 'sender_admin_id', 'actor_admin_id', 'preview_admin_id', 'owner_admin_id',
    ];

    /** The same, where the column has a generic name. */
    private const ACTOR_PAIRS = [
        'payment_reviews.admin_id', 'msg_ledger.admin_id', 'message_logs.admin_id', 'wa_outbox.admin_id', 'trip_status.admin_id',
        // sub-agents keep their account; only the link to this (deleted) parent goes
        'admin_profiles.parent_agent_id', 'agent_applications.parent_agent_id',
    ];

    /** Never edited: the audit trail, and codes that are never reused. */
    private const LEAVE_TABLES = ['audit_logs', 'domain_events', 'member_codes', 'team_code_history', 'ai_conversations', 'ai_action_confirmations'];

    /** When a booking is deleted these rows stay (they are someone else's record) and only lose the link, if it may be NULL. */
    private const DETACH_ON_BOOKING_DELETE = ['agent_ledger', 'offline_tickets', 'offline_requests', 'staff_threads', 'msg_ledger',
        'push_subscriptions', 'smartbook_requests', 'support_tickets', 'feedback', 'loyalty_transactions'];

    /** history() block keys that follow the phone (drivers / payroll): left as they are, only reported. */
    private const PHONE_KEYS = ['trips', 'schedules', 'salary', 'advances'];

    /** @var array<string, array<string, true>>|null table => columns, this database */
    private static ?array $cols = null;

    /** @var array<string, array<string, array{null:bool,type:string,len:int}>>|null */
    private static ?array $meta = null;

    /** @var list<array{child:string,col:string,parent:string,pcol:string,rule:string}>|null real FOREIGN KEYs */
    private static ?array $fks = null;

    // ------------------------------------------------------------------
    //  Schema helpers (information_schema, cached per request)
    // ------------------------------------------------------------------

    /** Does $table exist with every column in $cols? */
    public static function has(string $table, string ...$cols): bool
    {
        if (self::$cols === null) {
            self::$cols = [];
            self::$meta = [];
            try {
                foreach (Database::fetchAll(
                    'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, IS_NULLABLE AS n, DATA_TYPE AS d, CHARACTER_MAXIMUM_LENGTH AS l
                       FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
                ) as $r) {
                    $t = strtolower((string) $r['t']);
                    $c = strtolower((string) $r['c']);
                    self::$cols[$t][$c] = true;
                    self::$meta[$t][$c] = ['null' => (string) $r['n'] === 'YES', 'type' => strtolower((string) $r['d']), 'len' => (int) ($r['l'] ?? 0)];
                }
            } catch (Throwable $e) {
                Logger::warning('TeamPurge schema read failed: ' . $e->getMessage(), [], 'team');
            }
        }
        $t = self::$cols[strtolower($table)] ?? null;
        if ($t === null) {
            return false;
        }
        foreach ($cols as $c) {
            if (!isset($t[strtolower($c)])) {
                return false;
            }
        }
        return true;
    }

    public static function resetCache(): void
    {
        self::$cols = null;
        self::$meta = null;
        self::$fks = null;
    }

    /** @return array{null:bool,type:string,len:int}|null */
    private static function colMeta(string $table, string $col): ?array
    {
        self::has($table);
        return self::$meta[strtolower($table)][strtolower($col)] ?? null;
    }

    /** @return list<array{child:string,col:string,parent:string,pcol:string,rule:string}> */
    private static function fks(): array
    {
        if (self::$fks === null) {
            self::$fks = [];
            try {
                // Two reads joined here: the JOIN of the two views takes seconds on MariaDB, each alone milliseconds.
                $rules = [];
                foreach (Database::fetchAll(
                    'SELECT TABLE_NAME AS t, CONSTRAINT_NAME AS n, DELETE_RULE AS rule FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()'
                ) as $r) {
                    $rules[strtolower((string) $r['t']) . '|' . (string) $r['n']] = strtoupper((string) $r['rule']);
                }
                foreach (Database::fetchAll(
                    'SELECT TABLE_NAME AS child, COLUMN_NAME AS col, CONSTRAINT_NAME AS n, REFERENCED_TABLE_NAME AS parent, REFERENCED_COLUMN_NAME AS pcol
                       FROM information_schema.KEY_COLUMN_USAGE
                      WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
                      ORDER BY REFERENCED_TABLE_NAME, TABLE_NAME, COLUMN_NAME'
                ) as $r) {
                    $child = strtolower((string) $r['child']);
                    self::$fks[] = ['child' => $child, 'col' => strtolower((string) $r['col']),
                                    'parent' => strtolower((string) $r['parent']), 'pcol' => strtolower((string) $r['pcol']),
                                    // a rule we cannot read is treated as RESTRICT: never assume the database cleans up
                                    'rule' => $rules[$child . '|' . (string) $r['n']] ?? 'RESTRICT'];
                }
            } catch (Throwable $e) {
                // Unknown foreign keys = the database would surprise us: refuse rather than delete blind.
                throw new TeamPurgeError('The database links could not be read — nothing was deleted.');
            }
        }
        return self::$fks;
    }

    /** "IN (…)" for ids or keys of any type (all-digit values bound as ints). */
    private static function inAny(array $vals, array &$params, string $p): string
    {
        $vals = array_values(array_filter($vals, static fn($v): bool => $v !== null && $v !== ''));
        $digits = $vals !== [] && array_filter($vals, static fn($v): bool => !preg_match('/^\d+$/', (string) $v)) === [];
        return $digits ? self::in($vals, $params, $p) : self::inStr($vals, $params, $p);
    }

    /** "IN (:p0,:p1)" for a list of ints; adds the params. '' when the list is empty. */
    private static function in(array $ids, array &$params, string $p): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        if ($ids === []) {
            return '';
        }
        $ph = [];
        foreach ($ids as $i => $v) {
            $ph[] = ':' . $p . $i;
            $params[$p . $i] = $v;
        }
        return 'IN (' . implode(',', $ph) . ')';
    }

    /** Same for strings. */
    private static function inStr(array $vals, array &$params, string $p): string
    {
        $vals = array_values(array_unique(array_filter(array_map('strval', $vals), static fn(string $v): bool => $v !== '')));
        if ($vals === []) {
            return '';
        }
        $ph = [];
        foreach ($vals as $i => $v) {
            $ph[] = ':' . $p . $i;
            $params[$p . $i] = $v;
        }
        return 'IN (' . implode(',', $ph) . ')';
    }

    private static function count(string $sql, array $params): int
    {
        try {
            return (int) Database::scalar($sql, $params, 0);
        } catch (Throwable $e) {
            // A count we cannot read is treated as history: never delete blind.
            Logger::warning('TeamPurge count failed: ' . $e->getMessage(), [], 'team');
            return 1;
        }
    }

    /** Who may suspend / archive / restore: a super-admin or a manager (TeamManager's rule). */
    public static function mayManage(array $actor): bool
    {
        return in_array((string) ($actor['role'] ?? ''), ['superadmin', 'manager'], true);
    }

    // ------------------------------------------------------------------
    //  Suspend · archive · restore (the login + the card together)
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null the archive snapshot of this login, or null */
    public static function archived(int $adminId): ?array
    {
        $map = Settings::getArray(self::ARCHIVE_KEY, []);
        $row = $map['account:' . $adminId] ?? null;
        return is_array($row) ? $row : null;
    }

    /** @return array<int, true> admin ids that are archived */
    public static function archivedIds(): array
    {
        $out = [];
        foreach (array_keys(Settings::getArray(self::ARCHIVE_KEY, [])) as $k) {
            if (preg_match('/^account:(\d+)$/', (string) $k, $m) === 1) {
                $out[(int) $m[1]] = true;
            }
        }
        return $out;
    }

    /** The target login, checked: exists, not a super-admin, not the actor. */
    private static function target(int $adminId, array $actor, string $what): array
    {
        $a = Database::fetch('SELECT id, username, full_name, role, is_active FROM admins WHERE id = :i FOR UPDATE', ['i' => $adminId]);
        if ($a === null) {
            throw new TeamPurgeError('No such member · सदस्य भेटिएन।');
        }
        if ((string) $a['role'] === 'superadmin') {
            throw new TeamPurgeError('A super-admin login cannot be ' . $what . ' here · सुपर-एडमिनलाई यहाँबाट ' . $what . ' गर्न मिल्दैन।');
        }
        if ((int) $a['id'] === (int) ($actor['id'] ?? 0)) {
            throw new TeamPurgeError('You cannot change your own account here · आफ्नै खाता यहाँबाट बदल्न मिल्दैन।');
        }
        return $a;
    }

    /**
     * Suspend (card suspended + login off) or activate (both back on).
     * Activating a login whose application still waits is refused — approve it instead.
     */
    public static function suspend(int $adminId, bool $on, array $actor, string $reason = ''): void
    {
        if (!self::mayManage($actor) && !Auth::can('staff.manage')) {
            throw new TeamPurgeError('Your role cannot suspend members.');
        }
        $reason = Security::clean($reason, 200);
        Database::transaction(static function () use ($adminId, $on, $actor, $reason): void {
            $a = self::target($adminId, $actor, $on ? 'suspended' : 'activated');
            if (self::archived($adminId) !== null) {
                throw new TeamPurgeError('This member is archived — restore them first · पहिले Restore गर्नुहोस्।');
            }
            if (!$on && AgentPro::available()
                && Database::exists("SELECT 1 FROM ap_applications WHERE admin_id = :a AND status = 'submitted'", ['a' => $adminId])) {
                throw new TeamPurgeError('This person\'s application is still waiting — approve it on Team → Approvals · पहिले आवेदन स्वीकृत गर्नुहोस्।');
            }
            AgentPro::ensureMember($adminId, (string) $a['role']);
            Database::update('ap_members', ['card_status' => $on ? 'suspended' : 'active'], 'admin_id = :a', ['a' => $adminId]);
            Database::update('admins', ['is_active' => $on ? 0 : 1], 'id = :a', ['a' => $adminId]);
            Logger::audit($on ? 'team.suspended' : 'team.activated', 'admin', (string) $adminId,
                ['is_active' => (int) $a['is_active']], ['is_active' => $on ? 0 : 1, 'card_status' => $on ? 'suspended' : 'active'],
                ($on ? 'Suspended (card + login)' : 'Activated (card + login)') . ' by admin #' . (int) ($actor['id'] ?? 0), $reason);
        });
    }

    /** Archive (login off, card suspended, off the website) or restore exactly what was there. */
    public static function archive(int $adminId, bool $restore, array $actor, string $reason = ''): void
    {
        if (!self::mayManage($actor)) {
            throw new TeamPurgeError('Only a super-admin or a manager can archive or restore · सुपर-एडमिन वा म्यानेजर मात्र।');
        }
        $reason = Security::clean($reason, 200);
        Database::transaction(static function () use ($adminId, $restore, $actor, $reason): void {
            $a = self::target($adminId, $actor, $restore ? 'restored' : 'archived');
            // Serialise map edits on the settings row (TeamManager does the same).
            Database::query("INSERT IGNORE INTO settings (skey,svalue,stype,sgroup,is_public) VALUES ('" . self::ARCHIVE_KEY . "','{}','json','agent',0)");
            $locked = Database::fetch('SELECT svalue FROM settings WHERE skey = :k FOR UPDATE', ['k' => self::ARCHIVE_KEY]);
            $map = json_decode((string) ($locked['svalue'] ?? '{}'), true);
            $map = is_array($map) ? $map : [];
            $key = 'account:' . $adminId;
            $hasListing = self::has('admin_profiles', 'admin_id', 'public_listing');
            $m = AgentPro::ensureMember($adminId, (string) $a['role']);
            if (!$restore) {
                if (isset($map[$key])) {
                    return;
                }
                $listing = $hasListing ? (int) Database::scalar('SELECT public_listing FROM admin_profiles WHERE admin_id = :a', ['a' => $adminId], 0) : 0;
                $map[$key] = ['at' => date('c'), 'by' => (int) ($actor['id'] ?? 0), 'active' => (int) $a['is_active'],
                              'card' => (string) ($m['card_status'] ?? 'active'), 'listing' => $listing, 'reason' => $reason, 'src' => 'agentpro'];
                Database::update('admins', ['is_active' => 0], 'id = :a', ['a' => $adminId]);
                Database::update('ap_members', ['card_status' => 'suspended'], 'admin_id = :a', ['a' => $adminId]);
                if ($hasListing) {
                    Database::update('admin_profiles', ['public_listing' => 0], 'admin_id = :a', ['a' => $adminId]);
                }
            } else {
                if (!isset($map[$key])) {
                    return;
                }
                $snap = (array) $map[$key];
                Database::update('admins', ['is_active' => (int) ($snap['active'] ?? 0) === 1 ? 1 : 0], 'id = :a', ['a' => $adminId]);
                $card = in_array($snap['card'] ?? '', ['active', 'suspended', 'revoked'], true) ? (string) $snap['card'] : 'active';
                Database::update('ap_members', ['card_status' => $card], 'admin_id = :a', ['a' => $adminId]);
                if ($hasListing) {
                    Database::update('admin_profiles', ['public_listing' => (int) ($snap['listing'] ?? 0) === 1 ? 1 : 0], 'admin_id = :a', ['a' => $adminId]);
                }
                unset($map[$key]);
            }
            Settings::set(self::ARCHIVE_KEY, $map, 'json', 'agent', false);
            Logger::audit($restore ? 'team.restore' : 'team.archive', 'admin', (string) $adminId, null,
                ['account' => $adminId, 'is_active' => (int) $a['is_active']],
                ($restore ? 'Restored' : 'Archived') . ' by admin #' . (int) ($actor['id'] ?? 0), $reason);
        });
        Settings::flush();
    }

    // ------------------------------------------------------------------
    //  Who is this? (the application rows + the login behind them)
    // ------------------------------------------------------------------

    /**
     * @return array{found:bool, kind:string, id:int, ref:string, name:string, phone:string,
     *   admin_id:int, admin:?array, agent_ids:list<int>, staff_ids:list<int>, ap_ids:list<int>, refs:list<string>, keys:list<string>}
     */
    private static function subject(string $kind, int $id): array
    {
        $out = ['found' => false, 'kind' => $kind, 'id' => $id, 'ref' => '', 'name' => '', 'phone' => '',
                'admin_id' => 0, 'admin' => null, 'agent_ids' => [], 'staff_ids' => [], 'ap_ids' => [], 'refs' => [], 'keys' => []];
        if (!in_array($kind, self::KINDS, true) || $id <= 0) {
            return $out;
        }
        if ($kind === 'ap' && self::has('ap_applications', 'id', 'admin_id')) {
            $a = Database::fetch('SELECT id, ref, full_name, org_name, phone, admin_id FROM ap_applications WHERE id = :i', ['i' => $id]);
            if ($a === null) {
                return $out;
            }
            $out['found'] = true;
            $out['ref'] = (string) $a['ref'];
            $out['name'] = (string) (($a['full_name'] ?? '') ?: ($a['org_name'] ?? ''));
            $out['phone'] = (string) ($a['phone'] ?? '');
            $out['admin_id'] = (int) ($a['admin_id'] ?? 0);
            $out['ap_ids'][] = $id;
        } elseif ($kind === 'agent' && self::has('agent_applications', 'id')) {
            $a = Database::fetch('SELECT id, ref, name, phone, admin_id FROM agent_applications WHERE id = :i', ['i' => $id]);
            if ($a === null) {
                return $out;
            }
            $out['found'] = true;
            $out['ref'] = (string) ($a['ref'] ?: AgentApplications::refFor($id));
            $out['name'] = (string) $a['name'];
            $out['phone'] = (string) $a['phone'];
            $out['admin_id'] = (int) ($a['admin_id'] ?? 0);
            $out['agent_ids'][] = $id;
        } elseif ($kind === 'staff' && self::has('staff_applications', 'id')) {
            $s = Database::fetch('SELECT * FROM staff_applications WHERE id = :i', ['i' => $id]);
            if ($s === null) {
                return $out;
            }
            $out['found'] = true;
            $out['ref'] = StaffApps::refFor($id);
            $out['name'] = (string) ($s['full_name'] ?? '');
            $out['phone'] = (string) ($s['phone'] ?? '');
            $out['admin_id'] = (int) ($s['admin_id'] ?? 0);
            $out['staff_ids'][] = $id;
        } elseif ($kind === 'admin') {
            $out['admin_id'] = $id;
        }

        $aid = $out['admin_id'];
        if ($aid > 0) {
            $adm = Database::fetch('SELECT id, username, full_name, phone, role, is_active FROM admins WHERE id = :i', ['i' => $aid]);
            if ($adm === null) {
                $out['admin_id'] = 0;          // the link points at nothing: just the application
                if ($kind === 'admin') {
                    return $out;
                }
            } else {
                $out['admin'] = $adm;
                if ($kind === 'admin') {
                    $out['found'] = true;
                    $out['ref'] = 'ACC-' . $aid;
                    $out['name'] = (string) ($adm['full_name'] ?: $adm['username']);
                    $out['phone'] = (string) ($adm['phone'] ?? '');
                }
                // Every application linked to this login goes with it (no orphan pointing at a deleted id).
                foreach ([['ap_applications', 'ap_ids'], ['agent_applications', 'agent_ids'], ['staff_applications', 'staff_ids']] as [$t, $slot]) {
                    if (self::has($t, 'id', 'admin_id')) {
                        foreach (Database::fetchAll("SELECT id FROM $t WHERE admin_id = :a", ['a' => $aid]) as $r) {
                            $out[$slot][] = (int) $r['id'];
                        }
                    }
                }
            }
        }
        foreach (['ap_ids', 'agent_ids', 'staff_ids'] as $slot) {
            $out[$slot] = array_values(array_unique($out[$slot]));
        }
        foreach ($out['agent_ids'] as $i) {
            $out['refs'][] = AgentApplications::refFor($i);
            $out['keys'][] = 'agent:' . $i;
        }
        foreach ($out['staff_ids'] as $i) {
            $out['refs'][] = StaffApps::refFor($i);
            $out['keys'][] = 'staff:' . $i;
        }
        if ($out['ap_ids'] !== []) {
            $p = [];
            $w = self::in($out['ap_ids'], $p, 'q');
            foreach (Database::fetchAll("SELECT ref FROM ap_applications WHERE id $w", $p) as $r) {
                $out['refs'][] = (string) $r['ref'];
            }
        }
        if (self::has('agent_applications', 'ref') && $out['agent_ids'] !== []) {
            $p = [];
            $w = self::in($out['agent_ids'], $p, 'g');
            foreach (Database::fetchAll("SELECT ref FROM agent_applications WHERE id $w AND ref IS NOT NULL AND ref <> ''", $p) as $r) {
                $out['refs'][] = (string) $r['ref'];
            }
        }
        if ($aid > 0) {
            $out['keys'][] = 'account:' . $aid;
        }
        $out['refs'] = array_values(array_unique(array_filter($out['refs'])));
        return $out;
    }

    /** Last-10-digit and full-digit forms of a phone, for the drivers / payroll match. */
    private static function phoneForms(string $phone): array
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($d) < 7) {
            return [];
        }
        return array_values(array_unique([$d, substr($d, -10), normalisePhone($d)]));
    }

    // ------------------------------------------------------------------
    //  history() — what blocks a delete
    // ------------------------------------------------------------------

    /**
     * @return array{found:bool, kind:string, id:int, ref:string, name:string, phone:string, admin_id:int,
     *   admin:?array, agent_ids:list<int>, staff_ids:list<int>, ap_ids:list<int>, refs:list<string>, keys:list<string>,
     *   blocks:list<array{key:string,label:string,count:int}>, removes:list<array{label:string,count:int}>,
     *   refuse:string, purgeable:bool}
     */
    public static function history(string $kind, int $id): array
    {
        $s = self::subject($kind, $id);
        $s['blocks'] = [];
        $s['removes'] = [];
        $s['refuse'] = '';
        $s['purgeable'] = false;
        if (!$s['found']) {
            $s['refuse'] = 'Record not found · रेकर्ड भेटिएन।';
            return $s;
        }
        $aid = (int) $s['admin_id'];
        if ($s['admin'] !== null && (string) $s['admin']['role'] === 'superadmin') {
            $s['refuse'] = 'A super-admin login is never deleted here · सुपर-एडमिन मेटिँदैन।';
        }

        $block = static function (string $key, string $label, int $n) use (&$s): void {
            if ($n > 0) {
                $s['blocks'][] = ['key' => $key, 'label' => $label, 'count' => $n];
            }
        };

        if ($aid > 0) {
            $a = ['a' => $aid];
            // Tickets: sold by, sub-agent on, or parent agent of.
            // (native prepares: a named parameter may appear only once per statement)
            $bk = [];
            $bp = [];
            foreach (['sold_by_admin_id', 'sub_agent_admin_id', 'parent_agent_id'] as $i => $c) {
                if (self::has('bookings', $c)) { $bk[] = "$c = :b$i"; $bp['b' . $i] = $aid; }
            }
            if ($bk !== []) {
                $block('bookings', 'Tickets sold / handled · टिकट बिक्री', self::count('SELECT COUNT(*) FROM bookings WHERE ' . implode(' OR ', $bk), $bp));
            }
            $money = [
                ['agent_ledger',          'agent_admin_id', 'ledger',      'Wallet ledger entries · खाता (लेजर)'],
                ['agent_settlements',     'agent_admin_id', 'settlements', 'Settlements · हिसाब मिलान'],
                ['offline_tickets',       'agent_admin_id', 'offline',     'Paper tickets · कागजी टिकट'],
                ['agent_payout_requests', 'agent_admin_id', 'payouts',     'Payout requests · भुक्तानी अनुरोध'],
                ['agent_loans',           'agent_admin_id', 'loans',       'Loans / advances · ऋण / पेस्की'],
                ['agent_deposit_txns',    'agent_admin_id', 'deposits',    'Security deposits · धरौटी'],
                ['counter_shifts',        'admin_id',       'shifts',      'Counter cash shifts · काउन्टर सिफ्ट'],
                ['payments',              'verified_by',    'payments',    'Payments verified · भुक्तानी प्रमाणित'],
                ['tickets',               'scanned_by',     'scans',       'Tickets scanned at boarding · टिकट स्क्यान'],
                ['agent_ledger',          'created_by',     'ledger_by',   'Ledger entries this person recorded · लेखेको लेजर'],
                ['payout_requests',       'processed_by',   'payouts_by',  'Customer payouts processed · ग्राहक भुक्तानी'],
                ['schedules',             'cancelled_by',   'sched_by',    'Trips cancelled by this login · रद्द यात्रा'],
                ['support_tickets',       'assigned_to',    'support',     'Support tickets assigned · सहायता टिकट'],
                ['admin_profiles',        'parent_agent_id','subagents',   'Sub-agents under this agent · सब-एजेन्ट'],
                ['agent_applications',    'parent_agent_id','sub_apps',    'Applications under this agent · मातहत आवेदन'],
                ['ap_deposit_requests',   'decided_by',     'ap_dep_by',   'Payments-in this person decided · जम्मा निर्णय'],
                ['offline_requests',      'admin_id',       'offline_q',   'Offline bookings / cash taken · अफलाइन बुकिङ / नगद'],
                ['payment_reviews',       'admin_id',       'pay_reviews', 'Payment reviews this person did · भुक्तानी जाँच'],
                ['trip_expenses',         'created_by',     'trip_exp_by', 'Trip expenses this person recorded · यात्रा खर्च'],
                ['counter_shift_expenses','created_by',     'shift_exp_by','Counter expenses this person recorded · काउन्टर खर्च'],
                ['staff_salary_payments', 'created_by',     'salary_by',   'Salary payments this person recorded · तलब भुक्तानी'],
                ['staff_salary_payments', 'updated_by',     'salary_upd',  'Salary payments this person edited · तलब सम्पादन'],
                ['staff_advances',        'created_by',     'advance_by',  'Staff advances this person recorded · कर्मचारी पेस्की'],
                ['agent_loans',           'created_by',     'loans_by',    'Agent loans this person recorded · एजेन्ट ऋण'],
                ['agent_deposit_txns',    'created_by',     'deposits_by', 'Deposit entries this person recorded · धरौटी प्रविष्टि'],
                ['offline_tickets',       'created_by',     'offline_by',  'Paper tickets this person recorded · कागजी टिकट (लेखेको)'],
            ];
            foreach ($money as [$t, $c, $key, $label]) {
                if (self::has($t, $c)) {
                    $block($key, $label, self::count("SELECT COUNT(*) FROM $t WHERE $c = :a", $a));
                }
            }
            // Money they paid in that the office confirmed is accounts history.
            if (self::has('ap_deposit_requests', 'admin_id', 'status')) {
                $block('ap_deposits', 'Payments-in confirmed by the office · कम्पनीमा जम्मा', self::count("SELECT COUNT(*) FROM ap_deposit_requests WHERE admin_id = :a AND status = 'confirmed'", $a));
            }
            foreach (['created_by', 'approved_by', 'paid_by'] as $c) {
                if (self::has('agent_settlements', $c)) {
                    $block('settle_' . $c, 'Settlements this person ' . str_replace('_by', 'd', $c) . ' · हिसाब (कर्मचारी)', self::count("SELECT COUNT(*) FROM agent_settlements WHERE $c = :a", $a));
                }
            }
            if (self::has('staff_threads', 'agent_admin_id', 'msg_count')) {
                $block('chat', 'Staff chat conversations with messages · च्याट', self::count('SELECT COUNT(*) FROM staff_threads WHERE agent_admin_id = :a AND msg_count > 0', $a));
            }
            $deposit = AgentWallet::depositInfo($aid);
            if ((float) ($deposit['paid'] ?? 0) > 0.009) {
                $block('deposit_held', 'Security deposit held ' . inr((float) $deposit['paid']) . ' · धरौटी बाँकी', 1);
            }
        }

        // Driver trips and payroll follow the phone (drivers / staff_members are separate registers).
        if ($s['staff_ids'] !== [] || $kind === 'admin') {
            $forms = self::phoneForms((string) $s['phone']);
            if ($forms !== [] && self::has('drivers', 'id', 'phone')) {
                $p = [];
                $w = self::inStr($forms, $p, 'f');
                $dids = array_map('intval', array_column(Database::fetchAll("SELECT id FROM drivers WHERE phone $w", $p), 'id'));
                if ($dids !== []) {
                    $p2 = [];
                    $w2 = self::in($dids, $p2, 'd');
                    if (self::has('live_trips', 'driver_id')) {
                        $block('trips', 'GPS trips driven · GPS यात्रा', self::count("SELECT COUNT(*) FROM live_trips WHERE driver_id $w2", $p2));
                    }
                    if (self::has('schedules', 'driver_id')) {
                        $block('schedules', 'Trips assigned as driver · तोकिएको यात्रा', self::count("SELECT COUNT(*) FROM schedules WHERE driver_id $w2", $p2));
                    }
                }
            }
            if ($forms !== [] && self::has('staff_members', 'id', 'phone')) {
                $p = [];
                $w = self::inStr($forms, $p, 'f');
                $sids = array_map('intval', array_column(Database::fetchAll("SELECT id FROM staff_members WHERE phone $w", $p), 'id'));
                if ($sids !== []) {
                    foreach ([['staff_salary_payments', 'Salary payments · तलब भुक्तानी', 'salary'], ['staff_advances', 'Staff advances · पेस्की', 'advances']] as [$t, $label, $key]) {
                        if (self::has($t, 'staff_id')) {
                            $p2 = [];
                            $w2 = self::in($sids, $p2, 'm');
                            $block($key, $label, self::count("SELECT COUNT(*) FROM $t WHERE staff_id $w2", $p2));
                        }
                    }
                }
            }
        }

        // What a purge would remove (preview).
        $rm = static function (string $label, int $n) use (&$s): void {
            if ($n > 0) { $s['removes'][] = ['label' => $label, 'count' => $n]; }
        };
        $rm('Join form (AgentPro) + its photos and documents · आवेदन फारम', count($s['ap_ids']));
        $rm('Older agent / partner application · पुरानो आवेदन', count($s['agent_ids']));
        $rm('Older staff application + its documents · कर्मचारी आवेदन', count($s['staff_ids']));
        $rm('Login account + profile · लगइन खाता', $s['admin'] !== null ? 1 : 0);
        if ($aid > 0) {
            $a = ['a' => $aid];
            if (self::has('ap_members', 'admin_id')) {
                $rm('ID card + visiting card record (the old QR then reads not found) · ID कार्ड', self::count('SELECT COUNT(*) FROM ap_members WHERE admin_id = :a', $a));
            }
            if (self::has('ap_wa_links', 'admin_id')) {
                $rm('WhatsApp numbers linked to this member · WhatsApp लिंक', self::count('SELECT COUNT(*) FROM ap_wa_links WHERE admin_id = :a', $a));
            }
            if (self::has('ap_passkeys', 'admin_id')) {
                $rm('Fingerprint / face sign-ins · पासकी', self::count('SELECT COUNT(*) FROM ap_passkeys WHERE admin_id = :a', $a));
            }
            if (self::has('ap_deposit_requests', 'admin_id')) {
                $rm('Unconfirmed payment-in requests · नमिलेको जम्मा अनुरोध', self::count('SELECT COUNT(*) FROM ap_deposit_requests WHERE admin_id = :a', $a));
            }
            if (AgentWallet::agentCodeFor($aid) !== null) {
                $rm('SHG agent number ' . AgentWallet::agentCodeLabel($aid) . ' is freed · नम्बर खाली हुन्छ', 1);
            }
        }
        if (self::has('team_identity', 'person_key') && $s['keys'] !== []) {
            $p = [];
            $w = self::inStr($s['keys'], $p, 'k');
            $rm('Team ID record (the code itself stays reserved) · टिम ID', self::count("SELECT COUNT(*) FROM team_identity WHERE person_key $w", $p));
        }

        $s['purgeable'] = $s['refuse'] === '' && $s['blocks'] === [];
        return $s;
    }

    // ------------------------------------------------------------------
    //  purge()
    // ------------------------------------------------------------------

    /**
     * Permanently delete a record that has NO history. $actor = the admin_boot() row.
     *
     * @return array{ref:string, backup:string, rows:int, files:int}
     */
    public static function purge(string $kind, int $id, array $actor, string $confirm, string $reason = ''): array
    {
        if ((string) ($actor['role'] ?? '') !== 'superadmin') {
            throw new TeamPurgeError('Only a super-admin can delete permanently · स्थायी रूपमा मेटाउन सुपर-एडमिन मात्र।');
        }
        if (trim($confirm) !== self::CONFIRM) {
            throw new TeamPurgeError('Type DELETE (in capitals) to confirm · पुष्टि गर्न DELETE टाइप गर्नुहोस्।');
        }
        $by = (int) ($actor['id'] ?? 0);
        $reason = mb_substr(trim(Security::clean($reason, 300)), 0, 300);

        $result = Database::transaction(static function () use ($kind, $id, $by, $reason): array {
            // Lock the rows first, then re-read the history under the lock (no sale can slip in).
            $pre = self::subject($kind, $id);
            if ($pre['admin_id'] > 0) {
                Database::fetch('SELECT id FROM admins WHERE id = :i FOR UPDATE', ['i' => $pre['admin_id']]);
            }
            foreach ([['ap_applications', 'ap_ids'], ['agent_applications', 'agent_ids'], ['staff_applications', 'staff_ids']] as [$t, $slot]) {
                foreach ($pre[$slot] as $i) {
                    Database::fetch("SELECT id FROM $t WHERE id = :i FOR UPDATE", ['i' => $i]);
                }
            }
            $h = self::history($kind, $id);
            if (!$h['found']) {
                throw new TeamPurgeError('Record not found · रेकर्ड भेटिएन।');
            }
            if ($h['refuse'] !== '') {
                throw new TeamPurgeError($h['refuse']);
            }
            if ((int) $h['admin_id'] > 0 && (int) $h['admin_id'] === $by) {
                throw new TeamPurgeError('You cannot delete your own login · आफ्नै खाता मेटाउन मिल्दैन।');
            }
            if ($h['blocks'] !== []) {
                $list = implode(', ', array_map(static fn(array $b): string => $b['count'] . ' × ' . $b['label'], $h['blocks']));
                throw new TeamPurgeError('Not deleted — this person has history: ' . $list
                    . '. Archive instead (login off, off the website, money and booking records kept) · इतिहास भएकोले मेटिँदैन — Archive गर्नुहोस्।');
            }

            $plan = self::plan($h);
            $snap = ['version' => 2, 'at' => date('c'), 'by' => $by, 'reason' => $reason,
                     'subject' => ['kind' => $kind, 'id' => $id, 'ref' => $h['ref'], 'name' => $h['name'], 'refs' => $h['refs'], 'keys' => $h['keys']],
                     'rows' => [], 'settings' => [], 'files' => []];
            foreach ($plan['tables'] as [$table, $where, $params]) {
                $rows = Database::fetchAll("SELECT * FROM $table WHERE $where", $params);
                if ($rows !== []) {
                    $snap['rows'][$table] = array_merge($snap['rows'][$table] ?? [], array_map([self::class, 'scrub'], $rows));
                }
            }
            $files = self::files($h, $snap['rows']);
            $snap['settings'] = self::settingsMaps($h, false);
            $budget = self::SNAP_FILE_BYTES;
            foreach ($files as $rel => $abs) {
                $size = (int) @filesize($abs);
                if ($size > 0 && $size <= $budget) {
                    $bytes = @file_get_contents($abs);
                    if (is_string($bytes)) {
                        $snap['files'][$rel] = base64_encode($bytes);
                        $budget -= $size;
                        continue;
                    }
                }
                $snap['files'][$rel] = null;   // listed, too large to embed
            }

            $backup = self::writeSnapshot($h['ref'] !== '' ? $h['ref'] : ($kind . '-' . $id), $snap);

            $n = 0;
            foreach ($plan['tables'] as [$table, $where, $params]) {
                $n += Database::run("DELETE FROM $table WHERE $where", $params);
            }
            foreach ($plan['updates'] as [$sql, $params]) {
                $n += Database::run($sql, $params);
            }
            if ((int) $h['admin_id'] > 0 && AgentWallet::agentCodeFor((int) $h['admin_id']) !== null) {
                AgentWallet::setAgentCode((int) $h['admin_id'], null, $by);
            }
            self::settingsMaps($h, true);

            Logger::audit('team.purged', $kind, $h['ref'],
                ['name' => $h['name'], 'phone' => $h['phone'], 'refs' => $h['refs'], 'admin_id' => (int) $h['admin_id']],
                ['backup' => basename($backup), 'rows' => $n, 'files' => count($files)],
                'Permanently deleted (no history) by admin #' . $by, $reason);

            return ['ref' => $h['ref'], 'backup' => $backup, 'rows' => $n, 'files' => $files];
        }, 1);

        // Files go only after the rows are gone for good.
        $removed = 0;
        foreach ($result['files'] as $abs) {
            if (is_file($abs) && @unlink($abs)) {
                $removed++;
            }
        }
        foreach (array_unique(array_map('dirname', $result['files'])) as $dir) {
            if (preg_match('~[\\\\/]staff[\\\\/]\d+$~', $dir) === 1 && is_dir($dir) && (glob($dir . '/*') ?: []) === []) {
                @rmdir($dir);
            }
        }
        Settings::flush();
        self::resetCache();
        AgentApplications::resetCache();

        return ['ref' => $result['ref'], 'backup' => basename($result['backup']), 'rows' => $result['rows'], 'files' => $removed];
    }

    // ------------------------------------------------------------------
    //  purgeWithHistory() — the delete WITH history (31 Oct 2026)
    //  Owner: "agent lai permanently delete/disable sab kura admin ko haat ma hos".
    //  history() still decides what a NORMAL delete may do; this is the super-admin's
    //  override for old, disabled members: their money account goes, their tickets stay
    //  in the office's name (or the old ones go too), and a full before-image is
    //  written to backup/team-purge/ first.
    // ------------------------------------------------------------------

    /**
     * Archived / disabled members the office may delete for good in one go: archived (any role but
     * super-admin), or an agent whose login is off. A login whose join form still waits for approval
     * is an applicant, not a disabled member, and is never listed.
     *
     * @return list<array{id:int, username:string, name:string, role:string, active:bool, archived:bool}>
     */
    public static function disabledMembers(int $exceptId = 0): array
    {
        $arch = self::archivedIds();
        $p = [];
        $or = "(a.role = 'agent' AND a.is_active = 0)";
        if ($arch !== []) {
            $or .= ' OR a.id ' . self::in(array_keys($arch), $p, 'r');
        }
        $wait = self::has('ap_applications', 'admin_id', 'status')
            ? " AND NOT EXISTS (SELECT 1 FROM ap_applications w WHERE w.admin_id = a.id AND w.status = 'submitted')" : '';
        $out = [];
        foreach (Database::fetchAll("SELECT a.id, a.username, a.full_name, a.role, a.is_active FROM admins a
                                      WHERE a.role <> 'superadmin' AND ($or)$wait ORDER BY a.full_name, a.id", $p) as $r) {
            if ((int) $r['id'] === $exceptId) {
                continue;
            }
            $out[] = ['id' => (int) $r['id'], 'username' => (string) $r['username'], 'name' => (string) ($r['full_name'] ?: $r['username']),
                      'role' => (string) $r['role'], 'active' => (int) $r['is_active'] === 1, 'archived' => isset($arch[(int) $r['id']])];
        }
        return $out;
    }

    /**
     * What a delete WITH history would do, per tickets mode, for the page / the CLI. Read only.
     *
     * @return array<string, mixed>
     */
    public static function forcePreview(string $kind, int $id): array
    {
        $h = self::history($kind, $id);
        $out = ['found' => $h['found'], 'refuse' => $h['refuse'], 'kind' => $kind, 'id' => $id, 'name' => $h['name'], 'ref' => $h['ref'],
                'admin_id' => (int) $h['admin_id'], 'code' => '', 'bookings' => 0, 'upcoming' => 0, 'past' => 0, 'cancelled' => 0,
                'shared' => 0, 'value' => 0.0,
                'office' => ['bookings_deleted' => 0, 'bookings_detached' => 0, 'kept_upcoming' => 0, 'kept_shared' => 0],
                'delete' => ['bookings_deleted' => 0, 'bookings_detached' => 0, 'kept_upcoming' => 0, 'kept_shared' => 0,
                             'customers' => 0, 'customers_skipped' => 0],
                'money_rows' => 0, 'money' => [], 'actor_rows' => 0, 'actor_left' => [], 'cascade' => [], 'blockers' => [],
                'unknown' => [], 'payroll' => self::payrollOf($h), 'removes' => $h['removes']];
        if (!$h['found'] || $h['refuse'] !== '') {
            return $out;
        }
        $plan = self::forcePlan($h, 'office', false);
        $b = $plan['books'];
        $all = count($b['all']);
        $out['code'] = (int) $h['admin_id'] > 0 ? AgentWallet::agentCodeLabel((int) $h['admin_id']) : '';
        $out['bookings'] = $all;
        $out['upcoming'] = count($b['upcoming']);
        $out['past'] = $b['past'];
        $out['cancelled'] = $b['dead'];
        $out['shared'] = count($b['shared']);
        $out['value'] = $b['value'];
        $out['office'] = ['bookings_deleted' => 0, 'bookings_detached' => $all, 'kept_upcoming' => count($b['upcoming']), 'kept_shared' => 0];
        $cust = self::customersOf($b['del']);
        $out['delete'] = ['bookings_deleted' => count($b['del']), 'bookings_detached' => $all - count($b['del']),
                          'kept_upcoming' => count($b['upcoming']), 'kept_shared' => count($b['shared']),
                          'customers' => count($cust['list']), 'customers_skipped' => $cust['skipped']];
        $out['money'] = $plan['money'];
        $out['money_rows'] = array_sum(array_column($plan['money'], 'count'));
        foreach (['actor_rows', 'actor_left', 'cascade', 'blockers', 'unknown'] as $k) {
            $out[$k] = $plan[$k];
        }
        return $out;
    }

    /**
     * Permanently delete a person WITH their history. Super-admin + typed DELETE ALL.
     *   $tickets 'office'  every booking they sold / handled stays; the links to them go and the
     *                      office note says who sold it.
     *            'delete'  their old bookings go with every row under them; a booking with a trip
     *                      still to come (not cancelled) is always kept as in 'office', and so is one
     *                      another person is also on (seller / sub-agent / parent / their ledger).
     * Their own money account rows go in both modes; rows that only record them as the actor on
     * someone else's data lose the name (or are reported when the column may not be empty).
     *
     * @return array<string, mixed> ref, name, backup, rows, files, bookings_deleted, bookings_detached,
     *   kept_upcoming, kept_shared, child_rows, money_rows, actor_rows_nulled, actor_rows_left, unknown, payroll, tickets
     */
    public static function purgeWithHistory(string $kind, int $id, array $actor, string $confirm, string $reason = '', string $tickets = 'office'): array
    {
        if ((string) ($actor['role'] ?? '') !== 'superadmin') {
            throw new TeamPurgeError('Only a super-admin can delete permanently · स्थायी रूपमा मेटाउन सुपर-एडमिन मात्र।');
        }
        if (trim($confirm) !== self::CONFIRM_ALL) {
            throw new TeamPurgeError('Type DELETE ALL (in capitals) to confirm · पुष्टि गर्न DELETE ALL टाइप गर्नुहोस्।');
        }
        if (!in_array($tickets, self::TICKET_MODES, true)) {
            throw new TeamPurgeError('Choose what happens to the tickets · टिकट के गर्ने छान्नुहोस्।');
        }
        $by = (int) ($actor['id'] ?? 0);
        $reason = mb_substr(trim(Security::clean($reason, 300)), 0, 300);
        self::resetCache();          // fresh schema + links for every person (the bulk loop)
        $backup = '';
        $csv = '';

        try {
            $result = Database::transaction(static function () use ($kind, $id, $by, $reason, $tickets, &$backup, &$csv): array {
                // Lock the login and the forms first, then re-read everything under the lock.
                $pre = self::subject($kind, $id);
                if ($pre['admin_id'] > 0) {
                    Database::fetch('SELECT id FROM admins WHERE id = :i FOR UPDATE', ['i' => $pre['admin_id']]);
                }
                foreach ([['ap_applications', 'ap_ids'], ['agent_applications', 'agent_ids'], ['staff_applications', 'staff_ids']] as [$t, $slot]) {
                    foreach ($pre[$slot] as $i) {
                        Database::fetch("SELECT id FROM $t WHERE id = :i FOR UPDATE", ['i' => $i]);
                    }
                }
                $h = self::history($kind, $id);
                if (!$h['found']) {
                    throw new TeamPurgeError('Record not found · रेकर्ड भेटिएन।');
                }
                if ($h['refuse'] !== '') {
                    throw new TeamPurgeError($h['refuse']);
                }
                $aid = (int) $h['admin_id'];
                if ($aid > 0 && $aid === $by) {
                    throw new TeamPurgeError('You cannot delete your own login · आफ्नै खाता मेटाउन मिल्दैन।');
                }
                $code = $aid > 0 ? AgentWallet::agentCodeLabel($aid) : '';
                $plan = self::forcePlan($h, $tickets, true);
                if ($plan['blockers'] !== []) {
                    throw new TeamPurgeError('Not deleted — these rows must name a login and the database will not empty them: '
                        . implode(', ', $plan['blockers']) . '. Nothing was changed · केही मेटिएन।');
                }
                $b = $plan['books'];
                $del = $tickets === 'delete' ? $b['del'] : [];
                $keep = array_values(array_diff($b['all'], $del));
                // The customers on the tickets that go are read now, while the bookings still exist.
                $cust = self::customersOf($del);
                $why = 'agent ' . trim($h['name'] . ' ' . $code) . ' #' . $aid;

                // 1. The before-image of every row this run deletes or changes.
                $snap = ['version' => 3, 'what' => 'purge_all', 'at' => date('c'), 'by' => $by, 'reason' => $reason, 'tickets' => $tickets,
                         'subject' => ['kind' => $kind, 'id' => $id, 'ref' => $h['ref'], 'name' => $h['name'], 'phone' => $h['phone'],
                                       'admin_id' => $aid, 'agent_code' => $code, 'refs' => $h['refs'], 'keys' => $h['keys']],
                         'bookings' => ['deleted' => $del, 'detached' => $keep, 'kept_upcoming' => $b['upcoming'],
                                        'kept_shared' => $tickets === 'delete' ? $b['shared'] : [], 'note' => $plan['tag']],
                         'left' => ['actor_rows' => $plan['actor_left'], 'unknown' => $plan['unknown'], 'payroll' => self::payrollOf($h)],
                         'ops' => [], 'rows' => [], 'changed' => [], 'settings' => [], 'files' => []];
                $seen = [];   // table => row hash => 'rows' | 'changed'
                foreach ($plan['ops'] as $op) {
                    $rows = Database::fetchAll("SELECT * FROM `{$op['t']}` WHERE {$op['w']}", $op['p']);
                    $snap['ops'][] = ['op' => $op['op'], 'table' => $op['t'], 'set' => $op['set'], 'phase' => $op['k'], 'rows' => count($rows)];
                    foreach ($rows as $r) {
                        $r = self::scrub($r);
                        $key = md5((string) json_encode($r, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
                        $was = $seen[$op['t']][$key] ?? null;
                        if ($was === 'rows' || ($was === 'changed' && $op['op'] === 'update')) {
                            continue;   // already kept, as it was before any write
                        }
                        if ($was === 'changed') {
                            unset($snap['changed'][$op['t']][$key]);   // changed first, deleted in the end
                        }
                        $sec = $op['op'] === 'delete' ? 'rows' : 'changed';
                        $snap[$sec][$op['t']][$key] = $r;
                        $seen[$op['t']][$key] = $sec;
                    }
                }
                foreach (['rows', 'changed'] as $sec) {
                    foreach ($snap[$sec] as $t => $list) {
                        $snap[$sec][$t] = array_values($list);
                    }
                }
                unset($seen);
                $phones = array_column($cust['list'], 'phone');
                $snap['customers'] = ['phones' => $phones, 'skipped' => $cust['skipped'], 'note' => $why];
                if ($phones !== [] && self::has('user_profiles', 'phone')) {
                    $pp = [];
                    $snap['changed']['user_profiles'] = array_map([self::class, 'scrub'],
                        Database::fetchAll('SELECT * FROM user_profiles WHERE phone ' . self::inStr($phones, $pp, 'u'), $pp));
                }
                // The person's own files first (KYC), then ticket / upload files of deleted bookings.
                $files = self::files($h, $snap['rows']) + self::bookingFiles($snap['rows']);
                $embedded = [];
                $budget = self::SNAP_FILE_BYTES;
                foreach ($files as $rel => $abs) {
                    $size = (int) @filesize($abs);
                    $bytes = $size > 0 && $size <= $budget ? @file_get_contents($abs) : false;
                    if (is_string($bytes)) {
                        $snap['files'][$rel] = base64_encode($bytes);
                        $embedded[$rel] = true;
                        $budget -= $size;
                    } else {
                        $snap['files'][$rel] = null;   // too large to embed: moved next to the backup after COMMIT
                    }
                }
                $snap['settings'] = self::settingsMaps($h, false);
                $backup = self::writeSnapshot(($h['ref'] !== '' ? $h['ref'] : ($kind . '-' . $id)) . '-all', $snap);
                unset($snap);

                // 2. The customers first (owner: "customer name number for data"), then the writes in plan order.
                $kept = self::writeCustomers($cust, $why);
                $csv = self::writeCustomersCsv($backup, $kept['list']);
                $n = 0;
                $count = ['bookings_deleted' => 0, 'child_rows' => 0, 'money_rows' => 0, 'actor_rows_nulled' => 0];
                foreach ($plan['ops'] as $op) {
                    $sql = $op['op'] === 'delete'
                        ? "DELETE FROM `{$op['t']}` WHERE {$op['w']}"
                        : "UPDATE `{$op['t']}` SET {$op['set']} WHERE {$op['w']}";
                    $k = Database::run($sql, array_merge($op['p'], $op['sp']));
                    $n += $k;
                    if ($op['k'] === 'tickets' && $op['op'] === 'delete') {
                        $count[$op['t'] === 'bookings' ? 'bookings_deleted' : 'child_rows'] += $k;
                    } elseif ($op['k'] === 'own' && $op['op'] === 'delete') {
                        $count['money_rows'] += $k;
                    } elseif ($op['k'] === 'actor') {
                        $count['actor_rows_nulled'] += $k;
                    }
                }
                if ($aid > 0) {
                    if (AgentWallet::agentCodeFor($aid) !== null) {
                        AgentWallet::setAgentCode($aid, null, $by);
                    }
                    // Nothing may still point at a login that is gone.
                    if (Database::exists('SELECT 1 FROM admins WHERE id = :a', ['a' => $aid])) {
                        throw new TeamPurgeError('The login could not be removed — nothing was deleted.');
                    }
                    $bw = [];
                    $bp = [];
                    foreach (['sold_by_admin_id', 'sub_agent_admin_id', 'parent_agent_id'] as $i => $c) {
                        if (self::has('bookings', $c)) { $bw[] = "$c = :b$i"; $bp['b' . $i] = $aid; }
                    }
                    if ($bw !== [] && Database::exists('SELECT 1 FROM bookings WHERE ' . implode(' OR ', $bw) . ' LIMIT 1', $bp)) {
                        throw new TeamPurgeError('A ticket still names this person — nothing was deleted (try again).');
                    }
                }
                self::settingsMaps($h, true);

                $res = ['ref' => $h['ref'], 'name' => $h['name'], 'code' => $code, 'rows' => $n,
                        'bookings_detached' => count($keep), 'kept_upcoming' => count($b['upcoming']),
                        'kept_shared' => $tickets === 'delete' ? count($b['shared']) : 0] + $count
                     + ['customers_kept' => $kept['customers_kept'], 'customers_new' => $kept['customers_new'],
                        'customers_skipped' => $kept['skipped'], 'customers_csv' => $csv !== '' ? basename($csv) : ''];
                Logger::audit('team.purged_all', $kind, $h['ref'],
                    ['name' => $h['name'], 'phone' => $h['phone'], 'refs' => $h['refs'], 'admin_id' => $aid, 'agent_code' => $code,
                     'history' => array_map(static fn(array $x): string => $x['count'] . ' × ' . $x['key'], $h['blocks'])],
                    ['backup' => basename($backup), 'tickets' => $tickets, 'files' => count($files)] + $res,
                    'Permanently deleted WITH history (tickets: ' . $tickets . ') by admin #' . $by, $reason);

                return $res + ['files' => $files, 'embedded' => $embedded, 'actor_left' => $plan['actor_left'],
                               'unknown' => $plan['unknown'], 'payroll' => self::payrollOf($h)];
            }, 1);
        } catch (Throwable $ex) {
            foreach ([$backup, $csv] as $f) {
                if ($f !== '' && is_file($f)) {
                    @unlink($f);   // rolled back: a backup of a delete that never happened would only confuse
                }
            }
            throw $ex;
        }

        // Files go only after the rows are gone for good: embedded ones are removed, the rest move next to the backup.
        $side = (string) preg_replace('/\.json\.gz$/', '', $backup) . '-files';
        $removed = 0;
        $moved = 0;
        foreach ($result['files'] as $rel => $abs) {
            if (!is_file($abs)) {
                continue;
            }
            if (!empty($result['embedded'][$rel])) {
                if (@unlink($abs)) { $removed++; }
                continue;
            }
            $dest = $side . '/' . trim((string) preg_replace('~\.\.+|[^A-Za-z0-9._/-]+~', '_', (string) $rel), '/');
            if ((is_dir(dirname($dest)) || @mkdir(dirname($dest), 0750, true)) && (@rename($abs, $dest) || (@copy($abs, $dest) && @unlink($abs)))) {
                $moved++;
            }
        }
        foreach (array_unique(array_map('dirname', $result['files'])) as $dir) {
            if (preg_match('~[\\\\/]staff[\\\\/]\d+$~', $dir) === 1 && is_dir($dir) && (glob($dir . '/*') ?: []) === []) {
                @rmdir($dir);
            }
        }
        Settings::flush();
        self::resetCache();
        AgentApplications::resetCache();

        $out = $result;
        unset($out['embedded']);
        $out['backup'] = basename($backup);
        $out['files'] = $removed + $moved;
        $out['files_moved'] = $moved;
        $out['tickets'] = $tickets;
        $out['actor_rows_left'] = $result['actor_left'];
        unset($out['actor_left']);
        return $out;
    }

    /** One line for the office / the command line. */
    public static function resultText(array $r): string
    {
        $t = '🗑 Deleted with all history: ' . ($r['name'] ?? '') . ($r['code'] ?? '' ? ' ' . $r['code'] : '') . ' (' . ($r['ref'] ?? '') . ')'
            . ' — tickets: ' . (int) ($r['bookings_deleted'] ?? 0) . ' deleted, ' . (int) ($r['bookings_detached'] ?? 0) . ' kept in the office\'s name'
            . ((int) ($r['kept_upcoming'] ?? 0) > 0 ? ' (' . (int) $r['kept_upcoming'] . ' with a trip still to come)' : '')
            . ((int) ($r['kept_shared'] ?? 0) > 0 ? ' (' . (int) $r['kept_shared'] . ' shared with another member)' : '')
            . ((int) ($r['customers_kept'] ?? 0) + (int) ($r['customers_new'] ?? 0) > 0
                ? '; customers kept as data: ' . (int) $r['customers_new'] . ' new + ' . (int) $r['customers_kept'] . ' already known (' . ($r['customers_csv'] ?? '') . ')' : '')
            . '; ' . (int) ($r['money_rows'] ?? 0) . ' money rows, ' . (int) ($r['actor_rows_nulled'] ?? 0) . ' "done by" names cleared, '
            . (int) ($r['files'] ?? 0) . ' files. Backup: ' . ($r['backup'] ?? '');
        if (!empty($r['actor_rows_left'])) {
            $t .= '. Left as they are (column may not be empty): ' . implode(', ', (array) $r['actor_rows_left']);
        }
        return $t;
    }

    /** The preview as <li> lines (the member page, the approvals panel and the bulk card). */
    public static function forceFactsHtml(array $f): string
    {
        $e = static fn($v): string => Security::e((string) $v);
        $o = '<li><b>' . (int) $f['bookings'] . '</b> tickets sold / handled' . ($f['value'] > 0 ? ' (' . $e(inr((float) $f['value'])) . ' confirmed)' : '')
            . ' · ' . (int) $f['upcoming'] . ' with a trip still to come (always kept) · ' . (int) $f['past'] . ' past · ' . (int) $f['cancelled'] . ' cancelled / expired'
            . ((int) $f['shared'] > 0 ? ' · ' . (int) $f['shared'] . ' shared with another member (kept)' : '') . '</li>';
        $o .= '<li>“Delete old tickets too” deletes <b>' . (int) $f['delete']['bookings_deleted'] . '</b> and keeps ' . (int) $f['delete']['bookings_detached'] . ' in the office\'s name'
            . ((int) $f['delete']['customers'] > 0 ? ' — the ' . (int) $f['delete']['customers'] . ' customers on them (name + mobile) stay in the customer list · ग्राहकको नाम/नम्बर रहन्छ' : '') . '</li>';
        if ($f['money'] !== []) {
            $o .= '<li><b>' . (int) $f['money_rows'] . '</b> money / account rows deleted: '
                . $e(implode(', ', array_map(static fn(array $m): string => $m['count'] . ' × ' . $m['label'], $f['money']))) . '</li>';
        }
        if ((int) $f['actor_rows'] > 0) {
            $o .= '<li>' . (int) $f['actor_rows'] . ' rows on other people\'s records only say “done by” this person — the name is cleared, the rows stay</li>';
        }
        if ($f['actor_left'] !== []) {
            $o .= '<li>Left as they are (the column may not be empty): ' . $e(implode(', ', $f['actor_left'])) . '</li>';
        }
        if ($f['payroll'] !== []) {
            $o .= '<li>Driver trips / payroll follow the phone and stay: '
                . $e(implode(', ', array_map(static fn(array $m): string => $m['count'] . ' × ' . $m['label'], $f['payroll']))) . '</li>';
        }
        if ($f['unknown'] !== []) {
            $o .= '<li>Other columns naming this id, left as they are: ' . $e(implode(', ', $f['unknown'])) . '</li>';
        }
        if ($f['blockers'] !== []) {
            $o .= '<li style="color:#b91c1c;font-weight:800">⛔ Blocked — the database will not empty: ' . $e(implode(', ', $f['blockers'])) . '</li>';
        }
        return $o;
    }

    /** @return list<array{label:string,count:int}> drivers / payroll rows (phone-keyed): reported, never touched */
    private static function payrollOf(array $h): array
    {
        $out = [];
        foreach ((array) ($h['blocks'] ?? []) as $b) {
            if (in_array($b['key'], self::PHONE_KEYS, true)) {
                $out[] = ['label' => (string) $b['label'], 'count' => (int) $b['count']];
            }
        }
        return $out;
    }

    /** "[Sold by deleted agent Ram Kumar SHG-0027 #35]" — the office note on a ticket that stays. */
    private static function soldByTag(array $h): string
    {
        $aid = (int) $h['admin_id'];
        $name = (string) ($h['name'] !== '' ? $h['name'] : ($h['admin']['username'] ?? ''));
        $code = $aid > 0 ? AgentWallet::agentCodeLabel($aid) : '';
        $who = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\[\]]/u', '', $name . ' ' . $code)));
        return '[Sold by deleted agent ' . ($who !== '' ? $who . ' ' : '') . '#' . $aid . ']';
    }

    /**
     * Their bookings, sorted for the two ticket modes.
     *   upcoming  a leg today or later and not cancelled / rejected / expired: always kept
     *   shared    another (existing) member is also on it, or another member's ledger / paper
     *             ticket points at it: kept, so nobody else's sale or money is deleted
     *   del       what "delete old tickets too" deletes
     *
     * @return array{all:list<int>, del:list<int>, upcoming:list<int>, shared:list<int>, dead:int, past:int, value:float}
     */
    private static function bookingsOf(int $aid, bool $lock): array
    {
        $out = ['all' => [], 'del' => [], 'upcoming' => [], 'shared' => [], 'dead' => 0, 'past' => 0, 'value' => 0.0];
        if ($aid <= 0 || !self::has('bookings', 'id', 'status')) {
            return $out;
        }
        $cols = array_values(array_filter(['sold_by_admin_id', 'sub_agent_admin_id', 'parent_agent_id'], static fn(string $c): bool => self::has('bookings', $c)));
        if ($cols === []) {
            return $out;
        }
        $w = [];
        $p = [];
        foreach ($cols as $i => $c) {
            $w[] = "b.$c = :b$i";
            $p['b' . $i] = $aid;
        }
        $where = implode(' OR ', $w);
        if ($lock) {
            Database::fetchAll("SELECT b.id FROM bookings b WHERE $where FOR UPDATE", $p);
        }
        $legs = self::has('booking_legs', 'booking_id', 'travel_date');
        $sel = 'b.id, b.status, ' . (self::has('bookings', 'total_amount') ? 'b.total_amount' : '0 AS total_amount') . ', b.' . implode(', b.', $cols)
             . ($legs ? ', (SELECT MAX(l.travel_date) >= CURDATE() FROM booking_legs l WHERE l.booking_id = b.id) AS upcoming' : ', NULL AS upcoming');
        $cand = [];
        $others = [];
        foreach (Database::fetchAll("SELECT $sel FROM bookings b WHERE $where ORDER BY b.id", $p) as $r) {
            $bid = (int) $r['id'];
            $out['all'][] = $bid;
            $st = (string) $r['status'];
            if (in_array($st, ['confirmed', 'completed'], true)) {
                $out['value'] += (float) $r['total_amount'];
            }
            $dead = in_array($st, self::DEAD_STATUSES, true);
            // Without a legs table the trip date is unknown: a live booking is then never deleted.
            $up = $legs ? (int) ($r['upcoming'] ?? 0) === 1 : true;
            if ($dead) {
                $out['dead']++;
            } elseif ($up) {
                $out['upcoming'][] = $bid;
                continue;
            } else {
                $out['past']++;
            }
            $cand[] = $bid;
            foreach ($cols as $c) {
                $v = (int) ($r[$c] ?? 0);
                if ($v > 0 && $v !== $aid) {
                    $others[$bid][] = $v;
                }
            }
        }
        $shared = [];
        if ($others !== []) {
            $pp = [];
            $live = [];
            foreach (Database::fetchAll('SELECT id FROM admins WHERE id ' . self::in(array_merge(...array_values($others)), $pp, 'o'), $pp) as $r) {
                $live[(int) $r['id']] = true;
            }
            foreach ($others as $bid => $list) {
                foreach ($list as $o) {
                    if (isset($live[$o])) { $shared[$bid] = true; }
                }
            }
        }
        if ($cand !== []) {
            foreach ([['agent_ledger', 'agent_admin_id'], ['offline_tickets', 'agent_admin_id'], ['offline_requests', 'admin_id']] as [$t, $c]) {
                if (self::has($t, 'booking_id', $c)) {
                    $pp = ['a' => $aid];
                    foreach (Database::fetchAll("SELECT DISTINCT booking_id FROM $t WHERE $c <> :a AND booking_id " . self::in($cand, $pp, 'k'), $pp) as $r) {
                        $shared[(int) $r['booking_id']] = true;
                    }
                }
            }
        }
        foreach ($cand as $bid) {
            if (isset($shared[$bid])) {
                $out['shared'][] = $bid;
            } else {
                $out['del'][] = $bid;
            }
        }
        return $out;
    }

    /**
     * Every write of a delete WITH history, in order (children before parents), and what it found.
     * op = ['op' => 'delete'|'update', 't' => table, 'w' => where, 'p' => params, 'set' => SQL, 'sp' => set params, 'k' => phase]
     *
     * @return array{ops:list<array>, books:array, tag:string, money:list<array{label:string,count:int}>, actor_rows:int,
     *   actor_left:list<string>, cascade:list<string>, blockers:list<string>, unknown:list<string>}
     */
    private static function forcePlan(array $h, string $tickets, bool $lock): array
    {
        self::has('bookings');
        $aid = (int) $h['admin_id'];
        $out = ['ops' => [], 'books' => self::bookingsOf($aid, $lock), 'tag' => '', 'money' => [], 'actor_rows' => 0,
                'actor_left' => [], 'cascade' => [], 'blockers' => [], 'unknown' => []];
        $ops = &$out['ops'];
        $handled = array_fill_keys(self::PLAN_PAIRS, true);
        $fkOf = [];
        foreach (self::fks() as $fk) {
            $fkOf[$fk['child'] . '.' . $fk['col']] = $fk;
        }

        if ($aid > 0) {
            $a = ['a' => $aid];

            // 1. Their own account rows (both modes); child rows without a foreign key first.
            if (self::has('counter_shift_expenses', 'shift_id') && self::has('counter_shifts', 'id', 'admin_id')) {
                $ids = array_column(Database::fetchAll('SELECT id FROM counter_shifts WHERE admin_id = :a', $a), 'id');
                $p = [];
                if ($ids !== []) { self::addDelete('counter_shift_expenses', 'shift_id ' . self::in($ids, $p, 'k'), $p, 'own', $ops); }
            }
            if (self::has('staff_threads', 'id', 'agent_admin_id')) {
                $ids = array_column(Database::fetchAll('SELECT id FROM staff_threads WHERE agent_admin_id = :a', $a), 'id');
                foreach (['staff_thread_reads', 'staff_messages'] as $t) {
                    $p = [];
                    if ($ids !== [] && self::has($t, 'thread_id')) { self::addDelete($t, 'thread_id ' . self::in($ids, $p, 'k'), $p, 'own', $ops); }
                }
            }
            foreach (self::OWN_ROWS as [$t, $c, $label]) {
                $handled["$t.$c"] = true;
                if (!self::has($t, $c)) {
                    continue;
                }
                $cnt = self::count("SELECT COUNT(*) FROM `$t` WHERE `$c` = :a", $a);
                if ($cnt > 0) {
                    $out['money'][] = ['label' => $label, 'count' => $cnt];
                    self::addDelete($t, "`$c` = :a", $a, 'own', $ops);
                }
            }

            // 2. Their tickets.
            $b = $out['books'];
            foreach (['sold_by_admin_id', 'sub_agent_admin_id', 'parent_agent_id'] as $c) {
                $handled["bookings.$c"] = true;
            }
            $del = $tickets === 'delete' ? $b['del'] : [];
            $keep = array_values(array_diff($b['all'], $del));
            if ($del !== []) {
                // Tables with a booking_id but no real foreign key: they would be left pointing at nothing.
                foreach ((array) self::$meta as $t => $cols) {
                    if ($t === 'bookings' || !isset($cols['booking_id']) || isset($fkOf["$t.booking_id"])) {
                        continue;
                    }
                    $p = [];
                    $w = 'booking_id ' . self::in($del, $p, 'k');
                    if (!Database::exists("SELECT 1 FROM `$t` WHERE $w LIMIT 1", $p)) {
                        continue;
                    }
                    if (in_array($t, self::DETACH_ON_BOOKING_DELETE, true) && $cols['booking_id']['null']) {
                        $ops[] = ['op' => 'update', 't' => $t, 'w' => $w, 'p' => $p, 'set' => 'booking_id = NULL', 'sp' => [], 'k' => 'tickets'];
                    } else {
                        self::addDelete($t, $w, $p, 'tickets', $ops);
                    }
                }
                // bookings and everything a real foreign key hangs under it (legs → seats, passengers → documents, payments → screenshots …)
                $p = [];
                self::addDelete('bookings', 'id ' . self::in($del, $p, 'k'), $p, 'tickets', $ops);
            }
            if ($keep !== []) {
                $out['tag'] = self::soldByTag($h);
                $m = self::colMeta('bookings', 'admin_note');
                $note = $m !== null && in_array($m['type'], ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext'], true);
                // The note column has a length: the tag always fits, the old note is cut to make room.
                $max = !$note ? 0 : (in_array($m['type'], ['varchar', 'char'], true) ? max(1, $m['len']) : ($m['type'] === 'tinytext' ? 255 : 4000));
                // The note says what they were on THIS ticket: the seller (owner's wording), else the sub-agent / parent agent.
                $tags = ['sold_by_admin_id' => $out['tag'],
                         'sub_agent_admin_id' => str_replace('[Sold by deleted agent ', '[Sub-agent deleted: ', $out['tag']),
                         'parent_agent_id' => str_replace('[Sold by deleted agent ', '[Parent agent deleted: ', $out['tag'])];
                foreach ($tags as $c => $tag) {
                    if (!self::has('bookings', $c)) {
                        continue;
                    }
                    $p = ['a' => $aid];
                    $w = "$c = :a AND id " . self::in($keep, $p, 'k');
                    if (!Database::exists("SELECT 1 FROM bookings WHERE $w LIMIT 1", $p)) {
                        continue;
                    }
                    if (!(self::colMeta('bookings', $c)['null'] ?? false)) {
                        $out['blockers'][] = "bookings.$c";
                        continue;
                    }
                    $set = "$c = NULL";
                    $sp = [];
                    if ($note) {
                        $tag = mb_substr($tag, 0, $max);
                        $room = max(0, $max - mb_strlen($tag) - 1);
                        // One tag per ticket: a second column naming the same person does not add another.
                        $set = "admin_note = IF(LOCATE(:nid, COALESCE(admin_note, '')) > 0, admin_note, "
                             . "LEFT(CONCAT_WS(' ', NULLIF(LEFT(admin_note, $room), ''), :ntag), $max)), $set";
                        $sp = ['nid' => '#' . $aid . ']', 'ntag' => $tag];
                    }
                    $ops[] = ['op' => 'update', 't' => 'bookings', 'w' => $w, 'p' => $p, 'set' => $set, 'sp' => $sp, 'k' => 'tickets'];
                }
            }

            // 3. Rows that only record them as the one who did it (and links to them as parent agent),
            //    then every other real foreign key to admins.id, so the final DELETE succeeds.
            foreach ((array) self::$meta as $t => $cols) {
                if (in_array($t, self::LEAVE_TABLES, true) || $t === 'admins' || $t === 'bookings') {
                    continue;
                }
                foreach ($cols as $c => $cm) {
                    if (!in_array($cm['type'], ['int', 'bigint', 'smallint', 'mediumint'], true)) {
                        continue;
                    }
                    $pc = "$t.$c";
                    if (isset($handled[$pc])) {
                        continue;
                    }
                    $fk = $fkOf[$pc] ?? null;
                    if ($fk !== null && $fk['parent'] !== 'admins') {
                        continue;   // a link to another register (users, routes …), not to a login
                    }
                    $isActor = in_array($c, self::ACTOR_NAMES, true) || in_array($pc, self::ACTOR_PAIRS, true);
                    if (!$isActor && $fk === null) {
                        if (preg_match('/(_by|admin_id|agent_id|^assigned_to)$/', $c) === 1) {
                            $cnt = self::count("SELECT COUNT(*) FROM `$t` WHERE `$c` = :a", $a);
                            if ($cnt > 0) { $out['unknown'][] = "$pc × $cnt"; }
                        }
                        continue;
                    }
                    $handled[$pc] = true;
                    $cnt = self::count("SELECT COUNT(*) FROM `$t` WHERE `$c` = :a", $a);
                    if ($cnt === 0) {
                        continue;
                    }
                    $rule = $fk['rule'] ?? '';
                    if ($cm['null']) {
                        $ops[] = ['op' => 'update', 't' => $t, 'w' => "`$c` = :a", 'p' => $a, 'set' => "`$c` = NULL", 'sp' => [], 'k' => 'actor'];
                        $out['actor_rows'] += $cnt;
                    } elseif ($rule === 'CASCADE') {
                        // The database itself would delete these with the login: done here so the backup has them.
                        self::addDelete($t, "`$c` = :a", $a, 'actor', $ops);
                        $out['cascade'][] = "$pc × $cnt";
                    } elseif ($fk !== null) {
                        $out['blockers'][] = "$pc × $cnt";
                    } else {
                        $out['actor_left'][] = "$pc × $cnt";
                    }
                }
            }
        }

        // 4. The person's profile, cards, sign-ins, applications … (the normal purge plan), the login last.
        $plan = self::plan($h);
        foreach ($plan['tables'] as [$t, $w, $p]) {
            if ($aid > 0 && in_array($t, ['staff_threads', 'ap_deposit_requests'], true)) {
                continue;   // every one of them already went with the money rows
            }
            if ($t === 'admins') {
                $ops[] = ['op' => 'delete', 't' => $t, 'w' => $w, 'p' => $p, 'set' => '', 'sp' => [], 'k' => 'plan'];
                continue;
            }
            self::addDelete($t, $w, $p, 'plan', $ops);
        }
        foreach ($plan['updates'] as [$sql, $p]) {
            if (preg_match('/^UPDATE\s+(\w+)\s+SET\s+(.+?)\s+WHERE\s+(.+)$/s', $sql, $m) !== 1) {
                throw new TeamPurgeError('Internal: an update could not be planned — nothing was deleted.');
            }
            $ops[] = ['op' => 'update', 't' => $m[1], 'w' => $m[3], 'p' => $p, 'set' => $m[2], 'sp' => [], 'k' => 'plan'];
        }
        unset($ops);
        return $out;
    }

    /** A DELETE, preceded by the rows real foreign keys hang under it (CASCADE / RESTRICT → deleted, SET NULL → unlinked). */
    private static function addDelete(string $t, string $w, array $p, string $phase, array &$ops, int $depth = 0): void
    {
        if ($depth > 8) {
            throw new TeamPurgeError('Too many linked tables under ' . $t . ' — nothing was deleted.');
        }
        foreach (self::fks() as $fk) {
            if ($fk['parent'] !== $t || $fk['child'] === $t || !self::has($fk['child'], $fk['col'])) {
                continue;
            }
            $vals = array_column(Database::fetchAll("SELECT DISTINCT `{$fk['pcol']}` AS v FROM `$t` WHERE $w", $p), 'v');
            $cp = [];
            $in = self::inAny($vals, $cp, 'v');
            if ($in === '') {
                continue;
            }
            $cw = "`{$fk['col']}` $in";
            if (!Database::exists("SELECT 1 FROM `{$fk['child']}` WHERE $cw LIMIT 1", $cp)) {
                continue;
            }
            if ($fk['rule'] === 'SET NULL' && (self::colMeta($fk['child'], $fk['col'])['null'] ?? false)) {
                $ops[] = ['op' => 'update', 't' => $fk['child'], 'w' => $cw, 'p' => $cp, 'set' => "`{$fk['col']}` = NULL", 'sp' => [], 'k' => $phase];
            } else {
                self::addDelete($fk['child'], $cw, $cp, $phase, $ops, $depth + 1);
            }
        }
        $ops[] = ['op' => 'delete', 't' => $t, 'w' => $w, 'p' => $p, 'set' => '', 'sp' => [], 'k' => $phase];
    }

    // ------------------------------------------------------------------
    //  The customers on deleted tickets stay as data (31 Oct 2026, owner:
    //  "sab delete — customer name number for data").
    // ------------------------------------------------------------------

    /**
     * Save the customers of these bookings into user_profiles (the customer register) before the
     * bookings go. Placeholder numbers (0000000000 …) and our own people's numbers are skipped.
     *
     * @param list<int> $bookingIds
     * @param string    $why  e.g. "agent Ram Kumar SHG-0027" (goes into the profile note)
     * @return array{customers_kept:int, customers_new:int, skipped:int, list:list<array<string,mixed>>}
     */
    public static function keepCustomers(array $bookingIds, string $why): array
    {
        return self::writeCustomers(self::customersOf($bookingIds), $why);
    }

    /**
     * The customers of these bookings, one entry per normalised phone, read before any booking is deleted.
     *
     * @param list<int> $bookingIds
     * @return array{list:list<array<string,mixed>>, skipped:int}
     */
    private static function customersOf(array $bookingIds): array
    {
        $out = ['list' => [], 'skipped' => 0];
        if ($bookingIds === [] || !self::has('bookings', 'id', 'contact_phone')) {
            return $out;
        }
        require_once __DIR__ . '/customerbook.php';
        $opt = static fn(string $c): string => self::has('bookings', $c) ? "b.$c" : "NULL AS $c";
        $p = [];
        $w = self::in($bookingIds, $p, 'k');
        $books = Database::fetchAll('SELECT b.id, b.contact_phone, b.status, ' . implode(', ', array_map($opt,
            ['contact_country_code', 'alt_phone', 'contact_email', 'total_amount', 'created_at'])) . " FROM bookings b WHERE b.id $w ORDER BY b.id", $p);
        $pax = [];
        if (self::has('booking_passengers', 'booking_id', 'full_name')) {
            $order = self::has('booking_passengers', 'is_primary') ? 'is_primary DESC, id' : 'id';
            $p = [];
            foreach (Database::fetchAll("SELECT booking_id, full_name FROM booking_passengers WHERE booking_id " . self::in($bookingIds, $p, 'k') . " ORDER BY booking_id, $order", $p) as $r) {
                $n = trim((string) $r['full_name']);
                if ($n !== '') { $pax[(int) $r['booking_id']][] = $n; }
            }
        }
        $legs = [];
        if (self::has('booking_legs', 'booking_id', 'travel_date')) {
            $route = self::has('booking_legs', 'schedule_id') && self::has('schedules', 'id', 'route_id');
            $p = [];
            $sql = $route
                ? 'SELECT l.booking_id, l.travel_date, s.route_id FROM booking_legs l LEFT JOIN schedules s ON s.id = l.schedule_id WHERE l.booking_id '
                : 'SELECT l.booking_id, l.travel_date, NULL AS route_id FROM booking_legs l WHERE l.booking_id ';
            foreach (Database::fetchAll($sql . self::in($bookingIds, $p, 'k') . ' ORDER BY l.travel_date', $p) as $r) {
                $legs[(int) $r['booking_id']] = ['date' => (string) $r['travel_date'], 'route' => $r['route_id'] !== null ? (int) $r['route_id'] : null];
            }
        }
        $staff = CustomerBook::staffPhones();
        $by = [];
        foreach ($books as $bk) {
            $phone = normalisePhone((string) $bk['contact_phone']);
            if ($phone === '' || GemVault::isPlaceholder($phone) || isset($staff[$phone])) {
                $out['skipped']++;
                continue;   // a walk-in placeholder or the seller's own number is nobody's travel history
            }
            $bid = (int) $bk['id'];
            $c = $by[$phone] ?? ['phone' => $phone, 'country_code' => '', 'name' => '', 'others' => [], 'bookings' => 0, 'trips' => 0,
                                 'value' => 0.0, 'last_travel' => '', 'last_route' => null, 'first_seen' => '', 'last_booked' => '',
                                 'alt_phone' => '', 'email' => '', 'booking_ids' => []];
            $c['bookings']++;
            $c['booking_ids'][] = $bid;
            if (in_array((string) $bk['status'], ['confirmed', 'completed'], true)) {
                $c['trips']++;
                $c['value'] += (float) ($bk['total_amount'] ?? 0);
            }
            $cc = preg_replace('/\D/', '', (string) ($bk['contact_country_code'] ?? '')) ?? '';
            if ($c['country_code'] === '' && ($cc === '91' || $cc === '977')) {
                $c['country_code'] = $cc;   // a captured value only, never guessed from the digits
            }
            $names = $pax[$bid] ?? [];
            $created = (string) ($bk['created_at'] ?? '');
            // The newest booking's main passenger is the name; everyone else is listed.
            if ($names !== [] && ($c['name'] === '' || $created >= $c['last_booked'])) {
                if ($c['name'] !== '') { $c['others'][] = $c['name']; }
                $c['name'] = $names[0];
                array_shift($names);
            }
            foreach ($names as $n) { $c['others'][] = $n; }
            if ($created !== '' && ($c['first_seen'] === '' || $created < $c['first_seen'])) { $c['first_seen'] = $created; }
            if ($created !== '' && $created > $c['last_booked']) { $c['last_booked'] = $created; }
            $leg = $legs[$bid] ?? null;
            if ($leg !== null && $leg['date'] > $c['last_travel']) {
                $c['last_travel'] = $leg['date'];
                $c['last_route'] = $leg['route'];
            }
            $alt = normalisePhone((string) ($bk['alt_phone'] ?? ''));
            if ($c['alt_phone'] === '' && $alt !== '' && $alt !== $phone && !GemVault::isPlaceholder($alt)) { $c['alt_phone'] = $alt; }
            $mail = trim((string) ($bk['contact_email'] ?? ''));
            if ($c['email'] === '' && $mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) !== false) { $c['email'] = mb_substr($mail, 0, 191); }
            $by[$phone] = $c;
        }
        foreach ($by as $phone => $c) {
            $c['others'] = array_values(array_diff(array_unique($c['others']), [$c['name']]));
            $c['value'] = round($c['value'], 2);
            $out['list'][] = $c;
        }
        return $out;
    }

    /**
     * Upsert the customers into user_profiles: a new number gets a row; an existing row only has its
     * empty columns filled, its trip count / value added to, and a note — a name already there (or
     * locked by the office) is never overwritten.
     *
     * @param array{list:list<array<string,mixed>>, skipped:int} $cust
     * @return array{customers_kept:int, customers_new:int, skipped:int, list:list<array<string,mixed>>}
     */
    private static function writeCustomers(array $cust, string $why): array
    {
        $res = ['customers_kept' => 0, 'customers_new' => 0, 'skipped' => (int) $cust['skipped'], 'list' => $cust['list']];
        if ($cust['list'] === [] || !self::has('user_profiles', 'phone', 'full_name')) {
            return $res;
        }
        $col = static fn(string $c): bool => self::has('user_profiles', $c);
        $now = date('Y-m-d H:i:s');
        $why = trim((string) preg_replace('/\s+/u', ' ', $why));
        foreach ($res['list'] as $i => $c) {
            $note = date('j M Y') . ': kept from deleted tickets of ' . $why . ' (' . $c['trips'] . ' trip' . ($c['trips'] === 1 ? '' : 's')
                . ($c['bookings'] !== $c['trips'] ? ', ' . $c['bookings'] . ' bookings' : '') . ')';
            $name = mb_strlen(Security::clean((string) $c['name'], 120)) >= 2 ? Security::clean((string) $c['name'], 120) : '';
            $ex = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p FOR UPDATE', ['p' => $c['phone']]);
            if ($ex === null) {
                $row = ['phone' => $c['phone'], 'full_name' => $name !== '' ? $name : null,
                        'country_code' => $c['country_code'] !== '' ? $c['country_code'] : null];
                $opt = ['travel_count' => $c['trips'], 'lifetime_value' => $c['value'],
                        'last_travel_date' => $c['last_travel'] !== '' ? $c['last_travel'] : null, 'last_route_id' => $c['last_route'],
                        'first_seen_at' => $c['first_seen'] !== '' ? $c['first_seen'] : $now, 'last_seen_at' => $c['last_booked'] !== '' ? $c['last_booked'] : $now,
                        'last_booked_at' => $c['last_booked'] !== '' ? $c['last_booked'] : null,
                        'alt_phone' => $c['alt_phone'] !== '' ? $c['alt_phone'] : null, 'email' => $c['email'] !== '' ? $c['email'] : null,
                        'channels' => 'agent', 'notes' => $note];
                foreach ($opt as $k => $v) {
                    if ($col($k)) { $row[$k] = $v; }
                }
                Database::insert('user_profiles', $row);
                $res['list'][$i]['status'] = 'new';
                $res['customers_new']++;
                continue;
            }
            $up = [];
            $locked = !empty($ex['name_locked']);
            if (!$locked && trim((string) ($ex['full_name'] ?? '')) === '' && $name !== '') { $up['full_name'] = $name; }
            foreach (['country_code' => $c['country_code'], 'alt_phone' => $c['alt_phone'], 'email' => $c['email']] as $k => $v) {
                if ($v !== '' && $col($k) && trim((string) ($ex[$k] ?? '')) === '' && !($k === 'alt_phone' && $v === $c['phone'])) { $up[$k] = $v; }
            }
            if ($col('travel_count') && $c['trips'] > 0) { $up['travel_count'] = (int) ($ex['travel_count'] ?? 0) + $c['trips']; }
            if ($col('lifetime_value') && $c['value'] > 0) { $up['lifetime_value'] = round((float) ($ex['lifetime_value'] ?? 0) + $c['value'], 2); }
            if ($col('last_travel_date') && $c['last_travel'] !== '' && $c['last_travel'] > (string) ($ex['last_travel_date'] ?? '')) {
                $up['last_travel_date'] = $c['last_travel'];
            }
            if ($col('last_booked_at') && $c['last_booked'] !== '' && $c['last_booked'] > (string) ($ex['last_booked_at'] ?? '')) {
                $up['last_booked_at'] = $c['last_booked'];
            }
            if ($col('first_seen_at') && $c['first_seen'] !== '' && ((string) ($ex['first_seen_at'] ?? '') === '' || $c['first_seen'] < (string) $ex['first_seen_at'])) {
                $up['first_seen_at'] = $c['first_seen'];
            }
            if ($col('channels')) {
                $seen = array_values(array_filter(explode(',', (string) ($ex['channels'] ?? ''))));
                if (!in_array('agent', $seen, true)) { $seen[] = 'agent'; $up['channels'] = substr(implode(',', $seen), 0, 120); }
            }
            if ($col('notes')) {
                $old = trim((string) ($ex['notes'] ?? ''));
                $up['notes'] = $old !== '' ? $old . "\n" . $note : $note;
            }
            if ($up !== []) {
                Database::update('user_profiles', $up, 'phone = :p', ['p' => $c['phone']]);
            }
            $res['list'][$i]['status'] = 'existing';
            $res['customers_kept']++;
        }
        return $res;
    }

    /** customers-kept-<snapshot name>.csv next to the snapshot (phones: 0640, outside the web root's reach like the snapshot). */
    private static function writeCustomersCsv(string $backup, array $list): string
    {
        if ($list === []) {
            return '';
        }
        $file = dirname($backup) . '/customers-kept-' . preg_replace('/\.json\.gz$/', '', basename($backup)) . '.csv';
        $fh = @fopen($file, 'wb');
        if ($fh === false) {
            throw new TeamPurgeError('The customer list could not be written — nothing was deleted.');
        }
        // A cell that starts with = + - @ would run as a formula in Excel.
        $cell = static fn($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'" . $v : (string) $v;
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['phone', 'country_code', 'name', 'other_passengers', 'bookings', 'trips_confirmed', 'value', 'last_travel', 'email', 'alt_phone', 'profile'], ',', '"', '');
        foreach ($list as $c) {
            fputcsv($fh, array_map($cell, [$c['phone'], $c['country_code'], $c['name'], implode('; ', $c['others']), $c['bookings'], $c['trips'],
                number_format((float) $c['value'], 2, '.', ''), $c['last_travel'], $c['email'], $c['alt_phone'], $c['status'] ?? '']), ',', '"', '');
        }
        fclose($fh);
        @chmod($file, 0640);
        return $file;
    }

    /** An uploads/ path that resolves to a file inside UPLOAD_PATH, or null. */
    private static function insideUploads(string $rel): ?string
    {
        $up = realpath(UPLOAD_PATH);
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($up === false || $rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
            return null;
        }
        $abs = realpath(UPLOAD_PATH . '/' . $rel);
        return $abs !== false && is_file($abs) && str_starts_with($abs, $up . DIRECTORY_SEPARATOR) ? $abs : null;
    }

    /**
     * Files of deleted bookings: the ticket PDF / PNG / invoice, payment screenshots, passenger
     * documents and chat attachments — unless a row that stays names the same file.
     *
     * @param array<string, list<array<string,mixed>>> $rows the snapshot rows (deleted)
     * @return array<string, string>
     */
    private static function bookingFiles(array $rows): array
    {
        $out = [];
        $dirs = ['pdf_path' => defined('TICKET_PATH') ? TICKET_PATH : '', 'png_path' => defined('TICKET_PATH') ? TICKET_PATH : '',
                 'invoice_path' => defined('INVOICE_PATH') ? INVOICE_PATH : ''];
        foreach ($rows['tickets'] ?? [] as $r) {
            foreach ($dirs as $c => $dir) {
                $name = basename(str_replace('\\', '/', (string) ($r[$c] ?? '')));
                $base = $dir !== '' ? realpath($dir) : false;
                if ($name === '' || $name === '.' || $base === false) {
                    continue;
                }
                $abs = realpath($base . '/' . $name);
                if ($abs !== false && is_file($abs) && str_starts_with($abs, $base . DIRECTORY_SEPARATOR)) {
                    $out[($c === 'invoice_path' ? 'invoice/' : 'tickets/') . $name] = $abs;
                }
            }
        }
        foreach ([['payment_screenshots', ['file_path', 'thumb_path']], ['passenger_documents', ['file_path']], ['staff_messages', ['attach_path']]] as [$t, $cs]) {
            $gone = array_map('intval', array_column($rows[$t] ?? [], 'id'));
            foreach ($rows[$t] ?? [] as $r) {
                foreach ($cs as $c) {
                    $rel = ltrim(str_replace('\\', '/', (string) ($r[$c] ?? '')), '/');
                    if ($rel === '' || isset($out[$rel]) || ($abs = self::insideUploads($rel)) === null) {
                        continue;
                    }
                    $p = ['f' => $rel];
                    $not = $gone !== [] ? ' AND id NOT ' . self::in($gone, $p, 'x') : '';
                    if (self::has($t, $c, 'id') && Database::exists("SELECT 1 FROM `$t` WHERE `$c` = :f$not LIMIT 1", $p)) {
                        continue;
                    }
                    $out[$rel] = $abs;
                }
            }
        }
        return $out;
    }

    /** Never put a secret into a backup file (encrypted *_enc columns stay encrypted). */
    private static function scrub(array $row): array
    {
        foreach ($row as $k => $v) {
            if (preg_match('/password|pin_hash|secret|token|totp|recovery|public_key|credential/i', (string) $k) === 1 && $v !== null) {
                $row[$k] = '[removed]';
            }
        }
        return $row;
    }

    /**
     * Every DELETE (child tables first) and the few UPDATEs a purge runs.
     *
     * @return array{tables:list<array{0:string,1:string,2:array}>, updates:list<array{0:string,1:array}>}
     */
    private static function plan(array $h): array
    {
        $t = [];
        $u = [];
        $aid = (int) $h['admin_id'];
        $add = static function (string $table, string $where, array $params, string ...$cols) use (&$t): void {
            if (self::has($table, ...$cols)) {
                $t[] = [$table, $where, $params];
            }
        };

        // AgentPro layer: WhatsApp conversation state of this member's linked numbers, the links,
        // passkeys, unconfirmed payments-in (confirmed ones block the purge), the card record.
        if ($aid > 0) {
            $a = ['a' => $aid];
            if (self::has('ap_wa_state', 'phone') && self::has('ap_wa_links', 'phone', 'admin_id')) {
                $t[] = ['ap_wa_state', 'phone IN (SELECT phone FROM ap_wa_links WHERE admin_id = :a)', $a];
            }
            $add('ap_wa_links', 'admin_id = :a', $a, 'admin_id');
            $add('ap_passkeys', 'admin_id = :a', $a, 'admin_id');
            $add('ap_deposit_requests', "admin_id = :a AND status <> 'confirmed'", $a, 'admin_id', 'status');
            $add('ap_members', 'admin_id = :a', $a, 'admin_id');
        }
        if ($h['ap_ids'] !== []) {
            if (self::has('ap_invites', 'application_id')) {
                $p = [];
                $u[] = ['UPDATE ap_invites SET application_id = NULL WHERE application_id ' . self::in($h['ap_ids'], $p, 'iv'), $p];
            }
            $p = [];
            $add('ap_applications', 'id ' . self::in($h['ap_ids'], $p, 'ap'), $p, 'id');
        }

        // Older onboarding layer, keyed by person key / ref.
        if ($h['keys'] !== []) {
            foreach (['team_drafts', 'team_reviews'] as $table) {
                $p = [];
                $w = self::inStr($h['keys'], $p, 'k');
                $add($table, "person_key $w", $p, 'person_key');
            }
        }
        if ($h['refs'] !== []) {
            if (self::has('team_card_events', 'provider_ref') && self::has('team_card_jobs', 'ref', 'provider_ref')) {
                $p = [];
                $w = self::inStr($h['refs'], $p, 'r');
                $t[] = ['team_card_events', "provider_ref IN (SELECT provider_ref FROM team_card_jobs WHERE ref $w AND provider_ref IS NOT NULL)", $p];
            }
            $p = [];
            $w = self::inStr($h['refs'], $p, 'r');
            $add('team_card_jobs', "ref $w", $p, 'ref');
        }
        if (self::has('agreement_signatures', 'party_type', 'party_id')) {
            $or = [];
            $p = [];
            if ($h['agent_ids'] !== []) { $or[] = "(party_type = 'agent' AND party_id " . self::in($h['agent_ids'], $p, 'ga') . ')'; }
            if ($h['staff_ids'] !== []) { $or[] = "(party_type = 'staff' AND party_id " . self::in($h['staff_ids'], $p, 'gs') . ')'; }
            if ($h['refs'] !== [] && self::has('agreement_signatures', 'party_ref')) { $or[] = 'party_ref ' . self::inStr($h['refs'], $p, 'gr'); }
            if ($or !== []) {
                $t[] = ['agreement_signatures', implode(' OR ', $or), $p];
            }
        }
        if (self::has('team_passkeys', 'owner_kind', 'owner_id')) {
            $or = [];
            $p = [];
            if ($aid > 0) { $or[] = "(owner_kind = 'admin' AND owner_id = :pa)"; $p['pa'] = $aid; }
            if ($h['staff_ids'] !== []) { $or[] = "(owner_kind = 'staff' AND owner_id " . self::in($h['staff_ids'], $p, 'ps') . ')'; }
            if ($or !== []) {
                $t[] = ['team_passkeys', implode(' OR ', $or), $p];
            }
        }
        // team_identity: the code stays in team_code_history. If that table has a foreign key to
        // team_identity, the identity row is detached (renamed) instead of deleted.
        if ($h['keys'] !== [] && self::has('team_identity', 'person_key')) {
            $p = [];
            $w = self::inStr($h['keys'], $p, 'k');
            if (self::codeHistoryFk()) {
                $set = "person_key = CONCAT('purged:', person_key, ':', UNIX_TIMESTAMP())";
                foreach (['consent_note', 'route'] as $c) {
                    if (self::has('team_identity', $c)) { $set .= ", $c = NULL"; }
                }
                $u[] = ["UPDATE team_identity SET $set WHERE person_key $w", $p];
            } else {
                $add('team_identity', "person_key $w", $p, 'person_key');
            }
        }

        // The older applications themselves.
        if ($h['agent_ids'] !== []) {
            $p = [];
            $add('agent_applications', 'id ' . self::in($h['agent_ids'], $p, 'ai'), $p, 'id');
        }
        if ($h['staff_ids'] !== []) {
            $p = [];
            $add('staff_applications', 'id ' . self::in($h['staff_ids'], $p, 'si'), $p, 'id');
        }

        // The login and everything that is only about it.
        if ($aid > 0) {
            $a = ['a' => $aid];
            foreach ([['admin_sessions', 'admin_id'], ['agent_route_permissions', 'admin_id'], ['agent_targets', 'agent_admin_id'],
                      ['agent_lessons', 'admin_id'], ['admin_devices', 'admin_id'], ['admin_login_events', 'admin_id'],
                      ['wa_agent_presence', 'admin_id'], ['staff_presence', 'admin_id'], ['staff_thread_reads', 'admin_id']] as [$table, $c]) {
                $add($table, "$c = :a", $a, $c);
            }
            if (self::has('staff_threads', 'agent_admin_id', 'msg_count')) {
                $t[] = ['staff_threads', 'agent_admin_id = :a AND msg_count = 0', $a];   // empty threads only (history() blocks the rest)
            }
            $add('admin_profiles', 'admin_id = :a', $a, 'admin_id');
            $t[] = ['admins', "id = :a AND role <> 'superadmin'", $a];
        }

        return ['tables' => $t, 'updates' => $u];
    }

    private static function codeHistoryFk(): bool
    {
        try {
            return Database::exists(
                "SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'team_code_history' AND REFERENCED_TABLE_NAME = 'team_identity' LIMIT 1"
            );
        } catch (Throwable $e) {
            return true;   // unknown = take the safe path (detach, never delete)
        }
    }

    /**
     * The files that belong to this person: absolute path keyed by a relative label.
     * Only paths that resolve inside UPLOAD_PATH (or the private team store) are returned.
     *
     * @param array<string, list<array<string,mixed>>> $rows the snapshot rows
     * @return array<string, string>
     */
    private static function files(array $h, array $rows): array
    {
        $out = [];
        $up = realpath(UPLOAD_PATH);
        $inside = static function (string $rel) use ($up): ?string {
            if ($up === false || $rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
                return null;
            }
            $abs = realpath(UPLOAD_PATH . '/' . ltrim($rel, '/'));
            return $abs !== false && is_file($abs) && str_starts_with($abs, $up . DIRECTORY_SEPARATOR) ? $abs : null;
        };

        // AgentPro: every file of the join form(s) and the payment screenshots (all under agents-kyc/).
        $apFile = static function (string $rel) use (&$out): void {
            $abs = AgentPro::privateAbs($rel);
            if ($abs !== null) {
                $out[$rel] = $abs;
            }
        };
        foreach ($rows['ap_applications'] ?? [] as $r) {
            foreach (['doc_front', 'doc_back', 'selfie', 'thumb'] as $c) {
                $apFile((string) ($r[$c] ?? ''));
            }
            foreach (jsonColumn($r['extras'] ?? null) as $x) {
                $apFile((string) (is_array($x) ? ($x['path'] ?? '') : ''));
            }
        }
        foreach ($rows['ap_deposit_requests'] ?? [] as $r) {
            $apFile((string) ($r['proof_path'] ?? ''));
        }

        // Agent signature files from the team portal live under staff/<agent app id>/ (StaffApps::saveFile),
        // so a staff folder is emptied except for those, and the agent's own are taken one by one.
        $keepAgentSig = [];
        $sigOf = static fn(array $r): string => (string) ($r['signature_path'] ?? '');
        foreach ($rows['agreement_signatures'] ?? [] as $r) {
            if ((string) ($r['party_type'] ?? '') === 'agent' && ($abs = $inside($sigOf($r))) !== null) {
                $out[$sigOf($r)] = $abs;
            }
        }
        if (self::has('agreement_signatures', 'party_type', 'party_id', 'signature_path') && $h['staff_ids'] !== []) {
            $p = [];
            $w = self::in($h['staff_ids'], $p, 'k');
            foreach (Database::fetchAll("SELECT signature_path FROM agreement_signatures WHERE party_type = 'agent' AND party_id $w", $p) as $r) {
                if (($abs = $inside((string) $r['signature_path'])) !== null) {
                    $keepAgentSig[$abs] = true;
                }
            }
        }
        foreach ($h['staff_ids'] as $sid) {
            $dir = UPLOAD_PATH . '/staff/' . (int) $sid;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $f) {
                $abs = realpath($f);
                if ($abs !== false && is_file($abs) && !isset($keepAgentSig[$abs]) && str_starts_with($abs, (string) $up . DIRECTORY_SEPARATOR)) {
                    $out['staff/' . (int) $sid . '/' . basename($abs)] = $abs;
                }
            }
        }
        foreach ($rows['admin_profiles'] ?? [] as $r) {
            foreach (['photo_path' => 'agents/', 'kyc_doc_path' => 'agents-kyc/', 'kyc_doc2_path' => 'agents-kyc/'] as $c => $prefix) {
                $rel = (string) ($r[$c] ?? '');
                if (str_starts_with($rel, $prefix) && ($abs = $inside($rel)) !== null) {
                    $out[$rel] = $abs;
                }
            }
        }
        $aid = (int) $h['admin_id'];
        if ($aid > 0) {
            $rel = AgentWallet::agreementPath($aid);
            if (str_starts_with($rel, 'agents-kyc/') && ($abs = $inside($rel)) !== null) {
                $out[$rel] = $abs;
            }
        }
        // Encrypted originals in the private team store (team_reviews / team_card_jobs file_key).
        $keys = [];
        foreach (['team_reviews', 'team_card_jobs'] as $table) {
            foreach ($rows[$table] ?? [] as $r) {
                $k = (string) ($r['file_key'] ?? '');
                if (preg_match('/^[a-f0-9]{48}$/', $k) === 1) {
                    $keys[$k] = true;
                }
            }
        }
        if ($keys !== []) {
            $dir = defined('TEAM_PRIVATE_PATH') ? TEAM_PRIVATE_PATH : dirname(ROOT_PATH) . '/private-team';
            foreach (array_keys($keys) as $k) {
                // Still used by a row that stays? Then it stays too.
                $used = false;
                foreach (['team_reviews', 'team_card_jobs'] as $table) {
                    if (!self::has($table, 'file_key')) { continue; }
                    $p = ['k' => $k];
                    $not = '';
                    $ids = array_map('intval', array_column($rows[$table] ?? [], 'id'));
                    if ($ids !== []) { $not = ' AND id NOT ' . self::in($ids, $p, 'x'); }
                    if (Database::exists("SELECT 1 FROM $table WHERE file_key = :k$not LIMIT 1", $p)) { $used = true; break; }
                }
                if (!$used && is_file($dir . '/' . $k)) {
                    $out['private-team/' . $k] = $dir . '/' . $k;
                }
            }
        }
        return $out;
    }

    /**
     * The settings JSON maps keyed by admin id (and the archive map keyed by person key).
     * $write false = return what would be removed (for the snapshot); true = remove it.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function settingsMaps(array $h, bool $write): array
    {
        $out = [];
        $aid = (string) (int) $h['admin_id'];
        $maps = ['agent_types', 'agent_commission_overrides', 'agent_deposits', 'agent_salaries', 'agent_agreements', 'agent_codes'];
        foreach ($maps as $key) {
            if ($aid === '0') { break; }
            $map = Settings::getArray($key, []);
            if (array_key_exists($aid, $map)) {
                $out[$key] = [$aid => $map[$aid]];
                if ($write && $key !== 'agent_codes') {   // agent_codes goes through setAgentCode (audited)
                    unset($map[$aid]);
                    Settings::set($key, $map, 'json', 'agent', false);
                }
            }
        }
        $arch = Settings::getArray(self::ARCHIVE_KEY, []);
        $hit = array_intersect_key($arch, array_flip($h['keys']));
        if ($hit !== []) {
            $out[self::ARCHIVE_KEY] = $hit;
            if ($write) {
                Settings::set(self::ARCHIVE_KEY, array_diff_key($arch, $hit), 'json', 'agent', false);
            }
        }
        return $out;
    }

    /** gzip JSON into BACKUP_PATH/team-purge/; throws (and so aborts the purge) when it cannot be written. */
    private static function writeSnapshot(string $ref, array $snap): string
    {
        $dir = BACKUP_PATH . '/team-purge';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new TeamPurgeError('The backup folder could not be created — nothing was deleted.');
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        $json = json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $gz = is_string($json) ? gzencode($json, 9) : false;
        if (!is_string($gz)) {
            throw new TeamPurgeError('The backup could not be prepared — nothing was deleted.');
        }
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $ref) ?: 'record';
        // The random tail keeps the name unguessable (the snapshot holds KYC data).
        $file = $dir . '/' . date('Y-m-d_His') . '-' . $safe . '-' . bin2hex(random_bytes(8)) . '.json.gz';
        if (@file_put_contents($file, $gz, LOCK_EX) !== strlen($gz)) {
            @unlink($file);
            throw new TeamPurgeError('The backup could not be written — nothing was deleted.');
        }
        @chmod($file, 0640);
        return $file;
    }

    // ------------------------------------------------------------------
    //  The one panel every page shows
    // ------------------------------------------------------------------

    /**
     * The delete check, as HTML: the history (and "archive instead") or the typed-DELETE form.
     *
     * @param array<string,string|int> $hidden extra hidden inputs (action, id …)
     * @param string $archiveHtml a ready button / link to archive this record (shown when there is history)
     */
    public static function panelHtml(array $h, string $formAction, array $hidden, string $archiveHtml = '', bool $isSuper = false, string $cancelHref = ''): string
    {
        $e = static fn($v): string => Security::e((string) $v);
        $o = '<div class="panel" id="purge" style="border:2px solid #fecaca;border-radius:14px;padding:16px;margin:0 0 16px;background:var(--card,#fff);color:var(--ink,#0f172a)">';
        $o .= '<h3 style="margin:0 0 6px;color:#b91c1c;font-size:17px">🗑️ Delete permanently? · स्थायी रूपमा मेटाउने?</h3>';
        $o .= '<p style="margin:0 0 10px;font-size:13.5px"><b>' . $e($h['name'] ?: '—') . '</b> · <span class="ap-mono">' . $e($h['ref']) . '</span>'
            . ($h['admin'] !== null ? ' · login <span class="ap-mono">' . $e($h['admin']['username']) . '</span> (' . $e($h['admin']['role']) . ')' : '') . '</p>';
        if (!$h['found'] || $h['refuse'] !== '') {
            $o .= '<div class="flash bad" style="margin:0">' . $e($h['refuse'] ?: 'Record not found.') . '</div>';
        } elseif ($h['blocks'] !== []) {
            $o .= '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px;font-size:13px;color:#991b1b;line-height:1.55">'
                . '<b>🔒 Cannot be deleted — this person has history · इतिहास छ, मेटिँदैन</b><ul style="margin:6px 0 6px 18px;padding:0">';
            foreach ($h['blocks'] as $b) {
                $o .= '<li>' . (int) $b['count'] . ' × ' . $e($b['label']) . '</li>';
            }
            $o .= '</ul>Money, ticket and payroll records must stay for the accounts. <b>Archive instead</b>: the login is switched off, the card is suspended and leaves the website, and every record is kept (it can be restored later). · Archive गर्नुहोस् — लगइन बन्द, कार्ड रोकिन्छ, हिसाब सुरक्षित रहन्छ।</div>';
            if ($archiveHtml !== '') {
                $o .= '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">' . $archiveHtml . '</div>';
            }
            if ($isSuper) {
                $o .= self::forceFormHtml($h, $formAction, $hidden);
            }
        } else {
            $o .= '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px;font-size:13px;color:#92400e;line-height:1.55">'
                . '<b>✓ No tickets, money or payroll history · कुनै इतिहास छैन</b><br>This removes for good:<ul style="margin:6px 0 6px 18px;padding:0">';
            foreach ($h['removes'] as $r) {
                $o .= '<li>' . ((int) $r['count'] > 1 ? (int) $r['count'] . ' × ' : '') . $e($r['label']) . '</li>';
            }
            $o .= '<li>Photos, ID documents, thumb print and paper-form pages on file · फोटो, कागजात</li></ul>'
                . 'A backup copy is saved on the server first (backup/team-purge/, not reachable from the web).</div>';
            if ($isSuper) {
                $o .= '<form method="post" action="' . $e($formAction) . '" style="margin-top:12px;display:grid;gap:10px;max-width:460px" autocomplete="off">'
                    . Security::csrfField();
                foreach ($hidden as $k => $v) {
                    $o .= '<input type="hidden" name="' . $e($k) . '" value="' . $e($v) . '">';
                }
                $o .= '<label style="display:grid;gap:4px;font-size:13px;font-weight:700">Reason (kept in the audit log) · कारण'
                    . '<input type="text" name="purge_reason" maxlength="300" placeholder="e.g. duplicate / test entry" style="min-height:48px;border:1.5px solid #cbd5e1;border-radius:10px;padding:0 12px;font:inherit;background:var(--card,#fff);color:inherit"></label>'
                    . '<label style="display:grid;gap:4px;font-size:13px;font-weight:700">Type <span style="color:#dc2626">DELETE</span> to confirm · पुष्टि गर्न DELETE लेख्नुहोस्'
                    . '<input type="text" name="confirm_delete" required pattern="DELETE" placeholder="DELETE" autocapitalize="characters" spellcheck="false" style="min-height:48px;border:1.5px solid #fca5a5;border-radius:10px;padding:0 12px;font:inherit;letter-spacing:.08em;background:var(--card,#fff);color:inherit"></label>'
                    . '<button type="submit" class="btn danger" style="min-height:48px;background:#dc2626;color:#fff;border:0;border-radius:10px;font-weight:800">🗑️ Delete permanently · स्थायी रूपमा मेटाउनुहोस्</button>'
                    . '</form>';
            } else {
                $o .= '<p class="ph-sub" style="margin:10px 0 0;font-size:12.5px">Only a super-admin can delete permanently · सुपर-एडमिनले मात्र मेटाउन सक्छ।</p>';
            }
        }
        if ($cancelHref !== '') {
            $o .= '<p style="margin:12px 0 0"><a href="' . $e($cancelHref) . '" class="btn ghost" style="min-height:48px;display:inline-flex;align-items:center">✕ Close · बन्द</a></p>';
        }
        return $o . '</div>';
    }

    /**
     * The red "Delete with all history" form (super-admin, a record with history). Posts
     * action=purge_all, tickets (office|delete), reason, confirm + the page's own hidden fields.
     */
    private static function forceFormHtml(array $h, string $formAction, array $hidden): string
    {
        $e = static fn($v): string => Security::e((string) $v);
        try {
            $f = self::forcePreview((string) $h['kind'], (int) $h['id']);
        } catch (Throwable $ex) {
            return '<div class="flash bad" style="margin-top:12px">' . $e($ex->getMessage()) . '</div>';
        }
        $field = 'min-height:48px;border:1.5px solid #fca5a5;border-radius:10px;padding:0 12px;font:inherit;background:var(--card,#fff);color:inherit';
        $o = '<div id="purgeall" style="margin-top:16px;border:2px solid #dc2626;border-radius:12px;padding:14px;background:#fef2f2;color:#7f1d1d;font-size:13px;line-height:1.55">'
            . '<h4 style="margin:0 0 6px;font-size:15.5px;color:#b91c1c">⚠️ Delete with all history · इतिहास सहित स्थायी रूपमा मेटाउने</h4>'
            . '<p style="margin:0 0 6px">Super-admin only. The login, ID card, money account (ledger, settlements, payouts, deposits) and forms go for good. '
            . 'A full backup copy is saved on the server first (backup/team-purge/, not reachable from the web). · लगइन, कार्ड, खाता सबै मेटिन्छ — पहिले ब्याकअप बस्छ।</p>'
            . '<ul style="margin:6px 0 8px 18px;padding:0">' . self::forceFactsHtml($f) . '</ul>'
            . '<form method="post" action="' . $e($formAction) . '" style="display:grid;gap:10px;max-width:520px" autocomplete="off"'
            . ' onsubmit="return confirm(\'Delete this person and their history for good? A backup is saved first.\')">' . Security::csrfField();
        foreach ($hidden + ['action' => 'purge_all'] as $k => $v) {
            $o .= '<input type="hidden" name="' . $e($k) . '" value="' . $e($k === 'action' ? 'purge_all' : $v) . '">';
        }
        $o .= '<fieldset style="border:0;padding:0;margin:0;display:grid;gap:6px"><legend style="font-weight:800;margin-bottom:4px">Their tickets · टिकट</legend>'
            . '<label style="display:flex;gap:8px;align-items:flex-start;min-height:44px"><input type="radio" name="tickets" value="office" checked style="width:20px;height:20px;margin-top:2px">'
            . '<span><b>Keep tickets — move them to the office · टिकट अफिसमा सार्ने</b><br><small>' . (int) $f['bookings'] . ' tickets stay; the note says who sold them</small></span></label>'
            . '<label style="display:flex;gap:8px;align-items:flex-start;min-height:44px"><input type="radio" name="tickets" value="delete" style="width:20px;height:20px;margin-top:2px">'
            . '<span><b>Delete old tickets too (upcoming trips are always kept) · पुराना टिकट पनि मेटाउने</b><br><small>' . (int) $f['delete']['bookings_deleted']
            . ' deleted, ' . (int) $f['delete']['bookings_detached'] . ' kept'
            . ((int) $f['delete']['customers'] > 0 ? '; ' . (int) $f['delete']['customers'] . ' customers\' name + mobile stay in the customer list' : '') . '</small></span></label></fieldset>'
            . '<label style="display:grid;gap:4px;font-weight:700">Reason (kept in the audit log) · कारण'
            . '<input type="text" name="reason" maxlength="300" placeholder="e.g. old agent, disabled in the 30 Oct reset" style="' . $field . '"></label>'
            . '<label style="display:grid;gap:4px;font-weight:700">Type <span style="color:#dc2626">DELETE ALL</span> to confirm · पुष्टि गर्न DELETE ALL लेख्नुहोस्'
            . '<input type="text" name="confirm" required pattern="DELETE ALL" placeholder="DELETE ALL" autocapitalize="characters" spellcheck="false" style="' . $field . ';letter-spacing:.08em"></label>'
            . '<button type="submit" class="btn danger" style="min-height:48px;background:#b91c1c;color:#fff;border:0;border-radius:10px;font-weight:800"'
            . ($f['blockers'] !== [] ? ' disabled' : '') . '>🗑️ Delete with all history · सबै इतिहास सहित मेटाउनुहोस्</button></form></div>';
        return $o;
    }
}
