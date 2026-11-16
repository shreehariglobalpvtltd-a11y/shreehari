<?php
/**
 * admin/themes.php — the weekly colours the customer app shows
 * (16 Nov 2026, Master Upgrade v2.0 P5-7a).
 *
 * The customer app paints one accent colour per weekday, Asia/Kolkata
 * (assets/css/ds.css "Weekly accent" blocks, stamped before paint by the
 * inline script in app.template.html: IST weekday -> sun..sat). The office
 * had no place to see them. This page shows:
 *   - the seven accents, light + dark, read straight from ds.css (so it can
 *     never drift from what customers get);
 *   - the next 7 IST dates with the accent each day gets, and any festival
 *     date from AI Marketing (MkAutopilot::festivals) on those days;
 *   - a Preview link per accent: /?accent_preview=<key> opens the customer
 *     app in that colour for 5 minutes, in that browser tab only
 *     (sessionStorage 'shg:accentPreview', app.template.html). Nothing is
 *     saved and no customer sees it.
 *
 * The weekly colours stay read-only. Same gate as easy-settings.php.
 *
 * 16 Nov 2026 (X2: P5-3, P5-7b, P5-7c) - "विशेष दिन · Special dates": the
 * office's own list of days that get one of the seven colours and / or a
 * banner line, stored in settings festival_dates (json, never public):
 *   [{from, to, name, accent, banner_ne, banner_en}]
 * at most 30 rows, each at most 15 days, accent = one of the seven weekly
 * keys ('' = keep the weekday colour), with add / edit / delete, an
 * "आज यो रङ · Override today" row (a one-day row for today) and the
 * 5-minute Preview link per row. Nothing shows to any customer until the
 * switch festival_theme_on (default 0) is turned on; then index.php hands the
 * customer app ONLY the row covering today in India time (SHG_BOOT.festival).
 * Two rows on one day: the shorter wins, then the later start, then the later
 * row - index.php picks with the same rule. Nothing is prefilled.
 * Writes: Auth::canManageSettings() (the gate of settings.php and of the
 * switches in easy-settings.php), CSRF, Logger::audit with old / new; a
 * stale page (the list changed since it opened) saves nothing.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');
$canEdit = Auth::canManageSettings();

$e = static fn($v): string => Security::e((string) $v);

/* The day names and colour names, Nepali first. The English colour name is
   read from the ds.css comment ("Mon blue, Tue teal, ..."), this is only the
   fallback and the Nepali word. */
$days = [
    'sun' => ['आइतबार', 'Sunday'],   'mon' => ['सोमबार', 'Monday'],   'tue' => ['मंगलबार', 'Tuesday'],
    'wed' => ['बुधबार', 'Wednesday'], 'thu' => ['बिहीबार', 'Thursday'], 'fri' => ['शुक्रबार', 'Friday'],
    'sat' => ['शनिबार', 'Saturday'],
];
$colourNe = [
    'blue' => 'नीलो', 'teal' => 'टिल (हरियो-नीलो)', 'violet' => 'बैजनी', 'green' => 'हरियो',
    'indigo' => 'गाढा नीलो', 'burnt orange' => 'गाढा सुन्तला', 'rose' => 'गुलाबी',
];
$colourEn = ['mon' => 'blue', 'tue' => 'teal', 'wed' => 'violet', 'thu' => 'green', 'fri' => 'indigo', 'sat' => 'burnt orange', 'sun' => 'rose'];

/* ---- the accents, from ds.css ---- */
$css = (string) @file_get_contents(dirname(__DIR__) . '/assets/css/ds.css');
if (preg_match('~Weekly accent[^\n]*\n\s*(Mon [^\n]+?)\.?\s*\n~', $css, $cm) === 1) {
    foreach (explode(',', $cm[1]) as $part) {
        if (preg_match('/^\s*(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+([a-z][a-z ]{1,30}?)\s*$/', $part, $pm) === 1) {
            $colourEn[strtolower($pm[1])] = $pm[2];
        }
    }
}
$hex = static fn(string $v): string => preg_match('/^#[0-9A-Fa-f]{3,8}$/', trim($v)) === 1 ? trim($v) : '';
$accents = [];   // key => ['light' => [var => #hex], 'dark' => [...]] in ds.css order
if (preg_match_all('~\[data-theme="(light|dark)"\]\[data-accent="([a-z]{3})"\]\{([^}]*)\}~', $css, $bm, PREG_SET_ORDER) > 0) {
    foreach ($bm as $b) {
        if (!isset($days[$b[2]])) {
            continue;
        }
        $vars = [];
        if (preg_match_all('/--([a-z-]+)\s*:\s*([^;]+)/', $b[3], $vm, PREG_SET_ORDER) > 0) {
            foreach ($vm as $v) {
                $vars[$v[1]] = $hex($v[2]);
            }
        }
        $accents[$b[2]][$b[1]] = $vars;
    }
}
$accents = array_filter($accents, static fn(array $a): bool => ($a['light']['royal'] ?? '') !== '' && ($a['dark']['royal'] ?? '') !== '');

/* ---- the next 7 days, India time (the template's rule: IST weekday) ---- */
$tz    = new DateTimeZone('Asia/Kolkata');
$now   = new DateTimeImmutable('now', $tz);
$today = $now->format('Y-m-d');
$week  = [];
for ($i = 0; $i < 7; $i++) {
    $d = $now->modify('+' . $i . ' day');
    $week[] = ['date' => $d->format('Y-m-d'), 'key' => strtolower($d->format('D')), 'label' => $d->format('D, j M')];
}

/* ---- festival dates (AI Marketing), if that module is installed ---- */
$festivals = [];
try {
    if (!class_exists('MkAutopilot') && is_file(INCLUDE_PATH . '/mkautopilot.php')) {
        require_once INCLUDE_PATH . '/mkautopilot.php';
    }
    if (class_exists('MkAutopilot') && method_exists('MkAutopilot', 'festivals')) {
        $festivals = MkAutopilot::festivals();
    }
} catch (Throwable $ex) {
    $festivals = [];
}
$festOn = [];
$festNext = [];
$horizon = $now->modify('+120 day')->format('Y-m-d');
foreach ($festivals as $f) {
    $fd = (string) ($f['date'] ?? '');
    $fn = (string) ($f['name'] ?? '');
    if ($fd === '' || $fn === '') {
        continue;
    }
    $festOn[$fd][] = $fn;
    if ($fd >= $today && $fd <= $horizon && count($festNext) < 4) {
        $fdt = DateTimeImmutable::createFromFormat('!Y-m-d', $fd, $tz);
        if ($fdt !== false) {
            $festNext[] = ['date' => $fd, 'name' => $fn, 'key' => strtolower($fdt->format('D')), 'label' => $fdt->format('D, j M Y')];
        }
    }
}

$todayKey = $week[0]['key'];
$dot = static fn(string $key) => '<i class="th-dot" style="background:' . Security::e($accents[$key]['light']['royal'] ?? '#12264E') . '" aria-hidden="true"></i>';
$nameOf = static function (string $key) use ($colourEn, $colourNe): string {
    $en = (string) ($colourEn[$key] ?? '');
    return trim(($colourNe[$en] ?? '') . ' · ' . ucfirst($en), ' ·');
};

/* ---- special dates (16 Nov 2026, X2) ---- */
$spKeys    = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];   // the template's K list (app.template.html)
$spMaxRows = 30;
$spMaxDays = 15;
$spDate = static function (mixed $s) use ($tz): ?DateTimeImmutable {
    $s = is_string($s) ? trim($s) : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) !== 1) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, $tz);
    return ($d !== false && $d->format('Y-m-d') === $s) ? $d : null;
};
$spText = static fn(mixed $s, int $max): string => Security::clean((string) (preg_replace('/\s+/u', ' ', is_scalar($s) ? (string) $s : '') ?? ''), $max);
/* one row (stored or posted) -> [clean row, ''] or [null, why it was refused] */
$spRow = static function (array $in) use ($spDate, $spText, $spKeys, $spMaxDays): array {
    $f = $spDate($in['from'] ?? '');
    $t = $spDate($in['to'] ?? '');
    if ($f === null || $t === null) {
        return [null, 'दुवै मिति लेख्नुहोस् · Enter both dates (From and To).'];
    }
    if ($t < $f) {
        return [null, 'अन्तिम मिति सुरु मितिभन्दा अघि छ · "To" is before "From".'];
    }
    $days = (int) $f->diff($t)->days + 1;
    if ($days > $spMaxDays) {
        return [null, 'एक पङ्क्ति बढीमा ' . $spMaxDays . ' दिन · One row covers at most ' . $spMaxDays . ' days (this one is ' . $days . ').'];
    }
    $name = $spText($in['name'] ?? '', 60);
    if (mb_strlen($name) < 2) {
        return [null, 'नाम लेख्नुहोस् · Write a name.'];
    }
    $acc = is_string($in['accent'] ?? null) ? (string) $in['accent'] : '';
    if ($acc !== '' && !in_array($acc, $spKeys, true)) {
        return [null, 'रङ मिलेन · Pick one of the seven colours.'];
    }
    $ne = $spText($in['banner_ne'] ?? '', 120);
    $en = $spText($in['banner_en'] ?? '', 120);
    if ($acc === '' && $ne === '' && $en === '') {
        return [null, 'रङ वा सूचना कम्तीमा एउटा चाहिन्छ · Pick a colour or write a banner line.'];
    }
    return [['from' => $f->format('Y-m-d'), 'to' => $t->format('Y-m-d'), 'name' => $name, 'accent' => $acc, 'banner_ne' => $ne, 'banner_en' => $en], ''];
};
/* a posted row must also still be ahead of us, and not years away */
$spAhead = static function (array $r) use ($today, $now): string {
    if ($r['to'] < $today) {
        return 'यी मिति बितिसके · Those dates are already over.';
    }
    if ($r['from'] > $now->modify('+730 day')->format('Y-m-d')) {
        return 'दुई वर्षभित्रको मिति मात्र · Only dates within the next two years.';
    }
    return '';
};
/* the row covering $date: the shorter wins, then the later start, then the later row (index.php: same rule) */
$spPick = static function (array $rows, string $date) use ($tz): ?array {
    $best = null;
    $bestSpan = PHP_INT_MAX;
    foreach ($rows as $i => $r) {
        if ($r['from'] > $date || $r['to'] < $date) {
            continue;
        }
        $span = (int) (new DateTimeImmutable($r['from'], $tz))->diff(new DateTimeImmutable($r['to'], $tz))->days;
        if ($best === null || $span < $bestSpan || ($span === $bestSpan && $r['from'] >= $best['from'])) {
            $best = $r + ['i' => $i];
            $bestSpan = $span;
        }
    }
    return $best;
};
$spLoad = static function () use ($spRow, $spMaxRows): array {
    $rows = [];
    foreach (Settings::getArray('festival_dates', []) as $r) {
        if (is_array($r) && count($rows) < $spMaxRows) {
            [$clean] = $spRow($r);
            if ($clean !== null) {
                $rows[] = $clean;
            }
        }
    }
    return $rows;
};
$spRows = $spLoad();
$spOn   = Settings::getBool('festival_theme_on', false);
$spVer  = sha1((string) json_encode($spRows));
$spSave = static function (array $rows): void {
    Settings::set('festival_dates', array_values($rows), 'json', 'app');
};

$flash   = null;
$spDraft = null;   // the add form's values after a refused add
if (Security::isPost()) {
    $act = (string) ($_POST['act'] ?? '');
    $idx = (int) ($_POST['i'] ?? -1);
    if (!$canEdit) {
        $flash = ['bad', 'यो बदल्ने अनुमति छैन · Only the owner or a settings manager can change this.'];
    } elseif (!Security::verifyCsrf()) {
        $flash = ['bad', 'पेज धेरै बेर खुला थियो — फेरि प्रयास गर्नुहोस् · The page was open too long — try again.'];
    } elseif ($act !== 'switch' && !hash_equals($spVer, (string) ($_POST['ver'] ?? ''))) {
        $flash = ['bad', 'सूची यसैबीच बदलियो — केही सेभ भएन, हेरेर फेरि गर्नुहोस् · The list changed meanwhile — nothing was saved. Check it and try again.'];
    } else {
        try {
            if ($act === 'switch') {
                $want = (string) ($_POST['on'] ?? '') === '1';
                Settings::set('festival_theme_on', $want, 'bool', 'app');
                Logger::audit('theme.special_switch', 'setting', 'festival_theme_on', ['on' => $spOn], ['on' => $want], 'Special-day colour & banner ' . ($want ? 'ON' : 'OFF'));
                $spOn  = $want;
                $flash = ['ok', $want ? 'खुला: आजको विशेष दिन ग्राहकले देख्छन् · ON: customers now get today\'s special date.' : 'बन्द: ग्राहकले केही फरक देख्दैनन् · OFF: customers see no change.'];
            } elseif ($act === 'add' || $act === 'edit') {
                [$row, $why] = $spRow($_POST);
                if ($row !== null) {
                    $why = $spAhead($row);
                }
                if ($act === 'edit' && !isset($spRows[$idx])) {
                    $why = 'त्यो पङ्क्ति भेटिएन · That row was not found.';
                } elseif ($act === 'add' && count($spRows) >= $spMaxRows) {
                    $why = 'बढीमा ' . $spMaxRows . ' पङ्क्ति — पुरानो हटाउनुहोस् · At most ' . $spMaxRows . ' rows — delete an old one first.';
                }
                if ($why !== '' || $row === null) {
                    $flash = ['bad', $why];
                    if ($act === 'add') {
                        $spDraft = [
                            'from' => $spText($_POST['from'] ?? '', 10), 'to' => $spText($_POST['to'] ?? '', 10), 'name' => $spText($_POST['name'] ?? '', 60),
                            'accent' => $spText($_POST['accent'] ?? '', 3), 'banner_ne' => $spText($_POST['banner_ne'] ?? '', 120), 'banner_en' => $spText($_POST['banner_en'] ?? '', 120),
                        ];
                    }
                } else {
                    $old = $act === 'edit' ? $spRows[$idx] : null;
                    if ($act === 'edit') {
                        $spRows[$idx] = $row;
                    } else {
                        $spRows[] = $row;
                    }
                    $spSave($spRows);
                    Logger::audit('theme.special_' . $act, 'setting', 'festival_dates', $old, $row, 'Special date ' . ($act === 'add' ? 'added' : 'changed') . ': ' . $row['name']);
                    $flash = ['ok', ($act === 'add' ? 'थपियो · Added: ' : 'सेभ भयो · Saved: ') . $row['name']];
                }
            } elseif ($act === 'delete') {
                if (!isset($spRows[$idx])) {
                    $flash = ['bad', 'त्यो पङ्क्ति भेटिएन · That row was not found.'];
                } else {
                    $old = $spRows[$idx];
                    array_splice($spRows, $idx, 1);
                    $spSave($spRows);
                    Logger::audit('theme.special_delete', 'setting', 'festival_dates', $old, null, 'Special date deleted: ' . $old['name']);
                    $flash = ['ok', 'हटाइयो · Deleted: ' . $old['name']];
                }
            } elseif ($act === 'today') {
                $acc = (string) ($_POST['accent'] ?? '');
                if (!in_array($acc, $spKeys, true)) {
                    $flash = ['bad', 'रङ छान्नुहोस् · Pick a colour.'];
                } else {
                    $at = null;
                    foreach ($spRows as $i => $r) {
                        if ($r['from'] === $today && $r['to'] === $today) {
                            $at = $i;   // the last one-day row for today is the one that shows
                        }
                    }
                    if ($at === null && count($spRows) >= $spMaxRows) {
                        $flash = ['bad', 'बढीमा ' . $spMaxRows . ' पङ्क्ति — पुरानो हटाउनुहोस् · At most ' . $spMaxRows . ' rows — delete an old one first.'];
                    } else {
                        $old = $at !== null ? $spRows[$at] : null;
                        $row = $old !== null ? array_merge($old, ['accent' => $acc])
                            : ['from' => $today, 'to' => $today, 'name' => 'आजको रङ · Today\'s colour', 'accent' => $acc, 'banner_ne' => '', 'banner_en' => ''];
                        if ($at !== null) {
                            $spRows[$at] = $row;
                        } else {
                            $spRows[] = $row;
                        }
                        $spSave($spRows);
                        Logger::audit('theme.special_today', 'setting', 'festival_dates', $old, $row, 'Override today: ' . $acc);
                        $flash = ['ok', 'आजको रङ: ' . $nameOf($acc) . ' · Today\'s colour set' . ($spOn ? '.' : ' (the switch is OFF — customers see it once it is on).')];
                    }
                }
            } elseif ($act === 'prune') {
                $keep = array_values(array_filter($spRows, static fn(array $r): bool => $r['to'] >= $today));
                $gone = array_values(array_filter($spRows, static fn(array $r): bool => $r['to'] < $today));
                if ($gone === []) {
                    $flash = ['ok', 'बितेको पङ्क्ति छैन · No past rows.'];
                } else {
                    $spRows = $keep;
                    $spSave($spRows);
                    Logger::audit('theme.special_prune', 'setting', 'festival_dates', ['rows' => $gone], ['left' => count($keep)], 'Past special dates removed: ' . count($gone));
                    $flash = ['ok', count($gone) . ' बितेका पङ्क्ति हटाइए · past rows removed.'];
                }
            } else {
                $flash = ['bad', 'के गर्ने बुझिएन · Unknown action.'];
            }
        } catch (Throwable $ex) {
            Logger::error('themes.php special dates: ' . $ex->getMessage());
            $flash = ['bad', 'सेभ हुन सकेन · Could not save — nothing changed.'];
            $spRows = $spLoad();
            $spOn   = Settings::getBool('festival_theme_on', false);
        }
    }
    $spVer = sha1((string) json_encode($spRows));
}
$spToday = $spPick($spRows, $today);
$spDays = static fn(array $r): int => (int) (new DateTimeImmutable($r['from'], $tz))->diff(new DateTimeImmutable($r['to'], $tz))->days + 1;
$spLabel = static function (array $r) use ($tz): string {
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $r['from'], $tz);
    $t = DateTimeImmutable::createFromFormat('!Y-m-d', $r['to'], $tz);
    if ($f === false || $t === false) {
        return $r['from'] . ' – ' . $r['to'];
    }
    return $r['from'] === $r['to'] ? $f->format('D, j M Y') : $f->format('j M') . ' – ' . $t->format('j M Y');
};
$spColour = static fn(string $acc): string => $acc === '' ? 'हप्ताकै रङ · Weekday colour' : $nameOf($acc);
$spOpts = static function (string $sel, bool $blank) use ($spKeys, $days, $nameOf): string {
    $h = $blank ? '<option value=""' . ($sel === '' ? ' selected' : '') . '>हप्ताकै रङ (नफेर्ने) · Keep the weekday colour</option>'
        : ($sel === '' ? '<option value="" disabled selected>रङ छान्नुहोस् · Choose a colour</option>' : '');
    foreach ($spKeys as $k) {
        $h .= '<option value="' . Security::e($k) . '"' . ($sel === $k ? ' selected' : '') . '>'
            . Security::e(($days[$k][0] ?? '') . 'को रङ — ' . $nameOf($k) . ' (' . ($days[$k][1] ?? $k) . ')') . '</option>';
    }
    return $h;
};
$spFields = static function (array $r, string $id) use ($spOpts, $today, $e): string {
    $v = static fn(string $k): string => $e($r[$k] ?? '');
    return '<label for="' . $id . 'f">सुरु मिति · From<input type="date" id="' . $id . 'f" name="from" value="' . $v('from') . '" required></label>'
        . '<label for="' . $id . 't">अन्तिम मिति · To<input type="date" id="' . $id . 't" name="to" value="' . $v('to') . '" min="' . $e($today) . '" required></label>'
        . '<label class="wide" for="' . $id . 'n">नाम · Name<input type="text" id="' . $id . 'n" name="name" value="' . $v('name') . '" maxlength="60" required placeholder="जस्तै: दशैं · e.g. Dashain"></label>'
        . '<label class="wide" for="' . $id . 'a">रङ · Colour<select id="' . $id . 'a" name="accent">' . $spOpts((string) ($r['accent'] ?? ''), true) . '</select></label>'
        . '<label class="wide" for="' . $id . 'bn">सूचना (नेपाली) · Banner, Nepali<input type="text" id="' . $id . 'bn" name="banner_ne" value="' . $v('banner_ne') . '" maxlength="120" lang="ne"></label>'
        . '<label class="wide" for="' . $id . 'be">सूचना (English) · Banner, English<input type="text" id="' . $id . 'be" name="banner_en" value="' . $v('banner_en') . '" maxlength="120" lang="en"></label>';
};
$spHidden = static fn(string $act, int $i = -1): string => Security::csrfField()
    . '<input type="hidden" name="act" value="' . Security::e($act) . '">'
    . ($i >= 0 ? '<input type="hidden" name="i" value="' . $i . '">' : '')
    . '<input type="hidden" name="ver" value="' . Security::e($spVer) . '">';

admin_header('Colours & Themes · रङ र थिम', 'themes.php');
?>
<style>
.th-intro{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin:0 0 16px;font-size:14px;line-height:1.55}
.th-intro p{margin:0 0 8px}.th-intro p:last-child{margin:0}
.th-intro .en{color:var(--mut)}
.th-today{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:15px;margin:0 0 16px}
.th-dot{display:inline-block;width:14px;height:14px;border-radius:50%;flex:0 0 auto;box-shadow:0 0 0 1px rgba(0,0,0,.12)}
.th-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px;margin:0 0 22px}
.th-card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;min-width:0}
.th-card.on{box-shadow:0 0 0 2px var(--orange)}
.th-card header{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid var(--line)}
.th-card header b{font-size:14px}
.th-card header small{display:block;font-size:12px;color:var(--mut)}
.th-card header .tag{margin-left:auto;font-size:11px;font-weight:700;color:#fff;background:var(--orange);border-radius:99px;padding:2px 8px;white-space:nowrap}
.th-pair{display:grid;grid-template-columns:1fr 1fr}
.th-half{padding:12px 10px;min-width:0}
.th-half small{display:block;font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;margin:0 0 8px;opacity:.75}
.th-btn{display:inline-block;padding:7px 12px;border-radius:9px;font-size:13px;font-weight:700;white-space:nowrap}
.th-chip{display:inline-block;margin-top:8px;padding:3px 9px;border-radius:99px;font-size:12px;font-weight:600}
.th-hex{display:block;margin-top:8px;font:12px ui-monospace,Menlo,Consolas,monospace;opacity:.8;overflow-wrap:anywhere}
.th-card footer{padding:10px 12px;border-top:1px solid var(--line);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.th-card footer .btn{min-height:40px;display:inline-flex;align-items:center}
.th-days{list-style:none;margin:0;padding:0}
.th-days li{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:11px 16px;border-bottom:1px solid var(--line);font-size:14px}
.th-days li:last-child{border-bottom:0}
.th-days .d{min-width:96px;font-weight:700;font-variant-numeric:tabular-nums}
.th-days .n{color:var(--mut);font-size:13px}
.th-days .fest{font-size:12px;font-weight:700;color:#7a4a00;background:#ffe6c7;border-radius:99px;padding:2px 9px}
.th-days .now{font-size:11px;font-weight:700;color:#fff;background:var(--navy);border-radius:99px;padding:2px 8px}
:root[data-theme="dark"] .th-days .fest{color:#ffd9a8;background:#3a2a12}
:root[data-theme="dark"] .th-days .now{background:#2E5FA8}
@media(max-width:420px){.th-days .d{min-width:0;flex-basis:100%}}
/* special dates (16 Nov 2026, X2) */
.th-days .spd{font-size:12px;font-weight:700;color:#12264E;background:#e3eaf7;border-radius:99px;padding:2px 9px}
.th-days .spd.off{color:var(--mut);background:transparent;border:1px dashed var(--line)}
:root[data-theme="dark"] .th-days .spd{color:#dbe6fb;background:#1d2c4a}
.sp-top{display:grid;gap:14px;padding:14px 16px;border-bottom:1px solid var(--line)}
.sp-switch{display:flex;align-items:center;justify-content:space-between;gap:10px 14px;flex-wrap:wrap}
.sp-switch>div{min-width:0;flex:1 1 240px}
.sp-top small{display:block;font-size:12.5px;color:var(--mut);font-weight:400;margin-top:3px;line-height:1.45}
.sp-state{display:inline-block;font-size:11.5px;font-weight:800;border-radius:99px;padding:2px 9px;background:#eceff4;color:#3b4456;vertical-align:1px}
.sp-state.on{background:#d7f4e3;color:#0a6b3b}
:root[data-theme="dark"] .sp-state{background:#263248;color:#c9d3e6}
:root[data-theme="dark"] .sp-state.on{background:#123d29;color:#9ee2bd}
.sp-today label{font-weight:700;font-size:14px}
.sp-inline{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
.sp-inline select{flex:1 1 200px;min-width:0}
.sp-list{list-style:none;margin:0;padding:0}
.sp-row{display:grid;gap:7px;padding:12px 16px;border-bottom:1px solid var(--line);min-width:0}
.sp-row.past{opacity:.62}
.sp-head{display:flex;align-items:center;flex-wrap:wrap;gap:6px 10px;min-width:0}
.sp-what{flex:1 1 150px;min-width:0}
.sp-what b{display:block;font-size:14px;overflow-wrap:anywhere}
.sp-what small{display:block;font-size:12px;color:var(--mut);font-variant-numeric:tabular-nums}
.th-dot.none{background:transparent;box-shadow:inset 0 0 0 2px var(--line)}
.sp-chip{flex:0 1 auto;max-width:100%;font-size:11px;font-weight:700;border-radius:99px;padding:2px 8px;background:#eceff4;color:#3b4456}
.sp-chip.now{background:var(--navy);color:#fff}
.sp-chip.won{background:#fff1d6;color:#7a4a00}
:root[data-theme="dark"] .sp-chip{background:#263248;color:#c9d3e6}
:root[data-theme="dark"] .sp-chip.now{background:#2E5FA8;color:#fff}
:root[data-theme="dark"] .sp-chip.won{background:#3a2a12;color:#ffd9a8}
.sp-meta{font-size:13px;color:var(--mut)}
.sp-banner{font-size:13px;line-height:1.45;padding:7px 10px;border-radius:8px;background:var(--head);border-left:3px solid #D4A017;overflow-wrap:anywhere}
.sp-acts{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start}
.sp-acts form{margin:0}
.sp-acts .btn,.sp-add>summary,.sp-edit>summary{min-height:40px;display:inline-flex;align-items:center}
.sp-edit,.sp-add{min-width:0}
.sp-edit[open]{flex-basis:100%}
.sp-edit>summary,.sp-add>summary{list-style:none;cursor:pointer}
.sp-edit>summary::-webkit-details-marker,.sp-add>summary::-webkit-details-marker{display:none}
.sp-add{padding:12px 16px;border-bottom:1px solid var(--line)}
.sp-add>summary{font-weight:700;color:var(--blue)}
.sp-form{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:10px 0 2px}
.sp-form label{display:grid;gap:4px;font-size:12.5px;font-weight:700;min-width:0}
.sp-form .wide,.sp-form .go{grid-column:1/-1}
.sp-form input,.sp-form select,.sp-inline select{width:100%;box-sizing:border-box;min-width:0;padding:9px 10px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font:inherit;font-weight:400;min-height:42px}
.sp-rules{font-size:12.5px;line-height:1.5;padding:10px 16px 14px;margin:0}
@media(max-width:480px){.sp-form{grid-template-columns:1fr}.sp-top,.sp-row,.sp-add{padding-left:12px;padding-right:12px}}
</style>

<div class="th-intro">
  <p>ग्राहक एपको मुख्य रङ हप्ताको दिनअनुसार आफैं फेरिन्छ (भारतीय समय, रातको १२ बजे)। लोगो, नेभी बार, सिटका रङ र सफल / चेतावनी रङ कहिल्यै फेरिँदैनन्।
    <span class="en">The customer app's accent colour changes by itself with the weekday (India time, at midnight). The logo, the navy bar, seat colours and success / warning colours never change.</span></p>
  <p>ग्राहकले "Fixed · एउटै रङ" वा आफ्नै रङ (palette) छानेको छ भने हप्ताको रङ देखिँदैन; काउन्टर मोडमा पनि देखिँदैन।
    <span class="en">A customer who picked "Fixed", or picked a colour palette of their own, does not get the weekly colour; counter mode never does either.</span></p>
  <p><b>Preview</b>: नयाँ ट्याबमा ग्राहक एप त्यो रङमा ५ मिनेट देखिन्छ — त्यही ट्याबमा मात्र। केही सेभ हुँदैन, कुनै ग्राहकले देख्दैन।
    <span class="en">Opens the customer app in a new tab in that colour for 5 minutes, in that tab only. Nothing is saved and no customer sees it.</span></p>
  <p class="en">हप्ताको रङ यहाँबाट बदलिँदैन। तलका <a href="#special">विशेष दिन</a> मा मात्र अफिसले आफ्नै मितिको रङ / सूचना राख्छ — स्विच खोलेपछि मात्र ग्राहकले देख्छन्। · The weekly colours cannot be changed here. Only the <a href="#special">Special dates</a> below take the office's own dates — customers see them only once that switch is ON.</p>
</div>

<?php if ($flash !== null): ?>
  <div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>" role="status"><?= $e($flash[1]) ?></div>
<?php endif; ?>

<?php if ($accents === []): ?>
  <div class="flash bad">रङ पढ्न सकिएन (assets/css/ds.css)। · The weekly colours could not be read from assets/css/ds.css.</div>
<?php else: ?>
  <p class="th-today"><?= $dot($todayKey) ?>
    <span><b>आज <?= $e($days[$todayKey][0] ?? '') ?></b> · Today <?= $e($days[$todayKey][1] ?? '') ?>: <?= $e($nameOf($todayKey)) ?></span>
    <?php if ($spToday !== null): ?>
      <span><?= $spToday['accent'] !== '' ? $dot($spToday['accent']) : '' ?> <b>विशेष दिन · Special day:</b> <?= $e($spToday['name']) ?> — <?= $e($spColour($spToday['accent'])) ?><?= $spOn ? '' : ' <span class="sp-state">स्विच बन्द · switch OFF</span>' ?></span>
    <?php endif; ?></p>

  <div class="th-grid">
  <?php foreach ($accents as $key => $a): $l = $a['light']; $d = $a['dark']; ?>
    <section class="th-card<?= $key === $todayKey ? ' on' : '' ?>" aria-label="<?= $e(($days[$key][1] ?? $key) . ' ' . ($colourEn[$key] ?? '')) ?>">
      <header><?= $dot($key) ?><div><b><?= $e($days[$key][0] ?? '') ?> · <?= $e($days[$key][1] ?? $key) ?></b><small><?= $e($nameOf($key)) ?></small></div>
        <?php if ($key === $todayKey): ?><span class="tag">आज · Today</span><?php endif; ?></header>
      <div class="th-pair">
        <div class="th-half" style="background:<?= $e($l['royal-tint'] ?? '#FFFFFF') ?>;color:#1b2436">
          <small>Light · उज्यालो</small>
          <span class="th-btn" style="background:<?= $e($l['royal']) ?>;color:<?= $e($l['on-royal'] ?? '#FFFFFF') ?>">बुक · Book</span><br>
          <span class="th-chip" style="background:<?= $e($l['royal-soft'] ?? '') ?>;color:<?= $e($l['royal-strong'] ?? $l['royal']) ?>">छानिएको · Selected</span>
          <span class="th-hex"><?= $e($l['royal']) ?></span>
        </div>
        <div class="th-half" style="background:<?= $e($d['royal-tint'] ?? '#0B1220') ?>;color:#e8f0fb">
          <small>Dark · अँध्यारो</small>
          <span class="th-btn" style="background:<?= $e($d['royal']) ?>;color:<?= $e($d['on-royal'] ?? '#0B1220') ?>">बुक · Book</span><br>
          <span class="th-chip" style="background:<?= $e($d['royal-soft'] ?? '') ?>;color:<?= $e($d['royal-strong'] ?? $d['royal']) ?>">छानिएको · Selected</span>
          <span class="th-hex"><?= $e($d['royal']) ?></span>
        </div>
      </div>
      <footer>
        <a class="btn ghost" href="/?accent_preview=<?= $e($key) ?>" target="_blank" rel="noopener">Preview · हेर्नुहोस् ↗</a>
        <span class="muted" style="font-size:12px">५ मिनेट, यही ट्याब · 5 min, that tab</span>
      </footer>
    </section>
  <?php endforeach; ?>
  </div>

  <div class="panel">
    <h2>अर्को ७ दिन · Next 7 days (India time)</h2>
    <ul class="th-days">
    <?php foreach ($week as $i => $w): $k = $w['key']; ?>
      <li>
        <span class="d"><?= $e($w['label']) ?></span>
        <?= $dot($k) ?>
        <span><?= $e($days[$k][0] ?? '') ?> · <?= $e($nameOf($k)) ?></span>
        <?php if ($i === 0): ?><span class="now">आज · Today</span><?php endif; ?>
        <?php $sp = $spPick($spRows, $w['date']); if ($sp !== null): ?>
          <span class="spd<?= $spOn ? '' : ' off' ?>" title="<?= $e($spOn ? 'Special date' : 'Special date — switch OFF, customers do not see it') ?>">★ <?= $e($sp['name']) ?><?= $sp['accent'] !== '' ? ' → ' . $e($nameOf($sp['accent'])) : '' ?><?= $spOn ? '' : ' (बन्द · off)' ?></span>
        <?php endif; ?>
        <?php foreach ($festOn[$w['date']] ?? [] as $fn): ?><span class="fest"><?= $e($fn) ?></span><?php endforeach; ?>
      </li>
    <?php endforeach; ?>
    </ul>
  </div>

  <div class="panel" id="special">
    <h2>विशेष दिन · Special dates</h2>
    <div class="sp-top">
      <div class="sp-switch">
        <div>
          <b>विशेष दिनको रङ र सूचना · Special-day colour &amp; banner:</b>
          <?= $spOn ? '<span class="sp-state on">खुला · ON</span>' : '<span class="sp-state">बन्द · OFF</span>' ?>
          <small><?= $spOn
            ? 'आजको पङ्क्ति ग्राहक एपमा देखिन्छ: त्यसको रङ, र अफिसको सूचना नचलेको बेला त्यसको सूचना। · Today\'s row shows in the customer app: its colour, and its banner when no office notice is showing.'
            : 'बन्द हुँदा ग्राहकले केही फरक देख्दैनन् — तलका मिति सेभ मात्र हुन्छन्। · While OFF customers see no change; the dates below are only kept.' ?></small>
        </div>
        <?php if ($canEdit): ?>
        <form method="post" action="/admin/themes.php#special">
          <?= Security::csrfField() ?><input type="hidden" name="act" value="switch"><input type="hidden" name="on" value="<?= $spOn ? '0' : '1' ?>">
          <button type="submit" class="btn<?= $spOn ? ' ghost' : '' ?>"><?= $spOn ? 'बन्द गर्नुहोस् · Turn OFF' : 'खोल्नुहोस् · Turn ON' ?></button>
        </form>
        <?php endif; ?>
      </div>
      <?php if ($canEdit): ?>
      <form class="sp-today" method="post" action="/admin/themes.php#special">
        <?= $spHidden('today') ?>
        <label for="spTodayAcc">आज यो रङ · Override today <span class="muted">(<?= $e($now->format('D, j M')) ?>)</span></label>
        <div class="sp-inline">
          <select id="spTodayAcc" name="accent" required><?= $spOpts($spToday !== null && $spToday['from'] === $today && $spToday['to'] === $today ? $spToday['accent'] : '', false) ?></select>
          <button type="submit" class="btn">आज लागू · Use today</button>
        </div>
        <small>आजको लागि मात्र (भारतीय समय, रातको १२ बजेसम्म) — तल एक दिनको पङ्क्ति बन्छ, हटाउँदा फेरि हप्ताकै रङ। · Today only (India time, till midnight) — saved as a one-day row below; delete it to go back.</small>
      </form>
      <?php endif; ?>
    </div>

    <?php if ($spRows === []): ?>
      <p class="muted" style="padding:12px 16px;margin:0;border-bottom:1px solid var(--line)">कुनै विशेष दिन राखिएको छैन<?= $canEdit ? ' — तलबाट थप्नुहोस्' : '' ?>। · No special dates yet<?= $canEdit ? ' — add one below' : '' ?>.</p>
    <?php else: ?>
    <ul class="sp-list">
      <?php
      $spOrder = array_keys($spRows);
      usort($spOrder, static fn(int $a, int $b): int => [$spRows[$a]['from'], $spRows[$a]['to'], $a] <=> [$spRows[$b]['from'], $spRows[$b]['to'], $b]);
      foreach ($spOrder as $i):
          $r = $spRows[$i];
          $past = $r['to'] < $today;
          $live = !$past && $r['from'] <= $today;
          [$chipCls, $chip] = $past ? ['', 'बितिसक्यो · Over']
              : ($live ? (($spToday['i'] ?? -1) === $i ? ['now', 'आज · Today'] : ['won', 'आज, अर्को पङ्क्ति जित्छ · Today, another row wins'])
              : ['', 'आउँदै · Upcoming']);
          $bn = trim($r['banner_ne'] . ($r['banner_ne'] !== '' && $r['banner_en'] !== '' ? ' · ' : '') . $r['banner_en']);
      ?>
      <li class="sp-row<?= $past ? ' past' : '' ?>">
        <div class="sp-head">
          <?= $r['accent'] !== '' ? $dot($r['accent']) : '<i class="th-dot none" aria-hidden="true"></i>' ?>
          <div class="sp-what"><b><?= $e($r['name']) ?></b><small><?= $e($spLabel($r)) ?> · <?= $spDays($r) ?> दिन · day<?= $spDays($r) > 1 ? 's' : '' ?></small></div>
          <span class="sp-chip<?= $chipCls !== '' ? ' ' . $chipCls : '' ?>"><?= $e($chip) ?></span>
        </div>
        <div class="sp-meta">रङ · Colour: <?= $e($spColour($r['accent'])) ?></div>
        <?php if ($bn !== ''): ?><div class="sp-banner" lang="ne"><?= $e($bn) ?></div><?php endif; ?>
        <div class="sp-acts">
          <?php if ($r['accent'] !== ''): ?>
          <a class="btn ghost" href="/?accent_preview=<?= $e($r['accent']) ?>" target="_blank" rel="noopener">Preview · हेर्नुहोस् ↗</a>
          <?php endif; ?>
          <?php if ($canEdit): ?>
          <?php if (!$past): ?>
          <details class="sp-edit">
            <summary class="btn ghost">सम्पादन · Edit</summary>
            <form class="sp-form" method="post" action="/admin/themes.php#special">
              <?= $spHidden('edit', $i) ?><?= $spFields($r, 'spE' . $i) ?>
              <div class="go"><button type="submit" class="btn">सेभ · Save</button></div>
            </form>
          </details>
          <?php endif; ?>
          <form method="post" action="/admin/themes.php#special" onsubmit="return confirm('यो विशेष दिन हटाउने? · Delete this special date?');">
            <?= $spHidden('delete', $i) ?><button type="submit" class="btn ghost danger">हटाउनुहोस् · Delete</button>
          </form>
          <?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($canEdit): ?>
      <?php if (count($spRows) < $spMaxRows): ?>
      <details class="sp-add"<?= ($spRows === [] || $spDraft !== null) ? ' open' : '' ?>>
        <summary>＋ नयाँ विशेष दिन · Add a special date</summary>
        <form class="sp-form" method="post" action="/admin/themes.php#special">
          <?= $spHidden('add') ?><?= $spFields($spDraft ?? [], 'spA') ?>
          <div class="go"><button type="submit" class="btn">थप्नुहोस् · Add</button></div>
        </form>
      </details>
      <?php endif; ?>
      <?php if (array_filter($spRows, static fn(array $r): bool => $r['to'] < $today) !== []): ?>
      <form method="post" action="/admin/themes.php#special" style="padding:12px 16px 0;margin:0">
        <?= $spHidden('prune') ?><button type="submit" class="btn ghost">बितेका पङ्क्ति हटाउनुहोस् · Remove past rows</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
    <p class="muted sp-rules">बढीमा <?= $spMaxRows ?> पङ्क्ति, एक पङ्क्ति बढीमा <?= $spMaxDays ?> दिन। एकै दिन दुई पङ्क्ति परे छोटो पङ्क्ति जित्छ (आज यो रङ = एक दिन)। ग्राहकले आफैं रङ छानेको (Fixed / palette) छ भने उसकै रङ रहन्छ, काउन्टरमा रङ फेरिँदैन। सूचना पहिले नेपाली, त्यसपछि English; अफिसको सूचना (Settings) चलिरहेको बेला त्यही देखिन्छ। Preview ले रङ मात्र देखाउँछ।
      · At most <?= $spMaxRows ?> rows of up to <?= $spMaxDays ?> days. When two rows cover one day the shorter wins (Override today = one day). A visitor's own colour pick (Fixed / palette) stays and the counter never changes colour. The banner reads Nepali first, then English; an office notice (Settings) on screen comes first. Preview shows the colour only.</p>
  </div>

  <div class="panel">
    <h2>चाडपर्व · Festival dates</h2>
    <?php if ($festNext === []): ?>
      <p class="muted" style="padding:12px 18px;margin:0">अर्को चार महिनामा कुनै चाड राखिएको छैन। · No festival date in the next four months.</p>
    <?php else: ?>
    <ul class="th-days">
      <?php foreach ($festNext as $f): ?>
      <li><span class="d"><?= $e($f['label']) ?></span><span class="fest"><?= $e($f['name']) ?></span>
        <span class="n"><?= $dot($f['key']) ?> <?= $e($days[$f['key']][0] ?? '') ?> · <?= $e($nameOf($f['key'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <p class="muted" style="font-size:12.5px;padding:10px 16px 14px;margin:0;border-top:1px solid var(--line)">
      यी मिति AI Marketing का सन्देशका लागि मात्र हुन् — रङ फेर्दैनन्; रङ / सूचना माथिको विशेष दिनमा राख्नुहोस्। · These dates only drive AI Marketing messages and never change the colour; for a colour or banner use Special dates above. They are kept in AI Marketing<?php if (Auth::can('customers.view')): ?> (<a href="/admin/ai-marketing.php">AI Marketing</a>)<?php endif; ?>.
    </p>
  </div>
<?php endif; ?>
<?php admin_footer();
