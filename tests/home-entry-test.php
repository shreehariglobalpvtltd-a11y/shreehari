<?php
/**
 * home-entry-test.php — the three booking doors on the home view (24 Sep 2026).
 *
 * Owner (Phase B): three cards at the top of the home page, first on a phone —
 * Normal Booking (blue), Quick Ticket (orange), WhatsApp Booking (green). Each
 * card must only OPEN a flow that already exists, so the suite proves:
 *
 *   1. #entryCards holds exactly those three cards, each wired by a data
 *      attribute to an existing handler (initBookMode's [data-bkmode], the
 *      WhatsApp sheet's [data-wa-open]) — no inline handler, no JS of its own,
 *      and no phone number written into the markup;
 *   2. those handlers are still bound exactly once, and the sheet's wa.me link
 *      is still built by officeNum() from the configured booking number;
 *   3. on a phone the cards lead (order:0) inside the existing mobile block;
 *   4. white text on every card keeps a WCAG AA contrast of 4.5:1;
 *   5. every label the home view and the WhatsApp sheet print exists in
 *      en, hi and ne — the sheet's error messages had shipped with no text,
 *      so a failed check printed "waReqPhoneError" to the customer (node's
 *      i18n-check.js is not run on the VPS, so this is the PHP guard) — and
 *      the Gujarati override carries the card labels;
 *   6. the number the green card opens is Settings::bookingWhatsApp(), the
 *      same value index.php publishes as whatsapp_booking_number.
 *
 * Reads only.
 *
 *   php tests/home-entry-test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$root = dirname(__DIR__);
$PASS = 0;
$FAIL = 0;

function check(string $label, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    }
}

echo "\n=== Home: three booking doors ===\n\n";

$tpl    = (string) file_get_contents($root . '/app.template.html');
$css    = (string) file_get_contents($root . '/assets/css/views.css');
$router = (string) file_get_contents($root . '/assets/js/05-router.js');
$i18n   = (string) file_get_contents($root . '/assets/js/04-i18n.js');

/* ---- 1. the section and its three cards ---------------------------- */
check('exactly one #entryCards section', substr_count($tpl, 'id="entryCards"') === 1);
$section = preg_match('~<section[^>]*id="entryCards"[^>]*>(.*?)</section>~s', $tpl, $m) ? $m[1] : '';
check('the section has a body', $section !== '');

preg_match_all('~<(a|button)\b([^>]*\bclass="[^"]*\bec-card\b[^"]*"[^>]*)>~', $section, $cards);
check('three cards, no more', count($cards[2]) === 3, count($cards[2]) . ' found');

$want = [
    'ec-normal' => 'data-bkmode="regular"',
    'ec-quick'  => 'data-bkmode="quick"',
    'ec-wa'     => 'data-wa-open="booking"',
];
foreach ($want as $cls => $attr) {
    $hit = '';
    foreach ($cards[2] as $attrs) {
        if (preg_match('~\bclass="[^"]*\b' . preg_quote($cls, '~') . '\b~', $attrs)) { $hit = $attrs; }
    }
    check("{$cls} card exists", $hit !== '');
    check("{$cls} opens the existing flow via {$attr}", $hit !== '' && str_contains($hit, $attr));
}
$waCard = '';
foreach ($cards[2] as $attrs) { if (str_contains($attrs, 'ec-wa')) { $waCard = $attrs; } }
check('the WhatsApp card announces the sheet dialog', str_contains($waCard, 'aria-controls="waSheet"') && str_contains($waCard, 'aria-haspopup="dialog"'));

check('no inline event handler inside the cards', !preg_match('~\son[a-z]+\s*=~i', $section));
check('no wa.me link written into the cards', stripos($section, 'wa.me') === false);
check('no phone number written into the cards', !preg_match('~\d{8,}~', preg_replace('~<!--.*?-->~s', '', $section) ?? ''));
check('the section is labelled by its heading', str_contains($tpl, 'aria-labelledby="entryCardsTitle"') && str_contains($section, 'id="entryCardsTitle"'));

$posCards = strpos($tpl, 'id="entryCards"');
$posQuick = strpos($tpl, 'id="quick-ticket"');
$posSearch = strpos($tpl, 'id="search-anchor"');
check('desktop order: cards come before QuickBot and the search card',
    $posCards !== false && $posQuick !== false && $posSearch !== false && $posCards < $posQuick && $posCards < $posSearch);

foreach (['search-anchor', 'quick-ticket', 'quickTicket', 'waSheet', 'waBookLink', 'heroQtCta'] as $id) {
    check("#{$id} still exists", substr_count($tpl, 'id="' . $id . '"') === 1);
}
check('still exactly one <h1>', preg_match_all('~<h1[\s>]~i', $tpl) === 1);

/* ---- 2. the handlers the cards reuse --------------------------------- */
check("initBookMode's delegated [data-bkmode] handler is bound once",
    substr_count($router, "closest('[data-bkmode]')") === 1);
check('the [data-wa-open] openers are bound once',
    substr_count($router, "querySelectorAll('[data-wa-open]')") === 1);
$jsHits = [];
foreach (glob($root . '/assets/js/*.js') ?: [] as $f) {
    $src = (string) file_get_contents($f);
    if (str_contains($src, 'entryCards') || str_contains($src, 'ec-card')) { $jsHits[] = basename($f); }
}
check('the cards add no JS of their own (no second listener)', $jsHits === [], implode(', ', $jsHits));
check('officeNum() reads the published booking number first',
    (bool) preg_match('~function officeNum\(\)\s*\{.{0,300}?settings\.whatsapp_booking_number\s*\|\|\s*settings\.company_whatsapp~s', $router));
check('the sheet builds its wa.me link from officeNum()',
    str_contains($router, 'this.href = waUrl(officeNum(), composeBooking());'));

/* ---- 3. phone order --------------------------------------------------- */
$a = strpos($css, '[Round-3] MOBILE: widget above hero');
$b = strpos($css, '[Round-3] BUS-CARD');
$mobile = ($a !== false && $b !== false && $b > $a) ? substr($css, $a, $b - $a) : '';
check('the mobile home block is found', $mobile !== '');
check('phone: cards lead with order:0 inside that block', str_contains($mobile, '#view-home > #entryCards{order:0'));
check('phone: QuickBot (1) and the search card (2) keep their places',
    str_contains($mobile, '#view-home > #quick-ticket{order:1') && str_contains($mobile, '#view-home > .search-wrap{order:2'));
check('phone: the section sits inside @media (max-width:760px)', (bool) preg_match('~@media \(max-width:760px\)\{.*#view-home > #entryCards\{order:0.*\n\}~s', $mobile));
check('card height is not flattened by app.css button[type=button]{min-height:44px}',
    str_contains($css, '.ec-grid .ec-card{min-height:118px}'));
check('the hover lift is for hover-capable pointers only',
    str_contains($css, '@media (hover:hover){.ec-card:hover{'));

/* ---- 4. colour + contrast --------------------------------------------- */
function lum(string $hex): float
{
    $c = array_map(static fn ($h) => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
    $c = array_map(static fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
foreach (['ec-normal' => 'blue', 'ec-quick' => 'orange', 'ec-wa' => 'green'] as $cls => $name) {
    $ok = preg_match('~\.' . $cls . '\{background:linear-gradient\(135deg,(#[0-9A-Fa-f]{6})[^,]*,(#[0-9A-Fa-f]{6})~', $css, $g);
    check("{$cls} ({$name}) has its gradient", (bool) $ok);
    if ($ok) {
        $worst = min(1.05 / (lum($g[1]) + 0.05), 1.05 / (lum($g[2]) + 0.05));
        check("{$cls}: white text contrast >= 4.5:1", $worst >= 4.5, sprintf('%.2f:1', $worst));
    }
}
$ring = preg_match('~\.ec-card:focus-visible\{outline:3px solid (#[0-9A-Fa-f]{6})~', $css, $r) ? $r[1] : '';
check('the light-theme focus ring is >= 3:1 on the page background', $ring !== ''
    && (lum('#F6F8FC') + 0.05) / (lum($ring) + 0.05) >= 3.0, $ring);

/* ---- 5. every label exists in en, hi and ne --------------------------- */
$iEn = strpos($i18n, "\nen: {");
$iHi = strpos($i18n, "\nhi: {");
$iNe = strpos($i18n, "\nne: {");
$iEnd = $iNe === false ? false : strpos($i18n, "\n};", $iNe);
check('the three language blocks are found', $iEn !== false && $iHi !== false && $iNe !== false && $iEnd !== false);
$blocks = [
    'en' => substr($i18n, (int) $iEn, (int) $iHi - (int) $iEn),
    'hi' => substr($i18n, (int) $iHi, (int) $iNe - (int) $iHi),
    'ne' => substr($i18n, (int) $iNe, (int) $iEnd - (int) $iNe),
];
$keys = [];
preg_match_all('~data-i18n(?:-ph|-title)?="([A-Za-z][A-Za-z0-9_]*)"~', $tpl, $tk);
foreach ($tk[1] as $k) { $keys[$k] = 'template'; }
foreach (glob($root . '/assets/js/*.js') ?: [] as $f) {
    if (basename($f) === '04-i18n.js') { continue; }   // its header comment says t('key')
    $src = (string) file_get_contents($f);
    preg_match_all("~\\bt[f]?\\(\\s*'([A-Za-z][A-Za-z0-9_]*)'~", $src, $jk);
    foreach ($jk[1] as $k) { $keys[$k] ??= basename($f); }
}
// set via setAttribute / fail() rather than t('...') in the sheet code
foreach (['waReqFixNote', 'waReqNeedNote', 'waReqHelpPh', 'waReqNotePh', 'waReqPhoneError', 'waReqDateError',
          'waReqPointError', 'waReqPnrError', 'waReqNeed', 'sBoard', 'sDrop'] as $k) {
    $keys[$k] ??= '05-router.js';
}
$missing = [];
foreach ($keys as $k => $where) {
    foreach ($blocks as $lang => $body) {
        if (!preg_match('~(^|[\s,{])' . preg_quote($k, '~') . '\s*:~', $body)) { $missing[] = "{$lang}:{$k} ({$where})"; }
    }
}
check('every home/sheet label exists in en, hi and ne (' . count($keys) . ' keys)', $missing === [], implode(', ', array_slice($missing, 0, 12)));
$cardKeys = ['ecTitle', 'ecNormal', 'ecNormalSub', 'ecQuick', 'ecQuickSub', 'ecWa', 'ecWaSub'];
foreach ($cardKeys as $k) {
    $defs = 0;
    foreach ($blocks as $body) { $defs += preg_match_all('~(^|[\s,{])' . $k . '\s*:~', $body); }
    check("{$k} is defined once per language", $defs === 3, (string) $defs);
}
check('the privacy line still says a request does not confirm a seat',
    (bool) preg_match("~waReqPrivacy: '[^']*does not confirm a seat~", $blocks['en']));
foreach ($blocks as $lang => $body) {
    $chat = preg_match("~waReqChat: '([^']*)'~", $body, $c1) ? $c1[1] : '';
    $fail = preg_match("~waReqFail: '([^']*)'~", $body, $c2) ? $c2[1] : '';
    check("{$lang}: a failed send points at the real fallback button", $chat !== '' && str_contains($fail, $chat), $chat);
}
$gPos = strpos($i18n, 'I18N.gu = Object.assign(');
if ($gPos !== false) {
    $gLine = substr($i18n, $gPos, (strpos($i18n, "\n", $gPos) ?: strlen($i18n)) - $gPos);
    $gMiss = array_values(array_filter($cardKeys, static fn ($k) => !str_contains($gLine, '"' . $k . '":')));
    check('the Gujarati override carries the card labels', $gMiss === [], implode(', ', $gMiss));
}

/* ---- 6. the number the green card opens -------------------------------- */
$pub = Settings::publicSettings();
$booking = Settings::bookingWhatsApp();
check('index.php publishes whatsapp_booking_number', array_key_exists('whatsapp_booking_number', $pub));
check('...and it is Settings::bookingWhatsApp()', ($pub['whatsapp_booking_number'] ?? null) === $booking);
check('the booking number is a dialable international number', (bool) preg_match('/^[1-9]\d{7,14}$/', $booking), strlen($booking) . ' digits');

echo "\n----------------------------------------\n";
echo "  {$PASS} passed, {$FAIL} failed\n";
echo "----------------------------------------\n\n";

exit($FAIL === 0 ? 0 : 1);
