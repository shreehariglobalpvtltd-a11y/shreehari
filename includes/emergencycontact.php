<?php
/**
 * =====================================================================
 *  includes/emergencycontact.php - a passenger's emergency contact
 *  (15 Nov 2026, P7; database/upgrade-2026-11-15-emergency-contact.sql)
 *
 *  One optional person per booking the crew / office may ring if
 *  something happens on the road: three nullable columns on bookings
 *
 *    emergency_name      VARCHAR(80)   "Hari Thapa"            (may be NULL)
 *    emergency_phone     VARCHAR(20)   "+9779812345678"        dial code kept
 *    emergency_relation  VARCHAR(30)   "father"                a RELATIONS key
 *
 *  It is NOT bookings.alt_phone: that "second mobile" is the booker's own
 *  other SIM - the ticket is sent to it (WaQueue) and it opens the booking
 *  (api/track.php). An emergency contact gets nothing and opens nothing:
 *  no message is ever sent to it from here, it never proves ownership of
 *  a ticket, it is only shown to the office (admin/booking-view.php) and to
 *  the driver of that departure (TripEvents::manifest).
 *
 *  India and Nepal share the 10-digit mobile, so the number is stored with
 *  its dial code ('+977…' / '+91…'), picked like the main mobile (the +977 /
 *  +91 box, or a prefix typed in the number itself).
 *
 *  Never required. A number that is not a valid mobile, or that is the
 *  passenger's own mobile, is ignored (read() says why, the card shows the
 *  note) - the sale goes on. Before the migration runs, available() is
 *  false and every write here is a quiet no-op.
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class EmergencyContact
{
    /** key => [Nepali, English] - the card's select, the office form, the driver's list. */
    public const RELATIONS = [
        'father'  => ['बुबा', 'Father'],
        'mother'  => ['आमा', 'Mother'],
        'husband' => ['श्रीमान्', 'Husband'],
        'wife'    => ['श्रीमती', 'Wife'],
        'brother' => ['दाजुभाइ', 'Brother'],
        'sister'  => ['दिदीबहिनी', 'Sister'],
        'friend'  => ['साथी', 'Friend'],
        'other'   => ['अन्य', 'Other'],
    ];

    public const NAME_MAX = 80;

    private static ?bool $available = null;

    /** Do the three columns exist (the migration has run)? Cached per request. */
    public static function available(): bool
    {
        if (self::$available === null) {
            try {
                self::$available = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'
                        AND COLUMN_NAME IN ('emergency_name', 'emergency_phone', 'emergency_relation')",
                    [],
                    0
                ) === 3;
            } catch (Throwable $e) {
                self::$available = false;
            }
        }
        return self::$available;
    }

    public static function resetCache(): void
    {
        self::$available = null;
    }

    /**
     * A typed mobile as it is stored: '+9779812345678' / '+919812345678', or
     * '' when it is not a valid Nepal / India mobile. A +977 / +91 typed in
     * the number wins over the country box (the main mobile's rule); the box
     * decides a bare 10 digits; Nepal when neither says.
     */
    public static function phoneFor(string $raw, string $country = ''): string
    {
        $c = resolvePhoneCountry('', $raw);
        if ($c === '') {
            $c = strtoupper(trim($country));
            $c = in_array($c, ['IN', '91'], true) ? 'IN' : 'NP';
        }
        $d = normalisePhone($raw);
        if (strlen($d) !== 10 || isPlaceholderPhone($d)) {
            return '';
        }
        $ok = $c === 'NP' ? preg_match('/^9[678]\d{8}$/', $d) === 1 : preg_match('/^[6-9]\d{9}$/', $d) === 1;
        return $ok ? '+' . countryDialCode($c) . $d : '';
    }

    /** The 10 national digits of a stored / typed number ('' when none). */
    public static function national(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($d) === 13 && str_starts_with($d, '977')) {
            return substr($d, 3);
        }
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            return substr($d, 2);
        }
        return strlen($d) >= 10 ? substr(normalisePhone($d), -10) : '';
    }

    /** A relation key from what a form sent: the key itself, or its Nepali / English word. */
    public static function relationKey(mixed $raw): ?string
    {
        $r = trim((string) (is_scalar($raw) ? $raw : ''));
        if ($r === '') {
            return null;
        }
        $low = mb_strtolower($r);
        if (isset(self::RELATIONS[$low])) {
            return $low;
        }
        foreach (self::RELATIONS as $key => [$ne, $en]) {
            if ($r === $ne || $low === mb_strtolower($en)) {
                return $key;
            }
        }
        return null;
    }

    /** "बुबा · Father" for a stored key ('' for none; an unknown stored word as it is). */
    public static function relationLabel(?string $key): string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return '';
        }
        return isset(self::RELATIONS[$key]) ? self::RELATIONS[$key][0] . ' · ' . self::RELATIONS[$key][1] : $key;
    }

    /**
     * One contact from a request: {name?, phone, country?, relation?}.
     *
     *   contact  the row to store, or null when there is nothing to store
     *   note     ''        nothing typed, or stored as typed
     *            'own'     the number is the passenger's own mobile - ignored
     *            'invalid' not a valid Nepal / India mobile - ignored
     *            'nophone' a name / relation without a number - ignored
     *
     * @return array{contact: ?array{name: ?string, phone: string, relation: ?string}, note: string}
     */
    public static function read(mixed $in, string $ownPhone): array
    {
        $in = is_array($in) ? $in : [];
        $name = Security::clean(is_scalar($in['name'] ?? null) ? (string) $in['name'] : '', self::NAME_MAX * 2);
        $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, self::NAME_MAX);
        $raw  = is_scalar($in['phone'] ?? null) ? Security::clean((string) $in['phone'], 24) : '';
        $rel  = self::relationKey($in['relation'] ?? null);
        if (preg_replace('/\D/', '', $raw) === '') {
            return ['contact' => null, 'note' => ($name !== '' || $rel !== null) ? 'nophone' : ''];
        }
        $phone = self::phoneFor($raw, is_scalar($in['country'] ?? null) ? (string) $in['country'] : '');
        if ($phone === '') {
            return ['contact' => null, 'note' => 'invalid'];
        }
        $own = self::national($ownPhone);
        if ($own !== '' && $own === self::national($phone)) {
            return ['contact' => null, 'note' => 'own'];
        }
        return ['contact' => ['name' => $name !== '' ? $name : null, 'phone' => $phone, 'relation' => $rel], 'note' => ''];
    }

    /**
     * Write (or, with null, clear) a booking's contact. False - and nothing
     * written - before the migration, or when the update fails; never throws.
     *
     * @param array{name: ?string, phone: string, relation: ?string}|null $c
     */
    public static function save(int $bookingId, ?array $c): bool
    {
        if ($bookingId <= 0 || !self::available()) {
            return false;
        }
        try {
            Database::run(
                'UPDATE bookings SET emergency_name = :n, emergency_phone = :p, emergency_relation = :r WHERE id = :id',
                [
                    'n'  => $c !== null && ($c['name'] ?? null) !== null ? mb_substr((string) $c['name'], 0, self::NAME_MAX) : null,
                    'p'  => $c !== null ? (string) $c['phone'] : null,
                    'r'  => $c !== null && ($c['relation'] ?? null) !== null ? (string) $c['relation'] : null,
                    'id' => $bookingId,
                ]
            );
            return true;
        } catch (Throwable $e) {
            Logger::warning('Emergency contact not saved', ['booking' => $bookingId, 'e' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * A bookings row's contact as the office / driver sees it, or null.
     *
     * @param array<string, mixed> $b
     * @return array{name: string, relation: string, relationLabel: string, phone: string, tel: string}|null
     */
    public static function fromRow(array $b): ?array
    {
        $phone = trim((string) ($b['emergency_phone'] ?? ''));
        if ($phone === '') {
            return null;
        }
        $d   = preg_replace('/\D/', '', $phone) ?? '';
        $nat = self::national($phone);
        $cc  = $nat !== '' && strlen($d) > 10 ? substr($d, 0, strlen($d) - 10) : '';
        $rel = trim((string) ($b['emergency_relation'] ?? ''));
        return [
            'name'          => trim((string) ($b['emergency_name'] ?? '')),
            'relation'      => $rel,
            'relationLabel' => self::relationLabel($rel),
            'phone'         => $cc !== '' ? '+' . $cc . ' ' . $nat : $phone,
            // a bare 10 digits (never written here, but a hand-edited row) stays bare: the SIM's own country
            'tel'           => strlen($d) > 10 ? '+' . $d : $d,
        ];
    }
}
