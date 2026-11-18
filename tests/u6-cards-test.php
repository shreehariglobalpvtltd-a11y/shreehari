<?php
/**
 * =====================================================================
 *  u6-cards-test.php — the three ID / visiting card designs (CD-1) and
 *  the Nepal-first card (NP-4), 19 Nov 2026.
 *
 *    - every design × every side renders at the right size (PNG + SVG)
 *    - design switches: ap_id_front_style / ap_vc_style / ?variant=,
 *      'portrait' / 'theme' still give the old cards
 *    - Nepal member: Nepal company, PAN line (never a bare "CIN "),
 *      Pushpalal Chowk, Nepal flag first, route line; switch off = India
 *    - no photo / no blood group / no branch: nothing printed for them
 *    - no text runs past its box or off the card
 *    - visiting card back QR: web (default) / webref / wa
 *    - A4 sheets (4 ID / 10 VC, 2 pages), the 4-face PDF with a design
 *    - the render cache: same bytes again, a change makes a new file
 *    - signed links for sheets; the API over HTTP (when the local server runs)
 *
 *  Settings are changed IN MEMORY only (the test DB is shared); the only
 *  files it writes (sample photo, cache files) are removed again.
 *
 *    php tests/u6-cards-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/agentpro-card.php';
require_once INCLUDE_PATH . '/carddesign.php';

set_exception_handler(static function (Throwable $e): void {
    echo "\n  UNCAUGHT " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\nFAILED: 1 (uncaught)\n";
    exit(1);
});
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }

/** Settings in memory only (the shared test DB is never written). */
function setS(array $kv): void {
    Settings::get('x');
    $r = new ReflectionProperty(Settings::class, 'cache');
    $c = $r->getValue();
    foreach ($kv as $k => $v) {
        if ($v === null) { unset($c[$k]); continue; }
        $c[$k] = ['value' => (string) $v, 'type' => 'string', 'public' => false];
    }
    $r->setValue(null, $c);
}
/** @return list<string> every text drawn */
function texts(ApScene $s): array {
    return array_values(array_map(static fn(array $o): string => (string) $o['text'], array_filter($s->ops, static fn(array $o): bool => $o['t'] === 'text')));
}
function hasText(ApScene $s, string $needle): bool {
    foreach (texts($s) as $t) { if (str_contains($t, $needle)) { return true; } }
    return false;
}
function pngSize(string $bin): array {
    $i = @getimagesizefromstring($bin);
    return $i === false ? [0, 0] : [$i[0], $i[1]];
}

/* ---- fixtures ---- */
$photoRel = 'agents/zz-u6-test-photo.jpg';
$photoAbs = UPLOAD_PATH . '/' . $photoRel;
@mkdir(dirname($photoAbs), 0775, true);
$im = imagecreatetruecolor(300, 380);
imagefill($im, 0, 0, imagecolorallocate($im, 220, 228, 240));
imagefilledellipse($im, 150, 150, 120, 150, imagecolorallocate($im, 200, 160, 120));
imagejpeg($im, $photoAbs, 85);

setS(['nepal_company' => 'Shree Hari Global Pvt Ltd Nepal', 'nepal_office' => 'Pushpalal Chowk, Nepalgunj',
      'nepal_office_ne' => 'पुष्पलाल चोक, नेपालगञ्ज', 'nepal_phone' => '+977 98662 01375 / 98257 88450',
      'nepal_whatsapp' => '9779866201375', 'nepal_pan' => '612454076', 'nepal_reg' => '',
      'ap_id_front_style' => null, 'ap_vc_style' => null, 'ap_vc_back_qr' => null, 'ap_card_nepal_first' => null, 'ap_card_cache' => null]);

$base = static fn(array $o): array => $o + [
    'id' => 0, 'name' => 'Dileep Sunar', 'contact' => '', 'email' => '', 'phone' => '', 'role' => 'agent', 'crewRole' => '', 'isAgent' => true,
    'isOrg' => false, 'idNo' => 'SHG-0027', 'designation' => 'Authorised Agent', 'branch' => 'Surat', 'address' => '', 'country' => 'IN',
    'countryCode' => '91', 'bloodGroup' => 'B+', 'emergencyName' => '', 'emergencyPhone' => '', 'photo' => '', 'cardToken' => 'zzu6tok',
    'cardIssued' => '2026-11-19', 'validUntil' => '2028-11-19', 'status' => 'valid', 'wa' => '919876543210', 'waDisplay' => '+91 98765 43210',
    'call' => '919876543210', 'callDisplay' => '+91 98765 43210', 'tagline' => '', 'waLink' => 'https://wa.me/919876543210', 'theme' => 'royal',
    'kycStatus' => 'verified', 'company' => Settings::company(), 'joined' => '2026-11-01',
];
$india   = $base(['id' => 0, 'photo' => $photoRel]);
$nepal   = $base(['name' => 'Ram Bahadur Thapa', 'idNo' => 'SHG-0092', 'country' => 'NP', 'countryCode' => '977', 'branch' => '',
                  'wa' => '9779812345678', 'waDisplay' => '+977 981-2345678', 'callDisplay' => '+977 981-2345678', 'photo' => $photoRel]);
$nophoto = $base(['name' => 'Sita Kumari Sharma', 'idNo' => 'SHG-S-0005', 'role' => 'counter', 'isAgent' => false, 'designation' => 'Booking Executive',
                  'branch' => '', 'bloodGroup' => '', 'kycStatus' => 'none']);
$longOrg = $base(['name' => 'Shree Ganesh Tours and Travels Agency Private Limited Ahmedabad', 'contact' => 'Mahesh Prajapati', 'isOrg' => true,
                  'branch' => 'Ahmedabad Kalupur Railway Station Road Branch']);

section('every design, every side');
foreach (ApCard::VARIANTS as $v) {
    foreach (ApCard::SIDES as $side) {
        $sc = ApCard::scene($india, $side, $v);
        [$w, $h] = pngSize(ApCard::png($sc, 2.0));
        $want = str_starts_with($side, 'id-') ? [1080, 1712] : [1778, 1016];
        check("$v $side PNG {$want[0]}x{$want[1]}", [$w, $h] === $want, "$w x $h");
        $svg = ApCard::svg($sc, false, '', true, false);
        check("$v $side SVG", str_starts_with($svg, '<svg') && str_contains($svg, 'APPoppins'));
    }
}
$k300 = 300 / 25.4 / 10;
check('ID at 300 dpi is CR80 638 x 1011', pngSize(ApCard::png(ApCard::scene($india, 'id-front', 'royal'), $k300)) === [638, 1011]);
check('visiting card at 300 dpi is 1050 x 600', pngSize(ApCard::png(ApCard::scene($india, 'vc-front', 'royal'), $k300)) === [1050, 600]);

section('big photo on top + photo on the visiting card');
foreach (ApCard::VARIANTS as $v) {
    $img = array_values(array_filter(ApCard::scene($india, 'id-front', $v)->ops, static fn(array $o): bool => $o['t'] === 'image'));
    check("$v ID front: photo ≥ 46 % of the card width, in the top half", $img !== [] && $img[0]['w'] >= 540 * 0.46 && $img[0]['y'] + $img[0]['h'] / 2 < 428,
        $img !== [] ? round($img[0]['w']) . ' wide at y ' . round($img[0]['y']) : 'no photo');
    $vcImg = array_filter(ApCard::scene($india, 'vc-front', $v)->ops, static fn(array $o): bool => $o['t'] === 'image' && $o['shape'] === 'circle');
    check("$v visiting card front has the round photo", count($vcImg) === 1);
}

section('design switches');
setS(['ap_id_front_style' => 'modern']);
check('ap_id_front_style=modern is the default design', ApCard::idStyle() === 'modern' && hasText(ApCard::scene($india, 'id-front'), 'AGENT'));
setS(['ap_id_front_style' => 'portrait']);
check('portrait = the 13 Nov card (no Poppins)', ApCard::idStyle() === 'portrait'
    && !in_array('popB', array_column(array_filter(ApCard::scene($india, 'id-front')->ops, static fn($o) => $o['t'] === 'text'), 'font'), true));
setS(['ap_id_front_style' => 'nonsense']);
check('an unknown style falls back to royal', ApCard::idStyle() === 'royal');
setS(['ap_id_front_style' => null]);
check('code default is royal', ApCard::idStyle() === 'royal' && ApCard::vcStyle() === 'royal');
check('?variant= wins over the setting', ApCard::idStyle('heritage') === 'heritage' && ApCard::vcStyle('modern') === 'modern');
setS(['ap_vc_style' => 'theme']);
check('ap_vc_style=theme = each member\'s old visiting card', ApCard::vcStyle() === 'theme' && hasText(ApCard::scene($india, 'vc-front'), 'WA:'));
setS(['ap_vc_style' => null]);
check('CardDesign maps studio presets to the new designs', CardDesign::apStyle('minimal') === 'modern' && CardDesign::apStyle('orange') === 'heritage'
    && CardDesign::apStyle('royal') === 'royal' && count(CardDesign::apStyles()) === 3);

section('Nepal-first (NP-4)');
$pn = ApCard::prepare($nepal);
check('+977 member: Nepal company', ($pn['company']['entity'] ?? '') === 'NP' && !empty($pn['nepalFirst']), (string) ($pn['company']['legal'] ?? ''));
check('registration line = PAN, never a bare CIN', ApCard::regLine($pn['company']) === 'PAN 612454076');
$wm = ApCard::proWordmark($pn['company']);
check('wordmark SHREE HARI GLOBAL / PVT LTD · NEPAL', $wm === ['SHREE HARI GLOBAL', 'PVT LTD · NEPAL'], implode(' / ', $wm));
foreach (ApCard::VARIANTS as $v) {
    $back = ApCard::scene($nepal, 'id-back', $v);
    $all = implode(' | ', texts($back));
    check("$v ID back: Nepal office address, no Mehsana, no 'CIN '", str_contains($all, 'Pushpalal Chowk') && !str_contains($all, 'Mehsana') && !preg_match('/CIN\s*$|CIN \|/', $all));
    check("$v ID back: Nepal number first", str_contains($all, 'NEPAL OFFICE') && str_contains($all, '+977 98662 01375'));
    $front = ApCard::scene($nepal, 'id-front', $v);
    $flags = [];
    foreach ($front->ops as $o) {
        if ($o['t'] === 'art' && in_array($o['rel'], [ApCard::FLAG_IN, ApCard::FLAG_NP], true)) { $flags[$o['rel']] = $o['x']; }
    }
    check("$v ID front: Nepal flag left of India's", isset($flags[ApCard::FLAG_NP], $flags[ApCard::FLAG_IN]) && $flags[ApCard::FLAG_NP] < $flags[ApCard::FLAG_IN]);
    $vc = ApCard::scene($nepal, 'vc-front', $v);
    check("$v VC front: Nepalgunj office + route line", hasText($vc, 'Pushpalal Chowk, Nepalgunj') && hasText($vc, 'Surat') && hasText($vc, 'नेपालगञ्ज'));
}
check('Modern Blue role word is एजेन्ट for a Nepal-first agent', hasText(ApCard::scene($nepal, 'id-front', 'modern'), 'एजेन्ट'));
$fi = [];
foreach (ApCard::scene($india, 'id-front', 'royal')->ops as $o) {
    if ($o['t'] === 'art' && in_array($o['rel'], [ApCard::FLAG_IN, ApCard::FLAG_NP], true)) { $fi[$o['rel']] = $o['x']; }
}
check('India member: India flag first', isset($fi[ApCard::FLAG_IN]) && $fi[ApCard::FLAG_IN] < $fi[ApCard::FLAG_NP]);
setS(['ap_card_nepal_first' => '0']);
$off = ApCard::prepare($nepal);
check('ap_card_nepal_first=0: India company, India flag first', ($off['company']['entity'] ?? 'IN') === 'IN' && empty($off['nepalFirst']));
setS(['ap_card_nepal_first' => null, 'nepal_company' => '']);
$blank = ApCard::prepare($nepal);
check('Nepal company not filled in: India letterhead kept (nothing invented), flags still Nepal-first',
    ($blank['company']['entity'] ?? 'IN') === 'IN' && !empty($blank['nepalFirst']));
setS(['nepal_company' => 'Shree Hari Global Pvt Ltd Nepal']);
check('member() decision is respected (flagFirst=IN)', empty(ApCard::prepare($nepal + ['flagFirst' => 'IN'])['nepalFirst']));

section('nothing invented');
foreach (ApCard::VARIANTS as $v) {
    $f = ApCard::scene($nophoto, 'id-front', $v);
    $all = implode(' | ', texts($f));
    check("$v no photo: initials, no picture", !in_array('image', array_column($f->ops, 't'), true) && hasText($f, 'SK'));
    check("$v no blood group / branch: not printed", !str_contains($all, 'BLOOD') && !str_contains($all, 'BRANCH') && !str_contains($all, '—'));
    $b = implode(' | ', texts(ApCard::scene($nophoto, 'id-back', $v)));
    check("$v back: no blood row, no emergency row", !str_contains($b, 'BLOOD') && !str_contains($b, 'SOS'));
    $vc = ApCard::scene($nophoto, 'vc-front', $v);
    check("$v VC without a photo: no photo circle", !in_array('image', array_column($vc->ops, 't'), true));
}

section('text fits');
foreach ([$india, $nepal, $nophoto, $longOrg] as $mi => $mm) {
    foreach (ApCard::VARIANTS as $v) {
        foreach (ApCard::SIDES as $side) {
            $s = ApCard::scene($mm, $side, $v);
            $bad = [];
            foreach ($s->ops as $o) {
                if ($o['t'] !== 'text') { continue; }
                $x0 = match ($o['anchor']) { 'middle' => $o['x'] - $o['w'] / 2, 'end' => $o['x'] - $o['w'], default => $o['x'] };
                if (($o['max'] > 0 && $o['w'] > $o['max'] + 1) || $x0 < 10 || $x0 + $o['w'] > $s->w - 10 || $o['y'] > $s->h - 4) {
                    $bad[] = mb_substr((string) $o['text'], 0, 24) . '@' . round($x0) . '+' . round($o['w']);
                }
            }
            check("member $mi $v $side: every text inside the card", $bad === [], implode(', ', array_slice($bad, 0, 3)));
        }
    }
}

section('mixed Nepali / English line');
$s = new ApScene(540, 100);
$w = $s->mixed(270, 50, 'नेपालगञ्ज/Rupaidiha → Surat', 'popM', 14, '#000000', 'middle', 300);
$fonts = array_column(array_filter($s->ops, static fn($o) => $o['t'] === 'text'), 'font');
check('Devanagari run in Noto, Latin in Poppins, arrow drawn', in_array('deva', $fonts, true) && in_array('popM', $fonts, true)
    && in_array('shape', array_column($s->ops, 't'), true) && $w > 0 && $w <= 300);

section('visiting card back QR');
check('default = the website', ApCard::vcBackQrMode() === 'web' && ApCard::vcBackQrTexts($india) === ['https://shreehariglobal.in']);
setS(['ap_vc_back_qr' => 'webref']);
check('webref = website + agent code', ApCard::vcBackQrTexts($india)[0] === 'https://shreehariglobal.in/?ref=SHG-0027');
setS(['ap_vc_back_qr' => 'wa']);
check('wa = the member\'s WhatsApp', ApCard::vcBackQrTexts($india)[0] === 'https://wa.me/919876543210');
setS(['ap_vc_back_qr' => null]);
[$mx] = ApCard::safeQr(ApCard::vcBackQrTexts($india), QrCode::ECC_M, 7);
check('web QR is small (version ≤ 3)', count($mx) <= 17 + 4 * 3, (string) count($mx));
check('VC back prints SCAN TO BOOK + phone', hasText(ApCard::scene($india, 'vc-back', 'royal'), 'SCAN TO BOOK')
    && hasText(ApCard::scene($india, 'vc-back', 'royal'), 'INDIA - NEPAL BUS SERVICE'));

section('PDFs');
$india['id'] = 0;   // no cache for id 0
$pages = static fn(string $pdf): int => preg_match_all('#/Type\s*/Page[^s]#', $pdf);
$p1 = ApCard::sheetPdf($india, 'id', 'heritage');
check('A4 ID sheet: PDF, 2 pages', str_starts_with($p1, '%PDF') && $pages($p1) === 2, (string) $pages($p1));
$p2 = ApCard::sheetPdf($nepal, 'vc', 'modern');
check('A4 visiting card sheet: PDF, 2 pages', str_starts_with($p2, '%PDF') && $pages($p2) === 2);
$p3 = ApCard::pdf($nophoto, 'royal');
check('4-face PDF with a design', str_starts_with($p3, '%PDF') && $pages($p3) === 1);

$html = ApCard::printHtml([$india, $nepal], 'all', 'u6', 'heritage');
check('print sheet (HTML) in a new design: Poppins, 4 pages, design picker', str_contains($html, 'APPoppins') && substr_count($html, 'class="sheet"') === 4
    && str_contains($html, 'value="modern"') && str_contains($html, 'Gold (ivory) (old)'));
$old = ApCard::printHtml([$india], 'vc', 'u6', 'gold');
check('print sheet with an old theme still draws the old card', str_contains($old, 'WA:'));

section('render cache');
$cm = $india;
$cm['id'] = 999991;
$dir = (defined('TICKET_PATH') ? TICKET_PATH : sys_get_temp_dir()) . '/apcard';
foreach (glob($dir . '/m999991-*') ?: [] as $f) { @unlink($f); }
$a = ApCard::cachedPng($cm, 'id-front', 'royal', 1.0);
$files = glob($dir . '/m999991-id-front-royal-*.png') ?: [];
check('first draw is kept', count($files) === 1 && str_starts_with($a, "\x89PNG"));
$t0 = microtime(true);
$b = ApCard::cachedPng($cm, 'id-front', 'royal', 1.0);
check('second call = same bytes from the cache', $a === $b, round((microtime(true) - $t0) * 1000) . ' ms');
$cm['name'] = 'Dileep K Sunar';
ApCard::cachedPng($cm, 'id-front', 'royal', 1.0);
$files2 = glob($dir . '/m999991-id-front-royal-*.png') ?: [];
check('a changed name = a new picture, the old one removed', count($files2) === 1 && $files2 !== $files);
setS(['ap_card_cache' => '0']);
ApCard::cachedPng($cm + ['bloodGroup' => 'A+'], 'id-back', 'royal', 1.0);
check('ap_card_cache=0 keeps nothing', (glob($dir . '/m999991-id-back-*') ?: []) === []);
setS(['ap_card_cache' => null]);
setS(['ap_card_prerender_print' => '0']);
check('prerender draws 3 designs × 4 sides', ApCard::prerender($cm, 0.5) === 12);
check('prerender again with nothing changed = free (marker)', ApCard::prerender($cm, 0.5) === 0
    && count(glob($dir . '/m999991-warm-*.ok') ?: []) === 1);
check('prerender force=true draws again', ApCard::prerender($cm, 0.5, true) === 12);
$cm2 = $cm; $cm2['bloodGroup'] = 'O-';
check('changed member data = warmed again', ApCard::prerender($cm2, 0.5) === 12 && count(glob($dir . '/m999991-warm-*.ok') ?: []) === 1);
setS(['ap_card_prerender_print' => null]);
foreach (glob($dir . '/m999991-*') ?: [] as $f) { @unlink($f); }
check('prerender also warms the 300 dpi print faces of the default design (+4)', ApCard::prerender($cm, 0.5) === 16
    && count(glob($dir . '/m999991-*-royal-s' . (int) round(300 / 25.4 / 10 * 1000) . '-*.png') ?: []) === 4);
foreach (glob($dir . '/m999991-*') ?: [] as $f) { @unlink($f); }

section('signed links');
$u = ApCard::signedUrl(1, 'sheet-id', 600, 'heritage');
parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
check('sheet link carries sheet=a4, kind=id, variant', ($q['sheet'] ?? '') === 'a4' && ($q['kind'] ?? '') === 'id' && ($q['variant'] ?? '') === 'heritage');
check('sheet link is signed for sheet-id', ApCard::checkSig(1, 'sheet-id', (int) $q['e'], (string) $q['k']) && !ApCard::checkSig(1, 'pdf', (int) $q['e'], (string) $q['k']));
$threw = false;
try { ApCard::signedUrl(1, 'nope'); } catch (RuntimeException $e) { $threw = true; }
check('unknown side refused', $threw);

section('a real member (read only)');
$real = AgentPro::member(1, false);
if ($real === null) {
    check('superadmin #1 exists in the test DB', false);
} else {
    foreach (ApCard::VARIANTS as $v) {
        $ok = true;
        foreach (ApCard::SIDES as $side) {
            $ok = $ok && str_starts_with(ApCard::png(ApCard::scene($real, $side, $v), 0.6), "\x89PNG");
        }
        check("member() #1 renders in $v", $ok);
    }
}

section('the API over HTTP (local server :8963)');
$base8963 = 'http://localhost:8963';
$ping = @file_get_contents($base8963 . '/api/agentpro-card.php?m=1&s=id-front', false, stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]));
if ($ping === false) {
    echo "  SKIP  local server not running\n";
} else {
    $get = static function (string $url) use ($base8963): array {
        $url = preg_replace('#^https?://[^/]+#', $base8963, $url) ?? $url;
        $ctx = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true]]);
        $body = (string) @file_get_contents($url, false, $ctx);
        $hdr = implode("\n", $http_response_header ?? []);
        return [$hdr, $body];
    };
    [$h1, $b1] = $get(ApCard::signedUrl(1, 'id-front', 600, 'modern'));
    check('signed PNG (modern)', str_contains($h1, 'image/png') && str_starts_with($b1, "\x89PNG"), strtok($h1, "\n") ?: '');
    [$h2, $b2] = $get(ApCard::signedUrl(1, 'sheet-vc', 600, 'royal'));
    check('signed A4 visiting card sheet', str_contains($h2, 'application/pdf') && str_starts_with($b2, '%PDF'), strtok($h2, "\n") ?: '');
    [$h3] = $get($base8963 . '/api/agentpro-card.php?member=1&kind=id&sheet=a4&format=pdf');
    check('unsigned, signed-out sheet is refused', str_contains($h3, ' 401'), strtok($h3, "\n") ?: '');
    $tamper = ApCard::signedUrl(1, 'id-front', 600);
    [$h4] = $get(str_replace('&s=id-front', '&sheet=a4&kind=id', $tamper));
    check('a PNG signature does not open the sheet', str_contains($h4, ' 403'), strtok($h4, "\n") ?: '');
}

section('fix pass (19 Nov): labels, signatory, rollback switch, print sheet, PDF size, SVG crop');
$allOps = static fn(ApScene $s): string => implode(' | ', texts($s));
// Nepal Branch only under the Nepal letterhead
setS(['nepal_company' => '']);
$vcBlank = $allOps(ApCard::scene($nepal, 'vc-front', 'royal'));
check('+977 member, India letterhead (nepal_company blank): no "Nepal Branch" over the India address',
    !str_contains($vcBlank, 'Nepal Branch') && str_contains($vcBlank, 'Head Office'), mb_substr($vcBlank, 0, 0));
setS(['nepal_company' => 'Shree Hari Global Pvt Ltd Nepal']);
$vcNp = $allOps(ApCard::scene($nepal, 'vc-front', 'royal'));
check('+977 member under the Nepal company: "Nepal Branch" + Pushpalal Chowk', str_contains($vcNp, 'Nepal Branch') && str_contains($vcNp, 'Pushpalal Chowk'));
// Nepali label · Roman value on every design
foreach (ApCard::VARIANTS as $v) {
    $f = $allOps(ApCard::scene($india, 'id-front', $v));
    $b = $allOps(ApCard::scene($india, 'id-back', $v));
    check("$v: fact labels bilingual (मोबाइल · MOBILE), phone labels bilingual (कार्यालय)", str_contains($f, 'मोबाइल') && str_contains($f, 'MOBILE') && str_contains($b, 'कार्यालय'));
}
setS(['ap_card_bilingual' => '0']);
check('ap_card_bilingual=0: Royal back to English-only fact labels', !str_contains($allOps(ApCard::scene($india, 'id-front', 'royal')), 'मोबाइल')
    && str_contains($allOps(ApCard::scene($india, 'id-front', 'heritage')), 'मोबाइल'));
setS(['ap_card_bilingual' => null]);
// Nepal signatory
setS(['ap_card_signature_np' => 'ZZTEST Nepal Signer']);
check('ap_card_signature_np printed on a Nepal-company back only', hasText(ApCard::scene($nepal, 'id-back', 'royal'), 'ZZTEST Nepal Signer')
    && !hasText(ApCard::scene($india, 'id-back', 'royal'), 'ZZTEST Nepal Signer'));
setS(['ap_card_signature_np' => null]);
// one-switch rollback
setS(['ap_card_classic' => '1']);
check('ap_card_classic=1: portrait ID + own theme + WhatsApp QR in one switch', ApCard::idStyle() === 'portrait' && ApCard::vcStyle() === 'theme'
    && ApCard::vcBackQrMode() === 'wa' && hasText(ApCard::scene($india, 'vc-front'), 'WA:'));
check('ap_card_classic=1: an asked design still draws', ApCard::idStyle('royal') === 'royal' && ApCard::vcStyle('modern') === 'modern');
setS(['ap_card_classic' => null]);
// print sheet picker
$h0 = ApCard::printHtml([$india], 'id', 'u6', '');
check('print sheet: "" is labelled as the office default design (not "Each own design")', str_contains($h0, 'Office default design (Royal Gold)')
    && !str_contains($h0, '>Each own design<') && str_contains($h0, 'value="own"') && str_contains($h0, 'value="royal-old"'));
check('print sheet tpl=own = each member\'s own old visiting card', str_contains(ApCard::printHtml([$india], 'vc', 'u6', 'own'), 'WA:'));
check('print sheet tpl=royal-old = the old Royal theme (no Poppins name)', !str_contains(ApCard::printHtml([$india], 'id', 'u6', 'royal-old'), 'font-family="APPoppins,sans-serif" font-size="33"'));
// the visiting-card sheet embeds each face once
$imgs = preg_match_all('#/Subtype /Image /Width \d+ /Height \d+ /ColorSpace /Device(RGB|CMYK)#', $p2);
check('A4 visiting card sheet embeds each face ONCE (2 pictures, not 20)', $imgs === 2 && strlen($p2) < 900000, $imgs . ' pictures, ' . round(strlen($p2) / 1024) . ' KB');
$imgs1 = preg_match_all('#/Subtype /Image /Width \d+ /Height \d+ /ColorSpace /Device(RGB|CMYK)#', $p1);
check('A4 ID sheet embeds each face ONCE', $imgs1 === 2, (string) $imgs1);
// SVG photo crop = the PNG crop (30 % from the top)
$tall = 'agents/zz-u6-test-tall.jpg';
$im2 = imagecreatetruecolor(300, 600);
imagejpeg($im2, UPLOAD_PATH . '/' . $tall, 80);
$svgT = ApCard::svg(ApCard::scene($base(['photo' => $tall]), 'id-front', 'royal'), false, '', true, false);
$imgOp = array_values(array_filter(ApCard::scene($base(['photo' => $tall]), 'id-front', 'royal')->ops, static fn($o) => $o['t'] === 'image'))[0] ?? null;
$okCrop = false;
if ($imgOp !== null && preg_match('#<image x="([-\d.]+)" y="([-\d.]+)" width="([\d.]+)" height="([\d.]+)" preserveAspectRatio="none"#', $svgT, $mm)) {
    $sc = max($imgOp['w'] / 300, $imgOp['h'] / 600);
    $okCrop = abs((float) $mm[2] - ($imgOp['y'] - (600 * $sc - $imgOp['h']) * 0.3)) < 0.6;
}
check('SVG crops a tall photo 30 % from the top, like the PNG', $okCrop);
@unlink(UPLOAD_PATH . '/' . $tall);

/* ---- clean up ---- */
@unlink($photoAbs);
foreach (glob($dir . '/m999991-*') ?: [] as $f) { @unlink($f); }

echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
