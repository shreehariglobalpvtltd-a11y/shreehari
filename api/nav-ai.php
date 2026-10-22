<?php
/**
 * /api/nav-ai.php — the server side of the SHARI NAV "S Hari AI Guide"
 * (21 Oct 2026, ai1).
 *
 * The guide on navigate.html answers most questions on the phone itself,
 * from its knowledge base (temples, border posts, emergency numbers,
 * currency, nearby categories) — no network at all. Two things only this
 * server knows:
 *
 *   GET  ?facts=1   the office as the desk has set it (Admin -> Company
 *                   details): number, WhatsApp, company name, corridor km
 *                   and hours. The page carries none of these itself, so a
 *                   changed number changes everywhere at once. Cached an hour.
 *   POST            a question the guide did not recognise. First the
 *                   local brain (includes/walocal.php: timetable, seats
 *                   left, where is the bus — read from the tables, no AI
 *                   key), then the same assistant the website chat uses
 *                   (includes/aiagent.php), told where the rider is and
 *                   where they are going so "how far is the border" has
 *                   a real answer.
 *
 * Shape — FLAT, not the Response envelope (the page reads j.text):
 *   GET  -> {ok:true, office:{phone, tel, wa}, company, routeKm, journeyHours, ai:bool, v}
 *           phone = as people read it, tel = digits for tel:, wa = digits for wa.me/
 *   POST {message, lang:'ne'|'hi'|'en', me:{lat,lng}|null, to:{name,lat,lng}|null,
 *         route:{mode,distKm,durMin}|null, country:'IN'|'NP'|''}
 *        -> {ok:true, text, source:'local'|'ai', actions:[{l, act:'url', p}]}
 *        -> {ok:false, reason:'method'|'origin'|'rate'|'bad'|'off'|'busy'}
 *           405 / 403 / 429 (+ Retry-After) / 400 / 503 (assistant off) / 502 (busy)
 *
 * No CSRF token: navigate.html is a static page with no session form, so
 * the POST is guarded by the Origin / Referer host check plus two per-IP
 * budgets here (wide, because a CGNAT tower shares one IP) and by the
 * agent's own per-rider caps inside AiAgent::handleWeb(). Whoever is
 * signed in, the nav chat is served as a CUSTOMER: an office login on a
 * phone must not reach the office tools through a public page.
 *
 * Privacy: the body is never logged here (the agent logs its own turn);
 * the rider's position is rounded to 2 decimals (~1 km) before it is
 * sent on, the destination to 3.
 *
 * Layout: the request flow first, the pure helpers (body validation, host
 * check, prompt, reply clean-up, action mapping, tel digits) at the bottom —
 * PHP binds them at compile time, so a test may include this file with
 * SHG_NAV_AI_TEST defined and call them with no bootstrap and no database.
 */
declare(strict_types=1);
define('SHG_APP', true);

const NAVAI_VERSION     = '20261021a';
const NAVAI_MAX_MESSAGE = 400;      // characters; the guide's box stops there too and WaLocal ignores longer
const NAVAI_MAX_BODY    = 4096;     // bytes of JSON; a question with context is ~400
const NAVAI_MAX_NAME    = 80;       // destination name as told to the agent

if (defined('SHG_NAV_AI_TEST')) {
    return;                                             // tests: helpers only — no bootstrap, no DB, no output
}

/* =====================================================================
 *  The request
 * ===================================================================== */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
// bootstrap.php loads the core only; the brains and the site facts are per-endpoint.
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/aiagent.php';
require_once INCLUDE_PATH . '/walocal.php';
require_once INCLUDE_PATH . '/sitefacts.php';
require_once INCLUDE_PATH . '/aibrain.php';                // the travel guide for everything that is not the bus (23 Oct 2026)

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$send = static function (array $payload, int $code = 200, string $cache = 'no-store'): never {
    http_response_code($code);
    header('Cache-Control: ' . $cache);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$fail = static function (int $code, string $reason) use ($send): never {
    $send(['ok' => false, 'reason' => $reason], $code);
};
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/* 1. Facts: the office as the desk set it. wa is the number customers
      chat with (the Meta order bot), the office handset only when that is
      unset. Public and cacheable — unless this very answer started the
      visitor's session, in which case a shared cache must not keep its
      Set-Cookie. */
if ($method !== 'POST' && isset($_GET['facts'])) {
    $phone = Settings::officePhone();
    $wa    = SiteFacts::chatDigits();
    if ($wa === '') {
        $wa = Settings::officeWhatsApp();
    }
    $cookie = preg_grep('/^Set-Cookie:/i', headers_list()) !== [];
    $send([
        'ok'           => true,
        'office'       => ['phone' => $phone, 'tel' => navai_tel($phone), 'wa' => $wa],
        'company'      => (string) (Settings::company()['name'] ?? APP_NAME),
        'routeKm'      => SiteFacts::routeKm(),
        'journeyHours' => SiteFacts::journeyHours(),
        'ai'           => AiAgent::webEnabled(),
        'v'            => NAVAI_VERSION,
    ], 200, ($cookie ? 'private' : 'public') . ', max-age=3600');
}

/* 2. Only a question is a POST. */
if ($method !== 'POST') {
    header('Allow: GET, POST');
    $fail(405, 'method');
}

/* 3. Our own page only (no CSRF token on a static page — see the header). */
$hosts = [(string) ($_SERVER['HTTP_HOST'] ?? '')];
if (defined('APP_URL')) {
    $hosts[] = (string) parse_url((string) APP_URL, PHP_URL_HOST);
}
if (!navai_sameHost($_SERVER['HTTP_ORIGIN'] ?? null, $_SERVER['HTTP_REFERER'] ?? null, $hosts)) {
    $fail(403, 'origin');
}

/* 4. Per-IP budgets, wide on purpose: a mobile tower (CGNAT) puts many
      riders behind one address. The real guard is the agent's own
      per-rider cap. The page shows its offline line on a 429, never the
      raw error, and may try again after a minute. */
$ip = Security::clientIp();
if (!Security::rateLimit('nav_ai', $ip, 30, 300) || !Security::rateLimit('nav_ai_day', $ip, 600, 86400)) {
    header('Retry-After: 60');
    $fail(429, 'rate');
}

/* 5. The body. Content-Length first so an oversized post is a flat 400
      here rather than Response::input()'s enveloped 413. */
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > NAVAI_MAX_BODY) {
    $fail(400, 'bad');
}
Response::$maxInputBytes = NAVAI_MAX_BODY;
$q = navai_parse(Response::input());
if ($q === null) {
    $fail(400, 'bad');
}

/* 6. Who is asking: always a customer here. whoIsWeb() reads the signed-in
      session, so an office phone opening the public navigator would carry
      the office role — and its tools — into this chat. Demote before BOTH
      brains. The history / rate key follows: an office login must not
      share the office chat's memory, an empty key or a session-less guest
      key (every guest would be one rider) gets a per-phone key instead. */
$ctx                 = AiTools::whoIsWeb();
$ctx['role']         = 'customer';
$ctx['admin']        = null;
$ctx['adminId']      = 0;
$ctx['scopeAdminId'] = null;
$stageKey = trim((string) ($ctx['stageKey'] ?? ''));
if ($stageKey === '' || str_starts_with($stageKey, 'web-staff-')
    || (str_starts_with($stageKey, 'web-guest-') && session_status() !== PHP_SESSION_ACTIVE)) {
    $ctx['stageKey'] = 'nav-' . substr(hash('sha256', $ip . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 20);
}

/* The local brain: timetable, seats left, where is the bus, how to pay,
   refund rule, Nepal office — from the tables, no AI key, no quota. It
   answers only what it recognises (null otherwise, also for > 400
   characters, an SHG-#### code, or when wa_local_brain_on is off). */
try {
    $loc = WaLocal::answerWeb($ctx, $q['message'], $q['lang']);
    if ($loc !== null && trim((string) ($loc['text'] ?? '')) !== '') {
        $media = trim((string) ($loc['media'] ?? ''));
        $acts  = navai_actions([['label' => 'Open', 'href' => $media]]);
        if ($acts !== []) {
            $acts[0]['l'] = ['ne' => 'खोल्नुहोस्', 'hi' => 'खोलें', 'en' => 'Open'];
        }
        $send(['ok' => true, 'text' => navai_clean((string) $loc['text']), 'source' => 'local', 'actions' => $acts]);
    }
} catch (Throwable $e) {
    Logger::exception($e, 'ai');                        // the assistant still answers
}

/* 7. The assistant. Off (no key / switched off) is a quiet 503: the guide
      shows its office / WhatsApp line instead. */
if (!AiAgent::webEnabled()) {
    $fail(503, 'off');
}

/* 8. 23 Oct 2026 (owner: "har ek prasna ko ans din sakne, gpt gemini jasto,
      tha xaina vane tha xaina vanos"): a question that is not about the S Hari
      bus goes to the one AI (AiBrain: Gemini / GPT / Claude) as a travel guide
      that may answer anything - with the rule that it says "I don't know"
      instead of guessing, and never invents a phone number, address, price or
      timing. The booking agent below keeps the bus questions (its tools read
      the real seats and fares). Its own per-rider cap, as the agent has one. */
if (!navai_busQuestion($q['message']) && AiBrain::configured()
    && Security::rateLimit('nav_guide', (string) $ctx['stageKey'], 60, 3600)) {
    $g = null;
    try {
        $g = AiBrain::text(navai_guideSystem($q['lang']), navai_turns($q), 700, 'nav-guide');
    } catch (Throwable $e) {
        Logger::exception($e, 'ai');
    }
    if ($g !== null && trim((string) $g['text']) !== '') {
        $send(['ok' => true, 'text' => navai_clean((string) $g['text']), 'source' => 'guide', 'actions' => []]);
    }
}

$answer = null;
try {
    $answer = AiAgent::handleWeb($ctx, navai_prompt($q), $q['lang'] === 'gu' ? 'hi' : $q['lang']);
} catch (Throwable $e) {
    Logger::exception($e, 'ai');
}
if ($answer === null || trim((string) ($answer['text'] ?? '')) === '') {
    $fail(502, 'busy');                                 // its own rate limit, both brains down, or an empty reply
}

$send([
    'ok'      => true,
    'text'    => navai_clean((string) $answer['text']),
    'source'  => 'ai',
    'actions' => navai_actions($answer['actions'] ?? []),
]);

/* =====================================================================
 *  Pure helpers (no app classes, no database — testable on their own)
 * ===================================================================== */

/** One number from the body as a float, or null when it is not one. */
function navai_num(mixed $v): ?float
{
    if (is_int($v) || is_float($v)) {
        return is_finite((float) $v) ? (float) $v : null;
    }
    if (is_string($v) && is_numeric($v)) {
        return (float) $v;
    }
    return null;
}

/**
 * {lat,lng} rounded to $dp decimals: 2 for the rider (~1 km — enough for
 * an answer, not a trail), 3 for a destination (a public place).
 */
function navai_point(mixed $v, int $dp): ?array
{
    if (!is_array($v)) {
        return null;
    }
    $lat = navai_num($v['lat'] ?? null);
    $lng = navai_num($v['lng'] ?? null);
    if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return null;
    }
    return ['lat' => round($lat, $dp), 'lng' => round($lng, $dp)];
}

/** A place name as the agent may see it: printable, one line, capped. */
function navai_name(mixed $v): string
{
    if (!is_string($v)) {
        return '';
    }
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '';   // null = invalid UTF-8 -> ''
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return preg_replace('/^(.{' . NAVAI_MAX_NAME . '}).+$/su', '$1', $s) ?? '';
}

/**
 * The decoded POST body -> a trusted question, or null when it is not one
 * (not an object, no message, empty, over 400 characters, bad UTF-8).
 * Everything optional is dropped rather than refused: a missing location
 * is a normal state, not a bad request.
 *
 * @return array{message:string, lang:string, me:?array, to:?array, route:?array, country:string}|null
 */
function navai_parse(mixed $in): ?array
{
    if (!is_array($in) || !is_string($in['message'] ?? null)) {
        return null;
    }
    $msg = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $in['message']);
    $msg = trim((string) ($msg ?? ''));
    if ($msg === '' || preg_match('/^.{' . (NAVAI_MAX_MESSAGE + 1) . '}/su', $msg) === 1) {
        return null;
    }

    $lang = $in['lang'] ?? '';
    $lang = is_string($lang) && in_array($lang, ['ne', 'hi', 'en', 'gu'], true) ? $lang : 'ne';

    // the last few turns, so "ani tyo kati tadha xa?" knows what "tyo" is (23 Oct 2026)
    $hist = [];
    foreach (is_array($in['hist'] ?? null) ? array_slice($in['hist'], -6) : [] as $h) {
        if (!is_array($h) || !is_string($h['t'] ?? null)) {
            continue;
        }
        $t = trim((string) (preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $h['t']) ?? ''));
        $t = preg_replace('/^(.{300}).+$/su', '$1', $t) ?? '';
        if ($t !== '') {
            $hist[] = ['r' => ($h['r'] ?? '') === 'bot' ? 'bot' : 'user', 't' => $t];
        }
    }

    $country = $in['country'] ?? '';
    $country = is_string($country) && in_array($country, ['IN', 'NP'], true) ? $country : '';

    $to = navai_point($in['to'] ?? null, 3);
    if ($to !== null) {
        $to['name'] = navai_name(is_array($in['to']) ? ($in['to']['name'] ?? '') : '');
    }

    $route = null;
    $r = $in['route'] ?? null;
    if (is_array($r)) {
        $mode = $r['mode'] ?? '';
        $km   = navai_num($r['distKm'] ?? null);
        $min  = navai_num($r['durMin'] ?? null);
        if (is_string($mode) && in_array($mode, ['car', 'bus', 'bike', 'walk'], true)
            && $km !== null && $min !== null && $km >= 0 && $km < 20000 && $min >= 0 && $min < 100000) {
            $route = ['mode' => $mode, 'distKm' => round($km, 1), 'durMin' => (int) round($min)];
        }
    }

    return [
        'message' => $msg,
        'lang'    => $lang,
        'me'      => navai_point($in['me'] ?? null, 2),
        'to'      => $to,
        'route'   => $route,
        'country' => $country,
        'hist'    => $hist,
    ];
}

/**
 * Is this about the S Hari bus itself (seats, fares, timings, tickets,
 * refunds)? Those stay with the booking agent, whose tools read the real
 * tables; everything else may go to the travel guide.
 */
function navai_busQuestion(string $msg): bool
{
    $m = mb_strtolower($msg, 'UTF-8');
    return preg_match('/\b(bus|buses|coach|ticket|tickets|tikat|tiket|seat|seats|booking|booked|fare|fares|bhada|bhaada|kiraya|kiraaya|pnr|refund|cancel|'
        . 'departure|depart|boarding|pickup|pick up|chalani|s ?hari|shree ?hari|shri ?hari|rupaidiha|rupaidia|sleeper|berth|agent|office)\b|shg-?\d'
        . '|बस|टिकट|टिकेट|सिट|सीट|बुक|भाडा|किराया|रुपैडिहा|रूपैडिहा|रुपईडीहा|फिर्ता|रद्द|स्लिपर|બસ|ટિકિટ|સીટ|ભાડું|બુક/u', $m) === 1;
}

/** The one AI as SHARI NAV's travel guide: answer anything, honestly, briefly. */
function navai_guideSystem(string $lang): string
{
    $langName = ['ne' => 'Nepali (Devanagari script)', 'hi' => 'Hindi (Devanagari script)', 'en' => 'English', 'gu' => 'Gujarati (Gujarati script)'][$lang] ?? 'Nepali (Devanagari script)';
    return implode("\n", [
        'You are "S Hari AI", the smart assistant inside SHARI NAV, the map and navigation app of S Hari Global Pvt Ltd (a bus company running between Gujarat, India and the Nepal border at Rupaidiha / Nepalgunj).',
        'You can answer ANY question a traveller asks - travel, places, culture, festivals, food, history, geography, language and translation, simple maths, money, health basics, phones, general knowledge - like a smart, friendly assistant.',
        'Reply in ' . $langName . ', even when the question is written in Roman letters or mixed Nepali / Hindi / English. Use simple everyday words.',
        'Keep it short for a phone screen: 2 to 6 short lines. A list of 3 to 5 items with one line each when the question asks for suggestions. Plain text; **bold** is allowed; no headings, no tables, no links.',
        'HONESTY RULES (most important):',
        '- If you are not sure, say so plainly ("मलाई यसको पक्का जानकारी छैन" in Nepali, "मुझे इसकी पक्की जानकारी नहीं है" in Hindi, "I am not sure about this" in English) and say where to check. Never guess and never make things up.',
        '- Never invent phone numbers, addresses, prices, opening hours, ratings, timetables or names of particular shops, hotels or restaurants. For real places near the rider, tell them to ask like "najik ko hotel" / "nearby restaurant" - the map then lists real places with distance.',
        '- You cannot see live data (today\'s news, traffic now, live prices, exchange rates today, results, who is open now). Say so when asked, give what is generally true, and mark it as approximate.',
        '- Famous, well-known places (big temples, cities, national parks, lakes, landmarks) you may describe and suggest by name, with "approx." on any distance or time.',
        '- S Hari bus fares, seats, timings, tickets, refunds: do not answer from memory - tell them to ask about the bus here or contact the S Hari office. Cancellations and refunds are done by the office by hand.',
        '- Health: give safe general advice only and say to see a doctor; in an emergency India 112 / ambulance 108, Nepal police 100 / ambulance 102.',
        '- Never ask for passwords, OTPs or card numbers. Refuse anything harmful or illegal politely.',
        'The first user line may begin with the rider\'s situation in square brackets (position rounded to ~1 km, country, destination, route): use it only when it helps the answer.',
    ]);
}

/**
 * The guide's conversation: the last turns as they were, then this
 * question with the rider's situation in front of it (navai_prompt).
 *
 * @param array{message:string, lang:string, me:?array, to:?array, route:?array, country:string, hist:list<array{r:string,t:string}>} $q
 * @return list<array{role:string, content:string}>
 */
function navai_turns(array $q): array
{
    $out = [];
    foreach ($q['hist'] ?? [] as $h) {
        $out[] = ['role' => $h['r'] === 'bot' ? 'assistant' : 'user', 'content' => $h['t']];
    }
    $out[] = ['role' => 'user', 'content' => navai_prompt($q)];
    return $out;
}

/**
 * Is this request from our own page? Browsers send Origin on every POST
 * (same-origin included) and Referer on most, so a cross-site form or a
 * script on another site fails here. No header at all (curl, a crawler)
 * passes — the rate limits deal with those. "null" (sandboxed / file://)
 * has no host and fails.
 *
 * @param string[] $hosts our hostnames (APP_URL host, HTTP_HOST); ports are ignored
 */
function navai_sameHost(?string $origin, ?string $referer, array $hosts): bool
{
    $ours = [];
    foreach ($hosts as $h) {
        $h = strtolower(trim((string) $h));
        $h = preg_replace('/:\d+$/', '', $h) ?? $h;
        if ($h !== '') {
            $ours[] = $h;
        }
    }
    $check = static function (string $url) use ($ours): bool {
        $h = parse_url(trim($url), PHP_URL_HOST);
        return is_string($h) && $h !== '' && in_array(strtolower($h), $ours, true);
    };
    if ($origin !== null && trim($origin) !== '') {
        return $check($origin);
    }
    if ($referer !== null && trim($referer) !== '') {
        return $check($referer);
    }
    return true;
}

/**
 * What the agent is asked. The rider's situation goes in front of the
 * question so the model answers THIS road, not the office counter.
 *
 * @param array{message:string, lang:string, me:?array, to:?array, route:?array, country:string} $q
 */
function navai_prompt(array $q): string
{
    $pt = static fn(array $p, int $dp): string => number_format($p['lat'], $dp, '.', '') . ',' . number_format($p['lng'], $dp, '.', '');
    $parts = [];
    if ($q['me'] !== null) {
        $parts[] = 'Rider is at ' . $pt($q['me'], 2) . ($q['country'] !== '' ? ' (' . $q['country'] . ')' : '');
    } else {
        $parts[] = 'Rider location unknown' . ($q['country'] !== '' ? ' (' . $q['country'] . ')' : '');
    }
    if ($q['to'] !== null) {
        $name = $q['to']['name'] !== '' ? $q['to']['name'] : 'pin';
        $parts[] = 'destination: ' . $name . ' (' . $pt($q['to'], 3) . ')';
    } else {
        $parts[] = 'no destination set';
    }
    if ($q['route'] !== null) {
        $parts[] = 'route: ' . $q['route']['mode'] . ', ' . number_format($q['route']['distKm'], 1, '.', '') . ' km, ' . $q['route']['durMin'] . ' min';
    }
    $langName = ['ne' => 'Nepali', 'hi' => 'Hindi', 'en' => 'English', 'gu' => 'Gujarati'][$q['lang']] ?? 'Nepali';
    return '[SHARI NAV navigation app] ' . implode(', ', $parts)
        . '. Answer briefly for a traveller on the road, in ' . $langName . '. Question: ' . $q['message'];
}

/**
 * The reply as a chat bubble wants it: markdown headings lose their
 * hashes, table rows become "a · b" lines and the |---| rulers go, line
 * breaks stay, **bold** stays (the page renders it).
 */
function navai_clean(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $out  = [];
    foreach (explode("\n", $text) as $line) {
        $t = rtrim($line);
        if (preg_match('/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/', $t) === 1) {
            continue;                                   // |---|---| table ruler
        }
        if (preg_match('/^\s*\|.*\|\s*$/', $t) === 1) {
            $cells = array_map('trim', explode('|', trim(trim($t), '|')));
            $cells = array_values(array_filter($cells, static fn(string $c): bool => $c !== ''));
            $t     = implode(' · ', $cells);
        }
        $t     = preg_replace('/^\s*#{1,6}\s+/', '', $t) ?? $t;
        $out[] = $t;
    }
    $s = implode("\n", $out);
    $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
    return trim($s);
}

/**
 * The agent's buttons -> the guide's. AiAgent::handleWeb gives
 * [{label, href}] built from what its tools did (book this seat, open
 * ticket, pay now, track). '#/…' is the booking app's own hash route, so
 * it is served from '/'. Anything under /admin is the office's and is
 * dropped — on a site-relative link, a hash route or our own absolute
 * URL alike; so is anything that is not an http(s) or site-relative link.
 *
 * @return array<int, array{l:string, act:string, p:string}>
 */
function navai_actions(mixed $acts): array
{
    if (!is_array($acts)) {
        return [];
    }
    $out = [];
    foreach ($acts as $a) {
        if (!is_array($a) || !is_string($a['label'] ?? null) || !is_string($a['href'] ?? null)) {
            continue;
        }
        $label = navai_name($a['label']);
        $label = preg_replace('/^(.{40}).+$/su', '$1', $label) ?? $label;
        $href  = trim($a['href']);
        if ($label === '' || $href === '' || preg_match('/[\s\x00-\x1F]/', $href) === 1) {
            continue;
        }
        if ($href[0] === '#') {
            $href = '/' . $href;
        }
        if (preg_match('#^(https?://[^/\s]+|/)#i', $href) !== 1) {
            continue;
        }
        $path = preg_match('#^https?://#i', $href) === 1 ? (string) (parse_url($href, PHP_URL_PATH) ?? '') : $href;
        $path = preg_replace('#^/\#/?#', '/', $path) ?? $path;     // '/#/admin/x' -> '/admin/x'
        if (str_starts_with(strtolower($path), '/admin')) {
            continue;
        }
        $out[] = ['l' => $label, 'act' => 'url', 'p' => $href];
        if (count($out) >= 4) {
            break;
        }
    }
    return $out;
}

/** "+91 XXXXX XXXXX" -> "91XXXXXXXXXX" for tel:; a bare 10-digit number gets 91. */
function navai_tel(string $display): string
{
    $d = preg_replace('/\D+/', '', $display) ?? '';
    if (strlen($d) === 10) {
        $d = '91' . $d;
    }
    return $d;
}
