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
 * Anyone WITH history is never deleted: archive instead (login off, off the
 * website, money and booking records kept). The SHG agent number IS freed
 * (AgentWallet::setAgentCode(id, null), audited).
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

    /** @var array<string, array<string, true>>|null table => columns, this database */
    private static ?array $cols = null;

    // ------------------------------------------------------------------
    //  Schema helpers (information_schema, cached per request)
    // ------------------------------------------------------------------

    /** Does $table exist with every column in $cols? */
    public static function has(string $table, string ...$cols): bool
    {
        if (self::$cols === null) {
            self::$cols = [];
            try {
                foreach (Database::fetchAll(
                    'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
                ) as $r) {
                    self::$cols[strtolower((string) $r['t'])][strtolower((string) $r['c'])] = true;
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
}
