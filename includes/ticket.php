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

final class Ticket
{
    private const NAVY   = [16, 42, 86];
    private const BLUE   = [8, 99, 184];
    private const ORANGE = [255, 122, 22];
    private const GOLD   = [255, 200, 40];
    private const INK    = [30, 30, 40];
    private const MUTE   = [120, 120, 130];
    private const LINE   = [222, 222, 230];
    private const PANEL  = [246, 247, 251];

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
            $booking['contact_phone'] ?? '',
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
    private static function stopForPrint(string $label): string
    {
        $s = preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $label) ?? $label;   // drop [lat,lng]
        $s = preg_replace('/\s{2,}/u', ' ', $s) ?? $s;
        return trim($s, " \t·");
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
            $pdf->line($x, $y, $x, min($y + 4, $yBottom), self::LINE, 0.8);
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
           they cached under the old one. Per-request memo: the lists call this
           once per row. download-ticket.php ignores the parameter. */
        static $rev = [];
        if (!array_key_exists($pnr, $rev)) {
            try {
                $rev[$pnr] = (int) Database::scalar(
                    'SELECT t.reissue_count FROM tickets t JOIN bookings b ON b.id = t.booking_id WHERE b.pnr = :p LIMIT 1',
                    ['p' => $pnr], 0);
            } catch (Throwable $e) { $rev[$pnr] = 0; }
        }
        return appUrl(
            'download-ticket.php?pnr=' . urlencode($pnr)
            . '&img=1&k=' . self::downloadToken($pnr)
            . ($rev[$pnr] > 0 ? '&r=' . $rev[$pnr] : '')
        );
    }

    /**
     * Keyed link to the public "Where is my bus?" page (24 Sep 2026): live
     * position, minutes to the passenger's own stop, and a share button so
     * the family at home can watch too. Same key as the ticket download, so
     * every place that already links a ticket can link this.
     */
    public static function trackUrl(string $pnr): string
    {
        return appUrl('track.php?pnr=' . urlencode($pnr) . '&k=' . self::downloadToken($pnr));
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
        $company  = Settings::getString('company_name', APP_NAME);
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
        /* 26 Sep 2026: prefer the code FROZEN on the sale. The profile join
           is today's desk for that person, so a clerk who moved towns used to
           take every ticket they had ever sold with them. */
        $stamped  = trim((string) ($booking['counter_code'] ?? ''));
        $locCode  = $stamped !== '' ? $stamped : trim((string) ($booking['agent_counter_code'] ?? ''));
        $locName  = trim((string) ($booking['agent_counter'] ?? ''));
        if ($stamped !== '') {
            $deskRow = CounterDesk::get($stamped);
            if ($deskRow !== null) { $locName = (string) $deskRow['name']; }
        }
        $location = $kind === 'online' ? '' : CounterDesk::label($locCode, $locName);
        if ($location === '' && $kind !== 'online') {
            $location = trim((string) Settings::getString('company_city', ''));
        }

        /* The desk's OWN number, clearly, so a passenger holding this ticket
           rings the window that sold it rather than an office 1,500 km away
           (owner, 26 Sep 2026: "jun desk ho tesko naam number clearly
           mention"). Filled in Admin -> Counters & collection. */
        $deskPhone = '';
        if ($locCode !== '') {
            $deskPhone = trim((string) (CounterDesk::get($locCode)['phone'] ?? ''));
        }

        /* WHEN it was cut (same ask: "ticket kateko timing ni mention"). The
           moment the sale was confirmed — for a counter sale that is the
           moment the clerk pressed Confirm and took the money. */
        $cutTs    = strtotime((string) ($booking['confirmed_at'] ?? '')) ?: strtotime((string) ($booking['created_at'] ?? ''));
        $issuedAt = $cutTs ? date('d M Y, g:i A', $cutTs) : '';

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
            'kind'      => $kind,
            'code'      => $code,
            'name'      => $name,
            'phone'     => $phone,
            'deskPhone' => $deskPhone,
            'issuedAt'  => $issuedAt,
            'label'    => $label,
            'line'     => implode('  ·  ', $parts),
            'location' => $location,          // "Nepalgunj — Bus Park (NPJ)"
            'locName'  => $locName !== '' ? $locName : $location,
            'locCode'  => mb_strtoupper($locCode),
        ];
    }

    /**
     * The company logo file for PDF headers, or null when none is usable.
     * Same resolution as the chalani print view (admin/manifest.php): an
     * admin-set company_logo path wins, otherwise the site logo — but PDF
     * embedding only handles PNG/JPEG, so other formats fall through.
     */
    public static function logoFile(): ?string
    {
        foreach ([Settings::getString('company_logo', ''), 'assets/img/logo.png'] as $cand) {
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
    public static function liveStatus(array $booking): ?array
    {
        $status = strtolower((string) ($booking['status'] ?? ''));
        if (in_array($status, ['cancelled', 'rejected'], true)) {
            return null;
        }

        // Prefer top-level fields (loadBooking join); fall back to legs[0]
        // (BookingService::detail packs departure per leg for round trips).
        $depTime    = trim((string) ($booking['dep_time'] ?? ''));
        $travelDate = trim((string) ($booking['travel_date'] ?? ($booking['date'] ?? '')));
        if ($depTime === '' || $travelDate === '') {
            $leg = $booking['legs'][0] ?? null;
            if (is_array($leg)) {
                if ($depTime === '')    { $depTime    = trim((string) ($leg['dep_time'] ?? '')); }
                if ($travelDate === '') { $travelDate = trim((string) ($leg['travel_date'] ?? '')); }
            }
        }
        if ($depTime === '' || $travelDate === '') {
            return null;
        }

        $depTs = strtotime($travelDate . ' ' . substr($depTime, 0, 5));
        if ($depTs === false) {
            return null;
        }

        $now  = time();
        $diff = $depTs - $now; // seconds until departure; negative = gone

        if ($diff <= -5 * 60) {
            return [
                'key'         => 'departed',
                'label'       => 'Departed · Timoff',
                'color'       => 'red',
                'description' => 'This bus has already left.',
            ];
        }

        if ($diff <= 30 * 60) {
            return [
                'key'         => 'departing',
                'label'       => 'Departing now',
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
     */
    public static function reissue(int $bookingId): void
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
           run) must never break the reissue itself. */
        try {
            Database::query('UPDATE tickets SET reissue_count = reissue_count + 1, reissued_at = NOW() WHERE booking_id = :b', ['b' => $bookingId]);
        } catch (Throwable $e) {
            Logger::exception($e);
        }

        Logger::audit('ticket.reissue', 'booking', (string) ($booking['pnr'] ?? ''), null, null, 'Ticket QR + PDF re-minted after reschedule');
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
        $stale = is_file($path) && (int) @filemtime($path) < (int) strtotime(self::PDF_LAYOUT_CHANGED);
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

        if (!$force && is_file($path) && !empty($ticket['invoice_path'])) {
            return $path;
        }

        self::renderWithLock($path, !$force, static function () use ($booking, $ticket, $path, $filename) {
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
     *  legal document; GD cannot shape Devanagari (no OpenType shaping),
     *  so per the house ticket rules the image is Roman-script.
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
        $stale = is_file($path) && (int) @filemtime($path) < (int) strtotime(self::PNG_LAYOUT_CHANGED);
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

        // A payment request never replaces the signed boarding verification QR.
        $upiVpa = Settings::getString('upi_id', '');
        $fare = (float) ($booking['total_amount'] ?? 0);
        $settlement = self::paymentSummary($booking);
        $currency = strtoupper((string) ($booking['currency'] ?? 'INR'));
        $payUpi = ($upiVpa !== '' && $settlement['due'] > 0 && $currency === 'INR'
            && in_array($booking['status'] ?? '', ['pending', 'confirmed'], true))
            ? upiLink($upiVpa, Settings::getString('upi_name', APP_NAME), $settlement['due'], (string) ($booking['pnr'] ?? '')) : '';
        $qrExt = $payUpi !== '' ? 350 : 270;

        $W = 1080; $H = 1644 + $grow + $qrExt;   // +24: the issuer tile gained the desk line (26 Sep 2026)
        $im = imagecreatetruecolor($W, $H);

        $navy   = imagecolorallocate($im, 18, 38, 78);
        $navyHi = imagecolorallocate($im, 32, 60, 110);
        $orange = imagecolorallocate($im, 240, 124, 31);
        $gold   = imagecolorallocate($im, 232, 193, 90);
        $green  = imagecolorallocate($im, 23, 138, 80);
        $red    = imagecolorallocate($im, 176, 42, 42);
        $ink    = imagecolorallocate($im, 27, 36, 54);
        $mut    = imagecolorallocate($im, 84, 96, 118);     // darker than the web's muted: WhatsApp re-encodes to JPEG and thin light-grey small print is the first thing it smears (6 Sep 2026)
        $cream  = imagecolorallocate($im, 240, 247, 255);
        $white  = imagecolorallocate($im, 255, 255, 255);
        $line   = imagecolorallocate($im, 229, 233, 240);
        $tile   = imagecolorallocate($im, 246, 248, 252);

        imagefilledrectangle($im, 0, 0, $W, $H, $cream);

        /* ---- Header band: navy gradient + logo chip + brand ---------- */
        for ($y = 36; $y <= 250; $y++) {
            $t = ($y - 36) / 214;
            $c = imagecolorallocate($im, (int) (18 + 14 * $t), (int) (38 + 22 * $t), (int) (78 + 32 * $t));
            imageline($im, 36, $y, $W - 36, $y, $c);
        }
        self::gdRounded($im, 36, 36, $W - 36, 90, 24, $navy);           // round the top edge
        imagefilledrectangle($im, 36, 250, $W - 36, 262, $orange);       // accent bar

        /* The AGENT CODE chip claims the right edge of the band from y=124
           down, so the two brand lines beside it are clamped to stop short
           of it. The name itself steps down a size instead — a longer
           company_name must never run under the code. */
        /* The identity belongs to the DESK that cut it (26 Sep 2026): a
           ticket sold at Nepalgunj is issued by the Nepal entity, with the
           Nepal office and a Nepal number on it. An Indian desk and every
           online sale are unchanged. */
        $co     = Settings::companyFor($booking['counter_code'] ?? null);
        $chipX1 = $W - 60 - 268;

        $logo = self::logoFile();
        $bx = 60;
        if ($logo !== null) {
            self::gdRounded($im, 60, 66, 220, 224, 16, $white);
            $lg = @imagecreatefromstring((string) file_get_contents($logo));
            if ($lg !== false) {
                if (!imageistruecolor($lg)) { imagepalettetotruecolor($lg); }
                imagecopyresampled($im, $lg, 72, 84, 0, 0, 136, 100, imagesx($lg), imagesy($lg));
                imagedestroy($lg);
            }
            $bx = 248;
        }
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

        /* 26 Sep 2026: the width was measured to the PAGE edge while the
           ISSUED-BY chip sits 268px in from it, so a longer company name —
           "Shree Hari Global Pvt Ltd Nepal", the name a Nepalgunj ticket
           must carry — printed straight under the chip. The brand now stops
           where the chip starts, and may go down to 18px to do it. */
        $brand   = self::latinUpper($co['name']);
        $brandMax = $chipX1 - 20 - $bx;
        $bSz     = 30;
        foreach ([30, 27, 24, 21, 19, 18] as $try) { $bSz = $try; if (self::gdWidth($try, $brand) <= $brandMax) { break; } }
        self::gdText($im, $bSz, $bx, 122, $white, $clampTo($bSz, $brand, $brandMax), true);
        self::gdText($im, 19, $bx, 162, $gold,
            $clampTo(19, 'E-TICKET  ·  INDIA-NEPAL BUS SERVICE', $chipX1 - 24 - $bx), false);
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
        $brandSub = 'Gujarat <-> Rupaidiha  ·  ' . self::latin($co['web']);
        if ($deskLine !== '') {
            foreach ([
                $deskLine . '  ·  Gujarat <-> Rupaidiha',
                $deskLine . '  ·  ' . self::latin($co['web']),
                $deskLine,
            ] as $try) {
                $brandSub = $try;
                if (self::gdWidth(16, $try) <= $chipX1 - 24 - $bx) { break; }
            }
        }
        self::gdText($im, 16, $bx, 200, imagecolorallocate($im, 170, 185, 215),
            $clampTo(16, $brandSub, $chipX1 - 24 - $bx), false);

        /* Payment pill (top-right of the band) */
        $pay  = $booking['payment'] ?? null;
        $paid = self::paymentSummary($booking)['due'] <= 0;
        if ($paid) {
            $pillCol = $green;
            $method  = strtoupper((string) ($pay['method'] ?? ''));
            $pillTxt = 'PAID' . ($method !== '' ? ' · ' . ($method === 'COD' ? 'CASH' : $method) : '');
        } elseif ((int) ($booking['is_cod'] ?? 0) === 1) {
            $pillCol = $orange;
            $pillTxt = 'PAY CASH ON BOARDING';
        } else {
            $pillCol = $red;
            $pillTxt = 'PAYMENT PENDING';
        }
        $pw = self::gdWidth(17, $pillTxt) + 44;
        self::gdRounded($im, $W - 60 - $pw, 66, $W - 60, 112, 23, $pillCol);
        self::gdText($im, 17, $W - 60 - $pw + 22, 96, $white, $pillTxt, true);

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
        self::gdRounded($im, $chipX1, 124, $chipX2, 224, 18, $navyHi);
        imagerectangle($im, $chipX1 + 1, 125, $chipX2 - 1, 223, $gold);
        imagerectangle($im, $chipX1 + 2, 126, $chipX2 - 2, 222, $gold);
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
        self::gdShadow($im, 36, 300, $W - 36, 1544 + $grow + $qrExt, 24, 8);
        self::gdRounded($im, 36, 262, $W - 36, 1544 + $grow + $qrExt, 24, $white);
        imagefilledrectangle($im, 36, 262, $W - 36, 300, $white);        // square the join

        /* PNR hero */
        $pnr = (string) $booking['pnr'];
        self::gdText($im, 18, (int) (($W - self::gdWidth(18, 'TICKET / PNR')) / 2), 340, $mut, 'TICKET / PNR', false);
        $pnrW = self::gdWidth(44, $pnr) + 8;
        self::gdText($im, 44, (int) (($W - $pnrW) / 2), 408, $navy, $pnr, true);
        imagefilledrectangle($im, (int) (($W - 160) / 2), 428, (int) (($W + 160) / 2), 434, $orange);
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
        // arrow
        imagefilledrectangle($im, 380, 566, 660, 572, $orange);
        imagefilledpolygon($im, [660, 554, 660, 584, 692, 569], $orange);
        imagefilledellipse($im, 380, 569, 16, 16, $orange);

        /* Date · pickup time · bus strip — the time the bus reaches THIS
           passenger's boarding point, which is what "DEP" means to them. */
        $dep  = $ends['dep'] !== '' ? $ends['dep'] : substr((string) ($booking['dep_time'] ?? ''), 0, 5);
        $dt   = formatDate((string) ($booking['travel_date'] ?? ''), 'D, j M Y');
        $bus  = trim((string) ($booking['bus_number'] ?? ''));
        $strip = strtoupper($dt) . '  ·  DEP ' . ($dep !== '' ? date('g:i A', (int) strtotime($dep)) : formatTime($booking['dep_time'] ?? null))
               . ($bus !== '' ? '  ·  BUS ' . $bus : '');
        self::gdText($im, 22, (int) (($W - self::gdWidth(22, $strip)) / 2), 690, $ink, $strip, true);

        /* Perforation */
        for ($x = 76; $x < $W - 76; $x += 26) {
            imagefilledrectangle($im, $x, 726, $x + 12, 729, $line);
        }
        imagefilledellipse($im, 36, 727, 36, 36, $cream);
        imagefilledellipse($im, $W - 36, 727, 36, 36, $cream);

        /* ---- Detail tiles (2 columns) -------------------------------- */
        $lead   = self::displayOr((string) ($paxes[0]['full_name'] ?? ''));
        $paxTxt = $lead . ($paxN > 1 ? '  +' . ($paxN - 1) . ' जना' : '');
        $phone  = (string) ($booking['contact_phone'] ?? '');
        if ($phone === '0000000000') { $phone = '';  }   // counter walk-in placeholder — not a real number
        // `?:` not `??` — an EMPTY string slipped past the null-coalesce and
        // left the tile blank instead of showing the intended dash.
        $board  = self::displayOr(self::stopForPrint((string) ($booking['boarding_stop'] ?? '')));
        $drop   = self::displayOr(self::stopForPrint((string) ($booking['drop_stop'] ?? '')));

        /* Draws through self::gdText, NOT imagettftext (audit, 11 Sep 2026).
           The closure measured through gdWidth — which shapes — and drew around
           it, so a Devanagari name came out with its i-matra on the wrong side
           in the PASSENGER tile while the list 130px below spelled the SAME
           name correctly. One image, two spellings of one passenger. */
        $tileRow = static function ($im2, int $x, int $y, int $w, string $label, string $value, $lab, $val, $bg, $fn, $fw) {
            $fn($im2, $x, $y, $x + $w, $y + 108, 14, $bg);
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
                foreach ($words as $wd) {
                    $try = $l1 === '' ? $wd : $l1 . ' ' . $wd;
                    if ($fw(18, $try) <= $w - 44) { $l1 = $try; } else { $l2 = trim($l2 . ' ' . $wd); }
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
        $colW = ($W - 72 - 24) / 2;
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
        self::gdRounded($im, 60, $panelY, $W - 60, $panelY + $listH, 14, $tile);
        self::gdText($im, 17, 82, $panelY + 38, $mut, 'यात्रु र सिट  ·  PASSENGERS & SEATS', false);
        $cntTxt = 'जम्मा ' . self::nepaliDigits((string) $paxN) . ' जना';
        $cntW   = self::gdWidth(25, $cntTxt) + 40;
        self::gdRounded($im, $W - 82 - $cntW, $panelY + 12, $W - 82, $panelY + 58, 14, $navy);
        self::gdText($im, 25, $W - 82 - $cntW + 20, $panelY + 46, $white, $cntTxt, true);

        $ly = $panelY + 60;
        for ($i = 0; $i < $listN; $i++) {
            $nm = self::displayOr((string) ($paxes[$i]['full_name'] ?? ''));
            $sn = self::display(self::seatLabel((string) ($paxes[$i]['seat_no'] ?? ($seats[$i] ?? '')), $booking));
            $chipW2 = $sn !== '' ? self::gdWidth(21, $sn) + 34 : 0;
            if ($sn !== '') {
                self::gdRounded($im, $W - 82 - $chipW2, $ly + 2, $W - 82, $ly + 40, 12, $orange);
                self::gdText($im, 21, $W - 82 - $chipW2 + 17, $ly + 31, $white, $sn, true);
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
        self::gdShadow($im, 60, 1152 + $grow, $W - 60, 1250 + $grow, 16, 6);
        self::gdRoundedGrad($im, 60, 1152 + $grow, $W - 60, 1250 + $grow, 16, [14, 32, 68], [38, 72, 128]);
        imagefilledrectangle($im, 76, 1152 + $grow, $W - 76, 1155 + $grow, $gold);
        self::gdText($im, 17, 88, 1192 + $grow, $gold, 'जम्मा भाडा  ·  TOTAL FARE', false);
        $amt = $currency . ' ' . number_format((float) ($booking['total_amount'] ?? 0), 2);
        self::gdText($im, 36, 88, 1236 + $grow, $white, $amt, true);
        /* A Nepal window quoted this in NPR and took NPR (26 Sep 2026). The
           rupee stays the figure the company accounts in; beside it goes the
           money the passenger actually handed over, at the rate frozen on
           this ticket — so the passenger's own number is on their own ticket
           and nobody has to convert anything at the door. */
        $fxCur = strtoupper((string) ($booking['fx_currency'] ?? ''));
        $fxTot = (float) ($booking['fx_total'] ?? 0);
        if ($fxCur !== '' && $fxCur !== $currency && $fxTot > 0) {
            self::gdText($im, 25, 88 + self::gdWidth(36, $amt) + 26, 1234 + $grow, $gold,
                $fxCur . ' ' . number_format($fxTot), true);
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
        /* The advance-booking offer on its own line (26 Sep 2026 follow-up):
           the passenger booked early and this is what that earned them. It
           sits above the SAVED line inside the fare band, right-aligned, so a
           coupon and the offer can both be read. */
        $advCut = (float) ($booking['advance_discount'] ?? 0);
        if ($advCut > 0) {
            $advTxt = 'अग्रिम बुकिङ छुट  ·  ADVANCE OFFER − ₹ ' . number_format($advCut);
            $aw = self::gdWidth(15, $advTxt);
            self::gdText($im, 15, $W - 88 - $aw, 1172 + $grow, $gold, $advTxt, true);
        }

        /* Separate, labelled QR cards. Integer modules + four-module quiet
           zones stay crisp in the original PNG and WhatsApp's image copy. */
        $qrData = appUrl('verify-ticket.php') . '?pnr=' . urlencode($pnr) . '&k=' . self::downloadToken($pnr);
        if (!class_exists('QrCode')) { require_once __DIR__ . '/qr.php'; }
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
        self::gdText($im, 20, 76, 1300 + $grow, $navy, 'TICKET VERIFICATION', true);
        try { $drawQr($qrData, 76, 1320 + $grow, 245); }
        catch (Throwable $e) { Logger::error('Ticket verification QR: ' . $e->getMessage()); }
        $infoX = $payUpi !== '' ? 560 : 360;
        if ($payUpi !== '') {
            self::gdRounded($im, 550, 1270 + $grow, 1010, 1320 + $grow, 12, $orange);
            self::gdText($im, 22, 580, 1305 + $grow, $white, 'SCAN & PAY', true);
            try { $drawQr($payUpi, 590, 1332 + $grow, 350); }
            catch (Throwable $e) { Logger::error('Payment QR: ' . $e->getMessage()); }
            self::gdText($im, 18, 560, 1710 + $grow, $ink, $clampTo(18, 'UPI: ' . $upiVpa, 448), true);
            self::gdText($im, 16, 560, 1740 + $grow, $mut, $clampTo(16, Settings::getString('upi_name', APP_NAME), 448), false);
        } else {
            self::gdText($im, 24, $infoX, 1300 + $grow, $settlement['due'] <= 0 ? $green : $orange,
                $settlement['due'] <= 0 ? 'PAID' : 'PAYMENT DUE', true);
            $ref = (string) ($booking['payment']['utr_number'] ?? '');
            self::gdText($im, 17, $infoX, 1420 + $grow, $mut, $clampTo(17, $ref, 640), false);
            if ($settlement['due'] > 0) {
                self::gdText($im, 16, $infoX, 1454 + $grow, $mut, 'Contact the office for payment instructions.', false);
            }
        }
        self::gdText($im, 18, $infoX, ($payUpi !== '' ? 1780 : 1345) + $grow, $ink,
            'Paid: ' . $currency . ' ' . number_format($settlement['paid'], 2), true);
        self::gdText($im, 18, $infoX, ($payUpi !== '' ? 1810 : 1380) + $grow, $ink,
            'Due: ' . $currency . ' ' . number_format($settlement['due'], 2), true);
        self::gdText($im, 19, 60, 1398 + $grow + $qrExt - 90, $ink, 'Support: ' . self::latin($co['phone']), true);
        self::gdText($im, 18, 60, 1428 + $grow + $qrExt - 90, $orange, self::latin($co['web']), true);
        $wa = (string) ($co['whatsapp'] ?? Settings::officeWhatsApp());
        if ($wa !== '') { self::gdText($im, 16, 60, 1456 + $grow + $qrExt - 90, $green, 'WhatsApp: +' . $wa, true); }

        /* Who cut it, in full — name, code and the agent's own phone, with
           the CODE repeated as an orange chip. The header chip answers it at
           a glance; this strip is the one a passenger reads back over the
           phone and the office checks against the chalani. Facts come from
           Ticket::issuedBy(), the same call the PDF makes.

           x stops at 727: the QR card's left edge is W-60-(qr+24) and the
           widest realistic QR still leaves it at 751, so the strip can never
           run under the code no matter which QR version the payload picks. */
        /* 740, not 727 (26 Sep 2026): the tile carries a desk name, a phone
           and a timestamp now. The QR card's left edge is 751 at the widest
           realistic QR version, so 740 still cannot run under it. */
        $tileR = $payUpi !== '' ? 560 : 740;
        /* 26 Sep 2026: the tile grew by one line so the DESK can be named in
           full with its own phone, and the moment the ticket was cut printed
           under it. Both were asked for by name. */
        self::gdRounded($im, 60, 1472 + $grow + $qrExt, $tileR, 1566 + $grow + $qrExt, 14, $tile);
        imagefilledrectangle($im, 60, 1486 + $grow + $qrExt, 66, 1552 + $grow + $qrExt, $orange);
        self::gdText($im, 15, 82, 1500 + $grow + $qrExt, $mut, 'टिकट काट्ने  ·  ' . $issued['label'], false);
        $chip  = self::latinUpper($issued['code']);
        $chipW = self::gdWidth(20, $chip) + 34;
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
        self::gdText($im, 20, 82, 1526 + $grow + $qrExt, $ink, $who, true);

        /* The desk by name and number, and when the ticket was cut. The desk
           gives ground before the time does: a passenger can read the town
           off the header band, but nothing else on the ticket says WHEN. */
        /* The NAME gives ground, never the number: a passenger who has to
           ring the window needs the digits, and the town is already in the
           header band. The code in brackets is dropped too — it is on the
           orange chip two lines up. */
        $deskName  = self::display((string) ($issued['locName'] ?? ''));
        $deskPhone = self::display((string) ($issued['deskPhone'] ?? ''));
        $cutTxt    = ($issued['issuedAt'] ?? '') !== '' ? 'काटिएको  ' . self::latin((string) $issued['issuedAt']) : '';
        $cutSz     = 13;   // the time is the smaller of the two — the desk name is what a passenger reads
        if ($deskName !== '' || $deskPhone !== '' || $cutTxt !== '') {
            $cutW    = $cutTxt !== '' ? self::gdWidth($cutSz, $cutTxt) + 22 : 0;
            $maxDesk = $tileR - 24 - 82 - $cutW;
            $tail    = $deskPhone !== '' ? '  ·  ' . $deskPhone : '';
            /* The name is printed WHOLE or not at all. It is already in the
               header band in full, so half of it here ("Nepalgunj - Puspal")
               buys nothing and reads like a bug; the number and the time are
               what this line exists for. */
            if ($deskName !== '' && self::gdWidth(15, $deskName . $tail) > $maxDesk) {
                $deskName = '';
            }
            $deskTxt = $deskName !== '' ? $deskName . $tail : ltrim($tail, ' ·');
            if (trim($deskTxt) !== '') {
                self::gdText($im, 15, 82, 1554 + $grow + $qrExt, $mut, trim($deskTxt), false);
            }
            if ($cutTxt !== '') {
                self::gdText($im, $cutSz, $tileR - 16 - self::gdWidth($cutSz, $cutTxt), 1555 + $grow + $qrExt, $mut, $cutTxt, false);
            }
        }

        /* Footer notes on the cream */
        $n1 = 'सरकारी परिचयपत्र अनिवार्य  ·  बस छुट्नु ३० मिनेट अगाडि आउनुहोस्';
        self::gdText($im, 17, (int) (($W - self::gdWidth(17, $n1)) / 2), 1600 + $grow + $qrExt, $mut, $n1, false);
        $cin = (string) ($co['cin'] ?? '');
        $n2  = self::display($co['name'])
             . ($cin !== '' ? '  ·  ' . ($co['regLabel'] ?? 'CIN') . ' ' . $cin : '')
             . (($co['country'] ?? 'IN') === 'NP' && trim((string) $co['address']) !== ''
                 ? '  ·  ' . self::latin((string) $co['address']) : '');
        self::gdText($im, 16, (int) (($W - self::gdWidth(16, $n2)) / 2), 1628 + $grow + $qrExt, $mut, $n2, false);

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
                    r.from_city, r.to_city, r.route_code,
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
    private const PNG_LAYOUT_CHANGED = '2026-09-26 13:07:00';   // IST: the desk that cut it, its own number and the minute, in a taller issuer tile; the Nepal entity in the header. Before: a Nepal desk's NPR beside the rupee in the fare band. Before: Nepali on the ticket shaped by HarfBuzz (includes/devshape.php) - conjuncts, reph and the i-matra drawn as written. Before: the two-floor seat labels and the counter location (both 24 Sep, 00:34 / 01:49).

    /** Bump whenever renderTicketPdf()'s layout changes — see pdfPath().
     *  A cached PDF older than this re-renders ONCE on its next open, so the
     *  seat box + stub pick up the current seat labels without a manual purge
     *  (23 Sep 2026: the two-floor grid A1-F6 / A7-F12). */
    private const PDF_LAYOUT_CHANGED = '2026-09-26 13:07:00';   // IST: the desk, its number and the minute under ISSUED BY; the Nepal entity in the band. Before: a Nepal desk's NPR on the fare box line. Before: Nepali in the PDF shaped by HarfBuzz (glyph ids from DevShape into the Identity-H stream). Before: the two-floor seat labels, COUNTER line and desk code (24 Sep, 00:34 / 01:49).

    /**
     * How many passengers the ticket names one by one before it stops and
     * counts the rest. Twelve is two full sleeper cabins — past that the
     * picture is a manifest, and the chalani is the document for that.
     */
    private const PNG_MAX_PAX_ROWS = 12;

    /* Warm brown palette — premium boarding pass look. */
    private const BROWN  = [16, 42, 86];
    private const CREAM  = [240, 247, 255];

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
            'यात्रुको विवरण' => 'YATRIHARU', 'ओर्लिने ठाउँ' => 'ORLINE THAU',
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

        /* The desk that cut it is the issuer (26 Sep 2026): a Nepalgunj
           ticket carries the Nepal entity, its office and a Nepal number. */
        $coPdf   = Settings::companyFor($booking['counter_code'] ?? null);
        $company = $coPdf['name'];
        $cin     = (string) ($coPdf['cin'] ?? '');

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
        $seatList = self::seatLabelList($seats, $booking, ' · ');

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
        $pdf->rect(0, 0, $W, 96, self::BROWN, true);
        $pdf->rect(0, 92, $W, 4, self::ORANGE, true);
        $markTxt = self::markText($ticket, $mark);
        if ($markTxt !== '') {
            $pdf->rect(0, 0, $W, 13, $mark === 'dup' ? [70, 78, 94] : [176, 42, 42], true);
            $pdf->textCenter(0, $W, 9.5, $markTxt, 7, 'F2', [255, 255, 255]);
        }

        // Company logo on a white chip (5 Sep 2026) — the brand mark the
        // owner asked for. Text shifts right only when the logo is there,
        // so a missing file degrades to the old text-only band.
        $textX = 40;
        $logo  = self::logoFile();
        if ($logo !== null) {
            $pdf->rect(36, 14, 84, 66, [255, 255, 255], true);
            $pdf->image($logo, 41, 20, 74, 54);   // 440x321 source — keep the aspect
            $textX = 134;
        }
        $pdf->text($textX, 20, strtoupper($company), 17, 'F2', [255, 255, 255]);
        self::devText($pdf, $textX, 44, 'भारत–नेपाल बस सेवा', 11, $F7, self::GOLD);
        if ($cin !== '') {
            /* "CIN" is the Indian companies register. A Nepalgunj ticket
               carries the Nepal registration under its own label. */
            $pdf->text($textX, 68, ($coPdf['regLabel'] ?? 'CIN') . ': ' . $cin, 7.5, 'F1', [200, 190, 170]);
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
        $paid = self::paymentSummary($booking)['due'] <= 0;
        $due  = inr((float) $booking['total_amount']);
        if ($paid) {
            $method = strtoupper((string) ($pay['method'] ?? ''));
            $bandBg = [14, 122, 66];
            $band   = 'PAID  ·  ' . $due . ($method !== '' ? '  ·  ' . ($method === 'COD' ? 'CASH' : $method) : '');
        } elseif ((int) $booking['is_cod'] === 1) {
            $bandBg = [196, 106, 0];
            $band   = 'CASH ON BOARDING  ·  PAY ' . $due . ' TO THE CREW';
        } else {
            $bandBg = [176, 42, 42];
            $band   = 'PAYMENT PENDING  ·  NOT VALID UNTIL APPROVED';
        }
        $pdf->rect(0, 96, $W, 20, $bandBg, true);
        $pdf->textCenter(0, $W, 101, $band, 9, 'F2', [255, 255, 255]);

        /* ==============================================================
           BOARDING PASS CARD — professional layout, prominent time
           ============================================================== */
        $cardX = 36;
        $cardY = 122;
        $cardW = $W - 72;
        $cardH = 252;
        $tear  = $cardX + $cardW * 0.68;

        $pdf->roundedRect($cardX, $cardY, $cardW, $cardH, 10, [255, 255, 255], true);
        $pdf->roundedRect($cardX, $cardY, $cardW, $cardH, 10, self::LINE, false);
        $pdf->rect($tear, $cardY + 1, $cardX + $cardW - $tear - 1, $cardH - 2, self::CREAM, true);
        self::perforate($pdf, $tear, $cardY + 8, $cardY + $cardH - 8);

        /* ---- Passenger name ---- */
        $lx = $cardX + 20;
        self::devText($pdf, $lx, $cardY + 16, 'यात्रु', 8, $F7, self::MUTE);
        $pdf->text($lx + 30, $cardY + 16, '·  PASSENGER', 7, 'F1', self::MUTE);
        self::devOrLatin($pdf, $lx, $cardY + 32, (string) $primary, 14, $F8, 'F2', self::BROWN, true);
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
        $pdf->text($cx0, $infoY + 14, formatDate($booking['travel_date'], 'D, j M Y'), 9, 'F2', self::INK);
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
        $pdf->roundedRect($seatBoxX, $infoY - 8, $seatBoxW, 54, 8, self::ORANGE, true);
        self::devCenter($pdf, $seatBoxX, $seatBoxX + $seatBoxW, $infoY - 1, $paxCount > 1 ? 'सिट' : 'सिट नं.', 7.5, $F7, [255, 255, 255]);
        $seatText = $seatList !== '' ? $seatList : '—';
        $seatSize = 18.0;
        while ($seatSize > 8 && $pdf->textWidth($seatText, $seatSize, 'F2') > $seatBoxW - 10) { $seatSize -= 0.5; }
        $pdf->textCenter($seatBoxX, $seatBoxX + $seatBoxW, $infoY + 16, $seatText, $seatSize, 'F2', [255, 255, 255]);

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

        /* ==============================================================
           PASSENGER TABLE
           ============================================================== */
        $tableY = $cardY + $cardH + 22;
        self::devText($pdf, 40, $tableY, 'यात्रुको विवरण', 10, $F8, self::BROWN);
        $pdf->text(40 + 90, $tableY + 2, '·  PASSENGERS', 8, 'F1', self::MUTE);
        $tableY += 18;

        $pdf->rect(40, $tableY, $W - 80, 22, self::BROWN, true);
        $pdf->text(52, $tableY + 6, 'Name', 9, 'F2', [255, 255, 255]);
        $pdf->text(300, $tableY + 6, 'Age', 9, 'F2', [255, 255, 255]);
        $pdf->text(360, $tableY + 6, 'Gender', 9, 'F2', [255, 255, 255]);
        $pdf->textRight($W - 52, $tableY + 6, 'Seat', 9, 'F2', [255, 255, 255]);
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
            foreach ($shown as $i => $pax) {
                if ($i % 2 === 1) {
                    $pdf->rect(40, $tableY, $W - 80, 20, self::CREAM, true);
                }
                self::devOrLatin($pdf, 52, $tableY + 5, (string) $pax['full_name'], 10, $F7, 'F1', self::INK);
                $pdf->text(300, $tableY + 5, $pax['age'] !== null ? (string) $pax['age'] : '-', 10, 'F1', self::INK);
                $pdf->text(360, $tableY + 5, self::latin((string) ($pax['gender'] ?? '-')), 10, 'F1', self::INK);
                $pdf->textRight($W - 52, $tableY + 5, self::seatLabel((string) $pax['seat_no'], $booking), 10, 'F2', self::BROWN);
                $tableY += 20;
            }
            $rest = array_slice($paxAll, count($shown));
            if ($rest !== []) {
                $restSeats = self::seatLabelList(array_map(static fn($p) => (string) $p['seat_no'], $rest), $booking, ' · ');
                $pdf->rect(40, $tableY, $W - 80, 20, self::CREAM, true);
                $pdf->text(52, $tableY + 5, '+ ' . count($rest) . ' more passengers (full list with the office)', 9, 'F2', self::INK);
                $pdf->textRight($W - 52, $tableY + 5, self::latin($restSeats), 7.5, 'F2', self::BROWN);
                $tableY += 20;
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
        $boardLabel = self::latin(self::stopForPrint((string) ($booking['boarding_stop'] ?? '-')));
        $pdf->textBlock(40, $boardY + 14, 290, $boardLabel !== '' ? $boardLabel : '-', 10, 'F1', self::INK);

        self::devText($pdf, 40, $boardY + 42, 'ओर्लिने ठाउँ', 8, $F7, self::MUTE);
        $pdf->text(40 + 72, $boardY + 44, '·  DROP POINT', 7, 'F1', self::MUTE);
        $dropLabel = self::latin(self::stopForPrint((string) ($booking['drop_stop'] ?? '-')));
        $pdf->textBlock(40, $boardY + 56, 290, $dropLabel !== '' ? $dropLabel : '-', 10, 'F1', self::INK);

        // Fare box
        $pdf->roundedRect($W - 220, $boardY, 180, 70, 8, self::BROWN, true);
        self::devText($pdf, $W - 205, $boardY + 10, $paid ? 'जम्मा रकम' : 'बाँकी रकम', 9, $F7, self::GOLD);
        $pdf->text($W - 205, $boardY + 28, inr((float) $booking['total_amount']), 22, 'F2', [255, 255, 255]);
        /* One line under the amount, shared by the two things that can need
           it. A Nepal desk's NPR wins the space when both apply: the rupee
           breakdown is already on the invoice, but the NPR the passenger paid
           appears nowhere else on the page. */
        $fxCurPdf = strtoupper((string) ($booking['fx_currency'] ?? ''));
        $fxTotPdf = (float) ($booking['fx_total'] ?? 0);
        $discPdf  = (float) ($booking['coupon_discount'] ?? 0);
        /* The advance-booking offer is named on that line too (26 Sep 2026
           follow-up): "Offer" beside "Disc", so early booking reads as its
           own saving. The invoice page itemises it in full. */
        $advPdf   = (float) ($booking['advance_discount'] ?? 0);
        if ($fxCurPdf !== '' && $fxTotPdf > 0) {
            $cuts = ($discPdf > 0 ? '  -  Disc ' . self::latin(inr($discPdf)) : '')
                  . ($advPdf > 0 ? '  -  Offer ' . self::latin(inr($advPdf)) : '');
            $fxLine = $fxCurPdf . ' ' . number_format($fxTotPdf)
                . ($cuts !== '' ? $cuts : '  @ ' . number_format((float) ($booking['fx_rate'] ?? 0), 2));
            $pdf->text($W - 205, $boardY + 42, $fxLine, 8, 'F1', [255, 226, 168]);
        } elseif ($discPdf > 0 || $advPdf > 0) {
            $pdf->text($W - 205, $boardY + 42, 'Base ' . inr((float) $booking['base_total'])
                . ($discPdf > 0 ? '  -  Disc ' . inr($discPdf) : '')
                . ($advPdf > 0 ? '  -  Offer ' . inr($advPdf) : ''), 8, 'F1', [220, 210, 195]);
        }
        // Fare by passenger count (4 Sep 2026): "3 x Rs 2,000" for a party,
        // so the family sees how the total was built; a solo ticket keeps
        // the seat count.
        $fps = (float) ($booking['fare_per_seat'] ?? 0);
        $paxLine = (count($seats) > 1 && $fps > 0)
            ? count($seats) . ' x ' . self::latin(inr($fps)) . ' · ' . self::latinUpper((string) $booking['status'])
            : count($seats) . ' seat(s) · ' . self::latinUpper((string) $booking['status']);
        $pdf->text($W - 205, $boardY + 56, $paxLine, 8, 'F1', [200, 190, 170]);

        /* Live-status pill */
        $live = self::liveStatus($booking);
        if ($live !== null) {
            $pillBg = self::liveStatusRgb((string) $live['color']);
            $pillTx = self::latinUpper((string) $live['label']);
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
        /* The desk by name and number, and the moment it was cut (owner,
           26 Sep 2026). Two short lines rather than one long one: a Nepali
           desk name plus a +977 number does not fit beside the seller. */
        $deskLine2 = implode('  ·  ', array_filter([
            self::latin((string) ($issued['location'] ?? '')),
            self::latin((string) ($issued['deskPhone'] ?? '')),
        ], static fn(string $s): bool => $s !== ''));
        if ($deskLine2 !== '') {
            $pdf->text(400, $cY + 26, $pdf->textWidth($deskLine2, 7.5, 'F1') > $issuedW
                ? mb_substr($deskLine2, 0, 46) . '..' : $deskLine2, 7.5, 'F1', self::MUTE);
        }
        if ((string) ($issued['issuedAt'] ?? '') !== '') {
            $pdf->text(400, $cY + 36, 'Issued  ' . self::latin((string) $issued['issuedAt']), 7.5, 'F1', self::MUTE);
        }

        /* ==============================================================
           OFFICE CONTACTS
           ============================================================== */
        $officeY = $cY + 32;
        $pdf->line(40, $officeY - 4, $W - 40, $officeY - 4, self::LINE, 0.5);
        $offices = [
            ['Mehsana',   '+91 91048 01507'],
            ['Ahmedabad', '+91 91570 01507'],
            ['Baroda',    '+91 87358 81507'],
            ['Surat',     '+91 73593 01507'],
        ];
        $colW2 = ($W - 80) / 2;
        foreach ($offices as $i => [$oName, $oNum]) {
            $ox = 40 + ($i % 2) * $colW2;
            $oy = $officeY + (int) floor($i / 2) * 13;
            $pdf->text($ox, $oy, $oName . ': ', 7.5, 'F2', self::INK);
            $pdf->text($ox + 56, $oy, $oNum, 7.5, 'F1', self::INK);
        }

        /* ==============================================================
           FOOTER — QR + Nepali travel notes
           ============================================================== */
        $footY = $pdf->height() - 120;
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
            $pdf->imageFromString($webQrBin, 40, $footY + 6, 62, 62);
            self::devCenter($pdf, 38, 104, $footY + 70, 'टिकट स्थिति', 6.5, $F7, self::MUTE);
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
        $pdf->text($noteX, $footY + 74, 'Help: ' . self::latin($coPdf['phone']) . '  ·  shreehariglobal.in', 7.5, 'F1', self::MUTE);

        // Thank you footer
        self::devCenter($pdf, 40, $W - 40, $pdf->height() - 18, 'धन्यवाद · शुभ यात्रा · Thank you · ' . $company, 9, $F7, self::ORANGE);

        return $pdf;
    }

    private static function renderInvoicePdf(array $booking): Pdf
    {
        $pdf = new Pdf();
        $W   = $pdf->width();

        $company = Settings::getString('company_name', 'S Hari Global Pvt Ltd');
        $address = Settings::getString('company_address', '');
        $cin     = Settings::getString('company_cin', '');
        $gstin   = Settings::getString('company_gstin', '');

        $pdf->rect(0, 0, $W, 70, self::NAVY, true);
        $invTextX = 40;
        $invLogo  = self::logoFile();
        if ($invLogo !== null) {
            $pdf->rect(36, 10, 70, 50, [255, 255, 255], true);
            $pdf->image($invLogo, 40, 14, 62, 45);   // 440x321 source — keep the aspect
            $invTextX = 118;
        }
        $pdf->text($invTextX, 20, strtoupper($company), 16, 'F2', [255, 255, 255]);
        $pdf->textRight($W - 40, 22, 'TAX INVOICE', 16, 'F2', self::GOLD);

        $pdf->textBlock(40, 84, 320, $address, 9, 'F1', self::MUTE);
        if ($cin !== '')   $pdf->text(40, 120, 'CIN: ' . $cin, 9, 'F1', self::INK);
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
        if ((float) ($booking['advance_discount'] ?? 0) > 0) $rows[] = ['Advance booking offer', -(float) $booking['advance_discount']];
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
