<?php
/**
 * =====================================================================
 *  CustomerDirectory — every number we have, one row per person (5 Oct 2026)
 *
 *  Built live from the bookings (nothing stored): the booking phone AND the
 *  second number a family gave at checkout, optionally the leads that never
 *  booked (enquiries, help desk). Filters that matter for the office and
 *  for marketing:
 *
 *    date (booking date or travel date) · agent / counter / online ·
 *    route · country (IN/NP) · status · min trips · lapsed since · search
 *
 *  agentBreakdown() answers "whose customers, how many" for the same
 *  filters. phones() feeds the campaign audience and the exports, and
 *  always drops opted-out, placeholder and staff numbers for marketing.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/customerbook.php';

final class CustomerDirectory
{
    private function __construct() {}

    /**
     * Clean the query string into a filter set.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function filters(array $in): array
    {
        $date = static fn ($v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) === 1 ? (string) $v : '';
        $f = [
            'mode'    => in_array($in['mode'] ?? '', ['travel', 'booking'], true) ? (string) $in['mode'] : 'booking',
            'from'    => $date($in['from'] ?? ''),
            'to'      => $date($in['to'] ?? ''),
            'agent'   => (string) ($in['agent'] ?? ''),          // '' all, 'online', 'office', or an admin id
            'route'   => (int) ($in['route'] ?? 0),
            'country' => in_array($in['country'] ?? '', ['IN', 'NP'], true) ? (string) $in['country'] : '',
            'status'  => in_array($in['status'] ?? '', ['confirmed', 'all', 'cancelled'], true) ? (string) $in['status'] : 'confirmed',
            'min'     => max(0, min(50, (int) ($in['min'] ?? 0))),
            'lapsed'  => max(0, min(1000, (int) ($in['lapsed'] ?? 0))),   // no trip in the last N days
            'alt'     => (string) ($in['alt'] ?? '1') !== '0',
            'leads'   => (string) ($in['leads'] ?? '0') === '1',
            'q'       => trim(Security::clean((string) ($in['q'] ?? ''), 60)),
        ];
        $range = (string) ($in['range'] ?? '');
        $today = date('Y-m-d');
        $map = [
            'today' => [$today, $today],
            '7d'    => [date('Y-m-d', strtotime('-6 days')), $today],
            '30d'   => [date('Y-m-d', strtotime('-29 days')), $today],
            'month' => [date('Y-m-01'), $today],
            'year'  => [date('Y-01-01'), $today],
        ];
        if (isset($map[$range])) {
            [$f['from'], $f['to']] = $map[$range];
        }
        return $f;
    }

    /** The filters as a query string (for links, exports, a campaign audience). */
    public static function qs(array $f, array $over = []): string
    {
        $f = array_merge($f, $over);
        $out = [];
        foreach (['mode', 'from', 'to', 'agent', 'route', 'country', 'status', 'min', 'lapsed', 'q'] as $k) {
            if (($f[$k] ?? '') !== '' && ($f[$k] ?? 0) !== 0) {
                $out[$k] = $f[$k];
            }
        }
        if (empty($f['alt'])) {
            $out['alt'] = '0';
        }
        if (!empty($f['leads'])) {
            $out['leads'] = '1';
        }
        return http_build_query($out);
    }

    /**
     * Booking-number rows (one per booking and number) that pass the filters.
     * Returns SQL + params for a derived table with columns:
     *   ph (10 digits), cc ('91'|'977'), bid, created_at, travel_date, seller, source, route_id, amount, currency, status, kind
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function base(array $f, bool $ignoreAgent = false): array
    {
        $w = ["x.ph REGEXP '^[0-9]{9,10}$'", "x.ph NOT IN ('0000000000','1111111111','9999999999')"];
        $p = [];
        if ($f['status'] === 'confirmed') {
            $w[] = "x.status IN ('confirmed','completed')";
        } elseif ($f['status'] === 'cancelled') {
            $w[] = "x.status IN ('cancelled','rejected','expired')";
        }
        $col = $f['mode'] === 'travel' ? 'x.travel_date' : 'DATE(x.created_at)';
        if ($f['from'] !== '') {
            $w[] = "$col >= :dfrom";
            $p['dfrom'] = $f['from'];
        }
        if ($f['to'] !== '') {
            $w[] = "$col <= :dto";
            $p['dto'] = $f['to'];
        }
        if (!$ignoreAgent && $f['agent'] !== '') {
            if ($f['agent'] === 'online') {
                $w[] = "x.seller IS NULL AND x.source IN ('web','app')";
            } elseif ($f['agent'] === 'office') {
                $w[] = "x.seller IS NULL AND x.source NOT IN ('web','app')";
            } else {
                $w[] = 'x.seller = :agent';
                $p['agent'] = (int) $f['agent'];
            }
        }
        if ($f['route'] > 0) {
            $w[] = 'x.route_id = :route';
            $p['route'] = $f['route'];
        }
        if ($f['country'] !== '') {
            $w[] = $f['country'] === 'NP' ? "x.cc = '977'" : "x.cc <> '977'";
        }
        // bookings: phone normalised to its last 10 digits; country from the stored code or a 977 prefix
        $one = static function (string $phoneCol, string $kind): string {
            return "SELECT RIGHT(REGEXP_REPLACE(b.$phoneCol, '[^0-9]', ''), 10) AS ph,
                           CASE WHEN REGEXP_REPLACE(COALESCE(b.contact_country_code,''), '[^0-9]', '') = '977'
                                  OR REGEXP_REPLACE(b.$phoneCol, '[^0-9]', '') LIKE '977%' THEN '977' ELSE '91' END AS cc,
                           b.id AS bid, b.created_at, bl.travel_date, b.sold_by_admin_id AS seller, b.source,
                           s.route_id, b.total_amount AS amount, b.currency, b.status, '$kind' AS kind
                      FROM bookings b
                      LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                      LEFT JOIN schedules s ON s.id = bl.schedule_id
                     WHERE b.$phoneCol IS NOT NULL AND b.$phoneCol <> ''";
        };
        $sql = $one('contact_phone', 'booking');
        if ($f['alt'] && self::hasAlt()) {
            $sql .= ' UNION ALL ' . $one('alt_phone', 'alt');
        }
        return ['(' . $sql . ') x WHERE ' . implode(' AND ', $w), $p];
    }

    private static function hasAlt(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Database::fetch("SHOW COLUMNS FROM bookings LIKE 'alt_phone'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /** HAVING for min trips / lapsed / search, applied on the per-phone groups. */
    private static function having(array $f, array &$p): string
    {
        $h = [];
        if ($f['min'] > 0) {
            $h[] = 'trips >= :hmin';
            $p['hmin'] = $f['min'];
        }
        if ($f['lapsed'] > 0) {
            $h[] = '(last_travel IS NULL OR last_travel < :hlapse)';
            $p['hlapse'] = date('Y-m-d', strtotime('-' . $f['lapsed'] . ' days'));
        }
        return $h === [] ? '' : ' HAVING ' . implode(' AND ', $h);
    }

    /**
     * One row per number.
     *
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public static function rows(array $f, int $limit = 100, int $offset = 0): array
    {
        [$from, $p] = self::base($f);
        $having = self::having($f, $p);
        $search = '';
        if ($f['q'] !== '') {
            $d = preg_replace('/\D/', '', $f['q']) ?? '';
            $or = ['EXISTS (SELECT 1 FROM booking_passengers bp WHERE bp.booking_id = x.bid AND bp.full_name LIKE :sq1)'];
            $p['sq1'] = '%' . $f['q'] . '%';
            if (strlen($d) >= 4) {
                $or[] = 'x.ph LIKE :sq2';
                $p['sq2'] = '%' . $d . '%';
            }
            $search = ' AND (' . implode(' OR ', $or) . ')';
        }
        $group = "SELECT x.ph, MAX(x.cc) AS cc, COUNT(DISTINCT x.bid) AS trips,
                         MIN(x.created_at) AS first_at, MAX(x.created_at) AS last_at,
                         MAX(x.travel_date) AS last_travel,
                         SUM(CASE WHEN x.kind = 'booking' AND x.status IN ('confirmed','completed') THEN x.amount ELSE 0 END) AS spent,
                         MAX(x.bid) AS last_bid, GROUP_CONCAT(DISTINCT x.seller) AS sellers,
                         MAX(x.kind = 'alt') AS is_alt, MAX(x.kind = 'booking') AS is_main
                    FROM $from $search GROUP BY x.ph";
        $sql = $group . $having;
        $total = (int) (Database::fetch("SELECT COUNT(*) AS n FROM ($sql) g", $p)['n'] ?? 0);
        $rows = Database::fetchAll($sql . ' ORDER BY last_at DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), $p);
        if ($f['leads'] && $offset === 0) {
            $rows = array_merge($rows, self::leads($f, array_column($rows, 'ph')));
        }
        self::decorate($rows);
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Leads that never booked: enquiries + help desk tickets in the date range.
     *
     * @param list<string> $have
     * @return list<array<string,mixed>>
     */
    private static function leads(array $f, array $have): array
    {
        $out = [];
        $seen = array_flip(array_map('strval', $have));
        $w = ["phone <> ''"];
        $p = [];
        if ($f['from'] !== '') {
            $w[] = 'DATE(created_at) >= :lf';
            $p['lf'] = $f['from'];
        }
        if ($f['to'] !== '') {
            $w[] = 'DATE(created_at) <= :lt';
            $p['lt'] = $f['to'];
        }
        $sql = 'SELECT name, phone, created_at, \'lead\' AS src FROM enquiries WHERE ' . implode(' AND ', $w)
             . ' UNION ALL SELECT name, phone, created_at, \'help\' AS src FROM support_tickets WHERE ' . implode(' AND ', $w)
             . ' ORDER BY created_at DESC LIMIT 2000';
        try {
            $list = Database::fetchAll($sql, $p);
        } catch (Throwable $e) {
            return [];
        }
        foreach ($list as $l) {
            $digits = preg_replace('/\D/', '', (string) $l['phone']) ?? '';
            $ph = substr($digits, -10);
            if (strlen($ph) < 9 || isset($seen[$ph])) {
                continue;
            }
            if ($f['country'] !== '' && ($f['country'] === 'NP') !== str_starts_with($digits, '977')) {
                continue;
            }
            $seen[$ph] = true;
            $out[] = ['ph' => $ph, 'cc' => str_starts_with($digits, '977') ? '977' : '91', 'trips' => 0,
                      'first_at' => $l['created_at'], 'last_at' => $l['created_at'], 'last_travel' => null, 'spent' => 0,
                      'last_bid' => null, 'sellers' => '', 'is_alt' => 0, 'is_main' => 0,
                      'lead_name' => (string) $l['name'], 'lead' => $l['src']];
        }
        return $out;
    }

    /** Names, passengers, agent labels and do-not-message flags for a page of rows. */
    private static function decorate(array &$rows): void
    {
        if ($rows === []) {
            return;
        }
        $bids = array_values(array_filter(array_map(static fn ($r) => (int) ($r['last_bid'] ?? 0), $rows)));
        $names = [];
        if ($bids !== []) {
            $in = implode(',', $bids);
            foreach (Database::fetchAll("SELECT booking_id, full_name FROM booking_passengers WHERE booking_id IN ($in) ORDER BY is_primary DESC, id") as $n) {
                $names[(int) $n['booking_id']][] = (string) $n['full_name'];
            }
        }
        $sellerIds = [];
        foreach ($rows as $r) {
            foreach (array_filter(explode(',', (string) ($r['sellers'] ?? ''))) as $s) {
                $sellerIds[(int) $s] = true;
            }
        }
        $sellers = [];
        if ($sellerIds !== []) {
            foreach (Database::fetchAll('SELECT id, full_name, username, role FROM admins WHERE id IN (' . implode(',', array_keys($sellerIds)) . ')') as $a) {
                $sellers[(int) $a['id']] = CustomerBook::sellerLabel(['sold_by_admin_id' => $a['id'], 'seller_name' => $a['full_name'], 'seller_user' => $a['username'], 'seller_role' => $a['role']]);
            }
        }
        $optout = self::optedOut(array_map(static fn ($r) => (string) $r['ph'], $rows));
        $staff  = CustomerBook::staffPhones();
        foreach ($rows as &$r) {
            $ph = (string) $r['ph'];
            $list = $names[(int) ($r['last_bid'] ?? 0)] ?? [];
            $r['name'] = $list[0] ?? (string) ($r['lead_name'] ?? '');
            $r['passengers'] = implode(', ', array_slice(array_unique($list), 0, 6));
            $labels = [];
            foreach (array_filter(explode(',', (string) ($r['sellers'] ?? ''))) as $s) {
                $labels[] = $sellers[(int) $s] ?? ('#' . $s);
            }
            $r['agents'] = $labels !== [] ? implode(' / ', $labels) : (($r['lead'] ?? '') !== '' ? ($r['lead'] === 'help' ? 'Help desk' : 'Enquiry') : 'Online / office');
            $r['intl'] = ($r['cc'] === '977' ? '977' : '91') . $ph;
            $r['optout'] = isset($optout[$ph]);
            $r['staff'] = $staff[$ph] ?? '';
        }
        unset($r);
    }

    /**
     * "Whose customers, how many" — per seller for the same filters (agent filter ignored).
     *
     * @return list<array{key:string,label:string,customers:int,bookings:int}>
     */
    public static function agentBreakdown(array $f): array
    {
        [$from, $p] = self::base($f, true);
        $rows = Database::fetchAll(
            "SELECT x.seller, CASE WHEN x.seller IS NULL AND x.source IN ('web','app') THEN 'online'
                                   WHEN x.seller IS NULL THEN 'office' ELSE x.seller END AS k,
                    COUNT(DISTINCT x.ph) AS customers, COUNT(DISTINCT x.bid) AS bookings
               FROM $from GROUP BY k ORDER BY customers DESC LIMIT 200",
            $p
        );
        $ids = array_filter(array_map(static fn ($r) => (int) ($r['seller'] ?? 0), $rows));
        $who = [];
        if ($ids !== []) {
            foreach (Database::fetchAll('SELECT id, full_name, username, role FROM admins WHERE id IN (' . implode(',', $ids) . ')') as $a) {
                $who[(int) $a['id']] = CustomerBook::sellerLabel(['sold_by_admin_id' => $a['id'], 'seller_name' => $a['full_name'], 'seller_user' => $a['username'], 'seller_role' => $a['role']]);
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $k = (string) $r['k'];
            $out[] = [
                'key'       => $k,
                'label'     => $k === 'online' ? 'Online (customer booked)' : ($k === 'office' ? 'Office / counter (no seller)' : ($who[(int) $k] ?? '#' . $k)),
                'customers' => (int) $r['customers'],
                'bookings'  => (int) $r['bookings'],
            ];
        }
        return $out;
    }

    /**
     * Every number for a campaign or an export.
     * $forMarketing drops opted-out, placeholder and staff numbers.
     *
     * @return list<array{ph:string,intl:string,name:string,trips:int}>
     */
    public static function phones(array $f, bool $forMarketing = true, int $max = 20000): array
    {
        $all = [];
        $offset = 0;
        do {
            $page = self::rows($f, 1000, $offset);
            foreach ($page['rows'] as $r) {
                $all[] = $r;
            }
            $offset += 1000;
        } while ($offset < $page['total'] && $offset < $max);
        $out = [];
        $seen = [];
        foreach ($all as $r) {
            $ph = (string) $r['ph'];
            if (isset($seen[$ph]) || strlen($ph) !== 10) {
                continue;
            }
            $seen[$ph] = true;
            if ($forMarketing && ($r['optout'] || $r['staff'] !== '' || CustomerBook::nonCustomerReason($ph) !== '')) {
                continue;
            }
            $out[] = ['ph' => $ph, 'intl' => (string) $r['intl'], 'name' => (string) $r['name'], 'trips' => (int) $r['trips']];
        }
        if ($forMarketing) {
            $out = array_values(array_filter($out, static fn ($r) => !self::isPlaceholder($r['ph'])));
        }
        return array_slice($out, 0, $max);
    }

    private static function isPlaceholder(string $ph): bool
    {
        static $set = null;
        if ($set === null) {
            $set = [];
            try {
                foreach (Database::fetchAll('SELECT phone FROM user_profiles WHERE is_placeholder = 1') as $r) {
                    $set[substr(preg_replace('/\D/', '', (string) $r['phone']) ?? '', -10)] = true;
                }
            } catch (Throwable $ignored) {
            }
        }
        return isset($set[$ph]);
    }

    /**
     * Numbers that said STOP (WhatsApp) or were blocked by the office.
     *
     * @param list<string> $phones 10-digit
     * @return array<string,true>
     */
    public static function optedOut(array $phones = []): array
    {
        $out = [];
        try {
            foreach (Database::fetchAll('SELECT phone FROM mk_optout') as $r) {
                $out[substr(preg_replace('/\D/', '', (string) $r['phone']) ?? '', -10)] = true;
            }
        } catch (Throwable $ignored) {
        }
        try {
            foreach (Database::fetchAll("SELECT phone FROM wa_marketing_consents WHERE state = 'opted_out'") as $r) {
                $out[substr(preg_replace('/\D/', '', (string) $r['phone']) ?? '', -10)] = true;
            }
        } catch (Throwable $ignored) {
        }
        return $out;
    }

    /** vCard 3.0 file for a phone's contacts app (WhatsApp broadcast lists need saved contacts). */
    public static function vcards(array $list, string $prefix = 'SHG'): string
    {
        $out = '';
        foreach ($list as $r) {
            $name = trim((string) $r['name']) !== '' ? trim((string) $r['name']) : 'Customer ' . substr((string) $r['ph'], -4);
            $name = str_replace([';', ',', "\n", "\r"], ' ', $prefix . ' ' . $name);
            $out .= "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:{$name}\r\nN:{$name};;;;\r\nTEL;TYPE=CELL:+" . $r['intl'] . "\r\nEND:VCARD\r\n";
        }
        return $out;
    }
}
