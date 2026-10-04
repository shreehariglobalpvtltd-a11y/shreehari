<?php
declare(strict_types=1);

/**
 * Agent statement — one verified dataset, two outputs (Oct 2026, owner brief §5–§7).
 *
 *   AgentStatement::data()     the statement for an agent and a date range:
 *                              opening balance, every ledger row with what it
 *                              means, the booking it belongs to (PNR, journey
 *                              date, passengers), the running balance, a
 *                              summary by kind, and the closing balance.
 *   AgentStatementPng::render() draws that SAME array as phone-readable PNG
 *                              pages (page numbers, summary on the last page).
 *
 * The only money source is AgentWallet::statement() — the function whose
 * closing balance tests prove equals the live wallet (accounting-people-test).
 * Nothing here writes to the ledger. The screen (admin/agent-statement.php)
 * and the PNG print the same numbers because they print the same array; the
 * summary is re-added from the rows and must reproduce the closing balance
 * exactly, or the statement is marked unbalanced and no PNG is produced.
 *
 * SIGN: like AgentWallet::netPosition(), net = cash − commission.
 *   positive  the agent owes the company        ("Agent owes company ₹X")
 *   negative  the company owes the agent        ("Company owes agent ₹X")
 * A row is Dr (debit to the agent) when it makes the agent owe more, Cr when
 * it makes the company owe more.
 */
final class AgentStatement
{
    /** Kinds, in the order the summary lists them. [label EN, label NE] */
    public const KINDS = [
        'earned'    => ['Commission earned', 'कमिसन आम्दानी'],
        'reversed'  => ['Commission reversed (cancelled)', 'कमिसन फिर्ता'],
        'salary'    => ['Salary', 'तलब'],
        'advance'   => ['Advance / loan given', 'अग्रिम / ऋण दिइएको'],
        'repaid'    => ['Loan repaid / written off', 'ऋण फिर्ता'],
        'adjust'    => ['Adjustment', 'समायोजन'],
        'payout'    => ['Paid to agent', 'एजेन्टलाई भुक्तानी'],
        'cash_due'  => ['Ticket cash collected by agent', 'एजेन्टले उठाएको टिकट नगद'],
        'cash_in'   => ['Cash received from agent', 'एजेन्टबाट नगद प्राप्त'],
        'cash_adj'  => ['Cash adjustment', 'नगद समायोजन'],
    ];

    /**
     * @return array{agent: array, from: string, to: string, opening: float, closing: float,
     *               rows: array<int, array>, sum: array<string, array{count: int, amount: float}>,
     *               balanced: bool, check: float, rate: array, generated: string, fingerprint: string}
     */
    public static function data(int $agentId, string $from, string $to): array
    {
        $st = AgentWallet::statement($agentId, $from, $to, '');

        $agent = Database::fetch('SELECT id, username, full_name, phone, role FROM admins WHERE id = :i', ['i' => $agentId]) ?: [];
        $agentOut = [
            'id'    => $agentId,
            'name'  => (string) ($agent['full_name'] ?? '') !== '' ? (string) $agent['full_name'] : (string) ($agent['username'] ?? ('#' . $agentId)),
            'user'  => (string) ($agent['username'] ?? ''),
            'code'  => AgentWallet::agentCodeLabel($agentId) !== '' ? AgentWallet::agentCodeLabel($agentId) : ('#' . $agentId),
            'phone' => (string) ($agent['phone'] ?? ''),
        ];

        // Booking facts for the rows that belong to a booking: journey date,
        // passengers still travelling (released seats excluded), route.
        $ids = [];
        foreach ($st['rows'] as $r) {
            if (!empty($r['booking_id'])) { $ids[(int) $r['booking_id']] = true; }
        }
        $facts = [];
        if ($ids) {
            $in = implode(',', array_map('intval', array_keys($ids)));
            try {
                foreach (Database::fetchAll(
                    "SELECT b.id, b.pnr, b.status,
                            (SELECT bl.travel_date FROM booking_legs bl WHERE bl.booking_id = b.id ORDER BY (bl.leg_type = 'outbound') DESC, bl.id LIMIT 1) AS travel_date,
                            (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id AND bs.released_at IS NULL) AS pax,
                            (SELECT CONCAT(r.from_city, ' → ', r.to_city) FROM booking_legs bl2
                               JOIN schedules s ON s.id = bl2.schedule_id JOIN routes r ON r.id = s.route_id
                              WHERE bl2.booking_id = b.id ORDER BY bl2.id LIMIT 1) AS route
                       FROM bookings b WHERE b.id IN ($in)"
                ) as $f) {
                    $facts[(int) $f['id']] = $f;
                }
            } catch (Throwable $e) {
                Logger::error('AgentStatement facts failed', ['agent' => $agentId, 'e' => $e->getMessage()]);
            }
        }

        $sum = [];
        foreach (array_keys(self::KINDS) as $k) { $sum[$k] = ['count' => 0, 'amount' => 0.0]; }

        $rows = [];
        foreach ($st['rows'] as $r) {
            $amt    = round((float) $r['amount'], 2);
            $acct   = (string) $r['account'];
            $type   = (string) $r['entry_type'];
            $ref    = (string) ($r['ref'] ?? '');
            $kind   = self::kind($acct, $type, $ref, $amt);
            // effect on "agent owes": + makes the agent owe more (Dr), − (Cr)
            $effect = round($acct === 'cash' ? $amt : -$amt, 2);
            $bid    = (int) ($r['booking_id'] ?? 0);
            $fact   = $bid > 0 ? ($facts[$bid] ?? null) : null;

            $sum[$kind]['count']++;
            // per kind: the signed effect on "agent owes" (+ Dr, − Cr), so the
            // summary reads the same way as the rows and adds up to the closing
            $sum[$kind]['amount'] = round($sum[$kind]['amount'] + $effect, 2);

            $rows[] = [
                'id'      => (int) $r['id'],
                'date'    => substr((string) $r['created_at'], 0, 10),
                'time'    => substr((string) $r['created_at'], 11, 5),
                'kind'    => $kind,
                'label'   => self::KINDS[$kind][0],
                'labelNe' => self::KINDS[$kind][1],
                'ref'     => (string) ($r['pnr'] ?? '') !== '' ? (string) $r['pnr'] : $ref,
                'note'    => (string) ($r['note'] ?? ''),
                'travel'  => $fact ? (string) ($fact['travel_date'] ?? '') : '',
                'pax'     => $fact ? (int) $fact['pax'] : null,
                'route'   => $fact ? (string) ($fact['route'] ?? '') : '',
                'dr'      => $effect > 0 ? $effect : 0.0,
                'cr'      => $effect < 0 ? -$effect : 0.0,
                'running' => round((float) $r['running'], 2),
                'by'      => (string) ($r['by_name'] ?? ''),
            ];
        }

        // Re-add the summary in "agent owes" terms; it must land on the closing.
        $check = (float) $st['opening'];
        foreach ($rows as $row) { $check = round($check + $row['dr'] - $row['cr'], 2); }
        $balanced = abs($check - (float) $st['closing']) < 0.01;
        if (!$balanced) {
            Logger::error('AgentStatement unbalanced', ['agent' => $agentId, 'from' => $from, 'to' => $to, 'check' => $check, 'closing' => $st['closing']]);
        }

        $rate = [];
        try { $rate = AgentReport::rateCard($agentId); } catch (Throwable $e) { $rate = []; }

        $out = [
            'agent'     => $agentOut,
            'from'      => $from,
            'to'        => $to,
            'opening'   => round((float) $st['opening'], 2),
            'closing'   => round((float) $st['closing'], 2),
            'rows'      => $rows,
            'sum'       => $sum,
            'balanced'  => $balanced,
            'check'     => $check,
            'rate'      => $rate,
            'generated' => date('Y-m-d H:i'),
        ];
        $co = Settings::company();
        $out['fingerprint'] = md5(json_encode([$out['agent'], $from, $to, $out['opening'], $out['closing'], $rows,
            $rate['label'] ?? '', $co['legal'] ?? '', $co['cin'] ?? '', $co['phone'] ?? '', $co['web'] ?? '', AgentStatementPng::LAYOUT]));
        return $out;
    }

    /** What a ledger row is, in the owner's words. */
    public static function kind(string $account, string $type, string $ref, float $amount): string
    {
        if ($account === 'cash') {
            return match ($type) {
                'cash_due'      => 'cash_due',
                'cash_handover' => 'cash_in',
                default         => 'cash_adj',
            };
        }
        return match ($type) {
            'commission'      => 'earned',
            'commission_void' => 'reversed',
            'payout'          => 'payout',
            default           => str_starts_with(strtoupper($ref), 'SALARY') ? 'salary'
                : (str_starts_with(strtoupper($ref), 'ADVANCE') ? ($amount < 0 ? 'advance' : 'repaid') : 'adjust'),
        };
    }

    /** "Agent owes company ₹1,200" / "Company owes agent ₹800" / "Settled". */
    public static function owes(float $net, string $lang = 'en'): string
    {
        if (abs($net) < 0.5) {
            return $lang === 'ne' ? 'हिसाब मिलेको — कसैले तिर्नु पर्दैन' : 'Settled — nothing owed';
        }
        if ($net > 0) {
            return $lang === 'ne' ? 'एजेन्टले कम्पनीलाई तिर्नुपर्ने ' . inr($net) : 'Agent owes company ' . inr($net);
        }
        return $lang === 'ne' ? 'कम्पनीले एजेन्टलाई तिर्नुपर्ने ' . inr(-$net) : 'Company owes agent ' . inr(-$net);
    }

    /** Who may see this agent's statement: the agent themself, or an office role with commissions.view. */
    public static function mayView(int $agentId): bool
    {
        if (Auth::isCounterAgent()) {
            return Auth::bookingScopeAdminId() === $agentId;
        }
        return Auth::can('commissions.view');
    }
}

/**
 * Phone-readable PNG pages of AgentStatement::data(). 1080 px wide (a phone
 * screen at 1:1), text never shrunk to fit: a long statement simply becomes
 * more pages. Every page: logo band, agent + period, page N of M. Rows:
 * date, what it is (English + Nepali), PNR / voucher, journey date, people,
 * rate note, Dr / Cr amount and the running "who owes whom". The last page
 * adds the summary and the closing sentence.
 */
final class AgentStatementPng
{
    public const LAYOUT = 'stmt-v2';
    public const W = 1080;
    public const H = 1720;
    private const M = 48;
    private const ROW_H = 118;
    private const FIRST_ROWS = 8;
    private const NEXT_ROWS = 11;
    private const SUMMARY_ROWS = 4;   // rows that still fit above the summary on the last page

    /** @return array<int, array{0:int,1:int}> [start, count] per page; the summary sits on the last. */
    public static function pages(array $rows): array
    {
        $n = count($rows);
        $chunks = [];
        $i = 0;
        $cap = self::FIRST_ROWS;
        while ($i < $n) {
            $take = min($cap, $n - $i);
            $chunks[] = [$i, $take];
            $i += $take;
            $cap = self::NEXT_ROWS;
        }
        if (!$chunks) {
            return [[0, 0]];
        }
        // The summary needs its real height (one line per kind present); if the
        // last page cannot hold its rows AND the summary, the summary gets a page.
        [$s, $c] = $chunks[count($chunks) - 1];
        $kinds   = count(array_unique(array_column($rows, 'kind')));
        $boxH    = 300 + 46 * $kinds;                          // same formula as draw()
        $top     = count($chunks) === 1 ? 542 : 394;           // rows start: after the opening box on page 1
        $roomFor = max(0, intdiv((self::H - 90) - $boxH - $top - 32, self::ROW_H));
        if ($c > $roomFor) {
            $chunks[] = [$n, 0];
        }
        return $chunks;
    }

    public static function dir(int $agentId): string
    {
        return UPLOAD_PATH . '/statements/agent-' . $agentId;
    }

    /**
     * Render (or reuse) page $page of the statement. Same data + same layout =
     * same file (fingerprint in the name), so a re-download is instant.
     *
     * @return array{path: string, file: string, page: int, pages: int}
     */
    public static function render(array $d, int $page): array
    {
        if (!$d['balanced']) {
            throw new RuntimeException('This statement does not add up to the ledger balance, so no image was made. Please report it to the office.');
        }
        $chunks = self::pages($d['rows']);
        $pages  = count($chunks);
        if ($page < 1 || $page > $pages) {
            throw new RuntimeException('That page does not exist.');
        }
        $dir = self::dir((int) $d['agent']['id']);
        ensureDir($dir);
        $file = sprintf('statement-%s-to-%s-p%dof%d-%s.png', $d['from'], $d['to'], $page, $pages, substr($d['fingerprint'], 0, 10));
        $path = $dir . '/' . $file;
        if (!is_file($path)) {
            self::sweep($dir, $d['from'], $d['to'], $d['fingerprint']);
            self::draw($d, $chunks, $page, $path);
        }
        return ['path' => $path, 'file' => $file, 'page' => $page, 'pages' => $pages];
    }

    /** Drop older renders of the same period (data changed since). */
    private static function sweep(string $dir, string $from, string $to, string $fp): void
    {
        foreach (glob($dir . '/statement-' . $from . '-to-' . $to . '-p*.png') ?: [] as $old) {
            if (!str_contains($old, substr($fp, 0, 10))) { @unlink($old); }
        }
    }

    private static function font(): string
    {
        return dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari.ttf';
    }

    private static function t($im, float $size, int $x, int $y, int $col, string $text, bool $bold = false): void
    {
        if ($text === '') { return; }
        $flat = dev_shape($text);
        $box = imagettfbbox($size, 0, self::font(), $flat);
        $baseline = $y - (int) min($box[5], $box[7]);
        if (class_exists('DevShape') && DevShape::needs($text)
            && DevShape::gdText($im, $size, $x, $baseline, $col, $text, $bold ? [[0, 0], [1, 0]] : [[0, 0]])) {
            return;
        }
        imagettftext($im, $size, 0, $x, $baseline, $col, self::font(), $flat);
        if ($bold) { imagettftext($im, $size, 0, $x + 1, $baseline, $col, self::font(), $flat); }
    }

    private static function w(float $size, string $text): int
    {
        if ($text === '') { return 0; }
        if (class_exists('DevShape') && DevShape::needs($text) && ($sw = DevShape::gdWidth($size, $text)) !== null) {
            return $sw;
        }
        $box = imagettfbbox($size, 0, self::font(), dev_shape($text));
        return (int) (max($box[2], $box[4]) - min($box[0], $box[6]));
    }

    private static function tR($im, float $size, int $xr, int $y, int $col, string $text, bool $bold = false): void
    {
        self::t($im, $size, $xr - self::w($size, $text), $y, $col, $text, $bold);
    }

    private static function fit(float $size, string $text, int $maxW): string
    {
        if ($text === '' || self::w($size, $text) <= $maxW) { return $text; }
        $t = $text;
        while (mb_strlen($t) > 1 && self::w($size, $t . '..') > $maxW) { $t = mb_substr($t, 0, mb_strlen($t) - 1); }
        return rtrim($t) . '..';
    }

    private static function box($im, int $x1, int $y1, int $x2, int $y2, int $r, ?int $fill, ?int $border = null): void
    {
        if ($fill !== null) {
            imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $fill);
            imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $fill);
            foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
                imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $fill);
            }
        }
        if ($border !== null) { imagerectangle($im, $x1, $y1, $x2, $y2, $border); }
    }

    private static function d(string $iso): string
    {
        return $iso === '' ? '' : date('j M Y', (int) strtotime($iso));
    }

    private static function draw(array $d, array $chunks, int $page, string $path): void
    {
        $W = self::W; $H = self::H; $M = self::M;
        $im = imagecreatetruecolor($W, $H);
        imagesavealpha($im, false);
        // design-system colours (ds.css, light theme)
        $c = static fn(string $h) => imagecolorallocate($im, hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2)));
        $white = $c('#FFFFFF'); $page_ = $c('#F4F6FB'); $navy = $c('#0C306C'); $red = $c('#DC143C');
        $ink = $c('#16233C'); $muted = $c('#5C6B85'); $line = $c('#E4E9F1'); $lineS = $c('#D5DCE8');
        $royal = $c('#0054A8'); $tint = $c('#EEF4FC'); $ok = $c('#11703F'); $okBg = $c('#E4F6EC');
        $bad = $c('#B42525'); $badBg = $c('#FBE3E3'); $pale = $c('#B9C9E6'); $sunk = $c('#F8FAFD');
        imagefilledrectangle($im, 0, 0, $W, $H, $page_);

        $co = Settings::company();
        $pages = count($chunks);
        [$start, $count] = $chunks[$page - 1];
        $isLast = $page === $pages;

        // strings drawn with Devanagari shaping, primed in one batch
        $prime = ['एजेन्ट हिसाब विवरण', 'सुरु बाँकी', 'अन्तिम बाँकी', 'यात्रा', 'जना', 'सारांश'];
        foreach (AgentStatement::KINDS as $k) { $prime[] = $k[1]; }
        $prime[] = AgentStatement::owes($d['closing'], 'ne');
        $prime[] = AgentStatement::owes($d['opening'], 'ne');
        $prime[] = (string) $d['agent']['name'];
        if (class_exists('DevShape')) { DevShape::prime($prime); }

        /* ---------- header band ---------- */
        imagefilledrectangle($im, 0, 0, $W, 190, $navy);
        imagefilledrectangle($im, 0, 190, $W, 196, $red);
        $tx = $M;
        $logo = Ticket::logoFile();
        if ($logo !== null) {
            $li = @imagecreatefromstring((string) file_get_contents($logo));
            if ($li !== false) {
                if (!imageistruecolor($li)) { imagepalettetotruecolor($li); }
                $lh = 96;
                $lw = (int) round(imagesx($li) * $lh / max(1, imagesy($li)));
                self::box($im, $M - 8, 34, $M + $lw + 8, 34 + $lh + 16, 12, $white);
                imagecopyresampled($im, $li, $M, 42, 0, 0, $lw, $lh, imagesx($li), imagesy($li));
                imagedestroy($li);
                $tx = $M + $lw + 32;
            }
        }
        self::t($im, 26, $tx, 34, $white, 'S HARI GLOBAL PVT LTD', true);
        self::t($im, 19, $tx, 80, $pale, 'AGENT STATEMENT', true);
        self::t($im, 19, $tx + self::w(19, 'AGENT STATEMENT') + 16, 80, $pale, 'एजेन्ट हिसाब विवरण', false);
        self::t($im, 14, $tx, 124, $pale, self::fit(14, 'CIN ' . $co['cin'] . '  ·  ' . $co['phone'] . '  ·  ' . $co['web'], $W - $tx - $M), false);

        /* ---------- agent + period ---------- */
        $y = 222;
        self::box($im, $M, $y, $W - $M, $y + 150, 18, $white);
        self::t($im, 24, $M + 24, $y + 18, $ink, self::fit(24, (string) $d['agent']['name'], 640), true);
        self::t($im, 16, $M + 24, $y + 64, $muted, 'Agent ID ' . $d['agent']['code'] . ($d['agent']['phone'] !== '' ? '   ·   ' . $d['agent']['phone'] : ''), false);
        self::t($im, 16, $M + 24, $y + 100, $muted, 'Period  ' . self::d($d['from']) . '  –  ' . self::d($d['to']), true);
        self::tR($im, 16, $W - $M - 24, $y + 18, $royal, 'PAGE ' . $page . ' OF ' . $pages, true);
        if (!empty($d['rate']['label'])) {
            self::tR($im, 15, $W - $M - 24, $y + 64, $muted, 'Current rate: ' . $d['rate']['label'], false);
        }
        $y += 172;

        /* ---------- opening balance + how to read (first page) ---------- */
        if ($page === 1) {
            self::box($im, $M, $y, $W - $M, $y + 128, 18, $tint);
            self::t($im, 17, $M + 24, $y + 16, $muted, 'Opening balance on ' . self::d($d['from']) . '  ·  सुरु बाँकी', false);
            self::t($im, 23, $M + 24, $y + 50, $ink, AgentStatement::owes($d['opening']), true);
            self::t($im, 14, $M + 24, $y + 92, $muted, 'Dr = agent owes more (debit to agent)   ·   Cr = company owes more (credit to agent)', false);
            $y += 148;
        }

        /* ---------- rows ---------- */
        $rows = array_slice($d['rows'], $start, $count);
        if ($rows) {
            self::box($im, $M, $y, $W - $M, $y + 16 + count($rows) * self::ROW_H, 18, $white);
            $ry = $y + 8;
            foreach ($rows as $i => $r) {
                if ($i > 0) { imageline($im, $M + 20, $ry, $W - $M - 20, $ry, $line); }
                $x = $M + 24;
                // line 1: date · what · ref ............ amount Dr/Cr
                self::t($im, 15, $x, $ry + 14, $muted, date('j M', (int) strtotime($r['date'])) . ($r['time'] !== '' ? ' ' . $r['time'] : ''), false);
                self::t($im, 18, $x + 150, $ry + 12, $ink, self::fit(18, $r['label'], 470), true);
                $isDr = $r['dr'] > 0;
                $amt  = ($isDr ? $r['dr'] : $r['cr']);
                $amtS = inr($amt) . ($isDr ? '  Dr' : '  Cr');
                self::tR($im, 20, $W - $M - 24, $ry + 10, $isDr ? $bad : $ok, $amtS, true);
                // line 2: Nepali kind · ref · journey · people
                $bits = [$r['labelNe']];
                if ($r['ref'] !== '') { $bits[] = $r['ref']; }
                if ($r['travel'] !== '') { $bits[] = 'Travel ' . date('j M', (int) strtotime($r['travel'])); }
                if ($r['pax'] !== null) { $bits[] = $r['pax'] . ' जना'; }
                self::t($im, 15, $x + 150, $ry + 48, $muted, self::fit(15, implode('  ·  ', $bits), 600), false);
                // line 3: rate / note ............ running position
                $note = $r['note'];
                self::t($im, 14, $x + 150, $ry + 80, $muted, self::fit(14, $note, 430), false);
                self::tR($im, 14, $W - $M - 24, $ry + 80, $ink, self::fit(14, AgentStatement::owes($r['running']), 330), false);
                $ry += self::ROW_H;
            }
            $y = $ry + 24;
        } elseif ($page === 1 && !$d['rows']) {
            self::box($im, $M, $y, $W - $M, $y + 90, 18, $white);
            self::t($im, 18, $M + 24, $y + 30, $muted, 'No ledger entries in this period.', false);
            $y += 110;
        }

        /* ---------- summary (last page) ---------- */
        if ($isLast) {
            $sumRows = [];
            foreach (AgentStatement::KINDS as $k => $lbl) {
                $s = $d['sum'][$k];
                if ($s['count'] === 0) { continue; }
                $sumRows[] = [$lbl[0] . ' · ' . $lbl[1] . ' (' . $s['count'] . ')', $s['amount'], $k];
            }
            $boxH = 150 + count($sumRows) * 46 + 150;
            // pages() guarantees the box fits below the rows; never lift it over them
            self::box($im, $M, $y, $W - $M, $y + $boxH, 18, $white);
            self::t($im, 21, $M + 24, $y + 18, $ink, 'Summary  ·  सारांश', true);
            $sy = $y + 66;
            self::t($im, 16, $M + 24, $sy, $muted, 'Opening balance  ·  सुरु बाँकी', false);
            self::tR($im, 16, $W - $M - 24, $sy, $ink, AgentStatement::owes($d['opening']), true);
            $sy += 46;
            foreach ($sumRows as [$label, $amount, $k]) {
                $sign   = $amount > 0 ? 'Dr ' : 'Cr ';
                self::t($im, 16, $M + 24, $sy, $ink, self::fit(16, $label, 640), false);
                self::tR($im, 16, $W - $M - 24, $sy, ($sign === 'Dr ') ? $bad : $ok, $sign . inr(abs($amount)), true);
                $sy += 46;
            }
            imageline($im, $M + 20, $sy + 4, $W - $M - 20, $sy + 4, $lineS);
            $sy += 22;
            $owe = AgentStatement::owes($d['closing']);
            self::box($im, $M + 16, $sy, $W - $M - 16, $sy + 104, 14, $d['closing'] > 0.5 ? $badBg : ($d['closing'] < -0.5 ? $okBg : $sunk));
            self::t($im, 16, $M + 36, $sy + 12, $muted, 'Closing balance on ' . self::d($d['to']) . '  ·  अन्तिम बाँकी', false);
            self::t($im, 24, $M + 36, $sy + 44, $ink, $owe, true);
            $sy += 120;
            self::t($im, 13, $M + 24, $sy, $muted, self::fit(13, AgentStatement::owes($d['closing'], 'ne'), $W - 2 * $M - 48), false);
        }

        /* ---------- footer ---------- */
        imageline($im, $M, $H - 76, $W - $M, $H - 76, $lineS);
        self::t($im, 13, $M, $H - 62, $muted, self::fit(13, 'From the S Hari Global agent ledger. Generated ' . date('j M Y, g:i A', (int) strtotime($d['generated'])) . '  ·  ' . $co['legal'], $W - 2 * $M - 160), false);
        self::tR($im, 13, $W - $M, $H - 62, $muted, 'Page ' . $page . ' / ' . $pages, true);
        self::t($im, 12, $M, $H - 36, $muted, self::fit(12, 'Questions about an entry: call the office ' . $co['phone'] . '. Amounts in Indian rupees.', $W - 2 * $M), false);

        $tmp = $path . '.tmp';
        if (!imagepng($im, $tmp, 6)) {
            imagedestroy($im);
            @unlink($tmp);
            throw new RuntimeException('Could not write the statement page.');
        }
        imagedestroy($im);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not place the statement page.');
        }
    }
}
