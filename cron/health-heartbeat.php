<?php
/**
 * cron/health-heartbeat.php — notice when a scheduled job stops running.
 *
 * Recommended: hourly.
 *   crontab:   5 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/health-heartbeat.php
 *   or by URL: https://www.shreehariglobal.in/cron/health-heartbeat.php?token=CRON_TOKEN
 *
 * Why this exists: every other monitor in this system watches something that
 * FAILS. Nothing watched for something that simply STOPS. A crontab edited on
 * the VPS, a PHP fatal after a deploy, a full disk — the job vanishes, and the
 * only symptom is an absence: unpaid holds never released, the nightly brain
 * never thought, the backup never ran. Absences do not appear in logs.
 *
 * So: cron_done() stamps cron_runs on every run, and this job turns "no stamp
 * for longer than this job's interval allows" into an incident with a name.
 *
 * OBSERVE ONLY. It never runs another job, never repairs anything, never
 * sends. It writes incidents; a human (or the delivery sentinel) acts.
 *
 * THE ALERT CHANNEL IS NOT THE SUBSYSTEM UNDER TEST. When WhatsApp is the
 * thing that is broken, an alert sent over WhatsApp is not an alert. This job
 * only writes to health_incidents, which the owner's screen reads directly —
 * no outbound channel is involved in reporting that an outbound channel died.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/health.php';

/**
 * How often each job is expected to run, in minutes, and how much lateness is
 * normal before we call it late.
 *
 * `grace` is generous on purpose: a five-minute job that runs at 5m01s on a
 * busy box is not an incident, and an incident that cries wolf is one the
 * owner learns to scroll past. Only real silence — several missed cycles —
 * raises anything.
 *
 * A job NOT in this list is still heartbeat-recorded; it just is not policed,
 * because we have no expectation to compare against.
 *
 * @var array<string, array{every:int, grace:int, severity:string, what:string}>
 */
const EXPECTED = [
    'expire.php'          => ['every' => 5,    'grace' => 25,   'severity' => Health::CRITICAL,
                              'what'  => 'Unpaid seat holds are not being released — seats stay stuck as "pending" and the bus looks full when it is not.'],
    'whatsapp-retry.php'  => ['every' => 15,   'grace' => 60,   'severity' => Health::WARN,
                              'what'  => 'Tickets whose WhatsApp failed are no longer being retried — the backlog will not drain by itself.'],
    'alerts.php'          => ['every' => 30,   'grace' => 90,   'severity' => Health::WARN,
                              'what'  => 'Capacity and low-seat alerts have stopped.'],
    'reminders.php'       => ['every' => 10,   'grace' => 50,   'severity' => Health::WARN,
                              'what'  => 'Departure reminders are no longer going out to passengers.'],
    'lifecycle.php'       => ['every' => 5,    'grace' => 25,   'severity' => Health::WARN,
                              'what'  => 'The trip lifecycle is not advancing — departed/arrived states, and the per-passenger trip messages that follow them, are frozen.'],
    'daily-schedule.php'  => ['every' => 1440, 'grace' => 480,  'severity' => Health::CRITICAL,
                              'what'  => 'The daily bus is not being created — future dates will have no schedule to sell.'],
    'brain-nightly.php'   => ['every' => 1440, 'grace' => 480,  'severity' => Health::INFO,
                              'what'  => 'The night brain has not run — the ready queue and predictions are stale. Selling is unaffected.'],
    'brain-digest.php'    => ['every' => 1440, 'grace' => 480,  'severity' => Health::INFO,
                              'what'  => 'The 06:00 morning brief has not been produced.'],
    'daily-summary.php'   => ['every' => 1440, 'grace' => 480,  'severity' => Health::INFO,
                              'what'  => 'The daily revenue/agent summary has not been produced.'],
    'backup.php'          => ['every' => 1440, 'grace' => 360,  'severity' => Health::CRITICAL,
                              'what'  => 'No database backup has been taken. A disk failure right now loses every booking since the last good backup.'],
    /* WEEKLY on this box (crontab: Sunday 03:00), not daily. Checked against
       the live crontab rather than assumed — a daily expectation here would
       have raised a false "rotate.php has not run for 6 days" card on six
       days out of every seven, and a monitor that cries wolf is a monitor the
       owner learns to scroll past. */
    'rotate.php'          => ['every' => 10080, 'grace' => 1440, 'severity' => Health::WARN,
                              'what'  => 'Logs and old automation rows are not being pruned — the disk will fill.'],
];

$beats = [];
foreach (Health::heartbeats() as $row) {
    $beats[(string) $row['job']] = $row;
}

$late    = 0;
$healthy = 0;
$never   = 0;

foreach (EXPECTED as $job => $spec) {
    $key = 'cron.overdue.' . $job;
    $row = $beats[$job] ?? null;

    if ($row === null) {
        /* No row at all. Either the job has never run since the heartbeat
           shipped, or it is not scheduled on this box. Both are worth ONE
           quiet card rather than a critical alarm: on the day this deploys,
           every job is legitimately unstamped until its next cycle. */
        $never++;
        Health::open(
            'cron_missing',
            $key,
            Health::INFO,
            'No heartbeat yet from ' . $job,
            $spec['what'] . ' This job has not reported since heartbeats were switched on. '
                . 'If it is scheduled, this clears by itself after its next run.',
            'Check the crontab on the VPS: crontab -l | grep ' . $job,
        );
        continue;
    }

    $ageMin = $row['age_min'];
    if ($ageMin === null) {
        continue;
    }

    $limit = $spec['every'] + $spec['grace'];

    if ($ageMin > $limit) {
        $late++;
        $hours = $ageMin >= 120 ? round($ageMin / 60, 1) . ' hours' : $ageMin . ' minutes';
        Health::open(
            'cron_overdue',
            $key,
            $spec['severity'],
            $job . ' has not run for ' . $hours,
            $spec['what'] . ' Expected every ' . $spec['every'] . ' minutes; last run was '
                . $hours . ' ago (' . (string) $row['last_run_at'] . ').',
            "1. On the VPS run: crontab -l | grep {$job}\n"
                . "2. If the line is missing, restore it from /root/crontab-*.bak\n"
                . "3. If the line is there, run the job by hand and read the error:\n"
                . "   /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/{$job}\n"
                . "4. Check the disk is not full: df -h",
        );
    } else {
        $healthy++;
        Health::resolve($key);
    }

    /* A job that RUNS but keeps reporting failure is a different fault from a
       job that stopped, and needs its own card — otherwise a nightly brain
       that throws every night looks perfectly healthy from out here. */
    $failKey = 'cron.failing.' . $job;
    if ((int) ($row['fail_streak'] ?? 0) >= 3) {
        Health::open(
            'cron_failing',
            $failKey,
            $spec['severity'],
            $job . ' has failed ' . (int) $row['fail_streak'] . ' runs in a row',
            $spec['what'] . ' The job is running on schedule but reporting failure every time.',
            "Run it by hand and read the output:\n"
                . "   /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/{$job}\n"
                . 'Then check logs/<today>.log for the "cron ' . $job . '" lines.',
        );
    } else {
        Health::resolve($failKey);
    }
}

cron_done([
    'checked' => count(EXPECTED),
    'healthy' => $healthy,
    'late'    => $late,
    'never'   => $never,
]);
