<?php
/**
 * =====================================================================
 *  Parcels — a SMALL parcel record per bus (25 Nov 2026, op5 item 5b).
 *
 *  The owner cancelled the pickup-to-parcel LIST on 20 Nov; this is only the
 *  desk's own register: who sends what to whom on which bus, how much, paid
 *  now or to pay at delivery, and whether it is loaded / delivered.
 *
 *  - admin/parcels.php (counter / office: bookings.edit) is the only caller.
 *  - No public page, no WhatsApp / SMS, nothing marked paid by itself: "paid"
 *    or "to pay" is what the clerk chose when booking it.
 *  - Every change is Logger::audit'ed ('parcel.add', 'parcel.status').
 *  - Switch parcel_book_on (default on). Without the table
 *    (database/upgrade-2026-11-25-op5-parcels.sql) every read is [] and the
 *    page says the migration is waiting.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Parcels
{
    public const STATUSES = [
        'booked'    => ['बुक भयो', 'Booked'],
        'loaded'    => ['बसमा राखियो', 'Loaded'],
        'delivered' => ['बुझाइयो', 'Delivered'],
        'cancelled' => ['रद्द', 'Cancelled'],
    ];
    public const PAY = [
        'paid'   => ['तिरिसक्यो', 'Paid'],
        'to_pay' => ['बुझ्नेले तिर्ने', 'To pay'],
    ];
    public const CURRENCIES = ['INR' => '₹', 'NPR' => 'रू'];

    /** Allowed moves: booked -> loaded -> delivered; anything not delivered may be cancelled. */
    private const NEXT = [
        'booked'    => ['loaded', 'delivered', 'cancelled'],
        'loaded'    => ['delivered', 'cancelled', 'booked'],
        'delivered' => [],
        'cancelled' => [],
    ];

    private static ?bool $has = null;

    public static function hasTable(): bool
    {
        if (self::$has === null) {
            try {
                self::$has = Database::exists(
                    'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
                    ['t' => 'parcels']
                );
            } catch (Throwable) {
                self::$has = false;
            }
        }
        return self::$has;
    }

    /** For tests: forget the table probe. */
    public static function resetCache(): void
    {
        self::$has = null;
    }

    public static function enabled(): bool
    {
        return Settings::getBool('parcel_book_on', true) && self::hasTable();
    }

    /** "P-20261125-0042" - printed on the slip. */
    public static function number(array $p): string
    {
        $d = str_replace('-', '', (string) ($p['travel_date'] ?? ''));
        return 'P-' . ($d !== '' ? $d . '-' : '') . str_pad((string) (int) ($p['id'] ?? 0), 4, '0', STR_PAD_LEFT);
    }

    /** A sender / receiver mobile: 10 digits (India 6-9, Nepal 96-98), +91 / +977 allowed. '' when invalid. */
    public static function phone(string $raw): string
    {
        $d = (string) preg_replace('/\D/', '', $raw);
        if (strlen($d) === 13 && str_starts_with($d, '977')) {
            $d = substr($d, 3);
        } elseif (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 11 && $d[0] === '0') {
            $d = substr($d, 1);
        }
        return preg_match('/^[6-9]\d{9}$/', $d) === 1 ? $d : '';
    }

    /**
     * tel: link for a stored 10-digit number, as typed: 98... is a mobile in
     * both India and Nepal, so no country code is guessed (the desk phone
     * dials it in its own country).
     */
    public static function telHref(string $digits): string
    {
        return preg_match('/^\d{10}$/', $digits) === 1 ? 'tel:' . $digits : '';
    }

    /**
     * The departures of a date, for the bus picker.
     *
     * @return list<array{id:int, label:string, bus:string}>
     */
    public static function departures(string $date): array
    {
        if (!Security::isValidDate($date)) {
            return [];
        }
        $rows = Database::fetchAll(
            "SELECT s.id, s.slot, r.from_city, r.to_city, COALESCE(s.dep_time_override, r.dep_time) AS dep, bu.bus_number
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
               LEFT JOIN buses bu ON bu.id = COALESCE(s.bus_id, r.bus_id)
              WHERE s.travel_date = :d AND s.status <> 'cancelled' AND r.is_active = 1
              ORDER BY dep, s.slot, r.id",
            ['d' => $date]
        );
        $out = [];
        foreach ($rows as $r) {
            $bus = trim((string) ($r['bus_number'] ?? ''));
            $out[] = [
                'id'    => (int) $r['id'],
                'label' => trim((string) $r['from_city']) . ' → ' . trim((string) $r['to_city'])
                    . ($r['dep'] ? ' · ' . substr((string) $r['dep'], 0, 5) : '')
                    . ((int) $r['slot'] > 1 ? ' · #' . (int) $r['slot'] : '')
                    . ($bus !== '' ? ' · ' . $bus : ''),
                'bus'   => $bus,
            ];
        }
        return $out;
    }

    /**
     * Book one parcel. Throws RuntimeException (Nepali · English) on a bad field.
     *
     * @param array<string,mixed> $in
     */
    public static function add(array $in, int $adminId): int
    {
        if (!self::enabled()) {
            throw new RuntimeException('पार्सल बन्द छ · Parcels are switched off (or the migration has not run).');
        }
        $date = (string) ($in['travel_date'] ?? '');
        if (!Security::isValidDate($date)) {
            throw new RuntimeException('मिति मिलेन · Choose the travel date.');
        }
        $txt = static fn(string $k, int $n): string => trim(Security::clean((string) ($in[$k] ?? ''), $n));
        $from = $txt('from_place', 120);
        $to   = $txt('to_place', 120);
        $sn   = $txt('sender_name', 120);
        $rn   = $txt('receiver_name', 120);
        $sp   = self::phone((string) ($in['sender_phone'] ?? ''));
        $rp   = self::phone((string) ($in['receiver_phone'] ?? ''));
        $items = $txt('items', 255);
        if ($from === '' || $to === '') {
            throw new RuntimeException('कहाँबाट / कहाँसम्म लेख्नुहोस् · Enter from and to.');
        }
        if ($sn === '' || $rn === '') {
            throw new RuntimeException('पठाउने र बुझ्ने दुवैको नाम लेख्नुहोस् · Enter the sender and receiver names.');
        }
        if ($sp === '' || $rp === '') {
            throw new RuntimeException('मोबाइल १० अङ्कको हुनुपर्छ · Sender and receiver need a valid 10-digit mobile.');
        }
        if ($items === '') {
            throw new RuntimeException('के सामान हो लेख्नुहोस् · Describe the items.');
        }
        $pieces = (int) ($in['pieces'] ?? 1);
        if ($pieces < 1 || $pieces > 500) {
            throw new RuntimeException('पिस १ देखि ५०० सम्म · Pieces must be 1-500.');
        }
        $wRaw = trim((string) ($in['weight_kg'] ?? ''));
        $weight = null;
        if ($wRaw !== '') {
            if (!is_numeric($wRaw) || (float) $wRaw <= 0 || (float) $wRaw > 5000) {
                throw new RuntimeException('तौल मिलेन · Weight must be a number of kg (or blank).');
            }
            $weight = round((float) $wRaw, 2);
        }
        $aRaw = trim((string) ($in['amount'] ?? '0'));
        if ($aRaw === '' ) {
            $aRaw = '0';
        }
        if (!is_numeric($aRaw) || (float) $aRaw < 0 || (float) $aRaw > 1000000) {
            throw new RuntimeException('रकम मिलेन · Amount must be 0 or more.');
        }
        $cur = strtoupper((string) ($in['currency'] ?? 'INR'));
        $cur = isset(self::CURRENCIES[$cur]) ? $cur : 'INR';
        $pay = (string) ($in['pay'] ?? '');
        if (!isset(self::PAY[$pay])) {
            throw new RuntimeException('तिरिसक्यो कि बुझ्नेले तिर्ने छान्नुहोस् · Choose Paid or To pay.');
        }

        $sid = (int) ($in['schedule_id'] ?? 0);
        $bus = $txt('bus_label', 60);
        if ($sid > 0) {
            $dep = null;
            foreach (self::departures($date) as $d) {
                if ($d['id'] === $sid) { $dep = $d; break; }
            }
            if ($dep === null) {
                throw new RuntimeException('त्यो बस यो मितिमा छैन · That bus does not run on this date.');
            }
            if ($bus === '') {
                $bus = $dep['bus'];
            }
        }

        $row = [
            'travel_date'    => $date,
            'schedule_id'    => $sid > 0 ? $sid : null,
            'bus_label'      => $bus !== '' ? $bus : null,
            'from_place'     => $from,
            'to_place'       => $to,
            'sender_name'    => $sn,
            'sender_phone'   => $sp,
            'receiver_name'  => $rn,
            'receiver_phone' => $rp,
            'items'          => $items,
            'pieces'         => $pieces,
            'weight_kg'      => $weight,
            'amount'         => round((float) $aRaw, 2),
            'currency'       => $cur,
            'pay'            => $pay,
            'status'         => 'booked',
            'booked_by'      => $adminId > 0 ? $adminId : null,
            'note'           => $txt('note', 255) ?: null,
        ];
        $id = (int) Database::insert('parcels', $row);
        Logger::audit('parcel.add', 'parcel', (string) $id, null, $row, 'Parcel booked ' . self::number(['id' => $id, 'travel_date' => $date]));
        return $id;
    }

    public static function get(int $id): ?array
    {
        if ($id <= 0 || !self::hasTable()) {
            return null;
        }
        return Database::fetch(
            'SELECT p.*, a.full_name AS booked_by_name FROM parcels p LEFT JOIN admins a ON a.id = p.booked_by WHERE p.id = :i',
            ['i' => $id]
        );
    }

    /**
     * Move one parcel to loaded / delivered / cancelled (or back to booked from
     * loaded). The pay column is never touched here.
     */
    public static function setStatus(int $id, string $status, int $adminId): array
    {
        if (!self::enabled()) {
            throw new RuntimeException('पार्सल बन्द छ · Parcels are switched off.');
        }
        if (!isset(self::STATUSES[$status])) {
            throw new RuntimeException('Unknown parcel status.');
        }
        $p = self::get($id);
        if ($p === null) {
            throw new RuntimeException('पार्सल भेटिएन · Parcel not found.');
        }
        $from = (string) $p['status'];
        if ($from === $status) {
            return $p;
        }
        if (!in_array($status, self::NEXT[$from] ?? [], true)) {
            throw new RuntimeException('यो पार्सल ' . self::STATUSES[$from][0] . ' छ — ' . self::STATUSES[$status][1] . ' गर्न मिल्दैन · cannot move from ' . $from . ' to ' . $status . '.');
        }
        $set = ['status' => $status];
        if ($status === 'loaded') {
            $set['loaded_at'] = date('Y-m-d H:i:s');
        } elseif ($status === 'delivered') {
            $set['delivered_at'] = date('Y-m-d H:i:s');
            if ($p['loaded_at'] === null) {
                $set['loaded_at'] = $set['delivered_at'];
            }
        } elseif ($status === 'booked') {
            $set['loaded_at'] = null;
        }
        Database::update('parcels', $set, 'id = :i AND status = :s', ['i' => $id, 's' => $from]);
        Logger::audit('parcel.status', 'parcel', (string) $id, ['status' => $from], ['status' => $status, 'by' => $adminId],
            'Parcel ' . self::number($p) . ': ' . $from . ' -> ' . $status);
        return self::get($id) ?? $p;
    }

    /**
     * The parcels of a date (optionally one bus, one pay kind), newest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function listFor(string $date, int $scheduleId = 0, string $pay = ''): array
    {
        if (!self::hasTable() || !Security::isValidDate($date)) {
            return [];
        }
        $where  = ['p.travel_date = :d'];
        $params = ['d' => $date];
        if ($scheduleId > 0) {
            $where[] = 'p.schedule_id = :s';
            $params['s'] = $scheduleId;
        }
        if (isset(self::PAY[$pay])) {
            $where[] = 'p.pay = :p';
            $params['p'] = $pay;
        }
        return Database::fetchAll(
            'SELECT p.*, a.full_name AS booked_by_name FROM parcels p LEFT JOIN admins a ON a.id = p.booked_by
              WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT 500',
            $params
        );
    }

    /**
     * Totals of a list, cancelled parcels left out. Money is per currency (INR
     * and NPR are never added together).
     *
     * @param list<array<string,mixed>> $rows
     * @return array{count:int, pieces:int, paid:array<string,float>, to_pay:array<string,float>, byStatus:array<string,int>}
     */
    public static function totals(array $rows): array
    {
        $t = ['count' => 0, 'pieces' => 0, 'paid' => [], 'to_pay' => [], 'byStatus' => array_fill_keys(array_keys(self::STATUSES), 0)];
        foreach ($rows as $r) {
            $st = (string) ($r['status'] ?? '');
            if (isset($t['byStatus'][$st])) {
                $t['byStatus'][$st]++;
            }
            if ($st === 'cancelled') {
                continue;
            }
            $t['count']++;
            $t['pieces'] += (int) ($r['pieces'] ?? 0);
            $k   = (string) ($r['pay'] ?? '') === 'paid' ? 'paid' : 'to_pay';
            $cur = (string) ($r['currency'] ?? 'INR');
            $t[$k][$cur] = round(($t[$k][$cur] ?? 0.0) + (float) ($r['amount'] ?? 0), 2);
        }
        ksort($t['paid']);
        ksort($t['to_pay']);   // INR before NPR, whatever order the rows came in
        return $t;
    }

    /** "₹ 1,200 · रू 500" for a totals bucket ('—' when empty). */
    public static function moneyLine(array $bucket): string
    {
        $parts = [];
        foreach ($bucket as $cur => $amt) {
            $parts[] = (self::CURRENCIES[$cur] ?? $cur) . ' ' . number_format((float) $amt, ((float) $amt == floor((float) $amt)) ? 0 : 2);
        }
        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}
