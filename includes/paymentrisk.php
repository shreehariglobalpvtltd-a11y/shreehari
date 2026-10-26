<?php
/**
 * =====================================================================
 *  PaymentRisk — one advisory risk score per payment proof (9 Oct 2026)
 *  TicketLive  — the rotating 4-digit live code on the on-screen ticket
 *
 *  Owner: "ticket fake badi hun layo — approval system advanced, easy,
 *  kam risk banaunu paryo."
 *
 *  What it does
 *    - scoreMany() reads every signal for a list of bookings in a fixed
 *      number of grouped queries (the verify queue scores 200 rows at once)
 *      and returns {score, level, reasons[]} per booking. No side effects.
 *    - assertApprovable() is THE gate every approval path runs through
 *      (admin/payments.php, admin/booking-view.php, the WhatsApp office
 *      assistant, bulk approve): low = approve; medium = the reviewer ticks
 *      "I checked the bank statement"; high = the tick AND a written reason,
 *      and - when Settings pay_two_step_on is on - a SECOND person
 *      (super-admin / manager / accountant, never the first approver)
 *      within 24 h.
 *    - recordReview() keeps what the reviewer saw at the moment of every
 *      decision (payment_reviews + an audit row 'payment.review').
 *
 *  What it never does (owner rule, hard): reject, cancel, refund, expire or
 *  release seats. A HIGH score only stops a one-click approval and asks a
 *  person to look. Rejecting is always a person pressing Reject.
 *
 *  COD bookings (cash is collected at boarding, settleCod() is that check)
 *  and seller sales (counter / agent / office desk took the money in hand
 *  and verified it at sale time) are not payment proofs and are skipped.
 *
 *  Signals (weight from Settings risk_w_<code>, 0 = off):
 *    DUP_UTR         the same UTR on another booking                     +50
 *    DUP_SHOT        the very same screenshot file on another booking    +50
 *    NEAR_SHOT       a look-alike screenshot (dHash, BIT_COUNT <= 4)      0
 *                    - ships OFF: tested 9 Oct, a 64-bit hash puts two
 *                    different receipts from one UPI app at distance 0-1,
 *                    the same receipt re-sent through WhatsApp further
 *                    apart than that. It would flag honest passengers.
 *    EDITED_SHOT     the image names a photo editor (Photoshop, PicsArt)  +25
 *    UTR_FORMAT      UPI UTR not 12 digits / eSewa not 6-20 letters+digits,
 *                    or an obviously made-up number (111111111111)        +15
 *    UTR_OLD         the UPI reference's date code (YDDDHH…) is more than
 *                    3 days before the booking - a re-used old payment    +15
 *    AMOUNT_MISMATCH the amount the customer typed / the bank sent differs
 *                    from the fare by more than Rs 1                      +40
 *    TOO_FAST        proof within 45 s of a WhatsApp booking              +15
 *    QR_NEVER_OPENED a WhatsApp booking whose pay image / link was never
 *                    opened (the in-app checkout draws its QR itself, so
 *                    web bookings are never judged on this)              +10
 *    REOPENED        re-opened after a rejection (booking.reopen)        +20
 *    VELOCITY_PHONE  3+ public bookings from one mobile within 24 h      +15
 *    VELOCITY_IP     one IP used by 3+ mobiles within 24 h (office IPs
 *                    of signed-in staff are ignored)                     +15
 *    NEW_AGENT       sold by an agent added < 14 days ago (or KYC not
 *                    verified while agent_kyc_required is on)            +15
 *    CANCEL_FLAG     the mobile has brain_cancel_flag+ cancellations     +10
 *    HIGH_VALUE      total >= risk_high_value (5000) or 6+ seats         +10
 *    NO_PROOF        no UTR and no screenshot at all                     +20
 *    BANK_SIGNED     ICICI gateway proof (UTR ICICI-…, method bank)      -40
 *  Levels: below risk_medium_at (20) low, from risk_high_at (50) high.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

/** A gate refusal the UI can act on: $need = bank | note | second | level | amount. */
final class PaymentRiskGate extends RuntimeException
{
    public string $need;
    /** @var array<string,mixed> */
    public array $risk;

    /** @param array<string,mixed> $risk */
    public function __construct(string $message, string $need, array $risk = [])
    {
        parent::__construct($message);
        $this->need = $need;
        $this->risk = $risk;
    }
}

final class PaymentRisk
{
    /** code => [default weight, English label, Nepali label] */
    public const SIGNALS = [
        'DUP_UTR'           => [50,  'Same UTR on another booking',          'अर्को बुकिङमा पनि यही UTR'],
        'DUP_SHOT'          => [50,  'Same screenshot on another booking',   'अर्को बुकिङमा पनि यही स्क्रिनसट'],
        'NEAR_SHOT'         => [0,   'Look-alike screenshot on another booking', 'अर्को बुकिङको जस्तै स्क्रिनसट'],
        'EDITED_SHOT'       => [25,  'Screenshot made in a photo editor',    'फोटो सम्पादकमा बनाइएको स्क्रिनसट'],
        'UTR_FORMAT'        => [15,  'UTR does not look real',               'UTR सही ढाँचाको छैन'],
        'UTR_OLD'           => [15,  'UTR date is older than the booking',   'UTR को मिति बुकिङभन्दा पुरानो'],
        'AMOUNT_MISMATCH'   => [40,  'Amount paid differs from the fare',    'तिरेको रकम भाडासँग मिलेन'],
        'TOO_FAST'          => [15,  'Proof within 45 s of booking',         'बुकिङको ४५ सेकेन्डभित्रै प्रमाण'],
        'QR_NEVER_OPENED'   => [10,  'Pay QR / link never opened',           'भुक्तानी QR / लिङ्क खोलिएकै छैन'],
        'REOPENED'          => [20,  'Re-opened after a rejection',          'अस्वीकृत भएपछि फेरि खोलिएको'],
        'VELOCITY_PHONE'    => [15,  '3+ bookings from this mobile in 24 h', '२४ घण्टामा यो मोबाइलबाट ३+ बुकिङ'],
        'VELOCITY_IP'       => [15,  'One device used by 3+ mobiles in 24 h', '२४ घण्टामा एउटै डिभाइसबाट ३+ मोबाइल'],
        'NEW_AGENT'         => [15,  'Sold by a new agent',                  'नयाँ एजेन्टको बिक्री'],
        'CANCEL_FLAG'       => [10,  'Mobile has many cancellations',        'यो मोबाइलका धेरै रद्द'],
        'HIGH_VALUE'        => [10,  'High value / 6+ seats',                'ठूलो रकम / ६+ सिट'],
        'NO_PROOF'          => [20,  'No UTR and no screenshot',             'UTR वा स्क्रिनसट केही छैन'],
        'AMOUNT_SEEN_SHORT' => [40,  'Reviewer saw less than the fare',      'जाँचमा भाडाभन्दा कम रकम देखियो'],
        'BANK_SIGNED'       => [-40, 'Bank-verified (ICICI gateway)',        'बैंकले प्रमाणित (ICICI)'],
    ];

    /**
     * Reject reason codes => [chip label, customer text English, customer text Nepali].
     * The customer text is what reject() stores and the passenger reads.
     */
    public const REASONS = [
        'utr_not_found'     => ['UTR not in bank',     'This UTR was not found in our bank statement. Please check it and send it again.', 'यो UTR को भुक्तानी हाम्रो बैंकमा भेटिएन। जाँचेर फेरि पठाउनुहोस्।'],
        'amount_short'      => ['Amount short',        'The amount received is less than the fare. Please pay the balance and send the proof.', 'प्राप्त रकम भाडाभन्दा कम छ। बाँकी तिरेर प्रमाण पठाउनुहोस्।'],
        'duplicate_proof'   => ['Proof already used',  'This payment proof was already used for another booking.', 'यो भुक्तानी प्रमाण अर्को बुकिङमा प्रयोग भइसकेको छ।'],
        'edited_screenshot' => ['Edited screenshot',   'The screenshot looks edited. Please send the original from your payment app.', 'स्क्रिनसट सम्पादन गरिएको देखिन्छ। भुक्तानी एपकै मूल स्क्रिनसट पठाउनुहोस्।'],
        'wrong_account'     => ['Wrong account',       'The payment went to another account, not S Hari Global.', 'भुक्तानी S Hari Global को खातामा होइन, अर्कै खातामा गएको छ।'],
        'unclear_image'     => ['Unclear image',       'The screenshot is not clear. Please send one that shows the UTR and the amount.', 'स्क्रिनसट प्रस्ट छैन। UTR र रकम देखिने स्क्रिनसट पठाउनुहोस्।'],
        'other'             => ['Other',               'We could not verify this payment.', 'यो भुक्तानी प्रमाणित गर्न सकिएन।'],
    ];

    /** Largest image (pixels) GD may decode for a fingerprint / thumbnail - see fingerprint(). */
    public const MAX_HASH_PIXELS = 16000000;

    /** Roles that may give the SECOND approval of a two-person check. */
    public const SECOND_ROLES = ['superadmin', 'manager', 'accountant'];

    /** Editors whose name inside an image file means it was not a raw screenshot. */
    private const EDITORS = [
        'photoshop' => 'Photoshop', 'imageready' => 'Photoshop', 'lightroom' => 'Lightroom',
        'picsart' => 'PicsArt', 'snapseed' => 'Snapseed', 'canva' => 'Canva', 'gimp' => 'GIMP',
        'pixlr' => 'Pixlr', 'photodirector' => 'PhotoDirector', 'polarr' => 'Polarr', 'meitu' => 'Meitu',
        'fotor' => 'Fotor', 'photoscape' => 'PhotoScape', 'paint.net' => 'Paint.NET', 'affinity photo' => 'Affinity',
        'inshot' => 'InShot', 'photoroom' => 'PhotoRoom', 'picmonkey' => 'PicMonkey', 'ibis paint' => 'ibis Paint',
    ];

    /** @var array<string,bool> */
    private static array $colCache = [];

    private function __construct() {}

    /* -----------------------------------------------------------------
     *  Settings
     * ----------------------------------------------------------------- */

    public static function weight(string $code): int
    {
        if (!isset(self::SIGNALS[$code])) {
            return 0;
        }
        $key = $code === 'AMOUNT_SEEN_SHORT' ? 'amount_mismatch' : strtolower($code);
        $w = Settings::getInt('risk_w_' . $key, self::SIGNALS[$code][0]);
        return max(-100, min(100, $w));
    }

    public static function mediumAt(): int
    {
        return max(1, min(500, Settings::getInt('risk_medium_at', 20)));
    }

    public static function highAt(): int
    {
        return max(self::mediumAt() + 1, min(1000, Settings::getInt('risk_high_at', 50)));
    }

    public static function levelFor(int $score): string
    {
        if ($score >= self::highAt()) {
            return 'high';
        }
        return $score >= self::mediumAt() ? 'medium' : 'low';
    }

    public static function twoStepOn(): bool
    {
        return Settings::getBool('pay_two_step_on', false);
    }

    public static function available(): bool
    {
        return self::hasTable('payment_reviews');
    }

    /* -----------------------------------------------------------------
     *  Scoring
     * ----------------------------------------------------------------- */

    /**
     * @return array<string,mixed>|null the score of one booking, null when it does not exist
     */
    public static function score(int $bookingId): ?array
    {
        return self::scoreMany([$bookingId])[$bookingId] ?? null;
    }

    /**
     * Score many bookings in a fixed number of grouped queries.
     *
     * @param array<int,int|string> $bookingIds
     * @return array<int, array{bookingId:int, pnr:string, score:int, level:string, skip:?string,
     *         hasProof:bool, bankSigned:bool, codes:string, reasons:list<array<string,mixed>>}>
     */
    public static function scoreMany(array $bookingIds): array
    {
        $ids = [];
        foreach ($bookingIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $ids = array_slice($ids, 0, 500, true);
        $in  = implode(',', $ids);   // ints only - safe to inline

        $claimCol   = self::hasCol('payments', 'claimed_amount') ? 'p.claimed_amount' : 'NULL';
        $channelCol = self::hasCol('bookings', 'channel_tag') ? 'b.channel_tag' : 'NULL';

        $rows = Database::fetchAll(
            "SELECT b.id, b.pnr, b.status, b.is_cod, b.total_amount, b.contact_phone, b.ip_address,
                    b.created_at, b.sold_by_admin_id, b.source, {$channelCol} AS channel_tag,
                    p.id AS pay_id, p.method, p.mode, p.utr_number, p.status AS pay_status,
                    p.verified_by, p.gateway_payload, {$claimCol} AS claimed_amount,
                    (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id) AS seat_count
               FROM bookings b
               LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
              WHERE b.id IN ({$in})"
        );
        if ($rows === []) {
            return [];
        }

        /* ---- grouped look-ups ---------------------------------------- */
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $pnrs = array_values(array_map(static fn(array $r): string => (string) $r['pnr'], $rows));

        // screenshots: how many + the first upload time
        $shots = [];
        foreach (Database::fetchAll(
            "SELECT booking_id, COUNT(*) AS n, MIN(created_at) AS first_at
               FROM payment_screenshots WHERE booking_id IN ({$in}) GROUP BY booking_id"
        ) as $s) {
            $shots[(int) $s['booking_id']] = ['n' => (int) $s['n'], 'first' => (string) $s['first_at']];
        }

        // DUP_UTR - the same UTR typed on another booking
        $utrOthers = [];
        $utrs = [];
        foreach ($rows as $r) {
            $u = strtoupper(trim((string) ($r['utr_number'] ?? '')));
            if ($u !== '' && preg_match('/[A-Z0-9]{6,}/', $u) === 1) {
                $utrs[$u] = $u;
            }
        }
        if ($utrs !== [] && self::weight('DUP_UTR') !== 0) {
            [$ph, $pp] = self::placeholders(array_values($utrs), 'u');
            foreach (Database::fetchAll(
                "SELECT p.utr_number, p.booking_id, b.pnr, b.status
                   FROM payments p JOIN bookings b ON b.id = p.booking_id
                  WHERE p.utr_number IN ({$ph})", $pp
            ) as $o) {
                $utrOthers[strtoupper(trim((string) $o['utr_number']))][(int) $o['booking_id']] = (string) $o['pnr'];
            }
        }

        // DUP_SHOT - the very same file (sha256) on another booking
        $shotOthers = [];
        if (self::weight('DUP_SHOT') !== 0) {
            foreach (Database::fetchAll(
                "SELECT DISTINCT s1.booking_id, bx.pnr
                   FROM payment_screenshots s1
                   JOIN payment_screenshots s2 ON s2.sha256 = s1.sha256 AND s2.booking_id <> s1.booking_id
                   JOIN bookings bx ON bx.id = s2.booking_id
                  WHERE s1.booking_id IN ({$in}) AND s1.sha256 IS NOT NULL AND s1.sha256 <> ''"
            ) as $o) {
                $shotOthers[(int) $o['booking_id']][] = (string) $o['pnr'];
            }
        }

        // NEAR_SHOT - look-alike image (off by default, see the header)
        $nearOthers = [];
        if (self::weight('NEAR_SHOT') !== 0 && self::hasCol('payment_screenshots', 'phash')) {
            $bits = max(0, min(16, Settings::getInt('risk_near_shot_bits', 4)));
            foreach (Database::fetchAll(
                "SELECT DISTINCT s1.booking_id, bx.pnr
                   FROM payment_screenshots s1
                   JOIN payment_screenshots s2 ON s2.booking_id <> s1.booking_id AND s2.phash IS NOT NULL
                        AND s2.created_at >= NOW() - INTERVAL 60 DAY
                        AND BIT_COUNT(s1.phash ^ s2.phash) <= {$bits}
                        AND COALESCE(s1.sha256, '') <> COALESCE(s2.sha256, '-')
                   JOIN bookings bx ON bx.id = s2.booking_id
                  WHERE s1.booking_id IN ({$in}) AND s1.phash IS NOT NULL"
            ) as $o) {
                $nearOthers[(int) $o['booking_id']][] = (string) $o['pnr'];
            }
        }

        // EDITED_SHOT - a photo editor named inside the file
        $edited = [];
        if (self::weight('EDITED_SHOT') !== 0 && self::hasCol('payment_screenshots', 'edit_tool')) {
            foreach (Database::fetchAll(
                "SELECT booking_id, GROUP_CONCAT(DISTINCT edit_tool SEPARATOR ', ') AS tools
                   FROM payment_screenshots
                  WHERE booking_id IN ({$in}) AND edit_tool IS NOT NULL AND edit_tool <> ''
                  GROUP BY booking_id"
            ) as $e) {
                $edited[(int) $e['booking_id']] = (string) $e['tools'];
            }
        }

        // audit trail: re-opened after a rejection, first proof time
        $reopened = [];
        $proofAt  = [];
        [$ph, $pp] = self::placeholders($pnrs, 'n');
        try {
            foreach (Database::fetchAll(
                "SELECT entity_id, action, COUNT(*) AS n, MIN(created_at) AS first_at
                   FROM audit_logs
                  WHERE action IN ('booking.reopen', 'payment.proof') AND entity_type = 'booking'
                    AND entity_id IN ({$ph})
                  GROUP BY entity_id, action", $pp
            ) as $a) {
                $key = strtoupper((string) $a['entity_id']);
                if ($a['action'] === 'booking.reopen') {
                    $reopened[$key] = (int) $a['n'];
                } else {
                    $proofAt[$key] = (string) $a['first_at'];
                }
            }
        } catch (Throwable $e) {
            // an audit_logs hiccup must never stop the queue from rendering
        }

        // QR / pay-link trail (WhatsApp bookings only)
        $trail = [];
        try {
            if (!class_exists('PayEvents')) {
                require_once __DIR__ . '/payevents.php';
            }
            $trail = PayEvents::summary(array_keys($byId));
        } catch (Throwable $e) {
            $trail = [];
        }

        // velocity - phones and IPs over the time span of these bookings
        $phones = [];
        $ips    = [];
        $tMin = PHP_INT_MAX;
        $tMax = 0;
        foreach ($rows as $r) {
            $ph1 = (string) ($r['contact_phone'] ?? '');
            if ($ph1 !== '' && !isPlaceholderPhone($ph1)) {
                $phones[$ph1] = $ph1;
            }
            $ip = trim((string) ($r['ip_address'] ?? ''));
            if (self::publicIp($ip)) {
                $ips[$ip] = $ip;
            }
            $ts = strtotime((string) $r['created_at']) ?: time();
            $tMin = min($tMin, $ts);
            $tMax = max($tMax, $ts);
        }
        $from = date('Y-m-d H:i:s', $tMin - 86400);
        $to   = date('Y-m-d H:i:s', $tMax + 86400);

        $phoneTimes = [];
        $cancels    = [];
        if ($phones !== []) {
            [$ph, $pp] = self::placeholders(array_values($phones), 'ph');
            if (self::weight('VELOCITY_PHONE') !== 0) {
                foreach (Database::fetchAll(
                    "SELECT contact_phone, created_at FROM bookings
                      WHERE contact_phone IN ({$ph}) AND created_at BETWEEN :vf AND :vt
                        AND (sold_by_admin_id IS NULL OR sold_by_admin_id = 0)",
                    $pp + ['vf' => $from, 'vt' => $to]
                ) as $v) {
                    $phoneTimes[(string) $v['contact_phone']][] = strtotime((string) $v['created_at']) ?: 0;
                }
            }
            if (self::weight('CANCEL_FLAG') !== 0) {
                foreach (Database::fetchAll(
                    "SELECT contact_phone, COUNT(*) AS n FROM bookings
                      WHERE contact_phone IN ({$ph}) AND status = 'cancelled'
                        AND created_at >= NOW() - INTERVAL 180 DAY
                      GROUP BY contact_phone", $pp
                ) as $c) {
                    $cancels[(string) $c['contact_phone']] = (int) $c['n'];
                }
            }
        }

        // the IP each target booking uploaded its proof from
        $uploadIps = [];
        if (self::weight('VELOCITY_IP') !== 0) {
            foreach (Database::fetchAll(
                "SELECT DISTINCT booking_id, uploaded_ip FROM payment_screenshots
                  WHERE booking_id IN ({$in}) AND uploaded_ip IS NOT NULL AND uploaded_ip <> ''"
            ) as $u) {
                $uip = (string) $u['uploaded_ip'];
                if (self::publicIp($uip)) {
                    $uploadIps[(int) $u['booking_id']][] = $uip;
                    $ips[$uip] = $uip;
                }
            }
        }

        $ipPhones = [];
        if ($ips !== [] && self::weight('VELOCITY_IP') !== 0) {
            $staffIps = [];
            try {
                foreach (Database::fetchAll(
                    "SELECT DISTINCT last_login_ip FROM admins
                      WHERE last_login_ip IS NOT NULL AND last_login_ip <> ''
                        AND last_login_at >= NOW() - INTERVAL 30 DAY"
                ) as $s) {
                    $staffIps[(string) $s['last_login_ip']] = true;
                }
            } catch (Throwable $e) {
            }
            $ips = array_filter($ips, static fn(string $ip): bool => !isset($staffIps[$ip]));
            if ($ips !== []) {
                [$ph, $pp] = self::placeholders(array_values($ips), 'ip');
                foreach (Database::fetchAll(
                    "SELECT ip_address AS ip, contact_phone, created_at FROM bookings
                      WHERE ip_address IN ({$ph}) AND created_at BETWEEN :if AND :it",
                    $pp + ['if' => $from, 'it' => $to]
                ) as $v) {
                    $ipPhones[(string) $v['ip']][] = [(string) $v['contact_phone'], strtotime((string) $v['created_at']) ?: 0];
                }
                foreach (Database::fetchAll(
                    "SELECT s.uploaded_ip AS ip, b.contact_phone, s.created_at
                       FROM payment_screenshots s JOIN bookings b ON b.id = s.booking_id
                      WHERE s.uploaded_ip IN ({$ph}) AND s.created_at BETWEEN :sf AND :st",
                    $pp + ['sf' => $from, 'st' => $to]
                ) as $v) {
                    $ipPhones[(string) $v['ip']][] = [(string) $v['contact_phone'], strtotime((string) $v['created_at']) ?: 0];
                }
            }
        }
        // sellers: new agents
        $sellers = [];
        $sellerIds = array_values(array_unique(array_filter(array_map(
            static fn(array $r): int => (int) ($r['sold_by_admin_id'] ?? 0), $rows))));
        if ($sellerIds !== [] && self::weight('NEW_AGENT') !== 0) {
            $kycCol = self::hasCol('admin_profiles', 'kyc_status') ? 'ap.kyc_status' : "'verified'";
            foreach (Database::fetchAll(
                "SELECT a.id, a.role, a.created_at, {$kycCol} AS kyc
                   FROM admins a LEFT JOIN admin_profiles ap ON ap.admin_id = a.id
                  WHERE a.id IN (" . implode(',', array_map('intval', $sellerIds)) . ")"
            ) as $s) {
                $sellers[(int) $s['id']] = $s;
            }
        }

        $cancelFlag = max(2, min(50, Settings::getInt('brain_cancel_flag', 3)));
        $highValue  = max(1, Settings::getInt('risk_high_value', 5000));
        $kycNeeded  = Settings::getBool('agent_kyc_required', false);

        /* ---- per booking --------------------------------------------- */
        $out = [];
        foreach ($byId as $bid => $r) {
            $pnr     = (string) $r['pnr'];
            $pnrKey  = strtoupper($pnr);
            $total   = (float) $r['total_amount'];
            $utr     = trim((string) ($r['utr_number'] ?? ''));
            $utrU    = strtoupper($utr);
            $method  = strtolower((string) ($r['method'] ?? ''));
            $channel = strtoupper((string) ($r['channel_tag'] ?? ''));
            $nShots  = (int) ($shots[$bid]['n'] ?? 0);
            $hasProof = $utr !== '' || $nShots > 0;
            $bankSigned = $method === 'bank' && str_starts_with($utrU, 'ICICI-');

            $skip = null;
            if ((int) $r['is_cod'] === 1) {
                $skip = 'cod';
            } elseif ($r['pay_id'] === null) {
                $skip = 'nopay';
            } elseif ((string) $r['mode'] === 'offline' && (int) ($r['verified_by'] ?? 0) > 0
                && (int) $r['verified_by'] === (int) ($r['sold_by_admin_id'] ?? 0)) {
                $skip = 'seller';
            }

            $reasons = [];
            $add = static function (string $code, string $detail = '', array $others = []) use (&$reasons): void {
                $w = self::weight($code);
                if ($w === 0) {
                    return;
                }
                $reasons[] = [
                    'code'    => $code,
                    'weight'  => $w,
                    'label'   => self::SIGNALS[$code][1],
                    'labelNe' => self::SIGNALS[$code][2],
                    'detail'  => $detail,
                    'pnrs'    => array_values(array_slice(array_unique($others), 0, 8)),
                ];
            };

            if ($skip === null) {
                if (!$hasProof) {
                    $add('NO_PROOF');
                }

                // the same UTR elsewhere
                if ($utrU !== '' && isset($utrOthers[$utrU])) {
                    $o = $utrOthers[$utrU];
                    unset($o[$bid]);
                    if ($o !== []) {
                        $add('DUP_UTR', 'UTR ' . $utr . ' is also on', array_values($o));
                    }
                }
                if (!empty($shotOthers[$bid])) {
                    $add('DUP_SHOT', 'The same image file is also on', $shotOthers[$bid]);
                }
                if (!empty($nearOthers[$bid])) {
                    $add('NEAR_SHOT', 'Looks like the proof on', $nearOthers[$bid]);
                }
                if (isset($edited[$bid])) {
                    $add('EDITED_SHOT', 'File names: ' . $edited[$bid]);
                }

                // UTR shape
                if ($utr !== '' && !$bankSigned) {
                    $bad = self::utrLooksWrong($utr, $method);
                    if ($bad !== null) {
                        $add('UTR_FORMAT', $bad);
                    }
                    if ($method === 'upi' && preg_match('/^\d{12}$/', $utr) === 1) {
                        $utrDate = self::upiUtrDate($utr, (string) $r['created_at']);
                        if ($utrDate !== null) {
                            $days = (int) floor(((strtotime((string) $r['created_at']) ?: time()) - $utrDate) / 86400);
                            if ($days > 3) {
                                $add('UTR_OLD', 'UTR date code ' . date('j M Y', $utrDate) . ' — ' . $days . ' days before the booking');
                            }
                        }
                    }
                }

                // amount the customer / the bank says was paid
                $claimed = self::claimedAmount($r);
                if ($claimed !== null && abs($claimed - $total) > 1.0) {
                    $add('AMOUNT_MISMATCH', 'Paid ' . inr($claimed) . ', fare ' . inr($total));
                }

                // WhatsApp-only timing / QR signals (the in-app checkout pays before booking)
                if ($channel === 'WHATSAPP') {
                    $first = null;
                    foreach ([$shots[$bid]['first'] ?? null, $proofAt[$pnrKey] ?? null] as $t) {
                        $ts = $t ? strtotime((string) $t) : false;
                        if ($ts && ($first === null || $ts < $first)) {
                            $first = $ts;
                        }
                    }
                    $created = strtotime((string) $r['created_at']) ?: 0;
                    if ($first !== null && $created > 0 && $first - $created < 45 && $first >= $created) {
                        $add('TOO_FAST', 'Proof ' . max(0, $first - $created) . ' s after the booking');
                    }
                    $tr = $trail[$bid] ?? null;
                    if ($hasProof && ($tr === null || ((int) $tr['qr'] === 0 && (int) $tr['link'] === 0))) {
                        $add('QR_NEVER_OPENED');
                    }
                }

                if (!empty($reopened[$pnrKey])) {
                    $add('REOPENED', 'Re-opened ' . $reopened[$pnrKey] . ' time' . ($reopened[$pnrKey] === 1 ? '' : 's'));
                }

                // velocity
                $phone = (string) ($r['contact_phone'] ?? '');
                $created = strtotime((string) $r['created_at']) ?: time();
                if (isset($phoneTimes[$phone])) {
                    $n = count(array_filter($phoneTimes[$phone], static fn(int $t): bool => abs($t - $created) <= 86400));
                    if ($n >= 3) {
                        $add('VELOCITY_PHONE', $n . ' bookings from ' . maskPhone($phone) . ' within 24 h');
                    }
                }
                $myIps = array_values(array_unique(array_filter(array_merge(
                    [trim((string) ($r['ip_address'] ?? ''))], $uploadIps[$bid] ?? []))));
                $bestIp = 0;
                foreach ($myIps as $ip) {
                    if (!isset($ipPhones[$ip])) {
                        continue;
                    }
                    $set = [];
                    foreach ($ipPhones[$ip] as [$p1, $t1]) {
                        if ($p1 !== '' && abs($t1 - $created) <= 86400) {
                            $set[normalisePhone($p1)] = true;
                        }
                    }
                    $bestIp = max($bestIp, count($set));
                }
                if ($bestIp >= 3) {
                    $add('VELOCITY_IP', 'One IP used by ' . $bestIp . ' mobiles within 24 h');
                }

                // seller
                $sid = (int) ($r['sold_by_admin_id'] ?? 0);
                if ($sid > 0 && isset($sellers[$sid]) && (string) $sellers[$sid]['role'] === 'agent') {
                    $age = time() - (strtotime((string) $sellers[$sid]['created_at']) ?: 0);
                    if ($age < 14 * 86400) {
                        $add('NEW_AGENT', 'Agent added ' . max(0, (int) floor($age / 86400)) . ' days ago');
                    } elseif ($kycNeeded && (string) $sellers[$sid]['kyc'] !== 'verified') {
                        $add('NEW_AGENT', 'Agent KYC not verified');
                    }
                }

                if (($cancels[$phone] ?? 0) >= $cancelFlag) {
                    $add('CANCEL_FLAG', $cancels[$phone] . ' cancellations in 180 days');
                }
                if ($total >= $highValue || (int) $r['seat_count'] >= 6) {
                    $add('HIGH_VALUE', inr($total) . ' · ' . (int) $r['seat_count'] . ' seat' . ((int) $r['seat_count'] === 1 ? '' : 's'));
                }
                if ($bankSigned) {
                    $add('BANK_SIGNED', 'Bank reference ' . $utr);
                }
            }

            usort($reasons, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);
            $score = 0;
            foreach ($reasons as $x) {
                $score += (int) $x['weight'];
            }
            $score = max(0, $score);

            $out[$bid] = [
                'bookingId'  => $bid,
                'pnr'        => $pnr,
                'score'      => $skip === null ? $score : 0,
                'level'      => $skip === null ? self::levelFor($score) : 'skip',
                'skip'       => $skip,
                'hasProof'   => $hasProof,
                'bankSigned' => $bankSigned,
                'codes'      => implode(',', array_map(static fn(array $x): string => (string) $x['code'], $reasons)),
                'reasons'    => $reasons,
            ];
        }

        return $out;
    }

    /**
     * Why this UTR looks wrong for its method, or null when it looks fine.
     */
    public static function utrLooksWrong(string $utr, string $method): ?string
    {
        $u = trim($utr);
        $method = strtolower($method);
        if ($method === 'upi') {
            if (preg_match('/^\d{12}$/', $u) !== 1) {
                return 'A UPI UTR is 12 digits (got "' . mb_substr($u, 0, 24) . '")';
            }
        } elseif ($method === 'esewa') {
            if (preg_match('/^[A-Za-z0-9]{6,20}$/', $u) !== 1) {
                return 'An eSewa code is 6-20 letters and digits';
            }
        } elseif ($method === 'bank') {
            if (preg_match('/^[A-Za-z0-9]{10,22}$/', $u) !== 1) {
                return 'A bank UTR is 10-22 letters and digits';
            }
        } else {
            return null;   // cash / wallet: free text
        }
        $digits = preg_replace('/\D/', '', $u) ?? '';
        if (strlen($digits) >= 8) {
            if (preg_match('/^(\d)\1+$/', $digits) === 1) {
                return 'Made-up number (all one digit)';
            }
            if (str_contains('01234567890123456789', $digits) || str_contains('98765432109876543210', $digits)) {
                return 'Made-up number (a counting sequence)';
            }
        }
        return null;
    }

    /**
     * The payment date coded in a 12-digit UPI reference (NPCI RRN layout
     * YDDDHH + sequence: last digit of the year, day of the year, hour), as a
     * timestamp - or null when the number does not follow that layout.
     */
    public static function upiUtrDate(string $utr, string $nearDate): ?int
    {
        if (preg_match('/^(\d)(\d{3})(\d{2})\d{6}$/', $utr, $m) !== 1) {
            return null;
        }
        $y = (int) $m[1];
        $doy = (int) $m[2];
        $hh = (int) $m[3];
        if ($doy < 1 || $doy > 366 || $hh > 23) {
            return null;
        }
        $near = strtotime($nearDate) ?: time();
        $nearYear = (int) date('Y', $near);
        $best = null;
        foreach ([$nearYear - 1, $nearYear, $nearYear + 1] as $year) {
            if ($year % 10 !== $y) {
                continue;
            }
            $isLeap = ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
            if ($doy === 366 && !$isLeap) {
                continue;
            }
            $ts = mktime($hh, 0, 0, 1, $doy, $year);
            if ($ts !== false && ($best === null || abs($ts - $near) < abs($best - $near))) {
                $best = $ts;
            }
        }
        if ($best === null) {
            // year digit far from the booking year (e.g. 2017 for a 2026 booking): very old
            $year = $nearYear - (($nearYear % 10 - $y + 10) % 10 ?: 10);
            $ts = mktime($hh, 0, 0, 1, min($doy, 365), $year);
            return $ts === false ? null : $ts;
        }
        return $best;
    }

    /** @param array<string,mixed> $r a scoreMany base row */
    private static function claimedAmount(array $r): ?float
    {
        $c = $r['claimed_amount'] ?? null;
        if ($c !== null && $c !== '' && (float) $c > 0) {
            return (float) $c;
        }
        $gp = trim((string) ($r['gateway_payload'] ?? ''));
        if ($gp !== '' && $gp[0] === '{') {
            $j = json_decode($gp, true);
            if (is_array($j)) {
                foreach (['amount', 'txnAmount', 'txn_amount', 'TxnAmount', 'paidAmount'] as $k) {
                    if (isset($j[$k]) && is_numeric($j[$k]) && (float) $j[$k] > 0) {
                        return (float) $j[$k];
                    }
                }
            }
        }
        return null;
    }

    /* -----------------------------------------------------------------
     *  The approval gate
     * ----------------------------------------------------------------- */

    /**
     * May this staff member approve this payment now, with what they told us?
     *
     * @param array<string,mixed> $admin {id, role}
     * @param array<string,mixed> $opts  note, reasonCode, bankChecked (bool), amountSeen (?float), via
     * @return array{risk:array<string,mixed>, step:string, first:?array<string,mixed>}
     *         step = 'approve' (go ahead and confirm) | 'approve1' (record the first of two approvals only)
     * @throws PaymentRiskGate when the reviewer still has to do something
     * @throws RuntimeException when the booking does not exist
     */
    public static function assertApprovable(int $bookingId, array $admin, array $opts = []): array
    {
        $risk = self::score($bookingId);
        if ($risk === null) {
            throw new RuntimeException('Booking not found.');
        }
        $risk = self::withAmountSeen($risk, $bookingId, $opts['amountSeen'] ?? null);

        $via   = (string) ($opts['via'] ?? 'queue');
        $note  = trim((string) ($opts['note'] ?? ''));
        $bank  = !empty($opts['bankChecked']);
        $level = (string) $risk['level'];

        if ($level === 'skip' || $level === 'low') {
            return ['risk' => $risk, 'step' => 'approve', 'first' => null];
        }

        if ($via === 'bulk') {
            throw new PaymentRiskGate('Not low-risk any more (' . strtoupper($level) . ' ' . $risk['score'] . ') — open it and approve on its own.', 'level', $risk);
        }
        if ($via === 'whatsapp') {
            throw new PaymentRiskGate($risk['pnr'] . ' is ' . strtoupper($level) . ' risk (' . self::codesHuman($risk) . '). Approve it in Admin → Payments, where the checks are shown.', 'level', $risk);
        }
        if (!$bank) {
            throw new PaymentRiskGate('This payment is ' . strtoupper($level) . ' risk. Check the bank statement, then tick "I checked the bank statement". / बैंक स्टेटमेन्ट जाँचेर टिक गर्नुहोस्।', 'bank', $risk);
        }
        if ($level === 'high' && mb_strlen($note) < 4) {
            throw new PaymentRiskGate('HIGH risk: write why you are approving it (what you checked). / किन स्वीकृत गर्दै हुनुहुन्छ, लेख्नुहोस्।', 'note', $risk);
        }

        if ($level === 'high' && self::twoStepOn()) {
            $first = self::openFirstApproval($bookingId);
            if ($first === null) {
                return ['risk' => $risk, 'step' => 'approve1', 'first' => null];
            }
            if ((int) $first['admin_id'] === (int) ($admin['id'] ?? 0)) {
                throw new PaymentRiskGate('You gave the first approval. A different person must give the second. / दोस्रो स्वीकृति अर्को व्यक्तिले दिनुपर्छ।', 'second', $risk);
            }
            if (!in_array((string) ($admin['role'] ?? ''), self::SECOND_ROLES, true)) {
                throw new PaymentRiskGate('The second approval must come from a super-admin, manager or accountant.', 'second', $risk);
            }
            return ['risk' => $risk, 'step' => 'approve', 'first' => $first];
        }

        return ['risk' => $risk, 'step' => 'approve', 'first' => null];
    }

    /**
     * Fold what the reviewer read on the proof into the score: less than the
     * fare adds AMOUNT_SEEN_SHORT (usually tipping it to HIGH, so a written
     * reason is needed). Never blocks by itself, never rejects.
     *
     * @param array<string,mixed> $risk
     * @return array<string,mixed>
     */
    public static function withAmountSeen(array $risk, int $bookingId, mixed $amountSeen): array
    {
        if ($amountSeen === null || $amountSeen === '' || !is_numeric($amountSeen) || (float) $amountSeen <= 0 || $risk['level'] === 'skip') {
            return $risk;
        }
        $total = (float) Database::scalar('SELECT total_amount FROM bookings WHERE id = :b', ['b' => $bookingId], 0);
        if ($total - (float) $amountSeen <= 1.0) {
            return $risk;
        }
        $w = self::weight('AMOUNT_SEEN_SHORT');
        if ($w === 0) {
            return $risk;
        }
        $risk['reasons'][] = [
            'code' => 'AMOUNT_SEEN_SHORT', 'weight' => $w,
            'label' => self::SIGNALS['AMOUNT_SEEN_SHORT'][1], 'labelNe' => self::SIGNALS['AMOUNT_SEEN_SHORT'][2],
            'detail' => 'Seen ' . inr((float) $amountSeen) . ', fare ' . inr($total), 'pnrs' => [],
        ];
        usort($risk['reasons'], static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);
        $risk['score'] = max(0, (int) $risk['score'] + $w);
        $risk['level'] = self::levelFor((int) $risk['score']);
        $risk['codes'] = implode(',', array_map(static fn(array $x): string => (string) $x['code'], $risk['reasons']));
        return $risk;
    }

    /**
     * The open first approval of a two-person check: an 'approve1' row from
     * the last 24 h with no later approve / reject decision on the booking.
     *
     * @return array<string,mixed>|null
     */
    public static function openFirstApproval(int $bookingId): ?array
    {
        if (!self::available()) {
            return null;
        }
        $row = Database::fetch(
            "SELECT r.*, a.full_name, a.username FROM payment_reviews r
               LEFT JOIN admins a ON a.id = r.admin_id
              WHERE r.booking_id = :b AND r.step = 'approve1' AND r.created_at >= NOW() - INTERVAL 24 HOUR
              ORDER BY r.id DESC LIMIT 1",
            ['b' => $bookingId]
        );
        if ($row === null) {
            return null;
        }
        $later = Database::exists(
            "SELECT 1 FROM payment_reviews WHERE booking_id = :b AND id > :i AND step IN ('approve','reject')",
            ['b' => $bookingId, 'i' => (int) $row['id']]
        );
        return $later ? null : $row;
    }

    /**
     * Keep what the reviewer saw. Never throws - a missing table (migration
     * not run yet) still leaves the audit row.
     *
     * @param array<string,mixed> $risk
     * @param array<string,mixed> $admin {id, role}
     * @param array<string,mixed> $opts note, reasonCode, bankChecked, amountSeen, via
     */
    public static function recordReview(int $bookingId, string $step, array $risk, array $admin, array $opts = []): int
    {
        $step = in_array($step, ['approve1', 'approve', 'reject', 'hold', 'escalate'], true) ? $step : 'escalate';
        $pnr  = (string) ($risk['pnr'] ?? Database::scalar('SELECT pnr FROM bookings WHERE id = :b', ['b' => $bookingId], ''));
        $note = Security::clean((string) ($opts['note'] ?? ''), 255);
        $reasonCode = isset(self::REASONS[(string) ($opts['reasonCode'] ?? '')]) ? (string) $opts['reasonCode'] : null;
        $amountSeen = isset($opts['amountSeen']) && is_numeric($opts['amountSeen']) && (float) $opts['amountSeen'] > 0
            ? round((float) $opts['amountSeen'], 2) : null;
        $codes = mb_substr((string) ($risk['codes'] ?? ''), 0, 255);
        $id = 0;
        if (self::available()) {
            try {
                $payId = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM payments WHERE booking_id = :b', ['b' => $bookingId], 0);
                $id = Database::insert('payment_reviews', [
                    'booking_id'   => $bookingId,
                    'payment_id'   => $payId > 0 ? $payId : null,
                    'step'         => $step,
                    'risk_score'   => (int) ($risk['score'] ?? 0),
                    'risk_level'   => mb_substr((string) ($risk['level'] ?? 'low'), 0, 8),
                    'risk_codes'   => $codes !== '' ? $codes : null,
                    'reason_code'  => $reasonCode,
                    'note'         => $note !== '' ? $note : null,
                    'amount_seen'  => $amountSeen,
                    'bank_checked' => !empty($opts['bankChecked']) ? 1 : 0,
                    'via'          => mb_substr((string) ($opts['via'] ?? 'queue'), 0, 16),
                    'admin_id'     => (int) ($admin['id'] ?? 0) > 0 ? (int) $admin['id'] : null,
                    'admin_role'   => mb_substr((string) ($admin['role'] ?? ''), 0, 30) ?: null,
                    'ip'           => Security::clientIp(),
                ]);
            } catch (Throwable $e) {
                Logger::warning('payment_reviews insert failed', ['booking' => $bookingId, 'e' => $e->getMessage()]);
            }
        }
        Logger::audit('payment.review', 'booking', $pnr, null, [
            'step'   => $step,
            'score'  => (int) ($risk['score'] ?? 0),
            'level'  => (string) ($risk['level'] ?? ''),
            'codes'  => $codes,
            'via'    => (string) ($opts['via'] ?? 'queue'),
            'bank'   => !empty($opts['bankChecked']),
            'amount' => $amountSeen,
        ], $note, (string) ($reasonCode ?? ''));
        return $id;
    }

    /**
     * Every review on a booking, newest first, with the reviewer's name.
     *
     * @return list<array<string,mixed>>
     */
    public static function reviewsFor(int $bookingId, int $limit = 30): array
    {
        if (!self::available()) {
            return [];
        }
        return Database::fetchAll(
            'SELECT r.*, a.full_name, a.username FROM payment_reviews r
               LEFT JOIN admins a ON a.id = r.admin_id
              WHERE r.booking_id = :b ORDER BY r.id DESC LIMIT ' . max(1, min(200, $limit)),
            ['b' => $bookingId]
        );
    }

    /* -----------------------------------------------------------------
     *  Customer wording
     * ----------------------------------------------------------------- */

    /** The reject reason the customer reads: Nepali first, then English (<= 250 chars). */
    public static function customerText(string $reasonCode, string $note = ''): string
    {
        $note = trim(Security::clean($note, 200));
        if (!isset(self::REASONS[$reasonCode])) {
            return $note !== '' ? $note : self::REASONS['other'][2] . ' / ' . self::REASONS['other'][1];
        }
        if ($reasonCode === 'other' && $note !== '') {
            return mb_substr($note, 0, 250);
        }
        [, $en, $ne] = self::REASONS[$reasonCode];
        $txt = $ne . ' / ' . $en;
        if ($note !== '') {
            $txt .= ' (' . $note . ')';
        }
        return mb_strlen($txt) > 250 ? mb_substr($txt, 0, 249) . '…' : $txt;
    }

    /** The "please send a clearer proof" WhatsApp text. */
    public static function holdText(string $pnr, string $note = ''): string
    {
        $note = trim(Security::clean($note, 200));
        return '🧾 ' . $pnr . "\n"
            . 'तपाईंको भुक्तानी जाँच्न अझै प्रस्ट प्रमाण चाहियो: UTR, रकम र मिति देखिने पूरा स्क्रिनसट पठाउनुहोस्।' . "\n"
            . 'We need a clearer payment proof: please send the full screenshot showing the UTR, amount and date.'
            . ($note !== '' ? "\n" . $note : '') . "\n"
            . 'तपाईंको सिट सुरक्षित छ · Your seats stay held.';
    }

    /**
     * Hold — ask for a clearer proof. NO status change: the booking stays
     * pending (and off the expiry clock), only a message and a review row.
     *
     * @param array<string,mixed> $admin {id, role}
     * @return array{sent:bool, link:string, risk:array<string,mixed>}
     */
    public static function hold(int $bookingId, array $admin, string $note = '', string $via = 'queue'): array
    {
        $b = Database::fetch('SELECT id, pnr, status, contact_phone, contact_country_code FROM bookings WHERE id = :b', ['b' => $bookingId]);
        if ($b === null) {
            throw new RuntimeException('Booking not found.');
        }
        if ((string) $b['status'] !== 'pending') {
            throw new RuntimeException('Only a booking waiting for verification can be put on hold (this one is ' . $b['status'] . ').');
        }
        $risk = self::score($bookingId) ?? ['score' => 0, 'level' => 'low', 'codes' => '', 'pnr' => (string) $b['pnr']];
        $text = self::holdText((string) $b['pnr'], $note);
        $sent = false;
        $link = '';
        $phone = (string) ($b['contact_phone'] ?? '');
        if ($phone !== '' && !isPlaceholderPhone($phone)) {
            $cc = (string) ($b['contact_country_code'] ?? '');
            $hint = str_contains($cc, '977') ? 'NP' : (str_contains($cc, '91') ? 'IN' : null);
            try {
                if (!class_exists('Notify')) {
                    require_once __DIR__ . '/notify.php';
                }
                $r = Notify::whatsapp($phone, $text, null, $hint, [], $bookingId, ['purpose' => 'payment_hold']);
                if ($r === true) {
                    $sent = true;
                } elseif (is_string($r) && str_starts_with($r, 'http')) {
                    $link = $r;
                }
            } catch (Throwable $e) {
                Logger::warning('payment hold WhatsApp failed', ['booking' => $bookingId, 'e' => $e->getMessage()]);
            }
            if ($link === '') {
                $digits = preg_replace('/\D/', '', $phone) ?? '';
                if (strlen($digits) === 10) {
                    $digits = ($hint === 'NP' ? '977' : '91') . $digits;
                }
                $link = 'https://wa.me/' . $digits . '?text=' . rawurlencode($text);
            }
        }
        self::recordReview($bookingId, 'hold', $risk, $admin, [
            'note' => ($sent ? '[WhatsApp sent] ' : '[not sent - tap to send] ') . $note, 'via' => $via,
        ]);
        return ['sent' => $sent, 'link' => $link, 'risk' => $risk];
    }

    /** "DUP_UTR, UTR_OLD" in words, for one-line messages. */
    public static function codesHuman(array $risk, int $max = 3): string
    {
        $out = [];
        foreach (array_slice((array) ($risk['reasons'] ?? []), 0, $max) as $r) {
            if ((int) $r['weight'] > 0) {
                $out[] = (string) $r['label'];
            }
        }
        return $out === [] ? 'no warning signs' : implode(', ', $out);
    }

    /* -----------------------------------------------------------------
     *  Screenshot fingerprint (computed once, at upload)
     * ----------------------------------------------------------------- */

    /**
     * A 64-bit difference hash (decimal string, for BIGINT UNSIGNED) and the
     * photo editor named in the file, if any. Never throws: an upload must
     * never fail because a fingerprint could not be taken.
     *
     * @return array{phash:?string, editTool:?string}
     */
    public static function fingerprint(string $absPath, string $mime = ''): array
    {
        $out = ['phash' => null, 'editTool' => null];
        try {
            if (!is_file($absPath)) {
                return $out;
            }
            $out['editTool'] = self::editToolIn($absPath);
            if (!str_starts_with($mime, 'image/') || !function_exists('imagecreatefromstring')) {
                return $out;
            }
            $size = @getimagesize($absPath);
            /* GD holds ~4-5 bytes a pixel: a 40 MP camera photo would need
               ~200 MB and "memory exhausted" is a fatal error try/catch cannot
               stop - it would fail the upload itself. Phone screenshots are
               2-4 MP; anything over 16 MP (~80 MB) simply gets no hash. */
            if ($size === false || $size[0] < 8 || $size[1] < 8 || $size[0] * $size[1] > self::MAX_HASH_PIXELS) {
                return $out;
            }
            $raw = @file_get_contents($absPath);
            $src = $raw !== false ? @imagecreatefromstring($raw) : false;
            if ($src === false) {
                return $out;
            }
            $sm = imagecreatetruecolor(9, 8);
            imagecopyresampled($sm, $src, 0, 0, 0, 0, 9, 8, imagesx($src), imagesy($src));
            $hash = 0;
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $a = imagecolorat($sm, $x, $y);
                    $b = imagecolorat($sm, $x + 1, $y);
                    $la = (($a >> 16) & 255) * 299 + (($a >> 8) & 255) * 587 + ($a & 255) * 114;
                    $lb = (($b >> 16) & 255) * 299 + (($b >> 8) & 255) * 587 + ($b & 255) * 114;
                    $hash = ($hash << 1) | ($la > $lb ? 1 : 0);
                }
            }
            imagedestroy($sm);
            imagedestroy($src);
            $out['phash'] = sprintf('%u', $hash);
        } catch (Throwable $e) {
            // fingerprint is best-effort
        }
        return $out;
    }

    /** The photo editor named in the first 256 KB / last 64 KB of a file, or null. */
    public static function editToolIn(string $absPath): ?string
    {
        $fh = @fopen($absPath, 'rb');
        if ($fh === false) {
            return null;
        }
        $head = (string) fread($fh, 262144);
        $tail = '';
        $size = (int) @filesize($absPath);
        if ($size > 262144) {
            fseek($fh, max(262144, $size - 65536));
            $tail = (string) fread($fh, 65536);
        }
        fclose($fh);
        $hay = strtolower($head . $tail);
        foreach (self::EDITORS as $needle => $name) {
            if (str_contains($hay, $needle)) {
                return $name;
            }
        }
        return null;
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ----------------------------------------------------------------- */

    /**
     * @param list<string> $values
     * @return array{0:string, 1:array<string,string>} "IN (...)" body and its params
     */
    private static function placeholders(array $values, string $prefix): array
    {
        $ph = [];
        $pp = [];
        foreach (array_values($values) as $i => $v) {
            $ph[] = ':' . $prefix . $i;
            $pp[$prefix . $i] = (string) $v;
        }
        if ($ph === []) {
            return ["''", []];
        }
        return [implode(',', $ph), $pp];
    }

    /**
     * A routable client address worth comparing. Loopback, private LAN,
     * link-local and blanks are skipped: everyone behind the office router or
     * a local test shares those, so they say nothing about one person.
     */
    public static function publicIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    public static function hasCol(string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (!array_key_exists($k, self::$colCache)) {
            try {
                self::$colCache[$k] = preg_match('/^[a-z_]+$/', $table . $col) === 1
                    && Database::fetch('SHOW COLUMNS FROM `' . $table . '` LIKE ' . Database::pdo()->quote($col)) !== null;
            } catch (Throwable $e) {
                self::$colCache[$k] = false;
            }
        }
        return self::$colCache[$k];
    }

    private static function hasTable(string $table): bool
    {
        $k = '#' . $table;
        if (!array_key_exists($k, self::$colCache)) {
            try {
                self::$colCache[$k] = Database::fetch('SHOW TABLES LIKE ' . Database::pdo()->quote($table)) !== null;
            } catch (Throwable $e) {
                self::$colCache[$k] = false;
            }
        }
        return self::$colCache[$k];
    }

    /** Forget cached schema facts (tests that run the migration mid-process). */
    public static function flush(): void
    {
        self::$colCache = [];
    }
}

/**
 * The live code on the passenger's on-screen ticket: 4 digits that change
 * every 30 seconds, derived from the PNR and the server clock with the app
 * key. A screenshot or an edited picture shows a stale code and a frozen
 * clock; the gate scanner (admin/scan.php) shows what a live screen must
 * read right now. Never stored, never sent in a message.
 */
final class TicketLive
{
    public const STEP = 30;

    private function __construct() {}

    public static function window(?int $t = null): int
    {
        return intdiv($t ?? time(), self::STEP);
    }

    public static function validFor(?int $t = null): int
    {
        $t = $t ?? time();
        return self::STEP - ($t % self::STEP);
    }

    public static function code(string $pnr, ?int $t = null): string
    {
        $h = Security::sign('ticket-live|' . strtoupper(trim($pnr)) . '|' . self::window($t));
        return str_pad((string) (hexdec(substr($h, 0, 7)) % 10000), 4, '0', STR_PAD_LEFT);
    }

    public static function on(): bool
    {
        return Settings::getBool('ticket_live_code_on', true);
    }

    /**
     * The printed security code (TICKET package, Ticket::securityCode), or
     * null while that method does not exist or no ticket was issued.
     */
    public static function securityCode(string $pnr): ?string
    {
        if (!class_exists('Ticket') || !method_exists('Ticket', 'securityCode')) {
            return null;
        }
        try {
            $hash = (string) Database::scalar(
                'SELECT t.qr_hash FROM tickets t JOIN bookings b ON b.id = t.booking_id
                  WHERE b.pnr = :p ORDER BY t.id DESC LIMIT 1',
                ['p' => strtoupper(trim($pnr))], ''
            );
            if ($hash === '') {
                return null;
            }
            $code = (string) call_user_func(['Ticket', 'securityCode'], strtoupper(trim($pnr)), $hash);
            return $code !== '' ? $code : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
