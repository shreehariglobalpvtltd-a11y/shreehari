<?php
/**
 * =====================================================================
 *  ChalaniStrip — one money strip for every chalani surface (25 Nov 2026,
 *  release ch, owner plan section 6 "uniform chalani").
 *
 *  Every counter, both directions (Rupaidiha -> Gujarat and Gujarat ->
 *  Rupaidiha), prints the same four figures at the top of the chalani:
 *
 *     जम्मा यात्री Total | नगद Cash | अनलाइन Online | ड्यू बाँकी Due
 *
 *  on the screen (admin/manifest.php), the PNG pages (ChalaniPng) and the
 *  PDF (ChalaniPdf). It adds NO money rule of its own. It reads the
 *  per-row rule that ChalaniPng::departureMoney() already gives every
 *  chalani surface ($money: ticket / extra / cash / online) and the
 *  booking's pay mode (ChalaniPng::payMode via payMark). Each rupee of a
 *  row goes into exactly ONE bucket:
 *
 *     cash / online  what $money puts in नगद / अनलाइन (the chalani columns)
 *     due            mode boarding | due | pending: the rest of the row
 *     other          mode paid | agent | cash | online with nothing (or less)
 *                    in the two columns, e.g. an office tick with no
 *                    verified payment row: settled, but not cash / online
 *
 *  so  amount = cash + online + due + other  for every row and for the
 *  whole bus (tests/mp1-chalani-test.php proves it).
 *
 *  Also here, so the screen, the JSON poll and the tests read one place:
 *    - the chalani.export permission rule (PNG / PDF / share),
 *    - the live JSON payload for the 30 s poll,
 *    - the signed WhatsApp share links (download-chalan.php),
 *    - "आजको काउन्टर हिसाब": today's counter money (CounterBook) and per
 *      person who cut tickets (the desk-day.php query shape).
 *
 *  Read-only. Never writes a booking, a payment or a ledger row.
 *
 *  Switches (all inline Settings::getBool, default in brackets):
 *    chalani_money_strip_on     [on]  the strip + the Paid/Due row badge
 *    chalani_live_poll_on       [on]  the 30 s refresh on the chalani screen
 *    chalani_export_perm_on     [on]  PNG / PDF / share need chalani.export
 *                                     (off = bookings.view, as before)
 *    chalani_counter_totals_on  [on]  the "today's counter money" block
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class ChalaniStrip
{
    /** Pay modes whose unpaid rest is "due" (ChalaniPng::payMode). */
    public const DUE_MODES = ['boarding', 'due', 'pending'];

    public static function on(): bool
    {
        return Settings::getBool('chalani_money_strip_on', true);
    }

    public static function pollOn(): bool
    {
        return Settings::getBool('chalani_live_poll_on', true);
    }

    public static function counterOn(): bool
    {
        return Settings::getBool('chalani_counter_totals_on', true);
    }

    /**
     * May the signed-in staff member download / share the WHOLE-BUS chalani
     * (PNG, PDF, print sheet, WhatsApp share)? chalani.export when the switch
     * is on (superadmin, counter, manager, operations by default; anyone else
     * by a per-person grant); bookings.view when it is off (the old rule).
     * An agent's AGENT COPY is not decided here: agent_chalani_on keeps it.
     */
    public static function mayExport(): bool
    {
        if (!Settings::getBool('chalani_export_perm_on', true)) {
            return Auth::can('bookings.view');
        }
        return Auth::can('chalani.export');
    }

    /**
     * One row's money in the four buckets.
     *
     * @param array{ticket:float|int,extra?:float|int,cash:float|int,online:float|int} $m  the chalani $money($r)
     * @return array{amount:float,cash:float,online:float,due:float,other:float,paid:bool,mode:string}
     */
    public static function row(array $m, string $mode): array
    {
        $amount = (float) ($m['ticket'] ?? 0) + (float) ($m['extra'] ?? 0);
        $cash   = (float) ($m['cash'] ?? 0);
        $online = (float) ($m['online'] ?? 0);
        $rest   = max(0.0, $amount - $cash - $online);
        $due    = in_array($mode, self::DUE_MODES, true) ? $rest : 0.0;
        $other  = $rest - $due;
        /* the columns can never hold more than the row is worth; if they ever
           did, the row amount follows the columns so the sum still closes */
        if ($cash + $online > $amount) {
            $amount = $cash + $online;
        }
        return ['amount' => $amount, 'cash' => $cash, 'online' => $online, 'due' => $due, 'other' => $other,
            'paid' => $due <= 0.0, 'mode' => $mode];
    }

    /**
     * The whole strip from the rows and the two closures every chalani surface
     * already has ($fmt['money'], $fmt['payMark']).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{passengers:int,amount:float,cash:float,online:float,due:float,dueN:int,other:float,paidN:int,rows:array<int,array<string,mixed>>}
     */
    public static function fromRows(array $rows, callable $money, callable $payMark): array
    {
        $s = ['passengers' => 0, 'amount' => 0.0, 'cash' => 0.0, 'online' => 0.0, 'due' => 0.0, 'dueN' => 0, 'other' => 0.0, 'paidN' => 0, 'rows' => []];
        foreach ($rows as $r) {
            $pm   = (array) $payMark($r);
            $line = self::row((array) $money($r), (string) ($pm['mode'] ?? 'due'));
            $s['passengers']++;
            foreach (['amount', 'cash', 'online', 'due', 'other'] as $k) { $s[$k] += $line[$k]; }
            if ($line['paid']) { $s['paidN']++; } else { $s['dueN']++; }
            $s['rows'][(int) ($r['pax_id'] ?? 0)] = $line + ['bid' => (int) ($r['booking_id'] ?? 0)];
        }
        foreach (['amount', 'cash', 'online', 'due', 'other'] as $k) { $s[$k] = round($s[$k], 2); }
        return $s;
    }

    /**
     * The strip of a ChalaniPng::departureMoney() result. $local: in the desk's
     * own money when the copy is a non-rupee desk's (moneyLocal), else rupees.
     *
     * @param array<string,mixed> $dm
     */
    public static function fromDm(array $dm, bool $local = false): array
    {
        $pmBook = (array) ($dm['pmBook'] ?? []);
        $payMark = static fn(array $r): array => ['mode' => (string) ($pmBook[(int) ($r['booking_id'] ?? 0)] ?? 'due')];
        $money = ($local && ($dm['cur'] ?? null) !== null && is_callable($dm['moneyLocal'] ?? null)) ? $dm['moneyLocal'] : $dm['money'];
        return self::fromRows((array) ($dm['rows'] ?? []), $money, $payMark);
    }

    /**
     * The row badge: Paid green / Due red, with how (Cash / Online / ...).
     * @return array{cls:string,ne:string,en:string,ro:string}
     */
    public static function badge(string $mode, bool $paid): array
    {
        if (!$paid) {
            return match ($mode) {
                'boarding' => ['cls' => 'due', 'ne' => 'बाँकी · बसमा', 'en' => 'Due · On boarding', 'ro' => 'DUE'],
                'pending'  => ['cls' => 'due', 'ne' => 'बाँकी · अनलाइन जाँचमा', 'en' => 'Due · Online checking', 'ro' => 'DUE'],
                default    => ['cls' => 'due', 'ne' => 'बाँकी', 'en' => 'Due', 'ro' => 'DUE'],
            };
        }
        return match ($mode) {
            'cash'   => ['cls' => 'paid', 'ne' => 'भुक्तानी · नगद', 'en' => 'Paid · Cash', 'ro' => 'PAID'],
            'online' => ['cls' => 'paid', 'ne' => 'भुक्तानी · अनलाइन', 'en' => 'Paid · Online', 'ro' => 'PAID'],
            'agent'  => ['cls' => 'paid', 'ne' => 'भुक्तानी · एजेन्टसँग', 'en' => 'Paid · With agent', 'ro' => 'AGENT'],
            default  => ['cls' => 'paid', 'ne' => 'भुक्तानी', 'en' => 'Paid', 'ro' => 'PAID'],
        };
    }

    /** The strip's four labels, Nepali · English (rule: labels Nepali, values Roman). @return array<string,string> */
    public static function labels(): array
    {
        return ['pax' => 'जम्मा यात्री · Total', 'cash' => 'नगद · Cash', 'online' => 'अनलाइन · Online', 'due' => 'ड्यू बाँकी · Due'];
    }

    /** An amount the way the copy writes it: ₹ or रू. */
    public static function money(float $amt, ?string $currency = null): string
    {
        $cur = strtoupper((string) ($currency ?? 'INR'));
        return CounterDesk::format(round($amt), $cur === '' ? 'INR' : $cur);
    }

    /**
     * The JSON the chalani screen polls every 30 s: the strip, and per row the
     * colour (OpsHub::payState), the pay mode words and the Paid/Due badge. Its
     * 'key' changes when a passenger is added or removed (the page then offers a
     * reload); 'fp' changes when anything shown changes.
     *
     * @param array<string,mixed> $dm  ChalaniPng::departureMoney()
     * @return array<string,mixed>
     */
    public static function livePayload(array $dm, callable $pmStaff): array
    {
        require_once INCLUDE_PATH . '/opshub.php';
        $cur   = $dm['cur'] ?? null;
        $code  = is_array($cur) ? (string) $cur['code'] : 'INR';
        $strip = self::fromDm($dm, is_array($cur));
        $state = (array) ($dm['state'] ?? []);
        $first = (array) ($dm['xFirst'] ?? []);
        $pmDue = (array) ($dm['pmDue'] ?? []);
        $claim = (array) ($dm['pmClaim'] ?? []);
        $rows  = [];
        $groups = [];
        foreach ((array) ($dm['rows'] ?? []) as $r) {
            $pid = (int) ($r['pax_id'] ?? 0);
            $bid = (int) ($r['booking_id'] ?? 0);
            $st  = (string) ($state[$bid] ?? 'due');
            [$lbl, $cls] = OpsHub::payLabel($st);
            $line = $strip['rows'][$pid] ?? self::row([], 'due');
            $mode = (string) $line['mode'];
            $pmk  = ['mode' => $mode, 'amt' => $mode === 'boarding' ? round((float) ($pmDue[$bid] ?? 0), 2) : 0.0,
                     'first' => (int) ($first[$bid] ?? -1) === $pid, 'claim' => (bool) ($claim[$bid] ?? false)];
            $bd   = self::badge($mode, (bool) $line['paid']);
            $rows[] = ['p' => $pid, 'b' => $bid, 'st' => $st, 'lbl' => $lbl, 'cls' => $cls, 'pm' => $mode,
                       'pmTxt' => (string) $pmStaff($pmk, (bool) $pmk['first']), 'pd' => $bd['cls'], 'pdTxt' => $bd['ne'] . ' · ' . $bd['en']];
            if (!isset($groups[$bid])) {
                $groups[$bid] = ['b' => $bid, 'st' => $st, 'lbl' => $lbl, 'cls' => $cls, 'pm' => $mode, 'pmTxt' => (string) $pmStaff($pmk, true)];
            }
        }
        $ids = array_map(static fn(array $x): int => (int) $x['p'], $rows);
        sort($ids);
        $out = [
            'ok' => true, 'sid' => (int) ($dm['sid'] ?? 0), 'cur' => $code,
            'key' => md5(implode(',', $ids)),
            'strip' => [
                'pax' => (int) $strip['passengers'], 'amount' => $strip['amount'], 'cash' => $strip['cash'],
                'online' => $strip['online'], 'due' => $strip['due'], 'dueN' => (int) $strip['dueN'], 'other' => $strip['other'],
                'txt' => [
                    'pax'    => $strip['passengers'] . ' · ' . self::money($strip['amount'], $code),
                    'cash'   => self::money($strip['cash'], $code),
                    'online' => self::money($strip['online'], $code),
                    'due'    => self::money($strip['due'], $code) . ($strip['dueN'] > 0 ? ' · ' . $strip['dueN'] . ' जना' : ''),
                ],
            ],
            'rows' => $rows, 'groups' => array_values($groups),
        ];
        $out['fp'] = md5((string) json_encode([$out['key'], $out['strip'], $rows, $out['groups']]));
        $out['at'] = date('H:i:s');
        return $out;
    }

    /**
     * Signed, expiring public links to the chalani picture pages (the same key
     * download-chalan.php checks), for a WhatsApp share.
     *
     * @return array<int,string> page => url
     */
    public static function shareLinks(int $sid, int $pages, ?int $days = null): array
    {
        $days = max(1, min(14, $days ?? Settings::getInt('chalani_share_link_days', 2)));
        $exp  = time() + $days * 86400;
        $out  = [];
        for ($p = 1; $p <= max(1, $pages); $p++) {
            $out[$p] = appUrl('download-chalan.php?' . http_build_query([
                'sid' => $sid, 'doc' => 'chalani', 'page' => $p, 'exp' => $exp,
                'k' => substr(Security::sign('chalan-dl|' . $sid . '|chalani|' . $p . '|' . $exp), 0, 20),
            ]));
        }
        return $out;
    }

    /** The WhatsApp text: Nepali labels, Roman values, one link per page. @param array<int,string> $links */
    public static function shareText(array $trip, string $date, string $chalaniNo, array $strip, array $links, string $currency = 'INR'): string
    {
        $t = "🚌 बस चलानी · Bus Chalani " . $chalaniNo . "\n"
           . trim((string) ($trip['from_city'] ?? '')) . ' → ' . trim((string) ($trip['to_city'] ?? '')) . ' · ' . formatDate($date)
           . (($trip['dep_time'] ?? '') !== '' ? ' · ' . substr((string) $trip['dep_time'], 0, 5) : '') . "\n"
           . 'बस · Bus: ' . (trim((string) ($trip['bus_number'] ?? '')) ?: '-') . "\n"
           . 'जम्मा यात्री ' . (int) $strip['passengers'] . ' · नगद ' . self::money((float) $strip['cash'], $currency)
           . ' · अनलाइन ' . self::money((float) $strip['online'], $currency) . ' · बाँकी ' . self::money((float) $strip['due'], $currency) . "\n";
        foreach ($links as $p => $u) {
            $t .= 'पेज ' . $p . ' · Page ' . $p . ': ' . $u . "\n";
        }
        return $t;
    }

    /* ------------------------------------------------------------------ */
    /*  आजको काउन्टर हिसाब — today's counter money                          */
    /* ------------------------------------------------------------------ */

    /**
     * Who sees the block: a counter login (its own desk), or an office login
     * with reports.view (every desk). Never an agent, never anyone else.
     * @return 'desk'|'all'|null
     */
    public static function counterView(): ?string
    {
        if (!self::counterOn() || Auth::bookingScopeAdminId() !== null) {
            return null;
        }
        $role = (string) (Auth::admin()['role'] ?? '');
        if ($role === 'superadmin' || Auth::can('reports.view')) {
            return 'all';
        }
        return $role === 'counter' ? 'desk' : null;
    }

    /**
     * Today's money: the CounterBook lines (taken today: cash / online; pay at
     * boarding still to collect) for one desk or all, and per person who CUT
     * tickets today (the desk-day.php query shape: booked_by first, else sold_by).
     *
     * @return array{day:string,desk:string,deskName:string,lines:array<int,array<string,mixed>>,totals:array<string,float|int>,people:array<int,array<string,mixed>>}
     */
    public static function counterToday(string $view, int $adminId, ?string $day = null): array
    {
        require_once INCLUDE_PATH . '/counterbook.php';
        $day  = ($day !== null && Security::isValidDate($day)) ? $day : todayISO();
        $desk = $view === 'desk' ? (string) (CounterDesk::forAdmin($adminId)['code'] ?? '') : '';
        $book = CounterBook::day($day);
        $lines = [];
        foreach ($book['rows'] as $k => $r) {
            $k = (string) $k;
            if ($view === 'desk') {
                if ($desk !== '' ? $k !== $desk : $k !== 'AG:' . $adminId) { continue; }
            }
            /* a line with no ticket and no money today (only a refund / commission row) is noise here */
            if ((int) $r['tickets'] === 0 && (float) $r['cash'] == 0.0 && (float) $r['online'] == 0.0 && (float) $r['pending'] == 0.0) { continue; }
            $lines[] = ['key' => $k, 'label' => (string) ($r['label'] ?? $k), 'kind' => (string) ($r['kind'] ?? ''),
                'tickets' => (int) $r['tickets'], 'pax' => (int) $r['pax'], 'cash' => (float) $r['cash'], 'online' => (float) $r['online'],
                'due' => (float) $r['pending'], 'dueN' => (int) $r['pendingN'], 'currency' => (string) ($r['currency'] ?? 'INR'), 'cashLocal' => (float) ($r['cashLocal'] ?? 0)];
        }
        $tot = ['tickets' => 0, 'pax' => 0, 'cash' => 0.0, 'online' => 0.0, 'due' => 0.0, 'dueN' => 0];
        foreach ($lines as $l) { foreach ($tot as $f => $_) { $tot[$f] += $l[$f]; } }

        /* per person who cut tickets today (desk-day.php: COALESCE(booked_by, sold_by), the desk
           frozen on the sale else the cutter's profile desk) - money of THOSE tickets */
        $hasBy   = CounterDesk::hasColumn('bookings', 'booked_by_admin_id');
        $cutSql  = $hasBy ? 'COALESCE(b.booked_by_admin_id, b.sold_by_admin_id)' : 'b.sold_by_admin_id';
        $parts   = [];
        if (CounterDesk::stampColumn()) { $parts[] = "NULLIF(b.counter_code, '')"; }
        $parts[] = "NULLIF(ap.counter_code, '')";
        $deskSql = 'COALESCE(' . implode(', ', $parts) . ", '')";
        $where   = ["b.created_at >= :f", "b.created_at < :t", "$cutSql IS NOT NULL", "b.status IN ('pending','confirmed','completed')"];
        $params  = ['f' => $day . ' 00:00:00', 't' => addDaysISO($day, 1) . ' 00:00:00'];
        if ($view === 'desk') {
            if ($desk !== '') { $where[] = "$deskSql = :k"; $params['k'] = $desk; }
            else { $where[] = "$cutSql = :me"; $params['me'] = $adminId; }
        }
        $people = [];
        try {
            $people = Database::fetchAll(
                "SELECT $cutSql AS who,
                        (SELECT COALESCE(NULLIF(y.full_name, ''), y.username) FROM admins y WHERE y.id = $cutSql) AS name,
                        (SELECT y.role FROM admins y WHERE y.id = $cutSql) AS role,
                        MAX($deskSql) AS desk,
                        COUNT(*) AS tickets,
                        COALESCE(SUM((SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id)), 0) AS pax,
                        COALESCE(SUM(b.total_amount), 0) AS amount,
                        COALESCE(SUM((SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.booking_id = b.id AND p.status = 'verified' AND p.method IN ('cash','cod'))), 0) AS cash,
                        COALESCE(SUM((SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.booking_id = b.id AND p.status = 'verified' AND p.method NOT IN ('cash','cod'))), 0) AS online
                   FROM bookings b
                   LEFT JOIN admin_profiles ap ON ap.admin_id = $cutSql
                  WHERE " . implode(' AND ', $where) . "
                  GROUP BY $cutSql
                  ORDER BY amount DESC
                  LIMIT 30",
                $params
            );
        } catch (Throwable $e) {
            $people = [];
        }
        foreach ($people as &$p) {
            $p['tickets'] = (int) $p['tickets'];
            $p['pax']     = (int) $p['pax'];
            foreach (['amount', 'cash', 'online'] as $f) { $p[$f] = round((float) $p[$f], 2); }
            $p['due'] = round(max(0.0, $p['amount'] - $p['cash'] - $p['online']), 2);
        }
        unset($p);
        $deskName = $desk !== '' ? CounterDesk::label($desk) : '';
        return ['day' => $day, 'desk' => $desk, 'deskName' => $deskName, 'lines' => $lines, 'totals' => $tot, 'people' => $people];
    }
}
