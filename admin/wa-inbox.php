<?php
/**
 * admin/wa-inbox.php — the WhatsApp inbox, contacts and groups (29 Oct 2026).
 *
 * Owner's "AI System Prompt (Opus 5.5 Max)" 6.3 + 7: every message people
 * send the office number, grouped by sender, with the bot's answers beside
 * them; a person can reply (inside the free 24-hour window only) and pause
 * the bot for that number while they handle it; who wrote (passenger /
 * agent / office / unknown, opted out or not); and S HARI AI's office
 * groups — which group it may answer in, the quiet hours, what it said.
 *
 * Reads wa_inbound / message_logs / wa_group_log (includes/wainbox.php,
 * includes/wagroup.php). Seeing it: customers.view. Replying and pausing:
 * customers.view too (the desk's own job). Group switches: settings.manage.
 *
 * 16 Nov 2026 (SHG Network Master Upgrade v2.0, P6 / P7-9b) — the office's
 * conversation hub: WhatsApp | Messenger | Sent on top; names in the list;
 * unread per conversation; a state per chat (Agent needed / Agent handling /
 * AI) with filter chips and a "Resolved" button; search by number or name;
 * replies without a page reload (same WaInbox::reply() guard + audit) and a
 * 20 s refresh of the open chat; Help Desk quick replies; "Suggest reply"
 * (a draft only — never sent by itself); Book for them / Escalate; a
 * customer panel with the last 5 bookings; today's reply times against the
 * 30 s (AI) / 5 min (agent) targets; 👍 / 👎 on each bot reply.
 * Resolved / Escalate touch the Help Desk: support.reply. Sent: dashboard.view
 * (the same as the outbound message log). No new message to any customer —
 * except P6-3b: with wa_handoff_msg_on ON (default OFF) "Pause bot" offers a
 * "Tell the customer" tick that sends ONE fixed in-window line (WaInbox::handoffNotice).
 * P6-9d: with wa_inbox_promo_link_on ON (default OFF) a chat header shows
 * "🎁 अफर · Offer" to someone who may create campaigns (marketing.send):
 * a link to a NEW campaign for this one number — it sends nothing; the
 * Campaigns page keeps its STOP / opt-in, template, test and daily-cap rules.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/wainbox.php';
require_once INCLUDE_PATH . '/wagroup.php';

$admin   = admin_boot('customers.view');
$adminId = (int) $admin['id'];
$canSet  = Auth::canManageSettings();
$canDesk = Auth::can('support.reply');
$canBook = Auth::can('bookings.view');
$canSent = Auth::can('dashboard.view');
$canPromo = WaInbox::promoLinkOn();                 // P6-9d: wa_inbox_promo_link_on (OFF) + may create a campaign
$e       = static fn($v): string => Security::e((string) $v);
$tab     = in_array($_GET['tab'] ?? '', ['inbox', 'contacts', 'groups', 'sent'], true) ? (string) $_GET['tab'] : 'inbox';
if ($tab === 'sent' && !$canSent) {
    $tab = 'inbox';
}
$open    = preg_replace('/\D/', '', (string) ($_GET['p'] ?? '')) ?? '';
$q       = trim(Security::clean((string) ($_GET['q'] ?? ''), 60));
$st      = in_array($_GET['st'] ?? '', ['needed', 'agent', 'ai'], true) ? (string) $_GET['st'] : '';
$flash   = null;
$fbOn    = WaInbox::feedbackReady() > 0;
$json    = static function (array $out): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
};
/* One chat as the page and the 20 s refresh both draw it. */
$toClient = static function (array $chat, array $votes) use ($fbOn): array {
    return array_map(static fn(array $m): array => [
        'id'   => (int) $m['id'],
        'dir'  => (string) $m['dir'],
        'text' => trim($m['text'] !== '' ? (string) $m['text'] : '[' . $m['kind'] . ']'),
        'meta' => substr((string) $m['at'], 0, 16) . ' · ' . ($m['dir'] === 'out'
            ? (($m['kind'] !== '' ? $m['kind'] : 'message') . ' · ' . $m['status']) : (string) $m['kind']),
        'vote' => $fbOn && $m['dir'] === 'out' && $m['kind'] === 'bot_reply',
        'v'    => (string) ($votes[(int) $m['id']] ?? ''),
    ], $chat);
};
$sigOf = static fn(array $items): string => md5((string) json_encode(array_map(
    static fn(array $m): array => [$m['id'], $m['dir'], $m['meta'], $m['v']], $items)));
$voteIds = static fn(array $chat): array => array_map(static fn(array $m): int => (int) $m['id'],
    array_filter($chat, static fn(array $m): bool => $m['dir'] === 'out' && $m['kind'] === 'bot_reply'));

/* ---- read-only JSON: the open chat (20 s refresh) and a suggested reply ---- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && in_array($_GET['ajax'] ?? '', ['thread', 'suggest'], true)) {
    if ($open === '') {
        $json(['ok' => false, 'message' => 'Choose a conversation.']);
    }
    if ($_GET['ajax'] === 'thread') {
        $chat  = WaInbox::thread($open, $q !== '' ? WaInbox::SEARCH_DAYS : 7);
        $items = $toClient($chat, WaInbox::verdicts($voteIds($chat), $adminId));
        WaInbox::markThreadSeen($adminId, $open);
        $json(['ok' => true, 'items' => $items, 'sig' => $sigOf($items), 'status' => WaInbox::statusFor($open)]);
    }
    require_once INCLUDE_PATH . '/wafaq.php';
    $last = WaInbox::lastInbound($open);
    $sug  = $last !== '' ? WaFaq::suggest('+' . WaInbox::intl($open), $last) : null;
    $json(['ok' => $sug !== null, 'text' => (string) $sug, 'message' => $sug !== null
        ? 'Draft filled in — read it, change it, then Send. / सुझाव राखियो — हेरेर पठाउनुहोस्।'
        : 'No ready answer for their last message — please write your own. / तयार जवाफ छैन।']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ajax   = ($_POST['ajax'] ?? '') === '1';
    $bubble = null;
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('Session expired — please try again. / फेरि प्रयास गर्नुहोस्।');
        }
        $act = (string) ($_POST['action'] ?? '');
        $who = preg_replace('/\D/', '', (string) ($_POST['phone'] ?? '')) ?? '';
        if ($act === 'reply') {
            $text = (string) ($_POST['text'] ?? '');
            $r = WaInbox::reply($who, $text, $adminId);
            $flash = $r['ok'] ? ['ok', 'Sent. / पठाइयो।'] : ['bad', $r['why']];
            $open = $who;
            if ($r['ok']) {
                $bubble = ['id' => 0, 'dir' => 'out', 'text' => trim($text), 'meta' => date('Y-m-d H:i') . ' · inbox_reply · sent', 'vote' => false, 'v' => ''];
            }
        } elseif ($act === 'pause') {
            $h = (int) ($_POST['hours'] ?? 0);
            WaInbox::pause($who, $h, $adminId);
            $flash = ['ok', $h > 0 ? 'The bot is quiet for this number for ' . $h . ' h. / बोट रोकियो।' : 'The bot answers this number again. / बोट फेरि चालु।'];
            /* P6-3b (switch wa_handoff_msg_on, OFF): "tell the customer" ticked — one fixed in-window line. */
            if ($h > 0 && (string) ($_POST['tell'] ?? '') === '1' && WaInbox::handoffOn()) {
                $hn = WaInbox::handoffNotice($who, $adminId);
                $flash[1] .= $hn['sent'] ? ' The customer was told a person will help now. / ग्राहकलाई भनियो।' : ' ' . $hn['why'];
            }
            $open = $who;
        } elseif ($act === 'resolve') {
            if (!$canDesk || Auth::bookingScopeAdminId() !== null) {
                throw new RuntimeException('Resolving needs the Help Desk reply permission. / अनुमति छैन।');
            }
            $r = WaInbox::resolve($who, $adminId);
            $flash = ['ok', 'Resolved — the bot answers this number again' . ($r['tickets'] !== [] ? '; help desk ' . implode(', ', $r['tickets']) . ' resolved' : '') . '. / समाधान भयो।'];
            $open = $who;
        } elseif ($act === 'escalate') {
            if (!$canDesk || Auth::bookingScopeAdminId() !== null) {
                throw new RuntimeException('Escalating needs the Help Desk reply permission. / अनुमति छैन।');
            }
            $nm = (string) (WaInbox::names([$who])[substr($who, -10)]['name'] ?? '');
            $r = WaInbox::escalate($who, $adminId, $nm);
            $flash = $r['ok']
                ? ['ok', ($r['duplicate'] ? 'Already on the Help Desk: ' : 'Help Desk ticket opened: ') . $r['ref'] . ' — the customer is not messaged. / हेल्प डेस्कमा पठाइयो।']
                : ['bad', $r['why']];
            $open = $who;
        } elseif ($act === 'verdict') {
            $r = WaInbox::verdict($who, (int) ($_POST['msg'] ?? 0), (string) ($_POST['v'] ?? ''), (string) ($_POST['note'] ?? ''), $adminId);
            $flash = $r['ok'] ? ['ok', 'Noted — thank you. / राखियो, धन्यवाद।'] : ['bad', $r['why']];
            $open = $who;
        } elseif ($act === 'seen_all') {
            WaInbox::markSeen($adminId);
            $flash = ['ok', 'Every conversation is marked read. / सबै पढियो।'];
        } elseif ($act === 'groups') {
            if (!$canSet) {
                throw new RuntimeException('Group switches need the Settings permission.');
            }
            $before = ['on' => WaGroup::replyOn(), 'correct' => WaGroup::correctOn(), 'groups' => WaGroup::groups()];
            $groups = [];
            foreach ((array) ($_POST['g_id'] ?? []) as $i => $gid) {
                $gid = WaGroup::cleanId((string) $gid);
                if ($gid === '') {
                    continue;
                }
                $groups[$gid] = [
                    'name'  => Security::clean((string) ($_POST['g_name'][$i] ?? ''), 60),
                    'reply' => (string) ($_POST['g_reply'][$i] ?? '0') === '1',
                ];
            }
            $newId = WaGroup::cleanId((string) ($_POST['new_id'] ?? ''));
            if ($newId !== '' && !isset($groups[$newId])) {
                $groups[$newId] = ['name' => Security::clean((string) ($_POST['new_name'] ?? ''), 60), 'reply' => false];
            }
            foreach (['wa_group_quiet_from', 'wa_group_quiet_to'] as $k) {
                $v = trim((string) ($_POST[$k] ?? ''));
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) !== 1) {
                    throw new RuntimeException('Quiet hours are HH:MM, e.g. 22:00.');
                }
                Settings::set($k, $v, 'string', 'whatsapp');
            }
            Settings::set('wa_groups', $groups, 'json', 'whatsapp');
            Settings::set('wa_group_reply_on', (string) ($_POST['wa_group_reply_on'] ?? '0') === '1' ? '1' : '0', 'bool', 'whatsapp');
            Settings::set('wa_group_correct_on', (string) ($_POST['wa_group_correct_on'] ?? '0') === '1' ? '1' : '0', 'bool', 'whatsapp');
            Settings::set('wa_group_mention_words', Security::clean((string) ($_POST['wa_group_mention_words'] ?? ''), 200), 'string', 'whatsapp');
            Logger::audit('wa.groups', 'settings', 'whatsapp', $before, ['on' => WaGroup::replyOn(), 'correct' => WaGroup::correctOn(), 'groups' => $groups], 'WhatsApp groups saved by admin #' . $adminId);
            $flash = ['ok', 'Group settings saved. / सेभ भयो।'];
            $tab = 'groups';
        } elseif ($act === 'switches') {
            if (!$canSet) {
                throw new RuntimeException('These switches need the Settings permission.');
            }
            $before = [];
            $after  = [];
            foreach (['wa_pay_ocr_on' => 'whatsapp', 'wa_onboard_on' => 'whatsapp', 'data_retention_on' => 'general'] as $k => $grp) {
                $before[$k] = Settings::getBool($k, false) ? 1 : 0;
                $after[$k]  = (string) ($_POST[$k] ?? '0') === '1' ? 1 : 0;
                Settings::set($k, $after[$k] ? '1' : '0', 'bool', $grp);
            }
            Logger::audit('wa.switches', 'settings', 'whatsapp', $before, $after, 'S HARI AI switches saved by admin #' . $adminId);
            $flash = ['ok', 'Saved. / सेभ भयो।'];
            $tab = 'groups';
        }
    } catch (Throwable $ex) {
        $flash = ['bad', $ex->getMessage()];
    }
    if ($ajax) {
        $json(['ok' => ($flash[0] ?? '') === 'ok', 'message' => (string) ($flash[1] ?? ''), 'bubble' => $bubble]);
    }
}

$haveInbound = true;
try {
    Database::scalar('SELECT 1 FROM wa_inbound LIMIT 1', [], null);
} catch (Throwable $ex) {
    $haveInbound = false;
}

admin_header('WhatsApp Inbox', 'wa-inbox');
admin_page_head('Every WhatsApp message to the office number, with what the bot said — reply, or pause the bot while you handle someone. Plus contacts and S HARI AI in the office groups.');
?>
<style>
.wi-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}
.wi-tabs a{padding:8px 14px;border-radius:999px;border:1px solid var(--line);background:var(--card);font-weight:700;text-decoration:none;color:inherit}
.wi-tabs a.on{background:var(--blue);border-color:var(--blue);color:#fff}
.wi-grid{display:grid;grid-template-columns:minmax(260px,360px) 1fr;gap:14px;align-items:start}
@media (max-width:820px){.wi-grid{grid-template-columns:1fr}}
.wi-list{display:grid;gap:6px;max-height:70vh;overflow:auto}
.wi-row{display:grid;gap:2px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:var(--card);text-decoration:none;color:inherit}
.wi-row.on{border-color:var(--blue);box-shadow:0 0 0 2px rgba(31,79,224,.15)}
.wi-row b{display:flex;justify-content:space-between;gap:8px}
.wi-row span{font-size:12.5px;color:var(--mut);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wi-badge{background:#dc2626;color:#fff;border-radius:999px;padding:0 7px;font-size:11.5px;line-height:18px}
.wi-chat{display:grid;gap:8px;max-height:60vh;overflow:auto;padding:4px}
.wi-msg{max-width:min(520px,88%);padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-break:break-word;font-size:13.5px}
.wi-msg.in{background:var(--soft);border:1px solid var(--line);justify-self:start}
.wi-msg.out{background:#dcfce7;color:#14532d;justify-self:end}
.wi-msg small{display:block;margin-top:4px;font-size:11px;opacity:.75}
.wi-reply{display:grid;gap:8px;margin-top:10px}
.wi-reply textarea{width:100%;min-height:70px}
.wi-inline{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.wi-g input[type=text]{width:100%}
/* 16 Nov 2026 — the conversation hub */
.wi-grid{grid-template-columns:minmax(250px,330px) minmax(0,1fr) minmax(230px,290px);grid-template-areas:"list chat side"}
.wi-grid>.wi-l{grid-area:list}.wi-grid>.wi-c{grid-area:chat;min-width:0}.wi-grid>.wi-s{grid-area:side;min-width:0}
@media (max-width:1180px){.wi-grid{grid-template-columns:minmax(250px,330px) minmax(0,1fr);grid-template-areas:"list chat" "list side"}}
@media (max-width:820px){.wi-grid{grid-template-columns:minmax(0,1fr);grid-template-areas:"list" "chat" "side"}.wi-list{max-height:42vh}}
.wi-st{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:800;line-height:18px;white-space:nowrap}
.wi-st.needed{background:var(--bad-bg,#fdecec);color:var(--bad,#8a1f1f)}
.wi-st.agent{background:var(--warn-bg,#fff4d1);color:var(--warn,#8a6d00)}
.wi-st.ai{background:var(--ok-bg,#e7f6ec);color:var(--ok,#0a6b3b)}
.wi-row .wi-nm{color:inherit;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.wi-find{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 10px}
.wi-find input[type=search]{flex:1 1 180px;min-width:0;min-height:40px}
.wi-chips{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}
.wi-chips a{padding:5px 11px;border-radius:999px;border:1px solid var(--line);font-size:12.5px;font-weight:700;text-decoration:none;color:inherit;background:var(--card)}
.wi-chips a.on{border-color:var(--blue);box-shadow:0 0 0 2px rgba(31,79,224,.15)}
.wi-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin:0 0 12px}
.wi-k{border:1px solid var(--line);border-radius:12px;padding:9px 12px;background:var(--card);font-size:13px}
.wi-k b{display:block;font-size:12px;color:var(--mut);font-weight:700}
.wi-k strong{font-size:18px}
.wi-k small{display:block;color:var(--mut);font-size:11.5px;margin-top:2px}
.wi-k.ok strong{color:var(--ok,#0a6b3b)}.wi-k.warn strong{color:var(--warn,#8a6d00)}
.wi-head{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center;padding:12px 16px 0}
.wi-head .wi-who{min-width:0}.wi-head .wi-who b{font-size:16px;overflow-wrap:anywhere}
.wi-acts{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.wi-acts form{display:inline}
.wi-quick{display:flex;gap:6px;flex-wrap:wrap}
.wi-quick button{padding:5px 10px;border-radius:999px;border:1px solid var(--line);background:var(--soft);font-size:12px;font-weight:700;cursor:pointer;color:inherit}
.wi-quick button.wi-sug{background:var(--card);border-color:var(--blue)}
.wi-vote{display:inline-flex;gap:4px;margin-left:6px;vertical-align:middle}
.wi-vote button{border:1px solid transparent;background:transparent;border-radius:8px;padding:3px 8px;min-height:30px;font-size:14px;line-height:20px;cursor:pointer;opacity:.6}
.wi-vote button.on{opacity:1;border-color:currentColor;background:rgba(255,255,255,.6)}
.wi-say{font-size:12.5px;font-weight:700}
.wi-side dl{display:grid;grid-template-columns:auto 1fr;gap:4px 10px;margin:0 0 10px;font-size:13px}
.wi-side dt{color:var(--mut)}.wi-side dd{margin:0;overflow-wrap:anywhere}
.wi-bk{display:grid;gap:6px;margin:0;padding:0;list-style:none}
.wi-bk li{border:1px solid var(--line);border-radius:10px;padding:7px 9px;font-size:12.5px;display:grid;gap:2px}
.wi-bk li b{display:flex;justify-content:space-between;gap:6px;flex-wrap:wrap}
.wi-tell{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;min-height:40px;font-size:13px;font-weight:700;cursor:pointer}
.wi-tell input{width:18px;height:18px;margin:0}
.wi-tell small{font-weight:400;flex-basis:100%}
/* 16 Nov 2026 (F3): the floating corner button sat on the right end of "Pause bot 2 h" at 360px
   whenever the page scrolled that row past it - the AI settings bubble (.shg-aib-fab, 52px, 14-16px
   from the right edge) or, for staff without it, the "🤖 AI" link (.adm-ai-go, about 67px, 16px in).
   The Pause / Resume row keeps that corner's width free, so its buttons wrap before the button's
   column and no scroll position puts one under it. No floating button = no padding (as before). */
body:has(.shg-aib-fab) .wi-pause{padding-right:64px}
body:has(.adm-ai-go) .wi-pause{padding-right:80px}
</style>

<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<?= WaInbox::channelTabs($tab === 'sent' ? 'sent' : 'whatsapp') ?>

<?php if ($tab !== 'sent'): ?>
<nav class="wi-tabs" aria-label="Inbox sections">
  <?php foreach (['inbox' => '💬 Inbox', 'contacts' => '👥 Contacts', 'groups' => '🫂 Office groups (S HARI AI)'] as $k => $l): ?>
    <a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $e($l) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if (!$haveInbound && !in_array($tab, ['groups', 'sent'], true)): ?>
  <div class="panel"><div class="panel-body"><p class="muted" style="margin:0">No WhatsApp messages are recorded on this server yet (table <code>wa_inbound</code> is missing). Messages appear here once the Meta number receives them.</p></div></div>
<?php elseif ($tab === 'inbox'):
    $days    = $q !== '' ? WaInbox::SEARCH_DAYS : 7;
    $threads = WaInbox::threads($adminId, 7, 80, $q);
    $counts  = ['needed' => 0, 'agent' => 0, 'ai' => 0];
    foreach ($threads as $t) {
        $counts[$t['status']] = ($counts[$t['status']] ?? 0) + 1;
    }
    $shown = $st === '' ? $threads : array_values(array_filter($threads, static fn(array $t): bool => $t['status'] === $st));
    if ($open === '' && $shown !== []) {
        $open = $shown[0]['digits'];
    }
    $row = null;
    foreach ($threads as $i => $t) {
        if ($t['digits'] === $open) {
            $row = $t;
            $threads[$i]['unread'] = 0;                      // open now: seen below
        }
    }
    foreach ($shown as $i => $t) {
        if ($t['digits'] === $open) {
            $shown[$i]['unread'] = 0;
        }
    }
    $unread = array_sum(array_column($threads, 'unread'));
    if ($open !== '') {
        WaInbox::markThreadSeen($adminId, $open);           // P6-1g: only THIS chat
    }
    $chat     = $open !== '' ? WaInbox::thread($open, $days) : [];
    $items    = $toClient($chat, WaInbox::verdicts($voteIds($chat), $adminId));
    $pausedTo = $open !== '' ? WaInbox::pausedUntil($open) : 0;
    $status   = $row['status'] ?? ($open !== '' ? WaInbox::statusFor($open) : 'ai');
    $who      = $open !== '' ? (WaInbox::names([$open])[substr($open, -10)] ?? null) : null;
    $name     = (string) ($who['name'] ?? '');
    $local    = substr($open, -10);
    $keep     = static fn(array $extra = []): string => '?' . http_build_query(array_filter($extra + ['tab' => 'inbox', 'q' => $q, 'st' => $st], static fn($v): bool => $v !== '' && $v !== null));
    $rs       = WaInbox::responseStats(date('Y-m-d'));
    $dur      = static function (?float $s): string {
        if ($s === null) {
            return '—';
        }
        return $s < 60 ? round($s) . ' s' : (int) floor($s / 60) . ' min ' . ((int) round($s) % 60) . ' s';
    };
    $canned = [];
    try {
        require_once INCLUDE_PATH . '/helpdesk.php';
        $canned = HelpDesk::canned();
    } catch (Throwable $ex) {
        $canned = [];
    }
    $bk = [];
    $bkTotal = 0;
    if ($open !== '') {
        try {
            require_once INCLUDE_PATH . '/customerbook.php';
            $phones  = CustomerBook::linkedPhones($local) ?: [$local];
            $scope   = Auth::bookingScopeAdminId();
            $bk      = CustomerBook::ledger(['phones' => $phones, 'date_mode' => 'booking'], 5, 0, $scope);
            $bkTotal = (int) (CustomerBook::totals(['phones' => $phones, 'date_mode' => 'booking'], $scope)['bookings'] ?? count($bk));
        } catch (Throwable $ex) {
            $bk = [];
        }
    }
    $roleWord = ['admin' => 'Office', 'agent' => 'Agent / counter', 'staff' => 'Staff'];
    $srcWord  = ['customer' => 'Customer list', 'account' => 'Website account', 'contact' => 'Saved WhatsApp contact', 'booking' => 'Lead passenger of their last booking'];
    $whoWord  = $who === null ? '' : (($who['source'] ?? '') === 'staff' ? ($roleWord[$who['role']] ?? 'Staff') : ($srcWord[$who['source'] ?? ''] ?? ''));
?>
  <div class="wi-kpi" aria-label="Reply time today">
    <div class="wi-k <?= $rs['ai_avg_s'] === null ? '' : ($rs['ai_avg_s'] <= 30 ? 'ok' : 'warn') ?>"><b>AI जवाफ · AI reply (target ≤ 30 s)</b>
      <strong><?= $e($dur($rs['ai_avg_s'])) ?></strong>
      <small><?= $rs['ai_within_30s_pct'] !== null ? $e(rtrim(rtrim(number_format((float) $rs['ai_within_30s_pct'], 1), '0'), '.')) . '% within 30 s · ' : '' ?><?= (int) $rs['ai_n'] ?> today</small></div>
    <div class="wi-k <?= $rs['agent_avg_s'] === null ? '' : ($rs['agent_avg_s'] <= 300 ? 'ok' : 'warn') ?>"><b>एजेन्ट जवाफ · Agent reply (target ≤ 5 min)</b>
      <strong><?= $e($dur($rs['agent_avg_s'])) ?></strong>
      <small><?= $rs['agent_within_5m_pct'] !== null ? $e(rtrim(rtrim(number_format((float) $rs['agent_within_5m_pct'], 1), '0'), '.')) . '% within 5 min · ' : '' ?><?= (int) $rs['agent_n'] ?> today</small></div>
    <div class="wi-k <?= $rs['unanswered_n'] > 0 ? 'warn' : '' ?>"><b>जवाफ नपाएको · Not answered</b>
      <strong><?= (int) $rs['unanswered_n'] ?></strong><small>of <?= (int) $rs['inbound_n'] ?> messages today</small></div>
  </div>

  <form class="wi-find" method="get" role="search">
    <input type="hidden" name="tab" value="inbox"><?php if ($st !== ''): ?><input type="hidden" name="st" value="<?= $e($st) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= $e($q) ?>" maxlength="60" placeholder="नम्बर वा नाम · Number or name" aria-label="Search conversations">
    <button class="btn btn-ghost" type="submit">खोज्नुहोस् · Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="<?= $e('?' . http_build_query(array_filter(['tab' => 'inbox', 'st' => $st]))) ?>">✕ Clear</a><?php endif; ?>
  </form>
  <nav class="wi-chips" aria-label="Filter by state">
    <a href="<?= $e($keep(['st' => ''])) ?>" class="<?= $st === '' ? 'on' : '' ?>">सबै · All (<?= count($threads) ?>)</a>
    <?php foreach (WaInbox::STATUS as $k => [$lbl, $cls]): ?>
      <a href="<?= $e($keep(['st' => $k])) ?>" class="<?= $st === $k ? 'on' : '' ?>"><span class="wi-st <?= $e($cls) ?>"><?= $e($lbl) ?></span> <?= (int) ($counts[$k] ?? 0) ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="wi-grid">
    <div class="panel wi-l"><h2><?= $q !== '' ? 'Last ' . WaInbox::SEARCH_DAYS . ' days · “' . $e($q) . '”' : 'Last 7 days' ?><?= $unread > 0 ? ' · <span class="wi-badge">' . (int) $unread . ' new</span>' : '' ?></h2>
      <?php if ($unread > 0): ?>
        <form method="post" style="padding:0 16px"><?= Security::csrfField() ?><input type="hidden" name="action" value="seen_all">
          <button class="btn btn-ghost btn-sm" type="submit">सबै पढियो · Mark all read</button></form>
      <?php endif; ?>
      <div class="panel-body wi-list">
        <?php if ($shown === []): ?><p class="muted" style="margin:0"><?= $q !== '' || $st !== '' ? 'No conversation matches.' : 'Nobody wrote in the last 7 days.' ?></p><?php endif; ?>
        <?php foreach ($shown as $t): $sl = WaInbox::STATUS[$t['status']] ?? WaInbox::STATUS['ai']; ?>
          <a class="wi-row <?= $t['digits'] === $open ? 'on' : '' ?>" href="<?= $e($keep(['p' => $t['digits']])) ?>">
            <b><span class="wi-nm"><?= $e($t['name'] !== '' ? $t['name'] : '+' . $t['digits']) ?><?= $t['paused'] ? ' ⏸' : '' ?></span>
              <?php if ($t['unread'] > 0): ?><span class="wi-badge"><?= (int) $t['unread'] ?></span><?php endif; ?></b>
            <span><?= $t['name'] !== '' ? '+' . $e($t['digits']) . ' · ' : '' ?><span class="wi-st <?= $e($sl[1]) ?>"><?= $e($sl[0]) ?></span></span>
            <span><?= $e($t['last_text'] !== '' ? $t['last_text'] : '—') ?></span>
            <span><?= $e(substr($t['last_at'], 0, 16)) ?> · <?= (int) $t['msgs'] ?> msg</span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="panel wi-c">
      <?php if ($open === ''): ?>
        <div class="panel-body"><p class="muted" style="margin:0">Choose a conversation.</p></div>
      <?php else: $sl = WaInbox::STATUS[$status] ?? WaInbox::STATUS['ai']; ?>
        <div class="wi-head">
          <div class="wi-who"><b><?= $e($name !== '' ? $name : '+' . $open) ?></b>
            <?php if ($name !== ''): ?><span class="muted"> +<?= $e($open) ?></span><?php endif; ?>
            <span class="wi-st <?= $e($sl[1]) ?>" id="wiStatus"><?= $e($sl[0]) ?></span></div>
          <div class="wi-acts">
            <a class="btn btn-ghost btn-sm" href="customers.php?q=<?= $e($local) ?>">Customer 360</a>
            <?php if ($canBook): ?><a class="btn btn-ghost btn-sm" href="<?= $e('smartbook.php?' . http_build_query(['cphone' => $local, 'cname' => $name])) ?>">🎫 टिकट बुक · Book for them</a><?php endif; ?>
            <?php if ($canPromo && ($promoHref = WaInbox::promoLink($local)) !== ''): ?><a class="btn btn-ghost btn-sm" id="wiPromo" href="<?= $e($promoHref) ?>" title="नयाँ campaign यो एउटा नम्बरका लागि — केही पठाइँदैन · A new campaign for this one number — nothing is sent from here">🎁 अफर · Offer</a><?php endif; ?>
            <?php if ($canDesk): ?>
              <form method="post" onsubmit="return confirm('Open a Help Desk ticket for this chat? The customer is not messaged.')"><?= Security::csrfField() ?>
                <input type="hidden" name="action" value="escalate"><input type="hidden" name="phone" value="<?= $e($open) ?>">
                <button class="btn btn-ghost btn-sm" type="submit">⬆ Escalate</button></form>
              <?php if ($status !== 'ai'): ?>
              <form method="post" onsubmit="return confirm('Mark resolved? The bot answers this number again and its open Help Desk tickets close.')"><?= Security::csrfField() ?>
                <input type="hidden" name="action" value="resolve"><input type="hidden" name="phone" value="<?= $e($open) ?>">
                <button class="btn btn-sm" type="submit">✓ समाधान · Resolved</button></form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="panel-body">
          <div class="wi-chat" id="wiChat" data-p="<?= $e($open) ?>" data-q="<?= $e($q) ?>" data-sig="<?= $e($sigOf($items)) ?>" data-vote="<?= $fbOn ? '1' : '0' ?>">
            <?php if ($items === []): ?><p class="muted">No messages in the last <?= (int) $days ?> days.</p><?php endif; ?>
            <?php foreach ($items as $m): ?>
              <div class="wi-msg <?= $e($m['dir']) ?>"><?= $e($m['text']) ?><small><?= $e($m['meta']) ?><?php if ($m['vote']): ?>
                <form method="post" class="wi-vote"><?= Security::csrfField() ?><input type="hidden" name="action" value="verdict"><input type="hidden" name="phone" value="<?= $e($open) ?>"><input type="hidden" name="msg" value="<?= (int) $m['id'] ?>">
                  <button type="submit" name="v" value="up" class="<?= $m['v'] === 'up' ? 'on' : '' ?>" title="Good answer" aria-label="Good answer">👍</button><button type="submit" name="v" value="down" class="<?= $m['v'] === 'down' ? 'on' : '' ?>" title="Wrong or weak answer" aria-label="Wrong or weak answer">👎</button></form><?php endif; ?></small></div>
            <?php endforeach; ?>
          </div>
          <form method="post" class="wi-reply" id="wiReply">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="reply"><input type="hidden" name="phone" value="<?= $e($open) ?>">
            <div class="wi-quick" aria-label="Quick replies">
              <button type="button" class="wi-sug" id="wiSuggest" title="A draft from the timetable, fares and the knowledge base — never sent by itself">✨ सुझाव · Suggest reply</button>
              <?php foreach ($canned as $c): ?><button type="button" data-body="<?= $e(str_replace('{track}', appUrl('/#/my'), $c['b'])) ?>"><?= $e($c['t']) ?></button><?php endforeach; ?>
            </div>
            <label>Reply as the office (free inside 24 h of their last message)
              <textarea name="text" id="wiText" maxlength="1000" placeholder="नमस्ते, ..."></textarea></label>
            <p class="wi-inline"><button class="btn" type="submit" id="wiSend">Send on WhatsApp</button><span class="wi-say" id="wiSay" role="status" aria-live="polite"></span></p>
          </form>
          <form method="post" class="wi-inline wi-pause">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="pause"><input type="hidden" name="phone" value="<?= $e($open) ?>">
            <?php if ($pausedTo > time()): ?>
              <span class="pill st-warn">Bot paused until <?= $e(date('d M H:i', $pausedTo)) ?></span>
              <button class="btn btn-ghost" type="submit" name="hours" value="0">Resume the bot</button>
            <?php else: ?>
              <span class="muted">Handling them yourself?</span>
              <button class="btn btn-ghost" type="submit" name="hours" value="2">Pause bot 2 h</button>
              <button class="btn btn-ghost" type="submit" name="hours" value="24">Pause 24 h</button>
              <?php if (WaInbox::handoffOn()): ?>
                <label class="wi-tell" title="<?= $e(WaInbox::HANDOFF_TEXT) ?>"><input type="checkbox" name="tell" value="1"> ग्राहकलाई भन्नुहोस् · Tell the customer <small class="muted">(one fixed line, free, only inside their 24 h window)</small></label>
              <?php endif; ?>
            <?php endif; ?>
          </form>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($open !== ''): ?>
    <aside class="panel wi-s wi-side" aria-label="Customer">
      <h2>ग्राहक · Customer</h2>
      <div class="panel-body">
        <dl>
          <dt>Name</dt><dd><?= $name !== '' ? $e($name) : '<span class="muted">not known</span>' ?></dd>
          <dt>WhatsApp</dt><dd>+<?= $e($open) ?></dd>
          <?php if ($whoWord !== ''): ?><dt>Name from</dt><dd><?= $e($whoWord) ?></dd><?php endif; ?>
          <dt>Bookings</dt><dd><?= (int) $bkTotal ?></dd>
        </dl>
        <?php if ($bk === []): ?>
          <p class="muted" style="margin:0 0 10px">No booking under this number<?= Auth::bookingScopeAdminId() !== null ? ' that you sold' : '' ?>.</p>
        <?php else: ?>
          <ul class="wi-bk" aria-label="Last bookings">
            <?php foreach ($bk as $b): ?>
              <li><b><a class="mono" href="booking-view.php?pnr=<?= urlencode((string) $b['pnr']) ?>"><?= $e($b['pnr']) ?></a> <?= admin_pill((string) $b['status']) ?></b>
                <span><?= $e((string) ($b['travel_date'] ?? '')) ?> · <?= $e($b['from_label'] . ' → ' . $b['to_label']) ?></span>
                <span class="muted"><?= (int) $b['pax'] ?> pax<?= (string) ($b['seat_labels'] ?? '') !== '' ? ' · ' . $e((string) $b['seat_labels']) : '' ?> · <?= $e(inr((float) $b['total_amount'])) ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <p class="wi-inline" style="margin:10px 0 0"><a class="btn btn-ghost btn-sm" href="customers.php?q=<?= $e($local) ?>">Customer 360 →</a></p>
      </div>
    </aside>
    <?php endif; ?>
  </div>
  <script>
  (function () {
    var chat = document.getElementById('wiChat');
    if (!chat) { return; }
    chat.scrollTop = chat.scrollHeight;
    var phone = chat.getAttribute('data-p'), q = chat.getAttribute('data-q') || '', sig = chat.getAttribute('data-sig');
    var canVote = chat.getAttribute('data-vote') === '1';
    var form = document.getElementById('wiReply'), text = document.getElementById('wiText'), say = document.getElementById('wiSay');
    var send = document.getElementById('wiSend'), tok = form ? form.querySelector('input[name="<?= $e(CSRF_TOKEN_NAME) ?>"]') : null;
    var sayT = 0;
    function sayIt(msg, bad) {
      if (!say) { return; }
      say.textContent = msg || '';
      say.style.color = bad ? 'var(--bad,#b91c1c)' : 'var(--ok,#0a6b3b)';
      clearTimeout(sayT); sayT = setTimeout(function () { say.textContent = ''; }, 3000);
    }
    function hidden(f, n, v) { var i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); }
    function voteForm(m) {
      var f = document.createElement('form'); f.method = 'post'; f.className = 'wi-vote';
      if (tok) { hidden(f, tok.name, tok.value); }
      hidden(f, 'action', 'verdict'); hidden(f, 'phone', phone); hidden(f, 'msg', String(m.id));
      [['up', '👍', 'Good answer'], ['down', '👎', 'Wrong or weak answer']].forEach(function (b) {
        var x = document.createElement('button'); x.type = 'submit'; x.name = 'v'; x.value = b[0]; x.textContent = b[1];
        x.title = b[2]; x.setAttribute('aria-label', b[2]); if (m.v === b[0]) { x.className = 'on'; } f.appendChild(x);
      });
      return f;
    }
    function bubble(m) {
      var d = document.createElement('div'); d.className = 'wi-msg ' + (m.dir === 'in' ? 'in' : 'out');
      d.appendChild(document.createTextNode(m.text));
      var s = document.createElement('small'); s.textContent = m.meta;
      if (m.vote && canVote) { s.appendChild(voteForm(m)); }
      d.appendChild(s); return d;
    }
    function post(fd) {
      fd.set('ajax', '1');
      return fetch('wa-inbox.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); });
    }
    /* reply without a reload — the same server guard (24 h window) and audit */
    if (form) {
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (!text.value.trim()) { sayIt('Write a message first. / सन्देश लेख्नुहोस्।', true); return; }
        send.disabled = true;
        post(new FormData(form)).then(function (j) {
          sayIt(j.message, !j.ok);
          if (j.ok) { if (j.bubble) { chat.appendChild(bubble(j.bubble)); chat.scrollTop = chat.scrollHeight; } text.value = ''; }
        }).catch(function () { sayIt('Network problem — nothing was sent. Try again.', true); })
          .then(function () { send.disabled = false; });
      });
      form.querySelectorAll('[data-body]').forEach(function (b) {
        b.addEventListener('click', function () { text.value = b.getAttribute('data-body'); text.focus(); });
      });
      var sug = document.getElementById('wiSuggest');
      if (sug) {
        sug.addEventListener('click', function () {
          sug.disabled = true;
          fetch('wa-inbox.php?ajax=suggest&p=' + encodeURIComponent(phone), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.ok && j.text) { text.value = j.text; text.focus(); } sayIt(j.message, !j.ok); })
            .catch(function () { sayIt('Could not get a suggestion.', true); })
            .then(function () { sug.disabled = false; });
        });
      }
    }
    /* 👍 / 👎 on a bot reply */
    chat.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('.wi-vote button') : null;
      if (!b) { return; }
      ev.preventDefault();
      var f = b.form, fd = new FormData(f);
      fd.set('v', b.value);
      if (b.value === 'down') {
        var n = window.prompt('What was wrong with this answer? (optional) / के गलत थियो? (ऐच्छिक)', '');
        if (n === null) { return; }
        fd.set('note', n);
      }
      post(fd).then(function (j) {
        sayIt(j.message, !j.ok);
        if (j.ok) { f.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); }); }
      }).catch(function () { sayIt('Network problem — not saved.', true); });
    });
    /* the open chat refreshes every 20 s while this tab is visible */
    function poll() {
      if (document.visibilityState !== 'visible') { return; }
      fetch('wa-inbox.php?ajax=thread&p=' + encodeURIComponent(phone) + (q ? '&q=' + encodeURIComponent(q) : ''),
        { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok || j.sig === sig) { return; }
          sig = j.sig;
          var low = chat.scrollHeight - chat.scrollTop - chat.clientHeight < 60;
          chat.textContent = '';
          j.items.forEach(function (m) { chat.appendChild(bubble(m)); });
          if (low) { chat.scrollTop = chat.scrollHeight; }
        }).catch(function () {});
    }
    setInterval(poll, 20000);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { poll(); } });
  })();
  </script>

<?php elseif ($tab === 'sent'):
    $sent = WaInbox::sent(7, $q);
    $stCls = ['sent' => 'ai', 'failed' => 'needed', 'queued' => 'agent', 'skipped' => 'agent'];
?>
  <form class="wi-find" method="get" role="search">
    <input type="hidden" name="tab" value="sent">
    <input type="search" name="q" value="<?= $e($q) ?>" maxlength="60" placeholder="नम्बर वा शब्द · Number or words" aria-label="Search sent messages">
    <button class="btn btn-ghost" type="submit">खोज्नुहोस् · Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="?tab=sent">✕ Clear</a><?php endif; ?>
  </form>
  <div class="panel"><h2>पठाइएको · Sent on WhatsApp — last 7 days (<?= count($sent) ?>)</h2>
    <div class="dt-wrap"><table class="dt card-table">
      <thead><tr><th>When</th><th>To</th><th>What</th><th>Status</th><th>Message</th><th></th></tr></thead>
      <tbody>
      <?php if ($sent === []): ?><tr><td colspan="6" class="muted">Nothing sent<?= $q !== '' ? ' that matches' : '' ?>.</td></tr><?php endif; ?>
      <?php foreach ($sent as $m): $tn = preg_replace('/\D/', '', $m['to']) ?? ''; ?>
        <tr>
          <td data-label="When"><?= $e(substr($m['at'], 0, 16)) ?></td>
          <td data-label="To">+<?= $e($tn) ?></td>
          <td data-label="What"><?= $e($m['purpose'] !== '' ? $m['purpose'] : 'message') ?></td>
          <td data-label="Status"><span class="wi-st <?= $e($stCls[$m['status']] ?? 'agent') ?>"><?= $e($m['status']) ?></span><?= $m['error'] !== '' ? '<br><span class="muted">' . $e(mb_substr($m['error'], 0, 120)) . '</span>' : '' ?></td>
          <td data-label="Message" class="wrap"><?= $e(mb_substr($m['body'], 0, 220)) ?></td>
          <td data-label=""><?php if (strlen($tn) >= 10): ?><a class="btn btn-sm btn-ghost" href="?tab=inbox&amp;p=<?= $e(WaInbox::intl($tn)) ?>">Open chat</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

<?php elseif ($tab === 'contacts'):
    $contacts = WaInbox::contacts();
    $roleLbl = ['admin' => ['Office', 'st-ok'], 'agent' => ['Agent', 'st-ok'], 'staff' => ['Staff', 'st-ok'], 'customer' => ['Passenger', ''], 'unknown' => ['Unknown', 'st-muted']];
?>
  <div class="panel"><h2>Who wrote in the last 30 days (<?= count($contacts) ?>)</h2>
    <div class="dt-wrap"><table class="dt card-table">
      <thead><tr><th>Number</th><th>Role</th><th>Name</th><th>Bookings</th><th>Messages</th><th>Last message</th><th>Marketing</th><th></th></tr></thead>
      <tbody>
      <?php if ($contacts === []): ?><tr><td colspan="8" class="muted">Nobody yet.</td></tr><?php endif; ?>
      <?php foreach ($contacts as $c): $rl = $roleLbl[$c['role']] ?? [$c['role'], '']; ?>
        <tr>
          <td data-label="Number">+<?= $e($c['digits']) ?></td>
          <td data-label="Role"><span class="pill <?= $rl[1] ?>"><?= $e($rl[0]) ?></span></td>
          <td data-label="Name"><?= $e($c['name']) ?></td>
          <td data-label="Bookings"><?= (int) $c['bookings'] ?></td>
          <td data-label="Messages"><?= (int) $c['msgs'] ?></td>
          <td data-label="Last message"><?= $e(substr($c['last_at'], 0, 16)) ?></td>
          <td data-label="Marketing"><?= $c['optout'] ? '<span class="pill st-warn">opted out</span>' : 'ok' ?></td>
          <td data-label=""><a class="btn btn-sm btn-ghost" href="?tab=inbox&amp;p=<?= $e($c['digits']) ?>">Open chat</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

<?php else:
    $groups = WaGroup::groups();
    $seen   = WaGroup::seen();
    foreach ($seen as $s) {
        $gid = (string) $s['group_id'];
        if (!isset($groups[$gid])) {
            $groups[$gid] = ['name' => '', 'reply' => false];
        }
    }
    $recent = WaGroup::recent(40);
    $names  = array_map(static fn(array $g): string => $g['name'], $groups);
?>
  <div class="panel"><h2>S HARI AI in the office groups</h2>
    <div class="panel-body ab-uses">
      <p class="muted" style="margin:0">The AI reads every message in a group the office number is part of (groups made with Meta's Groups API), and answers <b>only</b>
        when someone calls it (<code>@S Hari AI</code>, <code>AI बताउ</code>), asks a timetable / seats / office / refund-rule question, or — if you allow it —
        states a pickup time the timetable contradicts. Never greetings, jokes or personal talk; never anyone's booking, number or money.
        Status: <?= WaGroup::replyOn() ? '<span class="pill st-ok">replies ON</span>' : '<span class="pill st-muted">replies OFF — listening only</span>' ?></p>
      <?php if ($canSet): ?>
      <form method="post" class="wi-g" style="display:grid;gap:10px">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="groups">
        <label class="wi-inline"><input type="checkbox" name="wa_group_reply_on" value="1" <?= WaGroup::replyOn() ? 'checked' : '' ?>> Let S HARI AI reply in the groups ticked below</label>
        <label class="wi-inline"><input type="checkbox" name="wa_group_correct_on" value="1" <?= WaGroup::correctOn() ? 'checked' : '' ?>> Correct a wrong pickup time ("⚠️ सच्याउँछु: Surat pickup 2 baje → 13:00")</label>
        <div class="wi-inline">Quiet hours — no replies from
          <input type="text" name="wa_group_quiet_from" value="<?= $e(Settings::getString('wa_group_quiet_from', '22:00')) ?>" style="width:80px" pattern="([01]\d|2[0-3]):[0-5]\d">
          to <input type="text" name="wa_group_quiet_to" value="<?= $e(Settings::getString('wa_group_quiet_to', '06:00')) ?>" style="width:80px" pattern="([01]\d|2[0-3]):[0-5]\d"></div>
        <label>Extra words that call the AI (comma separated, optional)
          <input type="text" name="wa_group_mention_words" value="<?= $e(Settings::getString('wa_group_mention_words', '')) ?>" placeholder="sahayak, hari bot"></label>
        <div class="dt-wrap"><table class="dt card-table">
          <thead><tr><th>Group id</th><th>Name</th><th>Reply here</th></tr></thead>
          <tbody>
          <?php if ($groups === []): ?><tr><td colspan="3" class="muted">No group has written yet. A group appears here by itself after its first message, or add its id below.</td></tr><?php endif; ?>
          <?php $i = 0; foreach ($groups as $gid => $g): ?>
            <tr>
              <td data-label="Group id"><code><?= $e($gid) ?></code><input type="hidden" name="g_id[<?= $i ?>]" value="<?= $e($gid) ?>"></td>
              <td data-label="Name"><input type="text" name="g_name[<?= $i ?>]" value="<?= $e($g['name']) ?>" maxlength="60" placeholder="S Hari Office"></td>
              <td data-label="Reply here"><select name="g_reply[<?= $i ?>]"><option value="0">Listen only</option><option value="1" <?= $g['reply'] ? 'selected' : '' ?>>Reply</option></select></td>
            </tr>
          <?php $i++; endforeach; ?>
          </tbody>
        </table></div>
        <div class="wi-inline"><input type="text" name="new_id" placeholder="Add a group id" style="max-width:260px"><input type="text" name="new_name" placeholder="Name" style="max-width:200px"></div>
        <p><button class="btn" type="submit">Save</button></p>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($canSet): ?>
  <div class="panel"><h2>Other S HARI AI switches</h2>
    <div class="panel-body">
      <form method="post" style="display:grid;gap:10px">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="switches">
        <label class="wi-inline"><input type="checkbox" name="wa_pay_ocr_on" value="1" <?= Settings::getBool('wa_pay_ocr_on', false) ? 'checked' : '' ?>>
          Read payment screenshots on WhatsApp — UTR + amount go to <b>Verify Payments</b> for a person to confirm (needs the Gemini key; never confirms by itself)</label>
        <label class="wi-inline"><input type="checkbox" name="wa_onboard_on" value="1" <?= Settings::getBool('wa_onboard_on', false) ? 'checked' : '' ?>>
          Send new agents one WhatsApp lesson a day for 7 days (free messages only; needs the cron line in docs/UPGRADE-2026-10-29-shari-ai-master.md)</label>
        <label class="wi-inline"><input type="checkbox" name="data_retention_on" value="1" <?= Settings::getBool('data_retention_on', false) ? 'checked' : '' ?>>
          Delete old logs: AI tool log after <?= max(30, Settings::getInt('ai_log_keep_days', 90)) ?> days, WhatsApp message logs after <?= max(90, Settings::getInt('wa_log_keep_days', 365)) ?> days (bookings are never touched)</label>
        <p><button class="btn" type="submit">Save</button></p>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <div class="panel"><h2>Latest group messages</h2>
    <div class="dt-wrap"><table class="dt card-table">
      <thead><tr><th>When</th><th>Group</th><th>From</th><th>Message</th><th>S HARI AI</th></tr></thead>
      <tbody>
      <?php if ($recent === []): ?><tr><td colspan="5" class="muted">Nothing yet.</td></tr><?php endif; ?>
      <?php foreach ($recent as $r): $gid = (string) $r['group_id']; ?>
        <tr>
          <td data-label="When"><?= $e(substr((string) $r['created_at'], 0, 16)) ?></td>
          <td data-label="Group"><?= $e(($names[$gid] ?? '') !== '' ? $names[$gid] : $gid) ?></td>
          <td data-label="From">+<?= $e((string) $r['from_digits']) ?></td>
          <td data-label="Message"><?= $e(mb_substr((string) ($r['body'] ?? ''), 0, 160)) ?></td>
          <td data-label="S HARI AI"><?= (int) $r['replied'] === 1 ? '✅ ' . $e((string) $r['reply_why']) . '<br><span class="muted">' . $e(mb_substr((string) ($r['reply_text'] ?? ''), 0, 160)) . '</span>' : ($r['reply_why'] ? $e((string) $r['reply_why']) : '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>
<?php
admin_footer();
