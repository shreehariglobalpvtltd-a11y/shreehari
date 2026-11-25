<?php
/**
 * =====================================================================
 *  PaxId — the optional passenger ID for the Rupaidiha border (25 Nov 2026, op5).
 *
 *  Owner (item 5): the border crew asks each traveller for an ID. The desk may
 *  now type it per passenger on the staff SmartBook card (counter / admin /
 *  agent desk). It is NEVER required: a blank row is simply "not given".
 *
 *  - Stored in the columns booking_passengers has always had (id_type /
 *    id_number), through BookingService::create()'s own paxIdField() path -
 *    nothing here writes a booking row.
 *  - The chalani (screen, print sheet, PDF, PNG) shows a SHORT form for the
 *    office / counter copy only - "CTZ **1234" - never on an agent copy. The
 *    full number prints only while chalani_id_full is on (default off).
 *
 *  Switches (read inline, not in the boot list):
 *    pax_id_field_on  (default on)  - the field on the desk card + the chalani tag
 *    chalani_id_full  (default off) - print the whole number instead of **last 4
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class PaxId
{
    /** Stored value => [Nepali, English, short code for the chalani]. */
    public const TYPES = [
        'Nepali Citizenship' => ['नागरिकता', 'Citizenship', 'CTZ'],
        'Passport'           => ['राहदानी', 'Passport', 'PP'],
        'Aadhaar Card'       => ['आधार', 'Aadhaar', 'AADH'],
        'Voter ID'           => ['मतदाता', 'Voter ID', 'VOTER'],
        'Other'              => ['अन्य', 'Other', 'ID'],
    ];

    /** Older stored spellings (contact-level ID, customer profile) and their short code. */
    private const ALIASES = [
        'nepali citizenship card' => 'CTZ', 'citizenship' => 'CTZ', 'nagrikta' => 'CTZ',
        'driving licence' => 'DL', 'driving license' => 'DL', 'aadhaar' => 'AADH', 'aadhar' => 'AADH',
        'voter' => 'VOTER', 'passport' => 'PP',
    ];

    public static function enabled(): bool
    {
        return Settings::getBool('pax_id_field_on', true);
    }

    /** The list the desk card draws: [{v, ne, en}]. */
    public static function choices(): array
    {
        $out = [];
        foreach (self::TYPES as $v => [$ne, $en]) {
            $out[] = ['v' => $v, 'ne' => $ne, 'en' => $en];
        }
        return $out;
    }

    /**
     * One passenger's typed ID, cleaned - or null when no usable number was
     * given (fewer than 4 letters/digits). An unknown type with a number is
     * 'Other' (never a guessed document).
     *
     * @return array{type: string, num: string}|null
     */
    public static function clean(mixed $type, mixed $num): ?array
    {
        $n = strtoupper((string) preg_replace('/[^A-Za-z0-9\-\/]/', '', is_scalar($num) ? (string) $num : ''));
        $n = substr($n, 0, 30);
        if (strlen((string) preg_replace('/[^A-Z0-9]/', '', $n)) < 4) {
            return null;
        }
        $t = is_scalar($type) ? trim((string) $type) : '';
        return ['type' => isset(self::TYPES[$t]) ? $t : 'Other', 'num' => $n];
    }

    /** Short code of a stored type: CTZ / PP / AADH / VOTER / DL / ID. */
    public static function code(string $type): string
    {
        $t = trim($type);
        if (isset(self::TYPES[$t])) {
            return self::TYPES[$t][2];
        }
        return self::ALIASES[strtolower($t)] ?? 'ID';
    }

    /**
     * The chalani tag: "CTZ **1234" ('' when there is no number). $full prints
     * the whole number (chalani_id_full). Latin only - the PNG cannot draw
     * Devanagari.
     */
    public static function short(?string $type, ?string $num, ?bool $full = null): string
    {
        $n = strtoupper(trim((string) $num));
        $plain = (string) preg_replace('/[^A-Z0-9]/', '', $n);
        if ($plain === '') {
            return '';
        }
        $full ??= Settings::getBool('chalani_id_full', false);
        $code = self::code((string) $type);
        if ($full) {
            return $code . ' ' . (string) preg_replace('/[^A-Z0-9\-\/]/', '', $n);
        }
        return $code . ' **' . substr($plain, -4);
    }
}
