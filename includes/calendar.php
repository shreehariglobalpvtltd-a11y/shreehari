<?php
/**
 * =====================================================================
 *  Calendar — .ics event generation for journey emails.
 *
 *  One VEVENT per booking, attached to the ticket-confirmed email so
 *  "Add to calendar" works in Gmail / Outlook / phone mail apps.
 *
 *  Times are written as FLOATING local time (no TZID) on purpose: the
 *  route crosses the India/Nepal border (IST vs NPT) and departure
 *  times are communicated as wall-clock at the boarding point — a
 *  floating time shows exactly that on every device.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Calendar
{
    /**
     * Build the .ics content for one journey.
     *
     * @param array{
     *   pnr: string, route?: string, date?: string, depTime?: string,
     *   seats?: string, busNo?: string, pickup?: string
     * } $facts date = Y-m-d, depTime = H:i (both may be '' — then null is returned)
     */
    public static function journeyEvent(array $facts): ?string
    {
        $pnr  = trim((string) ($facts['pnr'] ?? ''));
        $date = trim((string) ($facts['date'] ?? ''));
        if ($pnr === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $time = trim((string) ($facts['depTime'] ?? ''));
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '00:00';
        }

        $start = str_replace('-', '', $date) . 'T' . str_replace(':', '', $time) . '00';

        $company = Settings::getString('company_name', APP_NAME);
        $route   = trim((string) ($facts['route'] ?? ''));
        $summary = $company . ' Bus' . ($route !== '' ? ': ' . $route : '');

        $descLines = ['PNR: ' . $pnr];
        if (!empty($facts['seats'])) {
            $descLines[] = 'Seat(s): ' . $facts['seats'];
        }
        if (!empty($facts['busNo'])) {
            $descLines[] = 'Bus: ' . $facts['busNo'];
        }
        $descLines[] = 'Ticket: ' . Ticket::downloadUrl($pnr);

        $host = parse_url(APP_URL, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : 'shreehariglobal.in';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//' . self::esc($company) . '//Booking//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . self::esc($pnr) . '@' . $host,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $start,
            // Arrival varies by stop; a generous block keeps the whole
            // travel day visibly occupied without claiming a false ETA.
            'DURATION:PT12H',
            'SUMMARY:' . self::esc($summary),
            'DESCRIPTION:' . self::esc(implode("\n", $descLines)),
        ];
        $pickup = trim((string) ($facts['pickup'] ?? ''));
        if ($pickup !== '') {
            $lines[] = 'LOCATION:' . self::esc($pickup);
        }
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /** RFC 5545 text escaping: backslash, semicolon, comma, newline. */
    private static function esc(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\;', '\,', '\n', '\n', '\n'],
            $text
        );
    }

    /** Fold content lines longer than 75 octets (continuation = CRLF + space). */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out   = '';
        $chunk = substr($line, 0, 75);
        $rest  = substr($line, 75);
        $out  .= $chunk;
        while ($rest !== '') {
            $out  .= "\r\n " . substr($rest, 0, 74);
            $rest  = (string) substr($rest, 74);
        }
        return $out;
    }
}
