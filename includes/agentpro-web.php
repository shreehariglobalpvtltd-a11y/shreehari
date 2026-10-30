<?php
/**
 * =====================================================================
 *  agentpro-web.php — ApWeb: approved AgentPro members on the home page
 *  (31 Oct 2026, owner: "live website ma jas-jasle form bharera verified
 *  admin le dinchha, live ma home page ma photo haru dekhiyos").
 *
 *  #homeRoles (assets/js/34-roles.js) reads three public APIs that knew
 *  only the OLDER forms (agent_applications.web_live,
 *  staff_applications.show_on_website). An AgentPro member (admins +
 *  admin_profiles + ap_members, approved in Admin -> Team) now joins them:
 *
 *    shown($id)          on the website? The automatic rule: login active,
 *                        ID card active, not archived / suspended, not the
 *                        super-admin, a photo on file, and checked by the
 *                        office (an approved join form, or KYC verified).
 *                        ap_members.web_show (upgrade-2026-10-31-idc-web.sql):
 *                        NULL automatic · 1 always (skips the office check
 *                        and the super-admin rule - still needs the active
 *                        login + card + photo) · 0 never.
 *    shownMany($ids)     the same for a list of ids (admin screens); flush() forgets
 *                        what this request read.
 *    status($id)         the verdict with its reason, for the admin toggle.
 *    set($id, $v, $by)   the toggle (audited).
 *    list($kind, $n)     /api/top-people.php shape  {name, role, area, photo, phone, tel, wa, metric}
 *    directory()         /api/agent-directory.php shape (agents + partner agencies)
 *    staffDirectory()    /api/staff-list.php shape
 *    merge($a, $b, $n)   AgentPro first, then the older list, one card per
 *                        person (same last 10 phone digits or same name).
 *    photoFor($key) + jpeg($abs)   the 400 px JPEG behind /api/ap-photo.php?k=…
 *
 *  Public fields only, built key by key: the name, the role / designation,
 *  the branch (admin_profiles.counter_name), the photo through ap-photo.php
 *  (an HMAC key - never the admin id or the file name) and the visiting-card
 *  number (AgentPro::member()['wa']: a visiting card is a public business
 *  card). Never an ID number, home address, date of birth, bank detail,
 *  email or login name.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/agentpro.php';

final class ApWeb
{
    /** agent = an agent person, partner = an agency (agent_kind org), staff = every other role. */
    public const KINDS = ['agent', 'partner', 'staff'];

    /** The settings map TeamPurge / TeamManager keep archived logins in ('account:<id>'). */
    private const ARCHIVE_KEY = 'team_manager_archive';

    /** Longest edge of the public photo. */
    private const PHOTO_EDGE = 400;

    /** Nepali role names for the cards; the English side is the member's designation. */
    private const ROLE_NE = [
        'agent' => 'एजेन्ट', 'counter' => 'काउन्टर', 'manager' => 'म्यानेजर', 'accountant' => 'लेखापाल',
        'support' => 'ग्राहक सेवा', 'scanner' => 'बोर्डिङ स्टाफ', 'official' => 'अधिकारी', 'operations' => 'सञ्चालन',
        'viewer' => 'कर्मचारी', 'superadmin' => 'सञ्चालक', 'driver' => 'चालक', 'conductor' => 'कन्डक्टर',
    ];

    /** Why someone is (not) on the website — what the admin toggle prints. */
    public const REASONS = [
        'shown'      => 'वेबसाइटमा देखिन्छ · Shown on the website',
        'hidden'     => 'कार्यालयले लुकाएको · Hidden by the office',
        'archived'   => 'अभिलेखमा राखिएको · Archived',
        'login_off'  => 'लगइन बन्द · Login is off',
        'card'       => 'ID कार्ड सक्रिय छैन · ID card not active',
        'suspended'  => 'निलम्बित · Suspended',
        'no_photo'   => 'फोटो छैन · No photo yet',
        'superadmin' => 'सुपर-एडमिन आफैं देखिँदैन · Super-admin is not shown automatically',
        'unverified' => 'कार्यालय स्वीकृति / KYC बाँकी · Waiting for office approval or KYC',
        'desk'       => 'कम्पनीको आफ्नै काउन्टर कोड · Company desk code',
        'not_member' => 'AgentPro सदस्य होइन · Not an AgentPro member',
    ];

    /** @var array<int, array<string, mixed>>|null admin id => member row + verdict, read once per request */
    private static ?array $rows = null;

    /** @var list<array<string, mixed>>|null the shown members, ranked, read once per request */
    private static ?array $people = null;

    /** @var array<string, true>|null */
    private static ?array $profCols = null;

    /* =================================================================
     *  Who is on the website
     * ================================================================= */

    /** Has the web_show column (the admin toggle) been installed? */
    public static function ready(): bool
    {
        return AgentPro::available() && AgentPro::memberHas('web_show');
    }

    /**
     * The photo key in /api/ap-photo.php?k=…: 20 hex of an HMAC over the card
     * token AND the photo (version + file), so a new photo is a new URL - the
     * day-long browser cache never shows the old face. A re-issued card (new
     * token) also changes it.
     */
    public static function key(string $cardToken, int $photoVer = 1, string $photoPath = ''): string
    {
        return substr(hash_hmac('sha256', 'web:' . $cardToken . '|' . $photoVer . '|' . $photoPath, APP_KEY), 0, 20);
    }

    /** Forget what this request read (after changing a login, card or photo in the same request). */
    public static function flush(): void
    {
        self::$rows = null;
        self::$people = null;
    }

    public static function shown(int $adminId): bool
    {
        return (self::rows()[$adminId]['why'] ?? '') === 'shown';
    }

    /**
     * @param list<int> $adminIds
     * @return array<int, bool> admin id => on the website
     */
    public static function shownMany(array $adminIds): array
    {
        $rows = self::rows();
        $out = [];
        foreach ($adminIds as $id) {
            $out[(int) $id] = ($rows[(int) $id]['why'] ?? '') === 'shown';
        }
        return $out;
    }

    /**
     * What the admin toggle shows.
     *   mode   'auto' (web_show NULL) · 'show' (1) · 'hide' (0)
     *   value  the stored web_show: null / 1 / 0
     *   shown  on the website right now
     *   why    a REASONS key; reason = its bilingual text
     *   ready  false until the migration ran (then the toggle cannot be saved)
     *
     * @return array{mode: string, value: ?int, shown: bool, why: string, reason: string, ready: bool}
     */
    public static function status(int $adminId): array
    {
        $r = self::rows()[$adminId] ?? null;
        $value = $r === null || $r['web_show'] === null ? null : (int) $r['web_show'];
        $why = $r === null ? 'not_member' : (string) $r['why'];
        return [
            'mode'   => $value === null ? 'auto' : ($value === 1 ? 'show' : 'hide'),
            'value'  => $value,
            'shown'  => $why === 'shown',
            'why'    => $why,
            'reason' => self::REASONS[$why] ?? $why,
            'ready'  => self::ready(),
        ];
    }

    /** The admin toggle: null = automatic, 1 = always show, 0 = hide. */
    public static function set(int $adminId, ?int $value, int $by): void
    {
        if ($value !== null && $value !== 0 && $value !== 1) {
            throw new InvalidArgumentException('web_show must be null, 0 or 1.');
        }
        if (!self::ready()) {
            throw new RuntimeException('Run database/upgrade-2026-10-31-idc-web.sql first.');
        }
        $admin = Database::fetch('SELECT id, role FROM admins WHERE id = :a', ['a' => $adminId]);
        if ($admin === null) {
            throw new RuntimeException('No such member.');
        }
        $row = AgentPro::ensureMember($adminId, (string) $admin['role']);
        $old = isset($row['web_show']) && $row['web_show'] !== null ? (int) $row['web_show'] : null;
        Database::update('ap_members', ['web_show' => $value], 'admin_id = :a', ['a' => $adminId]);
        self::flush();
        if ($old !== $value) {
            $mode = $value === null ? 'automatic' : ($value === 1 ? 'always show' : 'hidden');
            Logger::audit('agentpro.web_show', 'admin', (string) $adminId, ['web_show' => $old], ['web_show' => $value],
                'Home page: ' . $mode . ($by > 0 ? ' by admin #' . $by : ''));
        }
    }

    /* =================================================================
     *  The public lists (the exact shapes of the three APIs)
     * ================================================================= */

    /**
     * /api/top-people.php: best first (passengers sold in 90 days, then the newest).
     *
     * @return list<array{name: string, role: string, area: string, photo: string, phone: string, tel: string, wa: string, metric: string}>
     */
    public static function list(string $kind, int $limit = 0): array
    {
        $out = [];
        foreach (self::people() as $p) {
            if ($p['kind'] !== $kind) {
                continue;
            }
            $out[] = [
                'name'   => $p['name'],
                'role'   => self::roleText($p),
                'area'   => $p['area'],
                'photo'  => $p['photo'],
                'phone'  => $p['waDisplay'],
                'tel'    => $p['wa'] !== '' ? '+' . $p['wa'] : '',
                'wa'     => $p['wa'],
                'metric' => self::metric($p),
            ];
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * /api/agent-directory.php: agents and partner agencies. addr / logo stay
     * empty (admin_profiles.address is the home address from the join form).
     *
     * @return list<array{name: string, kind: string, area: string, phone: string, verified: bool, signed: bool, addr: string, photo: string, logo: string, wa: string}>
     */
    public static function directory(): array
    {
        $out = [];
        foreach (self::people() as $p) {
            if ($p['kind'] === 'staff') {
                continue;
            }
            $out[] = [
                'name'     => $p['name'],
                'kind'     => $p['kind'] === 'partner' ? 'partner' : 'agent',
                'area'     => $p['area'],
                'phone'    => $p['waDisplay'],
                'verified' => $p['verified'],
                'signed'   => $p['signed'],
                'addr'     => '',
                'photo'    => $p['photo'],
                'logo'     => '',
                'wa'       => $p['wa'],
            ];
        }
        return $out;
    }

    /**
     * /api/staff-list.php: role = the AgentPro role key (crew: driver /
     * conductor), roleEn = the designation, loc = the branch.
     *
     * @return list<array{name: string, role: string, roleEn: string, roleNe: string, district: string, loc: string, photo: string, phone: string, tel: string, wa: string}>
     */
    public static function staffDirectory(): array
    {
        $out = [];
        foreach (self::people() as $p) {
            if ($p['kind'] !== 'staff') {
                continue;
            }
            $out[] = [
                'name'     => $p['name'],
                'role'     => $p['roleKey'],
                'roleEn'   => $p['designation'],
                'roleNe'   => self::ROLE_NE[$p['roleKey']] ?? '',
                'district' => '',
                'loc'      => $p['area'],
                'photo'    => $p['photo'],
                'phone'    => $p['waDisplay'],
                'tel'      => $p['wa'] !== '' ? '+' . $p['wa'] : '',
                'wa'       => $p['wa'],
            ];
        }
        return $out;
    }

    /**
     * One card per person: $first (AgentPro) keeps its place, then $then (the
     * older list) without anyone already there - the same last 10 phone digits
     * (from wa / tel / an unmasked phone) or the same name, case-insensitive.
     *
     * @param list<array<string, mixed>> $first
     * @param list<array<string, mixed>> $then
     * @return list<array<string, mixed>>
     */
    public static function merge(array $first, array $then, int $max = 0): array
    {
        $phones = [];
        $names = [];
        $out = [];
        foreach ([$first, $then] as $list) {
            foreach ($list as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $p = self::phoneKey($it);
                $n = self::nameKey((string) ($it['name'] ?? ''));
                if (($p !== '' && isset($phones[$p])) || ($n !== '' && isset($names[$n]))) {
                    continue;
                }
                if ($p !== '') {
                    $phones[$p] = true;
                }
                if ($n !== '') {
                    $names[$n] = true;
                }
                $out[] = $it;
                if ($max > 0 && count($out) >= $max) {
                    return $out;
                }
            }
        }
        return $out;
    }

    /* =================================================================
     *  The photo (/api/ap-photo.php)
     * ================================================================= */

    /**
     * The shown member behind a photo key: the file and its ETag. Null when the
     * key is unknown or the member is not on the website (any more).
     *
     * @return array{abs: string, etag: string}|null
     */
    public static function photoFor(string $key): ?array
    {
        if (preg_match('/^[a-f0-9]{20}$/', $key) !== 1) {
            return null;
        }
        foreach (self::rows() as $r) {
            if ($r['why'] !== 'shown' || !hash_equals((string) $r['key'], $key)) {
                continue;
            }
            $abs = self::photoAbs((string) $r['photo_path']);
            if ($abs === null) {
                return null;
            }
            return ['abs' => $abs, 'etag' => '"apw-' . (int) $r['photo_ver'] . '-' . substr(hash('sha256', $key . '|' . (int) @filemtime($abs) . '|' . (int) @filesize($abs)), 0, 12) . '"'];
        }
        return null;
    }

    /** The file as a JPEG of at most PHOTO_EDGE px (upright, no EXIF). Null when it cannot be read. */
    public static function jpeg(string $abs): ?string
    {
        $img = AgentPro::loadImage($abs);
        if ($img === null) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, self::PHOTO_EDGE / max(1, $w, $h));
        if ($scale < 1.0) {
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $small = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($small, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $small;
        }
        ob_start();
        $ok = imagejpeg($img, null, 84);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        return $ok && $bytes !== '' ? $bytes : null;
    }

    /* =================================================================
     *  Inside
     * ================================================================= */

    /**
     * Every AgentPro member with the verdict (why = a REASONS key) and the photo key.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }
        self::$rows = [];
        if (!AgentPro::available()) {
            return [];
        }
        $col = static fn(string $c, string $else): string => self::profHas($c) ? 'p.' . $c : $else . ' AS ' . $c;
        $sel = implode(', ', [
            AgentPro::memberHas('web_show') ? 'm.web_show' : 'NULL AS web_show',
            $col('kyc_status', "'none'"),
            $col('suspended_reason', 'NULL'),
            $col('agreement_status', "'none'"),
            $col('agreement_end', 'NULL'),
        ]);
        $rows = Database::fetchAll(
            "SELECT a.id, a.role, a.is_active, a.created_at, m.card_token, m.card_status, m.photo_ver,
                    p.photo_path, p.agent_kind, $sel, ap.approved_at
               FROM ap_members m
               JOIN admins a ON a.id = m.admin_id
               LEFT JOIN admin_profiles p ON p.admin_id = m.admin_id
               LEFT JOIN (SELECT admin_id, MAX(COALESCE(decided_at, updated_at)) AS approved_at
                            FROM ap_applications
                           WHERE status = 'approved' AND admin_id IS NOT NULL
                           GROUP BY admin_id) ap ON ap.admin_id = m.admin_id
              ORDER BY a.id ASC
              LIMIT 3000"
        );
        $archived = [];
        foreach (array_keys(Settings::getArray(self::ARCHIVE_KEY, [])) as $k) {
            if (preg_match('/^account:(\d+)$/', (string) $k, $mm) === 1) {
                $archived[(int) $mm[1]] = true;
            }
        }
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $r['why'] = self::judge($r, isset($archived[$id]));
            $r['key'] = self::key((string) $r['card_token'], (int) $r['photo_ver'], (string) ($r['photo_path'] ?? ''));
            self::$rows[$id] = $r;
        }
        return self::$rows;
    }

    /** @param array<string, mixed> $r */
    private static function judge(array $r, bool $archived): string
    {
        $v = $r['web_show'] === null ? null : (int) $r['web_show'];
        if ($v === 0) {
            return 'hidden';
        }
        if ($archived) {
            return 'archived';
        }
        if ((int) $r['is_active'] !== 1) {
            return 'login_off';
        }
        if ((string) $r['card_status'] !== 'active' || (string) $r['card_token'] === '') {
            return 'card';
        }
        if (trim((string) ($r['suspended_reason'] ?? '')) !== '') {
            return 'suspended';
        }
        if (self::photoAbs((string) ($r['photo_path'] ?? '')) === null) {
            return 'no_photo';
        }
        if ($v === 1) {
            return 'shown';   // the office said "always show"
        }
        if ((string) $r['role'] === 'superadmin') {
            return 'superadmin';
        }
        if (empty($r['approved_at']) && (string) ($r['kyc_status'] ?? '') !== 'verified') {
            return 'unverified';
        }
        if ((string) $r['role'] === 'agent') {
            // the company's own desk codes are not "agents" (as AgentApplications::top)
            $label = AgentWallet::agentCodeLabel((int) $r['id']);
            if ($label !== '' && AgentWallet::isCompanyCode($label)) {
                return 'desk';
            }
        }
        return 'shown';
    }

    /**
     * The shown members with their public fields, ranked: passengers sold in
     * 90 days, then the newest approval.
     *
     * @return list<array<string, mixed>>
     */
    private static function people(): array
    {
        if (self::$people !== null) {
            return self::$people;
        }
        $shown = array_filter(self::rows(), static fn(array $r): bool => $r['why'] === 'shown');
        $pax = self::pax(array_keys($shown));
        $today = date('Y-m-d');
        $out = [];
        foreach ($shown as $id => $r) {
            $m = AgentPro::member((int) $id, false);
            if ($m === null) {
                continue;
            }
            $name = trim((string) $m['name']);
            if ($name === '') {
                continue;
            }
            $end = (string) ($r['agreement_end'] ?? '');
            $out[] = [
                'id'          => (int) $id,
                'kind'        => !$m['isAgent'] ? 'staff' : ($m['isOrg'] ? 'partner' : 'agent'),
                'name'        => mb_substr($name, 0, 80, 'UTF-8'),
                'roleKey'     => (string) $m['roleKey'],
                'designation' => mb_substr(trim((string) $m['designation']), 0, 80, 'UTF-8'),
                'area'        => mb_substr(trim((string) $m['branch']), 0, 60, 'UTF-8'),
                'photo'       => '/api/ap-photo.php?k=' . $r['key'],
                'wa'          => (string) $m['wa'],
                'waDisplay'   => (string) $m['waDisplay'],
                'verified'    => (string) ($r['kyc_status'] ?? '') === 'verified',
                'signed'      => (string) ($r['agreement_status'] ?? '') === 'signed' && ($end === '' || $end >= $today),
                'pax'         => (int) ($pax[(int) $id] ?? 0),
                'since'       => (string) ($r['approved_at'] ?: $r['created_at']),
            ];
        }
        usort($out, static fn(array $x, array $y): int =>
            [$y['pax'], $y['since'], $y['id']] <=> [$x['pax'], $x['since'], $x['id']]);
        return self::$people = $out;
    }

    /**
     * Passengers on confirmed / completed tickets each member sold in 90 days -
     * counted as AgentApplications::top does (outbound seats only, released seats out).
     *
     * @param list<int> $ids
     * @return array<int, int>
     */
    private static function pax(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $params = ['since' => date('Y-m-d 00:00:00', strtotime('-90 days'))];
        $in = [];
        foreach (array_values($ids) as $i => $id) {
            $in[] = ':a' . $i;
            $params['a' . $i] = (int) $id;
        }
        try {
            $rows = Database::fetchAll(
                'SELECT b.sold_by_admin_id AS admin_id, COUNT(bs.id) AS pax
                   FROM bookings b
                   JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = \'outbound\'
                   JOIN booking_seats bs ON bs.leg_id = bl.id AND bs.released_at IS NULL
                  WHERE b.sold_by_admin_id IN (' . implode(', ', $in) . ")
                    AND b.status IN ('confirmed', 'completed')
                    AND b.created_at >= :since
                  GROUP BY b.sold_by_admin_id",
                $params
            );
        } catch (Throwable $e) {
            Logger::warning('ApWeb pax: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['admin_id']] = (int) $r['pax'];
        }
        return $out;
    }

    /** @param array<string, mixed> $p */
    private static function roleText(array $p): string
    {
        $ne = match ($p['kind']) {
            'partner' => 'साझेदार कम्पनी',
            'agent'   => 'एजेन्ट',
            default   => self::ROLE_NE[$p['roleKey']] ?? 'कर्मचारी',
        };
        return $p['designation'] !== '' ? $ne . ' · ' . $p['designation'] : $ne;
    }

    /** @param array<string, mixed> $p */
    private static function metric(array $p): string
    {
        if ($p['kind'] === 'staff') {
            return $p['verified'] ? '✔ कार्यालय प्रमाणित · Office verified' : '';
        }
        return $p['pax'] > 0 ? $p['pax'] . ' यात्रु · ९० दिन · ' . $p['pax'] . ' passengers / 90 days' : '';
    }

    /** The photo file, only when it really is inside uploads/agents/. */
    private static function photoAbs(string $rel): ?string
    {
        if ($rel === '' || !str_starts_with($rel, 'agents/') || str_contains($rel, '..') || str_contains($rel, "\0")) {
            return null;
        }
        $dir = realpath(UPLOAD_PATH . '/agents');
        $abs = realpath(UPLOAD_PATH . '/' . $rel);
        if ($dir === false || $abs === false || !is_file($abs) || !str_starts_with($abs, $dir . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $abs;
    }

    /** @param array<string, mixed> $it */
    private static function phoneKey(array $it): string
    {
        foreach (['wa', 'tel', 'phone'] as $k) {
            $v = (string) ($it[$k] ?? '');
            if ($v === '' || str_contains($v, '•')) {
                continue;   // a masked number says nothing
            }
            $d = preg_replace('/\D/', '', $v) ?? '';
            if (strlen($d) >= 10) {
                return substr($d, -10);
            }
        }
        return '';
    }

    private static function nameKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)), 'UTF-8');
    }

    private static function profHas(string $col): bool
    {
        if (self::$profCols === null) {
            self::$profCols = [];
            try {
                foreach (Database::fetchAll(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_profiles'"
                ) as $r) {
                    self::$profCols[(string) $r['COLUMN_NAME']] = true;
                }
            } catch (Throwable $e) {
                // no profile table: every optional column reads as missing
            }
        }
        return isset(self::$profCols[$col]);
    }
}
