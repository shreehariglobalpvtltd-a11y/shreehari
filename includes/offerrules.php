<?php
/**
 * =====================================================================
 *  includes/offerrules.php — the rules an offer / coupon row must pass
 *  (16 Nov 2026, Master v2 unit Y2, P3-2f + P8-10e).
 *
 *  admin/offers.php (act=save) is where the office makes an offer by hand.
 *  S Hari AI ("coupon DIWALI10 10% max 200 till 30 Nov", AiOps type
 *  'coupon') makes one from a sentence. Both must hold an offer to the
 *  SAME rules, so the rules live here, copied from admin/offers.php:
 *
 *    - code: upper-case letters, digits, - or _ (3 to 40), unique;
 *    - the discount is more than zero; a percent is at most 100 AND
 *      carries a maximum discount (an unbounded percent on a group
 *      booking quietly gives away a whole ticket);
 *    - the end date is not before the start date (both YYYY-MM-DD);
 *    - a route, when given, exists;
 *    - max uses is 1 .. 1,000,000 (blank = no limit), and on an edit
 *      never below what is already used;
 *    - per-mobile limit 0 .. 999 (default 1), min hours ahead 0 .. 720.
 *
 *  validate() returns the clean row (only columns the coupons table
 *  really has — an older database without first_booking_only /
 *  min_hours_ahead still works) or throws InvalidArgumentException with a
 *  bilingual "नेपाली · English" message. Nothing is written here.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class OfferRules
{
    /** The highest per-mobile limit and booking-ahead hours admin/offers.php accepts. */
    public const PER_USER_MAX = 999;
    public const AHEAD_MAX    = 720;
    public const USES_MAX     = 1000000;

    /** @var array<string,true>|null coupons columns, read once per request */
    private static ?array $cols = null;

    /** @return array<string,true> the coupons table's columns */
    public static function columns(): array
    {
        if (self::$cols === null) {
            self::$cols = [];
            try {
                foreach (Database::fetchAll('SHOW COLUMNS FROM coupons') as $c) {
                    self::$cols[(string) $c['Field']] = true;
                }
            } catch (Throwable $e) {
                self::$cols = [];
            }
        }
        return self::$cols;
    }

    public static function hasColumn(string $col): bool
    {
        return isset(self::columns()[$col]);
    }

    /** Tests that add / drop a column call this. */
    public static function flush(): void
    {
        self::$cols = null;
    }

    /**
     * One offer, held to admin/offers.php's rules.
     *
     * $in keys (all optional except code / discount_value): id (an edit), code, title,
     * discount_type flat|percent, discount_value, max_discount (null / '' = none),
     * min_amount, valid_from, valid_until (YYYY-MM-DD or null / ''), auto_apply,
     * is_active, min_hours_ahead, route_id, usage_limit (null / '' = none),
     * per_user_limit ('' = 1), first_booking_only.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed> the row to write (code included; on an edit the stored code)
     * @throws InvalidArgumentException
     */
    public static function validate(array $in): array
    {
        $str  = static fn(string $k): string => is_scalar($in[$k] ?? null) ? trim((string) $in[$k]) : '';
        $id   = (int) ($in['id'] ?? 0);
        $code = strtoupper(Security::clean($in['code'] ?? '', 40));
        $title = Security::clean($in['title'] ?? '', 120);
        $type  = $str('discount_type') === 'percent' ? 'percent' : 'flat';
        $value = round((float) ($in['discount_value'] ?? 0), 2);
        $maxD  = $str('max_discount') !== '' ? round((float) $in['max_discount'], 2) : null;
        $minA  = round((float) ($in['min_amount'] ?? 0), 2);
        $from  = $str('valid_from') !== '' ? $str('valid_from') : null;
        $until = $str('valid_until') !== '' ? $str('valid_until') : null;
        $auto  = !empty($in['auto_apply']) ? 1 : 0;
        $live  = !empty($in['is_active']) ? 1 : 0;
        $ahead = max(0, min(self::AHEAD_MAX, (int) ($in['min_hours_ahead'] ?? 0)));
        $routeIn = (int) ($in['route_id'] ?? 0);
        $perUser = max(0, min(self::PER_USER_MAX, (int) ($str('per_user_limit') !== '' ? $str('per_user_limit') : '1')));
        $maxUse  = $str('usage_limit') !== '' ? (int) $str('usage_limit') : null;
        $firstOn = $str('first_booking_only') !== '' && $str('first_booking_only') !== '0';
        $oldRow  = $id > 0 ? Database::fetch('SELECT * FROM coupons WHERE id = :i', ['i' => $id]) : null;

        $bad = static function (string $msg): never {
            throw new InvalidArgumentException($msg);
        };
        if ($id <= 0 && ($code === '' || preg_match('/^[A-Z0-9_-]{3,40}$/', $code) !== 1)) {
            $bad('कोड: अक्षर, अंक, - वा _ (३ देखि ४०) · Give the offer a short code — letters, numbers, - or _ (3 to 40).');
        }
        if ($value <= 0) {
            $bad('छुट शून्यभन्दा बढी हुनुपर्छ · The discount must be more than zero.');
        }
        if ($type === 'percent' && $value > 100) {
            $bad('प्रतिशत छुट १००% भन्दा बढी हुँदैन · A percent discount cannot be over 100%.');
        }
        if ($type === 'percent' && $maxD === null) {
            $bad('प्रतिशत छुटमा बढीमा कति (max ₹) चाहिन्छ · A percent offer needs a maximum discount, so one big booking cannot run away with it.');
        }
        foreach (['valid_from' => $from, 'valid_until' => $until] as $d) {
            if ($d !== null && !Security::isValidDate($d)) {
                $bad('मिति मिलेन (YYYY-MM-DD) · The date is not a real date (YYYY-MM-DD).');
            }
        }
        if ($from !== null && $until !== null && $until < $from) {
            $bad('अन्त्य मिति सुरु मितिभन्दा अगाडि छ · The end date is before the start date.');
        }
        if ($id > 0 && $oldRow === null) {
            $bad('यो अफर भेटिएन · That offer no longer exists.');
        }
        if ($routeIn > 0 && !Database::exists('SELECT 1 FROM routes WHERE id = :r', ['r' => $routeIn])) {
            $bad('रुट भेटिएन · That route was not found.');
        }
        if ($maxUse !== null && ($maxUse < 1 || $maxUse > self::USES_MAX)) {
            $bad('बढीमा प्रयोग १ वा बढी, खाली = सीमा छैन · Max uses must be 1 or more — leave it blank for no limit.');
        }
        if ($maxUse !== null && $oldRow !== null && $maxUse < (int) $oldRow['used_count']) {
            $bad('पहिले नै ' . (int) $oldRow['used_count'] . ' पटक प्रयोग भयो — कम राख्न मिल्दैन, रोक्न OFF गर्नुहोस् · This offer is already used '
                . (int) $oldRow['used_count'] . ' time(s) — max uses cannot be lower. To stop it, switch it OFF.');
        }
        if ($id > 0) {
            $code = (string) $oldRow['code'];   // the code is fixed once made
        } elseif (Database::exists('SELECT 1 FROM coupons WHERE code = :c', ['c' => $code])) {
            $bad('यो कोडको अफर पहिल्यै छ · An offer with that code already exists.');
        }

        $row = [
            'code'           => $code,
            'title'          => $title !== '' ? $title : $code,
            'discount_type'  => $type,
            'discount_value' => $value,
            'max_discount'   => $maxD,
            'min_amount'     => $minA,
            'valid_from'     => $from,
            'valid_until'    => $until,
            'auto_apply'     => $auto,
            'is_active'      => $live,
            'route_id'       => $routeIn > 0 ? $routeIn : null,
            'usage_limit'    => $maxUse,
            'per_user_limit' => $perUser,
        ];
        if (self::hasColumn('min_hours_ahead')) {
            $row['min_hours_ahead'] = $ahead > 0 ? $ahead : null;
        }
        if (self::hasColumn('first_booking_only')) {
            $row['first_booking_only'] = $firstOn ? 1 : null;
        }
        // only what this database's coupons table really has (an old one may lack a newer column)
        $cols = self::columns();
        return $cols === [] ? $row : array_intersect_key($row, $cols);
    }
}
