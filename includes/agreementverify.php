<?php
/**
 * AgreementVerify — the verification QR on every filled A4 agreement
 * (23 Oct 2026, owner: "unique agreement ID and verification QR").
 *
 * The agreement number is the application's own ref (STF-000012 for staff,
 * AGR-… for an agent or a Pvt. Ltd. partner). The QR carries
 * "<REF>.<12 hex HMAC 'agreement|REF'>", so a made-up number is "not
 * genuine" without a database lookup and the numbers cannot be walked —
 * the same idea as the ID card (IdCard::code), with its own key prefix so
 * an ID-card code never opens an agreement and the other way round.
 *
 * verify-agreement.php shows the agreement's CURRENT standing: received
 * (waiting for the office), in force (approved), suspended, ended or not
 * accepted, plus the signing and approval times. Never an ID number,
 * address, date of birth, bank detail or document.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/staffapplications.php';
require_once __DIR__ . '/agentapplications.php';

final class AgreementVerify
{
    public static function code(string $ref): string
    {
        $ref = strtoupper(trim($ref));
        return $ref . '.' . substr(Security::sign('agreement|' . $ref), 0, 12);
    }

    /** The ref inside a scanned code, or null when it was not signed by us. */
    public static function parse(string $code): ?string
    {
        if (preg_match('/^((?:STF|AGR)-\d{1,9})\.([a-f0-9]{12})$/i', trim($code), $m) !== 1) {
            return null;
        }
        $ref = strtoupper($m[1]);
        return hash_equals(self::code($ref), $ref . '.' . strtolower($m[2])) ? $ref : null;
    }

    public static function url(string $ref): string
    {
        return appUrl('verify-agreement.php') . '?c=' . rawurlencode(self::code($ref));
    }

    /**
     * The agreement's standing, or null when no such record exists.
     *
     * state: received | in_force | suspended | ended | refused
     *
     * @return array{kind:string, ref:string, name:string, phone:string, state:string, doc:string,
     *               signed:string, approved:string, kyc:bool, phoneProven:bool}|null
     */
    public static function standing(string $ref): ?array
    {
        $ref = strtoupper(trim($ref));
        if (preg_match('/^STF-(\d{1,9})$/', $ref, $m) === 1) {
            return self::staff((int) $m[1]);
        }
        if (preg_match('/^AGR-(\d{1,9})$/', $ref, $m) === 1) {
            $id = AgentApplications::available() ? (int) Database::scalar('SELECT id FROM agent_applications WHERE ref = :r LIMIT 1', ['r' => $ref], 0) : 0;
            return self::agent($id > 0 ? $id : (int) $m[1]);
        }
        return null;
    }

    private static function staff(int $id): ?array
    {
        $r = StaffApps::available() ? StaffApps::find($id) : null;
        if ($r === null) {
            return null;
        }
        $st = (string) $r['status'];
        $signed = '';
        foreach (StaffApps::signs($id) as $sg) {
            $t = (string) ($sg['signed_at'] ?? '');
            $signed = $signed === '' || ($t !== '' && $t < $signed) ? $t : $signed;
        }
        $cc = countryDialCode((string) ($r['phone_country'] ?? '')) ?: '';
        return [
            'kind'        => 'staff',
            'ref'         => (string) $r['ref'],
            'name'        => (string) $r['full_name'],
            'phone'       => ($cc !== '' ? '+' . $cc . ' ' : '') . (string) $r['phone'],
            'state'       => match ($st) {
                'approved'  => 'in_force',
                'suspended' => 'suspended',
                'exited'    => 'ended',
                'rejected'  => 'refused',
                default     => 'received',
            },
            'doc'         => 'Staff agreement v' . (string) ($r['agreement_version'] ?? ''),
            'signed'      => $signed !== '' ? $signed : (string) $r['created_at'],
            'approved'    => $st === 'approved' || $st === 'suspended' || $st === 'exited' ? (string) ($r['approved_at'] ?? '') : '',
            'kyc'         => (string) ($r['kyc_status'] ?? '') === 'verified',
            'phoneProven' => (int) ($r['phone_verified'] ?? 0) === 1,
        ];
    }

    private static function agent(int $id): ?array
    {
        $r = $id > 0 ? AgentApplications::find($id) : null;
        if ($r === null) {
            return null;
        }
        $org = (string) $r['type'] === 'org';
        $st  = (string) $r['status'];
        $state = match ($st) {
            'approved' => 'in_force',
            'rejected' => 'refused',
            default    => 'received',
        };
        if ($state === 'in_force' && !empty($r['admin_id'])
            && (int) Database::scalar('SELECT is_active FROM admins WHERE id = :i', ['i' => (int) $r['admin_id']], 0) !== 1) {
            $state = 'suspended';
        }
        if ($state === 'in_force' && (string) ($r['agreement_status'] ?? '') === 'expired') {
            $state = 'ended';
        }
        // 23 Oct 2026 (review): an end date that has passed ends it too - the rule AgentApplications::directory()
        // uses (in force while end >= today), so the QR never says "in force" for a lapsed agreement
        $end = (string) ($r['agreement_end'] ?? '');
        if ($state === 'in_force' && $end !== '' && $end < date('Y-m-d')) {
            $state = 'ended';
        }
        $signed = (string) Database::scalar(
            "SELECT MIN(signed_at) FROM agreement_signatures WHERE party_type = 'agent' AND party_id = :i",
            ['i' => (int) $r['id']], ''
        );
        return [
            'kind'        => $org ? 'partner' : 'agent',
            'ref'         => (string) ($r['ref'] ?: AgentApplications::refFor((int) $r['id'])),
            'name'        => (string) $r['name'],
            'phone'       => (string) (($r['phone_raw'] ?? '') ?: $r['phone']),
            'state'       => $state,
            'doc'         => $org ? 'Partner (Pvt. Ltd.) agreement' : 'Agent agreement',
            'signed'      => $signed !== '' ? $signed : (string) $r['created_at'],
            'approved'    => $st === 'approved' ? (string) ($r['reviewed_at'] ?? '') : '',
            'kyc'         => (string) ($r['kyc_status'] ?? '') === 'verified',
            'phoneProven' => (int) ($r['phone_verified'] ?? 0) === 1,
        ];
    }
}
