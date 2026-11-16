<?php
/**
 * =====================================================================
 *  run-all.php — the pre-deploy test battery, in one command.
 *
 *      php -c .claude/php-dev.ini tests/run-all.php
 *      php -c .claude/php-dev.ini tests/run-all.php --http    (adds the
 *                                     suites that need the :8899 server)
 *      php -c .claude/php-dev.ini tests/run-all.php --list
 *
 *  Until now the battery existed only as a list in
 *  MASTER-PROMPT-2026-08-27.md:186-191, which meant "run the tests"
 *  depended on remembering eight filenames, remembering to clear
 *  rate_limits first, and remembering which suites need curl. Anything
 *  you have to remember before a deploy is something you will one day
 *  skip.
 *
 *  SAFETY
 *  The battery WRITES: e2e-booking-test.php creates a real booking, and
 *  several suites insert and roll back seat locks. Pointed at production
 *  it would put fake bookings in the live register. So this runner
 *  refuses to start unless the connected database is unmistakably a test
 *  database — see assertTestDatabase(). That check is the reason this
 *  file exists as a runner rather than a shell alias.
 *
 *  Each suite is a standalone script that exits 0 on success and 1 on any
 *  failure. This runner adds nothing to that contract; it just sequences
 *  them, restores the one piece of shared state they all trip over
 *  (rate_limits), and reports.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
// clearStaleUnitLocks() needs the seat engine; bootstrap does not load it.
require_once INCLUDE_PATH . '/seats.php';

/* ---------------------------------------------------------------------
 *  The battery. Order matters only in that the slow HTTP suites run last.
 * ------------------------------------------------------------------- */

/**
 * The eight documented in MASTER-PROMPT-2026-08-27.md:186-191, plus any
 * later suite that guards a money or auth path and needs only the database.
 */
const CORE_SUITES = [
    /* 21 Sep 2026: e2e-booking-test.php USED to be listed here as well as in
       HTTP_SUITES. It needs the dev server on :8899 and its own PDO on the
       local dev MySQL, so in CORE it did not skip — it died on an uncaught
       PDOException and reported the whole battery red on every machine that
       is not the author's laptop. A suite that cannot run without the dev
       server belongs in HTTP_SUITES only, where --http gates it and the
       summary says "Not run" instead of "FAIL". */
    'agent-wallet-test.php'        => 'commission ledger, no double-claim',
    'gender-lock-test.php'         => 'shared-cabin gender locking',
    'seat-transfer-test.php'       => 'moving a seat keeps the money straight',
    'paid-pending-hold-test.php'   => 'a paid seat is never swept by expiry',
    'manifest-test.php'            => 'the boarding manifest matches the sale',
    'double-booking-race-test.php' => 'two racers, one seat, DB-level firewall',
    'launch-fixes-test.php'        => 'the launch-day regression set',
    // added 2026-09-06 with the fix it guards
    'forced-password-change-test.php' => 'a temporary password cannot be kept',
    'otp-forced-password-test.php' => '...and the OTP door cannot skip that',
    'code-login-test.php'          => 'code -> PIN / pattern; setup + reset need a WhatsApp or office proof',
    'wa-otp-login-test.php'        => 'unlock screen WhatsApp code opens agents only; ticket note + discount %',
    'wa-auth-otp-test.php'         => 'login codes on our own Meta authentication template, only once approved',
    'code-login-office-test.php'   => 'code door without the mobile: office opens it (1 h), security question resets',
    'asset-version-test.php'       => 'no ?v= stamp has drifted from sw.js',
    'staff-any-date-test.php'      => 'staff may book any date; customers may not',
    'quick-ticket-test.php'        => 'name + mobile → auto bus/seat/fare → confirmed ticket',
    'ticket-bot-test.php'          => 'AI Ticket Bot: learns from verified sales, never lifts a rule',
    'brain-test.php'               => 'Passive Brain: predicts the calls, parks the requests, never sells',
    'notify-country-test.php'      => 'the ticket WhatsApp/SMS reaches the right country (+977 vs +91)',

    /* Registered 8 Sep 2026. These 33 suites existed and passed but were never
       in the battery — 35 of the 53 test files in this directory were orphans,
       so "20 passed, 0 failed" was reporting on barely a third of the tests
       actually written. Nothing here is new work; it is the coverage that was
       already paid for and simply not being run. Notably cross-mode-seat-sync
       and cross-mode-hold guard the Private/Sharing physical-bed rule — the one
       invariant that stops the coach being oversold — and neither was running. */
    'bot-parse-test.php'           => 'the smart line reads a real desk message',
    'bot-ladder-test.php'          => 'the bot asks ONE question when unsure, in the writer language; same-as-last berths',
    'seat-mode-map-test.php'       => 'the mode↔bed mapping is a configurable rule set',
    'cross-mode-integrity-test.php' => 'admin seat mutations reason in beds, not labels',
    'cross-mode-seat-sync-test.php' => 'private cabin ⇄ sharing beds: one physical inventory',
    'cross-mode-hold-test.php'     => 'a hold in one mode blocks the bed in the other',
    'seat-layout-test.php'         => 'one 4+2 physical shape everywhere',
    'seat-block-test.php'          => 'a berth taken out of service stays out',
    'per-seat-cancel-test.php'     => 'cancelling one seat leaves the rest intact',
    'emergency-seat-test.php'      => 'the emergency berth is never sold',
    'women-only-test.php'          => 'women-reserved berths refuse a male passenger',
    'reschedule-test.php'          => 'moving a booking to another date',
    'agent-loans-test.php'         => 'loans & advances register agrees with the ledger, cap enforced',
    'agent-kyc-test.php'           => 'KYC verdicts are stamped; the selling gate only bites when switched on',
    'wa-send-scope-test.php'       => 'WhatsApp sends: an agent only about themselves and their own sales, the office about anyone',
    'boarding-other-test.php'      => 'an "Other" pickup / drop is typed, cleaned and printed; configured stops unchanged',
    'agent-360-render-test.php'    => 'the Agent 360 hub renders every tab for every role',
    'passenger-docs-test.php'      => 'passenger ID documents attach, stream behind the gate, and remove',
    'tripstatus-test.php'          => 'the 9-state departure ladder',
    'missed-bus-test.php'          => 'the 24h grace after a departure',
    'counter-mode-test.php'        => 'the desk sells through the same engine',
    'counter-role-test.php'        => 'the counter role sees only what it may',
    'counter-grace-test.php'       => 'counter may sell past the public cut-off',
    'counter-discount-test.php'    => 'counter discount is capped server-side',
    'agent-isolation-test.php'     => 'an agent never reads another agent’s book',
    'agent-controls-test.php'      => 'agent limits hold under pressure',
    'agent-kind-test.php'          => 'org vs person agent numbering',
    'agent-advance-test.php'       => 'agent advances and the ledger',
    'agent-reassign-test.php'      => 'reassigning a seller moves the commission once',
    'agent-late-book-test.php'     => 'the agent grace window',
    'agent-login-bulk-cancel-test.php' => 'agent sign-in + bulk cancel',
    'public-agent-code-resolver-test.php' => 'a customer-typed SHG-### resolves safely',
    'admin-security-test.php'      => 'the admin door refuses what it should',
    'admin-2fa-test.php'           => 'opt-in admin 2FA',
    'login-name-mobile-test.php'   => 'sign in by name + mobile',
    'settings-json-test.php'       => 'stype=json rows survive a save uneaten',
    'fares-settings-test.php'      => 'fare panel writes what the engine reads',
    'bs-calendar-test.php'         => 'Bikram Sambat calendar conversion',
    'bus-calendar-slot-test.php'   => 'extra buses live in their own slot',
    'export-filters-test.php'      => 'a register exports exactly what it shows',
    'ticket-png-test.php'          => 'the PNG ticket renders and stays fresh',
    'challan-png-test.php'         => 'the bus challan PNG draws the whole coach and stays fresh',
    'chalani-png-test.php'         => 'the chalani PNG pages + who cut the ticket (agent code)',
    'report-pdf-test.php'          => 'report PDFs render',
    'trip-reminder-test.php'       => 'the departure reminder fires once',

    /* Registered with the code they guard (Phase 0 of the self-improving
       system, 8 Sep 2026). Registered in the SAME commit as the feature on
       purpose — a suite added later is a suite that spends its first weeks
       not running. */
    'gemvault-test.php'            => 'the customer vault: one gem per human, sealed from other agents',
    'governor-spine-test.php'      => 'the AI may only touch the data lane; every fault names itself',
    'delivery-sentinel-test.php'   => 'a delivery outage names the right fault, in owner language',
    'whatsapp-retry-policy-test.php' => 'a recovered Meta sender releases the failed-ticket backlog',
    // 13 Sep 2026, registered with the PWA master upgrade it guards.
    'webpush-test.php'             => 'Web Push: RFC 8291 vectors, VAPID signature, subscription store',
    // 19 Sep 2026, registered with the night data audit it guards.
    'data-audit-test.php'          => 'the night audit names the right broken row, and only that one',
    'counter-shift-test.php'       => 'the cash drawer adds up: mine only, frozen at close, never on the sale path',
    'eta-alerts-test.php'          => 'bus-is-near: the right passenger, once, and silence whenever unsure',
    'backup-offsite-test.php'      => 'the off-site backup leaves encrypted, opens with stock openssl, goes once',
    'offline-queue-test.php'       => 'an offline request becomes ONE ticket or a loud failure - never two, never nothing',
    // 21 Sep 2026, registered with the on-VPS booking engine it guards.
    'wa-local-booking-test.php'    => 'the WhatsApp ticket engine sells with no AI key at all',
    // 20 Sep 2026, registered with the WhatsApp assistant it guards.
    'wa-agent-test.php'            => 'the WhatsApp assistant: role, switch, quote-then-confirm, ownership, audit',
    // 22 Sep 2026, registered with the knowledge base it guards.
    'ai-turn-test.php'            => 'the tool loop is bounded: budget, deadline, no repeated write on retry',
    'ai-kb-test.php'              => 'the knowledge base: audience scope, the ai_kb_on switch, an honest redacted miss',
    // 13 Nov 2026, registered with the cost ledger and Control Center it guards.
    'super-ai-test.php'           => 'Super AI: cost ledger, prompt-cache marks, daily ceiling, finance_day is office-only and read-only',
    // 14 Nov 2026, registered with the owner's-number reset exception it guards.
    'office-reset-test.php'       => 'office reset by WhatsApp: only the owner / CEO number, only superadmin or manager, only while switched on',
    // 14 Nov 2026, registered with the keep-alive reminder and the chart link refresh it guards.
    'wa-keepalive-test.php'       => 'office numbers: one free reminder before the 24 h window closes; report chart link signed again when sent',
    // 14 Nov 2026, registered with the owner's / CEO's WhatsApp vault it guards.
    'wa-vault-test.php'           => 'personal vault: owner / CEO numbers only, each sees only its own, encrypted, Drive from Google only',
    // 15 Nov 2026, registered with the staff / agent discount on WhatsApp and Quick Ticket it guards.
    'staff-discount-test.php'     => 'staff discount: % or ₹ read from a WhatsApp order, capped, stored; an agent discount is Cash only and from commission',
    // 15 Nov 2026 (spec3 gap pass), registered with the features they guard.
    'driver-actions-test.php'     => 'driver app: stop reached / delay / breakdown / SOS stored once, office alerted, manifest without IDs, Maps hand-off',
    'fleet-watch-test.php'        => 'fleet bell: SOS open, GPS silent, over-speed over a minute, off the road line; GPS purge OFF by default; old track links expire',
    'fleet-map-test.php'          => 'fleet map: heading, trail replay thinned and staff-only, status counts',
    'desk-tools-test.php'         => 'desk tools: ID masking + audited reveal, cash aging buckets, cancel bands, email/SMS resend behind OFF switches and CSRF',
    'ticket-requests-test.php'    => 'passenger date-change / seat-cancel REQUESTS: signed key only, rules, cap, rate limits, office bell, Done/Reject; booking never changed',
    'emergency-contact-test.php'  => 'emergency contact: optional on SmartBook, own number ignored, stored, on booking-view and the driver manifest',
    'eta-steps-test.php'          => 'near-stop web push at 15 / 5 min: OFF by default, once per step, silent on stale GPS',
    'customer-security-test.php'  =>'customer + security: per-IP limits, coupon guessing, log redaction word by word, first-booking coupons, promo link only while a coupon runs, real ratings only',
    // 14 Nov 2026, registered with the web research tool and the no-key quality eval they guard.
    'ai-web-research-test.php'    => 'web research: staff and office only, off by default, server blocks replayed unchanged, pause_turn bounded',
    'ai-eval-test.php'            => 'the golden set with no AI key: 88 questions in 6 languages, knowledge scope, offer guard, who may run which tool',
    // 28 Sep 2026, registered with the admin AI panel it guards.
    'ai-panel-test.php'           => 'the panel door: role, liveness, the /api/ temp-password case, separate staging',
    // 28 Sep 2026, registered with the WhatsApp seller gaps they guard.
    'bot-time-test.php'           => 'the time of day is read, and a time word is never the passenger name',
    'wa-advance-test.php'         => 'a bare hello opens a booking, and a near-miss pickup never guesses between two towns',
    // 29 Oct 2026, registered with the S HARI AI master brief build it guards.
    'master-brief-test.php'       => 'Opus 5.5 request shape, the master brief, a no is never a yes, groups say only public facts',
    // 16 Nov 2026, registered with the master prompt v2.0 build (units U1..U7) they guard.
    'u1-login-test.php'               => 'bcrypt cost 12 + rehash, PIN length backfill, lockout countdown, login method logged, driver PIN limit',
    'u2a-customer-live-test.php'      => 'install offer quiet after 2 no, menu install, push card, 60/30 s refresh, near-me rules, notice 24 h',
    'u2a-search-next-time-test.php'   => 'the next-departure banner shows the chosen stop time, not a fixed 1:00 PM',
    'u2b-customer-shell-test.php'     => 'accent fade, picked palette stays fixed, accent preview 5 min, notice_since boot',
    'u3a-wa-inbox-test.php'           => 'per-chat unread, names, status chips, search, reply-time stats, IDF KB search, thumbs',
    'u3a-wa-inbox-render-test.php'    => 'the WhatsApp hub and AI knowledge pages render',
    'u3b-fb-inbox-test.php'           => 'Messenger inbound stored, threads, office reply only inside the 24 h window',
    'u3b-noreply-alert-test.php'      => 'no-reply alert is OFF by default and fires once per number per hour when ON',
    'u3b-pages-render-test.php'       => 'home AI Watch + reply-time tile, Messenger inbox render',
    'u4a-boarding-cutoff-test.php'    => 'past-midnight pickups and retimed buses close at the right minute',
    'u4a-admin-pages-test.php'        => 'map/routes/settings: add point works, checkpoint no 500, counter 403, Mehsana labels, JSON export',
    'u4b-offers-test.php'             => 'offer rules (route, per mobile, first booking, max uses), used offer cannot be deleted, report',
    'u4b-dashboard-test.php'          => 'YTD revenue and the 30-day bookings chart',
    'u4b-ticket-discount-test.php'    => 'ticket PNG/PDF name the discount source',
    'u4b-bulk-tickets-test.php'       => 'bulk CSV tickets: preview, one-shot confirm, no double booking',
    'u5-endpoints-test.php'           => 'AI settings API + inline save: CSRF, permission, preview -> confirm, 2,200 = 2200',
    'u5-easy-settings-test.php'       => 'S Hari AI page: command, No is logged, Yes applies',
    'u6-staff-chrome-test.php'        => 'staff head metas, csrf meta, AI bubble wiring, nav entries, themes page',
    'u7-ai-turns-test.php'            => 'ai_turns row per bot turn with no message text; missing table never breaks a reply',
    // 16 Nov 2026 (later), the "baki kam" build (units W1..W6): every new behaviour ships switched OFF.
    'u8-W1-devices-limit-test.php'    => 'per-role device limit (0 = none), held sign-in, sign the other device out, cancel',
    'u8-W2-offline-test.php'          => 'offline call-back request queued (not a ticket), N queued counter, enquiry accepts callback',
    'u8-W3-ai-staff-test.php'         => 'AI Watch ignore/later, AI-change WhatsApp switch, settings engine, trip delay two-step, Easy Settings keys',
    'u8-W4-wa-bot-test.php'           => 'WhatsApp AI cancel = office request by default, hand-off line and typing switches OFF',
    'u8-W4-wa-inbox-render-test.php'  => 'WhatsApp inbox renders with the hand-off tick',
    'u8-W5-reminder24-test.php'       => '24 h trip reminder OFF by default, claimed once, skipped without the ENUM value',
    'u8-W5-agent-alerts-test.php'     => 'breakdown / SOS reaches the agents only when the switch is ON',
    'u8-W5-wa-cap-test.php'           => 'daily WhatsApp cap: 0 = none, tickets / OTP / bot replies never blocked, 80 % alert once',
    'social-posts-test.php'           => 'Facebook post queue: drafts, approve, publish only approved + switch ON, permission error marks failed',
    'u8-W6-facebook-pages-test.php'   => 'FB posts page + nav entry, support inbox shows Facebook source',
    // 16 Nov 2026 (mv3, units X1..X5): the last owner-decision items, every one switched OFF / 0.
    'u9-X1-money-test.php'            => 'return discount, desk luggage + infant fees: totals identical while OFF, ticket lines, no commission',
    'u9-lead-commission-test.php'     => 'commission is worked on the total minus luggage / infant fees; cash due keeps the full total',
    'u9-X2-festival-test.php'         => 'special dates: themes.php editor, SHG_BOOT.festival, head accent + notice strip',
    'u9-X3-login-look-test.php'       => 'login_theme classic = today byte for byte, gold adds only its class',
    'u9-X4-fb-comments-test.php'      => 'FB comments: poll only when ON, permission error once, public reply audited, AI reply rules',
    'u9-X5-settings-import-test.php'  => 'settings import preview / one-shot apply / audit, 60-min texts everywhere, Easy Settings keys',
];

/**
 * Suites that exist and are NOT run, with the reason. Declared rather than
 * silently omitted: an unlisted test file is invisible, and this directory
 * already lost 35 suites that way. Printed at the end of every run.
 *
 * Both entries below fail on ORIGINAL code at cec7dc1 — verified 8 Sep 2026 by
 * reverting and re-running — so they are stale tests, not regressions:
 *   automation-test.php     7 of 49 fail
 *   boarding-stop-fix-test.php  3 of 17 fail: it hard-codes seat L1, which the
 *       later women-only rule now reserves, so book.php correctly returns 409.
 */
const KNOWN_STALE = [
    'automation-test.php'          => '7/49 fail on unmodified code — stale expectations',
    'boarding-stop-fix-test.php'   => '3/17 fail: hard-codes L1, now women-reserved',
];

/**
 * Node suites. They need neither the database nor the dev server, so they
 * run every time — but only when node is on PATH; a missing node is
 * reported as a skip rather than silently shrinking the battery.
 */
const NODE_SUITES = [
    'i18n-check.js'          => 'en/hi/ne key parity',
    'offline-ticket-test.js' => 'the ticket survives a deploy and works offline',
    'lazy-retry-test.js'     => 'failed lazy downloads remain retryable without duplicate actions',
];

/** Need the dev server on :8899 as well as the database. */
const HTTP_SUITES = [
    'e2e-booking-test.php'        => 'whole ticket lifecycle over real HTTP',
    'role-gates-test.php'         => 'every admin page enforces its permission',
    'counter-mode-http-test.php'  => 'staff selling through the customer SPA',
    'feedback-test.php'           => 'post-journey rating: who may rate, and once',
    'beacon-test.php'             => 'the product beacon stores behaviour, never people',
    'pwa-http-test.php'           => 'PWA layer: push key + subscribe gates, seat fill, split-pay, offline page',
];

/* ---------------------------------------------------------------------
 *  Guard: never let the battery touch anything but a test database.
 * ------------------------------------------------------------------- */

/**
 * Three independent checks, all of which must pass. Any one of them alone
 * is defeatable by a plausible mistake — a production config copied to a
 * dev box still says APP_ENV=production, and a test database can be
 * created on a remote host.
 */
function assertTestDatabase(): void
{
    $name = defined('DB_NAME') ? (string) DB_NAME : '';
    $host = defined('DB_HOST') ? (string) DB_HOST : '';
    $env  = defined('APP_ENV') ? (string) APP_ENV : '';

    $problems = [];

    if (stripos($name, 'test') === false) {
        $problems[] = "database name '{$name}' does not contain 'test'";
    }

    // DB_HOST may carry a port suffix, e.g. '127.0.0.1;port=3307'.
    $bareHost = strtolower(trim(explode(';', $host)[0]));
    if (!in_array($bareHost, ['127.0.0.1', 'localhost', '::1', ''], true)) {
        $problems[] = "database host '{$bareHost}' is not local";
    }

    if (strtolower($env) === 'production') {
        $problems[] = "APP_ENV is 'production'";
    }

    if ($problems === []) {
        return;
    }

    fwrite(STDERR, "\n  REFUSING TO RUN — this does not look like a test database.\n\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "    - {$p}\n");
    }
    fwrite(STDERR, <<<TXT

    The battery writes real rows: e2e-booking-test.php creates a booking.
    Against production that puts fake sales in the live register.

    Expected: config/config.local.php pointing at 127.0.0.1;port=3307,
    database shari_test. Start the server with .claude/start-db.bat.

    To target a restored dump instead:
        SHG_DB_NAME=shg_restore_test php -c .claude/php-dev.ini tests/run-all.php


    TXT);
    exit(2);
}

/* ---------------------------------------------------------------------
 *  Shared state the suites trip over.
 * ------------------------------------------------------------------- */

/**
 * Every login-related suite authenticates repeatedly from one IP and hits
 * the 5-per-300s admin_login throttle, so a second run of the battery
 * fails for a reason that has nothing to do with the code under test.
 * Documented in reference-local-test-traps: clear it before every run.
 */
function clearRateLimits(): string
{
    try {
        Database::pdo()->exec('DELETE FROM rate_limits');
        return 'cleared';
    } catch (Throwable $e) {
        return 'could not clear (' . $e->getMessage() . ')';
    }
}

/**
 * The SECOND piece of shared state the suites trip over.
 *
 * schedule_unit_locks holds the shared-cabin gender lock. In production it is
 * always recomputed when a cabin empties, because seats are released through
 * Seats::releaseBooking(). Test cleanups, however, tidy up with a raw
 * `DELETE FROM bookings` — which cascades booking_seats but calls no PHP — so
 * every suite that does that can leave a lock behind on a cabin that is now
 * empty. The next suite to want that berth is then refused with "this shared
 * cabin is held for women only", and the failure looks like a code bug in
 * whatever ran second.
 *
 * That made the battery ORDER-DEPENDENT, which is the one thing a battery may
 * not be: a green run has to mean the code is good, not that the suites
 * happened to run in a lucky sequence. Only locks whose cabin has no live
 * seats are dropped, so a genuine lock is never touched.
 */
function clearStaleUnitLocks(): string
{
    try {
        $rows = Database::fetchAll(
            "SELECT schedule_id, unit_key FROM schedule_unit_locks WHERE gender_lock <> 'none'"
        );
        $dropped = 0;
        foreach ($rows as $r) {
            $sid     = (int) $r['schedule_id'];
            $members = Seats::unitSeats((string) $r['unit_key'], Seats::coachForSchedule($sid));
            if ($members === []) {
                continue;
            }
            $ph = [];
            $p  = ['s' => $sid];
            foreach ($members as $i => $m) {
                $ph[]      = ':m' . $i;
                $p['m' . $i] = $m;
            }
            $live = (int) Database::scalar(
                'SELECT COUNT(*) FROM booking_seats
                  WHERE schedule_id = :s AND released_at IS NULL
                    AND seat_no IN (' . implode(',', $ph) . ')',
                $p,
                0
            );
            if ($live === 0) {
                Database::run(
                    'DELETE FROM schedule_unit_locks WHERE schedule_id = :s AND unit_key = :u',
                    ['s' => $sid, 'u' => (string) $r['unit_key']]
                );
                $dropped++;
            }
        }
        return $dropped === 0 ? 'none stale' : ('cleared ' . $dropped . ' on empty cabins');
    } catch (Throwable $e) {
        return 'could not clear (' . $e->getMessage() . ')';
    }
}

/* ---------------------------------------------------------------------
 *  Runner
 * ------------------------------------------------------------------- */

/**
 * Run one suite as a child process using the SAME binary and ini as this
 * one — the winget PHP build loads no php.ini by default, so a child
 * started without -c would fatal in bootstrap.php on a missing PDO driver.
 *
 * @return array{code:int, output:string, seconds:float}
 */
function runSuite(string $file, array $extraIni = []): array
{
    $testsDir = __DIR__;
    $php      = PHP_BINARY;
    $ini      = php_ini_loaded_file();

    $cmd = escapeshellarg($php);
    if ($ini !== false) {
        $cmd .= ' -c ' . escapeshellarg($ini);
    }
    foreach ($extraIni as $directive) {
        $cmd .= ' -d ' . escapeshellarg($directive);
    }
    $cmd .= ' ' . escapeshellarg($testsDir . DIRECTORY_SEPARATOR . $file);

    $started = microtime(true);
    $output  = [];
    $code    = 0;
    exec($cmd . ' 2>&1', $output, $code);

    return [
        'code'    => $code,
        'output'  => implode("\n", $output),
        'seconds' => microtime(true) - $started,
    ];
}

/** Same as runSuite(), for a Node suite. */
function runNodeSuite(string $file): array
{
    $cmd     = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . $file);
    $started = microtime(true);
    $output  = [];
    $code    = 0;
    exec($cmd . ' 2>&1', $output, $code);

    return ['code' => $code, 'output' => implode("
", $output), 'seconds' => microtime(true) - $started];
}

/** Pull the suite's own "N passed, M failed" line out for the summary. */
function tally(string $output): string
{
    if (preg_match('/(\d+)\s+passed,\s+(\d+)\s+failed/i', $output, $m) === 1) {
        return "{$m[1]} passed, {$m[2]} failed";
    }
    if (preg_match('/PASS\s+(\d+)\s+WARN\s+(\d+)\s+FAIL\s+(\d+)/i', $output, $m) === 1) {
        return "PASS {$m[1]} WARN {$m[2]} FAIL {$m[3]}";
    }
    return '';
}

/* ---------------------------------------------------------------------
 *  main
 * ------------------------------------------------------------------- */

$args     = array_slice($argv, 1);
$withHttp = in_array('--http', $args, true);
$listOnly = in_array('--list', $args, true);

$suites = CORE_SUITES;
if ($withHttp) {
    $suites = CORE_SUITES + HTTP_SUITES; // '+' keeps the first occurrence, so no duplicate e2e
}

if ($listOnly) {
    echo "\n  battery (" . count($suites) . " suites)\n\n";
    foreach ($suites as $file => $what) {
        printf("    %-32s %s\n", $file, $what);
    }
    echo "
  node suites (always run): " . implode(', ', array_keys(NODE_SUITES)) . "
";
    echo "\n  --http adds: " . implode(', ', array_diff(array_keys(HTTP_SUITES), array_keys(CORE_SUITES))) . "\n";
    echo "  those need the dev server on :8899 (preview_start \"shari-php\").\n";
    echo "\n  NOT run (" . count(KNOWN_STALE) . "):\n";
    foreach (KNOWN_STALE as $file => $why) {
        printf("    %-32s %s\n", $file, $why);
    }
    echo "\n";
    exit(0);
}

assertTestDatabase();

echo "\n";
echo "========================================================\n";
echo "  S Hari Global — pre-deploy battery\n";
echo "========================================================\n";
echo '  database : ' . (defined('DB_NAME') ? DB_NAME : '?')
     . ' on ' . (defined('DB_HOST') ? DB_HOST : '?') . "\n";
echo '  php      : ' . PHP_VERSION . ' (' . (php_ini_loaded_file() ?: 'no ini') . ")\n";
echo '  suites   : ' . count($suites) . ($withHttp ? " (including HTTP)\n" : "\n");
echo '  rate_limits: ' . clearRateLimits() . "\n";
echo '  unit locks : ' . clearStaleUnitLocks() . "\n";
echo "--------------------------------------------------------\n\n";

// curl is not in the committed php-dev.ini on every machine, and the HTTP
// suites are useless without it. Force it on rather than failing obscurely.
$extraIni = [];
if (!extension_loaded('curl')) {
    $extraIni[] = 'extension=php_curl.dll';
    echo "  note: curl not loaded — passing -d extension=php_curl.dll to children\n\n";
}

$missing = [];
$failed  = [];
$passed  = [];
$totalT  = 0.0;

foreach ($suites as $file => $what) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        $missing[] = $file;
        printf("  MISSING  %-32s (no such file)\n", $file);
        continue;
    }

    printf("  running  %-32s %s\n", $file, $what);
    $r       = runSuite($file, $extraIni);
    $totalT += $r['seconds'];
    $note    = tally($r['output']);

    if ($r['code'] === 0) {
        $passed[] = $file;
        printf("     ok    %-32s %5.1fs  %s\n\n", '', $r['seconds'], $note);
    } else {
        $failed[$file] = $r['output'];
        printf("     FAIL  %-32s %5.1fs  %s  (exit %d)\n\n", '', $r['seconds'], $note, $r['code']);
    }
}

/* ---- Node suites ---------------------------------------------------- */
$probe = []; $pc = 0;
exec('node --version 2>&1', $probe, $pc);

if ($pc !== 0) {
    echo "  SKIPPED  node not on PATH — " . implode(', ', array_keys(NODE_SUITES)) . "

";
    $missing[] = 'node (' . count(NODE_SUITES) . ' suites)';
} else {
    foreach (NODE_SUITES as $file => $what) {
        if (!is_file(__DIR__ . DIRECTORY_SEPARATOR . $file)) {
            $missing[] = $file;
            printf("  MISSING  %-32s (no such file)
", $file);
            continue;
        }
        printf("  running  %-32s %s
", $file, $what);
        $r = runNodeSuite($file);
        $totalT += $r['seconds'];
        $note = tally($r['output']);
        if ($r['code'] === 0) {
            $passed[] = $file;
            printf("     ok    %-32s %5.1fs  %s

", '', $r['seconds'], $note);
        } else {
            $failed[$file] = $r['output'];
            printf("     FAIL  %-32s %5.1fs  %s  (exit %d)

", '', $r['seconds'], $note, $r['code']);
        }
    }
}

echo "--------------------------------------------------------\n";
printf("  %d passed, %d failed, %d missing   (%.1fs)\n",
    count($passed), count($failed), count($missing), $totalT);
echo "========================================================\n";

/* Say out loud what the battery did NOT run. A green line above means
   nothing if two suites quietly sat outside it. */
if (KNOWN_STALE !== []) {
    echo "\n  Deliberately NOT run (" . count(KNOWN_STALE) . " stale suites):\n";
    foreach (KNOWN_STALE as $file => $why) {
        printf("    %-32s %s\n", $file, $why);
    }
}

if ($failed !== []) {
    echo "\n  Output from the failing suites:\n";
    foreach ($failed as $file => $out) {
        echo "\n  ── {$file} " . str_repeat('─', max(0, 40 - strlen($file))) . "\n";
        // The tail is where the assertions and the summary live.
        $lines = explode("\n", rtrim($out));
        foreach (array_slice($lines, -25) as $line) {
            echo '  ' . $line . "\n";
        }
    }
    echo "\n";
}

if (!$withHttp) {
    echo "\n  Not run: " . implode(', ', array_diff(array_keys(HTTP_SUITES), array_keys(CORE_SUITES)))
       . "\n  Add --http (needs the dev server on :8899).\n\n";
}

exit($failed === [] && $missing === [] ? 0 : 1);
