<?php
/**
 * =====================================================================
 *  OfficeReport — the office's WhatsApp report at set times (27 Sep 2026).
 *
 *  The owner asked for the buses and the money to reach the office and the
 *  CEO by themselves, a few times a day, without anyone asking the bot.
 *  cron/office-report.php calls send() when a slot in office_report_times
 *  (IST, the app clock) comes due; nothing else sends it.
 *
 *  What it carries — counts and sums only, never a passenger's name or
 *  number (the message sits on two personal phones):
 *    · today's sales (tickets, seats, rupees) and the 7-day total,
 *      straight from ReportChart::data() so the bot's "aaja ko report",
 *      the chart and this message can never disagree;
 *    · money verified today, with the NPR a Nepal desk took beside it;
 *    · payments waiting for approval, refunds;
 *    · today's and tomorrow's buses with seats sold / free — the same
 *      departures the occupancy tool counts, without creating a schedule
 *      row for a day nobody has searched yet.
 *
 *  Who gets it: numbers() — admin_whatsapp, ceo_whatsapp and any extra in
 *  office_report_numbers, one message per person however the number was
 *  typed. Delivery rides Notify::whatsapp() with purpose office_report,
 *  which the retry cron and the pending screen ignore (they only chase
 *  tickets). Outside a number's 24-hour WhatsApp window Meta needs an
 *  approved template; until one exists the report reaches a phone that
 *  has written to the bot within the last day.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once INCLUDE_PATH . '/reportchart.php';

final class OfficeReport
{
    /** How late a slot may still be sent: a cron that was down at 07:00 does not send "morning" at noon. */
    public const GRACE_MIN = 60;

    /** Office numbers, one per person (as typed; Notify adds the country code). @return list<string> */
    public static function numbers(): array
    {
        $raw = [Settings::getString('admin_whatsapp', ''), Settings::getString('ceo_whatsapp', '')];
        foreach (preg_split('/[\s,;]+/', Settings::getString('office_report_numbers', '')) ?: [] as $n) {
            $raw[] = $n;
        }
        $out  = [];
        $seen = [];
        foreach ($raw as $n) {
            $d = preg_replace('/\D/', '', (string) $n) ?? '';
            if (strlen($d) < 10) {
                continue;
            }
            $key = normalisePhone($d);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $d;
        }
        return $out;
    }

    /**
     * The slot due at $now ('Y-m-d H:i'), as 'Y-m-d H:i', or null. A slot is
     * due from its minute until GRACE_MIN later, once: $last is the slot sent
     * most recently. Times that are not HH:MM are ignored.
     */
    public static function dueSlot(string $times, string $now, string $last, int $graceMin = self::GRACE_MIN): ?string
    {
        $nowTs = strtotime($now);
        if ($nowTs === false) {
            return null;
        }
        $day  = date('Y-m-d', $nowTs);
        $best = null;
        foreach (preg_split('/[\s,;]+/', $times) ?: [] as $t) {
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($t), $m) !== 1) {
                continue;
            }
            $slot   = sprintf('%s %02d:%s', $day, (int) $m[1], $m[2]);
            $slotTs = (int) strtotime($slot);
            if ($slotTs <= $nowTs && $nowTs - $slotTs <= $graceMin * 60 && ($best === null || $slotTs > strtotime($best))) {
                $best = $slot;
            }
        }
        return ($best !== null && $best !== $last) ? $best : null;
    }

    /**
     * Departures on one day with seats sold / free. @return list<array{route: string, time: string, sold: int, cap: int, free: int, blocked: bool}>
     */
    public static function departures(string $day): array
    {
        $out  = [];
        $have = [];
        try {
            foreach (Database::fetchAll(
                "SELECT s.route_id, r.from_city, r.to_city, s.is_blocked, s.status,
                        COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                        COALESCE(bus.total_seats, rb.total_seats, 0) AS bus_seats,
                        (SELECT COUNT(*) FROM booking_seats bs WHERE bs.schedule_id = s.id AND bs.released_at IS NULL) AS sold
                   FROM schedules s
                   JOIN routes r ON r.id = s.route_id
                   LEFT JOIN buses bus ON bus.id = s.bus_id
                   LEFT JOIN buses rb  ON rb.id  = r.bus_id
                  WHERE s.travel_date = :d
                  ORDER BY dep_time",
                ['d' => $day]
            ) as $r) {
                $have[(int) $r['route_id']] = true;
                if ((string) $r['status'] === 'cancelled') {
                    continue;
                }
                $out[] = self::row((string) $r['from_city'], (string) $r['to_city'], (string) $r['dep_time'],
                    (int) $r['sold'], (int) $r['bus_seats'], (int) $r['is_blocked'] === 1);
            }
        } catch (Throwable $e) {
            // no schedules table on a bare database: the route fill below still reports
        }
        /* A day nobody has searched yet has no schedule row, but the daily bus
           still runs: count it from the active routes WITHOUT creating a row. */
        try {
            require_once INCLUDE_PATH . '/seats.php';
            foreach (Database::fetchAll(
                'SELECT r.id, r.from_city, r.to_city, r.dep_time, r.coach_type, COALESCE(rb.total_seats, 0) AS bus_seats
                   FROM routes r LEFT JOIN buses rb ON rb.id = r.bus_id
                  WHERE r.is_active = 1 ORDER BY r.sort_order, r.dep_time'
            ) as $r) {
                if (isset($have[(int) $r['id']])) {
                    continue;
                }
                $cap = (int) $r['bus_seats'];
                try {
                    $cap = count(Seats::seatIds((string) $r['coach_type'], 'sharing'));
                } catch (Throwable $e) {
                }
                $out[] = self::row((string) $r['from_city'], (string) $r['to_city'], (string) $r['dep_time'], 0, $cap, false);
            }
        } catch (Throwable $e) {
        }
        usort($out, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);
        return $out;
    }

    /** @return array{route: string, time: string, sold: int, cap: int, free: int, blocked: bool} */
    private static function row(string $from, string $to, string $time, int $sold, int $cap, bool $blocked): array
    {
        return [
            'route'   => trim($from) . ' → ' . trim($to),
            'time'    => substr($time, 0, 5),
            'sold'    => $sold,
            'cap'     => $cap,
            'free'    => max(0, $cap - $sold),
            'blocked' => $blocked,
        ];
    }

    /** Money verified on $day: INR, and the NPR a Nepal desk took (0 when the desk columns are absent). @return array{inr: float, npr: float, count: int} */
    public static function collected(string $day): array
    {
        $next = addDaysISO($day, 1);
        $args = ['s' => $day . ' 00:00:00', 'e' => $next . ' 00:00:00'];
        $row  = Database::fetch(
            "SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS amt FROM payments
              WHERE status = 'verified' AND COALESCE(verified_at, created_at) >= :s AND COALESCE(verified_at, created_at) < :e",
            $args
        ) ?? ['c' => 0, 'amt' => 0];
        $npr = 0.0;
        try {
            $npr = (float) Database::scalar(
                "SELECT COALESCE(SUM(local_amount), 0) FROM payments
                  WHERE status = 'verified' AND local_currency = 'NPR'
                    AND COALESCE(verified_at, created_at) >= :s AND COALESCE(verified_at, created_at) < :e",
                $args, 0
            );
        } catch (Throwable $e) {
            // local_amount arrives with the counter-desk migration
        }
        return ['inr' => (float) $row['amt'], 'npr' => $npr, 'count' => (int) $row['c']];
    }

    /**
     * The report for $day. Nepali first, one English line at the end.
     * @return array{text: string, media: ?string, figures: array<string, mixed>}
     */
    public static function build(string $day = '', string $slot = ''): array
    {
        $day   = Security::isValidDate($day) ? $day : todayISO();
        $data  = ReportChart::data($day, 7);
        $t     = $data['today'];
        $w     = $data['week'];
        $money = self::collected($day);
        $today = self::departures($day);
        $tmrw  = self::departures(addDaysISO($day, 1));
        $when  = $slot !== '' ? date('j M H:i', (int) strtotime($slot)) : date('j M', (int) strtotime($day));

        $lines   = [];
        $lines[] = '🚌 S Hari Global — अफिस रिपोर्ट · ' . $when . ' (IST)';
        $lines[] = '🎫 आज बिक्री: ' . $t['tickets'] . ' टिकट · ' . $t['seats'] . ' सिट · ' . inr($t['revenue']);
        $lines[] = '💰 आज प्रमाणित भुक्तानी: ' . inr($money['inr'])
            . ($money['npr'] > 0 ? ' (नेपाल काउन्टर: NPR ' . number_format($money['npr'], 0) . ')' : '');
        $lines[] = '⏳ Payment बाँकी: ' . $t['pending'] . ' · ↩️ Refund: ' . inr($t['refunds']);
        foreach ([['🚍 आजका बस:', $today], ['🚍 भोलिका बस:', $tmrw]] as [$head, $list]) {
            $lines[] = $head . ($list === [] ? ' छैन' : '');
            foreach (array_slice($list, 0, 8) as $b) {
                $lines[] = ' • ' . $b['route'] . ' ' . $b['time'] . ' — ' . $b['sold'] . '/' . $b['cap'] . ' बिक्री, '
                    . $b['free'] . ' खाली' . ($b['blocked'] ? ' (बन्द)' : '');
            }
            if (count($list) > 8) {
                $lines[] = ' • +' . (count($list) - 8) . ' थप';
            }
        }
        $lines[] = '📅 ७ दिन: ' . $w['tickets'] . ' टिकट · ' . inr($w['revenue']);
        $lines[] = 'EN: today ' . $t['tickets'] . ' tickets · ' . $t['seats'] . ' seats · ' . inr($t['revenue'])
            . ' · verified ' . inr($money['inr']) . ' · waiting ' . $t['pending']
            . ' · buses today ' . count($today) . ', tomorrow ' . count($tmrw) . '.';

        $media = Settings::getBool('wa_report_chart_on', true) ? ReportChart::url($day, 6 * 3600) : null;
        return [
            'text'    => implode("\n", $lines),
            'media'   => $media,
            'figures' => [
                'tickets' => (int) $t['tickets'], 'seats' => (int) $t['seats'], 'revenue' => (float) $t['revenue'],
                'pending' => (int) $t['pending'], 'refunds' => (float) $t['refunds'],
                'verified' => $money, 'today' => $today, 'tomorrow' => $tmrw,
                'week' => ['tickets' => (int) $w['tickets'], 'revenue' => (float) $w['revenue']],
            ],
        ];
    }

    /**
     * Build once and send to every office number. $dry sends nothing.
     * @return array{slot: string, numbers: int, sent: int, failed: int, dry: bool, text?: string}
     */
    public static function send(string $slot, bool $dry = false): array
    {
        $rep  = self::build(substr($slot, 0, 10), $slot);
        $nums = self::numbers();
        $out  = ['slot' => $slot, 'numbers' => count($nums), 'sent' => 0, 'failed' => 0, 'dry' => $dry];
        if ($dry) {
            $out['text'] = $rep['text'];
            return $out;
        }
        foreach ($nums as $n) {
            try {
                $ok = Notify::whatsapp($n, $rep['text'], $rep['media'], null, [], null, ['purpose' => 'office_report']);
                $ok === false ? $out['failed']++ : $out['sent']++;
            } catch (Throwable $e) {
                $out['failed']++;
            }
        }
        return $out;
    }
}
