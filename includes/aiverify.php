<?php
/**
 * =====================================================================
 *  AiVerify — step-up verification for staff and office numbers on
 *  WhatsApp (24 Sep 2026).
 *
 *  A WhatsApp sender is already identified by NUMBER: AiTools::whoIs
 *  matches it against the admins table, and the webhook signature proves
 *  the message really came through Meta from that number. That is a
 *  possession factor, and for reading it is enough.
 *
 *  It is not enough for the actions that move money or open the
 *  company's papers, because a staff handset can be shared, lost or
 *  SIM-swapped. For those the sender must ALSO prove they hold the staff
 *  ACCOUNT: the assistant sends a one-time link, the staff member opens
 *  it while signed in to the admin panel (password, and 2FA where it is
 *  on), and the panel confirms the signed-in account is the one whose
 *  phone this is. The number is then "fresh" for wa_ops_stepup_minutes.
 *
 *  Nothing about a password ever crosses WhatsApp. The token is stored
 *  hashed, never logged, dies at first use or after ten minutes, and a
 *  mismatch (a link opened from a different staff account) burns it and
 *  writes a security audit line.
 *
 *  Customers are not gated here: the existing rule — the number on the
 *  booking plus an explicit "ho" in a later message — stays as it is.
 *
 *  Switch wa_ops_stepup_on off and every call here says "not needed".
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiVerify
{
    private const CHALLENGE_TTL = 600;          // a link lives ten minutes

    private function __construct() {}

    public static function enabled(): bool
    {
        return Settings::getBool('wa_ops_stepup_on', false) && self::available();
    }

    public static function available(bool $recheck = false): bool
    {
        static $has = null;
        if ($has === null || $recheck) {
            try {
                $has = Database::fetch("SHOW TABLES LIKE 'wa_identity_links'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /** The tools that need a fresh verification, from Settings. @return list<string> */
    public static function actions(): array
    {
        $raw = Settings::getString('wa_ops_stepup_actions', 'office_confirm,cancel_ticket,fix_ticket,company_doc_send,agent_day,office_day');
        $out = [];
        foreach (explode(',', $raw) as $t) {
            $t = strtolower(trim($t));
            if ($t !== '' && preg_match('/^[a-z_]{3,40}$/', $t) === 1) {
                $out[] = $t;
            }
        }
        return array_values(array_unique($out));
    }

    /** Does THIS tool, for THIS sender, need a fresh verification? */
    public static function needs(string $tool, array $ctx): bool
    {
        if (!self::enabled()) {
            return false;
        }
        if ((string) ($ctx['role'] ?? 'customer') === 'customer' || (int) ($ctx['adminId'] ?? 0) <= 0) {
            return false;
        }
        return in_array($tool, self::actions(), true);
    }

    /** Verified recently enough, by the account this number maps to today? */
    public static function isFresh(array $ctx): bool
    {
        return self::freshUntil($ctx) > time();
    }

    /** Unix time the current verification lapses, or 0. */
    public static function freshUntil(array $ctx): int
    {
        $phone   = (string) ($ctx['phone'] ?? '');
        $adminId = (int) ($ctx['adminId'] ?? 0);
        if ($phone === '' || $adminId <= 0 || !self::available()) {
            return 0;
        }
        try {
            $row = Database::fetch('SELECT admin_id, verified_at FROM wa_identity_links WHERE phone = :p LIMIT 1', ['p' => $phone]);
        } catch (Throwable $e) {
            return 0;
        }
        if ($row === null || empty($row['verified_at']) || (int) $row['admin_id'] !== $adminId) {
            return 0;
        }
        $ttl = max(1, Settings::getInt('wa_ops_stepup_minutes', 30)) * 60;
        return (int) strtotime((string) $row['verified_at']) + $ttl;
    }

    /**
     * The refusal AiTools hands the model when a gated tool is called
     * without a fresh verification — with the one-time link inside it.
     * Returns null when no gate applies.
     *
     * @return array{ok: bool, say: string, data: array<string,mixed>, media: ?string}|null
     */
    public static function gate(string $tool, array $ctx): ?array
    {
        if (!self::needs($tool, $ctx) || self::isFresh($ctx)) {
            return null;
        }
        $c = self::challenge($ctx);
        return [
            'ok'    => false,
            'say'   => 'VERIFICATION NEEDED before ' . $tool . '. ' . $c['say'],
            'data'  => $c['data'] + ['tool' => $tool, 'needs_verification' => true],
            'media' => null,
        ];
    }

    /**
     * Mint (or point at) the one-time link. Rate-limited per number; while
     * an unexpired link is outstanding no new one is minted — the person
     * is asked to open the one they already have.
     *
     * @return array{ok: bool, say: string, data: array<string,mixed>}
     */
    public static function challenge(array $ctx): array
    {
        $phone   = (string) ($ctx['phone'] ?? '');
        $adminId = (int) ($ctx['adminId'] ?? 0);
        if ($phone === '' || $adminId <= 0) {
            return ['ok' => false, 'say' => 'Only a staff or office number can be verified.', 'data' => []];
        }
        $row = null;
        try {
            $row = Database::fetch('SELECT * FROM wa_identity_links WHERE phone = :p LIMIT 1', ['p' => $phone]);
        } catch (Throwable $ignored) {
        }
        if ($row !== null && !empty($row['challenge_hash']) && strtotime((string) $row['challenge_expires_at']) > time() + 60) {
            $until = date('H:i', strtotime((string) $row['challenge_expires_at']));
            return [
                'ok'   => false,
                'say'  => 'A verification link was ALREADY sent in this chat and is valid until ' . $until
                        . '. Ask them to open that link while signed in to the staff panel, then ask again. No new link is issued yet.',
                'data' => ['link' => null, 'expires_at' => (string) $row['challenge_expires_at'], 'already_sent' => true],
            ];
        }
        if (!Security::rateLimit('wa_stepup_link', $phone, 4, 900)) {
            return [
                'ok'   => false,
                'say'  => 'Too many verification links were requested from this number. Ask them to wait fifteen minutes or use the staff panel directly.',
                'data' => ['link' => null, 'rate_limited' => true],
            ];
        }

        $token = bin2hex(random_bytes(32));
        $exp   = time() + self::CHALLENGE_TTL;
        try {
            Database::run(
                'INSERT INTO wa_identity_links (phone, challenge_hash, challenge_expires_at, challenge_count, last_challenge_at)
                 VALUES (:p, :h, :e, 1, NOW())
                 ON DUPLICATE KEY UPDATE challenge_hash = :h2, challenge_expires_at = :e2,
                     challenge_count = challenge_count + 1, last_challenge_at = NOW()',
                ['p' => $phone, 'h' => hash('sha256', $token), 'e' => date('Y-m-d H:i:s', $exp),
                 'h2' => hash('sha256', $token), 'e2' => date('Y-m-d H:i:s', $exp)]
            );
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
            return ['ok' => false, 'say' => 'The verification link could not be issued right now. Tell them to use the staff panel directly.', 'data' => []];
        }
        Logger::audit('wa.identity.challenge', 'admin', (string) $adminId, null,
            ['phone' => maskPhone($phone)], 'one-time step-up link issued for WhatsApp');

        $link = appUrl('admin/wa-verify.php?t=' . $token);
        return [
            'ok'   => false,
            'say'  => 'Send EXACTLY this link and nothing else about it: ' . $link
                    . ' — they must open it on a phone or computer where they are signed in to the staff panel (it asks for their password if not). '
                    . 'It works once and expires in 10 minutes. When they say it is done, run the tool again. Never ask for a password in this chat.',
            'data' => ['link' => $link, 'expires_at' => date('Y-m-d H:i:s', $exp), 'valid_minutes' => (int) (self::CHALLENGE_TTL / 60)],
        ];
    }

    /**
     * Redeem a link from admin/wa-verify.php. $admin is the SIGNED-IN staff
     * row (Auth::admin()). The number's staff match must be this account.
     *
     * @return array{ok: bool, error?: string, phone?: string, minutes?: int}
     */
    public static function consume(string $token, array $admin): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return ['ok' => false, 'error' => 'That link is not valid.'];
        }
        if (!Security::rateLimit('wa_stepup_consume', Security::clientIp(), 10, 600)) {
            return ['ok' => false, 'error' => 'Too many attempts. Please wait a few minutes.'];
        }
        $row = null;
        try {
            $row = Database::fetch('SELECT * FROM wa_identity_links WHERE challenge_hash = :h LIMIT 1', ['h' => hash('sha256', $token)]);
        } catch (Throwable $ignored) {
        }
        if ($row === null) {
            return ['ok' => false, 'error' => 'That link has already been used or does not exist.'];
        }
        $phone = (string) $row['phone'];
        // Burn it first: whatever happens next, this token never works again.
        Database::update('wa_identity_links', ['challenge_hash' => null, 'challenge_expires_at' => null], 'id = :id', ['id' => (int) $row['id']]);

        if (strtotime((string) $row['challenge_expires_at']) < time()) {
            return ['ok' => false, 'error' => 'That link has expired. Ask the assistant for a new one.'];
        }

        require_once INCLUDE_PATH . '/aitools.php';
        $who = AiTools::whoIs($phone);
        $signedIn = (int) ($admin['id'] ?? 0);
        if ($signedIn <= 0 || (int) $who['adminId'] !== $signedIn) {
            Logger::warning('WhatsApp step-up link opened by a different staff account', [
                'phone' => maskPhone($phone), 'signed_in' => $signedIn, 'number_maps_to' => (int) $who['adminId'],
            ], 'security');
            Logger::audit('wa.identity.mismatch', 'admin', (string) $signedIn, null,
                ['phone' => maskPhone($phone), 'maps_to' => (int) $who['adminId']], 'step-up link opened from another account', 'refused');
            return ['ok' => false, 'error' => 'This link belongs to a different staff number. Sign in with the account that owns that WhatsApp number.'];
        }

        Database::update('wa_identity_links', [
            'admin_id'     => $signedIn,
            'verified_at'  => date('Y-m-d H:i:s'),
            'verified_via' => 'panel_link',
        ], 'id = :id', ['id' => (int) $row['id']]);
        Logger::audit('wa.identity.verified', 'admin', (string) $signedIn, null,
            ['phone' => maskPhone($phone), 'via' => 'panel_link'], 'WhatsApp number verified for sensitive actions');

        return ['ok' => true, 'phone' => $phone, 'minutes' => max(1, Settings::getInt('wa_ops_stepup_minutes', 30))];
    }

    /** Forget a number's verification (staff record changed, account disabled). */
    public static function revoke(string $phone): void
    {
        $phone = normalisePhone($phone);
        if ($phone === '' || !self::available()) {
            return;
        }
        try {
            Database::update('wa_identity_links', ['verified_at' => null, 'challenge_hash' => null, 'challenge_expires_at' => null],
                'phone = :p', ['p' => $phone]);
        } catch (Throwable $ignored) {
        }
    }
}
