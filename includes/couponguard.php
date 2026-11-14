<?php
/**
 * =====================================================================
 *  CouponGuard — stops coupon-code guessing (15 Nov 2026).
 *
 *  Every NEW code a visitor tries costs one hit in the 'coupon_try'
 *  bucket: 10 different codes per IP per 10 minutes. A code this session
 *  already tried is free, because the booking card sends the same code
 *  again on every re-quote (date, pickup, passengers) — a real customer
 *  who applied one code never runs into the limit by changing the card.
 *  A guesser without a session cookie pays for every request.
 *
 *  Used by api/quote.php and SmartBook::quote (the promo field).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class CouponGuard
{
    public const BUCKET   = 'coupon_try';
    public const MAX_NEW  = 10;     // different codes …
    public const WINDOW   = 600;    // … per 10 minutes per IP
    private const SESSION = 'coupon_tried';
    private const REMEMBER = 20;

    /** True when this visitor may try $code now. '' is always allowed (no code = nothing to guess). */
    public static function allow(string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return true;
        }
        $hasSession = session_status() === PHP_SESSION_ACTIVE;
        $seen = $hasSession && is_array($_SESSION[self::SESSION] ?? null) ? $_SESSION[self::SESSION] : [];
        if (in_array($code, $seen, true)) {
            return true;
        }
        if (!Security::rateLimit(self::BUCKET, Security::clientIp(), self::MAX_NEW, self::WINDOW)) {
            return false;
        }
        if ($hasSession) {
            $seen[] = $code;
            $_SESSION[self::SESSION] = array_slice(array_values(array_unique($seen)), -self::REMEMBER);
        }
        return true;
    }
}
