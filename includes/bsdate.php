<?php
/**
 * =====================================================================
 *  BsDate — Bikram Sambat (विक्रम संवत्) -> AD for dates WRITTEN on paper
 *  (30 Oct 2026, the form-reading AI).
 *
 *  A Nepali applicant writes the date of birth the way the citizenship
 *  card prints it: "जन्म मिति: २०४८/०५/१२" (BS). The paper-form reader
 *  (ApFormRead, includes/agentpro-formread.php) asks the model to copy
 *  such a date AS WRITTEN with a "BS" mark and converts it here — the
 *  model never does calendar arithmetic, because a wrong guess there is a
 *  wrong date of birth on an ID card. ApDocRead::bsToAd (ID photos) uses
 *  this table too (its own copy had wrong month rows for 2080-2083).
 *
 *  Verified 30 Oct 2026 (af1): every 1 Baishakh 2046-2084 lands on the
 *  published new-year date, plus Kartik 23 2047 = 1990-11-09 (constitution),
 *  Baishakh 11 2063 = 2006-04-24, Jestha 15 2065 = 2008-05-28 and
 *  Asoj 3 2072 = 2015-09-20 (tests/agentpro-formread-test.php).
 *
 *  BS month lengths are not formulaic: every year is a fixed panchanga
 *  table. MONTHS below is BS 2000..2090, one row of 12 month lengths per
 *  year, counted from 1 Baishakh 2000 = 14 April 1943 (AD).
 *    2000-2079  the published table (the same rows every Nepali date
 *               converter carries; cross-checked 30 Oct 2026)
 *    2080-2084  EXACTLY Ticket::BS_DATA (includes/ticket.php) and BS_DATA
 *               in assets/js/02-config.js — the rows the tickets print,
 *               verified against the panchanga. tests/formscan-test.php
 *               fails if they ever drift apart.
 *    2085-2090  the converters' projected rows — only a licence's
 *               valid-until date lands this far out.
 *  Outside the table every function answers '' / null: no date is better
 *  than a wrong date.
 *
 *  Pure PHP, no DB, no Settings: the tests call it directly.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class BsDate
{
    public const FIRST    = 2000;
    public const LAST     = 2090;
    private const EPOCH   = '1943-04-14';   // 1 Baishakh 2000 BS
    public const MONTH_NAMES = ['बैशाख', 'जेठ', 'असार', 'साउन', 'भदौ', 'असोज', 'कार्तिक', 'मंसिर', 'पुष', 'माघ', 'फागुन', 'चैत'];

    private const MONTHS = [
        2000 => [30,32,31,32,31,30,30,30,29,30,29,31], 2001 => [31,31,32,31,31,31,30,29,30,29,30,30],
        2002 => [31,31,32,32,31,30,30,29,30,29,30,30], 2003 => [31,32,31,32,31,30,30,30,29,29,30,31],
        2004 => [30,32,31,32,31,30,30,30,29,30,29,31], 2005 => [31,31,32,31,31,31,30,29,30,29,30,30],
        2006 => [31,31,32,32,31,30,30,29,30,29,30,30], 2007 => [31,32,31,32,31,30,30,30,29,29,30,31],
        2008 => [31,31,31,32,31,31,29,30,30,29,29,31], 2009 => [31,31,32,31,31,31,30,29,30,29,30,30],
        2010 => [31,31,32,32,31,30,30,29,30,29,30,30], 2011 => [31,32,31,32,31,30,30,30,29,29,30,31],
        2012 => [31,31,31,32,31,31,29,30,30,29,30,30], 2013 => [31,31,32,31,31,31,30,29,30,29,30,30],
        2014 => [31,31,32,32,31,30,30,29,30,29,30,30], 2015 => [31,32,31,32,31,30,30,30,29,29,30,31],
        2016 => [31,31,31,32,31,31,29,30,30,29,30,30], 2017 => [31,31,32,31,31,31,30,29,30,29,30,30],
        2018 => [31,32,31,32,31,30,30,29,30,29,30,30], 2019 => [31,32,31,32,31,30,30,30,29,30,29,31],
        2020 => [31,31,31,32,31,31,30,29,30,29,30,30], 2021 => [31,31,32,31,31,31,30,29,30,29,30,30],
        2022 => [31,32,31,32,31,30,30,30,29,29,30,30], 2023 => [31,32,31,32,31,30,30,30,29,30,29,31],
        2024 => [31,31,31,32,31,31,30,29,30,29,30,30], 2025 => [31,31,32,31,31,31,30,29,30,29,30,30],
        2026 => [31,32,31,32,31,30,30,30,29,29,30,31], 2027 => [30,32,31,32,31,30,30,30,29,30,29,31],
        2028 => [31,31,32,31,31,31,30,29,30,29,30,30], 2029 => [31,31,32,31,32,30,30,29,30,29,30,30],
        2030 => [31,32,31,32,31,30,30,30,29,29,30,31], 2031 => [30,32,31,32,31,30,30,30,29,30,29,31],
        2032 => [31,31,32,31,31,31,30,29,30,29,30,30], 2033 => [31,31,32,32,31,30,30,29,30,29,30,30],
        2034 => [31,32,31,32,31,30,30,30,29,29,30,31], 2035 => [30,32,31,32,31,31,29,30,30,29,29,31],
        2036 => [31,31,32,31,31,31,30,29,30,29,30,30], 2037 => [31,31,32,32,31,30,30,29,30,29,30,30],
        2038 => [31,32,31,32,31,30,30,30,29,29,30,31], 2039 => [31,31,31,32,31,31,29,30,30,29,30,30],
        2040 => [31,31,32,31,31,31,30,29,30,29,30,30], 2041 => [31,31,32,32,31,30,30,29,30,29,30,30],
        2042 => [31,32,31,32,31,30,30,30,29,29,30,31], 2043 => [31,31,31,32,31,31,29,30,30,29,30,30],
        2044 => [31,31,32,31,31,31,30,29,30,29,30,30], 2045 => [31,32,31,32,31,30,30,29,30,29,30,30],
        2046 => [31,32,31,32,31,30,30,30,29,29,30,31], 2047 => [31,31,31,32,31,31,30,29,30,29,30,30],
        2048 => [31,31,32,31,31,31,30,29,30,29,30,30], 2049 => [31,32,31,32,31,30,30,30,29,29,30,30],
        2050 => [31,32,31,32,31,30,30,30,29,30,29,31], 2051 => [31,31,31,32,31,31,30,29,30,29,30,30],
        2052 => [31,31,32,31,31,31,30,29,30,29,30,30], 2053 => [31,32,31,32,31,30,30,30,29,29,30,30],
        2054 => [31,32,31,32,31,30,30,30,29,30,29,31], 2055 => [31,31,32,31,31,31,30,29,30,29,30,30],
        2056 => [31,31,32,31,32,30,30,29,30,29,30,30], 2057 => [31,32,31,32,31,30,30,30,29,29,30,31],
        2058 => [30,32,31,32,31,30,30,30,29,30,29,31], 2059 => [31,31,32,31,31,31,30,29,30,29,30,30],
        2060 => [31,31,32,32,31,30,30,29,30,29,30,30], 2061 => [31,32,31,32,31,30,30,30,29,29,30,31],
        2062 => [30,32,31,32,31,31,29,30,29,30,29,31], 2063 => [31,31,32,31,31,31,30,29,30,29,30,30],
        2064 => [31,31,32,32,31,30,30,29,30,29,30,30], 2065 => [31,32,31,32,31,30,30,30,29,29,30,31],
        2066 => [31,31,31,32,31,31,29,30,30,29,29,31], 2067 => [31,31,32,31,31,31,30,29,30,29,30,30],
        2068 => [31,31,32,32,31,30,30,29,30,29,30,30], 2069 => [31,32,31,32,31,30,30,30,29,29,30,31],
        2070 => [31,31,31,32,31,31,29,30,30,29,30,30], 2071 => [31,31,32,31,31,31,30,29,30,29,30,30],
        2072 => [31,32,31,32,31,30,30,29,30,29,30,30], 2073 => [31,32,31,32,31,30,30,30,29,29,30,31],
        2074 => [31,31,31,32,31,31,30,29,30,29,30,30], 2075 => [31,31,32,31,31,31,30,29,30,29,30,30],
        2076 => [31,32,31,32,31,30,30,30,29,29,30,30], 2077 => [31,32,31,32,31,30,30,30,29,30,29,31],
        2078 => [31,31,31,32,31,31,30,29,30,29,30,30], 2079 => [31,31,32,31,31,31,30,29,30,29,30,30],
        // = Ticket::BS_DATA (the ticket's verified rows) — change both together
        2080 => [31,31,31,32,31,31,30,29,30,29,30,30], 2081 => [31,32,31,32,31,30,30,30,29,30,30,30],
        2082 => [30,32,31,32,31,30,30,30,29,30,30,30], 2083 => [31,31,32,31,31,30,30,30,29,30,30,30],
        2084 => [31,31,32,31,31,30,30,30,29,30,30,30],
        // projected rows (a licence valid-until date only)
        2085 => [31,32,31,32,30,31,30,30,29,30,30,30], 2086 => [30,32,31,32,31,30,30,30,29,30,30,30],
        2087 => [31,31,32,31,31,31,30,30,29,30,30,30], 2088 => [30,31,32,32,30,31,30,30,29,30,30,30],
        2089 => [30,32,31,32,31,30,30,30,29,30,30,30], 2090 => [30,32,31,32,31,30,30,30,29,30,30,30],
    ];

    /** @var array<int, int>|null day number (days since 1970-01-01 UTC) of 1 Baishakh, per BS year */
    private static ?array $starts = null;

    private function __construct() {}

    /** Days in BS month $m (1-12) of year $y; 0 when the year is not tabled. */
    public static function daysInMonth(int $y, int $m): int
    {
        return ($m >= 1 && $m <= 12) ? (self::MONTHS[$y][$m - 1] ?? 0) : 0;
    }

    /** "YYYY-MM-DD" (AD) of 1 Baishakh of BS year $y, or ''. */
    public static function newYear(int $y): string
    {
        $s = self::starts()[$y] ?? null;
        return $s === null ? '' : gmdate('Y-m-d', $s * 86400);
    }

    /** BS year / month / day -> "YYYY-MM-DD" in AD, or '' (not tabled / no such day). */
    public static function toAd(int $y, int $m, int $d): string
    {
        $len = self::daysInMonth($y, $m);
        if ($len === 0 || $d < 1 || $d > $len) {
            return '';
        }
        $day = self::starts()[$y];
        for ($i = 0; $i < $m - 1; $i++) {
            $day += self::MONTHS[$y][$i];
        }
        return gmdate('Y-m-d', ($day + $d - 1) * 86400);
    }

    /**
     * AD "YYYY-MM-DD" -> ['y'=>, 'm'=>, 'd'=>] in BS, or null outside the table.
     *
     * @return array{y: int, m: int, d: int}|null
     */
    public static function fromAd(string $iso): ?array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($iso), $p) !== 1 || !checkdate((int) $p[2], (int) $p[3], (int) $p[1])) {
            return null;
        }
        $target = intdiv(gmmktime(0, 0, 0, (int) $p[2], (int) $p[3], (int) $p[1]), 86400);
        foreach (self::starts() as $y => $start) {
            $off = $target - $start;
            if ($off < 0 || $off >= array_sum(self::MONTHS[$y])) {
                continue;
            }
            foreach (self::MONTHS[$y] as $i => $len) {
                if ($off < $len) {
                    return ['y' => $y, 'm' => $i + 1, 'd' => $off + 1];
                }
                $off -= $len;
            }
        }
        return null;
    }

    /**
     * A date as it was written, in BS, -> AD "YYYY-MM-DD", or ''.
     * Takes Devanagari or 0-9 digits in year-month-day order with any of - / . or spaces between
     * ("२०४८/०५/१२", "2048-5-12", "2048.05.12") and an optional mark ("BS", "B.S.", "वि.सं.", "बि.सं.").
     * Day-first ("12/05/2048") is accepted only when the year is clearly last.
     */
    public static function parse(string $written): string
    {
        $s = strtr($written, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
        $s = (string) preg_replace('/(?:(?<![a-z])[bv]\.?\s?s\.?(?![a-z])|[वब]ि\.?\s?सं\.?|साल)/iu', ' ', $s);
        $s = trim($s);
        if (preg_match('/^(\d{4})\s*[-\/.\s]\s*(\d{1,2})\s*[-\/.\s]\s*(\d{1,2})$/', $s, $p) === 1) {
            return self::toAd((int) $p[1], (int) $p[2], (int) $p[3]);
        }
        if (preg_match('/^(\d{1,2})\s*[-\/.\s]\s*(\d{1,2})\s*[-\/.\s]\s*(\d{4})$/', $s, $p) === 1) {
            return self::toAd((int) $p[3], (int) $p[2], (int) $p[1]);
        }
        return '';
    }

    /** @return array<int, int> */
    private static function starts(): array
    {
        if (self::$starts === null) {
            $day = intdiv((int) strtotime(self::EPOCH . ' UTC'), 86400);
            self::$starts = [];
            foreach (self::MONTHS as $y => $months) {
                self::$starts[$y] = $day;
                $day += array_sum($months);
            }
        }
        return self::$starts;
    }
}
