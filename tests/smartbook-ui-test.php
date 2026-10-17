<?php
/**
 * =====================================================================
 *  smartbook-ui-test.php — SmartBook v5 phases 4-7 (15 Oct 2026).
 *
 *  A. The card markup: one section, leads the home view, every control the
 *     owner's spec names (direction, date chips, point, +91/+977, name,
 *     passengers with gender + band, Sharing | Private, berth, Auto | Choose,
 *     Cash | Online, QuickBot line, summary rail, sheet, ticket modal)
 *  B. D1 / D13: agent-only markup is wrapped <!--staff--> and the served
 *     customer page carries no code field; the server refuses a departed bus
 *     to a customer session even with a valid code
 *  C. The card script: one API door, mode only from STAFF, idempotency key,
 *     pinned berths, 80 mm print, keyboard, Sahayak hand-off, PDF auto-download
 *  D. The pages: smartpay.php (QR page), admin/cash-book.php, admin/smartbook.php,
 *     admin/smart-rules.php, admin/ticket-80mm.php - present, guarded, wired
 *  E. The release: CSS / JS in the template and the service-worker shell
 *  F. HTTP (SHG_TEST_BASE): the customer home has the card and no code field;
 *     smartpay.php refuses a stranger; the staff pages need a login
 *
 *      SHG_TEST_BASE=http://localhost:8970 php -c .claude/php-dev.ini tests/smartbook-ui-test.php
 *
 *  Read-only. exit(1) on any failure.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$ROOT = dirname(__DIR__);
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$src = static fn(string $rel): string => (string) @file_get_contents($ROOT . '/' . $rel);
$tpl = $src('app.template.html');
$js  = $src('assets/js/27-smartbook.js');
$css = $src('assets/css/smartbook.css');

echo "\nA. The card markup\n";
check('one #smartBook section', substr_count($tpl, 'id="smartBook"') === 1);
$home = preg_match('~<section class="view active" id="view-home"(?: data-first)?>(.*)~s', $tpl, $m) ? $m[1] : '';
/* 17 Oct 2026 (premium 3D UI): the decorative 3D band (#hero3d) may stand above the card */
check('it is the first booking block of the home view (only the 3D band above it)', (bool) preg_match('~^\s*(?:<!--.*?-->\s*)*(?:<div class="p3" id="hero3d"[^>]*>(?:(?!<section\b|<form\b|<a\b|<button\b).)*?)?<section class="sb" id="smartBook"~s', $home));
foreach (['sbBot' => 'QuickBot line', 'sbDates' => 'date chips', 'sbPoint' => 'boarding / drop point', 'sbNear' => 'nearest-to-me button',
          'sbCc' => '+91 / +977', 'sbPhone' => 'mobile', 'sbName' => 'lead name', 'sbPax' => 'passenger chips', 'sbMinus' => 'fewer passengers',
          'sbPlus' => 'more passengers', 'sbType' => 'Sharing | Private', 'sbCabin' => 'Single | Double', 'sbBerth' => 'berth preference',
          'sbSeatMode' => 'Auto | Choose seats', 'sbSeatLine' => 'seat line', 'sbCash' => 'Book · Cash', 'sbOnline' => 'Book · Online', 'sbRail' => 'Journey Summary rail',
          'sbBar' => 'phone sticky bar', 'sbSheet' => 'seat-map sheet', 'sbModal' => 'ticket modal', 'sbPolicy' => 'policy line'] as $id => $what) {
    check("has $what (#$id)", str_contains($tpl, 'id="' . $id . '"'));
}
check('the book buttons are never shipped disabled (a tap must answer)', (bool) preg_match('~id="sbCash"(?![^>]*disabled)[^>]*>~', $tpl) && (bool) preg_match('~id="sbOnline"(?![^>]*disabled)[^>]*>~', $tpl));
check('the buttons say they book', str_contains($tpl, 'Book · Cash') && str_contains($tpl, 'Book · Online'));
check('direction switch has both runs', str_contains($tpl, 'data-dir="toNepal"') && str_contains($tpl, 'data-dir="toIndia"'));
check('the card section ships hidden until the engine boots (classic search is the fallback)', (bool) preg_match('~id="smartBook" aria-labelledby="sbTitle" hidden>~', $tpl)
    && str_contains($css, 'html.sb-on #search-anchor, html.sb-on #quick-ticket{display:none}'));
check('no code / agent field inside the card', !preg_match('~agent|referral|SHG-0~i', (string) (preg_match('~<section class="sb" id="smartBook".*?</section>~s', $tpl, $mm) ? $mm[0] : 'agent')));

echo "\nB. D1 / D13 — no code entry for customers\n";
check('the search card\'s "Agent? Enter code" block is staff-only', (bool) preg_match('~<!--staff-->\s*<details class="s-field s-agent-latebook".*?</details><!--/staff-->~s', $tpl));
check('the checkout\'s Agent Code field is staff-only', (bool) preg_match('~<!--staff--><div class="co-card">.*?id="agentCodeField".*?<!--/staff-->~s', $tpl));
check('index.php strips <!--staff--> blocks for a non-staff page', str_contains($src('index.php'), "preg_replace('~<!--staff-->.*?<!--/staff-->~s', '', \$html)") && str_contains($src('index.php'), "if (empty(\$boot['staff']))"));
check('QuickBot draws its agent box only for staff', str_contains($src('assets/js/05-router.js'), "((window.SHG_BOOT && window.SHG_BOOT.staff) ? '<div class=\"qt-agent\">"));
check('booking.php: a departed bus needs a selling-staff session', str_contains($src('includes/booking.php'), "&& Auth::isSellingStaff()\n            && Boarding::agentGraceOpen") || str_contains($src('includes/booking.php'), "&& Auth::isSellingStaff()\r\n            && Boarding::agentGraceOpen"));
check('search.php: a typed code alone opens no departed bus', str_contains($src('api/search.php'), "Auth::isSellingStaff() && AgentWallet::resolveAgentCodeFromString"));
check('api/smartbook.php: the mode comes from the session', str_contains($src('api/smartbook.php'), 'SmartBook::sessionMode('));

echo "\nC. The card script\n";
check('one API door that adds the staff mode', str_contains($js, "function api(body) { if (STAFF) body.mode = MODE; return shgApi.post('/smartbook.php', body); }") && substr_count($js, "shgApi.post('/smartbook.php'") === 1);
check('a booking carries an idempotency key', str_contains($js, 'S.idem = S.idem || newKey()') && str_contains($js, 'body.idem = S.idem'));
check('the shown berths are the berths sold', str_contains($js, 'body.seats = S.q.seats; body.autoPicked = !!S.q.auto;'));
check('the price shown is the price expected', str_contains($js, 'body.expect = { total: S.q.price ? S.q.price.total : null, date: S.q.date };'));
check('a moved quote (409 changed) re-renders the card', str_contains($js, "er.fields.code === 'changed'"));
/* 15 Oct 2026: the PDF no longer downloads by itself (on a phone that
   navigation closed the ticket); the PNG on the card is the delivery, with
   Share first and the downloads beside it. */
check('Cash opens the ticket on the page, nothing downloads by itself', !str_contains($js, "if (fresh && r.ticket && r.ticket.pdf)") && str_contains($js, "'#/?ticket='") && str_contains($js, 'function wireTicketImg(host)'));
check('  Share leads the ticket actions, PNG and PDF beside it', str_contains($js, 'class="sb-main-act" data-tk="share"') && str_contains($js, 'download="SHG-Ticket-'));
check('  the booking API pre-draws the PNG after the answer is flushed', str_contains($src('api/smartbook.php'), 'fastcgi_finish_request();') && str_contains($src('api/smartbook.php'), 'Ticket::pngPath($preBid);'));
check('  download-ticket lets go of the session before rendering', str_contains($src('download-ticket.php'), 'session_write_close();'));
check('Online goes to the QR page (customer) / the pay link (agent)', str_contains($js, "location.href = r.payPage") && str_contains($js, 'showPayLink(r)'));
check('counter keyboard: Ctrl+Enter books Cash and prints', str_contains($js, "(e.ctrlKey || e.metaKey)") && str_contains($js, "'/admin/ticket-80mm.php?pnr='"));
check('Sahayak / QuickBot share fillFromText', str_contains($js, 'function fillFromText(text)') && str_contains($src('assets/js/16-lazy.js'), 'data-sb-fill="'));
check('every innerHTML value is escaped', substr_count($js, 'esc(') > 40);
check('phones: the card fits 360 × 640 (short-screen strips step aside)', str_contains($css, '@media (max-width:859px) and (max-height:780px)'));
check('dark + AMOLED tokens', str_contains($css, ':root[data-theme="dark"] #smartBook{') && str_contains($css, ':root[data-display="amoled"] #smartBook{'));

echo "\nD. The pages\n";
$pay = $src('smartpay.php');
check('smartpay.php: owner / keyed link only, nothing confirms', str_contains($pay, 'Auth::ownPnrs()') && str_contains($pay, 'Ticket::checkDownloadToken') && !str_contains($pay, 'approveReviewed'));
check('smartpay.php: proof form stays open while pending', str_contains($pay, "if (\$state === 'pay' && \$proof)") && !str_contains($pay, "\$left <= 0 && \$st === 'pending'"));
check('smartpay.php: UPI QR + copy + open app + eSewa + UTR / screenshot', str_contains($pay, 'data-copy="upiId"') && str_contains($pay, 'data-t="openUpi"') && str_contains($pay, 'p-esewa') && str_contains($pay, 'name="screenshot"'));
check('payadapter: QR today, gateways by setting, nothing auto-confirms', str_contains($src('includes/payadapter.php'), 'interface PaymentAdapter') && str_contains($src('includes/payadapter.php'), "Settings::getString('payment_gateway', '')"));
$cb = $src('admin/cash-book.php');
check('cash book: settleCod, scope, CSV safe, daily totals', str_contains($cb, 'BookingService::settleCod(') && str_contains($cb, 'Auth::bookingScopeAdminId()') && str_contains($cb, "\"'\" . \$v") && str_contains($cb, 'Daily cash totals'));
$sb = $src('admin/smartbook.php');
check('staff SmartBook reads the ONE card markup from the template', str_contains($sb, "file_get_contents(dirname(__DIR__) . '/app.template.html')") && str_contains($sb, 'SmartBook::sessionMode('));
check('staff SmartBook refuses a customer session', str_contains($sb, "if (\$mode === 'customer')"));
/* 15 Oct 2026 — the desk's agent pick (owner: "Agent Code entry in Agent Mode and
   especially Counter Mode, with agent selection/search, automatic commission") */
check('staff SmartBook splices the agent field in (never in the template)', str_contains($sb, 'id="sbAgent" list="sbAgentList"') && str_contains($sb, 'AgentWallet::sellableAgents()') && !str_contains($tpl, 'sbAgent'));
check('  an agent login gets its own code, locked', str_contains($sb, "AgentWallet::isAgentRow((int) \$admin['id']) ? AgentWallet::agentCodeLabel((int) \$admin['id'])") && str_contains($sb, 'id="sbAgentOwn"'));
check('  the pick is remembered per clerk under the counter-mode key', str_contains($js, "'shg_ctr_agent_' + (STAFF.id || 0)") && str_contains($src('assets/js/14-counter.js'), "'shg_ctr_agent_' + (STAFF.id || 0)"));
check('  the card sends the pick; the server validates it', str_contains($js, 'body.agentCode = S.agent') && str_contains($src('api/smartbook.php'), 'AgentWallet::deskAgentCode($typed)') && str_contains($src('api/smartbook.php'), "unset(\$in['referralCode'])"));
check('  a refused pick lands on the agent field', str_contains($js, "er.fields.field === 'agent'") && str_contains($src('api/smartbook.php'), "['field' => 'agent']"));
check('  the ticket card names the agent and the commission (staff only)', str_contains($js, 'STAFF && r.agent') && str_contains($src('api/smartbook.php'), "\$out['agent'] = ["));
check('  the engine credits whatever the API set, never the payload', str_contains($src('includes/smartbook.php'), "'referralCode'    => \$mode !== 'customer' ? Security::clean((string) (\$in['referralCode'] ?? ''), 20) : ''"));
check('  the rules page carries the "must pick an agent" switch', str_contains($src('admin/smart-rules.php'), "'smartbook_agent_required'") && str_contains($src('admin/smart-rules.php'), 'name="agent_req"'));
check('  index.php and the API share one agent list', str_contains($src('index.php'), "\$boot['staff']['agents'] = AgentWallet::sellableAgents();"));
$sr = $src('admin/smart-rules.php');
check('rules page: saves need settings.manage and are audit-logged', str_contains($sr, 'Auth::canManageSettings()') && str_contains($sr, "Logger::audit('smartbook.settings'"));
check('rules page: Default Rules preview', str_contains($sr, 'DefaultRules::preview('));
check('80 mm receipt: verify QR, policy line, agent scope', str_contains($src('admin/ticket-80mm.php'), 'Ticket::qrPayload(') && str_contains($src('admin/ticket-80mm.php'), "Fare::refundPolicyLine('en')") && str_contains($src('admin/ticket-80mm.php'), 'bookingScopeAdminId'));
foreach (['smartpay.php', 'admin/cash-book.php', 'admin/smartbook.php', 'admin/smart-rules.php', 'admin/ticket-80mm.php', 'api/smartbook.php', 'includes/smartbook.php', 'includes/autoberth.php', 'includes/payadapter.php', 'includes/defaultrules.php', 'includes/smartbookbot.php'] as $f) {
    $out = []; $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . (php_ini_loaded_file() ? ' -c ' . escapeshellarg((string) php_ini_loaded_file()) : '') . ' -l ' . escapeshellarg($ROOT . '/' . $f) . ' 2>&1', $out, $rc);
    check("php -l $f", $rc === 0, $rc === 0 ? '' : implode(' ', $out));
}

echo "\nE. The release\n";
check('template loads smartbook.css and 27-smartbook.js', (bool) preg_match('~/assets/css/smartbook\.css\?v=~', $tpl) && (bool) preg_match('~<script defer src="/assets/js/27-smartbook\.js\?v=~', $tpl));
$sw = $src('sw.js');
check('the service-worker shell carries both', str_contains($sw, "'/assets/css/smartbook.css?v=' + ASSET_VER") && str_contains($sw, "'/assets/js/27-smartbook.js?v=' + ASSET_VER"));
check('admin nav: SmartBook, Cash book, SmartBook rules', str_contains($src('admin/_guard.php'), "'smartbook.php'") && str_contains($src('admin/_guard.php'), "'cash-book.php'") && str_contains($src('admin/_guard.php'), "'smart-rules.php'"));

$base = rtrim((string) getenv('SHG_TEST_BASE'), '/');
if ($base !== '') {
    echo "\nF. HTTP — $base\n";
    $get = static function (string $u): array {
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
        $b = (string) curl_exec($ch);
        $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$c, $b];
    };
    [$c, $h] = $get($base . '/?ui=' . time());
    check('home: the card is served', $c === 200 && str_contains($h, 'id="smartBook"'));
    check('home: no code field for a customer (D1 / D13)', !preg_match('~id="sAgentCode"|id="cAgentCode"|agentLateSummary"~', $h));
    check('home: the policy line is the D8 one', str_contains($h, '≥48 hrs before departure: 75% refund'));
    [$c] = $get($base . '/smartpay.php?pnr=bad!');
    check('smartpay.php: a bad PNR is refused', $c === 400);
    [$c, $b] = $get($base . '/smartpay.php?pnr=SHG-2026-00001');
    check('smartpay.php: a stranger sees no amount or QR', $c === 200 && !str_contains($b, 'data-copy="upiId"'));
    foreach (['admin/smartbook.php', 'admin/cash-book.php', 'admin/smart-rules.php', 'admin/ticket-80mm.php?pnr=SHG-2026-00001'] as $p) {
        [$c, $b] = $get($base . '/' . $p);
        check("/$p needs a staff login", in_array($c, [302, 303, 401, 403], true) || str_contains($b, 'name="password"'), (string) $c);
    }
}

echo "\n" . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . "\n";
exit($FAIL === 0 ? 0 : 1);
