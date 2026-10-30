<?php
/**
 * =====================================================================
 *  tools/team-reset-20261030.php — one-off team reset the owner approved
 *  on 30 Oct 2026: start the agent / staff register fresh (AgentPro is the
 *  one form system from now on) and keep only the people named on the
 *  command line. CLI only. DRY RUN unless --go.
 *
 *    php tools/team-reset-20261030.php --keep-admin=1,7 --keep-app=none \
 *        --keep-staff=none --keep-ap=12 --backup-dir=/root/backups   # lists only
 *    php tools/team-reset-20261030.php ... --backup-dir=/root/backups --go
 *
 *  --keep-admin  admins.id that are never touched. Only role='agent'
 *                accounts are reset at all: superadmin / manager / counter
 *                / accountant / operations (and every other office role)
 *                are never touched, listed or not.
 *  --keep-app    agent_applications.id to keep (old agent form); every
 *                other row is deleted (its documents are data URIs inside
 *                the row).
 *  --keep-staff  staff_applications.id to keep (old staff form); every
 *                other row is deleted and its uploads/staff/<id>/ folder
 *                leaves the web root (moved into the backup folder after
 *                COMMIT, not erased).
 *  --keep-ap     ap_applications.id to keep (AgentPro join form, join.php);
 *                every other row is deleted - drafts, submitted, approved,
 *                rejected - and the files it names (doc_front, doc_back,
 *                selfie, thumb, extras[].path, all in uploads/agents-kyc/)
 *                leave the web root after COMMIT, unless a row that stays
 *                (an admin profile's KYC copy, a kept application, ...)
 *                still names the same file.
 *  Each --keep-* must be given; "none" (or empty) means keep nobody.
 *  Applications (agent_applications / staff_applications / ap_applications)
 *  whose admin_id is an account that STAYS (kept, archived, or an office
 *  role) are kept automatically, with their signatures, Team ID rows,
 *  staff folder and files: they are the signed agreement / filled form of
 *  a person who is still on the books. --drop-survivor-apps deletes them
 *  too (each one is then named in a WARNING).
 *
 *  An agent account that is NOT kept:
 *    - has history (bookings sold / sub-agent / parent, agent_ledger,
 *      agent_settlements, offline_tickets, agent_payout_requests,
 *      agent_loans, agent_deposit_txns, ap_deposit_requests,
 *      offline_requests, payment_reviews, counter_shifts, payments
 *      verified, tickets scanned, or ANY other row whose foreign key points
 *      at it) -> ARCHIVED: is_active = 0 (sign-in, /me/ and the WhatsApp
 *      desk all check it), public listing / phone off, AgentPro card
 *      suspended, open sessions ended. The account and every money row stay.
 *    - no history -> DELETED with its per-person rows (profile, sessions,
 *      devices, sign-in log, route permissions, targets, lessons, admin +
 *      AgentPro passkeys, presence, chat read marks, ap_members card,
 *      ap_wa_links, WhatsApp logins / identity links, push subscriptions,
 *      ap_wa_state + ap_jobs of their phone) and its keys in the settings
 *      maps agent_codes (frees the SHG number), agent_types,
 *      agent_commission_overrides, agent_deposits, agent_salaries,
 *      agent_agreements and team_manager_archive. Profile photo / KYC /
 *      agreement files move into the backup folder after COMMIT.
 *  Every team_identity, team_drafts, team_reviews, team_card_jobs +
 *  team_card_events, agreement_signatures, staff passkey and uploads/staff/
 *  <id>/ folder that is NOT a kept application's is removed, including the
 *  orphans of applications deleted earlier. When team_code_history has a
 *  foreign key to team_identity, the identity row is detached (person_key ->
 *  'purged:…', like TeamPurge) instead of deleted. ap_invites that nobody
 *  used (or that a deleted application used) go - an invite a kept
 *  application points at (ap_applications.invite_id) stays, used or not;
 *  finished ap_jobs, form doc-read jobs of deleted applications and member
 *  ('wa') jobs of removed people's phones go (a passenger's queued
 *  'wa_customer' reply is left alone, and a phone a customer (users.phone)
 *  also uses keeps its WhatsApp state).
 *  wa_verify is emptied.
 *  team_code_history and team_sequences are NEVER touched: A-n / S-n codes
 *  are not reused. Accounts of a kept person whose parent agent is deleted
 *  are not edited (a WARNING names them). staff_threads, message logs,
 *  audit_logs, users, drivers, payroll and ap_deposit_requests are not
 *  touched either.
 *
 *  Safety
 *    - every table / column is looked up in information_schema first;
 *      missing ones are skipped and listed (live has tables the repo has
 *      no CREATE for, and the test copy lacks some of live's).
 *    - --go refuses without --backup-dir (which must be outside the web
 *      root). Before the first write every row that will be deleted or
 *      updated is written as JSON lines (gzip, 0600) to
 *      <backup-dir>/team-reset-<timestamp>.jsonl.gz and read back.
 *    - all database writes are ONE transaction. The plan is re-read inside
 *      it and the run rolls back if anything changed since the backup, or
 *      if any statement touches a different number of rows than planned.
 *      Busy tables are left out of that check so live traffic cannot stall
 *      it: ap_jobs and wa_verify run on the ids / max id seen at plan time
 *      and may touch FEWER rows (the worker purges too); sign-in / presence
 *      rows (admin_sessions, admin_devices, admin_login_events, presence)
 *      may touch any number (a sign-in during the backup is not backed up).
 *    - a kept application linked to an agent account that is not kept, a
 *      kept id that does not exist, or a foreign key this script does not
 *      know about that would cascade / block / null rows on delete stops
 *      --go and is named.
 *    - files are moved only after COMMIT (a rollback leaves them in place).
 *  Take a full mysqldump as well before --go (see the run notes).
 *
 *  Test on a copy: SHG_RESET_DB=<database> points this tool (only) at
 *  another database on the same server, e.g. af1_reset_test. uploads/ is
 *  still the REAL folder of this install, so with SHG_RESET_DB set no file
 *  is ever moved: the file list is only printed.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/includes/bootstrap.php';

// Plain text + exit 1 on any uncaught error (bootstrap's handler prints HTML and exits 0).
set_exception_handler(static function (Throwable $e): void {
    Logger::exception($e);
    fwrite(STDERR, 'ERROR: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    exit(1);
});

final class TeamReset20261030
{
    /** Only this admins.role is ever reset. */
    private const RESET_ROLE = 'agent';

    /** No-FK columns that make an agent account "has history" (owner's list + money tables). */
    private const HISTORY = [
        ['bookings', 'sold_by_admin_id'], ['bookings', 'sub_agent_admin_id'], ['bookings', 'parent_agent_id'],
        ['agent_ledger', 'agent_admin_id'], ['agent_settlements', 'agent_admin_id'],
        ['offline_tickets', 'agent_admin_id'], ['agent_payout_requests', 'agent_admin_id'],
        ['agent_loans', 'agent_admin_id'], ['agent_deposit_txns', 'agent_admin_id'],
        ['ap_deposit_requests', 'admin_id'], ['offline_requests', 'admin_id'], ['payment_reviews', 'admin_id'],
        ['counter_shifts', 'admin_id'], ['payments', 'verified_by'], ['tickets', 'scanned_by'],
    ];

    /** Per-person rows deleted with an agent account that has no history. */
    private const PER_PERSON = [
        ['admin_profiles', 'admin_id'], ['admin_sessions', 'admin_id'], ['admin_devices', 'admin_id'],
        ['admin_login_events', 'admin_id'], ['agent_route_permissions', 'admin_id'],
        ['agent_targets', 'agent_admin_id'], ['agent_lessons', 'admin_id'],
        ['wa_agent_presence', 'admin_id'], ['staff_presence', 'admin_id'], ['staff_thread_reads', 'admin_id'],
        ['ap_members', 'admin_id'], ['ap_passkeys', 'admin_id'], ['ap_wa_links', 'admin_id'],
        ['wa_logins', 'admin_id'], ['wa_identity_links', 'admin_id'], ['push_subscriptions', 'admin_id'],
    ];

    /** settings JSON maps keyed by admin id. */
    private const ADMIN_MAPS = ['agent_codes', 'agent_types', 'agent_commission_overrides',
        'agent_deposits', 'agent_salaries', 'agent_agreements'];

    /** ap_applications columns that name one private file (uploads/agents-kyc/...). */
    private const AP_FILE_COLS = ['doc_front', 'doc_back', 'selfie', 'thumb'];

    /** Columns that may still name an uploads/ file after the reset (a file they name is never moved). */
    private const FILE_REFS = [
        ['admin_profiles', 'photo_path'], ['admin_profiles', 'kyc_doc_path'], ['admin_profiles', 'kyc_doc2_path'],
        ['ap_applications', 'doc_front'], ['ap_applications', 'doc_back'], ['ap_applications', 'selfie'],
        ['ap_applications', 'thumb'], ['ap_deposit_requests', 'proof_path'], ['agreement_signatures', 'signature_path'],
    ];

    /**
     * Sign-in / presence tables: a sign-in or heartbeat during the backup adds or removes
     * rows. Left out of the fingerprint and of the exact row-count check.
     */
    private const BUSY_ANY = ['admin_sessions', 'admin_devices', 'admin_login_events', 'wa_agent_presence', 'staff_presence'];

    /** @var array<string, array<string, true>> table => columns present */
    private array $cols = [];
    /** @var list<array{child:string,col:string,parent:string,pcol:string,rule:string}> */
    private array $fks = [];
    /** @var array<string, true> "table" / "table.column" looked for but absent */
    private array $skipped = [];
    private string $now;

    /** @param array{keep-admin:list<int>,keep-app:list<int>,keep-staff:list<int>,keep-ap:list<int>} $keep */
    public function __construct(private array $keep, private bool $dropSurvivorApps = false)
    {
        $this->now = date('Y-m-d H:i:s');
        foreach (Database::fetchAll('SELECT table_name AS t, column_name AS c FROM information_schema.columns
                                      WHERE table_schema = DATABASE()') as $r) {
            $this->cols[(string) $r['t']][(string) $r['c']] = true;
        }
        $this->fks = Database::fetchAll(
            "SELECT k.TABLE_NAME AS child, k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS parent,
                    k.REFERENCED_COLUMN_NAME AS pcol, r.DELETE_RULE AS rule
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                AND r.TABLE_NAME = k.TABLE_NAME
              WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL");
    }

    /* ---------------------------------------------------------------
     *  Schema helpers
     * --------------------------------------------------------------- */

    /** Table (and column) present? Remembers what was missing for the report. */
    private function has(string $table, string ...$columns): bool
    {
        if (!isset($this->cols[$table])) { $this->skipped[$table] = true; return false; }
        foreach ($columns as $c) {
            if (!isset($this->cols[$table][$c])) { $this->skipped[$table . '.' . $c] = true; return false; }
        }
        return true;
    }

    /** Column present, without noting it as skipped (optional columns). */
    private function col(string $table, string $column): bool
    {
        return isset($this->cols[$table][$column]);
    }

    /** @return list<array{child:string,col:string,parent:string,pcol:string,rule:string}> */
    private function children(string $parent): array
    {
        return array_values(array_filter($this->fks, static fn(array $f): bool => $f['parent'] === $parent));
    }

    /** "col IN (1,2)" for ints, always safe to inline; "0=1" when empty. */
    private static function inInts(string $col, array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return $ids === [] ? '0=1' : $col . ' IN (' . implode(',', $ids) . ')';
    }

    /** "NOT (col IN (1,2))"; "NOT (0=1)" (= every row) when empty. */
    private static function notInInts(string $col, array $ids): string
    {
        return 'NOT (' . self::inInts($col, $ids) . ')';
    }

    /** @return array{0:string,1:array<string,string>} "col IN (:s0,:s1)" + params; "0=1" when empty. */
    private static function inStrs(string $col, array $vals, string $p = 's'): array
    {
        $vals = array_values(array_unique(array_map('strval', $vals)));
        if ($vals === []) { return ['0=1', []]; }
        $ph = []; $params = [];
        foreach ($vals as $i => $v) { $ph[] = ':' . $p . $i; $params[$p . $i] = $v; }
        return [$col . ' IN (' . implode(',', $ph) . ')', $params];
    }

    private static function count(string $table, string $where, array $params = []): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where, $params, 0);
    }

    /** @return list<int> */
    private static function ids(string $sql, array $params = []): array
    {
        return array_map('intval', array_column(Database::fetchAll($sql, $params), 'id'));
    }

    /** Same reference StaffApps::refFor() prints (kept local: bootstrap does not load StaffApps). */
    public static function staffRef(int $id): string
    {
        return 'STF-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /** Printable one-line text (names come from forms). */
    public static function txt(mixed $s, int $max = 60): string
    {
        $s = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $s));
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    /**
     * Every spelling of a phone the WhatsApp side may have stored: normalised
     * digits (what WaBot sees), and with the India / Nepal prefix.
     *
     * @return list<string>
     */
    private static function phoneForms(mixed $raw): array
    {
        $digits = (string) preg_replace('/\D/', '', (string) $raw);
        if (strlen($digits) < 7) { return []; }
        $n = normalisePhone($digits);
        return array_values(array_unique(array_filter([$digits, $n, '91' . $n, '977' . $n],
            static fn(string $s): bool => $s !== '' && strlen($s) <= 20)));
    }

    /** Private-file path an ap_applications row names, or null when it is not one of ours. */
    private static function kycRel(mixed $p): ?string
    {
        $p = str_replace('\\', '/', trim((string) $p));
        if ($p === '' || !str_starts_with($p, 'agents-kyc/') || str_contains($p, '..') || str_contains($p, "\0")) { return null; }
        return $p;
    }

    /* ---------------------------------------------------------------
     *  The plan: everything that would change, read only
     * --------------------------------------------------------------- */

    /**
     * @return array{ops:list<array>, maps:list<array>, people:array, files:list<array>,
     *               blockers:list<string>, warnings:list<string>, notes:list<string>, skipped:list<string>, fingerprint:string}
     */
    public function plan(): array
    {
        $this->skipped = [];
        $ops = []; $maps = []; $files = []; $blockers = []; $warnings = []; $notes = [];
        $people = ['kept_admins' => [], 'kept_apps' => [], 'kept_staff' => [], 'kept_ap' => [], 'archive' => [], 'delete' => [],
                   'del_apps' => [], 'del_staff' => [], 'del_ap' => [], 'office_untouched' => 0,
                   'auto_apps' => [], 'auto_staff' => [], 'auto_ap' => []];
        $ka = $this->keep['keep-admin']; $kp = $this->keep['keep-app']; $ks = $this->keep['keep-staff']; $kq = $this->keep['keep-ap'];

        if (!$this->has('admins', 'id', 'role', 'is_active')) {
            return ['ops' => [], 'maps' => [], 'people' => $people, 'files' => [], 'blockers' => ['admins table not found - wrong database?'],
                    'warnings' => [], 'notes' => [], 'skipped' => array_keys($this->skipped), 'fingerprint' => ''];
        }

        /* 1. Who is kept, and are the listed ids real? */
        $people['kept_admins'] = Database::fetchAll('SELECT id, username, full_name, role, is_active FROM admins WHERE '
            . self::inInts('id', $ka) . ' ORDER BY id');
        foreach (array_diff($ka, array_map('intval', array_column($people['kept_admins'], 'id'))) as $id) {
            $blockers[] = "--keep-admin #$id does not exist";
        }
        $people['office_untouched'] = self::count('admins', 'role <> :r AND ' . self::notInInts('id', $ka), ['r' => self::RESET_ROLE]);
        $apps = $this->has('agent_applications', 'id', 'ref', 'name', 'admin_id', 'status');
        $staff = $this->has('staff_applications', 'id', 'full_name', 'admin_id', 'status');
        $ap = $this->has('ap_applications', 'id', 'ref', 'draft_key', 'status', 'kind', 'full_name', 'admin_id');
        if ($apps) {
            $people['kept_apps'] = Database::fetchAll('SELECT id, ref, type, name, admin_id, status FROM agent_applications WHERE '
                . self::inInts('id', $kp) . ' ORDER BY id');
            foreach (array_diff($kp, array_map('intval', array_column($people['kept_apps'], 'id'))) as $id) {
                $blockers[] = "--keep-app #$id does not exist";
            }
        } elseif ($kp) { $blockers[] = '--keep-app given but agent_applications does not exist'; }
        if ($staff) {
            $people['kept_staff'] = Database::fetchAll('SELECT id, full_name, role_applied, admin_id, status FROM staff_applications WHERE '
                . self::inInts('id', $ks) . ' ORDER BY id');
            foreach (array_diff($ks, array_map('intval', array_column($people['kept_staff'], 'id'))) as $id) {
                $blockers[] = "--keep-staff #$id does not exist";
            }
        } elseif ($ks) { $blockers[] = '--keep-staff given but staff_applications does not exist'; }
        $apSel = 'SELECT id, ref, draft_key, kind, status, full_name, '
            . ($this->col('ap_applications', 'org_name') ? 'org_name' : 'NULL AS org_name') . ', admin_id, '
            . ($this->col('ap_applications', 'phone') ? 'phone' : 'NULL AS phone') . ', '
            . ($this->col('ap_applications', 'whatsapp') ? 'whatsapp' : 'NULL AS whatsapp') . ' FROM ap_applications WHERE ';
        if ($ap) {
            $people['kept_ap'] = Database::fetchAll($apSel . self::inInts('id', $kq) . ' ORDER BY id');
            foreach (array_diff($kq, array_map('intval', array_column($people['kept_ap'], 'id'))) as $id) {
                $blockers[] = "--keep-ap #$id does not exist";
            }
        } elseif ($kq) { $blockers[] = '--keep-ap given but ap_applications does not exist'; }

        /* 2. Agent accounts that are reset, split by history */
        $cands = Database::fetchAll('SELECT id, username, full_name, phone, role, is_active FROM admins WHERE role = :r AND '
            . self::notInInts('id', $ka) . ' ORDER BY id', ['r' => self::RESET_ROLE]);
        $candIds = array_map('intval', array_column($cands, 'id'));
        foreach ([['kept_apps', 'agent application'], ['kept_staff', 'staff application'], ['kept_ap', 'AgentPro application']] as [$k, $label]) {
            foreach ($people[$k] as $r) {
                if (in_array((int) ($r['admin_id'] ?? 0), $candIds, true)) {
                    $ref = $k === 'kept_staff' ? self::staffRef((int) $r['id']) : (string) $r['ref'];
                    $blockers[] = "kept $label $ref belongs to agent account #" . (int) $r['admin_id']
                        . ' which is not in --keep-admin (add it, or drop the application)';
                }
            }
        }
        $personTables = array_column(self::PER_PERSON, 0);
        $checks = [];
        foreach (self::HISTORY as [$t, $c]) { if ($this->has($t, $c)) { $checks["$t.$c"] = [$t, $c]; } }
        foreach ($this->children('admins') as $f) {
            // Any other row whose FK points at the account is history too: a delete would cascade it,
            // null it (bookings.sold_by_admin_id -> who sold the ticket is lost) or fail on it.
            if (!in_array($f['child'], $personTables, true) && $f['child'] !== 'admins') { $checks[$f['child'] . '.' . $f['col']] = [$f['child'], $f['col']]; }
        }
        $why = [];
        if ($candIds) {
            foreach ($checks as $label => [$t, $c]) {
                foreach (Database::fetchAll('SELECT `' . $c . '` AS id, COUNT(*) AS n FROM `' . $t . '` WHERE ' . self::inInts('`' . $c . '`', $candIds)
                    . ' GROUP BY `' . $c . '`') as $r) {
                    $why[(int) $r['id']][] = $label . '×' . (int) $r['n'];
                }
            }
        }
        $arch = []; $del = [];
        foreach ($cands as $r) {
            $id = (int) $r['id'];
            if (isset($why[$id])) { $r['why'] = implode(', ', $why[$id]); $people['archive'][] = $r; $arch[] = $id; }
            else { $people['delete'][] = $r; $del[] = $id; }
        }

        /* 2b. Applications of accounts that STAY (kept, archived, office role) are kept too */
        // They are the signed agreement / filled form behind an account that is still on the
        // books (an archived agent's open ledger, a staff member's ID photo, the K3 form). From
        // here on $kp / $ks / $kq are "kept by id OR by surviving account"; every side table below
        // (signatures, Team ID rows, staff folders, invites, form-read jobs) follows them.
        $survivorWhere = 'admin_id IS NOT NULL AND admin_id IN (SELECT id FROM admins WHERE ' . self::notInInts('id', $del) . ')';
        if (!$this->dropSurvivorApps) {
            if ($apps) {
                $people['auto_apps'] = Database::fetchAll('SELECT id, ref, type, name, admin_id, status FROM agent_applications WHERE '
                    . self::notInInts('id', $kp) . ' AND ' . $survivorWhere . ' ORDER BY id');
                $kp = array_merge($kp, array_map('intval', array_column($people['auto_apps'], 'id')));
            }
            if ($staff) {
                $people['auto_staff'] = Database::fetchAll('SELECT id, full_name, role_applied, admin_id, status FROM staff_applications WHERE '
                    . self::notInInts('id', $ks) . ' AND ' . $survivorWhere . ' ORDER BY id');
                $ks = array_merge($ks, array_map('intval', array_column($people['auto_staff'], 'id')));
            }
            if ($ap) {
                $people['auto_ap'] = Database::fetchAll($apSel . self::notInInts('id', $kq) . ' AND ' . $survivorWhere . ' ORDER BY id');
                $kq = array_merge($kq, array_map('intval', array_column($people['auto_ap'], 'id')));
            }
        }
        $keptApRows = array_merge($people['kept_ap'], $people['auto_ap']);

        /* 3. Archive: account and money stay; sign-in, website and card off */
        if ($arch) {
            $ops[] = ['t' => 'admins', 'op' => 'update', 'w' => self::inInts('id', $arch) . ' AND is_active <> 0', 'p' => [],
                      'set' => ['is_active' => 0], 'why' => 'archive: sign-in off'];
            foreach (['public_listing', 'public_phone'] as $c) {
                if ($this->has('admin_profiles', 'admin_id', $c)) {
                    $ops[] = ['t' => 'admin_profiles', 'op' => 'update', 'w' => self::inInts('admin_id', $arch) . " AND `$c` <> 0", 'p' => [],
                              'set' => [$c => 0], 'why' => 'archive: website off'];
                }
            }
            if ($this->has('ap_members', 'admin_id', 'card_status')) {
                $ops[] = ['t' => 'ap_members', 'op' => 'update', 'w' => self::inInts('admin_id', $arch) . " AND card_status = 'active'", 'p' => [],
                          'set' => ['card_status' => 'suspended'], 'why' => 'archive: ID card suspended'];
            }
            if ($this->has('admin_sessions', 'admin_id', 'ended_at', 'end_reason')) {
                $ops[] = ['t' => 'admin_sessions', 'op' => 'update', 'w' => self::inInts('admin_id', $arch) . ' AND ended_at IS NULL', 'p' => [],
                          'set' => ['ended_at' => $this->now, 'end_reason' => 'Team reset 30 Oct 2026 (archived)'], 'why' => 'archive: open sessions ended'];
            }
        }

        /* 4. AgentPro applications that are not kept (read now: their phones feed step 5) */
        $delAp = $ap ? Database::fetchAll($apSel . self::notInInts('id', $kq) . ' ORDER BY id') : [];
        $people['del_ap'] = $delAp;
        $apIds = array_map('intval', array_column($delAp, 'id'));

        /* 5. Delete: accounts without history and their per-person rows */
        // Phones of everyone removed now (deleted accounts + deleted AgentPro applicants) and of
        // everyone who stays: a phone that a survivor also uses is never cleaned.
        $gonePhones = []; $keepPhones = [];
        $addPhones = static function (array &$into, mixed ...$raw): void {
            foreach ($raw as $r) { foreach (self::phoneForms($r) as $f) { $into[$f] = true; } }
        };
        foreach ($cands as $r) {
            if (in_array((int) $r['id'], $del, true)) { $addPhones($gonePhones, $r['phone']); }
        }
        $profPhoneCols = array_values(array_filter(['display_phone', 'whatsapp'], fn(string $c): bool => $this->col('admin_profiles', $c)));
        if ($del && $profPhoneCols && $this->col('admin_profiles', 'admin_id')) {
            foreach (Database::fetchAll('SELECT ' . implode(', ', $profPhoneCols) . ' FROM admin_profiles WHERE ' . self::inInts('admin_id', $del)) as $r) {
                $addPhones($gonePhones, ...array_values($r));
            }
        }
        if ($del && $this->col('ap_wa_links', 'phone') && $this->col('ap_wa_links', 'admin_id')) {
            foreach (Database::fetchAll('SELECT phone FROM ap_wa_links WHERE ' . self::inInts('admin_id', $del)) as $r) { $addPhones($gonePhones, $r['phone']); }
        }
        foreach ($delAp as $r) {
            // An applicant whose account stays (kept / archived / office role) keeps their WhatsApp state.
            if (empty($r['admin_id']) || in_array((int) $r['admin_id'], $del, true)) { $addPhones($gonePhones, $r['phone'], $r['whatsapp']); }
        }
        if ($gonePhones) {
            foreach (Database::fetchAll("SELECT phone FROM admins WHERE phone IS NOT NULL AND phone <> '' AND " . self::notInInts('id', $del)) as $r) {
                $addPhones($keepPhones, $r['phone']);
            }
            if ($profPhoneCols && $this->col('admin_profiles', 'admin_id')) {
                foreach (Database::fetchAll('SELECT ' . implode(', ', $profPhoneCols) . ' FROM admin_profiles WHERE ' . self::notInInts('admin_id', $del)) as $r) {
                    $addPhones($keepPhones, ...array_values($r));
                }
            }
            if ($this->col('ap_wa_links', 'phone') && $this->col('ap_wa_links', 'admin_id')) {
                foreach (Database::fetchAll('SELECT phone FROM ap_wa_links WHERE ' . self::notInInts('admin_id', $del)) as $r) { $addPhones($keepPhones, $r['phone']); }
            }
            foreach ($keptApRows as $r) { $addPhones($keepPhones, $r['phone'], $r['whatsapp']); }
            // A removed applicant who is also a passenger keeps their customer chat state.
            if ($this->col('users', 'phone')) {
                $forms = array_keys($gonePhones);
                [$uw, $up] = self::inStrs('phone', array_merge($forms, array_map(static fn(string $f): string => '+' . $f, $forms)), 'up');
                foreach (Database::fetchAll('SELECT phone FROM users WHERE ' . $uw, $up) as $r) { $addPhones($keepPhones, $r['phone']); }
            }
        }
        $phones = array_keys(array_diff_key($gonePhones, $keepPhones));
        if (array_intersect_key($gonePhones, $keepPhones)) {
            $notes[] = count(array_intersect_key($gonePhones, $keepPhones)) . ' phone form(s) of removed people are also used by someone who stays - their WhatsApp state is left';
        }

        if ($del) {
            foreach (self::PER_PERSON as [$t, $c]) {
                if ($this->has($t, $c)) { $ops[] = ['t' => $t, 'op' => 'delete', 'w' => self::inInts("`$c`", $del), 'p' => [], 'why' => 'per-person row of a deleted account']; }
            }
            if ($this->has('team_passkeys', 'owner_kind', 'owner_id')) {
                $ops[] = ['t' => 'team_passkeys', 'op' => 'delete', 'w' => "owner_kind = 'admin' AND " . self::inInts('owner_id', $del), 'p' => [], 'why' => 'passkeys of a deleted account'];
            }
            // A survivor whose parent agent is deleted is NOT edited (kept people are never touched):
            // it is named so the owner can keep the parent too. Ids are never reused, so the old
            // number just points at nobody.
            if ($this->has('admin_profiles', 'admin_id', 'parent_agent_id')) {
                foreach (Database::fetchAll('SELECT admin_id, parent_agent_id FROM admin_profiles WHERE ' . self::inInts('parent_agent_id', $del)
                    . ' AND ' . self::notInInts('admin_id', $del)) as $r) {
                    $warnings[] = 'account #' . (int) $r['admin_id'] . ' has parent agent #' . (int) $r['parent_agent_id'] . ' which is deleted (add it to --keep-admin to keep the link)';
                }
            }
            if ($apps && $this->col('agent_applications', 'parent_agent_id')) {
                foreach (Database::fetchAll('SELECT ref, parent_agent_id FROM agent_applications WHERE ' . self::inInts('parent_agent_id', $del)
                    . ' AND ' . self::inInts('id', $kp)) as $r) {
                    $warnings[] = 'kept application ' . $r['ref'] . ' has parent agent #' . (int) $r['parent_agent_id'] . ' which is deleted (add it to --keep-admin to keep the link)';
                }
            }
            if ($this->has('admin_profiles', 'admin_id')) {
                $pc = array_values(array_filter(['photo_path', 'kyc_doc_path', 'kyc_doc2_path'], fn(string $c): bool => $this->col('admin_profiles', $c)));
                if ($pc) {
                    foreach (Database::fetchAll('SELECT admin_id, ' . implode(', ', $pc) . ' FROM admin_profiles WHERE ' . self::inInts('admin_id', $del)) as $r) {
                        foreach ($pc as $c) { if (!empty($r[$c])) { $files[] = ['kind' => 'file', 'rel' => (string) $r[$c], 'why' => 'admin #' . $r['admin_id'] . ' ' . $c]; } }
                    }
                }
            }
            if ($this->col('staff_threads', 'agent_admin_id')) {
                $n = self::count('staff_threads', self::inInts('agent_admin_id', $del));
                if ($n) { $notes[] = "$n staff_threads row(s) of deleted accounts are left as they are (office chat record)"; }
            }
            if ($this->col('delivery_log', 'agent_id')) {
                $n = self::count('delivery_log', self::inInts('agent_id', $del));
                if ($n) { $notes[] = "$n delivery_log row(s) of deleted accounts are left as they are (ticket delivery log)"; }
            }
            if ($this->col('member_codes', 'admin_id')) {
                $n = self::count('member_codes', self::inInts('admin_id', $del));
                if ($n) { $notes[] = "$n member_codes row(s) of deleted accounts are left as they are (codes are never reused)"; }
            }
        }
        if ($phones && $this->has('ap_wa_state', 'phone')) {
            [$pw, $pp] = self::inStrs('phone', $phones, 'ph');
            $ops[] = ['t' => 'ap_wa_state', 'op' => 'delete', 'w' => $pw, 'p' => $pp, 'why' => 'WhatsApp desk state of removed people (by phone)'];
        }

        /* 6. Old-form applications that are not kept */
        $delApps = $apps ? Database::fetchAll('SELECT id, ref, type, name, admin_id, status FROM agent_applications WHERE '
            . self::notInInts('id', $kp) . ' ORDER BY id') : [];
        $delStaff = $staff ? Database::fetchAll('SELECT id, full_name, role_applied, admin_id, status FROM staff_applications WHERE '
            . self::notInInts('id', $ks) . ' ORDER BY id') : [];
        $people['del_apps'] = $delApps; $people['del_staff'] = $delStaff;
        $appIds = array_map('intval', array_column($delApps, 'id'));
        $staffIds = array_map('intval', array_column($delStaff, 'id'));
        // Only with --drop-survivor-apps can a deleted old-form application still belong to an
        // account that stays (kept / archived / office role): name each one.
        $oldOwners = array_values(array_filter(array_map('intval', array_merge(array_column($delApps, 'admin_id'), array_column($delStaff, 'admin_id')))));
        $oldRole = [];
        if ($oldOwners) {
            foreach (Database::fetchAll('SELECT id, role FROM admins WHERE ' . self::inInts('id', $oldOwners) . ' AND ' . self::notInInts('id', $del)) as $r) {
                $oldRole[(int) $r['id']] = (string) $r['role'];
            }
        }
        foreach ([[$delApps, 'agent application', 'its signed agreement'], [$delStaff, 'staff application', 'its form, ID photo and uploads/staff folder']] as [$rows, $label, $loses]) {
            foreach ($rows as $r) {
                $aid = (int) ($r['admin_id'] ?? 0);
                if (!isset($oldRole[$aid])) { continue; }
                $ref = $label === 'staff application' ? self::staffRef((int) $r['id']) : (string) $r['ref'];
                $state = in_array($aid, $ka, true) ? 'kept' : (in_array($aid, $arch, true) ? 'archived' : 'stays');
                $warnings[] = "$label $ref is deleted (--drop-survivor-apps) but its account #$aid ({$oldRole[$aid]}) $state - lost with it: $loses";
            }
        }
        // The side tables are cleaned by "NOT one of the kept people", not by "one of the rows
        // deleted now": rows left behind by applications removed earlier (orphans) go as well.
        $keptKeys = array_merge(array_map(static fn(int $i): string => 'agent:' . $i, $kp), array_map(static fn(int $i): string => 'staff:' . $i, $ks));
        $keptRefs = array_merge(array_map('strval', array_column(array_merge($people['kept_apps'], $people['auto_apps']), 'ref')),
            array_map(static fn(int $i): string => self::staffRef($i), $ks));

        if ($this->has('team_card_jobs', 'ref')) {
            [$kw, $p] = self::inStrs('ref', $keptRefs, 'ref');
            $w = "(ref LIKE 'AGR-%' OR ref LIKE 'STF-%') AND NOT ($kw)";
            if ($this->col('team_card_events', 'provider_ref') && $this->col('team_card_jobs', 'provider_ref')) {
                [$jkw, $jp] = self::inStrs('j.ref', $keptRefs, 'jref');
                $ops[] = ['t' => 'team_card_events', 'op' => 'delete', 'p' => $jp, 'why' => 'card delivery events (not kept)',
                          'w' => "provider_ref IN (SELECT j.provider_ref FROM team_card_jobs j WHERE j.provider_ref IS NOT NULL AND (j.ref LIKE 'AGR-%' OR j.ref LIKE 'STF-%') AND NOT ($jkw))"];
            } else {
                $this->has('team_card_events', 'provider_ref');
            }
            $ops[] = ['t' => 'team_card_jobs', 'op' => 'delete', 'w' => $w, 'p' => $p, 'why' => 'card delivery jobs (not kept)',
                      'files' => $this->col('team_card_jobs', 'file_key') ? 'file_key' : null];
            $other = self::count('team_card_jobs', "NOT (ref LIKE 'AGR-%' OR ref LIKE 'STF-%')");
            if ($other) { $notes[] = "$other team_card_jobs row(s) with other refs are left as they are"; }
        }
        $codeFk = (bool) array_filter($this->fks, static fn(array $f): bool => $f['child'] === 'team_code_history' && $f['parent'] === 'team_identity');
        foreach (['team_reviews' => 'kr', 'team_drafts' => 'kd', 'team_identity' => 'ki'] as $t => $prefix) {
            if (!$this->has($t, 'person_key')) { continue; }
            // Distinct placeholder prefixes: the FK check below joins two ops' params.
            [$kw, $p] = self::inStrs('person_key', $keptKeys, $prefix);
            $w = "(person_key LIKE 'agent:%' OR person_key LIKE 'staff:%') AND NOT ($kw)";
            if ($t === 'team_identity' && $codeFk) {
                // team_code_history (never touched) has a foreign key to team_identity: a delete could
                // cascade into it or fail on it. Detach instead (same rule as TeamPurge): the row stays
                // under a 'purged:' key, so the A-n / S-n code stays reserved and the person key is free.
                $set = ['person_key' => ['$sql' => "CONCAT('purged:', person_key, ':reset20261030')"]];
                foreach (['consent_note', 'route'] as $c) { if ($this->col($t, $c)) { $set[$c] = null; } }
                $ops[] = ['t' => $t, 'op' => 'update', 'w' => $w, 'p' => $p, 'set' => $set, 'why' => 'Team ID detached (code stays reserved in team_code_history)'];
                continue;
            }
            $ops[] = ['t' => $t, 'op' => 'delete', 'w' => $w, 'p' => $p, 'why' => 'registry row not kept',
                      'files' => $t === 'team_reviews' && $this->col($t, 'file_key') ? 'file_key' : null];
        }
        if ($this->has('agreement_signatures', 'party_type', 'party_id')) {
            $ops[] = ['t' => 'agreement_signatures', 'op' => 'delete', 'p' => [], 'why' => 'signatures not kept (deleted or earlier-removed applications)',
                      'w' => "(party_type = 'agent' AND party_id IS NOT NULL AND " . self::notInInts('party_id', $kp) . ')'
                           . " OR (party_type = 'staff' AND party_id IS NOT NULL AND " . self::notInInts('party_id', $ks) . ')'];
            $other = self::count('agreement_signatures', "party_type NOT IN ('agent','staff') OR party_id IS NULL");
            if ($other) { $notes[] = "$other agreement_signatures row(s) of other party types / without a party are left as they are"; }
            if ($this->col('agreement_signatures', 'signature_path')) {
                // Staff signature files live in uploads/staff/<id>/ (moved with the folder below).
                foreach (Database::fetchAll("SELECT party_id, signature_path FROM agreement_signatures WHERE party_type = 'agent' AND party_id IS NOT NULL AND "
                    . self::notInInts('party_id', $kp) . " AND signature_path IS NOT NULL AND signature_path <> ''") as $r) {
                    $files[] = ['kind' => 'file', 'rel' => (string) $r['signature_path'], 'why' => 'agent signature #' . (int) $r['party_id']];
                }
            }
        }
        if ($this->has('team_passkeys', 'owner_kind', 'owner_id')) {
            $ops[] = ['t' => 'team_passkeys', 'op' => 'delete', 'w' => "owner_kind = 'staff' AND " . self::notInInts('owner_id', $ks), 'p' => [], 'why' => 'staff portal passkeys (not kept)'];
        }
        if ($appIds) { $ops[] = ['t' => 'agent_applications', 'op' => 'delete', 'w' => self::inInts('id', $appIds), 'p' => [], 'why' => 'old agent application (documents inside the row)']; }
        if ($staffIds) {
            $ops[] = ['t' => 'staff_applications', 'op' => 'delete', 'w' => self::inInts('id', $staffIds), 'p' => [], 'why' => 'old staff application (files in uploads/staff/<id>/)'];
        }
        // Every uploads/staff/<id>/ folder that is not a kept application's: the deleted ones and
        // folders left by applications removed earlier.
        if (is_dir(UPLOAD_PATH . '/staff')) {
            foreach (scandir(UPLOAD_PATH . '/staff') ?: [] as $d) {
                if (preg_match('/^[1-9]\d{0,9}$/', $d) !== 1 || !is_dir(UPLOAD_PATH . '/staff/' . $d) || in_array((int) $d, $ks, true)) { continue; }
                $files[] = ['kind' => 'dir', 'rel' => 'staff/' . $d,
                            'why' => self::staffRef((int) $d) . (in_array((int) $d, $staffIds, true) ? '' : ' - orphan folder')];
            }
        }

        /* 7. AgentPro: applications, invites, AI queue */
        if ($ap) {
            $ownerRole = [];
            $owners = array_values(array_filter(array_map('intval', array_column($delAp, 'admin_id'))));
            if ($owners) {
                foreach (Database::fetchAll('SELECT id, role FROM admins WHERE ' . self::inInts('id', $owners)) as $r) { $ownerRole[(int) $r['id']] = (string) $r['role']; }
            }
            $fileCols = array_values(array_filter(self::AP_FILE_COLS, fn(string $c): bool => $this->col('ap_applications', $c)));
            $hasExtras = $this->col('ap_applications', 'extras');
            $unknown = 0;
            if ($apIds && ($fileCols || $hasExtras)) {
                $sel = 'SELECT id, ref' . ($fileCols ? ', ' . implode(', ', $fileCols) : '') . ($hasExtras ? ', extras' : '')
                     . ' FROM ap_applications WHERE ' . self::inInts('id', $apIds) . ' ORDER BY id';
                foreach (Database::fetchAll($sel) as $r) {
                    $paths = [];
                    foreach ($fileCols as $c) { if ((string) ($r[$c] ?? '') !== '') { $paths[$c] = $r[$c]; } }
                    if ($hasExtras && (string) ($r['extras'] ?? '') !== '') {
                        $x = json_decode((string) $r['extras'], true);
                        foreach (is_array($x) ? $x : [] as $i => $e) {
                            if (is_array($e) && (string) ($e['path'] ?? '') !== '') { $paths['extra ' . ($i + 1) . ' ' . self::txt($e['label'] ?? '', 24)] = $e['path']; }
                        }
                    }
                    foreach ($paths as $what => $p) {
                        $rel = self::kycRel($p);
                        if ($rel === null) { $unknown++; continue; }
                        $files[] = ['kind' => 'file', 'rel' => $rel, 'why' => $r['ref'] . ' ' . $what];
                    }
                }
            }
            if ($unknown) { $notes[] = "$unknown file path(s) on deleted AgentPro applications are not under agents-kyc/ - left on disk"; }
            foreach ($delAp as $r) {
                $aid = (int) ($r['admin_id'] ?? 0);
                if ($aid === 0 || in_array($aid, $del, true)) { continue; }
                if (!isset($ownerRole[$aid])) { continue; }
                $state = in_array($aid, $arch, true) ? 'archived' : 'stays';
                $warnings[] = 'AgentPro application ' . $r['ref'] . ' is deleted (--drop-survivor-apps) but its account #' . $aid . ' (' . $ownerRole[$aid] . ") $state"
                    . ' - its filled form / selfie / thumb go with it (add it to --keep-ap, or drop the flag, to keep them)';
            }
            if ($apIds) { $ops[] = ['t' => 'ap_applications', 'op' => 'delete', 'w' => self::inInts('id', $apIds), 'p' => [], 'why' => 'AgentPro application (files in uploads/agents-kyc/)']; }
        }
        if ($this->has('ap_invites', 'id', 'used_at')) {
            // Unused invites, and invites an application that is deleted now (or earlier) used.
            $keepInv = $this->col('ap_invites', 'application_id') && $kq
                ? self::ids('SELECT id FROM ap_invites WHERE used_at IS NOT NULL AND ' . self::inInts('application_id', $kq)) : [];
            // A kept draft opened from an invite has not claimed it yet (AgentPro::submit does,
            // and refuses when the invite is gone): keep every invite a kept application names.
            if ($kq && $this->col('ap_applications', 'invite_id')) {
                $keepInv = array_merge($keepInv, self::ids('SELECT invite_id AS id FROM ap_applications WHERE invite_id IS NOT NULL AND ' . self::inInts('id', $kq)));
            }
            $invIds = self::ids('SELECT id FROM ap_invites WHERE ' . self::notInInts('id', $keepInv));
            if ($invIds) { $ops[] = ['t' => 'ap_invites', 'op' => 'delete', 'w' => self::inInts('id', $invIds), 'p' => [], 'why' => 'staff invites unused / used by a deleted application']; }
        }
        if ($this->has('ap_jobs', 'id', 'phone', 'kind', 'status', 'text')) {
            // Pinned to ids: the worker keeps adding / finishing jobs while this runs.
            $or = ["status IN ('done','failed')"];
            $params = [];
            $keepKeys = array_values(array_filter(array_map('strval', array_column($keptApRows, 'draft_key'))));
            $docKeep = [];
            foreach ($keepKeys as $i => $k) { $docKeep[] = 'INSTR(`text`, :dk' . $i . ') > 0'; $params['dk' . $i] = $k; }
            $or[] = "kind = 'doc'" . ($docKeep ? ' AND NOT (' . implode(' OR ', $docKeep) . ')' : '');
            // By phone only member ('wa') jobs: a queued 'wa_customer' reply is a passenger's.
            if ($phones) { [$pw, $pp] = self::inStrs('phone', $phones, 'jp'); $or[] = "kind = 'wa' AND $pw"; $params += $pp; }
            $jobIds = self::ids('SELECT id FROM ap_jobs WHERE ' . implode(' OR ', array_map(static fn(string $s): string => "($s)", $or)), $params);
            if ($jobIds) { $ops[] = ['t' => 'ap_jobs', 'op' => 'delete', 'w' => self::inInts('id', $jobIds), 'p' => [], 'busy' => 'pinned',
                                     'why' => 'AI queue: finished, form reads of deleted applications, removed people']; }
            $left = self::count('ap_jobs', self::notInInts('id', $jobIds));
            if ($left) { $notes[] = "$left queued / running ap_jobs row(s) (passengers, kept people) are left for the worker"; }
        }

        if ($del) { $ops[] = ['t' => 'admins', 'op' => 'delete', 'w' => self::inInts('id', $del) . " AND role = '" . self::RESET_ROLE . "'", 'p' => [], 'why' => 'agent account without history']; }
        if ($this->has('wa_verify', 'id')) {
            $max = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM wa_verify', [], 0);
            if ($max > 0) { $ops[] = ['t' => 'wa_verify', 'op' => 'delete', 'w' => 'id <= ' . $max, 'p' => [], 'busy' => 'pinned', 'why' => 'WhatsApp verification tokens (cleared)']; }
        }

        /* 8. Foreign keys pointing at rows we delete that the plan does not handle */
        foreach ($ops as $op) {
            if ($op['op'] !== 'delete' || $op['t'] === 'admins') { continue; }   // admins: such rows already count as history
            foreach ($this->children($op['t']) as $f) {
                // Child rows that another op of this plan deletes anyway are fine; count only the rest.
                $own = []; $params = $op['p'];
                foreach ($ops as $j => $o) {
                    if ($o['op'] !== 'delete' || $o['t'] !== $f['child']) { continue; }
                    // Native prepares allow a placeholder once per statement: rename the child op's ones.
                    $own[] = '(' . preg_replace_callback('/:(\w+)/', static fn(array $m): string => isset($o['p'][$m[1]]) ? ':' . $m[1] . '_c' . $j : $m[0], $o['w']) . ')';
                    foreach ($o['p'] as $k => $v) { $params[$k . '_c' . $j] = $v; }
                }
                $n = self::count($f['child'], '`' . $f['col'] . '` IN (SELECT `' . $f['pcol'] . '` FROM `' . $op['t'] . '` WHERE ' . $op['w'] . ')'
                    . ($own ? ' AND NOT (' . implode(' OR ', $own) . ')' : ''), $params);
                if ($n === 0) { continue; }
                $blockers[] = "{$f['child']}.{$f['col']} -> {$op['t']}.{$f['pcol']} (ON DELETE {$f['rule']}) has $n row(s) pointing at rows this reset deletes";
            }
        }
        $ops = array_values($ops);

        /* 9. Settings maps */
        // Keys of the accounts deleted now, plus keys of accounts that no longer exist at all
        // (left by earlier deletes: such a key still holds an SHG number nobody can use).
        $drop = array_map('strval', $del);
        $existing = array_flip(array_map('strval', array_column(Database::fetchAll('SELECT id FROM admins'), 'id')));
        $orphanKey = static fn(string $k): bool => preg_match('/^[1-9]\d{0,9}$/', $k) === 1 && !isset($existing[$k]);
        // team_manager_archive keys: agent:<app id> / staff:<app id> not kept, account:<deleted or gone admin id>.
        $archHit = static fn(string $k): bool => preg_match('/^(agent|staff):\d+$/', $k) === 1
            ? !in_array($k, $keptKeys, true)
            : (preg_match('/^account:(\d+)$/', $k, $m) === 1 && (in_array((int) $m[1], $del, true) || $orphanKey($m[1])));
        if ($this->has('settings', 'skey', 'svalue')) {
            foreach (array_merge(self::ADMIN_MAPS, ['team_manager_archive']) as $skey) {
                $row = Database::fetch('SELECT skey, svalue FROM settings WHERE skey = :k', ['k' => $skey]);
                if ($row === null || $row['svalue'] === null || trim((string) $row['svalue']) === '') { continue; }
                try { $map = Settings::decodeJsonLayers((string) $row['svalue'], $skey); }
                catch (Throwable $e) { $warnings[] = "settings $skey is not valid JSON - left as it is"; continue; }
                if (!is_array($map)) { $warnings[] = "settings $skey is not a map - left as it is"; continue; }
                $gone = [];
                foreach (array_keys($map) as $k) {
                    $hit = $skey === 'team_manager_archive' ? $archHit((string) $k) : (in_array((string) $k, $drop, true) || $orphanKey((string) $k));
                    if ($hit) { $gone[] = (string) $k; unset($map[$k]); }
                }
                if ($skey === 'agent_agreements' && $gone) {
                    $old = Settings::decodeJsonLayers((string) $row['svalue'], $skey);
                    foreach ($gone as $k) {
                        if (!empty($old[$k]) && is_string($old[$k])) { $files[] = ['kind' => 'file', 'rel' => $old[$k], 'why' => "admin #$k signed agreement"]; }
                    }
                }
                if ($gone) {
                    $maps[] = ['skey' => $skey, 'raw' => (string) $row['svalue'], 'gone' => $gone,
                               'new' => $map === [] ? '{}' : (string) json_encode((object) $map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
                }
            }
        }

        /* 10. Counts + fingerprint (re-checked inside the transaction) */
        foreach ($ops as &$op) {
            $op['n'] = self::count($op['t'], $op['w'], $op['p']);
            if (in_array($op['t'], self::BUSY_ANY, true)) { $op['busy'] = 'any'; }
        }
        unset($op);
        $ops = array_values(array_filter($ops, static fn(array $o): bool => $o['n'] > 0));
        foreach ($ops as $op) {
            if (!empty($op['files'])) {
                $n = self::count($op['t'], '(' . $op['w'] . ') AND `' . $op['files'] . "` IS NOT NULL AND `{$op['files']}` <> ''", $op['p']);
                if ($n) { $notes[] = "$n encrypted private-team file(s) referenced by {$op['t']} are no longer referenced (left on disk; sealed, not web-served)"; }
            }
        }
        foreach (['team_code_history', 'team_sequences'] as $t) { if (isset($this->cols[$t])) { $notes[] = "$t is not touched (codes are never reused)"; } }
        // One entry per file path (two applications may name the same file).
        $seen = [];
        $files = array_values(array_filter($files, static function (array $f) use (&$seen): bool {
            $k = $f['kind'] . ':' . $f['rel'];
            if (isset($seen[$k])) { return false; }
            return $seen[$k] = true;
        }));
        // Busy ops (live WhatsApp / sign-in traffic) are not part of the fingerprint: apply() runs
        // them with the planned WHERE and checks their counts loosely. Everything else is exact.
        $fp = sha1((string) json_encode([
            array_map(static fn(array $o): array => [$o['t'], $o['op'], $o['w'], $o['p'], $o['set'] ?? null, $o['n']],
                array_values(array_filter($ops, static fn(array $o): bool => empty($o['busy'])))),
            array_map(static fn(array $m): array => [$m['skey'], sha1($m['raw'])], $maps),
        ]));

        return ['ops' => $ops, 'maps' => $maps, 'people' => $people, 'files' => $files, 'blockers' => $blockers,
                'warnings' => $warnings, 'notes' => $notes, 'skipped' => array_keys($this->skipped), 'fingerprint' => $fp];
    }

    /* ---------------------------------------------------------------
     *  Backup: every row to be deleted / updated, as gzip JSON lines
     * --------------------------------------------------------------- */

    /** Binary-safe row for JSON: invalid UTF-8 strings become {"$b64": "..."}. */
    private static function jsonRow(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) { $row[$k] = ['$b64' => base64_encode($v)]; }
        }
        return $row;
    }

    /** @return array{lines:int, rows:int} */
    public function backup(array $plan, string $path, array $meta): array
    {
        $gz = gzopen($path, 'wb6');
        if ($gz === false) { throw new RuntimeException("Cannot write $path"); }
        @chmod($path, 0600);
        $lines = 0; $rows = 0;
        $put = static function (array $line) use ($gz, &$lines): void {
            if (gzwrite($gz, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
                throw new RuntimeException('Backup write failed');
            }
            $lines++;
        };
        try {
            $put(['meta' => $meta]);
            $pdo = Database::pdo();
            foreach ($plan['ops'] as $op) {
                // Unbuffered: agent_applications rows carry megabytes of data-URI documents each.
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                try {
                    $st = $pdo->prepare('SELECT * FROM `' . $op['t'] . '` WHERE ' . $op['w']);
                    $st->execute($op['p']);
                    while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                        $put(['table' => $op['t'], 'op' => $op['op'], 'set' => $op['set'] ?? null, 'row' => self::jsonRow($r)]);
                        $rows++;
                    }
                    $st->closeCursor();
                } finally {
                    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
            }
            foreach ($plan['maps'] as $m) {
                $r = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $m['skey']]);
                $put(['table' => 'settings', 'op' => 'update', 'set' => ['svalue' => $m['new']], 'removed_keys' => $m['gone'], 'row' => self::jsonRow($r ?? [])]);
                $rows++;
            }
            foreach ($plan['files'] as $f) { $put(['file' => $f]); }
        } finally {
            gzclose($gz);
        }
        // Read it back: a backup that cannot be read is no backup.
        $in = gzopen($path, 'rb'); $back = 0;
        if ($in === false) { throw new RuntimeException("Cannot re-open $path"); }
        while (($l = gzgets($in)) !== false) { if (trim($l) !== '') { json_decode($l, true, 512, JSON_THROW_ON_ERROR); $back++; } }
        gzclose($in);
        if ($back !== $lines) { throw new RuntimeException("Backup check failed: wrote $lines lines, read back $back"); }
        return ['lines' => $lines, 'rows' => $rows];
    }

    /* ---------------------------------------------------------------
     *  Apply, inside ONE transaction
     * --------------------------------------------------------------- */

    /** @return array<string, int> "op table" => rows */
    public function apply(array $planned): array
    {
        return Database::transaction(function () use ($planned): array {
            $fresh = $this->plan();
            if ($fresh['blockers']) { throw new RuntimeException('Refused inside the transaction: ' . implode('; ', $fresh['blockers'])); }
            if ($fresh['fingerprint'] !== $planned['fingerprint']) {
                throw new RuntimeException('Data changed between the backup and the write - nothing was changed. Run again.');
            }
            $done = [];
            foreach ($planned['ops'] as $op) {
                if ($op['op'] === 'update') {
                    $set = []; $p = $op['p'];
                    foreach ($op['set'] as $c => $v) {
                        // ['$sql' => ...] is a fixed expression written by plan() itself, never input.
                        if (is_array($v)) { $set[] = "`$c` = " . $v['$sql']; continue; }
                        $set[] = "`$c` = :set_$c"; $p['set_' . $c] = $v;
                    }
                    $n = Database::run('UPDATE `' . $op['t'] . '` SET ' . implode(', ', $set) . ' WHERE ' . $op['w'], $p);
                } else {
                    $n = Database::run('DELETE FROM `' . $op['t'] . '` WHERE ' . $op['w'], $op['p']);
                }
                // 'pinned' (planned ids / max id): the worker may have purged some meanwhile, never more.
                // 'any' (sign-in / presence rows): a sign-in during the backup may add or end rows.
                $busy = (string) ($op['busy'] ?? '');
                $ok = $busy === 'any' || ($busy === 'pinned' ? $n <= $op['n'] : $n === $op['n']);
                if (!$ok) {
                    throw new RuntimeException(strtoupper($op['op']) . " {$op['t']}: planned {$op['n']} row(s), touched $n - rolled back. Run again.");
                }
                $done[$op['op'] . ' ' . $op['t']] = ($done[$op['op'] . ' ' . $op['t']] ?? 0) + $n;
            }
            foreach ($planned['maps'] as $m) {
                $raw = Database::scalar('SELECT svalue FROM settings WHERE skey = :k FOR UPDATE', ['k' => $m['skey']]);
                if ((string) $raw !== $m['raw']) { throw new RuntimeException("settings {$m['skey']} changed since the backup - rolled back. Run again."); }
                Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $m['new'], 'k' => $m['skey']]);
                $done['update settings'] = ($done['update settings'] ?? 0) + 1;
            }
            Logger::audit('team.reset_20261030', 'team', 'reset-20261030', null,
                ['rows' => $done, 'kept' => $this->keep], 'One-off team reset approved by the owner (tools/team-reset-20261030.php)');
            return $done;
        }, 1);
    }

    /* ---------------------------------------------------------------
     *  Files: out of the web root into the backup folder, after COMMIT
     * --------------------------------------------------------------- */

    /** @return list<string> report lines */
    public function moveFiles(array $files, string $dest): array
    {
        $out = [];
        $root = realpath(UPLOAD_PATH);
        foreach ($files as $f) {
            $rel = ltrim((string) preg_replace('#^/?uploads/#', '', str_replace('\\', '/', $f['rel'])), '/');
            $src = UPLOAD_PATH . '/' . $rel;
            $real = realpath($src);
            if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                $out[] = "  -   uploads/$rel (not on disk)";
                continue;
            }
            if ($f['kind'] === 'file' && ($who = $this->stillUsed($rel)) !== null) { $out[] = "  -   uploads/$rel (still used by $who - left)"; continue; }
            $to = $dest . '/' . $rel;
            if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0700, true) && !is_dir(dirname($to))) {
                $out[] = "  !!  uploads/$rel could not be moved (cannot create " . dirname($to) . ')';
                continue;
            }
            $ok = @rename($real, $to) || (self::copyTree($real, $to) && self::removeTree($real));
            $out[] = $ok ? "  ok  uploads/$rel -> $to" : "  !!  uploads/$rel could not be moved - remove it by hand";
        }
        return $out;
    }

    /** Which row that stays (after COMMIT) still names this uploads/ file? Null = nobody. */
    private function stillUsed(string $rel): ?string
    {
        $forms = array_values(array_unique([$rel, 'uploads/' . $rel, '/uploads/' . $rel]));
        foreach (self::FILE_REFS as [$t, $c]) {
            if (!$this->col($t, $c)) { continue; }
            [$w, $p] = self::inStrs("`$c`", $forms, 'f');
            if (Database::exists("SELECT 1 FROM `$t` WHERE $w LIMIT 1", $p)) { return "$t.$c"; }
        }
        // extras is JSON: json_encode() writes "agents-kyc\/x.jpg" unless told not to.
        if ($this->col('ap_applications', 'extras')
            && Database::exists('SELECT 1 FROM ap_applications WHERE INSTR(extras, :a) > 0 OR INSTR(extras, :b) > 0 LIMIT 1',
                ['a' => '"' . $rel . '"', 'b' => '"' . str_replace('/', '\\/', $rel) . '"'])) {
            return 'ap_applications.extras';
        }
        return null;
    }

    private static function copyTree(string $from, string $to): bool
    {
        if (is_file($from)) { return @copy($from, $to); }
        if (!is_dir($to) && !@mkdir($to, 0700, true)) { return false; }
        foreach (scandir($from) ?: [] as $n) {
            if ($n === '.' || $n === '..') { continue; }
            if (!self::copyTree($from . '/' . $n, $to . '/' . $n)) { return false; }
        }
        return true;
    }

    private static function removeTree(string $p): bool
    {
        if (is_file($p) || is_link($p)) { return @unlink($p); }
        foreach (scandir($p) ?: [] as $n) {
            if ($n !== '.' && $n !== '..' && !self::removeTree($p . '/' . $n)) { return false; }
        }
        return @rmdir($p);
    }
}

/* =====================================================================
 *  Command line
 * ===================================================================== */

$usage = "Usage: php tools/team-reset-20261030.php --keep-admin=<ids> --keep-app=<ids> --keep-staff=<ids> --keep-ap=<ids> [--drop-survivor-apps] [--backup-dir=<dir>] [--go]\n"
       . "  <ids> = comma-separated numbers, or 'none'. Dry run unless --go; --go needs --backup-dir (outside the web root).\n"
       . "  --keep-app = agent_applications.id, --keep-staff = staff_applications.id, --keep-ap = ap_applications.id (AgentPro).\n"
       . "  Applications of accounts that stay (kept / archived / office role) are kept unless --drop-survivor-apps.\n"
       . "  SHG_RESET_DB=<database> (environment) runs against another database on the same server, e.g. a test copy;\n"
       . "  uploads/ is still this install's real folder, so with SHG_RESET_DB no file is moved (the list is printed).\n";
$fail = static function (string $msg, int $code = 1) use ($usage): never {
    fwrite(STDERR, "ERROR: $msg\n\n$usage");
    exit($code);
};
$go = false; $backupDir = null; $keep = []; $dropSurvivors = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--go') { $go = true; continue; }
    if ($a === '--drop-survivor-apps') { $dropSurvivors = true; continue; }
    if ($a === '--help' || $a === '-h') { echo $usage; exit(0); }
    if (preg_match('/^--(keep-admin|keep-app|keep-staff|keep-ap)=(.*)$/', $a, $m) === 1) {
        $v = str_replace(' ', '', $m[2]);
        if ($v === '' || strtolower($v) === 'none') { $keep[$m[1]] = []; continue; }
        if (preg_match('/^[1-9]\d{0,9}(,[1-9]\d{0,9})*$/', $v) !== 1) { $fail("--{$m[1]}: use ids separated by commas, e.g. --{$m[1]}=3,17"); }
        $keep[$m[1]] = array_values(array_unique(array_map('intval', explode(',', $v))));
        continue;
    }
    if (str_starts_with($a, '--backup-dir=')) { $backupDir = rtrim(substr($a, 13), '/\\'); continue; }
    $fail("unknown argument $a");
}
foreach (['keep-admin', 'keep-app', 'keep-staff', 'keep-ap'] as $f) {
    if (!array_key_exists($f, $keep)) { $fail("--$f is required (use --$f=none to keep nobody)"); }
}
/** @var array{keep-admin:list<int>,keep-app:list<int>,keep-staff:list<int>,keep-ap:list<int>} $keep */
if ($go && ($backupDir === null || $backupDir === '')) { $fail('--go refuses to run without --backup-dir=<dir>', 2); }
if ($backupDir !== null) {
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0700, true) && !is_dir($backupDir)) { $fail("cannot create --backup-dir $backupDir"); }
    $real = (string) realpath($backupDir);
    $web  = (string) realpath(ROOT_PATH);
    if ($real === '' || !is_writable($real)) { $fail("--backup-dir $backupDir is not writable"); }
    // The backup holds names, phones, bank details and password hashes: never inside the public web root.
    if ($web !== '' && ($real === $web || str_starts_with($real . DIRECTORY_SEPARATOR, $web . DIRECTORY_SEPARATOR))) { $fail('--backup-dir must be outside the web root (' . $web . ')'); }
    $backupDir = $real;
}

// Test copy: SHG_RESET_DB points this tool (only) at another database on the same server.
$overrideDb = getenv('SHG_RESET_DB');
if (is_string($overrideDb) && $overrideDb !== '') {
    if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $overrideDb) !== 1) { $fail('SHG_RESET_DB: letters, digits and _ only'); }
    if (!Database::exists('SELECT 1 FROM information_schema.schemata WHERE schema_name = :s', ['s' => $overrideDb])) {
        $fail("SHG_RESET_DB: database $overrideDb does not exist");
    }
    Database::pdo()->exec('USE `' . $overrideDb . '`');
    Settings::flush();
}

$e = static fn(mixed $s, int $max = 60): string => TeamReset20261030::txt($s, $max);
$testCopy = is_string($overrideDb) && $overrideDb !== '';
$reset = new TeamReset20261030($keep, $dropSurvivors);
$plan = $reset->plan();
$db = (string) Database::scalar('SELECT DATABASE()', [], '?');

echo 'TEAM RESET 30 Oct 2026 - ' . ($go ? 'APPLY' : 'DRY RUN (nothing is written)') . " - database $db"
    . ($testCopy ? ' (SHG_RESET_DB override: files are listed, never moved)' : '')
    . ($dropSurvivors ? ' - --drop-survivor-apps' : '') . "\n";
echo "\nKept accounts (--keep-admin):\n";
foreach ($plan['people']['kept_admins'] as $r) { printf("  #%-6d %-20s %-30s %s%s\n", $r['id'], $e($r['username'], 20), $e($r['full_name'], 30), $r['role'], $r['is_active'] ? '' : ' (inactive)'); }
if (!$plan['people']['kept_admins']) { echo "  (none)\n"; }
echo '  + ' . $plan['people']['office_untouched'] . " office account(s) of other roles - never touched\n";
echo "Kept old agent applications (--keep-app):\n";
foreach ($plan['people']['kept_apps'] as $r) { printf("  #%-6d %-12s %-30s %s, %s, account #%s\n", $r['id'], $r['ref'], $e($r['name'], 30), $r['type'], $r['status'], $r['admin_id'] ?? '-'); }
if (!$plan['people']['kept_apps']) { echo "  (none)\n"; }
echo "Kept old staff applications (--keep-staff):\n";
foreach ($plan['people']['kept_staff'] as $r) { printf("  #%-6d %-12s %-30s %s, %s\n", $r['id'], TeamReset20261030::staffRef((int) $r['id']), $e($r['full_name'], 30), $r['role_applied'], $r['status']); }
if (!$plan['people']['kept_staff']) { echo "  (none)\n"; }
echo "Kept AgentPro applications (--keep-ap):\n";
foreach ($plan['people']['kept_ap'] as $r) { printf("  #%-6d %-12s %-30s %s, %s, account #%s\n", $r['id'], $r['ref'], $e($r['full_name'] ?: $r['org_name'], 30), $r['kind'], $r['status'], $r['admin_id'] ?? '-'); }
if (!$plan['people']['kept_ap']) { echo "  (none)\n"; }
if ($plan['people']['auto_apps'] || $plan['people']['auto_staff'] || $plan['people']['auto_ap']) {
    echo "Also kept - application of an account that stays (use --drop-survivor-apps to delete these too):\n";
    foreach ($plan['people']['auto_apps'] as $r) { printf("  old agent #%-6d %-12s %-30s %s, account #%d\n", $r['id'], $r['ref'], $e($r['name'], 30), $r['status'], $r['admin_id']); }
    foreach ($plan['people']['auto_staff'] as $r) { printf("  old staff #%-6d %-12s %-30s %s, account #%d\n", $r['id'], TeamReset20261030::staffRef((int) $r['id']), $e($r['full_name'], 30), $r['status'], $r['admin_id']); }
    foreach ($plan['people']['auto_ap'] as $r) { printf("  AgentPro  #%-6d %-12s %-30s %s, account #%d\n", $r['id'], $r['ref'], $e($r['full_name'] ?: $r['org_name'], 30), $r['status'], $r['admin_id']); }
}

echo "\nAgent accounts ARCHIVED (have history; account + money rows stay, sign-in off):\n";
foreach ($plan['people']['archive'] as $r) { printf("  #%-6d %-20s %-30s agent%s - %s\n", $r['id'], $e($r['username'], 20), $e($r['full_name'], 30), $r['is_active'] ? '' : ' (already off)', $r['why']); }
if (!$plan['people']['archive']) { echo "  (none)\n"; }
echo "Agent accounts DELETED (no history):\n";
foreach ($plan['people']['delete'] as $r) { printf("  #%-6d %-20s %-30s agent\n", $r['id'], $e($r['username'], 20), $e($r['full_name'], 30)); }
if (!$plan['people']['delete']) { echo "  (none)\n"; }
echo "Old agent applications DELETED:\n";
foreach ($plan['people']['del_apps'] as $r) { printf("  #%-6d %-12s %-30s %s, %s, account #%s\n", $r['id'], $r['ref'], $e($r['name'], 30), $r['type'], $r['status'], $r['admin_id'] ?? '-'); }
if (!$plan['people']['del_apps']) { echo "  (none)\n"; }
echo "Old staff applications DELETED:\n";
foreach ($plan['people']['del_staff'] as $r) { printf("  #%-6d %-12s %-30s %s, %s\n", $r['id'], TeamReset20261030::staffRef((int) $r['id']), $e($r['full_name'], 30), $r['role_applied'], $r['status']); }
if (!$plan['people']['del_staff']) { echo "  (none)\n"; }
echo "AgentPro applications DELETED:\n";
foreach ($plan['people']['del_ap'] as $r) { printf("  #%-6d %-12s %-30s %s, %s, account #%s\n", $r['id'], $r['ref'], $e($r['full_name'] ?: $r['org_name'], 30), $r['kind'], $r['status'], $r['admin_id'] ?? '-'); }
if (!$plan['people']['del_ap']) { echo "  (none)\n"; }

echo "\nPer table:\n";
foreach ($plan['ops'] as $op) {
    $set = isset($op['set']) ? ' SET ' . implode(', ', array_map(static fn($c, $v): string => $c . '=' . ($v === null ? 'NULL' : (is_array($v) ? $v['$sql'] : $v)), array_keys($op['set']), $op['set'])) : '';
    printf("  %-6s %-26s %6d row(s)  %s%s\n", strtoupper($op['op']), $op['t'], $op['n'], $op['why'], $set);
}
foreach ($plan['maps'] as $m) { printf("  UPDATE settings.%-17s remove key(s) %s\n", $m['skey'], implode(', ', $m['gone'])); }
if (!$plan['ops'] && !$plan['maps']) { echo "  nothing to change\n"; }
if ($plan['files']) {
    echo $testCopy
        ? "Files the reset WOULD move out of uploads/ (SHG_RESET_DB test copy: uploads/ belongs to the real database, so nothing is moved):\n"
        : "Files moved out of uploads/ into the backup folder after COMMIT (unless a row that stays still names them):\n";
    foreach ($plan['files'] as $f) { echo '  uploads/' . ltrim((string) preg_replace('#^/?uploads/#', '', $f['rel']), '/') . ($f['kind'] === 'dir' ? '/' : '') . '  (' . $e($f['why'], 70) . ")\n"; }
}
foreach ($plan['notes'] as $n) { echo "Note: $n\n"; }
if ($plan['skipped']) { echo 'SKIPPED (not in this database): ' . implode(', ', $plan['skipped']) . "\n"; }
foreach ($plan['warnings'] as $w) { echo "WARNING: $w\n"; }
foreach ($plan['blockers'] as $b) { echo "BLOCKER: $b\n"; }

$total = array_sum(array_column($plan['ops'], 'n')) + count($plan['maps']);
printf("\nSummary: %d archived, %d account(s) deleted, %d old agent + %d old staff + %d AgentPro application(s) deleted, %d row change(s) in %d table(s), %d settings map(s), %d file path(s).\n",
    count($plan['people']['archive']), count($plan['people']['delete']), count($plan['people']['del_apps']), count($plan['people']['del_staff']),
    count($plan['people']['del_ap']), $total, count(array_unique(array_column($plan['ops'], 't'))), count($plan['maps']), count($plan['files']));

if ($plan['blockers']) {
    echo $go ? "REFUSED: fix the blocker(s) above first. Nothing was written.\n" : "Fix the blocker(s) above before --go.\n";
    exit(2);
}
if (!$go) {
    echo "DRY RUN - nothing written. Add --go (with --backup-dir) to apply.\n";
    exit(0);
}
if ($total === 0) { echo "Nothing to change.\n"; exit(0); }

/* --go: backup first, then one transaction, then files */
$stamp = date('Ymd-His');
$file  = $backupDir . DIRECTORY_SEPARATOR . 'team-reset-' . $stamp . '.jsonl.gz';
if (file_exists($file)) { $fail("$file already exists"); }
try {
    $b = $reset->backup($plan, $file, ['tool' => 'tools/team-reset-20261030.php', 'db' => $db, 'at' => date('c'), 'keep' => $keep,
        'drop_survivor_apps' => $dropSurvivors, 'test_copy' => $testCopy, 'fingerprint' => $plan['fingerprint']]);
} catch (Throwable $ex) {
    @unlink($file);
    fwrite(STDERR, 'BACKUP FAILED - nothing was changed: ' . $ex->getMessage() . "\n");
    exit(1);
}
printf("\nBackup: %s\n        %d row(s), %d line(s), sha256 %s\n", $file, $b['rows'], $b['lines'], hash_file('sha256', $file));

try {
    $done = $reset->apply($plan);
} catch (Throwable $ex) {
    fwrite(STDERR, 'ROLLED BACK - nothing was changed: ' . $ex->getMessage() . "\n");
    exit(1);
}
Settings::flush();
echo "COMMITTED:\n";
foreach ($done as $k => $n) { printf("  %-34s %d\n", $k, $n); }

if ($plan['files'] && $testCopy) {
    // The copy's rows were changed, but uploads/ is the real install's: moving would strip live
    // rows of their KYC / selfie / staff files, and stillUsed() would ask the copy, not live.
    echo 'Files: NOT moved (SHG_RESET_DB test copy) - ' . count($plan['files']) . " path(s) listed above would move on the real database.\n";
} elseif ($plan['files']) {
    $dest = $backupDir . DIRECTORY_SEPARATOR . 'team-reset-' . $stamp . '-files';
    echo "Files:\n" . implode("\n", $reset->moveFiles($plan['files'], $dest)) . "\n";
}
// Re-plan on the committed data: anything still listed (busy WhatsApp / sign-in rows aside) means a re-run is needed.
$after = (new TeamReset20261030($keep, $dropSurvivors))->plan();
$left  = count($after['maps']) + ($testCopy ? 0 : count($after['files']));
foreach ($after['ops'] as $op) { $left += empty($op['busy']) ? $op['n'] : 0; }
printf("Final: %d agent account(s) active, %d old agent / %d old staff / %d AgentPro application(s) remain; %d change(s) left (expect 0).\n",
    (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE role = 'agent' AND is_active = 1", [], 0),
    count($after['people']['kept_apps']) + count($after['people']['auto_apps']) + count($after['people']['del_apps']),
    count($after['people']['kept_staff']) + count($after['people']['auto_staff']) + count($after['people']['del_staff']),
    count($after['people']['kept_ap']) + count($after['people']['auto_ap']) + count($after['people']['del_ap']), $left);
echo "Done. Keep the backup file; restore rows from it (or from your mysqldump) if anything must come back.\n";
exit(0);
