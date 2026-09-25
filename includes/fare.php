<?php
/**
 * =====================================================================
 *  Fare engine
 *
 *  A line-for-line port of the pricing logic from the original
 *  single-file build (calcCabinFare, calcFare, refund slabs, loyalty
 *  tiers, referral commission). Prices computed here are authoritative:
 *  the browser may display a total, but only this file decides what a
 *  passenger is actually charged.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Fare
{
    /**
     * Cabin pricing table, loaded from settings with the original
     * CONFIG.cabinPricing values as the fallback.
     *
     * @return array<string, mixed>
     */
    public static function pricing(): array
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $cached = Settings::getArray('cabin_pricing', [
            'onlineDiscountPct' => 0,   // 5% online discount REMOVED 26 Aug 2026 — one flat fare
            // Sharing per-person base fare (offline) by direction. This is
            // only the FALLBACK: the live `cabin_pricing` settings row wins,
            // and these values mirror it. Owner-confirmed 25 Aug 2026:
            // Gujarat->Rupaidiha (toNepal) 2000, Rupaidiha->Gujarat (toIndia)
            // 1800. Editable from Admin -> Settings (cabin_pricing.sharingByDir),
            // or from the app's Main-point fares box, which writes the
            // 'main_fares' setting read by dirFares() below.
            'sharingByDir' => ['toNepal' => 2000, 'toIndia' => 1800],
            'sharing' => [
                'single_2pax' => ['capacity' => 2, 'offline' => 4400, 'online' => 4180, 'perPerson' => 2090,
                                  'label' => 'Single Sleeper (Sharing)', 'cabinType' => 'single', 'emoji' => '🛏️'],
                'double_3pax' => ['capacity' => 3, 'offline' => 7500, 'online' => 7125, 'perPerson' => 2375,
                                  'label' => 'Double Sleeper (Sharing) · 3P', 'cabinType' => 'double', 'emoji' => '🛏️🛏️'],
                'double_4pax' => ['capacity' => 4, 'offline' => 8800, 'online' => 8360, 'perPerson' => 2090,
                                  'label' => 'Double Sleeper (Sharing) · 4P', 'cabinType' => 'double', 'emoji' => '🛏️🛏️'],
            ],
            'private' => [
                'single_1pax' => ['capacity' => 1, 'offline' => 3800, 'online' => 3800,
                                  'label' => 'Single Sleeper (Private)', 'cabinType' => 'single', 'emoji' => '🔒🛏️'],
                'double_2pax' => ['capacity' => 2, 'offline' => 7600, 'online' => 7600,
                                  'label' => 'Double Sleeper (Private)', 'cabinType' => 'double', 'emoji' => '🔒🛏️🛏️'],
            ],
        ]);

        return $cached;
    }

    /**
     * The towns on each side of the border. The browser keeps the same list
     * in CONFIG.mainPoints; this copy is the one that decides money, because
     * a client can send any city name it likes.
     *
     * @return array{india: string[], nepal: string[]}
     */
    public static function mainPoints(): array
    {
        return Settings::getArray('main_points', [
            'india' => ['Surat', 'Kamrej', 'Ankleshwar', 'Bharuch', 'Vadodara', 'Anand', 'Nadiad', 'Emli Bhupal', 'S Hari Parking, Nana Chiloda'],
            // Rupaidiha is the ONLY Nepal-side point the company may sell today: the service is licensed to the India-side border and no further. Nepalgunj, Kohalpur and Lumbini Pradesh are a FUTURE extension — listing them here put them on the public fare board and in the search boxes as if they were bookable. Add them back the day the permit exists.
            'nepal' => ['Rupaidiha'],
        ]);
    }

    /**
     * True when the destination is on the Nepal side, i.e. this is the
     * outbound leg. Replaces the old `$toCity === 'Ahmedabad'` test, which
     * quietly mispriced every Gujarat town except Ahmedabad the moment more
     * than one became bookable.
     */
    public static function isNepalPoint(?string $city): bool
    {
        $nepal = self::mainPoints()['nepal'] ?? [];
        return in_array((string) $city, $nepal, true);
    }

    /**
     * Live directional fares. The operator's 'main_fares' setting (written
     * by Admin -> Settings -> Main-point fares) wins over the packaged
     * cabin_pricing defaults, so a price change needs no deploy.
     *
     * @return array{toNepal: float, toIndia: float}
     */
    public static function dirFares(): array
    {
        // Owner-confirmed launch rates (25 Aug 2026): Gujarat->Rupaidiha
        // (toNepal, "jane") 2000, Rupaidiha->Gujarat (toIndia, "aune") 1800.
        // 3 Sep 2026: the owner now edits these from Admin -> Settings ->
        // "Fares & booking rules" (settings rows fare_to_nepal / fare_to_india,
        // seeded by database/upgrade-2026-09-fares.php). The code figures stay
        // as the fallback when a row is missing or zero. cabin_pricing.sharingByDir
        // is still deliberately ignored (the live DB held the reversed pair).
        // The legacy "Main-point fares" override (main_fares) still wins below;
        // settings.php keeps it in step whenever the fares panel is saved.
        $ftn  = Settings::getFloat('fare_to_nepal', 0.0);
        $fti  = Settings::getFloat('fare_to_india', 0.0);
        $base = ['toNepal' => $ftn > 0 ? $ftn : 2000.0, 'toIndia' => $fti > 0 ? $fti : 1800.0];
        $over = Settings::getArray('main_fares', []);

        $pick = static function ($o, $b): float {
            $v = is_numeric($o) ? (float) $o : (float) $b;
            return $v > 0 ? round($v) : (float) $b;
        };

        return [
            'toNepal' => $pick($over['toNepal'] ?? null, $base['toNepal'] ?? 2000),
            'toIndia' => $pick($over['toIndia'] ?? null, $base['toIndia'] ?? 1800),
        ];
    }

    /**
     * Cabin fare — the direct port of calcCabinFare().
     *
     * @param string $cabinType   'single' | 'double'
     * @param string $bookingType 'sharing' | 'private'
     * @param int    $passengers  head count
     * @param bool   $isOnline    true for prepaid web bookings (discounted)
     *
     * @return array{base: float, total: float, saved: float, perPerson: float,
     *               label: string, emoji: string, cabins: int}
     */
    /**
     * $stopCount: number of Gujarat-side stops actually touched on this
     * booking (boarding + drop combined, from 1 to 4). Only relevant for
     * sharing service — a passenger who only touches 1–2 of the 4 stops
     * gets a "short run" discount off the base fare (business rule set
     * in Admin → Settings → sharing_short_run_*).
     */
    public static function cabinFare(string $cabinType, string $bookingType, int $passengers, bool $isOnline = true, ?string $toCity = null, int $stopCount = 4): array
    {
        $passengers = max(1, $passengers);
        $pricing    = self::pricing();

        $empty = [
            'base' => 0.0, 'total' => 0.0, 'saved' => 0.0, 'perPerson' => 0.0,
            'label' => '', 'emoji' => '', 'cabins' => 0,
        ];

        if ($bookingType === 'private') {
            $key = $cabinType === 'single' ? 'single_1pax' : 'double_2pax';
            $p   = $pricing['private'][$key] ?? null;

            if ($p === null) {
                return $empty;
            }

            $capacity = max(1, (int) $p['capacity']);
            $cabins   = (int) ceil($passengers / $capacity);

            // Flat pricing (26 Aug 2026): the 5% online discount is removed —
            // a private cabin is charged the single published rate online and
            // offline alike, so no "you saved" line ever appears.
            $total       = $cabins * (float) $p['offline'];
            $offlineCost = $cabins * (float) $p['offline'];

            return [
                'base'      => $offlineCost,
                'total'     => $total,
                'saved'     => $offlineCost - $total,
                'perPerson' => round($total / $passengers),
                'label'     => (string) $p['label'] . ($cabins > 1 ? ' x' . $cabins : ''),
                'emoji'     => (string) ($p['emoji'] ?? ''),
                'cabins'    => $cabins,
            ];
        }

        if ($bookingType === 'sharing') {
            if ($cabinType === 'single') {
                $key = 'single_2pax';
            } elseif ($passengers === 3) {
                $key = 'double_3pax';
            } else {
                $key = 'double_4pax';
            }

            $p = $pricing['sharing'][$key] ?? null;

            if ($p === null) {
                return $empty;
            }

            // Per-person is a flat, direction-based fare — 2000 towards
            // Nepal (Gujarat->Rupaidiha) / 1800 towards India (return) — the
            // same across every sharing tier. Owner decision 26 Aug 2026: the
            // 5% online discount is REMOVED, so the fare is identical online and
            // offline. A "short run" that only uses 1-2 of the 4 Gujarat stops
            // (e.g. Surat -> border only) still drops 200 off the base -- set
            // in Admin -> Settings.
            $dir      = self::dirFares();
            $base     = self::isNepalPoint($toCity) ? $dir['toNepal'] : $dir['toIndia'];

            $minFullStops = Settings::getInt('sharing_short_run_min_stops', 3);
            $shortCut     = Settings::getFloat('sharing_short_run_discount_inr', 200.0);
            if ($stopCount > 0 && $stopCount < $minFullStops && $shortCut > 0) {
                $base = max(0.0, $base - $shortCut);
            }

            // Flat fare: no online discount. Hardcoded to 0 (not read from the
            // cabin_pricing setting) for the same reason dirFares() hardcodes
            // the rate — the live production row may still carry the old 5, and
            // the flat price must ship in code. $isOnline no longer changes the
            // per-person charge.
            $perPerson   = $base;
            $total       = $perPerson * $passengers;
            $offlineCost = $base * $passengers;

            return [
                'base'      => $offlineCost,
                'total'     => $total,
                'saved'     => $offlineCost - $total,
                'perPerson' => $perPerson,
                'label'     => (string) $p['label'],
                'emoji'     => (string) ($p['emoji'] ?? ''),
                'cabins'    => 1,
            ];
        }

        return $empty;
    }

    /**
     * Seater / simple per-seat pricing, plus the group discount.
     *
     * @return array{base: float, groupDiscount: float, fee: float, total: float, perSeat: float}
     */
    public static function seatFare(float $farePerSeat, int $seatCount): array
    {
        $seatCount = max(1, $seatCount);
        $base      = $farePerSeat * $seatCount;

        $minSeats  = Settings::getInt('group_discount_min_seats', 5);
        $percent   = Settings::getFloat('group_discount_percent', 5.0);
        $groupCut  = ($minSeats > 0 && $seatCount >= $minSeats)
            ? round($base * $percent / 100)
            : 0.0;

        $fee = Settings::getFloat('booking_fee', 0.0);

        return [
            'base'          => $base,
            'groupDiscount' => $groupCut,
            'fee'           => $fee,
            'total'         => max(0.0, $base - $groupCut + $fee),
            'perSeat'       => $farePerSeat,
        ];
    }

    /**
     * Loyalty tier for a lifetime points balance.
     *
     * @return array{name: string, min: int, discountPct: float, icon: string}
     */
    public static function tierForPoints(int $lifetimePoints): array
    {
        $tiers = Settings::getArray('loyalty_tiers', [
            ['name' => 'Silver',   'min' => 0,    'discountPct' => 0, 'icon' => '🥈'],
            ['name' => 'Gold',     'min' => 300,  'discountPct' => 2, 'icon' => '🥇'],
            ['name' => 'Platinum', 'min' => 900,  'discountPct' => 3, 'icon' => '⭐'],
            ['name' => 'Diamond',  'min' => 2000, 'discountPct' => 5, 'icon' => '💎'],
        ]);

        $current = ['name' => 'Silver', 'min' => 0, 'discountPct' => 0.0, 'icon' => '🥈'];

        foreach ($tiers as $tier) {
            if ($lifetimePoints >= (int) ($tier['min'] ?? 0)) {
                $current = [
                    'name'        => (string) ($tier['name'] ?? 'Silver'),
                    'min'         => (int) ($tier['min'] ?? 0),
                    'discountPct' => (float) ($tier['discountPct'] ?? 0),
                    'icon'        => (string) ($tier['icon'] ?? ''),
                ];
            }
        }

        return $current;
    }

    /**
     * Tier discount in rupees for a given subtotal.
     *
     * @return array{amount: float, tierName: string, pct: float}
     */
    public static function tierDiscount(float $subtotal, int $lifetimePoints): array
    {
        $tier = self::tierForPoints($lifetimePoints);
        $pct  = $tier['discountPct'];

        return [
            'amount'   => $pct > 0 ? round($subtotal * $pct / 100) : 0.0,
            'tierName' => $tier['name'],
            'pct'      => $pct,
        ];
    }

    /**
     * Convert loyalty points into a rupee discount.
     * Points are only redeemable in whole blocks (default 100 pts = ₹10).
     *
     * @return array{points: int, value: float}
     */
    public static function pointsRedemption(int $pointsRequested, int $pointsAvailable, float $capAmount): array
    {
        $blockPoints = max(1, Settings::getInt('loyalty_redeem_points', 100));
        $blockValue  = Settings::getFloat('loyalty_redeem_rupees', 10.0);

        $usable = min(max(0, $pointsRequested), max(0, $pointsAvailable));
        $blocks = intdiv($usable, $blockPoints);

        if ($blocks < 1) {
            return ['points' => 0, 'value' => 0.0];
        }

        $value = $blocks * $blockValue;

        // Never let points push the payable total below zero.
        if ($value > $capAmount) {
            $blocks = (int) floor($capAmount / $blockValue);
            $value  = $blocks * $blockValue;
        }

        return [
            'points' => $blocks * $blockPoints,
            'value'  => max(0.0, $value),
        ];
    }

    /**
     * Points earned by a confirmed booking.
     */
    public static function pointsEarned(float $confirmedAmount): int
    {
        $per100 = Settings::getInt('loyalty_points_per_100', 1);
        return (int) floor($confirmedAmount / 100) * $per100;
    }

    /**
     * Validate a coupon and work out its discount.
     *
     * @return array{ok: bool, error?: string, amount?: float, coupon?: array<string, mixed>}
     */
    /**
     * The running offer, applied without anyone typing a code (20 Sep 2026).
     *
     * A Dashain/Tihar offer has to reach every passenger, not only the ones
     * who know a code. Rows flagged auto_apply are considered here and put
     * through the SAME couponDiscount() validation as a typed code, so
     * dates, per-route limits, minimum amount, usage caps and the max
     * discount ceiling all behave identically. When several qualify the
     * passenger gets the biggest one.
     *
     * @return array{ok: bool, amount?: float, code?: string, title?: string}
     */
    public static function autoOffer(float $subtotal, string $phone, ?int $routeId = null): array
    {
        $today = todayISO();
        $rows  = Database::fetchAll(
            "SELECT code, title FROM coupons
              WHERE is_active = 1 AND auto_apply = 1
                AND (valid_from  IS NULL OR valid_from  <= :d1)
                AND (valid_until IS NULL OR valid_until >= :d2)
                AND (route_id IS NULL OR route_id = :r)
              ORDER BY id DESC
              LIMIT 20",
            ['d1' => $today, 'd2' => $today, 'r' => $routeId]
        );

        $best = ['ok' => false];
        foreach ($rows as $row) {
            $try = self::couponDiscount((string) $row['code'], $subtotal, $phone, $routeId);
            if (!empty($try['ok']) && (float) $try['amount'] > (float) ($best['amount'] ?? 0)) {
                $best = [
                    'ok'     => true,
                    'amount' => (float) $try['amount'],
                    'code'   => (string) $row['code'],
                    'title'  => trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : (string) $row['code'],
                ];
            }
        }
        return $best;
    }

    public static function couponDiscount(string $code, float $subtotal, string $phone, ?int $routeId = null): array
    {
        $code = strtoupper(Security::clean($code, 40));

        if ($code === '') {
            return ['ok' => false, 'error' => 'Enter a coupon code.'];
        }

        $coupon = Database::fetch(
            'SELECT * FROM coupons WHERE code = :c AND is_active = 1 LIMIT 1',
            ['c' => $code]
        );

        if ($coupon === null) {
            return ['ok' => false, 'error' => 'That coupon code is not valid.'];
        }

        $today = todayISO();

        if (!empty($coupon['valid_from']) && $coupon['valid_from'] > $today) {
            return ['ok' => false, 'error' => 'This coupon is not active yet.'];
        }
        if (!empty($coupon['valid_until']) && $coupon['valid_until'] < $today) {
            return ['ok' => false, 'error' => 'This coupon has expired.'];
        }
        if ($coupon['route_id'] !== null && $routeId !== null && (int) $coupon['route_id'] !== $routeId) {
            return ['ok' => false, 'error' => 'This coupon does not apply to the selected route.'];
        }
        if ($subtotal < (float) $coupon['min_amount']) {
            return ['ok' => false, 'error' => 'This coupon needs a minimum booking of ' . inr((float) $coupon['min_amount']) . '.'];
        }
        if ($coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
            return ['ok' => false, 'error' => 'This coupon has been fully redeemed.'];
        }

        $perUser = (int) $coupon['per_user_limit'];
        if ($perUser > 0 && $phone !== '') {
            $used = (int) Database::scalar(
                'SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = :cid AND user_phone = :p',
                ['cid' => $coupon['id'], 'p' => $phone],
                0
            );
            if ($used >= $perUser) {
                return ['ok' => false, 'error' => 'You have already used this coupon.'];
            }
        }

        $amount = $coupon['discount_type'] === 'percent'
            ? round($subtotal * (float) $coupon['discount_value'] / 100)
            : (float) $coupon['discount_value'];

        if ($coupon['max_discount'] !== null) {
            $amount = min($amount, (float) $coupon['max_discount']);
        }

        $amount = min($amount, $subtotal);

        return ['ok' => true, 'amount' => $amount, 'coupon' => $coupon];
    }

    /**
     * The published refund slabs, highest threshold first so the most
     * generous matching slab wins. One source for refundFor() and for the
     * ladder the app shows next to the Pay button (Trust::ladder()).
     *
     * @return list<array{minHrs:int|float,pct:int|float}>
     */
    public static function refundSlabs(): array
    {
        $slabs = Settings::getArray('refund_slabs', [
            ['minHrs' => 96, 'pct' => 90],
            ['minHrs' => 48, 'pct' => 75],
            ['minHrs' => 24, 'pct' => 50],
            ['minHrs' => 6,  'pct' => 25],
            ['minHrs' => 0,  'pct' => 0],
        ]);
        $slabs = array_values(array_filter($slabs, static fn($s): bool => is_array($s) && isset($s['minHrs'], $s['pct'])));
        usort($slabs, static fn(array $a, array $b): int => (int) $b['minHrs'] <=> (int) $a['minHrs']);
        return $slabs;
    }

    /**
     * Refund due on cancellation, using the published slabs.
     *
     * @return array{amount: float, percent: float, hoursLeft: float, reason: string}
     */
    public static function refundFor(float $amountPaid, string $travelDate, string $departureTime = '00:00:00'): array
    {
        $hoursLeft = hoursUntil($travelDate, $departureTime);

        // 5 Sep 2026: matched to the published Terms (section C) — the T&C
        // page has promised five tiers since launch, but the enforced slabs
        // still had the older three. Terms are the contract; code follows.
        $slabs = self::refundSlabs();

        $percent = 0.0;
        $reason  = 'Cancelled after the free-cancellation window.';

        foreach ($slabs as $slab) {
            if ($hoursLeft >= (float) $slab['minHrs']) {
                $percent = (float) $slab['pct'];
                $reason  = sprintf(
                    'Cancelled %s hours before departure — %s%% refundable.',
                    number_format(max(0, $hoursLeft), 0),
                    number_format($percent, 0)
                );
                break;
            }
        }

        return [
            'amount'    => round($amountPaid * $percent / 100),
            'percent'   => $percent,
            'hoursLeft' => round($hoursLeft, 1),
            'reason'    => $reason,
        ];
    }

    /**
     * Referral commission for a confirmed booking — port of
     * computeReferralCommission(), including the self-referral guard
     * and the confirm-within-N-days window.
     *
     * @param array<string, mixed> $booking
     * @return array{amount: float, agent: array<string, mixed>|null, mode: string, value: float, withinWindow: bool}
     */
    public static function referralCommission(array $booking, int $seatCount): array
    {
        $none = ['amount' => 0.0, 'agent' => null, 'mode' => 'flat', 'value' => 0.0, 'withinWindow' => false];

        $code = strtoupper((string) ($booking['referral_code'] ?? ''));
        if ($code === '') {
            return $none;
        }

        $agent = Database::fetch(
            "SELECT * FROM users WHERE ref_code = :c AND role = 'agent' AND is_blocked = 0 LIMIT 1",
            ['c' => $code]
        );

        if ($agent === null) {
            return $none;
        }

        // An agent cannot earn commission on their own booking.
        if (normalisePhone((string) ($booking['contact_phone'] ?? '')) === (string) $agent['phone']) {
            return $none;
        }

        $mode    = Settings::getString('referral_mode', 'flat');
        $flat    = Settings::getFloat('referral_flat', 100.0);
        $percent = Settings::getFloat('referral_percent', 5.0);
        $window  = Settings::getInt('referral_window_days', 7);

        $amount = $mode === 'percent'
            ? round((float) ($booking['total_amount'] ?? 0) * $percent / 100)
            : $flat;

        $createdAt    = strtotime((string) ($booking['created_at'] ?? 'now'));
        $withinWindow = (time() - $createdAt) <= ($window * 86400);

        return [
            'amount'       => $withinWindow ? max(0.0, $amount) : 0.0,
            'agent'        => $agent,
            'mode'         => $mode,
            'value'        => $mode === 'percent' ? $percent : $flat,
            'withinWindow' => $withinWindow,
        ];
    }

    /**
     * Tax on a subtotal. Zero by default — set tax_percent in Admin →
     * Settings once GST registration is active.
     */
    public static function tax(float $subtotal): float
    {
        $percent = Settings::getFloat('tax_percent', 0.0);
        return $percent > 0 ? round($subtotal * $percent / 100, 2) : 0.0;
    }

    /**
     * Assemble a complete, authoritative quote for a booking request.
     *
     * Every discount is applied in a fixed order so the arithmetic is
     * reproducible: base -> group -> coupon -> tier -> points -> tax -> fee.
     *
     * @return array{
     *   base: float, groupDiscount: float, couponDiscount: float,
     *   tierDiscount: float, tierName: string, pointsUsed: int,
     *   pointsValue: float, tax: float, fee: float, total: float,
     *   breakdown: array<int, array{label: string, amount: float}>
     * }
     */
    public static function quote(
        float $baseAmount,
        int $seatCount,
        int $lifetimePoints = 0,
        int $pointsRequested = 0,
        int $pointsAvailable = 0,
        string $couponCode = '',
        string $phone = '',
        ?int $routeId = null
    ): array {
        $breakdown = [['label' => 'Base fare', 'amount' => $baseAmount]];

        // 1. Group discount
        $minSeats     = Settings::getInt('group_discount_min_seats', 5);
        $groupPct     = Settings::getFloat('group_discount_percent', 5.0);
        $groupCut     = ($minSeats > 0 && $seatCount >= $minSeats) ? round($baseAmount * $groupPct / 100) : 0.0;
        $running      = $baseAmount - $groupCut;

        if ($groupCut > 0) {
            $breakdown[] = ['label' => 'Group discount (' . $seatCount . ' seats)', 'amount' => -$groupCut];
        }

        // 2. Coupon — typed by the passenger, or the running offer
        $couponCut = 0.0;
        if ($couponCode !== '') {
            $couponResult = self::couponDiscount($couponCode, $running, $phone, $routeId);
            if ($couponResult['ok']) {
                $couponCut   = (float) $couponResult['amount'];
                $running    -= $couponCut;
                $breakdown[] = ['label' => 'Coupon ' . strtoupper($couponCode), 'amount' => -$couponCut];
            }
        } else {
            $auto = self::autoOffer($running, $phone, $routeId);
            if (!empty($auto['ok'])) {
                $couponCut   = (float) $auto['amount'];
                $running    -= $couponCut;
                $couponCode  = (string) $auto['code'];   // stored on the booking
                $breakdown[] = ['label' => (string) $auto['title'], 'amount' => -$couponCut];
            }
        }

        // 3. Loyalty tier
        $tier    = self::tierDiscount($running, $lifetimePoints);
        $tierCut = $tier['amount'];
        $running -= $tierCut;

        if ($tierCut > 0) {
            $breakdown[] = ['label' => $tier['tierName'] . ' member discount', 'amount' => -$tierCut];
        }

        // 4. Points
        $points     = self::pointsRedemption($pointsRequested, $pointsAvailable, $running);
        $pointsCut  = $points['value'];
        $running   -= $pointsCut;

        if ($pointsCut > 0) {
            $breakdown[] = ['label' => $points['points'] . ' loyalty points', 'amount' => -$pointsCut];
        }

        // 5. Tax and booking fee
        $tax = self::tax($running);
        if ($tax > 0) {
            $breakdown[] = ['label' => 'Taxes', 'amount' => $tax];
        }

        $fee = Settings::getFloat('booking_fee', 0.0);
        if ($fee > 0) {
            $breakdown[] = ['label' => 'Booking fee', 'amount' => $fee];
        }

        $total = max(0.0, round($running + $tax + $fee));

        return [
            'base'           => $baseAmount,
            'groupDiscount'  => $groupCut,
            'couponDiscount' => $couponCut,
            'couponCode'     => $couponCut > 0 ? strtoupper($couponCode) : '',
            'tierDiscount'   => $tierCut,
            'tierName'       => $tierCut > 0 ? $tier['tierName'] : '',
            'pointsUsed'     => $points['points'],
            'pointsValue'    => $pointsCut,
            'tax'            => $tax,
            'fee'            => $fee,
            'total'          => $total,
            'breakdown'      => $breakdown,
        ];
    }
}
