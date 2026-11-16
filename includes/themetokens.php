<?php
/**
 * =====================================================================
 *  includes/themetokens.php — the weekly accent on the stand-alone pages
 *  (16 Nov 2026, Master v2 unit Y6, P5-4a)
 *
 *  The app (app.template.html + assets/css/ds.css, 13 Nov 2026) wears one
 *  accent per weekday in India time: Mon blue, Tue teal, Wed violet, Thu
 *  green, Fri indigo, Sat burnt orange, Sun rose. The pages a passenger
 *  opens straight from a WhatsApp link - track.php, help.php, pay.php,
 *  verify-ticket.php - load no app bundle, so they kept one fixed blue.
 *
 *  ThemeTokens::accentHead() gives such a page, for its <head>:
 *
 *   1. the app's pre-paint accent script, same rules: the IST weekday
 *      stamped as <html data-accent="mon">; nothing when the visitor chose
 *      "Fixed brand colour" (localStorage shg:accent = 'fixed') or picked a
 *      colour palette of their own (shg:palette = 'shg' or a palette id);
 *      the special date's accent while festival_theme_on is ON (the same row
 *      index.php picks); the Admin -> Themes 5-minute preview
 *      (?accent_preview=<day>, sessionStorage shg:accentPreview); a re-check
 *      at midnight IST and when the tab comes back;
 *   2. the owner's accent table itself, READ FROM assets/css/ds.css at run
 *      time (never typed here): the 14 light / dark blocks exactly as ds.css
 *      has them (--royal, --royal-strong, --royal-soft, --royal-tint,
 *      --on-royal ...), plus two theme-independent names taken from the same
 *      light row - --accent-fill (= light --royal) and --accent-fill-strong
 *      (= light --royal-strong) - for a page that puts WHITE text on its
 *      primary colour in dark mode too (ds.css's dark --royal is a light tint
 *      made for dark text; white on it would not be readable).
 *
 *  Each page then maps only its OWN primary variable(s) under [data-accent]
 *  (e.g. track.php --pri: var(--royal)); with no accent the page keeps its
 *  own colours exactly as before. If ds.css cannot be read or the table is
 *  not complete, accentHead() returns '' and nothing changes at all.
 *
 *  The palette ids (PALETTES) mirror window.SHG_PAL_TC in app.template.html;
 *  tests/u11-Y6-themes-test.php keeps the two lists equal.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class ThemeTokens
{
    /** Weekday keys in JavaScript getUTCDay() order (0 = Sunday), as app.template.html K. */
    public const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /** The colour palettes a visitor can pick (app.template.html window.SHG_PAL_TC keys). */
    public const PALETTES = ['professional', 'whiteblue', 'goldenorange', 'himalaya', 'laligurans', 'saffron', 'tulsi',
        'phewa', 'lotus', 'lavender', 'marigold', 'monsoon', 'everest', 'terracotta', 'peacock'];

    /** @var array<string, array{light: array<string, string>, dark: array<string, string>}>|null */
    private static ?array $table = null;

    /** @var array<string, string> per-file cache of the rendered head */
    private static array $head = [];

    /**
     * The owner's accent table from ds.css: [day => ['light' => [token => #hex], 'dark' => [...]]].
     * Empty when the file is missing or any of the 14 rows is absent / not plain hex.
     *
     * @return array<string, array{light: array<string, string>, dark: array<string, string>}>
     */
    public static function table(?string $cssFile = null): array
    {
        if ($cssFile === null && self::$table !== null) {
            return self::$table;
        }
        $file = $cssFile ?? dirname(__DIR__) . '/assets/css/ds.css';
        $out  = [];
        $css  = is_file($file) ? (string) @file_get_contents($file) : '';
        if ($css !== '' && preg_match_all(
            '/^:root:root\[data-theme="(light|dark)"\]\[data-accent="(sun|mon|tue|wed|thu|fri|sat)"\]\{([^{}]*)\}[ \t]*\r?$/m',
            $css, $m, PREG_SET_ORDER
        )) {
            foreach ($m as $row) {
                $tokens = [];
                foreach (explode(';', $row[3]) as $decl) {
                    $decl = trim($decl);
                    if ($decl === '') {
                        continue;
                    }
                    if (preg_match('/^(--[a-z][a-z0-9-]*):\s*(#[0-9A-Fa-f]{6})$/', $decl, $d) !== 1) {
                        $tokens = [];   // anything but a plain hex colour: refuse the row
                        break;
                    }
                    $tokens[$d[1]] = strtoupper($d[2]);
                }
                if ($tokens !== []) {
                    $out[$row[2]][$row[1]] = $tokens;
                }
            }
        }
        $ok = true;
        foreach (self::DAYS as $day) {
            foreach (['light', 'dark'] as $theme) {
                if (!isset($out[$day][$theme]['--royal'], $out[$day][$theme]['--royal-strong'])) {
                    $ok = false;
                }
            }
        }
        $table = [];
        if ($ok) {
            foreach (self::DAYS as $day) {
                $table[$day] = ['light' => $out[$day]['light'], 'dark' => $out[$day]['dark']];
            }
        }
        if ($cssFile === null) {
            self::$table = $table;
        }
        return $table;
    }

    /**
     * Today's special date, exactly as index.php hands it to the app (SHG_BOOT.festival):
     * null unless festival_theme_on is ON and a valid row covers today in India time.
     *
     * @return array{from: string, to: string, accent: string}|null
     */
    public static function festival(): ?array
    {
        try {
            if (!Settings::getBool('festival_theme_on', false)) {
                return null;
            }
            $ist  = new DateTimeZone('Asia/Kolkata');
            $day  = (new DateTimeImmutable('now', $ist))->format('Y-m-d');
            $best = null;
            $span = PHP_INT_MAX;
            $txt  = static fn(mixed $s, int $max): string => Security::clean((string) (preg_replace('/\s+/u', ' ', is_scalar($s) ? (string) $s : '') ?? ''), $max);
            $good = 0;
            foreach (Settings::getArray('festival_dates', []) as $f) {
                if (!is_array($f) || $good >= 30) {
                    continue;
                }
                $fs = is_string($f['from'] ?? null) ? trim($f['from']) : '';
                $ts = is_string($f['to'] ?? null) ? trim($f['to']) : '';
                $a  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fs) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $fs, $ist) : false;
                $b  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ts) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $ts, $ist) : false;
                if ($a === false || $b === false || $a->format('Y-m-d') !== $fs || $b->format('Y-m-d') !== $ts || $b < $a) {
                    continue;
                }
                $d   = (int) $a->diff($b)->days;
                $acc = is_string($f['accent'] ?? null) ? $f['accent'] : '';
                $ne  = $txt($f['banner_ne'] ?? '', 120);
                $en  = $txt($f['banner_en'] ?? '', 120);
                if ($d > 14 || mb_strlen($txt($f['name'] ?? '', 60)) < 2
                    || ($acc !== '' && !in_array($acc, self::DAYS, true))
                    || ($acc === '' && $ne === '' && $en === '')) {
                    continue;
                }
                $good++;
                if ($fs > $day || $ts < $day) {
                    continue;
                }
                if ($best === null || $d < $span || ($d === $span && $fs >= $best['from'])) {
                    $best = ['from' => $fs, 'to' => $ts, 'accent' => $acc];
                    $span = $d;
                }
            }
            return $best;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** The accent CSS: ds.css's 14 rows as they are, then the theme-independent fill names. */
    public static function css(?string $cssFile = null): string
    {
        $t = self::table($cssFile);
        if ($t === []) {
            return '';
        }
        $css = '';
        foreach ($t as $day => $themes) {
            foreach ($themes as $theme => $tokens) {
                $decl = [];
                foreach ($tokens as $k => $v) {
                    $decl[] = $k . ':' . $v;
                }
                $css .= ':root:root[data-theme="' . $theme . '"][data-accent="' . $day . '"]{' . implode(';', $decl) . "}\n";
            }
        }
        foreach ($t as $day => $themes) {
            $css .= ':root[data-accent="' . $day . '"]{--accent-fill:' . $themes['light']['--royal']
                . ';--accent-fill-strong:' . $themes['light']['--royal-strong'] . "}\n";
        }
        return $css;
    }

    /**
     * The pre-paint accent script + the accent CSS, for a stand-alone page's <head>.
     * Put it right after the page's own theme script. '' when ds.css's table is unreadable.
     */
    public static function accentHead(?string $cssFile = null): string
    {
        $key = $cssFile ?? '';
        if (!isset(self::$head[$key])) {
            $css = self::css($cssFile);
            self::$head[$key] = $css === '' ? '' : self::script() . "\n<style id=\"shg-accent\">\n/* 16 Nov 2026 (P5-4a): the weekly accent - the owner's table in assets/css/ds.css, via includes/themetokens.php */\n" . $css . "</style>\n";
        }
        if (self::$head[$key] === '') {
            return '';
        }
        /* the special date is read per request (the page itself is never cached) */
        $fest = self::festival();
        return str_replace('__SHG_FEST__', Security::jsonForHtml($fest), self::$head[$key]);
    }

    private static function script(): string
    {
        $pal  = Security::jsonForHtml(array_fill_keys(self::PALETTES, 1));
        $days = Security::jsonForHtml(self::DAYS);
        /* The rules of app.template.html's accent script (13 Nov 2026 + P5-8a, C7, X2); the
           counter, the Weekly / Fixed buttons and the 1 s cross-fade are app-only. */
        return <<<HTML
<script>
/* 16 Nov 2026 (P5-4a): the app's weekly accent, before the first paint (includes/themetokens.php). */
(function () {
  var d = document.documentElement, K = {$days}, IST = 19800000, P = {$pal}, F = __SHG_FEST__, timer = 0, pvT = 0;
  function mode() {
    try {
      var a = localStorage.getItem('shg:accent');
      if (a === 'fixed' || a === 'weekly') return a;
      var p = (localStorage.getItem('shg:palette') || '').replace(/"/g, '');
      return (p === 'shg' || Object.prototype.hasOwnProperty.call(P, p)) ? 'fixed' : 'weekly';
    } catch (e) { return 'weekly'; }
  }
  function day(now) { return K[new Date((now || Date.now()) + IST).getUTCDay()]; }
  function preview() {
    try {
      var v = JSON.parse(sessionStorage.getItem('shg:accentPreview') || 'null'), now = Date.now();
      if (v && K.indexOf(v.key) >= 0 && typeof v.until === 'number' && v.until > now && v.until - now <= 300000) return v;
    } catch (e) {}
    return null;
  }
  function fest(now) {
    try {
      if (!F || K.indexOf(F.accent) < 0) return '';
      var t = new Date((now || Date.now()) + IST).toISOString().slice(0, 10);
      return (String(F.from) <= t && t <= String(F.to)) ? F.accent : '';
    } catch (e) { return ''; }
  }
  try {
    var q = /[?&]accent_preview=([^&#]*)/.exec(location.search);
    if (q) {
      if (K.indexOf(q[1]) >= 0) sessionStorage.setItem('shg:accentPreview', JSON.stringify({ key: q[1], until: Date.now() + 300000 }));
      if (history.replaceState) history.replaceState(history.state, '', location.pathname + location.search.replace(/([?&])accent_preview=[^&#]*(&?)/, function (all, a, b) { return b ? a : ''; }) + location.hash);
    }
  } catch (e) {}
  function apply() {
    try {
      var pv = preview(), want = pv ? pv.key : (mode() === 'fixed' ? '' : (fest() || day()));
      if ((d.getAttribute('data-accent') || '') !== want) {
        if (want) d.setAttribute('data-accent', want); else d.removeAttribute('data-accent');
      }
      clearTimeout(pvT);
      if (pv) pvT = setTimeout(apply, pv.until - Date.now() + 50);
    } catch (e) {}
  }
  function arm() {
    clearTimeout(timer);
    timer = setTimeout(function () { apply(); arm(); }, 86400000 - ((Date.now() + IST) % 86400000) + 1000);
  }
  window.shgAccent = { apply: apply, mode: mode, day: day, preview: preview, fest: fest };
  apply(); arm();
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { apply(); arm(); } });
  window.addEventListener('pageshow', function () { apply(); });
})();
</script>
HTML;
    }
}
