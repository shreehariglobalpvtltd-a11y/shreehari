<?php
/**
 * =====================================================================
 *  MkAutopilot — the AI that does the WhatsApp marketing by itself
 *  (6 Oct 2026).
 *
 *  Owner (6 Oct, Nepali): "WhatsApp full advance — AI afai sikne bujhne,
 *  message automatic jaos, AI le marketing gari rakhos."
 *
 *  Once a day (cron/campaigns.php → tick(), after mk_auto_hour) it:
 *
 *    1. works out how many marketing messages the money allows TODAY —
 *       the AiSensy wallet minus a reserve that keeps the ticket
 *       templates paid (a week of utility spend, never less than
 *       mk_auto_reserve), minus what is already queued, divided by the
 *       Meta marketing rate; capped by mk_auto_daily and the number's
 *       24 h tier limit. Meta bills every marketing template (India
 *       ≈ ₹0.88, Nepal ≈ ₹6.45) — the AiSensy subscription does NOT
 *       cover it, so this guard is what keeps tickets flowing;
 *    2. picks people by moment: festival coming (go home), festival
 *       over (come back), return trip due (20–60 days after a trip),
 *       not seen for 60+ days, asked but never booked. Never a STOP /
 *       staff / placeholder number (CustomerDirectory::phones), never
 *       anyone with a trip already booked, never the same person twice
 *       inside mk_auto_gap_days;
 *    3. has the one AI (AiBrain) write the offer line for each group in
 *       Nepali — fed only the live fares, departures and the owner's
 *       own offer text, then CHECKED: a number that is not a real fare /
 *       date / phone, a "free"/"% off" the owner never offered, a link or
 *       a line break → rejected, the hand-written line is used instead;
 *    4. queues it as a normal Campaign (approved MARKETING template
 *       shg_offer) so the existing worker sends it — quiet hours, daily
 *       cap, delivered/read read back — and it shows on 📣 Campaigns;
 *    5. LEARNS: every send is matched to bookings made by that number in
 *       the next 7 days. Groups that bring bookings get more of tomorrow's
 *       budget, and the lines that sold are shown to the AI as examples.
 *
 *  mk_auto_mode: off | draft (builds the campaigns, a person presses
 *  Launch) | auto (sends). Nothing here touches a booking or a fare.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/campaigns.php';
require_once __DIR__ . '/customerdirectory.php';

final class MkAutopilot
{
    /** mk_campaigns.audience of an autopilot campaign: 'autopilot=<segment>'. */
    public const MARK = 'autopilot=';

    public const SEGMENTS = [
        'festival_go'   => 'चाडपर्वमा घर जाने',
        'festival_back' => 'चाडपर्वपछि गुजरात फर्कने',
        'return'        => 'फर्कने टिकटको बेला (२०–६० दिन)',
        'lapsed'        => '६० दिनभन्दा बढी नआएका',
        'leads'         => 'सोधेका तर बुक नगरेका',
    ];

    /** Defaults when the setting is empty — dates checked 6 Oct 2026 (Nepal calendar). */
    public const FESTIVALS = [
        ['date' => '2026-10-21', 'name' => 'दशैं (विजया दशमी)'],
        ['date' => '2026-11-08', 'name' => 'तिहार / दीपावली'],
        ['date' => '2026-11-15', 'name' => 'छठ'],
        ['date' => '2027-03-22', 'name' => 'होली'],
    ];

    private const SCOPE = 'mk_auto';

    private function __construct() {}

    /* =================================================================
     *  Settings
     * ================================================================= */

    public static function mode(): string
    {
        $m = Settings::getString('mk_auto_mode', 'off');
        return in_array($m, ['off', 'draft', 'auto'], true) ? $m : 'off';
    }

    public static function template(): string
    {
        $t = Settings::getString('mk_auto_template', 'shg_offer');
        return preg_match('/^[a-z0-9_]{1,80}$/', $t) === 1 ? $t : 'shg_offer';
    }

    /**
     * The template + header photo a campaign goes out on (6 Oct 2026): while an
     * office offer runs AND the photo template (mk_auto_image_template, default
     * shg_offer_image: same 3 variables + an IMAGE header) is APPROVED by Meta,
     * the offer pamphlet travels as the photo (/offer-card.php). Otherwise the
     * text template. Asked of AiSensy once per run.
     *
     * @return array{0:string, 1:?string}
     */
    public static function templateAndPhoto(): array
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        $memo = [self::template(), null];
        $img = Settings::getString('mk_auto_image_template', 'shg_offer_image');
        if ($img === '' || preg_match('/^[a-z0-9_]{1,80}$/', $img) !== 1) {
            return $memo;
        }
        try {
            require_once __DIR__ . '/offercard.php';
            $offer = OfferCard::offer();
            if ($offer === null) {
                return $memo;
            }
            require_once ROOT_PATH . '/whatsapp/aisensy.php';
            foreach (AiSensyAPI::templates() as $t) {
                if ($t['name'] === $img && $t['status'] === 'APPROVED' && !empty($t['image'])) {
                    $memo = [$img, appUrl('/offer-card.php?lang=' . (Settings::getString('mk_auto_lang', 'ne') === 'hi' ? 'hi' : 'ne') . '&o=' . strtolower((string) $offer['code']))];
                    break;
                }
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'marketing');
        }
        return $memo;
    }

    /** @return list<array{date:string,name:string}> sorted by date */
    public static function festivals(): array
    {
        $out = [];
        foreach (preg_split('/\R/', Settings::getString('mk_auto_festivals', '')) ?: [] as $line) {
            if (preg_match('/^\s*(\d{4}-\d{2}-\d{2})\s*[|,]\s*(.{2,60}?)\s*$/u', $line, $m) === 1 && strtotime($m[1]) !== false) {
                $out[] = ['date' => $m[1], 'name' => $m[2]];
            }
        }
        if ($out === []) {
            $out = self::FESTIVALS;
        }
        usort($out, static fn ($a, $b) => strcmp($a['date'], $b['date']));
        return $out;
    }

    /** The festival people travel home FOR (2–20 days ahead) and the one they come back FROM (2–14 days ago). */
    public static function festivalNow(?string $today = null): array
    {
        $t = strtotime($today ?? date('Y-m-d'));
        $go = $back = null;
        foreach (self::festivals() as $f) {
            $d = (int) round((strtotime($f['date']) - $t) / 86400);
            if ($go === null && $d >= 2 && $d <= 20) {
                $go = $f + ['days' => $d];
            }
            if ($d <= -2 && $d >= -14) {
                $back = $f + ['days' => -$d];
            }
        }
        return ['go' => $go, 'back' => $back];
    }

    /* =================================================================
     *  Money — what today's marketing may cost
     * ================================================================= */

    /** @return array<string,mixed> */
    public static function budget(): array
    {
        $b = ['wallet' => null, 'reserve' => 0.0, 'queued' => 0, 'queued_cost' => 0.0, 'rate' => 0.88, 'rate_np' => 6.45,
              'spare' => 0.0, 'by_money' => 0, 'daily' => max(0, Settings::getInt('mk_auto_daily', 25)), 'auto_today' => 0,
              'tier_limit' => 0, 'tier_used' => 0, 'by_tier' => 0, 'allowed' => 0, 'reason' => '', 'plan' => '', 'renewal' => '',
              'quality' => ''];
        $ledger = false;
        try {
            require_once __DIR__ . '/msgledger.php';
            $ledger = MsgLedger::ready();
        } catch (Throwable $e) {
            $ledger = false;
        }
        if ($ledger) {
            $b['rate'] = MsgLedger::rate('IN', 'marketing');
            $b['rate_np'] = MsgLedger::rate('NP', 'marketing');
            try {
                $p = MsgLedger::snapCredit();
            } catch (Throwable $e) {
                $p = [];
            }
            if (!isset($p['credit'])) {
                // The msg-ledger cron reads the wallet every 2 minutes; half an hour old is still a fair guide.
                $k = MsgLedger::kvJson('project');
                if (isset($k['credit']) && strtotime((string) ($k['at'] ?? '')) > time() - 1800) {
                    $p = $k;
                }
            }
            if (isset($p['credit']) && is_numeric($p['credit'])) {
                $b['wallet'] = (float) $p['credit'];
            }
            $b['tier_limit'] = (int) ($p['daily_limit'] ?? 0);
            $b['plan'] = (string) ($p['plan'] ?? '');
            $b['renewal'] = (string) ($p['renewal'] ?? '');
            $b['quality'] = (string) ($p['quality'] ?? '');
            try {
                // A week of what the ticket / notice templates really cost = what must stay in the wallet.
                $week = (float) (Database::fetch(
                    "SELECT COALESCE(SUM(cost),0) c FROM msg_ledger
                      WHERE provider = 'aisensy' AND category IN ('utility','authentication')
                        AND created_at > NOW() - INTERVAL 7 DAY"
                )['c'] ?? 0);
                $b['reserve'] = $week;
                $b['tier_used'] = (int) (Database::fetch(
                    "SELECT COUNT(DISTINCT to_number) n FROM msg_ledger
                      WHERE provider = 'aisensy' AND category IN ('utility','marketing','authentication')
                        AND status NOT IN ('failed','skipped') AND created_at > NOW() - INTERVAL 24 HOUR"
                )['n'] ?? 0);
            } catch (Throwable $e) {
                // the ledger is a help, not a requirement
            }
        }
        $b['reserve'] = round(max($b['reserve'], (float) Settings::getInt('mk_auto_reserve', 25)), 2);

        try {
            $q = Database::fetch(
                "SELECT COUNT(*) n, COALESCE(SUM(r.phone LIKE '977%'),0) np FROM mk_recipients r
                   JOIN mk_campaigns c ON c.id = r.campaign_id
                  WHERE r.state IN ('queued','sending') AND c.status IN ('scheduled','running') AND c.channel = 'wa_template'"
            ) ?? [];
            $b['queued'] = (int) ($q['n'] ?? 0);
            $b['queued_cost'] = round(((int) ($q['n'] ?? 0) - (int) ($q['np'] ?? 0)) * $b['rate'] + (int) ($q['np'] ?? 0) * $b['rate_np'], 2);
            $b['auto_today'] = (int) (Database::fetch(
                "SELECT COUNT(*) n FROM mk_recipients r JOIN mk_campaigns c ON c.id = r.campaign_id
                  WHERE c.audience LIKE 'autopilot=%' AND DATE(c.created_at) = CURDATE()"
            )['n'] ?? 0);
        } catch (Throwable $e) {
            // campaigns tables missing → nothing allowed below
        }

        if ($b['wallet'] === null) {
            $b['reason'] = 'AiSensy wallet could not be read — nothing is sent until it can.';
            return $b;
        }
        $b['spare'] = round($b['wallet'] - $b['reserve'] - $b['queued_cost'], 2);
        $b['by_money'] = $b['rate'] > 0 ? max(0, (int) floor($b['spare'] / $b['rate'])) : 0;
        // Keep 30 of the number's daily limit for tickets and notices.
        $b['by_tier'] = $b['tier_limit'] > 0 ? max(0, $b['tier_limit'] - $b['tier_used'] - 30) : $b['daily'];
        $b['allowed'] = max(0, min($b['daily'] - $b['auto_today'], $b['by_money'], $b['by_tier']));
        if ($b['allowed'] === 0) {
            $b['reason'] = match (true) {
                $b['daily'] === 0                    => 'Daily limit is 0.',
                $b['auto_today'] >= $b['daily']      => 'Today\'s AI messages are already queued.',
                $b['by_money'] === 0                 => 'Wallet ₹' . number_format($b['wallet'], 2) . ' is all needed for tickets (reserve ₹' . number_format($b['reserve'], 2) . '). Recharge the AiSensy wallet to market.',
                default                              => 'The number\'s 24 h limit is nearly used — tickets come first.',
            };
        }
        return $b;
    }

    /* =================================================================
     *  Who — today's groups
     * ================================================================= */

    /**
     * @return array<string, list<array{ph:string,intl:string,name:string,trips:int,last:string,dir:string}>>
     */
    public static function candidates(?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $t = strtotime($today);
        $fest = self::festivalNow($today);
        $out = array_fill_keys(array_keys(self::SEGMENTS), []);

        // Everyone marketing may reach (opt-outs, staff and placeholders already gone), leads included.
        $people = CustomerDirectory::phones(CustomerDirectory::filters(['status' => 'confirmed', 'leads' => '1']), true, 20000);
        if ($people === []) {
            return $out;
        }
        $nepalOk = Settings::getBool('mk_auto_nepal', false);

        // Last trip of every number: date + direction.
        $last = [];
        try {
            require_once __DIR__ . '/fare.php';
            $rows = Database::fetchAll(
                "SELECT RIGHT(REGEXP_REPLACE(b.contact_phone, '[^0-9]', ''), 10) ph, bl.travel_date, r.to_city
                   FROM bookings b
                   JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                   JOIN schedules s ON s.id = bl.schedule_id
                   JOIN routes r ON r.id = s.route_id
                  WHERE b.status IN ('confirmed','completed')
                  ORDER BY bl.travel_date"
            );
            foreach ($rows as $r) {
                $last['k' . $r['ph']] = ['date' => (string) $r['travel_date'], 'dir' => Fare::routeDir(['to_city' => (string) $r['to_city']])];
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'marketing');
        }

        // Anyone with a trip still ahead (or waiting for payment) is not marketed to.
        $ahead = [];
        try {
            foreach (Database::fetchAll(
                "SELECT DISTINCT RIGHT(REGEXP_REPLACE(b.contact_phone, '[^0-9]', ''), 10) ph
                   FROM bookings b JOIN booking_legs bl ON bl.booking_id = b.id
                  WHERE bl.travel_date >= :d AND b.status IN ('confirmed','pending')",
                ['d' => $today]
            ) as $r) {
                $ahead['k' . $r['ph']] = true;
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'marketing');
        }

        // Marketed recently (any campaign), or already waiting in a queue.
        $recent = [];
        $gap = max(1, Settings::getInt('mk_auto_gap_days', 10));
        try {
            foreach (Database::fetchAll(
                "SELECT DISTINCT RIGHT(phone, 10) ph FROM mk_recipients
                  WHERE state IN ('queued','sending')
                     OR (state IN ('sent','delivered','read') AND sent_at > NOW() - INTERVAL " . $gap . " DAY)"
            ) as $r) {
                $recent['k' . $r['ph']] = true;
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'marketing');
        }

        foreach ($people as $p) {
            $k = 'k' . $p['ph'];
            if (isset($ahead[$k]) || isset($recent[$k]) || $p['intl'] === '') {
                continue;
            }
            if (!$nepalOk && str_starts_with($p['intl'], '977')) {
                continue;
            }
            $l = $last[$k] ?? null;
            $row = ['ph' => $p['ph'], 'intl' => $p['intl'], 'name' => $p['name'], 'trips' => (int) $p['trips'],
                    'last' => $l['date'] ?? '', 'dir' => ''];
            $ago = $l !== null ? (int) floor(($t - strtotime($l['date'])) / 86400) : null;

            if ($l === null) {
                if ((int) $p['trips'] === 0) {
                    $out['leads'][] = $row;
                }
                continue;
            }
            // Just went home for the festival → bring them back to Gujarat.
            if ($fest['back'] !== null && $l['dir'] === 'toNepal' && $ago !== null && $ago >= 0 && $ago <= 75) {
                $out['festival_back'][] = ['dir' => 'toIndia'] + $row;
                continue;
            }
            // In India now (or long ago home) → take them home for the festival.
            if ($fest['go'] !== null && !($l['dir'] === 'toNepal' && $ago !== null && $ago < 30)) {
                $out['festival_go'][] = ['dir' => 'toNepal'] + $row;
                continue;
            }
            if ($ago !== null && $ago >= 20 && $ago <= 60) {
                // Went home with a festival coming: they stay for it and are asked back after it
                // (festival_back) — "come back to Gujarat" now would be the wrong message.
                if ($fest['go'] !== null && $l['dir'] === 'toNepal') {
                    continue;
                }
                $out['return'][] = ['dir' => $l['dir'] === 'toNepal' ? 'toIndia' : 'toNepal'] + $row;
                continue;
            }
            if ($ago !== null && $ago > 60) {
                $out['lapsed'][] = $row;
            }
        }
        foreach ($out as &$list) {
            usort($list, static fn ($a, $b) => [$b['trips'], $b['last']] <=> [$a['trips'], $a['last']]);
        }
        unset($list);
        return $out;
    }

    /* =================================================================
     *  Learning — what brought bookings
     * ================================================================= */

    /**
     * Per group: sent, reached, read, booked within 7 days, and a smoothed
     * score that tomorrow's budget split follows.
     *
     * @return array<string, array{sent:int,reached:int,read:int,booked:int,score:float}>
     */
    public static function scores(int $days = 90): array
    {
        $out = [];
        foreach (array_keys(self::SEGMENTS) as $s) {
            $out[$s] = ['sent' => 0, 'reached' => 0, 'read' => 0, 'booked' => 0, 'score' => 0.0];
        }
        try {
            $rows = Database::fetchAll(
                "SELECT SUBSTRING(c.audience, 11) seg, COUNT(*) sent,
                        SUM(r.state IN ('delivered','read')) reached, SUM(r.state = 'read') read_n,
                        SUM(EXISTS(SELECT 1 FROM bookings b
                                    WHERE RIGHT(REGEXP_REPLACE(b.contact_phone, '[^0-9]', ''), 10) = RIGHT(r.phone, 10)
                                      AND b.created_at BETWEEN r.sent_at AND r.sent_at + INTERVAL 7 DAY
                                      AND b.status NOT IN ('cancelled','rejected','expired'))) booked
                   FROM mk_recipients r JOIN mk_campaigns c ON c.id = r.campaign_id
                  WHERE c.audience LIKE 'autopilot=%' AND r.state IN ('sent','delivered','read')
                    AND r.sent_at > NOW() - INTERVAL " . max(1, $days) . " DAY
                  GROUP BY seg"
            );
            foreach ($rows as $r) {
                $s = (string) $r['seg'];
                if (!isset($out[$s])) {
                    continue;
                }
                $out[$s] = ['sent' => (int) $r['sent'], 'reached' => (int) $r['reached'], 'read' => (int) $r['read_n'],
                            'booked' => (int) $r['booked'], 'score' => 0.0];
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'marketing');
        }
        // Beta-smoothed booking rate: an untried group starts at ~4 %, so it still gets tried.
        foreach ($out as &$v) {
            $v['score'] = round(($v['booked'] + 1) / ($v['sent'] + 25), 4);
        }
        unset($v);
        return $out;
    }

    /**
     * The lines that sold best (and worst), for the AI to learn from.
     *
     * @return array{good:list<string>, bad:list<string>}
     */
    public static function lessons(): array
    {
        $good = $bad = [];
        try {
            $rows = Database::fetchAll(
                "SELECT c.template_params, COUNT(*) sent,
                        SUM(EXISTS(SELECT 1 FROM bookings b
                                    WHERE RIGHT(REGEXP_REPLACE(b.contact_phone, '[^0-9]', ''), 10) = RIGHT(r.phone, 10)
                                      AND b.created_at BETWEEN r.sent_at AND r.sent_at + INTERVAL 7 DAY
                                      AND b.status NOT IN ('cancelled','rejected','expired'))) booked
                   FROM mk_campaigns c JOIN mk_recipients r ON r.campaign_id = c.id AND r.state IN ('sent','delivered','read')
                  WHERE c.audience LIKE 'autopilot=%' AND c.created_at > NOW() - INTERVAL 120 DAY
                  GROUP BY c.id HAVING sent >= 8
                  ORDER BY booked / sent DESC, sent DESC"
            );
            foreach ($rows as $r) {
                $line = (string) ((json_decode((string) $r['template_params'], true) ?: [])[1] ?? '');
                if ($line === '') {
                    continue;
                }
                $tag = $line . ' (' . (int) $r['booked'] . ' bookings from ' . (int) $r['sent'] . ')';
                if ((int) $r['booked'] > 0 && count($good) < 3) {
                    $good[] = $tag;
                } elseif ((int) $r['booked'] === 0) {
                    $bad[] = $tag;
                }
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'marketing');
        }
        return ['good' => $good, 'bad' => array_slice(array_reverse($bad), 0, 2)];
    }

    /**
     * Split today's allowance: festival groups first (it is the season),
     * then the rest in proportion to what each has sold.
     *
     * @param array<string,int> $have candidates per segment
     * @return array<string,int>
     */
    public static function allocate(int $allowed, array $have, array $scores): array
    {
        $give = array_fill_keys(array_keys(self::SEGMENTS), 0);
        $left = $allowed;
        foreach (['festival_go', 'festival_back'] as $s) {
            $n = min($left, (int) ($have[$s] ?? 0));
            $give[$s] = $n;
            $left -= $n;
        }
        $rest = array_filter(['return', 'lapsed', 'leads'], static fn ($s) => (int) ($have[$s] ?? 0) > 0);
        while ($left > 0 && $rest !== []) {
            $sum = array_sum(array_map(static fn ($s) => (float) $scores[$s]['score'], $rest)) ?: 1.0;
            $round = $left;
            foreach ($rest as $s) {
                $want = max(1, (int) round($round * ((float) $scores[$s]['score'] / $sum)));
                $n = min($want, (int) $have[$s] - $give[$s], $left);
                $give[$s] += $n;
                $left -= $n;
            }
            $rest = array_values(array_filter($rest, static fn ($s) => $give[$s] < (int) $have[$s]));
            if ($left === $round) {
                break;
            }
        }
        return $give;
    }

    /* =================================================================
     *  What — the offer line
     * ================================================================= */

    /** @return array<string,mixed> the facts a line may use */
    public static function facts(array $fest = []): array
    {
        require_once __DIR__ . '/fare.php';
        $f = Fare::dirFares();
        $deps = ['toNepal' => [], 'toIndia' => []];
        try {
            foreach (Database::fetchAll('SELECT from_city, to_city, dep_time FROM routes WHERE is_active = 1 ORDER BY sort_order, id') as $r) {
                $deps[Fare::routeDir($r)][] = $r['from_city'] . ' ' . substr((string) $r['dep_time'], 0, 5) . ' → ' . $r['to_city'];
            }
        } catch (Throwable $e) {
            // facts without departures are still facts
        }
        $daily = 0;
        try {
            $daily = (int) (Database::fetch(
                "SELECT COUNT(DISTINCT travel_date) n FROM schedules
                  WHERE travel_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 6 DAY AND status = 'scheduled' AND is_blocked = 0"
            )['n'] ?? 0);
        } catch (Throwable $e) {
            $daily = 0;
        }
        return [
            'fare_go'    => (int) $f['toNepal'],
            'fare_back'  => (int) $f['toIndia'],
            'deps'       => $deps,
            'daily'      => $daily >= 7,
            'phone'      => Settings::officePhone(),
            'offer'      => trim(Settings::getString('mk_auto_offer', '')),
            'festival'   => $fest,
        ];
    }

    /** The hand-written line for a group (used when the AI is off or wrote something unsafe). */
    public static function fallback(string $seg, array $facts, string $dir = ''): string
    {
        $go = number_format($facts['fare_go']);
        $back = number_format($facts['fare_back']);
        $fn = (string) ($facts['festival']['name'] ?? '');
        $offer = $facts['offer'] !== '' ? ' ' . $facts['offer'] : '';
        return match ($seg) {
            'festival_go'   => $fn . ' नजिकिँदैछ 🪔 घर जाने बस Gujarat → Rupaidiha, भाडा ₹' . $go . '। चाडको बेला भीड हुन्छ, सिट अहिले नै पक्का गर्नुहोस्।' . $offer,
            'festival_back' => $fn . ' को शुभकामना 🙏 गुजरात फर्कने बस Rupaidiha → Gujarat, भाडा ₹' . $back . '। आफ्नो सिट अहिले नै छान्नुहोस्।' . $offer,
            'return'        => ($dir === 'toIndia'
                                ? 'गुजरात फर्कने बेला भयो? Rupaidiha → Gujarat बस, भाडा ₹' . $back . '।'
                                : 'घर जाने बेला भयो? Gujarat → Rupaidiha बस, भाडा ₹' . $go . '।') . ' सिट अनलाइन छान्नुहोस्।' . $offer,
            'lapsed'        => 'धेरै दिन भयो 🙏 Gujarat ⇄ Rupaidiha बस सेवा जारी छ — घर जाने ₹' . $go . ', फर्कने ₹' . $back . '। सिट अनलाइन छान्नुहोस्।' . $offer,
            default         => 'तपाईंले बस टिकटबारे सोध्नुभएको थियो 🙏 Gujarat ⇄ Rupaidiha — घर जाने ₹' . $go . ', फर्कने ₹' . $back . '। सिट अनलाइन छान्नुहोस्।' . $offer,
        };
    }

    /**
     * Is this AI line safe to send? Returns the cleaned line, or '' with $why set.
     */
    public static function check(string $line, array $facts, string &$why = ''): string
    {
        $l = trim(str_replace(['**', '__', '```'], '', $line));
        $l = trim($l, " \t\"'`“”‘’");
        $l = preg_replace('/\s*\R+\s*/u', ' ', $l) ?? $l;          // Meta refuses a line break inside a variable
        $l = preg_replace('/[ \t]{2,}/u', ' ', $l) ?? $l;
        $len = mb_strlen($l);
        if ($len < 30 || $len > 320) {
            $why = 'length ' . $len;
            return '';
        }
        if (preg_match('~https?://|www\.|\.(com|in|net|org)\b|wa\.me~iu', $l) === 1) {
            $why = 'has a link';
            return '';
        }
        $offerText = mb_strtolower((string) $facts['offer']);
        // A promise the owner never made. छुट/छूट = discount, but छुट्छ / छुट्ने = "departs" (a halant or vowel sign follows).
        $promises = [
            '/%/u', '/\bfree\b/iu', '/फ्री|मुफ्त|निःशुल्क|निशुल्क/u', '/छ[ुू]ट(?![\x{093E}-\x{094D}])/u',
            '/discount|cashback|कैशबैक|guarantee|ग्यारेन्टी|गारंटी/iu', '/\boff\b/iu',
        ];
        foreach ($promises as $rx) {
            if (preg_match($rx, $l, $hit) === 1 && ($offerText === '' || preg_match($rx, $offerText) !== 1)) {
                $why = 'promises "' . $hit[0] . '"';
                return '';
            }
        }
        // Every number must be a real one: a fare, a festival date, a departure time, the office phone.
        $ok = [(string) $facts['fare_go'], (string) $facts['fare_back'], date('Y'), (string) ((int) date('Y') + 1)];
        $fd = (string) ($facts['festival']['date'] ?? '');
        if ($fd !== '') {
            $ok[] = (string) (int) substr($fd, 8, 2);
            $ok[] = (string) (int) substr($fd, 5, 2);
        }
        foreach ($facts['deps'] as $list) {
            foreach ($list as $d) {
                if (preg_match_all('/\d+/', $d, $m)) {
                    foreach ($m[0] as $n) {
                        $ok[] = (string) (int) $n;
                        $ok[] = $n;
                        if ((int) $n > 12) {
                            $ok[] = (string) ((int) $n - 12);
                        }
                    }
                }
            }
        }
        $phone = preg_replace('/\D/', '', (string) $facts['phone']) ?? '';
        if ($phone !== '') {
            $ok[] = $phone;
            $ok[] = substr($phone, -10);
        }
        if (preg_match_all('/\d+/', $offerText, $m)) {
            foreach ($m[0] as $n) {
                $ok[] = $n;
            }
        }
        $digits = strtr($l, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
        $digits = preg_replace('/(?<=\d)[,\s](?=\d{2,3}\b)/u', '', $digits) ?? $digits;   // ₹2,000 / +91 98...
        if (preg_match_all('/\d+/', $digits, $m)) {
            foreach ($m[0] as $n) {
                if (!in_array($n, $ok, true) && !in_array(ltrim($n, '0'), $ok, true)) {
                    $why = 'number ' . $n . ' is not a real fare/date';
                    return '';
                }
            }
        }
        return $l;
    }

    /**
     * The offer line for one group: the AI's, checked; else the hand-written one.
     *
     * @return array{text:string, by:string, why:string}
     */
    public static function copy(string $seg, array $facts, string $dir = ''): array
    {
        $fallback = self::fallback($seg, $facts, $dir);
        if (!Settings::getBool('mk_auto_ai_copy', true)) {
            return ['text' => $fallback, 'by' => 'fixed', 'why' => 'AI writing switched off'];
        }
        try {
            require_once __DIR__ . '/aibrain.php';
            if (!AiBrain::configured()) {
                return ['text' => $fallback, 'by' => 'fixed', 'why' => 'no AI key'];
            }
        } catch (Throwable $e) {
            return ['text' => $fallback, 'by' => 'fixed', 'why' => 'AI unavailable'];
        }
        $lang = Settings::getString('mk_auto_lang', 'ne') === 'hi' ? 'Hindi (Devanagari)' : 'Nepali (Devanagari)';
        $les = self::lessons();
        $goal = match ($seg) {
            'festival_go'   => 'Festival "' . $facts['festival']['name'] . '" is on ' . $facts['festival']['date'] . ' (' . $facts['festival']['days'] . ' days away). Invite them to book the bus HOME (Gujarat → Rupaidiha, Nepal border), fare ₹' . $facts['fare_go'] . '.',
            'festival_back' => 'Festival "' . $facts['festival']['name'] . '" is over. Invite them to book the bus BACK to Gujarat (Rupaidiha → Gujarat), fare ₹' . $facts['fare_back'] . '.',
            'return'        => $dir === 'toIndia'
                                ? 'They went home 20–60 days ago. Invite them to book the return bus to Gujarat (Rupaidiha → Gujarat), fare ₹' . $facts['fare_back'] . '.'
                                : 'They came to Gujarat 20–60 days ago. Invite them to book the bus home (Gujarat → Rupaidiha), fare ₹' . $facts['fare_go'] . '.',
            'lapsed'        => 'They have not travelled with us for over 60 days. Warm "we miss you", the service runs Gujarat ⇄ Rupaidiha: home ₹' . $facts['fare_go'] . ', back ₹' . $facts['fare_back'] . '.',
            default         => 'They asked us about a ticket but never booked. Friendly reminder: Gujarat ⇄ Rupaidiha, home ₹' . $facts['fare_go'] . ', back ₹' . $facts['fare_back'] . '.',
        };
        $sys = "You write ONE short WhatsApp marketing line for S Hari Global Pvt Ltd, a bus company carrying Nepali workers between Gujarat (India) and Rupaidiha (Nepal border).\n"
             . "It goes into this approved template: \"Namaste <name>,\\n<YOUR LINE>\\n\\nBook your seat: <link>\\n\\nS Hari Global Pvt Ltd. Reply STOP to stop these messages.\"\n"
             . "RULES:\n- Write in " . $lang . ", warm and simple, like a trusted travel agent from their village. 1 or 2 emoji at most.\n"
             . "- 90 to 220 characters. ONE line: no line breaks, no links, no markdown, no greeting (the template already says Namaste and the name).\n"
             . "- Use ONLY these facts. Never invent a discount, free item, percentage, guarantee, seat count, date or time.\n"
             . "- Every number you write must be one of the facts below.\n"
             . "FACTS:\n- Fares: going home (Gujarat → Rupaidiha) ₹" . $facts['fare_go'] . ", coming back (Rupaidiha → Gujarat) ₹" . $facts['fare_back'] . "\n"
             . '- Departures: ' . implode('; ', array_merge($facts['deps']['toNepal'], $facts['deps']['toIndia'])) . "\n"
             . ($facts['daily'] ? "- A bus runs every day.\n" : '')
             . ($facts['offer'] !== '' ? '- The owner\'s current offer (you may use exactly this): ' . $facts['offer'] . "\n" : "- There is NO discount or offer right now.\n")
             . ($les['good'] !== [] ? "LINES THAT BROUGHT BOOKINGS (learn the style, do not copy):\n- " . implode("\n- ", $les['good']) . "\n" : '')
             . ($les['bad'] !== [] ? "LINES THAT SOLD NOTHING (avoid this style):\n- " . implode("\n- ", $les['bad']) . "\n" : '')
             . 'Reply with the line only.';
        $why = '';
        for ($try = 0; $try < 2; $try++) {
            $msg = 'Audience: ' . self::SEGMENTS[$seg] . '. Goal: ' . $goal . ($try > 0 ? ' (Your last line was rejected: ' . $why . '. Follow the rules exactly.)' : '');
            $r = null;
            try {
                $r = AiBrain::text($sys, [['role' => 'user', 'content' => $msg]], 400, 'marketing');
            } catch (Throwable $e) {
                $r = null;
            }
            if ($r === null) {
                $why = 'AI did not answer';
                break;
            }
            $line = self::check((string) $r['text'], $facts, $why);
            if ($line !== '') {
                return ['text' => $line, 'by' => (string) $r['provider'], 'why' => ''];
            }
        }
        return ['text' => $fallback, 'by' => 'fixed', 'why' => $why];
    }

    /* =================================================================
     *  The day's run
     * ================================================================= */

    /**
     * Plan today and (unless $dry) queue it.
     *
     * @return array<string,mixed>
     */
    public static function run(bool $dry = false, int $by = 0, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $mode = self::mode();
        $res = ['at' => date('Y-m-d H:i:s'), 'mode' => $mode, 'dry' => $dry, 'budget' => [], 'groups' => [], 'campaigns' => [], 'note' => ''];
        if (!Campaigns::ready()) {
            $res['note'] = 'Campaign tables missing.';
            return $res;
        }
        $budget = self::budget();
        $res['budget'] = $budget;
        $fest = self::festivalNow($today);
        $cands = self::candidates($today);
        $scores = self::scores();
        $have = array_map('count', $cands);
        $give = self::allocate((int) $budget['allowed'], $have, $scores);

        foreach (self::SEGMENTS as $seg => $label) {
            $res['groups'][$seg] = ['label' => $label, 'people' => $have[$seg], 'send' => $give[$seg], 'score' => $scores[$seg]];
        }
        if ((int) $budget['allowed'] === 0) {
            $res['note'] = (string) $budget['reason'];
        } elseif (array_sum($give) === 0) {
            $res['note'] = 'Nobody to market to today (everyone is booked, opted out or was messaged in the last ' . Settings::getInt('mk_auto_gap_days', 10) . ' days).';
        }

        foreach ($give as $seg => $n) {
            if ($n <= 0) {
                continue;
            }
            $f = match ($seg) {
                'festival_go'   => $fest['go'] ?? [],
                'festival_back' => $fest['back'] ?? [],
                default         => [],
            };
            $facts = self::facts($f);
            $pick = array_slice($cands[$seg], 0, $n);
            // 'return' carries a direction per person: one campaign (and one line) per direction.
            $parts = $seg === 'return'
                ? array_filter(['toNepal' => array_values(array_filter($pick, static fn ($p) => $p['dir'] === 'toNepal')),
                                'toIndia' => array_values(array_filter($pick, static fn ($p) => $p['dir'] === 'toIndia'))])
                : ['' => $pick];
            foreach ($parts as $dir => $list) {
                $copy = $dry && !Settings::getBool('mk_auto_preview_ai', true) ? ['text' => self::fallback($seg, $facts, (string) $dir), 'by' => 'fixed', 'why' => 'preview'] : self::copy($seg, $facts, (string) $dir);
                $c = ['segment' => $seg, 'dir' => $dir, 'people' => count($list), 'text' => $copy['text'], 'by' => $copy['by'], 'why' => $copy['why'], 'id' => 0,
                      'photo' => self::templateAndPhoto()[1],
                      'sample' => array_map(static fn ($p) => ['name' => $p['name'], 'ph' => '•••' . substr($p['ph'], -4)], array_slice($list, 0, 3))];
                if (!$dry && $mode !== 'off') {
                    $c['id'] = self::queue($seg, (string) $dir, $copy['text'], $list, $mode === 'auto', $by);
                }
                $res['campaigns'][] = $c;
            }
        }
        if (!$dry) {
            self::remember($res);
        }
        return $res;
    }

    /** One campaign row + its recipients. Returns the campaign id. */
    private static function queue(string $seg, string $dir, string $line, array $list, bool $send, int $by): int
    {
        $name = '🤖 AI · ' . self::SEGMENTS[$seg] . ($dir !== '' ? ' · ' . ($dir === 'toNepal' ? 'घर जाने' : 'फर्कने') : '') . ' · ' . date('j M');
        $link = trim(Settings::getString('mk_auto_link', ''));
        [$tpl, $photo] = self::templateAndPhoto();
        $id = (int) Database::insert('mk_campaigns', [
            'name'            => mb_substr($name, 0, 120),
            'channel'         => 'wa_template',
            'template_name'   => $tpl,
            'template_params' => json_encode(['{first}', $line, '{link}'], JSON_UNESCAPED_UNICODE),
            'header_image'    => $photo,
            'message'         => $line,
            'link_url'        => preg_match('#^https?://\S+$#i', $link) === 1 ? mb_substr($link, 0, 500) : null,
            'audience'        => self::MARK . $seg,
            'status'          => $send ? 'scheduled' : 'draft',
            'start_at'        => $send ? date('Y-m-d H:i:s') : null,
            'rate_per_min'    => 10,
            'created_by'      => $by > 0 ? $by : null,
            'launched_by'     => $send && $by > 0 ? $by : null,
            'launched_at'     => $send ? date('Y-m-d H:i:s') : null,
        ]);
        foreach (array_chunk($list, 200) as $chunk) {
            $vals = [];
            $p = [];
            foreach ($chunk as $i => $r) {
                $vals[] = "(:c$i, :p$i, :n$i)";
                $p["c$i"] = $id;
                $p["p$i"] = $r['intl'];
                $p["n$i"] = mb_substr((string) $r['name'], 0, 120);
            }
            Database::run('INSERT IGNORE INTO mk_recipients (campaign_id, phone, name) VALUES ' . implode(',', $vals), $p);
        }
        Campaigns::recount($id);
        Logger::audit('marketing.autopilot_queue', 'mk_campaign', (string) $id, null,
            ['segment' => $seg, 'dir' => $dir, 'recipients' => count($list), 'send' => $send], $by > 0 ? 'by admin #' . $by : 'by the autopilot');
        return $id;
    }

    /**
     * cron/campaigns.php, every minute: once a day after mk_auto_hour,
     * plus an hourly sweep that turns provider opt-out failures into STOP.
     *
     * @return array<string,mixed>
     */
    public static function tick(): array
    {
        if (self::mode() === 'off' || !Campaigns::ready()) {
            return ['autopilot' => 'off'];
        }
        $out = [];
        if (self::kv('sweep') !== date('Y-m-d H')) {
            self::kvSet('sweep', date('Y-m-d H'));
            $out['optout'] = self::sweepOptOuts();
        }
        if ((int) date('G') < max(0, min(20, Settings::getInt('mk_auto_hour', 10))) || self::kv('day') === date('Y-m-d')) {
            return $out + ['autopilot' => 'waiting'];
        }
        $lock = (int) (Database::fetch("SELECT GET_LOCK('shg_mk_autopilot', 0) AS l")['l'] ?? 0);
        if ($lock !== 1) {
            return $out + ['autopilot' => 'busy'];
        }
        try {
            if (self::kv('day') === date('Y-m-d')) {
                return $out + ['autopilot' => 'done today'];
            }
            self::kvSet('day', date('Y-m-d'));      // first, so a crash never repeats the day's sends
            $r = self::run(false, 0);
            return $out + ['autopilot' => 'ran', 'queued' => array_sum(array_column($r['campaigns'], 'people')), 'note' => $r['note']];
        } catch (Throwable $e) {
            Logger::exception($e, 'marketing');
            return $out + ['autopilot' => 'error'];
        } finally {
            Database::fetch("SELECT RELEASE_LOCK('shg_mk_autopilot')");
        }
    }

    /**
     * A STOP sent to the AiSensy number never reaches us (no webhook), but
     * the next template to that person fails with an opt-out / "user
     * stopped marketing" error. Those numbers are blocked for marketing.
     */
    public static function sweepOptOuts(): int
    {
        $n = 0;
        try {
            $rows = Database::fetchAll(
                "SELECT DISTINCT r.phone FROM mk_recipients r
                  WHERE r.state = 'failed' AND r.updated_at > NOW() - INTERVAL 3 DAY
                    AND (r.error LIKE '%opt%out%' OR r.error LIKE '%opted%' OR r.error LIKE '%unsubscrib%'
                         OR r.error LIKE '%131050%' OR r.error LIKE '%stopped%marketing%')"
            );
            foreach ($rows as $r) {
                if (Campaigns::optOut((string) $r['phone'], 'opted out (provider refused marketing)', 0)) {
                    $n++;
                }
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'marketing');
        }
        return $n;
    }

    /* =================================================================
     *  Memory
     * ================================================================= */

    /** @return list<array<string,mixed>> newest first */
    public static function history(): array
    {
        $j = json_decode(self::kv('log'), true);
        return is_array($j) ? $j : [];
    }

    private static function remember(array $res): void
    {
        $h = self::history();
        array_unshift($h, [
            'at' => $res['at'], 'mode' => $res['mode'], 'note' => $res['note'],
            'allowed' => (int) ($res['budget']['allowed'] ?? 0), 'wallet' => $res['budget']['wallet'] ?? null,
            'campaigns' => array_map(static fn ($c) => ['id' => $c['id'], 'segment' => $c['segment'], 'people' => $c['people'],
                                                       'by' => $c['by'], 'text' => mb_substr($c['text'], 0, 200)], $res['campaigns']),
        ]);
        self::kvSet('log', json_encode(array_slice($h, 0, 30), JSON_UNESCAPED_UNICODE) ?: '[]');
    }

    public static function kv(string $key): string
    {
        try {
            return (string) (Database::fetch('SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => self::SCOPE, 'k' => $key])['kvalue'] ?? '');
        } catch (Throwable $e) {
            return '';
        }
    }

    public static function kvSet(string $key, string $value): void
    {
        Database::run(
            'INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES (:s, :k, :v, :u)
             ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = VALUES(updated_by)',
            ['s' => self::SCOPE, 'k' => $key, 'v' => $value, 'u' => 'autopilot']
        );
    }
}
