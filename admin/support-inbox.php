<?php
/**
 * admin/support-inbox.php — the Help Desk (24 Sep 2026, rebuilt 5 Oct 2026).
 *
 * One numbered queue for every customer problem: the Help page, the chat
 * complaint desk, call-me-back, the WhatsApp assistant and tickets staff
 * type in from a phone call or a walk-in. Each ticket has a short number
 * (HD-0051) and today's token (#7) — see includes/helpdesk.php.
 *
 * What the desk does here: see what is overdue, take or reassign a ticket,
 * answer with one tap (quick replies, WhatsApp or SMS), resolve. New tickets
 * are auto-assigned and escalated by cron/helpdesk.php when the SLA passes.
 *
 * support.view to read, support.reply to act (manager, support, counter,
 * official). A counter agent sees only tickets about bookings they sold, or
 * with no booking. Desk settings: superadmin / settings.manage.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin    = admin_boot('support.view');
$mayAct   = Auth::can('support.reply');
$mayAdmin = Auth::canManageSettings();
$scopeId  = Auth::bookingScopeAdminId();
$me       = (int) $admin['id'];
$flash    = null;

require_once INCLUDE_PATH . '/aihandoff.php';
require_once INCLUDE_PATH . '/helpdesk.php';
$migrated = AiHandoff::available(true);
$ready    = HelpDesk::ready(true);

/* ---- evidence: stream an inbound WhatsApp attachment kept for the desk ---- */
if ($migrated && isset($_GET['evidence'])) {
    $t = Database::fetch('SELECT t.id, t.evidence_path, t.booking_id, b.sold_by_admin_id FROM support_tickets t LEFT JOIN bookings b ON b.id = t.booking_id WHERE t.id = :id', ['id' => (int) $_GET['evidence']]);
    if ($t === null || (string) ($t['evidence_path'] ?? '') === ''
        || ($scopeId !== null && $t['booking_id'] !== null && (int) ($t['sold_by_admin_id'] ?? 0) !== $scopeId)) {
        http_response_code(404);
        exit('No such file.');
    }
    require_once INCLUDE_PATH . '/wamedia.php';
    try {
        $ev = ltrim(str_replace('\\', '/', (string) $t['evidence_path']), '/');
        if (preg_match('#^fraud/\d{4}/\d{2}/[A-Za-z0-9_.-]+$#', $ev) === 1 && strpos($ev, '..') === false) {
            // 9 Oct 2026: photo sent with a fake-ticket report (api/report-fake.php), stored plain
            if (!is_file(UPLOAD_PATH . '/' . $ev)) {
                throw new RuntimeException('Evidence file missing.');
            }
            $bytes = (string) file_get_contents(UPLOAD_PATH . '/' . $ev);
        } else {
            $bytes = WaMedia::openStash((string) $t['evidence_path']);
        }
    } catch (Throwable $e) {
        http_response_code(404);
        exit('The file is no longer available.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->buffer($bytes);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
        $mime = 'application/octet-stream';
    }
    Logger::audit('support.evidence_view', 'support_ticket', (string) $t['id'], null, null, 'opened by admin #' . $me);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="evidence-' . (int) $t['id'] . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

$staff   = HelpDesk::staff();
$staffBy = [];
foreach ($staff as $s) {
    $staffBy[$s['id']] = $s;
}

/* ---- actions ---- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif ($act === 'settings') {
        if (!$mayAdmin) {
            $flash = ['bad', 'Only the owner or a manager with settings access can change the desk rules.'];
        } else {
            foreach (['helpdesk_on', 'helpdesk_autoassign', 'helpdesk_ack', 'helpdesk_office_alert'] as $k) {
                Settings::set($k, isset($_POST[$k]) ? '1' : '0', 'bool', 'helpdesk');
            }
            foreach (['urgent', 'high', 'normal', 'low'] as $p) {
                $v = max(5, min(20160, (int) ($_POST['sla_' . $p] ?? 0)));
                Settings::set('helpdesk_sla_' . $p, (string) $v, 'int', 'helpdesk');
            }
            Settings::set('helpdesk_autoclose_days', (string) max(1, min(60, (int) ($_POST['autoclose'] ?? 3))), 'int', 'helpdesk');
            $canned = [];
            foreach (preg_split('/\R/', (string) ($_POST['canned'] ?? '')) ?: [] as $line) {
                $parts = explode('|', $line, 2);
                if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
                    $canned[] = ['t' => mb_substr(trim($parts[0]), 0, 40), 'b' => mb_substr(str_replace('\n', "\n", trim($parts[1])), 0, 900)];
                }
            }
            Settings::set('helpdesk_canned', $canned, 'json', 'helpdesk');
            Logger::audit('support.settings', 'settings', 'helpdesk', null, null, 'help desk rules saved by admin #' . $me);
            $flash = ['ok', 'Help desk rules saved.'];
        }
    } elseif (!$mayAct) {
        $flash = ['bad', 'Your role may read tickets but not act on them.'];
    } elseif ($act === 'new') {
        $r = HelpDesk::open([
            'name'       => (string) ($_POST['name'] ?? ''),
            'phone'      => (string) ($_POST['phone'] ?? ''),
            'country'    => (string) ($_POST['country'] ?? 'IN'),
            'category'   => (string) ($_POST['category'] ?? ''),
            'priority'   => (string) ($_POST['priority'] ?? ''),
            'message'    => (string) ($_POST['message'] ?? ''),
            'pnr'        => (string) ($_POST['pnr'] ?? ''),
            'source'     => 'desk',
            'trusted'    => true,
            'created_by' => $me,
            'ack'        => isset($_POST['ack']),
            'lang'       => 'ne',
        ]);
        if (!$r['ok']) {
            $flash = ['bad', ['phone' => 'Enter a 10-digit mobile number.', 'message' => 'Write what the problem is.'][$r['error'] ?? ''] ?? 'The ticket could not be saved.'];
        } else {
            if (($_POST['take'] ?? '') === '1') {
                Database::update('support_tickets', ['assigned_to' => $me, 'status' => 'in_progress'], 'id = :id', ['id' => (int) $r['id']]);
            }
            $flash = ['ok', ($r['duplicate'] ? 'Already open as ' : 'Ticket ') . $r['ref'] . ' · token #' . (int) $r['token']
                . (!empty($r['assigned']) ? ' · given to ' . $r['assigned'] : '')
                . (isset($_POST['ack']) ? ($r['ack'] ?? false ? ' · number sent to the customer (' . $r['ack_via'] . ')' : ' · number NOT sent — tell the customer yourself') : '')];
            $_GET['open'] = (string) $r['id'];
        }
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $t  = $id > 0 ? Database::fetch('SELECT t.*, b.sold_by_admin_id FROM support_tickets t LEFT JOIN bookings b ON b.id = t.booking_id WHERE t.id = :id', ['id' => $id]) : null;
        if ($t !== null && $scopeId !== null && $t['booking_id'] !== null && (int) ($t['sold_by_admin_id'] ?? 0) !== $scopeId) {
            $t = null;
        }
        try {
            if ($t === null) {
                throw new RuntimeException('Ticket not found.');
            }
            $ref = (string) $t['ticket_ref'];
            if ($act === 'status') {
                $st = (string) ($_POST['status'] ?? '');
                if (!in_array($st, ['open', 'in_progress', 'resolved', 'closed'], true)) {
                    throw new RuntimeException('Unknown status.');
                }
                Database::update('support_tickets', [
                    'status'      => $st,
                    'resolved_at' => in_array($st, ['resolved', 'closed'], true) ? date('Y-m-d H:i:s') : null,
                    'assigned_to' => $t['assigned_to'] ?: $me,
                ], 'id = :id', ['id' => $id]);
                Logger::audit('support.status', 'support_ticket', $ref, ['status' => $t['status']], ['status' => $st], 'by admin #' . $me);
                $flash = ['ok', $ref . ' marked ' . str_replace('_', ' ', $st) . '.'];
            } elseif ($act === 'take') {
                Database::update('support_tickets', ['assigned_to' => $me, 'status' => $t['status'] === 'open' ? 'in_progress' : $t['status']], 'id = :id', ['id' => $id]);
                Logger::audit('support.assign', 'support_ticket', $ref, ['to' => $t['assigned_to']], ['to' => $me], 'taken by admin #' . $me);
                $flash = ['ok', 'You have taken ' . $ref . '.'];
            } elseif ($act === 'assign') {
                $to = (int) ($_POST['to'] ?? 0);
                if ($to !== 0 && !isset($staffBy[$to])) {
                    throw new RuntimeException('That person cannot take help desk tickets.');
                }
                Database::update('support_tickets', ['assigned_to' => $to ?: null], 'id = :id', ['id' => $id]);
                Logger::audit('support.assign', 'support_ticket', $ref, ['to' => $t['assigned_to']], ['to' => $to ?: null], 'reassigned by admin #' . $me);
                $flash = ['ok', $ref . ($to ? ' given to ' . $staffBy[$to]['name'] : ' put back in the shared queue') . '.'];
            } elseif ($act === 'priority') {
                $p = (string) ($_POST['priority'] ?? '');
                if (!in_array($p, HelpDesk::PRIORITIES, true)) {
                    throw new RuntimeException('Unknown priority.');
                }
                $set = ['priority' => $p];
                if ($ready && !in_array((string) $t['status'], ['resolved', 'closed'], true)) {
                    $set['due_at'] = date('Y-m-d H:i:s', time() + HelpDesk::sla($p) * 60);
                }
                Database::update('support_tickets', $set, 'id = :id', ['id' => $id]);
                Logger::audit('support.priority', 'support_ticket', $ref, ['priority' => $t['priority']], ['priority' => $p], 'by admin #' . $me);
                $flash = ['ok', $ref . ' priority ' . $p . '.'];
            } elseif ($act === 'ack') {
                $a = HelpDesk::acknowledge($id);
                $flash = $a['ok'] ? ['ok', 'Number ' . $ref . ' sent to the customer (' . $a['via'] . ').']
                                  : ['bad', 'No provider accepted the message — call or WhatsApp the customer by hand.'];
            } elseif ($act === 'reply') {
                $note = trim(Security::clean((string) ($_POST['note'] ?? ''), 2000));
                if (mb_strlen($note) < 2) {
                    throw new RuntimeException('Write the reply first.');
                }
                // Send first, then record what it turned out to be: a reply the
                // person actually received (shown on their Help page and read back
                // by the assistant) or an INTERNAL note that never leaves this screen.
                $sent = null;
                $via  = '';
                $text = Settings::getString('company_name', APP_NAME) . ' — ' . $ref . "\n" . $note
                      . "\n\n" . HelpDesk::statusUrl($ref);
                if (($_POST['send_wa'] ?? '') === '1') {
                    require_once INCLUDE_PATH . '/notify.php';
                    $r = Notify::whatsapp((string) $t['phone'], $text, null, null, [], $t['booking_id'] ? (int) $t['booking_id'] : null,
                        ['purpose' => 'support_reply', 'admin_id' => $me]);
                    $sent = $r === true;
                    if (!$sent && is_file(ROOT_PATH . '/whatsapp/aisensy.php')) {
                        require_once ROOT_PATH . '/whatsapp/aisensy.php';
                        if (AiSensyAPI::enabled()) {
                            $sent = !empty(AiSensyAPI::sendText((string) $t['phone'], $text)['success']);
                        }
                    }
                    $via = $sent ? 'WhatsApp' : '';
                }
                if ($sent !== true && ($_POST['send_sms'] ?? '') === '1') {
                    require_once INCLUDE_PATH . '/notify.php';
                    $sent = Notify::sms((string) $t['phone'], mb_substr($ref . ': ' . $note, 0, 300), null, $t['booking_id'] ? (int) $t['booking_id'] : null);
                    $via  = $sent ? 'SMS' : $via;
                }
                $msgRow = [
                    'ticket_id' => $id, 'sender_type' => 'admin',
                    'sender_name' => mb_substr((string) ($admin['full_name'] ?? $admin['username'] ?? 'Office'), 0, 120),
                    'message' => $note,
                ];
                // "Show on their Help page" makes an unsent reply visible to the customer too.
                $public = $sent === true || ($_POST['public'] ?? '') === '1';
                try {
                    Database::insert('support_messages', $msgRow + ['is_internal' => $public ? 0 : 1]);
                } catch (Throwable $e) {
                    Database::insert('support_messages', $msgRow);       // column not migrated yet
                }
                $set = [];
                if ($t['status'] === 'open') {
                    $set['status'] = 'in_progress';
                }
                if (empty($t['assigned_to'])) {
                    $set['assigned_to'] = $me;
                }
                if ($ready && $public && empty($t['first_reply_at'])) {
                    $set['first_reply_at'] = date('Y-m-d H:i:s');
                }
                if (($_POST['and_resolve'] ?? '') === '1') {
                    $set['status'] = 'resolved';
                    $set['resolved_at'] = date('Y-m-d H:i:s');
                }
                if ($set !== []) {
                    Database::update('support_tickets', $set, 'id = :id', ['id' => $id]);
                }
                Logger::audit('support.reply', 'support_ticket', $ref, null, ['sent' => $sent, 'via' => $via, 'public' => $public], 'by admin #' . $me);
                $flash = ['ok', ($sent === true ? 'Reply sent by ' . $via . ' and saved.'
                    : ($sent === false ? 'No provider accepted it — saved' . ($public ? ' on their Help page' : ' as an internal note') . '; call or WhatsApp them by hand.'
                    : ($public ? 'Saved — the customer sees it on their Help page.' : 'Internal note saved (not shown to the customer).')))
                    . (($_POST['and_resolve'] ?? '') === '1' ? ' Ticket resolved.' : '')];
            } else {
                throw new RuntimeException('Unknown action.');
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- list ---- */
$view   = Security::clean($_GET['status'] ?? 'live', 20);
$source = Security::clean($_GET['source'] ?? '', 20);
$catF   = Security::clean($_GET['cat'] ?? '', 30);
$q      = trim(Security::clean($_GET['q'] ?? '', 60));
$where  = [];
$params = [];
switch ($view) {
    case 'live':        $where[] = "t.status IN ('open','in_progress')"; break;
    case 'mine':        $where[] = "t.status IN ('open','in_progress') AND t.assigned_to = :me"; $params['me'] = $me; break;
    case 'unassigned':  $where[] = "t.status IN ('open','in_progress') AND t.assigned_to IS NULL"; break;
    case 'overdue':     $where[] = $ready ? "t.status IN ('open','in_progress') AND t.due_at < NOW()" : '1=0'; break;
    case 'open': case 'in_progress': case 'resolved': case 'closed':
        $where[] = 't.status = :st'; $params['st'] = $view; break;
    default:            $view = 'all';
}
if ($migrated && in_array($source, ['whatsapp_ai', 'web', 'chat', 'callback', 'desk', 'admin'], true)) {
    $where[]       = 't.source = :src';
    $params['src'] = $source;
}
if ($catF !== '') {
    $where[]        = 't.category = :cat';
    $params['cat']  = $catF;
}
if ($q !== '') {
    $ref = HelpDesk::normRef($q);
    $digits = preg_replace('/\D/', '', $q) ?? '';
    $or = ['t.name LIKE :q1', 'b.pnr = :q2', 't.message LIKE :q3'];
    $params['q1'] = '%' . $q . '%';
    $params['q2'] = strtoupper($q);
    $params['q3'] = '%' . $q . '%';
    if ($ref !== null) {
        $or[] = 't.ticket_ref = :q4';
        $params['q4'] = $ref;
    }
    if (strlen($digits) >= 4) {
        $or[] = 't.phone LIKE :q5';
        $params['q5'] = '%' . $digits;
    }
    $where[] = '(' . implode(' OR ', $or) . ')';
}
if ($scopeId !== null) {
    $where[]         = '(t.booking_id IS NULL OR b.sold_by_admin_id = :scope)';
    $params['scope'] = $scopeId;
}
$rows = [];
try {
    $rows = Database::fetchAll(
        'SELECT t.*, b.pnr, a.full_name AS assignee
           FROM support_tickets t
           LEFT JOIN bookings b ON b.id = t.booking_id
           LEFT JOIN admins a ON a.id = t.assigned_to'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ($ready
            ? " ORDER BY t.status IN ('resolved','closed'), FIELD(t.priority,'urgent','high','normal','low'), t.due_at IS NULL, t.due_at, t.id DESC LIMIT 300"
            : " ORDER BY FIELD(t.priority,'urgent','high','normal','low'), t.id DESC LIMIT 300"),
        $params
    );
} catch (Throwable $e) {
    $rows = [];
}
$st   = HelpDesk::stats($scopeId);
$csrf = '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . Security::csrfToken() . '">';
$open = isset($_GET['open']) ? (int) $_GET['open'] : 0;
$thread = [];
if ($open > 0) {
    try {
        try {
            $thread = Database::fetchAll('SELECT sender_type, sender_name, message, created_at, is_internal FROM support_messages WHERE ticket_id = :t ORDER BY id', ['t' => $open]);
        } catch (Throwable $e) {
            $thread = Database::fetchAll('SELECT sender_type, sender_name, message, created_at, 0 AS is_internal FROM support_messages WHERE ticket_id = :t ORDER BY id', ['t' => $open]);
        }
    } catch (Throwable $ignored) {
    }
}
$canned = HelpDesk::canned();

$prio = static function (string $p): string {
    $map = ['urgent' => 'var(--bad)', 'high' => 'var(--warn)', 'normal' => 'var(--ink)', 'low' => 'var(--mut)'];
    return '<b style="color:' . ($map[$p] ?? 'var(--ink)') . '">' . Security::e($p) . '</b>';
};
$slaText = static function (array $r): string {
    if (in_array((string) $r['status'], ['resolved', 'closed'], true) || empty($r['due_at'])) {
        return '<span class="hd-mut">—</span>';
    }
    $left = strtotime((string) $r['due_at']) - time();
    $fmt  = static function (int $s): string {
        $s = abs($s);
        return $s >= 86400 ? floor($s / 86400) . 'd ' . floor(($s % 86400) / 3600) . 'h'
             : ($s >= 3600 ? floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm' : max(1, (int) floor($s / 60)) . 'm');
    };
    return $left < 0
        ? '<b class="hd-late">overdue ' . $fmt($left) . '</b>'
        : '<span class="' . ($left < 3600 ? 'hd-soon' : 'hd-ok') . '">due in ' . $fmt($left) . '</span>';
};
$srcLabel = ['whatsapp_ai' => 'WhatsApp assistant', 'web' => 'Help page', 'chat' => 'Chat', 'callback' => 'Call me back', 'desk' => 'Desk / phone', 'admin' => 'Office'];
$keep = static function (array $over = []) use ($view, $source, $catF, $q): string {
    $p = array_filter(['status' => $view, 'source' => $source, 'cat' => $catF, 'q' => $q] + [], static fn ($v) => $v !== '');
    return '?' . http_build_query(array_merge($p, $over));
};

admin_header('Help Desk', 'support-inbox');
?>
<style>
  .hd-note{font-size:13px;color:var(--mut);margin:0 0 14px;line-height:1.5}
  .hd-filters{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 10px;align-items:center}
  .hd-filters a{padding:6px 11px;border:1px solid var(--line);border-radius:999px;font-size:13px;text-decoration:none;color:var(--ink);background:var(--card)}
  .hd-filters a.on{background:var(--ink);color:var(--card);border-color:var(--ink)}
  .hd-filters a .n{opacity:.7;margin-left:4px}
  .hd-search{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}
  .hd-search input,.hd-search select{padding:8px 10px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font:inherit;min-height:38px}
  .hd-search input[type=search]{flex:1;min-width:180px}
  .hd-inline{display:inline} .hd-inline .btn{padding:5px 9px;font-size:12px;min-height:32px}
  .hd-inline select{padding:4px 6px;border:1px solid var(--line);border-radius:7px;background:var(--card);color:var(--ink);font:inherit;font-size:12px;max-width:150px}
  .hd-msg{white-space:pre-wrap;max-width:440px;font-size:13.5px;line-height:1.45}
  .hd-ref{font-size:15px;letter-spacing:.3px} .hd-tok{display:inline-block;margin-left:4px;padding:1px 7px;border-radius:999px;background:var(--ink);color:var(--card);font-size:11px;font-weight:700}
  .hd-late{color:var(--bad)} .hd-soon{color:var(--warn);font-weight:600} .hd-ok{color:var(--ok)} .hd-mut{color:var(--mut)}
  .hd-esc{display:inline-block;margin-top:3px;font-size:11px;color:var(--bad);border:1px solid var(--bad);border-radius:6px;padding:0 5px}
  .hd-thread{border:1px solid var(--line);border-radius:12px;padding:10px 14px;margin:8px 0}
  .hd-thread .adm{color:var(--ok)} .hd-thread .cus{color:var(--ink)}
  .hd-reply textarea,.hd-new textarea{width:100%;min-height:80px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font:inherit}
  .hd-quick{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0}
  .hd-quick button{padding:5px 10px;border:1px dashed var(--line);border-radius:999px;background:var(--card);color:var(--ink);font:inherit;font-size:12px;cursor:pointer}
  .hd-opts{display:flex;gap:14px;flex-wrap:wrap;margin:8px 0;font-size:13px}
  .hd-opts label{display:inline-flex;gap:6px;align-items:center}
  .hd-new{border:1px solid var(--line);border-radius:14px;padding:4px 14px;margin:0 0 14px;background:var(--card)}
  .hd-new summary{cursor:pointer;font-weight:700;padding:10px 0;list-style:none} .hd-new summary::-webkit-details-marker{display:none}
  .hd-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:10px;margin:6px 0 10px}
  .hd-grid label{display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--mut)}
  .hd-grid input,.hd-grid select{padding:8px 10px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font:inherit;min-height:40px}
  .hd-cats{display:flex;gap:6px;flex-wrap:wrap;margin:4px 0 12px}
  .hd-cats a{font-size:12px;padding:3px 9px;border-radius:999px;border:1px solid var(--line);text-decoration:none;color:var(--ink)}
  .hd-cats a.on{background:var(--ink);color:var(--card)}
</style>

<?php if ($flash !== null): ?>
  <p style="display:block;padding:10px 12px;border-radius:10px;background:<?= $flash[0] === 'ok' ? 'var(--ok-bg)' : 'var(--bad-bg)' ?>;color:<?= $flash[0] === 'ok' ? 'var(--ok)' : 'var(--bad)' ?>"><?= Security::e($flash[1]) ?></p>
<?php endif; ?>

<p class="hd-note">Every customer problem in one numbered queue — the Help page (<a href="/help.php" target="_blank" rel="noopener">/help.php</a>),
  the chat complaint desk, “call me back”, the WhatsApp assistant and tickets you type in here. Each ticket gets a number the customer can say on the phone
  (<b>HD-0051</b>) and today’s token (<b>#7</b>). New tickets go to the least-busy person automatically; anything not answered before its due time is escalated once and the office WhatsApp is alerted.
  <?php if (!$ready): ?><br><b style="color:var(--bad)">Help desk columns are not installed — apply database/upgrade-2026-10-05-helpdesk.sql (numbers still work, SLA/tokens/auto-assign wait for it).</b><?php endif; ?></p>

<div class="kpis">
  <div class="kpi tone-orange"><span class="ki"><svg class="a-ic"><use href="#a-alert"/></svg></span><div class="kt"><div class="kk">Open</div><div class="kv"><?= (int) $st['open'] ?></div><div class="ks"><?= (int) $st['unassigned'] ?> not taken yet</div></div></div>
  <div class="kpi tone-navy"><span class="ki"><svg class="a-ic"><use href="#a-msg"/></svg></span><div class="kt"><div class="kk">In progress</div><div class="kv"><?= (int) $st['in_progress'] ?></div><div class="ks">being worked on</div></div></div>
  <div class="kpi tone-red"><span class="ki"><svg class="a-ic"><use href="#a-clock"/></svg></span><div class="kt"><div class="kk">Overdue</div><div class="kv"><?= (int) $st['overdue'] ?></div><div class="ks">past the due time</div></div></div>
  <div class="kpi tone-green"><span class="ki"><svg class="a-ic"><use href="#a-check-circle"/></svg></span><div class="kt"><div class="kk">Today</div><div class="kv"><?= (int) $st['today'] ?> new</div><div class="ks"><?= (int) $st['resolved_today'] ?> resolved<?= $st['first_reply_min'] > 0 ? ' · first reply ~' . (int) $st['first_reply_min'] . ' min (7 d)' : '' ?></div></div></div>
</div>

<?php if ($mayAct): ?>
<details class="hd-new" <?= isset($_GET['new']) ? 'open' : '' ?>>
  <summary>＋ New ticket — phone call / walk-in</summary>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="new">
    <div class="hd-grid">
      <label>Mobile *<input name="phone" inputmode="tel" required placeholder="98XXXXXXXX" maxlength="16"></label>
      <label>Country<select name="country"><option value="IN">India +91</option><option value="NP">Nepal +977</option></select></label>
      <label>Name<input name="name" maxlength="120" placeholder="Customer name"></label>
      <label>PNR (optional)<input name="pnr" maxlength="24" placeholder="SHG-2026-00540" style="text-transform:uppercase"></label>
      <label>Topic<select name="category"><option value="">Auto (from the words)</option>
        <?php foreach (HelpDesk::CATEGORIES as $k => $c): ?><option value="<?= $k ?>"><?= Security::e($c[0] . ' · ' . $c[1]) ?></option><?php endforeach; ?></select></label>
      <label>Priority<select name="priority"><option value="">Auto</option><option value="urgent">Urgent</option><option value="high">High</option><option value="normal">Normal</option><option value="low">Low</option></select></label>
    </div>
    <textarea name="message" required placeholder="What is the problem? (customer’s words)"></textarea>
    <div class="hd-opts">
      <label><input type="checkbox" name="ack" value="1" checked> send the ticket number to the customer</label>
      <label><input type="checkbox" name="take" value="1"> I will handle it myself</label>
    </div>
    <p><button class="btn" type="submit">Create ticket &amp; give number</button></p>
  </form>
</details>
<?php endif; ?>

<div class="hd-filters">
  <?php foreach (['live' => ['Live', $st['open'] + $st['in_progress']], 'mine' => ['Mine', null], 'unassigned' => ['Not taken', $st['unassigned']], 'overdue' => ['Overdue', $st['overdue']],
                  'open' => ['Open', null], 'in_progress' => ['In progress', null], 'resolved' => ['Resolved', null], 'closed' => ['Closed', null], 'all' => ['All', null]] as $k => [$lbl, $n]): ?>
    <a class="<?= $view === $k ? 'on' : '' ?>" href="<?= Security::e($keep(['status' => $k])) ?>"><?= $lbl ?><?= $n !== null ? '<span class="n">' . (int) $n . '</span>' : '' ?></a>
  <?php endforeach; ?>
</div>
<form class="hd-search" method="get">
  <input type="hidden" name="status" value="<?= Security::e($view) ?>">
  <input type="search" name="q" value="<?= Security::e($q) ?>" placeholder="Number (HD-51), mobile, name or PNR">
  <select name="source"><option value="">Every door</option>
    <?php foreach ($srcLabel as $k => $lbl): ?><option value="<?= $k ?>" <?= $source === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?></select>
  <select name="cat"><option value="">Every topic</option>
    <?php foreach (HelpDesk::CATEGORIES + array_map(static fn ($c) => [$c[0], '', ''], AiHandoff::CATEGORIES) as $k => $c): ?><option value="<?= $k ?>" <?= $catF === $k ? 'selected' : '' ?>><?= Security::e($c[0]) ?></option><?php endforeach; ?></select>
  <button class="btn ghost" type="submit">Find</button>
</form>

<div class="panel">
  <h2>Tickets (<?= count($rows) ?>)</h2>
  <?php if ($rows === []): ?>
    <p class="hd-note">Nothing here. 🎉</p>
  <?php else: ?>
  <div class="dt-wrap">
    <table class="dt card-table">
      <thead><tr><th>Number</th><th>When</th><th>Priority</th><th>Topic</th><th>Who</th><th>Problem</th><th>Due</th><th>Status</th><th>Handled by</th><th data-nosort>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $rid = (int) $r['id']; $phoneIntl = preg_replace('/\D/', '', strlen((string) $r['phone']) === 10 ? '91' . $r['phone'] : (string) $r['phone']) ?? ''; ?>
        <tr>
          <td data-label="Number"><b class="hd-ref"><?= Security::e((string) $r['ticket_ref']) ?></b><?php if (!empty($r['day_token'])): ?><span class="hd-tok" title="Token of the day">#<?= (int) $r['day_token'] ?></span><?php endif; ?>
            <?php if ((string) ($r['pnr'] ?? '') !== ''): ?><br><a href="/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= Security::e((string) $r['pnr']) ?></a><?php endif; ?></td>
          <td data-label="When" data-sort="<?= Security::e((string) $r['created_at']) ?>"><?= Security::e(substr((string) $r['created_at'], 5, 11)) ?><br><small><?= Security::e($srcLabel[(string) ($r['source'] ?? 'web')] ?? (string) ($r['source'] ?? 'web')) ?></small></td>
          <td data-label="Priority"><?= $prio((string) $r['priority']) ?><?= !empty($r['escalated_at']) ? '<br><span class="hd-esc">escalated</span>' : '' ?></td>
          <td data-label="Topic"><?= Security::e(HelpDesk::label((string) ($r['category'] ?? 'other'))) ?></td>
          <td data-label="Who"><?= Security::e((string) $r['name']) ?><br>
            <a href="tel:+<?= Security::e($phoneIntl) ?>">📞</a> <a href="https://wa.me/<?= Security::e($phoneIntl) ?>" target="_blank" rel="noopener">+<?= Security::e($phoneIntl) ?></a>
            <?php if ($ready && !empty($r['ack_sent_at'])): ?><br><small class="hd-ok">number sent</small><?php endif; ?></td>
          <td data-label="Problem" class="wrap"><div class="hd-msg"><?= Security::e(mb_strimwidth((string) $r['message'], 0, 260, '…')) ?></div>
            <?php if ((string) ($r['evidence_path'] ?? '') !== ''): ?><a href="?evidence=<?= $rid ?>" target="_blank" rel="noopener">📎 attachment</a><?php endif; ?></td>
          <td data-label="Due" data-sort="<?= Security::e((string) ($r['due_at'] ?? '')) ?>"><?= $slaText($r) ?></td>
          <td data-label="Status"><?= Security::e(str_replace('_', ' ', (string) $r['status'])) ?></td>
          <td data-label="Handled by">
            <?php if ($mayAct && $staff !== []): ?>
              <form method="post" class="hd-inline"><?= $csrf ?><input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="<?= $rid ?>">
                <select name="to" onchange="this.form.submit()" aria-label="Give to">
                  <option value="0">— shared queue —</option>
                  <?php foreach ($staff as $s): ?><option value="<?= $s['id'] ?>" <?= (int) ($r['assigned_to'] ?? 0) === $s['id'] ? 'selected' : '' ?>><?= Security::e($s['name'] . ($s['desk'] !== '' ? ' · ' . $s['desk'] : '') . ' (' . $s['open'] . ')') ?></option><?php endforeach; ?>
                  <?php if (!empty($r['assigned_to']) && !isset($staffBy[(int) $r['assigned_to']])): ?><option value="<?= (int) $r['assigned_to'] ?>" selected><?= Security::e((string) ($r['assignee'] ?? '#' . $r['assigned_to'])) ?></option><?php endif; ?>
                </select></form>
            <?php else: ?><?= Security::e((string) ($r['assignee'] ?? '—')) ?><?php endif; ?>
          </td>
          <td data-label="Actions">
            <div class="dt-acts">
              <a class="btn ghost" href="<?= Security::e($keep(['open' => $rid])) ?>#t<?= $rid ?>">Open</a>
              <?php if ($mayAct): ?>
                <?php if ((int) ($r['assigned_to'] ?? 0) !== $me && !in_array((string) $r['status'], ['resolved', 'closed'], true)): ?><form method="post" class="hd-inline"><?= $csrf ?><input type="hidden" name="action" value="take"><input type="hidden" name="id" value="<?= $rid ?>"><button class="btn" type="submit">Take</button></form><?php endif; ?>
                <?php if (!in_array((string) $r['status'], ['resolved', 'closed'], true)): ?>
                  <form method="post" class="hd-inline"><?= $csrf ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="status" value="resolved"><button class="btn ghost" type="submit">Resolve</button></form>
                <?php else: ?>
                  <form method="post" class="hd-inline"><?= $csrf ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="status" value="open"><button class="btn ghost" type="submit">Reopen</button></form>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php if ($open === $rid): ?>
        <tr id="t<?= $rid ?>"><td colspan="10">
          <p class="hd-note" style="margin:6px 0">Full problem: </p><div class="hd-msg" style="max-width:none"><?= Security::e((string) $r['message']) ?></div>
          <?php foreach ($thread as $m): ?>
            <div class="hd-thread"><b class="<?= $m['sender_type'] === 'admin' ? 'adm' : 'cus' ?>"><?= Security::e((string) ($m['sender_name'] ?? $m['sender_type'])) ?></b>
              <small style="color:var(--mut)"> · <?= Security::e(substr((string) $m['created_at'], 0, 16)) ?><?= $m['sender_type'] === 'admin' ? ((int) ($m['is_internal'] ?? 0) === 1 ? ' · internal note' : ' · customer sees it') : '' ?></small>
              <div class="hd-msg"><?= Security::e((string) $m['message']) ?></div></div>
          <?php endforeach; ?>
          <?php if ($mayAct): ?>
          <form method="post" class="hd-reply"><?= $csrf ?>
            <input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?= $rid ?>">
            <div class="hd-quick" aria-label="Quick replies">
              <?php
                /* {track} → THIS ticket's keyed tracker (8 Oct 2026, D7-12), or the
                   My-ticket page when the help-desk ticket carries no PNR. */
                if (!class_exists('Ticket')) { require_once INCLUDE_PATH . '/ticket.php'; }
                $trackLink = (string) ($r['pnr'] ?? '') !== '' ? Ticket::trackUrl((string) $r['pnr']) : appUrl('/#/my');
              ?>
              <?php foreach ($canned as $c): ?><button type="button" data-body="<?= Security::e(str_replace('{track}', $trackLink, $c['b'])) ?>"><?= Security::e($c['t']) ?></button><?php endforeach; ?>
            </div>
            <textarea name="note" required placeholder="Reply to the customer, or an internal note…"></textarea>
            <div class="hd-opts">
              <label><input type="checkbox" name="send_wa" value="1" checked> WhatsApp</label>
              <label><input type="checkbox" name="send_sms" value="1"> SMS if WhatsApp fails</label>
              <label><input type="checkbox" name="public" value="1" checked> show on their Help page</label>
            </div>
            <p style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn" type="submit">Send reply</button>
              <button class="btn ghost" type="submit" name="and_resolve" value="1">Send &amp; resolve</button>
            </p>
          </form>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
            <form method="post" class="hd-inline"><?= $csrf ?><input type="hidden" name="action" value="priority"><input type="hidden" name="id" value="<?= $rid ?>">
              <select name="priority" onchange="this.form.submit()" aria-label="Priority"><?php foreach (array_reverse(HelpDesk::PRIORITIES) as $p): ?><option value="<?= $p ?>" <?= $r['priority'] === $p ? 'selected' : '' ?>>priority: <?= $p ?></option><?php endforeach; ?></select></form>
            <form method="post" class="hd-inline"><?= $csrf ?><input type="hidden" name="action" value="ack"><input type="hidden" name="id" value="<?= $rid ?>"><button class="btn ghost" type="submit">Send number again</button></form>
            <a class="btn ghost" href="<?= Security::e(HelpDesk::statusUrl((string) $r['ticket_ref'])) ?>" target="_blank" rel="noopener">Customer’s view</a>
          </div>
          <?php endif; ?>
        </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($mayAdmin): $cannedText = implode("\n", array_map(static fn (array $c): string => $c['t'] . ' | ' . str_replace("\n", '\n', $c['b']), $canned)); ?>
<details class="hd-new">
  <summary>⚙ Desk rules — automation, due times, quick replies</summary>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="settings">
    <div class="hd-opts">
      <label><input type="checkbox" name="helpdesk_on" value="1" <?= HelpDesk::on() ? 'checked' : '' ?>> customers can open tickets (Help page, chat)</label>
      <label><input type="checkbox" name="helpdesk_autoassign" value="1" <?= Settings::getBool('helpdesk_autoassign', true) ? 'checked' : '' ?>> auto-assign to the least-busy person</label>
      <label><input type="checkbox" name="helpdesk_ack" value="1" <?= Settings::getBool('helpdesk_ack', true) ? 'checked' : '' ?>> send the number to the customer</label>
      <label><input type="checkbox" name="helpdesk_office_alert" value="1" <?= Settings::getBool('helpdesk_office_alert', true) ? 'checked' : '' ?>> office WhatsApp alert (urgent / high / overdue)</label>
    </div>
    <div class="hd-grid">
      <?php foreach (['urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'] as $p => $lbl): ?>
        <label>Answer “<?= $lbl ?>” within (minutes)<input type="number" min="5" max="20160" name="sla_<?= $p ?>" value="<?= HelpDesk::sla($p) ?>"></label>
      <?php endforeach; ?>
      <label>Close resolved tickets after (days)<input type="number" min="1" max="60" name="autoclose" value="<?= max(1, Settings::getInt('helpdesk_autoclose_days', 3)) ?>"></label>
    </div>
    <label class="hd-note" style="display:block">Quick replies — one per line: <code>Button text | message</code> (write <code>\n</code> for a new line)</label>
    <textarea name="canned" style="width:100%;min-height:150px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font:inherit"><?= Security::e($cannedText) ?></textarea>
    <p><button class="btn" type="submit">Save desk rules</button></p>
  </form>
</details>
<?php endif; ?>

<script>
document.querySelectorAll('.hd-quick button[data-body]').forEach(function (b) {
  b.addEventListener('click', function () {
    var ta = b.closest('form').querySelector('textarea[name=note]');
    ta.value = (ta.value ? ta.value + '\n' : '') + b.getAttribute('data-body');
    ta.focus();
  });
});
</script>
<?php admin_footer(); ?>
