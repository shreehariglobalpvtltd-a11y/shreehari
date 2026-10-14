<?php
/**
 * =====================================================================
 *  site-facts-test.php — ONE source for prices and names (9 Oct 2026).
 *
 *  Owner: "price haru name haru update hun sath update hune system
 *  bandinu paryo" — change a fare, a phone, an office or the CEO in Admin
 *  and every surface follows on the next load.
 *
 *  A. SiteFacts::boot() — the SHG_BOOT.facts contract shape, no secrets
 *  B. Fare::board() PARITY — every row equals what Fare::perSeatFor()
 *     charges for that pickup / drop (the checkout's own number)
 *  C. tokens() / apply() — every contract token, escaped, money "₹2,200",
 *     an unknown {{f:…}} renders empty
 *  D. Offices — CounterDesk::publicOffices() (owner's list, head first,
 *     phones from the DB, never fx / methods / notes) + identityChanged()
 *  E. Wiring — index.php token pass before the comment strip, JSON-LD from
 *     facts, the client helpers, the pinned server surfaces
 *  F. LITERAL RATCHET — hardcoded prices / phones / names per file may only
 *     go DOWN (baseline below). Lower a number when you remove literals.
 *  G. HTTP (SHG_TEST_BASE, e.g. http://localhost:8991): the served page has
 *     no "{{f:" left and carries the company phone + the fare board.
 *
 *      php -c .claude/php-dev.ini tests/site-facts-test.php
 *      SHG_TEST_BASE=http://localhost:8991 php -c .claude/php-dev.ini tests/site-facts-test.php
 *      php -c .claude/php-dev.ini tests/site-facts-test.php --print-baseline
 *
 *  Read-only: writes nothing to the database. exit(1) on any failure.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/sitefacts.php';
require_once INCLUDE_PATH . '/watpllib.php';

$ROOT = dirname(__DIR__);
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$src = static fn(string $rel): string => (string) @file_get_contents($ROOT . '/' . $rel);

/* ------------------------------------------------------------------ *
 *  F. The literal scanner (defined first: --print-baseline uses it)   *
 * ------------------------------------------------------------------ */
const LIT_PRICE = '/(?:₹|Rs\.?|INR|रु\.?)\s?(?:1,?800|2,?000|2,?090|2,?200|3,?800|7,?600|4,?400)\b/u';
const LIT_PHONE = '/9104801507|91048\s?01507|9726401507|97264\s?01507|9157001507|91570\s?01507|7359301507|73593\s?01507|7305901507|73059\s?01507|8735881507|87358\s?81507|9173401507|91734\s?01507|986-?6201375|9866201375|9848889950|984-?8889950|9848119600|984-?8119600|9426827507|94268\s?27507/';
const LIT_NAME  = '/Sher Bahadur|Lok Bahadur|Pus?h?palal Chowk|Near Shilpa Garage|U52291GJ2026PTC174029/u';

/** Files the ratchet watches (repo-relative). Sources of truth are allow-listed. */
function lit_files(string $root): array
{
    $allow = ['includes/sitefacts.php', 'includes/settings.php', 'includes/fare.php'];
    $out = ['app.template.html'];
    foreach (glob($root . '/assets/js/*.js') ?: [] as $f) {
        if (basename($f) !== 'terms-data.js') {
            $out[] = 'assets/js/' . basename($f);
        }
    }
    foreach (glob($root . '/*.php') ?: [] as $f) {
        $out[] = basename($f);
    }
    foreach (['includes', 'api', 'admin', 'whatsapp', 'cron'] as $dir) {
        $it = is_dir($root . '/' . $dir)
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS))
            : [];
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            }
        }
    }
    $out = array_values(array_unique(array_diff($out, $allow)));
    sort($out);
    return $out;
}

/** Comments out (HTML, block, line), the 02-config.js fallback block out, then count. */
function lit_count(string $root, string $rel): int
{
    $s = (string) @file_get_contents($root . '/' . $rel);
    if ($s === '') {
        return 0;
    }
    if ($rel === 'assets/js/02-config.js') {
        $s = (string) preg_replace('~/\* FACTS-FALLBACK:START.*?FACTS-FALLBACK:END \*/~s', '', $s);
    }
    $s = (string) preg_replace('/<!--(?!\[if).*?-->/s', '', $s);
    $s = (string) preg_replace('~/\*.*?\*/~s', '', $s);
    $s = (string) preg_replace('~(?<![:\w"\'\\\\/])//[^\n]*~', '', $s);
    return (int) preg_match_all(LIT_PRICE, $s) + (int) preg_match_all(LIT_PHONE, $s) + (int) preg_match_all(LIT_NAME, $s);
}

/* The baseline (9 Oct 2026). A number may only go DOWN. app.template.html is
   being moved to {{f:…}} tokens by the HOME package; integration tightens its
   figure once that lands. A file not listed here must stay at 0. */
const LIT_BASELINE = [
    /*BASELINE:START*/
    'admin/ai-marketing.php' => 2,
    'admin/company.php' => 11,           // the "built-in value" hints beside each box
    'admin/wa-compose.php' => 1,
    'api/wa-request.php' => 1,
    'app.template.html' => 10,           // HOME is tokenising it; integration tightens this
    'assets/js/04-i18n.js' => 1,
    'assets/js/05-router.js' => 8,       // defaultContactNumbers() offline fallback (home-premium-test pins it)
    'assets/js/07-checkout.js' => 3,
    'assets/js/13-admin-routes.js' => 7,
    'assets/js/15-nav.js' => 3,
    'assets/js/16-lazy.js' => 8,
    'assets/js/17-pwa.js' => 1,
    'includes/agreementpdf.php' => 1,
    'includes/legal.php' => 1,
    'includes/publiclayout.php' => 1,
    'includes/waorder.php' => 1,
    'includes/waqueue.php' => 2,
    'index.php' => 2,                    // aisensy_wa_number default (waHi)
    'whatsapp/aisensy.php' => 1,
    /*BASELINE:END*/
];

if (in_array('--print-baseline', $argv, true)) {
    foreach (lit_files($ROOT) as $rel) {
        $n = lit_count($ROOT, $rel);
        if ($n > 0) {
            echo "    '" . $rel . "' => " . $n . ",\n";
        }
    }
    exit(0);
}

/* ------------------------------------------------------------------ */
echo "\n=== A. SiteFacts::boot() — contract shape, no secrets ===\n";
SiteFacts::flush();
$boot = SiteFacts::boot();
$all  = SiteFacts::all();
$need = [
    'v' => null, 'brand' => ['name', 'legal', 'legalNe', 'short', 'web', 'email', 'cin', 'tagline'],
    'ceo' => ['name', 'nameNe', 'title', 'titleNe', 'phone', 'tel', 'wa', 'photo'],
    'head' => ['city', 'address', 'phone', 'tel', 'wa', 'mapUrl'],
    'offices' => null, 'expanding' => null, 'nepal' => ['company', 'office', 'phone'],
    'support' => ['phone', 'whatsapp'], 'npr' => null,
    'fares' => ['go', 'back', 'range', 'defaults', 'private'], 'agent' => ['direct', 'joint'],
];
$missing = [];
foreach ($need as $k => $sub) {
    if (!array_key_exists($k, $boot)) { $missing[] = $k; continue; }
    foreach ((array) $sub as $s) {
        if (!is_array($boot[$k]) || !array_key_exists($s, $boot[$k])) { $missing[] = $k . '.' . $s; }
    }
}
check('boot() carries every contract key', $missing === [], $missing === [] ? count($need) . ' groups' : 'missing ' . implode(', ', $missing));
check('v is a 10-char content hash', is_string($boot['v'] ?? null) && strlen((string) $boot['v']) === 10);
check('brand.name = Settings::company() name', ($boot['brand']['name'] ?? '') === Settings::company()['name'] && $boot['brand']['name'] !== '');
$ceoSet = trim(Settings::getString('company_ceo', ''));
check('ceo.name follows company_ceo (single CEO source)', $ceoSet === '' || ($boot['ceo']['name'] ?? '') === $ceoSet, (string) ($boot['ceo']['name'] ?? ''));
$teamCeo = null;
foreach (Settings::nepalTeam() as $p) { if (($p['key'] ?? '') === 'ceo') { $teamCeo = $p; } }
check('ceo.phone = Settings::nepalTeam() ceo line', $teamCeo !== null && ($boot['ceo']['phone'] ?? '') === (string) $teamCeo['phone'], (string) ($boot['ceo']['phone'] ?? ''));
check('head.phone = Company details phone', ($boot['head']['phone'] ?? '') === Settings::company()['phone'] && $boot['head']['phone'] !== '');
check('head.tel is dial digits', preg_match('/^[1-9]\d{9,14}$/', (string) ($boot['head']['tel'] ?? '')) === 1, (string) ($boot['head']['tel'] ?? ''));
check('npr = npr_per_inr', abs((float) $boot['npr'] - Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6)) < 0.0001, (string) $boot['npr']);
check('expanding = true', ($boot['expanding'] ?? null) === true);
check('agent.direct = agent_flat_direct', (float) $boot['agent']['direct'] === Settings::getFloat('agent_flat_direct', 200.0));
check('boot() is JSON-encodable', json_encode($boot) !== false);

$secretKeys = ['fx', 'fx_rate', 'methods', 'note', 'notes', 'password', 'pass', 'upi', 'upi_id', 'esewa', 'bank', 'account', 'label', 'team', 'api_key', 'token', 'secret'];
$found = [];
$walk = static function ($node, string $path) use (&$walk, &$found, $secretKeys): void {
    if (!is_array($node)) { return; }
    foreach ($node as $k => $v) {
        if (is_string($k) && in_array(strtolower($k), $secretKeys, true)) { $found[] = $path . '.' . $k; }
        $walk($v, $path . '.' . $k);
    }
};
$walk($boot, 'facts');
check('boot() carries no money / staff / secret keys', $found === [], $found === [] ? '' : implode(', ', $found));
check('SiteFacts::all() is memoised per request', SiteFacts::all() === $all);

/* ------------------------------------------------------------------ */
echo "\n=== B. Fare::board() parity with Fare::perSeatFor() ===\n";
$board = Fare::board();
$dir   = Fare::dirFares();
$routes = [];
foreach (Database::fetchAll('SELECT id, from_city, to_city FROM routes') as $r) { $routes[(int) $r['id']] = $r; }
check('board has go rows', count($board['go']) > 0, count($board['go']) . ' pickups');
check('board has back rows (return priced per drop)', count($board['back']) > 0, count($board['back']) . ' drops');
$bad = [];
foreach (['go', 'back'] as $side) {
    foreach ($board[$side] as $row) {
        $rid = (int) $row['routeId'];
        if (!isset($routes[$rid])) { $bad[] = $side . ':' . $row['name'] . ' (no route)'; continue; }
        $charge = $side === 'go'
            ? Fare::perSeatFor($routes[$rid], $row['name'], null)
            : Fare::perSeatFor($routes[$rid], null, $row['name']);
        if (abs($charge - (float) $row['fare']) > 0.001) {
            $bad[] = $side . ':' . $row['name'] . ' board ' . $row['fare'] . ' vs charged ' . $charge;
        }
        $def = (float) $dir[$side === 'go' ? 'toNepal' : 'toIndia'];
        if (!$row['own'] && abs((float) $row['fare'] - $def) > 0.001) {
            $bad[] = $side . ':' . $row['name'] . ' own=false but not the default';
        }
    }
}
check('every board row = what perSeatFor() charges for that stop', $bad === [], $bad === [] ? (count($board['go']) + count($board['back'])) . ' rows' : implode('; ', array_slice($bad, 0, 5)));
foreach (['go', 'back'] as $side) {
    $f = array_map(static fn(array $r): float => (float) $r['fare'], $board[$side]);
    check("range.$side = min/max of its rows", $f !== [] && (float) $board['range'][$side]['min'] === min($f) && (float) $board['range'][$side]['max'] === max($f),
        $board['range'][$side]['min'] . '–' . $board['range'][$side]['max']);
}
check('defaults = Fare::dirFares()', (float) $board['defaults']['toNepal'] === (float) $dir['toNepal'] && (float) $board['defaults']['toIndia'] === (float) $dir['toIndia']);
$priv = Fare::pricing()['private'] ?? [];
check('private = the cabin prices the checkout bills', (float) $board['private']['single'] === (float) ($priv['single_1pax']['offline'] ?? -1)
    && (float) $board['private']['double'] === (float) ($priv['double_2pax']['offline'] ?? -1), $board['private']['single'] . ' / ' . $board['private']['double']);
check('facts.fares.range = board range', $boot['fares']['range'] == $board['range']);
check('facts.fares.go rows = board rows (name + fare)', array_map(static fn($r) => [$r['name'], (float) $r['fare']], $boot['fares']['go'])
    === array_map(static fn($r) => [$r['name'], (float) $r['fare']], $board['go']));

/* ------------------------------------------------------------------ */
echo "\n=== C. tokens() / apply() ===\n";
$tok = SiteFacts::tokens();
$contract = ['brand.name', 'brand.legal', 'brand.cin', 'brand.email', 'ceo.name', 'ceo.nameNe', 'ceo.phone', 'ceo.tel', 'ceo.wa',
    'head.phone', 'head.tel', 'head.wa', 'head.address', 'npr', 'fares.go.min', 'fares.go.max', 'fares.back.min', 'fares.back.max',
    'fares.private.single', 'fares.private.double', 'agent.direct'];
$miss = array_values(array_filter($contract, static fn(string $p): bool => !array_key_exists('{{f:' . $p . '}}', $tok)));
check('every contract token exists', $miss === [], $miss === [] ? count($contract) . ' tokens' : 'missing ' . implode(', ', $miss));
$money = ['fares.go.min', 'fares.go.max', 'fares.back.min', 'fares.back.max', 'fares.private.single', 'fares.private.double', 'agent.direct'];
$notMoney = array_values(array_filter($money, static fn(string $p): bool => !str_starts_with($tok['{{f:' . $p . '}}'] ?? '', '₹')));
check('money tokens render "₹…"', $notMoney === [], $tok['{{f:fares.go.min}}'] ?? '');
check('{{f:fares.go.min}} = inr(board go min)', ($tok['{{f:fares.go.min}}'] ?? '') === htmlspecialchars(inr((float) $board['range']['go']['min']), ENT_QUOTES, 'UTF-8'));
check('{{f:npr}} renders 2 decimals', preg_match('/^\d+\.\d{2}$/', $tok['{{f:npr}}'] ?? '') === 1, $tok['{{f:npr}}'] ?? '');
$raw = [];
foreach ($tok as $k => $v) {
    if (str_starts_with($k, '{{f:html:')) { continue; }   // 10 Oct 2026 (trust): every {{f:html:…}} token is markup by design (offices, social, emails)
    if (preg_match('/[<>"]|&(?!amp;|lt;|gt;|quot;|#039;)/', $v) === 1) { $raw[] = $k; }
}
check('every value token is HTML-escaped', $raw === [], $raw === [] ? '' : implode(', ', array_slice($raw, 0, 6)));
check('an "&" in a fact is escaped (ceo.title "Founder & CEO")', !str_contains((string) $all['ceo']['title'], '&') || str_contains($tok['{{f:ceo.title}}'] ?? '', '&amp;'));
check('no token value carries "{{f:"', !str_contains(implode('', $tok), '{{f:'));
check('apply() fills a known token', SiteFacts::apply('<b>{{f:brand.name}}</b>') === '<b>' . htmlspecialchars($all['brand']['name'], ENT_QUOTES, 'UTF-8') . '</b>');
check('apply() renders an unknown token EMPTY', SiteFacts::apply('[{{f:ceo.nmae}}][{{f:html:nope}}]') === '[][]');
check('apply() leaves a token-free page untouched', SiteFacts::apply('<p>ok {{x}}</p>') === '<p>ok {{x}}</p>');
$ol = $tok['{{f:html:offices}}'] ?? '';
check('{{f:html:offices}} lists every public office + the expanding chip', substr_count($ol, 'data-office=') === count($all['offices'])
    && str_contains($ol, 'th-more') && str_contains($ol, '/offices'), substr_count($ol, 'data-office=') . ' rows');

/* ------------------------------------------------------------------ */
echo "\n=== D. Offices (CounterDesk::publicOffices) ===\n";
CounterDesk::flush();
$offs  = CounterDesk::publicOffices();
$codes = array_column($offs, 'code');
$hasCol = CounterDesk::hasColumn('counter_locations', 'show_public');
check('migration applied (counter_locations.show_public)', $hasCol, 'php tests/apply-sql.php database/upgrade-2026-10-09-site-facts.sql');
foreach (['MSA', 'AMD', 'SRT', 'RJT', 'NPJ'] as $c) {
    $d = CounterDesk::get($c);
    if ($d !== null && (int) ($d['is_active'] ?? 1) === 1 && (!$hasCol || (int) ($d['show_public'] ?? 1) === 1)) {
        check("owner office $c is listed", in_array($c, $codes, true));
    }
}
check('head office (Mehsana) is FIRST', ($offs[0]['kind'] ?? '') === 'head', (string) ($offs[0]['code'] ?? ''));
check('Baroda stays a pickup, not a listed office', !in_array('BRD', $codes, true) || (int) (CounterDesk::get('BRD')['show_public'] ?? 0) === 1);
$npj = null;
foreach ($offs as $o) { if ($o['code'] === 'NPJ') { $npj = $o; } }
if ($npj !== null) {
    check('NPJ is the Nepal partner office', $npj['country'] === 'NP' && $npj['kind'] === 'partner');
    $npjDesk = trim((string) (CounterDesk::get('NPJ')['phone'] ?? ''));
    $npTyped = trim(Settings::getString('np_office_phone', ''));
    $npTeam  = '';
    foreach (Settings::nepalTeam() as $p) { if (($p['key'] ?? '') === 'office') { $npTeam = (string) $p['phone']; } }
    $expect = $npjDesk !== '' ? $npjDesk : ($npTyped !== '' ? $npTyped : (trim(Settings::getString('nepal_phone', '')) ?: $npTeam));
    check('NPJ phone = desk phone, else the Nepal office line', $npj['phone'] === $expect, $npj['phone']);
}
$leak = [];
foreach ($offs as $o) {
    foreach (['fx', 'fx_rate', 'methods', 'note', 'currency', 'entity', 'is_active'] as $k) {
        if (array_key_exists($k, $o)) { $leak[] = $o['code'] . '.' . $k; }
    }
}
check('publicOffices() never exposes fx / methods / notes', $leak === [], implode(', ', $leak));
$byTown = [];
foreach (preg_split('/\s*[\x{00B7}|;\n]\s*/u', (string) Settings::company()['counters']) ?: [] as $part) {
    if (preg_match('/^(.*?)\s*(\+?\d[\d \-]{7,})$/u', trim($part), $m) === 1) { $byTown[strtolower(trim($m[1]))] = trim($m[2]); }
}
$phoneBad = [];
foreach ($offs as $o) {
    $desk = trim((string) (CounterDesk::get($o['code'])['phone'] ?? ''));
    if ($o['status'] !== 'open' || $o['country'] === 'NP') { continue; }
    $want = $desk !== '' ? $desk : ($byTown[strtolower($o['city'])] ?? ($o['kind'] === 'head' ? Settings::company()['phone'] : ''));
    if ($o['phone'] !== $want) { $phoneBad[] = $o['code'] . ' ' . $o['phone'] . ' ≠ ' . $want; }
}
check('India office phones = desk phone, else the company_counters town number', $phoneBad === [], implode('; ', $phoneBad));
$soon = array_filter($offs, static fn(array $o): bool => $o['status'] !== 'open' && $o['phone'] !== '');
check('a coming-soon office shows no phone', $soon === []);
$msa = CounterDesk::get('MSA');
if ($msa !== null) {
    $only = $msa; $only['name_ne'] = ($msa['name_ne'] ?? '') . 'x'; $only['lat'] = 1.5; $only['show_public'] = 0;
    check('identityChanged(): website-only fields do NOT re-render tickets', CounterDesk::identityChanged($msa, $only) === false);
    $ph = $msa; $ph['phone'] = '+91 90000 00000';
    check('identityChanged(): a phone change DOES', CounterDesk::identityChanged($msa, $ph) === true);
    check('identityChanged(): a new desk counts', CounterDesk::identityChanged(null, $msa) === true);
}
check('CounterDesk::dialDigits(+977 986-6201375, NP)', CounterDesk::dialDigits('+977 986-6201375', 'NP') === '9779866201375');
check('CounterDesk::dialDigits(10 digits, IN) adds 91', CounterDesk::dialDigits('98765 43210', 'IN') === '919876543210');
check('CounterDesk::townOf("Rajkot - Office +91 94268 27507") = Rajkot', CounterDesk::townOf('Rajkot - Office +91 94268 27507') === 'Rajkot');
$sql = $src('database/upgrade-2026-10-09-site-facts.sql');
check('migration guards every ADD COLUMN with information_schema', substr_count($sql, 'ADD COLUMN `') === 10 && substr_count($sql, 'information_schema.COLUMNS') === 10);
check('migration seeds no phone', preg_match('/SET[^;]*`phone`\s*=/i', $sql) !== 1);

/* ------------------------------------------------------------------ */
echo "\n=== E. Wiring ===\n";
$idx = $src('index.php');
$pTok = strpos($idx, 'SiteFacts::apply($html)');
$pStrip = strpos($idx, "preg_replace('/<!--(?!\\[if).*?-->/s'");
check('index.php: token pass runs BEFORE the comment strip', $pTok !== false && $pStrip !== false && $pTok < $pStrip);
check('index.php: $boot[\'facts\'] = SiteFacts::boot()', str_contains($idx, "\$boot['facts'] = SiteFacts::boot()"));
check('index.php: JSON-LD has no typed name / phone / street', !str_contains($idx, "'telephone' => '+91") && !str_contains($idx, "'name'      => 'S Hari Global Pvt Ltd'")
    && !str_contains($idx, "'streetAddress'   => 'Near"));
check('index.php: JSON-LD logo = transparent emblem', str_contains($idx, "/assets/img/logo-mark.png'"));
check('index.php: departments per public office + AggregateOffer', str_contains($idx, "'department'") && str_contains($idx, "'AggregateOffer'"));
$cfg = $src('assets/js/02-config.js');
foreach (['window.FACTS = FACTS', 'function fact(', 'function hydrateFacts(', 'function fareRangeJS(', 'FACTS-FALLBACK:START', 'FACTS-FALLBACK:END'] as $needle) {
    check('02-config.js has ' . $needle, str_contains($cfg, $needle));
}
foreach (['data-fact-tel', 'data-fact-wa', 'data-fact-money'] as $needle) {
    check('hydrateFacts fills [' . $needle . ']', str_contains($cfg, "'[" . $needle . "]'"));
}
check('04-i18n.js applyLang() refills facts', (bool) preg_match('/function applyLang\(\)[\s\S]{0,2500}hydrateFacts\(\)/', $src('assets/js/04-i18n.js')));
$rt = $src('assets/js/05-router.js');
check('05-router.js renders offices from FACTS (+ expanding chip)', str_contains($rt, 'function renderOffices()') && str_contains($rt, 'F.expanding') && str_contains($rt, "/offices"));
check('05-router.js back fare card prices per drop', str_contains($rt, "p => locationFare(p, 'back')"));
check('05-router.js pricing cards quote the board range', str_contains($rt, "fareRangeJS('go')") && str_contains($rt, "fareRangeJS('back')"));
check('05-router.js has no ₹3,800 literal', !str_contains($rt, '₹3,800'));
check('admin/login.php reads the office phone + CEO from Settings', str_contains($src('admin/login.php'), 'Settings::officePhone()') && !str_contains($src('admin/login.php'), '+91 91048 01507'));
check('refund-policy.php reads SiteFacts, emblem logo', str_contains($src('refund-policy.php'), 'SiteFacts::all()') && str_contains($src('refund-policy.php'), 'logo-mark'));
check('routepages.php: AggregateOffer from the board + emblem logo', str_contains($src('includes/routepages.php'), 'Fare::board()') && str_contains($src('includes/routepages.php'), "'AggregateOffer'") && str_contains($src('includes/routepages.php'), 'logo-mark'));
check('wafaq.php quotes the return run per drop', str_contains($src('includes/wafaq.php'), "\$side(\$board['back']"));
check('aiprompt.php reads the board (no getInt fare fallback)', str_contains($src('includes/aiprompt.php'), 'Fare::board()') && !str_contains($src('includes/aiprompt.php'), "getInt('fare_to_nepal'"));
check('watpllib.php: range vars + old keys kept', str_contains($src('includes/watpllib.php'), "'fare_range_to_nepal'") && str_contains($src('includes/watpllib.php'), "'fare_to_nepal'"));
check('offercard.php caption quotes "from ₹min"', str_contains($src('includes/offercard.php'), "self::fareFrom('go')"));
check('mkautopilot.php facts from the board', str_contains($src('includes/mkautopilot.php'), 'Fare::board()'));
check('promo-card.php from SiteFacts (no typed cabin price)', str_contains($src('admin/promo-card.php'), 'SiteFacts::all()') && !preg_match('/\?\?\s*(3800|7600)/', $src('admin/promo-card.php')));
$wv = WaTplLib::liveValues();
check('WA template range var = board range', ($wv['fare_range_to_nepal'] ?? '') === SiteFacts::rangeText('go'), (string) ($wv['fare_range_to_nepal'] ?? ''));

/* ------------------------------------------------------------------ */
echo "\n=== F. Literal ratchet (prices / phones / names may only go down) ===\n";
$over = []; $down = []; $total = 0;
foreach (lit_files($ROOT) as $rel) {
    $n = lit_count($ROOT, $rel);
    $total += $n;
    $base = LIT_BASELINE[$rel] ?? 0;
    if ($n > $base) { $over[] = "$rel $n > $base"; }
    elseif ($n < $base) { $down[] = "$rel $n < $base"; }
}
check('no file gained a hardcoded price / phone / name', $over === [], $over === [] ? $total . ' literals left in ' . count(LIT_BASELINE) . ' baseline files' : implode('; ', $over));
if ($down !== []) {
    echo "  note  fewer literals than the baseline — lower LIT_BASELINE: " . implode('; ', $down) . "\n";
}
check('the scanner sees a planted literal', (function (): bool {
    $s = "x '₹2,200' y +91 91048 01507 z Sher Bahadur";
    return preg_match_all(LIT_PRICE, $s) === 1 && preg_match_all(LIT_PHONE, $s) === 1 && preg_match_all(LIT_NAME, $s) === 1;
})());

/* ------------------------------------------------------------------ */
$BASE = rtrim((string) getenv('SHG_TEST_BASE'), '/');
if ($BASE !== '') {
    echo "\n=== G. HTTP ($BASE) ===\n";
    $get = static function (string $url): array {
        $ctx = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true, 'header' => "Accept-Language: ne\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int) $m[1]; } }
        return [$code, (string) $body];
    };
    [$code, $home] = $get($BASE . '/');
    check('GET / → 200', $code === 200, (string) $code);
    check('served / has no "{{f:" token left', !str_contains($home, '{{f:'));
    check('served / carries the company phone', str_contains($home, $all['head']['phone']), $all['head']['phone']);
    check('served / ships SHG_BOOT.facts', str_contains($home, '"facts":{') && str_contains($home, '"expanding":true'));
    check('served / meta description has no "one fixed fare"', preg_match('/<meta name="description" content="[^"]*fixed fare/i', $home) !== 1);
    check('served / JSON-LD AggregateOffer low = board low', str_contains($home, '"lowPrice":"' . (int) min($board['range']['go']['min'], $board['range']['back']['min']) . '"'));
    check('served / title keeps "Surat to Nepal Bus"', (bool) preg_match('#<title>[^<]*Surat to Nepal Bus#', $home));
    [$c2, $rp] = $get($BASE . '/refund-policy');
    if ($c2 !== 200) { [$c2, $rp] = $get($BASE . '/refund-policy.php'); }
    check('refund policy shows the head phone + support line', $c2 === 200 && str_contains($rp, $all['head']['phone']) && str_contains($rp, $all['support']['phone']), (string) $c2);
    [$c3, $lg] = $get($BASE . '/admin/login.php');
    check('admin login shows Settings::officePhone()', $c3 === 200 && str_contains($lg, htmlspecialchars(Settings::officePhone(), ENT_QUOTES, 'UTF-8')), (string) $c3);
} else {
    echo "\n  (HTTP part skipped — set SHG_TEST_BASE=http://localhost:8991)\n";
}

echo "\n" . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . "\n";
exit($FAIL === 0 ? 0 : 1);
