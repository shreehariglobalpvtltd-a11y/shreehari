<?php
/**
 * cron/chalani-mail.php — the bus chalani by email before every departure
 * (4 Oct 2026, owner: "agent admin lai chalani data ni jane khalko").
 *
 *   crontab (www-data), every 10 minutes:  0-59/10 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/chalani-mail.php >> /var/www/shreehariglobal.in/public_html/logs/chalani-mail.log 2>&1
 *
 * For each departure starting within the next `chalani_mail_hours` (default
 * 2) hours, ONCE per departure:
 *   - office (settings chalani_mail_to, default admin_email + company_email):
 *     the FULL passenger list + the seat-chart PNG (ChallanPng) attached;
 *   - every agent / counter who sold seats on it and has an email on their
 *     profile: ONLY their own passengers (another seller's customers are
 *     never disclosed).
 * Switch: chalani_mail_enabled (default on). Sent once — remembered in
 * kv_store (kscope 'global', kkey 'chalani_mail.<schedule id>').
 * Read-only on bookings; never throws out of the loop.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cron/chalani-mail.php runs from the command line only.\n");
}

require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/challanpng.php';

$t0  = microtime(true);
$out = ['departures' => 0, 'office' => 0, 'sellers' => 0, 'failed' => 0, 'skipped' => 0];

if (!Settings::getBool('chalani_mail_enabled', true)) {
    echo json_encode(['ok' => true, 'off' => true]), "\n";
    exit;
}

$hours  = max(1, min(24, Settings::getInt('chalani_mail_hours', 2)));
// Test mode: CHALANI_MAIL_TEST_TO=<email> sends every mail there instead and
// remembers nothing, so the real run still happens later.
$testTo = Security::email((string) (getenv('CHALANI_MAIL_TEST_TO') ?: ''));
if ($testTo !== '' && (int) getenv('CHALANI_MAIL_TEST_HOURS') > 0) {
    $hours = min(48, (int) getenv('CHALANI_MAIL_TEST_HOURS'));
}
$office = [];
$cfg    = trim(Settings::getString('chalani_mail_to', ''));
foreach ($cfg !== '' ? preg_split('/[\s,;]+/', $cfg) : [Settings::getString('admin_email', ''), Settings::getString('company_email', '')] as $e) {
    $e = Security::email((string) $e);
    if ($e !== '' && !in_array(strtolower($e), array_map('strtolower', $office), true)) {
        $office[] = $e;
    }
}

$deps = Database::fetchAll(
    "SELECT s.id, s.travel_date, COALESCE(s.dep_time_override, r.dep_time) AS dep_time
       FROM schedules s
       JOIN routes r ON r.id = s.route_id
      WHERE s.travel_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 1 DAY
        AND s.status = 'scheduled'"
);

foreach ($deps as $d) {
    try {
        $depTs = strtotime($d['travel_date'] . ' ' . substr((string) $d['dep_time'], 0, 8));
        if ($depTs === false || $depTs < time() || $depTs > time() + $hours * 3600) {
            continue;
        }
        $sid = (int) $d['id'];
        $key = 'chalani_mail.' . $sid;
        if (Database::scalar("SELECT 1 FROM kv_store WHERE kscope = 'global' AND kkey = :k", ['k' => $key]) !== null) {
            continue;   // already sent for this departure
        }
        $sched = ChallanPng::schedule($sid);
        if ($sched === null) {
            continue;
        }
        $out['departures']++;

        $rows = Database::fetchAll(
            "SELECT bs.seat_no, b.pnr, b.contact_phone, b.sold_by_admin_id, b.total_amount, b.source,
                    bp.full_name, bp.gender, bl.boarding_stop, bl.drop_stop
               FROM booking_seats bs
               JOIN bookings b ON b.id = bs.booking_id
               LEFT JOIN booking_legs bl ON bl.id = bs.leg_id
               LEFT JOIN booking_passengers bp ON bp.booking_id = bs.booking_id AND bp.seat_no = bs.seat_no
              WHERE bs.schedule_id = :s AND bs.released_at IS NULL
                AND b.status IN ('confirmed', 'completed')
              ORDER BY LENGTH(bs.seat_no), bs.seat_no",
            ['s' => $sid]
        );

        $company = Settings::getString('company_name', APP_NAME);
        $bus     = trim((string) ($sched['bus_number'] ?? '') . ' ' . (string) ($sched['bus_name'] ?? ''));
        $title   = trim((string) $sched['from_city']) . ' → ' . trim((string) $sched['to_city'])
                 . ' · ' . (string) $sched['travel_date'] . ' · ' . substr((string) $sched['dep_time'], 0, 5);
        $stop    = static fn(?string $s): string => trim(explode('·', (string) $s)[0]);

        $table = static function (array $list) use ($stop): string {
            $h = '<table style="border-collapse:collapse;font-size:13px" border="1" cellpadding="5">'
               . '<tr style="background:#f3f3f3"><th>Seat</th><th>Passenger</th><th>Phone</th><th>Boarding</th><th>Drop</th><th>PNR</th></tr>';
            foreach ($list as $r) {
                $h .= '<tr><td>' . e((string) $r['seat_no']) . '</td><td>' . e(trim((string) ($r['full_name'] ?? '')))
                    . ($r['gender'] ? ' (' . e(substr((string) $r['gender'], 0, 1)) . ')' : '') . '</td><td>' . e((string) $r['contact_phone'])
                    . '</td><td>' . e($stop($r['boarding_stop'])) . '</td><td>' . e($stop($r['drop_stop'])) . '</td><td>' . e((string) $r['pnr']) . '</td></tr>';
            }
            return $h . '</table>';
        };
        $head = static fn(string $who, int $n) => '<div style="font-family:Arial,sans-serif;color:#222">'
            . '<h2 style="margin:0 0 6px">' . e($company) . ' — Bus chalani</h2>'
            . '<p style="margin:0 0 4px"><strong>' . e($title) . '</strong>' . ($bus !== '' ? ' · Bus ' . e($bus) : '')
            . (!empty($sched['driver_name']) ? ' · Driver ' . e((string) $sched['driver_name']) . ' ' . e((string) ($sched['driver_phone'] ?? '')) : '') . '</p>'
            . '<p style="margin:0 0 10px">' . e($who) . ' — ' . $n . ' passenger(s).</p>';

        // Office copy: everything + the seat-chart picture.
        $attach = [];
        try {
            $png = ChallanPng::render($sid, 'email', null, false);
            if (!empty($png['path']) && is_file($png['path'])) {
                $attach[] = ['name' => 'chalan-' . $sid . '.png', 'mime' => 'image/png', 'data' => (string) file_get_contents($png['path'])];
            }
        } catch (Throwable $e) {
            Logger::warning('chalani-mail: seat chart render failed', ['sid' => $sid, 'err' => $e->getMessage()], 'mail');
        }
        $html = $head('Full passenger list', count($rows)) . ($rows !== [] ? $table($rows) : '<p>No confirmed passengers yet.</p>')
              . '<p style="color:#888;font-size:12px">Seat chart attached. यो इमेल बस छुट्नु अघि आफैँ आउँछ।</p></div>';
        foreach ($testTo !== '' ? [$testTo] : $office as $to) {
            Notify::email($to, 'Chalani · ' . $title, $html, '', $attach) ? $out['office']++ : $out['failed']++;
        }

        // Each seller: their own passengers only.
        $bySeller = [];
        foreach ($rows as $r) {
            if ((int) ($r['sold_by_admin_id'] ?? 0) > 0) {
                $bySeller[(int) $r['sold_by_admin_id']][] = $r;
            }
        }
        foreach ($bySeller as $adminId => $list) {
            $s = Database::fetch("SELECT email, full_name, username, role FROM admins WHERE id = :i AND is_active = 1 AND role IN ('agent', 'counter')", ['i' => $adminId]);
            $em = $s !== null ? Security::email((string) ($s['email'] ?? '')) : '';
            if ($em === '' || ($testTo === '' && in_array(strtolower($em), array_map('strtolower', $office), true))) {
                $out['skipped']++;
                continue;
            }
            $who  = 'Your passengers (' . (trim((string) ($s['full_name'] ?? '')) ?: (string) $s['username']) . ')';
            $html = $head($who, count($list)) . $table($list)
                  . '<p style="color:#888;font-size:12px">यो बसमा तपाईंले बुक गरेका यात्रु मात्र।</p></div>';
            Notify::email($testTo !== '' ? $testTo : $em, ($testTo !== '' ? '[TEST for ' . $em . '] ' : '') . 'Your passengers · ' . $title, $html) ? $out['sellers']++ : $out['failed']++;
        }

        if ($testTo === '') Database::run(
            "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('global', :k, :v, 'chalani-mail')
             ON DUPLICATE KEY UPDATE kvalue = :v2",
            ['k' => $key, 'v' => (string) time(), 'v2' => (string) time()]
        );
    } catch (Throwable $e) {
        $out['failed']++;
        Logger::warning('chalani-mail: departure failed', ['sid' => (int) ($d['id'] ?? 0), 'err' => $e->getMessage()], 'mail');
    }
}

$out['ms'] = (int) round((microtime(true) - $t0) * 1000);
echo '[' . date('Y-m-d H:i:s') . '] ' . json_encode($out) . "\n";
