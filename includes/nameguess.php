<?php
/**
 * =====================================================================
 *  NameGuess — "Sita Thapa" is probably Female (15 Oct 2026).
 *
 *  Owner ask: "AI le naam bata gender guess garos, location guess garos,
 *  database bata sikdai jaos" — one brain behind all three booking doors
 *  (the customer app, the staff SmartBook / counter, WhatsApp).
 *
 *  A GUESS, never a fact. Women-only berths and the shared-cabin lock
 *  depend on gender, so every caller treats this as a prefill the
 *  person can change:
 *    · SmartBook card  — the F / M chip is pre-set and marked 🤖 when the
 *                        confidence reaches SHOW; one tap changes it.
 *    · QuickBot line   — a desk Auto-book only counts the gender as said
 *                        when the confidence reaches AUTO.
 *    · WhatsApp        — the sale carries the gender only at AUTO; below
 *                        that it goes out without one, exactly as before.
 *
 *  Where the answer comes from, strongest first:
 *    1. a title          Mrs / Smt / Ms / श्रीमती …  ·  Mr / Shri …
 *    2. a name part      Kumari / Devi / Kaur / -ben  ·  Bahadur / Prasad / -bhai …
 *    3. what we LEARNED  ai_name_gender: every passenger ever booked with a
 *                        gender teaches their first name (rebuilt nightly
 *                        from booking_passengers + one row per sale)
 *    4. a seed list      common Nepali / Indian first names
 *    5. the ending       -ita / -mila / -ika …  ·  -endra / -esh / -deep …
 *  Two signals that disagree cancel each other down below the bar.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class NameGuess
{
    /** Pre-fill the chip on a card a person is looking at. */
    public const SHOW = 0.6;
    /** Count it as said where nobody looks before the sale (Auto-book, WhatsApp). */
    public const AUTO = 0.85;

    private const TITLES_F = ['mrs', 'smt', 'ms', 'miss', 'shrimati', 'srimati', 'sushri', 'kumari', 'श्रीमती', 'सुश्री', 'कुमारी', 'શ્રીમતી'];
    private const TITLES_M = ['mr', 'shri', 'sri', 'shree', 'shriman', 'श्री', 'श्रीमान', 'શ્રી', 'શ્રીમાન'];

    /** A later part of the name that says it outright. [part => confidence] */
    private const PARTS_F = ['kumari' => 0.95, 'devi' => 0.95, 'kaur' => 0.97, 'begum' => 0.97, 'khatun' => 0.97, 'bibi' => 0.95,
                             'bano' => 0.9, 'ben' => 0.95, 'bahen' => 0.95, 'behen' => 0.95, 'rani' => 0.85,
                             'कुमारी' => 0.95, 'देवी' => 0.95, 'बेन' => 0.95, 'કુમારી' => 0.95, 'દેવી' => 0.95, 'બેન' => 0.95];
    private const PARTS_M = ['bahadur' => 0.95, 'prasad' => 0.9, 'kumar' => 0.85, 'lal' => 0.85, 'nath' => 0.85, 'bhai' => 0.9,
                             'mohammad' => 0.95, 'mohammed' => 0.95, 'mohd' => 0.95, 'md' => 0.9, 'singh' => 0.75, 'khan' => 0.8,
                             'बहादुर' => 0.95, 'प्रसाद' => 0.9, 'कुमार' => 0.85, 'लाल' => 0.85, 'भाई' => 0.9, 'ભાઈ' => 0.9];

    /** Common first names (seed — what the office learned is read before these). */
    private const FEMALE = ['sita', 'gita', 'geeta', 'rita', 'sunita', 'anita', 'kamala', 'laxmi', 'lakshmi', 'sarita', 'puja', 'pooja',
        'priya', 'preeti', 'priti', 'ambita', 'dipa', 'deepa', 'radha', 'parvati', 'mina', 'meena', 'sabina', 'sushila', 'maya', 'manju',
        'anju', 'sangita', 'sangeeta', 'saraswati', 'durga', 'kalpana', 'rekha', 'asha', 'usha', 'nisha', 'ritu', 'sapana', 'sapna',
        'bimala', 'kopila', 'januka', 'sharmila', 'urmila', 'nirmala', 'pramila', 'renu', 'sushma', 'kabita', 'kavita', 'rupa', 'roshni',
        'shanti', 'santi', 'ganga', 'jamuna', 'yamuna', 'kalpana', 'babita', 'bindu', 'chandra kala', 'dhan maya', 'gauri', 'hema',
        'indira', 'jyoti', 'kamla', 'kiranbala', 'lila', 'leela', 'mamta', 'mamata', 'menuka', 'mira', 'meera', 'neha', 'nirmala',
        'pabitra', 'pavitra', 'rachana', 'rama', 'ranjana', 'rina', 'reena', 'sabita', 'samjhana', 'sarala', 'sharda', 'sharada',
        'shova', 'shobha', 'sita', 'srijana', 'sujata', 'sunaina', 'tara', 'tulasi', 'tulsi', 'uma', 'yashoda', 'anjali', 'anjana',
        'aarti', 'arti', 'bhawana', 'bhavna', 'champa', 'chanda', 'dimple', 'divya', 'heena', 'hina', 'jasmin', 'jasmine', 'kajal', 'kiran kumari',
        'komal', 'krishna kumari', 'madhu', 'mansi', 'monika', 'nikita', 'payal', 'poonam', 'punam', 'rajani', 'rajni', 'reshma', 'rubina',
        'sabnam', 'shabnam', 'salma', 'sonam', 'sonia', 'soniya', 'swati', 'tanuja', 'tina', 'twinkle', 'vandana', 'bandana', 'varsha',
        'barsha', 'yamuna', 'aasha', 'asmita', 'alisha', 'apsara', 'bishnu maya', 'ful maya', 'phul maya', 'gyanu', 'jamuna', 'junu',
        'kumari', 'man maya', 'pabita', 'parbati', 'rupa', 'sanju', 'sarswati', 'sumitra', 'sunita', 'tika', 'devaki', 'dhanmaya',
        'phulmaya', 'manmaya', 'bishnumaya', 'harimaya', 'chandrakala', 'sitadevi', 'ambika', 'anisha', 'binita', 'pratima', 'sarmila'];
    private const MALE = ['ram', 'hari', 'krishna', 'shyam', 'gopal', 'ganesh', 'suresh', 'ramesh', 'mahesh', 'dinesh', 'rajesh', 'mukesh',
        'rakesh', 'naresh', 'bharat', 'arjun', 'madhav', 'sagar', 'kabir', 'raju', 'dil', 'dalbir', 'bed', 'tek', 'man', 'lok', 'bim', 'prem',
        'pream', 'bikash', 'vikash', 'vikas', 'bikram', 'vikram', 'dipak', 'deepak', 'rohit', 'karan', 'gagan', 'roshan', 'rosan', 'santosh',
        'sanjay', 'sunil', 'anil', 'binod', 'vinod', 'kamal', 'bishnu', 'birendra', 'narendra', 'mahendra', 'lokendra', 'surendra', 'dhruba',
        'dhrubha', 'khadak', 'yagya', 'padam', 'tilak', 'keshav', 'govind', 'bal', 'jit', 'nabin', 'naveen', 'amit', 'vijay', 'bijay',
        'ajay', 'sachin', 'rahul', 'ravi', 'rabi', 'manoj', 'pradeep', 'sandeep', 'kuldeep', 'ashok', 'ashish', 'arun', 'baburam', 'bhim',
        'bhimsen', 'bir', 'chandra', 'chet', 'dev', 'dhan', 'durga bahadur', 'gajendra', 'gokul', 'hem', 'indra', 'jagat', 'jeevan', 'jiwan',
        'kishor', 'kishore', 'krishan', 'laxman', 'lakshman', 'madan', 'mohan', 'nar', 'nanda lal', 'nirmal', 'om', 'pawan', 'prakash',
        'prabin', 'praveen', 'puran', 'raj', 'rajan', 'raju', 'ramesh', 'sabin', 'sahil', 'shiva', 'shiv', 'sohan', 'subash', 'subhash',
        'sudip', 'sujan', 'suraj', 'tej', 'umesh', 'yam', 'yogesh', 'jay', 'jai', 'dilip', 'dileep', 'dharmendra', 'devendra', 'jitendra',
        'upendra', 'rajendra', 'ramchandra', 'harish', 'girish', 'jagdish', 'nitesh', 'ritesh', 'hitesh', 'kamlesh', 'kalpesh', 'paresh',
        'bhavesh', 'jignesh', 'nilesh', 'alpesh', 'mitesh', 'chirag', 'hardik', 'jaydeep', 'kaushik', 'parth', 'vishal', 'bishal', 'akash',
        'aakash', 'abhishek', 'aditya', 'ankit', 'anuj', 'gaurav', 'gourav', 'harsh', 'kunal', 'mayank', 'nikhil', 'pankaj', 'rahul',
        'rajiv', 'sanjeev', 'saurabh', 'shubham', 'sumit', 'tarun', 'varun', 'vivek', 'bibek', 'yuvraj', 'kasim', 'kasam', 'salim', 'imran',
        'irfan', 'rafiq', 'rashid', 'newson', 'mangal', 'budhi', 'ganga ram', 'lal bahadur', 'khem', 'bhupendra', 'nagendra', 'pushkar',
        'dhurba', 'kul', 'min', 'gopi', 'tulsi ram', 'hari prasad', 'baru', 'jau', 'bhola', 'sher', 'karna', 'kamal bahadur', 'resham'];

    /** Endings that lean one way on an unknown first name. [ending => confidence] */
    private const END_F = ['ita' => 0.7, 'mila' => 0.75, 'ika' => 0.7, 'ini' => 0.7, 'wati' => 0.8, 'vati' => 0.8, 'maya' => 0.8,
                           'kumari' => 0.9, 'devi' => 0.9, 'shree' => 0.6, 'isha' => 0.7, 'ben' => 0.9];
    private const END_M = ['endra' => 0.8, 'esh' => 0.75, 'deep' => 0.75, 'dip' => 0.7, 'jit' => 0.75, 'nath' => 0.8, 'bahadur' => 0.95,
                           'raj' => 0.7, 'ant' => 0.65, 'bir' => 0.75, 'veer' => 0.75, 'pal' => 0.7, 'ram' => 0.7, 'bhai' => 0.9, 'kumar' => 0.85];

    /** @var array<string, array{0:int,1:int}>|null name => [male, female] */
    private static ?array $learned = null;

    private function __construct() {}

    public static function on(): bool
    {
        return Settings::getBool('ai_name_guess_on', true);
    }

    /**
     * @return array{gender: string, confidence: float, source: string, why: string}
     *         gender 'Female' | 'Male' | '' (no guess)
     */
    public static function gender(string $fullName): array
    {
        $none = ['gender' => '', 'confidence' => 0.0, 'source' => '', 'why' => ''];
        if (!self::on()) {
            return $none;
        }
        $tokens = self::tokens($fullName);
        if ($tokens === []) {
            return $none;
        }
        $best = ['Female' => [0.0, '', ''], 'Male' => [0.0, '', '']];
        $note = static function (string $g, float $c, string $src, string $why) use (&$best): void {
            if ($c > $best[$g][0]) {
                $best[$g] = [$c, $src, $why];
            }
        };

        /* 1. a title in front */
        $first = $tokens[0];
        if (in_array($first, self::TITLES_F, true)) {
            $note('Female', 0.97, 'title', 'Title "' . $first . '"');
            array_shift($tokens);
        } elseif (in_array($first, self::TITLES_M, true) && count($tokens) > 1) {
            $note('Male', 0.88, 'title', 'Title "' . $first . '"');
            array_shift($tokens);
        }
        if ($tokens === []) {
            return self::pick($best);
        }

        /* 2. a later part of the name that says it ("Sita Kumari", "Ram Bahadur", "Geetaben") */
        foreach (array_slice($tokens, 1) as $t) {
            if (isset(self::PARTS_F[$t])) {
                $note('Female', self::PARTS_F[$t], 'part', '"' . $t . '" in the name');
            }
            if (isset(self::PARTS_M[$t])) {
                $note('Male', self::PARTS_M[$t], 'part', '"' . $t . '" in the name');
            }
        }
        foreach ($tokens as $t) {
            // Gujarati: "Geetaben", "Rameshbhai" — the suffix glued on
            if (mb_strlen($t) > 4 && str_ends_with($t, 'ben')) {
                $note('Female', 0.95, 'part', '"-ben" name');
            }
            if (mb_strlen($t) > 5 && str_ends_with($t, 'bhai')) {
                $note('Male', 0.95, 'part', '"-bhai" name');
            }
        }

        $fn  = $tokens[0];
        $two = count($tokens) > 1 ? $fn . ' ' . $tokens[1] : '';

        /* 3. what this office learned */
        $learned = self::learned();
        foreach ([$two, $fn] as $key) {
            if ($key === '' || !isset($learned[$key])) {
                continue;
            }
            [$m, $f] = $learned[$key];
            $n = $m + $f;
            if ($n < 2) {
                continue;
            }
            $g     = $f > $m ? 'Female' : 'Male';
            $share = max($m, $f) / $n;
            if ($share < 0.8) {
                continue;
            }
            $note($g, round($share * min(0.97, 0.7 + 0.05 * $n), 2), 'learned',
                'Learned from ' . $n . ' earlier passenger' . ($n > 1 ? 's' : '') . ' named ' . ucwords($key));
            break;
        }

        /* 4. the seed lists */
        foreach ([$two, $fn] as $key) {
            if ($key === '') {
                continue;
            }
            if (in_array($key, self::FEMALE, true)) {
                $note('Female', 0.9, 'list', 'Common female name');
                break;
            }
            if (in_array($key, self::MALE, true)) {
                $note('Male', 0.9, 'list', 'Common male name');
                break;
            }
        }

        /* 5. the ending of the first name (weak) */
        if ($best['Female'][0] === 0.0 && $best['Male'][0] === 0.0 && mb_strlen($fn) >= 4) {
            foreach (self::END_F as $end => $c) {
                if (str_ends_with($fn, $end)) {
                    $note('Female', $c, 'ending', 'Name ending "-' . $end . '"');
                }
            }
            foreach (self::END_M as $end => $c) {
                if (str_ends_with($fn, $end)) {
                    $note('Male', $c, 'ending', 'Name ending "-' . $end . '"');
                }
            }
        }
        return self::pick($best);
    }

    /** The guess only when it is sure enough to act on without a look. */
    public static function confident(string $fullName, float $bar = self::AUTO): ?string
    {
        $g = self::gender($fullName);
        return ($g['gender'] !== '' && $g['confidence'] >= $bar) ? $g['gender'] : null;
    }

    /** One sale teaches its passenger's first name (the nightly rebuild recounts anyway). */
    public static function teach(string $fullName, ?string $gender): void
    {
        if (!in_array($gender, ['Male', 'Female'], true)) {
            return;
        }
        $key = self::key($fullName);
        if ($key === '') {
            return;
        }
        try {
            Database::run(
                'INSERT INTO ai_name_gender (name, male, female) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE male = male + VALUES(male), female = female + VALUES(female)',
                [$key, $gender === 'Male' ? 1 : 0, $gender === 'Female' ? 1 : 0]
            );
            self::$learned = null;
        } catch (Throwable $e) {
            // table not migrated yet — learning waits, booking never does
        }
    }

    /**
     * Recount every first name from booking_passengers (cron/brain-nightly.php).
     * @return array{names: int, passengers: int}
     */
    public static function rebuild(): array
    {
        $rows = Database::fetchAll(
            "SELECT full_name, gender FROM booking_passengers WHERE gender IN ('Male','Female')"
        );
        $count = [];
        $used  = 0;
        foreach ($rows as $r) {
            $key = self::key((string) $r['full_name']);
            if ($key === '') {
                continue;
            }
            $count[$key] ??= [0, 0];
            $count[$key][$r['gender'] === 'Male' ? 0 : 1]++;
            $used++;
        }
        Database::transaction(static function () use ($count): void {
            Database::run('DELETE FROM ai_name_gender');
            foreach (array_chunk($count, 200, true) as $chunk) {
                $sql = []; $args = [];
                foreach ($chunk as $name => [$m, $f]) {
                    $sql[] = '(?, ?, ?)';
                    array_push($args, (string) $name, $m, $f);
                }
                Database::run('INSERT INTO ai_name_gender (name, male, female) VALUES ' . implode(',', $sql), $args);
            }
        });
        self::$learned = null;
        return ['names' => count($count), 'passengers' => $used];
    }

    /** @return array{names: int, passengers: int, top: list<array{name: string, male: int, female: int}>} */
    public static function stats(int $top = 12): array
    {
        try {
            $sum = Database::fetch('SELECT COUNT(*) n, COALESCE(SUM(male + female), 0) p FROM ai_name_gender') ?? ['n' => 0, 'p' => 0];
            $rows = Database::fetchAll('SELECT name, male, female FROM ai_name_gender ORDER BY (male + female) DESC, name LIMIT ' . max(1, min(50, $top)));
        } catch (Throwable $e) {
            return ['names' => 0, 'passengers' => 0, 'top' => []];
        }
        return [
            'names'      => (int) $sum['n'],
            'passengers' => (int) $sum['p'],
            'top'        => array_map(static fn(array $r): array => ['name' => (string) $r['name'], 'male' => (int) $r['male'], 'female' => (int) $r['female']], $rows),
        ];
    }

    /* ----------------------------------------------------------------- */

    /** @param array{Female: array{0: float, 1: string, 2: string}, Male: array{0: float, 1: string, 2: string}} $best */
    private static function pick(array $best): array
    {
        [$fc, $fs, $fw] = $best['Female'];
        [$mc, $ms, $mw] = $best['Male'];
        if ($fc === 0.0 && $mc === 0.0) {
            return ['gender' => '', 'confidence' => 0.0, 'source' => '', 'why' => ''];
        }
        // two signals that disagree pull each other down
        if ($fc >= $mc) {
            return ['gender' => 'Female', 'confidence' => round(max(0.0, $fc - 0.5 * $mc), 2), 'source' => $fs, 'why' => $fw];
        }
        return ['gender' => 'Male', 'confidence' => round(max(0.0, $mc - 0.5 * $fc), 2), 'source' => $ms, 'why' => $mw];
    }

    /** @return array<string, array{0: int, 1: int}> */
    private static function learned(): array
    {
        if (self::$learned !== null) {
            return self::$learned;
        }
        self::$learned = [];
        try {
            foreach (Database::fetchAll('SELECT name, male, female FROM ai_name_gender') as $r) {
                self::$learned[(string) $r['name']] = [(int) $r['male'], (int) $r['female']];
            }
        } catch (Throwable $e) {
            // not migrated yet: the seed lists still answer
        }
        return self::$learned;
    }

    /** The learning key: the first real name word (title dropped), lower case. */
    private static function key(string $fullName): string
    {
        $t = self::tokens($fullName);
        if ($t !== [] && (in_array($t[0], self::TITLES_F, true) || in_array($t[0], self::TITLES_M, true))) {
            array_shift($t);
        }
        $k = $t[0] ?? '';
        // "Quick Walk In", "Passenger 2" and the like teach nothing
        if ($k === '' || mb_strlen($k) < 2 || mb_strlen($k) > 40 || in_array($k, ['quick', 'walk', 'passenger', 'guest', 'test', 'customer', 'pax', 'unknown'], true)) {
            return '';
        }
        return $k;
    }

    /** @return list<string> lower-case name words, letters only */
    private static function tokens(string $name): array
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace('/\(\d+\)/u', ' ', $name);          // "Ram (2)"
        $name = (string) preg_replace('/[^\p{L}\p{M}]+/u', ' ', $name);
        $out  = [];
        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $w) {
            if ($w !== '') {
                $out[] = $w;
            }
        }
        return $out;
    }
}
