<?php
/**
 * =====================================================================
 *  IdVerify — "Verify our agent" without the QR (23 Oct 2026).
 *
 *  Owner prompt (registration system, points 8 + 19): the public may scan
 *  the QR OR type the code. The QR carries a signed code (IdCard::code), so
 *  card numbers cannot be walked. A typed card number alone would undo
 *  that, so the manual check asks for the card number AND the last 4 digits
 *  of the person's mobile (printed on the card's back, or said by the
 *  person standing in front of you). Both must match.
 *
 *  Guessing the 4 digits is rate-limited per visitor (10 tries / 10 min)
 *  and per card (20 wrong tries a day from anyone, then the card answers
 *  "try later" to everyone until the next day).
 *
 *  publicJson() is the ONLY shape the API returns: photo (a small JPEG),
 *  name, role, standing, card number, valid-till. Never the phone, an ID
 *  document, an address or a date of birth.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class IdVerify
{
    public const TAIL_TRIES_IP  = 10;    // per visitor per 10 minutes
    public const TAIL_FAILS_REF = 20;    // wrong tails per card per day, from anyone

    private function __construct() {}

    /** STF-000005 / AGR-000035 (spaces, lower case and a missing 0-padding are forgiven), or null. */
    public static function normaliseRef(string $s): ?string
    {
        $s = strtoupper(preg_replace('/\s+/', '', $s) ?? '');
        if (preg_match('/^(STF|AGR)-?(\d{1,9})$/', $s, $m) !== 1) {
            return null;
        }
        return $m[1] . '-' . str_pad(ltrim($m[2], '0') ?: '0', 6, '0', STR_PAD_LEFT);
    }

    /**
     * The card for a typed number + the last 4 digits of its mobile.
     *
     * @return array{state: string, ref: ?string}  state: ok | nomatch | wait | bad
     */
    public static function manual(string $refIn, string $tailIn, string $ip): array
    {
        $ref  = self::normaliseRef($refIn);
        $tail = preg_replace('/\D/', '', $tailIn) ?? '';
        if ($ref === null || strlen($tail) !== 4) {
            return ['state' => 'bad', 'ref' => null];
        }
        if (!Security::rateLimit('idcard_tail_ip', $ip, self::TAIL_TRIES_IP, 600)) {
            return ['state' => 'wait', 'ref' => null];
        }
        // a card that took too many wrong tails today is blocked for everyone (read without counting a hit)
        if (Database::exists("SELECT 1 FROM rate_limits WHERE bucket = 'idcard_tail_fail' AND identifier = :r AND blocked_until > :n LIMIT 1",
                ['r' => $ref, 'n' => date('Y-m-d H:i:s')])) {
            return ['state' => 'wait', 'ref' => null];
        }
        $p = IdCard::person($ref);
        $digits = $p !== null ? (preg_replace('/\D/', '', (string) $p['phone']) ?? '') : '';
        if ($p === null || strlen($digits) < 4 || !hash_equals(substr($digits, -4), $tail)) {
            Security::rateLimit('idcard_tail_fail', $ref, self::TAIL_FAILS_REF, 86400);
            return ['state' => 'nomatch', 'ref' => null];
        }
        return ['state' => 'ok', 'ref' => (string) $p['ref']];
    }

    /** The public answer for one card. */
    public static function publicJson(?array $p, string $state): array
    {
        $out = ['ok' => $state === 'valid', 'state' => $state];
        if ($p !== null) {
            $out['person'] = [
                'ref'        => (string) $p['ref'],
                'name'       => (string) $p['name'],
                'kind'       => (string) $p['kind'],
                'role'       => (string) $p['roleEn'],
                'roleNe'     => (string) $p['roleNe'],
                'standing'   => (string) $p['standing'],
                'standingNe' => (string) $p['standingNe'],
                'valid'      => (bool) $p['valid'],
                'validTill'  => (string) ($p['expires'] ?? ''),
                'photo'      => IdCard::thumb($p['photo'], 160),
                'verifyUrl'  => IdCard::verifyUrl((string) $p['ref']),
            ];
        }
        return $out;
    }
}
