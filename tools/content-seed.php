<?php
/**
 * =====================================================================
 *  tools/content-seed.php — load the reviewed travel research into the
 *  website content (9 Oct 2026). CLI only.
 *
 *    php -c .claude/php-dev.ini tools/content-seed.php <research.json> [--dry-run] [--no-php]
 *
 *  Reads one JSON file shaped like the research hand-off:
 *    timeline { intro, events[], key_numbers[] }
 *    border   { summary, rules[], rupaidiha_nepalgunj[], tips[] }
 *    nepal    { places[] }
 *    india    { places[], packages[] }     (nepal.packages[] is read too)
 *  Every text item carries en / ne (hi optional) and sources[].
 *
 *  Writes:
 *    travel_places  upsert by slug (country NP / IN)
 *    tour_packages  upsert by slug; NEW rows are 'published' with
 *                   price_from NULL (= "price on request"); an existing
 *                   row keeps its status, price, partner and photo — what
 *                   the office set in Admin -> Tour packages survives a re-run
 *    includes/content/indianepal.php   rewritten whole (.tmp + rename,
 *                   checked by including it before the rename)
 *
 *  Idempotent: running it twice with the same file changes nothing the
 *  second time. Hindi columns are left NULL when the file has no Hindi
 *  (the pages fall back to English).
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/contentpages.php';

$args = array_slice($argv, 1);
$file = '';
foreach ($args as $a) {
    if (!str_starts_with($a, '--')) {
        $file = $a;
    }
}
$dry = in_array('--dry-run', $args, true);
$noPhp = in_array('--no-php', $args, true);
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "usage: php tools/content-seed.php <research.json> [--dry-run] [--no-php]\n");
    exit(2);
}
$j = json_decode((string) file_get_contents($file), true);
if (!is_array($j)) {
    fwrite(STDERR, "Not valid JSON: $file (" . json_last_error_msg() . ")\n");
    exit(2);
}

/* ---------------- helpers ---------------- */

/** One text in three languages from the many shapes the research may use. */
function cs_tri(mixed $item, string $base = ''): array
{
    $out = ['en' => '', 'ne' => '', 'hi' => ''];
    if (is_string($item)) {
        $out['en'] = trim($item);
        return $out;
    }
    if (!is_array($item)) {
        return $out;
    }
    $src = $base === '' ? $item : ($item[$base] ?? null);
    if (is_string($src)) {
        $out['en'] = trim($src);
    } elseif (is_array($src)) {
        foreach (['en', 'ne', 'hi'] as $l) {
            $out[$l] = trim((string) (is_scalar($src[$l] ?? null) ? $src[$l] : ''));
        }
    }
    if ($base !== '') {
        foreach (['en', 'ne', 'hi'] as $l) {
            if ($out[$l] === '' && is_scalar($item[$base . '_' . $l] ?? null)) {
                $out[$l] = trim((string) $item[$base . '_' . $l]);
            }
        }
    }
    return $out;
}

function cs_has(array $t): bool
{
    return $t['en'] !== '' || $t['ne'] !== '';
}

/** @return list<array{label:string,url:string}> */
function cs_sources(mixed $s): array
{
    $out = [];
    foreach (is_array($s) ? $s : [] as $x) {
        if (is_string($x)) {
            $x = trim($x);
            if ($x === '') {
                continue;
            }
            $isUrl = preg_match('#^https?://#i', $x) === 1;
            $out[] = ['label' => $isUrl ? (string) parse_url($x, PHP_URL_HOST) : $x, 'url' => $isUrl ? $x : ''];
        } elseif (is_array($x)) {
            $url = trim((string) ($x['url'] ?? $x['link'] ?? ''));
            $label = trim((string) ($x['label'] ?? $x['title'] ?? $x['name'] ?? $x['publisher'] ?? ''));
            if ($url !== '' && preg_match('#^https?://#i', $url) !== 1) {
                $url = '';
            }
            if ($label === '' && $url !== '') {
                $label = (string) parse_url($url, PHP_URL_HOST);
            }
            if ($label !== '' || $url !== '') {
                $out[] = ['label' => $label, 'url' => $url];
            }
        }
    }
    return $out;
}

/** An item of a text list ({en, ne, hi?, sources}) for indianepal.php. */
function cs_item(mixed $it): ?array
{
    $t = cs_tri($it);
    if (!cs_has($t)) {
        $t = cs_tri($it, 'text');
    }
    if (!cs_has($t)) {
        return null;
    }
    return ['en' => $t['en'], 'ne' => $t['ne'], 'hi' => $t['hi'], 'sources' => cs_sources(is_array($it) ? ($it['sources'] ?? []) : [])];
}

function cs_slug(string $s): string
{
    $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
    return substr($s, 0, 80);
}

function cs_icon(string $text): string
{
    $t = strtolower($text);
    return match (true) {
        (bool) preg_match('/buddha|lumbini|stupa/', $t) => '☸️',
        (bool) preg_match('/treaty|agreement|accord|friendship|sign/', $t) => '🤝',
        (bool) preg_match('/bus|motor vehicle|road/', $t) => '🚌',
        (bool) preg_match('/rail|train/', $t) => '🚆',
        (bool) preg_match('/border|crossing|checkpost|icp/', $t) => '🛂',
        (bool) preg_match('/earthquake|disaster|relief/', $t) => '🕯️',
        (bool) preg_match('/pipeline|power|energy|hydro|electric/', $t) => '⚡',
        (bool) preg_match('/trade|transit|economy|currency|rupee/', $t) => '💱',
        (bool) preg_match('/gorkha|army|soldier/', $t) => '🎖️',
        (bool) preg_match('/temple|pilgrim|janakpur|ram|sita|pashupati/', $t) => '🛕',
        default => '📌',
    };
}

function cs_num(mixed $v): ?float
{
    return is_numeric($v) ? (float) $v : null;
}

/* ---------------- 1. indianepal.php ---------------- */

$tl = is_array($j['timeline'] ?? null) ? $j['timeline'] : [];
$bd = is_array($j['border'] ?? null) ? $j['border'] : [];
$intro = cs_tri($tl['intro'] ?? '');
$events = [];
foreach ((array) ($tl['events'] ?? []) as $ev) {
    if (!is_array($ev)) {
        continue;
    }
    $title = cs_tri($ev, 'title');
    $body = cs_tri($ev, 'body');
    if (!cs_has($body)) {
        $body = cs_tri($ev, 'text');
    }
    if (!cs_has($body)) {
        $body = cs_tri($ev);   // en / ne straight on the event
    }
    if (!cs_has($title) && cs_has($body)) {
        // "Title: rest" / "Title — rest" → split; otherwise the text is the title
        foreach (['en', 'ne', 'hi'] as $l) {
            if ($body[$l] !== '' && preg_match('/^(.{4,90}?)\s*(?::|—|–| - )\s+(.+)$/us', $body[$l], $m) === 1) {
                $title[$l] = trim($m[1]);
                $body[$l] = trim($m[2]);
            } else {
                $title[$l] = $body[$l];
                $body[$l] = '';
            }
        }
    }
    if (!cs_has($title)) {
        continue;
    }
    $year = trim((string) ($ev['year'] ?? $ev['date'] ?? ''));
    $yearTri = cs_tri($ev, 'year_label');
    $row = [
        'year'     => $year !== '' ? $year : $yearTri['en'],
        'icon'     => trim((string) ($ev['icon'] ?? '')) !== '' ? trim((string) $ev['icon']) : cs_icon($title['en'] . ' ' . $body['en']),
        'title_en' => $title['en'], 'title_ne' => $title['ne'], 'title_hi' => $title['hi'],
        'body_en'  => $body['en'], 'body_ne' => $body['ne'], 'body_hi' => $body['hi'],
        'sources'  => cs_sources($ev['sources'] ?? []),
    ];
    if ($yearTri['ne'] !== '') {
        $row['year_ne'] = $yearTri['ne'];
    }
    if (isset($ev['year_ne']) && is_scalar($ev['year_ne'])) {
        $row['year_ne'] = (string) $ev['year_ne'];
    }
    $events[] = $row;
}
$nums = [];
foreach ((array) ($tl['key_numbers'] ?? []) as $k) {
    if (!is_array($k)) {
        continue;
    }
    $label = cs_tri($k, 'label');
    if (!cs_has($label)) {
        $label = cs_tri($k);
    }
    $value = trim((string) ($k['value'] ?? $k['number'] ?? ''));
    if ($value === '' || !cs_has($label)) {
        continue;
    }
    $nums[] = ['value' => $value, 'label_en' => $label['en'], 'label_ne' => $label['ne'], 'label_hi' => $label['hi'], 'sources' => cs_sources($k['sources'] ?? [])];
}
$list = static fn(mixed $a): array => array_values(array_filter(array_map('cs_item', is_array($a) ? $a : []), static fn($x): bool => $x !== null));
$summary = cs_item($bd['summary'] ?? '') ?? ['en' => '', 'ne' => '', 'hi' => '', 'sources' => []];
$story = [
    'reviewed'    => (string) ($j['reviewed'] ?? date('Y-m-d')),
    'intro'       => ['en' => $intro['en'], 'ne' => $intro['ne'], 'hi' => $intro['hi']],
    'events'      => $events,
    'key_numbers' => $nums,
    'border'      => [
        'summary'  => $summary,
        'rules'    => $list($bd['rules'] ?? []),
        'crossing' => $list($bd['rupaidiha_nepalgunj'] ?? $bd['crossing'] ?? []),
        'tips'     => $list($bd['tips'] ?? []),
    ],
    'sources'     => cs_sources($j['sources'] ?? []),
];
// Keep the current intro if the research has none.
if (!cs_has($story['intro'])) {
    $cur = ContentPages::indiaNepalData();
    $story['intro'] = $cur['intro'];
}
echo 'timeline: ' . count($events) . ' events, ' . count($nums) . ' key numbers, border rules ' . count($story['border']['rules'])
    . ', crossing ' . count($story['border']['crossing']) . ', tips ' . count($story['border']['tips']) . "\n";

$path = INCLUDE_PATH . '/content/indianepal.php';
$same = false;
if (is_file($path)) {
    try {
        $same = (include $path) === $story;   // idempotent: an unchanged story is not rewritten
    } catch (Throwable $ex) {
        $same = false;
    }
}
if ($same) {
    echo "includes/content/indianepal.php already up to date
";
} elseif (!$dry && !$noPhp && $events !== []) {
    $php = "<?php\n/**\n * includes/content/indianepal.php — the India–Nepal story for /india-nepal.\n"
        . " * GENERATED by tools/content-seed.php from " . basename($file) . " on " . date('Y-m-d H:i') . ".\n"
        . " * Shape: see ContentPages::indiaNepalData(). Edit the research JSON and re-run, or edit here with care.\n */\n\n"
        . "declare(strict_types=1);\n\nif (!defined('SHG_APP')) {\n    http_response_code(404);\n    exit;\n}\n\nreturn " . var_export($story, true) . ";\n";
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path . '.tmp', $php);
    $check = include $path . '.tmp';
    if (!is_array($check) || count($check['events'] ?? []) !== count($events)) {
        @unlink($path . '.tmp');
        fwrite(STDERR, "Generated indianepal.php did not read back — left the old one in place.\n");
        exit(1);
    }
    rename($path . '.tmp', $path);
    echo "wrote includes/content/indianepal.php\n";
}

/* ---------------- 2. travel_places ---------------- */

$placeCount = ['new' => 0, 'updated' => 0, 'same' => 0];
$sort = 0;
foreach (['NP' => $j['nepal']['places'] ?? [], 'IN' => $j['india']['places'] ?? []] as $country => $places) {
    foreach (is_array($places) ? $places : [] as $p) {
        if (!is_array($p)) {
            continue;
        }
        $nameEn = trim((string) ($p['name'] ?? $p['name_en'] ?? ''));
        if ($nameEn === '') {
            continue;
        }
        $sort += 10;
        $slug = cs_slug((string) ($p['slug'] ?? $nameEn));
        $blurb = cs_tri($p, 'blurb');
        $season = cs_tri($p, 'best_season');
        $reach = cs_tri($p, 'how_to_reach');
        $near = null;
        $km = null;
        foreach (['from_nepalgunj_hint' => 'Nepalgunj', 'near_route_stop' => null, 'from_rupaidiha_hint' => 'Rupaidiha'] as $key => $stop) {
            $v = $p[$key] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $vt = cs_tri($v);
            if ($stop === null) {
                // near_route_stop: the stop's name (or "Ayodhya (≈130 km from Lucknow)")
                $near = mb_substr(trim((string) preg_replace('/\s*\(.*$/u', '', $vt['en'])), 0, 80);
                if (preg_match('/(\d[\d,]*)\s*km/i', $vt['en'], $m) === 1) {
                    $km = (int) str_replace(',', '', $m[1]);
                }
                if (!cs_has($reach) && mb_strlen($vt['en']) > 40) {
                    $reach = $vt;
                }
            } else {
                $near = $near ?? $stop;
                if ($km === null && preg_match('/(\d[\d,]*)\s*km/i', $vt['en'], $m) === 1) {
                    $km = (int) str_replace(',', '', $m[1]);
                }
                if (!cs_has($reach)) {
                    $reach = $vt;
                }
            }
        }
        if (isset($p['km_hint']) && is_numeric($p['km_hint'])) {
            $km = (int) $p['km_hint'];
        }
        $row = [
            'slug' => $slug, 'country' => $country, 'region' => mb_substr(trim((string) ($p['region'] ?? '')), 0, 80) ?: null,
            'category' => ContentPages::normCategory((string) ($p['type'] ?? $p['category'] ?? '')),
            'name_en' => mb_substr($nameEn, 0, 120), 'name_ne' => trim((string) ($p['name_ne'] ?? '')) ?: null, 'name_hi' => trim((string) ($p['name_hi'] ?? '')) ?: null,
            'blurb_en' => $blurb['en'] ?: null, 'blurb_ne' => $blurb['ne'] ?: null, 'blurb_hi' => $blurb['hi'] ?: null,
            'best_season_en' => mb_substr($season['en'], 0, 160) ?: null, 'best_season_ne' => mb_substr($season['ne'], 0, 200) ?: null,
            'how_to_reach_en' => $reach['en'] ?: null, 'how_to_reach_ne' => $reach['ne'] ?: null,
            'unesco' => !empty($p['unesco']) ? 1 : 0,
            'lat' => cs_num($p['lat'] ?? null), 'lng' => cs_num($p['lon'] ?? $p['lng'] ?? null),
            'near_stop' => $near, 'km_hint' => $km, 'sort_order' => $sort,
        ];
        $cur = Database::fetch('SELECT * FROM travel_places WHERE slug = :s', ['s' => $slug]);
        if ($cur === null) {
            $row['is_featured'] = !empty($p['featured']) || !empty($p['unesco']) ? 1 : 0;
            $row['is_active'] = 1;
            if (!$dry) {
                Database::insert('travel_places', $row);
            }
            $placeCount['new']++;
            continue;
        }
        $diff = [];
        foreach ($row as $k => $v) {
            $c = $cur[$k];
            $same = ($v === null && $c === null) || ($v !== null && $c !== null && (is_float($v) ? abs((float) $c - $v) < 1e-6 : (string) $c === (string) $v));
            if (!$same) {
                $diff[$k] = $v;
            }
        }
        if ($diff === []) {
            $placeCount['same']++;
            continue;
        }
        if (!$dry) {
            Database::update('travel_places', $diff, 'id = :id', ['id' => (int) $cur['id']]);
        }
        $placeCount['updated']++;
    }
}
echo 'places: ' . json_encode($placeCount) . "\n";

/* ---------------- 3. tour_packages ---------------- */

$pkgCount = ['new' => 0, 'updated' => 0, 'same' => 0];
$sort = 0;
$pkgs = array_merge(is_array($j['india']['packages'] ?? null) ? $j['india']['packages'] : [], is_array($j['nepal']['packages'] ?? null) ? $j['nepal']['packages'] : [], is_array($j['packages'] ?? null) ? $j['packages'] : []);
foreach ($pkgs as $k) {
    if (!is_array($k)) {
        continue;
    }
    $title = cs_tri($k, 'title');
    if ($title['en'] === '') {
        continue;
    }
    $sort += 10;
    $slug = cs_slug((string) ($k['slug'] ?? $k['code'] ?? $title['en']));
    $c = strtolower((string) ($k['country'] ?? ''));
    $country = str_contains($c, 'both') || (str_contains($c, 'nepal') && str_contains($c, 'india')) ? 'Both' : (str_contains($c, 'india') || $c === 'in' ? 'India' : 'Nepal');
    $summary = cs_tri($k, 'summary');
    if (!cs_has($summary)) {
        $summary = cs_tri($k, 'best_for');
    }
    $route = cs_tri($k, 'route');
    $hlEn = array_values(array_filter(array_map(static fn($x): string => is_scalar($x) ? trim((string) $x) : '', (array) ($k['highlights_en'] ?? []))));
    $hlNe = array_values(array_filter(array_map(static fn($x): string => is_scalar($x) ? trim((string) $x) : '', (array) ($k['highlights_ne'] ?? []))));
    $itin = [];
    foreach ((array) ($k['itinerary'] ?? []) as $i => $d) {
        $t = cs_tri($d);
        if (!cs_has($t)) {
            $t = cs_tri($d, 'text');
        }
        if (cs_has($t)) {
            $itin[] = ['day' => (int) (is_array($d) ? ($d['day'] ?? $i + 1) : $i + 1), 'en' => $t['en'], 'ne' => $t['ne'], 'hi' => $t['hi']];
        }
    }
    $leg = in_array($k['coach_leg'] ?? '', ['toNepal', 'toIndia', 'both', 'none'], true) ? (string) $k['coach_leg'] : 'both';
    $row = [
        'slug' => $slug, 'country' => $country,
        'title_en' => mb_substr($title['en'], 0, 160), 'title_ne' => mb_substr($title['ne'], 0, 200) ?: null, 'title_hi' => mb_substr($title['hi'], 0, 200) ?: null,
        'summary_en' => $summary['en'] ?: null, 'summary_ne' => $summary['ne'] ?: null, 'summary_hi' => $summary['hi'] ?: null,
        'route_en' => mb_substr($route['en'], 0, 255) ?: null, 'route_ne' => mb_substr($route['ne'], 0, 255) ?: null,
        'days' => max(1, min(60, (int) ($k['days'] ?? 1))), 'nights' => max(0, min(60, (int) ($k['nights'] ?? 0))),
        'highlights_en' => $hlEn !== [] ? json_encode($hlEn, JSON_UNESCAPED_UNICODE) : null,
        'highlights_ne' => $hlNe !== [] ? json_encode($hlNe, JSON_UNESCAPED_UNICODE) : null,
        'itinerary_json' => $itin !== [] ? json_encode($itin, JSON_UNESCAPED_UNICODE) : null,
        'coach_leg' => $leg, 'sort_order' => $sort,
    ];
    $cur = Database::fetch('SELECT * FROM tour_packages WHERE slug = :s', ['s' => $slug]);
    if ($cur === null) {
        $row += ['status' => 'published', 'price_from' => null, 'is_featured' => !empty($k['featured']) ? 1 : 0];
        if (!$dry) {
            Database::insert('tour_packages', $row);
        }
        $pkgCount['new']++;
        continue;
    }
    $diff = [];
    foreach ($row as $f => $v) {
        if (!(($v === null && $cur[$f] === null) || ($v !== null && (string) $cur[$f] === (string) $v))) {
            $diff[$f] = $v;
        }
    }
    if ($diff === []) {
        $pkgCount['same']++;
        continue;
    }
    if (!$dry) {
        Database::update('tour_packages', $diff, 'id = :id', ['id' => (int) $cur['id']]);
    }
    $pkgCount['updated']++;
}
echo 'packages: ' . json_encode($pkgCount) . "\n";
echo $dry ? "dry run — nothing written\n" : "done\n";
exit(0);
