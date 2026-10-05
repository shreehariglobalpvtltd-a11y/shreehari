<?php
/**
 * admin/wa-manager.php — WhatsApp Manager (5 Oct 2026).
 *
 * Owner: "a WhatsApp manager in the admin panel — run on our VPS, live,
 * where each message went; at least who got which message, what the
 * words were, all data A to Z, and how much it cost."
 *
 * One screen over includes/msgledger.php:
 *   - the AiSensy account as the API reports it (wallet, plan, quality,
 *     daily limit) next to the Meta number, SMS and email;
 *   - totals for a period: sent, reached, read, failed, waiting, people,
 *     estimated cost and what the AiSensy wallet really paid;
 *   - by purpose, by country, failure reasons in plain words, 14 days;
 *   - the live feed: every message, newest first, updating every few
 *     seconds while the page is open (?feed=1 is the JSON behind it);
 *   - CSV of the period (?export=csv), the rate card, the template list.
 *
 * reports.view: the office roles that already see money. Nothing here
 * sends a message.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/msgledger.php';
require_once ROOT_PATH . '/whatsapp/aisensy.php';

$admin   = admin_boot('reports.view');
$canEdit = Auth::canManageSettings();
$e       = static fn($v): string => Security::e((string) $v);
$self    = '/admin/wa-manager.php';
$ready   = MsgLedger::ready();

/* ---------- period ---------- */
$periods = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => '7 days', '30d' => '30 days', 'month' => 'This month'];
$per = (string) ($_GET['p'] ?? 'today');
$per = isset($periods[$per]) ? $per : 'today';
$today = date('Y-m-d');
[$from, $to] = match ($per) {
    'yesterday' => [date('Y-m-d', strtotime('-1 day')) . ' 00:00:00', $today . ' 00:00:00'],
    '7d'        => [date('Y-m-d', strtotime('-6 day')) . ' 00:00:00', date('Y-m-d', strtotime('+1 day')) . ' 00:00:00'],
    '30d'       => [date('Y-m-d', strtotime('-29 day')) . ' 00:00:00', date('Y-m-d', strtotime('+1 day')) . ' 00:00:00'],
    'month'     => [date('Y-m-01') . ' 00:00:00', date('Y-m-d', strtotime('+1 day')) . ' 00:00:00'],
    default     => [$today . ' 00:00:00', date('Y-m-d', strtotime('+1 day')) . ' 00:00:00'],
};

$filters = [
    'q'       => Security::clean($_GET['q'] ?? '', 60),
    'channel' => in_array($_GET['ch'] ?? '', ['whatsapp', 'sms', 'email'], true) ? (string) $_GET['ch'] : '',
    'status'  => in_array($_GET['st'] ?? '', ['delivered', 'read', 'failed', 'waiting', 'skipped'], true) ? (string) $_GET['st'] : '',
    'country' => in_array($_GET['cc'] ?? '', ['IN', 'NP', 'OT'], true) ? (string) $_GET['cc'] : '',
];

/** Light sync: copy new message_logs rows at most every 15 s (the cron does the rest). */
function wm_light_sync(): void
{
    $last = (int) MsgLedger::kvGet('light_at', '0');
    if ($last > time() - 15) {
        return;
    }
    MsgLedger::kvSet('light_at', (string) time());
    try {
        MsgLedger::sync(300);
    } catch (Throwable $ex) {
        Logger::warning('wa-manager light sync failed: ' . $ex->getMessage(), [], 'whatsapp');
    }
}

/** One feed row as the browser draws it. */
function wm_row(array $r): array
{
    [$code, $why] = $r['status'] === 'failed' ? MsgLedger::explain((string) $r['error']) : ['', ''];
    $ts = strtotime((string) $r['created_at']) ?: time();
    return [
        'id'      => (int) $r['id'],
        't'       => date('Y-m-d', $ts) === date('Y-m-d') ? date('H:i:s', $ts) : date('d M H:i', $ts),
        'ch'      => (string) $r['channel'],
        'prov'    => (string) $r['provider'],
        'to'      => (string) $r['to_number'],
        'cc'      => (string) $r['country'],
        'who'     => (string) ($r['who_name'] ?? ''),
        'kind'    => (string) ($r['who_kind'] ?? ''),
        'pnr'     => (string) ($r['pnr'] ?? ''),
        'what'    => (string) ($r['purpose'] !== null && $r['purpose'] !== '' ? $r['purpose'] : $r['label']),
        'label'   => (string) $r['label'],
        'cat'     => (string) $r['category'],
        'body'    => mb_strimwidth((string) ($r['body'] ?? ''), 0, 220, '…'),
        'media'   => (string) ($r['media_url'] ?? ''),
        'st'      => (string) $r['status'],
        'why'     => $why,
        'code'    => $code,
        'cost'    => (float) $r['cost'],
        'by'      => (string) ($r['by_name'] ?? ''),
    ];
}

/* ---------- JSON feed (live) ---------- */
if (isset($_GET['feed'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$ready) {
        echo json_encode(['ok' => false, 'error' => 'ledger not installed']);
        exit;
    }
    wm_light_sync();
    $since  = max(0, (int) ($_GET['since'] ?? 0));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $rows   = MsgLedger::feed($filters + ($offset > 0 ? ['offset' => $offset] : ['since' => $since]) + ['from' => $from, 'to' => $to], $offset > 0 ? 60 : 80);
    /* Rows already on screen whose state moved (delivered / read / failed). */
    $chg = [];
    $after = (string) ($_GET['chg'] ?? '');
    if (preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $after)) {
        foreach (Database::fetchAll(
            'SELECT id, status, error, cost FROM msg_ledger WHERE status_at >= :a AND created_at >= :f ORDER BY id DESC LIMIT 200',
            ['a' => $after, 'f' => $from]
        ) as $c) {
            [, $why] = $c['status'] === 'failed' ? MsgLedger::explain((string) $c['error']) : ['', ''];
            $chg[] = ['id' => (int) $c['id'], 'st' => (string) $c['status'], 'why' => $why, 'cost' => (float) $c['cost']];
        }
    }
    echo json_encode([
        'ok'     => true,
        'now'    => date('Y-m-d H:i:s'),
        'rows'   => array_map('wm_row', $rows),
        'chg'    => $chg,
        'totals' => MsgLedger::totals($from, $to),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- CSV of the period ---------- */
if (($_GET['export'] ?? '') === 'csv' && $ready) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="messages-' . substr($from, 0, 10) . '-' . $per . '.csv"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['Time', 'Channel', 'Provider', 'To', 'Country', 'Name', 'Who', 'PNR', 'Purpose', 'Template / type', 'Category', 'Message', 'Status', 'Why failed', 'Cost INR', 'Sent by']);
    $offset = 0;
    do {
        $rows = MsgLedger::feed($filters + ['from' => $from, 'to' => $to, 'offset' => $offset], 500);
        $offset += 500;
        foreach ($rows as $r) {
            $w = wm_row($r);
            fputcsv($fh, [(string) $r['created_at'], $w['ch'], $w['prov'], '+' . $w['to'], $w['cc'], $w['who'], $w['kind'], $w['pnr'],
                $w['what'], $w['label'], $w['cat'], (string) ($r['body'] ?? ''), $w['st'], $w['why'], number_format($w['cost'], 3, '.', ''), $w['by']]);
        }
    } while (count($rows) === 500);
    fclose($fh);
    exit;
}

/* ---------- actions ---------- */
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $ready) {
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('Session expired — please try again. / फेरि प्रयास गर्नुहोस्।');
        }
        $act = (string) ($_POST['action'] ?? '');
        if ($act === 'check') {
            MsgLedger::backfillAiSensyLog();
            $s = MsgLedger::sync(1000);
            $p = MsgLedger::poll(25);
            MsgLedger::snapCredit(true);
            MsgLedger::refreshTemplates(true);
            MsgLedger::price();
            $flash = ['ok', 'Checked now: ' . $s['copied'] . ' new, ' . $p['checked'] . ' asked AiSensy (' . ($p['delivered'] + $p['read']) . ' delivered/read, ' . $p['failed'] . ' failed).'];
        } elseif ($act === 'rates') {
            if (!$canEdit) {
                throw new RuntimeException('Changing the rate card needs the Settings permission.');
            }
            $n = 0;
            foreach (['in', 'np', 'ot'] as $cc) {
                foreach (['utility', 'marketing', 'authentication'] as $cat) {
                    $k = 'wam_rate_' . $cc . '_' . $cat;
                    if (isset($_POST[$k]) && is_numeric($_POST[$k])) {
                        Settings::set($k, (string) max(0, min(100, (float) $_POST[$k])), 'float', 'whatsapp');
                        $n++;
                    }
                }
            }
            if (isset($_POST['wam_rate_sms']) && is_numeric($_POST['wam_rate_sms'])) {
                Settings::set('wam_rate_sms', (string) max(0, min(100, (float) $_POST['wam_rate_sms'])), 'float', 'whatsapp');
                $n++;
            }
            MsgLedger::price();
            Logger::audit('wam.rates', 'settings', 'whatsapp', null, ['n' => $n], 'WhatsApp rate card saved by admin #' . (int) $admin['id']);
            $flash = ['ok', 'Rate card saved — costs recalculated. / रेट सेभ भयो।'];
        }
    } catch (Throwable $ex) {
        $flash = ['bad', $ex->getMessage()];
    }
}

/* ---------- page data ---------- */
if ($ready) {
    wm_light_sync();
}
$tot      = $ready ? MsgLedger::totals($from, $to) : [];
$byPur    = $ready ? MsgLedger::breakdown($from, $to, 'purpose') : [];
$byCc     = $ready ? MsgLedger::breakdown($from, $to, 'country') : [];
$byProv   = $ready ? MsgLedger::breakdown($from, $to, 'provider') : [];
$why      = $ready ? MsgLedger::failReasons($from, $to) : [];
$days     = $ready ? MsgLedger::daily(14) : [];
$feed     = $ready ? MsgLedger::feed($filters + ['from' => $from, 'to' => $to], 60) : [];
$wallet   = $ready ? MsgLedger::walletSpend($from, $to) : ['spent' => 0, 'recharged' => 0];
$proj     = $ready ? MsgLedger::kvJson('project') : [];
if ($ready && ($proj === [] || strtotime((string) ($proj['at'] ?? '')) < time() - 900)) {
    $proj = MsgLedger::snapCredit() ?: $proj;
}
$tpls     = $ready ? (array) (MsgLedger::kvJson('templates')['rows'] ?? []) : [];
$credit7  = $ready ? Database::fetchAll('SELECT DATE(at) d, MIN(credit) lo, MAX(credit) hi FROM wam_credit WHERE at >= CURDATE() - INTERVAL 13 DAY GROUP BY d ORDER BY d') : [];

/* The other senders, last 24 h, straight from the ledger. */
$health = [];
if ($ready) {
    foreach (Database::fetchAll(
        "SELECT provider, channel, COUNT(*) n, SUM(status IN ('delivered','read','sent','accepted')) ok, SUM(status = 'failed') bad,
                MAX(created_at) last_at
           FROM msg_ledger WHERE created_at > NOW() - INTERVAL 1 DAY GROUP BY provider, channel"
    ) as $h) {
        $health[(string) $h['provider'] . '|' . (string) $h['channel']] = $h;
    }
}
$topErr = static function (string $prov) use ($ready): string {
    if (!$ready) {
        return '';
    }
    $err = (string) Database::scalar(
        "SELECT error FROM msg_ledger WHERE provider = :p AND status = 'failed' AND created_at > NOW() - INTERVAL 1 DAY
          GROUP BY error ORDER BY COUNT(*) DESC LIMIT 1", ['p' => $prov], '');
    return $err !== '' ? MsgLedger::explain($err)[1] : '';
};
$lastStatusAt = date('Y-m-d H:i:s');
$maxId = $feed !== [] ? max(array_map(static fn($r) => (int) $r['id'], $feed)) : 0;
$money = static fn($v): string => '₹' . number_format((float) $v, 2);
$pct = static fn($a, $b): string => (int) $b > 0 ? (string) round(100 * (int) $a / (int) $b) . '%' : '—';
$qs = static function (array $over) use ($per, $filters): string {
    $q = array_filter(['p' => $per, 'q' => $filters['q'], 'ch' => $filters['channel'], 'st' => $filters['status'], 'cc' => $filters['country']] + [], 'strlen');
    $q = array_merge($q, $over);
    return '?' . http_build_query(array_filter($q, static fn($v) => $v !== '' && $v !== null));
};
$purLabels = [
    'aisensy_ticket' => 'Ticket (AiSensy)', 'ticket' => 'Ticket', 'ticket_resend' => 'Ticket re-sent', 'seller_copy' => 'Seller / counter copy',
    'bot_reply' => 'Bot reply', 'wa_fail_alert' => 'Failure alert to office', 'office_report' => 'Office report',
    'booking_cancelled' => 'Cancellation', 'agent_statement' => 'Agent statement', 'chalan_send' => 'Chalani', 'sms' => 'SMS', 'email' => 'Email',
    'manual_message' => 'Typed by staff', 'shg_ticket_image' => 'Ticket PNG (template)', 'shg_ticket_confirmed' => 'Ticket (template)', 'text' => 'Text reply', 'image' => 'Image',
];

admin_header('WhatsApp Manager', 'wa-manager');
admin_page_head('Every WhatsApp, SMS and email the system sent — who got it, what it said, whether it arrived, and what it cost. Live while this page is open.');
?>
<style>
.wm-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 14px}
.wm-bar .chip{min-height:38px;padding:0 14px;text-decoration:none;font-size:13.5px}
.wm-bar .chip.on{background:var(--blue);color:#fff;border-color:var(--blue)}
.wm-live{display:inline-flex;align-items:center;gap:7px;font-weight:800;font-size:12.5px;letter-spacing:.06em;color:var(--ok,#16a34a);margin-left:auto}
.wm-live i{width:9px;height:9px;border-radius:50%;background:currentColor;box-shadow:0 0 0 0 currentColor;animation:wmPulse 1.6s infinite}
.wm-live.off{color:var(--mut)}.wm-live.off i{animation:none}
@keyframes wmPulse{0%{box-shadow:0 0 0 0 rgba(22,163,74,.55)}70%{box-shadow:0 0 0 9px rgba(22,163,74,0)}100%{box-shadow:0 0 0 0 rgba(22,163,74,0)}}
.wm-conn{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:12px;margin:0 0 16px}
.wm-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:13px 15px;border-top:4px solid var(--line)}
.wm-card.good{border-top-color:#16a34a}.wm-card.warn{border-top-color:#f59e0b}.wm-card.bad{border-top-color:#dc2626}
.wm-card h4{margin:0 0 6px;font-size:14px;display:flex;align-items:center;gap:7px}
.wm-card .big{font-size:22px;font-weight:800;line-height:1.15}
.wm-card dl{margin:6px 0 0;display:grid;grid-template-columns:auto 1fr;gap:2px 10px;font-size:12.5px}
.wm-card dt{color:var(--mut)}.wm-card dd{margin:0;font-weight:600;min-width:0;overflow-wrap:anywhere}
.wm-card p{margin:6px 0 0;font-size:12.5px;color:var(--mut)}
.wm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:14px;margin:0 0 16px}
.wm-grid .panel{margin:0}
.wm-t{width:100%;border-collapse:collapse;font-size:13px}
.wm-t th,.wm-t td{padding:7px 8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
.wm-t td.n,.wm-t th.n{text-align:right;font-variant-numeric:tabular-nums}
.wm-why{display:grid;gap:8px}
.wm-why div{display:grid;grid-template-columns:52px minmax(0,1fr);gap:10px;align-items:start;font-size:13px}
.wm-why b{font-size:18px;text-align:right;color:var(--bad,#dc2626)}
.wm-why small{color:var(--mut)}
.wm-days{display:flex;align-items:flex-end;gap:5px;height:130px;padding:6px 2px 0}
.wm-day{flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:stretch;justify-content:flex-end;height:100%;position:relative}
.wm-day span{display:block;border-radius:3px 3px 0 0}
.wm-day .ok{background:#16a34a}.wm-day .bd{background:#dc2626}.wm-day .ot{background:#94a3b8}
.wm-day em{font-style:normal;font-size:10px;color:var(--mut);text-align:center;margin-top:3px;white-space:nowrap}
.wm-legend{display:flex;gap:12px;font-size:12px;color:var(--mut);margin-top:6px;flex-wrap:wrap}
.wm-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:4px;vertical-align:-1px}
.wm-feed td{font-size:13px}
.wm-feed tr.new{animation:wmNew 2.4s ease-out}
@keyframes wmNew{0%{background:rgba(37,99,235,.22)}100%{background:transparent}}
.wm-who b{display:block;font-size:13.5px}.wm-who small{color:var(--mut)}
.wm-kind{display:inline-block;font-size:10.5px;font-weight:700;padding:1px 6px;border-radius:999px;background:var(--soft);color:var(--mut);margin-left:4px;text-transform:uppercase;letter-spacing:.03em}
.wm-cc{display:inline-block;font-size:10.5px;font-weight:800;padding:1px 5px;border-radius:4px;margin-right:4px;color:#fff;background:#f97316}
.wm-cc.NP{background:#dc2626}.wm-cc.OT{background:#64748b}
.wm-st{display:inline-flex;align-items:center;gap:4px;font-weight:700;font-size:12.5px;white-space:nowrap}
.wm-st.read{color:#2563eb}.wm-st.delivered{color:#16a34a}.wm-st.failed{color:#dc2626}.wm-st.accepted,.wm-st.sent,.wm-st.queued{color:#b45309}.wm-st.skipped{color:var(--mut)}
.wm-body{max-width:380px;color:var(--mut);font-size:12.5px;overflow-wrap:anywhere}
.wm-tag{display:inline-block;font-size:11px;font-weight:700;padding:1px 7px;border-radius:6px;background:var(--soft);margin-bottom:3px}
.wm-fail{color:#dc2626;font-size:12px;margin-top:3px}
.wm-tpl{display:flex;flex-wrap:wrap;gap:6px}
.wm-tpl span{font-size:12px;padding:3px 8px;border-radius:8px;border:1px solid var(--line);background:var(--card)}
.wm-tpl span.ok{border-color:#16a34a}.wm-tpl span.pend{border-color:#f59e0b}.wm-tpl span.bad{border-color:#dc2626}
.wm-rates{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px}
.wm-rates label{display:grid;gap:3px;font-size:12px;color:var(--mut)}
.wm-rates input{width:100%}
@media (max-width:760px){.wm-body{max-width:none}.wm-live{margin-left:0}}
</style>

<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<?php if (!$ready): ?>
  <div class="flash bad">The WhatsApp Manager tables are not installed yet. Run <span class="mono">database/upgrade-2026-10-05-wa-manager.sql</span> once, then reload.</div>
<?php else: ?>

<div class="wm-bar">
  <?php foreach ($periods as $k => $l): ?>
    <a class="chip <?= $per === $k ? 'on' : '' ?>" href="<?= $e($qs(['p' => $k])) ?>"><?= $e($l) ?></a>
  <?php endforeach; ?>
  <form method="post" style="margin:0">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="check">
    <button class="btn ghost" type="submit" title="Copy new messages, ask AiSensy for delivery, read the wallet"><svg class="a-ic"><use href="#a-refresh"/></svg>Check now</button>
  </form>
  <a class="btn ghost" href="<?= $e($qs(['export' => 'csv'])) ?>"><svg class="a-ic"><use href="#a-download"/></svg>CSV</a>
  <?php if (Auth::can('customers.view') && is_file(__DIR__ . '/wa-compose.php')): ?>
    <a class="btn ghost" href="/admin/wa-compose.php"><svg class="a-ic"><use href="#a-send"/></svg>Send a message</a>
  <?php endif; ?>
  <span class="wm-live" id="wmLive"><i></i>LIVE</span>
</div>

<!-- Connections: what each sender looks like right now -->
<div class="wm-conn">
  <?php
    $aisH = $health['aisensy|whatsapp'] ?? null;
    $q = strtoupper((string) ($proj['quality'] ?? ''));
    $cr = (float) ($proj['credit'] ?? 0);
    $aisTone = $proj === [] ? 'warn' : (($q === 'RED' || ($proj['status'] ?? '') !== 'active') ? 'bad' : ($cr < 50 || $q === 'YELLOW' ? 'warn' : 'good'));
  ?>
  <div class="wm-card <?= $aisTone ?>">
    <h4><svg class="a-ic"><use href="#a-whatsapp"/></svg>AiSensy WhatsApp (main)</h4>
    <?php if ($proj !== []): ?>
      <div class="big"><?= $money($cr) ?> <small class="muted" style="font-size:12px;font-weight:600">wallet</small></div>
      <dl>
        <dt>Number</dt><dd>+<?= $e($proj['number'] ?? '') ?></dd>
        <dt>Plan</dt><dd><?= $e(str_replace('_', ' ', (string) ($proj['plan'] ?? ''))) ?> · renews <?= $e($proj['renewal'] ?? '') ?></dd>
        <dt>Quality</dt><dd><?= $e($q ?: '—') ?> · <?= $e(str_replace('_', ' ', (string) ($proj['tier'] ?? ''))) ?> · <?= (int) ($proj['daily_limit'] ?? 0) ?>/day</dd>
        <dt>Meta verify</dt><dd><?= $e(str_replace('_', ' ', (string) ($proj['fbm'] ?? ''))) ?></dd>
        <dt>Last 24 h</dt><dd><?= (int) ($aisH['n'] ?? 0) ?> sent · <?= (int) ($aisH['bad'] ?? 0) ?> failed</dd>
      </dl>
      <?php if ($cr < 50): ?><p>⚠️ Wallet is low — recharge in the AiSensy dashboard or tickets stop.</p><?php endif; ?>
      <p class="text-xs">Read from the AiSensy API · <?= $e(substr((string) ($proj['at'] ?? ''), 11, 5)) ?></p>
    <?php else: ?>
      <p>AiSensy did not answer yet (key missing or API down). Press <b>Check now</b>.</p>
    <?php endif; ?>
  </div>
  <?php
    $meta = $health['cloud_api|whatsapp'] ?? null;
    $metaTone = $meta === null ? 'warn' : ((int) $meta['bad'] > (int) $meta['ok'] ? 'bad' : 'good');
  ?>
  <div class="wm-card <?= $metaTone ?>">
    <h4><svg class="a-ic"><use href="#a-globe"/></svg>Meta Cloud API (old number)</h4>
    <div class="big"><?= (int) ($meta['n'] ?? 0) ?> <small class="muted" style="font-size:12px;font-weight:600">tries in 24 h</small></div>
    <dl><dt>Failed</dt><dd><?= (int) ($meta['bad'] ?? 0) ?></dd><dt>Last</dt><dd><?= $e(substr((string) ($meta['last_at'] ?? '—'), 0, 16)) ?></dd></dl>
    <?php $te = $topErr('cloud_api'); if ($te !== ''): ?><p>Main reason: <?= $e($te) ?></p><?php endif; ?>
  </div>
  <?php
    $smsH = null;
    foreach ($health as $k => $h) { if (str_ends_with($k, '|sms')) { $smsH = $h; break; } }
    $smsTone = $smsH === null ? 'warn' : ((int) $smsH['bad'] > 0 && (int) $smsH['ok'] === 0 ? 'bad' : 'good');
  ?>
  <div class="wm-card <?= $smsTone ?>">
    <h4><svg class="a-ic"><use href="#a-phone"/></svg>SMS</h4>
    <div class="big"><?= (int) ($smsH['n'] ?? 0) ?> <small class="muted" style="font-size:12px;font-weight:600">in 24 h</small></div>
    <dl><dt>Delivered/accepted</dt><dd><?= (int) ($smsH['ok'] ?? 0) ?></dd><dt>Failed</dt><dd><?= (int) ($smsH['bad'] ?? 0) ?></dd></dl>
    <?php $te = $topErr((string) ($smsH['provider'] ?? 'brevo')); if ($te !== ''): ?><p><?= $e($te) ?></p><?php endif; ?>
  </div>
  <div class="wm-card good">
    <h4><svg class="a-ic"><use href="#a-rupee"/></svg>Cost — <?= $e($periods[$per]) ?></h4>
    <div class="big"><?= $money($wallet['spent']) ?> <small class="muted" style="font-size:12px;font-weight:600">wallet paid</small></div>
    <dl>
      <dt>Rate-card estimate</dt><dd><?= $money($tot['cost'] ?? 0) ?></dd>
      <?php if ((float) $wallet['recharged'] > 0): ?><dt>Recharged</dt><dd><?= $money($wallet['recharged']) ?></dd><?php endif; ?>
      <dt>Per person reached</dt><dd><?= (int) ($tot['delivered'] ?? 0) > 0 ? $money(max((float) $wallet['spent'], (float) ($tot['cost'] ?? 0)) / (int) $tot['delivered']) : '—' ?></dd>
    </dl>
    <p class="text-xs">Wallet = real drop in the AiSensy balance (read every few minutes). Estimate = delivered templates × rate card below.</p>
  </div>
</div>

<div class="kpis" id="wmKpis">
  <?= admin_kpi('Messages', (string) ($tot['total'] ?? 0), ($tot['people'] ?? 0) . ' people · ' . ($tot['wa'] ?? 0) . ' WhatsApp · ' . ($tot['sms'] ?? 0) . ' SMS', 'send', 'blue') ?>
  <?= admin_kpi('Reached', (string) ($tot['delivered'] ?? 0), $pct($tot['delivered'] ?? 0, $tot['total'] ?? 0) . ' delivered or read', 'check-circle', 'green') ?>
  <?= admin_kpi('Read', (string) ($tot['seen'] ?? 0), 'opened on the phone', 'eye', 'teal') ?>
  <?= admin_kpi('Failed', (string) ($tot['failed'] ?? 0), ($tot['skipped'] ?? 0) . ' never left (bad number)', 'alert', (int) ($tot['failed'] ?? 0) > 0 ? 'red' : 'teal') ?>
  <?= admin_kpi('Waiting', (string) ($tot['waiting'] ?? 0), 'accepted, no receipt yet', 'clock', 'navy') ?>
</div>

<div class="wm-grid">
  <div class="panel">
    <h2>What went — by purpose</h2><div class="panel-body">
    <div class="tbl-scroll"><table class="wm-t">
      <thead><tr><th>Message</th><th class="n">Sent</th><th class="n">Reached</th><th class="n">Read</th><th class="n">Failed</th><th class="n">₹</th></tr></thead>
      <tbody>
      <?php if ($byPur === []): ?><tr><td colspan="6" class="muted">Nothing in this period.</td></tr><?php endif; ?>
      <?php foreach ($byPur as $r): ?>
        <tr><td><a href="<?= $e($qs(['q' => (string) $r['k']])) ?>"><?= $e($purLabels[(string) $r['k']] ?? ucfirst(str_replace('_', ' ', (string) $r['k']))) ?></a></td>
          <td class="n"><?= (int) $r['total'] ?></td><td class="n"><?= (int) $r['delivered'] ?></td><td class="n"><?= (int) $r['seen'] ?></td>
          <td class="n" style="<?= (int) $r['failed'] > 0 ? 'color:#dc2626;font-weight:700' : '' ?>"><?= (int) $r['failed'] ?></td><td class="n"><?= number_format((float) $r['cost'], 2) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div></div>

  <div class="panel">
    <h2>Why messages failed</h2><div class="panel-body">
    <div class="wm-why">
      <?php if ($why === []): ?><p class="muted">No failures in this period. ✅</p><?php endif; ?>
      <?php foreach ($why as $w): ?>
        <div><b><?= (int) $w['n'] ?></b><span><?= $e($w['text']) ?><br><small><?= $e($w['provider']) ?><?= $w['code'] !== '' && ctype_digit($w['code']) ? ' · code ' . $e($w['code']) : '' ?></small></span></div>
      <?php endforeach; ?>
    </div>
  </div></div>

  <div class="panel">
    <h2>Where they went</h2><div class="panel-body">
    <table class="wm-t">
      <thead><tr><th>Country</th><th class="n">Sent</th><th class="n">Reached</th><th class="n">Failed</th><th class="n">₹</th></tr></thead>
      <tbody>
      <?php foreach ($byCc as $r): $k = (string) $r['k']; ?>
        <tr><td><span class="wm-cc <?= $e($k) ?>"><?= $e($k) ?></span><?= $k === 'IN' ? 'India' : ($k === 'NP' ? 'Nepal' : 'Other') ?></td>
          <td class="n"><?= (int) $r['total'] ?></td><td class="n"><?= (int) $r['delivered'] ?></td><td class="n"><?= (int) $r['failed'] ?></td><td class="n"><?= number_format((float) $r['cost'], 2) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <table class="wm-t" style="margin-top:10px">
      <thead><tr><th>Sender</th><th class="n">Sent</th><th class="n">Reached</th><th class="n">Failed</th></tr></thead>
      <tbody>
      <?php foreach ($byProv as $r): ?>
        <tr><td><?= $e((string) $r['k']) ?></td><td class="n"><?= (int) $r['total'] ?></td><td class="n"><?= (int) $r['delivered'] ?></td><td class="n"><?= (int) $r['failed'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>

  <div class="panel">
    <h2>Last 14 days</h2><div class="panel-body">
    <?php $mx = max(1, ...array_map(static fn($d) => (int) $d['total'], $days ?: [['total' => 1]])); ?>
    <div class="wm-days" role="img" aria-label="Messages per day, last 14 days">
      <?php foreach ($days as $d):
        $okH = 100 * $d['delivered'] / $mx; $bdH = 100 * $d['failed'] / $mx; $otH = 100 * max(0, $d['total'] - $d['delivered'] - $d['failed']) / $mx; ?>
        <div class="wm-day" title="<?= $e($d['d']) ?>: <?= (int) $d['total'] ?> sent, <?= (int) $d['delivered'] ?> reached, <?= (int) $d['failed'] ?> failed, ₹<?= number_format($d['cost'], 2) ?>">
          <span class="ot" style="height:<?= round($otH, 1) ?>%"></span><span class="bd" style="height:<?= round($bdH, 1) ?>%"></span><span class="ok" style="height:<?= round($okH, 1) ?>%"></span>
          <em><?= $e(date('d', strtotime($d['d']))) ?></em>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="wm-legend"><span><i style="background:#16a34a"></i>reached</span><span><i style="background:#dc2626"></i>failed</span><span><i style="background:#94a3b8"></i>no receipt / sent</span></div>
    <?php if ($credit7 !== []): ?>
      <p class="muted text-xs" style="margin:8px 0 0">AiSensy wallet: <?php foreach (array_slice($credit7, -5) as $c): ?><?= $e(date('d M', strtotime((string) $c['d']))) ?> <?= $money($c['lo']) ?> · <?php endforeach; ?></p>
    <?php endif; ?>
  </div></div>
</div>

<form method="get" class="toolbar" id="wmFilters">
  <input type="hidden" name="p" value="<?= $e($per) ?>">
  <input type="search" name="q" value="<?= $e($filters['q']) ?>" placeholder="Phone · name · PNR · words" style="min-width:200px">
  <select name="ch"><option value="">All channels</option>
    <?php foreach (['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'Email'] as $v => $l): ?><option value="<?= $v ?>" <?= $filters['channel'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select>
  <select name="st"><option value="">Any status</option>
    <?php foreach (['delivered' => 'Reached (delivered/read)', 'read' => 'Read', 'waiting' => 'Waiting', 'failed' => 'Failed', 'skipped' => 'Never left'] as $v => $l): ?><option value="<?= $v ?>" <?= $filters['status'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select>
  <select name="cc"><option value="">India + Nepal</option>
    <?php foreach (['IN' => 'India', 'NP' => 'Nepal', 'OT' => 'Other'] as $v => $l): ?><option value="<?= $v ?>" <?= $filters['country'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select>
  <button class="btn ghost" type="submit"><svg class="a-ic"><use href="#a-filter"/></svg>Filter</button>
  <?php if (array_filter($filters)): ?><a class="btn ghost" href="<?= $e('?p=' . $per) ?>">Clear</a><?php endif; ?>
</form>

<div class="panel">
  <h2>Live feed <small class="muted" id="wmCount"></small></h2>
  <div class="tbl-scroll">
  <table class="wm-t wm-feed">
    <thead><tr><th style="width:84px">Time</th><th>To</th><th>What</th><th>Status</th><th class="n">₹</th></tr></thead>
    <tbody id="wmRows">
    <?php if ($feed === []): ?>
      <tr id="wmEmpty"><td colspan="5"><?= admin_empty('No messages in this period', 'New ones appear here by themselves.', '💬') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
  <div style="padding:10px 0 12px;text-align:center"><button class="btn ghost" type="button" id="wmMore" <?= count($feed) < 60 ? 'hidden' : '' ?>>Show older</button></div>
</div>

<?php if ($tpls !== []): ?>
<div class="panel">
  <h2>AiSensy templates (<?= count($tpls) ?>)</h2>
  <div class="wm-tpl panel-body">
    <?php foreach ($tpls as $t): $s = strtoupper((string) ($t['status'] ?? '')); ?>
      <span class="<?= $s === 'APPROVED' ? 'ok' : ($s === 'REJECTED' ? 'bad' : 'pend') ?>" title="<?= $e(mb_strimwidth((string) ($t['text'] ?? ''), 0, 300, '…')) ?>"><b><?= $e($t['name'] ?? '') ?></b> · <?= $e(strtolower((string) ($t['category'] ?? ''))) ?> · <?= $e(strtolower($s)) ?></span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<details class="panel">
  <summary style="cursor:pointer;font-weight:700;padding:14px 18px">Rate card (₹ per delivered message)</summary>
  <div class="panel-body"><p class="muted text-sm" style="margin-top:0">Meta charges a template when it is delivered; replies inside the customer's 24 h chat are free. These numbers only make the estimate — the wallet figure above is what AiSensy really took.</p>
  <form method="post">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="rates">
    <div class="wm-rates">
      <?php foreach (['in' => 'India', 'np' => 'Nepal', 'ot' => 'Other'] as $cc => $cl): foreach (['utility' => 'ticket / utility', 'marketing' => 'offer / marketing', 'authentication' => 'OTP'] as $cat => $catL): ?>
        <label><?= $e($cl . ' — ' . $catL) ?><input type="number" step="0.001" min="0" name="wam_rate_<?= $cc ?>_<?= $cat ?>" value="<?= $e(MsgLedger::rate(strtoupper($cc), $cat)) ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
      <?php endforeach; endforeach; ?>
      <label>SMS (each)<input type="number" step="0.001" min="0" name="wam_rate_sms" value="<?= $e(MsgLedger::rate('IN', 'sms')) ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
    </div>
    <?php if ($canEdit): ?><p style="margin:10px 0 0"><button class="btn" type="submit">Save rates</button></p><?php endif; ?>
  </form></div>
</details>

<script>
(function(){
  var FEED = <?= json_encode(array_map('wm_row', $feed), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var PUR = <?= json_encode($purLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var maxId = <?= $maxId ?>, chgAt = <?= json_encode($lastStatusAt) ?>;
  var tbody = document.getElementById('wmRows'), live = document.getElementById('wmLive');
  var params = new URLSearchParams(location.search); params.delete('export');
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function phone(d){ d=String(d||''); if(d.indexOf('977')===0) return '+977 '+d.slice(3,6)+' '+d.slice(6); if(d.indexOf('91')===0&&d.length===12) return '+91 '+d.slice(2,7)+' '+d.slice(7); return d?'+'+d:'—'; }
  var ST = {read:'✓✓ Read',delivered:'✓✓ Delivered',sent:'✓ Sent',accepted:'⏳ Accepted',queued:'⏳ Queued',failed:'✕ Failed',skipped:'— Not sent'};
  function stHtml(r){ return '<span class="wm-st '+esc(r.st)+'">'+esc(ST[r.st]||r.st)+'</span>'+(r.st==='failed'&&r.why?'<div class="wm-fail">'+esc(r.why)+'</div>':''); }
  function rowHtml(r){
    var who = r.who ? '<b>'+esc(r.who)+(r.kind&&r.kind!=='unknown'?'<span class="wm-kind">'+esc(r.kind)+'</span>':'')+'</b>' : (r.kind&&r.kind!=='unknown'?'<b><span class="wm-kind">'+esc(r.kind)+'</span></b>':'');
    var what = PUR[r.what] || PUR[r.label] || String(r.what||'').replace(/_/g,' ');
    var ch = r.ch==='whatsapp' ? '' : '<span class="wm-tag">'+esc(r.ch.toUpperCase())+'</span> ';
    return '<td class="mono muted text-xs">'+esc(r.t)+'</td>'
      +'<td class="wm-who">'+who+'<small>'+(r.cc?'<span class="wm-cc '+esc(r.cc)+'">'+esc(r.cc)+'</span>':'')+esc(phone(r.to))+'</small>'
        +(r.pnr?'<div class="text-xs"><a href="/admin/booking-view.php?pnr='+encodeURIComponent(r.pnr)+'">'+esc(r.pnr)+'</a></div>':'')+'</td>'
      +'<td>'+ch+'<span class="wm-tag" title="'+esc(r.label+' · '+r.cat+' · '+r.prov)+'">'+esc(what)+'</span>'
        +(r.body?'<div class="wm-body">'+esc(r.body)+'</div>':'')
        +(r.media?'<div class="text-xs"><a href="'+esc(r.media)+'" target="_blank" rel="noopener">📎 attachment</a></div>':'')
        +(r.by?'<div class="muted text-xs">by '+esc(r.by)+'</div>':'')+'</td>'
      +'<td class="wm-stc">'+stHtml(r)+'</td>'
      +'<td class="n wm-cost">'+(r.cost>0?r.cost.toFixed(2):'—')+'</td>';
  }
  function add(rows, top, flash){
    var e=document.getElementById('wmEmpty'); if(e&&rows.length) e.remove();
    var frag=document.createDocumentFragment();
    rows.forEach(function(r){ if(document.getElementById('wm'+r.id)) return; var tr=document.createElement('tr'); tr.id='wm'+r.id; tr.innerHTML=rowHtml(r); if(flash) tr.className='new'; frag.appendChild(tr); });
    if(top) tbody.insertBefore(frag, tbody.firstChild); else tbody.appendChild(frag);
    document.getElementById('wmCount').textContent='· '+tbody.querySelectorAll('tr[id^=wm]').length+' shown';
  }
  add(FEED, false, false);
  var shown = FEED.length;
  var busy=false, fails=0;
  function tick(){
    if(busy||document.hidden) return; busy=true;
    var p=new URLSearchParams(params); p.set('feed','1'); p.set('since',String(maxId)); p.set('chg',chgAt);
    fetch('?'+p.toString(),{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(j){
      busy=false; if(!j||!j.ok){ throw new Error('feed'); }
      fails=0; live.classList.remove('off');
      if(j.rows&&j.rows.length){ j.rows.forEach(function(r){ maxId=Math.max(maxId,r.id); }); add(j.rows, true, true); }
      (j.chg||[]).forEach(function(c){ var tr=document.getElementById('wm'+c.id); if(!tr) return;
        var td=tr.querySelector('.wm-stc'); if(td) td.innerHTML=stHtml({st:c.st,why:c.why});
        var tc=tr.querySelector('.wm-cost'); if(tc) tc.textContent=c.cost>0?c.cost.toFixed(2):'—'; });
      chgAt=j.now||chgAt;
      if(j.totals){ var kv=document.querySelectorAll('#wmKpis .kpi .kv'), t=j.totals, vals=[t.total,t.delivered,t.seen,t.failed,t.waiting];
        for(var i=0;i<kv.length&&i<vals.length;i++){ if(kv[i].textContent!==String(vals[i]||0)) kv[i].textContent=String(vals[i]||0); } }
    }).catch(function(){ busy=false; fails++; if(fails>2) live.classList.add('off'); });
  }
  setInterval(tick, 6000);
  document.addEventListener('visibilitychange', function(){ if(!document.hidden) tick(); });
  var more=document.getElementById('wmMore');
  if(more) more.onclick=function(){
    var p=new URLSearchParams(params); p.set('feed','1'); p.set('since','0');
    more.disabled=true;
    p.set('offset',String(shown));
    fetch('?'+p.toString(),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
      more.disabled=false; var rows=j.rows||[]; shown+=rows.length;
      if(rows.length) add(rows,false,false);
      if(rows.length<60) more.hidden=true;
    }).catch(function(){ more.disabled=false; });
  };
})();
</script>
<?php endif; ?>
<?php
admin_footer();
