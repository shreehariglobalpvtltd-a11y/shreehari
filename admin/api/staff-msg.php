<?php
/**
 * =====================================================================
 *  admin/api/staff-msg.php — Office chat JSON API (9 Oct 2026)
 *
 *  GET  ?action=inbox&filter=waiting|open|mine|urgent|closed|all&q=&kind=&agent=
 *  GET  ?action=thread&id=<thread>&after=<msgId>     messages (+ meta when after=0), marks read
 *  GET  ?action=people                               office: agents/counters + office people
 *  POST {action:'open', kind, pnr|agent_id|ref_id, body?, client_uuid?, urgent?}
 *  POST {action:'send', thread_id, body, client_uuid, urgent?}  — or multipart with
 *       `attach` (photo / PDF / voice note) + `voice=1` + `secs`
 *  POST {action:'read', thread_id, last_id}
 *  POST {action:'close'|'reopen'|'assign'|'urgent', thread_id, to?, on?}   office only
 *  POST {action:'broadcast', body, audience: agents|counters|all, client_uuid}  office + staff.manage
 *
 *  Gates: signed-in staff (bookings.view) through admin_boot — JSON 401/403
 *  because the URI has /api/ — then StaffMsg::canUse() (office or field
 *  role; a viewer never gets here). Every write: POST + CSRF (X-CSRF-Token)
 *  + a per-person rate limit. Every thread read/write: StaffMsg::mayOpen()
 *  — a foreign thread answers 404 exactly like a missing one. The stored
 *  file path is never in a response. Nothing here cancels, refunds or edits
 *  a booking: a request is a conversation (owner rule).
 * =====================================================================
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_guard.php';
require_once INCLUDE_PATH . '/staffmsg.php';
$admin = admin_boot('bookings.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function smOut(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // Pushes queued by a send go out after the answer has left (php-fpm).
    StaffMsg::flushNotify();
    exit;
}
function smErr(string $error, int $code = 400): never
{
    smOut(['ok' => false, 'error' => $error, 'code' => $code], $code);
}

if (!StaffMsg::available()) {
    smErr('Office chat is not set up on this server yet (upgrade-2026-10-09-staff-chat.sql).', 503);
}
if (!StaffMsg::canUse()) {
    smErr('Office chat is not available for your role. / तपाईंको भूमिकामा अफिस च्याट छैन।', 403);
}

$me     = (int) ($admin['id'] ?? 0);
$office = StaffMsg::isOffice();
$side   = $office ? 'office' : 'staff';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
StaffMsg::touchPresence($me);

/** The thread the request names, after the scope check. */
function smThread(int $id): array
{
    $t = StaffMsg::threadById($id);
    if ($t === null || !StaffMsg::mayOpen($t)) {
        smErr('Conversation not found. / कुराकानी भेटिएन।', 404);
    }
    return $t;
}

/** Header facts for one thread: who, numbers, presence. */
function smMeta(array $t, bool $office): array
{
    $p = StaffMsg::presentThread($t, $office, StaffMsg::agentCodes([(int) $t['agent_admin_id']]));
    $p['contacts'] = StaffMsg::contactsFor($t, $office);
    $p['presence'] = $office ? StaffMsg::presenceOf((int) $t['agent_admin_id']) : StaffMsg::officePresence();
    $p['bookingUrl'] = (string) ($t['pnr'] ?? '') !== '' && (int) ($t['booking_id'] ?? 0) > 0
        ? '/admin/booking-view.php?pnr=' . rawurlencode((string) $t['pnr']) : '';
    return $p;
}

/* =================================================================== GET */
if ($method === 'GET') {
    // Read-only from here: release the session lock so a 4 s poll never
    // queues behind a slow page in the same browser.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $action = (string) ($_GET['action'] ?? 'inbox');

    if ($action === 'inbox') {
        smOut([
            'ok'      => true,
            'office'  => $office,
            'unread'  => StaffMsg::unreadFor(),
            'threads' => StaffMsg::inbox([
                'filter' => (string) ($_GET['filter'] ?? ($office ? 'open' : '')),
                'q'      => (string) ($_GET['q'] ?? ''),
                'kind'   => (string) ($_GET['kind'] ?? ''),
                'agent'  => $office ? (int) ($_GET['agent'] ?? 0) : 0,
            ]),
        ]);
    }

    if ($action === 'thread') {
        $t     = smThread((int) ($_GET['id'] ?? 0));
        $after = max(0, (int) ($_GET['after'] ?? 0));
        $before = max(0, (int) ($_GET['before'] ?? 0));
        $rows  = StaffMsg::messages((int) $t['id'], $after, $after > 0 ? 200 : 60, $before);
        $msgs  = array_map(static fn(array $m): array => StaffMsg::present($m, $office, $me), $rows);
        $last  = $msgs !== [] ? (int) end($msgs)['id'] : 0;
        if ($last > 0 && $before === 0) {
            StaffMsg::markRead((int) $t['id'], $me, $last);
        }
        $out = [
            'ok'       => true,
            'id'       => (int) $t['id'],
            'messages' => $msgs,
            'peerRead' => StaffMsg::peerRead($t, $office),
            'status'   => (string) $t['status'],
            'priority' => (string) $t['priority'],
            'more'     => $after === 0 && count($rows) >= 60,
        ];
        if ($after === 0 && $before === 0) {
            $out['thread'] = smMeta($t, $office);
        }
        smOut($out);
    }

    if ($action === 'people') {
        if (!$office) {
            smErr('That list is for the office.', 403);
        }
        smOut(['ok' => true, 'field' => StaffMsg::fieldPeople(), 'office' => StaffMsg::officePeople()]);
    }

    smErr('Unknown action.');
}

/* ================================================================== POST */
if ($method !== 'POST') {
    smErr('POST only.', 405);
}
if (!Security::verifyCsrf()) {
    smErr('Your session expired — reload the page and try again. / पेज रिलोड गर्नुहोस्।', 419);
}
if (!Security::rateLimit('staff_msg', 'admin:' . $me, 40, 60)) {
    smErr('Too many messages in a minute — pause a moment. / एकछिन पर्खनुहोस्।', 429);
}

$in     = Response::input();
$action = (string) ($in['action'] ?? '');
$uuid   = isset($in['client_uuid']) ? Security::clean((string) $in['client_uuid'], 36) : null;
$urgent = !empty($in['urgent']) && (string) $in['urgent'] !== '0';

try {
    switch ($action) {
        case 'open': {
            $kind = (string) ($in['kind'] ?? 'general');
            $t = StaffMsg::threadFor($kind, [
                'pnr'      => (string) ($in['pnr'] ?? ''),
                'agent_id' => (int) ($in['agent_id'] ?? 0),
                'ref_id'   => (int) ($in['ref_id'] ?? 0),
            ]);
            $msgId = 0;
            $body  = (string) ($in['body'] ?? '');
            if (trim($body) !== '') {
                $msgId = StaffMsg::post((int) $t['id'], $body, $side, $me, null, $uuid, $urgent);
                Logger::audit('staffmsg.send', 'staff_thread', (string) $t['id'], null, ['msg' => $msgId, 'kind' => 'text']);
            }
            smOut(['ok' => true, 'thread_id' => (int) $t['id'], 'message_id' => $msgId]);
        }

        case 'send': {
            $t      = smThread((int) ($in['thread_id'] ?? 0));
            $attach = null;
            if (isset($_FILES['attach']) && is_array($_FILES['attach']) && (int) ($_FILES['attach']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $voice  = (string) ($in['voice'] ?? '') === '1';
                $attach = StaffMsg::storeUpload($_FILES['attach'], $voice);
                if ($attach['kind'] === 'voice') {
                    $attach['secs'] = max(0, min(StaffMsg::VOICE_SECS * 2, (int) ($in['secs'] ?? 0)));
                }
            }
            $msgId = StaffMsg::post((int) $t['id'], (string) ($in['body'] ?? ''), $side, $me, $attach, $uuid, $urgent);
            Logger::audit('staffmsg.send', 'staff_thread', (string) $t['id'], null,
                ['msg' => $msgId, 'kind' => $attach['kind'] ?? 'text', 'urgent' => $urgent ? 1 : 0]);
            $row = StaffMsg::messages((int) $t['id'], $msgId - 1, 1);
            smOut(['ok' => true, 'thread_id' => (int) $t['id'], 'message_id' => $msgId,
                'message' => $row !== [] ? StaffMsg::present($row[0], $office, $me) : null]);
        }

        case 'read': {
            $t = smThread((int) ($in['thread_id'] ?? 0));
            StaffMsg::markRead((int) $t['id'], $me, max(0, (int) ($in['last_id'] ?? 0)));
            smOut(['ok' => true, 'unread' => StaffMsg::unreadFor()]);
        }

        case 'close':
        case 'reopen': {
            if (!$office) {
                smErr('Only the office closes a conversation.', 403);
            }
            $t = smThread((int) ($in['thread_id'] ?? 0));
            StaffMsg::close((int) $t['id'], $me, $action === 'close');
            $nm = (string) ($admin['full_name'] ?: $admin['username']);
            // A system line never reopens a thread (StaffMsg::post), so the order is safe.
            StaffMsg::post((int) $t['id'], ($action === 'close' ? '✅ Closed by ' : '🔄 Reopened by ') . $nm, 'system', null);
            smOut(['ok' => true, 'status' => $action === 'close' ? 'closed' : 'open']);
        }

        case 'assign': {
            if (!$office) {
                smErr('Only the office assigns a conversation.', 403);
            }
            $t    = smThread((int) ($in['thread_id'] ?? 0));
            $to   = (int) ($in['to'] ?? $me);
            $name = StaffMsg::assign((int) $t['id'], $to, $me);
            StaffMsg::post((int) $t['id'], $to > 0 ? '👤 ' . $name . ' is handling this · ' . $name . ' ले हेर्दै हुनुहुन्छ' : '👤 Unassigned', 'system', null);
            smOut(['ok' => true, 'assignedTo' => $to, 'assignedName' => $name]);
        }

        case 'urgent': {
            if (!$office) {
                smErr('Only the office changes the priority.', 403);
            }
            $t = smThread((int) ($in['thread_id'] ?? 0));
            StaffMsg::setUrgent((int) $t['id'], !empty($in['on']));
            smOut(['ok' => true, 'priority' => !empty($in['on']) ? 'urgent' : 'normal']);
        }

        case 'broadcast': {
            if (!StaffMsg::canBroadcast()) {
                smErr('Announcements are sent by the office (staff.manage).', 403);
            }
            $aud = (string) ($in['audience'] ?? 'all');
            $aud = in_array($aud, ['agents', 'counters', 'all'], true) ? $aud : 'all';
            $n   = StaffMsg::broadcast((string) ($in['body'] ?? ''), $aud, $me, $uuid);
            smOut(['ok' => true, 'reached' => $n]);
        }

        case 'report': {
            // A field user reports a suspicious ticket (the scan page uses StaffMsg::reportSuspicious directly).
            $res = StaffMsg::reportSuspicious((string) ($in['pnr'] ?? ''), (string) ($in['body'] ?? ''));
            if (!$res['ok']) {
                smErr((string) ($res['error'] ?? 'Could not send the report.'), 422);
            }
            smOut($res);
        }
    }
    smErr('Unknown action.');
} catch (RuntimeException $e) {
    $code = (int) $e->getCode();
    smErr($e->getMessage(), in_array($code, [403, 404, 413, 415, 422], true) ? $code : 400);
} catch (Throwable $e) {
    Logger::error('Office chat API failed', ['action' => $action, 'err' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
    smErr('Something went wrong — try again. / फेरि प्रयास गर्नुहोस्।', 500);
}
