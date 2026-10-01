<?php
/**
 * tests/wa-staff-menu-test.php — the staff menu on WhatsApp (26 Sep 2026).
 *
 * Owner: "admin ma hi lekhera athawa kei msg lekhera jaba thau samma
 * puryaidinu paryo, guide ni". A member of staff who writes hi / namaste /
 * menu gets the map of what the number does and links into the panel.
 *
 *   php tests/wa-staff-menu-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/wabot.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 300) : '') . "\n"; }
}
echo "\n=== WhatsApp staff menu ===\n\n";

/* 1. What counts as a greeting — and what must not. */
foreach (['hi', 'Hi!', 'HII', 'hello', 'Hello 🙏', 'namaste', 'Namaskar', 'नमस्ते', 'नमस्कार 🙏', 'menu', 'मेनु', 'help', 'सहायता', '?', 'k garne'] as $g) {
    check('greeting: ' . $g, WaBot::isStaffGreeting($g));
}
foreach (['SHG-2026-0001-ABCD', 'bholi 2 seat Ram 9876543210', 'hi Ram ko ticket resend', 'aaja ko report', '', 'hello world how are you doing today'] as $n) {
    check('not a greeting: ' . ($n === '' ? '(empty)' : $n), !WaBot::isStaffGreeting($n));
}

/* 2. The menu itself. */
$menu = WaBot::staffMenu(['name' => 'Ram Bahadur', 'role' => 'staff']);
check('greets by name', str_contains($menu, 'Ram Bahadur'));
check('says staff', str_contains($menu, '(staff)'));
check('PNR line', str_contains($menu, 'PNR'));
check('admin link', str_contains($menu, appUrl('admin/')));
check('quick ticket link', str_contains($menu, 'quick-ticket.php'));
check('bookings link', str_contains($menu, 'bookings.php'));
check('messages log link', str_contains($menu, 'messages-log.php'));
check('says how to get it again', str_contains($menu, '"menu"'));
check('fits a WhatsApp message', mb_strlen($menu) < 1500, (string) mb_strlen($menu));
$admin = WaBot::staffMenu(['name' => '', 'role' => 'admin']);
check('admin role shown', str_contains($admin, '(admin)'));
check('no name → साथी', str_contains($admin, 'साथी'));
/* Assistant lines only while the assistant is on. */
$aiOn = false;
try { require_once INCLUDE_PATH . '/aiagent.php'; $aiOn = AiAgent::enabled(); } catch (Throwable $e) {}
check('assistant lines follow AiAgent::enabled() = ' . ($aiOn ? 'on' : 'off'), str_contains($menu, '2️⃣') === $aiOn);
check('bulk line follows wa_bulk_on', str_contains($menu, 'FORMAT') === Settings::getBool('wa_bulk_on', false));

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL ? 1 : 0);
