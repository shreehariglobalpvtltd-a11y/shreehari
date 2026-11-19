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
 *      row for a day nobody has searched yet;
 *    · the FIRST slot of the day only (16 Nov 2026): yesterday's sales,
 *      complaints open / overdue (HelpDesk::stats), unread bell alerts and
 *      WhatsApp sends that failed in the last 24 h (morning()).
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
                "SELECT s.route_id, s.slot, r.from_city, r.to_city, s.is_blocked, s.status, r.is_active,
                        COALESCE(s.coach_type_override, r.coach_type) AS coach,
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
                // 2 Oct 2026: an empty schedule row on a route the office has
                // switched off is not a bus that runs - keep it out of the report.
                if ((int) $r['is_active'] !== 1 && (int) $r['sold'] === 0) {
                    continue;
                }
                // Nor is an extra bus (slot 2+) nobody is booked on.
                if ((int) ($r['slot'] ?? 1) > 1 && (int) $r['sold'] === 0) {
                    continue;
                }
                // Capacity from the coach the departure runs (schedules.total_seats
                // and an unassigned bus both said 0, printing "0/0 sold").
                $cap = 0;
                try {
                    require_once INCLUDE_PATH . '/seats.php';
                    $cap = count(Seats::seatIds((string) $r['coach'], 'sharing'));
                } catch (Throwable $e) {
                }
                if ($cap <= 0) { $cap = (int) $r['bus_seats']; }
                $out[] = self::row((string) $r['from_city'], (string) $r['to_city'], (string) $r['dep_time'],
                    (int) $r['sold'], $cap, (int) $r['is_blocked'] === 1);
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

        /* 16 Nov 2026 (Master v2 P6-5, owner's 07:00 digest): the FIRST slot of
           the day also carries yesterday's sales and what is waiting on the
           office — open / overdue complaints, unread bell alerts, WhatsApp
           sends that failed. Counts only, the same office numbers. */
        $morning = null;
        if (self::isFirstSlot($slot, Settings::getString('office_report_times', '07:00,13:00,20:00'))) {
            $morning = self::morning($day);
            $y = $morning['yesterday'];
            array_splice($lines, 1, 0, [
                '🌅 हिजो (' . date('j M', (int) strtotime($y['date'])) . '): ' . $y['tickets'] . ' टिकट · ' . $y['seats'] . ' सिट · ' . inr($y['revenue']),
            ]);
            $lines[] = '🛎️ गुनासो: ' . $morning['complaints_open'] . ' खुला · ' . $morning['complaints_overdue'] . ' समय नाघेको'
                . ' · 🔔 नपढेको सूचना: ' . $morning['alerts_unread']
                . ' · ⚠️ WhatsApp नपुगेको (२४ घण्टा): ' . $morning['wa_failed'];
        }

        $lines[] = 'EN: today ' . $t['tickets'] . ' tickets · ' . $t['seats'] . ' seats · ' . inr($t['revenue'])
            . ' · verified ' . inr($money['inr']) . ' · waiting ' . $t['pending']
            . ' · buses today ' . count($today) . ', tomorrow ' . count($tmrw) . '.'
            . ($morning !== null
                ? ' Yesterday ' . $morning['yesterday']['tickets'] . ' tickets · ' . inr($morning['yesterday']['revenue'])
                  . ' · complaints ' . $morning['complaints_open'] . ' open / ' . $morning['complaints_overdue'] . ' overdue'
                  . ' · unread alerts ' . $morning['alerts_unread'] . ' · failed WhatsApp ' . $morning['wa_failed'] . '.'
                : '');

        $media = Settings::getBool('wa_report_chart_on', true) ? ReportChart::url($day, 6 * 3600) : null;
        return [
            'text'    => implode("\n", $lines),
            'media'   => $media,
            'figures' => [
                'tickets' => (int) $t['tickets'], 'seats' => (int) $t['seats'], 'revenue' => (float) $t['revenue'],
                'pending' => (int) $t['pending'], 'refunds' => (float) $t['refunds'],
                'verified' => $money, 'today' => $today, 'tomorrow' => $tmrw,
                'week' => ['tickets' => (int) $w['tickets'], 'revenue' => (float) $w['revenue']],
                'morning' => $morning,
            ],
        ];
    }

    /**
     * Is $slot ('Y-m-d H:i') the earliest valid time in office_report_times?
     * An empty slot (a report built by hand) is not.
     */
    public static function isFirstSlot(string $slot, string $times): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} (\d{2}):(\d{2})$/', trim($slot), $s) !== 1) {
            return false;
        }
        $first = null;
        foreach (preg_split('/[\s,;]+/', $times) ?: [] as $t) {
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($t), $m) === 1) {
                $min = (int) $m[1] * 60 + (int) $m[2];
                $first = $first === null ? $min : min($first, $min);
            }
        }
        return $first !== null && (int) $s[1] * 60 + (int) $s[2] === $first;
    }

    /**
     * The morning extras for $day: yesterday's sales (ReportChart, the same
     * figures as the chart) and the office's open items.
     *
     * @return array{yesterday: array{date: string, tickets: int, seats: int, revenue: float},
     *               complaints_open: int, complaints_overdue: int, alerts_unread: int, wa_failed: int}
     */
    public static function morning(string $day): array
    {
        $yday = addDaysISO($day, -1);
        $y    = ReportChart::data($yday, 1)['today'];
        $help = ['open' => 0, 'in_progress' => 0, 'overdue' => 0];
        try {
            require_once INCLUDE_PATH . '/helpdesk.php';
            $help = HelpDesk::stats() + $help;
        } catch (Throwable $e) {
            // no help desk tables on a bare database
        }
        $unread = 0;
        try {
            require_once INCLUDE_PATH . '/adminalerts.php';
            $unread = AdminAlerts::unreadCount();
        } catch (Throwable $e) {
        }
        $failed = 0;
        try {
            // A WhatsApp send that failed and was not followed by a good send to the same number.
            $failed = (int) Database::scalar(
                "SELECT COUNT(DISTINCT m.to_number) FROM message_logs m
                  WHERE m.channel = 'whatsapp' AND m.status = 'failed'
                    AND m.created_at >= NOW() - INTERVAL 1 DAY
                    AND NOT EXISTS (SELECT 1 FROM message_logs m2
                                     WHERE m2.created_at >= NOW() - INTERVAL 1 DAY
                                       AND m2.to_number = m.to_number AND m2.channel = 'whatsapp'
                                       AND m2.status = 'sent' AND m2.id > m.id)",
                [], 0
            );
        } catch (Throwable $e) {
        }
        return [
            'yesterday'          => ['date' => $yday, 'tickets' => (int) $y['tickets'], 'seats' => (int) $y['seats'], 'revenue' => (float) $y['revenue']],
            'complaints_open'    => (int) $help['open'] + (int) $help['in_progress'],
            'complaints_overdue' => (int) $help['overdue'],
            'alerts_unread'      => $unread,
            'wa_failed'          => $failed,
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
            /* 19 Nov 2026: only true is a delivery - a wa.me link (string) means it
               did NOT go (it waits for a human tap), so it counts as failed */
            self::deliver($n, $rep['text'], $rep['media'], 'office_report') ? $out['sent']++ : $out['failed']++;
        }
        $out['email'] = self::emailFallback($out, 'S Hari office report · ' . $slot, $rep['text']);
        return $out;
    }

    /* =================================================================
     *  19 Nov 2026 (WA-6) - the COUNTER-WISE day report: per desk (or per
     *  seller with no desk) tickets, passengers, cash, online, NPR, still to
     *  collect, + per bus the chalani's passengers and money, + the day-close
     *  count against the register. Nepali first, counts and money only -
     *  never a passenger's name or number. cron/office-report.php sends it at
     *  counter_day_report_time (default 21:30 IST) while counter_day_report_on
     *  (default 1), to numbers() + counter_day_report_numbers.
     * ================================================================= */

    /** Test seams: WhatsApp (phone, text, media, purpose) -> bool|string; mail (to, subject, html, text) -> bool. */
    public static ?Closure $transport = null;
    public static ?Closure $mailer = null;

    /**
     * One WhatsApp; true only when an API delivered it. $metaTpl = an approved
     * Meta template (WaTplMeta::forPurpose) Notify may use outside the 24 h
     * window; the free text goes first inside it.
     */
    private static function deliver(string $to, string $text, ?string $media, string $purpose, ?array $metaTpl = null): bool
    {
        try {
            if (self::$transport !== null) {
                return (self::$transport)($to, $text, $media, $purpose, $metaTpl) === true;
            }
            require_once INCLUDE_PATH . '/notify.php';
            return Notify::whatsapp($to, $text, $media, null, [], null, ['purpose' => $purpose] + ($metaTpl !== null ? ['meta_tpl' => $metaTpl] : [])) === true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The office e-mail list: chalani_mail_to, else admin_email + company_email. @return list<string> */
    public static function emails(): array
    {
        $out = [];
        $cfg = trim(Settings::getString('chalani_mail_to', ''));
        foreach ($cfg !== '' ? (preg_split('/[\s,;]+/', $cfg) ?: []) : [Settings::getString('admin_email', ''), Settings::getString('company_email', '')] as $e) {
            $e = Security::email((string) $e);
            if ($e !== '' && !in_array(strtolower($e), array_map('strtolower', $out), true)) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /**
     * When EVERY WhatsApp failed (131070 / outside the window ...), the same text
     * once by e-mail (office_report_email_fallback, default 1). @return int e-mails sent
     */
    private static function emailFallback(array $res, string $subject, string $text): int
    {
        if ((int) ($res['sent'] ?? 0) > 0 || (int) ($res['numbers'] ?? 0) === 0 || !Settings::getBool('office_report_email_fallback', true)) {
            return 0;
        }
        $html = '<div style="font-family:Arial,sans-serif;white-space:pre-wrap;font-size:14px">' . Security::e($text)
              . '<p style="color:#888;font-size:12px">WhatsApp could not deliver this report to any office number, so it came by e-mail.</p></div>';
        $sent = 0;
        foreach (self::emails() as $to) {
            try {
                if (self::$mailer !== null) {
                    $ok = (bool) (self::$mailer)($to, $subject, $html, $text);
                } else {
                    require_once INCLUDE_PATH . '/notify.php';
                    $ok = Notify::email($to, $subject, $html, $text);
                }
                if ($ok) { $sent++; }
            } catch (Throwable $e) {
                Logger::warning('office report e-mail fallback failed', ['err' => $e->getMessage()], 'mail');
            }
        }
        return $sent;
    }

    /** numbers() + counter_day_report_numbers, one per person. @return list<string> */
    public static function counterDayNumbers(): array
    {
        $out  = self::numbers();
        $seen = [];
        foreach ($out as $n) { $seen[normalisePhone($n)] = true; }
        foreach (preg_split('/[\s,;]+/', Settings::getString('counter_day_report_numbers', '')) ?: [] as $n) {
            $d = preg_replace('/\D/', '', (string) $n) ?? '';
            if (!preg_match('/^(91[6-9]\d{9}|9779[6-8]\d{8})$/', $d)) {
                if ($d !== '') { Logger::warning('counter_day_report_numbers: skipped a number without country code', ['n' => substr($d, -4)], 'report'); }
                continue;   // a bare 98xxxxxxxx would become +91 - only full 91/977 numbers
            }
            $k = normalisePhone($d);
            if ($k === '' || isset($seen[$k])) { continue; }
            $seen[$k] = true;
            $out[] = $d;
        }
        return $out;
    }

    /**
     * The counter-wise report for $day. Line 1 = the grand total (it survives a
     * ~600-character template cut); the whole text stays under 4096.
     *
     * @return array{text:string, figures:array<string,mixed>}
     */
    public static function counterDay(string $day = ''): array
    {
        require_once INCLUDE_PATH . '/counterbook.php';
        require_once INCLUDE_PATH . '/countershift.php';
        require_once INCLUDE_PATH . '/chalanipng.php';
        require_once INCLUDE_PATH . '/driverlist.php';
        $day   = Security::isValidDate($day) ? $day : todayISO();
        $book  = CounterBook::day($day);
        $tot   = $book['totals'];
        $closes = [];
        foreach (CounterShift::dayCloses($day) as $c) { $closes[(string) $c['desk_key']] = $c; }

        /* per bus: the chalani's own passengers and money (ChalaniPng::departureMoney) */
        $buses = [];
        $boardDue = 0.0; $boardDueN = 0; $noDriver = [];
        foreach (Database::fetchAll(
            "SELECT s.id, COALESCE(s.dep_time_override, r.dep_time) AS dep_time, r.from_city, r.to_city, bu.bus_number
               FROM schedules s JOIN routes r ON r.id = s.route_id
               LEFT JOIN buses bu ON bu.id = COALESCE(s.bus_id, r.bus_id)
              WHERE s.travel_date = :d AND r.is_active = 1 AND s.status <> 'cancelled'
              ORDER BY dep_time, s.slot", ['d' => $day]) as $s) {
            $t = ChalaniPng::departureMoney((int) $s['id'])['totals'];
            if ((int) $t['passengers'] === 0) { continue; }
            $crew = DriverList::crewFor((int) $s['id']);
            if ($crew['phone'] === '') { $noDriver[] = substr((string) $s['dep_time'], 0, 5); }
            $boardDue += (float) $t['boardDue']; $boardDueN += (int) $t['boardDueN'];
            $buses[] = ['route' => trim((string) $s['from_city']) . '→' . trim((string) $s['to_city']), 'time' => substr((string) $s['dep_time'], 0, 5),
                'plate' => trim((string) ($s['bus_number'] ?? '')), 'pax' => (int) $t['passengers'], 'cash' => (float) $t['cash'],
                'online' => (float) $t['online'], 'boardDue' => (float) $t['boardDue'], 'boardDueN' => (int) $t['boardDueN'],
                'driver' => $crew['phone'] !== ''];
        }

        $lines   = [];
        $lines[] = '📊 ' . date('j M', (int) strtotime($day)) . ' जम्मा: ' . (int) $tot['tickets'] . ' टिकट · ' . (int) $tot['pax'] . ' यात्रु · नगद ' . inr((float) $tot['cash'])
            . ' · अनलाइन ' . inr((float) $tot['online']) . ' · कमिसन ' . inr((float) $tot['commission']) . ' · नेट ' . inr((float) $tot['net'])
            . ' · बोर्डिङमा बाँकी ' . inr($boardDue) . ' (' . $boardDueN . ')'
            . ((float) $tot['pending'] > 0 ? ' · आज बेचेको बाँकी ' . inr((float) $tot['pending']) : '')
            . ((float) $tot['takenNpr'] > 0 ? ' · NPR ' . number_format((float) $tot['takenNpr']) : '');
        $lines[] = '🧾 काउन्टर हिसाब · Counter-wise (' . $day . ')';
        $notClosed = [];
        foreach ($book['rows'] as $key => $r) {
            $key = (string) $key;
            $isNp = ($r['currency'] ?? 'INR') === 'NPR';
            /* an NPR desk: the drawer in NPR (cash + cod, payment by payment - the
               same figure its day close is checked against) */
            $drawerNpr = $isNp ? CounterShift::dayDrawer($r) : 0.0;
            $nprTxt = $isNp && $drawerNpr > 0 ? ' (' . CounterDesk::format($drawerNpr, 'NPR') . ')' : '';
            $line = ' • ' . (string) $r['label'] . ($r['kind'] === 'desk' ? ' (' . $key . ')' : '') . ': टिकट ' . (int) $r['tickets'] . ' · यात्रु ' . (int) $r['pax']
                . ' · नगद ' . inr((float) $r['cash']) . $nprTxt . ' · अनलाइन ' . inr((float) $r['online'])
                . ' · जम्मा ' . inr((float) $r['net']) . ((float) $r['pending'] > 0 ? ' · बाँकी ' . inr((float) $r['pending']) : '');
            if ($key !== '') {
                $c = $closes[$key] ?? null;
                if ($c !== null) {
                    $v = (float) $c['variance'];
                    $line .= ' · ✅ बुझायो ' . CounterDesk::format((float) $c['counted_cash'], (string) $c['currency'])
                        . (abs($v) > 0.004 ? ' फरक ' . ($v > 0 ? '+' : '−') . CounterDesk::format(abs($v), (string) $c['currency']) : '');
                } elseif ((float) $r['cash'] > 0) {
                    $line .= ' · ⏳ बाँकी';
                    $notClosed[] = (string) $r['label'];
                }
            }
            $lines[] = $line;
        }
        if ($book['rows'] === []) {
            $lines[] = ' • आज बिक्री छैन';
        }
        if ($buses !== []) {
            $lines[] = '🚍 बस · चलानी यात्रु';
            foreach ($buses as $b) {
                $lines[] = ' • ' . $b['route'] . ' ' . $b['time'] . ($b['plate'] !== '' ? ' ' . $b['plate'] : '') . ': ' . $b['pax'] . ' यात्रु · नगद ' . inr($b['cash'])
                    . ' · अनलाइन ' . inr($b['online']) . ($b['boardDueN'] > 0 ? ' · बाँकी ' . inr($b['boardDue']) . ' (' . $b['boardDueN'] . ')' : '')
                    . ($b['driver'] ? '' : ' · ⚠️ चालक नम्बर छैन · driver number missing');
            }
        }
        if ($notClosed !== []) {
            $lines[] = '⏳ हिसाब नबुझाएका: ' . implode(', ', $notClosed);
        }
        $lines[] = '🔗 ' . appUrl('admin/counters.php?from=' . $day . '&to=' . $day);
        $lines[] = '🧾 मेमो · Memo: ' . appUrl('admin/day-memo.php?day=' . $day);
        $lines[] = 'EN: ' . (int) $tot['tickets'] . ' tickets · ' . (int) $tot['pax'] . ' pax · cash ' . inr((float) $tot['cash']) . ' · online ' . inr((float) $tot['online'])
            . ' · net ' . inr((float) $tot['net']) . ' · to collect ' . inr($boardDue) . '.';

        $text = implode("\n", $lines);
        while (mb_strlen($text) > 4000 && count($lines) > 4) {
            array_splice($lines, -3, 1);   // drop detail lines from the end, keep the total, link and EN line
            $text = implode("\n", $lines);
        }
        return ['text' => $text, 'figures' => ['book' => $book, 'buses' => $buses, 'closes' => $closes, 'notClosed' => $notClosed,
            'noDriver' => $noDriver, 'boardDue' => $boardDue, 'boardDueN' => $boardDueN]];
    }

    /**
     * The approved shg_daily_collection template for the whole day (purpose
     * 'counter_day'), so the report can still go outside the 24-hour window;
     * null while Meta has not approved it (then the generic office alert / the
     * e-mail fallback carry it). Total = cash + online taken, as the template's
     * own example adds them; every figure is the book's, nothing invented.
     *
     * @param array{text:string, figures:array<string,mixed>} $rep
     * @return array<string,mixed>|null
     */
    public static function counterDayTemplate(string $day, array $rep): ?array
    {
        try {
            require_once INCLUDE_PATH . '/watplmeta.php';
            if (!class_exists('WaTplMeta') || !method_exists('WaTplMeta', 'forPurpose')) {
                return null;
            }
            $tot = (array) ($rep['figures']['book']['totals'] ?? []);
            $cash = (float) ($tot['cash'] ?? 0);
            $online = (float) ($tot['online'] ?? 0);
            return WaTplMeta::forPurpose('counter_day', [
                'date' => date('j M Y', (int) strtotime($day)),
                'counter' => 'सबै काउन्टर · All counters',
                'tickets' => (string) (int) ($tot['tickets'] ?? 0),
                'passengers' => (string) (int) ($tot['pax'] ?? 0),
                'cash' => inr($cash), 'online' => inr($online), 'total' => inr($cash + $online),
            ]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Build once and send to every counter-report number. $dry sends nothing.
     * @return array{slot:string, day:string, numbers:int, sent:int, failed:int, dry:bool, email?:int, text?:string}
     */
    public static function sendCounterDay(string $slot, bool $dry = false, string $day = ''): array
    {
        $day  = Security::isValidDate($day) ? $day : (Security::isValidDate(substr($slot, 0, 10)) ? substr($slot, 0, 10) : todayISO());
        $rep  = self::counterDay($day);
        $nums = self::counterDayNumbers();
        $out  = ['slot' => $slot, 'day' => $day, 'numbers' => count($nums), 'sent' => 0, 'failed' => 0, 'dry' => $dry];
        if ($dry) {
            $out['text'] = $rep['text'];
            return $out;
        }
        $tpl = self::counterDayTemplate($day, $rep);
        $out['template'] = $tpl !== null ? (string) $tpl['name'] : '';
        foreach ($nums as $n) {
            self::deliver($n, $rep['text'], null, 'counter_day', $tpl) ? $out['sent']++ : $out['failed']++;
        }
        $out['email'] = self::emailFallback($out, 'S Hari counter-wise report · ' . $day, $rep['text']);
        return $out;
    }
}
