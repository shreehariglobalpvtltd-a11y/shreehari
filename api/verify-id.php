<?php
/**
 * api/verify-id.php — "Verify our agent" as JSON (23 Oct 2026, owner prompt
 * registration system point 19: GET verify/{code}, public).
 *
 *   GET ?c=<REF>.<12 hex>              the code inside the ID card's QR
 *   GET ?ref=AGR-000035&tail=1234      card number + last 4 digits of the mobile
 *
 * Answer: {ok, state, person?} — state is valid | invalid (genuine card, not
 * active now) | forged (code not ours) | gone (no such active card) |
 * nomatch (number + digits do not match) | wait (too many checks) | empty.
 * person = photo (small JPEG data URI), name, role, standing, card number,
 * valid till, verify page link. Never the phone, an ID document, an address
 * or a date of birth (includes/idverify.php). No login and no token: the
 * same facts the printed QR shows anyone. Rate-limited per visitor.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/idcard.php';
require_once INCLUDE_PATH . '/idverify.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Access-Control-Allow-Origin: *');

$say = static function (array $body, int $code = 200): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $say(['ok' => false, 'state' => 'method', 'error' => 'GET only'], 405);
}
$ip = Security::clientIp();
if (!Security::rateLimit('idcard_verify_api', $ip, 30, 60)) {
    $say(['ok' => false, 'state' => 'wait'], 429);
}

$code = trim((string) ($_GET['c'] ?? ''));
$ref  = null;
if ($code !== '') {
    $ref = IdCard::parse($code);
    if ($ref === null) {
        $say(['ok' => false, 'state' => 'forged']);
    }
} elseif (trim((string) ($_GET['ref'] ?? '')) !== '') {
    $m = IdVerify::manual((string) $_GET['ref'], (string) ($_GET['tail'] ?? ''), $ip);
    if ($m['state'] !== 'ok') {
        $say(['ok' => false, 'state' => $m['state'] === 'bad' ? 'empty' : $m['state']], $m['state'] === 'wait' ? 429 : 200);
    }
    $ref = (string) $m['ref'];
} else {
    $say(['ok' => false, 'state' => 'empty']);
}

$p = IdCard::person($ref);
$state = $p === null ? 'gone' : ($p['valid'] ? 'valid' : 'invalid');
if ($p !== null && Security::rateLimit('idcard_verify_log', $ip . '|' . $p['ref'], 3, 600)) {
    Logger::audit('idcard.verify', $p['kind'], $p['ref'], null, ['valid' => $p['valid'], 'via' => $code !== '' ? 'api-qr' : 'api-typed'], 'ID card checked (API)');
}
$say(IdVerify::publicJson($p, $state));
