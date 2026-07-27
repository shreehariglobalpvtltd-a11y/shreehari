<?php
/**
 * =====================================================================
 *  Shared helper functions
 *
 *  Small, dependency-free utilities used across the API, the admin
 *  panel and the PDF/ticket generators.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}


/* =====================================================================
 *  Money
 * ===================================================================== */

/**
 * Indian-format rupee string: ₹1,23,456
 */
function inr(float|int|string $amount): string
{
    return '₹' . indianNumber((float) $amount);
}

/**
 * Indian digit grouping (last three, then pairs) — 1234567 -> 12,34,567
 */
function indianNumber(float $amount, int $decimals = 0): string
{
    $negative = $amount < 0;
    $amount   = abs($amount);

    $fixed = number_format($amount, $decimals, '.', '');
    [$whole, $fraction] = array_pad(explode('.', $fixed), 2, '');

    if (strlen($whole) > 3) {
        $lastThree = substr($whole, -3);
        $rest      = substr($whole, 0, -3);
        $rest      = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
        $whole     = $rest . ',' . $lastThree;
    }

    $out = $whole . ($decimals > 0 && $fraction !== '' ? '.' . $fraction : '');

    return ($negative ? '-' : '') . $out;
}

/**
 * Approximate NPR equivalent using the configured peg.
 */
function nprEstimate(float|int $inrAmount): float
{
    $peg = Settings::getFloat('npr_per_inr', NPR_PER_INR);
    return round(((float) $inrAmount) * $peg);
}

function nprLabel(float|int $inrAmount): string
{
    return 'NPR ' . indianNumber(nprEstimate($inrAmount));
}

/**
 * Round to whole rupees the way the front end does.
 */
function money(float|int|string $amount): float
{
    return round((float) $amount, 2);
}


/* =====================================================================
 *  Dates
 * ===================================================================== */

function todayISO(): string
{
    return date('Y-m-d');
}

function addDaysISO(string $iso, int $days): string
{
    $ts = strtotime($iso . ' +' . $days . ' days');
    return $ts === false ? $iso : date('Y-m-d', $ts);
}

/**
 * Human date: Mon, 3 Aug 2026
 */
function formatDate(?string $iso, string $format = 'D, j M Y'): string
{
    if ($iso === null || $iso === '' || $iso === '0000-00-00') {
        return '';
    }
    $ts = strtotime($iso);
    return $ts === false ? '' : date($format, $ts);
}

/**
 * Human time from a TIME column: 11:00:00 -> 11:00 AM
 */
function formatTime(?string $time, string $format = 'g:i A'): string
{
    if ($time === null || $time === '') {
        return '';
    }
    $ts = strtotime('1970-01-01 ' . $time);
    return $ts === false ? '' : date($format, $ts);
}

/**
 * Whole hours between now and a departure datetime. Negative once the
 * bus has left — the refund calculator relies on that sign.
 */
function hoursUntil(string $dateIso, string $time = '00:00:00'): float
{
    $target = strtotime($dateIso . ' ' . $time);
    if ($target === false) {
        return 0.0;
    }
    return ($target - time()) / 3600;
}

/**
 * "2 hours ago" style relative label for admin lists.
 */
function timeAgo(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }

    $diff = time() - $ts;

    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return (int) ($diff / 60) . 'm ago';
    if ($diff < 86400)  return (int) ($diff / 3600) . 'h ago';
    if ($diff < 604800) return (int) ($diff / 86400) . 'd ago';

    return date('j M Y', $ts);
}


/* =====================================================================
 *  Identifiers
 * ===================================================================== */

/**
 * PNR in the original format: SHG-<ROUTECODE>-<base36 ts>-<4 random>
 *
 * Kept byte-compatible with tickets already issued by the single-file
 * build so old references still validate.
 */
function generatePnr(string $routeCode = 'R'): string
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $routeCode) ?: 'R');
    $code = substr($code, 0, 6);

    $stamp  = strtoupper(base_convert((string) time(), 10, 36));
    $random = randomChars(4);

    return PNR_PREFIX . '-' . $code . '-' . $stamp . '-' . $random;
}

/**
 * Unambiguous uppercase characters — no O/0 or I/1 confusion when a
 * passenger reads a PNR down the phone.
 */
function randomChars(int $length): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max      = strlen($alphabet) - 1;
    $out      = '';

    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }

    return $out;
}

function generateTicketNumber(): string
{
    return 'TKT-' . date('ymd') . '-' . randomChars(6);
}

function generatePaymentRef(): string
{
    return 'PAY-' . date('ymd') . '-' . randomChars(6);
}

function generatePassengerRef(): string
{
    return 'PAX-' . date('ymd') . '-' . randomChars(6);
}

function generateSupportRef(): string
{
    return 'SUP-' . date('ymd') . '-' . randomChars(5);
}

function generateTripRef(string $dateIso): string
{
    return PNR_PREFIX . '-' . str_replace('-', '', $dateIso) . '-' . randomChars(4);
}


/* =====================================================================
 *  Strings
 * ===================================================================== */

/**
 * Escape for HTML output. Short alias used throughout the templates.
 */
function e(mixed $value): string
{
    return Security::e($value);
}

function truncate(string $text, int $length = 100, string $suffix = '…'): string
{
    if (mb_strlen($text, 'UTF-8') <= $length) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $length, 'UTF-8')) . $suffix;
}

function slugify(string $text): string
{
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? $text;
    return trim(strtolower($text), '-');
}

/**
 * Mask a phone number for public display: 98765 43210 -> 98765•••10
 */
function maskPhone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    $length = strlen($digits);

    if ($length < 6) {
        return $digits;
    }

    return substr($digits, 0, 4) . str_repeat('•', max(2, $length - 6)) . substr($digits, -2);
}

/**
 * Normalise a phone number to digits, dropping a leading country code
 * so the same person is one row in `users` regardless of how they typed it.
 */
function normalisePhone(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw) ?? '';

    // 0091..., 91..., 977... prefixes
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 13 && str_starts_with($digits, '977')) {
        $digits = substr($digits, 3);
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }

    return $digits;
}


/* =====================================================================
 *  Arrays and JSON
 * ===================================================================== */

/**
 * Decode a JSON column, always returning an array.
 *
 * @return array<mixed>
 */
function jsonColumn(mixed $raw, array $default = []): array
{
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || $raw === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

/**
 * Pull one column out of a row set.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, mixed>
 */
function pluck(array $rows, string $column): array
{
    return array_values(array_map(static fn(array $r): mixed => $r[$column] ?? null, $rows));
}

/**
 * Re-key a row set by one of its columns.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<string|int, array<string, mixed>>
 */
function keyBy(array $rows, string $column): array
{
    $out = [];
    foreach ($rows as $row) {
        if (isset($row[$column])) {
            $out[$row[$column]] = $row;
        }
    }
    return $out;
}


/* =====================================================================
 *  Filesystem
 * ===================================================================== */

/**
 * Create a directory (recursively) if it is missing.
 */
function ensureDir(string $path): bool
{
    if (is_dir($path)) {
        return true;
    }
    return @mkdir($path, 0755, true) || is_dir($path);
}

/**
 * Human file size.
 */
function fileSizeLabel(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 2) . ' MB';
}


/* =====================================================================
 *  URLs
 * ===================================================================== */

function appUrl(string $path = ''): string
{
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * Cache-busting asset URL based on file mtime, so a redeploy never
 * serves a stale CSS bundle from the browser cache.
 */
function asset(string $relativePath): string
{
    $relative = ltrim($relativePath, '/');
    $absolute = ROOT_PATH . '/' . $relative;
    $version  = is_file($absolute) ? (string) filemtime($absolute) : '1';

    return appUrl($relative) . '?v=' . $version;
}

/**
 * WhatsApp click-to-chat link. Works with no API account.
 */
function whatsappLink(string $phone, string $message = ''): string
{
    $number = preg_replace('/\D/', '', $phone) ?? '';
    $url    = 'https://wa.me/' . $number;

    if ($message !== '') {
        $url .= '?text=' . rawurlencode($message);
    }

    return $url;
}

/**
 * UPI deep link with the exact amount pre-filled.
 */
function upiLink(string $vpa, string $name, float $amount, string $note = ''): string
{
    $params = [
        'pa' => $vpa,
        'pn' => $name,
        'am' => number_format($amount, 2, '.', ''),
        'cu' => 'INR',
    ];

    if ($note !== '') {
        $params['tn'] = $note;
    }

    return 'upi://pay?' . http_build_query($params);
}
