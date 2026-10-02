<?php
/**
 * =====================================================================
 *  AgreementText — the agreement POINTS the office can correct from admin.
 *
 *  Owner, 2 Oct 2026: "agreement ma bhayeko kura live customer ma dekhinchha,
 *  tara tyaslai admin panel bata point haru sachauna, edit garna milne
 *  khalko hunu paryo."
 *
 *  The eight /agreements/ pages (Agent + Partner/Pvt Ltd, each in Nepali,
 *  Hindi, English and Gujarati) are static HTML built from
 *  tools/agreements/content.js. Every point in them is one
 *
 *      <div class="cl"><span class="n">2.1</span><div class="t">…</div></div>
 *
 *  and the number ("2.1") is the same in all four languages. This class
 *  reads those points straight from the page files (so the ORIGINAL wording
 *  is always whatever is deployed) and keeps only the office's changes in
 *  `agreement_points`: an edited wording, a hidden point, or a new point
 *  added under a section. assets/js/agreement-live.js fetches the changes
 *  (api/agreement-points.php) and applies them on the page.
 *
 *  The fill-in boxes inside a point (₹ amount, hours, tick boxes, the
 *  "Applies / Does not apply" choice) are the agreement FORM — they are shown
 *  to the editor as {{name}} blanks and must stay in the text exactly once
 *  each, so an edit can change the words around a blank but never lose one.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AgreementText
{
    public const DOCS  = ['agent' => 'Agent agreement', 'partner' => 'Partner (Pvt Ltd) agreement'];
    public const LANGS = ['ne' => 'नेपाली', 'hi' => 'हिन्दी', 'en' => 'English', 'gu' => 'ગુજરાતી'];
    public const MAX_TEXT = 4000;

    /** agreements/agent-agreement-ne.html etc. */
    public static function file(string $doc, string $lang): string
    {
        if (!isset(self::DOCS[$doc]) || !isset(self::LANGS[$lang])) {
            throw new InvalidArgumentException('Unknown agreement or language.');
        }
        return dirname(__DIR__) . '/agreements/' . $doc . '-agreement-' . $lang . '.html';
    }

    /** The public URL of one page. */
    public static function url(string $doc, string $lang): string
    {
        return '/agreements/' . $doc . '-agreement-' . $lang . '.html';
    }

    /**
     * The page as built: sections and their points, in page order.
     *
     * @return array<int, array{no:string, title:string, points:array<int, array{num:string, text:string, keys:array<int,string>}>}>
     */
    public static function original(string $doc, string $lang): array
    {
        static $memo = [];
        $k = $doc . '|' . $lang;
        if (isset($memo[$k])) {
            return $memo[$k];
        }
        $html = @file_get_contents(self::file($doc, $lang));
        if (!is_string($html) || $html === '') {
            return $memo[$k] = [];
        }
        $sections = [];
        // Section heads and points in document order.
        preg_match_all(
            '~<h3 class="sh"><span class="no">([^<]*)</span><span>(.*?)</span></h3>|<div class="cl"><span class="n">([^<]*)</span><div class="t">(.*?)</div></div>~su',
            $html, $m, PREG_SET_ORDER
        );
        $cur = null;
        foreach ($m as $hit) {
            if (($hit[1] ?? '') !== '' || ($hit[2] ?? '') !== '') {
                $sections[] = ['no' => trim($hit[1]), 'title' => self::plain($hit[2]), 'points' => []];
                $cur = count($sections) - 1;
                continue;
            }
            $num = trim((string) ($hit[3] ?? ''));
            if ($num === '') {
                continue;
            }
            if ($cur === null) {
                $sections[] = ['no' => '', 'title' => '', 'points' => []];
                $cur = 0;
            }
            [$text, $keys] = self::editable((string) ($hit[4] ?? ''));
            $sections[$cur]['points'][] = ['num' => $num, 'text' => $text, 'keys' => $keys];
        }
        return $memo[$k] = $sections;
    }

    /** num => point, flat. @return array<string, array{num:string, text:string, keys:array<int,string>, section:string}> */
    public static function points(string $doc, string $lang): array
    {
        $out = [];
        foreach (self::original($doc, $lang) as $sec) {
            foreach ($sec['points'] as $p) {
                $out[$p['num']] = $p + ['section' => $sec['no']];
            }
        }
        return $out;
    }

    /**
     * Inner HTML of one point → [editable text with {{key}} blanks, keys].
     * A blank's key is its input's name, or name=value for a radio choice
     * (the "Applies / Does not apply" pair share one name) — the same rule
     * assets/js/agreement-live.js uses to find the box on the page.
     *
     * @return array{0:string, 1:array<int,string>}
     */
    public static function editable(string $inner): array
    {
        $keys    = [];
        $widgets = [];
        // A <label> that wraps an input is one blank (its words come with it).
        $inner = (string) preg_replace_callback('~<label\b[^>]*>.*?</label>~su', static function (array $mm) use (&$keys, &$widgets): string {
            $key = self::widgetKey($mm[0]);
            if ($key === '') {
                return $mm[0];
            }
            $keys[] = $key;
            $widgets[] = $key;
            return "\x01" . (count($widgets) - 1) . "\x02";
        }, $inner);
        $inner = (string) preg_replace_callback('~<input\b[^>]*>~su', static function (array $mm) use (&$keys, &$widgets): string {
            $key = self::widgetKey($mm[0]);
            if ($key === '') {
                return '';
            }
            $keys[] = $key;
            $widgets[] = $key;
            return "\x01" . (count($widgets) - 1) . "\x02";
        }, $inner);
        $text = self::plain($inner);
        $text = (string) preg_replace_callback("~\x01(\\d+)\x02~", static fn(array $mm): string => '{{' . $widgets[(int) $mm[1]] . '}}', $text);
        return [trim($text), $keys];
    }

    /** name, or name=value for a radio; '' when the element carries no input name. */
    private static function widgetKey(string $html): string
    {
        if (!preg_match('~<input\b[^>]*\bname="([^"]*)"[^>]*>~su', $html, $in)) {
            return '';
        }
        $name = $in[1];
        if (preg_match('~\btype="radio"~', $in[0]) && preg_match('~\bvalue="([^"]*)"~', $in[0], $v)) {
            return $name . '=' . $v[1];
        }
        return $name;
    }

    /** Tags out, entities decoded, runs of spaces folded (line breaks kept). */
    private static function plain(string $html): string
    {
        $s = (string) preg_replace('~<br\s*/?>~i', "\n", $html);
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = (string) preg_replace('~[ \t\x{00A0}]+~u', ' ', $s);
        return trim((string) preg_replace("~ *\n *~", "\n", $s));
    }

    /** The {{…}} blanks in a text, in order. @return array<int,string> */
    public static function tokens(string $text): array
    {
        preg_match_all('~\{\{([^{}]{1,80})\}\}~u', $text, $m);
        return $m[1];
    }

    /* ---------------------------------------------------------------- */

    /** Is the agreement_points table there? (The migration may not have run.) */
    public static function available(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                Database::scalar('SELECT 1 FROM agreement_points LIMIT 1');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** @return array<int, array<string,mixed>> the office's rows for one page */
    public static function changes(string $doc, string $lang): array
    {
        if (!self::available()) {
            return [];
        }
        return Database::fetchAll(
            'SELECT p.*, a.full_name AS by_name, a.username AS by_user FROM agreement_points p
               LEFT JOIN admins a ON a.id = p.updated_by
              WHERE p.doc = :d AND p.lang = :l ORDER BY p.id',
            ['d' => $doc, 'l' => $lang]
        );
    }

    /**
     * What the public page needs: edited / hidden points and added points.
     *
     * @return array{points: array<string, array{text:?string, hidden:bool}>, added: array<int, array{num:string, section:string, text:string}>, v:string}
     */
    public static function publicPayload(string $doc, string $lang): array
    {
        $orig   = self::points($doc, $lang);
        $points = [];
        $added  = [];
        foreach (self::changes($doc, $lang) as $r) {
            $num = (string) $r['num'];
            if ((int) $r['is_added'] === 1) {
                if ((int) $r['hidden'] !== 1 && trim((string) $r['body']) !== '') {
                    $added[] = ['num' => $num, 'section' => (string) $r['section'], 'text' => (string) $r['body']];
                }
                continue;
            }
            if (!isset($orig[$num])) {
                continue;   // a point the page no longer has
            }
            $points[$num] = [
                'text'   => $r['body'] !== null && (string) $r['body'] !== '' ? (string) $r['body'] : null,
                'hidden' => (int) $r['hidden'] === 1,
            ];
        }
        $v = substr(sha1((string) json_encode([$points, $added])), 0, 12);
        return ['points' => $points, 'added' => $added, 'v' => $v];
    }

    /**
     * Check an edited wording against the point's blanks: every blank of the
     * original exactly once, no blank the point does not have.
     */
    public static function validate(string $text, array $keys): ?string
    {
        if (trim($text) === '') {
            return 'The text is empty — tick "Hide" to take a point off the page instead.';
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            return 'Too long (at most ' . self::MAX_TEXT . ' characters).';
        }
        $got = self::tokens($text);
        $want = array_count_values($keys);
        $have = array_count_values($got);
        foreach ($want as $k => $n) {
            if (($have[$k] ?? 0) !== 1) {
                return 'Keep the blank {{' . $k . '}} exactly once (it is a box people fill in on the form).';
            }
        }
        foreach ($have as $k => $n) {
            if (!isset($want[$k])) {
                return 'This point has no blank {{' . $k . '}} — remove it, or type the words without {{ }}.';
            }
        }
        return null;
    }

    /** Text as the editor typed it → stored form (LF, trimmed lines, no control chars). */
    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', $text);
        $text = (string) preg_replace("~[ \t]+\n~", "\n", $text);
        return trim((string) preg_replace("~\n{3,}~", "\n\n", $text));
    }

    /**
     * Save one existing point: an edited wording (null = original) and/or hidden.
     * Back to the original wording and not hidden → the row is removed.
     *
     * @return string|null error, or null when saved / nothing to do
     */
    public static function savePoint(string $doc, string $lang, string $num, ?string $text, bool $hidden, int $adminId): ?string
    {
        $orig = self::points($doc, $lang)[$num] ?? null;
        if ($orig === null) {
            return 'Point ' . $num . ' is not on this page.';
        }
        $body = $text === null ? null : self::clean($text);
        if ($body !== null && $body === $orig['text']) {
            $body = null;
        }
        if ($body !== null && ($err = self::validate($body, $orig['keys'])) !== null) {
            return 'Point ' . $num . ': ' . $err;
        }
        $prev = Database::fetch('SELECT body, hidden FROM agreement_points WHERE doc = :d AND lang = :l AND num = :n AND is_added = 0',
            ['d' => $doc, 'l' => $lang, 'n' => $num]);
        $prevBody   = $prev !== null && $prev['body'] !== null ? (string) $prev['body'] : null;
        $prevHidden = $prev !== null && (int) $prev['hidden'] === 1;
        if ($prevBody === $body && $prevHidden === $hidden) {
            return null;
        }
        if ($body === null && !$hidden) {
            Database::delete('agreement_points', 'doc = :d AND lang = :l AND num = :n AND is_added = 0', ['d' => $doc, 'l' => $lang, 'n' => $num]);
        } else {
            Database::query(
                'INSERT INTO agreement_points (doc, lang, num, section, body, hidden, is_added, updated_by)
                      VALUES (:d, :l, :n, :s, :b, :h, 0, :u)
                 ON DUPLICATE KEY UPDATE body = VALUES(body), hidden = VALUES(hidden), updated_by = VALUES(updated_by)',
                ['d' => $doc, 'l' => $lang, 'n' => $num, 's' => $orig['section'], 'b' => $body, 'h' => $hidden ? 1 : 0, 'u' => $adminId ?: null]
            );
        }
        Logger::audit('agreement.point_edit', 'agreement', $doc . '-' . $lang . ' ' . $num,
            ['text' => $prevBody === null ? '(original)' : mb_substr($prevBody, 0, 400), 'hidden' => $prevHidden],
            ['text' => $body === null ? '(original)' : mb_substr($body, 0, 400), 'hidden' => $hidden],
            'Agreement point ' . $num . ' (' . $doc . ', ' . $lang . ') ' . ($body === null && !$hidden ? 'reset to the original' : 'changed'));
        return null;
    }

    /** Add a new point under a section. @return string|null error */
    public static function addPoint(string $doc, string $lang, string $section, string $num, string $text, int $adminId): ?string
    {
        $num  = trim($num);
        $body = self::clean($text);
        $secs = array_column(self::original($doc, $lang), 'no');
        if (!in_array($section, $secs, true)) {
            return 'Choose a section of this agreement.';
        }
        if (!preg_match('~^[0-9]{1,2}(\.[0-9]{1,2}){1,2}[a-z]?$~', $num)) {
            return 'Number it like ' . $section . '.9 (section, a dot, a number).';
        }
        if (isset(self::points($doc, $lang)[$num]) || Database::exists(
            'SELECT 1 FROM agreement_points WHERE doc = :d AND lang = :l AND num = :n', ['d' => $doc, 'l' => $lang, 'n' => $num]
        )) {
            return 'Point ' . $num . ' already exists — pick the next free number.';
        }
        if ($body === '' || mb_strlen($body) > self::MAX_TEXT) {
            return 'Write the new point (at most ' . self::MAX_TEXT . ' characters).';
        }
        if (self::tokens($body) !== []) {
            return 'A new point cannot have {{ }} blanks — the form only knows the boxes the agreement was built with.';
        }
        Database::insert('agreement_points', [
            'doc' => $doc, 'lang' => $lang, 'num' => $num, 'section' => $section,
            'body' => $body, 'hidden' => 0, 'is_added' => 1, 'updated_by' => $adminId ?: null,
        ]);
        Logger::audit('agreement.point_add', 'agreement', $doc . '-' . $lang . ' ' . $num, null,
            ['text' => mb_substr($body, 0, 400)], 'Agreement point ' . $num . ' added (' . $doc . ', ' . $lang . ')');
        return null;
    }

    /** Edit or remove an added point. */
    public static function saveAdded(string $doc, string $lang, string $num, string $text, bool $remove, int $adminId): ?string
    {
        $row = Database::fetch('SELECT * FROM agreement_points WHERE doc = :d AND lang = :l AND num = :n AND is_added = 1',
            ['d' => $doc, 'l' => $lang, 'n' => $num]);
        if ($row === null) {
            return null;
        }
        if ($remove) {
            Database::delete('agreement_points', 'id = :i', ['i' => (int) $row['id']]);
            Logger::audit('agreement.point_remove', 'agreement', $doc . '-' . $lang . ' ' . $num,
                ['text' => mb_substr((string) $row['body'], 0, 400)], null, 'Added agreement point ' . $num . ' removed');
            return null;
        }
        $body = self::clean($text);
        if ($body === (string) $row['body']) {
            return null;
        }
        if ($body === '' || mb_strlen($body) > self::MAX_TEXT || self::tokens($body) !== []) {
            return 'Point ' . $num . ': write the text (no {{ }} blanks, at most ' . self::MAX_TEXT . ' characters).';
        }
        Database::update('agreement_points', ['body' => $body, 'updated_by' => $adminId ?: null], 'id = :i', ['i' => (int) $row['id']]);
        Logger::audit('agreement.point_edit', 'agreement', $doc . '-' . $lang . ' ' . $num,
            ['text' => mb_substr((string) $row['body'], 0, 400)], ['text' => mb_substr($body, 0, 400)], 'Added agreement point ' . $num . ' changed');
        return null;
    }
}
