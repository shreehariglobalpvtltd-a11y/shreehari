<?php
/**
 * =====================================================================
 *  applyedit.php — the applicant corrects the form they already sent
 *  (23 Oct 2026, owner: "only digital form apply, edit ... yo form lai
 *  kei jarurat nai napros").
 *
 *  The person proves the mobile in THIS browser with the form's own
 *  WhatsApp "hi VERIFY" (WaVerify::sessionVerified), then opens the
 *  application that number sent while the office has not decided it yet.
 *  The form comes back filled; sending it again saves over the same row
 *  (AgentApplications::applicantEdit / StaffApps::applicantEdit) instead
 *  of making a second one. Files already sent stay unless a new one is
 *  taken. Nothing about anyone else is reachable: the row is looked up by
 *  the proven number only, and the open edit lives in this session.
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/agentapplications.php';
require_once __DIR__ . '/staffapplications.php';
require_once __DIR__ . '/waverify.php';

final class ApplyEdit
{
    /** How long an opened edit stays open in this browser. */
    public const OPEN_SEC = 1800;

    /** Agent / partner statuses the applicant may still change. */
    private const AGENT_OPEN = ['new', 'reviewing', 'kyc_pending', 'agreement_pending'];
    /** Staff statuses the applicant may still change. */
    private const STAFF_OPEN = ['pending', 'under_review'];

    /**
     * The newest application this number sent that the office has not decided.
     *
     * @return array{type: string, id: int, ref: string, status: string}|null
     */
    public static function findOpen(string $phone10, string $cc): ?array
    {
        if (strlen($phone10) !== 10) {
            return null;
        }
        $best = null;
        $a = Database::fetch(
            "SELECT id, ref, status, phone_raw, created_at FROM agent_applications
              WHERE phone = :p AND status IN ('" . implode("','", self::AGENT_OPEN) . "')
              ORDER BY id DESC LIMIT 1",
            ['p' => $phone10]
        );
        // The same 10 digits in the other country are a stranger: a number stored with its code must match.
        if ($a !== null && self::sameCountry((string) ($a['phone_raw'] ?? ''), $cc)) {
            $best = ['type' => 'agent', 'id' => (int) $a['id'], 'ref' => (string) ($a['ref'] ?: AgentApplications::refFor((int) $a['id'])),
                     'status' => (string) $a['status'], 't' => (string) $a['created_at']];
        }
        if (StaffApps::available()) {
            $s = StaffApps::findByPhone($phone10);
            if ($s !== null && in_array((string) $s['status'], self::STAFF_OPEN, true)
                && ((string) ($s['phone_country'] ?? '') === '' || (string) $s['phone_country'] === ($cc === '977' ? 'NP' : 'IN'))
                && ($best === null || (string) $s['created_at'] > $best['t'])) {
                $best = ['type' => 'staff', 'id' => (int) $s['id'], 'ref' => StaffApps::refFor((int) $s['id']),
                         'status' => (string) $s['status'], 't' => (string) $s['created_at']];
            }
        }
        if ($best === null) {
            return null;
        }
        unset($best['t']);
        return $best;
    }

    private static function sameCountry(string $raw, string $cc): bool
    {
        $d = preg_replace('/\D/', '', $raw) ?? '';
        if (strlen($d) <= 10) {
            return true;   // stored without a country code: the 10 digits are all there is
        }
        return str_starts_with($d, $cc === '977' ? '977' : '91');
    }

    /** Open the edit for this session; the caller has checked the number was proven here. */
    public static function open(array $app, string $phone10): void
    {
        $_SESSION['apply_edit'] = ['type' => $app['type'], 'id' => (int) $app['id'], 'phone' => $phone10, 't' => time()];
        Logger::audit('apply.edit_open', $app['type'] === 'staff' ? 'staff_application' : 'agent_application', (string) $app['ref'],
            null, null, 'Applicant opened their own form to correct it (WhatsApp-proven number)');
    }

    /**
     * The open edit, when it is for this kind and this number and still undecided.
     *
     * @return array{type: string, id: int, phone: string}|null
     */
    public static function current(string $type, string $phone10): ?array
    {
        $e = $_SESSION['apply_edit'] ?? null;
        if (!is_array($e) || (int) ($e['t'] ?? 0) < time() - self::OPEN_SEC || (string) ($e['type'] ?? '') !== $type
            || (string) ($e['phone'] ?? '') !== $phone10 || (int) ($e['id'] ?? 0) <= 0) {
            return null;
        }
        if ($type === 'staff') {
            $r = StaffApps::find((int) $e['id']);
            $ok = $r !== null && in_array((string) $r['status'], self::STAFF_OPEN, true) && (string) $r['phone'] === $phone10;
        } else {
            $r = AgentApplications::find((int) $e['id']);
            $ok = $r !== null && in_array((string) $r['status'], self::AGENT_OPEN, true) && (string) $r['phone'] === $phone10;
        }
        return $ok ? ['type' => $type, 'id' => (int) $e['id'], 'phone' => $phone10] : null;
    }

    public static function close(): void
    {
        unset($_SESSION['apply_edit']);
    }

    /**
     * What the form needs to fill itself: the typed values under the form's own input ids, and which
     * pictures are already with the office (they are not sent back - the office keeps them).
     *
     * @return array{kind: string, ref: string, values: array<string, string>, have: array<int, string>, public_ok: bool}
     */
    public static function prefill(array $app): array
    {
        $v = [];
        $have = [];
        if ($app['type'] === 'staff') {
            $r = StaffApps::find((int) $app['id']) ?? [];
            // the form's <select id="p_idtype"> values (apply.html STAFF_ID, the other way round)
            $idType = array_search((string) ($r['id_type'] ?? ''), ['0' => 'aadhaar', '1' => 'citizenship', '2' => 'passport', '5' => 'driving_license', '6' => 'pan'], true);
            $v = [
                'p_name' => (string) ($r['full_name'] ?? ''), 's_role' => (string) ($r['role_applied'] ?? ''),
                'p_idtype' => $idType !== false ? (string) $idType : '1', 'p_idno' => (string) ($r['id_number'] ?? ''),
                'p_area' => (string) ($r['district'] ?? ''), 'p_addr' => (string) ($r['address_perm'] ?? ''),
                's_country' => (string) ($r['country'] ?? 'NP'), 's_dob' => (string) ($r['dob'] ?? ''), 's_gender' => (string) ($r['gender'] ?? ''),
                's_email' => (string) ($r['email'] ?? ''), 'cv_qual' => (string) ($r['qualification'] ?? ''),
                'cv_exp' => (int) ($r['experience_years'] ?? 0) > 0 ? (string) (int) $r['experience_years'] : '', 'cv_prev' => (string) ($r['prev_employer'] ?? ''),
                's_lic' => (string) ($r['license_number'] ?? ''), 's_licCat' => (string) ($r['license_category'] ?? ''), 's_licExp' => (string) ($r['license_expiry'] ?? ''),
                's_emn' => (string) ($r['emergency_contact_name'] ?? ''), 's_emp' => (string) ($r['emergency_contact_phone'] ?? ''),
                'b_name' => (string) ($r['bank_holder'] ?? ''), 'b_bank' => (string) ($r['bank_name'] ?? ''),
                'b_acc' => (string) ($r['bank_account'] ?? ''), 'b_ifsc' => (string) ($r['bank_ifsc_or_swift'] ?? ''),
            ];
            $refs = json_decode((string) ($r['references_json'] ?? ''), true);
            if (is_array($refs) && isset($refs[0])) {
                $v['s_ref1n'] = (string) ($refs[0]['name'] ?? '');
                $v['s_ref1p'] = (string) ($refs[0]['phone'] ?? '');
            }
            foreach (StaffApps::FILES as $slot => [$col]) {
                if ((string) ($r[$col] ?? '') !== '') {
                    $have[] = $slot;
                }
            }
            $pub = (int) ($r['show_on_website'] ?? 0) === 1;
            $kind = 'staff';
        } else {
            $r = AgentApplications::find((int) $app['id']) ?? [];
            $f = json_decode((string) ($r['fields_json'] ?? '{}'), true);
            $f = is_array($f) ? $f : [];
            /* every scalar the form stored (p_dob, p_pan, guarantor / witness boxes, e-mail…) - a box the form does
               not know is simply not drawn, while one it knows is no longer wiped on an edit (23 Oct 2026 review) */
            foreach ($f as $k => $val) {
                if (is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,40}$/', $k) === 1 && is_scalar($val) && !in_array($k, ['p_phone', 'p_cc'], true)) {
                    $v[$k] = (string) $val;
                }
            }
            if ((string) ($r['email'] ?? '') !== '' && !isset($v['p_email']) && !isset($v['s_email'])) {
                $v['p_email'] = (string) $r['email'];
            }
            foreach (['photo' => 'photo', 'doc_photo' => 'doc', 'signature' => 'signature', 'thumb' => 'thumb', 'edu_cert' => 'edu_cert',
                      'logo' => 'logo', 'reg_cert' => 'reg_cert', 'tax_cert' => 'tax_cert'] as $col => $slot) {
                if ((string) ($r[$col] ?? '') !== '') {
                    $have[] = $slot;
                }
            }
            $pub = (int) ($r['public_ok'] ?? 0) === 1;
            $kind = (string) ($r['type'] ?? 'ind') === 'org' ? 'org' : 'ind';
        }
        return ['kind' => $kind, 'ref' => (string) $app['ref'], 'values' => array_filter($v, static fn($x) => $x !== ''), 'have' => $have, 'public_ok' => $pub];
    }
}
