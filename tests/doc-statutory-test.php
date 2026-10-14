<?php
/**
 * doc-statutory-test.php — the TRUST package (10 Oct 2026).
 *
 * Owner: "government-grade documentation, ticket dekhi sabai kura 100%
 * genuine" + "Gmail YouTube / Facebook sanga connect xa, mention gardeu;
 * shreehariglobal.in ko email ni dekhaideu; agreement ko data live hunu
 * paryo".
 *
 *   A. Ticket::statutoryParts() / statutoryLine(): issue time in IST (+ NPT
 *      on a Nepal company's ticket), "computer-generated", the verify and
 *      refund pointers - and no pointers when no website is set.
 *   B. The PNG ticket (a duplicate render, temp file): still 1080 wide and
 *      taller by the two statutory rows; the footer draws the legal name,
 *      CIN, GSTIN only when set, the address, the ID-at-the-border line and
 *      both statutory rows (source pins: GD keeps no text log).
 *   C. The PDF ticket (a duplicate render, temp file): its decompressed text
 *      carries the PNR, CIN, legal name, IST issue line, computer-generated,
 *      refund pointer, registered office and - only when the office has one
 *      - the GSTIN; the live-status QR still opens verify-ticket.php.
 *   D. Chalani / challan PNG + verify-ticket.php: the statutory lines in
 *      their footers (source pins) and the layout versions moved.
 *   E. SiteFacts: brand.email2 / contactEmail / social (https only),
 *      {{f:html:social}} accessible and '' when nothing is set, JSON-LD
 *      sameAs wiring, the settings rows + Admin help text.
 *   F. Agreements: the built pages carry <span data-fact> with the same keys
 *      the API serves (AgreementText::FACT_KEYS), agreement-live.js fills
 *      them, the agreement PDF letterhead reads Company details.
 *
 * Writes: two temporary files under the system temp folder (deleted).
 * Refuses to run anywhere but a test database.
 *
 *   php -c .claude/php-dev.ini tests/doc-statutory-test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ticket.php';
require_once __DIR__ . '/../includes/pdf.php';
require_once __DIR__ . '/../includes/qr.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/chalanipng.php';
require_once __DIR__ . '/../includes/challanpng.php';
require_once __DIR__ . '/../includes/sitefacts.php';
require_once __DIR__ . '/../includes/agreementtext.php';

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

$dbName = defined('DB_NAME') ? (string) DB_NAME : '';
if (stripos($dbName, 'test') === false) {
    echo "  refusing to run: database '{$dbName}' is not a test database\n\n";
    exit(1);
}
$root = dirname(__DIR__);
$src  = static fn(string $rel): string => (string) @file_get_contents($root . '/' . $rel);

/** Every FlateDecode content stream of a PDF, inflated and joined (the text lives there). */
function pdf_text(string $pdf): string
{
    $out = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
        foreach ($m[1] as $raw) {
            $inflated = @gzuncompress($raw);
            $out .= ($inflated !== false ? $inflated : $raw) . "\n";
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ */
echo "\n=== A. Ticket::statutoryParts() ===\n\n";

$india = ['web' => 'shreehariglobal.in', 'tz' => 'IST'];
$nepal = ['web' => 'shreehariglobal.in', 'tz' => 'NPT'];
$tk    = ['issued_at' => '2026-10-10 18:05:00'];
[$a1, $a2] = Ticket::statutoryParts([], $tk, $india);
check('issue time printed in IST', str_contains($a1, 'Issued 10 Oct 2026, 6:05 PM IST'), $a1);
check('no NPT on an India company ticket', !str_contains($a1, 'NPT'));
check('"computer-generated" stated', str_contains($a1, 'Computer-generated e-ticket'));
check('verify pointer', str_contains($a2, 'Verify: shreehariglobal.in/verify-ticket.php'), $a2);
check('refund-policy pointer', str_contains($a2, 'Refund rules: shreehariglobal.in/refund-policy'));
[$n1] = Ticket::statutoryParts([], $tk, $nepal);
check('a Nepal company ticket adds the Nepal clock (IST + 15 min)', str_contains($n1, '6:05 PM IST (6:20 PM NPT)'), $n1);
[$b1] = Ticket::statutoryParts(['created_at' => '2026-10-11 09:00:00'], [], $india);
check('no issued_at: the booking time stands in', str_contains($b1, '11 Oct 2026, 9:00 AM IST'), $b1);
[, $c2] = Ticket::statutoryParts([], $tk, ['web' => '', 'tz' => 'IST']);
check('no website set: no pointer row (nothing invented)', $c2 === '');
check('statutoryLine() = both halves joined', Ticket::statutoryLine([], $tk, $india) === $a1 . '  ·  ' . $a2);
check('the IST/NPT rule matches zonedTime()', str_contains(Ticket::zonedTime('18:05', $nepal), '6:20 PM NPT'));

/* ------------------------------------------------------------------ */
echo "\n=== B. PNG ticket (duplicate render) ===\n\n";

$b = Database::fetch(
    "SELECT b.id, b.pnr
       FROM bookings b
       JOIN tickets t ON t.booking_id = b.id
       JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
       JOIN schedules s ON s.id = l.schedule_id
       JOIN routes r ON r.id = s.route_id
      WHERE b.status = 'confirmed'
      ORDER BY b.id DESC LIMIT 1"
);
$gstin = trim(Settings::getString('company_gstin', ''));
$co    = Settings::company();
if ($b === null) {
    echo "  SKIP  no confirmed booking with a renderable ticket (B and C)\n";
} else {
    $png = Ticket::pngDuplicatePath((int) $b['id']);
    $sz  = is_file($png) ? getimagesize($png) : false;
    check('PNG renders, 1080 wide', $sz !== false && $sz[0] === 1080, $sz ? $sz[0] . 'x' . $sz[1] : 'none');
    $rc = new ReflectionClass(Ticket::class);
    check('the canvas grew for two statutory rows (PNG_STAT_H >= 42)', (int) $rc->getConstant('PNG_STAT_H') >= 42);
    check('PNG height >= the floor + the statutory rows', $sz !== false && $sz[1] >= 1648 + (int) $rc->getConstant('PNG_STAT_H'));
    @unlink($png);

    /* ---------------------------------------------------------------- */
    echo "\n=== C. PDF ticket (duplicate render) ===\n\n";
    $pdfPath = Ticket::pdfDuplicatePath((int) $b['id']);
    $raw  = is_file($pdfPath) ? (string) file_get_contents($pdfPath) : '';
    @unlink($pdfPath);
    $text = pdf_text($raw);
    check('PDF renders', str_starts_with($raw, '%PDF'));
    check('PNR printed', str_contains($text, (string) $b['pnr']));
    check('CIN printed', $co['cin'] === '' || str_contains($text, $co['cin']), $co['cin']);
    $latin = new ReflectionMethod(Ticket::class, 'latin');
    check('legal company name printed', str_contains($text, (string) $latin->invoke(null, $co['legal'])), $co['legal']);
    check('issue time in IST printed', preg_match('/Issued \d{2} [A-Z][a-z]{2} \d{4}, \d{1,2}:\d{2} [AP]M IST/', $text) === 1);
    check('"computer-generated" printed', str_contains($text, 'Computer-generated e-ticket, no signature needed'));
    check('refund-policy pointer printed', str_contains($text, '/refund-policy'));
    check('registered office printed', trim($co['address']) === '' || str_contains($text, 'Regd. office: '));
    check($gstin === '' ? 'no GSTIN while the office has none' : 'GSTIN printed (set on this database)',
        $gstin === '' ? !str_contains($text, 'GSTIN') : str_contains($text, 'GSTIN ' . $gstin));
    check('the passenger ID line is in the PDF notes (source)', str_contains($src('includes/ticket.php'), "'मान्य फोटो परिचयपत्र साथमा राख्नुहोस्।'"));
}

$t = $src('includes/ticket.php');
check('PNG footer: the legal name beside the CIN', str_contains($t, "self::display(trim((string) (\$co['legal'] ?? '')) !== '' ? (string) \$co['legal'] : (string) \$co['name'])"));
check('PNG footer: GSTIN only when set', str_contains($t, "((string) (\$co['gstin'] ?? '') !== '' ? '  ·  GSTIN '"));
check('PNG footer: the address line', str_contains($t, "\$co['desk']['address'] ?? '') !== '' ? \$co['desk']['address'] : \$co['address']"));
check('PNG footer: the border ID line', str_contains($t, 'सरकारी परिचयपत्र अनिवार्य'));
check('PNG footer: both statutory rows drawn', str_contains($t, 'foreach (self::statutoryParts($booking, $ticket, $co) as $si => $n4)'));
check('PNG: the verify QR still carries the keyed URL', str_contains($t, "appUrl('verify-ticket.php')"));
check('PDF: the statutory row + GSTIN only when set', str_contains($t, "\$statPdf = self::statutoryLine(\$booking, \$ticket, \$coId)")
    && str_contains($t, "((string) (\$coId['gstin'] ?? '') !== '' ? '  ·  GSTIN '"));
check('PDF: the closing line names the legal company + office', str_contains($t, "'Regd. office'"));

/* ------------------------------------------------------------------ */
echo "\n=== D. Chalani / challan / verify page ===\n\n";

$ch = $src('includes/chalanipng.php');
check('chalani: legal name + address + CIN in the header', str_contains($ch, "\$l1 = \$roman(\$co['legal']") && str_contains($ch, "\$l2 = \$roman(\$co['address'])") && str_contains($ch, "'CIN ' . \$co['cin']"));
check('chalani: "Computer-generated" on the official-document line', str_contains($ch, 'Computer-generated; valid with authorised signature / stamp'));
check('chalani: GSTIN only when set', str_contains($ch, "(\$gstin !== '' ? ' | GSTIN ' . \$gstin : '')"));
check('chalani: the Generated stamp says IST', str_contains($ch, "' IST  ·  ' . \$chalaniNo"));
check('ChalaniPng::LAYOUT_VERSION = 11', ChalaniPng::LAYOUT_VERSION === 11);
$cl = $src('includes/challanpng.php');
check('challan: computer-generated + IST stamp', str_contains($cl, "'Computer-generated by SHG AI Brain") && str_contains($cl, "' IST   ·   '"));
check('challan: legal name, CIN and registered office in the foot band', str_contains($cl, "'  ·  CIN ' . \$cinFt") && str_contains($cl, "'  ·  Regd. office: ' . \$addrFt"));
check('ChallanPng::LAYOUT_VERSION = 9', ChallanPng::LAYOUT_VERSION === 9);
$vt = $src('verify-ticket.php');
check('verify page: legal name, CIN/PAN, GSTIN-when-set, address', str_contains($vt, "\$vLegal") && str_contains($vt, "\$vReg") && str_contains($vt, "\$vGst !== '' ? 'GSTIN ' . \$vGst : ''") && str_contains($vt, '$vAddr'));
check('verify page: computer-generated + refund pointer', str_contains($vt, 'Computer-generated verification page') && str_contains($vt, 'href="/refund-policy"'));
check('verify page: the check time is stamped IST', str_contains($vt, "' IST'"));
$tv = $src('ticket/view.php');
check('ticket view page: the issuing company (legal name, CIN/PAN, GSTIN-when-set, office)', str_contains($tv, '$issuerCo = $b !== null ? Ticket::companyOf($b)') && str_contains($tv, "'GSTIN ' . trim((string) \$issuerCo['gstin'])") && str_contains($tv, '$coAddr'));
check('ticket view page: IST issue line + verify link + refund pointer', str_contains($tv, 'Ticket::statutoryParts($b, $tkRow, $issuerCo)[0]') && str_contains($tv, "appUrl('verify-ticket.php')") && str_contains($tv, 'href="/refund-policy"'));
check('ticket view page: the photo-ID line stays', str_contains($tv, 'Carry a photo ID'));

/* ------------------------------------------------------------------ */
echo "\n=== E. SiteFacts: channels + domain mailbox ===\n\n";

check('https URL kept', SiteFacts::httpsUrlOrEmpty('https://www.youtube.com/@shreehariglobal') === 'https://www.youtube.com/@shreehariglobal');
foreach (['http://facebook.com/x', 'facebook.com/x', '@shg', 'javascript:alert(1)', 'https://exa mple.com', 'https://x.com/"><script>', 'https://localhost/x', ''] as $bad) {
    check('dropped: ' . ($bad === '' ? '(empty)' : $bad), SiteFacts::httpsUrlOrEmpty($bad) === '');
}
check('a mailbox is validated', SiteFacts::emailOrEmpty(' info@shreehariglobal.in ') === 'info@shreehariglobal.in' && SiteFacts::emailOrEmpty('info@') === '');
check('nothing set: {{f:html:social}} draws nothing', SiteFacts::socialHtml(false, []) === '' && SiteFacts::socialHtml(true, ['youtube' => 'http://bad']) === '');
$row = SiteFacts::socialHtml(true, ['youtube' => 'https://www.youtube.com/@shg', 'facebook' => 'https://www.facebook.com/shg']);
check('one accessible link per set network', substr_count($row, '<a ') === 2 && str_contains($row, 'aria-label="YouTube"') && str_contains($row, 'aria-label="Facebook"'));
check('links open safely in a new tab', substr_count($row, 'rel="noopener noreferrer me"') === 2 && substr_count($row, 'target="_blank"') === 2);
check('the inline glyph is decorative', str_contains($row, 'aria-hidden="true"'));
check('sprite symbols for all four networks', substr_count(SiteFacts::socialSymbols(), '<symbol id="i-') === 4);
$f = SiteFacts::all();
check('brand.email2 / contactEmail / social exist', array_key_exists('email2', $f['brand']) && array_key_exists('contactEmail', $f['brand']) && is_array($f['brand']['social'] ?? null));
check('contactEmail = the domain mailbox when set, else the Gmail', $f['brand']['contactEmail'] === ($f['brand']['email2'] !== '' ? $f['brand']['email2'] : $f['brand']['email']));
check('social keys = youtube / facebook / instagram / tiktok', array_keys($f['brand']['social']) === ['youtube', 'facebook', 'instagram', 'tiktok']);
check('ceo.facebook exists (https or empty)', array_key_exists('facebook', $f['ceo']) && ($f['ceo']['facebook'] === '' || str_starts_with($f['ceo']['facebook'], 'https://')));
$tok = SiteFacts::tokens();
foreach (['{{f:html:social}}', '{{f:html:socialSymbols}}', '{{f:html:ceoSocial}}', '{{f:html:emails}}', '{{f:brand.contactEmail}}'] as $k) {
    check('token ' . $k . ' served', array_key_exists($k, $tok));
}
check('{{f:html:emails}} links the contact mailbox', str_contains($tok['{{f:html:emails}}'], 'mailto:' . htmlspecialchars($f['brand']['contactEmail'], ENT_QUOTES, 'UTF-8')));
$tpl = $src('app.template.html');
check('the home footer no longer types a personal Facebook link', !str_contains($tpl, 'facebook.com/sherbahadur'));
check('the home footer draws the channels + the Director profile', str_contains($tpl, '{{f:html:social}}{{f:html:ceoSocial}}'));
check('the top bar mails the contact mailbox', str_contains($tpl, 'href="mailto:{{f:brand.contactEmail}}" data-fact-mail="brand.contactEmail"'));
check('the "Developed by" credit stays', stripos($tpl, 'Developed by') !== false);
check('index.php JSON-LD: sameAs from the channels, email = contactEmail', str_contains($src('index.php'), "\$shgOrg['sameAs'] = \$shgSame;") && str_contains($src('index.php'), "\$F['brand']['contactEmail']"));
check('content pages JSON-LD: sameAs + contactEmail', str_contains($src('includes/contentpages.php'), "\$node['sameAs'] = \$same;") && str_contains($src('includes/contentpages.php'), "['brand']['contactEmail']"));
check('content pages footer: registered office + mailboxes + channel row', str_contains($src('includes/publiclayout.php'), "\$w['regOffice']") && str_contains($src('includes/publiclayout.php'), 'SiteFacts::socialHtml(true)'));
check('hydrateFacts refreshes mailto: links', str_contains($src('assets/js/02-config.js'), "querySelectorAll('[data-fact-mail]')"));
$sql = $src('database/upgrade-2026-10-10-trust.sql');
foreach (['company_email_alt', 'company_youtube', 'company_facebook', 'company_instagram', 'company_tiktok', 'ceo_facebook'] as $k) {
    $row = Database::fetch('SELECT sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
    check("setting {$k}: in the migration, present, public, company group",
        str_contains($sql, "('{$k}',") && $row !== null && (int) $row['is_public'] === 1 && (string) $row['sgroup'] === 'company');
    check("setting {$k}: Admin help text", str_contains($src('admin/settings.php'), "'{$k}'"));
}
check('the migration is idempotent (INSERT IGNORE)', str_contains($sql, 'INSERT IGNORE INTO `settings`'));

/* ------------------------------------------------------------------ */
echo "\n=== F. Agreements fed by live facts ===\n\n";

$facts = AgreementText::facts();
check('API facts = exactly AgreementText::FACT_KEYS (blank ones left out)', array_diff(array_keys($facts), AgreementText::FACT_KEYS) === []);
$phones = array_filter(array_keys($facts), static fn(string $k): bool => str_contains($k, 'phone') || str_contains($k, 'tel') || str_contains($k, 'wa'));
check('the only number served is the published head-office phone', array_values($phones) === ['head.phone'] || $phones === []);
check('the Nepali address loses its own label (the cell has one)', !str_starts_with((string) ($facts['head.addressNe'] ?? ''), 'प्रधान कार्यालय'));
check('api/agreement-points.php serves "facts"', str_contains($src('api/agreement-points.php'), "\$payload['facts']"));
check('agreement-live.js fills [data-fact]', str_contains($src('assets/js/agreement-live.js'), "querySelectorAll('[data-fact]')") && str_contains($src('assets/js/agreement-live.js'), 'fillFacts(data.facts)'));
foreach (['agent', 'partner'] as $doc) {
    foreach (['en', 'ne', 'hi', 'gu'] as $lg) {
        $html = $src("agreements/{$doc}-agreement-{$lg}.html");
        preg_match_all('/data-fact="([a-zA-Z.]+)"/', $html, $m);
        $keys = array_unique($m[1]);
        check("{$doc}-{$lg}: facts wrapped, every key is served",
            count($m[1]) >= 8 && array_diff($keys, AgreementText::FACT_KEYS) === [] && in_array('brand.cin', $keys, true) && in_array('brand.legal', $keys, true),
            count($m[1]) . ' spans');
        check("{$doc}-{$lg}: the printed fallback stays (reads right without JS)", str_contains($html, '<span data-fact="brand.cin">U52291GJ2026PTC174029</span>'));
    }
}
$ap = $src('includes/agreementpdf.php');
check('agreement PDF letterhead reads Company details', str_contains($ap, 'Settings::company()') && str_contains($ap, "self::co()['legal']") && str_contains($ap, '$co = self::co();'));

echo "\n----------------------------------------\n";
echo "doc-statutory: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
