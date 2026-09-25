<?php
/**
 * includes/assetbundle.php — serve one script and one stylesheet instead
 * of sixteen, when the office turns it on.
 *
 *  tools/build.mjs concatenates the fourteen scripts and two stylesheets
 *  that app.template.html loads in its head, minifies them and commits the
 *  result as assets/dist/app.min.js and assets/dist/app.min.css together
 *  with a manifest listing every source file and its sha256.
 *
 *  This class does the swap at serve time, and it is deliberately hard to
 *  get wrong:
 *
 *    · the switch bundle_assets_on ships OFF, so nothing changes until the
 *      office turns it on in Admin -> Settings;
 *    · the swap happens only when the tags in the template are EXACTLY the
 *      files the manifest was built from, in the same order. Add a script
 *      to the template, forget to rebuild, and the page keeps loading the
 *      sixteen separate files — it never silently drops the new one;
 *    · the bundle must exist on disk and be non-empty;
 *    · the same ?v= stamp the rest of the tree uses is carried over, so the
 *      service worker's cache-first rule still invalidates on a release.
 *
 *  25 Sep 2026. Measured on the committed build: 1002 KB of JavaScript in
 *  fourteen requests becomes 614 KB in one, and 442 KB of CSS in two
 *  becomes 354 KB in one — before gzip, which nginx adds on top.
 */

declare(strict_types=1);

final class AssetBundle
{
    private const MANIFEST = '/assets/dist/manifest.json';

    /** @var array<string, mixed>|null|false false = looked and found nothing */
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
        if (!is_array($json) || !isset($json['js']['sources'], $json['css']['sources'])) {
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
            preg_match_all('#<script[^>]*\bsrc="/assets/js/([^"?]+)(?:\?v=([^"]*))?"[^>]*></script>#i', $head, $jsM, PREG_SET_ORDER);
            preg_match_all('#<link[^>]*\bhref="/assets/css/([^"?]+)(?:\?v=([^"]*))?"[^>]*>#i', $head, $cssM, PREG_SET_ORDER);

            $wantJs  = array_map(static fn(array $s): string => 'assets/js/' . $s[1], $jsM);
            $wantCss = array_map(static fn(array $s): string => 'assets/css/' . $s[1], $cssM);
            $haveJs  = array_column($man['js']['sources'], 'file');
            $haveCss = array_column($man['css']['sources'], 'file');

            if ($wantJs !== $haveJs || $wantCss !== $haveCss) {
                Logger::warning('Asset bundle is out of date — serving the separate files', [
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

            /* A bundle older than one of its own sources is stale: someone
               edited a script and did not run tools/build.mjs. Serving it
               would quietly ship the OLD code, which is the one failure mode
               that would be invisible from the outside, so the page falls
               back to the separate files and says so in the log. Sixteen
               stat() calls, no hashing — this runs on every page view.
               (tools/build.mjs --check compares hashes in CI as well.) */
            foreach ([...$man['js']['sources'], ...$man['css']['sources']] as $src) {
                $file = ROOT_PATH . '/' . ltrim((string) $src['file'], '/');
                if (is_file($file) && (int) filemtime($file) > $built) {
                    Logger::warning('Asset bundle is older than its sources — serving the separate files. Run node tools/build.mjs', [
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
                '#<script[^>]*\bsrc="/assets/js/[^"]+"[^>]*></script>#i',
                static function () use (&$first, $jsTag): string {
                    if ($first) { $first = false; return $jsTag; }
                    return '';
                },
                $head
            ) ?? $head;
            $firstCss = true;
            $newHead = preg_replace_callback(
                '#<link[^>]*\bhref="/assets/css/[^"]+"[^>]*>#i',
                static function (array $m) use (&$firstCss, $cssTag): string {
                    // journey.css and any other stylesheet the head may add
                    // later are only replaced if they were in the bundle.
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
