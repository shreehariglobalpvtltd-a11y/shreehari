<?php
/**
 * 16 Nov 2026 (Master v2, unit Y6, P5-4a) — the app's weekly accent on the
 * stand-alone public pages (track.php, help.php, pay.php, verify-ticket.php).
 *
 *  - ThemeTokens::accentHead() carries all 7 weekday accents, light and dark,
 *    with EXACTLY the owner's hex values from assets/css/ds.css (no colour of
 *    its own), the palette whitelist of app.template.html and the special-date
 *    row index.php would pick; '' when ds.css's table is unreadable;
 *  - the pre-paint script, run in node with a fake page: the IST weekday,
 *    Fixed, a picked palette, Weekly over a palette, the festival accent, the
 *    5-minute preview and its expiry;
 *  - white text on every solid accent and the tint / text pairs the pages use
 *    stay at 4.5:1 or better in light and dark;
 *  - each page includes the snippet in <head>, maps ONLY its own primary
 *    variable(s) under [data-accent], keeps its fallback colours word for word,
 *    and still answers as before over HTTP with no PHP warning (track.php with
 *    a bogus id = 404 "link not valid", help.php = 200, verify-ticket.php = 200,
 *    pay.php with no params = 400 "Invalid PNR", pay.php for a cancelled
 *    booking = 200, pay.php for a pending one = 302 to upi:// with the amount).
 *
 *   SHG_TEST_BASE=http://localhost:8986 php -c <ini> tests/u11-Y6-themes-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/themetokens.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $what" . ($why !== '' ? " — $why" : '') . "\n"; } else { $fail++; echo "  FAIL  $what" . ($why !== '' ? " — $why" : '') . "\n"; }
}

/**
 * A small CSS cascade, enough for these pages' own CSS: rules in source order, specificity, !important,
 * custom properties inherited down an element chain and resolved with var() (+ fallback, cycles = invalid),
 * then one painted colour ('background' or 'border', shorthand or longhand) on the last element.
 * Selectors: compounds of tag / #id / .class / [attr] / [attr="v"] / :root / :first-child / :last-child /
 * :not(compound) / ::before / ::after, joined by descendant or child combinators. Any other pseudo-class
 * (:hover, :has(...)) never matches; '+' / '~' selectors are skipped. @media (prefers-color-scheme: dark|light)
 * and (prefers-reduced-motion: no-preference) are honoured, any other @media / @keyframes / @supports skipped.
 * A node = ['tag', 'cls' => [], 'id', 'attr' => [name => value], 'pe' => ''|'after'|'before', 'root' => bool].
 */
final class Y6Css
{
    /** @var list<array{sel: list<array{0: string, 1: array<string, mixed>}>, spec: int, decls: list<array{0: string, 1: string, 2: bool}>, media: ?string}> */
    public array $rules = [];

    public function __construct(string $css)
    {
        $this->parse((string) preg_replace('~/\*.*?\*/~s', '', $css), null);
    }

    private function parse(string $css, ?string $media): void
    {
        $len = strlen($css);
        $pos = 0;
        while (($open = strpos($css, '{', $pos)) !== false) {
            $prelude = substr($css, $pos, $open - $pos);
            if (($semi = strrpos($prelude, ';')) !== false) { $prelude = substr($prelude, $semi + 1); }   /* @charset / @import statements */
            $prelude = trim($prelude);
            $depth = 1;
            $j = $open + 1;
            while ($j < $len && $depth > 0) {
                if ($css[$j] === '{') { $depth++; } elseif ($css[$j] === '}') { $depth--; }
                $j++;
            }
            $body = substr($css, $open + 1, max(0, $j - $open - 2));
            $pos = $j;
            if ($prelude === '') { continue; }
            if ($prelude[0] === '@') {
                if (preg_match('/^@media\b(.*)$/is', $prelude, $m) === 1) { $this->parse($body, trim($m[1])); }
                continue;
            }
            $decls = [];
            foreach (explode(';', $body) as $d) {
                $c = strpos($d, ':');
                if ($c === false) { continue; }
                $v = trim(substr($d, $c + 1));
                $imp = preg_match('/\s*!important\s*$/i', $v) === 1;
                if ($imp) { $v = trim((string) preg_replace('/\s*!important\s*$/i', '', $v)); }
                $decls[] = [strtolower(trim(substr($d, 0, $c))), $v, $imp];
            }
            foreach (self::splitTop($prelude, ',') as $sel) {
                $p = self::selector(trim($sel));
                if ($p !== null) { $this->rules[] = ['sel' => $p[0], 'spec' => $p[1], 'decls' => $decls, 'media' => $media]; }
            }
        }
    }

    /** @return list<string> */
    private static function splitTop(string $s, string $by): array
    {
        $out = [];
        $cur = '';
        $depth = 0;
        $q = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $ch = $s[$i];
            if ($q !== '') { if ($ch === $q) { $q = ''; } }
            elseif ($ch === '"' || $ch === "'") { $q = $ch; }
            elseif ($ch === '(' || $ch === '[') { $depth++; }
            elseif ($ch === ')' || $ch === ']') { $depth--; }
            elseif ($ch === $by && $depth === 0) { $out[] = $cur; $cur = ''; continue; }
            $cur .= $ch;
        }
        $out[] = $cur;
        return $out;
    }

    /** @return array{0: list<array{0: string, 1: array<string, mixed>}>, 1: int}|null */
    private static function selector(string $s): ?array
    {
        $parts = [];
        $cur = '';
        $comb = '';
        $depth = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $ch = $s[$i];
            if ($ch === '(' || $ch === '[') { $depth++; } elseif ($ch === ')' || $ch === ']') { $depth--; }
            if ($depth === 0 && ($ch === '+' || $ch === '~')) { return null; }
            if ($depth === 0 && ($ch === '>' || ctype_space($ch))) {
                if ($cur !== '') { $parts[] = [$comb, $cur]; $cur = ''; $comb = ' '; }
                if ($ch === '>') { $comb = '>'; }
                continue;
            }
            $cur .= $ch;
        }
        if ($cur !== '') { $parts[] = [$comb, $cur]; }
        if ($parts === []) { return null; }
        $spec = 0;
        foreach ($parts as $k => [$cb, $c]) {
            $o = self::compound($c);
            if ($o === null) { return null; }
            $parts[$k] = [$cb, $o[0]];
            $spec += $o[1];
        }
        return [$parts, $spec];
    }

    /** @return array{0: array<string, mixed>, 1: int}|null */
    private static function compound(string $c): ?array
    {
        $o = ['tag' => '', 'id' => '', 'cls' => [], 'attr' => [], 'pc' => [], 'not' => [], 'pe' => ''];
        $spec = 0;
        $i = 0;
        $n = strlen($c);
        if (preg_match('/^(\*|[a-zA-Z][a-zA-Z0-9-]*)/', $c, $m) === 1) {
            if ($m[1] !== '*') { $o['tag'] = strtolower($m[1]); $spec += 1; }
            $i = strlen($m[1]);
        }
        while ($i < $n) {
            $rest = substr($c, $i);
            if (preg_match('/^::?(before|after)\b/i', $rest, $m) === 1) {
                $o['pe'] = strtolower($m[1]); $spec += 1; $i += strlen($m[0]);
            } elseif (preg_match('/^:not\(((?:[^()]|\([^()]*\))*)\)/i', $rest, $m) === 1) {
                $in = self::compound(trim($m[1]));
                if ($in === null) { return null; }
                $o['not'][] = $in[0]; $spec += $in[1]; $i += strlen($m[0]);
            } elseif (preg_match('/^:([a-z-]+)(\((?:[^()]|\([^()]*\))*\))?/i', $rest, $m) === 1) {
                $o['pc'][] = strtolower($m[1]); $spec += 1000; $i += strlen($m[0]);
            } elseif (preg_match('/^\.([a-zA-Z0-9_-]+)/', $rest, $m) === 1) {
                $o['cls'][] = $m[1]; $spec += 1000; $i += strlen($m[0]);
            } elseif (preg_match('/^#([a-zA-Z0-9_-]+)/', $rest, $m) === 1) {
                $o['id'] = $m[1]; $spec += 1000000; $i += strlen($m[0]);
            } elseif (preg_match('/^\[\s*([a-zA-Z0-9_-]+)\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]+))\s*)?\]/', $rest, $m, PREG_UNMATCHED_AS_NULL) === 1) {
                $o['attr'][] = [strtolower((string) $m[1]), $m[2] ?? $m[3] ?? $m[4] ?? null]; $spec += 1000; $i += strlen((string) $m[0]);
            } else {
                return null;
            }
        }
        return [$o, $spec];
    }

    /** @param array<string, mixed> $o @param array<string, mixed> $node */
    private static function matchCompound(array $o, array $node, bool $inNot = false): bool
    {
        if (!$inNot && $o['pe'] !== ($node['pe'] ?? '')) { return false; }
        if ($o['tag'] !== '' && $o['tag'] !== $node['tag']) { return false; }
        if ($o['id'] !== '' && $o['id'] !== ($node['id'] ?? '')) { return false; }
        foreach ($o['cls'] as $cl) { if (!in_array($cl, $node['cls'] ?? [], true)) { return false; } }
        foreach ($o['attr'] as [$a, $v]) {
            $have = $node['attr'][$a] ?? null;
            if ($have === null || ($v !== null && $have !== $v)) { return false; }
        }
        foreach ($o['pc'] as $pc) {
            $ok = match ($pc) { 'root' => !empty($node['root']), 'first-child' => !empty($node['first']), 'last-child' => !empty($node['last']), default => false };
            if (!$ok) { return false; }
        }
        foreach ($o['not'] as $nt) { if (self::matchCompound($nt, $node, true)) { return false; } }
        return true;
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $parts @param list<array<string, mixed>> $chain */
    private static function matchUp(array $parts, int $k, array $chain, int $idx): bool
    {
        if (!self::matchCompound($parts[$k][1], $chain[$idx])) { return false; }
        if ($k === 0) { return true; }
        if ($parts[$k][0] === '>') { return $idx > 0 && self::matchUp($parts, $k - 1, $chain, $idx - 1); }
        for ($j = $idx - 1; $j >= 0; $j--) {
            if (self::matchUp($parts, $k - 1, $chain, $j)) { return true; }
        }
        return false;
    }

    private static function mediaOk(?string $m, bool $dark): bool
    {
        if ($m === null) { return true; }
        return match (strtolower((string) preg_replace('/\s+/', '', $m))) {
            '(prefers-color-scheme:dark)' => $dark,
            '(prefers-color-scheme:light)' => !$dark,
            '(prefers-reduced-motion:no-preference)' => true,
            default => false,
        };
    }

    /** the element chain a rule is matched against: a ::before / ::after node stands in for its own element */
    private static function sub(array $chain, int $k): array
    {
        $s = array_slice($chain, 0, $k + 1);
        if (($chain[$k]['pe'] ?? '') !== '' && $k > 0) { array_splice($s, $k - 1, 1); }
        return $s;
    }

    /** @return array<string, array{0: list<int>, 1: string}> prop => [cascade key, value] for the last node */
    private function declared(array $chain, bool $dark, callable $want): array
    {
        $win = [];
        $last = count($chain) - 1;
        foreach ($this->rules as $ord => $r) {
            if (!self::mediaOk($r['media'], $dark) || !self::matchUp($r['sel'], count($r['sel']) - 1, $chain, $last)) { continue; }
            foreach ($r['decls'] as $di => [$p, $v, $imp]) {
                if (!$want($p)) { continue; }
                $key = [$imp ? 1 : 0, $r['spec'], $ord, $di];
                if (!isset($win[$p]) || self::later($key, $win[$p][0])) { $win[$p] = [$key, $v]; }
            }
        }
        return $win;
    }

    private static function later(array $a, array $b): bool
    {
        foreach ($a as $i => $x) { if ($x !== $b[$i]) { return $x > $b[$i]; } }
        return true;
    }

    private static function subst(string $v, callable $get): ?string
    {
        for ($guard = 0; ($p = stripos($v, 'var(')) !== false; $guard++) {
            if ($guard > 50) { return null; }
            $depth = 0;
            $end = -1;
            for ($j = $p + 3, $n = strlen($v); $j < $n; $j++) {
                if ($v[$j] === '(') { $depth++; } elseif ($v[$j] === ')' && --$depth === 0) { $end = $j; break; }
            }
            if ($end < 0) { return null; }
            $args = self::splitTop(substr($v, $p + 4, $end - $p - 4), ',');
            $val = $get(trim($args[0]));
            if ($val === null) {
                if (count($args) < 2) { return null; }
                $val = trim(implode(',', array_slice($args, 1)));
            }
            $v = substr($v, 0, $p) . $val . substr($v, $end + 1);
        }
        return trim($v);
    }

    /** computed custom properties of the last node (null = guaranteed-invalid) */
    public function vars(array $chain, bool $dark): array
    {
        $vars = [];
        foreach (array_keys($chain) as $k) {
            $spec = $this->declared(self::sub($chain, $k), $dark, static fn(string $p): bool => str_starts_with($p, '--'));
            $parent = $vars;
            $own = [];
            $resolve = function (string $name, array $stack) use (&$resolve, &$own, $spec, $parent): ?string {
                if (array_key_exists($name, $own)) { return $own[$name]; }
                if (!isset($spec[$name])) { return $parent[$name] ?? null; }
                if (in_array($name, $stack, true)) { return null; }
                return $own[$name] = self::subst($spec[$name][1], static fn(string $n): ?string => $resolve($n, [...$stack, $name]));
            };
            foreach (array_keys($spec) as $name) { $resolve($name, []); }
            $vars = array_merge($parent, $own);
        }
        return $vars;
    }

    /** the colour painted on the last node: $what = 'background' | 'border' (shorthand or longhand), as #RRGGBB when plain */
    public function colour(array $chain, string $what, bool $dark): ?string
    {
        $props = [$what, $what === 'border' ? 'border-color' : 'background-color'];
        $win = $this->declared(self::sub($chain, count($chain) - 1), $dark, static fn(string $p): bool => in_array($p, $props, true));
        if ($win === []) { return null; }
        uasort($win, static fn(array $a, array $b): int => self::later($a[0], $b[0]) ? -1 : 1);
        $prop = (string) array_key_first($win);
        $vars = $this->vars($chain, $dark);
        $v = self::subst($win[$prop][1], static fn(string $n): ?string => $vars[$n] ?? null);
        if ($v === null) { return null; }
        if (!str_contains($prop, '-color')) {
            if (stripos($v, 'gradient(') !== false) { return $v; }
            if (preg_match('/#[0-9a-f]{3,8}\b|rgba?\([^)]*\)|\b(?:transparent|white|black|currentcolor)\b/i', $v, $m) !== 1) { return $v; }
            $v = $m[0];
        }
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $v, $m) === 1) {
            $h = strlen($m[1]) === 3 ? $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] : $m[1];
            return '#' . strtoupper($h);
        }
        return $v;
    }
}

$root  = dirname(__DIR__);
$dsCss = (string) file_get_contents($root . '/assets/css/ds.css');
$tmp   = sys_get_temp_dir() . '/shg_u11_y6_' . getmypid();
@mkdir($tmp);

/* ds.css's own rows, read independently of ThemeTokens: [day][theme] => the exact rule line */
$rows = [];
preg_match_all('/^(:root:root\[data-theme="(light|dark)"\]\[data-accent="([a-z]{3})"\]\{[^}]*\})\s*$/m', $dsCss, $m, PREG_SET_ORDER);
foreach ($m as $r) { $rows[$r[3]][$r[2]] = $r[1]; }
$royal = static function (string $rule, string $tok): string {
    return preg_match('/[{;]' . preg_quote($tok, '/') . ':(#[0-9A-Fa-f]{6})[;}]/', $rule, $mm) === 1 ? strtoupper($mm[1]) : '';
};

echo "\n=== Y6 themes: the weekly accent on the stand-alone pages ===\n\n";

/* ---------------------------------------------------------------- 1. the owner's table */
echo "-- the owner's table (assets/css/ds.css) --\n";
$owner = ['mon' => '#1D4ED8', 'tue' => '#0F766E', 'wed' => '#6D28D9', 'thu' => '#166534', 'fri' => '#4338CA', 'sat' => '#9A3412', 'sun' => '#BE123C'];
check('ds.css has 14 accent rows (7 days x light / dark)', count($m) === 14, (string) count($m));
foreach ($owner as $day => $hex) {
    check("ds.css light $day --royal is the owner's $hex", isset($rows[$day]['light']) && $royal($rows[$day]['light'], '--royal') === $hex);
}
$t = ThemeTokens::table();
check('ThemeTokens::table() reads all 7 days, light and dark', count($t) === 7 && array_keys($t) === ThemeTokens::DAYS);
$same = true; $why = '';
foreach ($rows as $day => $themes) {
    foreach ($themes as $theme => $rule) {
        preg_match_all('/(--[a-z0-9-]+):(#[0-9A-Fa-f]{6})/', $rule, $dm, PREG_SET_ORDER);
        $want = [];
        foreach ($dm as $d) { $want[$d[1]] = strtoupper($d[2]); }
        if (($t[$day][$theme] ?? null) !== $want) { $same = false; $why = "$day/$theme"; }
    }
}
check('every parsed token equals the ds.css value, nothing more, nothing less', $same, $why);

/* ---------------------------------------------------------------- 2. accentHead() */
echo "\n-- ThemeTokens::accentHead() --\n";
$head = ThemeTokens::accentHead();
check('accentHead() is a <script> + <style id="shg-accent">', str_starts_with($head, '<script>') && str_contains($head, '<style id="shg-accent">') && substr_count($head, '</script>') === 1);
foreach (ThemeTokens::DAYS as $day) {
    $both = isset($rows[$day]['light'], $rows[$day]['dark']) && str_contains($head, $rows[$day]['light']) && str_contains($head, $rows[$day]['dark']);
    check("the $day rows are in the head exactly as ds.css has them (light + dark)", $both);
    $fill = ':root[data-accent="' . $day . '"]{--accent-fill:' . $royal($rows[$day]['light'] ?? '', '--royal') . ';--accent-fill-strong:' . $royal($rows[$day]['light'] ?? '', '--royal-strong') . '}';
    check("the $day solid fill is ds.css's light --royal / --royal-strong", str_contains($head, $fill), $fill);
}
preg_match('#<style id="shg-accent">(.*?)</style>#s', $head, $sm);
preg_match_all('/#[0-9A-Fa-f]{6}\b/', (string) ($sm[1] ?? ''), $hx);
$dsHex = [];
foreach ($rows as $themes) { foreach ($themes as $rule) { preg_match_all('/#[0-9A-Fa-f]{6}\b/', $rule, $h2); foreach ($h2[0] as $h) { $dsHex[strtoupper($h)] = true; } } }
$foreign = array_values(array_filter(array_unique(array_map('strtoupper', $hx[0])), static fn(string $h): bool => !isset($dsHex[$h])));
check('no colour in the accent CSS that is not in the owner\'s table', $foreign === [] && count($hx[0]) > 100, implode(',', $foreign));
check('the script stamps data-accent on <html>', str_contains($head, "d.setAttribute('data-accent', want)") && str_contains($head, "d.removeAttribute('data-accent')"));
check('Asia/Kolkata weekday (UTC+5:30, getUTCDay order sun..sat)', str_contains($head, 'IST = 19800000') && str_contains($head, 'K = ["sun","mon","tue","wed","thu","fri","sat"]'));
check("honours shg:accent = fixed / weekly and an explicit shg:palette", str_contains($head, "localStorage.getItem('shg:accent')") && str_contains($head, "localStorage.getItem('shg:palette')") && str_contains($head, "p === 'shg'"));
preg_match('/var P = \{(.*?)\};/s', (string) file_get_contents($root . '/app.template.html'), $pm);
preg_match_all('/([a-z]+):\s*\[/', (string) ($pm[1] ?? ''), $pk);
check('the palette whitelist equals app.template.html window.SHG_PAL_TC', $pk[1] === ThemeTokens::PALETTES && count($pk[1]) === 15, implode(',', $pk[1]));
check('no festival (switch OFF in this DB) = F = null', str_contains($head, 'F = null'));
check('no timer or storage write in production beyond the app\'s own preview rule', substr_count($head, 'setItem(') === 1 && str_contains($head, "sessionStorage.setItem('shg:accentPreview'"));

/* unreadable / incomplete table = nothing at all */
check('a missing ds.css = empty table and accentHead() = \'\'', ThemeTokens::table($tmp . '/nope.css') === [] && ThemeTokens::accentHead($tmp . '/nope.css') === '');
$cut = (string) preg_replace('/^:root:root\[data-theme="dark"\]\[data-accent="wed"\].*$/m', '', $dsCss);
file_put_contents($tmp . '/cut.css', $cut);
check('one row missing = accentHead() = \'\' (never a half table)', ThemeTokens::accentHead($tmp . '/cut.css') === '');
file_put_contents($tmp . '/bad.css', str_replace('--royal:#E49BAD;', '--royal:red;', $dsCss));
check('a non-hex value = accentHead() = \'\'', ThemeTokens::accentHead($tmp . '/bad.css') === '');

/* ---------------------------------------------------------------- 3. festival = index.php's row */
echo "\n-- the special date (festival_theme_on, default OFF) --\n";
$keep = [];
foreach (['festival_theme_on', 'festival_dates'] as $k) { $keep[$k] = Database::fetch('SELECT svalue, stype, sgroup FROM settings WHERE skey = :k', ['k' => $k]); }
$restore = static function () use ($keep): void {
    foreach ($keep as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
    }
    Settings::flush();
};
try {
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    $ago   = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->modify('-3 day')->format('Y-m-d');
    $next  = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->modify('+3 day')->format('Y-m-d');
    Settings::set('festival_dates', [
        ['name' => 'Long one', 'from' => $ago, 'to' => $next, 'accent' => 'wed', 'banner_ne' => '', 'banner_en' => ''],
        ['name' => 'Short one', 'from' => $today, 'to' => $today, 'accent' => 'sun', 'banner_ne' => 'शुभ', 'banner_en' => ''],
        ['name' => 'Bad colour', 'from' => $today, 'to' => $today, 'accent' => 'pink', 'banner_ne' => 'x', 'banner_en' => ''],
        ['name' => 'X', 'from' => $today, 'to' => $today, 'accent' => 'mon', 'banner_ne' => '', 'banner_en' => ''],
    ], 'json', 'app', false);
    Settings::set('festival_theme_on', '0', 'bool', 'app', false);
    Settings::flush();
    check('switch OFF = no special date even with rows for today', ThemeTokens::festival() === null);
    Settings::set('festival_theme_on', '1', 'bool', 'app', false);
    Settings::flush();
    $f = ThemeTokens::festival();
    check('switch ON = the shortest row covering today (same pick as index.php)', ($f['accent'] ?? '') === 'sun' && ($f['from'] ?? '') === $today, json_encode($f));
    check('a bad colour and a one-letter name never win', ($f['accent'] ?? '') !== 'pink' && ($f['accent'] ?? '') !== 'mon');
    check('accentHead() hands the row to the script', str_contains(ThemeTokens::accentHead(), 'F = {"from":"' . $today . '","to":"' . $today . '","accent":"sun"}'));
    Settings::set('festival_dates', [['name' => 'Banner only', 'from' => $today, 'to' => $today, 'accent' => '', 'banner_ne' => 'शुभ', 'banner_en' => '']], 'json', 'app', false);
    Settings::flush();
    check('a banner-only row = accent "" (the script keeps the weekday)', (ThemeTokens::festival()['accent'] ?? 'x') === '');
} finally {
    $restore();
}
$back = true;
foreach ($keep as $k => $row) {
    $now = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
    $back = $back && (($row === null && $now === null) || ($row !== null && $now !== null && $now['svalue'] === $row['svalue']));
}
check('festival settings put back exactly as found', $back);

/* ---------------------------------------------------------------- 4. the script, run */
echo "\n-- the pre-paint script, run in node with a fake page --\n";
$node = trim((string) @shell_exec('node --version 2>&1'));
if (!preg_match('/^v\d+/', $node)) {
    echo "  SKIP  node not found — the script rules were not executed\n";
} else {
    preg_match('#<script>(.*?)</script>#s', ThemeTokens::accentHead(), $js);
    file_put_contents($tmp . '/accent.js', (string) $js[1]);
    $harness = <<<'JS'
const fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');
const cases = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
const out = [];
for (const c of cases) {
  const attrs = {}; const ls = Object.assign({}, c.ls || {}); const ss = Object.assign({}, c.ss || {});
  let url = c.search || '';
  const store = (o) => ({ getItem: (k) => (k in o ? o[k] : null), setItem: (k, v) => { o[k] = String(v); }, removeItem: (k) => { delete o[k]; } });
  const RealDate = Date; const now = c.now;
  class FakeDate extends RealDate { constructor(...a) { super(...(a.length ? a : [now])); } static now() { return now; } }
  const ctx = {
    document: { documentElement: { getAttribute: (k) => (k in attrs ? attrs[k] : null), setAttribute: (k, v) => { attrs[k] = String(v); }, removeAttribute: (k) => { delete attrs[k]; } },
                addEventListener: () => {}, hidden: false },
    window: { addEventListener: () => {} }, localStorage: store(ls), sessionStorage: store(ss),
    location: { get search() { return url; }, pathname: '/help.php', hash: '' },
    history: { state: null, replaceState: (s, t, u) => { url = u.replace('/help.php', ''); } },
    setTimeout: () => 0, clearTimeout: () => {}, Date: FakeDate, JSON, Object, String
  };
  if (c.throwLs) ctx.localStorage = { getItem: () => { throw new Error('blocked'); }, setItem: () => { throw new Error('blocked'); } };
  const fn = new Function('document', 'window', 'localStorage', 'sessionStorage', 'location', 'history', 'setTimeout', 'clearTimeout', 'Date',
    (c.fest ? src.replace('F = null', 'F = ' + JSON.stringify(c.fest)) : src));
  fn(ctx.document, ctx.window, ctx.localStorage, ctx.sessionStorage, ctx.location, ctx.history, ctx.setTimeout, ctx.clearTimeout, FakeDate);
  out.push({ accent: attrs['data-accent'] === undefined ? null : attrs['data-accent'], search: url, ss: ss['shg:accentPreview'] || null, api: typeof ctx.window.shgAccent });
}
console.log(JSON.stringify(out));
JS;
    file_put_contents($tmp . '/harness.js', $harness);
    $utc = static fn(string $s): int => (int) ((new DateTimeImmutable($s, new DateTimeZone('UTC')))->format('U')) * 1000;
    $cases = [
        ['name' => 'Sun 15 Nov 23:59 IST = sun', 'now' => $utc('2026-11-15 18:29:00'), 'want' => 'sun'],
        ['name' => 'Mon 16 Nov 00:01 IST = mon (UTC is still Sunday)', 'now' => $utc('2026-11-15 18:31:00'), 'want' => 'mon'],
        ['name' => 'Tue 17 Nov 12:00 IST = tue', 'now' => $utc('2026-11-17 06:30:00'), 'want' => 'tue'],
        ['name' => 'Wed', 'now' => $utc('2026-11-18 06:30:00'), 'want' => 'wed'],
        ['name' => 'Thu', 'now' => $utc('2026-11-19 06:30:00'), 'want' => 'thu'],
        ['name' => 'Fri', 'now' => $utc('2026-11-20 06:30:00'), 'want' => 'fri'],
        ['name' => 'Sat 21 Nov 23:00 IST = sat', 'now' => $utc('2026-11-21 17:30:00'), 'want' => 'sat'],
        ['name' => 'shg:accent = fixed = no accent', 'now' => $utc('2026-11-17 06:30:00'), 'ls' => ['shg:accent' => 'fixed'], 'want' => null],
        ['name' => 'a picked palette (himalaya) = no accent', 'now' => $utc('2026-11-17 06:30:00'), 'ls' => ['shg:palette' => '"himalaya"'], 'want' => null],
        ['name' => "shg:palette = 'shg' (brand on purpose) = no accent", 'now' => $utc('2026-11-17 06:30:00'), 'ls' => ['shg:palette' => 'shg'], 'want' => null],
        ['name' => 'an old day-palette value is ignored = weekly', 'now' => $utc('2026-11-17 06:30:00'), 'ls' => ['shg:palette' => '3'], 'want' => 'tue'],
        ['name' => 'Weekly chosen over a palette = weekly', 'now' => $utc('2026-11-17 06:30:00'), 'ls' => ['shg:palette' => 'lotus', 'shg:accent' => 'weekly'], 'want' => 'tue'],
        ['name' => 'storage blocked = weekly', 'now' => $utc('2026-11-17 06:30:00'), 'throwLs' => true, 'want' => 'tue'],
        ['name' => 'festival row today = its accent', 'now' => $utc('2026-11-17 06:30:00'), 'fest' => ['from' => '2026-11-17', 'to' => '2026-11-17', 'accent' => 'sun'], 'want' => 'sun'],
        ['name' => 'festival row ended yesterday (IST) = weekday', 'now' => $utc('2026-11-17 18:31:00'), 'fest' => ['from' => '2026-11-17', 'to' => '2026-11-17', 'accent' => 'sun'], 'want' => 'wed'],
        ['name' => 'festival + Fixed = no accent', 'now' => $utc('2026-11-17 06:30:00'), 'ls' => ['shg:accent' => 'fixed'], 'fest' => ['from' => '2026-11-17', 'to' => '2026-11-17', 'accent' => 'sun'], 'want' => null],
        ['name' => 'banner-only festival (accent "") = weekday', 'now' => $utc('2026-11-17 06:30:00'), 'fest' => ['from' => '2026-11-17', 'to' => '2026-11-17', 'accent' => ''], 'want' => 'tue'],
        ['name' => '?accent_preview=sun = sun, saved for this tab, dropped from the address', 'now' => $utc('2026-11-17 06:30:00'), 'search' => '?pnr=X&accent_preview=sun', 'want' => 'sun', 'wantSearch' => '?pnr=X'],
        ['name' => '?accent_preview=pink = ignored (weekday), still dropped', 'now' => $utc('2026-11-17 06:30:00'), 'search' => '?accent_preview=pink', 'want' => 'tue', 'wantSearch' => ''],
        ['name' => 'an expired preview = weekday', 'now' => $utc('2026-11-17 06:30:00'), 'ss' => ['shg:accentPreview' => json_encode(['key' => 'sun', 'until' => $utc('2026-11-17 06:29:00')])], 'want' => 'tue'],
        ['name' => 'a preview longer than 5 minutes = refused', 'now' => $utc('2026-11-17 06:30:00'), 'ss' => ['shg:accentPreview' => json_encode(['key' => 'sun', 'until' => $utc('2026-11-17 07:30:00')])], 'want' => 'tue'],
    ];
    file_put_contents($tmp . '/cases.json', (string) json_encode($cases));
    $res = json_decode((string) shell_exec('node ' . escapeshellarg($tmp . '/harness.js') . ' ' . escapeshellarg($tmp . '/accent.js') . ' ' . escapeshellarg($tmp . '/cases.json') . ' 2>&1'), true);
    check('the script runs in node', is_array($res) && count($res) === count($cases), is_array($res) ? '' : 'no JSON');
    foreach ($cases as $i => $c) {
        $r = $res[$i] ?? [];
        $ok = array_key_exists('accent', $r) && $r['accent'] === $c['want'] && ($r['api'] ?? '') === 'object';
        if (isset($c['wantSearch'])) { $ok = $ok && ($r['search'] ?? null) === $c['wantSearch']; }
        check($c['name'], $ok, json_encode($r));
    }
    $chk = trim((string) shell_exec('node --check ' . escapeshellarg($tmp . '/accent.js') . ' 2>&1'));
    check('node --check on the snippet', $chk === '', $chk);
}

/* ---------------------------------------------------------------- 5. contrast */
echo "\n-- contrast (WCAG, 4.5:1 for text) --\n";
$lum = static function (string $hex): float {
    $c = array_map(static fn(string $h): float => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
    $c = array_map(static fn(float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$ratio = static function (string $a, string $b) use ($lum): float { $x = $lum($a); $y = $lum($b); return (max($x, $y) + 0.05) / (min($x, $y) + 0.05); };
$worst = [];
foreach ($t as $day => $th) {
    $pairs = [
        'white on fill (help / pay / verify CTA, track buttons)'    => ['#FFFFFF', $th['light']['--royal']],
        'white on fill-strong (track dark hero / buttons)'           => ['#FFFFFF', $th['light']['--royal-strong']],
        'light --royal text on track --soft #EAF0FB'                 => [$th['light']['--royal'], '#EAF0FB'],
        'light --royal link on verify --bg #F5F6FA'                  => [$th['light']['--royal'], '#F5F6FA'],
        'dark --royal text on track --soft #1B2A47'                  => [$th['dark']['--royal'], '#1B2A47'],
        'dark --royal text on track --card #141E33'                  => [$th['dark']['--royal'], '#141E33'],
        'dark --royal link on verify --bg #0B1220'                   => [$th['dark']['--royal'], '#0B1220'],
        'dark --royal on verify --card #131D30'                      => [$th['dark']['--royal'], '#131D30'],
        'dark --royal on AMOLED black (track --pri)'                 => [$th['dark']['--royal'], '#000000'],
    ];
    foreach ($pairs as $label => [$fg, $bg]) {
        $r = $ratio($fg, $bg);
        if (!isset($worst[$label]) || $r < $worst[$label][0]) { $worst[$label] = [$r, $day]; }
    }
}
foreach ($worst as $label => [$r, $day]) {
    check($label . ' >= 4.5', $r >= 4.5, sprintf('lowest %.2f (%s)', $r, $day));
}

/* ---------------------------------------------------------------- 6. the four pages (source) */
echo "\n-- the four pages: only their own primary colour, fallback unchanged --\n";
$src = [];
foreach (['track.php', 'help.php', 'pay.php', 'verify-ticket.php'] as $p) { $src[$p] = (string) file_get_contents($root . '/' . $p); }
foreach ($src as $p => $s) {
    check("$p loads includes/themetokens.php and prints accentHead() once", str_contains($s, "INCLUDE_PATH . '/themetokens.php'") && substr_count($s, 'ThemeTokens::accentHead()') === 1);
    /* every rule that reads an accent token is scoped to [data-accent] */
    preg_match_all('/([^{}]*)\{[^{}]*var\(--(?:royal|accent-fill)[a-z-]*\)[^{}]*\}/', $s, $rm);
    /* pay.php reads no accent token at all: its pay button stays green every day */
    $scoped = $p === 'pay.php' ? $rm[1] === [] : ($rm[1] !== [] && array_filter($rm[1], static fn(string $sel): bool => !str_contains($sel, '[data-accent]')) === []);
    check("$p: every accent mapping sits under [data-accent] (no accent = old colours)", $scoped, (string) count($rm[1]) . ' rule(s)');
}
check('track.php fallback colours word for word', str_contains($src['track.php'], ':root{--pri:#1F4FA3;--orange:#F07C1F;--ink:#14213D;--muted:#56657F;--line:#E2E9F4;--bg:#F2F5FB;--card:#fff;--soft:#EAF0FB;--h1:#0F2550;--h2:#1F4FA3;--h3:#3D7BE0}')
    && str_contains($src['track.php'], ':root[data-theme="dark"]{--pri:#8DB4FF;--ink:#EEF3FB;--muted:#A6B4CB;--line:#24324F;--bg:#0B1220;--card:#141E33;--soft:#1B2A47;--h1:#0B1A38;--h2:#173C82;--h3:#2559B8;color-scheme:dark}')
    && str_contains($src['track.php'], ':root[data-display="amoled"]{--bg:#000;--card:#0A0E15;--line:#1E2A3C;--soft:#121B2B;--h1:#000;--h2:#06122A;--h3:#0E2A5C}'));
check('track.php maps only --pri / --h2 / --h3 (navy --h1 and the rest never)', (bool) preg_match_all('/\[data-accent\][^{]*\{([^}]*)\}/', $src['track.php'], $tm)
    && array_filter($tm[1], static fn(string $b): bool => preg_match('/--(?!pri:|h2:|h3:)[a-z0-9-]+:/', $b) === 1) === []);
check('track.php AMOLED keeps its near-black hero', str_contains($src['track.php'], ':root[data-theme="dark"][data-accent]:not([data-display="amoled"]){--h2:var(--accent-fill-strong);--h3:var(--accent-fill)}'));
check('help.php fallback --blue word for word', str_contains($src['help.php'], ':root{--blue:#2E5FA8;--navy:#12264E;') && str_contains($src['help.php'], '--chip:#1A2638;--blue:#28579C}'));
check('help.php maps only --blue', str_contains($src['help.php'], ':root[data-theme="light"][data-accent]{--blue:var(--royal)}') && str_contains($src['help.php'], ':root[data-theme="dark"][data-accent]{--blue:var(--accent-fill)}'));
check('verify-ticket.php fallback --link / --cta word for word', str_contains($src['verify-ticket.php'], '--foot: #4b5b70; --link: #1A3A6A; --cta: #1A3A6A;') && str_contains($src['verify-ticket.php'], '--foot: #a9b8cc; --link: #9cc2ff; --cta: #2b5aa8;'));
check('verify-ticket.php maps only --link / --cta (status colours never)', str_contains($src['verify-ticket.php'], ':root[data-theme="light"][data-accent] { --link: var(--royal); --cta: var(--royal); }')
    && str_contains($src['verify-ticket.php'], ':root[data-theme="dark"][data-accent] { --link: var(--royal); --cta: var(--accent-fill); }'));
check('pay.php fallback green button word for word (both cards)', substr_count($src['pay.php'], '.btn { display:block; text-align:center; background:#0a7c2f; color:#fff;') === 2 && substr_count($src['pay.php'], '.amt { font-size:32px; font-weight:700; color:#0a7c2f;') === 1);
check('pay.php keeps the UPI pay button green on every day (no accent rule on .btn), WhatsApp button keeps its inline colour', !str_contains($src['pay.php'], '[data-accent] .btn')
    && substr_count($src['pay.php'], 'style="background:#128C7E"') === 2);
$payLogic = ['$due     = round(max(0, $total - $paid), 2);', "PayEvents::log((int) (\$booking['id'] ?? 0), \$pnr, 'pay_open');", "header('Location: ' . \$upiUri, true, 302);", "exit('Invalid PNR');"];
check('pay.php payment logic untouched', array_filter($payLogic, static fn(string $l): bool => !str_contains($src['pay.php'], $l)) === []);

/* ---------------------------------------------------------------- 6b. status colours never take the accent */
/* Fix round (16 Nov 2026): the accent moved shared page variables that ALSO paint status - track.php's
   'your stop' pill (.r em = --h2) and upcoming-stop rings (--pri) turned Saturday orange next to the orange
   'Next' pill / rings, and help.php's 'in progress' dot (--blue) turned Thursday green (= resolved) and
   Saturday orange (~ open). Y6Css (below) resolves the pages' real CSS - accentHead() + the page <style> +
   its linked stylesheets, in document order - for every day x light / dark / AMOLED and checks the status
   colour against the no-accent page and against its neighbour states. */
echo "\n-- status colours never take the accent (track rail, help status dot) --\n";
$pageCss = static function (string $html) use ($root): string {
    preg_match_all('~<style\b[^>]*>(.*?)</style>|<link\b[^>]*\brel="stylesheet"[^>]*\bhref="(/[^"?]+)~is', $html, $mm, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
    $css = '';
    foreach ($mm as $x) {
        $css .= ($x[2] ?? null) !== null ? (string) @file_get_contents($root . $x[2]) . "\n" : (string) $x[1] . "\n";
    }
    return $css;
};
$srcCss = static function (string $s) use ($pageCss): string {
    return $pageCss(str_replace('<?= ThemeTokens::accentHead() ?>', ThemeTokens::accentHead(), $s));
};
$node = static fn(string $tag, array $cls = [], string $id = '', string $pe = ''): array => ['tag' => $tag, 'cls' => $cls, 'id' => $id, 'attr' => [], 'pe' => $pe];
$htmlNode = static function (string $theme, ?string $acc): array {
    $a = ['data-theme' => $theme === 'light' ? 'light' : 'dark'];
    if ($theme === 'amoled') { $a['data-display'] = 'amoled'; }
    if ($acc !== null) { $a['data-accent'] = $acc; }
    return ['tag' => 'html', 'cls' => [], 'id' => '', 'attr' => $a, 'pe' => '', 'root' => true];
};
$lab = static function (string $hex): array {
    $c = array_map(static fn(string $h): float => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
    $c = array_map(static fn(float $v): float => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    $xyz = [(0.4124 * $c[0] + 0.3576 * $c[1] + 0.1805 * $c[2]) / 0.95047, 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2], (0.0193 * $c[0] + 0.1192 * $c[1] + 0.9505 * $c[2]) / 1.08883];
    $f = array_map(static fn(float $t): float => $t > 0.008856 ? $t ** (1 / 3) : 7.787 * $t + 16 / 116, $xyz);
    return [116 * $f[1] - 16, 500 * ($f[0] - $f[1]), 200 * ($f[1] - $f[2])];
};
$dE = static function (?string $a, ?string $b) use ($lab): float {
    if ($a === null || $b === null || preg_match('/^#[0-9A-F]{6}$/', $a . '') !== 1 || preg_match('/^#[0-9A-F]{6}$/', $b . '') !== 1) { return 0.0; }
    [$x, $y] = [$lab($a), $lab($b)];
    return sqrt(($x[0] - $y[0]) ** 2 + ($x[1] - $y[1]) ** 2 + ($x[2] - $y[2]) ** 2);
};
const Y6_MIN_DE = 40.0;   /* CIE76: Saturday's bug pair #9A3412 / #C2410C is ~13; the page's blue vs orange is 70+ */
$themes = ['light' => false, 'dark' => true, 'amoled' => true];   /* theme => prefers-color-scheme: dark */
$days = array_merge([null], ThemeTokens::DAYS);

/* track.php: html > body > .wrap > section.card#jr > ol.rail#rail > li.r[.nx|.ps] > (div > em | i::after) */
$trackProbe = static function (Y6Css $css, string $theme, ?string $acc) use ($node, $htmlNode, $themes): array {
    $top = [$htmlNode($theme, $acc), $node('body'), $node('div', ['wrap']), $node('section', ['card'], 'jr'), $node('ol', ['rail'], 'rail')];
    $dk = $themes[$theme];
    $ring = static fn(array $li) => $css->colour([...$top, $li, $node('i'), $node('i', [], '', 'after')], 'border', $dk);
    return [
        'mine'  => $css->colour([...$top, $node('li', ['r']), $node('div'), $node('em')], 'background', $dk),
        'next'  => $css->colour([...$top, $node('li', ['r', 'nx']), $node('div'), $node('em', ['n'])], 'background', $dk),
        'ring'  => $ring($node('li', ['r'])),
        'nxRing' => $ring($node('li', ['r', 'nx'])),
        'psRing' => $ring($node('li', ['r', 'ps'])),
        'btn'   => $css->colour([$htmlNode($theme, $acc), $node('body'), $node('div', ['wrap']), $node('section', ['card'], 'mapCard'), $node('button', ['btn', 'blue'], 'mapBtn')], 'background', $dk),
    ];
};
/* help.php: html > body > .wrap > section#pCheck > div.card#vCard > div.st > span.dot[.in_progress|.resolved] */
$helpProbe = static function (Y6Css $css, string $theme, ?string $acc) use ($node, $htmlNode, $themes): array {
    $top = [$htmlNode($theme, $acc), $node('body'), $node('div', ['wrap']), $node('section', [], 'pCheck'), $node('div', ['card'], 'vCard'), $node('div', ['st'])];
    $dk = $themes[$theme];
    return [
        'open'     => $css->colour([...$top, $node('span', ['dot'], 'vDot')], 'background', $dk),
        'prog'     => $css->colour([...$top, $node('span', ['dot', 'in_progress'], 'vDot')], 'background', $dk),
        'resolved' => $css->colour([...$top, $node('span', ['dot', 'resolved'], 'vDot')], 'background', $dk),
        'btn'      => $css->colour([$htmlNode($theme, $acc), $node('body'), $node('div', ['wrap']), $node('section', [], 'pCheck'), $node('form', ['card'], 'fCheck'), $node('button', ['btn', 'blue'], 'bCheck')], 'background', $dk),
    ];
};
/** every day x theme: status = the no-accent colour, far from its neighbours; the brand button does follow the accent */
$statusChecks = static function (string $page, Y6Css $css, callable $probe, array $status, array $brand) use ($themes, $days, $dE): void {
    foreach (array_keys($themes) as $theme) {
        $base = $probe($css, $theme, null);
        $moved = []; $close = []; $brandBad = [];
        foreach ($days as $day) {
            $p = $probe($css, $theme, $day);
            foreach ($status as $key => $neighbours) {
                if ($p[$key] === null || $p[$key] !== $base[$key]) { $moved[] = ($day ?? 'none') . " $key=" . ($p[$key] ?? 'null') . ' (was ' . ($base[$key] ?? 'null') . ')'; }
                foreach ($neighbours as $nb) {
                    $d = $dE($p[$key], $p[$nb]);
                    if ($d < Y6_MIN_DE) { $close[] = ($day ?? 'none') . sprintf(" $key %s ~ $nb %s (dE %.1f)", $p[$key] ?? 'null', $p[$nb] ?? 'null', $d); }
                }
            }
            if ($day !== null && isset($brand[$theme])) {
                $want = ($brand[$theme])($day);
                if ($p['btn'] !== $want) { $brandBad[] = "$day btn=" . ($p['btn'] ?? 'null') . " want $want"; }
            }
        }
        $keys = implode(' / ', array_keys($status));
        check("$page $theme: $keys keep the no-accent colour on all 7 days (" . implode(', ', array_map(static fn($k) => $base[$k] ?? 'null', array_keys($status))) . ')', $moved === [] && !in_array(null, array_intersect_key($base, $status), true), implode('; ', array_slice($moved, 0, 4)));
        check("$page $theme: $keys stay clearly apart from their neighbour states (CIE76 dE >= " . Y6_MIN_DE . ')', $close === [], implode('; ', array_slice($close, 0, 4)));
        if (isset($brand[$theme])) {
            check("$page $theme: the brand button still follows the day's accent (resolver sanity)", $brandBad === [], implode('; ', array_slice($brandBad, 0, 3)));
        }
    }
};
$trackStatus = ['mine' => ['next', 'nxRing'], 'ring' => ['nxRing', 'psRing', 'next']];
$helpStatus  = ['prog' => ['open', 'resolved']];
$fill = static fn(string $tok): callable => static fn(string $day): string => strtoupper($t[$day]['light'][$tok]);
$trackBrand = ['light' => $fill('--royal'), 'dark' => $fill('--royal-strong')];   /* AMOLED keeps its near-black --h2 */
$helpBrand  = ['light' => $fill('--royal'), 'dark' => $fill('--royal'), 'amoled' => $fill('--royal')];

$trackCss = new Y6Css($srcCss($src['track.php']));
$helpCss  = new Y6Css($srcCss($src['help.php']));
check('the resolver reads track.php + accentHead() + track-live.css, and help.php + accentHead()', count($trackCss->rules) > 80 && count($helpCss->rules) > 60, count($trackCss->rules) . ' / ' . count($helpCss->rules) . ' rules');
$statusChecks('track.php', $trackCss, $trackProbe, $trackStatus, $trackBrand);
$statusChecks('help.php', $helpCss, $helpProbe, $helpStatus, $helpBrand);

/* the check is sharp: put the reviewed (pre-fix) rules back and it sees both bugs */
$oldTrack = new Y6Css(str_replace('.rail{--pri:var(--rail-ring);--h2:var(--rail-mine)}', '', $srcCss($src['track.php'])));
$oldHelp  = new Y6Css(str_replace('.dot.in_progress{background:var(--prog)}', '.dot.in_progress{background:var(--blue)}', $srcCss($src['help.php'])));
$o = $trackProbe($oldTrack, 'light', 'sat');
check('the check catches the old track.php bug (Saturday pill = #9A3412 next to Next #C2410C)', $o['mine'] === '#9A3412' && $o['next'] === '#C2410C' && $dE($o['mine'], $o['next']) < Y6_MIN_DE, json_encode($o));
$o = $helpProbe($oldHelp, 'light', 'thu');
$o2 = $helpProbe($oldHelp, 'dark', 'sat');
check('the check catches the old help.php bug (Thursday in-progress green ~ resolved, Saturday ~ open)', $o['prog'] === '#166534' && $dE($o['prog'], $o['resolved']) < Y6_MIN_DE && $dE($o2['prog'], $o2['open']) < Y6_MIN_DE, json_encode([$o, $o2]));

/* ---------------------------------------------------------------- 7. over HTTP */
echo "\n-- the four pages over HTTP --\n";
$base = rtrim((string) (getenv('SHG_TEST_BASE') ?: ''), '/');
if ($base === '' || !function_exists('curl_init')) {
    echo "  SKIP  no SHG_TEST_BASE (or no curl) — start a dev server and pass SHG_TEST_BASE=http://localhost:<port>\n";
} else {
    $http = static function (string $url) use ($base): array {
        $ch = curl_init($base . $url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hl = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'body' => substr($raw, $hl), 'head' => substr($raw, 0, $hl)];
    };
    $noWarn = static fn(string $b): bool => preg_match('/(<b>)?(Warning|Notice|Deprecated|Fatal error|Uncaught)(<\/b>)?:/', $b) !== 1;
    $inHead = static function (string $b): bool {
        $h = strpos($b, '</head>'); $s = strpos($b, '<style id="shg-accent">'); $j = strpos($b, "d.setAttribute('data-accent', want)");
        return $h !== false && $s !== false && $j !== false && $s < $h && $j < $h;
    };
    try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1')"); } catch (Throwable $e) {}

    $r = $http('/track.php?pnr=SHG-0000-BOGUS&k=nope');
    check('track.php bogus id: 404 "link not valid" page as before', $r['code'] === 404 && str_contains($r['body'], '🔒'), (string) $r['code']);
    check('track.php: snippet in <head>, no PHP warning', $inHead($r['body']) && $noWarn($r['body']));
    check('track.php: the page maps its blue under [data-accent]', str_contains($r['body'], ':root[data-theme="light"][data-accent]{--pri:var(--royal);--h2:var(--royal);--h3:var(--royal)}'));
    foreach (['/help.php', '/help.php?lang=en', '/help.php?lang=hi&ref=HD-0001'] as $u) {
        $r = $http($u);
        check("$u: 200, snippet in <head>, no PHP warning", $r['code'] === 200 && $inHead($r['body']) && $noWarn($r['body']), (string) $r['code']);
    }
    $r = $http('/verify-ticket.php');
    check('verify-ticket.php (no params): 200, snippet in <head>, no PHP warning', $r['code'] === 200 && $inHead($r['body']) && $noWarn($r['body']), (string) $r['code']);
    $r = $http('/verify-ticket.php?pnr=SHG-0000-BOGUS&k=0000000000');
    check('verify-ticket.php forged key: still answers with the snippet', in_array($r['code'], [200, 404], true) && $inHead($r['body']) && $noWarn($r['body']), (string) $r['code']);
    $r = $http('/pay.php');
    check('pay.php (no params): 400 "Invalid PNR" exactly as before', $r['code'] === 400 && trim($r['body']) === 'Invalid PNR', (string) $r['code']);

    /* two bookings of our own: one cancelled (nothing to pay), one pending (pay redirect) */
    $pnrs = ['Y6T-2099-90001' => 'cancelled', 'Y6T-2099-90002' => 'pending'];
    $clean = static function () use ($pnrs): void {
        foreach (array_keys($pnrs) as $p) {
            foreach (Database::fetchAll('SELECT id FROM bookings WHERE pnr = :p', ['p' => $p]) as $b) {
                try { Database::run('DELETE FROM payment_events WHERE booking_id = :b', ['b' => (int) $b['id']]); } catch (Throwable $e) {}
                Database::delete('bookings', 'id = :i', ['i' => (int) $b['id']]);
            }
        }
    };
    $clean();
    try {
        foreach ($pnrs as $p => $st) {
            Database::insert('bookings', ['pnr' => $p, 'contact_phone' => '9100000986', 'status' => $st, 'total_amount' => 2200, 'is_cod' => 0]);
        }
        $r = $http('/pay.php?pnr=Y6T-2099-90001');
        check('pay.php cancelled booking: 200 "nothing to pay" card, snippet in <head>, no warning', $r['code'] === 200 && str_contains($r['body'], 'No payment is due') && $inHead($r['body']) && $noWarn($r['body']), (string) $r['code']);
        check('pay.php: the pay button is never re-coloured by the accent', !str_contains($r['body'], '[data-accent] .btn'));
        $r = $http('/pay.php?pnr=Y6T-2099-90002');
        check('pay.php pending booking: 302 to upi:// with the amount due, as before', $r['code'] === 302 && preg_match('/^Location: upi:\/\/pay\?.*am=2200(\.00)?\b/mi', $r['head']) === 1, (string) $r['code']);
        check('pay.php pay card: snippet in <head>, amount shown, no warning', $inHead($r['body']) && str_contains($r['body'], '₹2,200.00') && $noWarn($r['body']));
    } finally {
        $clean();
    }
    check('test bookings removed', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE pnr LIKE 'Y6T-2099-9%'", [], 0) === 0);
}

foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmp);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
