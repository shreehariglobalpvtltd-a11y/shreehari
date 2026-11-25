<?php
/**
 * includes/sellerday.php — ONE person's day on the books, and the evening
 * WhatsApp day report (25 Nov 2026, release dy, owner plan section 7).
 *
 * Owner: "agent / counter / staff le WhatsApp ma 'aaja' / 'hisab' lekhda
 * aafno din ko hisab aaos, ra beluka aafai aaos".
 *
 * THE NUMBERS are CounterBook's rules (includes/counterbook.php), scoped to
 * one person instead of one desk, so the bot, the evening message, Counters
 * and the Day book never tell two different stories. Every booking belongs to
 * exactly ONE person, the one who cut it (self::cutBy: booked_by, else
 * sold_by; admin/desk-day.php's $cutSql), so the people's figures add up to
 * CounterBook's and never count one ticket or one rupee twice:
 *   tickets / seats / pax / inr - confirmed or completed bookings by
 *                 confirmed_at, cut by this person
 *   cash        - VERIFIED cash + cod payments (नगद) by verified_at on those
 *                 bookings; cashLocal = the drawer in the person's desk money
 *                 (NPR at a Nepal desk: local_amount, else amount x the rate
 *                 frozen on the payment, else x the desk rate)
 *   online      - every other verified method (UPI, eSewa, bank, gateway)
 *   pending     - ड्यू बाँकी: today's tickets whose latest payment is still
 *                 cod_pending (to collect)
 *   commission  - agent_ledger commission + commission_void created today for
 *                 this person (agent_admin_id): an agent whose code a clerk
 *                 used still sees his commission, never the clerk's cash
 * For a seller with no desk this is exactly CounterBook's 'AG:<id>' line; for
 * the only clerk at a desk it is exactly that desk's line, his tickets cut on
 * an agent's code included - all but commission, which CounterBook files on
 * the desk line and this file gives to the agent who earned it
 * (tests/mp1-dayreport-test.php).
 *
 * THE EVENING REPORT (cron/seller-day.php) goes only FREE: inside the
 * person's open 24 h WhatsApp window (WaRoute::freeFirst), else a web push
 * when they have a subscription, else nothing. Never a paid template (the new
 * Meta templates fail with 132001). One claim per person per day in
 * automation_log (EventBus::claim) so a rerun never sends twice.
 *
 * Switches (read inline, Settings > all):
 *   seller_day_numbers_on   (1) AiTools sellerDay reads these figures
 *   seller_day_wa_on        (1) the bot's "aaja / hisab" reply in this format
 *   seller_day_report_on    (1) the evening report to each seller
 *   seller_day_report_hour (20) the IST hour it starts (cron every 15 min)
 *   seller_day_admin_on     (off while counter_day_report_on sends the 21:30
 *                           all-desk report, so the owner gets ONE evening total)
 *   seller_day_push_on      (1) web push when the window is closed
 *
 * Read-only on the books: never writes a booking, a payment or a ledger row.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/counterdesk.php';
require_once __DIR__ . '/counterbook.php';

final class SellerDay
{
    /** Test hook: fn(int $adminId, array $payload): int (pushes sent). */
    public static $pusher = null;

    private const DIGITS = ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४', '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९'];

    public static function numbersOn(): bool
    {
        return Settings::getBool('seller_day_numbers_on', true);
    }

    public static function waOn(): bool
    {
        return Settings::getBool('seller_day_wa_on', true);
    }

    /**
     * WHO a booking belongs to, one person only: the one who CUT it (the
     * clerk, booked_by), else the seller whose code it is on (sold_by) when no
     * clerk was recorded. admin/desk-day.php's $cutSql. A clerk cutting on an
     * agent's code (Booking::attributeDeskSale) keeps the ticket and the cash
     * he took; the agent keeps only the commission (agent_ledger).
     */
    public static function cutBy(): string
    {
        return CounterDesk::hasColumn('bookings', 'booked_by_admin_id')
            ? 'COALESCE(b.booked_by_admin_id, b.sold_by_admin_id)'
            : 'b.sold_by_admin_id';
    }

    /**
     * One person's day (Y-m-d, IST app clock). Zeros when nothing happened.
     *
     * @return array<string,mixed>
     */
    public static function figures(int $adminId, string $day): array
    {
        if (!Security::isValidDate($day)) {
            $day = todayISO();
        }
        $desk = $adminId > 0 ? CounterDesk::forAdmin($adminId)['code'] : '';
        $cur  = $desk !== '' ? CounterDesk::currency($desk) : 'INR';
        $rate = $desk !== '' ? CounterDesk::rate($desk) : 1.0;
        $out  = ['date' => $day, 'adminId' => $adminId, 'desk' => $desk, 'currency' => $cur, 'rate' => $rate,
                 'tickets' => 0, 'seats' => 0, 'pax' => 0, 'inr' => 0.0, 'cash' => 0.0, 'cashLocal' => 0.0,
                 'online' => 0.0, 'pending' => 0.0, 'pendingN' => 0, 'commission' => 0.0];
        if ($adminId <= 0) {
            return $out;
        }
        $w    = ['f' => $day, 't1' => addDaysISO($day, 1), 'a' => $adminId];
        $mine = self::cutBy() . ' = :a';

        try {
            $r = Database::fetch(
                "SELECT COUNT(*) AS tickets,
                        COALESCE(SUM((SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id)), 0) AS seats,
                        COALESCE(SUM((SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id)), 0) AS pax,
                        COALESCE(SUM(b.total_amount), 0) AS inr
                   FROM bookings b
                  WHERE b.status IN ('confirmed','completed')
                    AND b.confirmed_at >= :f AND b.confirmed_at < :t1
                    AND $mine", $w) ?? [];
            $out['tickets'] = (int) ($r['tickets'] ?? 0);
            $out['seats']   = (int) ($r['seats'] ?? 0);
            $out['pax']     = (int) ($r['pax'] ?? 0);
            $out['inr']     = round((float) ($r['inr'] ?? 0), 2);

            /* the drawer, as CounterBook::range reads it */
            $hasLocal = CounterDesk::hasColumn('payments', 'local_amount');
            $hasPFx   = $hasLocal && CounterDesk::hasColumn('payments', 'fx_rate');
            $stamped  = "(COALESCE(p.local_currency, '') = 'NPR' AND COALESCE(p.local_amount, 0) > 0)";
            $drawer   = $hasLocal
                ? ", COALESCE(SUM(CASE WHEN $stamped THEN p.local_amount ELSE 0 END), 0) AS npr_known"
                  . ($hasPFx
                      ? ", COALESCE(SUM(CASE WHEN NOT $stamped AND COALESCE(p.fx_rate, 0) > 0 THEN p.amount * p.fx_rate ELSE 0 END), 0) AS npr_fx"
                        . ", COALESCE(SUM(CASE WHEN NOT $stamped AND COALESCE(p.fx_rate, 0) <= 0 THEN p.amount ELSE 0 END), 0) AS inr_bare"
                      : ", 0 AS npr_fx, COALESCE(SUM(CASE WHEN NOT $stamped THEN p.amount ELSE 0 END), 0) AS inr_bare")
                : ', 0 AS npr_known, 0 AS npr_fx, COALESCE(SUM(p.amount), 0) AS inr_bare';
            $known = 0.0;
            $bare  = 0.0;
            foreach (Database::fetchAll(
                "SELECT p.method, COALESCE(SUM(p.amount), 0) AS inr $drawer
                   FROM payments p
                   JOIN bookings b ON b.id = p.booking_id
                  WHERE p.status = 'verified'
                    AND p.verified_at >= :f AND p.verified_at < :t1
                    AND $mine
                  GROUP BY p.method", $w) as $p) {
                $m = strtolower((string) $p['method']);
                if ($m === 'cash' || $m === 'cod') {
                    $out['cash'] += (float) $p['inr'];
                    $known += (float) $p['npr_known'] + (float) $p['npr_fx'];
                    $bare  += (float) $p['inr_bare'];
                } else {
                    $out['online'] += (float) $p['inr'];
                }
            }
            $out['cash']      = round($out['cash'], 2);
            $out['online']    = round($out['online'], 2);
            $out['cashLocal'] = $cur === 'INR' ? $out['cash'] : (float) round($known + $bare * $rate, 2);

            $r = Database::fetch(
                "SELECT COUNT(*) AS n, COALESCE(SUM(b.total_amount), 0) AS amt
                   FROM bookings b
                  WHERE b.status IN ('confirmed','completed')
                    AND b.confirmed_at >= :f AND b.confirmed_at < :t1
                    AND $mine
                    AND (SELECT p2.status FROM payments p2 WHERE p2.booking_id = b.id ORDER BY p2.id DESC LIMIT 1) = 'cod_pending'", $w) ?? [];
            $out['pending']  = round((float) ($r['amt'] ?? 0), 2);
            $out['pendingN'] = (int) ($r['n'] ?? 0);
        } catch (Throwable $e) {
            Logger::warning('SellerDay figures failed: ' . $e->getMessage(), ['admin' => $adminId], 'reports');
        }

        try {
            $out['commission'] = round((float) Database::scalar(
                "SELECT COALESCE(SUM(l.amount), 0) FROM agent_ledger l
                  WHERE l.agent_admin_id = :a AND l.entry_type IN ('commission','commission_void')
                    AND l.created_at >= :f AND l.created_at < :t1", $w, 0), 2);
        } catch (Throwable $e) {
            // no ledger table on a bare database: commission reads 0
        }
        return $out;
    }

    /** An INR figure in the person's desk money: "रू 3,520" at a Nepal desk, "₹2,199" otherwise. */
    public static function money(float $inr, array $fig): string
    {
        $cur = (string) ($fig['currency'] ?? 'INR');
        if ($cur === 'INR') {
            return CounterDesk::format(round($inr, 2), 'INR');
        }
        return CounterDesk::format((float) round($inr * (float) ($fig['rate'] ?? 1.0)), $cur);
    }

    /** The cash figure: the drawer itself at a Nepal desk (NPR as taken), else INR. */
    public static function cashMoney(array $fig): string
    {
        $cur = (string) ($fig['currency'] ?? 'INR');
        return $cur === 'INR'
            ? CounterDesk::format((float) ($fig['cash'] ?? 0), 'INR')
            : CounterDesk::format((float) round((float) ($fig['cashLocal'] ?? 0)), $cur);
    }

    /** "२०८३ मंसिर ९ / 25 Nov 2026" — BS first, AD beside it; AD alone outside the BS table. */
    public static function dateLabel(string $day): string
    {
        $ad = date('j M Y', (int) strtotime($day . ' 12:00:00'));
        try {
            require_once __DIR__ . '/bsdate.php';
            $bs = BsDate::fromAd($day);
            if ($bs !== null) {
                return strtr((string) $bs['y'], self::DIGITS) . ' ' . BsDate::MONTH_NAMES[$bs['m'] - 1] . ' '
                    . strtr((string) $bs['d'], self::DIGITS) . ' / ' . $ad;
            }
        } catch (Throwable $e) {
        }
        return $ad;
    }

    /**
     * "नमस्ते Ram जी, आज २०८३ मंसिर ९ / 25 Nov 2026: टिकट 3, नगद ₹4,000, अनलाइन ₹1,500,
     *  ड्यू बाँकी ₹900, तपाईंको कमिसन ₹300" — Nepali (English only when the person chose it).
     */
    public static function text(array $fig, string $name, string $lang = 'ne'): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $d = self::dateLabel((string) ($fig['date'] ?? todayISO()));
        $t = (string) (int) ($fig['tickets'] ?? 0);
        $c = self::cashMoney($fig);
        $o = self::money((float) ($fig['online'] ?? 0), $fig);
        $z = self::money((float) ($fig['pending'] ?? 0), $fig);
        $w = self::money((float) ($fig['commission'] ?? 0), $fig);
        if ($lang === 'en') {
            return 'Namaste ' . ($name !== '' ? $name . ' ji' : 'ji') . ', today ' . $d . ': tickets ' . $t . ', cash ' . $c
                . ', online ' . $o . ', due ' . $z . ', your commission ' . $w;
        }
        return 'नमस्ते ' . ($name !== '' ? $name . ' जी' : 'जी') . ', आज ' . $d . ': टिकट ' . $t . ', नगद ' . $c
            . ', अनलाइन ' . $o . ', ड्यू बाँकी ' . $z . ', तपाईंको कमिसन ' . $w;
    }

    /** The owner's evening total, all desks (CounterBook::day), counts and money only — no passenger names. */
    public static function adminText(string $day): string
    {
        $book = CounterBook::day($day);
        $tot  = $book['totals'];
        $inr  = static fn(float $v): string => CounterDesk::format(round($v, 2), 'INR');
        $lines = ['📊 नमस्ते जी, आज ' . self::dateLabel($day) . ': सबै काउन्टर — टिकट ' . (int) $tot['tickets']
            . ', नगद ' . $inr((float) $tot['cash']) . ', अनलाइन ' . $inr((float) $tot['online'])
            . ', ड्यू बाँकी ' . $inr((float) $tot['pending']) . ', कमिसन ' . $inr((float) $tot['commission'])];
        $n = 0;
        foreach ($book['rows'] as $r) {
            if ((int) $r['tickets'] <= 0 && (float) $r['cash'] <= 0 && (float) $r['online'] <= 0) {
                continue;
            }
            if (++$n > 12) {
                $lines[] = '…';
                break;
            }
            $cash = (string) $r['currency'] === 'INR'
                ? $inr((float) $r['cash'])
                : CounterDesk::format((float) round((float) $r['cashLocal']), (string) $r['currency']);
            $lines[] = '· ' . mb_substr((string) $r['label'], 0, 40) . ': टिकट ' . (int) $r['tickets'] . ', नगद ' . $cash
                . ', अनलाइन ' . $inr((float) $r['online']);
        }
        return implode("\n", $lines);
    }

    /* -----------------------------------------------------------------
     *  The evening report
     * ----------------------------------------------------------------- */

    /**
     * People who CUT at least one ticket today (self::cutBy, the same rule as
     * figures), active accounts only.
     *
     * @return list<array{id:int,name:string,phone:string}>
     */
    public static function sellersOn(string $day): array
    {
        $w = ['f' => $day, 't1' => addDaysISO($day, 1)];
        $cut = self::cutBy();
        $sql = "SELECT DISTINCT $cut AS id FROM bookings b
                 WHERE b.status IN ('confirmed','completed') AND b.confirmed_at >= :f AND b.confirmed_at < :t1
                   AND $cut IS NOT NULL";
        $ids = [];
        try {
            foreach (Database::fetchAll($sql, $w) as $r) {
                if ((int) $r['id'] > 0) {
                    $ids[(int) $r['id']] = true;
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (Database::fetchAll(
            'SELECT id, full_name, username, phone FROM admins WHERE is_active = 1 AND id IN (' . implode(',', array_map('intval', array_keys($ids))) . ') ORDER BY id'
        ) as $a) {
            $out[] = ['id' => (int) $a['id'], 'name' => (string) (($a['full_name'] ?? '') !== '' ? $a['full_name'] : $a['username']),
                      'phone' => (string) ($a['phone'] ?? '')];
        }
        return $out;
    }

    /** A number of this person whose free 24 h Meta window is open now, as international digits, or ''. */
    public static function openNumber(int $adminId, string $registered): string
    {
        require_once __DIR__ . '/waroute.php';
        require_once __DIR__ . '/wainbox.php';
        $raw = [];
        try {
            foreach (Database::fetchAll('SELECT phone FROM ap_wa_links WHERE admin_id = :a AND revoked_at IS NULL', ['a' => $adminId]) as $r) {
                $raw[] = (string) $r['phone'];
            }
        } catch (Throwable $e) {
            // no AgentPro tables: the registered number only
        }
        if (trim($registered) !== '') {
            $raw[] = $registered;
        }
        $desk = CounterDesk::forAdmin($adminId)['code'];
        $deskHint = $desk !== '' && CounterDesk::dialCode($desk) === '977' ? 'NP' : '';
        foreach (array_unique($raw) as $p) {
            $hint = '';
            try {
                $hint = WaInbox::seenCountry($p);
            } catch (Throwable $e) {
            }
            $intl = WaRoute::intl($p, $hint !== '' ? $hint : ($deskHint !== '' ? $deskHint : null));
            if ($intl !== '' && WaRoute::metaWindowOpen($intl)) {
                return $intl;
            }
        }
        return '';
    }

    /** Has this person a web-push handset? (the hook counts as one in tests) */
    private static function canPush(int $adminId): bool
    {
        if (!Settings::getBool('seller_day_push_on', true)) {
            return false;
        }
        if (self::$pusher !== null) {
            return true;
        }
        try {
            require_once __DIR__ . '/webpush.php';
            return WebPush::enabled() && WebPush::forAdmin($adminId) !== [];
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function push(int $adminId, string $body): int
    {
        $payload = ['title' => '📊 आजको हिसाब', 'body' => mb_substr($body, 0, 240), 'url' => appUrl('admin/'), 'tag' => 'seller-day'];
        if (self::$pusher !== null) {
            return (int) (self::$pusher)($adminId, $payload);
        }
        try {
            require_once __DIR__ . '/webpush.php';
            return (int) (WebPush::sendToAdmin($adminId, $payload, 'seller_day')['sent'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Was today's message for this key already claimed? (no claim is made here) */
    private static function claimed(string $key): bool
    {
        try {
            return Database::exists('SELECT 1 FROM automation_log WHERE dedupe_key = :k', ['k' => substr($key, 0, 120)]);
        } catch (Throwable $e) {
            return true;      // no ledger to remember a send in: send nothing rather than twice
        }
    }

    public static function claimKey(string $who, string $day): string
    {
        return 'seller-day:' . $who . ':' . $day;
    }

    /**
     * One evening pass (cron/seller-day.php). Before seller_day_report_hour it
     * does nothing; from then on every run sends each person at most once a
     * day. A person whose window is closed and who has no push handset is
     * skipped WITHOUT a claim, so a later run that evening can still reach
     * them once they write to the bot.
     *
     * $only (tests, a manual rerun): just these admin ids; null = everyone.
     *
     * @param list<int>|null $only
     * @return array<string,int|string>
     */
    public static function evening(?int $now = null, ?array $only = null): array
    {
        $now ??= time();
        $out = ['state' => 'ok', 'sellers' => 0, 'sent' => 0, 'pushed' => 0, 'skipped' => 0, 'already' => 0, 'failed' => 0, 'admin' => 'none'];
        if (!Settings::getBool('seller_day_report_on', true)) {
            $out['state'] = 'off';
            return $out;
        }
        $hour = max(0, min(23, Settings::getInt('seller_day_report_hour', 20)));
        if ((int) date('G', $now) < $hour) {
            $out['state'] = 'early';
            return $out;
        }
        $day = date('Y-m-d', $now);
        require_once __DIR__ . '/events.php';
        require_once __DIR__ . '/waroute.php';
        $wa = WaRoute::metaOn();

        foreach (self::sellersOn($day) as $a) {
            if ($only !== null && !in_array($a['id'], array_map('intval', $only), true)) {
                continue;
            }
            $fig = self::figures($a['id'], $day);
            if ((int) $fig['tickets'] < 1) {
                continue;
            }
            $out['sellers']++;
            $key = self::claimKey((string) $a['id'], $day);
            if (self::claimed($key)) {
                $out['already']++;
                continue;
            }
            $text = '📊 ' . self::text($fig, $a['name']);
            $to = $wa ? self::openNumber($a['id'], $a['phone']) : '';
            $push = $to === '' && self::canPush($a['id']);
            if ($to === '' && !$push) {
                $out['skipped']++;
                continue;
            }
            if (!EventBus::claim($key, 'seller_day')) {
                $out['already']++;
                continue;
            }
            if ($to !== '') {
                $r = WaRoute::freeFirst($to, $text, null, 'seller_day', ['admin_id' => $a['id']]);
                if ($r !== null && !empty($r['success'])) {
                    $out['sent']++;
                    continue;
                }
                $push = self::canPush($a['id']);
            }
            if ($push && self::push($a['id'], $text) > 0) {
                $out['pushed']++;
                continue;
            }
            $out['failed']++;
        }

        if (Settings::getBool('seller_day_admin_on', !Settings::getBool('counter_day_report_on', true))) {
            $out['admin'] = self::adminEvening($day, $wa);
        }
        return $out;
    }

    /** The owner's one total: free on the admin number's open window, else push to super-admins. */
    private static function adminEvening(string $day, bool $wa): string
    {
        $key = self::claimKey('admin', $day);
        if (self::claimed($key)) {
            return 'already';
        }
        $to = '';
        if ($wa) {
            try {
                require_once __DIR__ . '/waagentroute.php';
                $n = WaAgentRoute::adminNumber();
                $to = $n !== '' && WaRoute::metaWindowOpen($n) ? $n : '';
            } catch (Throwable $e) {
                $to = '';
            }
        }
        $bosses = [];
        if ($to === '') {
            try {
                foreach (Database::fetchAll("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id") as $r) {
                    if (self::canPush((int) $r['id'])) {
                        $bosses[] = (int) $r['id'];
                    }
                }
            } catch (Throwable $e) {
            }
            if ($bosses === []) {
                return 'skipped';
            }
        }
        if (!EventBus::claim($key, 'seller_day')) {
            return 'already';
        }
        $text = self::adminText($day);
        if ($to !== '') {
            $r = WaRoute::freeFirst($to, $text, null, 'seller_day_admin', []);
            return $r !== null && !empty($r['success']) ? 'sent' : 'failed';
        }
        $n = 0;
        foreach ($bosses as $id) {
            $n += self::push($id, $text);
        }
        return $n > 0 ? 'pushed' : 'failed';
    }
}
