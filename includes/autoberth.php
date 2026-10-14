<?php
/**
 * =====================================================================
 *  AutoBerth — the SmartBook seat engine (15 Oct 2026, SmartBook v5 §9).
 *
 *  Deterministic code, no AI (owner rule D11: "rules decide, AI assists").
 *  Given a departure's availability and the party (gender + age band per
 *  passenger) it returns one berth per passenger plus the reason, in
 *  English / Hindi / Nepali, that the card shows when a berth is tapped.
 *
 *  HARD rules — never broken (the server re-checks them at the sale:
 *  Seats::assertAvailable + Seats::assertGenderAllowed + the women-only
 *  check in BookingService::create):
 *    H1  a sharing cabin is never shared by unrelated passengers of a
 *        different gender (the cabin's gender lock; a mixed pair only when
 *        the party takes the WHOLE empty cabin — the server's own rule)
 *    H2  a women-only berth seats a woman only
 *    H3  a private cabin holds one booking only (private mode is picked by
 *        cabin label, never mixed with sharing)
 *    H4  a child under 12 sits in the same cabin as an adult of the party,
 *        else on the bed right beside one
 *    H5  never a booked / held / blocked / staff / emergency berth (the
 *        availability list already excludes them)
 *
 *  SOFT rules — weights from Settings `seat_rules` (Admin), defaults:
 *    S1 together 50 · S2 femaleNearFemale 40 · S3 careLower 35 ·
 *    S4 lowerFirst 20 · S5 maleUpper 15 · S6 emergencyAble 10 · S7 rowBalance 1
 *
 *  The party is cut into cabin-sized chunks (an adult with each child,
 *  same-gender pairs, a couple, then singles) and placed by a small
 *  depth-first search over the best few cabins per chunk — a few
 *  thousand nodes at most, well under 50 ms on a 72-berth coach.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/seats.php';

final class AutoBerth
{
    public const DEFAULT_WEIGHTS = [
        'together'         => 50,
        'femaleNearFemale' => 40,
        'careLower'        => 35,
        'lowerFirst'       => 20,
        'maleUpper'        => 15,
        'emergencyAble'    => 10,
        'rowBalance'       => 1,
    ];

    /** Age bands the card offers (one chip per passenger). */
    public const BANDS = ['adult', 'child', 'senior', 'patient'];

    private const BRANCH    = 4;      // cabins tried per chunk
    private const EXPLORE   = 2;      // levels that compare their alternatives in full
    private const NODE_CAP  = 4000;   // search budget

    /** @var array<string, string> seat|coach => cabin key (Seats::unitKey is per-seat hot) */
    private static array $unitMemo = [];
    /** @var array<string, list<string>> cabin|coach => its beds */
    private static array $bedsMemo = [];

    public static function unitOf(string $seat, string $coach = 'sleeper'): string
    {
        $k = $seat . '|' . $coach;
        return self::$unitMemo[$k] ??= Seats::unitKey($seat, $coach);
    }

    /** @return list<string> */
    public static function bedsOf(string $unit, string $coach = 'sleeper'): array
    {
        $k = $unit . '|' . $coach;
        if (!isset(self::$bedsMemo[$k])) {
            $beds = [];
            foreach (Seats::seatIds($coach, 'sharing') as $s) {
                if (self::unitOf((string) $s, $coach) === $unit) {
                    $beds[] = (string) $s;
                }
            }
            self::$bedsMemo[$k] = $beds;
        }
        return self::$bedsMemo[$k];
    }

    /** @return array<string, int> */
    public static function weights(): array
    {
        $w   = self::DEFAULT_WEIGHTS;
        $cfg = Settings::getArray('seat_rules', []);
        foreach ($w as $k => $v) {
            if (isset($cfg[$k]) && is_numeric($cfg[$k])) {
                $w[$k] = max(0, min(500, (int) $cfg[$k]));
            }
        }
        return $w;
    }

    /** 'Female' | 'Male' | null */
    public static function gender(mixed $g): ?string
    {
        $g = strtolower(trim((string) $g));
        return match ($g) {
            'female', 'f', 'woman', 'mahila' => 'Female',
            'male', 'm', 'man', 'purush'     => 'Male',
            default                          => null,
        };
    }

    public static function band(mixed $b): string
    {
        $b = strtolower(trim((string) $b));
        return in_array($b, self::BANDS, true) ? $b : 'adult';
    }

    /**
     * Pick one berth per passenger.
     *
     * @param array<string, mixed> $av   Seats::availabilityForSchedule(…, 'sharing')
     * @param list<array<string, mixed>> $pax  [{gender, band}] in party order
     * @param array<string, mixed> $opts pref 'lower'|'upper'|'any', prefer list<string>,
     *                                   coach 'sleeper'|'seater', weights array
     * @return array{ok: bool, seats: list<string>, reasons: list<array{en: string, hi: string, ne: string}>,
     *               score: int, ms: float, why: string}
     */
    public static function pick(array $av, array $pax, array $opts = []): array
    {
        $t0    = microtime(true);
        $coach = (string) ($opts['coach'] ?? 'sleeper');
        $w     = isset($opts['weights']) && is_array($opts['weights']) ? $opts['weights'] + self::DEFAULT_WEIGHTS : self::weights();
        $pref  = in_array($opts['pref'] ?? '', ['lower', 'upper'], true) ? (string) $opts['pref'] : 'any';
        $prefer = array_flip(array_map(static fn($s): string => strtoupper((string) $s), (array) ($opts['prefer'] ?? [])));
        $rear  = (string) ($opts['rowOrder'] ?? Settings::getString('seat_ai_row_order', 'rear')) !== 'front';

        $party = [];
        foreach (array_values($pax) as $i => $p) {
            $party[] = ['i' => $i, 'g' => self::gender($p['gender'] ?? null), 'b' => self::band($p['band'] ?? 'adult')];
        }
        $fail = static fn(string $why) => ['ok' => false, 'seats' => [], 'reasons' => [], 'score' => 0,
                                           'ms' => round((microtime(true) - $t0) * 1000, 2), 'why' => $why];
        if ($party === []) {
            return $fail('no passengers');
        }

        /* ---- the coach as cabins --------------------------------------- */
        $free    = array_values(array_filter(array_map('strval', (array) ($av['available'] ?? [])),
                    static fn(string $s): bool => !Seats::isExtraSeat($s)));
        $women   = array_flip(array_map('strval', (array) ($av['female'] ?? [])));
        $locks   = is_array($av['units'] ?? null) ? $av['units'] : [];
        $freeSet = array_flip($free);
        $units   = [];
        foreach ($free as $s) {
            $u = self::unitOf($s, $coach);
            if (!isset($units[$u])) {
                $beds  = self::bedsOf($u, $coach);
                $beds  = $beds !== [] ? $beds : [$s];
                $pos   = self::pos($beds[0]);
                $units[$u] = [
                    'key'   => $u,
                    'beds'  => $beds,
                    'free'  => array_values(array_filter($beds, static fn(string $b): bool => isset($freeSet[$b]))),
                    'lock'  => (string) ($locks[$u]['lock'] ?? 'none'),
                    'deck'  => $pos['deck'],
                    'row'   => $pos['row'],
                    'col'   => $pos['col'],
                    'women' => (bool) array_filter($beds, static fn(string $b): bool => isset($women[$b])),
                ];
            }
        }
        if ($units === []) {
            return $fail('no free berth');
        }
        $rows = max(1, max(array_map(static fn(array $u): int => $u['row'], $units)));

        /* ---- the party as chunks --------------------------------------- */
        $chunks = self::chunks($party);

        $ctx = [
            'units' => $units, 'women' => $women, 'w' => $w, 'pref' => $pref, 'prefer' => $prefer,
            'rear' => $rear, 'rows' => $rows, 'solo' => count($party) === 1, 'coach' => $coach,
            'bandOf' => array_column($party, 'b', 'i'),
        ];

        $best  = null;
        $nodes = 0;
        $state = ['used' => [], 'placed' => [], 'assign' => [], 'score' => 0, 'lock' => []];
        self::search($chunks, 0, $state, $ctx, $best, $nodes);

        if ($best === null) {
            /* Second pass: everyone a single, a child only on the bed right
               beside its adult (H4 "adjacent"). Fills a near-full coach where
               no whole cabin is left for a pair. */
            $singles = array_map(static fn(array $p): array => [$p], $party);
            // adults first, so every child has its adult placed before it
            usort($singles, static fn(array $a, array $b): int => [($a[0]['b'] === 'child' ? 1 : 0), $a[0]['i']] <=> [($b[0]['b'] === 'child' ? 1 : 0), $b[0]['i']]);
            $ctx['adjacentChild'] = true;
            self::search($singles, 0, $state, $ctx, $best, $nodes);
        }
        if ($best === null) {
            return $fail('no combination keeps every hard rule');
        }

        ksort($best['assign']);
        $seats   = [];
        $reasons = [];
        foreach ($party as $p) {
            $seat      = $best['assign'][$p['i']]['seat'];
            $seats[]   = $seat;
            $reasons[] = self::reasonText($best['assign'][$p['i']]['why']);
        }
        // Belt and braces: the answer must pass the very check a hand pick gets.
        if (self::checkManual($av, $seats, $pax, $coach) !== '') {
            return $fail('the picked berths failed the hard-rule check');
        }

        return ['ok' => true, 'seats' => $seats, 'reasons' => $reasons, 'score' => (int) $best['score'],
                'ms' => round((microtime(true) - $t0) * 1000, 2), 'why' => ''];
    }

    /**
     * Check seats the passenger chose by hand against the HARD rules.
     * Returns '' when fine, else a passenger-safe reason (en / hi / ne).
     *
     * @param array<string, mixed> $av
     * @param list<string> $seats one per passenger, same order as $pax
     * @param list<array<string, mixed>> $pax
     */
    public static function checkManual(array $av, array $seats, array $pax, string $coach = 'sleeper'): string
    {
        if (count($seats) !== count($pax)) {
            return 'Choose one berth for every passenger. / हरेक यात्रुको लागि एउटा बर्थ छान्नुहोस्।';
        }
        $free  = array_flip(array_map('strval', (array) ($av['available'] ?? [])));
        $women = array_flip(array_map('strval', (array) ($av['female'] ?? [])));
        $locks = is_array($av['units'] ?? null) ? $av['units'] : [];
        $byUnit = [];
        foreach (array_values($seats) as $i => $s) {
            $s = strtoupper((string) $s);
            if (!isset($free[$s])) {
                return 'Berth ' . Seats::displayLabel($s, $coach) . ' is not free any more — pick another. / बर्थ ' . Seats::displayLabel($s, $coach) . ' खाली छैन।';
            }
            $g = self::gender($pax[$i]['gender'] ?? null);
            if (isset($women[$s]) && $g !== 'Female') {
                return 'Berth ' . Seats::displayLabel($s, $coach) . ' is for women only. / यो बर्थ महिलाका लागि मात्र हो।';
            }
            $u    = self::unitOf($s, $coach);
            $lock = (string) ($locks[$u]['lock'] ?? 'none');
            if (($lock === 'female_only' && $g !== 'Female') || ($lock === 'male_only' && $g !== 'Male') || $lock === 'mixed_allowed') {
                return 'Berth ' . Seats::displayLabel($s, $coach) . ' is in a cabin held for another gender. / यो क्याबिन अर्को लिङ्गका लागि राखिएको छ।';
            }
            $byUnit[$u][] = ['g' => $g, 'b' => self::band($pax[$i]['band'] ?? 'adult'), 'seat' => $s];
        }
        foreach ($byUnit as $u => $list) {
            $gs = array_unique(array_filter(array_map(static fn(array $x): ?string => $x['g'], $list)));
            if (count($gs) > 1) {
                $whole = count($list) === count(self::bedsOf($u, $coach)) && (string) ($locks[$u]['lock'] ?? 'none') === 'none';
                if (!$whole) {
                    return 'A shared cabin cannot mix women and men unless your group takes the whole cabin. / साझा क्याबिनमा महिला र पुरुष सँगै राख्न पूरा क्याबिन लिनुपर्छ।';
                }
            }
        }
        // H4: every child beside an adult of the party (same cabin or the next bed).
        $adults = [];
        foreach (array_values($seats) as $i => $s) {
            if (self::band($pax[$i]['band'] ?? 'adult') !== 'child') {
                $adults[] = strtoupper((string) $s);
            }
        }
        foreach (array_values($seats) as $i => $s) {
            if (self::band($pax[$i]['band'] ?? 'adult') !== 'child') {
                continue;
            }
            $ok = false;
            foreach ($adults as $a) {
                if (self::beside(strtoupper((string) $s), $a, $coach)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return 'A child under 12 must sit beside an adult of the booking. / १२ वर्षमुनिको बच्चा ठूलो मान्छेको छेउमा बस्नुपर्छ।';
            }
        }
        return '';
    }

    /* ================================================================ */

    /** L14 -> deck L, row 3, col 2 (sleeper 6 across) ; 3A -> deck M, row 3, col 1. */
    public static function pos(string $seat): array
    {
        $seat = strtoupper($seat);
        if (preg_match('/^([LU])(\d+)$/', $seat, $m) === 1) {
            $n = (int) $m[2];
            return ['deck' => $m[1], 'row' => intdiv($n - 1, 6) + 1, 'col' => ($n - 1) % 6];
        }
        if (preg_match('/^(\d+)([A-D])$/', $seat, $m) === 1) {
            return ['deck' => 'L', 'row' => (int) $m[1], 'col' => ord($m[2]) - 65];
        }
        return ['deck' => 'L', 'row' => 99, 'col' => 0];
    }

    /** Same cabin, or the next bed in the same row of the same deck. */
    private static function beside(string $a, string $b, string $coach): bool
    {
        if (self::unitOf($a, $coach) === self::unitOf($b, $coach)) {
            return true;
        }
        $pa = self::pos($a);
        $pb = self::pos($b);
        return $pa['deck'] === $pb['deck'] && $pa['row'] === $pb['row'] && abs($pa['col'] - $pb['col']) === 1;
    }

    /**
     * Cabin-sized chunks: an adult with each child (a woman first), then
     * same-gender pairs, then a couple, then singles; pairs placed first.
     *
     * @param list<array{i:int,g:?string,b:string}> $party
     * @return list<list<array{i:int,g:?string,b:string}>>
     */
    private static function chunks(array $party): array
    {
        $kids   = array_values(array_filter($party, static fn(array $p): bool => $p['b'] === 'child'));
        $adults = array_values(array_filter($party, static fn(array $p): bool => $p['b'] !== 'child'));
        usort($adults, static fn(array $a, array $b): int => [($a['g'] === 'Female' ? 0 : 1), $a['i']] <=> [($b['g'] === 'Female' ? 0 : 1), $b['i']]);

        $chunks = [];
        foreach ($kids as $k) {
            if ($adults !== []) {
                $chunks[] = [array_shift($adults), $k];
            } else {
                $chunks[] = [$k];   // more children than adults: placed beside the others (S1)
            }
        }
        $fem  = array_values(array_filter($adults, static fn(array $p): bool => $p['g'] === 'Female'));
        $male = array_values(array_filter($adults, static fn(array $p): bool => $p['g'] === 'Male'));
        $oth  = array_values(array_filter($adults, static fn(array $p): bool => $p['g'] === null));
        foreach ([&$fem, &$male] as &$list) {
            while (count($list) >= 2) {
                $chunks[] = [array_shift($list), array_shift($list)];
            }
        }
        unset($list);
        if (count($fem) === 1 && count($male) === 1 && count($party) === 2) {
            $chunks[] = [array_shift($fem), array_shift($male)];   // a couple: the whole cabin is theirs
        }
        foreach (array_merge($fem, $male, $oth) as $p) {
            $chunks[] = [$p];
        }
        usort($chunks, static fn(array $a, array $b): int => [-count($a), $a[0]['i']] <=> [-count($b), $b[0]['i']]);
        return $chunks;
    }

    /**
     * @param list<list<array{i:int,g:?string,b:string}>> $chunks
     * @param array<string, mixed> $state
     * @param array<string, mixed> $ctx
     */
    /**
     * Greedy with backtracking: every level takes its best cabin; only a
     * dead end (no cabin keeps the hard rules further down) backtracks to the
     * next-best. The first EXPLORE levels — where the party's place in the
     * coach is decided — also compare their top alternatives in full.
     *
     * @return bool a complete placement was found below this node
     */
    private static function search(array $chunks, int $k, array $state, array $ctx, ?array &$best, int &$nodes): bool
    {
        if (++$nodes > self::NODE_CAP) {
            return false;
        }
        if ($k >= count($chunks)) {
            if ($best === null || $state['score'] > $best['score']) {
                $best = $state;
            }
            return true;
        }
        $cands = self::candidates($chunks[$k], $state, $ctx);
        $found = false;
        foreach (array_slice($cands, 0, self::BRANCH) as $c) {
            $next = $state;
            foreach ($c['assign'] as $idx => $a) {
                $next['assign'][$idx] = $a;
                $next['used'][$a['seat']] = true;
            }
            $next['placed'][] = $c['unit'];
            $next['lock'][$c['unit']] = $c['lockAfter'];
            $next['score'] += $c['score'];
            if (self::search($chunks, $k + 1, $next, $ctx, $best, $nodes)) {
                $found = true;
                if ($k >= self::EXPLORE) {
                    break;   // deeper levels: the first complete answer is kept
                }
            }
        }
        return $found;
    }

    /**
     * Every cabin a chunk may take under the hard rules, best first.
     *
     * @return list<array{unit: string, score: int, lockAfter: string, assign: array<int, array{seat: string, why: list<string>}>}>
     */
    private static function candidates(array $chunk, array $state, array $ctx): array
    {
        $out  = [];
        $size = count($chunk);
        $w    = $ctx['w'];
        foreach ($ctx['units'] as $u) {
            $freeBeds = array_values(array_filter($u['free'], static fn(string $b): bool => !isset($state['used'][$b])));
            if (count($freeBeds) < $size) {
                continue;
            }
            $lock = (string) ($state['lock'][$u['key']] ?? $u['lock']);
            $occupied = count($u['beds']) - count($freeBeds);   // strangers + our own earlier picks
            $genders  = array_values(array_unique(array_filter(array_map(static fn(array $p): ?string => $p['g'], $chunk))));

            /* H1 */
            if ($lock === 'mixed_allowed') {
                continue;
            }
            if ($lock === 'female_only' && $genders !== [] && $genders !== ['Female']) {
                continue;
            }
            if ($lock === 'male_only' && $genders !== [] && $genders !== ['Male']) {
                continue;
            }
            if (in_array(null, array_map(static fn(array $p): ?string => $p['g'], $chunk), true) && $lock !== 'none') {
                continue;   // an unknown gender never joins a gender-held cabin
            }
            if (count($genders) > 1) {
                // a mixed chunk only on a WHOLE empty cabin (the server's rule)
                if ($occupied > 0 || $lock !== 'none' || $size !== count($u['beds'])) {
                    continue;
                }
            }
            /* H2: women-only beds to women. Every way of seating the chunk in this
               cabin is a candidate (a lone child must take the bed next to its
               adult, so which bed it gets matters). */
            foreach (self::bedOptions($chunk, $freeBeds, $ctx['women']) as $assignBeds) {

                /* H4: a child placed on its own (more children than adults, or the
                   second pass) must sit right beside an ADULT of the party already
                   placed - another child does not count. */
                if ($size === 1 && $chunk[0]['b'] === 'child') {
                    $seat = $assignBeds[$chunk[0]['i']];
                    $near = false;
                    foreach ($state['assign'] as $idx => $a) {
                        if (($ctx['bandOf'][$idx] ?? 'adult') !== 'child' && self::beside($seat, $a['seat'], $ctx['coach'])) {
                            $near = true;
                            break;
                        }
                    }
                    if (!$near) {
                        continue;
                    }
                }

                /* soft score */
                $score  = 0;
                $assign = [];
                foreach ($chunk as $p) {
                    $seat = $assignBeds[$p['i']];
                    $pos  = self::pos($seat);
                    $why  = [];
                    $s    = 0;
                    // S5 is the specific rule for a solo man, so the general S4 does not pull him down.
                    $soloMan = $p['g'] === 'Male' && $size === 1 && $p['b'] === 'adult';
                    if ($pos['deck'] === 'L' && !$soloMan) {
                        $s += $w['lowerFirst'];
                        $why[] = 'lower';
                    }
                    if ($ctx['pref'] === 'lower' && $pos['deck'] === 'L') {
                        $s += 30;
                    } elseif ($ctx['pref'] === 'upper' && $pos['deck'] === 'U') {
                        $s += 30 + $w['lowerFirst'];
                        $why[] = 'upperPref';
                    }
                    if (in_array($p['b'], ['senior', 'patient', 'child'], true)) {
                        if ($pos['deck'] === 'L') {
                            $s += $w['careLower'];
                            $why[] = 'care_' . $p['b'];
                            if ($pos['row'] <= 2) {
                                $s += intdiv($w['careLower'], 2);
                                $why[] = 'easy';
                            }
                        } else {
                            $s -= $w['careLower'];
                        }
                    }
                    if ($p['g'] === 'Female' && ($ctx['solo'] || $size === 1)) {
                        if (isset($ctx['women'][$seat])) {
                            $s += $w['femaleNearFemale'];
                            $why[] = 'womenOnly';
                        } elseif ($lock === 'female_only') {
                            $s += $w['femaleNearFemale'];
                            $why[] = 'nearWoman';
                        }
                    }
                    if ($soloMan) {
                        if ($pos['deck'] === 'U') {
                            $s += $w['maleUpper'];
                            $why[] = 'maleUpper';
                        }
                        if ($u['women']) {
                            $s -= $w['maleUpper'];
                        }
                    }
                    if (isset($ctx['prefer'][$seat])) {
                        $s += 25;
                        $why[] = 'sameAsLast';
                    }
                    $rowScore = $ctx['rear'] ? $pos['row'] : ($ctx['rows'] + 1 - $pos['row']);
                    $s += $w['rowBalance'] * $rowScore;
                    $score += $s;
                    $assign[$p['i']] = ['seat' => $seat, 'why' => $why];
                }
                // S1: the party together — same cabin, else the same / next row of the same deck.
                if ($size > 1) {
                    $score += $w['together'];
                    foreach ($assign as &$a) {
                        $a['why'][] = 'sameCabin';
                    }
                    unset($a);
                }
                if ($state['placed'] !== []) {
                    $near = 0;
                    foreach ($state['placed'] as $pk) {
                        $pu = $ctx['units'][$pk];
                        if ($pu['deck'] === $u['deck']) {
                            $d = abs($pu['row'] - $u['row']);
                            $near = max($near, $d === 0 ? 2 : ($d === 1 ? 1 : 0));
                        }
                    }
                    if ($near > 0) {
                        $score += intdiv($w['together'] * $near, 2);
                        foreach ($assign as &$a) {
                            $a['why'][] = 'nearGroup';
                        }
                        unset($a);
                    }
                }
                // A single completes a cabin someone of the same gender already uses (keeps whole cabins for pairs).
                if ($size === 1 && $occupied > 0 && $lock !== 'none') {
                    $score += 10;
                }

                $after = $lock;
                if ($lock === 'none') {
                    $after = count($genders) > 1 ? 'mixed_allowed' : ($genders === ['Female'] ? 'female_only' : ($genders === ['Male'] ? 'male_only' : 'none'));
                }
                $out[] = ['unit' => $u['key'], 'score' => $score, 'lockAfter' => $after, 'assign' => $assign];
            }
        }
        usort($out, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return strnatcmp($a['unit'], $b['unit']);
        });
        return $out;
    }

    /**
     * Every valid way to seat a chunk on a cabin's free beds: a single may take
     * any free bed it is allowed on; a pair gets the women-first seating and,
     * when allowed, the swapped one. A man is never put on a women-only bed.
     *
     * @return list<array<int, string>>
     */
    private static function bedOptions(array $chunk, array $freeBeds, array $women): array
    {
        $ok = static fn(array $p, string $bed): bool => !isset($women[$bed]) || $p['g'] === 'Female';
        if (count($chunk) === 1) {
            $p   = $chunk[0];
            $out = [];
            foreach ($freeBeds as $b) {
                if ($ok($p, $b)) {
                    $out[] = [$p['i'] => $b];
                }
            }
            return $out;
        }
        $base = self::assignBeds($chunk, $freeBeds, $women);
        if ($base === null) {
            return [];
        }
        $out = [$base];
        if (count($chunk) === 2) {
            [$a, $b] = [$chunk[0], $chunk[1]];
            $sw = [$a['i'] => $base[$b['i']], $b['i'] => $base[$a['i']]];
            if ($sw[$a['i']] !== $base[$a['i']] && $ok($a, $sw[$a['i']]) && $ok($b, $sw[$b['i']])) {
                $out[] = $sw;
            }
        }
        return $out;
    }

    /**
     * Seat the chunk on the free beds: women on women-only beds first, a man
     * never on one. Null when impossible.
     *
     * @return array<int, string>|null passenger index => bed
     */
    private static function assignBeds(array $chunk, array $freeBeds, array $women): ?array
    {
        $wBeds = array_values(array_filter($freeBeds, static fn(string $b): bool => isset($women[$b])));
        $oBeds = array_values(array_filter($freeBeds, static fn(string $b): bool => !isset($women[$b])));
        $out   = [];
        $order = $chunk;
        usort($order, static fn(array $a, array $b): int => [($a['g'] === 'Female' ? 0 : 1), $a['i']] <=> [($b['g'] === 'Female' ? 0 : 1), $b['i']]);
        foreach ($order as $p) {
            if ($p['g'] === 'Female' && $wBeds !== []) {
                $out[$p['i']] = array_shift($wBeds);
            } elseif ($oBeds !== []) {
                $out[$p['i']] = array_shift($oBeds);
            } elseif ($p['g'] === 'Female' && $wBeds !== []) {
                $out[$p['i']] = array_shift($wBeds);
            } else {
                return null;
            }
        }
        return $out;
    }

    /** @param list<string> $why  @return array{en: string, hi: string, ne: string} */
    private static function reasonText(array $why): array
    {
        $T = [
            'womenOnly'  => ['Women-only berth', 'महिला-मात्र बर्थ', 'महिला मात्रको बर्थ'],
            'nearWoman'  => ['Next to a woman passenger', 'महिला यात्री के पास', 'महिला यात्रुको छेउमा'],
            'sameCabin'  => ['Same cabin as your group', 'आपके समूह के साथ एक केबिन', 'तपाईंको समूहसँग एउटै क्याबिन'],
            'nearGroup'  => ['Beside your group', 'समूह के पास', 'समूहको नजिक'],
            'care_child' => ['Lower floor for the child', 'बच्चे के लिए नीचे', 'बच्चाका लागि तल्लो तला'],
            'care_senior'=> ['Lower floor for the senior', 'वरिष्ठ के लिए नीचे', 'ज्येष्ठ नागरिकका लागि तल्लो तला'],
            'care_patient'=> ['Lower floor for the patient', 'मरीज़ के लिए नीचे', 'बिरामीका लागि तल्लो तला'],
            'easy'       => ['easy to reach', 'आसान पहुँच', 'सजिलो पहुँच'],
            'maleUpper'  => ['Upper floor for a solo man', 'अकेले पुरुष के लिए ऊपर', 'एक्लो पुरुषका लागि माथिल्लो तला'],
            'upperPref'  => ['Upper floor as you asked', 'आपकी पसंद: ऊपर', 'तपाईंले रोजेको माथिल्लो तला'],
            'lower'      => ['Lower floor', 'नीचे (1F)', 'तल्लो तला (1F)'],
            'sameAsLast' => ['Same berth as last time', 'पिछली बार वाली बर्थ', 'पहिलेकै बर्थ'],
        ];
        $order = ['womenOnly', 'nearWoman', 'care_child', 'care_senior', 'care_patient', 'easy', 'sameCabin', 'nearGroup', 'sameAsLast', 'upperPref', 'maleUpper', 'lower'];
        $out = [[], [], []];
        foreach ($order as $k) {
            if (in_array($k, $why, true) && count($out[0]) < 3) {
                foreach ([0, 1, 2] as $l) {
                    $out[$l][] = $T[$k][$l];
                }
            }
        }
        if ($out[0] === []) {
            $out = [['Best free berth'], ['सबसे अच्छी खाली बर्थ'], ['सबैभन्दा राम्रो खाली बर्थ']];
        }
        return ['en' => implode(' · ', $out[0]), 'hi' => implode(' · ', $out[1]), 'ne' => implode(' · ', $out[2])];
    }
}
