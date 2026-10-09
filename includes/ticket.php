<?php
/**
 * =====================================================================
 *  Ticket — issues ticket records and renders the branded PDF ticket,
 *  boarding pass and GST invoice.
 *
 *  A ticket is only ever created for a confirmed booking (payment
 *  verified). The QR payload is HMAC-signed so a forged or altered
 *  ticket is detected the moment it is scanned at boarding.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/docart.php';   // emblem, flags, heritage band (9 Oct 2026)

final class Ticket
{
    /* PDF palette — the GOLD template (9 Oct 2026): the DocArt brand values
       (navy #0E1E3F, maroon #7A1E2C as the accent, light gold foil on navy,
       gold hairlines). Shared by the ticket and the invoice PDF. */
    private const NAVY   = [14, 30, 63];
    private const BLUE   = [27, 58, 122];
    private const ORANGE = [122, 30, 44];
    private const GOLD   = [243, 222, 138];
    private const INK    = [26, 26, 46];
    private const MUTE   = [110, 104, 92];
    private const LINE   = [226, 206, 150];
    private const PANEL  = [252, 246, 230];

    /**
     * Build the signed QR payload for a booking, matching the pipe-format
     * used by the original single-file build.
     */
    public static function qrPayload(array $booking, array $seats): string
    {
        /* Fields 1-6 are the original v1 payload and must keep their
           positions — verifyQrPayload() reads the PNR from index 1, and
           tickets issued before this change are still in passengers'
           phones. Everything after is additive: the signature is always
           the LAST element and the core is everything before it, so a v1
           ticket (6 fields) and a v2 ticket (10) both verify unchanged.

           The extra fields are what makes the QR readable on its own at a
           checkpoint with no signal — the scanner sees seat, date, route,
           departure and bus without querying anything. */
        $core = implode('|', [
            'SHG-TICKET',
            $booking['pnr'],
            $booking['travel_date'] ?? ($booking['date'] ?? ''),
            implode('+', $seats),
            ($booking['from_city'] ?? '') . '->' . ($booking['to_city'] ?? ''),
            /* Field 5 was the passenger's mobile (8 Oct 2026): any camera
               app read it off a printed or forwarded PDF. The POSITION stays
               (verifyQrPayload() signs whatever core was issued, so every old
               v1/v2 ticket with the number in it still verifies); new issues
               and reissues simply leave it blank. */
            '',
            // ---- v2 ----
            (string) ($booking['ticket_number'] ?? ''),
            (string) count($seats),
            substr((string) ($booking['dep_time'] ?? ''), 0, 5),
            (string) ($booking['bus_number'] ?? ''),
        ]);

        $signature = substr(Security::sign($core), 0, 16);

        return $core . '|' . $signature;
    }

    /**
     * A 3-letter city code for the boarding pass, airline style. Known
     * cities are spelled the way the trade writes them; anything else
     * falls back to its first three letters, which is still stable and
     * still readable.
     */
    /**
     * The two ends of THIS passenger's journey, for the big letters on the
     * ticket: the boarding point they chose and the stop they get off at —
     * not the route's origin and terminus. The PDF got this right from the
     * start and the PNG did not (owner's report, 6 Sep 2026: "S Hari Parking
     * select gareko, ticket ma Surat"), so both now read one helper. Falls
     * back to the route ends when a leg carries no stop text (old rows).
     *
     * @return array{fromCode:string, fromName:string, toCode:string, toName:string, dep:string}  dep = HH:MM or ''
     */
    private static function tripEnds(array $booking): array
    {
        if (!class_exists('Boarding')) {
            require_once __DIR__ . '/boarding.php';
        }
        $from = Boarding::stopDisplay((string) ($booking['boarding_stop'] ?? ''), (string) ($booking['from_city'] ?? ''));
        $to   = Boarding::stopDisplay((string) ($booking['drop_stop'] ?? ''), (string) ($booking['to_city'] ?? ''));
        $dep  = (string) ($from['time'] ?? '');
        if ($dep === '' && !empty($booking['boarding_time'])) {
            $dep = substr((string) $booking['boarding_time'], 0, 5);
        }
        if ($dep === '' && !empty($booking['dep_time'])) {
            $dep = substr((string) $booking['dep_time'], 0, 5);
        }

        return [
            'fromCode' => $from['code'], 'fromName' => $from['name'],
            'toCode'   => $to['code'],   'toName'   => $to['name'],
            'dep'      => $dep,
        ];
    }

    private static function cityCode(string $city): string
    {
        $city = trim($city);
        $map  = [
            'ahmedabad' => 'AMD', 'nepalgunj' => 'NPJ', 'nepalganj' => 'NPJ',
            'mehsana'   => 'MSA', 'rupaidiha' => 'RPD', 'lucknow'   => 'LKO',
            'kathmandu' => 'KTM', 'delhi'     => 'DEL', 'jaipur'    => 'JAI',
            'surat'     => 'STV', 'vadodara'  => 'BDQ', 'udaipur'   => 'UDR',
            'bahraich'  => 'BRK', 'gorakhpur' => 'GKP', 'kohalpur'  => 'KHL',
        ];

        $key = strtolower($city);
        if (isset($map[$key])) {
            return $map[$key];
        }

        $letters = preg_replace('/[^A-Za-z]/', '', $city) ?? '';

        return strtoupper(substr($letters !== '' ? $letters : 'SHG', 0, 3));
    }

    /**
     * Strip UTF-8 multi-byte characters (Devanagari clusters, curly quotes,
     * emoji, non-breaking spaces) from a string so it prints cleanly through
     * the Latin-1 fonts (F1 Helvetica, F2 Helvetica-Bold). Devanagari should
     * go through devText()/F7 instead — this is the safety net for fields
     * (boarding_stop, drop_stop, passenger name) that mix English + Nepali
     * in one string. Without it, strtoupper() on the raw bytes produces
     * "NAN%A□ CHILODA" and printing the trailing "(नाना चिलोडा)" through
     * Helvetica produces "(0>$? o ,G)"-shaped junk (30 Aug 2026 fix).
     */
    private static function latin(string $text): string
    {
        // Common whitespace-ish + punctuation UTF-8 sequences that should
        // become their ASCII lookalike, not vanish (so "Nana<NBSP>Chiloda"
        // stays "Nana Chiloda" and "Rupaidiha — border" stays "Rupaidiha -
        // border"). Everything not on this list gets dropped by the regex
        // below (Devanagari, emoji, unknown UTF-8).
        $text = strtr($text, [
            "\xC2\xA0"     => ' ',    // NBSP
            "\xE2\x80\x94" => '-',    // em dash —
            "\xE2\x80\x93" => '-',    // en dash –
            "\xE2\x80\x98" => "'",    // left single quote '
            "\xE2\x80\x99" => "'",    // right single quote '
            "\xE2\x80\x9C" => '"',    // left double quote "
            "\xE2\x80\x9D" => '"',    // right double quote "
            "\xE2\x80\xA6" => '...',  // ellipsis …
        ]);
        // Drop remaining non-printable-ASCII bytes. \x20-\x7E keeps space through ~.
        $ascii = preg_replace('/[^\x20-\x7E]/u', '', $text) ?? '';
        // The strip usually leaves an empty "()" behind — e.g. "Foo (नेप)"
        // → "Foo ()" — so collapse that + any doubled whitespace.
        $ascii = (string) preg_replace('/\(\s*\)/', '', $ascii);
        $ascii = (string) preg_replace('/\s{2,}/', ' ', $ascii);
        return trim($ascii);
    }

    /** Uppercase after Latin-safe sanitisation — see latin(). */
    /**
     * A stop label as it should PRINT (5 Sep 2026): the stored form carries
     * machine parts — "Surat · Departure @ 13:00 [21.1702,72.8311]" — and the
     * "[lat,lng]" coordinates were being printed on the ticket verbatim.
     */
    /**
     * Where the passenger boards, for a map link (8 Oct 2026, master prompt
     * 2.0): the [lat,lng] the booking carries, else the coordinates of the
     * matching official pickup row (Routes -> Pickup points). Null when the
     * pickup has none - a ticket never prints a guessed position.
     *
     * @return array{lat: float, lng: float, landmark: string}|null
     */
    public static function pickupGeo(array $booking): ?array
    {
        $label = (string) ($booking['boarding_stop'] ?? '');
        $landmark = '';
        $lat = null; $lng = null;
        if (preg_match('/\[\s*(-?\d+(?:\.\d+)?)\s*[,;]\s*(-?\d+(?:\.\d+)?)\s*\]/', $label, $m) === 1) {
            $lat = (float) $m[1]; $lng = (float) $m[2];
        }
        $routeId = (int) ($booking['route_id_for_stop'] ?? 0);
        $name = trim((string) (preg_replace('/\s*(@\s*[0-2]?\d:\d{2})?\s*(\[[^\]]*\])?\s*$/u', '', $label) ?? $label));
        $name = trim((string) (explode('·', $name)[0] ?? $name));
        if ($routeId > 0 && $name !== '') {
            try {
                if (!class_exists('Boarding')) { require_once __DIR__ . '/boarding.php'; }
                $row = Boarding::stopRow($routeId, $name, 'boarding');
                if ($row !== null) {
                    $landmark = trim((string) ($row['landmark'] ?? ''));
                    if ($lat === null) {
                        $g = Database::fetch('SELECT latitude, longitude FROM route_stops WHERE id = :i', ['i' => (int) $row['id']]);
                        if ($g !== null && $g['latitude'] !== null && $g['longitude'] !== null) {
                            $lat = (float) $g['latitude']; $lng = (float) $g['longitude'];
                        }
                    }
                }
            } catch (Throwable $e) {
                // no stop row / table: the ticket simply carries no map
            }
        }
        if ($lat === null || $lng === null || ($lat == 0.0 && $lng == 0.0) || abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }
        return ['lat' => $lat, 'lng' => $lng, 'landmark' => $landmark];
    }

    /** Google Maps directions link to the pickup (works in any maps app that opens https links). */
    public static function pickupMapsUrl(array $geo): string
    {
        return 'https://www.google.com/maps/dir/?api=1&destination=' . round($geo['lat'], 6) . ',' . round($geo['lng'], 6);
    }

    /* 8 Oct 2026: also drops the trailing machine "@ HH:MM" (the stored form
       is "Name · Landmark @ 21:00 [lat,lng]") — the pickup time already has
       its own place on every ticket, and the PNG tile printed a dangling "@".
       Public so the verify page can print the same clean label. */
    public static function stopForPrint(string $label): string
    {
        $s = preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $label) ?? $label;   // drop [lat,lng]
        $s = preg_replace('/\s*@\s*[0-2]?\d:\d{2}\s*$/u', '', trim($s)) ?? $s;   // drop "@ 21:00"
        $s = preg_replace('/\s{2,}/u', ' ', $s) ?? $s;
        return trim($s, " \t·");
    }

    /**
     * Which floor a berth is on, from its CANONICAL id (8 Oct 2026): L… is
     * the lower deck (1F), U… the upper (2F). The printed grid label (A3, C8)
     * does not say it, and an elderly passenger needs to know before the
     * door whether to climb. '' for anything else (a seater seat, '').
     */
    public static function floorCode(string $seat): string
    {
        if (preg_match('/^([LU])\d+$/i', trim($seat), $m) !== 1) {
            return '';
        }
        return strtoupper($m[1]) === 'U' ? '2F' : '1F';
    }

    /** "A3 · A4 (1F)" when every seat shares a floor, else "A3 1F · C8 2F" — Latin, for the Helvetica PDF. */
    private static function seatLabelListFloor(array $seats, array $booking): string
    {
        $labels = []; $floors = [];
        foreach ($seats as $s) {
            $lbl = self::seatLabel((string) $s, $booking);
            if ($lbl === '') { continue; }
            $labels[] = $lbl;
            $floors[] = self::floorCode((string) $s);
        }
        if ($labels === []) {
            return '';
        }
        $uniq = array_values(array_unique($floors));
        if (count($uniq) === 1) {
            return implode(' · ', $labels) . ($uniq[0] !== '' ? ' (' . $uniq[0] . ')' : '');
        }
        $out = [];
        foreach ($labels as $i => $lbl) {
            $out[] = trim($lbl . ' ' . $floors[$i]);
        }
        return implode(' · ', $out);
    }

    private static function latinUpper(string $text): string
    {
        return strtoupper(self::latin($text));
    }

    /**
     * latin(), in public. GD draws through FreeType with no OpenType
     * shaping engine, so every PNG document in the app has to reduce its
     * text to ASCII the same way — this is that one rule, so the ticket,
     * the challan and the chalani cannot disagree about what a name looks
     * like once the Devanagari is stripped.
     */
    public static function roman(string $text): string
    {
        return self::latin($text);
    }

    /**
     * Text for the PNG documents, WITH its Devanagari intact.
     *
     * The PNG ticket has stripped every non-ASCII byte since it was built,
     * on the belief that GD cannot shape Devanagari. GD indeed cannot (the
     * 10 Sep 2026 note that it forms conjuncts was wrong: libgd 2.3.3 on the
     * VPS links FreeType only, and यात्रु printed as यात्‌रु). Since 24 Sep
     * 2026 HarfBuzz shapes it (DevShape, via gdText/gdWidth), so a Nepali
     * name prints as the passenger wrote it — conjuncts, reph and all.
     *
     * Kept: printable ASCII and the Devanagari block (which carries the
     * Nepali digits and the danda). Dropped: everything else — emoji and
     * other scripts have no glyph in NotoSansDevanagari and would print as
     * empty boxes, which is worse than absent. Callers fall back to
     * roman(), then to a dash, when a name is left with nothing.
     */
    public static function display(string $text): string
    {
        $text = strtr($text, ["\xC2\xA0" => ' ', "\xE2\x80\x94" => '-', "\xE2\x80\x93" => '-']);
        $text = preg_replace('/[^\x20-\x7E\p{Devanagari}\x{00B7}\x{20B9}\x{2026}]/u', '', $text) ?? '';
        $text = (string) preg_replace('/\(\s*\)/', '', $text);
        $text = (string) preg_replace('/\s{2,}/u', ' ', $text);
        return trim($text);
    }

    /** display(), falling back to roman() and then to a dash. */
    public static function displayOr(string $text, string $fallback = '-'): string
    {
        $d = self::display($text);
        if ($d !== '') { return $d; }
        $r = self::roman($text);
        return $r !== '' ? $r : $fallback;
    }

    /**
     * Roman-Nepali time-of-day description for the five permanent Gujarat stops.
     * The PDF font is Latin-1 (Helvetica), so Devanagari renders as garbage —
     * we transliterate to Roman Nepali which prints correctly on every device.
     */
    private static function nepaliTime(string $time24): string
    {
        $h = (int) substr(trim($time24), 0, 2);
        if ($h === 13) return '(diusora 1 baje)';
        if ($h === 17) return '(sanjha 5 baje)';
        if ($h === 19) return '(sanjha 7 baje)';
        if ($h === 21) return '(rati 9 baje)';
        if ($h === 23) return '(rati 11 baje)';
        if ($h === 18) return '(sanjha 6 baje)';
        return '';
    }

    /* ================================================================
     *  Nepali text helpers
     * ================================================================ */

    /** Convert ASCII digits to Devanagari numerals: 0→०, 1→१, etc. */
    private static function nepaliDigits(string $str): string
    {
        return strtr($str, [
            '0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४',
            '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९',
        ]);
    }

    /** Format a date as "०२ सेप्टेम्बर २०२६". */
    private static function nepaliDate(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) return $date;
        $months = [1=>'जनवरी','फेब्रुअरी','मार्च','अप्रिल','मे','जुन',
                   'जुलाई','अगस्ट','सेप्टेम्बर','अक्टोबर','नोभेम्बर','डिसेम्बर'];
        $d = (int) date('j', $ts);
        $m = (int) date('n', $ts);
        $y = date('Y', $ts);
        return self::nepaliDigits(str_pad((string) $d, 2, '0', STR_PAD_LEFT))
             . ' ' . ($months[$m] ?? '') . ' ' . self::nepaliDigits($y);
    }

    /* ----------------------------------------------------------------
     *  BIKRAM SAMBAT (विक्रम संवत्)
     *
     *  nepaliDate() above renders the GREGORIAN date in Devanagari
     *  script; this is the real AD→BS conversion the ticket prints
     *  next to it, because a Nepali passenger reckons travel dates in
     *  BS ("भदौ १७"). BS month lengths are not formulaic — each year is
     *  a fixed panchanga table — so the sellable years are tabled here.
     *
     *  ⚠️ MIRROR CONSTANT: this table MUST stay identical to BS_DATA in
     *  assets/js/02-config.js. Change one, change the other in the SAME
     *  deploy — the site and the PDF would otherwise print different
     *  Nepali dates for the same ticket. Outside the table both sides
     *  return empty and the date degrades to AD-only (never a wrong date).
     * ---------------------------------------------------------------- */
    private const BS_DATA = [
        2080 => ['start' => '2023-04-14', 'm' => [31,31,31,32,31,31,30,29,30,29,30,30]], // 365
        2081 => ['start' => '2024-04-13', 'm' => [31,32,31,32,31,30,30,30,29,30,30,30]], // 366
        2082 => ['start' => '2025-04-14', 'm' => [30,32,31,32,31,30,30,30,29,30,30,30]], // 365
        2083 => ['start' => '2026-04-14', 'm' => [31,31,32,31,31,30,30,30,29,30,30,30]], // 365
        2084 => ['start' => '2027-04-14', 'm' => [31,31,32,31,31,30,30,30,29,30,30,30]], // 365
    ];

    private const BS_MONTHS = ['बैशाख','जेठ','असार','साउन','भदौ','असोज',
                               'कार्तिक','मंसिर','पुष','माघ','फागुन','चैत'];

    /**
     * "भदौ १७, २०८३" for an AD date, or '' when the date falls outside
     * BS_DATA. UTC throughout so the server's timezone cannot shift the
     * day count.
     */
    private static function bsDate(string $date): string
    {
        /* Require a real Y-m-d. Without this, an empty or partial
           travel_date makes strtotime(' UTC') resolve to *now*, and the
           ticket would confidently print TODAY's Nepali date for a
           booking whose date is missing. */
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '';

        $ts = strtotime($date . ' UTC');
        if ($ts === false) return '';
        $target = (int) floor($ts / 86400);

        foreach (self::BS_DATA as $year => $rec) {
            $startTs = strtotime($rec['start'] . ' UTC');
            if ($startTs === false) continue;
            $start = (int) floor($startTs / 86400);
            $total = array_sum($rec['m']);
            if ($target < $start || $target >= $start + $total) continue;

            $offset = $target - $start;
            foreach ($rec['m'] as $i => $len) {
                if ($offset < $len) {
                    return self::BS_MONTHS[$i]
                         . ' ' . self::nepaliDigits((string) ($offset + 1))
                         . ', ' . self::nepaliDigits((string) $year);
                }
                $offset -= $len;
            }
        }

        return '';
    }

    /** Devanagari time-of-day label: "राति ९:०० बजे" / "दिउँसो १:०० बजे". */
    private static function nepaliTimeLabel(string $time24): string
    {
        $h = (int) substr(trim($time24), 0, 2);
        $mm = substr(trim($time24), 3, 2) ?: '00';
        $h12 = $h % 12 ?: 12;
        $period = '';
        if ($h >= 4  && $h < 12)  $period = 'बिहान';
        if ($h >= 12 && $h < 16)  $period = 'दिउँसो';
        if ($h >= 16 && $h < 20)  $period = 'साँझ';
        if ($h >= 20 || $h < 4)   $period = 'राति';
        return $period . ' ' . self::nepaliDigits((string) $h12) . ':' . self::nepaliDigits($mm) . ' बजे';
    }

    /** A perforation — the tear line between coupon and stub. */
    private static function perforate(Pdf $pdf, float $x, float $yTop, float $yBottom): void
    {
        for ($y = $yTop; $y < $yBottom; $y += 7) {
            $pdf->line($x, $y, $x, min($y + 4, $yBottom), DocArt::GOLD, 0.9);
        }
    }

    /**
     * Verify a scanned QR payload. Returns the PNR when valid, null when
     * the signature does not match (tampered or foreign ticket).
     */
    public static function verifyQrPayload(string $payload): ?string
    {
        $payload = trim($payload);

        // PNG-era QR (5 Sep 2026): the image ticket carries ONE QR — the
        // keyed verify URL — so a phone camera opens the live status page
        // AND the boarding scanner (admin/scan.php posts the raw scan here)
        // accepts the same code. The download token is the proof: a forged
        // or altered URL fails checkDownloadToken exactly like a bad HMAC.
        if (preg_match('#^https?://#i', $payload) === 1) {
            $query = (string) parse_url($payload, PHP_URL_QUERY);
            parse_str($query, $q);
            $pnr = strtoupper(trim((string) ($q['pnr'] ?? '')));
            $key = trim((string) ($q['k'] ?? ''));
            if ($pnr !== '' && $key !== '' && Security::isValidPnr($pnr) && self::checkDownloadToken($pnr, $key)) {
                return $pnr;
            }

            return null;
        }

        $parts = explode('|', $payload);

        if (count($parts) < 7 || $parts[0] !== 'SHG-TICKET') {
            return null;
        }

        $signature = array_pop($parts);
        $core      = implode('|', $parts);

        if (!hash_equals(substr(Security::sign($core), 0, 16), $signature)) {
            return null;
        }

        return $parts[1]; // the PNR
    }

    /* =================================================================
     *  Download authorisation (master prompt §25)
     *
     *  PNRs are SEQUENTIAL — nextTicketNo() mints SHG-2026-00001,
     *  00002, 00003... So "anyone holding the PNR may download" meant
     *  anyone could count upwards and harvest every passenger's name,
     *  phone, ID number, route and seat. The PNR is a booking reference,
     *  not a secret, and it must not be the only thing guarding a PDF.
     *
     *  Every link the system generates now carries a keyed token derived
     *  from the PNR, so a guessed PNR alone opens nothing.
     * ================================================================= */

    /** Unguessable per-PNR download key (truncated HMAC — 20 hex chars). */
    public static function downloadToken(string $pnr): string
    {
        return substr(Security::sign('ticket-dl|' . strtoupper(trim($pnr))), 0, 20);
    }

    /** Constant-time check of a supplied download key. */
    public static function checkDownloadToken(string $pnr, string $token): bool
    {
        return $token !== '' && hash_equals(self::downloadToken($pnr), $token);
    }

    /**
     * The canonical download URL for a ticket (or its invoice), including
     * the key. Every place that links to a ticket must build it here so a
     * new link is never accidentally emitted without one.
     */
    public static function downloadUrl(string $pnr, bool $invoice = false): string
    {
        return appUrl(
            'download-ticket.php?pnr=' . urlencode($pnr)
            . ($invoice ? '&invoice=1' : '')
            . '&k=' . self::downloadToken($pnr)
        );
    }

    /**
     * Keyed link to the PNG ticket image (5 Sep 2026) — the PRIMARY ticket
     * customers receive on WhatsApp: opens/saves as a phone-friendly HD
     * image. The PDF stays one parameter away for printing.
     */
    public static function imageUrl(string $pnr): string
    {
        /* 23 Sep 2026: ?r=<revision> - a corrected ticket gets a NEW url, so
           WhatsApp and browsers fetch the redrawn picture instead of the copy
           they cached under the old one. download-ticket.php ignores both
           parameters; sw.js keys its cache on the PNR alone.

           1 Oct 2026 (R3f, G8): the link now changes on EVERY regeneration,
           not only on a counted correction - &r= is always present and &v= is
           a short tag over the signed hash and the last refresh time, so a
           silent refresh (agent credited at sale, cash collected, a layout
           re-render) also gets a URL nobody has cached. The per-request memo
           (the lists call this once per row) is a class property so
           forgetRevision() can drop it when the same request re-mints the
           ticket after already linking it (an AI turn: lookup, then fix). */
        $v = self::revisionTag($pnr);
        return appUrl(
            'download-ticket.php?pnr=' . urlencode($pnr)
            . '&img=1&k=' . self::downloadToken($pnr)
            . '&r=' . $v['rev']
            . ($v['tag'] !== '' ? '&v=' . $v['tag'] : '')
        );
    }

    /** @var array<string, array{rev:int, tag:string}> per-request memo behind imageUrl() */
    private static array $revMemo = [];

    /**
     * @return array{rev:int, tag:string} revision count + an 8-char content tag
     *         ('' when the booking has no ticket row yet)
     */
    public static function revisionTag(string $pnr): array
    {
        if (!array_key_exists($pnr, self::$revMemo)) {
            $row = null;
            try {
                $row = Database::fetch(
                    'SELECT t.reissue_count, t.qr_hash, t.reissued_at, t.issued_at
                       FROM tickets t JOIN bookings b ON b.id = t.booking_id
                      WHERE b.pnr = :p LIMIT 1',
                    ['p' => $pnr]
                );
            } catch (Throwable $e) {
                $row = null;
            }
            self::$revMemo[$pnr] = $row === null
                ? ['rev' => 0, 'tag' => '']
                : [
                    'rev' => (int) ($row['reissue_count'] ?? 0),
                    'tag' => substr(sha1((string) ($row['qr_hash'] ?? '') . '|' . (string) ($row['reissued_at'] ?? '') . '|' . (string) ($row['issued_at'] ?? '')), 0, 8),
                ];
        }
        return self::$revMemo[$pnr];
    }

    /** Drop the imageUrl() memo for one PNR (or all) so the next link is fresh. */
    public static function forgetRevision(?string $pnr = null): void
    {
        if ($pnr === null) {
            self::$revMemo = [];
            return;
        }
        unset(self::$revMemo[$pnr]);
    }

    /**
     * Keyed link to the public "Where is my bus?" page (24 Sep 2026): live
     * position, minutes to the passenger's own stop, and a share button so
     * the family at home can watch too.
     *
     * 8 Oct 2026 (D7-11): its OWN key now. This link is made to be forwarded
     * to a family or village WhatsApp group, and the download key it used to
     * carry also opened the full ticket (names + mobile) and bound push to the
     * passenger's number. The track token opens only track.php and
     * api/whereis.php; download-ticket, push and feedback keep accepting the
     * download key alone. Links already sent with the download key still open
     * the tracker (WhereIs::authorised accepts both).
     */
    public static function trackUrl(string $pnr): string
    {
        return appUrl('track.php?pnr=' . urlencode($pnr) . '&k=' . self::trackToken($pnr));
    }

    /** Per-PNR key that opens the live tracker and nothing else (truncated HMAC — 20 hex chars). */
    public static function trackToken(string $pnr): string
    {
        return substr(Security::sign('ticket-track|' . strtoupper(trim($pnr))), 0, 20);
    }

    /** Constant-time check of a supplied track key. */
    public static function checkTrackToken(string $pnr, string $token): bool
    {
        return $token !== '' && hash_equals(self::trackToken($pnr), $token);
    }

    /**
     * The 6-character SECURITY CODE printed beside the verify QR, on the PDF
     * stub and in big letters on verify-ticket.php (9 Oct 2026, owner: "ticket
     * fake badi hun layo, yeslai manage garnu"). The crew compares the paper
     * (or the screenshot) with the live page: an edited picture keeps an old
     * code, a made-up ticket has no matching one.
     *
     * Keyed (HMAC with APP_KEY over 'ticket-sec|PNR|qr_hash'), so it cannot be
     * computed from what is printed; tied to qr_hash, so every reissue (a new
     * date, seat or bus) mints a new code and a superseded copy reads as a
     * mismatch. 30 bits in a 32-letter alphabet with no 0/O or 1/I, so it can
     * be read out over the phone without a "zero or oh?".
     */
    public static function securityCode(string $pnr, string $qrHash): string
    {
        $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $hex   = substr(Security::sign('ticket-sec|' . strtoupper(trim($pnr)) . '|' . trim($qrHash)), 0, 8);
        $n     = (int) hexdec($hex);                // 32 bits
        $out   = '';
        for ($i = 0; $i < 6; $i++) {
            $out .= $alpha[$n & 31];
            $n >>= 5;
        }
        return $out;
    }

    /** securityCode() for a booking id, from its ticket row ('' when there is no ticket yet). */
    public static function securityCodeFor(int $bookingId): string
    {
        try {
            $row = Database::fetch('SELECT b.pnr, t.qr_hash FROM tickets t JOIN bookings b ON b.id = t.booking_id WHERE t.booking_id = :b ORDER BY t.id DESC LIMIT 1', ['b' => $bookingId]);
        } catch (Throwable $e) {
            $row = null;
        }
        return $row === null ? '' : self::securityCode((string) $row['pnr'], (string) ($row['qr_hash'] ?? ''));
    }

    /** Pretty form for print: "AB3 K7Q". */
    public static function securityCodeGrouped(string $code): string
    {
        return strlen($code) === 6 ? substr($code, 0, 3) . ' ' . substr($code, 3) : $code;
    }

    /**
     * Who cut this ticket — ONE answer for every surface that prints it
     * (PNG, PDF, chalani, register), so the ticket a passenger holds and the
     * sheet the office keeps can never disagree about whose sale it was.
     *
     * Owner ask, 6 Sep 2026 ("kasko through bata kateko tha hos") and again
     * 10 Sep 2026: the AGENT CODE is the fact that matters — a commission
     * argument is settled by the code, not by a name that two agents share.
     * So `code` is always populated: the agent's SHG number when a numbered
     * agent sold it, else the channel that did (OFFICE / ONLINE).
     *
     * @param array<string,mixed> $booking a row carrying sold_by_admin_id and,
     *        ideally, the joined agent_name / agent_phone (loadBooking does).
     * @return array{kind:string, code:string, name:string, phone:string, line:string}
     */
    public static function issuedBy(array $booking): array
    {
        if (!class_exists('AgentWallet')) {
            require_once __DIR__ . '/agentwallet.php';
        }
        $company  = (string) self::companyOf($booking)['name'];   // the ISSUING company (4 Oct 2026)
        $soldById = (int) ($booking['sold_by_admin_id'] ?? 0);
        $name     = trim((string) ($booking['agent_name'] ?? ''));
        $phone    = trim((string) ($booking['agent_phone'] ?? ''));
        $role     = strtolower(trim((string) ($booking['agent_role'] ?? '')));
        $code     = $soldById > 0 ? AgentWallet::agentCodeLabel($soldById) : '';

        // An agent whose SHG number has not been issued yet is still an
        // AGENT sale — printing OFFICE on it would hand the commission to
        // the wrong desk. The row falls back to the word, never to a lie.
        if ($soldById > 0 && ($code !== '' || $role === 'agent')) {
            $kind = 'agent';
            if ($code === '') { $code = 'AGENT'; }
            if ($name === '') { $name = 'Counter agent'; }
        } elseif ($soldById > 0) {
            // Company staff hold no SHG number — the desk itself is the seller.
            $kind  = 'office';
            $code  = $role === 'counter' ? 'COUNTER' : 'OFFICE';
            if ($name === '') { $name = $company; }
            $phone = '';
        } else {
            /* No seller row at all. The CHANNEL still knows what happened, and
               the manifest has always read it — a counter walk-in typed with no
               admin id printed COUNTER on the chalani and ONLINE on the ticket
               for the same booking (audit, 11 Sep 2026). One fallback now, here. */
            $src   = strtolower(trim((string) ($booking['source'] ?? '')));
            $kind  = $src === 'counter' || $src === 'admin' ? 'office' : 'online';
            $code  = $src === 'counter' ? 'COUNTER' : ($src === 'admin' ? 'OFFICE' : 'ONLINE');
            $name  = $company;
            $phone = '';
        }

        /* WHERE the ticket was cut (owner ask, 24 Sep 2026: "ticket [ma] by
           name ra counter ko location hos, dekhine gari"). The desk's town
           and its short code live on admin_profiles — loadBooking() joins
           them in as agent_counter / agent_counter_code. Only a real seller
           has a desk: an online sale has none, and printing the head office
           on it would say a counter issued something a customer issued for
           themselves. Falls back to the company city so a desk that has not
           been given a location yet still prints somewhere true. */
        $locName = trim((string) ($booking['agent_counter'] ?? ''));
        $locCode = trim((string) ($booking['agent_counter_code'] ?? ''));
        /* 4 Oct 2026: the desk FROZEN on the sale wins. agent_counter* is the
           credited seller's desk today - for a clerk's sale that is the agent
           the commission was moved to, not the window that cut the ticket,
           and it moves when staff move. Old rows carry no stamp and keep the
           profile answer. */
        $stamp = mb_strtoupper(trim((string) ($booking['counter_code'] ?? '')));
        if ($stamp !== '') {
            $locCode = $stamp;
            $locName = (string) (CounterDesk::get($stamp)['name'] ?? '');
        }
        $location = $kind === 'online' ? '' : Settings::counterLabel($locCode, $locName);
        if ($location === '' && $kind !== 'online') {
            $location = trim((string) Settings::getString('company_city', ''));
        }

        $parts = array_values(array_filter([$name, $code, $phone], static fn(string $s): bool => $s !== ''));

        /* The CHANNEL, named out loud (owner, 11 Sep 2026: "counter bata
           kateko chha bhane COUNTER, agent bata kateko chha bhane AGENT
           lekhne"). The code alone did not say which desk it came from, and
           a generic "ISSUED BY" said nothing at all. Bilingual, and free of
           the short-i matra so it needs no shaping exception. */
        $label = match ($kind) {
            'agent'  => 'एजेन्ट  ·  AGENT',
            'office' => $code === 'COUNTER' ? 'काउन्टर  ·  COUNTER' : 'कार्यालय  ·  OFFICE',
            default  => 'अनलाइन  ·  ONLINE',
        };

        return [
            'kind'     => $kind,
            'code'     => $code,
            'name'     => $name,
            'phone'    => $phone,
            'label'    => $label,
            'line'     => implode('  ·  ', $parts),
            'location' => $location,          // "Nepalgunj — Bus Park (NPJ)"
            'locCode'  => mb_strtoupper($locCode),
        ];
    }

    /**
     * The NPR frozen on a sale (bookings.fx_total), or 0 when the sale was in
     * rupees or predates the stamp. Never converts.
     */
    public static function frozenNpr(array $booking): float
    {
        return strtoupper(trim((string) ($booking['fx_currency'] ?? ''))) === 'NPR'
            ? max(0.0, (float) ($booking['fx_total'] ?? 0)) : 0.0;
    }

    /**
     * The company logo file for PDF headers, or null when none is usable.
     * Same resolution as the chalani print view (admin/manifest.php): an
     * admin-set company_logo path wins, otherwise the site logo — but PDF
     * embedding only handles PNG/JPEG, so other formats fall through.
     */
    /**
     * The letterhead for THIS booking (4 Oct 2026): the company frozen on the
     * sale (bookings.entity) and the desk frozen on it (bookings.counter_code).
     * An unstamped row - every online sale and everything before the stamp -
     * is the India company, exactly as before. One answer for the PNG, the
     * PDF, the invoice, the verify page and the WhatsApp text.
     *
     * @return array<string,mixed> see Settings::companyFor()
     */
    public static function companyOf(array $booking): array
    {
        return Settings::companyFor(
            (string) ($booking['counter_code'] ?? ''),
            (string) ($booking['entity'] ?? '')
        );
    }

    /**
     * Up to four help numbers for the foot of a printed ticket, as
     * [place, phone]. One contact truth: desks that carry a phone in the desk
     * table come first (the issuing company's own, then the rest); when no
     * desk has one yet, the office's own company_counters line is used. The
     * OTHER side's help number is always among them when the office has set
     * one, so a passenger across the border has someone to ring. Nothing is
     * hard-coded here any more.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function helpContacts(array $co, int $max = 4): array
    {
        return Settings::helpContacts($co, $max);
    }

    /** "6:00 PM IST" - and the Nepal clock beside it on a Nepal company's ticket. */
    public static function zonedTime(string $hhmm, array $co): string
    {
        $ts = strtotime($hhmm);
        if ($hhmm === '' || $ts === false) {
            return '';
        }
        // Every boarding point is in India, so the timetable is India time.
        // Nepal runs 15 minutes ahead; a Nepalgunj passenger reads NPT.
        return date('g:i A', $ts) . ' IST'
            . (($co['tz'] ?? 'IST') === 'NPT' ? ' (' . date('g:i A', $ts + 15 * 60) . ' NPT)' : '');
    }

    /**
     * A cached ticket file older than this redraws once: the later of the
     * layout stamp and the last time the office edited the company details
     * (Admin -> Company details / a desk's contact), so a corrected address or
     * a new logo reaches tickets that were already drawn (4 Oct 2026).
     */
    private static function staleBefore(string $layoutStamp): int
    {
        return max((int) strtotime($layoutStamp), Settings::getInt('ticket_identity_changed_at', 0));
    }

    public static function logoFile(string $prefer = ''): ?string
    {
        foreach ([$prefer, Settings::getString('company_logo', ''), 'assets/img/logo.png'] as $cand) {
            if ($cand === '' || preg_match('#^https?://#i', $cand) === 1) {
                continue;
            }
            $ext = strtolower(pathinfo($cand, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
                continue;
            }
            $path = dirname(__DIR__) . '/' . ltrim($cand, '/');
            if (is_file($path) && filesize($path) > 0 && filesize($path) < 600 * 1024) {
                return $path;
            }
        }

        return null;
    }

    /**
     * A company's OWN uploaded logo (the Nepal company's nepal_logo), passed
     * to DocArt::markFile() as the preferred mark — '' for the India company,
     * whose documents now carry the transparent emblem (9 Oct 2026) rather
     * than the full logo with the name baked into it.
     */
    private static function ownLogo(array $co): string
    {
        $own = trim((string) ($co['logo'] ?? ''));
        return ($own !== '' && $own !== Settings::getString('company_logo', '')) ? $own : '';
    }

    /**
     * Which flag leads on this ticket: the side the bus leaves from
     * ('IN' for Gujarat → Rupaidiha, 'NP' for the return run).
     */
    private static function flagFirst(array $booking): string
    {
        if (!class_exists('Boarding')) {
            require_once __DIR__ . '/boarding.php';
        }
        return Boarding::routeDirection(['from_city' => (string) ($booking['from_city'] ?? ''), 'to_city' => (string) ($booking['to_city'] ?? '')]) === 'toIndia' ? 'NP' : 'IN';
    }

    /**
     * Diagonal microtext across the ticket body (9 Oct 2026, anti-fake):
     * "PNR · NAME · TKT-…" repeated at a slant in faint navy, drawn BEFORE
     * the tiles and QR codes so those paint over it — the QR quiet zones stay
     * clean and every value stays readable, while a pasted-in name or PNR
     * visibly breaks the pattern. Latin only (imagettftext at an angle; the
     * shaped Devanagari path cannot rotate). Faint, but dark enough to come
     * through WhatsApp's JPEG pass as a texture.
     */
    private static function gdWatermark($im, int $x1, int $y1, int $x2, int $y2, string $text): void
    {
        try {
            $text = trim($text);
            if ($text === '' || !function_exists('imagesetclip')) {
                return;
            }
            $col   = imagecolorallocatealpha($im, 110, 84, 22, 117);   // gold-deep on the ivory card (9 Oct 2026)
            $font  = self::pngFont();
            $angle = 22;
            $unit  = $text . '     ·     ';
            $box   = imagettfbbox(15, 0, $font, $unit);
            $uw    = $box === false ? 400 : max(120, (int) abs($box[2] - $box[0]));
            $line  = str_repeat($unit, (int) ceil(2600 / $uw) + 1);
            $rise  = (int) ceil(($x2 - $x1 + 200) * tan(deg2rad($angle)));
            imagesetclip($im, $x1, $y1, $x2, $y2);
            imagealphablending($im, true);
            $row = 0;
            for ($y = $y1 + 30; $y <= $y2 + $rise; $y += 74) {
                $shift = ($row++ % 2) * (int) round($uw / 2);
                imagettftext($im, 15, $angle, $x1 - 120 - $shift, $y, $col, $font, $line);
            }
        } catch (Throwable $e) {
        } finally {
            if (function_exists('imagesetclip')) {
                @imagesetclip($im, 0, 0, imagesx($im) - 1, imagesy($im) - 1);
            }
        }
    }

    /**
     * The SECURITY CODE tile next to the verify QR: Nepali label, Roman code,
     * a gold edge and guilloche-style hairlines behind the letters.
     */
    private static function gdSecurityTile($im, int $x1, int $y1, int $x2, int $y2, string $code): void
    {
        if ($code === '') {
            return;
        }
        $navy  = DocArt::col($im, DocArt::NAVY);
        $gold  = DocArt::col($im, DocArt::GOLD);
        $mut   = imagecolorallocate($im, 84, 96, 118);
        $bg    = imagecolorallocate($im, 253, 246, 224);
        $wave  = imagecolorallocate($im, 236, 220, 176);
        DocArt::softShadow($im, $x1, $y1, $x2, $y2, 14, 7, 4, [90, 64, 12], 12);
        self::gdRounded($im, $x1, $y1, $x2, $y2, 14, $bg);
        DocArt::roundOutline($im, $x1, $y1, $x2, $y2, 14, $gold, 1);
        // guilloche: three sine hairlines across the tile — hard to retouch, cheap to draw
        for ($k = 0; $k < 3; $k++) {
            $py = null;
            for ($x = $x1 + 16; $x <= $x2 - 16; $x += 3) {
                $y = (int) round(($y1 + $y2) / 2 + 6 + $k * 7 + sin(($x - $x1) / (17 + 5 * $k)) * (9 - $k * 2));
                if ($py !== null) {
                    imageline($im, $x - 3, $py, $x, $y, $wave);
                }
                $py = $y;
            }
        }
        imagefilledrectangle($im, $x1, $y1 + 12, $x1 + 6, $y2 - 12, $gold);
        $h = $y2 - $y1;
        if ($h < 76) {
            /* Compact tile (the pay-QR layout has ~60px): the label stacked on
               the left, the code beside it at full height — stacked above each
               other they touched. */
            self::gdText($im, 14, $x1 + 22, $y1 + (int) round($h * 0.44), $mut, 'सुरक्षा कोड', true);
            self::gdText($im, 12, $x1 + 22, $y1 + (int) round($h * 0.44) + 21, $mut, 'SECURITY CODE', false);
            $sz = 29;
            $cx = $x1 + 22 + max(self::gdWidth(14, 'सुरक्षा कोड'), self::gdWidth(12, 'SECURITY CODE')) + 26;
            $base = $y1 + intdiv($h, 2) + 14;
            foreach (str_split(self::securityCodeGrouped($code)) as $ch) {
                if ($ch === ' ') { $cx += (int) round($sz * 0.55); continue; }
                self::gdText($im, $sz, $cx, $base, $navy, $ch, true);
                $cx += self::gdWidth($sz, $ch) + (int) round($sz * 0.22);
            }
            return;
        }
        $lab = 'सुरक्षा कोड  ·  SECURITY CODE';
        $labSz = $h >= 76 ? 14 : 13;
        self::gdText($im, $labSz, $x1 + 22, $y1 + ($h >= 76 ? 26 : 22), $mut, $lab, false);
        $sz = $h >= 76 ? 32 : 27;
        $cx = $x1 + 22;
        $base = $y2 - ($h >= 76 ? 14 : 11);
        foreach (str_split(self::securityCodeGrouped($code)) as $ch) {
            if ($ch === ' ') { $cx += (int) round($sz * 0.55); continue; }
            self::gdText($im, $sz, $cx, $base, $navy, $ch, true);
            $cx += self::gdWidth($sz, $ch) + (int) round($sz * 0.22);
        }
    }

    /**
     * The heritage band at the foot of the PNG ticket (9 Oct 2026, owner:
     * "ticket dekhi chalani ma ni Nepali chiz haru: Sagarmatha ko photo,
     * Gautam Buddha, Nepal flag"): the art (Sagarmatha, prayer flags, the
     * seated Buddha, a stupa with Buddha eyes) over a navy caption strip —
     * Nepali words, Roman proper nouns — and both flags. Appended BELOW
     * everything else by growing the canvas, so no tile, QR or line above it
     * moves. Returns nothing; never throws.
     */
    private static function gdHeritage($im, int $W, int $y0, string $first, array $cream): void
    {
        try {
            $x1 = 36; $x2 = $W - 36;
            $artB = $y0 + self::PNG_ART_IMG_H;
            DocArt::band($im, $x1, $y0, $x2, $artB, 0, null, 0.5, 0.85);
            // caption strip
            $capB = $artB + 74;
            self::gdRoundedGrad($im, $x1, $artB, $x2, $capB, 0, DocArt::NAVY, DocArt::ROYAL);
            DocArt::goldFoil($im, $x1, $artB, $x2, $artB + 4);
            DocArt::roundCorners($im, $x1, $y0, $x2, $capB, 24, imagecolorallocate($im, $cream[0], $cream[1], $cream[2]));
            DocArt::roundOutline($im, $x1, $y0, $x2, $capB, 24, DocArt::col($im, DocArt::GOLD), 2);
            $flagsW = DocArt::flagPairWidth(38, 10);
            DocArt::flagPair($im, $x2 - 28 - $flagsW, $artB + 20, 38, 10, $first);
            $maxW = $x2 - 28 - $flagsW - 24 - ($x1 + 28);
            $l1 = 'सगरमाथा · Sagarmatha   ·   लुम्बिनी — गौतम बुद्धको जन्मस्थल · Lumbini';
            $sz = 20;
            foreach ([20, 18, 17, 16] as $try) { $sz = $try; if (self::gdWidth($try, $l1) <= $maxW) { break; } }
            if (self::gdWidth($sz, $l1) > $maxW) {
                $l1 = 'सगरमाथा · Sagarmatha   ·   लुम्बिनी · Lumbini';
            }
            self::gdText($im, $sz, $x1 + 28, $artB + 36, DocArt::col($im, DocArt::GOLD_LIGHT), $l1, true);
            $l2 = 'भारत–नेपाल मैत्री यात्रा  ·  India – Nepal, one journey';
            self::gdText($im, 15, $x1 + 28, $artB + 62, imagecolorallocate($im, 190, 204, 228), $l2, false);
        } catch (Throwable $e) {
            Logger::error('Ticket heritage band: ' . $e->getMessage());
        }
    }

    /* =================================================================
     *  Live trip status (surface layer)
     *
     *  bookings.status stays the source of truth for the transaction
     *  (pending / confirmed / cancelled / rejected). liveStatus() is the
     *  layer on top of it — where the trip is in time — so the ticket,
     *  the /api/track.php response and My Bookings can all show the
     *  same "Upcoming in 3h" / "Departing now" / "Departed" pill without
     *  each re-implementing the diff math.
     *
     *  The diff windows mirror admin/trips.php trips_urgency():
     *      dep + 5 min <= now                → Departed   (red)
     *      dep - 30 min <= now < dep + 5 min → Departing  (orange)
     *      dep - 30 min > now                → Upcoming   (blue) + human diff
     *
     *  Cancelled / rejected bookings return null — there is no live
     *  status for a trip the passenger will not board.
     *
     *  Accepts either the loadBooking() flat shape (dep_time + travel_date
     *  joined at the top level) or the BookingService::detail() shape
     *  (dep_time + travel_date on legs[0]) so every surface can call it.
     *
     * @param array<string, mixed> $booking
     * @return array{key:string,label:string,color:string,description:string}|null
     * ================================================================= */
    public static function liveStatus(array $booking, ?int $now = null): ?array
    {
        $status = strtolower((string) ($booking['status'] ?? ''));
        if (in_array($status, ['cancelled', 'rejected'], true)) {
            return null;
        }

        // Prefer top-level fields (loadBooking join); fall back to the
        // outbound leg (BookingService::detail packs departure per leg, and
        // orders a round trip's return leg first).
        $leg = null;
        foreach (($booking['legs'] ?? []) as $lg) {
            if (is_array($lg) && (string) ($lg['leg_type'] ?? 'outbound') === 'outbound') { $leg = $lg; break; }
        }
        if ($leg === null && is_array($booking['legs'][0] ?? null)) {
            $leg = $booking['legs'][0];
        }
        $pick = static function (string $k) use ($booking, $leg): string {
            $v = trim((string) ($booking[$k] ?? ''));
            return $v !== '' ? $v : trim((string) ($leg[$k] ?? ''));
        };
        $depTime    = $pick('dep_time');
        $travelDate = trim((string) ($booking['travel_date'] ?? ($booking['date'] ?? '')));
        if ($travelDate === '') {
            $travelDate = trim((string) ($leg['travel_date'] ?? ''));
        }

        /* A trip the office cancelled (schedules.status, mark-only — the
           booking stays confirmed until the owner settles it by hand) must
           never read "Upcoming in 5h" (D7-M2). No refund wording here. */
        if ($pick('schedule_status') === 'cancelled') {
            return [
                'key'         => 'trip_cancelled',
                'label'       => 'Trip cancelled · यात्रा रद्द',
                'color'       => 'red',
                'description' => 'The office will contact you about this ticket.',
            ];
        }
        if ($depTime === '' || $travelDate === '') {
            return null;
        }

        /* THIS passenger's pickup, not the route's origin (8 Oct 2026, D6-M2):
           a Mehsana 23:00 traveller on the 13:00 Surat coach read "Departed"
           from 13:05. Boarding::pickupTs() is the one rule the reminders use. */
        if (!class_exists('Boarding')) {
            require_once __DIR__ . '/boarding.php';
        }
        $depTs = Boarding::pickupTs($travelDate, $pick('boarding_stop'), $pick('boarding_time') ?: null, $depTime,
            $pick('route_dep_time'), (int) ($pick('schedule_slot') ?: 1), (int) ($pick('schedule_route_id') ?: 0));
        if ($depTs <= 0) {
            return null;
        }

        $now  = $now ?? time();
        $diff = $depTs - $now; // seconds until the pickup; negative = gone

        if ($diff <= -5 * 60) {
            return [
                'key'         => 'departed',
                'label'       => 'Departed · छुटिसक्यो',
                'color'       => 'red',
                'description' => 'This bus has already left.',
            ];
        }

        if ($diff <= 30 * 60) {
            return [
                'key'         => 'departing',
                'label'       => 'Departing now · छुट्दैछ',
                'color'       => 'orange',
                'description' => 'Reach the boarding point immediately.',
            ];
        }

        // Upcoming — humanise the diff so the passenger reads it at a glance.
        if ($diff < 3600) {
            $human = max(1, (int) round($diff / 60)) . ' min';
        } elseif ($diff < 86400) {
            $hours = $diff / 3600;
            $human = ($hours < 10 ? number_format($hours, 1) : (string) (int) round($hours)) . 'h';
        } else {
            $days = (int) floor($diff / 86400);
            $rh   = (int) round(($diff - $days * 86400) / 3600);
            $human = $days . 'd' . ($rh > 0 ? ' ' . $rh . 'h' : '');
        }

        return [
            'key'         => 'upcoming',
            'label'       => 'Upcoming in ' . $human,
            'color'       => 'blue',
            'description' => 'Bus departs at ' . date('g:i A', $depTs) . '.',
        ];
    }

    /** RGB triplet for a liveStatus() colour name — used by the PDF pill. */
    private static function liveStatusRgb(string $color): array
    {
        switch ($color) {
            case 'red':    return [176, 42, 42];
            case 'orange': return [196, 106, 0];
            case 'blue':   return [46, 95, 168];
        }
        return [80, 80, 100];
    }

    /**
     * Create the ticket row for a confirmed booking (idempotent).
     *
     * @return array<string, mixed> the ticket row
     */
    public static function issue(int $bookingId): array
    {
        $existing = Database::fetch('SELECT * FROM tickets WHERE booking_id = :b LIMIT 1', ['b' => $bookingId]);
        if ($existing !== null) {
            return $existing;
        }

        $booking = self::loadBooking($bookingId);
        $seats   = self::seatNumbers($bookingId);

        // The ticket number is minted before the payload so the QR can
        // carry it — a checkpoint scanning offline then has the printed
        // number in hand without looking anything up.
        $ticketNumber = generateTicketNumber();
        $booking['ticket_number'] = $ticketNumber;

        $payload = self::qrPayload($booking, $seats);
        $hash    = Security::sign($payload);

        $ticketId = Database::insert('tickets', [
            'booking_id'    => $bookingId,
            'ticket_number' => $ticketNumber,
            'qr_payload'    => $payload,
            'qr_hash'       => $hash,
            'issued_at'     => date('Y-m-d H:i:s'),
        ]);

        Logger::audit('ticket.issue', 'booking', $booking['pnr'], null, null, 'Ticket issued');

        return Database::fetch('SELECT * FROM tickets WHERE id = :id', ['id' => $ticketId]) ?? [];
    }

    /**
     * Re-mint the QR payload + hash for a booking whose trip details changed
     * (a reschedule moved its outbound leg to a new date / bus / seats).
     *
     * issue() is idempotent and will NOT refresh an existing row, and the
     * cached PDFs short-circuit on pdf_path — so without this the signed QR
     * (qr_payload/qr_hash) and the printed ticket keep the OLD date/seats/
     * dep_time/bus and would still validate to the wrong trip at the gate.
     *
     * The ticket_number and PNR are DELIBERATELY preserved (the passenger
     * already holds that number); only the trip-bound fields change. Cached
     * PDF/invoice paths are nulled so the next download regenerates them.
     *
     * $countRevision=false refreshes the ticket without printing CORRECTED
     * TICKET · REV n (30 Sep 2026): used when the desk credits the agent in
     * the same request that made the sale, before anyone has seen a copy.
     */
    public static function reissue(int $bookingId, bool $countRevision = true): void
    {
        $existing = Database::fetch('SELECT * FROM tickets WHERE booking_id = :b LIMIT 1', ['b' => $bookingId]);
        if ($existing === null) {
            // No ticket yet (still pending) — issue() will mint a correct one
            // on the next confirm/download, so there is nothing to refresh.
            return;
        }

        $booking = self::loadBooking($bookingId);
        $seats   = self::seatNumbers($bookingId);
        $booking['ticket_number'] = (string) ($existing['ticket_number'] ?? '');

        $payload = self::qrPayload($booking, $seats);
        $hash    = Security::sign($payload);

        Database::update('tickets', [
            'qr_payload'   => $payload,
            'qr_hash'      => $hash,
            'pdf_path'     => null,
            'png_path'     => null,
            'invoice_path' => null,
        ], 'booking_id = :b', ['b' => $bookingId]);

        // Delete the rendered files too (5 Sep 2026): renderWithLock skips
        // the render when the file already exists, so nulling the columns
        // alone let a rescheduled booking keep serving the OLD date/seats
        // from disk. Gone from disk = next download re-renders, guaranteed.
        $pnr = (string) ($booking['pnr'] ?? '');
        if ($pnr !== '') {
            @unlink(TICKET_PATH . '/ticket_' . $pnr . '.pdf');
            @unlink(TICKET_PATH . '/ticket_' . $pnr . '.png');
            @unlink(INVOICE_PATH . '/invoice_' . $pnr . '.pdf');
        }

        /* Revision count + time (19 Sep 2026, owner: 'correction lekhnu paryo'): the
           re-rendered ticket prints CORRECTED · REV n so an older copy on a phone or
           a WhatsApp chat is recognisably superseded. A missing column (migration not
           run) must never break the reissue itself.

           1 Oct 2026: a silent refresh still stamps reissued_at (markText() only
           prints it when the count is > 0), so imageUrl()'s version tag - and with
           it every WhatsApp / browser URL - changes on this regeneration too. The
           audit row is written by TicketSync::afterBookingChange(), the one
           orchestrator every booking change now goes through: one
           `ticket.regenerated` row per change, carrying the kind, the reason the
           desk typed and whether the passenger was told. */
        try {
            Database::query($countRevision
                ? 'UPDATE tickets SET reissue_count = reissue_count + 1, reissued_at = NOW() WHERE booking_id = :b'
                : 'UPDATE tickets SET reissued_at = NOW() WHERE booking_id = :b', ['b' => $bookingId]);
        } catch (Throwable $e) {
            Logger::exception($e);
        }
        self::forgetRevision($pnr);
    }

    /**
     * Generate (and cache) the ticket PDF, returning its absolute path.
     */
    public static function pdfPath(int $bookingId, bool $force = false): string
    {
        $ticket  = self::issue($bookingId);
        $booking = self::loadBooking($bookingId);

        $filename = 'ticket_' . $booking['pnr'] . '.pdf';
        $path     = TICKET_PATH . '/' . $filename;

        /* A PDF rendered before the layout last changed is stale — mirrors the
           PNG staleness check so a seat-label change re-renders each ticket ONCE
           on its next open rather than serving the pre-change document forever. */
        $stale = is_file($path) && (int) @filemtime($path) < self::staleBefore(self::PDF_LAYOUT_CHANGED);
        if (!$force && !$stale && is_file($path) && !empty($ticket['pdf_path'])) {
            return $path;
        }

        /* 24 Sep 2026: pass $stale through, as pngPath() does. It used to be
           !$force alone, so a stale PDF that already existed was "skipped" and
           printed / e-mailed tickets kept the old seat names forever. */
        self::renderWithLock($path, !($force || $stale), static function () use ($booking, $ticket, $path, $filename) {
            self::renderTicketPdf($booking, $ticket)->save($path);
            Database::update('tickets', ['pdf_path' => $filename], 'id = :id', ['id' => $ticket['id']]);
        });

        return $path;
    }

    /**
     * Generate (and cache) the GST invoice PDF.
     */
    public static function invoicePath(int $bookingId, bool $force = false): string
    {
        $ticket  = self::issue($bookingId);
        $booking = self::loadBooking($bookingId);

        $filename = 'invoice_' . $booking['pnr'] . '.pdf';
        $path     = INVOICE_PATH . '/' . $filename;

        // Same staleness rule as the ticket PDF: a cached invoice older than the
        // last layout change redraws once (it had no stamp before 4 Oct 2026).
        $stale = is_file($path) && (int) @filemtime($path) < self::staleBefore(self::PDF_LAYOUT_CHANGED);
        if (!$force && !$stale && is_file($path) && !empty($ticket['invoice_path'])) {
            return $path;
        }

        self::renderWithLock($path, !$force && !$stale, static function () use ($booking, $ticket, $path, $filename) {
            self::renderInvoicePdf($booking)->save($path);
            Database::update('tickets', ['invoice_path' => $filename], 'id = :id', ['id' => $ticket['id']]);
        });

        return $path;
    }

    /* =================================================================
     *  PNG ticket (5 Sep 2026) — the PRIMARY customer ticket.
     *
     *  One centralized HD image (1080x1620) for customer, agent and
     *  counter: opens instantly on a phone, forwards on WhatsApp without
     *  a PDF viewer, and is exactly what a future WhatsApp Business API
     *  sender will attach — that sender only needs pngPath($bookingId).
     *  The PDF stays for printing (admin/office) and as the bilingual
     *  legal document. Nepali labels are shaped by HarfBuzz (DevShape,
     *  24 Sep 2026) through gdText()/gdWidth(); proper nouns and codes stay
     *  Roman (house ticket rules). Since 9 Oct 2026 the image also carries
     *  the transparent emblem, both flags, a diagonal anti-fake microtext,
     *  the SECURITY CODE beside the verify QR and the heritage band
     *  (Sagarmatha · Lumbini) appended under the footer.
     * ================================================================= */

    /** Generate (and cache) the PNG ticket. Same contract as pdfPath(). */
    /**
     * A one-off DUPLICATE COPY of the current ticket for an office reprint.
     * Rendered to a temp file (the caller deletes it) so the cached original
     * the passenger holds is never replaced by the stamped copy.
     */
    public static function pngDuplicatePath(int $bookingId): string
    {
        $ticket  = self::issue($bookingId);
        $booking = self::loadBooking($bookingId);
        $path    = sys_get_temp_dir() . '/shg-dup-' . bin2hex(random_bytes(6)) . '.png';
        self::renderTicketPng($booking, $ticket, $path, 'dup');
        return $path;
    }

    /** PDF twin of pngDuplicatePath(). */
    public static function pdfDuplicatePath(int $bookingId): string
    {
        $ticket  = self::issue($bookingId);
        $booking = self::loadBooking($bookingId);
        $path    = sys_get_temp_dir() . '/shg-dup-' . bin2hex(random_bytes(6)) . '.pdf';
        self::renderTicketPdf($booking, $ticket, 'dup')->save($path);
        return $path;
    }

    public static function pngPath(int $bookingId, bool $force = false): string
    {
        $ticket  = self::issue($bookingId);
        $booking = self::loadBooking($bookingId);

        $filename = 'ticket_' . $booking['pnr'] . '.png';
        $path     = TICKET_PATH . '/' . $filename;

        /* A cached render from before the layout last changed is stale — it
           lacks whatever the newer layout adds (6 Sep 2026: the ISSUED BY
           line, fitted stop names). Compared by file mtime against a stamp
           bumped on every layout change, so an already-issued ticket
           re-renders ONCE on its next open instead of never. */
        $stale = is_file($path) && (int) @filemtime($path) < self::staleBefore(self::PNG_LAYOUT_CHANGED);
        if (!$force && !$stale && is_file($path) && !empty($ticket['png_path'])) {
            return $path;
        }

        self::renderWithLock($path, !($force || $stale), static function () use ($booking, $ticket, $path, $filename) {
            self::renderTicketPng($booking, $ticket, $path);
            Database::update('tickets', ['png_path' => $filename], 'id = :id', ['id' => $ticket['id']]);
        });

        return $path;
    }

    /** The one TTF shipped with the app (covers Latin + ₹). */
    private static function pngFont(): string
    {
        return dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari.ttf';
    }

    /** TTF text with optional faux bold (the shipped face has no Bold). */
    private static function gdText($im, float $size, int $x, int $y, int $col, string $text, bool $bold = false): void
    {
        /* Devanagari goes through HarfBuzz (includes/devshape.php) so the
           conjuncts, the reph and the i-matra come out as written. The old
           path below is the fallback when shaping is unavailable. */
        if (class_exists('DevShape') && DevShape::needs($text)
            && DevShape::gdText($im, $size, $x, $y, $col, $text, $bold ? [[0, 0], [1, 0], [0, 1]] : [[0, 0]])) {
            return;
        }
        $text = dev_shape($text);
        $f = self::pngFont();
        imagettftext($im, $size, 0, $x, $y, $col, $f, $text);
        if ($bold) {
            imagettftext($im, $size, 0, $x + 1, $y, $col, $f, $text);
            imagettftext($im, $size, 0, $x, $y + 1, $col, $f, $text);
        }
    }

    /** Rendered width of a TTF string, for centring / right-aligning. */
    private static function gdWidth(float $size, string $text): int
    {
        if (class_exists('DevShape') && DevShape::needs($text)) {
            $w = DevShape::gdWidth($size, $text);
            if ($w !== null) {
                return $w;
            }
        }
        $text = dev_shape($text);
        $box = imagettfbbox($size, 0, self::pngFont(), $text);

        return $box === false ? 0 : (int) abs($box[2] - $box[0]);
    }

    /** Filled rounded rectangle. */
    private static function gdRounded($im, int $x1, int $y1, int $x2, int $y2, int $r, int $col): void
    {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $col);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $col);
        imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $col);
        imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $col);
        imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $col);
        imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $col);
    }

    /* -----------------------------------------------------------------
     *  Depth helpers (21 Sep 2026, owner: "ticket ali color, full digital
     *  ho ta, design dimensions wala banao attractive").
     *
     *  GD has no shadow or gradient primitive, so these three build the
     *  only kinds of depth that survive WhatsApp: a soft drop shadow, a
     *  vertical gradient inside a rounded box, and a scanner-style corner
     *  frame. All three are drawn from flat fills — no alpha blending per
     *  pixel — because WhatsApp re-encodes the PNG to JPEG and soft alpha
     *  edges are the first thing it smears into mud.
     * --------------------------------------------------------------- */

    /** Soft drop shadow under a rounded box: $depth stacked, fading rings. */
    private static function gdShadow($im, int $x1, int $y1, int $x2, int $y2, int $r, int $depth = 7, int $baseR = 190, int $baseG = 196, int $baseB = 208): void
    {
        // Darkest ring closest to the card, lightening outward, so the eye
        // reads a lift rather than a grey outline.
        for ($i = $depth; $i >= 1; $i--) {
            $t   = $i / $depth;                       // 1 = outermost = lightest
            $col = imagecolorallocate(
                $im,
                (int) min(255, $baseR + (250 - $baseR) * $t),
                (int) min(255, $baseG + (243 - $baseG) * $t),
                (int) min(255, $baseB + (232 - $baseB) * $t)
            );
            self::gdRounded($im, $x1 + $i, $y1 + $i + 1, $x2 + $i, $y2 + $i + 1, $r, $col);
        }
    }

    /** Rounded box filled with a vertical gradient from RGB $a to RGB $b. */
    private static function gdRoundedGrad($im, int $x1, int $y1, int $x2, int $y2, int $r, array $a, array $b): void
    {
        $h = max(1, $y2 - $y1);
        // Paint the gradient as full-width lines, then re-round the corners
        // by overpainting them with the page behind — cheaper and crisper
        // than clipping every line to the rounded path.
        for ($y = $y1; $y <= $y2; $y++) {
            $t   = ($y - $y1) / $h;
            $col = imagecolorallocate(
                $im,
                (int) ($a[0] + ($b[0] - $a[0]) * $t),
                (int) ($a[1] + ($b[1] - $a[1]) * $t),
                (int) ($a[2] + ($b[2] - $a[2]) * $t)
            );
            // Inset the ends by the corner radius on the first/last rows so
            // the box still reads as rounded.
            $dy   = min($y - $y1, $y2 - $y);
            $inset = $dy >= $r ? 0 : (int) round($r - sqrt(max(0, $r * $r - ($r - $dy) * ($r - $dy))));
            imageline($im, $x1 + $inset, $y, $x2 - $inset, $y, $col);
        }
    }

    /** Scanner-style corner brackets — four L marks around a QR. */
    private static function gdScanFrame($im, int $x1, int $y1, int $x2, int $y2, int $len, int $thick, int $col): void
    {
        foreach ([[$x1, $y1, 1, 1], [$x2, $y1, -1, 1], [$x1, $y2, 1, -1], [$x2, $y2, -1, -1]] as [$cx, $cy, $sx, $sy]) {
            $hx1 = $sx > 0 ? $cx : $cx - $len;
            $hx2 = $sx > 0 ? $cx + $len : $cx;
            $hy1 = $sy > 0 ? $cy : $cy - $thick;
            imagefilledrectangle($im, $hx1, $hy1, $hx2, $hy1 + $thick, $col);   // horizontal arm
            $vy1 = $sy > 0 ? $cy : $cy - $len;
            $vy2 = $sy > 0 ? $cy + $len : $cy;
            $vx1 = $sx > 0 ? $cx : $cx - $thick;
            imagefilledrectangle($im, $vx1, $vy1, $vx1 + $thick, $vy2, $col);   // vertical arm
        }
    }

    private static function renderTicketPng(array $booking, array $ticket, string $path, string $mark = ''): void
    {
        /* The passenger list decides the height (owner ask, 10 Sep 2026:
           "kati jana manche chan tinko exact, list like 7 people"). A single
           traveller keeps the original 1080x1620; every extra one adds a
           line and the canvas grows underneath it, so a seven-seat family
           ticket names all seven with their berths instead of shrugging
           "+6 more". Everything below the list is shifted by $grow. */
        $seats = self::seatNumbers((int) $booking['id']);
        $paxes = array_values($booking['passengers'] ?? []);
        $paxN  = count($paxes) > 0 ? count($paxes) : max(1, count($seats));
        $listN = min($paxN, self::PNG_MAX_PAX_ROWS);
        $listH = 60 + $listN * 44 + 16 + ($paxN > $listN ? 32 : 0);
        $grow  = max(0, $listH - 108);          // 108 = the seat-chip band it replaced

        /* PAYMENT QR — MANDATORY on every ticket (9 Oct 2026, owner: "ticket
           ma sadhai payment QR hunu parcha"), always BESIDE the signed verify
           QR, never instead of it. payQr() picks the payload and its label:
           today's due as an amount-prefilled UPI link, else the company UPI
           without an amount, else the office WhatsApp pay chat. */
        $settlement = self::paymentSummary($booking);
        $currency = strtoupper((string) ($booking['currency'] ?? 'INR'));
        $payQ   = self::paymentQr($booking, $settlement);
        $payUpi = $payQ['payload'];                  // never '' - the pay card layout is the only layout now
        $upiVpa = $payQ['vpa'];
        $qrExt  = 350;

        $W = 1080; $H = 1648 + $grow + $qrExt;   // +28 (4 Oct 2026): the address line under the company name in the footer
        /* The blessing line (9 Oct 2026, gold) sits on the paper under the
           footer notes; the canvas grows for it and nothing above moves. */
        $blessing = DocArt::blessingLine();
        if ($blessing !== '') {
            $H += self::PNG_BLESS_H;
        }
        /* The heritage band (9 Oct 2026) is APPENDED under the footer: the
           canvas grows by PNG_ART_H and nothing above moves, so every tile,
           QR box and line keeps the coordinates the tests probe. */
        $artY = $H;
        $artOn = DocArt::heritageOn();
        if ($artOn) {
            $H += self::PNG_ART_H;
        }
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);

        /* GOLD palette (9 Oct 2026) — the shared DocArt values, so the
           ticket and the chalani are one family. */
        $navy   = DocArt::col($im, DocArt::NAVY);
        $navyHi = DocArt::col($im, DocArt::ROYAL);
        $orange = DocArt::col($im, DocArt::MAROON);   // the old orange accents are maroon now
        $gold   = DocArt::col($im, DocArt::GOLD_LIGHT);   // gold TEXT on navy - the light foil reads best
        $goldM  = DocArt::col($im, DocArt::GOLD);
        $goldD  = DocArt::col($im, DocArt::GOLD_DEEP);
        $green  = imagecolorallocate($im, 23, 122, 72);
        $red    = imagecolorallocate($im, 160, 32, 40);
        $ink    = DocArt::col($im, DocArt::INK);
        $mut    = imagecolorallocate($im, 84, 96, 118);     // darker than the web's muted: WhatsApp re-encodes to JPEG and thin light-grey small print is the first thing it smears (6 Sep 2026)
        $pageRgb = self::PNG_PAGE;
        $cream  = DocArt::col($im, $pageRgb);              // the paper around the card
        $white  = imagecolorallocate($im, 255, 255, 255);
        $line   = DocArt::col($im, [232, 214, 170]);
        $tile   = DocArt::col($im, self::PNG_TILE);
        $sub    = DocArt::col($im, [196, 206, 228]);       // small print on navy

        imagefilledrectangle($im, 0, 0, $W, $H, $cream);

        /* ---- Header band: navy -> royal + gold-foil double rule ---------- */
        DocArt::softShadow($im, 36, 36, $W - 36, 262, 24, 12, 6, [40, 30, 10], 12);
        DocArt::gradBox($im, 36, 36, $W - 36, 262, 24, DocArt::NAVY, DocArt::ROYAL);
        imagefilledrectangle($im, 36, 250, $W - 36, 262, $navyHi);   // square foot (the foil bar sits on it)

        /* The AGENT CODE chip claims the right edge of the band from y=124
           down, so the two brand lines beside it are clamped to stop short
           of it. The name itself steps down a size instead — a longer
           company_name must never run under the code. */
        $co     = self::companyOf($booking);   // the ISSUING company - India or Nepal (4 Oct 2026)
        $chipX1 = $W - 60 - 268;

        /* A quiet Himalayan ridge along the foot of the band (9 Oct 2026) —
           a shade lighter than the gradient, drawn before the emblem, the
           text and the chips so all of them sit on top of it. */
        DocArt::peaks($im, 36, 150, $W - 36, 250, [30, 64, 128], [36, 72, 140], [84, 112, 166]);

        /* Gold-foil DOUBLE rule round the band with corner ornaments, and
           the MANTRA line at the very top (9 Oct 2026, owner: "bhagwan ko
           mantra"), gold on a deeper navy strip inside the frame. */
        DocArt::goldFrame($im, 42, 40, $W - 42, 254, 4, 6, 14);
        $mantra = DocArt::mantraLine();
        if ($mantra !== '') {
            DocArt::gradBox($im, 51, 51, $W - 51, 80, 0, [8, 18, 42], DocArt::NAVY);
            imageline($im, 51, 81, $W - 51, 81, $goldM);
            $mSz = 17;
            while ($mSz > 11 && self::gdWidth($mSz, $mantra) > $W - 260) { $mSz--; }
            $mw = self::gdWidth($mSz, $mantra);
            $mx = (int) (($W - $mw) / 2);
            self::gdText($im, $mSz, $mx, 73, $gold, $mantra, false);
            // a small lotus-diamond either side of the line
            foreach ([$mx - 30, $mx + $mw + 30] as $dx) {
                imagefilledpolygon($im, [$dx, 58, $dx + 8, 66, $dx, 74, $dx - 8, 66], $goldM);
                imagefilledpolygon($im, [$dx, 61, $dx + 5, 66, $dx, 66], $gold);
                imageline($im, $dx + ($dx < $mx ? -14 : 14), 66, $dx + ($dx < $mx ? -60 : 60), 66, $goldD);
            }
        }

        /* The EMBLEM alone (9 Oct 2026, owner: "logo haru ma name ni join xa,
           background hataidinu, only logo matra rakhdeu"): no white chip, no
           baked name — the company name is the live text beside it. Aspect
           kept (the mark is ~1.93:1). Gold version (9 Oct 2026): it sits in
           an EMBOSSED GOLD SEAL with a royal-navy enamel face and a soft glow,
           so the navy globe still reads. A company's own uploaded logo (the
           Nepal company's) still wins. */
        $sealFace = DocArt::seal($im, 150, 154, 92, 70, [20, 44, 98]);
        DocArt::mark($im, $sealFace['x'], $sealFace['y'], $sealFace['w'], $sealFace['h'], true, self::ownLogo($co), 'center');
        $bx = 262;   // the seal is drawn with or without the emblem file
        $clampTo = static function (float $size, string $text, int $maxW): string {
            while ($text !== '' && self::gdWidth($size, $text) > $maxW) {
                $text = mb_substr($text, 0, mb_strlen($text) - 2) . '…';
            }
            return $text;
        };
        /* Resolved here rather than at the chip below, because the line
           under the company name needs the desk too (owner, 24 Sep 2026:
           "company ko name ko tala location lekhne thau"). */
        $issued = self::issuedBy($booking);

        /* Payment pill (top-right of the band) */
        $pay  = $booking['payment'] ?? null;
        $paid = self::paymentSummary($booking)['due'] <= 0;
        if ($paid) {
            $pillCol = $green;
            $method  = strtoupper((string) ($pay['method'] ?? ''));
            $pillTxt = 'PAID' . ($method !== '' ? ' · ' . ($method === 'COD' ? 'CASH' : $method) : '');
        } elseif ((int) ($booking['is_cod'] ?? 0) === 1) {
            $pillCol = $goldD;
            $pillTxt = 'PAY CASH ON BOARDING';
        } else {
            $pillCol = $red;
            $pillTxt = 'PAYMENT PENDING';
        }
        /* Under the mantra strip now (9 Oct 2026): 86..120, a gold rim. */
        $pw = self::gdWidth(16, $pillTxt) + 40;
        self::gdRounded($im, $W - 60 - $pw - 2, 85, $W - 58, 121, 18, $goldM);
        self::gdRounded($im, $W - 60 - $pw, 87, $W - 60, 119, 16, $pillCol);
        self::gdText($im, 16, $W - 60 - $pw + 20, 110, $white, $pillTxt, true);

        $brand = self::latinUpper($co['name']);
        $bSz   = 30;
        /* Stops short of the chip / pay pill on the right (4 Oct 2026): a
           longer company name - the Nepal company's - ran under the pill. */
        $brandMax = min($chipX1, $W - 60 - $pw) - 24 - $bx;
        foreach ([30, 27, 24, 21, 18] as $try) { $bSz = $try; if (self::gdWidth($try, $brand) <= $brandMax) { break; } }
        self::gdText($im, $bSz, $bx, 122, $white, $clampTo($bSz, $brand, $brandMax), true);
        /* Which way this ticket runs (3 Oct 2026, owner: "Nepal bata pathauda
           ticket ma ni Nepal to India"): the return run says so in the band
           instead of the fixed "Gujarat <-> Rupaidiha" both runs printed. */
        if (!class_exists('Boarding')) {
            require_once __DIR__ . '/boarding.php';
        }
        $toIndia  = Boarding::routeDirection(['from_city' => (string) ($booking['from_city'] ?? ''), 'to_city' => (string) ($booking['to_city'] ?? '')]) === 'toIndia';
        $corridor = $toIndia ? 'Nepal -> India  ·  Rupaidiha -> Gujarat' : 'India -> Nepal  ·  Gujarat -> Rupaidiha';
        /* Label in Nepali, the countries Roman (house ticket rule), then the
           two flags — the side the bus leaves from first (9 Oct 2026). */
        $flagsW = DocArt::flagPairWidth(22, 8);
        $eLine  = $clampTo(19, $toIndia ? 'ई-टिकट  ·  NEPAL -> INDIA' : 'ई-टिकट  ·  INDIA -> NEPAL', $chipX1 - 24 - $bx - $flagsW - 18);
        self::gdText($im, 19, $bx, 162, $gold, $eLine, false);
        DocArt::flagPair($im, $bx + self::gdWidth(19, $eLine) + 18, 143, 22, 8, $toIndia ? 'NP' : 'IN');
        /* The desk this ticket was cut at, directly under the company name.
           A Nepalgunj walk-in should be able to see which window sold it
           without reading the small print. No desk (an online sale, or a
           counter that has not been given a location yet) leaves the line
           exactly as it was — the route and the website. */
        $deskLine = self::latin($issued['location'] ?? '');
        /* This line is ~480px wide and the desk name is the new thing on it,
           so the line gives ground rather than the desk: try desk + route,
           then desk + website, then the desk alone, and take the first that
           fits WHOLE. Nothing is lost by dropping the other two — the route
           is set in 60px letters in the middle of the ticket (STV -> RPD)
           and the website is printed in full beside the QR. With no desk
           the line is exactly what it always was. */
        $brandSub = $corridor . '  ·  ' . self::latin($co['web']);
        if (self::gdWidth(16, $brandSub) > $chipX1 - 24 - $bx) {
            $brandSub = $corridor;
        }
        if ($deskLine !== '') {
            foreach ([
                $deskLine . '  ·  ' . $corridor,
                $deskLine . '  ·  ' . self::latin($co['web']),
                $deskLine,
            ] as $try) {
                $brandSub = $try;
                if (self::gdWidth(16, $try) <= $chipX1 - 24 - $bx) { break; }
            }
        }
        self::gdText($im, 16, $bx, 200, imagecolorallocate($im, 170, 185, 215),
            $clampTo(16, $brandSub, $chipX1 - 24 - $bx), false);


        /* ---- AGENT CODE chip (owner ask 10 Sep 2026: "agent code pani
           include hunu paryo — kasko through bata kateko tha hos") -------
           The code used to appear only as 17px grey small print at the very
           bottom of the ticket, which is the first thing WhatsApp's JPEG
           pass smears. It now also sits in the header, in the same size
           class as the PNR, so whoever picks the ticket up reads the code
           before anything else — and a commission question is settled off
           the picture instead of off the register. */
        $iCode  = self::latinUpper($issued['code']);
        $chipX2 = $W - 60;
        DocArt::softShadow($im, $chipX1, 124, $chipX2, 224, 18, 8, 4, [0, 0, 0], 22);
        DocArt::gradBox($im, $chipX1, 124, $chipX2, 224, 18, [10, 24, 54], DocArt::ROYAL);
        DocArt::roundOutline($im, $chipX1, 124, $chipX2, 224, 18, $goldM, 2);
        /* The chip says WHERE the ticket was cut, then WHO. An agent's SHG
           number is the identity that settles a commission, so it takes the
           big line; a counter or office sale has no number, so the desk that
           sold it takes that line instead of repeating the word above it. */
        self::gdText($im, 15, $chipX1 + 20, 154, $gold, $issued['label'], false);
        $iBig = $issued['kind'] === 'agent'
            ? $iCode
            : self::display($issued['kind'] === 'online' ? $co['web'] : $issued['name']);
        $cSz = 30;
        foreach ([30, 26, 22, 19, 17] as $try) { $cSz = $try; if (self::gdWidth($try, $iBig) <= 228) { break; } }
        self::gdText($im, $cSz, $chipX1 + 20, 196, $white, $clampTo($cSz, $iBig, 228), true);
        /* The third line of the chip. An agent sale spends it on the person
           behind the code (the code is the identity; the name is the
           courtesy). A counter or office sale has already spent the big
           line on the seller's NAME, so this one carries WHERE they sold it
           — the half of "by name and counter location" that was missing. */
        if ($issued['kind'] === 'agent') {
            $iWho = self::display($issued['name']);
        } else {
            /* 228px at 14px is about 30 characters. "Nepalgunj — Bus Park
               (NPJ)" is 26 and fits; "Nepalgunj — Dhamboji Chowk (NPJD)" is
               not, and clamping it produced "Nepalgunj — Dhamboji Cho…" —
               an ellipsis where the CODE should be, which is the one part
               of a desk name that has to survive. So: try the whole label,
               then the town without its bracket, then the bare code, and
               take the first that fits whole. */
            $iWho = '';
            foreach ([
                self::latin((string) ($issued['location'] ?? '')),
                self::latin(trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', (string) ($issued['location'] ?? '')))),
                self::latinUpper((string) ($issued['locCode'] ?? '')),
            ] as $try) {
                if ($try !== '' && self::gdWidth(14, $try) <= 228) { $iWho = $try; break; }
            }
        }
        self::gdText($im, 14, $chipX1 + 20, 216, imagecolorallocate($im, 170, 185, 215),
            $clampTo(14, $iWho, 228), false);

        /* ---- White card ---------------------------------------------- */
        // 21 Sep 2026: the card now sits ON the cream page instead of being
        // painted flat against it — a soft shadow under the right and lower
        // edges is what makes the whole ticket read as a physical pass.
        $cardB = 1544 + $grow + $qrExt;
        DocArt::softShadow($im, 36, 300, $W - 36, $cardB, 24, 14, 8, [70, 50, 10], 14);
        self::gdRounded($im, 36, 262, $W - 36, $cardB, 24, DocArt::col($im, DocArt::CREAM));
        imagefilledrectangle($im, 36, 262, $W - 36, 300, DocArt::col($im, DocArt::CREAM));        // square the join
        imagefilledrectangle($im, 36, 254, $W - 36, 262, $goldM);
        DocArt::goldFoil($im, 36, 254, $W - 36, 263);                    // the foil bar between band and card

        /* Mandala watermark (9 Oct 2026) — a faint gold rosette in the
           middle of the ivory card, then the anti-fake microtext; both go
           down before anything else lands on the card, so tiles and QRs
           paint over them and the quiet zones stay clean. */
        DocArt::mandala($im, (int) ($W / 2), 560, 300, DocArt::GOLD, 108);
        DocArt::mandala($im, (int) ($W / 2), 1150 + $grow, 250, DocArt::GOLD, 114);
        $pnr = (string) $booking['pnr'];
        $wmName = strtoupper((string) (preg_split('/\s+/', self::latin((string) ($paxes[0]['full_name'] ?? ''))) ?: [''])[0]);
        self::gdWatermark($im, 40, 268, $W - 40, 1540 + $grow + $qrExt,
            implode('  ·  ', array_filter([$pnr, $wmName, (string) ($ticket['ticket_number'] ?? ''), self::latinUpper((string) $co['name'])], static fn(string $s): bool => $s !== '')));
        // gold hairline inside the card edge + corner ornaments
        DocArt::roundOutline($im, 46, 272, $W - 46, $cardB - 10, 18, $goldM, 1);
        DocArt::corners($im, 46, 272, $W - 46, $cardB - 10, 12);

        /* PNR hero */
        self::gdText($im, 18, (int) (($W - self::gdWidth(18, 'टिकट / PNR')) / 2), 340, $mut, 'टिकट / PNR', false);
        $pnrW = self::gdWidth(44, $pnr) + 8;
        self::gdText($im, 44, (int) (($W - $pnrW) / 2), 408, $navy, $pnr, true);
        DocArt::goldFoil($im, (int) (($W - 200) / 2), 427, (int) (($W + 200) / 2), 433);
        foreach ([(int) (($W - 200) / 2) - 12, (int) (($W + 200) / 2) + 12] as $dx) {
            imagefilledpolygon($im, [$dx, 422, $dx + 8, 430, $dx, 438, $dx - 8, 430], $goldD);
        }
        if (!empty($ticket['ticket_number'])) {
            $tn = 'Ticket ' . (string) $ticket['ticket_number'];
            self::gdText($im, 17, (int) (($W - self::gdWidth(17, $tn)) / 2), 466, $mut, $tn, false);
        }

        /* Route: big city codes + arrow — the passenger's OWN boarding and
           drop points, not the route's ends (tripEnds()). A Nana Chiloda
           passenger reads AMD · Nana Chiloda · DEP 9:00 PM, not Surat 1 PM. */
        $ends  = self::tripEnds($booking);
        $fromC = $ends['fromCode'];
        $toC   = $ends['toCode'];
        $fromN = self::latin($ends['fromName']);
        $toN   = self::latin($ends['toName']);
        $nameSz = 20;
        foreach ([20, 17, 15] as $try) {
            $nameSz = $try;
            if (max(self::gdWidth($try, $fromN), self::gdWidth($try, $toN)) <= 250) { break; }
        }
        self::gdText($im, 56, 120, 590, $navy, $fromC, true);
        self::gdText($im, $nameSz, 122, 630, $mut, $fromN, false);
        $tw = self::gdWidth(56, $toC);
        self::gdText($im, 56, $W - 120 - $tw, 590, $navy, $toC, true);
        $tw2 = self::gdWidth($nameSz, $toN);
        self::gdText($im, $nameSz, $W - 122 - $tw2, 630, $mut, $toN, false);
        // arrow — gold foil shaft, gold head, a ringed origin dot
        DocArt::goldFoil($im, 384, 565, 660, 573);
        imagefilledpolygon($im, [658, 552, 658, 586, 694, 569], $goldD);
        imagefilledpolygon($im, [662, 559, 662, 579, 684, 569], $goldM);
        imagefilledellipse($im, 380, 569, 22, 22, $goldD);
        imagefilledellipse($im, 380, 569, 12, 12, DocArt::col($im, DocArt::GOLD_LIGHT));

        /* Date · pickup time · bus strip — the time the bus reaches THIS
           passenger's boarding point, which is what "DEP" means to them. */
        $dep  = $ends['dep'] !== '' ? $ends['dep'] : substr((string) ($booking['dep_time'] ?? ''), 0, 5);
        $dt   = formatDate((string) ($booking['travel_date'] ?? ''), 'D, j M Y');
        $bus  = trim((string) ($booking['bus_number'] ?? ''));
        $depTxt = $dep !== '' ? self::zonedTime($dep, $co) : '';
        $strip = strtoupper($dt) . '  ·  DEP ' . ($depTxt !== '' ? $depTxt : formatTime($booking['dep_time'] ?? null))
               . ($bus !== '' ? '  ·  BUS ' . $bus : '');
        /* 8 Oct 2026: starts larger (26px) — date and pickup time are what a
           passenger looks for first; it still steps down to fit the card. */
        $stripSz = 26;
        foreach ([26, 24, 22, 20, 18, 16] as $try) { $stripSz = $try; if (self::gdWidth($try, $strip) <= $W - 150) { break; } }
        self::gdText($im, $stripSz, (int) (($W - self::gdWidth($stripSz, $strip)) / 2), 690, $ink, $strip, true);

        /* Perforation — gold dots, half-moon notches in the paper colour */
        DocArt::tearLine($im, 36, $W - 36, 727, $pageRgb, 18);

        /* ---- Detail tiles (2 columns) -------------------------------- */
        $lead   = self::displayOr((string) ($paxes[0]['full_name'] ?? ''));
        $paxTxt = $lead . ($paxN > 1 ? '  +' . ($paxN - 1) . ' जना' : '');
        $phone  = (string) ($booking['contact_phone'] ?? '');
        if ($phone === '0000000000') { $phone = '';  }   // counter walk-in placeholder — not a real number
        // `?:` not `??` — an EMPTY string slipped past the null-coalesce and
        // left the tile blank instead of showing the intended dash.
        $board  = self::displayOr(self::stopForPrint((string) ($booking['boarding_stop'] ?? '')));
        $geo    = self::pickupGeo($booking);
        if ($geo !== null && $geo['landmark'] !== '' && stripos($board, $geo['landmark']) === false) {
            $board .= ' · ' . self::display($geo['landmark']);   // landmark the crew meets you at (8 Oct 2026)
        }
        $drop   = self::displayOr(self::stopForPrint((string) ($booking['drop_stop'] ?? '')));

        /* Draws through self::gdText, NOT imagettftext (audit, 11 Sep 2026).
           The closure measured through gdWidth — which shapes — and drew around
           it, so a Devanagari name came out with its i-matra on the wrong side
           in the PASSENGER tile while the list 130px below spelled the SAME
           name correctly. One image, two spellings of one passenger. */
        $tileRow = static function ($im2, int $x, int $y, int $w, string $label, string $value, $lab, $val, $bg, $fn, $fw) {
            // Gold card (9 Oct 2026): soft lift, warm-white face, gold hairline, a gold tab.
            DocArt::softShadow($im2, $x, $y, $x + $w, $y + 108, 14, 7, 4, [90, 64, 12], 12);
            $fn($im2, $x, $y, $x + $w, $y + 108, 14, $bg);
            DocArt::roundOutline($im2, $x, $y, $x + $w, $y + 108, 14, DocArt::col($im2, [222, 192, 110]), 1);
            imagefilledrectangle($im2, $x + 22, $y + 46, $x + 58, $y + 47, DocArt::col($im2, DocArt::GOLD));
            self::gdText($im2, 16, $x + 22, $y + 38, $lab, $label, false);
            /* Fit before cutting (6 Sep 2026): step the size 23 → 20 → 18
               first, so "Rupaidiha India-Nepal border" reads whole instead of
               ending in "bord…" as it did on a real ticket. Only if it still
               will not fit at 18 does it get the ellipsis. */
            $v  = $value;
            $sz = 23;
            foreach ([23, 20, 18] as $try) { $sz = $try; if ($fw($try, $v) <= $w - 44) { break; } }
            if ($fw($sz, $v) <= $w - 44) {
                $by = $y + 82 - (int) round((23 - $sz) / 2);   // stays optically centred in the tile
                self::gdText($im2, $sz, $x + 22, $by, $val, $v, true);
            } else {
                /* Still too long at 18: break onto a second line at the last
                   space that fits, rather than cut the place name. The tile
                   is 108px tall — two 18px lines sit at +64 and +92 under
                   the label. Only the second line may end in an ellipsis. */
                $words = preg_split('/\s+/u', $v) ?: [$v];
                $l1 = ''; $l2 = '';
                /* Once a word spills, EVERY later word follows it (8 Oct
                   2026): a shorter later word used to hop back onto line 1
                   and the place read out of order. */
                $spilled = false;
                foreach ($words as $wd) {
                    $try = $l1 === '' ? $wd : $l1 . ' ' . $wd;
                    if (!$spilled && $fw(18, $try) <= $w - 44) { $l1 = $try; } else { $spilled = true; $l2 = trim($l2 . ' ' . $wd); }
                }
                if ($l1 === '') { $l1 = $words[0]; $l2 = trim(implode(' ', array_slice($words, 1))); }
                while ($l2 !== '' && $fw(18, $l2) > $w - 44) { $l2 = mb_substr($l2, 0, mb_strlen($l2) - 4) . '…'; }
                foreach ([[$l1, $y + 64], [$l2, $y + 92]] as [$ln, $ly]) {
                    if ($ln === '') { continue; }
                    self::gdText($im2, 18, $x + 22, $ly, $val, $ln, true);
                }
            }
        };
        $roundedFn = [self::class, 'gdRounded'];
        $widthFn   = [self::class, 'gdWidth'];
        // 60..528 and 552..1020: flush with the passenger panel below, inside
        // the card (was ($W - 72 - 24) / 2 — the right tiles ran 24px past it).
        $colW = ($W - 120 - 24) / 2;
        $tileRow($im, 60, 764, (int) $colW, 'यात्रु  ·  PASSENGER', $paxTxt, $mut, $ink, $tile, $roundedFn, $widthFn);
        $tileRow($im, 60 + (int) $colW + 24, 764, (int) $colW, 'मोबाइल  ·  MOBILE', $phone !== '' ? $phone : '-', $mut, $ink, $tile, $roundedFn, $widthFn);
        $tileRow($im, 60, 892, (int) $colW, 'चढ्ने ठाउँ  ·  BOARDING POINT', $board, $mut, $ink, $tile, $roundedFn, $widthFn);
        /* झर्ने, not ओर्लिने: the i-matra after a र्ल conjunct is the one
           case dev_shape() leaves alone, and this label is ours to choose. */
        $tileRow($im, 60 + (int) $colW + 24, 892, (int) $colW, 'झर्ने ठाउँ  ·  DROP POINT', $drop, $mut, $ink, $tile, $roundedFn, $widthFn);

        /* ---- यात्रु र सिट — exactly who is travelling, and how many ----
           The seat chips used to sit here on their own, with the names
           reduced to "Lead +6 more". The crew at the door needs the other
           six names, and the family holding the ticket needs to see that
           all seven are on it — so this is a numbered list, one row per
           passenger with their own berth, headed by the count in Nepali. */
        $panelY = 1020;
        DocArt::softShadow($im, 60, $panelY, $W - 60, $panelY + $listH, 14, 7, 4, [90, 64, 12], 12);
        self::gdRounded($im, 60, $panelY, $W - 60, $panelY + $listH, 14, $tile);
        DocArt::roundOutline($im, 60, $panelY, $W - 60, $panelY + $listH, 14, DocArt::col($im, [222, 192, 110]), 1);
        self::gdText($im, 17, 82, $panelY + 38, $mut, 'यात्रु र सिट  ·  PASSENGERS & SEATS', false);
        $cntTxt = 'जम्मा ' . self::nepaliDigits((string) $paxN) . ' जना';
        $cntW   = self::gdWidth(25, $cntTxt) + 40;
        self::gdRounded($im, $W - 84 - $cntW, $panelY + 10, $W - 80, $panelY + 60, 16, $goldM);
        DocArt::gradBox($im, $W - 82 - $cntW, $panelY + 12, $W - 82, $panelY + 58, 14, DocArt::NAVY, DocArt::ROYAL);
        self::gdText($im, 25, $W - 82 - $cntW + 20, $panelY + 46, $white, $cntTxt, true);

        $ly = $panelY + 60;
        for ($i = 0; $i < $listN; $i++) {
            $nm = self::displayOr((string) ($paxes[$i]['full_name'] ?? ''));
            $canon = (string) ($paxes[$i]['seat_no'] ?? ($seats[$i] ?? ''));
            $sn = self::display(self::seatLabel($canon, $booking));
            /* The floor, from the canonical L/U id (8 Oct 2026): "C8 · माथि 2F"
               says climb to the upper deck; "A3 · तल 1F" the lower. Nepali
               word, Roman code — the house ticket rule. */
            $fl = self::floorCode($canon);
            if ($sn !== '' && $fl !== '') {
                $sn .= ' · ' . ($fl === '2F' ? 'माथि 2F' : 'तल 1F');
            }
            $chipW2 = $sn !== '' ? self::gdWidth(21, $sn) + 34 : 0;
            if ($sn !== '') {
                // maroon chip on a gold rim (9 Oct 2026)
                self::gdRounded($im, $W - 84 - $chipW2, $ly, $W - 80, $ly + 42, 14, $goldM);
                DocArt::gradBox($im, $W - 82 - $chipW2, $ly + 2, $W - 82, $ly + 40, 12, [150, 40, 56], DocArt::MAROON);
                self::gdText($im, 21, $W - 82 - $chipW2 + 17, $ly + 31, $white, $sn, true);
            }
            if ($i > 0) {
                imageline($im, 82, $ly - 2, $W - 100 - $chipW2, $ly - 2, DocArt::col($im, [236, 220, 180]));
            }
            self::gdText($im, 18, 82, $ly + 30, $mut, self::nepaliDigits((string) ($i + 1)) . '.', false);
            $nmMax = $W - 82 - $chipW2 - 20 - 130;
            $nmSz  = 22;
            foreach ([22, 20, 18] as $try) { $nmSz = $try; if (self::gdWidth($try, $nm) <= $nmMax) { break; } }
            while ($nm !== '' && self::gdWidth($nmSz, $nm) > $nmMax) { $nm = mb_substr($nm, 0, mb_strlen($nm) - 2) . '…'; }
            self::gdText($im, $nmSz, 130, $ly + 30, $ink, $nm, true);
            $ly += 44;
        }
        if ($paxN > $listN) {
            self::gdText($im, 18, 130, $ly + 22, $mut,
                '+ ' . self::nepaliDigits((string) ($paxN - $listN)) . ' जना थप  ·  ' . ($paxN - $listN) . ' more', false);
        }

        /* Fare band — the one number everybody looks for, so it gets the
           deepest treatment on the ticket: its own shadow, a navy gradient
           instead of a flat fill, and a gold hairline along the top edge
           that catches the eye the way a foil stripe does on a real pass. */
        DocArt::softShadow($im, 60, 1152 + $grow, $W - 60, 1250 + $grow, 16, 10, 6, [20, 14, 4], 16);
        self::gdRounded($im, 58, 1150 + $grow, $W - 58, 1252 + $grow, 18, $goldM);
        DocArt::gradBox($im, 60, 1152 + $grow, $W - 60, 1250 + $grow, 16, DocArt::NAVY, DocArt::ROYAL);
        DocArt::goldFoil($im, 76, 1152 + $grow, $W - 76, 1156 + $grow);
        DocArt::guilloche($im, 560, 1170 + $grow, $W - 80, 1240 + $grow, DocArt::GOLD, 112, 4);
        self::gdText($im, 17, 88, 1192 + $grow, $gold, 'जम्मा भाडा  ·  TOTAL FARE', false);
        $amt = $currency . ' ' . number_format((float) ($booking['total_amount'] ?? 0), 2);
        self::gdText($im, 36, 88, 1236 + $grow, $white, $amt, true);
        /* A ticket cut at a Nepal-side desk (3 Oct 2026) also says what the
           passenger paid in NPR — the fare stays INR; this is the same
           "≈ NPR" conversion aid the desk saw, at Admin → Settings' rate. */
        $nprRate = Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6);
        /* A sale stamped with its desk (4 Oct 2026) prints the NPR FROZEN on
           it - the rate of that second, never today's - and prints nothing
           when the desk took rupees. Only an unstamped old row still converts
           at today's rate through the seller's profile. */
        $nprFrozen = self::frozenNpr($booking);
        if (trim((string) ($booking['counter_code'] ?? '')) !== '') {
            if ($nprFrozen > 0) {
                self::gdText($im, 20, 88 + self::gdWidth(36, $amt) + 22, 1230 + $grow, $gold, '(NPR ' . number_format($nprFrozen) . ')', true);
            }
        } elseif ($currency === 'INR' && $nprRate > 0 && ($issued['kind'] ?? '') !== 'online'
            && Settings::isNepalDesk((string) ($booking['agent_counter_code'] ?? ''), (string) ($booking['agent_counter'] ?? ''))) {
            $nprTxt = '(NPR ' . number_format(round((float) ($booking['total_amount'] ?? 0) * $nprRate)) . ')';   // the font has no U+2248
            self::gdText($im, 20, 88 + self::gdWidth(36, $amt) + 22, 1230 + $grow, $gold, $nprTxt, true);
        }
        $fps = (float) ($booking['fare_per_seat'] ?? 0);
        /* Never fewer than the list above it names: a private cabin holds more
           berths than passengers, and a booking whose seat rows lag its passenger
           rows must not print "1 seat" under seven names. */
        $seatN = max(count($seats), $paxN);
        $sub = $seatN > 1 && $fps > 0
            ? $seatN . ' x ₹' . number_format($fps)
            : $seatN . ' सिट';
        $sw2 = self::gdWidth(19, $sub);
        self::gdText($im, 19, $W - 88 - $sw2, 1222 + $grow, imagecolorallocate($im, 200, 210, 230), $sub, false);

        /* What the offer saved them, on the ticket itself (owner ask, 20 Sep
           2026). The fare band already shows what they paid; without this the
           discount lived only in the checkout screen they have closed. */
        $offerCut = (float) ($booking['coupon_discount'] ?? 0);
        if ($offerCut > 0) {
            $offerTxt = 'तपाईंले बचाउनुभयो  ·  SAVED ₹ ' . number_format($offerCut);
            $ow = self::gdWidth(18, $offerTxt);
            self::gdText($im, 18, $W - 88 - $ow, 1192 + $grow, $gold, $offerTxt, true);
        }

        /* Separate, labelled QR cards. Integer modules + four-module quiet
           zones stay crisp in the original PNG and WhatsApp's image copy. */
        $qrData = appUrl('verify-ticket.php') . '?pnr=' . urlencode($pnr) . '&k=' . self::downloadToken($pnr);
        if (!class_exists('QrCode')) { require_once __DIR__ . '/qr.php'; }
        $qrFit = static function (string $payload, int $maxSize): int {
            $modules = count(QrCode::matrix($payload, QrCode::ECC_M)) + 8;
            return max(1, intdiv($maxSize, $modules)) * $modules;
        };
        $drawQr = static function (string $payload, int $x, int $y, int $maxSize) use ($im): void {
            $tmp = tempnam(sys_get_temp_dir(), 'shgqr');
            try {
                $modules = count(QrCode::matrix($payload, QrCode::ECC_M)) + 8;
                $scale = max(1, intdiv($maxSize, $modules));
                QrCode::png($payload, $tmp, $scale, 4, QrCode::ECC_M);
                $qr = imagecreatefromstring((string) file_get_contents($tmp));
                if ($qr === false) { throw new RuntimeException('QR image unavailable'); }
                imagecopy($im, $qr, $x, $y, 0, 0, imagesx($qr), imagesy($qr));
                imagedestroy($qr);
            } finally { @unlink($tmp); }
        };
        /* The STUB (9 Oct 2026): a second perforated tear line between the
           fare and the three QR codes. */
        DocArt::tearLine($im, 36, $W - 36, 1261 + $grow, $pageRgb, 14);

        self::gdText($im, 20, 76, 1300 + $grow, $navy, 'टिकट जाँच  ·  VERIFY', true);
        try {
            $vs = $qrFit($qrData, 245);
            self::gdRounded($im, 68, 1312 + $grow, 84 + $vs, 1328 + $grow + $vs, 10, $goldM);
            self::gdRounded($im, 70, 1314 + $grow, 82 + $vs, 1326 + $grow + $vs, 9, $white);
            $drawQr($qrData, 76, 1320 + $grow, 245);
        }
        catch (Throwable $e) { Logger::error('Ticket verification QR: ' . $e->getMessage()); }
        /* SECURITY CODE (9 Oct 2026, anti-fake) — keyed to this revision of
           the ticket; verify-ticket.php shows the same letters. Under the
           verify + map QRs, the pay card holds the right half. */
        $secCode = self::securityCode($pnr, (string) ($ticket['qr_hash'] ?? ''));
        self::gdSecurityTile($im, 60, 1566 + $grow, 540, 1628 + $grow, $secCode);
        /* Pickup map QR (8 Oct 2026, master prompt 2.0 · Settings -> ticket_maps_qr):
           scanning opens directions to the OFFICIAL pickup point - never the
           passenger's own position. It sits BESIDE the verification QR (x
           340..530, the pay card starts at 550) with its caption under it;
           the verification QR keeps x 76..321, so the two never touch. */
        $mapsQr = $geo !== null && Settings::getBool('ticket_maps_qr', true);
        if ($mapsQr) {
            try {
                $ms = $qrFit(self::pickupMapsUrl($geo), 190);
                self::gdRounded($im, 340 - 4, 1330 + $grow - 4, 340 + $ms + 4, 1330 + $grow + $ms + 4, 8, $goldM);
                self::gdRounded($im, 340 - 2, 1330 + $grow - 2, 340 + $ms + 2, 1330 + $grow + $ms + 2, 7, $white);
                $drawQr(self::pickupMapsUrl($geo), 340, 1330 + $grow, 190);
                self::gdText($im, 16, 340, 1545 + $grow, $navy, 'PICKUP MAP · नक्सा', true);
            } catch (Throwable $e) { Logger::error('Pickup map QR: ' . $e->getMessage()); $mapsQr = false; }
        }
        if (!$mapsQr) {
            /* No map: the free column states the payment in words. */
            $stX = 344;
            self::gdText($im, 24, $stX, 1366 + $grow, $settlement['due'] <= 0 ? $green : $orange,
                $settlement['due'] <= 0 ? 'PAID' : 'PAYMENT DUE', true);
            $ref = (string) ($booking['payment']['utr_number'] ?? '');
            if ($ref !== '') {
                self::gdText($im, 16, $stX, 1400 + $grow, $mut, $clampTo(16, self::latin($ref), 192), false);
            }
            self::gdText($im, 16, $stX, 1440 + $grow, $mut, 'बोर्डिङमा देखाउनुहोस्', false);
            self::gdText($im, 15, $stX, 1466 + $grow, $mut, 'Show at boarding', false);
        }

        /* ---- PAYMENT card: mandatory, right half ---------------------- */
        $pcB = 1812 + $grow;
        DocArt::softShadow($im, 550, 1270 + $grow, 1010, $pcB, 16, 10, 6, [70, 50, 10], 16);
        self::gdRounded($im, 550, 1270 + $grow, 1010, $pcB, 16, $white);
        DocArt::roundOutline($im, 550, 1270 + $grow, 1010, $pcB, 16, $goldM, 2);
        DocArt::roundOutline($im, 556, 1276 + $grow, 1004, $pcB - 6, 12, DocArt::col($im, [230, 208, 150]), 1);
        DocArt::gradBox($im, 556, 1276 + $grow, 1004, 1326 + $grow, 12,
            $payQ['mode'] === 'due' ? [150, 40, 56] : DocArt::ROYAL, $payQ['mode'] === 'due' ? DocArt::MAROON : DocArt::NAVY);
        imagefilledrectangle($im, 556, 1316 + $grow, 1004, 1326 + $grow, $payQ['mode'] === 'due' ? $orange : $navy);
        DocArt::goldFoil($im, 556, 1326 + $grow, 1004, 1329 + $grow);
        $badgeW = 0;
        if ($payQ['mode'] !== 'due' && $settlement['due'] <= 0) {
            /* PAID ✓ — a small gold-foil badge in the header (the tick is
               drawn: the shipped face has no U+2713). */
            $badgeW = self::gdWidth(16, 'PAID') + 58;
            $bx1 = 1004 - 12 - $badgeW;
            self::gdRounded($im, $bx1 - 2, 1284 + $grow, 992 + 2, 1320 + $grow, 16, $goldD);
            DocArt::goldFoil($im, $bx1 + 6, 1286 + $grow, 992 - 6, 1318 + $grow);
            self::gdRounded($im, $bx1, 1286 + $grow, $bx1 + 16, 1318 + $grow, 8, DocArt::col($im, DocArt::foilAt(0.3)));
            self::gdRounded($im, 976, 1286 + $grow, 992, 1318 + $grow, 8, DocArt::col($im, DocArt::foilAt(0.8)));
            self::gdText($im, 16, $bx1 + 14, 1309 + $grow, $navy, 'PAID', true);
            $tx = $bx1 + 14 + self::gdWidth(16, 'PAID') + 10;
            imagesetthickness($im, 4);
            imageline($im, $tx, 1302 + $grow, $tx + 7, 1309 + $grow, $green);
            imageline($im, $tx + 7, 1309 + $grow, $tx + 20, 1293 + $grow, $green);
            imagesetthickness($im, 1);
        }
        $hTxt = $payQ['label'];
        $hMax = 420 - ($badgeW > 0 ? $badgeW + 12 : 0);
        $hSz = 22;
        foreach ([22, 20, 18, 17, 16, 15] as $try) { $hSz = $try; if (self::gdWidth($try, $hTxt) <= $hMax) { break; } }
        self::gdText($im, $hSz, 576, 1312 + $grow, $white, $clampTo($hSz, $hTxt, $hMax), true);
        try {
            $ps  = $qrFit($payUpi, 350);
            $pqx = 780 - intdiv($ps, 2);
            $pqy = 1342 + $grow;
            self::gdScanFrame($im, $pqx - 10, $pqy - 8, $pqx + $ps + 10, $pqy + $ps + 8, 34, 4, $goldM);
            $drawQr($payUpi, $pqx, $pqy, 350);
        }
        catch (Throwable $e) { Logger::error('Payment QR: ' . $e->getMessage()); }
        if ($payQ['mode'] === 'office') {
            self::gdText($im, 18, 576, 1716 + $grow, $ink, $clampTo(18, 'WhatsApp: +' . $payQ['wa'], 410), true);
            self::gdText($im, 15, 576, 1742 + $grow, $mut, $clampTo(15, 'कार्यालयले भुक्तानी विवरण पठाउनेछ', 410), false);
        } else {
            self::gdText($im, 18, 576, 1716 + $grow, $ink, $clampTo(18, 'UPI: ' . $upiVpa, 410), true);
            self::gdText($im, 15, 576, 1742 + $grow, $mut, $clampTo(15, $payQ['name'], 410), false);
        }
        imageline($im, 576, 1756 + $grow, 984, 1756 + $grow, DocArt::col($im, [230, 208, 150]));
        self::gdText($im, 18, 576, 1782 + $grow, $ink,
            'Paid: ' . $currency . ' ' . number_format($settlement['paid'], 2), true);
        $dueTxt = 'Due: ' . $currency . ' ' . number_format($settlement['due'], 2);
        self::gdText($im, 18, 984 - self::gdWidth(18, $dueTxt), 1782 + $grow, $settlement['due'] > 0 ? $red : $green, $dueTxt, true);
        /* The desk's own number when it has one, else the issuing company's -
           and the OTHER side's help number beside it, so the ticket works on
           both sides of the border. Blanks are simply not printed. */
        $supPhone = (string) (($co['desk']['phone'] ?? '') !== '' ? $co['desk']['phone'] : $co['phone']);
        $supLine  = 'Support: ' . self::latin($supPhone);
        if (is_array($co['otherHelp'] ?? null) && ($co['otherHelp']['phone'] ?? '') !== '') {
            $supLine .= '  ·  ' . $co['otherHelp']['label'] . ': ' . self::latin((string) $co['otherHelp']['phone']);
        }
        $supSz = 19;
        foreach ([19, 17, 15] as $try) { $supSz = $try; if (self::gdWidth($try, $supLine) <= 480) { break; } }
        self::gdText($im, $supSz, 60, 1398 + $grow + $qrExt - 90, $ink, $clampTo($supSz, $supLine, 480), true);
        self::gdText($im, 18, 60, 1428 + $grow + $qrExt - 90, $goldD, self::latin($co['web']), true);
        $wa = (string) $co['whatsapp'];
        if ($wa !== '') { self::gdText($im, 16, 60, 1456 + $grow + $qrExt - 90, $green, 'WhatsApp: +' . $wa, true); }

        /* Who cut it, in full — name, code and the agent's own phone, with
           the CODE repeated as an orange chip. The header chip answers it at
           a glance; this strip is the one a passenger reads back over the
           phone and the office checks against the chalani. Facts come from
           Ticket::issuedBy(), the same call the PDF makes.

           x stops at 540: the pay card (always drawn since 9 Oct 2026)
           starts at 550. */
        $tileR = 540;
        DocArt::softShadow($im, 60, 1472 + $grow + $qrExt, $tileR, 1541 + $grow + $qrExt, 14, 7, 4, [90, 64, 12], 12);
        self::gdRounded($im, 60, 1472 + $grow + $qrExt, $tileR, 1541 + $grow + $qrExt, 14, $tile);
        DocArt::roundOutline($im, 60, 1472 + $grow + $qrExt, $tileR, 1541 + $grow + $qrExt, 14, DocArt::col($im, [222, 192, 110]), 1);
        imagefilledrectangle($im, 60, 1486 + $grow + $qrExt, 66, 1527 + $grow + $qrExt, $goldM);
        self::gdText($im, 15, 82, 1500 + $grow + $qrExt, $mut, 'टिकट काट्ने  ·  ' . $issued['label'], false);
        $chip  = self::latinUpper($issued['code']);
        $chipW = self::gdWidth(20, $chip) + 34;
        self::gdRounded($im, $tileR - 18 - $chipW, 1486 + $grow + $qrExt, $tileR - 14, 1530 + $grow + $qrExt, 14, $goldM);
        self::gdRounded($im, $tileR - 16 - $chipW, 1488 + $grow + $qrExt, $tileR - 16, 1528 + $grow + $qrExt, 12, $orange);
        self::gdText($im, 20, $tileR - 16 - $chipW + 17, 1517 + $grow + $qrExt, $white, $chip, true);
        /* The parts are sanitised FIRST and the separator added after —
           joining before it once ate the middle dot and printed
           "Agent One 9825012345" as one run. */
        $whoName = self::display($issued['name']);
        $whoPh   = self::display($issued['phone']);
        $who     = $whoPh !== '' ? $whoName . '  ·  ' . $whoPh : $whoName;
        $maxWho = $tileR - 16 - $chipW - 20 - 82;
        while ($who !== '' && self::gdWidth(20, $who) > $maxWho) { $who = mb_substr($who, 0, mb_strlen($who) - 2) . '…'; }
        self::gdText($im, 20, 82, 1530 + $grow + $qrExt, $ink, $who, true);

        /* Footer notes on the cream */
        $n1 = 'सरकारी परिचयपत्र अनिवार्य  ·  बस छुट्नु ३० मिनेट अगाडि आउनुहोस्';
        self::gdText($im, 17, (int) (($W - self::gdWidth(17, $n1)) / 2), 1578 + $grow + $qrExt, $mut, $n1, false);
        $n2  = self::display($co['name'])
             . ((string) $co['regNo'] !== '' ? '  ·  ' . $co['regLabel'] . ' ' . self::latin((string) $co['regNo']) : '');
        self::gdText($im, 16, (int) (($W - self::gdWidth(16, $n2)) / 2), 1606 + $grow + $qrExt, $mut, $n2, false);
        /* The address: the desk's own when the office has typed one, else the
           issuing company's registered address. Left out when both are blank. */
        $n3 = self::latin((string) (($co['desk']['address'] ?? '') !== '' ? $co['desk']['address'] : $co['address']));
        if ($n3 !== '') {
            $n3 = $clampTo(14, $n3, $W - 140);
            self::gdText($im, 14, (int) (($W - self::gdWidth(14, $n3)) / 2), 1632 + $grow + $qrExt, $mut, $n3, false);
        }

        /* शुभ यात्रा — the blessing line (9 Oct 2026, setting
           doc_blessing_line), gold-deep between two foil rules. */
        if ($blessing !== '') {
            $blY = 1648 + $grow + $qrExt + 44;
            $bSz2 = 24;
            while ($bSz2 > 14 && self::gdWidth($bSz2, $blessing) > $W - 300) { $bSz2--; }
            $blW = self::gdWidth($bSz2, $blessing);
            $blX = (int) (($W - $blW) / 2);
            self::gdText($im, $bSz2, $blX, $blY, $goldD, $blessing, true);
            foreach ([[$blX - 150, $blX - 30], [$blX + $blW + 30, $blX + $blW + 150]] as [$r1, $r2]) {
                DocArt::goldFoil($im, $r1, $blY - 12, $r2, $blY - 9);
            }
            foreach ([$blX - 18, $blX + $blW + 18] as $dx) {
                imagefilledpolygon($im, [$dx, $blY - 19, $dx + 8, $blY - 11, $dx, $blY - 3, $dx - 8, $blY - 11], $orange);
                imagefilledpolygon($im, [$dx, $blY - 15, $dx + 4, $blY - 11, $dx, $blY - 7, $dx - 4, $blY - 11], $goldM);
            }
        }

        /* Heritage band — Sagarmatha, prayer flags, Gautam Buddha, the stupa,
           both flags — in the strip the canvas grew by (9 Oct 2026). */
        if ($artOn) {
            self::gdHeritage($im, $W, $artY + 6, $toIndia ? 'NP' : 'IN', $pageRgb);
        }

        /* CORRECTED / DUPLICATE band in the 36px margin above the header. */
        $markTxt = self::markText($ticket, $mark);
        if ($markTxt !== '') {
            imagefilledrectangle($im, 0, 0, $W, 34, $mark === 'dup' ? imagecolorallocate($im, 70, 78, 94) : $red);
            $mSz = 16;
            while ($mSz > 11 && self::gdWidth($mSz, $markTxt) > $W - 40) { $mSz--; }
            self::gdText($im, $mSz, (int) (($W - self::gdWidth($mSz, $markTxt)) / 2), 24, $white, $markTxt, true);
        }

        /* Atomic write: tmp then rename, so a half-written PNG is never served. */
        $tmp = $path . '.tmp';
        imagepng($im, $tmp, 6);
        imagedestroy($im);
        @rename($tmp, $path);
    }

    /**
     * Serialize concurrent first-time renders of the same PDF (e.g. a
     * WhatsApp link-preview crawler and the actual customer both hitting
     * download-ticket.php within the same second) so two requests never
     * render and write the same file at once. Uses an exclusive flock on
     * a sibling .lock file, with a double-checked is_file() re-read once
     * the lock is held so the second caller just reuses the first's
     * output instead of rendering again.
     */
    private static function renderWithLock(string $path, bool $skipIfExists, callable $render): void
    {
        ensureDir(dirname($path));
        $fp = @fopen($path . '.lock', 'c');
        if ($fp === false) {
            // Locking unavailable (e.g. restrictive shared-hosting perms) —
            // fall back to rendering directly rather than failing the download.
            $render();
            return;
        }
        flock($fp, LOCK_EX);
        try {
            if (!$skipIfExists || !is_file($path)) {
                $render();
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }


    /* =================================================================
     *  Data loading
     * ================================================================= */

    /**
     * @return array<string, mixed>
     */
    private static function loadBooking(int $bookingId): array
    {
        $booking = Database::fetch(
            'SELECT b.*, l.travel_date, l.boarding_stop, l.drop_stop, l.boarding_time,
                    r.from_city, r.to_city, r.route_code, r.id AS route_id_for_stop,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time, r.arr_time,
                    r.crew_name, r.crew_phone, r.duration_text,
                    bus.bus_name, bus.bus_number, bus.coach_type,
                    a.full_name AS agent_name, a.phone AS agent_phone, a.role AS agent_role,
                    ap.counter_name AS agent_counter, ap.counter_code AS agent_counter_code
               FROM bookings b
               JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = \'outbound\'
               JOIN schedules s ON s.id = l.schedule_id
               JOIN routes r ON r.id = s.route_id
               LEFT JOIN buses bus ON bus.id = s.bus_id
               LEFT JOIN admins a ON a.id = b.sold_by_admin_id
               LEFT JOIN admin_profiles ap ON ap.admin_id = a.id
              WHERE b.id = :id
              LIMIT 1',
            ['id' => $bookingId]
        );

        if ($booking === null) {
            throw new RuntimeException('Booking not found for ticket generation.');
        }

        $booking['passengers'] = Database::fetchAll(
            'SELECT * FROM booking_passengers WHERE booking_id = :b ORDER BY id',
            ['b' => $bookingId]
        );

        // Latest payment row — the ticket prints PAID / CASH DUE from this,
        // so the passenger and the crew never have to guess.
        $booking['payment'] = Database::fetch(
            'SELECT status, method, amount, utr_number FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
            ['b' => $bookingId]
        );

        $booking['verified_paid'] = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = :b AND status = 'verified'",
            ['b' => $bookingId], 0
        );
        return $booking;
    }

    /**
     * The PAYMENT QR every ticket carries (9 Oct 2026, owner: mandatory on
     * every PNG and PDF, always beside the signed verify QR):
     *
     *   due > 0, INR, pending/confirmed, upi_id set -> today's amount-prefilled
     *       upiLink()                                       mode 'due'
     *   otherwise with upi_id set (paid, NPR, ...)    -> the company UPI with
     *       no amount, the PNR as the note                  mode 'upi'
     *   no upi_id                                     -> the office WhatsApp
     *       pay chat (wa.me + a prefilled PNR message)      mode 'office'
     *
     * @return array{mode:string,payload:string,label:string,vpa:string,name:string,wa:string,due:float}
     */
    public static function paymentQr(array $booking, ?array $settlement = null): array
    {
        $settlement ??= self::paymentSummary($booking);
        $vpa  = trim(Settings::getString('upi_id', ''));
        $name = Settings::getString('upi_name', APP_NAME);
        $pnr  = (string) ($booking['pnr'] ?? '');
        $cur  = strtoupper((string) ($booking['currency'] ?? 'INR'));
        $due  = (float) $settlement['due'];
        $out  = ['vpa' => $vpa, 'name' => $name, 'wa' => '', 'due' => $due];
        if ($vpa !== '' && $due > 0 && $cur === 'INR' && in_array($booking['status'] ?? '', ['pending', 'confirmed'], true)) {
            $amt = number_format($due, fmod($due, 1.0) > 0 ? 2 : 0);
            return $out + ['mode' => 'due', 'payload' => upiLink($vpa, $name, $due, $pnr),
                'label' => 'SCAN & PAY ₹' . $amt . ' · भुक्तानी गर्नुहोस्'];
        }
        if ($vpa !== '') {
            return $out + ['mode' => 'upi',
                'payload' => 'upi://pay?' . http_build_query(['pa' => $vpa, 'pn' => $name, 'cu' => 'INR', 'tn' => $pnr]),
                'label' => 'भुक्तानी QR · UPI'];
        }
        $wa  = Settings::officeWhatsApp();
        $msg = 'Payment for ticket ' . $pnr . ($due > 0 ? ' - ' . $cur . ' ' . number_format($due, 2) . ' due' : '');
        $out['wa'] = $wa;
        return $out + ['mode' => 'office', 'payload' => 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg),
            'label' => 'Pay via office · कार्यालय'];
    }

    /** Presentation only: never treats a screenshot or pending transfer as paid. */
    public static function paymentSummary(array $booking): array
    {
        $total = max(0, (float) ($booking['total_amount'] ?? 0));
        $payment = $booking['payment'] ?? [];
        $paid = max(0, (float) ($booking['verified_paid'] ??
            (($payment['status'] ?? '') === 'verified' ? ($payment['amount'] ?? 0) : 0)));
        return ['paid' => round($paid, 2), 'due' => round(max(0, $total - $paid), 2)];
    }

    /**
     * @return array<int, string>
     */
    private static function seatNumbers(int $bookingId): array
    {
        return array_map('strval', pluck(
            Database::fetchAll(
                'SELECT seat_no FROM booking_seats WHERE booking_id = :b ORDER BY seat_no',
                ['b' => $bookingId]
            ),
            'seat_no'
        ));
    }

    /**
     * Passenger-facing seat label for ONE seat, in THIS booking's coach + mode.
     * The printed ticket shows the row-letter grid id (A1, B9…) the app draws,
     * while the QR payload and the database keep the canonical L1/U7 — so this is
     * used only where a berth is shown to a human, never for identity.
     */
    private static function seatLabel(string $seat, array $booking): string
    {
        if (!class_exists('Seats')) {
            require_once __DIR__ . '/seats.php';
        }
        return Seats::displayLabel(
            $seat,
            (string) ($booking['coach_type'] ?? 'sleeper'),
            (string) ($booking['booking_mode'] ?? 'sharing')
        );
    }

    /** displayLabel() for a whole seat list, joined for the printed ticket. */
    private static function seatLabelList(array $seats, array $booking, string $glue = ' · '): string
    {
        $out = [];
        foreach ($seats as $s) {
            $lbl = self::seatLabel((string) $s, $booking);
            if ($lbl !== '') {
                $out[] = $lbl;
            }
        }
        return implode($glue, $out);
    }


    /* =================================================================
     *  PDF layouts
     * ================================================================= */

    /** Bump whenever renderTicketPng()'s layout changes — see pngPath().
     *  21 Sep 2026: depth pass — card and fare-band drop shadows, navy
     *  gradient fare band with a gold hairline, and the payment QR in a
     *  scanner viewfinder (double ring + corner brackets).
     *
     *  WRITE THIS IN INDIA TIME, and never later than the deploy that ships
     *  it. strtotime() reads it in the app timezone that bootstrap.php sets
     *  (APP_TIMEZONE = Asia/Kolkata on live and on shg-test, checked through
     *  bootstrap on 24 Sep 2026). A stamp in the FUTURE makes
     *  `filemtime < stamp` true for every ticket until then: each one
     *  re-renders on every download instead of once. A stamp EARLIER than
     *  the layout change leaves the tickets drawn in between stale for
     *  good — the old note said "UTC", so 20:10 here meant 20:10 IST and two
     *  tickets drawn at 23:43/23:44 IST with the old seat labels were never
     *  redrawn. tests/chalani-png-test.php and tests/ticket-cache-test.php
     *  assert a past moment. */
    private const PNG_LAYOUT_CHANGED = '2026-10-10 00:10:00';   // IST: GOLD template - ivory card + mandala, navy/royal band in a gold-foil double rule, mantra line on top, emblem in an embossed gold seal, maroon seat chips, stub tear line, MANDATORY payment QR card (due / company UPI / office WhatsApp), blessing line. Was 2026-10-09 20:10:00:   // IST: showcase upgrade - emblem without the white chip, India + Nepal flags, heritage band (Sagarmatha · Lumbini) appended under the footer, SECURITY CODE beside the verify QR, diagonal anti-fake microtext, Nepali header labels. Was 2026-10-08 23:42:00:   // IST: premium upgrade (pr1) - pickup-time clock, floor on the seat chip, map QR beside the verify QR, no phone in the QR, 'Departed' label. Was 2026-10-08 12:08:30:   // IST: pickup landmark + Google Maps QR of the pickup (master prompt 2.0). Was 2026-10-04 12:15:00:   // IST: per-company letterhead (India / Nepal), desk + other-side help numbers, address line, IST/NPT on the departure. Was 2026-09-24 10:30:00:   // IST: Nepali on the ticket shaped by HarfBuzz (includes/devshape.php) - conjuncts, reph and the i-matra drawn as written. Before: the two-floor seat labels and the counter location (both 24 Sep, 00:34 / 01:49).

    /** Bump whenever renderTicketPdf()'s layout changes — see pdfPath().
     *  A cached PDF older than this re-renders ONCE on its next open, so the
     *  seat box + stub pick up the current seat labels without a manual purge
     *  (23 Sep 2026: the two-floor grid A1-F6 / A7-F12). */
    private const PDF_LAYOUT_CHANGED = '2026-10-10 00:10:00';   // IST: GOLD template - cream page + mandala, gold-foil rules and corners, mantra strip, gold seal, MANDATORY payment QR in the footer, blessing line, long names clamped off the stub. Was 2026-10-09 20:10:00:   // IST: showcase upgrade - emblem aspect-correct without the white rect, flags, heritage card in the footer, SECURITY CODE on the stub, fare box no longer overlaps the Base / Disc line. Was 2026-10-08 23:42:00:   // IST: premium upgrade (pr1) - INR amounts, LOWER 1F / UPPER 2F seat box, bilingual headers, fixed DEPARTS label. Was 2026-10-04 12:15:00:   // IST: per-company letterhead, help numbers from the desk table (no hard-coded offices), INVOICE vs TAX INVOICE. Was 2026-09-24 10:30:00:   // IST: Nepali in the PDF shaped by HarfBuzz (glyph ids from DevShape into the Identity-H stream). Before: the two-floor seat labels, COUNTER line and desk code (24 Sep, 00:34 / 01:49).

    /**
     * How many passengers the ticket names one by one before it stops and
     * counts the rest. Twelve is two full sleeper cabins — past that the
     * picture is a manifest, and the chalani is the document for that.
     */
    private const PNG_MAX_PAX_ROWS = 12;

    /** Heritage band under the PNG footer: 6 gap + art + 74 caption + 28 margin. */
    private const PNG_ART_IMG_H = 228;
    private const PNG_ART_H     = 6 + self::PNG_ART_IMG_H + 74 + 28;

    /* Gold ticket (9 Oct 2026): the paper round the card, the warm-white
       tile face, and the strip the blessing line adds under the footer. */
    private const PNG_PAGE    = [241, 230, 204];
    private const PNG_TILE    = [255, 253, 246];
    private const PNG_BLESS_H = 64;

    /* Navy + warm cream (GOLD template, 9 Oct 2026). */
    private const BROWN  = [14, 30, 63];
    private const CREAM  = [252, 244, 222];

    private static function registerDevanagariFont(Pdf $pdf): void
    {
        $fontDir = dirname(__DIR__) . '/assets/fonts/';
        $regular = $fontDir . 'NotoSansDevanagari.ttf';
        $bold    = $fontDir . 'NotoSansDevanagari-Bold.ttf';
        if (is_file($regular)) $pdf->registerTTF($regular, 'F7');
        if (is_file($bold))    $pdf->registerTTF($bold,    'F8');
        elseif (is_file($regular)) $pdf->registerTTF($regular, 'F8');  // fall back to regular
    }

    /** Render Devanagari text with Latin fallback (if font unavailable). */
    /**
     * A PERSON'S NAME on a PDF, in the script they wrote it in.
     *
     * The PDF has two text paths: Helvetica (`$pdf->text`, Latin-1 only) and
     * the embedded Devanagari CID face (`textCID`). Names went down the
     * Helvetica path through latin(), which drops every non-ASCII byte — so
     * "सीता शर्मा" reduced to the empty string and the boarding pass printed a
     * blank where the passenger's name belongs, while the PNG of the same
     * booking named her correctly (audit, 11 Sep 2026).
     *
     * Devanagari goes to the CID face; anything else keeps the Helvetica look
     * the layout was drawn for. A name in a third script (Gujarati, say) has
     * no glyphs in either face, so it falls back to a dash rather than an
     * invisible cell — an anonymous row on a boarding pass is worse than an
     * obvious one.
     */
    private static function devOrLatin(Pdf $pdf, float $x, float $y, string $text, float $size,
                                       string $devKey, string $latinFont, array $rgb, bool $upper = false): void
    {
        $shown = self::display($text);
        if ($shown !== '' && preg_match('/\p{Devanagari}/u', $shown) === 1 && $pdf->hasCIDFont($devKey)) {
            try { $pdf->textCID($x, $y, $shown, $size, $devKey, $rgb); return; } catch (\Throwable $e) {}
        }
        $latin = $upper ? self::latinUpper($text) : self::latin($text);
        $pdf->text($x, $y, $latin !== '' ? $latin : '-', $size, $latinFont, $rgb);
    }

    private static function devText(Pdf $pdf, float $x, float $y, string $nepali, float $size, string $fontKey, array $rgb): void
    {
        try { $pdf->textCID($x, $y, $nepali, $size, $fontKey, $rgb); return; } catch (\Throwable $e) {}
        // Latin fallback — use Roman Nepali approximation
        $pdf->text($x, $y, self::romanize($nepali), $size, 'F2', $rgb);
    }

    private static function devCenter(Pdf $pdf, float $xL, float $xR, float $y, string $nepali, float $size, string $fontKey, array $rgb): void
    {
        try { $pdf->textCIDCenter($xL, $xR, $y, $nepali, $size, $fontKey, $rgb); return; } catch (\Throwable $e) {}
        $pdf->textCenter($xL, $xR, $y, self::romanize($nepali), $size, 'F2', $rgb);
    }

    /** Best-effort Roman Nepali transliteration for Latin-1 fallback. */
    private static function romanize(string $text): string
    {
        static $map = null;
        if ($map === null) $map = [
            'यात्रा टिकट' => 'YATRA TICKET', 'भारत–नेपाल बस सेवा' => 'India-Nepal Bus Seva',
            'यात्रु' => 'YATRI', 'चढ्ने ठाउँ' => 'CHADNE THAU', 'गन्तव्य' => 'GANTABYA',
            'यात्रा मिति' => 'MITI', 'सिट नं.' => 'SEAT', 'सिट' => 'SEAT',
            'बस' => 'BUS', 'रिपोर्टिङ' => 'REPORTING', 'सम्पर्क' => 'SAMPARK',
            'यात्रुको विवरण' => 'YATRIHARU', 'ओर्लिने ठाउँ' => 'ORLINE THAU', 'झर्ने ठाउँ' => 'JHARNE THAU',
            'नाम' => 'NAAM', 'उमेर' => 'UMER', 'लिङ्ग' => 'LINGA', 'सिट ·' => 'SEAT ·',
            'महत्त्वपूर्ण:' => 'Important:', 'बोर्डिङ पास' => 'BOARDING PASS',
            'बोर्डिङमा स्क्यान गर्नुहोस्' => 'Scan at boarding', 'वेबसाइट' => 'Website',
            'जम्मा रकम' => 'TOTAL PAID', 'बाँकी रकम' => 'TOTAL DUE',
            'धन्यवाद · शुभ यात्रा' => 'Dhanyabad · Shubha Yatra',
        ];
        return $map[$text] ?? preg_replace('/[^\x20-\x7E]/', '', $text) ?: $text;
    }

    /** 'CORRECTED TICKET · REV 2 · 19 Sep 2026 23:40 ...' / 'DUPLICATE COPY ...', or '' for a first issue. */
    private static function markText(array $ticket, string $mark): string
    {
        $rev = (int) ($ticket['reissue_count'] ?? 0);
        if ($mark === 'dup') {
            return 'DUPLICATE COPY' . ($rev > 0 ? '  ·  REV ' . $rev : '') . '  ·  PRINTED ' . strtoupper(date('d M Y H:i'));
        }
        if ($rev > 0) {
            $at = !empty($ticket['reissued_at']) ? (int) strtotime((string) $ticket['reissued_at']) : time();
            return 'CORRECTED TICKET  ·  REV ' . $rev . '  ·  ' . strtoupper(date('d M Y H:i', $at)) . '  ·  REPLACES EARLIER COPIES';
        }
        return '';
    }

    private static function renderTicketPdf(array $booking, array $ticket, string $mark = ''): Pdf
    {
        $pdf = new Pdf();
        $W   = $pdf->width();
        $seats = self::seatNumbers((int) $booking['id']);

        $coId    = self::companyOf($booking);   // the ISSUING company (4 Oct 2026)
        $company = self::latin((string) $coId['name']);
        $cin     = (string) $coId['regNo'];

        /* Register Devanagari font for Nepali headings. */
        self::registerDevanagariFont($pdf);
        $F7 = 'F7';  // Devanagari regular
        $F8 = 'F8';  // Devanagari bold (or regular fallback)

        /* ==============================================================
           DATA PREPARATION — identical to the old layout
           ============================================================== */
        $primary  = $booking['passengers'][0]['full_name'] ?? '';
        $paxCount = count($booking['passengers']);
        // Row-letter grid ids for the printed ticket (A1 · A2 …); the QR
        // payload built elsewhere keeps the canonical L1/L2 for the scanner.
        // 8 Oct 2026: with the floor (1F lower / 2F upper) — Latin, Helvetica.
        $floorSeats = $seats !== [] ? $seats
            : array_values(array_filter(array_map(static fn($p) => (string) ($p['seat_no'] ?? ''), $booking['passengers'] ?? []), 'strlen'));
        $seatList = self::seatLabelListFloor($floorSeats, $booking);

        $boardStopRaw   = trim((string) ($booking['boarding_stop'] ?? ''));
        $dropStopRaw    = trim((string) ($booking['drop_stop'] ?? ''));
        /* Same helper as the PNG, so the two tickets can never disagree on
           where the passenger boards or when the bus gets there. */
        $ends           = self::tripEnds($booking);
        $boardCityText  = $ends['fromName'];
        $dropCityText   = $ends['toName'];
        $fromCode       = $ends['fromCode'];
        $toCode         = $ends['toCode'];
        $busType  = !empty($booking['coach_type']) ? ucfirst((string) $booking['coach_type']) : '';

        $pickupHHMM   = $ends['dep'];
        $depTimeStr   = $pickupHHMM !== '' ? date('g:i A', (int) strtotime($pickupHHMM)) : '—';
        $nepaliTime   = $pickupHHMM !== '' ? self::nepaliTimeLabel($pickupHHMM) : '';
        $nepDateStr   = self::nepaliDate((string) ($booking['travel_date'] ?? ''));
        $bsDateStr    = self::bsDate((string) ($booking['travel_date'] ?? ''));
        $engDateStr   = formatDate($booking['travel_date'], 'd-m-Y');

        /* ==============================================================
           HEADER — warm brown band with Devanagari subtitle
           ============================================================== */
        /* GOLD template (9 Oct 2026): cream paper with a faint mandala, a
           navy -> royal band, the MANTRA strip at the very top, a gold-foil
           rule under the band and corner ornaments. */
        $pdf->rect(0, 0, $W, $pdf->height(), DocArt::CREAM, true);
        DocArt::pdfMandala($pdf, $W / 2, 250, 150, [240, 226, 186]);
        DocArt::pdfMandala($pdf, $W / 2, 640, 120, [242, 230, 194]);
        DocArt::pdfGradient($pdf, 0, 0, $W, 96, DocArt::NAVY, DocArt::ROYAL, 32);
        $mantraPdf = DocArt::mantraLine();
        if ($mantraPdf !== '') {
            $pdf->rect(0, 0, $W, 14, [8, 18, 42], true);
            $pdf->line(0, 14, $W, 14, DocArt::GOLD, 0.6);
            self::devCenter($pdf, 0, $W, 2.6, $mantraPdf, 7.5, $F7, DocArt::GOLD_LIGHT);
        }
        DocArt::pdfFoil($pdf, 0, 91, $W, 5, 6);
        DocArt::pdfCorners($pdf, 10, 20, $W - 20, 64, 8);
        $markTxt = self::markText($ticket, $mark);

        // Company logo on a white chip (5 Sep 2026) — the brand mark the
        // owner asked for. Text shifts right only when the logo is there,
        // so a missing file degrades to the old text-only band.
        /* 9 Oct 2026: the transparent EMBLEM, aspect-correct (the old box
           assumed a 440x321 logo and stretched anything else), no white rect,
           on a baked soft glow so the navy globe reads on the navy band. The
           company name stays live text beside it. */
        /* Gold version: the emblem in an embossed gold seal (a cartouche -
           deep rim, foil ring, light bevel, royal enamel face). */
        $pdf->roundedRect(27, 21, 106, 68, 34, [6, 14, 32], true);                   // shadow
        $pdf->roundedRect(24, 18, 106, 68, 34, DocArt::GOLD_DEEP, true);
        $pdf->roundedRect(25.5, 19.2, 103, 65, 32.5, DocArt::GOLD_LIGHT, true);
        $pdf->roundedRect(27, 20.8, 100, 62.4, 31.2, DocArt::GOLD, true);
        $pdf->roundedRect(30.5, 24.3, 93, 55.4, 27.7, DocArt::GOLD_DEEP, true);
        $pdf->roundedRect(31.5, 25.3, 91, 53.4, 26.7, [20, 44, 98], true);
        $mk = DocArt::pdfMark($pdf, 37, 29, 80, 46, self::ownLogo($coId), 'center', true);
        $textX = 144;
        // both flags, top right of the band — the side the bus leaves from first
        DocArt::pdfFlags($pdf, $W - 40 - DocArt::pdfFlagsWidth(14, 4), 19, 14, 4, self::flagFirst($booking));
        /* The name stops short of the title block at W-220 (4 Oct 2026): a
           longer company name - the Nepal company's - ran under it. Helvetica
           Bold capitals average ~0.68 em, so the size steps down to fit. */
        $coSz = 17.0;
        while ($coSz > 9 && mb_strlen($company) * $coSz * 0.68 > ($W - 232 - $textX)) { $coSz -= 0.5; }
        $pdf->text($textX, 20, strtoupper($company), $coSz, 'F2', [255, 255, 255]);
        self::devText($pdf, $textX, 44, 'भारत–नेपाल बस सेवा', 11, $F7, self::GOLD);
        if ($cin !== '') {
            $pdf->text($textX, 68, $coId['regLabel'] . ': ' . self::latin($cin), 7.5, 'F1', [200, 190, 170]);
        }
        /* The desk, directly under the company name (owner, 24 Sep 2026:
           "company ko name ko tala location lekhne thau"). It takes the last
           line of the 96pt band — above the orange rule at y=92 — and steps
           up into the CIN's slot when there is no CIN, so the band never
           carries an empty row. Latin only: the desk names are place names
           and this line sits outside the Devanagari font's reach. */
        $deskPdf = self::latin((string) (self::issuedBy($booking)['location'] ?? ''));
        if ($deskPdf !== '') {
            $pdf->text($textX, $cin !== '' ? 82 : 68, 'COUNTER: ' . strtoupper($deskPdf), 8.5, 'F2', self::GOLD);
        }

        self::devText($pdf, $W - 220, 18, 'यात्रा टिकट', 16, $F8, self::GOLD);
        $pdf->text($W - 220, 42, 'E-TICKET / BOARDING PASS', 8, 'F2', [230, 220, 200]);
        $pdf->textRight($W - 40, 60, (string) $booking['pnr'], 14, 'F2', [255, 255, 255]);
        if (!empty($ticket['ticket_number'])) {
            $pdf->textRight($W - 40, 78, 'Ticket ' . (string) $ticket['ticket_number'], 7.5, 'F1', [200, 190, 170]);
        }

        /* ==============================================================
           PAYMENT BAND — bilingual (keeps the existing logic)
           ============================================================== */
        $pay  = $booking['payment'] ?? null;
        $sumP = self::paymentSummary($booking);
        $paid = $sumP['due'] <= 0;
        /* Helvetica has no ₹, so the rupee helper printed a bare "6,210" — which reads as
           NPR on a cross-border ticket (8 Oct 2026, D6-15). Spelled out now,
           and the cash band asks for what is actually DUE, not the total. */
        $money = static fn(float $a): string => 'INR ' . indianNumber($a);
        $due  = $money((float) $booking['total_amount']);
        if ($paid) {
            $method = strtoupper((string) ($pay['method'] ?? ''));
            $bandBg = [20, 110, 64];
            $band   = 'PAID  ·  ' . $due . ($method !== '' ? '  ·  ' . ($method === 'COD' ? 'CASH' : $method) : '');
        } elseif ((int) $booking['is_cod'] === 1) {
            $bandBg = DocArt::GOLD_DEEP;
            $band   = 'CASH ON BOARDING  ·  PAY ' . $money((float) $sumP['due']) . ' TO THE CREW';
        } else {
            $bandBg = self::ORANGE;
            $band   = 'PAYMENT PENDING  ·  NOT VALID UNTIL APPROVED';
        }
        $pdf->rect(0, 96, $W, 20, $bandBg, true);
        $pdf->line(0, 116.3, $W, 116.3, DocArt::GOLD, 0.8);
        $pdf->textCenter(0, $W, 101, $band, 9, 'F2', [255, 255, 255]);
        /* CORRECTED / DUPLICATE: its own strip under the payment band (the
           mantra owns the very top since 9 Oct 2026); the card moves down. */
        $markH = 0.0;
        if ($markTxt !== '') {
            $markH = 14.0;
            $pdf->rect(0, 117, $W, 13, $mark === 'dup' ? [70, 78, 94] : [160, 32, 40], true);
            $pdf->textCenter(0, $W, 119.5, $markTxt, 7, 'F2', [255, 255, 255]);
        }

        /* ==============================================================
           BOARDING PASS CARD — professional layout, prominent time
           ============================================================== */
        $cardX = 36;
        $cardY = 122 + $markH;
        $cardW = $W - 72;
        $cardH = 252;
        $tear  = $cardX + $cardW * 0.68;

        // depth: two warm offset layers under the card, then an ivory face with a gold edge
        $pdf->roundedRect($cardX + 1.5, $cardY + 3.5, $cardW, $cardH, 10, [226, 214, 186], true);
        $pdf->roundedRect($cardX + 0.8, $cardY + 1.8, $cardW, $cardH, 10, [236, 226, 202], true);
        $pdf->roundedRect($cardX, $cardY, $cardW, $cardH, 10, [255, 253, 247], true);
        $pdf->rect($tear, $cardY + 1, $cardX + $cardW - $tear - 1, $cardH - 2, self::CREAM, true);
        $pdf->setLineWidth(0.9);
        $pdf->roundedRect($cardX, $cardY, $cardW, $cardH, 10, DocArt::GOLD, false);
        $pdf->setLineWidth(0.4);
        $pdf->roundedRect($cardX + 4, $cardY + 4, $cardW - 8, $cardH - 8, 7, [226, 206, 150], false);
        self::perforate($pdf, $tear, $cardY + 10, $cardY + $cardH - 10);
        // half-moon notches at both ends of the tear line
        DocArt::pdfCircle($pdf, $tear, $cardY, 6, DocArt::CREAM, 0.5, true);
        DocArt::pdfCircle($pdf, $tear, $cardY + $cardH, 6, DocArt::CREAM, 0.5, true);

        /* ---- Passenger name ---- */
        $lx = $cardX + 20;
        self::devText($pdf, $lx, $cardY + 16, 'यात्रु', 8, $F7, self::MUTE);
        $pdf->text($lx + 30, $cardY + 16, '·  PASSENGER', 7, 'F1', self::MUTE);
        /* Fit the lead name in front of the stub (9 Oct 2026): a long name
           ran under the boarding QR. Step the size down, then trim. */
        $nameMax = $tear - $lx - 14;
        $nmPdf   = (string) $primary;
        $nmSz    = 14.0;
        $nmW = static function (string $s, float $z) use ($pdf, $F8): float {
            $shown = self::display($s);
            if ($shown !== '' && preg_match('/\p{Devanagari}/u', $shown) === 1 && $pdf->hasCIDFont($F8)) {
                try { return (float) $pdf->textWidthCID($shown, $z, $F8); } catch (\Throwable $e) {}
            }
            return $pdf->textWidth(self::latinUpper($s), $z, 'F2');
        };
        while ($nmSz > 9 && $nmW($nmPdf, $nmSz) > $nameMax) { $nmSz -= 0.5; }
        while (mb_strlen($nmPdf) > 3 && $nmW($nmPdf, $nmSz) > $nameMax) { $nmPdf = rtrim(mb_substr($nmPdf, 0, mb_strlen($nmPdf) - 2)) . '.'; }
        self::devOrLatin($pdf, $lx, $cardY + 32 + (14 - $nmSz) * 0.4, $nmPdf, $nmSz, $F8, 'F2', self::BROWN, true);
        if ($paxCount > 1) {
            $pdf->text($lx, $cardY + 50, '+ ' . ($paxCount - 1) . ' more', 8, 'F1', self::MUTE);
        }

        /* ---- Route: FROM → TO with prominent boarding time ---- */
        $routeY = $cardY + 68;
        self::devText($pdf, $lx, $routeY - 8, 'चढ्ने ठाउँ', 8, $F7, self::MUTE);
        $pdf->text($lx, $routeY + 8, $fromCode, 28, 'F2', self::BROWN);
        $pdf->text($lx, $routeY + 38, self::latinUpper($boardCityText), 9, 'F2', self::INK);

        // Prominent pickup time — THE most important detail for the passenger
        if ($nepaliTime !== '') {
            self::devText($pdf, $lx, $routeY + 54, $nepaliTime, 11, $F8, self::ORANGE);
        }
        $pdf->text($lx, $routeY + 70, $depTimeStr, 9, 'F2', self::MUTE);

        // Arrow
        $midL = $lx + 110;
        $midR = $tear - 120;
        $pdf->line($midL, $routeY + 20, $midR, $routeY + 20, self::ORANGE, 1.5);
        // arrowhead drawn with two strokes (was a ">>>" text glyph — looked cheap)
        $pdf->line($midR - 7, $routeY + 15.5, $midR, $routeY + 20, self::ORANGE, 1.5);
        $pdf->line($midR - 7, $routeY + 24.5, $midR, $routeY + 20, self::ORANGE, 1.5);
        if ((string) ($booking['duration_text'] ?? '') !== '') {
            $pdf->textCenter($midL, $midR, $routeY + 6, (string) $booking['duration_text'], 7.5, 'F1', self::MUTE);
        }

        // Destination
        $rx = $tear - 20;
        self::devText($pdf, $rx - 80, $routeY - 8, 'गन्तव्य', 8, $F7, self::MUTE);
        $pdf->textRight($rx, $routeY + 8, $toCode, 28, 'F2', self::BROWN);
        $pdf->textRight($rx, $routeY + 38, self::latinUpper($dropCityText), 9, 'F2', self::INK);

        /* ---- Bottom row: Date / Bus / Seat ---- */
        $infoY = $cardY + 170;
        $pdf->line($lx, $infoY - 10, $tear - 20, $infoY - 10, self::LINE, 0.5);

        $infoRight = $tear - 20;
        $seatBoxW  = 84.0;
        $seatBoxX  = $infoRight - $seatBoxW;
        $colW      = ($seatBoxX - 10 - $lx) / 3;

        // Date column — Nepali AD date, then the Bikram Sambat date the
        // passenger actually reckons by, then the plain English date.
        // $bsDateStr is '' outside BS_DATA, and the BS line is skipped.
        // 5 Sep 2026: two date lines, not three — the English date (crew,
        // border) and the Bikram Sambat date (the passenger's own calendar).
        // The same Gregorian date in Devanagari digits added nothing.
        $cx0 = $lx;
        self::devText($pdf, $cx0, $infoY, 'यात्रा मिति', 7.5, $F7, self::MUTE);
        $dTxt = formatDate($booking['travel_date'], 'D, j M Y');
        $dSz  = 9.0;
        while ($dSz > 6.5 && $pdf->textWidth($dTxt, $dSz, 'F2') > $colW - 8) { $dSz -= 0.5; }   // ran into the bus number (9 Oct 2026)
        $pdf->text($cx0, $infoY + 14, $dTxt, $dSz, 'F2', self::INK);
        if ($bsDateStr !== '') {
            self::devText($pdf, $cx0, $infoY + 29, $bsDateStr, 8, $F7, self::MUTE);
        } else {
            self::devText($pdf, $cx0, $infoY + 29, $nepDateStr, 8, $F7, self::MUTE);
        }

        // Bus column
        $cx1 = $lx + $colW;
        self::devText($pdf, $cx1, $infoY, 'बस', 7.5, $F7, self::MUTE);
        $busNo = (string) ($booking['bus_number'] ?? '') ?: '—';
        $busSize = 10.0;
        while ($busSize > 7 && $pdf->textWidth($busNo, $busSize, 'F2') > $colW - 6) { $busSize -= 0.5; }
        $pdf->text($cx1, $infoY + 14, $busNo, $busSize, 'F2', self::INK);
        if ($busType !== '') {
            $pdf->text($cx1, $infoY + 30, $busType, 7.5, 'F1', self::MUTE);
        }
        // Bus name
        $busName = (string) ($booking['bus_name'] ?? '');
        if ($busName !== '' && $busName !== 'SHG Bus') {
            $pdf->text($cx1, $infoY + 42, $busName, 7, 'F1', self::MUTE);
        }

        // Reporting time column
        $cx2 = $lx + $colW * 2;
        self::devText($pdf, $cx2, $infoY, 'रिपोर्टिङ', 7.5, $F7, self::MUTE);
        $boardTime = $pickupHHMM !== '' ? date('g:i A', strtotime($pickupHHMM) - 30 * 60) : '—';
        $pdf->text($cx2, $infoY + 14, $boardTime, 10, 'F2', self::INK);

        // SEAT — prominent orange box
        $pdf->roundedRect($seatBoxX + 1, $infoY - 6, $seatBoxW, 54, 8, [214, 196, 160], true);
        $pdf->roundedRect($seatBoxX, $infoY - 8, $seatBoxW, 54, 8, self::ORANGE, true);
        $pdf->setLineWidth(1.2);
        $pdf->roundedRect($seatBoxX + 2, $infoY - 6, $seatBoxW - 4, 50, 6.5, DocArt::GOLD, false);
        self::devCenter($pdf, $seatBoxX, $seatBoxX + $seatBoxW, $infoY - 1, $paxCount > 1 ? 'सिट' : 'सिट नं.', 7.5, $F7, [255, 255, 255]);
        /* The berths on one line, the floor on a second small line (8 Oct
           2026): "A3 · A4 · B9" over "1F · 1F · 2F", or "LOWER 1F" when every
           berth shares a floor — the box is 84pt wide and cannot take both. */
        $boxLabels = self::seatLabelList($floorSeats, $booking, ' · ');
        $seatText = $boxLabels !== '' ? $boxLabels : '—';
        $seatSize = 18.0;
        while ($seatSize > 8 && $pdf->textWidth($seatText, $seatSize, 'F2') > $seatBoxW - 10) { $seatSize -= 0.5; }
        $pdf->textCenter($seatBoxX, $seatBoxX + $seatBoxW, $infoY + 14, $seatText, $seatSize, 'F2', [255, 255, 255]);
        $boxFloors = array_map([self::class, 'floorCode'], $floorSeats);
        $uniqFl    = array_values(array_unique(array_filter($boxFloors, 'strlen')));
        if ($uniqFl !== []) {
            $flText = count($uniqFl) === 1 && count(array_filter($boxFloors, 'strlen')) === count($boxFloors)
                ? ($uniqFl[0] === '2F' ? 'UPPER 2F' : 'LOWER 1F')
                : implode(' · ', array_map(static fn(string $f): string => $f !== '' ? $f : '-', $boxFloors));
            $flSize = 8.0;
            while ($flSize > 5 && $pdf->textWidth($flText, $flSize, 'F2') > $seatBoxW - 8) { $flSize -= 0.5; }
            $pdf->textCenter($seatBoxX, $seatBoxX + $seatBoxW, $infoY + 34, $flText, $flSize, 'F2', [255, 255, 255]);
        }

        /* ---- Stub: QR for boarding scan ---- */
        $sx = $tear + 16;
        $sw = $cardX + $cardW - $tear - 32;
        self::devCenter($pdf, $sx, $sx + $sw, $cardY + 14, 'बोर्डिङ पास', 7.5, $F7, self::MUTE);

        $qrBinary = self::qrBinary((string) $ticket['qr_payload'], 8);
        $qrSize   = min(110, $sw);
        $qrX      = $sx + ($sw - $qrSize) / 2;
        $pdf->imageFromString($qrBinary, $qrX, $cardY + 28, $qrSize, $qrSize);

        $stubY = $cardY + 32 + $qrSize;
        $pdf->textCenter($sx, $sx + $sw, $stubY, (string) $booking['pnr'], 11, 'F2', self::BROWN);
        $pdf->textCenter($sx, $sx + $sw, $stubY + 16, $fromCode . ' > ' . $toCode . '  ·  ' . formatDate($booking['travel_date'], 'j M'), 8, 'F1', self::INK);
        $pdf->textCenter($sx, $sx + $sw, $stubY + 30, ($paxCount > 1 ? 'Seats ' : 'Seat ') . ($seatList !== '' ? $seatList : '—'), 8, 'F2', self::INK);
        self::devCenter($pdf, $sx, $sx + $sw, $stubY + 46, 'बोर्डिङमा स्क्यान गर्नुहोस्', 6.5, $F7, self::MUTE);
        /* SECURITY CODE on the stub (9 Oct 2026, anti-fake) — the same letters
           the PNG prints and verify-ticket.php shows for this revision. */
        $secCode = self::securityCode((string) $booking['pnr'], (string) ($ticket['qr_hash'] ?? ''));
        $pdf->roundedRect($sx + 4, $stubY + 58, $sw - 8, 40, 5, [252, 247, 232], true);
        $pdf->rect($sx + 4, $stubY + 62, 2.5, 32, [214, 168, 52], true);
        self::devCenter($pdf, $sx, $sx + $sw, $stubY + 62, 'सुरक्षा कोड · SECURITY CODE', 6, $F7, self::MUTE);
        $pdf->textCenter($sx, $sx + $sw, $stubY + 74, implode(' ', str_split(self::securityCodeGrouped($secCode))), 13, 'F2', self::BROWN);

        /* ==============================================================
           PASSENGER TABLE
           ============================================================== */
        $tableY = $cardY + $cardH + 22;
        self::devText($pdf, 40, $tableY, 'यात्रुको विवरण', 10, $F8, self::BROWN);
        $pdf->text(40 + 90, $tableY + 2, '·  PASSENGERS', 8, 'F1', self::MUTE);
        $tableY += 18;

        DocArt::pdfGradient($pdf, 40, $tableY, $W - 80, 22, DocArt::NAVY, DocArt::ROYAL, 8);
        DocArt::pdfFoil($pdf, 40, $tableY + 22, $W - 80, 2, 2);
        /* Bilingual headers (8 Oct 2026, D6-15): Nepali label in the
           Devanagari face, then the Latin word — the same "नाम · Name" the
           PNG prints. A missing CID face degrades to the Latin word alone. */
        $white = [255, 255, 255];
        $devW  = static function (string $s) use ($pdf, $F7): float {
            try { return $pdf->hasCIDFont($F7) ? (float) $pdf->textWidthCID($s, 8, $F7) : 0.0; } catch (\Throwable $e) { return 0.0; }
        };
        foreach ([[52, 'नाम', 'Name'], [300, 'उमेर', 'Age'], [360, 'लिङ्ग', 'Gender']] as [$hx, $hDev, $hLat]) {
            $dw = $devW($hDev);
            if ($dw > 0) {
                self::devText($pdf, $hx, $tableY + 5, $hDev, 8, $F7, $white);
                $pdf->text($hx + $dw + 3, $tableY + 6, '·  ' . $hLat, 9, 'F2', $white);
            } else {
                $pdf->text($hx, $tableY + 6, $hLat, 9, 'F2', $white);
            }
        }
        $pdf->textRight($W - 52, $tableY + 6, 'Seat', 9, 'F2', $white);
        $sdw = $devW('सिट ·');
        if ($sdw > 0) {
            self::devText($pdf, $W - 52 - $pdf->textWidth('Seat', 9, 'F2') - 4 - $sdw, $tableY + 5, 'सिट ·', 8, $F7, $white);
        }
        $tableY += 22;

        // Group booking (Task 6): when every passenger shares ONE name, print a
        // single summary row (name · N passengers · all seats) instead of the
        // same name repeated down the table. A genuinely per-named booking still
        // prints the full per-seat list.
        $paxNames = array_map(static fn($p) => trim((string) ($p['full_name'] ?? '')), $booking['passengers']);
        $isGroup  = count($paxNames) > 1 && count(array_unique($paxNames)) === 1 && $paxNames[0] !== '';

        if ($isGroup) {
            $seatCsv = self::seatLabelList(array_map(static fn($p) => (string) $p['seat_no'], $booking['passengers']), $booking, ' · ');
            self::devOrLatin($pdf, 52, $tableY + 5, $paxNames[0], 10, $F8, 'F2', self::INK);
            $pdf->text(300, $tableY + 5, count($paxNames) . ' pax', 9, 'F1', self::MUTE);
            $pdf->textRight($W - 52, $tableY + 5, self::latin($seatCsv), 8, 'F2', self::BROWN);
            $tableY += 20;
        } else {
            // Bulk booking (5 Sep 2026): a staff sale may carry up to 20
            // passengers, but this is a fixed one-page layout — beyond
            // $maxRows named rows the boarding/fare/footer sections below
            // would run off the page. List the first rows, then fold the
            // rest into one summary line (their seats still show; the full
            // name list lives on the manifest / chalani).
            $paxAll  = $booking['passengers'];
            // 6 rows is the geometry this page was designed for (the old
            // seat cap) — the boarding/fare/contact/offices chain below
            // fits exactly. Never exceed it.
            $maxRows = 6;
            $shown   = count($paxAll) > $maxRows ? array_slice($paxAll, 0, $maxRows - 1) : $paxAll;
            /* 18pt rows for a big party or a CORRECTED strip (9 Oct 2026):
               the mandatory pay QR made the footer the fixed part of the page. */
            $rowH = (count($paxAll) >= 5 || $markH > 0) ? 18 : 20;
            foreach ($shown as $i => $pax) {
                if ($i % 2 === 1) {
                    $pdf->rect(40, $tableY, $W - 80, $rowH, self::CREAM, true);
                }
                self::devOrLatin($pdf, 52, $tableY + 4, (string) $pax['full_name'], 10, $F7, 'F1', self::INK);
                $pdf->text(300, $tableY + 4, $pax['age'] !== null ? (string) $pax['age'] : '-', 10, 'F1', self::INK);
                $pdf->text(360, $tableY + 4, self::latin(ucfirst(strtolower((string) ($pax['gender'] ?? '-')))), 10, 'F1', self::INK);
                $pdf->textRight($W - 52, $tableY + 4, trim(self::seatLabel((string) $pax['seat_no'], $booking) . ' ' . self::floorCode((string) $pax['seat_no'])), 10, 'F2', self::BROWN);
                $tableY += $rowH;
            }
            $rest = array_slice($paxAll, count($shown));
            if ($rest !== []) {
                $restSeats = self::seatLabelList(array_map(static fn($p) => (string) $p['seat_no'], $rest), $booking, ' · ');
                $pdf->rect(40, $tableY, $W - 80, $rowH, self::CREAM, true);
                $pdf->text(52, $tableY + 4, '+ ' . count($rest) . ' more passengers (full list with the office)', 9, 'F2', self::INK);
                $pdf->textRight($W - 52, $tableY + 4, self::latin($restSeats), 7.5, 'F2', self::BROWN);
                $tableY += $rowH;
            }
        }
        $pdf->line(40, $tableY + 2, $W - 40, $tableY + 2, self::LINE, 0.5);

        /* ==============================================================
           BOARDING + FARE
           ============================================================== */
        $boardY = $tableY + 16;
        self::devText($pdf, 40, $boardY, 'चढ्ने ठाउँ', 8, $F7, self::MUTE);
        $pdf->text(40 + 62, $boardY + 2, '·  BOARDING POINT', 7, 'F1', self::MUTE);
        // boarding_stop and drop_stop strings often include a "(Devanagari)"
        // parenthetical after the English label; strip that here since this
        // slot uses Helvetica (Latin-1). The Nepali heading above it —
        // "चढ्ने ठाउँ" — already communicates the label in Nepali via F7.
        $boardLabel = self::latin(str_replace('·', '-', self::stopForPrint((string) ($booking['boarding_stop'] ?? '-'))));
        $pdf->textBlock(40, $boardY + 14, 290, $boardLabel !== '' ? $boardLabel : '-', 10, 'F1', self::INK);

        // झर्ने, as on the PNG (8 Oct 2026) — one word for one booking's two documents.
        self::devText($pdf, 40, $boardY + 42, 'झर्ने ठाउँ', 8, $F7, self::MUTE);
        $pdf->text(40 + 62, $boardY + 44, '·  DROP POINT', 7, 'F1', self::MUTE);
        $dropLabel = self::latin(str_replace('·', '-', self::stopForPrint((string) ($booking['drop_stop'] ?? '-'))));
        $pdf->textBlock(40, $boardY + 56, 290, $dropLabel !== '' ? $dropLabel : '-', 10, 'F1', self::INK);

        // Fare box
        $pdf->roundedRect($W - 219, $boardY + 2.5, 180, 70, 8, [214, 200, 168], true);
        $pdf->roundedRect($W - 222, $boardY - 2, 184, 74, 9, DocArt::GOLD, true);
        $pdf->roundedRect($W - 220, $boardY, 180, 70, 8, self::BROWN, true);
        DocArt::pdfFoil($pdf, $W - 212, $boardY, 164, 2, 2);
        self::devText($pdf, $W - 205, $boardY + 10, $paid ? 'जम्मा रकम' : 'बाँकी रकम', 9, $F7, self::GOLD);
        $fareTx = $money((float) $booking['total_amount']);
        /* 20pt from +23 (9 Oct 2026): at 22pt from +28 the amount ran down
           into the Base / Disc line under it on every discounted ticket. */
        $fareSz = 20.0;
        while ($fareSz > 12 && $pdf->textWidth($fareTx, $fareSz, 'F2') > 165) { $fareSz -= 1; }
        $pdf->text($W - 205, $boardY + 23, $fareTx, $fareSz, 'F2', [255, 255, 255]);
        // Base → Discount breakdown (Task 9) when a counter discount was applied.
        if ((float) ($booking['coupon_discount'] ?? 0) > 0) {
            $pdf->text($W - 205, $boardY + 45, 'Base ' . $money((float) $booking['base_total']) . '  -  Disc ' . $money((float) $booking['coupon_discount']), 7.5, 'F1', [220, 210, 195]);
        }
        // Fare by passenger count (4 Sep 2026): "3 x Rs 2,000" for a party,
        // so the family sees how the total was built; a solo ticket keeps
        // the seat count.
        $fps = (float) ($booking['fare_per_seat'] ?? 0);
        $paxLine = (count($seats) > 1 && $fps > 0)
            ? count($seats) . ' x ' . $money($fps) . ' · ' . self::latinUpper((string) $booking['status'])
            : count($seats) . ' seat(s) · ' . self::latinUpper((string) $booking['status']);
        $pdf->text($W - 205, $boardY + 56, $paxLine, 7.5, 'F1', [200, 190, 170]);

        /* Departure pill — STATIC (8 Oct 2026, D6-M2). The PDF is cached until
           the layout stamp moves, so a relative "UPCOMING IN 11D 23H" froze
           and kept being served wrong from the next hour on. It now states
           the passenger's own pickup moment, which never goes stale; a trip
           the office cancelled says so. Latin only (Helvetica). */
        $live = self::liveStatus($booking);
        if ($live !== null) {
            if (!class_exists('Boarding')) { require_once __DIR__ . '/boarding.php'; }
            $lg0    = is_array($booking['legs'][0] ?? null) ? $booking['legs'][0] : [];
            $pickTs = Boarding::pickupTs((string) ($booking['travel_date'] ?? ''), (string) ($booking['boarding_stop'] ?? ''),
                ($booking['boarding_time'] ?? null) !== null ? (string) $booking['boarding_time'] : null, (string) ($booking['dep_time'] ?? ''),
                (string) ($booking['route_dep_time'] ?? ($lg0['route_dep_time'] ?? '')), (int) ($booking['schedule_slot'] ?? ($lg0['schedule_slot'] ?? 1)),
                (int) ($booking['schedule_route_id'] ?? ($lg0['schedule_route_id'] ?? 0)));
            if ($live['key'] === 'trip_cancelled') {
                $pillBg = self::liveStatusRgb('red');
                $pillTx = 'TRIP CANCELLED - OFFICE WILL CONTACT YOU';
            } else {
                $pillBg = self::liveStatusRgb('blue');
                $pillTx = $pickTs > 0
                    ? 'DEPARTS ' . strtoupper(date('D j M', $pickTs)) . ' · ' . self::latin(self::zonedTime(date('H:i', $pickTs), $coId))
                    : 'DEPARTURE AS PRINTED ABOVE';
            }
            $pillH  = 12.0;
            /* 66, not 72 (10 Sep 2026): the pill's bottom edge landed
               exactly on the top of the ISSUED BY label below it, so the
               live-status badge sat across the line naming the seller —
               the one line the owner asked to be readable. */
            $pillY  = $boardY + 66;
            $pillW  = min(180.0, $pdf->textWidth($pillTx, 7.0, 'F2') + 14);
            $pillX  = $W - 40 - $pillW;
            $pdf->roundedRect($pillX, $pillY, $pillW, $pillH, 4, $pillBg, true);
            // Pdf::textCenter takes the TOP edge and drops the baseline 0.8em
            // below it, so +8 in a 12pt pill put the baseline under the pill
            // and clipped the descenders off the status word.
            $pdf->textCenter($pillX, $pillX + $pillW, $pillY + 2.4, $pillTx, 7.0, 'F2', [255, 255, 255]);
        }

        /* ==============================================================
           CONTACT + AGENT
           ============================================================== */
        $cY = $boardY + 84;
        self::devText($pdf, 40, $cY, 'सम्पर्क', 8, $F7, self::MUTE);
        $pdf->text(40 + 42, $cY + 2, '·  CONTACT', 7, 'F1', self::MUTE);
        $pdf->text(40, $cY + 14, self::latin((string) ($booking['contact_phone'] ?? '-')), 10, 'F1', self::INK);
        $bookedOn = !empty($booking['created_at'])
            ? date('j M Y · g:i A', strtotime((string) $booking['created_at'])) : '-';
        $pdf->text(220, $cY, 'BOOKED ON', 8, 'F2', self::MUTE);
        $pdf->text(220, $cY + 14, $bookedOn, 10, 'F1', self::INK);

        /* Who issued this ticket — ALWAYS shown (owner ask 2 Sep: "kasle
           kateko tha hos"). A counter agent is named with their SHG code and
           phone; an office/admin sale names the office; a website booking
           names the company online. So every ticket says who cut it. */
        $issued  = self::issuedBy($booking);
        $issuedW = max(120.0, $W - 440);
        /* ONE line, always. textBlock() wrapped a long "name · code · phone"
           onto a second line that ran straight through the office-contacts
           rule below it. The phone goes first (the code is what identifies
           the seller), then the name is trimmed — never the code. */
        /* display(), not latin(): an agent who registered in Nepali used to lose
           their name here and the ticket credited a bare code (audit, 11 Sep
           2026). The line is measured with the SAME face it is drawn with, so
           the one-line trim still matches what lands on the page. */
        $iName = self::display($issued['name']);
        $iPh   = self::display($issued['phone']);
        $isDev = preg_match('/\p{Devanagari}/u', $iName) === 1 && $pdf->hasCIDFont($F7);
        $join  = static fn(array $p): string => implode('  ·  ', array_filter($p, static fn(string $s): bool => $s !== ''));
        $wide  = static fn(string $s): bool => $isDev
            ? $pdf->textWidthCID($s, 9, $F7) > $issuedW
            : $pdf->textWidth(self::latin($s), 9, 'F1') > $issuedW;
        $issuedTxt = $join([$iName, $issued['code'], $iPh]);
        if ($wide($issuedTxt)) {
            $issuedTxt = $join([$iName, $issued['code']]);
        }
        while ($iName !== '' && $wide($issuedTxt)) {
            $iName     = rtrim(mb_substr($iName, 0, max(1, mb_strlen($iName) - 2)));
            $issuedTxt = $join([$iName . '..', $issued['code']]);
        }
        /* The caption carries the desk code (NPJ, MSA) — three letters is
           all this line has room for, and the code is what a clerk reads
           back over the phone. The full name is already in the header band. */
        $issuedCap = 'ISSUED BY  ·  ' . strtoupper($issued['kind'] === 'agent' ? 'AGENT' : $issued['code']);
        if (($issued['locCode'] ?? '') !== '') {
            $issuedCap .= '  ·  ' . strtoupper((string) $issued['locCode']);
        }
        $pdf->text(400, $cY, $issuedCap, 8, 'F2', self::MUTE);
        if ($isDev) {
            self::devText($pdf, 400, $cY + 14, $issuedTxt, 9, $F7, self::INK);
        } else {
            $pdf->text(400, $cY + 14, self::latin($issuedTxt), 9, 'F1', self::INK);
        }

        /* ==============================================================
           OFFICE CONTACTS
           ============================================================== */
        $officeY = $cY + 32;
        $pdf->line(40, $officeY - 4, $W - 40, $officeY - 4, self::LINE, 0.5);
        /* 4 Oct 2026: four Gujarat numbers used to be typed in here (two of
           them disagreed with the office's own list) and no Nepal number at
           all. One contact truth now - see helpContacts(). */
        $offices = self::helpContacts($coId, 4);
        $colW2 = ($W - 80) / 2;
        foreach ($offices as $i => [$oName, $oNum]) {
            $ox = 40 + ($i % 2) * $colW2;
            $oy = $officeY + (int) floor($i / 2) * 13;
            $oName = self::latin($oName);
            $pdf->text($ox, $oy, $oName . ': ', 7.5, 'F2', self::INK);
            $pdf->text($ox + max(56, 8 + 4.3 * mb_strlen($oName)), $oy, self::latin($oNum), 7.5, 'F1', self::INK);
        }

        /* ==============================================================
           FOOTER — QR + Nepali travel notes
           ============================================================== */
        $footY = $pdf->height() - 126;
        $pdf->line(40, $footY, $W - 40, $footY, self::LINE, 0.5);

        // Live-status QR (5 Sep 2026) — was the homepage. A camera scan now
        // opens the public verify page for THIS ticket: VALID / CANCELLED /
        // USED, keyed with the same download token every WhatsApp link
        // carries, so it works with no sign-in and confirms nothing without
        // the key. The boarding-pass stub QR above stays the signed offline
        // payload — the checkpoint scanner is untouched.
        $statusUrl = appUrl('verify-ticket.php')
            . '?pnr=' . urlencode((string) ($booking['pnr'] ?? ''))
            . '&k=' . self::downloadToken((string) ($booking['pnr'] ?? ''));
        $webQrBin = self::qrBinary($statusUrl, 6);
        if ($webQrBin !== '') {
            $pdf->roundedRect(36, $footY + 2, 70, 70, 4, DocArt::GOLD, true);
            $pdf->rect(37.5, $footY + 3.5, 67, 67, [255, 255, 255], true);
            $pdf->imageFromString($webQrBin, 40, $footY + 6, 62, 62);
            self::devCenter($pdf, 38, 104, $footY + 73, 'टिकट स्थिति', 6.5, $F7, self::MUTE);
            $pdf->textCenter(38, 104, $footY + 82, 'Live status', 6.5, 'F2', self::BROWN);
        }

        // Nepali travel notes — short and essential
        $noteX = 114;
        $noteY = $footY + 8;
        self::devText($pdf, $noteX, $noteY, 'महत्त्वपूर्ण:', 8, $F8, self::BROWN);
        $notes = [
            'यात्राको समयमा टिकट साथमा राख्नुहोस्।',
            'मान्य फोटो परिचयपत्र साथमा राख्नुहोस्।',
            'चढ्ने समयभन्दा ३० मिनेट अगाडि पुग्नुहोस्।',
            'भारत–नेपाल सीमा पार गर्दा कागजात साथमा राख्नुहोस्।',
        ];
        foreach ($notes as $ni => $note) {
            self::devText($pdf, $noteX, $noteY + 14 + $ni * 13, '•  ' . $note, 7.5, $F7, self::INK);
        }

        // (5 Sep 2026) The ticket number / fare line and the second copy of the
        // office numbers were removed here: both already print above.
        $pdf->text($noteX, $footY + 74, 'Help: ' . self::latin((string) (($coId['desk']['phone'] ?? '') !== '' ? $coId['desk']['phone'] : $coId['phone'])) . '  ·  ' . self::latin((string) $coId['web']), 7.5, 'F1', self::MUTE);

        /* Heritage card (9 Oct 2026, owner: Sagarmatha, Gautam Buddha, the
           Nepal flag on the ticket) — the free right half of the footer, so it
           fits on every ticket whatever the passenger count above. */
        /* PAYMENT QR — mandatory on every ticket (9 Oct 2026), beside the
           live-status QR, never replacing the signed stub QR above. Same
           rules as the PNG: Ticket::paymentQr(). */
        $payPdf = self::paymentQr($booking, $sumP);
        $payBin = self::qrBinary($payPdf['payload'], 6);
        if ($payBin !== '') {
            $pqx = 288.0;
            $pdf->roundedRect($pqx - 4, $footY + 3, 70, 70, 4, DocArt::GOLD, true);
            $pdf->rect($pqx - 2.5, $footY + 4.5, 67, 67, [255, 255, 255], true);
            $pdf->imageFromString($payBin, $pqx, $footY + 7, 62, 62);
            $payCap = match ($payPdf['mode']) {
                'due'    => 'SCAN & PAY ' . $money((float) $payPdf['due']),
                'upi'    => $sumP['due'] <= 0 ? 'UPI QR  ·  PAID' : 'UPI QR',
                default  => 'PAY VIA OFFICE',
            };
            $capSz = 6.5;
            while ($capSz > 5 && $pdf->textWidth($payCap, $capSz, 'F2') > 80) { $capSz -= 0.25; }
            self::devCenter($pdf, $pqx - 10, $pqx + 72, $footY + 73, 'भुक्तानी QR', 6.5, $F7, self::MUTE);
            $pdf->textCenter($pqx - 10, $pqx + 72, $footY + 82, $payCap, $capSz, 'F2', $payPdf['mode'] === 'due' ? self::ORANGE : self::BROWN);
        }

        if (DocArt::heritageOn()) {
            $hw = 150.0; $hx = $W - 40 - $hw; $hy = $footY + 8;
            if (DocArt::pdfBand($pdf, $hx, $hy, $hw, 50, 0.5, 0.8)) {
                $pdf->setLineWidth(1.0);
                $pdf->rect($hx, $hy, $hw, 50, DocArt::GOLD, false);
                DocArt::pdfFlags($pdf, $hx + $hw - 4 - DocArt::pdfFlagsWidth(10, 3), $hy + 4, 10, 3, self::flagFirst($booking));
                self::devCenter($pdf, $hx - 6, $hx + $hw + 6, $hy + 54, 'सगरमाथा · Sagarmatha  ·  लुम्बिनी · Lumbini', 6.6, $F7, self::BROWN);
                self::devCenter($pdf, $hx - 6, $hx + $hw + 6, $hy + 64, 'गौतम बुद्धको जन्मस्थल · भारत–नेपाल मैत्री', 6, $F7, self::MUTE);
            }
        }

        // शुभ यात्रा — the blessing line (setting doc_blessing_line), then the thanks
        $blessPdf = DocArt::blessingLine();
        $pdf->line(40, $pdf->height() - 33, $W - 40, $pdf->height() - 33, [226, 206, 150], 0.4);
        if ($blessPdf !== '') {
            self::devCenter($pdf, 40, $W - 40, $pdf->height() - 30, $blessPdf, 9.5, $F8, DocArt::GOLD_DEEP);
            $pdf->textCenter(40, $W - 40, $pdf->height() - 16, 'Thank you  ·  ' . $company, 6.5, 'F2', self::ORANGE);
        } else {
            self::devCenter($pdf, 40, $W - 40, $pdf->height() - 24, 'धन्यवाद · Thank you · ' . $company, 9, $F7, self::ORANGE);
        }
        DocArt::pdfFoil($pdf, 0, $pdf->height() - 5, $W, 5, 4);

        return $pdf;
    }

    private static function renderInvoicePdf(array $booking): Pdf
    {
        $pdf = new Pdf();
        $W   = $pdf->width();

        $coId    = self::companyOf($booking);   // the ISSUING company (4 Oct 2026)
        $company = self::latin((string) $coId['name']);
        $address = self::latin((string) $coId['address']);
        $cin     = (string) $coId['regNo'];
        $gstin   = (string) $coId['gstin'];

        $pdf->rect(0, 0, $W, 70, self::NAVY, true);
        $invTextX = 40;
        // 9 Oct 2026: the emblem, aspect-correct, no white rect (see renderTicketPdf).
        $invMk = DocArt::pdfMark($pdf, 34, 10, 90, 50, self::ownLogo($coId), 'left', true);
        if ($invMk !== null) {
            $invTextX = (int) ceil($invMk['x'] + $invMk['w'] + 12);
        }
        $pdf->text($invTextX, 20, strtoupper($company), 16, 'F2', [255, 255, 255]);
        DocArt::pdfFlags($pdf, $invTextX, 44, 11, 3, self::flagFirst($booking));
        // "TAX INVOICE" is a claim: only a company with a GSTIN on file may make it.
        $pdf->textRight($W - 40, 22, $gstin !== '' ? 'TAX INVOICE' : 'INVOICE', 16, 'F2', self::GOLD);

        $pdf->textBlock(40, 84, 320, $address, 9, 'F1', self::MUTE);
        if ($cin !== '')   $pdf->text(40, 120, $coId['regLabel'] . ': ' . self::latin($cin), 9, 'F1', self::INK);
        if ($gstin !== '') $pdf->text(40, 134, 'GSTIN: ' . $gstin, 9, 'F1', self::INK);

        $pdf->textRight($W - 40, 84, 'Invoice for PNR: ' . $booking['pnr'], 10, 'F2', self::INK);
        $pdf->textRight($W - 40, 100, 'Date: ' . formatDate(substr((string) $booking['created_at'], 0, 10), 'j M Y'), 9, 'F1', self::MUTE);

        $pdf->line(40, 156, $W - 40, 156, self::LINE, 0.5);

        $pdf->text(40, 168, 'Billed to', 9, 'F2', self::MUTE);
        $pdf->text(40, 182, maskPhone((string) $booking['contact_phone']), 11, 'F1', self::INK);

        /* Line items */
        $y = 220;
        $pdf->rect(40, $y, $W - 80, 24, self::NAVY, true);
        $pdf->text(52, $y + 7, 'Description', 10, 'F2', [255, 255, 255]);
        $pdf->textRight($W - 52, $y + 7, 'Amount', 10, 'F2', [255, 255, 255]);
        $y += 24;

        $rows = [
            ['Base fare (' . $booking['from_city'] . ' -> ' . $booking['to_city'] . ')', (float) $booking['base_total']],
        ];
        if ((float) $booking['group_discount'] > 0) $rows[] = ['Group discount', -(float) $booking['group_discount']];
        if ((float) $booking['coupon_discount'] > 0) $rows[] = [((string) ($booking['coupon_code'] ?? '') !== '' ? 'Coupon ' . $booking['coupon_code'] : 'Discount'), -(float) $booking['coupon_discount']];
        if ((float) $booking['tier_discount'] > 0)   $rows[] = [($booking['tier_name'] ?? 'Member') . ' discount', -(float) $booking['tier_discount']];
        if ((float) $booking['points_value'] > 0)    $rows[] = ['Loyalty points redeemed', -(float) $booking['points_value']];
        if ((float) $booking['tax_amount'] > 0)      $rows[] = ['Taxes', (float) $booking['tax_amount']];
        if ((float) $booking['booking_fee'] > 0)     $rows[] = ['Booking fee', (float) $booking['booking_fee']];

        foreach ($rows as $i => $row) {
            if ($i % 2 === 1) $pdf->rect(40, $y, $W - 80, 22, [242, 244, 249], true);
            $pdf->text(52, $y + 6, (string) $row[0], 10, 'F1', self::INK);
            $pdf->textRight($W - 52, $y + 6, ($row[1] < 0 ? '- ' : '') . inr(abs($row[1])), 10, 'F1', self::INK);
            $y += 22;
        }

        $pdf->line(40, $y + 4, $W - 40, $y + 4, self::LINE, 0.8);
        $y += 14;
        $pdf->text($W - 260, $y, 'TOTAL', 13, 'F2', self::NAVY);
        $pdf->textRight($W - 52, $y, inr((float) $booking['total_amount']), 15, 'F2', self::NAVY);

        if ($gstin === '') {
            $pdf->text(40, $y + 40, 'Note: This is a fare receipt. GST is not charged as the operator is not currently GST-registered for this service.', 8, 'F3', self::MUTE);
        }

        $pdf->textCenter(40, $W - 40, $pdf->height() - 40, 'Thank you for travelling with ' . $company, 10, 'F1', self::MUTE);

        return $pdf;
    }

    /**
     * QR PNG bytes for embedding, resilient to a QR failure so ticket
     * generation never dies over an unreadable payload.
     */
    private static function qrBinary(string $payload, int $scale): string
    {
        try {
            $uri = QrCode::dataUri($payload, $scale, 2, QrCode::ECC_M);
            return base64_decode(explode(',', $uri)[1] ?? '');
        } catch (Throwable $e) {
            Logger::error('QR generation failed for ticket: ' . $e->getMessage());
            return '';
        }
    }
}
