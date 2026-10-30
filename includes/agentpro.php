<?php
/**
 * =====================================================================
 *  includes/agentpro.php — Agent Pro (Oct 2026): the people behind the
 *  counters. ID cards, visiting cards, the join form, one-tap approval,
 *  member money requests. See docs/AGENT-PRO-DESIGN.md.
 *
 *  WHAT IS REUSED, NOT COPIED
 *  --------------------------
 *    admins            login, role, active                (unchanged)
 *    admin_profiles    phone, WhatsApp, address, ID, photo, KYC  (AgentWallet::saveProfile)
 *    settings.agent_codes   SHG-0027                       (AgentWallet::agentCodeLabel)
 *    agent_ledger / agent_payout_requests   the wallet     (AgentWallet::record / requestPayout)
 *
 *  ap_members holds only what none of those had: what the cards print
 *  (designation, the visiting-card WhatsApp number, validity, the verify
 *  token), payout details (encrypted) and a staff number for non-agents.
 *
 *  "UPDATE TOGETHER"
 *  -----------------
 *  No card is ever stored as an image. member() reads the agent code,
 *  name, photo, status and numbers NOW, and ApCard draws from that, so
 *  a new code or a new photo is on the very next view or print.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/agentwallet.php';

/**
 * "This mobile already has a login" (30 Oct 2026, owner: one WhatsApp number may
 * carry up to 3 IDs). $owners = the accounts on it (id, full_name, role, is_active);
 * $canShare = the office may still add one more ID on it (with the office's yes).
 */
final class ApPhoneTaken extends RuntimeException
{
    /** @param list<array<string, mixed>> $owners */
    public function __construct(string $message, public readonly array $owners, public readonly bool $canShare, public readonly int $max)
    {
        parent::__construct($message);
    }
}

final class AgentPro
{
    /** The ID documents the join form understands. Value => label. */
    public const ID_TYPES = [
        'aadhaar'     => 'Aadhaar',
        'citizenship' => 'Nepali Citizenship (नागरिकता)',
        'nid'         => 'Nepal National ID (राष्ट्रिय परिचयपत्र)',
        'pan'         => 'PAN Card',
        'voter'       => 'Voter ID',
        'dl'          => 'Driving Licence',
        'passport'    => 'Passport',
    ];

    /** Our type => the closest AgentWallet::ID_TYPES value for admin_profiles. */
    private const ID_TYPE_PROFILE = [
        'aadhaar' => 'Aadhaar', 'citizenship' => 'Citizenship', 'nid' => 'Citizenship', 'pan' => 'PAN',
        'voter' => 'Voter ID', 'dl' => 'Driving Licence', 'passport' => 'Passport',
    ];

    /** Roles an invite may carry (never superadmin — that is not handed out by link). */
    public const INVITE_ROLES = ['counter', 'manager', 'accountant', 'support', 'scanner', 'official', 'agent', 'driver', 'conductor'];

    /**
     * 30 Oct 2026 — bus crew. They are AgentPro staff (ID card, /me/) AND have
     * a driver-app account (drivers row + PIN, driver/) so the bus shows live.
     * admins.role has no such value: their login is CREW_LOGIN_ROLE (boarding
     * staff, tickets.scan only) and ap_members.crew_role says which crew role.
     */
    public const CREW_ROLES = ['driver', 'conductor'];
    public const CREW_LOGIN_ROLE = 'scanner';

    /** Short role names for the office screens (AgentPro roles, crew included). */
    public const ROLE_LABELS = [
        'agent' => 'Agent', 'counter' => 'Counter', 'manager' => 'Manager', 'accountant' => 'Accountant',
        'support' => 'Support', 'scanner' => 'Boarding staff', 'official' => 'Official', 'operations' => 'Operations',
        'viewer' => 'Viewer', 'superadmin' => 'Super-admin', 'driver' => 'Driver', 'conductor' => 'Conductor',
    ];

    /**
     * 30 Oct 2026 — the extra boxes of the printed paper form (occupation …
     * languages), column => max length. They exist once
     * database/upgrade-2026-10-30-agentpro-af1.sql ran (appHas() guards).
     */
    public const EXTRA_FIELDS = ['occupation' => 120, 'education' => 120, 'experience' => 160, 'skills' => 200, 'languages' => 120];

    /** A page of the hand-filled paper form, kept with the extras as {label: "Paper form p1", path, paper: 1}. */
    public const PAPER_LABEL = 'Paper form p';
    public const PAPER_MAX_PAGES = 4;

    /** What the card prints under the name when the office typed nothing. */
    private const DESIGNATION_DEFAULTS = [
        'agent:org'    => 'Authorised Booking Agency',
        'agent:person' => 'Ticketing Agent',
        'counter'      => 'Counter Executive',
        'manager'      => 'Manager',
        'superadmin'   => 'Director',
        'accountant'   => 'Accounts Officer',
        'support'      => 'Customer Support',
        'scanner'      => 'Boarding Staff',
        'official'     => 'Official',
        'driver'       => 'Bus Driver',
        'conductor'    => 'Bus Conductor',
    ];

    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /** Unambiguous letters for public references (no 0/O, 1/I/L). */
    private const REF_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** Images are re-encoded to at most this edge (keeps EXIF / GPS out of storage). */
    private const DOC_MAX_EDGE = 2200;

    /* =================================================================
     *  Is it installed?
     * ================================================================= */

    private static ?bool $available = null;

    public static function available(): bool
    {
        if (self::$available === null) {
            try {
                self::$available = Database::exists(
                    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members'"
                );
            } catch (Throwable $e) {
                self::$available = false;
            }
        }
        return self::$available;
    }

    /**
     * May this signed-in admin use the office form (join.php?office=1):
     * fill for someone, skip the WhatsApp check, approve at once?
     *
     * @param array<string, mixed>|null $admin Auth::admin()
     */
    public static function officeAllowed(?array $admin): bool
    {
        if ($admin === null) {
            return false;
        }
        // The same gates as Auth::requireAdmin(): a temporary password (sent on WhatsApp)
        // must be changed first, and a view-only account changes nothing.
        if (self::mustChangePw($admin)) {
            return false;
        }
        $role = (string) ($admin['role'] ?? '');
        if ($role === 'viewer') {
            return false;
        }
        return $role === 'superadmin' || $role === 'manager' || Auth::can('staff.manage');
    }

    /** Is this session still on a temporary password (Auth::requireAdmin shuts everything until it is changed)? */
    public static function mustChangePw(?array $admin): bool
    {
        return !empty($admin['must_change_pw'])
            || (defined('ADMIN_SESSION_KEY') && !empty($_SESSION[ADMIN_SESSION_KEY]['must_change_pw'] ?? null));
    }

    /**
     * May this office user mark KYC verified ("saw the original ID") and make
     * the senior staff roles? The same rule as admin/team.php ($canKyc):
     * super-admin or manager only.
     *
     * @param array<string, mixed>|null $admin
     */
    public static function officeCanKyc(?array $admin): bool
    {
        $role = (string) ($admin['role'] ?? '');
        return $admin !== null && ($role === 'superadmin' || $role === 'manager');
    }

    /** Staff roles only a super-admin / manager office user may create from the office form. */
    public const OFFICE_SENIOR_ROLES = ['manager', 'accountant'];

    /** @var array<string, true> */
    private static array $appCols = [];

    /** Does ap_applications have this column yet? (the af1 migration may not have run on a server) */
    public static function appHas(string $col): bool
    {
        if (self::$appCols === []) {
            self::$appCols['#'] = true;
            try {
                foreach (Database::fetchAll(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_applications'"
                ) as $r) {
                    self::$appCols[(string) $r['COLUMN_NAME']] = true;
                }
            } catch (Throwable $e) {
                // not installed: every optional column reads as missing
            }
        }
        return isset(self::$appCols[$col]);
    }

    /** @var array<string, true> */
    private static array $memCols = [];

    /** Does ap_members have this column yet? (upgrade-2026-10-30-agentpro2.sql: crew_role, driver_id) */
    public static function memberHas(string $col): bool
    {
        if (self::$memCols === []) {
            self::$memCols['#'] = true;
            try {
                foreach (Database::fetchAll(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members'"
                ) as $r) {
                    self::$memCols[(string) $r['COLUMN_NAME']] = true;
                }
            } catch (Throwable $e) {
                // not installed
            }
        }
        return isset(self::$memCols[$col]);
    }

    /** The admins.role an AgentPro role signs in with (bus crew → boarding staff). */
    public static function loginRole(string $role): string
    {
        return in_array($role, self::CREW_ROLES, true) ? self::CREW_LOGIN_ROLE : $role;
    }

    /* =================================================================
     *  Encryption at rest (bank account, ID number)
     *
     *  sodium secretbox with a key derived from APP_KEY. If APP_KEY is ever
     *  changed the old values read back as '' — the screens then ask for
     *  the number again rather than showing garbage.
     * ================================================================= */

    private static function key(): string
    {
        return sodium_crypto_generichash('agentpro-v1|' . APP_KEY, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $box): string
    {
        if ($box === null || !str_starts_with($box, 'v1:')) {
            return '';
        }
        $raw = base64_decode(substr($box, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key()
        );
        return $plain === false ? '' : $plain;
    }

    /**
     * What may be shown of an ID number. Aadhaar: only the last four, the
     * way UIDAI requires anywhere outside an Aadhaar Data Vault. Others:
     * the last four with the rest dotted, enough to recognise, not to copy.
     */
    public static function maskId(string $type, string $number): string
    {
        $n = strtoupper(preg_replace('/\s+/', '', $number) ?? '');
        if ($n === '') {
            return '';
        }
        if ($type === 'aadhaar') {
            $d = preg_replace('/\D/', '', $n) ?? '';
            return strlen($d) >= 4 ? 'XXXX XXXX ' . substr($d, -4) : '';
        }
        $len = mb_strlen($n);
        return $len <= 4 ? $n : str_repeat('•', min(8, $len - 4)) . mb_substr($n, -4);
    }

    /**
     * Undo UTF-8 that was stored through a latin1 connection ("Â·" for "·",
     * "Ã©" for "é") — a database loaded with the wrong client charset shows
     * exactly that. Text that is already right is returned unchanged.
     */
    public static function fixMojibake(string $s): string
    {
        if (preg_match('/[\x{00C2}\x{00C3}][\x{0080}-\x{00BF}]/u', $s) !== 1) {
            return $s;
        }
        $back = mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        return mb_check_encoding($back, 'UTF-8') ? $back : $s;
    }

    /* =================================================================
     *  Phone numbers as people read them, and as wa.me wants them
     * ================================================================= */

    /**
     * Digits with the country code, for wa.me / tel:. A bare 10-digit
     * number takes the member's own country (admin_profiles.country_code)
     * and otherwise India, which is where the company and most counters are.
     */
    public static function intlDigits(string $raw, string $countryCode = ''): string
    {
        $d = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        if (strlen($d) === 10) {
            $cc = in_array($countryCode, ['91', '977'], true) ? $countryCode : '91';
            $d = $cc . $d;
        }
        return (strlen($d) >= 11 && strlen($d) <= 15) ? $d : '';
    }

    /** "+91 98765 43210" · "+977 981-2345678" — the way it is printed. */
    public static function displayPhone(string $intl): string
    {
        $d = preg_replace('/\D/', '', $intl) ?? '';
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            return '+91 ' . substr($d, 2, 5) . ' ' . substr($d, 7);
        }
        if (strlen($d) === 13 && str_starts_with($d, '977')) {
            return '+977 ' . substr($d, 3, 3) . '-' . substr($d, 6);
        }
        return $d === '' ? '' : '+' . $d;
    }

    /* =================================================================
     *  Members
     * ================================================================= */

    /**
     * Everything a card, the portal or a WhatsApp reply needs about one
     * member of staff, read now. Null when there is no such admin.
     *
     * @return array<string, mixed>|null
     */
    public static function member(int $adminId, bool $ensure = true): ?array
    {
        if ($adminId <= 0) {
            return null;
        }
        $a = Database::fetch(
            'SELECT id, username, full_name, email, phone, role, is_active, locked_until, created_at, last_login_at
               FROM admins WHERE id = :id',
            ['id' => $adminId]
        );
        if ($a === null) {
            return null;
        }
        $p = AgentWallet::profile($adminId);
        $m = null;
        if (self::available()) {
            $m = $ensure ? self::ensureMember($adminId, (string) $a['role']) : Database::fetch(
                'SELECT * FROM ap_members WHERE admin_id = :a', ['a' => $adminId]
            );
        }
        $m = $m ?? [];

        $role    = (string) $a['role'];
        $isAgent = $role === 'agent';
        $kind    = $isAgent ? AgentWallet::normaliseKind((string) ($p['agent_kind'] ?? 'person')) : '';
        $cc      = (string) ($p['country_code'] ?? '');

        $idNo = '';
        if ($isAgent) {
            $idNo = AgentWallet::agentCodeLabel($adminId);
        } elseif (!empty($m['staff_no'])) {
            $idNo = 'SHG-S-' . str_pad((string) (int) $m['staff_no'], 4, '0', STR_PAD_LEFT);
        }

        $crew = $isAgent ? '' : self::crewRole($adminId, $m);
        $designation = trim((string) ($m['designation'] ?? ''));
        if ($designation === '') {
            $designation = self::DESIGNATION_DEFAULTS[$isAgent ? 'agent:' . $kind : ($crew !== '' ? $crew : $role)] ?? ucfirst($role);
        }

        // The number on the visiting card: what the member chose, else their
        // WhatsApp, else their login mobile — "jun number halcha, tei number".
        $waRaw = trim((string) ($m['vc_whatsapp'] ?? ''));
        if ($waRaw === '') { $waRaw = trim((string) ($p['whatsapp'] ?? '')); }
        if ($waRaw === '') { $waRaw = trim((string) ($a['phone'] ?? '')); }
        $wa    = self::intlDigits($waRaw, $cc);
        $call  = self::intlDigits(trim((string) ($m['vc_phone'] ?? '')) ?: (string) ($a['phone'] ?? ''), $cc);

        $photo = (string) ($p['photo_path'] ?? '');
        $photoUrl = ($photo !== '' && str_starts_with($photo, 'agents/'))
            ? '/uploads/' . $photo . '?v=' . (int) ($m['photo_ver'] ?? 1) : '';

        $validUntil = (string) ($m['card_valid_until'] ?? '');
        $locked     = !empty($a['locked_until']) && strtotime((string) $a['locked_until']) > time();
        $suspended  = trim((string) ($p['suspended_reason'] ?? '')) !== '';
        $cardStatus = (string) ($m['card_status'] ?? 'active');
        $status = match (true) {
            $cardStatus === 'revoked'                                => 'revoked',
            (int) $a['is_active'] !== 1                              => 'inactive',
            $cardStatus === 'suspended' || $locked || $suspended     => 'suspended',
            $validUntil !== '' && $validUntil < date('Y-m-d')        => 'expired',
            default                                                  => 'valid',
        };

        $company = array_map(static fn($v) => is_string($v) ? self::fixMojibake($v) : $v, Settings::company());
        $greeting = trim((string) ($m['vc_greeting'] ?? ''));
        if ($greeting === '') {
            $greeting = Settings::getString('ap_vc_greeting', 'Namaste! SHG card');
        }

        $isOrg = $isAgent && $kind === 'org';

        return [
            'id'            => (int) $a['id'],
            'username'      => (string) $a['username'],
            'name'          => (string) $a['full_name'],
            'contact'       => $isOrg ? trim((string) ($p['contact_person'] ?? '')) : '',
            'email'         => trim((string) ($m['vc_email'] ?? '')) ?: (trim((string) ($p['display_email'] ?? '')) ?: (string) ($a['email'] ?? '')),
            'phone'         => (string) ($a['phone'] ?? ''),
            'role'          => $role,
            'crewRole'      => $crew,                       // 'driver' / 'conductor' / ''
            'roleKey'       => $crew !== '' ? $crew : $role, // the AgentPro role (crew instead of 'scanner')
            'roleLabel'     => self::ROLE_LABELS[$crew !== '' ? $crew : $role] ?? ucfirst($role),
            'isAgent'       => $isAgent,
            'agentKind'     => $kind,
            'isOrg'         => $isOrg,
            'active'        => (int) $a['is_active'] === 1,
            'idNo'          => $idNo,
            'designation'   => $designation,
            'department'    => (string) ($m['department'] ?? ''),
            'branch'        => trim((string) ($p['counter_name'] ?? '')),
            'address'       => trim((string) ($p['address'] ?? '')),
            'country'       => $cc === '977' ? 'NP' : 'IN',
            'countryCode'   => $cc,
            'dob'           => (string) ($m['dob'] ?? ''),
            'gender'        => (string) ($m['gender'] ?? ''),
            'bloodGroup'    => (string) ($m['blood_group'] ?? ''),
            'guardian'      => (string) ($m['guardian'] ?? ''),
            'emergencyName' => (string) ($m['emergency_name'] ?? ''),
            'emergencyPhone'=> (string) ($m['emergency_phone'] ?? ''),
            'photo'         => $photo,
            'photoUrl'      => $photoUrl,
            'photoVer'      => (int) ($m['photo_ver'] ?? 1),
            'cardToken'     => (string) ($m['card_token'] ?? ''),
            'cardIssued'    => (string) ($m['card_issued_at'] ?? ''),
            'validUntil'    => $validUntil,
            'cardStatus'    => $cardStatus,
            'status'        => $status,
            'wa'            => $wa,
            'waDisplay'     => self::displayPhone($wa),
            'call'          => $call,
            'callDisplay'   => self::displayPhone($call),
            'tagline'       => trim((string) ($m['vc_tagline'] ?? '')) ?: Settings::getString('ap_card_tagline', 'India & Nepal Travel Service'),
            'greeting'      => $greeting,
            'waLink'        => $wa !== '' ? whatsappLink($wa, $greeting) : '',
            'theme'         => (string) ($m['vc_theme'] ?? 'royal'),
            'kycStatus'     => (string) ($p['kyc_status'] ?? 'none'),
            'idType'        => (string) ($p['id_type'] ?? ''),
            'idMasked'      => (string) ($p['id_number'] ?? ''),
            'hasDoc1'       => (string) ($p['kyc_doc_path'] ?? '') !== '',
            'hasDoc2'       => (string) ($p['kyc_doc2_path'] ?? '') !== '',
            'bank'          => [
                'holder' => (string) ($m['bank_holder'] ?? ''),
                'name'   => (string) ($m['bank_name'] ?? ''),
                'last4'  => (string) ($m['bank_account_last4'] ?? ''),
                'ifsc'   => (string) ($m['bank_ifsc'] ?? ''),
                'branch' => (string) ($m['bank_branch'] ?? ''),
                'upi'    => (string) ($m['upi_id'] ?? ''),
                'esewa'  => (string) ($m['esewa_id'] ?? ''),
                'khalti' => (string) ($m['khalti_id'] ?? ''),
            ],
            'lang'          => (string) ($m['lang'] ?? 'ne'),
            'company'       => $company,
            'joined'        => substr((string) ($a['created_at'] ?? ''), 0, 10),
            'lastLogin'     => (string) ($a['last_login_at'] ?? ''),
        ];
    }

    /**
     * The ap_members row, created on first use: a fresh verify token, a
     * staff number for non-agents, issue date + validity.
     *
     * @return array<string, mixed>
     */
    public static function ensureMember(int $adminId, string $role = ''): array
    {
        $row = Database::fetch('SELECT * FROM ap_members WHERE admin_id = :a', ['a' => $adminId]);
        if ($row !== null) {
            if ($role !== '' && $role !== 'agent' && empty($row['staff_no'])) {
                self::assignStaffNo($adminId);
                $row = Database::fetch('SELECT * FROM ap_members WHERE admin_id = :a', ['a' => $adminId]) ?? $row;
            }
            return $row;
        }
        $years = max(1, min(10, Settings::getInt('ap_card_valid_years', 2)));
        for ($try = 0; $try < 3; $try++) {
            try {
                Database::insertIgnore('ap_members', [
                    'admin_id'         => $adminId,
                    'card_token'       => self::newToken(),
                    'card_issued_at'   => date('Y-m-d H:i:s'),
                    'card_valid_until' => date('Y-m-d', strtotime('+' . $years . ' years')),
                ]);
                break;
            } catch (Throwable $e) {
                // a token collision is astronomically rare — try again
            }
        }
        if ($role !== '' && $role !== 'agent') {
            self::assignStaffNo($adminId);
        }
        return Database::fetch('SELECT * FROM ap_members WHERE admin_id = :a', ['a' => $adminId]) ?? [];
    }

    /** Next free SHG-S number. The UNIQUE key settles a race; we retry. */
    private static function assignStaffNo(int $adminId): void
    {
        for ($try = 0; $try < 5; $try++) {
            $next = (int) Database::scalar('SELECT COALESCE(MAX(staff_no), 0) + 1 FROM ap_members', [], 1);
            try {
                Database::update('ap_members', ['staff_no' => $next], 'admin_id = :a AND staff_no IS NULL', ['a' => $adminId]);
                return;
            } catch (Throwable $e) {
                usleep(20000);
            }
        }
    }

    /* =================================================================
     *  30 Oct 2026 — IDs for the office: "issue code" in one tap, and a
     *  member card for a login that already exists (the owner's own
     *  super-admin account: its mobile cannot be used for a new login).
     * ================================================================= */

    /** Give a member without one their agent code (agents) or SHG-S staff number. Returns the ID. */
    public static function issueCode(int $adminId, int $by): string
    {
        $a = Database::fetch('SELECT id, role FROM admins WHERE id = :i', ['i' => $adminId]);
        if ($a === null) {
            throw new RuntimeException('No such member.');
        }
        if ((string) $a['role'] === 'agent') {
            if (AgentWallet::agentCodeFor($adminId) === null) {
                $n = AgentWallet::nextFreeAgentCode(AgentWallet::agentKindFor($adminId));
                if ($n === null) {
                    throw new RuntimeException('Every agent number is taken.');
                }
                AgentWallet::setAgentCode($adminId, $n, $by);
            }
            $code = AgentWallet::agentCodeLabel($adminId);
        } else {
            self::ensureMember($adminId, (string) $a['role']);
            $code = (string) (self::member($adminId)['idNo'] ?? '');
        }
        Logger::audit('agentpro.code_issued', 'admin', (string) $adminId, null, ['code' => $code], 'ID issued by admin #' . $by);
        return $code;
    }

    /**
     * An ID card for a login that already exists (any role, the super-admin
     * too): makes the member row (card token, validity, staff number) and
     * saves the name / designation / department the card prints.
     *
     * @param array<string, mixed> $in full_name, designation, department
     * @return array<string, mixed> the member
     */
    public static function makeCardFor(int $adminId, array $in, int $by): array
    {
        $a = Database::fetch('SELECT id, role, full_name FROM admins WHERE id = :i', ['i' => $adminId]);
        if ($a === null) {
            throw new RuntimeException('Choose the login to make the card for.');
        }
        self::ensureMember($adminId, (string) $a['role']);
        $name = trim(Security::clean((string) ($in['full_name'] ?? ''), 120));
        if ($name !== '' && mb_strlen($name) < 2) {
            throw new RuntimeException('Write the full name as it should print on the card.');
        }
        if ($name !== '' && $name !== (string) $a['full_name']) {
            Database::update('admins', ['full_name' => $name], 'id = :i', ['i' => $adminId]);
            Logger::audit('agentpro.card_name', 'admin', (string) $adminId, ['full_name' => (string) $a['full_name']], ['full_name' => $name],
                'Name on the card changed by admin #' . $by);
        }
        $card = array_intersect_key($in, array_flip(['designation', 'department']));
        if ($card !== []) {
            self::saveCard($adminId, $card, $by);
        }
        Logger::audit('agentpro.card_for_login', 'admin', (string) $adminId, null, ['role' => (string) $a['role']],
            'ID card made for an existing login by admin #' . $by);
        return self::member($adminId) ?? [];
    }

    /* =================================================================
     *  Bus crew (driver / conductor) — the driver app account
     * ================================================================= */

    /** @var array<int, string> */
    private static array $crewCache = [];

    /**
     * 'driver' / 'conductor' when this member is bus crew, else ''.
     * ap_members.crew_role when the agentpro2 migration ran; before that, the
     * role of their join application (a crew login is always CREW_LOGIN_ROLE).
     *
     * @param array<string, mixed>|null $memberRow the ap_members row when the caller has it
     */
    public static function crewRole(int $adminId, ?array $memberRow = null): string
    {
        if ($adminId <= 0) {
            return '';
        }
        if (isset(self::$crewCache[$adminId])) {
            return self::$crewCache[$adminId];
        }
        $crew = '';
        try {
            if (self::memberHas('crew_role')) {
                $v = $memberRow !== null && array_key_exists('crew_role', $memberRow)
                    ? $memberRow['crew_role']
                    : Database::scalar('SELECT crew_role FROM ap_members WHERE admin_id = :a', ['a' => $adminId]);
                $crew = in_array((string) $v, self::CREW_ROLES, true) ? (string) $v : '';
            } elseif (self::available()) {
                $v = Database::scalar(
                    "SELECT ap.role_wanted FROM ap_applications ap JOIN admins a ON a.id = ap.admin_id
                      WHERE ap.admin_id = :a AND ap.status IN ('submitted','approved') AND a.role = :r
                      ORDER BY ap.id DESC LIMIT 1",
                    ['a' => $adminId, 'r' => self::CREW_LOGIN_ROLE]
                );
                $crew = in_array((string) $v, self::CREW_ROLES, true) ? (string) $v : '';
            }
        } catch (Throwable $e) {
            $crew = '';
        }
        return self::$crewCache[$adminId] = $crew;
    }

    /** Mark (or unmark, '') a staff member as bus crew. Needs the agentpro2 migration. */
    public static function setCrewRole(int $adminId, string $crew, int $by = 0): void
    {
        if ($crew !== '' && !in_array($crew, self::CREW_ROLES, true)) {
            throw new RuntimeException('Choose driver or conductor.');
        }
        unset(self::$crewCache[$adminId]);
        if (!self::memberHas('crew_role')) {
            return;   // the application role is read instead (crewRole fallback)
        }
        $role = (string) Database::scalar('SELECT role FROM admins WHERE id = :a', ['a' => $adminId], '');
        self::ensureMember($adminId, $role);
        Database::update('ap_members', ['crew_role' => $crew !== '' ? $crew : null], 'admin_id = :a', ['a' => $adminId]);
        if ($by > 0) {
            Logger::audit('agentpro.crew_role', 'admin', (string) $adminId, null, ['crew' => $crew ?: null], 'Bus crew role set by admin #' . $by);
        }
    }

    /**
     * The member's driver-app account (drivers row): the linked id, else the
     * drivers row with the same mobile. Null when there is none.
     *
     * @return array{id:int, full_name:string, phone:string, role:string, is_active:int, has_pin:bool}|null
     */
    public static function driverRow(int $adminId): ?array
    {
        $row = null;
        try {
            if (self::memberHas('driver_id')) {
                $did = (int) Database::scalar('SELECT driver_id FROM ap_members WHERE admin_id = :a', ['a' => $adminId], 0);
                if ($did > 0) {
                    $row = Database::fetch('SELECT id, full_name, phone, role, is_active, pin_hash FROM drivers WHERE id = :i', ['i' => $did]);
                }
            }
            if ($row === null) {
                $phone = normalisePhone((string) Database::scalar('SELECT phone FROM admins WHERE id = :a', ['a' => $adminId], ''));
                if ($phone !== '') {
                    $row = Database::fetch('SELECT id, full_name, phone, role, is_active, pin_hash FROM drivers WHERE phone = :p', ['p' => $phone]);
                }
            }
        } catch (Throwable $e) {
            return null;   // no drivers table on this database
        }
        if ($row === null) {
            return null;
        }
        return ['id' => (int) $row['id'], 'full_name' => (string) $row['full_name'], 'phone' => (string) $row['phone'],
                'role' => (string) $row['role'], 'is_active' => (int) $row['is_active'], 'has_pin' => !empty($row['pin_hash'])];
    }

    /** A 6-digit PIN that is not 000000 / 123456-like. */
    public static function newDriverPin(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (preg_match('/^(\d)\1{5}$/', $pin) === 1 || in_array($pin, ['123456', '654321', '012345', '543210'], true));
        return $pin;
    }

    /**
     * Give a crew member their driver-app account: link (or make) the drivers
     * row of their mobile, keep its role in step (a co-driver stays co-driver,
     * a host stays host) and set a PIN when it has none — or always with
     * $newPin (that signs every phone of theirs out).
     *
     * $activate (the office): switch a deactivated drivers row back on.
     * Without it (the member's own "Start bus location") a row the office
     * switched off stays off and this refuses.
     *
     * @return array{driverId:int, pin:?string, created:bool}
     */
    public static function linkDriver(int $adminId, int $by, bool $activate = true, bool $newPin = false): array
    {
        $crew = self::crewRole($adminId);
        if ($crew === '') {
            throw new RuntimeException('Only a driver or conductor gets the driver app.');
        }
        $a = Database::fetch('SELECT id, full_name, phone, is_active FROM admins WHERE id = :i', ['i' => $adminId]);
        $phone = normalisePhone((string) ($a['phone'] ?? ''));
        if ($a === null || $phone === '') {
            throw new RuntimeException('Add the mobile number first — the driver app signs in with it.');
        }
        require_once __DIR__ . '/driverauth.php';
        $row = self::driverRow($adminId);
        $created = false;
        if ($row === null) {
            try {
                $id = Database::insert('drivers', [
                    'full_name' => mb_substr((string) $a['full_name'], 0, 120), 'phone' => $phone, 'role' => $crew, 'is_active' => 1,
                ]);
            } catch (Throwable $e) {
                // the same mobile was added in Admin → Drivers a moment ago: use that row
                $id = (int) Database::scalar('SELECT id FROM drivers WHERE phone = :p', ['p' => $phone], 0);
                if ($id <= 0) {
                    throw new RuntimeException('Could not make the driver-app account: ' . $e->getMessage());
                }
            }
            $created = true;
            $hasPin = (bool) Database::scalar('SELECT pin_hash IS NOT NULL FROM drivers WHERE id = :i', ['i' => (int) $id], 0);
            $row = ['id' => (int) $id, 'is_active' => 1, 'has_pin' => $hasPin, 'role' => $crew];
        } else {
            if ((int) $row['is_active'] !== 1 && !$activate) {
                throw new RuntimeException('The office has switched off your driver app. Call the office. · अफिसले ड्राइभर एप बन्द गरेको छ।');
            }
            $keep = $crew === 'driver' ? ['driver', 'co-driver'] : ['conductor', 'host'];
            $upd = [];
            if (!in_array($row['role'], $keep, true)) { $upd['role'] = $crew; }
            if ($activate && (int) $row['is_active'] !== 1) { $upd['is_active'] = 1; }
            if ($activate && trim((string) $a['full_name']) !== '' && $row['full_name'] !== (string) $a['full_name']) {
                $upd['full_name'] = mb_substr((string) $a['full_name'], 0, 120);
            }
            if ($upd !== []) {
                Database::update('drivers', $upd, 'id = :i', ['i' => (int) $row['id']]);
            }
        }
        $driverId = (int) $row['id'];
        $pin = null;
        if ($newPin || empty($row['has_pin'])) {
            $pin = self::newDriverPin();
            DriverAuth::setPin($driverId, $pin);
        }
        if (self::memberHas('driver_id')) {
            Database::update('ap_members', ['driver_id' => $driverId], 'admin_id = :a', ['a' => $adminId]);
        }
        Logger::audit('agentpro.driver_link', 'admin', (string) $adminId, null,
            ['driver' => $driverId, 'crew' => $crew, 'created' => $created, 'pin' => $pin !== null],
            'Driver app account ' . ($created ? 'made' : 'linked') . ($by > 0 ? ' by admin #' . $by : ''));
        return ['driverId' => $driverId, 'pin' => $pin, 'created' => $created];
    }

    /** The driver-app sign-in on WhatsApp (mobile + PIN), like the staff password. */
    public static function sendDriverPin(int $adminId, string $pin): bool
    {
        $mem = self::member($adminId, false);
        if ($mem === null || $mem['phone'] === '') {
            return false;
        }
        require_once __DIR__ . '/notify.php';
        $ok = Notify::whatsapp((string) $mem['phone'], implode("\n", self::driverPinLines($mem, $pin)), null, $mem['countryCode'] === '977' ? 'NP' : 'IN');
        return $ok !== false;
    }

    /**
     * @param array<string, mixed> $mem
     * @return list<string>
     */
    private static function driverPinLines(array $mem, string $pin): array
    {
        return [
            '🚌 बस लोकेसन एप · SHG Driver app',
            '📱 ' . appUrl('driver/'),
            '👤 मोबाइल / Mobile: ' . $mem['phone'],
            '🔑 PIN: ' . $pin,
            'बस छुट्दा "START TRIP" थिच्नुहोस्, स्क्रिन खुला र फोन चार्जरमा राख्नुहोस्। / Tap START TRIP when the bus leaves.',
            '(/me/ मा "🚌 बस लोकेसन सुरु" बटनले PIN बिना नै खोल्छ।)',
        ];
    }

    /**
     * What a member's portal (/me/) shows for their role: the wallet only for
     * those who hold money (agents, counters, or anyone with ledger entries),
     * and links to the work pages their permissions open.
     * Call with the member signed in (Auth::can reads the session).
     *
     * @param array<string, mixed> $m member()
     * @return array{walletOn: bool, tools: list<array{icon:string, ne:string, en:string, href:string}>, crew: array<string, mixed>|null}
     */
    public static function portal(array $m): array
    {
        $id = (int) $m['id'];
        $hasLedger = false;
        try {
            $hasLedger = Database::exists('SELECT 1 FROM agent_ledger WHERE agent_admin_id = :a LIMIT 1', ['a' => $id]);
        } catch (Throwable $e) {
        }
        $walletOn = !empty($m['isAgent']) || $m['role'] === 'counter' || $hasLedger;
        $tools = [];
        $add = static function (string $icon, string $ne, string $en, string $href) use (&$tools): void {
            $tools[] = ['icon' => $icon, 'ne' => $ne, 'en' => $en, 'href' => $href];
        };
        if (self::mustChangePw(Auth::admin())) {
            $add('🔑', 'अस्थायी पासवर्ड बदल्नुहोस्', 'Change your temporary password', '/admin/change-password.php');
        }
        if (!empty($m['isAgent'])) {
            $add('🎫', 'टिकट बुक', 'Book a ticket', '/admin/agent.php');
        } elseif (Auth::can('bookings.view')) {
            $add('🎫', 'टिकट बुक · SmartBook', 'Book a ticket · SmartBook', '/admin/smartbook.php');
            $add('📋', 'टिकट रजिस्टर', 'Tickets register', '/admin/bookings.php');
            $add('🧾', 'मेरो डेस्क / सिफ्ट', 'My desk & shift', '/admin/my-desk.php');
        }
        if (Auth::can('tickets.scan')) {
            $add('📷', 'टिकट स्क्यान (बोर्डिङ)', 'Scan tickets (boarding)', '/admin/scan.php');
        }
        if (Auth::can('payments.view')) {
            $add('💳', 'भुक्तानी जाँच', 'Verify payments', '/admin/payments.php');
        }
        if (Auth::can('support.view')) {
            $add('💬', 'ग्राहक सेवा', 'Support inbox', '/admin/support-inbox.php');
        }
        if (Auth::can('schedules.view')) {
            $add('🗺', 'बस लाइभ नक्सा', 'Live bus map', '/admin/map.php');
        }
        if (Auth::can('staff.manage')) {
            $add('👥', 'टिम र ID कार्ड', 'Team & ID cards', '/admin/team.php');
            $add('＋', 'नयाँ सदस्य थप्नुहोस्', 'Add a member', '/join.php?office=1');
        }
        if (Auth::can('dashboard.view')) {
            $add('🏢', 'एडमिन प्यानल', 'Admin panel', '/admin/index.php');
        }
        $crew = null;
        if (($m['crewRole'] ?? '') !== '') {
            $d = self::driverRow($id);
            $crew = ['role' => (string) $m['crewRole'], 'linked' => $d !== null, 'active' => $d !== null && $d['is_active'] === 1,
                     'app' => '/driver/'];
        }
        return ['walletOn' => $walletOn, 'tools' => $tools, 'crew' => $crew];
    }

    /** 24 url-safe characters. */
    public static function newToken(): string
    {
        return substr(strtr(base64_encode(random_bytes(18)), '+/', '-_'), 0, 24);
    }

    /** Public reference like JN-7KQ2M. */
    public static function newRef(string $prefix = 'JN'): string
    {
        $s = '';
        for ($i = 0; $i < 5; $i++) {
            $s .= self::REF_ALPHABET[random_int(0, strlen(self::REF_ALPHABET) - 1)];
        }
        return $prefix . '-' . $s;
    }

    /**
     * Save what the cards print and the member's own details.
     *
     * @param array<string, mixed> $in
     */
    public static function saveCard(int $adminId, array $in, int $by = 0): void
    {
        $admin = Database::fetch('SELECT role FROM admins WHERE id = :a', ['a' => $adminId]);
        if ($admin === null) {
            throw new RuntimeException('No such member.');
        }
        self::ensureMember($adminId, (string) $admin['role']);

        $u = [];
        $text = static fn(string $k, int $max) => Security::clean((string) $in[$k], $max);
        foreach (['designation' => 80, 'department' => 80, 'guardian' => 120, 'emergency_name' => 120,
                  'vc_tagline' => 120, 'vc_greeting' => 160] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $v = $text($k, $max);
                $u[$k] = $v !== '' ? $v : null;
            }
        }
        foreach (['vc_whatsapp', 'vc_phone', 'emergency_phone'] as $k) {
            if (array_key_exists($k, $in)) {
                $raw = trim((string) $in[$k]);
                $d = preg_replace('/\D/', '', $raw) ?? '';
                if ($raw !== '' && (strlen($d) < 8 || strlen($d) > 15)) {
                    throw new RuntimeException('Enter a valid phone number (with +91 or +977 for another country).');
                }
                $u[$k] = $d !== '' ? (str_starts_with($raw, '+') ? '+' . $d : $d) : null;
            }
        }
        if (array_key_exists('vc_email', $in)) {
            $e = Security::email((string) $in['vc_email']);
            $u['vc_email'] = $e !== '' ? $e : null;
        }
        if (array_key_exists('dob', $in)) {
            $d = trim((string) $in['dob']);
            $u['dob'] = ($d !== '' && Security::isValidDate($d) && $d < date('Y-m-d')) ? $d : null;
        }
        if (array_key_exists('gender', $in)) {
            $g = ucfirst(strtolower(trim((string) $in['gender'])));   // 'male' / 'MALE' from the paper-form reader too
            $u['gender'] = in_array($g, ['Male', 'Female', 'Other'], true) ? $g : null;
        }
        if (array_key_exists('blood_group', $in)) {
            $b = strtoupper(trim((string) $in['blood_group']));
            $u['blood_group'] = in_array($b, self::BLOOD_GROUPS, true) ? $b : null;
        }
        if (array_key_exists('vc_theme', $in)) {
            $t = (string) $in['vc_theme'];
            $u['vc_theme'] = in_array($t, ['royal', 'gold', 'night'], true) ? $t : 'royal';
        }
        if (array_key_exists('lang', $in)) {
            $l = (string) $in['lang'];
            $u['lang'] = in_array($l, ['ne', 'hi', 'en'], true) ? $l : 'ne';
        }
        if (array_key_exists('card_valid_until', $in)) {
            $d = trim((string) $in['card_valid_until']);
            if ($d !== '' && Security::isValidDate($d)) {
                $u['card_valid_until'] = $d;
            }
        }
        if ($u === []) {
            return;
        }
        Database::update('ap_members', $u, 'admin_id = :a', ['a' => $adminId]);
        Logger::audit('agentpro.card_saved', 'admin', (string) $adminId, null, array_keys($u) === [] ? null : ['fields' => array_keys($u)],
            'Card details updated' . ($by > 0 ? ' by admin #' . $by : ''));
    }

    /**
     * Payout details. The account number is encrypted; only its last four
     * are kept in clear for screens and WhatsApp.
     *
     * @param array<string, mixed> $in
     */
    public static function saveBank(int $adminId, array $in, int $by = 0): void
    {
        $admin = Database::fetch('SELECT role FROM admins WHERE id = :a', ['a' => $adminId]);
        if ($admin === null) {
            throw new RuntimeException('No such member.');
        }
        self::ensureMember($adminId, (string) $admin['role']);
        $u = self::bankColumns($in);
        if ($u === []) {
            return;
        }
        Database::update('ap_members', $u, 'admin_id = :a', ['a' => $adminId]);
        // The office's payout screens read admin_profiles.payout_method / account.
        $method = !empty($u['upi_id']) ? 'upi' : (!empty($u['bank_account_enc']) ? 'bank' : (!empty($u['esewa_id']) ? 'esewa' : ''));
        if ($method !== '') {
            $acct = match ($method) {
                'upi'   => (string) $u['upi_id'],
                'bank'  => trim(($u['bank_name'] ?? '') . ' ••••' . ($u['bank_account_last4'] ?? '') . ' ' . ($u['bank_ifsc'] ?? '')),
                default => 'eSewa ' . (string) $u['esewa_id'],
            };
            AgentWallet::saveProfile($adminId, ['payout_method' => $method, 'payout_account' => $acct]);
        }
        Logger::audit('agentpro.bank_saved', 'admin', (string) $adminId, null, ['fields' => array_keys($u)],
            'Payout details updated' . ($by > 0 ? ' by admin #' . $by : ''));
    }

    /**
     * Clean bank / wallet input into ap_* columns (shared by members and applications).
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public static function bankColumns(array $in): array
    {
        $u = [];
        foreach (['bank_holder' => 120, 'bank_name' => 120, 'bank_branch' => 120] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $v = Security::clean((string) $in[$k], $max);
                $u[$k] = $v !== '' ? $v : null;
            }
        }
        if (array_key_exists('bank_account', $in)) {
            $acct = preg_replace('/[^0-9A-Za-z]/', '', (string) $in['bank_account']) ?? '';
            if ($acct !== '' && (strlen($acct) < 6 || strlen($acct) > 24)) {
                throw new RuntimeException('The bank account number looks wrong (6–24 characters).');
            }
            if ($acct !== '') {
                $u['bank_account_enc']   = self::encrypt($acct);
                $u['bank_account_last4'] = substr($acct, -4);
            }
        }
        if (array_key_exists('bank_ifsc', $in)) {
            $ifsc = strtoupper(preg_replace('/\s+/', '', (string) $in['bank_ifsc']) ?? '');
            if ($ifsc !== '' && preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc) !== 1 && preg_match('/^[A-Z0-9]{3,15}$/', $ifsc) !== 1) {
                throw new RuntimeException('The IFSC / SWIFT code looks wrong.');
            }
            $u['bank_ifsc'] = $ifsc !== '' ? $ifsc : null;
            if ($ifsc !== '' && empty($in['bank_name']) && ($bank = self::bankFromIfsc($ifsc)) !== '') {
                $u['bank_name'] = $bank;
            }
        }
        if (array_key_exists('upi_id', $in)) {
            $upi = strtolower(trim((string) $in['upi_id']));
            // A bare 10-digit Indian mobile is accepted too: UPI apps pay "to mobile number"
            // (the paper form's "UPI / eSewa / Khalti" box usually holds just the number).
            if ($upi !== '' && preg_match('/^[a-z0-9._-]{2,64}@[a-z][a-z0-9.-]{1,30}$/', $upi) !== 1 && preg_match('/^[6-9]\d{9}$/', $upi) !== 1) {
                throw new RuntimeException('The UPI ID looks wrong (like name@okaxis).');
            }
            $u['upi_id'] = $upi !== '' ? $upi : null;
        }
        foreach (['esewa_id', 'khalti_id'] as $k) {
            if (array_key_exists($k, $in)) {
                $v = preg_replace('/[^0-9A-Za-z@._-]/', '', (string) $in[$k]) ?? '';
                $u[$k] = $v !== '' ? substr($v, 0, 40) : null;
            }
        }
        return $u;
    }

    /** The bank behind the first four letters of an Indian IFSC (the common ones). */
    public static function bankFromIfsc(string $ifsc): string
    {
        static $banks = [
            'SBIN' => 'State Bank of India', 'HDFC' => 'HDFC Bank', 'ICIC' => 'ICICI Bank', 'UTIB' => 'Axis Bank',
            'PUNB' => 'Punjab National Bank', 'BARB' => 'Bank of Baroda', 'CNRB' => 'Canara Bank', 'UBIN' => 'Union Bank of India',
            'KKBK' => 'Kotak Mahindra Bank', 'IDIB' => 'Indian Bank', 'BKID' => 'Bank of India', 'MAHB' => 'Bank of Maharashtra',
            'CBIN' => 'Central Bank of India', 'IOBA' => 'Indian Overseas Bank', 'UCBA' => 'UCO Bank', 'PSIB' => 'Punjab & Sind Bank',
            'YESB' => 'Yes Bank', 'INDB' => 'IndusInd Bank', 'IDFB' => 'IDFC First Bank', 'FDRL' => 'Federal Bank',
            'AUBL' => 'AU Small Finance Bank', 'RATN' => 'RBL Bank', 'KARB' => 'Karnataka Bank', 'SIBL' => 'South Indian Bank',
            'PYTM' => 'Paytm Payments Bank', 'AIRP' => 'Airtel Payments Bank', 'IPOS' => 'India Post Payments Bank',
            'GSCB' => 'Gujarat State Co-op Bank', 'DCBL' => 'DCB Bank', 'JAKA' => 'J&K Bank', 'TMBL' => 'Tamilnad Mercantile Bank',
        ];
        return $banks[substr(strtoupper($ifsc), 0, 4)] ?? '';
    }

    /** Full account number, for the office's payout screen only. */
    public static function bankAccount(int $adminId): string
    {
        $enc = (string) Database::scalar('SELECT bank_account_enc FROM ap_members WHERE admin_id = :a', ['a' => $adminId], '');
        return self::decrypt($enc);
    }

    /**
     * Re-issue the card: a new verify token (every old printed card now
     * scans as REVOKED), a fresh issue date and validity.
     */
    public static function reissueCard(int $adminId, int $by = 0): void
    {
        $admin = Database::fetch('SELECT role FROM admins WHERE id = :a', ['a' => $adminId]);
        if ($admin === null) {
            throw new RuntimeException('No such member.');
        }
        self::ensureMember($adminId, (string) $admin['role']);
        $years = max(1, min(10, Settings::getInt('ap_card_valid_years', 2)));
        Database::update('ap_members', [
            'card_token'       => self::newToken(),
            'card_issued_at'   => date('Y-m-d H:i:s'),
            'card_valid_until' => date('Y-m-d', strtotime('+' . $years . ' years')),
            'card_status'      => 'active',
        ], 'admin_id = :a', ['a' => $adminId]);
        Logger::audit('agentpro.card_reissued', 'admin', (string) $adminId, null, null,
            'ID card re-issued (old card QR now reads revoked)' . ($by > 0 ? ' by admin #' . $by : ''));
    }

    public static function setCardStatus(int $adminId, string $status, int $by = 0): void
    {
        if (!in_array($status, ['active', 'suspended', 'revoked'], true)) {
            throw new RuntimeException('Unknown card status.');
        }
        $admin = Database::fetch('SELECT role FROM admins WHERE id = :a', ['a' => $adminId]);
        if ($admin === null) {
            throw new RuntimeException('No such member.');
        }
        self::ensureMember($adminId, (string) $admin['role']);
        Database::update('ap_members', ['card_status' => $status], 'admin_id = :a', ['a' => $adminId]);
        Logger::audit('agentpro.card_' . $status, 'admin', (string) $adminId, null, ['card_status' => $status],
            'ID card ' . $status . ($by > 0 ? ' by admin #' . $by : ''));
    }

    /** The member behind a card token (verify page, digital card). */
    public static function memberByToken(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{24}$/', $token) !== 1 || !self::available()) {
            return null;
        }
        $id = (int) Database::scalar('SELECT admin_id FROM ap_members WHERE card_token = :t', ['t' => $token], 0);
        return $id > 0 ? self::member($id, false) : null;
    }

    /**
     * Store a new profile photo (= the ID card photo). Square-cropped,
     * re-encoded (no EXIF / GPS), 640 px. Works from an upload or from a
     * private file already on disk (an approved applicant's selfie).
     */
    public static function setPhotoFromFile(int $adminId, string $absPath, int $by = 0): string
    {
        $img = self::loadImage($absPath);
        if ($img === null) {
            throw new RuntimeException('That photo could not be read — use a JPG or PNG.');
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $side = min($w, $h);
        // A little above centre: faces sit in the upper half of a selfie.
        $sx = (int) (($w - $side) / 2);
        $sy = (int) max(0, min($h - $side, ($h - $side) * 0.35));
        $out = imagecreatetruecolor(640, 640);
        imagecopyresampled($out, $img, 0, 0, $sx, $sy, 640, 640, $side, $side);
        imagedestroy($img);

        $dir = UPLOAD_PATH . '/agents';
        ensureDir($dir);
        $file = Security::safeFilename('jpg');
        if (!imagejpeg($out, $dir . '/' . $file, 88)) {
            imagedestroy($out);
            throw new RuntimeException('Could not save the photo.');
        }
        imagedestroy($out);

        $old = (string) (AgentWallet::profile($adminId)['photo_path'] ?? '');
        AgentWallet::saveProfile($adminId, ['photo_path' => 'agents/' . $file]);
        if ($old !== '' && str_starts_with($old, 'agents/') && !str_contains($old, '..')) {
            @unlink(UPLOAD_PATH . '/' . $old);
        }
        if (self::available()) {
            $admin = Database::fetch('SELECT role FROM admins WHERE id = :a', ['a' => $adminId]);
            self::ensureMember($adminId, (string) ($admin['role'] ?? ''));
            Database::run('UPDATE ap_members SET photo_ver = photo_ver + 1 WHERE admin_id = :a', ['a' => $adminId]);
        }
        Logger::audit('agentpro.photo', 'admin', (string) $adminId, null, null,
            'Profile / ID photo changed' . ($by > 0 ? ' by admin #' . $by : ''));
        return 'agents/' . $file;
    }

    /** @param array<string, mixed> $file a $_FILES entry */
    public static function setPhoto(int $adminId, array $file, int $by = 0): string
    {
        $check = Security::validateUpload($file);
        if (!$check['ok']) {
            throw new RuntimeException((string) ($check['error'] ?? 'Upload failed.'));
        }
        if (!str_starts_with((string) ($check['mime'] ?? ''), 'image/')) {
            throw new RuntimeException('The photo must be a JPG, PNG or WEBP image.');
        }
        return self::setPhotoFromFile($adminId, (string) $file['tmp_name'], $by);
    }

    /** GD image from a JPG / PNG / WEBP file, upright (EXIF orientation applied). */
    public static function loadImage(string $abs): ?GdImage
    {
        $info = @getimagesize($abs);
        if ($info === false) {
            return null;
        }
        $img = match ((int) $info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($abs),
            IMAGETYPE_PNG  => @imagecreatefrompng($abs),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($abs) : false,
            default        => false,
        };
        if (!$img instanceof GdImage) {
            return null;
        }
        if ((int) $info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($abs);
            $rot = match ((int) ($exif['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if ($rot !== 0) {
                $r = imagerotate($img, $rot, 0);
                if ($r instanceof GdImage) {
                    imagedestroy($img);
                    $img = $r;
                }
            }
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        return $img;
    }

    /* =================================================================
     *  Private documents (uploads/agents-kyc/, never a public URL)
     * ================================================================= */

    /**
     * Validate and store one uploaded document privately. Images are
     * re-encoded (upright, ≤ 2200 px, EXIF stripped); a PDF is kept as is.
     *
     * @param array<string, mixed> $file
     * @return array{path: string, abs: string, mime: string}
     */
    public static function storePrivate(array $file, bool $imageOnly = false): array
    {
        $check = Security::validateUpload($file);
        if (!$check['ok']) {
            throw new RuntimeException((string) ($check['error'] ?? 'Upload failed.'));
        }
        $mime = (string) ($check['mime'] ?? '');
        if ($imageOnly && !str_starts_with($mime, 'image/')) {
            throw new RuntimeException('Please send a photo (JPG or PNG), not a PDF.');
        }
        $dir = UPLOAD_PATH . '/agents-kyc';
        ensureDir($dir);
        if (str_starts_with($mime, 'image/')) {
            $img = self::loadImage((string) $file['tmp_name']);
            if ($img === null) {
                throw new RuntimeException('That image could not be read.');
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $scale = min(1.0, self::DOC_MAX_EDGE / max($w, $h));
            if ($scale < 1.0) {
                $nw = max(1, (int) round($w * $scale));
                $nh = max(1, (int) round($h * $scale));
                $small = imagecreatetruecolor($nw, $nh);
                imagecopyresampled($small, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $small;
            }
            $name = Security::safeFilename('jpg');
            if (!imagejpeg($img, $dir . '/' . $name, 90)) {
                imagedestroy($img);
                throw new RuntimeException('Could not save the document.');
            }
            imagedestroy($img);
            return ['path' => 'agents-kyc/' . $name, 'abs' => $dir . '/' . $name, 'mime' => 'image/jpeg'];
        }
        $name = Security::safeFilename('pdf');
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            throw new RuntimeException('Could not save the document.');
        }
        return ['path' => 'agents-kyc/' . $name, 'abs' => $dir . '/' . $name, 'mime' => 'application/pdf'];
    }

    /** Absolute path of a stored private document, or null if the path is not one of ours. */
    public static function privateAbs(string $rel): ?string
    {
        if ($rel === '' || !str_starts_with($rel, 'agents-kyc/') || str_contains($rel, '..') || str_contains($rel, "\0")) {
            return null;
        }
        $dir = realpath(UPLOAD_PATH . '/agents-kyc');
        $abs = realpath(UPLOAD_PATH . '/' . $rel);
        if ($dir === false || $abs === false || !is_file($abs) || !str_starts_with($abs, $dir . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $abs;
    }

    /* =================================================================
     *  Applications (the join form)
     * ================================================================= */

    /**
     * Start a draft. A staff invite fixes the kind and role.
     *
     * @return array<string, mixed>
     */
    public static function newDraft(string $inviteToken = '', int $officeBy = 0): array
    {
        $invite = $inviteToken !== '' ? self::invite($inviteToken) : null;
        if ($inviteToken !== '' && $invite === null) {
            throw new RuntimeException('This invitation link has expired or was already used. Ask the office for a new one.');
        }
        // The office (join.php?office=1) fills forms even while the public form is closed.
        if ($invite === null && $officeBy <= 0 && !Settings::getBool('ap_join_open', true)) {
            throw new RuntimeException('New agent applications are closed right now. Please call the office.');
        }
        $key = bin2hex(random_bytes(16));
        for ($try = 0; $try < 5; $try++) {
            try {
                Database::insert('ap_applications', [
                    'ref'         => self::newRef(),
                    'draft_key'   => $key,
                    'kind'        => $invite !== null && $invite['role'] !== 'agent' ? 'staff' : 'agent',
                    'role_wanted' => $invite['role'] ?? null,
                    'designation' => $invite['designation'] ?? null,
                    'invite_id'   => $invite['id'] ?? null,
                    'ip'          => Security::clientIp(),
                    'user_agent'  => Security::userAgent(),
                ] + ($officeBy > 0 && self::appHas('office_filled_by') ? ['office_filled_by' => $officeBy] : []));
                break;
            } catch (Throwable $e) {
                if ($try === 4) {
                    throw $e;
                }
            }
        }
        return self::draft($key) ?? [];
    }

    /** @return array<string, mixed>|null */
    public static function draft(string $key): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $key) !== 1) {
            return null;
        }
        return Database::fetch('SELECT * FROM ap_applications WHERE draft_key = :k', ['k' => $key]);
    }

    /** @return array<string, mixed>|null */
    public static function application(int $id): ?array
    {
        return Database::fetch('SELECT * FROM ap_applications WHERE id = :i', ['i' => $id]);
    }

    /**
     * Save the typed fields of a draft. Only a draft can change; a
     * verified phone can only be changed by verifying the new one.
     *
     * @param array<string, mixed> $in
     */
    public static function saveDraft(string $key, array $in, bool $office = false): array
    {
        $app = self::editable($key, $office);
        $u = [];
        $text = ['full_name' => 120, 'org_name' => 120, 'guardian' => 120, 'address' => 255, 'city' => 80,
                 'district' => 80, 'state' => 80];
        foreach (self::EXTRA_FIELDS as $k => $max) {
            if (self::appHas($k)) {
                $text[$k] = $max;
            }
        }
        if ($office) {
            $text['designation'] = 80;   // printed under the name; the office decides it
        }
        foreach ($text as $k => $max) {
            if (array_key_exists($k, $in)) {
                $v = Security::clean((string) $in[$k], $max);
                $u[$k] = $v !== '' ? $v : null;
            }
        }
        if (array_key_exists('pan', $in) && self::appHas('pan')) {
            // the PAN box of the paper form — separate from the ID used (often Aadhaar)
            $p = strtoupper(preg_replace('/[\s.\-]/', '', (string) $in['pan']) ?? '');
            if ($p !== '' && preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $p) !== 1) {
                throw new RuntimeException('A PAN looks like ABCDE1234F.');
            }
            $u['pan'] = $p !== '' ? $p : null;
        }
        if (array_key_exists('agent_kind', $in) && $app['kind'] === 'agent') {
            $u['agent_kind'] = AgentWallet::normaliseKind((string) $in['agent_kind']);
        }
        if (array_key_exists('pincode', $in)) {
            $pin = preg_replace('/\D/', '', (string) $in['pincode']) ?? '';
            $u['pincode'] = $pin !== '' ? substr($pin, 0, 10) : null;
        }
        if (array_key_exists('country', $in)) {
            $c = strtoupper((string) $in['country']);
            $u['country'] = in_array($c, ['IN', 'NP'], true) ? $c : null;
        }
        if (array_key_exists('email', $in)) {
            $e = Security::email((string) $in['email']);
            if (trim((string) $in['email']) !== '' && $e === '') {
                throw new RuntimeException('That email address looks wrong.');
            }
            $u['email'] = $e !== '' ? $e : null;
        }
        if (array_key_exists('dob', $in)) {
            $d = trim((string) $in['dob']);
            if ($d !== '' && (!Security::isValidDate($d) || $d > date('Y-m-d', strtotime('-16 years')) || $d < '1930-01-01')) {
                throw new RuntimeException('Check the date of birth (you must be at least 16).');
            }
            $u['dob'] = $d !== '' ? $d : null;
        }
        if (array_key_exists('gender', $in)) {
            $g = ucfirst(strtolower(trim((string) $in['gender'])));   // 'male' / 'MALE' from the paper-form reader too
            $u['gender'] = in_array($g, ['Male', 'Female', 'Other'], true) ? $g : null;
        }
        if (array_key_exists('id_type', $in)) {
            $t = (string) $in['id_type'];
            $u['id_type'] = isset(self::ID_TYPES[$t]) ? $t : null;
        }
        if (array_key_exists('id_number', $in)) {
            $type = (string) ($u['id_type'] ?? $app['id_type'] ?? '');
            $num  = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $in['id_number']) ?? ''));
            $num  = mb_substr(Security::clean($num, 40), 0, 40);
            if ($num !== '') {
                if ($type === 'aadhaar' && preg_match('/^\d{12}$/', preg_replace('/\D/', '', $num) ?? '') !== 1) {
                    throw new RuntimeException('An Aadhaar number has 12 digits.');
                }
                if ($type === 'pan' && preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', str_replace(' ', '', $num)) !== 1) {
                    throw new RuntimeException('A PAN looks like ABCDE1234F.');
                }
                $keepFull = $type !== 'aadhaar' || Settings::getBool('ap_aadhaar_store_full', false);
                $u['id_number_enc']    = $keepFull ? self::encrypt($num) : null;
                $u['id_number_masked'] = self::maskId($type, $num);
            } else {
                $u['id_number_enc'] = null;
                $u['id_number_masked'] = null;
            }
        }
        if (array_key_exists('id_expires_on', $in)) {
            $d = trim((string) $in['id_expires_on']);
            $u['id_expires_on'] = ($d !== '' && Security::isValidDate($d)) ? $d : null;
        }
        if (array_key_exists('whatsapp', $in)) {
            // the second number; the verified one is `phone`
            $d = preg_replace('/\D/', '', (string) $in['whatsapp']) ?? '';
            $u['whatsapp'] = ($d !== '' && strlen($d) >= 8 && strlen($d) <= 15) ? $d : null;
        }
        $u += self::bankColumns(array_intersect_key($in, array_flip([
            'bank_holder', 'bank_name', 'bank_account', 'bank_ifsc', 'bank_branch', 'upi_id', 'esewa_id', 'khalti_id',
        ])));
        if ($u !== []) {
            Database::update('ap_applications', $u, 'id = :i', ['i' => (int) $app['id']]);
            self::markOfficeEdit($app);
        }
        return self::draft($key) ?? $app;
    }

    /**
     * The office changed a form AFTER it was sent (join.php?office=1&d=…):
     * note it in readout.office_edited_at, so approve() copies the form as it
     * is now into the account and profile. An explicit marker, not updated_at
     * vs submitted_at: those two come from different clocks (MySQL TIMESTAMP in
     * the server's zone vs PHP date() in Asia/Kolkata), and background readout
     * writes touch updated_at too. Its own column (af1 migration) so the
     * background ID reader, which rewrites readout from an older copy, cannot
     * drop it; before the migration, one atomic JSON_SET into readout.
     *
     * @param array<string, mixed> $app the row as it was before the change
     */
    private static function markOfficeEdit(array $app): void
    {
        if ((string) ($app['status'] ?? '') !== 'submitted') {
            return;
        }
        if (self::appHas('office_edited_at')) {
            Database::update('ap_applications', ['office_edited_at' => date('Y-m-d H:i:s')], 'id = :i', ['i' => (int) $app['id']]);
            return;
        }
        Database::run(
            "UPDATE ap_applications SET readout = JSON_SET(IF(JSON_VALID(readout), readout, '{}'), '$.office_edited_at', :t) WHERE id = :i",
            ['t' => date('c'), 'i' => (int) $app['id']]
        );
    }

    /**
     * The draft, if it may still change: a draft; for the office also a
     * submitted application (the office finishes it before approving).
     *
     * @return array<string, mixed>
     */
    private static function editable(string $key, bool $office = false): array
    {
        $app = self::draft($key);
        if ($app === null) {
            throw new RuntimeException('Your form has expired — please start again.');
        }
        if ($app['status'] === 'draft' || ($office && $app['status'] === 'submitted')) {
            return $app;
        }
        throw new RuntimeException('This application was already sent. Reference ' . $app['ref'] . '.');
    }

    /**
     * Office: who the form is for — an agent (person / agency) or a staff
     * role. Only while it is a draft (the account is made with the role).
     */
    public static function officeSetRole(string $key, string $role, string $designation = '', string $agentKind = 'person'): array
    {
        $app = self::draft($key);
        if ($app === null) {
            throw new RuntimeException('Your form has expired — please start again.');
        }
        if ($app['status'] !== 'draft') {
            throw new RuntimeException('The role is fixed once the form was sent.');
        }
        if (!in_array($role, self::INVITE_ROLES, true)) {
            throw new RuntimeException('Choose a role.');
        }
        $agent = $role === 'agent';
        Database::update('ap_applications', [
            'kind'        => $agent ? 'agent' : 'staff',
            'role_wanted' => $agent ? null : $role,
            'agent_kind'  => $agent ? AgentWallet::normaliseKind($agentKind) : 'person',
            'designation' => Security::clean($designation, 80) ?: null,
        ], 'id = :i', ['i' => (int) $app['id']]);
        return self::draft($key) ?? $app;
    }

    /** The (first) admins row that already uses this mobile (any status), or null. Excludes $exceptAdminId. */
    public static function phoneOwner(string $phone, int $exceptAdminId = 0): ?array
    {
        return self::phoneOwners($phone, $exceptAdminId)[0] ?? null;
    }

    /**
     * Every admins row on this mobile (any status, oldest first), excluding $exceptAdminId.
     *
     * @return list<array<string, mixed>>
     */
    public static function phoneOwners(string $phone, int $exceptAdminId = 0): array
    {
        $phone = normalisePhone($phone);
        if ($phone === '') {
            return [];
        }
        $out = [];
        foreach (Database::fetchAll("SELECT id, full_name, role, is_active, phone FROM admins WHERE phone IS NOT NULL AND phone <> '' ORDER BY id ASC") as $r) {
            if ((int) $r['id'] !== $exceptAdminId && normalisePhone((string) $r['phone']) === $phone) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * How many logins one mobile may carry (owner 30 Oct 2026: "euta WhatsApp number ma 3 ota ID").
     * Setting ap_phone_max_accounts, default 3, never below 1 or above 5.
     */
    public static function phoneMax(): int
    {
        return max(1, min(5, Settings::getInt('ap_phone_max_accounts', 3)));
    }

    /**
     * May one more account be made on this mobile?
     *   public form: only a number nobody has yet;
     *   office form: up to phoneMax() accounts, and only once the office said
     *   "add another ID on the same number" ($share).
     *
     * @throws ApPhoneTaken
     */
    public static function assertPhoneFree(string $phone, int $exceptAdminId = 0, bool $office = false, bool $share = false): void
    {
        $owners = self::phoneOwners($phone, $exceptAdminId);
        if ($owners === []) {
            return;
        }
        $n = count($owners);
        $max = self::phoneMax();
        $canShare = $office && $n < $max;
        if ($canShare && $share) {
            return;
        }
        $names = implode(', ', array_map(static fn(array $o): string => (string) $o['full_name'] . ' (#' . (int) $o['id'] . ')', $owners));
        if (!$office) {
            $active = array_filter($owners, static fn(array $o): bool => (int) $o['is_active'] === 1) !== [];
            throw new ApPhoneTaken($active
                ? 'यो मोबाइलको खाता पहिल्यै छ — /me/ मा लगइन गर्नुहोस्; अर्को ID चाहिए अफिसलाई भन्नुहोस् · This mobile number already has an account — sign in at /me/ instead (for another ID on the same number, ask the office).'
                : 'यो मोबाइलको आवेदन पहिल्यै अफिसमा छ · An application from this mobile number is already with the office.', $owners, false, $max);
        }
        if (!$canShare) {
            throw new ApPhoneTaken('यो मोबाइलमा पहिल्यै ' . $n . ' वटा ID छन् (बढीमा ' . $max . ') · This mobile already has ' . $n . ' logins — the most one number may carry is ' . $max . ': ' . $names . '.', $owners, false, $max);
        }
        throw new ApPhoneTaken('यो मोबाइलमा पहिल्यै ' . $n . ' लगइन छ: ' . $names . ' — सोही नम्बरमा अर्को ID थप्ने? · This number already has ' . $n . ' login(s): ' . $names . ' — add another ID on the same number?', $owners, true, $max);
    }

    /**
     * The office said yes to "add another ID on the same number" for this draft's mobile:
     * remembered on the draft, so the WhatsApp step and the final submit agree.
     */
    public static function officeSharePhone(string $key, string $phone): void
    {
        $app = self::editable($key, true);
        $phone = normalisePhone($phone);
        if ($phone === '') {
            return;
        }
        self::assertPhoneFree($phone, (int) ($app['admin_id'] ?? 0), true, true);   // still room (max) — throws when full
        $readout = jsonColumn($app['readout'] ?? null);
        $readout['phone_share'] = ['phone' => $phone, 'at' => date('c')];
        Database::update('ap_applications', ['readout' => json_encode($readout, JSON_UNESCAPED_UNICODE)], 'id = :i', ['i' => (int) $app['id']]);
    }

    /** Did the office already say yes to sharing THIS mobile on this draft? */
    private static function shareAgreed(array $app, string $phone): bool
    {
        $s = jsonColumn($app['readout'] ?? null)['phone_share'] ?? null;
        return is_array($s) && $phone !== '' && (string) ($s['phone'] ?? '') === normalisePhone($phone);
    }

    /**
     * Office: the applicant is in front of us / phoned us — the office vouches
     * for the number instead of a WhatsApp code. Needs a reason; audited.
     *
     * @return array<string, mixed> the application
     */
    public static function officeVerify(string $key, int $by, string $reason, string $rawPhone = '', string $country = '', bool $share = false): array
    {
        $app = self::editable($key, true);
        if (!self::appHas('office_filled_by')) {
            throw new RuntimeException('Run database/upgrade-2026-10-30-agentpro-af1.sql first (office check).');
        }
        $reason = Security::clean($reason, 160);
        if (mb_strlen($reason) < 3) {
            throw new RuntimeException('Write why the WhatsApp check is skipped (e.g. "came to the office, no WhatsApp").');
        }
        $phone = trim($rawPhone) !== '' ? normalisePhone($rawPhone) : (string) ($app['phone'] ?? '');
        if (!Security::isValidPhone($phone, true)) {
            throw new RuntimeException('Enter the mobile number.');
        }
        $share = $share || self::shareAgreed($app, $phone);
        self::assertPhoneFree($phone, (int) ($app['admin_id'] ?? 0), true, $share);
        $country = in_array($country, ['IN', 'NP'], true) ? $country : ((string) ($app['country'] ?? '') ?: (resolvePhoneCountry('', $rawPhone) ?: 'IN'));
        $readout = jsonColumn($app['readout'] ?? null);
        $readout['office'] = ['by' => $by, 'reason' => $reason, 'at' => date('c')];
        if ($share && self::phoneOwners($phone, (int) ($app['admin_id'] ?? 0)) !== []) {
            $readout['phone_share'] = ['phone' => $phone, 'at' => date('c'), 'by' => $by];   // "another ID on the same number"
        }
        Database::update('ap_applications', [
            'phone' => $phone, 'country' => $country, 'wa_verified_at' => date('Y-m-d H:i:s'), 'wa_verified_via' => 'office',
            'office_filled_by' => $by, 'verify_code' => null, 'readout' => json_encode($readout, JSON_UNESCAPED_UNICODE),
        ], 'id = :i', ['i' => (int) $app['id']]);
        self::markOfficeEdit($app);   // the number changed after sending: approve() refreshes the profile
        if (!empty($app['admin_id'])) {
            Database::update('admins', ['phone' => $phone], 'id = :i AND is_active = 0', ['i' => (int) $app['admin_id']]);
        }
        Logger::audit('agentpro.office_verify', 'application', (string) $app['ref'], null,
            ['phone' => self::maskPhone($phone), 'reason' => $reason], 'WhatsApp check skipped by admin #' . $by);
        return self::draft($key) ?? $app;
    }

    private static function maskPhone(string $p): string
    {
        return strlen($p) > 4 ? str_repeat('•', strlen($p) - 4) . substr($p, -4) : $p;
    }

    /**
     * The hand-filled paper form: up to 4 page images (data: URIs — the
     * browser already turned a PDF into JPEG pages with pdf.js). Stored like
     * every other document (re-encoded JPEG, no EXIF) and kept with the
     * extras as "Paper form p1…"; a new scan replaces the earlier pages.
     *
     * @param list<mixed> $dataUrls
     * @return list<array{path: string, abs: string}>
     */
    public static function storePaperPages(string $key, array $dataUrls, bool $office = false): array
    {
        $app = self::editable($key, $office);
        $dir = UPLOAD_PATH . '/agents-kyc';
        ensureDir($dir);
        $pages = [];
        foreach (array_slice(array_values($dataUrls), 0, self::PAPER_MAX_PAGES) as $uri) {
            if (!is_string($uri) || preg_match('#^data:image/(?:jpeg|jpg|png|webp);base64,#i', $uri) !== 1) {
                continue;
            }
            $bin = base64_decode(substr($uri, (int) strpos($uri, ',') + 1), true);
            if ($bin === false || strlen($bin) < 1500 || strlen($bin) > 8_000_000) {
                continue;
            }
            // The header's size BEFORE GD opens it: a tiny PNG can claim 46000x46000 px
            // (libgd would allocate gigabytes outside memory_limit). Same rule as every upload.
            if (!class_exists('AgentApplications')) {
                require_once __DIR__ . '/agentapplications.php';
            }
            if (!AgentApplications::imageFits($bin)) {
                unset($bin);
                continue;
            }
            $img = @imagecreatefromstring($bin);
            unset($bin);
            if (!$img instanceof GdImage) {
                continue;
            }
            $w = imagesx($img);
            $h = imagesy($img);
            if ($w < 300 || $h < 300) {
                imagedestroy($img);
                continue;
            }
            $scale = min(1.0, self::DOC_MAX_EDGE / max($w, $h));
            if ($scale < 1.0) {
                $small = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
                imagecopyresampled($small, $img, 0, 0, 0, 0, imagesx($small), imagesy($small), $w, $h);
                imagedestroy($img);
                $img = $small;
            }
            $name = Security::safeFilename('jpg');
            $ok = imagejpeg($img, $dir . '/' . $name, 88);
            imagedestroy($img);
            if ($ok) {
                $pages[] = ['path' => 'agents-kyc/' . $name, 'abs' => $dir . '/' . $name];
            }
        }
        if ($pages === []) {
            throw new RuntimeException('Add a clear photo of each page of the filled form (JPG / PNG), or the PDF.');
        }
        $old = [];
        $keep = [];
        foreach (jsonColumn($app['extras'] ?? null) as $x) {
            if (!empty($x['paper'])) {
                $old[] = (string) ($x['path'] ?? '');
                continue;
            }
            $keep[] = $x;
        }
        foreach ($pages as $i => $p) {
            $keep[] = ['label' => self::PAPER_LABEL . ($i + 1), 'path' => $p['path'], 'paper' => 1];
        }
        Database::update('ap_applications', ['extras' => json_encode($keep, JSON_UNESCAPED_UNICODE)], 'id = :i', ['i' => (int) $app['id']]);
        foreach ($old as $rel) {
            if (($abs = self::privateAbs($rel)) !== null) {
                @unlink($abs);
            }
        }
        return $pages;
    }

    /**
     * What the paper-form reader found, for the office (readout.paper). The
     * ID and bank numbers are masked here — the full values only ever went
     * back to the browser that sent the pages.
     *
     * @param array<string, mixed> $r ApFormRead::read() result
     */
    public static function noteFormRead(string $key, array $r): void
    {
        $app = self::draft($key);
        if ($app === null) {
            return;
        }
        $fields = (array) ($r['fields'] ?? []);
        if (isset($fields['id_number']) && is_string($fields['id_number'])) {
            $fields['id_number'] = self::maskId((string) ($fields['id_type'] ?? $app['id_type'] ?? ''), $fields['id_number']);
        }
        if (isset($fields['bank_account']) && is_string($fields['bank_account'])) {
            $d = preg_replace('/\D/', '', $fields['bank_account']) ?? '';
            $fields['bank_account'] = $d !== '' ? '••••' . substr($d, -4) : '';
        }
        $all = jsonColumn($app['readout'] ?? null);
        $all['paper'] = [
            'status' => (string) ($r['status'] ?? ''), 'reader' => (string) ($r['reader'] ?? ''),
            'fields' => $fields, 'confidence' => (array) ($r['confidence'] ?? []),
            'note' => mb_substr((string) ($r['note'] ?? ''), 0, 300), 'at' => date('c'),
        ];
        Database::update('ap_applications', ['readout' => json_encode($all, JSON_UNESCAPED_UNICODE)], 'id = :i', ['i' => (int) $app['id']]);
    }

    /** Add what the document reader found to the draft's readout (for the office). */
    public static function noteReadout(string $key, string $slot, array $read): void
    {
        $app = self::draft($key);
        if ($app === null) {
            return;
        }
        $all = jsonColumn($app['readout'] ?? null);
        $all[$slot] = ['fields' => $read['fields'] ?? [], 'sources' => $read['sources'] ?? [], 'at' => date('c')];
        Database::update('ap_applications', ['readout' => json_encode($all, JSON_UNESCAPED_UNICODE)], 'id = :i', ['i' => (int) $app['id']]);
    }

    /**
     * Store one document on a draft: front | back | selfie | thumb | extra.
     *
     * @param array<string, mixed> $file
     * @return array{path: string, abs: string, mime: string}
     */
    public static function storeDraftDoc(string $key, string $slot, array $file, string $label = '', bool $office = false): array
    {
        $app = self::editable($key, $office);
        $col = match ($slot) {
            'front' => 'doc_front', 'back' => 'doc_back', 'selfie' => 'selfie', 'thumb' => 'thumb', 'extra' => 'extras',
            default => throw new RuntimeException('Unknown document.'),
        };
        $extras = jsonColumn($app['extras'] ?? null);
        $own = count(array_filter($extras, static fn($x): bool => is_array($x) && empty($x['paper'])));   // paper-form pages do not count
        if ($slot === 'extra' && $own >= max(1, Settings::getInt('ap_join_max_extras', 6))) {
            throw new RuntimeException('That is the most documents one form can carry. Bring the rest to the office.');
        }
        $saved = self::storePrivate($file, in_array($slot, ['selfie', 'thumb'], true));
        if ($slot === 'extra') {
            $extras[] = ['label' => Security::clean($label !== '' ? $label : 'Document', 60), 'path' => $saved['path']];
            Database::update('ap_applications', ['extras' => json_encode($extras, JSON_UNESCAPED_UNICODE)], 'id = :i', ['i' => (int) $app['id']]);
        } else {
            $old = (string) ($app[$col] ?? '');
            Database::update('ap_applications', [$col => $saved['path']], 'id = :i', ['i' => (int) $app['id']]);
            if (($oldAbs = self::privateAbs($old)) !== null) {
                @unlink($oldAbs);
            }
        }
        return $saved;
    }

    /** Remove one extra document from a draft. */
    public static function dropDraftExtra(string $key, int $index, bool $office = false): void
    {
        $app = self::editable($key, $office);
        $extras = jsonColumn($app['extras'] ?? null);
        if (!isset($extras[$index])) {
            return;
        }
        if (($abs = self::privateAbs((string) ($extras[$index]['path'] ?? ''))) !== null) {
            @unlink($abs);
        }
        array_splice($extras, $index, 1);
        Database::update('ap_applications', ['extras' => json_encode($extras, JSON_UNESCAPED_UNICODE)], 'id = :i', ['i' => (int) $app['id']]);
    }

    /* ---------------- WhatsApp verification of the applicant ---------------- */

    /** Send a 6-digit code to the number on WhatsApp (SMS too when the office enabled SMS OTP). */
    public static function sendJoinOtp(string $key, string $rawPhone, string $country = '', bool $office = false, bool $share = false): array
    {
        $app = self::draft($key);
        if ($app === null || $app['status'] !== 'draft') {
            throw new RuntimeException('Your form has expired — please start again.');
        }
        $phone = normalisePhone($rawPhone);
        if (!Security::isValidPhone($phone, true)) {
            throw new RuntimeException('Enter your 10-digit mobile number.');
        }
        $share = $office && ($share || self::shareAgreed($app, $phone));
        self::assertPhoneFree($phone, (int) ($app['admin_id'] ?? 0), $office, $share);
        if ($share && self::phoneOwners($phone, (int) ($app['admin_id'] ?? 0)) !== []) {
            self::officeSharePhone($key, $phone);
        }
        $country = in_array($country, ['IN', 'NP'], true) ? $country : (resolvePhoneCountry('', $rawPhone) ?: 'IN');
        self::limit('apjoin_otp_ip', Security::clientIp(), 12, 3600);
        $otp = Auth::issueOtp('apjoin:' . $phone, 'verify', 'whatsapp');
        if (!$otp['ok']) {
            throw new RuntimeException((string) ($otp['error'] ?? 'Could not send a code right now.'));
        }
        require_once __DIR__ . '/notify.php';
        $company = Settings::getString('company_name', APP_NAME);
        $text = $company . " — तपाईंको कोड / your code: *" . $otp['code'] . "*\n"
              . OTP_EXPIRY_MINUTES . " मिनेट मान्य। कसैलाई नबताउनुहोस्। / Valid " . OTP_EXPIRY_MINUTES . " min. Do not share it.";
        $sent = Notify::whatsapp($phone, $text, null, $country);
        if (Settings::getBool('sms_send_otp', false)) {
            Notify::sms($phone, $text, $country);
        }
        Database::update('ap_applications', [
            'phone' => $phone, 'country' => $country, 'wa_verified_at' => null, 'wa_verified_via' => null,
        ], 'id = :i', ['i' => (int) $app['id']]);
        return ['ok' => true, 'sent' => $sent !== false, 'resend' => OTP_RESEND_SECONDS];
    }

    public static function verifyJoinOtp(string $key, string $code): array
    {
        $app = self::draft($key);
        if ($app === null || $app['status'] !== 'draft') {
            throw new RuntimeException('Your form has expired — please start again.');
        }
        $phone = (string) ($app['phone'] ?? '');
        if ($phone === '') {
            throw new RuntimeException('Ask for a code first.');
        }
        $res = Auth::verifyOtp('apjoin:' . $phone, $code, 'verify');
        if (!$res['ok']) {
            throw new RuntimeException((string) ($res['error'] ?? 'That code is not right.'));
        }
        Database::update('ap_applications', ['wa_verified_at' => date('Y-m-d H:i:s'), 'wa_verified_via' => 'otp'],
            'id = :i', ['i' => (int) $app['id']]);
        return ['ok' => true, 'phone' => $phone];
    }

    /**
     * Reverse verification: a code the applicant sends FROM their WhatsApp
     * ("JOIN 482913"). Free for them, needs no template on our side, and
     * proves they hold that WhatsApp number. Returns the wa.me link.
     */
    public static function reverseCode(string $key): array
    {
        $app = self::draft($key);
        if ($app === null || $app['status'] !== 'draft') {
            throw new RuntimeException('Your form has expired — please start again.');
        }
        $code = (string) ($app['verify_code'] ?? '');
        if ($code === '') {
            for ($try = 0; $try < 8; $try++) {
                $code = Security::numericCode(6);
                if (!Database::exists("SELECT 1 FROM ap_applications WHERE verify_code = :c AND status = 'draft'", ['c' => $code])) {
                    break;
                }
            }
            Database::update('ap_applications', ['verify_code' => $code], 'id = :i', ['i' => (int) $app['id']]);
        }
        $to = Settings::bookingWhatsApp();
        return ['code' => $code, 'to' => $to, 'link' => whatsappLink($to, 'JOIN ' . $code)];
    }

    /**
     * WhatsApp said "JOIN <code>" from $senderDigits. Marks the draft
     * verified with that number. Null when no draft carries the code.
     *
     * @return array<string, mixed>|null the application
     */
    public static function reverseVerified(string $senderRaw, string $code): ?array
    {
        if (!self::available() || preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $app = Database::fetch(
            "SELECT * FROM ap_applications WHERE verify_code = :c AND status = 'draft'
              AND updated_at > (NOW() - INTERVAL 2 DAY) LIMIT 1",
            ['c' => $code]
        );
        if ($app === null) {
            return null;
        }
        $phone = normalisePhone($senderRaw);
        $raw   = preg_replace('/\D/', '', $senderRaw) ?? '';
        $country = str_starts_with($raw, '977') ? 'NP' : (str_starts_with($raw, '91') ? 'IN' : ((string) ($app['country'] ?? '') ?: 'IN'));
        Database::update('ap_applications', [
            'phone' => $phone, 'country' => $country,
            'wa_verified_at' => date('Y-m-d H:i:s'), 'wa_verified_via' => 'reverse', 'verify_code' => null,
        ], 'id = :i', ['i' => (int) $app['id']]);
        return self::application((int) $app['id']);
    }

    /** A rate limit that throws (library code must never answer HTTP itself). */
    private static function limit(string $bucket, string $id, int $max, int $window): void
    {
        if (!Security::rateLimit($bucket, $id, $max, $window)) {
            throw new RuntimeException('Too many attempts — please wait a while and try again.');
        }
    }


    /**
     * Send the application: make the (inactive) staff account, exactly the
     * way agent-signup.php does, so the office sees it in both places.
     *
     * @return array<string, mixed> the submitted application
     */
    public static function submit(string $key, bool $office = false, int $by = 0, bool $share = false): array
    {
        $app = self::draft($key);
        if ($app === null) {
            throw new RuntimeException('Your form has expired — please start again.');
        }
        if ($app['status'] !== 'draft') {
            return $app;   // a double tap on Submit (or the office finishing a submitted one)
        }

        $missing = [];
        if (mb_strlen(trim((string) ($app['full_name'] ?? ''))) < 2) { $missing[] = 'your full name'; }
        if ($app['kind'] === 'agent' && $app['agent_kind'] === 'org' && trim((string) ($app['org_name'] ?? '')) === '') { $missing[] = 'the agency name'; }
        if (empty($app['wa_verified_at'])) { $missing[] = $office ? 'the mobile number (verify on WhatsApp, or skip with a reason)' : 'WhatsApp verification'; }
        if (!$office) {
            // 30 Oct 2026 (owner): the OFFICE form needs only name + mobile (+ role) — ID number,
            // ID photos, photo, bank are "पछि थप्न सकिन्छ · add later". Self-fill keeps all of these.
            if (empty($app['id_type'])) { $missing[] = 'the ID type'; }
            if (empty($app['doc_front'])) { $missing[] = 'a photo of the ID (front)'; }
            if (trim((string) ($app['city'] ?? '')) === '' && trim((string) ($app['address'] ?? '')) === '') { $missing[] = 'your address or city'; }
            if (empty($app['selfie'])) { $missing[] = 'your photo'; }
        }
        if ($missing !== []) {
            throw new RuntimeException('Still needed: ' . implode(', ', $missing) . '.');
        }

        $phone = (string) $app['phone'];
        // One lock per mobile from the "is there room on this number?" check until the
        // admins row exists, so two office drafts for the same number sent at the same
        // moment cannot both pass the limit (ap_phone_max_accounts). Released below.
        $phoneLock = 'ap_phone_' . (normalisePhone($phone) ?: md5($phone));
        if ((int) Database::scalar('SELECT GET_LOCK(:k, 5)', ['k' => $phoneLock], 0) !== 1) {
            throw new RuntimeException('यो मोबाइलको अर्को फारम पठाइँदैछ — केही बेरपछि फेरि · Another form for this mobile is being sent — please try again in a moment.');
        }
        try {
            // Re-read under the lock: a double tap that waited here finds the form already sent.
            $app = self::draft($key) ?? $app;
            if ($app['status'] !== 'draft') {
                return $app;
            }
            return self::submitLocked($app, $phone, $office, $by, $share);
        } finally {
            Database::scalar('SELECT RELEASE_LOCK(:k)', ['k' => $phoneLock], 0);
        }
    }

    /**
     * submit(), from the per-number limit on, while the caller holds the phone lock.
     *
     * @param array<string, mixed> $app
     * @return array<string, mixed>
     */
    private static function submitLocked(array $app, string $phone, bool $office, int $by, bool $share): array
    {
        self::assertPhoneFree($phone, 0, $office, $office && ($share || self::shareAgreed($app, $phone)));
        if (!$office) {
            // Counted only now — a form that failed validation created nothing. An
            // internet cafe may register several agents, so the per-IP budget is generous.
            self::limit('apjoin_submit_ip', Security::clientIp(), 10, 3600);
            self::limit('apjoin_submit_phone', $phone, 2, 86400);
        }
        $email = (string) ($app['email'] ?? '');
        if ($email !== '' && Database::exists('SELECT 1 FROM admins WHERE LOWER(email) = :e', ['e' => strtolower($email)])) {
            throw new RuntimeException('That email already belongs to an account. Use another, or leave it empty.');
        }

        $role   = $app['kind'] === 'staff' ? (string) ($app['role_wanted'] ?: 'counter') : 'agent';
        $isOrg  = $role === 'agent' && $app['agent_kind'] === 'org';
        $person = trim((string) $app['full_name']);
        $display = $isOrg ? trim((string) $app['org_name']) : $person;
        $username = $phone;
        if (Database::exists('SELECT 1 FROM admins WHERE username = :u', ['u' => $username])) {
            // a second / third ID on the same number (office, ap_phone_max_accounts): agent_98…, agent_98…_2
            $base = ($role === 'agent' ? 'agent_' : 'staff_') . $phone;
            $username = $base;
            for ($n = 2; $n < 20 && Database::exists('SELECT 1 FROM admins WHERE username = :u', ['u' => $username]); $n++) {
                $username = $base . '_' . $n;
            }
        }

        // One invitation = one application. Two drafts opened from the same link
        // race here; only the first UPDATE wins (used_at was NULL), atomically.
        $inviteId = (int) ($app['invite_id'] ?? 0);
        if ($inviteId > 0) {
            $won = Database::run('UPDATE ap_invites SET used_at = NOW(), application_id = :a WHERE id = :i AND used_at IS NULL',
                ['a' => (int) $app['id'], 'i' => $inviteId]);
            if ($won !== 1) {
                throw new RuntimeException('This invitation link was already used for another application. Ask the office for a new link.');
            }
        }

        $loginRole = self::loginRole($role);   // a driver / conductor signs in as boarding staff
        try {
            $adminId = (int) Database::transaction(static function () use ($loginRole, $display, $username, $email, $phone, $office): int {
                $id = (int) Database::insert('admins', [
                    'username'       => $username,
                    'password_hash'  => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
                    'full_name'      => $display,
                    'email'          => $email !== '' ? $email : null,
                    'phone'          => $phone,
                    'role'           => $loginRole,
                    'is_active'      => 0,
                    'must_change_pw' => 0,
                ]);
                // Belt and braces with the phone lock: counted again with the new row in,
                // so a writer outside this lock can never leave more than the limit.
                if (count(self::phoneOwners($phone)) > ($office ? self::phoneMax() : 1)) {
                    throw new ApPhoneTaken('यो मोबाइलमा बढीमा ' . self::phoneMax() . ' वटा ID · This mobile already has the most logins one number may carry (' . self::phoneMax() . ').', self::phoneOwners($phone, $id), false, self::phoneMax());
                }
                return $id;
            });
        } catch (Throwable $e) {
            if ($inviteId > 0) {   // nothing was made: the link works again
                Database::run('UPDATE ap_invites SET used_at = NULL, application_id = NULL WHERE id = :i AND application_id = :a',
                    ['i' => $inviteId, 'a' => (int) $app['id']]);
            }
            throw $e;
        }

        self::writeProfile($adminId, $app, $role);
        if (in_array($role, self::CREW_ROLES, true)) {
            try {
                self::setCrewRole($adminId, $role);
            } catch (Throwable $e) {
                Logger::warning('Submit: crew role not stored: ' . $e->getMessage(), [], 'agent');
            }
        }
        if (AgentWallet::kycAvailable()) {
            AgentWallet::saveProfile($adminId, array_filter([
                'kyc_doc_path'  => (string) ($app['doc_front'] ?? ''),
                'kyc_doc2_path' => (string) ($app['doc_back'] ?? ''),
                'kyc_status'    => 'submitted',
            ], static fn($v): bool => $v !== ''));
        }

        $upd = ['status' => 'submitted', 'submitted_at' => date('Y-m-d H:i:s'), 'admin_id' => $adminId];
        if ($office && $by > 0 && empty($app['office_filled_by']) && self::appHas('office_filled_by')) {
            $upd['office_filled_by'] = $by;
        }
        Database::update('ap_applications', $upd, 'id = :i', ['i' => (int) $app['id']]);

        Logger::audit('agentpro.applied', 'admin', (string) $adminId, null,
            ['ref' => $app['ref'], 'role' => $role, 'kind' => $app['agent_kind'], 'office' => $office ? $by : 0],
            'Application ' . $app['ref'] . ' submitted' . ($office ? ' by the office (admin #' . $by . ')' : ''));

        // Tell the office at once (the same channel as other office alerts) —
        // unless the office itself just filled it.
        if (!$office) {
            try {
                require_once __DIR__ . '/notify.php';
                Notify::adminNote('🆕 New ' . ($role === 'agent' ? 'agent' : $role) . ' application ' . $app['ref'], [
                    'Name'  => $display . ($isOrg ? ' (' . $person . ')' : ''),
                    'Phone' => $phone,
                    'City'  => (string) ($app['city'] ?? ''),
                    'Open'  => appUrl('admin/team.php?tab=approvals'),
                ]);
            } catch (Throwable $e) {
                Logger::warning('Office note for a new application failed: ' . $e->getMessage(), [], 'agent');
            }
        }

        return self::application((int) $app['id']) ?? $app;
    }

    /**
     * The application's contact / ID details into admin_profiles (the
     * profile every older screen reads). At submit, and again at approve
     * when the office changed the form after it was sent.
     *
     * @param array<string, mixed> $app
     */
    private static function writeProfile(int $adminId, array $app, string $role): void
    {
        $isOrg  = $role === 'agent' && $app['agent_kind'] === 'org';
        $phone  = (string) $app['phone'];
        $address = trim(implode(', ', array_filter([
            (string) ($app['address'] ?? ''), (string) ($app['city'] ?? ''), (string) ($app['district'] ?? ''),
            (string) ($app['state'] ?? ''), (string) ($app['pincode'] ?? ''),
        ], static fn(string $s): bool => trim($s) !== '')));

        AgentWallet::saveProfile($adminId, array_filter([
            'display_phone'  => $phone,
            'whatsapp'       => (string) ($app['whatsapp'] ?? '') !== '' ? (string) $app['whatsapp'] : $phone,
            'counter_name'   => (string) ($app['city'] ?? ''),
            'agent_kind'     => $role === 'agent' ? (string) $app['agent_kind'] : null,
            'contact_person' => $isOrg ? trim((string) $app['full_name']) : null,
            'address'        => mb_substr($address, 0, 255),
            'id_type'        => self::ID_TYPE_PROFILE[(string) ($app['id_type'] ?? '')] ?? '',
            'id_number'      => (string) ($app['id_number_masked'] ?? ''),
            'country_code'   => ($app['country'] ?? '') === 'NP' ? '977' : '91',
            'id_expires_on'  => (string) ($app['id_expires_on'] ?? ''),
            'notes'          => 'Joined with the Agent Pro form ' . $app['ref'],
            'joined_on'      => date('Y-m-d'),
        ], static fn($v): bool => $v !== null));
    }

    /**
     * ONE TAP: everything the office used to do by hand after reading an
     * application. Idempotent — approving twice changes nothing more.
     *
     * @return array{admin_id: int, code: string, password?: string}
     */
    public static function approve(int $appId, int $by, ?bool $kycVerified = null): array
    {
        $app = self::application($appId);
        if ($app === null) {
            throw new RuntimeException('Application not found.');
        }
        if ($app['status'] === 'approved') {
            return ['admin_id' => (int) $app['admin_id'], 'code' => self::member((int) $app['admin_id'])['idNo'] ?? ''];
        }
        if ($app['status'] !== 'submitted' || empty($app['admin_id'])) {
            throw new RuntimeException('Only a submitted application can be approved.');
        }
        $adminId = (int) $app['admin_id'];
        $admin = Database::fetch('SELECT id, role, full_name, phone, username, is_active FROM admins WHERE id = :i', ['i' => $adminId]);
        if ($admin === null) {
            throw new RuntimeException('The account for this application was deleted.');
        }
        $role = (string) $admin['role'];

        // The office may have finished the form after it was sent (join.php?office=1&d=…):
        // the account and profile take the form as it is now. saveDraft / officeVerify set
        // the marker; no comparison of updated_at with submitted_at (two different clocks).
        if (!empty($app['office_edited_at']) || !empty(jsonColumn($app['readout'] ?? null)['office_edited_at'])) {
            $isOrg = $role === 'agent' && $app['agent_kind'] === 'org';
            $name = trim((string) ($isOrg ? $app['org_name'] : $app['full_name']));
            if ($name !== '' && $name !== (string) $admin['full_name']) {
                Database::update('admins', ['full_name' => $name], 'id = :i', ['i' => $adminId]);
            }
            try {
                self::writeProfile($adminId, $app, $role);
            } catch (Throwable $e) {
                Logger::warning('Approve: profile not refreshed: ' . $e->getMessage(), [], 'agent');
            }
        }

        $password = null;
        $upd = ['is_active' => 1];
        if ($role !== 'agent') {
            // Office staff sign in with a password; the first sign-in must change it.
            $password = substr(strtr(base64_encode(random_bytes(9)), '+/=', 'xyz'), 0, 10);
            $upd['password_hash']  = password_hash($password, PASSWORD_DEFAULT);
            $upd['must_change_pw'] = 1;
        }
        Database::update('admins', $upd, 'id = :i', ['i' => $adminId]);

        $code = '';
        if ($role === 'agent') {
            if (AgentWallet::agentCodeFor($adminId) === null) {
                $n = AgentWallet::nextFreeAgentCode(AgentWallet::agentKindFor($adminId));
                if ($n !== null) {
                    AgentWallet::setAgentCode($adminId, $n, $by);
                }
            }
            $code = AgentWallet::agentCodeLabel($adminId);
        }
        // $kycVerified: null = the one-tap approve (the office looked at the ID photos) → verified;
        // the office form passes its "saw the original ID" tick — unticked leaves KYC "submitted".
        // 30 Oct 2026: an office form sent with no ID photo at all ("add later") is never KYC-verified
        // by the one-tap approve — only by a manager's explicit "saw the original ID" tick.
        if ($kycVerified === null && empty($app['doc_front']) && empty($app['doc_back'])) {
            $kycVerified = false;
        }
        if (AgentWallet::kycAvailable() && $kycVerified !== false) {
            AgentWallet::kycVerify($adminId, 'verified', ($kycVerified === true ? 'Original ID seen by the office · ' : '')
                . 'Approved from the Agent Pro form ' . $app['ref'], $by);
        }

        // The selfie becomes the profile photo — and so the ID card photo.
        if (($selfie = self::privateAbs((string) ($app['selfie'] ?? ''))) !== null) {
            try {
                self::setPhotoFromFile($adminId, $selfie, $by);
            } catch (Throwable $e) {
                Logger::warning('Approve: selfie not copied: ' . $e->getMessage(), [], 'agent');
            }
        }

        self::ensureMember($adminId, $role);
        $m = [];
        foreach (['dob', 'gender', 'guardian', 'bank_holder', 'bank_name', 'bank_account_enc', 'bank_account_last4',
                  'bank_ifsc', 'bank_branch', 'upi_id', 'esewa_id', 'khalti_id', 'designation'] as $k) {
            if (!empty($app[$k])) {
                $m[$k] = $app[$k];
            }
        }
        if ($m !== []) {
            Database::update('ap_members', $m, 'admin_id = :a', ['a' => $adminId]);
        }
        if (!empty($app['upi_id']) || !empty($app['bank_account_enc']) || !empty($app['esewa_id']) || !empty($app['khalti_id'])) {
            $method = !empty($app['upi_id']) ? 'upi' : (!empty($app['bank_account_enc']) ? 'bank' : (!empty($app['esewa_id']) ? 'esewa' : 'khalti'));
            AgentWallet::saveProfile($adminId, [
                'payout_method'  => $method,
                'payout_account' => match ($method) {
                    'upi'    => (string) $app['upi_id'],
                    'bank'   => trim(($app['bank_name'] ?? '') . ' ••••' . ($app['bank_account_last4'] ?? '') . ' ' . ($app['bank_ifsc'] ?? '')),
                    'esewa'  => 'eSewa ' . (string) $app['esewa_id'],
                    default  => 'Khalti ' . (string) $app['khalti_id'],
                },
            ]);
        }

        // Bus crew: the driver-app account (drivers row + PIN) so they can share the bus location.
        $driver = null;
        $crew = in_array((string) ($app['role_wanted'] ?? ''), self::CREW_ROLES, true) && $role === self::CREW_LOGIN_ROLE
            ? (string) $app['role_wanted'] : self::crewRole($adminId);
        if ($crew !== '') {
            try {
                if (self::crewRole($adminId) !== $crew) {
                    self::setCrewRole($adminId, $crew);
                }
                $driver = self::linkDriver($adminId, $by, true, false);
            } catch (Throwable $e) {
                Logger::warning('Approve: driver app account not made: ' . $e->getMessage(), [], 'agent');
            }
        }

        Database::update('ap_applications', [
            'status' => 'approved', 'decided_by' => $by ?: null, 'decided_at' => date('Y-m-d H:i:s'),
        ], 'id = :i', ['i' => $appId]);
        Logger::audit('agentpro.approved', 'admin', (string) $adminId, ['status' => 'submitted'],
            ['status' => 'approved', 'code' => $code, 'ref' => $app['ref'], 'driver' => $driver['driverId'] ?? null], 'Approved in one tap by admin #' . $by);

        self::sendWelcome($adminId, $password, $driver['pin'] ?? null);

        return ['admin_id' => $adminId, 'code' => $code] + ($password !== null ? ['password' => $password] : [])
            + ($driver !== null ? ['driver_id' => $driver['driverId'], 'driver_pin' => $driver['pin']] : []);
    }

    /** Welcome on WhatsApp: who they are now, how to sign in, the ID card itself. */
    public static function sendWelcome(int $adminId, ?string $password = null, ?string $driverPin = null): bool
    {
        $mem = self::member($adminId);
        if ($mem === null || $mem['phone'] === '') {
            return false;
        }
        require_once __DIR__ . '/notify.php';
        $company = Settings::getString('company_name', APP_NAME);
        $lines = [
            '🎉 ' . $company . ' मा स्वागत छ, ' . $mem['name'] . '!',
            'Welcome to ' . $company . '.',
            '',
            '🪪 ID: ' . ($mem['idNo'] !== '' ? $mem['idNo'] : '—') . ' · ' . $mem['designation'],
        ];
        if ($mem['isAgent']) {
            $lines[] = '📱 आफ्नो ID, visiting card र wallet: ' . appUrl('me/');
            $lines[] = '🔐 लगइन: एजेन्ट कोड ' . $mem['idNo'] . ' + यो मोबाइल + WhatsApp OTP';
            $lines[] = '';
            $lines[] = '💬 यही WhatsApp मा लेख्नुहोस्: "help" — टिकट, balance, statement सबै यहीँबाट।';
        } else {
            $lines[] = '📱 ' . appUrl('me/');
            $lines[] = '🔐 Username: ' . Database::scalar('SELECT username FROM admins WHERE id = :i', ['i' => $adminId], '');
            if ($password !== null) {
                $lines[] = '🔑 Temporary password: ' . $password . ' (पहिलो लगइनमा बदल्नुहोस् / change it at first sign-in)';
            }
            $lines[] = '🌐 ' . appUrl('admin/login.php');
        }
        if (($mem['crewRole'] ?? '') !== '') {
            $lines[] = '';
            if ($driverPin !== null) {
                foreach (self::driverPinLines($mem, $driverPin) as $l) {
                    $lines[] = $l;
                }
            } else {
                $lines[] = '🚌 बस लोकेसन / Driver app: ' . appUrl('driver/') . ' — मोबाइल + PIN (अफिसबाट), वा /me/ मा "🚌 बस लोकेसन सुरु"।';
            }
        }
        $media = null;
        try {
            require_once __DIR__ . '/agentpro-card.php';
            $media = ApCard::signedUrl($adminId, 'id-front', 86400 * 3);
        } catch (Throwable $e) {
            $media = null;
        }
        $country = $mem['countryCode'] === '977' ? 'NP' : 'IN';
        $ok = Notify::whatsapp((string) $mem['phone'], implode("\n", $lines), $media, $country);
        return $ok !== false;
    }

    public static function reject(int $appId, int $by, string $note): void
    {
        $app = self::application($appId);
        if ($app === null) {
            throw new RuntimeException('Application not found.');
        }
        if ($app['status'] !== 'submitted') {
            throw new RuntimeException('Only a submitted application can be rejected.');
        }
        $note = Security::clean($note, 255);
        if ($note === '') {
            throw new RuntimeException('Write one line for the applicant: why, or what to fix.');
        }
        Database::update('ap_applications', [
            'status' => 'rejected', 'decided_by' => $by ?: null, 'decided_at' => date('Y-m-d H:i:s'), 'decision_note' => $note,
        ], 'id = :i', ['i' => $appId]);
        // The inactive account goes, so the number can apply again. It never sold anything.
        if (!empty($app['admin_id'])) {
            $id = (int) $app['admin_id'];
            $active = (int) Database::scalar('SELECT is_active FROM admins WHERE id = :i', ['i' => $id], 1);
            $sold = Database::exists('SELECT 1 FROM agent_ledger WHERE agent_admin_id = :i LIMIT 1', ['i' => $id]);
            if ($active === 0 && !$sold) {
                try { AgentWallet::setAgentCode($id, null, $by); } catch (Throwable $e) {}
                Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]);
                Database::delete('ap_members', 'admin_id = :a', ['a' => $id]);
                Database::run("DELETE FROM admins WHERE id = :i AND is_active = 0 AND role <> 'superadmin'", ['i' => $id]);
                Database::update('ap_applications', ['admin_id' => null], 'id = :i', ['i' => $appId]);
            }
        }
        Logger::audit('agentpro.rejected', 'application', (string) $app['ref'], null, ['note' => $note], 'Rejected by admin #' . $by);
        try {
            require_once __DIR__ . '/notify.php';
            $country = $app['country'] === 'NP' ? 'NP' : 'IN';
            Notify::whatsapp((string) $app['phone'],
                '🙏 ' . Settings::getString('company_name', APP_NAME) . ' — आवेदन ' . $app['ref'] . "\n"
                . 'अहिले स्वीकृत हुन सकेन / could not be approved yet:' . "\n" . $note . "\n\n"
                . 'मिलाएर फेरि पठाउनुहोस् / fix and apply again: ' . appUrl('join.php'), null, $country);
        } catch (Throwable $e) {
            // the office still sees the reason on screen
        }
    }

    /**
     * Applications for the office, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function applications(string $status = 'submitted', int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        if (!in_array($status, ['draft', 'submitted', 'approved', 'rejected'], true)) {
            $status = 'submitted';
        }
        return Database::fetchAll(
            'SELECT * FROM ap_applications WHERE status = :s ORDER BY COALESCE(submitted_at, created_at) DESC, id DESC LIMIT ' . $limit,
            ['s' => $status]
        );
    }

    /* =================================================================
     *  Staff invites
     * ================================================================= */

    /** @return array{token: string, link: string, expires: string} */
    public static function createInvite(string $role, string $designation, int $by, int $days = 7, string $note = ''): array
    {
        if (!in_array($role, self::INVITE_ROLES, true)) {
            throw new RuntimeException('Choose a role for the invitation.');
        }
        $token = self::newToken();
        $exp = date('Y-m-d H:i:s', time() + max(1, min(30, $days)) * 86400);
        Database::insert('ap_invites', [
            'token' => $token, 'role' => $role, 'designation' => Security::clean($designation, 80) ?: null,
            'note' => Security::clean($note, 160) ?: null, 'created_by' => $by ?: null, 'expires_at' => $exp,
        ]);
        Logger::audit('agentpro.invite', 'invite', $token, null, ['role' => $role], 'Staff invitation created by admin #' . $by);
        return ['token' => $token, 'link' => appUrl('join.php?invite=' . $token), 'expires' => $exp];
    }

    /** @return array<string, mixed>|null an unused, unexpired invite */
    public static function invite(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{24}$/', $token) !== 1) {
            return null;
        }
        return Database::fetch(
            'SELECT * FROM ap_invites WHERE token = :t AND used_at IS NULL AND expires_at > NOW()',
            ['t' => $token]
        );
    }

    /* =================================================================
     *  Money the member paid in ("jama") — waiting for the office
     * ================================================================= */

    /**
     * @param array<string, mixed>|null $proof a $_FILES entry, optional
     */
    public static function requestDeposit(int $adminId, float $amount, string $purpose, string $method,
                                          string $utr = '', ?array $proof = null, string $note = ''): int
    {
        $amount = round($amount, 2);
        if ($amount < 1) {
            throw new RuntimeException('Enter the amount you paid.');
        }
        if ($amount > 500000) {
            throw new RuntimeException('That amount is too large for one entry — split it, or record it at the office.');
        }
        $purpose = $purpose === 'security' ? 'security' : 'handover';
        $method  = in_array($method, ['upi', 'bank', 'cash', 'esewa', 'khalti', 'other'], true) ? $method : 'upi';
        $utr     = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $utr) ?? '');
        if (in_array($method, ['upi', 'bank'], true) && strlen($utr) < 6 && ($proof === null || ($proof['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
            throw new RuntimeException('Add the UPI / bank reference (UTR) or a screenshot, so the office can find the payment.');
        }
        if ($utr !== '' && Database::exists("SELECT 1 FROM ap_deposit_requests WHERE utr = :u AND status <> 'declined'", ['u' => $utr])) {
            throw new RuntimeException('That reference was already sent.');
        }
        if (count(self::openDeposits($adminId)) >= 3) {
            throw new RuntimeException('You already have 3 payments waiting for the office. Please wait for them first.');
        }
        $path = null;
        if ($proof !== null && ($proof['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $path = self::storePrivate($proof)['path'];
        }
        $id = Database::insert('ap_deposit_requests', [
            'admin_id' => $adminId, 'amount' => $amount, 'purpose' => $purpose, 'method' => $method,
            'utr' => $utr !== '' ? $utr : null, 'proof_path' => $path, 'note' => Security::clean($note, 200) ?: null,
        ]);
        Logger::audit('agentpro.deposit_request', 'admin', (string) $adminId, null,
            ['amount' => $amount, 'purpose' => $purpose, 'method' => $method, 'utr' => $utr], 'Member says they paid the company');
        return $id;
    }

    /** @return list<array<string, mixed>> */
    public static function openDeposits(?int $adminId = null): array
    {
        if (!self::available()) {
            return [];
        }
        $where = "d.status = 'open'";
        $p = [];
        if ($adminId !== null) {
            $where .= ' AND d.admin_id = :a';
            $p['a'] = $adminId;
        }
        return Database::fetchAll(
            "SELECT d.*, a.full_name AS member_name FROM ap_deposit_requests d
               LEFT JOIN admins a ON a.id = d.admin_id WHERE $where ORDER BY d.id ASC LIMIT 300",
            $p
        );
    }

    /** @return list<array<string, mixed>> */
    public static function deposits(int $adminId, int $limit = 30): array
    {
        return Database::fetchAll(
            'SELECT * FROM ap_deposit_requests WHERE admin_id = :a ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            ['a' => $adminId]
        );
    }

    /**
     * The office saw the money: write it to the wallet (cash handed over,
     * or a security deposit), or decline with a reason.
     */
    public static function decideDeposit(int $id, string $decision, int $by, string $note = ''): void
    {
        $row = Database::fetch('SELECT * FROM ap_deposit_requests WHERE id = :i', ['i' => $id]);
        if ($row === null) {
            throw new RuntimeException('That payment entry no longer exists.');
        }
        if ($row['status'] !== 'open') {
            throw new RuntimeException('That payment was already ' . $row['status'] . '.');
        }
        $adminId = (int) $row['admin_id'];
        $amount  = (float) $row['amount'];
        $ledgerId = null;
        if ($decision === 'confirmed') {
            $ref = (string) ($row['utr'] ?? '');
            if ($row['purpose'] === 'security') {
                AgentWallet::recordDeposit($adminId, $amount, $by, 'Security deposit (' . $row['method'] . ')', $ref);
            } else {
                $held = AgentWallet::balances($adminId)['cash'];
                if ($amount > $held + 0.009) {
                    throw new RuntimeException('This member holds only ' . inr($held) . ' of company cash. Record the rest as a security deposit, or decline.');
                }
                $ledgerId = AgentWallet::record($adminId, 'cash_handover', $amount, [
                    'note' => 'Paid by ' . strtoupper((string) $row['method']) . ($note !== '' ? ' · ' . $note : ''),
                    'ref'  => $ref, 'by' => $by,
                ]);
            }
        } elseif ($decision === 'declined') {
            if (trim($note) === '') {
                throw new RuntimeException('Tell the member why (for example: not found in the bank).');
            }
        } else {
            throw new RuntimeException('Confirm or decline.');
        }
        Database::update('ap_deposit_requests', [
            'status' => $decision, 'decided_by' => $by ?: null, 'decided_at' => date('Y-m-d H:i:s'),
            'decided_note' => Security::clean($note, 255) ?: null, 'ledger_id' => $ledgerId,
        ], 'id = :i', ['i' => $id]);
        Logger::audit('agentpro.deposit_' . $decision, 'admin', (string) $adminId, null,
            ['request' => $id, 'amount' => $amount, 'ledger' => $ledgerId], 'Payment ' . $decision . ' by admin #' . $by);
        self::tellMember($adminId, $decision === 'confirmed'
            ? '✅ ' . inr($amount) . ' प्राप्त भयो, धन्यवाद। / received, thank you.' . ($row['utr'] ? ' Ref ' . $row['utr'] : '')
            : '⚠️ ' . inr($amount) . ' भुक्तानी भेटिएन / not matched: ' . $note);
    }

    /**
     * Pay an open withdrawal in one step: the ledger payout + the request
     * marked paid with that voucher, then a WhatsApp to the member.
     */
    public static function payWithdrawal(int $requestId, int $by, string $ref = '', string $note = ''): int
    {
        $req = Database::fetch('SELECT * FROM agent_payout_requests WHERE id = :i', ['i' => $requestId]);
        if ($req === null || $req['status'] !== 'open') {
            throw new RuntimeException('That withdrawal request is not open any more.');
        }
        $adminId = (int) $req['agent_admin_id'];
        $amount  = (float) $req['amount'];
        // request_id: record() must not count THIS request's own amount as "reserved" (it is the one being paid).
        $ledgerId = AgentWallet::record($adminId, 'payout', $amount, [
            'note' => 'Withdrawal request #' . $requestId . ($note !== '' ? ' · ' . $note : ''), 'ref' => $ref, 'by' => $by,
            'request_id' => $requestId,
        ]);
        AgentWallet::decidePayoutRequest($requestId, 'paid', $by, $note, $ledgerId);
        $voucher = AgentWallet::voucherFor($ledgerId);
        self::tellMember($adminId, '💸 ' . inr($amount) . ' पठाइयो / paid to you. Ref ' . $voucher);
        return $ledgerId;
    }

    public static function declineWithdrawal(int $requestId, int $by, string $note): void
    {
        $req = Database::fetch('SELECT * FROM agent_payout_requests WHERE id = :i', ['i' => $requestId]);
        AgentWallet::decidePayoutRequest($requestId, 'declined', $by, $note);
        if ($req !== null) {
            self::tellMember((int) $req['agent_admin_id'], '⚠️ ' . inr((float) $req['amount']) . ' withdrawal: ' . $note);
        }
    }

    /** A short WhatsApp line to a member about their money / account. Never throws. */
    public static function tellMember(int $adminId, string $text): void
    {
        try {
            $m = self::member($adminId, false);
            if ($m === null || $m['phone'] === '') {
                return;
            }
            require_once __DIR__ . '/notify.php';
            Notify::whatsapp((string) $m['phone'], Settings::getString('company_name', APP_NAME) . "\n" . $text, null,
                $m['countryCode'] === '977' ? 'NP' : 'IN');
        } catch (Throwable $e) {
            Logger::warning('Member notice failed: ' . $e->getMessage(), [], 'agent');
        }
    }

    /* =================================================================
     *  The team, for the office
     * ================================================================= */

    /**
     * Every member of staff with what the Team screen groups and filters on.
     *
     * @return list<array<string, mixed>>
     */
    public static function roster(): array
    {
        $rows = Database::fetchAll(
            "SELECT a.id, a.username, a.full_name, a.phone, a.role, a.is_active, a.locked_until, a.created_at, a.last_login_at,
                    p.agent_kind, p.photo_path, p.counter_name, p.kyc_status, p.whatsapp, p.suspended_reason
               FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id
              ORDER BY a.is_active DESC, a.role, a.full_name"
        );
        $members = [];
        if (self::available()) {
            $crewCol = self::memberHas('crew_role') ? ', crew_role' : '';
            foreach (Database::fetchAll('SELECT admin_id, staff_no, designation, card_status, card_valid_until, photo_ver' . $crewCol . ' FROM ap_members') as $m) {
                $members[(int) $m['admin_id']] = $m;
            }
            if ($crewCol === '') {   // before the agentpro2 migration: the crew role of the application
                foreach (Database::fetchAll("SELECT ap.admin_id, ap.role_wanted FROM ap_applications ap JOIN admins a ON a.id = ap.admin_id
                                              WHERE ap.role_wanted IN ('driver','conductor') AND a.role = :r AND ap.status IN ('submitted','approved')",
                                             ['r' => self::CREW_LOGIN_ROLE]) as $r) {
                    $members[(int) $r['admin_id']]['crew_role'] = (string) $r['role_wanted'];
                }
            }
        }
        $pending = [];
        if (self::available()) {
            foreach (Database::fetchAll("SELECT admin_id, ref FROM ap_applications WHERE status = 'submitted' AND admin_id IS NOT NULL") as $r) {
                $pending[(int) $r['admin_id']] = (string) $r['ref'];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $m = $members[$id] ?? [];
            $isAgent = $r['role'] === 'agent';
            $kind = $isAgent ? AgentWallet::normaliseKind((string) ($r['agent_kind'] ?? 'person')) : '';
            $idNo = $isAgent ? AgentWallet::agentCodeLabel($id)
                : (!empty($m['staff_no']) ? 'SHG-S-' . str_pad((string) (int) $m['staff_no'], 4, '0', STR_PAD_LEFT) : '');
            $photo = (string) ($r['photo_path'] ?? '');
            $crew = !$isAgent && in_array((string) ($m['crew_role'] ?? ''), self::CREW_ROLES, true) ? (string) $m['crew_role'] : '';
            $roleKey = $crew !== '' ? $crew : (string) $r['role'];
            $out[] = [
                'id'        => $id,
                'name'      => (string) $r['full_name'],
                'phone'     => (string) ($r['phone'] ?? ''),
                'role'      => (string) $r['role'],
                'crew'      => $crew,
                'roleLabel' => self::ROLE_LABELS[$roleKey] ?? ucfirst($roleKey),
                'hasMember' => $m !== [] && isset($m['admin_id']),
                'isAgent'   => $isAgent,
                'kind'      => $kind,
                'idNo'      => $idNo,
                'designation' => trim((string) ($m['designation'] ?? '')) ?: (self::DESIGNATION_DEFAULTS[$isAgent ? 'agent:' . $kind : $roleKey] ?? ucfirst($roleKey)),
                'active'    => (int) $r['is_active'] === 1,
                'pendingRef'=> $pending[$id] ?? '',
                'kyc'       => (string) ($r['kyc_status'] ?? 'none'),
                'branch'    => (string) ($r['counter_name'] ?? ''),
                'photoUrl'  => ($photo !== '' && str_starts_with($photo, 'agents/')) ? '/uploads/' . $photo . '?v=' . (int) ($m['photo_ver'] ?? 1) : '',
                'card'      => (string) ($m['card_status'] ?? ''),
                'validUntil'=> (string) ($m['card_valid_until'] ?? ''),
                'suspended' => trim((string) ($r['suspended_reason'] ?? '')) !== ''
                               || (!empty($r['locked_until']) && strtotime((string) $r['locked_until']) > time()),
                'lastLogin' => (string) ($r['last_login_at'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Live numbers for the Control Center tiles. Every count is guarded —
     * a table that is not there yet reads 0, never a 500.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $q = static function (string $sql): int {
            try {
                return (int) Database::scalar($sql, [], 0);
            } catch (Throwable $e) {
                return 0;
            }
        };
        $ap = self::available();
        return [
            'applications' => $ap ? $q("SELECT COUNT(*) FROM ap_applications WHERE status = 'submitted'") : 0,
            'withdrawals'  => $q("SELECT COUNT(*) FROM agent_payout_requests WHERE status = 'open'"),
            'deposits'     => $ap ? $q("SELECT COUNT(*) FROM ap_deposit_requests WHERE status = 'open'") : 0,
            'agents'       => $q("SELECT COUNT(*) FROM admins WHERE role = 'agent' AND is_active = 1"),
            'staff'        => $q("SELECT COUNT(*) FROM admins WHERE role <> 'agent' AND is_active = 1"),
            'kycWaiting'   => $q("SELECT COUNT(*) FROM admin_profiles WHERE kyc_status = 'submitted'"),
            'pendingPay'   => $q("SELECT COUNT(*) FROM bookings WHERE status = 'pending'"),
            'todayTickets' => $q("SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed','completed') AND DATE(created_at) = CURDATE()"),
            'aiQueue'      => $ap ? $q("SELECT COUNT(*) FROM ap_jobs WHERE status IN ('queued','running')") : 0,
            'liveBuses'    => $q("SELECT COUNT(*) FROM fleet_trips WHERE status = 'running' AND last_ping_at > (NOW() - INTERVAL 3 MINUTE)"),
            'waLinks'      => $ap ? $q('SELECT COUNT(*) FROM ap_wa_links WHERE revoked_at IS NULL') : 0,
        ];
    }
}
