<?php
/**
 * =====================================================================
 *  AgreementPrint — every term of the three agreements, for the A4 form.
 *
 *  Owner, 22 Oct 2026: "2 3 page kati huncha agreement fill garna milos,
 *  tesko tala sabai niyam sart haru ni 3 otai lekhdinu" — under each A4
 *  application form, that kind's own agreement in full, with the blanks
 *  left as lines to fill by hand (or filled from the online application).
 *
 *  The wording is read straight from the deployed pages, so the paper
 *  form always says what the website says:
 *    staff    agreements/staff-agreement.html   (Nepali + English rows)
 *    agent    agreements/agent-agreement-<lang>.html
 *    partner  agreements/partner-agreement-<lang>.html
 *  and for agent / partner the office's own edits from Admin -> Agreements
 *  (AgreementText::publicPayload: hidden, reworded and added points) are
 *  applied exactly as the live page applies them.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AgreementPrint
{
    /** The languages each agreement exists in (the first is the default). */
    public const LANGS = [
        'staff'   => ['ne', 'hi', 'en', 'gu'],   // hi / gu: includes/staffagreement-i18n.php (23 Oct 2026)
        'agent'   => ['ne', 'hi', 'en', 'gu'],
        'partner' => ['ne', 'hi', 'en', 'gu'],
    ];
    public const LANG_LABELS = ['ne' => 'नेपाली', 'hi' => 'हिन्दी', 'en' => 'English', 'gu' => 'ગુજરાતી'];

    private const FILL = "\x01F\x02";
    private const BOX  = "\x01B\x02";
    private const BR   = "\x01N\x02";

    /** A language this agreement has; anything else falls back to Nepali. */
    public static function lang(string $kind, string $want): string
    {
        $ok = self::LANGS[$kind] ?? ['ne'];
        return in_array($want, $ok, true) ? $want : $ok[0];
    }

    /**
     * The agreement in page order.
     *
     * @return list<array{no:string, title:string, points:list<array{num:string, html:string}>}>
     */
    public static function sections(string $kind, string $lang): array
    {
        $lang = self::lang($kind, $lang);
        try {
            return $kind === 'staff' ? self::staff($lang) : self::agentLike($kind, $lang);
        } catch (Throwable $e) {
            Logger::warning('AgreementPrint: ' . $kind . '/' . $lang . ' not read: ' . $e->getMessage());
            return [];
        }
    }

    /** agent / partner: <h3 class="sh"> sections and <div class="cl"> points, plus the office's edits. */
    private static function agentLike(string $doc, string $lang): array
    {
        require_once __DIR__ . '/agreementtext.php';
        $html = @file_get_contents(AgreementText::file($doc, $lang));
        if (!is_string($html) || $html === '') {
            return [];
        }
        $pay = ['points' => [], 'added' => []];
        try {
            $pay = AgreementText::publicPayload($doc, $lang);
        } catch (Throwable $e) {
            // no agreement_points table: the page as built
        }
        preg_match_all(
            '~<h3 class="sh"><span class="no">([^<]*)</span><span>(.*?)</span></h3>|<div class="cl"><span class="n">([^<]*)</span><div class="t">(.*?)</div></div>~su',
            $html, $m, PREG_SET_ORDER
        );
        $out = [];
        $cur = -1;
        foreach ($m as $hit) {
            if (($hit[1] ?? '') !== '' || ($hit[2] ?? '') !== '') {
                $out[] = ['no' => trim($hit[1]), 'title' => self::text($hit[2]), 'points' => []];
                $cur = count($out) - 1;
                continue;
            }
            $num = trim((string) ($hit[3] ?? ''));
            if ($num === '') {
                continue;
            }
            if ($cur < 0) {
                $out[] = ['no' => '', 'title' => '', 'points' => []];
                $cur = 0;
            }
            $ch = $pay['points'][$num] ?? null;
            if (is_array($ch) && !empty($ch['hidden'])) {
                continue;
            }
            $out[$cur]['points'][] = ['num' => $num, 'html' => is_array($ch) && is_string($ch['text'] ?? null)
                ? self::fromTemplate((string) $ch['text']) : self::fromHtml((string) ($hit[4] ?? ''))];
        }
        foreach ((array) ($pay['added'] ?? []) as $a) {
            for ($i = 0, $n = count($out); $i < $n; $i++) {
                if ($out[$i]['no'] === (string) ($a['section'] ?? '')) {
                    $out[$i]['points'][] = ['num' => (string) $a['num'], 'html' => self::fromTemplate((string) $a['text'])];
                    break;
                }
            }
        }
        return $out;
    }

    /** staff: <section class="card" id="cN"> with Nepali / English rows side by side. */
    private static function staff(string $lang): array
    {
        $html = @file_get_contents(dirname(__DIR__) . '/agreements/staff-agreement.html');
        if (!is_string($html) || $html === '') {
            return [];
        }
        preg_match_all('~<section class="card" id="c(\d+)"[^>]*>(.*?)</section>~su', $html, $secs, PREG_SET_ORDER);
        $tr = [];   // Hindi / Gujarati: the page's English structure, the words from the translation file
        if ($lang === 'hi' || $lang === 'gu') {
            $all = require __DIR__ . '/staffagreement-i18n.php';
            $tr  = is_array($all[$lang] ?? null) ? $all[$lang] : [];
            $lang = 'en';
        }
        $out = [];
        foreach ($secs as $sec) {
            $no = '';
            $title = '';
            if (preg_match('~<h2[^>]*><i>([^<]*)</i>(.*?)</h2>~su', $sec[2], $h)) {
                $no = preg_match('~^\d+[A-Z]?$~', trim($h[1])) ? trim($h[1]) : '';
                $en = preg_match('~<small>(.*?)</small>~su', $h[2], $sm) ? trim((string) preg_replace('~^\s*·\s*~u', '', self::text($sm[1]))) : '';
                $ne = self::text((string) preg_replace('~<small>.*?</small>~su', '', $h[2]));
                $title = $lang === 'en' && $en !== '' ? $en : $ne;
            }
            preg_match_all('~<div class="row"><div lang="en">(.*?)</div><div lang="ne">(.*?)</div></div>~su', $sec[2], $rows, PREG_SET_ORDER);
            $points = [];
            foreach ($rows as $r) {
                $inner = $lang === 'en' ? $r[1] : $r[2];
                $num = preg_match('~^\s*<b class="n">([^<]*)</b>~u', $inner, $nm) ? trim($nm[1]) : '';
                $inner = (string) preg_replace('~^\s*<b class="n">[^<]*</b>~u', '', $inner);
                $points[] = ['num' => $num, 'html' => self::fromHtml($inner)];
            }
            $t = $tr[(int) $sec[1]] ?? null;
            if (is_array($t)) {
                $title = (string) $t[0] !== '' ? (string) $t[0] : $title;
                foreach ($points as $i => $pt) {
                    if (is_string($t[1][$i] ?? null)) {
                        $points[$i]['html'] = htmlspecialchars($t[1][$i], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
            }
            if ($points !== []) {
                $out[] = ['no' => $no, 'title' => $title, 'points' => $points];
            }
        }
        return $out;
    }

    /**
     * One point of our own page as print HTML: a text box / select becomes a
     * line to write on, a tick box / radio becomes ☐, emphasis is kept and
     * everything else (attributes included) goes.
     */
    public static function fromHtml(string $inner): string
    {
        $s = (string) preg_replace('~<select\b.*?</select>~su', self::FILL, $inner);
        $s = (string) preg_replace_callback('~<input\b[^>]*>~su',
            static fn(array $m): string => preg_match('~\btype="(radio|checkbox)"~', $m[0]) === 1 ? self::BOX . ' ' : self::FILL, $s);
        $s = (string) preg_replace('~<br\s*/?>~i', self::BR, $s);
        $s = strip_tags($s, '<b><strong><em><i>');
        $s = (string) preg_replace('~<(/?)(b|strong|em|i)\b[^>]*>~i', '<$1$2>', $s);
        $s = (string) preg_replace('~[ \t\r\n\x{00A0}]+~u', ' ', $s);
        return trim(strtr($s, [self::FILL => '<span class="fill"></span>', self::BOX => '☐', self::BR => '<br>']));
    }

    /** An office-edited point ({{blank}} text, typed in admin): escaped, blanks as lines / boxes. */
    public static function fromTemplate(string $text): string
    {
        $e = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $e = (string) preg_replace('~\{\{[^{}=]{1,80}=[^{}]{0,80}\}\}~u', '☐', $e);
        $e = (string) preg_replace('~\{\{[^{}]{1,80}\}\}~u', '<span class="fill"></span>', $e);
        return nl2br($e, false);
    }

    private static function text(string $html): string
    {
        $s = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('~\s+~u', ' ', $s));
    }
}
