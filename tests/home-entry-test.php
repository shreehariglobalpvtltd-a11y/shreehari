<?php
/**
 * home-entry-test.php — booking first on the home view (Oct 2026 redesign).
 *
 * History: 24 Sep 2026 the owner asked for three booking doors (Normal, Quick
 * Ticket, WhatsApp) first on a phone. The Oct 2026 brief replaced that: the
 * first screen is the booking panel itself (direction, date, town,
 * passengers, "Find Bus / बस खोज्नुहोस्"), with the two other ways to book
 * right under it. This suite now proves that layout, and keeps every
 * safeguard the old one had:
 *
 *   1. the booking panel is the first block of #view-home and carries the
 *      page's one <h1>; #entryCards holds the two other doors, each wired by a
 *      data attribute to an existing handler (initBookMode's [data-bkmode],
 *      the WhatsApp sheet's [data-wa-open]) — no inline handler, no JS of its
 *      own, no phone number in the markup;
 *   2. those handlers are still bound exactly once, and the sheet's wa.me link
 *      is still built by officeNum() from the configured booking number;
 *   3. on every width the booking panel leads (redesign.css order), and
 *      #view-home is only ever displayed while .active;
 *   4. card text and the focus ring meet WCAG contrast, computed from the
 *      design-system tokens in ds.css;
 *   5. every label the home view and the WhatsApp sheet print exists in
 *      en, hi and ne, and the Gujarati override carries the card labels;
 *   6. the number the WhatsApp card opens is Settings::bookingWhatsApp(), the
 *      same value index.php publishes as whatsapp_booking_number.
 *

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

echo "\n=== Home: booking first ===\n\n";

$tpl    = (string) file_get_contents($root . '/app.template.html');
$css    = (string) file_get_contents($root . '/assets/css/redesign.css');
$ds     = (string) file_get_contents($root . '/assets/css/ds.css');
$router = (string) file_get_contents($root . '/assets/js/05-router.js');
$i18n   = (string) file_get_contents($root . '/assets/js/04-i18n.js');

/* ---- 1. the booking panel, then the two other doors ------------------ */
$home = preg_match('~<section class="view active" id="view-home">(.*)~s', $tpl, $hm) ? $hm[1] : '';
$firstBlock = preg_match('~^\s*(?:<!--.*?-->\s*)*<div[^>]*\bid="([a-z-]+)"~s', $home, $fb) ? $fb[1] : '';
check('the booking panel is the first block of the home view', $firstBlock === 'search-anchor', $firstBlock);
check('the panel is the search form with a Find Bus button',
    str_contains($tpl, 'id="searchForm"') && (bool) preg_match('~id="searchBtn"[^>]*>.*?data-i18n="sSearch"~s', $tpl));
check('the page has exactly one <h1>, and it titles the booking panel',
    preg_match_all('~<h1[\s>]~i', $tpl) === 1 && str_contains($tpl, '<h1 class="sp-title" id="spTitle"'));
check('passengers default to 1 on the panel', (bool) preg_match('~<output id="paxCount"[^>]*>1</output>~', $tpl));

check('exactly one #entryCards section', substr_count($tpl, 'id="entryCards"') === 1);
$section = preg_match('~<section[^>]*id="entryCards"[^>]*>(.*?)</section>~s', $tpl, $m) ? $m[1] : '';
check('the section has a body', $section !== '');

preg_match_all('~<(a|button)\b([^>]*\bclass="[^"]*\bec-card\b[^"]*"[^>]*)>~', $section, $cards);
check('two other ways to book, no more', count($cards[2]) === 2, count($cards[2]) . ' found');

$want = [
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

$posSearch = strpos($tpl, 'id="search-anchor"');
$posCards  = strpos($tpl, 'id="entryCards"');
$posQuick  = strpos($tpl, 'id="quick-ticket"');
check('DOM order: booking panel, then the other doors, then QuickBot',
    $posCards !== false && $posQuick !== false && $posSearch !== false && $posSearch < $posCards && $posCards < $posQuick);

foreach (['search-anchor', 'quick-ticket', 'quickTicket', 'waSheet', 'waBookLink', 'heroQtCta'] as $id) {
    check("#{$id} still exists", substr_count($tpl, 'id="' . $id . '"') === 1);
}


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

/* ---- 3. order on every width ------------------------------------------- */
check('the home view is a column only while it is the active view',
    str_contains($css, '#view-home.active { display: flex !important; flex-direction: column; }')
    && !preg_match('~(^|[\s,}])#view-home\s*\{[^}]*display~', $css));
check('every other block defaults behind the booking flow', str_contains($css, '#view-home > * { order: 10 !important; }'));
check('the booking panel leads (order 0)', str_contains($css, '#view-home > .search-wrap { order: 0 !important; }'));
check('the other doors (2) and QuickBot (3) follow it',
    str_contains($css, '#view-home > #entryCards { order: 2 !important; }') && str_contains($css, '#view-home > #quick-ticket { order: 3 !important; }'));
check('the cards keep a 44px+ touch target', (bool) preg_match('~\.entry-cards \.ec-grid \.ec-card \{[^}]*min-height: (\d+)px~', $css, $mh) && (int) $mh[1] >= 44);

/* ---- 4. colour + contrast (design-system tokens) ----------------------- */
function lum(string $hex): float
{
    $c = array_map(static fn ($h) => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
    $c = array_map(static fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
function ratio(string $a, string $b): float { $x = lum($a); $y = lum($b); return (max($x, $y) + 0.05) / (min($x, $y) + 0.05); }
$light = preg_match('~:root,:root\[data-theme="light"\]\{([^}]*)\}~', $ds, $lm) ? $lm[1] : '';
$dark  = preg_match('~:root\[data-theme="dark"\]\{([^}]*)\}~', $ds, $dm) ? $dm[1] : '';
$tok = static fn (string $block, string $name): string => preg_match('~--' . preg_quote($name, '~') . ':(#[0-9A-Fa-f]{6})~', $block, $t) ? $t[1] : '';
check('ds.css carries both themes', $light !== '' && $dark !== '');
check('the cards are painted from tokens (surface + ink + royal)',
    str_contains($css, 'background: var(--surface) !important;') && str_contains($css, '.entry-cards .ec-card b { font: 700 14px/18px var(--font-sans) !important; color: var(--ink) !important; }'));
foreach (['light' => $light, 'dark' => $dark] as $theme => $block) {
    $ink = $tok($block, 'ink'); $surface = $tok($block, 'surface'); $muted = $tok($block, 'muted');
    check("{$theme}: card title (ink on surface) >= 4.5:1", $ink !== '' && $surface !== '' && ratio($ink, $surface) >= 4.5, $ink !== '' && $surface !== '' ? sprintf('%.2f:1', ratio($ink, $surface)) : 'token missing');
    check("{$theme}: card caption (muted on surface) >= 4.5:1", $muted !== '' && ratio($muted, $surface) >= 4.5, $muted !== '' ? sprintf('%.2f:1', ratio($muted, $surface)) : 'token missing');
}
$ringLight = $tok($light, 'royal');
check('the light-theme focus ring (royal) is >= 3:1 on the page background', $ringLight !== '' && ratio($ringLight, $tok($light, 'page')) >= 3.0, $ringLight);


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
$cardKeys = ['ecTitleAlt', 'ecQuick', 'ecQuickSub', 'ecWa', 'ecWaSub'];
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
