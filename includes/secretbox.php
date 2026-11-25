<?php
/**
 * =====================================================================
 *  includes/secretbox.php — SecretBox: a secret at rest, sealed (25 Nov 2026, mp2 "bank Gmail").
 *
 *  sodium secretbox (XSalsa20-Poly1305), the same construction AgentPro::encrypt
 *  uses for bank accounts / ID numbers, with its own key per purpose: the key is
 *  sodium_crypto_generichash('shg-secretbox-v1|<context>|' . APP_KEY). A value sealed
 *  for 'bankmail' never opens as anything else. (AgentPro's and GDrive's helpers
 *  are private to their own data, so this is the shared one.)
 *
 *  No sodium (or no APP_KEY) = seal() REFUSES with a clear message: a secret is
 *  never stored in plaintext as a fallback. open() of anything it cannot read
 *  (another key, a cut value, an APP_KEY that changed) = '' - the panel then asks
 *  for the password / a new Google login again rather than using garbage.
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class SecretBox
{
    private const PREFIX = 'sb1:';

    /** Can a new secret be sealed here (ext-sodium + an APP_KEY)? */
    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_generichash')
            && defined('APP_KEY') && strlen((string) APP_KEY) >= 16;
    }

    /** Why a secret cannot be stored ('' = it can). */
    public static function whyNot(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            return 'PHP sodium extension छैन — secret सुरक्षित (encrypt) गर्न सकिँदैन, त्यसैले सेभ गरिएन · ext-sodium is missing, the secret was NOT stored.';
        }
        if (!defined('APP_KEY') || strlen((string) APP_KEY) < 16) {
            return 'APP_KEY config मा छैन — secret सेभ गरिएन · APP_KEY is not set, the secret was NOT stored.';
        }
        return '';
    }

    private static function key(string $context): string
    {
        return sodium_crypto_generichash('shg-secretbox-v1|' . $context . '|' . (string) APP_KEY, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * Seal $plain for $context. Throws (never returns plaintext) when sodium / APP_KEY is missing.
     */
    public static function seal(string $plain, string $context = 'bankmail'): string
    {
        if (!self::available()) {
            throw new RuntimeException(self::whyNot());
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key($context)));
    }

    /** The plaintext, or '' when the value cannot be opened (not ours, cut, another key, no sodium). */
    public static function open(?string $box, string $context = 'bankmail'): string
    {
        if ($box === null || !str_starts_with($box, self::PREFIX) || !self::available()) {
            return '';
        }
        $raw = base64_decode(substr($box, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES - 1) {
            return '';
        }
        try {
            $plain = sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                self::key($context)
            );
        } catch (Throwable $e) {
            return '';
        }
        return $plain === false ? '' : $plain;
    }
}
