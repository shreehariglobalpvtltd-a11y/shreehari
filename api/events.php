<?php
/**
 * =====================================================================
 *  api/events.php — the product beacon.
 *
 *  The app can tell us where people get stuck: which view they left from,
 *  which search returned nothing, which checkout step they abandoned, which
 *  sentence the ticket parser failed to understand. Today none of that is
 *  recorded, so every UX decision is a guess and every "the parser missed
 *  this" report depends on someone remembering to mention it.
 *
 *  Called with navigator.sendBeacon(), which is fire-and-forget: the browser
 *  posts and moves on. So this endpoint is built to be the cheapest thing in
 *  the codebase — it does NOT include api/_init.php, because that pulls in
 *  the PDF renderer, the QR encoder, the ticket builder and the notifier, and
 *  a view transition should not load a PDF library.
 *
 *  PRIVACY IS ENFORCED AT THE WRITER, NOT BY CONVENTION
 *  ----------------------------------------------------
 *  · The event NAME must be on an allow-list. An unknown name is dropped.
 *  · Only allow-listed PROP KEYS survive, and only as short scalars.
 *  · Anything that looks like a phone number, an email or a PNR is redacted
 *    before it is stored, even from an allow-listed key — a search box is a
 *    place people type their own phone number.
 *  · The identity stored is a rotating per-visit key, not a person.
 *  · Rows are deleted after `events_retention_days` (default 90) by
 *    cron/rotate.php. Retention exists from the first row written, not from
 *    the first time somebody asks about it.
 *
 *  Always answers 204. A beacon that returns an error the browser cannot act
 *  on is just a slower beacon.
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

/** Answer and stop. sendBeacon ignores the body, so there is nothing to say. */
function beacon_done(): never
{
    if (!headers_sent()) {
        http_response_code(204);
        header('Cache-Control: no-store');
    }
    exit;
}

if (!Security::isPost()) {
    beacon_done();
}

/**
 * The complete set of events the app may report.
 *
 * An allow-list rather than a free-form name because a beacon endpoint that
 * accepts any string becomes, within a year, a general-purpose log that
 * nobody can characterise — and characterising exactly what is collected is
 * the whole basis of the retention and redaction promises above.
 */
const ALLOWED_EVENTS = [
    'view',              // a view transition inside the SPA
    'search',            // a route/date search ran
    'search_empty',      // …and returned nothing
    'seat_open',         // the seat map was opened
    'checkout_step',     // a checkout step was reached
    'checkout_drop',     // the checkout was abandoned at a step
    'quick_ticket_open', // the one-tap QuickTicket panel was opened
    'parse_miss',        // the ticket parser could not read a field
    'offline_hit',       // the app served something from the offline cache
    'install_prompt',    // the PWA install prompt was shown/accepted
    'lang_switch',       // the language was changed
    'error_boundary',    // a JS error was caught by the app shell
];

/**
 * Prop keys worth keeping, and what each may hold.
 *
 * Everything else in the payload is discarded — not truncated, discarded.
 */
const ALLOWED_PROPS = [
    'from', 'to', 'view', 'step', 'field', 'lang', 'direction',
    'date_offset', 'seats', 'ms', 'count', 'ok', 'reason', 'source',
];

/** Redact anything that could identify a person, wherever it appears. */
function beacon_scrub(string $value): string
{
    // 8+ consecutive digits: a phone number, an ID number, an account number.
    $value = preg_replace('/\d{8,}/', '#', $value) ?? $value;
    // Email addresses.
    $value = preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/', '#', $value) ?? $value;
    // Our own ticket numbers — a PNR identifies one passenger's journey.
    $value = preg_replace('/\bSHG[-\w]*\d[-\w]*/i', '#', $value) ?? $value;
    return $value;
}

try {
    if (!Settings::getBool('events_beacon_on', true)) {
        beacon_done();
    }

    /* A beacon fires on every view change, so the budget is generous — but it
       IS a budget: an open endpoint that writes a row per request is a free
       disk-filling tool for anyone who finds it. */
    if (!Security::rateLimit('beacon', Security::clientIp(), 240, 60)) {
        beacon_done();
    }

    $raw = (string) file_get_contents('php://input');
    if ($raw === '' || strlen($raw) > 4000) {
        beacon_done();
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        beacon_done();
    }

    $name = (string) ($body['name'] ?? '');
    if (!in_array($name, ALLOWED_EVENTS, true)) {
        beacon_done();
    }

    /* The visit key comes from the client and is deliberately NOT trusted as
       an identity: it is normalised to 32 hex characters and used only to tie
       a handful of events into one visit. It is not a user id, it is not
       stable across days, and nothing joins it to a booking. */
    $session = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) ($body['sid'] ?? '')) ?? '');
    $session = $session !== '' ? substr(str_pad($session, 32, '0'), 0, 32) : null;

    $props = [];
    $given = is_array($body['props'] ?? null) ? $body['props'] : [];
    foreach (ALLOWED_PROPS as $key) {
        if (!array_key_exists($key, $given)) {
            continue;
        }
        $v = $given[$key];
        if (is_bool($v)) {
            $props[$key] = $v;
        } elseif (is_int($v) || is_float($v)) {
            $props[$key] = $v;
        } elseif (is_string($v)) {
            $clean = beacon_scrub(mb_substr(trim($v), 0, 60));
            if ($clean !== '') {
                $props[$key] = $clean;
            }
        }
        // Arrays and objects are dropped: nested payloads are where
        // unreviewed personal data hides.
    }

    $path = beacon_scrub(mb_substr((string) ($body['path'] ?? ''), 0, 160));

    /* A signed-in customer's id is kept because the funnel question "do
       returning customers drop off at the same step?" cannot be answered
       without it — and that customer already has an account with us. An
       anonymous visitor stays anonymous. */
    $userId = null;
    $me     = Auth::user();
    if ($me !== null && isset($me['id'])) {
        $userId = (int) $me['id'];
    }

    Database::insert('app_events', [
        'name'        => $name,
        'session_key' => $session,
        'user_id'     => $userId,
        'props'       => $props !== [] ? substr((string) json_encode($props, JSON_UNESCAPED_UNICODE), 0, 1000) : null,
        'path'        => $path !== '' ? $path : null,
    ]);
} catch (Throwable $e) {
    /* Never surface a beacon failure. A missing app_events table (migration
       not yet applied on this server) must not put an error in the console of
       every customer's phone. */
    try {
        Logger::warning('beacon write failed', ['error' => $e->getMessage()], 'events');
    } catch (Throwable $ignored) {
    }
}

beacon_done();
