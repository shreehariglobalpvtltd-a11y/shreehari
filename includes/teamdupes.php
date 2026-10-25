<?php
/**
 * =====================================================================
 *  TeamDupes — "is this person already with us?" (23 Oct 2026).
 *
 *  Owner prompt (registration system, point 9): before the office decides,
 *  check the ID number, the mobile and the name + date of birth against
 *  every other agent / partner / staff file and WARN — the office decides.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class TeamDupes
{
    public const SCAN_MAX = 5000;
    public const WHY = [
        'doc'      => 'same ID number',
        'phone'    => 'same mobile',
        'name_dob' => 'same name + date of birth',
    ];

    private static ?array $all = null;

    private function __construct() {}

    /**
     * @return array<int, array{kind: string, id: int, ref: string, name: string, status: string, why: array<int, string>, url: string}>
     */
    public static function lookalikes(string $kind, int $id): array
    {
        $me = null;
        foreach (self::all() as $r) {
            if ($r['kind'] === $kind && (int) $r['id'] === $id) {
                $me = $r;
                break;
            }
        }
        if ($me === null) {
            return [];
        }
        $out = [];
        foreach (self::all() as $r) {
            if ($r['kind'] === $kind && (int) $r['id'] === $id) {
                continue;
            }
            $why = [];
            if ($me['doc'] !== '' && $me['doc'] === $r['doc']) {
                $why[] = 'doc';
            }
            if ($me['phone'] !== '' && $me['phone'] === $r['phone']) {
                $why[] = 'phone';
            }
            if ($me['dob'] !== '' && $me['dob'] === $r['dob'] && $me['nm'] !== '' && $me['nm'] === $r['nm']) {
                $why[] = 'name_dob';
            }
            if ($why !== []) {
                $out[] = [
                    'kind' => $r['kind'], 'id' => (int) $r['id'], 'ref' => $r['ref'], 'name' => $r['name'],
                    'status' => $r['status'], 'why' => $why,
                    'url' => 'team.php?tab=approvals&ref=' . urlencode($r['ref']),
                ];
            }
        }
        usort($out, static fn(array $a, array $b): int => [in_array('doc', $b['why'], true), count($b['why'])] <=> [in_array('doc', $a['why'], true), count($a['why'])]);
        return array_slice($out, 0, 12);
    }

    public static function html(string $kind, int $id): string
    {
        try {
            $hits = self::lookalikes($kind, $id);
        } catch (Throwable $e) {
            Logger::warning('TeamDupes: ' . $e->getMessage(), [], 'agreements');
            return '';
        }
        if ($hits === []) {
            return '';
        }
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $li = [];
        foreach ($hits as $x) {
            $why = implode(', ', array_map(static fn(string $w): string => self::WHY[$w] ?? $w, $x['why']));
            $li[] = '<li><a class="mono" href="' . $e($x['url']) . '">' . $e($x['ref']) . '</a> ' . $e($x['name'])
                  . ' <span class="muted text-xs">(' . $e($x['kind']) . ' · ' . $e($x['status']) . ')</span> — <b>' . $e($why) . '</b></li>';
        }
        return '<div class="team-dupes" role="note" style="margin:10px 0;padding:10px 12px;border-radius:12px;border:1px solid rgba(178,106,0,.45);background:rgba(178,106,0,.08)">'
             . '<b>⚠️ Looks like a person already on file</b> <span class="muted text-xs">— compare before you approve; nothing is blocked</span>'
             . '<ul style="margin:6px 0 0;padding-left:18px">' . implode('', $li) . '</ul></div>';
    }

    public static function foldDoc(string $s): string
    {
        $d = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', strtr($s, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9'])) ?? '');
        return strlen($d) >= 5 ? $d : '';
    }

    public static function foldPhone(string $s): string
    {
        $d = preg_replace('/\D/', '', $s) ?? '';
        return strlen($d) >= 10 ? substr($d, -10) : '';
    }

    public static function foldName(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/^(mr|mrs|ms|miss|shri|sri|smt)\.?\s+/u', '', $s) ?? $s;
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? '';
    }

    public static function foldDob(string $s): string
    {
        $s = trim($s);
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) === 1 && $s !== '0000-00-00' ? $m[1] . '-' . $m[2] . '-' . $m[3] : '';
    }

    private static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $all = [];
        if (class_exists('AgentApplications') && AgentApplications::available()) {
            foreach (Database::fetchAll('SELECT id, ref, type, name, phone, status, fields_json FROM agent_applications ORDER BY id DESC LIMIT ' . self::SCAN_MAX) as $r) {
                $f = json_decode((string) ($r['fields_json'] ?? ''), true);
                $f = is_array($f) ? $f : [];
                $all[] = [
                    'kind' => (string) $r['type'] === 'org' ? 'partner' : 'agent', 'id' => (string) $r['id'],
                    'ref' => (string) ($r['ref'] ?: AgentApplications::refFor((int) $r['id'])), 'name' => (string) $r['name'],
                    'status' => (string) $r['status'],
                    'doc' => self::foldDoc((string) ($f['p_idno'] ?? '')), 'phone' => self::foldPhone((string) $r['phone']),
                    'nm' => self::foldName((string) $r['name']), 'dob' => self::foldDob((string) ($f['p_dob'] ?? '')),
                ];
            }
        }
        if (class_exists('StaffApps') && StaffApps::available()) {
            foreach (Database::fetchAll('SELECT id, full_name, phone, status, id_number, dob FROM staff_applications ORDER BY id DESC LIMIT ' . self::SCAN_MAX) as $r) {
                $all[] = [
                    'kind' => 'staff', 'id' => (string) $r['id'], 'ref' => StaffApps::refFor((int) $r['id']), 'name' => (string) $r['full_name'],
                    'status' => (string) $r['status'],
                    'doc' => self::foldDoc((string) ($r['id_number'] ?? '')), 'phone' => self::foldPhone((string) $r['phone']),
                    'nm' => self::foldName((string) $r['full_name']), 'dob' => self::foldDob((string) ($r['dob'] ?? '')),
                ];
            }
        }
        return self::$all = $all;
    }

    public static function reset(): void
    {
        self::$all = null;
    }
}
