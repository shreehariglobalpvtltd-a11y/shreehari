<?php
/**
 * ticket-cache-test.php — a cached ticket older than its layout stamp is
 * drawn again, once (24 Sep 2026).
 *
 * Ticket::pngPath() and Ticket::pdfPath() keep the rendered file. When a
 * layout changes (the two-floor seat labels, the counter line) the stamps
 * PNG_LAYOUT_CHANGED / PDF_LAYOUT_CHANGED move forward so every older file
 * re-renders ONCE on its next open. pdfPath() computed $stale but still told
 * renderWithLock() to skip a file that existed, so a stale PDF was never
 * redrawn: printed and e-mailed tickets kept the old seat names ("UA1").
 *
 * The suite renders one real ticket, ages its cached PNG and PDF to before
 * the stamps, opens both again without force, and checks that
 *   1. both files come back newer than their stamp (redrawn), and
 *   2. a file already newer than the stamp is left alone (drawn once only).
 *
 * Writes: one ticket's cached PNG + PDF. It refuses to run anywhere but a
 * test database — the same rule tests/run-all.php enforces.
 *
 *   php tests/ticket-cache-test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../includes/bootstrap.php';
/* bootstrap does not autoload these (see tests/counter-location-test.php). */
require_once __DIR__ . '/../includes/ticket.php';
require_once __DIR__ . '/../includes/agentwallet.php';
require_once __DIR__ . '/../includes/pdf.php';

$PASS = 0;
$FAIL = 0;

function check(string $label, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    }
}

echo "\n=== Ticket cache: a stale PNG / PDF is redrawn once ===\n\n";

$dbName = defined('DB_NAME') ? (string) DB_NAME : '';
if (stripos($dbName, 'test') === false) {
    echo "  refusing to run: database '{$dbName}' is not a test database (this suite re-renders ticket files)\n\n";
    exit(1);
}

$rc = new ReflectionClass(Ticket::class);
$pngStamp = (int) strtotime((string) $rc->getConstant('PNG_LAYOUT_CHANGED'));
$pdfStamp = (int) strtotime((string) $rc->getConstant('PDF_LAYOUT_CHANGED'));
check('PNG_LAYOUT_CHANGED is a real moment in the past', $pngStamp > 0 && $pngStamp <= time(), date('c', $pngStamp));
check('PDF_LAYOUT_CHANGED is a real moment in the past', $pdfStamp > 0 && $pdfStamp <= time(), date('c', $pdfStamp));

$row = Database::fetch(
    "SELECT b.id FROM bookings b
       JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
      WHERE b.status IN ('confirmed','completed')
      ORDER BY b.id DESC LIMIT 1"
);

if ($row === null) {
    echo "  \033[33mSKIP\033[0m  no confirmed booking in this database to render\n";
} else {
    $bid = (int) $row['id'];
    try {
        $png = Ticket::pngPath($bid, true);
        $pdf = Ticket::pdfPath($bid, true);
        check('the ticket renders (PNG + PDF)', is_file($png) && is_file($pdf), basename($png) . ', ' . basename($pdf));

        /* 1. aged past the stamp -> redrawn on a plain open */
        touch($png, $pngStamp - 3600);
        touch($pdf, $pdfStamp - 3600);
        clearstatcache();
        Ticket::pngPath($bid);
        Ticket::pdfPath($bid);
        clearstatcache();
        check('a stale PNG is redrawn on its next open', (int) filemtime($png) >= $pngStamp, date('c', (int) filemtime($png)));
        check('a stale PDF is redrawn on its next open', (int) filemtime($pdf) >= $pdfStamp, date('c', (int) filemtime($pdf)));

        /* 2. already newer than the stamp -> left alone */
        $fresh = max($pngStamp, $pdfStamp) + 60;
        if ($fresh < time()) {
            touch($png, $fresh);
            touch($pdf, $fresh);
            clearstatcache();
            Ticket::pngPath($bid);
            Ticket::pdfPath($bid);
            clearstatcache();
            check('a current PNG is not redrawn again', (int) filemtime($png) === $fresh);
            check('a current PDF is not redrawn again', (int) filemtime($pdf) === $fresh);
        } else {
            echo "  \033[33mSKIP\033[0m  the stamps are less than a minute old; the draw-once check needs a moment after them\n";
        }
    } catch (Throwable $e) {
        check('the ticket cache round trip', false, $e->getMessage());
    }
}

echo "\n----------------------------------------\n";
echo "  {$PASS} passed, {$FAIL} failed\n";
echo "----------------------------------------\n\n";

exit($FAIL === 0 ? 0 : 1);
