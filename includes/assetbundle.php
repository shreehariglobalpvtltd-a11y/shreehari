<?php
/**
 * includes/assetbundle.php — serve one script and one stylesheet instead
 * of twenty-two, when the office turns it on.
 *
 *  tools/build.mjs joins the eighteen scripts and four stylesheets that
 *  app.template.html loads in its head and commits the result as
 *  assets/dist/app.min.js and assets/dist/app.min.css together with a
 *  manifest listing every source file, its size and its sha256.
 *
 *  This class does the swap at serve time, and it is deliberately hard to
 *  get wrong:
 *
 *    · the switch bundle_assets_on ships OFF (absent row = off), so nothing
 *      changes until the office turns it on in Admin -> Settings;
 *    · the swap happens only when the tags in the template are EXACTLY the
 *      files the manifest was built from, in the same order. Add a script
 *      to the template, forget to rebuild, and the page keeps loading the
 *      separate files — it never silently drops the new one;
 *    · a source whose size is not what the manifest recorded, or whose
 *      mtime is well after the built files, means "edited without a
 *      rebuild": the separate files are served and the log says why;
 *    · the bundle must exist on disk and be non-empty;
 *    · the same ?v= stamp the rest of the tree uses is carried over, so the
 *      service worker's cache-first rule still invalidates on a release.
 *
 *  27 Sep 2026. The JavaScript half is a plain join (no minifier is
 *  vendored on this tree), so the byte count is the sources' byte count;
 *  the saving is twenty-one fewer requests and one compression context.
 *  The CSS half is stripped of comments and whitespace. Numbers in
 *  assets/dist/manifest.json and docs/PERF-2026-09-27-bundle.md.
 */

declare(strict_types=1);

final class AssetBundle
{
    private const MANIFEST = '/assets/dist/manifest.json';

    /* The same two patterns tools/build.mjs reads the template with. A
       stylesheet is a <link> that says rel="stylesheet" (wherever in the
       tag) and points at /assets/css/; a script is any <script src=
       "/assets/js/…">. Keep them in step with the build or the guard below
       refuses a bundle the build thought was current. */
    private const JS_TAG  = '#<script[^>]*\bsrc="/assets/js/([^"?]+)(?:\?v=([^"]*))?"[^>]*></script>#i';
    private const CSS_TAG = '#<link(?=[^>]*\brel="stylesheet")[^>]*\bhref="/assets/css/([^"?]+)(?:\?v=([^"]*))?"[^>]*>#i';

    /** @var array<string, mixed>|null|false false = not looked yet */
    private static array|null|false $cache = false;

    /** Tests only: forget the manifest so an edited one is read again. */
    public static function __reset(): void
    {
        self::$cache = false;
    }

    public static function on(): bool
    {
        return Settings::getBool('bundle_assets_on', false);
    }

    /** @return array<string, mixed>|null */
    public static function manifest(): ?array
    {
        if (self::$cache !== false) {
            return self::$cache;
        }
        self::$cache = null;
        $path = ROOT_PATH . self::MANIFEST;
        if (!is_file($path)) {
            return null;
        }
        $json = json_decode((string) @file_get_contents($path), true);
        if (!is_array($json) || !isset($json['js']['sources'], $json['css']['sources'], $json['js']['output'], $json['css']['output'])) {
            return null;
        }
        self::$cache = $json;
        return self::$cache;
    }

    /**
     * Swap the tags, or hand the HTML back untouched. Never throws: a page
     * that cannot be bundled must still be a page.
     */
    public static function apply(string $html): string
    {
        try {
            if (!self::on()) {
                return $html;
            }
            $man = self::manifest();
            if ($man === null) {
                return $html;
            }
            $headEnd = stripos($html, '</head>');
            $head    = $headEnd === false ? $html : substr($html, 0, $headEnd);

            /* What the template actually asks for, in order. */
            preg_match_all(self::JS_TAG, $head, $jsM, PREG_SET_ORDER);
            preg_match_all(self::CSS_TAG, $head, $cssM, PREG_SET_ORDER);
            if ($jsM === [] || $cssM === []) {
                return $html;
            }

            $wantJs  = array_map(static fn(array $s): string => 'assets/js/' . $s[1], $jsM);
            $wantCss = array_map(static fn(array $s): string => 'assets/css/' . $s[1], $cssM);
            $haveJs  = array_column($man['js']['sources'], 'file');
            $haveCss = array_column($man['css']['sources'], 'file');

            if ($wantJs !== $haveJs || $wantCss !== $haveCss) {
                Logger::warning('Asset bundle is out of date — serving the separate files. Run node tools/build.mjs', [
                    'templateJs' => count($wantJs), 'bundleJs' => count($haveJs),
                    'missing'    => array_values(array_diff($wantJs, $haveJs)),
                    'extra'      => array_values(array_diff($haveJs, $wantJs)),
                ]);
                return $html;
            }
            $built = PHP_INT_MAX;
            foreach ([$man['js']['output'], $man['css']['output']] as $out) {
                $file = ROOT_PATH . '/' . ltrim((string) $out, '/');
                if (!is_file($file) || filesize($file) < 1024) {
                    Logger::warning('Asset bundle file is missing or too small', ['file' => (string) $out]);
                    return $html;
                }
                $built = min($built, (int) filemtime($file));
            }

            /* Is the bundle still made of these files? Someone who edits a
               script and forgets to run tools/build.mjs would otherwise ship
               the OLD code — the one failure mode invisible from outside.
               Two cheap signals from the same stat() call:

                 · the size the manifest recorded for that source, which is
                   exact and catches an edit however it was made;
                 · its mtime, but only when it is more than a couple of
                   seconds newer than the built file.

               The tolerance matters. git does not preserve mtimes, so a
               fresh checkout writes all of these within the same instant in
               no particular order, and a strict comparison called a perfectly
               good bundle stale. A real edit is minutes or hours later, never
               one second. Twenty-two stat() calls, no hashing; the sha256 of
               every source is compared by tests/bundle-test.php. */
            foreach ([...$man['js']['sources'], ...$man['css']['sources']] as $src) {
                $file = ROOT_PATH . '/' . ltrim((string) $src['file'], '/');
                if (!is_file($file)) {
                    continue;
                }
                $size = (int) filesize($file);
                $want = (int) ($src['bytes'] ?? $size);
                if ($size !== $want) {
                    Logger::warning('Asset bundle does not match its sources any more — serving the separate files. Run node tools/build.mjs', [
                        'changed' => (string) $src['file'], 'bytes' => $size, 'built with' => $want,
                    ]);
                    return $html;
                }
                if ((int) filemtime($file) > $built + 2) {
                    Logger::warning('A source was edited after the bundle was built — serving the separate files. Run node tools/build.mjs', [
                        'newer' => (string) $src['file'],
                    ]);
                    return $html;
                }
            }

            /* The stamp is taken from the tags being replaced, so there is
               no second copy of it to drift: whatever the template says the
               release is, the bundle URL says too. */
            $stampOf = static function (array $m): string {
                $v = (string) ($m[2] ?? '');
                return $v === '' ? '' : '?v=' . rawurlencode($v);
            };
            $jsTag  = '<script defer src="/' . ltrim((string) $man['js']['output'], '/') . $stampOf($jsM[0]) . '"></script>';
            $cssTag = '<link rel="stylesheet" href="/' . ltrim((string) $man['css']['output'], '/') . $stampOf($cssM[0]) . '">';

            /* The first tag of each run becomes the bundle, the rest go. */
            $first = true;
            $newHead = preg_replace_callback(
                self::JS_TAG,
                static function () use (&$first, $jsTag): string {
                    if ($first) { $first = false; return $jsTag; }
                    return '';
                },
                $head
            ) ?? $head;
            $firstCss = true;
            $newHead = preg_replace_callback(
                self::CSS_TAG,
                static function () use (&$firstCss, $cssTag): string {
                    if ($firstCss) { $firstCss = false; return $cssTag; }
                    return '';
                },
                $newHead
            ) ?? $newHead;

            return $headEnd === false ? $newHead : $newHead . substr($html, $headEnd);
        } catch (Throwable $e) {
            try { Logger::warning('Asset bundle swap failed', ['e' => $e->getMessage()]); } catch (Throwable $x) { /* never fatal */ }
            return $html;
        }
    }
}
