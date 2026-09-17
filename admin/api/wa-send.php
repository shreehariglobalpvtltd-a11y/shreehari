<?php
/**
 * =====================================================================
 *  admin/api/wa-send.php — one-click WhatsApp from the panel
 *  (17 Sep 2026)
 *
 *  POST JSON {action, purpose, agent_id | pnr, from, to, on, ledger_id, note}
 *
 *    action=preview   compose the message and say HOW it can go out:
 *                     {ok, to, intl, text, mediaUrl, link, canAutoSend,
 *                      sessionOpen, templateConfigured, driver, reason}
 *    action=send      send through Notify::whatsapp() (logged to
 *                     message_logs with purpose / admin / agent), or —
 *                     when WhatsApp would refuse a free-text message
 *                     outside a 24-hour chat and no approved template is
 *                     configured — return the wa.me link instead of
 *                     burning a send that fails 63016 later.
 *    action=handoff   record that the staff member opened the wa.me link
 *                     on their own phone (message_logs status 'skipped',
 *                     provider 'manual' — the convention booking-view.php
 *                     already used), so the log is complete either way.
 *
 *  Gates: signed-in staff (bookings.view), CSRF, per-purpose permission
 *  from WaTemplates::REGISTRY, agent scoping (a counter agent only ever
 *  targets themselves or their own bookings — enforced inside compose()),
 *  the office switch wa_admin_tools_enabled, and a per-admin rate limit.
 * =====================================================================
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_guard.php';
require_once INCLUDE_PATH . '/watemplates.php';
$admin = admin_boot('bookings.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function waOut(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function waErr(string $error, array $extra = []): void
{
    waOut(['ok' => false, 'error' => $error] + $extra);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    waErr('POST only.');
}
if (!Security::verifyCsrf()) {
    waErr('Your session expired — reload the page and try again.');
}
if (!Settings::getBool('wa_admin_tools_enabled', true)) {
    waErr('WhatsApp tools are switched off in Settings (wa_admin_tools_enabled).');
}
$adminId = (int) ($admin['id'] ?? 0);
if (!Security::rateLimit('wa_send', 'admin:' . $adminId, 40, 60)) {
    waErr('Too many WhatsApp actions in a minute — pause and try again.');
}

$in      = Response::input();
$action  = (string) ($in['action'] ?? 'preview');
$purpose = Security::clean((string) ($in['purpose'] ?? ''), 40);
$reg     = WaTemplates::REGISTRY[$purpose] ?? null;
if ($reg === null) {
    waErr('Unknown message type.');
}
if (!Auth::can((string) $reg['perm'])) {
    waErr('Your role may not send this message type.');
}

$ctx = [
    'agent_id'  => (int) ($in['agent_id'] ?? 0),
    'pnr'       => Security::clean((string) ($in['pnr'] ?? ''), 40),
    'from'      => Security::clean((string) ($in['from'] ?? ''), 10),
    'to'        => Security::clean((string) ($in['to'] ?? ''), 10),
    'on'        => Security::clean((string) ($in['on'] ?? ''), 10),
    'ledger_id' => (int) ($in['ledger_id'] ?? 0),
    'note'      => Security::clean((string) ($in['note'] ?? ''), 300),
];
$targetKey = $reg['target'] === 'agent' ? 'agent:' . $ctx['agent_id'] : ($reg['target'] === 'booking' ? $ctx['pnr'] : 'office');

try {
    $msg = WaTemplates::compose($purpose, $ctx, $admin);
} catch (Throwable $e) {
    waErr($e->getMessage());
}

$driver  = Settings::getString('whatsapp_driver', 'click_to_chat');
$apiOn   = ($driver === 'twilio' && Settings::getString('twilio_account_sid', '') !== '' && Settings::getString('twilio_whatsapp_from', '') !== '')
        || ($driver === 'cloud_api' && Settings::getString('whatsapp_api_token', '') !== '');
$sidKey  = (string) $msg['contentSidKey'];
$contentSid = $sidKey !== '' ? Settings::getString($sidKey, '') : '';
// The ticket template carries the ticket vars; any other purpose has no
// template variables yet, so its Content SID can only be used once the
// office maps variables — until then it counts as "not configured".
$templateConfigured = $contentSid !== '' && $msg['templateVars'] !== [];
$sessionOpen = WaTemplates::inSessionWindow($msg['intl']);
$paused      = $driver === 'twilio' ? Notify::twilioPause() : null;
$canAutoSend = $apiOn && $paused === null && ($templateConfigured || $sessionOpen || $driver === 'cloud_api');
$reason = !$apiOn ? 'No WhatsApp API is configured — the message opens on your phone instead.'
    : ($paused !== null ? 'Twilio is paused after an account-level refusal until ' . date('H:i', (int) $paused['until']) . ' — use your phone for now.'
    : ($canAutoSend ? ($templateConfigured ? 'Sent through the approved template.' : 'This number wrote to us in the last 24 h, so free text is delivered.')
    : 'WhatsApp only delivers free text inside a 24-hour chat; this number has not written to us recently and no approved template is set for this message (Settings → ' . $sidKey . '). It opens on your phone instead.'));

$link = whatsappLink($msg['intl'], $msg['text']);

if ($action === 'preview') {
    waOut([
        'ok' => true, 'purpose' => $purpose, 'label' => $msg['label'], 'to' => $msg['to'], 'intl' => $msg['intl'],
        'recipient' => $msg['recipientName'], 'text' => $msg['text'], 'mediaUrl' => $msg['mediaUrl'],
        'attachments' => $msg['attachments'], 'link' => $link, 'driver' => $driver,
        'canAutoSend' => $canAutoSend, 'sessionOpen' => $sessionOpen, 'templateConfigured' => $templateConfigured, 'reason' => $reason,
    ]);
}

$meta = [
    'purpose'        => $purpose,
    'admin_id'       => $adminId,
    'agent_admin_id' => $msg['agentId'],
    'media_url'      => $msg['mediaUrl'],
    'media_type'     => $msg['mediaType'],
];

if ($action === 'handoff') {
    Notify::logOutbound($msg['intl'], $msg['text'], 'skipped', $meta + [
        'provider' => 'manual', 'bookingId' => $msg['bookingId'],
        'error' => 'handed to staff phone (wa.me)',
    ]);
    Logger::audit('wa.handoff', 'message', $purpose . ':' . $targetKey, null,
        ['to' => $msg['intl'], 'purpose' => $purpose], 'opened on the staff phone by admin #' . $adminId);
    waOut(['ok' => true, 'handoff' => true, 'link' => $link]);
}

if ($action !== 'send') {
    waErr('Unknown action.');
}

if (!$canAutoSend) {
    Notify::logOutbound($msg['intl'], $msg['text'], 'skipped', $meta + [
        'provider' => 'manual', 'bookingId' => $msg['bookingId'],
        'error' => 'not auto-sent: ' . mb_substr($reason, 0, 200),
    ]);
    Logger::audit('wa.send', 'message', $purpose . ':' . $targetKey, null,
        ['to' => $msg['intl'], 'purpose' => $purpose, 'result' => 'handoff'], $reason);
    waOut(['ok' => false, 'link' => $link, 'handoff' => true, 'reason' => $reason]);
}

if ($templateConfigured) {
    $meta['content_sid'] = $contentSid;
}
$res = Notify::whatsapp(
    $msg['to'],
    $msg['text'],
    $msg['mediaUrl'],
    $msg['hint'],
    $templateConfigured ? $msg['templateVars'] : [],
    $msg['bookingId'],
    $meta
);

if ($res === true) {
    Logger::audit('wa.send', 'message', $purpose . ':' . $targetKey, null,
        ['to' => $msg['intl'], 'purpose' => $purpose, 'result' => 'sent', 'media' => $msg['mediaUrl']], 'sent by admin #' . $adminId);
    waOut(['ok' => true, 'sent' => true, 'to' => $msg['intl'], 'message' => 'Sent to +' . $msg['intl'] . ' on WhatsApp.']);
}
if (is_string($res) && $res !== '') {
    $pause = $driver === 'twilio' ? Notify::twilioPause() : null;
    Logger::audit('wa.send', 'message', $purpose . ':' . $targetKey, null,
        ['to' => $msg['intl'], 'purpose' => $purpose, 'result' => 'fallback'], 'provider refused — link offered to admin #' . $adminId);
    waOut(['ok' => false, 'link' => $res, 'handoff' => true,
        'reason' => $pause !== null
            ? 'Twilio is refusing the account right now — paused until ' . date('H:i', (int) $pause['until']) . '. Send from your phone for now.'
            : 'The provider did not accept the message. Send it from your phone instead.']);
}
waErr('WhatsApp send failed. Use Settings → "Test WhatsApp" to see the provider error, or check logs/ (channel: whatsapp).');
