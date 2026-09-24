<?php
/**
 * =====================================================================
 *  PersonName — "is this a person's name?" (24 Sep 2026).
 *
 *  Owner ask: "naam ra mobile number ma mistake nahos" — the name and
 *  the mobile on a ticket must be right. At the border a wrong name is a
 *  stopped passenger, and the register already carries names like
 *  "Mehsana", "ho", "2 seat" and "9876543210" that a hurried chat put on
 *  a berth. Every WhatsApp path that takes a name — the assistant's
 *  issue_ticket / staff_sell, the bulk list, the local booking engine —
 *  now asks this one class the same question, so the rule cannot drift
 *  between them.
 *
 *  clean() returns the name as it should print, or '' when the text is
 *  not a name; why() says what was wrong in words a bot can read back.
 *  Nothing here is clever: a name has letters, no digits, is not a bus
 *  stop, not a yes/no word, not a gender word, and is short.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class PersonName
{
    /** Words a chat produces that are never anybody's name. */
    private const NOT_A_NAME = [
        'ho', 'hoo', 'yes', 'y', 'ok', 'okay', 'no', 'na', 'nai', 'hunxa', 'huncha', 'hunchha', 'thik', 'thikcha',
        'hajur', 'namaste', 'namaskar', 'hi', 'hello', 'hey', 'haan', 'han', 'haa', 'confirm', 'book', 'ticket',
        'seat', 'cash', 'upi', 'esewa', 'bank', 'male', 'female', 'other', 'purush', 'mahila', 'm', 'f',
        'today', 'tomorrow', 'aaja', 'bholi', 'parsi', 'kal', 'nepal', 'india', 'np', 'in',
        // The request vocabulary TicketBot strips as intent — "ticket chahiyo"
        // must never ride on a berth as Mr Chahiyo (24 Sep review).
        'chahiyo', 'chahiye', 'chaiyo', 'chaiye', 'chahie', 'chahincha', 'chahinchha', 'chahinxa', 'chainxa', 'chahinu',
        'bata', 'jane', 'jana', 'jaane', 'aaune', 'aune', 'farkine', 'wapas', 'kaat', 'kata', 'katne', 'katnus', 'katidinus',
        'katdinus', 'kaatnu', 'katnu', 'pathau', 'pathaunus', 'pathaidinus', 'gara', 'garnus', 'garidinus', 'booking', 'tikat',
        'tiket', 'sleeper', 'cabin', 'berth', 'malai', 'hamilai', 'mero', 'hamro', 'lagi', 'samma', 'chahincha',
        'चाहियो', 'चाहिए', 'चाहिये', 'चाहिन्छ', 'जाने', 'आउने', 'मलाई', 'हामीलाई', 'बाट', 'लागि', 'काट', 'काट्नुहोस्', 'पठाउनुस्',
        'हो', 'हजुर', 'हुन्छ', 'ठिक', 'ठीक', 'नमस्ते', 'पुरुष', 'महिला', 'टिकट', 'सिट', 'नेपाल', 'भारत', 'भोलि', 'आज',
    ];

    /** Given names that are also towns we serve — a person before a place. */
    private const GIVEN_NAMES = ['anand', 'dang', 'nadia', 'gorakh', 'gorakhi', 'nepal', 'bharat'];

    /** @var array<int, string>|null lowercase town/stop keys we serve, loaded once */
    private static ?array $places = null;

    /**
     * The printable name, or '' when the text is not a person's name.
     * Accepts any script. ASCII names typed all-lowercase or ALL CAPS are
     * title-cased, because "ram thapa" on a border manifest looks like a
     * mistake even when it is not.
     */
    public static function clean(string $raw): string
    {
        return self::why($raw) === '' ? self::normalise($raw) : '';
    }

    /**
     * Why the text is not a name — '' when it is one. Written so the
     * assistant can read it to the person without translation.
     */
    public static function why(string $raw): string
    {
        $name = self::normalise($raw);
        if ($name === '') {
            return 'no name was given';
        }
        if (mb_strlen($name) < 2) {
            return 'the name is too short';
        }
        if (mb_strlen($name) > 80) {
            return 'the name is too long for a ticket';
        }
        if (preg_match('/\d/u', $name) === 1) {
            return 'a name cannot contain digits — the mobile number goes in its own place';
        }
        if (preg_match('/^[\p{L}\p{M}\s.\'\-]+$/u', $name) !== 1) {
            return 'the name contains symbols that do not belong in a name';
        }
        if (preg_match_all('/\p{L}/u', $name) < 2) {
            return 'a name needs at least two letters';
        }
        if (count(preg_split('/\s+/u', $name) ?: []) > 6) {
            return 'that is more words than a name has';
        }
        $lower = mb_strtolower($name);
        if (in_array($lower, self::NOT_A_NAME, true)) {
            return '"' . $name . '" is not a name';
        }
        // "thik cha", "ठिक छ", "ho ni" — every word is chat, none is a person.
        $words = preg_split('/\s+/u', $lower) ?: [];
        $chat  = 0;
        foreach ($words as $w) {
            if (in_array(trim($w, '.'), self::NOT_A_NAME, true) || in_array(trim($w, '.'), ['cha', 'chha', 'xa', 'ni', 'छ', 'नि', 'ta', 'त', 'la', 'ल'], true)) {
                $chat++;
            }
        }
        if ($chat === count($words)) {
            return '"' . $name . '" is not a name';
        }
        if (self::looksLikePlace($lower)) {
            return '"' . $name . '" is a bus stop, not a passenger';
        }

        return '';
    }

    /** Two spellings of the same name? Case, spacing and dots are ignored. */
    public static function same(string $a, string $b): bool
    {
        $k = static fn (string $s): string => mb_strtolower((string) preg_replace('/[^\p{L}\p{M}]+/u', '', $s));
        $ka = $k($a);
        $kb = $k($b);

        return $ka !== '' && $ka === $kb;
    }

    /**
     * Is this one of the towns or stops we serve? A pickup typed where a
     * name was expected is the commonest wrong name on this register.
     */
    public static function looksLikePlace(string $name): bool
    {
        $n = mb_strtolower(trim($name));
        if ($n === '') {
            return false;
        }
        $key = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $n);
        if ($key === '') {
            return false;
        }
        /* Exact matches only. A prefix rule read "Nadia" as Nadiad and
           "Gorakh" as Gorakhpur and refused real passengers for ever; and
           Anand is a stop on this route AND a common Gujarati given name. */
        if (in_array($key, self::GIVEN_NAMES, true)) {
            return false;
        }
        foreach (self::places() as $place) {
            if ($place === $key) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------- */

    private static function normalise(string $raw): string
    {
        $s = Security::clean($raw, 200);
        // "naam: Ram" / "name - Ram" / "नाम राम" — the label is not the name.
        $s = (string) preg_replace('/^\s*(?:naam|nam|name|नाम|નામ)\s*[:\-–]?\s*/iu', '', $s);
        // Quotes and brackets a phone keyboard adds. A Unicode-aware strip:
        // trim() works on BYTES, and the byte 0x99 that ends "’" also ends
        // "ङ", so trim() ate the last byte of every name ending in ङ.
        $edge = '[\s"\'“”‘’()\[\]{}:;,.\-–—\/|]+';
        $s = (string) preg_replace('/^' . $edge . '|' . $edge . '$/u', '', $s);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        if ($s === '') {
            return '';
        }
        // Title-case an ASCII name that arrived in one case only.
        if (preg_match('/^[A-Za-z .\'\-]+$/', $s) === 1 && ($s === strtolower($s) || $s === strtoupper($s))) {
            $s = mb_convert_case(strtolower($s), MB_CASE_TITLE, 'UTF-8');
        }

        return $s;
    }

    /** @return array<int, string> */
    private static function places(): array
    {
        if (self::$places !== null) {
            return self::$places;
        }
        $out = [];
        $add = static function (string $label) use (&$out): void {
            $lead = trim((string) preg_replace('/\s*\[[^\]]*\]\s*$/u', '', (string) preg_replace('/@.*$/u', '', $label)));
            foreach (preg_split('/\s*[—–·,\-]\s*/u', $lead) ?: [] as $part) {
                $k = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $part));
                if (mb_strlen($k) >= 3) {
                    $out[$k] = true;
                }
            }
        };
        try {
            foreach (Database::fetchAll('SELECT stop_name FROM route_stops WHERE stop_name IS NOT NULL') as $r) {
                $add((string) $r['stop_name']);
            }
            foreach (Database::fetchAll('SELECT from_city, to_city FROM routes') as $r) {
                $add((string) $r['from_city']);
                $add((string) $r['to_city']);
            }
        } catch (Throwable $e) {
            // Unsure — let a name through rather than block a real booking.
        }
        // The towns this company actually serves, in the spellings people type.
        foreach (['mehsana', 'mahesana', 'ahmedabad', 'amdavad', 'surat', 'vadodara', 'baroda', 'bharuch', 'ankleshwar',
                  'anand', 'nadiad', 'kamrej', 'rupaidiha', 'nepalgunj', 'nepalganj', 'kohalpur', 'chiloda', 'nanachiloda',
                  'sharipark', 'shariparking', 'parking', 'gujarat', 'kathmandu', 'pokhara', 'butwal', 'dang', 'bahraich',
                  'gorakhpur', 'lucknow', 'delhi', 'mumbai'] as $p) {
            $out[$p] = true;
        }
        self::$places = array_keys($out);

        return self::$places;
    }
}
