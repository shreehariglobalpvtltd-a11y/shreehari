<?php
/**
 * =====================================================================
 *  AiReview — Claude reviews the closed day, every night at 00:00 IST
 *  (19 Nov 2026, unit u8 / AI-1).
 *
 *  Owner: Claude should look at the whole day at night and tell us what
 *  went wrong, what the bot must learn, and how the office should work.
 *
 *  One run (cron/ai-review.php, 00:20 IST, for YESTERDAY):
 *    facts   CODE computes every number for the closed IST day — bookings
 *            by route / direction / seller channel, passengers travelling,
 *            payments by method and status (pending / failed included),
 *            cancellations and refund requests, WhatsApp delivery failures
 *            by error code, AI turns that failed or were handed to a person,
 *            the top unanswered questions (already redacted), agents'
 *            activity (staff first names only), real stop timings when the
 *            GPS learned any. No passenger name, phone, PNR or chat text is
 *            sent; free text goes through AiKb::redact().
 *    ask     ONE Opus request (ai_review_model, effort high, structured JSON,
 *            NO tools — the night job cannot sell, cancel or change
 *            anything) through AiTeach::call(), the project's one raw
 *            Claude client. The briefing (the chat prompt's FACTS block
 *            only — no clock, no chat STYLE rules) comes first, the day's
 *            facts after it. No cache mark: one call a night never reads a
 *            cache, so the mark would only add the write premium.
 *            A source that cannot be read is sent as 'unavailable'.
 *    save    the report → ai_reviews (one row per day; shown on
 *            admin/ai-center.php); the "teach the bot" Q&A pairs (ne/hi/en)
 *            → the AI knowledge base through AiTeach::saveArticle() as
 *            DRAFTS, published only when ai_review_autolearn = 1 — and never
 *            with a fare / time / count in them (AiLearn::numbersOk);
 *            recommendations and anomalies are display only. The 3-line
 *            Nepali summary goes into the 06:00 brain digest.
 *
 *  Money: the worst case of the call (input estimate + max_tokens of
 *  output) must fit ai_review_usd (1.50) for the night, ai_review_month_usd
 *  (45) for the month and the owner's daily ceiling (AiMeter); otherwise a
 *  'skipped' row says why. The real cost is priced from the usage block and
 *  recorded in ai_usage (tag night_review). No key → a 'skipped: no key'
 *  row. A failure is recorded once and never retried in a loop.
 *
 *  Never throws at its caller.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/aiteach.php';

final class AiReview
{
    public const MAX_TEACH = 8;
    public const MAX_RECS  = 6;
    public const MAX_WRONG = 8;
    public const MAX_ANOM  = 6;
    /** What a fact reads when its source could not be read (never [] / 0). */
    public const NA        = 'unavailable';

    /** Test seams — null in production. */
    public static ?Closure $facts  = null;   // fn(string $day): array
    public static ?Closure $brief  = null;   // fn(): string  (stable company briefing)
    public static ?Closure $titles = null;   // fn(): list<string> existing KB titles

    private function __construct() {}

    /* ---------------------------------------------------------- settings */

    public static function enabled(): bool
    {
        return Settings::getBool('ai_review_on', true);
    }

    public static function hasKey(): bool
    {
        return trim(Settings::getString('anthropic_api_key', '')) !== '' && function_exists('curl_init');
    }

    public static function model(): string
    {
        $m = trim(Settings::getString('ai_review_model', 'claude-opus-5-5'));
        return $m !== '' ? $m : 'claude-opus-5-5';
    }

    public static function hour(): int
    {
        return max(0, min(23, Settings::getInt('ai_review_hour', 0)));
    }

    public static function nightUsd(): float
    {
        return max(0.0, (float) Settings::getString('ai_review_usd', '1.50'));
    }

    public static function monthUsd(): float
    {
        return max(0.0, (float) Settings::getString('ai_review_month_usd', '45'));
    }

    public static function maxTokens(): int
    {
        return max(4000, min(64000, Settings::getInt('ai_review_max_tokens', 16000)));
    }

    public static function ready(): bool
    {
        try {
            return (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ai_reviews'", [], 0) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The closed IST day the 00:00 run reviews: yesterday. */
    public static function closedDay(?int $now = null): string
    {
        return date('Y-m-d', ($now ?? time()) - 86400);
    }

    /* ---------------------------------------------------------- the run */

    /**
     * Review one closed day.
     *
     * @return array{status: string, day: string, note: string, cost: float, kb_saved: int, kb_published: int, facts?: array, prompt?: array}
     */
    public static function run(string $day, bool $dry = false, bool $force = false): array
    {
        $out = ['status' => 'skipped', 'day' => $day, 'note' => '', 'cost' => 0.0, 'kb_saved' => 0, 'kb_published' => 0];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            $out['note'] = 'bad day';
            return $out;
        }
        if (!self::enabled()) {
            $out['note'] = 'ai_review_on is off';
            return $out;
        }
        if (!$dry && !self::ready()) {
            $out['note'] = 'ai_reviews table missing (run database/upgrade-2026-11-19-u8-ai-review.sql)';
            return $out;
        }
        if (!$dry && !$force) {
            $have = self::row($day);
            if ($have !== null && (string) $have['status'] === 'ok') {
                $out['status'] = 'ok';
                $out['note'] = 'already reviewed';
                return $out;
            }
        }

        try {
            $facts = self::facts($day);
            [$system, $task] = self::prompt($day, $facts);
        } catch (Throwable $e) {
            Logger::warning('AI review: facts failed: ' . $e->getMessage(), [], 'ai');
            $out['status'] = 'failed';
            $out['note'] = 'facts failed';
            if (!$dry) {
                self::store($day, 'failed', 'facts failed: ' . mb_substr($e->getMessage(), 0, 160), [], null);
            }
            return $out;
        }
        $est = self::estimate($system, $task);

        if ($dry) {
            $out['status'] = 'dry';
            $out['note'] = 'dry run: no API call, nothing saved; worst case $' . number_format($est, 2);
            $out['facts'] = $facts;
            $out['prompt'] = ['system' => $system, 'task' => $task, 'model' => self::model(), 'effort' => 'high',
                              'max_tokens' => self::maxTokens(), 'estimate_usd' => round($est, 4)];
            return $out;
        }
        if (!self::hasKey()) {
            $out['note'] = 'skipped: no key';
            self::store($day, 'skipped', $out['note'], $facts, null);
            return $out;
        }
        $why = self::overCap($est);
        if ($why !== '') {
            $out['note'] = 'skipped: ' . $why;
            self::store($day, 'skipped', $out['note'], $facts, null);
            Logger::info('AI review skipped', ['day' => $day, 'why' => $why], 'ai');
            return $out;
        }

        require_once __DIR__ . '/aimeter.php';
        AiMeter::context(['channel' => 'cron', 'role' => 'admin', 'tag' => 'night_review']);
        $t0 = microtime(true);
        // No cache mark (fix of 19 Nov): one call a night never reads a cache
        // (5-minute life), so the mark would only add the 1.25x write charge.
        $r = AiTeach::call($system, $task, self::schema(), self::maxTokens(), self::model(), 'high', false);
        AiMeter::record('anthropic', (string) $r['model'], AiMeter::fromAnthropic($r['usage']),
            (int) round((microtime(true) - $t0) * 1000), $r['json'] !== null);
        $out['cost'] = round((float) $r['cost'], 4);
        $usage = ['in' => (int) ($r['usage']['input_tokens'] ?? 0) + (int) ($r['usage']['cache_read_input_tokens'] ?? 0)
                         + (int) ($r['usage']['cache_creation_input_tokens'] ?? 0),
                  'out' => (int) ($r['usage']['output_tokens'] ?? 0)];

        if ($r['json'] === null) {
            $out['status'] = 'failed';
            $out['note'] = (string) $r['note'];
            self::store($day, 'failed', $out['note'], $facts, null, $out['cost'], $usage, (string) $r['model']);
            return $out;
        }

        $review = self::clean($r['json']);
        [$saved, $published] = self::learn($day, $review['teach_bot']);
        $out['status'] = 'ok';
        $out['kb_saved'] = $saved;
        $out['kb_published'] = $published;
        $out['note'] = count($review['recommendations']) . ' recommendations, ' . $saved . ' KB '
            . ($published > 0 ? '(' . $published . ' published)' : 'drafts');
        self::store($day, 'ok', $out['note'], $facts, $review, $out['cost'], $usage, (string) $r['model'], $saved, $published);
        return $out;
    }

    /** Worst case of one call in USD: all input (2 bytes a token, generous for Devanagari) + every max_token as output. */
    public static function estimate(string $system, string $task): float
    {
        $p = AiTeach::cost(['input_tokens' => (int) ceil((strlen($system) + strlen($task)) / 2),
                            'output_tokens' => self::maxTokens()], self::model());
        return round($p, 4);
    }

    /** '' when the estimate fits every cap, else the reason. */
    public static function overCap(float $est): string
    {
        $night = self::nightUsd();
        if ($night <= 0 || $est > $night) {
            return 'estimate $' . number_format($est, 2) . ' is over the night cap $' . number_format($night, 2);
        }
        $month = self::monthUsd();
        $spent = self::monthSpent();
        if ($month <= 0 || $spent + $est > $month) {
            return 'month cap $' . number_format($month, 2) . ' (spent $' . number_format($spent, 2) . ')';
        }
        try {
            require_once __DIR__ . '/aimeter.php';
            $b = AiMeter::budget();
            if ($b > 0 && AiMeter::spentToday() + $est > $b) {
                return 'daily AI ceiling $' . number_format($b, 2) . ' (spent today $' . number_format(AiMeter::spentToday(), 2) . ')';
            }
        } catch (Throwable $e) {
            // the meter is a help; the review caps above still hold
        }
        return '';
    }

    public static function monthSpent(): float
    {
        try {
            return (float) Database::scalar(
                'SELECT COALESCE(SUM(cost_usd), 0) FROM ai_reviews WHERE created_at >= :m', ['m' => date('Y-m-01') . ' 00:00:00'], 0);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /* ---------------------------------------------------------- facts */

    /** @return array<string,mixed> */
    public static function facts(string $day): array
    {
        if (self::$facts !== null) {
            return (self::$facts)($day);
        }
        require_once __DIR__ . '/fare.php';
        require_once __DIR__ . '/aiknowledge.php';
        $a = $day . ' 00:00:00';
        $b = date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00';
        $p = ['a' => $a, 'b' => $b];
        $q = static function (callable $fn, mixed $d) {
            try { return $fn(); } catch (Throwable $e) { return $d; }
        };
        /* Fix of 19 Nov (review): a source that cannot be read is reported as
           'unavailable', never as [] or 0, so Claude cannot take a missing
           table for a quiet day. */
        $na = self::NA;
        $n = static function (callable $fn) use ($na): int|string {
            try { return (int) $fn(); } catch (Throwable $e) { return $na; }
        };
        $redact = static fn(string $t): string => mb_substr(trim(preg_replace('/\d{6,}/', '[number]', AiKb::redact($t)) ?? ''), 0, 160);
        $f = ['day' => $day, 'currency' => 'INR'];

        /* Bookings made today: route, direction, seller channel, status. */
        $aiBooked = [];
        foreach ($q(static fn() => Database::fetchAll(
            'SELECT DISTINCT booking_id FROM ai_agent_calls WHERE ok = 1 AND booking_id IS NOT NULL AND created_at >= :a AND created_at < :b', $p), []) as $r) {
            $aiBooked[(int) $r['booking_id']] = true;
        }
        $rows = $q(static fn() => Database::fetchAll(
            "SELECT b.id, b.status, b.source, b.is_cod, b.total_amount, b.sold_by_admin_id, b.booked_by_admin_id,
                    r.route_code, r.from_city, r.to_city,
                    (SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id) AS pax
               FROM bookings b
               LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
               LEFT JOIN schedules s ON s.id = bl.schedule_id
               LEFT JOIN routes r ON r.id = s.route_id
              WHERE b.created_at >= :a AND b.created_at < :b", $p), []);
        $bk = ['made' => 0, 'seats' => 0, 'amount_inr' => 0.0, 'by_status' => [], 'by_route' => [], 'by_channel' => []];
        $sellerIds = [];
        foreach ($rows as $r) {
            $st = (string) $r['status'];
            $bk['made']++;
            $bk['by_status'][$st] = ($bk['by_status'][$st] ?? 0) + 1;
            $live = !in_array($st, ['cancelled', 'rejected', 'expired'], true);
            $route = $r['route_code'] !== null
                ? $r['from_city'] . ' → ' . $r['to_city'] . ' (' . (Fare::routeDir(['to_city' => (string) $r['to_city']]) === 'toNepal' ? 'to Nepal' : 'to India') . ')'
                : 'no route';
            $ch = isset($aiBooked[(int) $r['id']]) ? 'ai_agent' : ((string) $r['source'] !== '' ? (string) $r['source'] : 'unknown');
            if ((int) $r['is_cod'] === 1) {
                $ch .= '+pay_at_boarding';
            }
            foreach (['by_route' => $route, 'by_channel' => $ch] as $key => $label) {
                $bk[$key][$label] ??= ['bookings' => 0, 'seats' => 0, 'amount_inr' => 0.0];
                $bk[$key][$label]['bookings']++;
                if ($live) {
                    $bk[$key][$label]['seats'] += (int) $r['pax'];
                    $bk[$key][$label]['amount_inr'] += (float) $r['total_amount'];
                }
            }
            if ($live) {
                $bk['seats'] += (int) $r['pax'];
                $bk['amount_inr'] += (float) $r['total_amount'];
            }
            $sid = (int) ($r['sold_by_admin_id'] ?: $r['booked_by_admin_id']);
            if ($sid > 0) {
                $sellerIds[$sid][] = ['pax' => $live ? (int) $r['pax'] : 0, 'amt' => $live ? (float) $r['total_amount'] : 0.0, 'live' => $live];
            }
        }
        $bk['amount_inr'] = round($bk['amount_inr']);
        foreach (['by_route', 'by_channel'] as $key) {
            foreach ($bk[$key] as &$v) {
                $v['amount_inr'] = round($v['amount_inr']);
            }
            unset($v);
        }
        $f['bookings_made'] = $bk;

        /* Passengers travelling today (any booking date). */
        $f['travelling'] = $q(static fn() => array_map(static fn($r) => [
            'route' => $r['from_city'] . ' → ' . $r['to_city'], 'passengers' => (int) $r['n'], 'boarded_marked' => (int) $r['boarded']],
            Database::fetchAll(
                "SELECT r.from_city, r.to_city, COUNT(bp.id) AS n, SUM(bp.boarded_at IS NOT NULL) AS boarded
                   FROM booking_legs bl
                   JOIN bookings b ON b.id = bl.booking_id AND b.status IN ('confirmed','completed')
                   JOIN schedules s ON s.id = bl.schedule_id
                   JOIN routes r ON r.id = s.route_id
                   JOIN booking_passengers bp ON bp.booking_id = b.id
                  WHERE bl.travel_date = :d GROUP BY r.id", ['d' => $day])), $na);

        /* Payments recorded today by method and status; what still waits. */
        $f['payments'] = $q(static fn() => array_map(static fn($r) => [
            'method' => (string) $r['method'], 'status' => (string) $r['status'], 'count' => (int) $r['n'], 'amount' => round((float) $r['amt'])],
            Database::fetchAll(
                'SELECT method, status, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amt FROM payments
                  WHERE created_at >= :a AND created_at < :b GROUP BY method, status ORDER BY n DESC', $p)), $na);
        $f['payments_waiting_now'] = [
            'pending_verification' => $n(static fn() => Database::scalar("SELECT COUNT(*) FROM payments WHERE status = 'pending'", [], 0)),
            'pay_at_boarding_unsettled' => $n(static fn() => Database::scalar("SELECT COUNT(*) FROM payments WHERE status = 'cod_pending'", [], 0)),
            'rejected_today' => $n(static fn() => Database::scalar(
                "SELECT COUNT(*) FROM payments WHERE status = 'rejected' AND updated_at >= :a AND updated_at < :b", $p, 0)),
        ];

        /* Cancellations and refunds (the owner settles refunds by hand). */
        $f['cancellations'] = [
            'cancelled_today' => $n(static fn() => Database::scalar(
                'SELECT COUNT(*) FROM bookings WHERE cancelled_at >= :a AND cancelled_at < :b', $p, 0)),
            'reasons' => array_map($redact, array_column($q(static fn() => Database::fetchAll(
                "SELECT DISTINCT cancel_reason FROM bookings WHERE cancelled_at >= :a AND cancelled_at < :b AND cancel_reason <> '' LIMIT 8", $p), []), 'cancel_reason')),
            'refunds_waiting_now' => $n(static fn() => Database::scalar("SELECT COUNT(*) FROM bookings WHERE refund_status = 'pending'", [], 0)),
        ];

        /* WhatsApp messages of the day that failed, by provider, error code
           and purpose. Fix of 19 Nov (review): there is no delivery-events
           table. The message ledger (msg_ledger, synced every 2 min) is the
           one book of every send: Meta Cloud API rows copied from
           message_logs — a Meta webhook "failed" rewrites message_logs.status
           in place, so failures reported after the send are in it too — plus
           AiSensy's own records. message_logs is the fallback when the
           ledger is missing; neither readable → 'unavailable'. */
        $waRows = null;
        $waSrc = '';
        foreach ([
            'msg_ledger'   => "SELECT provider, purpose, error FROM msg_ledger
                                WHERE channel = 'whatsapp' AND status = 'failed' AND created_at >= :a AND created_at < :b",
            'message_logs' => "SELECT '' AS provider, purpose, error FROM message_logs
                                WHERE channel = 'whatsapp' AND status = 'failed' AND created_at >= :a AND created_at < :b",
        ] as $src => $sql) {
            try {
                $waRows = Database::fetchAll($sql, $p);
                $waSrc = $src;
                break;
            } catch (Throwable $e) {
                $waRows = null;
            }
        }
        if ($waRows === null) {
            $f['whatsapp_failed'] = self::NA;
        } else {
            $waFail = [];
            foreach ($waRows as $r) {
                $k = self::waFailKey((string) ($r['provider'] ?? ''), (string) ($r['error'] ?? ''), (string) ($r['purpose'] ?? ''), $redact);
                $waFail[$k] = ($waFail[$k] ?? 0) + 1;
            }
            arsort($waFail);
            $f['whatsapp_failed'] = ['source' => $waSrc, 'total' => count($waRows), 'by_code' => array_slice($waFail, 0, 12, true)];
        }

        /* The assistant: turns by branch, failures, hand-offs, 👎. */
        $f['ai_turns'] = $q(static fn() => array_map(static fn($r) => [
            'branch' => (string) $r['router'] !== '' ? (string) $r['router'] : '—', 'role' => (string) $r['role'],
            'turns' => (int) $r['n'], 'failed' => (int) $r['bad']],
            Database::fetchAll(
                'SELECT router, role, COUNT(*) AS n, SUM(ok = 0) AS bad FROM ai_turns
                  WHERE created_at >= :a AND created_at < :b GROUP BY router, role ORDER BY n DESC LIMIT 15', $p)), $na);
        $f['ai_tool_failures'] = $q(static fn() => array_map(static fn($r) => [
            'tool' => (string) $r['tool'], 'role' => (string) $r['role'], 'failed' => (int) $r['n'], 'example' => $redact((string) $r['detail'])],
            Database::fetchAll(
                'SELECT tool, role, COUNT(*) AS n, MAX(detail) AS detail FROM ai_agent_calls
                  WHERE ok = 0 AND created_at >= :a AND created_at < :b GROUP BY tool, role ORDER BY n DESC LIMIT 10', $p)), $na);
        /* Fix of 19 Nov (review): hand-offs are the support tickets the
           assistant opened (AiHandoff → support_tickets, source whatsapp_ai /
           facebook). Category and priority only — no name, phone or text. */
        $f['ai_handed_to_person'] = $q(static fn() => array_map(static fn($r) => [
            'channel' => (string) $r['source'], 'category' => (string) ($r['category'] ?? '') !== '' ? (string) $r['category'] : 'other',
            'priority' => (string) $r['priority'], 'count' => (int) $r['n'], 'still_open' => (int) $r['open_now']],
            Database::fetchAll(
                "SELECT source, category, priority, COUNT(*) AS n, SUM(status IN ('open','in_progress')) AS open_now
                   FROM support_tickets
                  WHERE source IN ('whatsapp_ai','facebook') AND created_at >= :a AND created_at < :b
                  GROUP BY source, category, priority ORDER BY n DESC LIMIT 12", $p)), $na);
        $f['ai_marked_wrong_by_office'] = $n(static fn() => Database::scalar(
            "SELECT COUNT(*) FROM ai_feedback_log WHERE verdict IN ('down','correction') AND created_at >= :a AND created_at < :b", $p, 0));
        $f['top_unanswered_questions'] = $q(static fn() => array_map(static fn($r) => [
            'question' => $redact((string) $r['normalized_question']), 'lang' => (string) $r['language'], 'asked' => (int) $r['frequency']],
            Database::fetchAll(
                "SELECT normalized_question, language, frequency FROM ai_unanswered_questions
                  WHERE status IN ('new','needs_owner') AND updated_at >= :a AND updated_at < :b ORDER BY frequency DESC LIMIT 10", $p)), $na);

        /* Our own sellers today (staff first names only — no passenger data). */
        $agents = [];
        if ($sellerIds !== []) {
            $ids = array_slice(array_keys($sellerIds), 0, 200);
            $names = [];
            foreach ($q(static fn() => Database::fetchAll(
                'SELECT id, role, full_name, username FROM admins WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')'), []) as $r) {
                $first = trim((string) strtok(trim((string) $r['full_name']) !== '' ? (string) $r['full_name'] : (string) $r['username'], ' '));
                $names[(int) $r['id']] = [$first . ' #' . (int) $r['id'], (string) $r['role']];
            }
            foreach ($sellerIds as $id => $list) {
                $agents[] = ['who' => $names[$id][0] ?? ('#' . $id), 'role' => $names[$id][1] ?? '', 'bookings' => count($list),
                             'cancelled_or_lost' => count(array_filter($list, static fn($x) => !$x['live'])),
                             'seats' => array_sum(array_column($list, 'pax')), 'amount_inr' => round(array_sum(array_column($list, 'amt')))];
            }
            usort($agents, static fn($x, $y) => $y['bookings'] <=> $x['bookings']);
        }
        $f['sellers'] = array_slice($agents, 0, 25);
        $f['agents_active_total'] = $n(static fn() => Database::scalar(
            "SELECT COUNT(*) FROM admins WHERE role IN ('agent','counter') AND is_active = 1", [], 0));

        /* Real stop times from GPS (AI-4), when there are any. */
        $f['stop_timing'] = $q(static fn() => array_map(static fn($r) => [
            'stop' => (string) $r['stop_name'], 'timetable' => $r['stop_time'] !== null ? substr((string) $r['stop_time'], 0, 5) : null,
            'reached' => substr((string) $r['reached_at'], 11, 5), 'source' => (string) $r['source']],
            Database::fetchAll(
                "SELECT rs.stop_name, rs.stop_time, t.reached_at, t.source FROM trip_stop_times t
                   JOIN route_stops rs ON rs.id = t.stop_ref AND t.stop_kind = 'stop'
                  WHERE t.reached_at >= :a AND t.reached_at < :b ORDER BY t.reached_at LIMIT 30", $p)), $na);

        return $f;
    }

    /* ---------------------------------------------------------- prompt */

    /** @return array{0: string, 1: string} [system (stable, cached), task (the day)] */
    public static function prompt(string $day, array $facts): array
    {
        $brief = self::$brief !== null ? (self::$brief)() : self::briefing();
        $titles = self::$titles !== null ? (self::$titles)() : self::kbTitles();
        $system = "You are the night reviewer of S Hari Global, a daily bus service between Gujarat (India) and the Nepal border "
            . "(Surat → Rupaidiha / Nepalgunj and back). Every night you read the facts of the day that just closed and write a short, "
            . "honest review for the owner and the office.\n"
            . "RULES:\n"
            . "1. Use ONLY the facts given. Never invent a number, name, booking or event. If the facts are thin, say so.\n"
            . "2. Refunds and cancellations are always settled by hand by the owner; payments are always verified by a person. "
            . "Never recommend that the AI or the system does them automatically.\n"
            . "3. summary_ne: exactly 3 short lines in simple Nepali (Devanagari), separated by newlines: how the day went, the biggest "
            . "problem, the one thing to do tomorrow.\n"
            . "4. went_wrong: real problems seen in the facts (failed payments, WhatsApp delivery failures by code, AI failures and "
            . "hand-offs, cancellations), each with the evidence (the figure from the facts).\n"
            . "5. teach_bot: question-and-answer pairs the WhatsApp assistant should learn so tomorrow it answers what it could not "
            . "today, mainly from the unanswered questions and the AI failures. Write the question and the answer in Nepali "
            . "(Devanagari), Hindi (Devanagari) and English, plus 3-5 romanised ways people type it. NO fare, price, time, date, seat "
            . "number or count in an answer (those are read live) — say 'see the live fare on the website or ask on WhatsApp'. "
            . "Only facts from the company briefing below. Do not repeat a topic already in the knowledge base list.\n"
            . "6. recommendations: at most 6, for office | agents | bot | owner, in Nepali (ne) with English (en), practical and specific.\n"
            . "7. anomalies: anything unusual in the figures (a sudden drop, a seller with many cancellations, a failure spike).\n"
            . "8. Plain text in every field, no markdown.\n"
            . "9. A fact that reads '" . self::NA . "' could not be read by the system tonight: say it is unknown, never treat it as zero "
            . "or as a quiet day.\n\n"
            . "COMPANY BRIEFING:\n" . $brief . "\n\n"
            . "ALREADY IN THE KNOWLEDGE BASE (titles):\n- " . ($titles !== [] ? implode("\n- ", array_slice($titles, 0, 700)) : '(none)');
        $task = "THE DAY THAT JUST CLOSED: " . $day . " (IST). Amounts in INR. Facts computed by the system:\n"
            . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            . "\n\nWrite the review as the JSON asked for.";
        return [$system, $task];
    }

    /** "code 131047 · cloud_api · ticket" — the code when the error carries one, else its first words. */
    public static function waFailKey(string $provider, string $err, string $purpose, ?Closure $redact = null): string
    {
        $code = preg_match('/code\s*\(?#?(\d{2,6})/i', $err, $m) === 1 ? 'code ' . $m[1]
            : (preg_match('/\b(13\d{4})\b/', $err, $m) === 1 ? 'code ' . $m[1]
            : ($err !== '' ? mb_substr($redact !== null ? $redact($err) : $err, 0, 48) : 'no error text'));
        return $code . ($provider !== '' ? ' · ' . $provider : '') . ' · ' . ($purpose !== '' ? $purpose : '—');
    }

    private static function briefing(): string
    {
        try {
            require_once INCLUDE_PATH . '/aiprompt.php';
            return self::factsOnly(ai_system_prompt());
        } catch (Throwable $e) {
            return '(briefing unavailable)';
        }
    }

    /**
     * Fix of 19 Nov (review): the chat briefing minus what is not for the
     * night reviewer — the persona line with its live "Now: …" clock, the
     * "answer only about the bus" paragraph and the chat STYLE rules (3
     * lines, under 45 words, reply in the user's language…), which
     * contradicted the review's own JSON and length rules. Only the FACTS
     * block (routes, fares, payment, refund slabs, border, office) stays.
     */
    public static function factsOnly(string $prompt): string
    {
        $at = strpos($prompt, 'FACTS (');
        $s = $at !== false ? substr($prompt, $at) : $prompt;
        $cut = strpos($s, "\nSTYLE:");
        if ($cut !== false) {
            $s = substr($s, 0, $cut);
        }
        $s = preg_replace('/\s*Now:[^\n]*?IST\.?/u', '', $s) ?? $s;
        return trim($s);
    }

    /** @return list<string> */
    private static function kbTitles(): array
    {
        try {
            return array_map(static fn($r) => (string) $r['canonical_title'], Database::fetchAll(
                "SELECT canonical_title FROM ai_kb_articles WHERE publication_status IN ('published','draft') ORDER BY id"));
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function schema(): array
    {
        $s = ['type' => 'string'];
        $obj = static fn(array $props): array => ['type' => 'object', 'properties' => $props,
            'required' => array_keys($props), 'additionalProperties' => false];
        return $obj([
            'summary_ne' => $s,
            'summary_en' => $s,
            'went_wrong' => ['type' => 'array', 'items' => $obj([
                'what_ne' => $s, 'what_en' => $s, 'evidence' => $s,
                'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']]])],
            'teach_bot' => ['type' => 'array', 'items' => $obj([
                'title' => $s, 'audience' => ['type' => 'string', 'enum' => ['customer', 'agent', 'staff']], 'category' => $s,
                'question_ne' => $s, 'question_hi' => $s, 'question_en' => $s,
                'answer_ne' => $s, 'answer_hi' => $s, 'answer_en' => $s,
                'roman_ne' => $s, 'roman_hi' => $s, 'keywords' => $s])],
            'recommendations' => ['type' => 'array', 'items' => $obj([
                'who' => ['type' => 'string', 'enum' => ['office', 'agents', 'bot', 'owner']], 'ne' => $s, 'en' => $s])],
            'anomalies' => ['type' => 'array', 'items' => $obj(['what_ne' => $s, 'what_en' => $s, 'evidence' => $s])],
        ]);
    }

    /** Trim the answer to its limits; unknown keys dropped. */
    public static function clean(array $j): array
    {
        $str = static fn($v, int $n): string => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, $n);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($j['summary_ne'] ?? '')) ?: [])));
        $list = static fn(string $k, int $max): array => array_values(array_filter(array_slice(is_array($j[$k] ?? null) ? $j[$k] : [], 0, $max), 'is_array'));
        return [
            'summary_ne' => implode("\n", array_map(static fn($l) => mb_substr($l, 0, 200), array_slice($lines, 0, 3))),
            'summary_en' => $str($j['summary_en'] ?? '', 800),
            'went_wrong' => array_map(static fn($w) => ['what_ne' => $str($w['what_ne'] ?? '', 300), 'what_en' => $str($w['what_en'] ?? '', 300),
                'evidence' => $str($w['evidence'] ?? '', 300),
                'severity' => in_array($w['severity'] ?? '', ['low', 'medium', 'high'], true) ? $w['severity'] : 'low'], $list('went_wrong', self::MAX_WRONG)),
            'teach_bot' => $list('teach_bot', self::MAX_TEACH),
            'recommendations' => array_map(static fn($r) => ['who' => in_array($r['who'] ?? '', ['office', 'agents', 'bot', 'owner'], true) ? $r['who'] : 'office',
                'ne' => $str($r['ne'] ?? '', 400), 'en' => $str($r['en'] ?? '', 400)], $list('recommendations', self::MAX_RECS)),
            'anomalies' => array_map(static fn($a) => ['what_ne' => $str($a['what_ne'] ?? '', 300), 'what_en' => $str($a['what_en'] ?? '', 300),
                'evidence' => $str($a['evidence'] ?? '', 300)], $list('anomalies', self::MAX_ANOM)),
        ];
    }

    /**
     * Teach the bot: each pair → one knowledge article, a DRAFT unless
     * ai_review_autolearn = 1. A pair with a number in it (fare, time,
     * count) or a title the base already has is dropped.
     *
     * @return array{0: int, 1: int} [saved, published]
     */
    public static function learn(string $day, array $pairs): array
    {
        require_once __DIR__ . '/ailearn.php';
        $publish = Settings::getBool('ai_review_autolearn', false);
        $phone = Settings::officePhone();
        $known = array_map('mb_strtolower', self::$titles !== null ? (self::$titles)() : self::kbTitles());
        $saved = $published = 0;
        foreach ($pairs as $p) {
            $title = trim((string) ($p['title'] ?? ''));
            $en = trim((string) ($p['answer_en'] ?? ''));
            if ($title === '' || mb_strlen($en) < 20 || in_array(mb_strtolower($title), $known, true)) {
                continue;
            }
            $all = $en . ' ' . ($p['answer_ne'] ?? '') . ' ' . ($p['answer_hi'] ?? '');
            if (!AiLearn::numbersOk($all, $phone)) {
                continue;
            }
            $article = [
                'title' => mb_substr($title, 0, 190),
                'audience' => in_array($p['audience'] ?? '', ['customer', 'agent', 'staff'], true) ? $p['audience'] : 'customer',
                'category' => (string) ($p['category'] ?? ''),
                'answer_en' => $en, 'answer_ne' => (string) ($p['answer_ne'] ?? ''), 'answer_hi' => (string) ($p['answer_hi'] ?? ''),
                'roman_ne' => (string) ($p['roman_ne'] ?? ''), 'roman_hi' => (string) ($p['roman_hi'] ?? ''),
                'keywords' => trim((string) ($p['keywords'] ?? '') . ', ' . ($p['question_en'] ?? '') . ', ' . ($p['question_ne'] ?? '') . ', ' . ($p['question_hi'] ?? ''), ', '),
            ];
            try {
                AiTeach::saveArticle($article, $publish, 'AI night review ' . $day);
            } catch (Throwable $e) {
                Logger::warning('AI review: KB save failed: ' . $e->getMessage(), [], 'ai');
                continue;
            }
            $known[] = mb_strtolower($title);
            $saved++;
            if ($publish) {
                $published++;
            }
        }
        return [$saved, $published];
    }

    /* ---------------------------------------------------------- storage */

    private static function store(string $day, string $status, string $note, array $facts, ?array $review,
        float $cost = 0.0, array $usage = [], string $model = '', int $saved = 0, int $published = 0): void
    {
        try {
            Database::run(
                'INSERT INTO ai_reviews (day, status, model, note, cost_usd, in_tokens, out_tokens, facts, review, summary_ne, kb_saved, kb_published, created_at)
                 VALUES (:d, :s, :m, :n, :c, :ti, :tout, :f, :r, :sn, :k, :kp, NOW())
                 ON DUPLICATE KEY UPDATE status = VALUES(status), model = VALUES(model), note = VALUES(note),
                    cost_usd = cost_usd + VALUES(cost_usd), in_tokens = VALUES(in_tokens), out_tokens = VALUES(out_tokens),
                    facts = VALUES(facts), review = VALUES(review), summary_ne = VALUES(summary_ne),
                    kb_saved = VALUES(kb_saved), kb_published = VALUES(kb_published), created_at = NOW()',
                ['d' => $day, 's' => $status, 'm' => mb_substr($model !== '' ? $model : self::model(), 0, 60), 'n' => mb_substr($note, 0, 255),
                 'c' => round($cost, 4), 'ti' => (int) ($usage['in'] ?? 0), 'tout' => (int) ($usage['out'] ?? 0),
                 'f' => $facts !== [] ? (string) json_encode($facts, JSON_UNESCAPED_UNICODE) : null,
                 'r' => $review !== null ? (string) json_encode($review, JSON_UNESCAPED_UNICODE) : null,
                 'sn' => $review !== null ? (string) $review['summary_ne'] : null, 'k' => $saved, 'kp' => $published]
            );
        } catch (Throwable $e) {
            Logger::warning('AI review: could not store the report: ' . $e->getMessage(), [], 'ai');
        }
    }

    /** One day's row (review decoded), or null. */
    public static function row(string $day): ?array
    {
        try {
            $r = Database::fetch('SELECT * FROM ai_reviews WHERE day = :d', ['d' => $day]);
        } catch (Throwable $e) {
            return null;
        }
        if ($r === null) {
            return null;
        }
        $r['review'] = is_string($r['review'] ?? null) ? (json_decode($r['review'], true) ?: null) : null;
        return $r;
    }

    /** @return list<array<string,mixed>> newest first, review decoded */
    public static function history(int $limit = 14): array
    {
        try {
            $rows = Database::fetchAll('SELECT day, status, model, note, cost_usd, summary_ne, kb_saved, kb_published, review, created_at
                FROM ai_reviews ORDER BY day DESC LIMIT ' . max(1, min(90, $limit)));
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as &$r) {
            $r['review'] = is_string($r['review'] ?? null) ? (json_decode($r['review'], true) ?: null) : null;
        }
        unset($r);
        return $rows;
    }

    /**
     * The 3 Nepali lines for the morning brief (cron/brain-digest.php),
     * from the review of $day; [] when there is none or it was not 'ok'.
     *
     * @return list<string>
     */
    public static function digestLines(string $day): array
    {
        if (!Settings::getBool('ai_review_in_digest', true)) {
            return [];
        }
        $r = self::row($day);
        if ($r === null || (string) $r['status'] !== 'ok') {
            return [];
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($r['summary_ne'] ?? '')) ?: [])));
        return array_map(static fn($l) => mb_substr($l, 0, 200), array_slice($lines, 0, 3));
    }
}
