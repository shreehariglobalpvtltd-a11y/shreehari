<?php
/**
 * =====================================================================
 *  DefaultRules — "a ticket fills itself" (15 Oct 2026, SmartBook v5 D15).
 *
 *  Every booking field gets a default. SmartBook::defaults() computes the
 *  built-in answer (customer history -> location -> most used -> first);
 *  the office then adds simple IF -> THEN rules in Admin -> Default Rules,
 *  stored as Settings `default_rules` (JSON):
 *
 *    {
 *      "fixed": { "direction": "toNepal", "type": "sharing", "berth": "any", "pay": "cash" },
 *      "prefer": "history" | "rules",            // who wins when both answer
 *      "rules": [
 *        { "name": "Solo woman",  "when": { "paxCount": 1, "gender": "Female" },
 *          "then": { "berth": "lower" } },
 *        { "name": "Couple",      "when": { "paxCount": 2, "gender": "mixed" },
 *          "then": { "suggest": "private_double" } },
 *        { "name": "Sunday",      "when": { "weekday": "Sun" },
 *          "then": { "date": "next" } },
 *        { "name": "Senior",      "when": { "minAge": 60 },
 *          "then": { "band": "senior", "berth": "lower" } }
 *      ]
 *    }
 *
 *  when  keys: seen (bool: the mobile booked before), paxCount (int),
 *              gender (Female|Male|mixed), weekday (Mon..Sun), direction,
 *              minAge (int, matched against ages typed for passengers), mode
 *  then  keys: direction, point, type, cabin, berth, pay, band, date
 *              ("next" = next departure), suggest ("private_double")
 *
 *  Rules only ever choose among values the card offers; a price, a berth
 *  number or a payment status is never a default (D11). An empty setting
 *  ([]) leaves the built-in defaults exactly as they are.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class DefaultRules
{
    public const FIELDS = [
        'direction' => ['toNepal', 'toIndia'],
        'type'      => ['sharing', 'private'],
        'cabin'     => ['single', 'double'],
        'berth'     => ['any', 'lower', 'upper'],
        'pay'       => ['cash', 'online'],
        'band'      => ['adult', 'child', 'senior', 'patient'],
        'date'      => ['next'],
        'suggest'   => ['private_double'],
    ];
    public const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /** @return array{fixed: array<string, string>, prefer: string, rules: list<array<string, mixed>>} */
    public static function config(): array
    {
        $raw = Settings::getArray('default_rules', []);
        return self::clean(is_array($raw) ? $raw : []);
    }

    /**
     * Validate an office-edited config: unknown keys and values outside the
     * card's own choices are dropped, never trusted.
     *
     * @return array{fixed: array<string, string>, prefer: string, rules: list<array<string, mixed>>}
     */
    public static function clean(array $raw): array
    {
        $fixed = [];
        foreach ((array) ($raw['fixed'] ?? []) as $k => $v) {
            if (isset(self::FIELDS[$k]) && in_array((string) $v, self::FIELDS[$k], true) && !in_array($k, ['date', 'suggest', 'band'], true)) {
                $fixed[$k] = (string) $v;
            }
        }
        $rules = [];
        foreach (array_slice((array) ($raw['rules'] ?? []), 0, 40) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $when = [];
            $w    = (array) ($r['when'] ?? []);
            if (array_key_exists('seen', $w)) {
                $when['seen'] = (bool) $w['seen'];
            }
            if (isset($w['paxCount']) && (int) $w['paxCount'] > 0) {
                $when['paxCount'] = min(20, (int) $w['paxCount']);
            }
            if (in_array($w['gender'] ?? '', ['Female', 'Male', 'mixed'], true)) {
                $when['gender'] = (string) $w['gender'];
            }
            if (in_array($w['weekday'] ?? '', self::WEEKDAYS, true)) {
                $when['weekday'] = (string) $w['weekday'];
            }
            if (in_array($w['direction'] ?? '', self::FIELDS['direction'], true)) {
                $when['direction'] = (string) $w['direction'];
            }
            if (isset($w['minAge']) && (int) $w['minAge'] > 0) {
                $when['minAge'] = min(120, (int) $w['minAge']);
            }
            if (in_array($w['mode'] ?? '', ['customer', 'agent', 'counter', 'admin'], true)) {
                $when['mode'] = (string) $w['mode'];
            }
            $then = [];
            foreach ((array) ($r['then'] ?? []) as $k => $v) {
                if ($k === 'point') {
                    $p = Security::clean((string) $v, 191);
                    if ($p !== '') {
                        $then['point'] = $p;
                    }
                } elseif (isset(self::FIELDS[$k]) && in_array((string) $v, self::FIELDS[$k], true)) {
                    $then[$k] = (string) $v;
                }
            }
            if ($then === []) {
                continue;
            }
            $rules[] = ['name' => Security::clean((string) ($r['name'] ?? ''), 60), 'when' => $when, 'then' => $then,
                        'on' => !array_key_exists('on', $r) || (bool) $r['on']];
        }
        return ['fixed' => $fixed, 'prefer' => ($raw['prefer'] ?? '') === 'rules' ? 'rules' : 'history', 'rules' => $rules];
    }

    /**
     * Apply fixed values and matching rules to SmartBook::defaults().
     *
     * @param array<string, mixed> $d   the built-in defaults
     * @param array<string, mixed> $ctx phone, history (array|null), mode, points (list<string>), today (Y-m-d), ages (list<int>)
     * @return array<string, mixed>
     */
    public static function apply(array $d, array $ctx, ?array $cfg = null): array
    {
        $cfg   = $cfg ?? self::config();
        $seen  = !empty($ctx['history']);
        $d['rules'] = [];
        // Fixed values fill only what nothing better answered (no history / no reason given).
        foreach ($cfg['fixed'] as $k => $v) {
            if (!isset($d['why'][$k]) && !($seen && $cfg['prefer'] === 'history' && in_array($k, ['direction'], true))) {
                $d[$k] = $v;
            }
        }
        foreach ($cfg['rules'] as $r) {
            if (empty($r['on']) || !self::matches($r['when'], $d, $ctx)) {
                continue;
            }
            foreach ($r['then'] as $k => $v) {
                if ($seen && $cfg['prefer'] === 'history' && isset($d['why'][$k])) {
                    continue;   // the customer's own history wins unless the office said otherwise
                }
                switch ($k) {
                    case 'band':
                        foreach ($d['pax'] as &$p) {
                            // only an adult chip is re-banded; a chosen child / patient stays
                            $p['band'] = ($p['band'] ?? 'adult') === 'adult' ? $v : $p['band'];
                        }
                        unset($p);
                        break;
                    case 'point':
                        $hit = class_exists('SmartBook') ? SmartBook::matchPoint($v, (array) ($ctx['points'] ?? [])) : '';
                        if ($hit !== '') {
                            $d['point'] = $hit;
                        }
                        break;
                    case 'date':
                        $d['date'] = '';   // '' = the engine's next departure
                        break;
                    case 'suggest':
                        $d['suggest'] = $v;
                        break;
                    default:
                        $d[$k] = $v;
                }
                $d['why'][$k] = ['en' => 'Office rule: ' . ($r['name'] ?: $k), 'hi' => 'ऑफिस नियम: ' . ($r['name'] ?: $k), 'ne' => 'अफिस नियम: ' . ($r['name'] ?: $k)];
            }
            $d['rules'][] = $r['name'];
        }
        return $d;
    }

    /** @param array<string, mixed> $when */
    private static function matches(array $when, array $d, array $ctx): bool
    {
        $pax     = (array) ($d['pax'] ?? []);
        $genders = array_values(array_unique(array_filter(array_map(static fn($p): string => (string) ($p['gender'] ?? ''), $pax))));
        $agg     = count($genders) > 1 ? 'mixed' : ($genders[0] ?? '');
        $today   = (string) ($ctx['today'] ?? todayISO());
        foreach ($when as $k => $v) {
            $ok = match ($k) {
                'seen'      => !empty($ctx['history']) === $v,
                'paxCount'  => count($pax) === $v,
                'gender'    => $agg === $v,
                'weekday'   => date('D', (int) strtotime($today)) === $v,
                'direction' => ($d['direction'] ?? '') === $v,
                'minAge'    => (bool) array_filter((array) ($ctx['ages'] ?? []), static fn($a): bool => (int) $a >= $v),
                'mode'      => ($ctx['mode'] ?? 'customer') === $v,
                default     => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /**
     * What a new booking would get — the Admin preview.
     *
     * @param array<string, mixed> $sample paxCount, gender (Female|Male|mixed), weekday, seen, direction, minAge, mode
     * @return array<string, mixed>
     */
    public static function preview(array $sample, ?array $cfg = null): array
    {
        $n   = max(1, min(20, (int) ($sample['paxCount'] ?? 1)));
        $g   = (string) ($sample['gender'] ?? '');
        $pax = [];
        for ($i = 0; $i < $n; $i++) {
            $pax[] = ['gender' => $g === 'mixed' ? ($i % 2 === 0 ? 'Female' : 'Male') : $g, 'band' => 'adult'];
        }
        $base = [
            'direction' => in_array($sample['direction'] ?? '', self::FIELDS['direction'], true) ? $sample['direction'] : 'toNepal',
            'point' => '', 'pointType' => 'boarding', 'name' => '', 'pax' => $pax, 'type' => 'sharing', 'cabin' => 'single',
            'berth' => 'any', 'pay' => 'cash', 'history' => !empty($sample['seen']), 'why' => [],
        ];
        $day   = in_array($sample['weekday'] ?? '', self::WEEKDAYS, true) ? (string) $sample['weekday'] : date('D');
        $today = date('Y-m-d', (int) strtotime('next ' . $day));
        return self::apply($base, [
            'history' => !empty($sample['seen']) ? ['sample' => true] : null,
            'mode'    => (string) ($sample['mode'] ?? 'customer'),
            'today'   => $today,
            'ages'    => isset($sample['minAge']) ? [(int) $sample['minAge']] : [],
            'points'  => [],
        ], $cfg);
    }
}
