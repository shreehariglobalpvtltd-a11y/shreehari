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
 * The window of travel dates that may be sold right now.
 *
 * The service is inaugurated on 2 Sep 2026 (puja + udghatan), so no seat
 * may be sold for an earlier travel date; and sales stay within a rolling
 * horizon so the office is never committed further ahead than the fleet
 * plan. Both ends are settings-driven (booking_open_from,
 * booking_horizon_days) so the owner can widen them without a deploy.
 *
 * @return array{from: string, to: string} inclusive ISO date bounds
 */
function bookingWindow(): array
{
    $openFrom = Settings::getString('booking_open_from', '2026-09-02');
    if (!Security::isValidDate($openFrom)) {
        $openFrom = '2026-09-02';
    }
    $horizon = max(1, Settings::getInt('booking_horizon_days', 30));

    $from = max(todayISO(), $openFrom);
    return ['from' => $from, 'to' => addDaysISO($from, $horizon)];
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
 * The next sequential, human-readable ticket number: SHG-<YEAR>-<00001>.
 *
 * MUST be called inside an open transaction (Booking::create wraps the whole
 * booking insert). The SELECT ... FOR UPDATE locks that year's counter row so
 * two simultaneous checkouts can never be handed the same number, and because
 * the increment shares the booking's transaction there are no gaps when a
 * booking rolls back. The counter resets on the first booking of a new year.
 *
 * Resilience: if the `pnr_counters` table has not been migrated yet (or the
 * counter is momentarily unavailable) this NEVER blocks a booking — it falls
 * back to the legacy unique PNR so checkout keeps working until the one-time
 * migration (database/upgrade-2026-08-ticketno-official.sql) is applied.
 */
function nextTicketNo(string $routeCode = 'R'): string
{
    $yr = (int) date('Y');

    try {
        $rows = Database::fetchForUpdate('SELECT seq FROM pnr_counters WHERE yr = :y', ['y' => $yr]);

        if ($rows === []) {
            // First booking of the year — seed the row, then lock it.
            Database::insertIgnore('pnr_counters', ['yr' => $yr, 'seq' => 0]);
            $rows = Database::fetchForUpdate('SELECT seq FROM pnr_counters WHERE yr = :y', ['y' => $yr]);
        }

        $seq = (int) ($rows[0]['seq'] ?? 0) + 1;
        Database::update('pnr_counters', ['seq' => $seq], 'yr = :y', ['y' => $yr]);

        return sprintf('%s-%d-%05d', PNR_PREFIX, $yr, $seq);
    } catch (Throwable $e) {
        Logger::warning('nextTicketNo fell back to legacy PNR', ['error' => $e->getMessage()]);
        return generatePnr($routeCode);
    }
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

/**
 * Decide the country of a phone number: 'IN', 'NP', or '' when unknown.
 *
 * India and Nepal share the same 10-digit mobile format, so the digits alone
 * cannot tell them apart — a bare 9812345678 is a valid number in BOTH. That
 * ambiguity is exactly why a ticket for a Nepali customer was being WhatsApped
 * to a stranger in India. An explicit picker value ('IN'/'NP') always wins;
 * otherwise a +91 / +977 (or 0091/00977) prefix the customer actually typed is
 * honoured. Everything else stays '' so the caller can fall back to the
 * account on file rather than guessing.
 */
function resolvePhoneCountry(string $explicit, string $rawPhone = ''): string
{
    $c = strtoupper(trim($explicit));
    if ($c === 'IN' || $c === 'NP') {
        return $c;
    }

    $rd = preg_replace('/\D/', '', $rawPhone) ?? '';
    if (str_starts_with($rd, '00')) {
        $rd = substr($rd, 2);
    }
    if (strlen($rd) >= 13 && str_starts_with($rd, '977')) {
        return 'NP';
    }
    if (strlen($rd) >= 12 && str_starts_with($rd, '91')) {
        return 'IN';
    }
    return '';
}

/**
 * The dialing code for a country ('NP' -> '977', 'IN' -> '91'), or '' when the
 * country is unknown. Kept separate from resolvePhoneCountry() so a stored
 * users.country_code ('977'/'91') and a picker value ('NP'/'IN') both map to
 * the one canonical prefix the notifier prepends.
 */
function countryDialCode(string $country): string
{
    $c = strtoupper(trim($country));
    return $c === 'NP' ? '977' : ($c === 'IN' ? '91' : '');
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

/**
 * Build a "match this text against any of these places" SQL fragment,
 * giving every arm its own bound placeholder.
 *
 * Prepared statements are NOT emulated on this connection (see
 * includes/db.php), so MySQL binds placeholders positionally: a named
 * placeholder written twice needs two bound values, and passing one raises
 * SQLSTATE[HY093] "Invalid parameter number" rather than reusing the value.
 * A search across three columns written as `a LIKE :q OR b LIKE :q` is
 * therefore not a slow query — it is a 500 on every non-empty search.
 *
 * Each $arm is an SQL expression containing '%s' where the placeholder
 * belongs, which keeps the EXISTS-subquery form expressible:
 *
 *     $sql = sqlSearchClause([
 *         'b.pnr LIKE %s',
 *         'EXISTS (SELECT 1 FROM booking_passengers p
 *                   WHERE p.booking_id = b.id AND p.full_name LIKE %s)',
 *     ], $q, $params);
 *
 * Arms are trusted SQL and must never be built from user input; only $text
 * is user-supplied, and it is bound, never interpolated.
 *
 * @param  array<int, string>   $arms   SQL expressions, each containing one '%s'
 * @param  array<string, mixed> $params bound-parameter bag, appended to in place
 * @return string  parenthesised OR-clause, or '' when there is nothing to match
 */
function sqlSearchClause(array $arms, string $text, array &$params, string $prefix = 'q'): string
{
    $text = trim($text);

    if ($text === '' || $arms === []) {
        return '';
    }

    $parts = [];
    foreach (array_values($arms) as $i => $arm) {
        $key            = $prefix . $i;
        $parts[]        = str_replace('%s', ':' . $key, $arm);
        $params[$key]   = '%' . $text . '%';
    }

    return '(' . implode(' OR ', $parts) . ')';
}


/* WhatsApp delivery state of the ticket message for ONE booking (ticket
   page, 17 Sep 2026): the passenger reads "sent to WhatsApp ••••1507" or an
   honest "could not deliver — send it yourself" instead of guessing whether
   the office's message went. Reads the newest whatsapp row Notify wrote to
   message_logs; the number is masked to its last four digits. Never throws:
   the ticket must render even when the log table is unavailable. */
function shg_wa_last(int $bookingId): ?array
{
    if ($bookingId <= 0) {
        return null;
    }
    try {
        $row = Database::fetch(
            "SELECT status, to_number, error, created_at FROM message_logs WHERE booking_id = :b AND channel = 'whatsapp' ORDER BY id DESC LIMIT 1",
            [':b' => $bookingId]
        );
    } catch (Throwable $e) {
        return null;
    }
    if ($row === null) {
        return null;
    }
    $digits = preg_replace('/\D/', '', (string) ($row['to_number'] ?? '')) ?? '';
    $status = strtolower((string) ($row['status'] ?? ''));
    $code   = preg_match('/\(code (\d{4,6})\)/', (string) ($row['error'] ?? ''), $m) ? $m[1] : '';
    return [
        'status' => $status,
        'ok'     => in_array($status, ['sent', 'delivered', 'read', 'accepted', 'queued'], true),
        'last4'  => $digits !== '' ? substr($digits, -4) : '',
        'code'   => $code,
        'at'     => (string) ($row['created_at'] ?? ''),
    ];
}

/**
 * Customer-facing view of one booking (4 Sep 2026). This is the shape
 * /api/track.php has always answered with, lifted into a shared helper so
 * /api/my-bookings.php returns the very same thing and the app's
 * bookingFromServer() adapter reads both identically.
 *
 * @param array<string, mixed> $detail BookingService::detail() row
 * @return array<string, mixed>
 */
function shg_customer_payload(array $detail): array
{
    $legs = array_map(static function (array $leg): array {
        return [
            'type'        => $leg['leg_type'],
            // The booking app keys its route catalogue by route_code ('r1'),
            // so hand it back — without it a ticket pulled from the server
            // cannot be matched to a bus for the status screen.
            'routeCode'   => $leg['route_code'],
            'seats'       => $leg['seats'] ?? [],
            'from'        => $leg['from_city'],
            'to'          => $leg['to_city'],
            'date'        => $leg['travel_date'],
            'busName'     => $leg['bus_name'],
            'depTime'     => substr((string) $leg['dep_time'], 0, 5),
            // duration_text '' = arrival deliberately blanked — no false clock.
            'arrTime'     => (string) ($leg['duration_text'] ?? '') === '' ? '' : substr((string) $leg['arr_time'], 0, 5),
            'boarding'    => $leg['boarding_stop'],
            'drop'        => $leg['drop_stop'],
            'crewName'    => $leg['crew_name'],
            'crewPhone'   => $leg['crew_phone'],
        ];
    }, $detail['legs'] ?? []);

    /* Has the journey actually finished? Drives the "rate your trip" card:
       a rating collected before arrival is not a rating of the journey.
       Read off the legs, which already carry the schedule row, so this
       costs no extra query even on a 30-booking My Bookings page. */
    $arrived = false;
    foreach ($detail['legs'] ?? [] as $lg) {
        if ((string) ($lg['schedule_status'] ?? '') === 'arrived') { $arrived = true; break; }
    }

    $createdAt = !empty($detail['created_at']) ? strtotime((string) $detail['created_at']) : false;

    return [
        'pnr'         => $detail['pnr'],
        'ticketNumber'=> $detail['ticket_number'] ?? '',
        'status'      => $detail['status'],
        // Computed server-side so the ticket, track.php, my-bookings.php and
        // the My Bookings list all show the same "Upcoming" / "Departed" pill.
        'live_status' => Ticket::liveStatus($detail),
        'total'       => (float) $detail['total_amount'],
        'farePerSeat' => (float) ($detail['fare_per_seat'] ?? 0),
        'currency'    => $detail['currency'],
        'isCod'       => (bool) $detail['is_cod'],
        'seats'       => $detail['seats'],
        'passengers'  => array_map(static fn(array $p): array => [
            'name'    => $p['full_name'],
            'seat'    => $p['seat_no'],
            'age'     => $p['age'],
            'gender'  => $p['gender'],
            'special' => $p['special_need'] ?? null,
        ], $detail['passengers'] ?? []),
        'legs'        => $legs,
        'arrived'     => $arrived,
        'payment'     => [
            'method' => $detail['payment']['method'] ?? '',
            'status' => $detail['payment']['status'] ?? '',
            'utr'    => $detail['payment']['utr_number'] ?? '',
            // Why a payment was refused, so the ticket page can show the real
            // reason instead of a generic "could not be verified".
            'reason' => (string) ($detail['payment']['reject_reason'] ?? ($detail['cancel_reason'] ?? '')),
        ],
        'canCancel'   => in_array($detail['status'], ['pending', 'confirmed'], true),
        'ticketUrl'   => $detail['status'] === 'confirmed'
            ? Ticket::downloadUrl((string) $detail['pnr'])
            : null,
        'createdAt'   => $createdAt !== false ? $createdAt * 1000 : null,
    ];
}

/**
 * The one piece of Devanagari shaping neither of our text engines does.
 *
 * Measured on 10 Sep 2026, on this box and on the live VPS: GD/FreeType
 * forms the Devanagari conjuncts and the reph correctly (यात्रु, कृष्ण,
 * निर्मला), and Pdf::textCID maps codepoints straight to glyphs. NEITHER
 * performs the Indic shaper's REORDERING step. The short-i matra ि is
 * stored AFTER its consonant and must be DRAWN BEFORE it, so "सिता" came
 * out with the hook on the wrong side, and every Nepali name carrying an
 * i was quietly wrong on the ticket, the chalani and the PDF.
 *
 * ि is the only pre-base sign in Devanagari, so moving it one position is
 * the whole fix. Call it at the DRAWING boundary and nowhere else — the
 * transform is deliberately NOT idempotent, and running it twice would
 * carry the matra past a second consonant.
 *
 * A matra that follows a CONJUNCT (स्थि, क्षि, ओर्लि) is left where it is:
 * moving it in front of the cluster stops the font forming the conjunct at
 * all, and a correct conjunct with a misplaced hook reads better than a
 * broken one. Those are rare in names, while every single-consonant case —
 * सिता, दिपक, हरि, बिमला, अधिकारी, टिकट, मिनेट — now comes out right.
 */
function dev_shape(string $text): string
{
    if (!str_contains($text, "\u{093F}")) {
        return $text;
    }
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out   = [];
    foreach ($chars as $ch) {
        if ($ch !== "\u{093F}") { $out[] = $ch; continue; }
        $i = count($out) - 1;
        if ($i >= 0 && $out[$i] === "\u{093C}") { $i--; }            // step over a nukta
        $cp = $i >= 0 ? mb_ord($out[$i], 'UTF-8') : 0;
        $isConsonant = ($cp >= 0x0915 && $cp <= 0x0939)
            || ($cp >= 0x0958 && $cp <= 0x095F) || ($cp >= 0x0978 && $cp <= 0x097F);
        // nothing to attach to, or the consonant is the tail of a conjunct
        if (!$isConsonant || ($i >= 1 && $out[$i - 1] === "\u{094D}")) { $out[] = $ch; continue; }
        array_splice($out, $i, 0, ["\u{093F}"]);
    }
    return implode('', $out);
}
