<?php
/**
 * cron/daily-summary.php — daily digests for the COMPLETED previous day
 * (recommended: shortly after midnight, e.g. 00:15 IST).
 *
 *   · Each active agent with sales yesterday → WhatsApp (+ email) summary:
 *     bookings, commission earned, cash collected, wallet balances.
 *   · Admin → revenue digest: bookings, revenue, per-route lines, top
 *     agents, pending-approval count (WhatsApp short + HTML email).
 *
 * Why "yesterday", not "today": a run at any evening hour that reported the
 * current calendar day would silently drop every sale between the run and
 * midnight (they fall in today's window but after today's send, and the
 * per-day claim blocks a re-run). Reporting the closed previous day makes
 * the window complete no matter what time the owner actually schedules it.
 *
 * Exactly-once per reported day via automation_log claims (INSERT IGNORE on
 * the UNIQUE dedupe_key — same pattern as trip_events), one claim per
 * sub-job so toggling one on later cannot be eaten by the other's run.
 * Toggles are checked BEFORE claiming (the tripEventEnabled contract).
 *
 *   crontab:  15 0 * * *  curl -s "https://shreehariglobal.in/cron/daily-summary.php?token=CRON_TOKEN"
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/agentwallet.php';

// The claims ledger is the migration this cron depends on. Missing table →
// friendly hint and a green exit, exactly like cron/reminders.php does for
// its own migration (a red cron panel every night helps nobody).
try {
    Database::query('SELECT 1 FROM automation_log LIMIT 1');
} catch (Throwable $e) {
    cron_done([
        'skipped' => 'automation_log missing',
        'hint'    => 'Run database/upgrade-2026-08-automation.sql, then this cron works.',
    ]);
    exit(0);
}

// Long-running safety: re-read toggles fresh rather than a stale cache.
Settings::flush();

// The completed day we report. $d0/$d1 bound its [00:00, next 00:00) window;
// claim keys carry $reportDate so a same-day re-run is idempotent but each new
// day is a fresh claim.
$reportDate = date('Y-m-d', strtotime('-1 day'));
$d0 = $reportDate;
$d1 = date('Y-m-d', strtotime($reportDate . ' +1 day'));
$out = ['date' => $reportDate];

/* =====================================================================
 *  A. Agent daily summaries
 * ===================================================================== */
if (!Settings::getBool('agent_daily_summary_enabled', true)) {
    $out['agents'] = 'off';
} elseif (!EventBus::claim('agent-summary:' . $reportDate, 'cron.agent_summary')) {
    $out['agents'] = 'already ran for ' . $reportDate;
} else {
    $sent = 0;
    $idle = 0;
    $fail = 0;

    $agents = Database::fetchAll(
        "SELECT id, full_name, phone, email FROM admins
          WHERE role = 'agent' AND is_active = 1"
    );

    foreach ($agents as $a) {
        try {
            $aid      = (int) $a['id'];
            $bookings = (int) Database::scalar(
                'SELECT COUNT(*) FROM bookings
                  WHERE sold_by_admin_id = :a AND created_at >= :d0 AND created_at < :d1',
                ['a' => $aid, 'd0' => $d0, 'd1' => $d1],
                0
            );
            if ($bookings === 0) {
                $idle++;   // nothing sold that day — no spam
                continue;
            }

            $pax = (int) Database::scalar(
                'SELECT COUNT(*) FROM booking_passengers bp
                   JOIN bookings b ON b.id = bp.booking_id
                  WHERE b.sold_by_admin_id = :a AND b.created_at >= :d0 AND b.created_at < :d1',
                ['a' => $aid, 'd0' => $d0, 'd1' => $d1],
                0
            );
            // Net commission movement that day (earned minus any reversals).
            $commission = (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM agent_ledger
                  WHERE agent_admin_id = :a AND account = 'commission'
                    AND entry_type IN ('commission', 'commission_void')
                    AND created_at >= :d0 AND created_at < :d1",
                ['a' => $aid, 'd0' => $d0, 'd1' => $d1],
                0
            );
            $cash = (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM agent_ledger
                  WHERE agent_admin_id = :a AND entry_type = 'cash_due'
                    AND created_at >= :d0 AND created_at < :d1",
                ['a' => $aid, 'd0' => $d0, 'd1' => $d1],
                0
            );
            $bal = AgentWallet::balances($aid);

            Notify::agentDailySummary(
                [
                    'id'    => $aid,
                    'name'  => (string) ($a['full_name'] ?? ''),
                    'phone' => (string) ($a['phone'] ?? ''),
                    'email' => (string) ($a['email'] ?? ''),
                    'code'  => AgentWallet::agentCodeLabel($aid),
                ],
                [
                    'bookings'           => $bookings,
                    'pax'                => $pax,
                    'commission'         => $commission,
                    'cash'               => $cash,
                    'balance_commission' => (float) ($bal['commission'] ?? 0),
                    'balance_cash'       => (float) ($bal['cash'] ?? 0),
                ]
            );
            $sent++;
        } catch (Throwable $e) {
            $fail++;
            Logger::error('Agent summary failed', ['agent' => $a['id'] ?? 0, 'e' => $e->getMessage()], 'automation');
        }
    }

    $out['agents'] = ['sent' => $sent, 'idle' => $idle, 'failed' => $fail];
}

/* =====================================================================
 *  B. Admin revenue digest
 * ===================================================================== */
if (!Settings::getBool('admin_daily_digest_enabled', true)) {
    $out['digest'] = 'off';
} elseif (!EventBus::claim('admin-digest:' . $reportDate, 'cron.admin_digest')) {
    $out['digest'] = 'already ran for ' . $reportDate;
} else {
    try {
        $bookings = (int) Database::scalar(
            'SELECT COUNT(*) FROM bookings WHERE created_at >= :d0 AND created_at < :d1',
            ['d0' => $d0, 'd1' => $d1], 0
        );
        // Revenue = money APPROVED today (confirmed_at), not merely promised.
        $revenue = (float) Database::scalar(
            "SELECT COALESCE(SUM(total_amount), 0) FROM bookings
              WHERE confirmed_at >= :d0 AND confirmed_at < :d1 AND status = 'confirmed'",
            ['d0' => $d0, 'd1' => $d1], 0
        );
        $pax = (int) Database::scalar(
            "SELECT COUNT(*) FROM booking_passengers bp JOIN bookings b ON b.id = bp.booking_id
              WHERE b.confirmed_at >= :d0 AND b.confirmed_at < :d1 AND b.status = 'confirmed'",
            ['d0' => $d0, 'd1' => $d1], 0
        );
        $pending = (int) Database::scalar(
            "SELECT COUNT(*) FROM bookings WHERE status = 'pending'", [], 0
        );

        $routes = [];
        foreach (Database::fetchAll(
            "SELECT r.from_city, r.to_city, COUNT(DISTINCT b.id) AS n, COALESCE(SUM(bl.leg_total), 0) AS amt
               FROM bookings b
               JOIN booking_legs bl ON bl.booking_id = b.id
               JOIN schedules s     ON s.id = bl.schedule_id
               JOIN routes r        ON r.id = s.route_id
              WHERE b.confirmed_at >= :d0 AND b.confirmed_at < :d1 AND b.status = 'confirmed'
              GROUP BY r.id, r.from_city, r.to_city
              ORDER BY amt DESC",
            ['d0' => $d0, 'd1' => $d1]
        ) as $r) {
            $routes[] = $r['from_city'] . ' → ' . $r['to_city'] . ': '
                      . (int) $r['n'] . ' bookings · ' . inr((float) $r['amt']);
        }

        $topAgents = [];
        foreach (Database::fetchAll(
            "SELECT a.full_name, COALESCE(SUM(l.amount), 0) AS earned
               FROM agent_ledger l JOIN admins a ON a.id = l.agent_admin_id
              WHERE l.entry_type = 'commission' AND l.created_at >= :d0 AND l.created_at < :d1
              GROUP BY l.agent_admin_id, a.full_name
              ORDER BY earned DESC
              LIMIT 5",
            ['d0' => $d0, 'd1' => $d1]
        ) as $r) {
            $topAgents[] = $r['full_name'] . ' — ' . inr((float) $r['earned']) . ' commission';
        }

        Notify::adminDailyDigest([
            'date'     => $reportDate,
            'bookings' => $bookings,
            'pax'      => $pax,
            'revenue'  => $revenue,
            'pending'  => $pending,
            'routes'   => $routes,
            'agents'   => $topAgents,
        ]);
        $out['digest'] = ['bookings' => $bookings, 'revenue' => $revenue];
    } catch (Throwable $e) {
        Logger::error('Admin digest failed', ['e' => $e->getMessage()], 'automation');
        $out['digest'] = 'failed: ' . $e->getMessage();
    }
}

cron_done($out);
