<?php
/**
 * =====================================================================
 *  admin/report-problem.php — "🐞 समस्या रिपोर्ट · Report a problem"
 *  (20 Nov 2026, px2. Owner: "kei bug / problem haru head office ma aos,
 *  report garna banaideu".)
 *
 *  Any signed-in agent / counter / staff writes what went wrong in two
 *  lines. It becomes a Help-desk ticket (HelpDesk::open, priority high,
 *  source admin) - so it lands in Support Inbox with a number, an owner
 *  and a due time, and the head office gets the WhatsApp alert the help
 *  desk already sends for a high-priority ticket. The page the person
 *  came from, their role and their phone model are added by the page, so
 *  nobody has to describe where they were.
 *
 *  Nothing new is stored anywhere else and there is no new table.
 *  Switch: staff_problem_report_on (default on).
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot();
require_once INCLUDE_PATH . '/helpdesk.php';

$e      = static fn($v): string => Security::e((string) $v);
$on     = Settings::getBool('staff_problem_report_on', true);
$kinds  = [
    'ticket'  => '🎫 टिकट काट्दा · Cutting a ticket',
    'wa'      => '💬 WhatsApp / टिकट गएन · Ticket not delivered',
    'pay'     => '💰 पैसा / हिसाब · Money / accounts',
    'login'   => '🔐 लगइन · Sign-in',
    'slow'    => '🐢 ढिलो / अड्कियो · Slow / stuck',
    'other'   => '❓ अरू · Something else',
];
$from   = (string) ($_POST['from'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
$from   = preg_match('~/admin/([A-Za-z0-9_\-]+\.php)~', $from, $fm) === 1 ? $fm[1] : '';
$done   = null;
$err    = '';

if ($on && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Security::requireCsrf();
    $kind = (string) ($_POST['kind'] ?? 'other');
    $kind = isset($kinds[$kind]) ? $kind : 'other';
    $text = trim(Security::clean((string) ($_POST['text'] ?? ''), 1200));
    $pnr  = strtoupper(trim(Security::clean((string) ($_POST['pnr'] ?? ''), 20)));
    if (mb_strlen($text) < 5) {
        $err = 'के भयो, दुई शब्दमा लेख्नुहोस् · Please write what happened.';
    } elseif (!Security::rateLimit('staff_problem', (string) ($admin['id'] ?? 0), 6, 3600, 3600)) {
        $err = 'धेरै रिपोर्ट भयो, केही बेरमा फेरि · Too many reports, try again in a while.';
    } else {
        // the reporter's own number when the account has one, else the office line (the desk needs a number to file under)
        $phone = preg_replace('/\D/', '', (string) ($admin['phone'] ?? '')) ?? '';
        if (strlen($phone) > 10) { $phone = substr($phone, -10); }
        if (strlen($phone) < 9) { $phone = substr(Settings::officeWhatsApp(), -10); }
        $ua  = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160);
        $msg = '🐞 APP PROBLEM · ' . preg_replace('/^\S+\s/u', '', $kinds[$kind]) . "\n"
             . $text . "\n\n"
             . 'By: ' . (string) ($admin['full_name'] ?: $admin['username']) . ' (' . (string) $admin['role'] . ' #' . (int) $admin['id'] . ')'
             . ($from !== '' ? ' · page: ' . $from : '')
             . ($pnr !== '' ? ' · PNR: ' . $pnr : '') . "\n" . 'Device: ' . $ua;
        $r = HelpDesk::open([
            'name'       => (string) ($admin['full_name'] ?: $admin['username']),
            'phone'      => $phone,
            'category'   => 'other',
            'message'    => $msg,
            'pnr'        => $pnr,
            'source'     => 'admin',
            'priority'   => 'high',
            'trusted'    => true,
            'created_by' => (int) $admin['id'],
            'ack'        => false,          // the reporter is on this screen; no WhatsApp back to them
        ]);
        if (($r['ok'] ?? false) === true) {
            $done = (string) ($r['ref'] ?? '');
            Logger::audit('staff.problem_report', 'support_ticket', $done, null, ['kind' => $kind, 'page' => $from], 'problem report from the staff app');
        } else {
            $err = 'रिपोर्ट सेभ भएन, फेरि प्रयास गर्नुहोस् · Could not save the report, please try again.';
        }
    }
}

admin_header('समस्या रिपोर्ट · Report a problem', 'report-problem');
?>
<style>
.rp{max-width:560px}
.rp-card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:18px 16px;margin:0 0 14px}
.rp-card p{margin:0 0 12px;color:var(--mut)}
.rp-kinds{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:0 0 14px}
.rp-kinds label{display:flex;align-items:center;gap:8px;min-height:54px;padding:8px 12px;border:1.5px solid var(--line);border-radius:16px;font-size:15px;font-weight:700;cursor:pointer;transition:transform .12s,border-color .15s,background .15s}
.rp-kinds label:active{transform:scale(.97)}
.rp-kinds input{position:absolute;opacity:0;pointer-events:none}
.rp-kinds label:has(input:checked){border-color:#F07C1F;background:rgba(240,124,31,.12)}
.rp textarea{width:100%;min-height:130px;padding:12px 14px;border-radius:16px;border:1.5px solid var(--line);background:transparent;color:inherit;font:inherit;font-size:18px;box-sizing:border-box}
.rp input[type=text]{width:100%;min-height:52px;padding:0 14px;border-radius:16px;border:1.5px solid var(--line);background:transparent;color:inherit;font:inherit;font-size:17px;box-sizing:border-box}
.rp label.l{display:block;font-weight:800;font-size:16px;margin:12px 0 6px}
.rp .go{width:100%;min-height:58px;margin-top:16px;border:0;border-radius:18px;background:#F07C1F;color:#fff;font:inherit;font-size:19px;font-weight:800;cursor:pointer;transition:transform .12s}
.rp .go:active{transform:scale(.97)}
.rp-ok{text-align:center;padding:26px 16px}
.rp-ok b{display:block;font-size:22px;margin:8px 0}
.rp-ok .big{font-size:54px;line-height:1}
.rp-ok a{display:inline-flex;align-items:center;justify-content:center;min-height:52px;padding:0 22px;margin-top:14px;border-radius:16px;background:#1a44c8;color:#fff;font-weight:800;text-decoration:none;font-size:17px}
@media(max-width:380px){.rp-kinds{grid-template-columns:minmax(0,1fr)}}
</style>
<div class="rp">
<?php if (!$on): ?>
  <div class="flash warn">यो सुविधा बन्द छ · Problem reports are switched off.</div>
<?php elseif ($done !== null): ?>
  <div class="rp-card rp-ok">
    <div class="big">✅</div>
    <b>हेड अफिसमा पुग्यो · Sent to head office</b>
    <p>रिपोर्ट नम्बर · Report no. <strong><?= $e($done) ?></strong><br>अफिसले हेरेर सम्पर्क गर्छ · The office will look at it and get back to you.</p>
    <a href="<?= $e(Auth::homeUrl()) ?>">🎫 टिकट काट्न फर्कनुहोस् · Back to tickets</a>
  </div>
<?php else: ?>
  <?php if ($err !== ''): ?><div class="flash err"><?= $e($err) ?></div><?php endif; ?>
  <form method="post" class="rp-card" autocomplete="off">
    <?= Security::csrfField() ?><input type="hidden" name="from" value="<?= $e($from) ?>">
    <p>के बिग्रियो? छोटकरीमा लेख्नुहोस् — सिधै हेड अफिसमा जान्छ · Tell us what went wrong — it goes straight to head office.</p>
    <div class="rp-kinds" role="radiogroup" aria-label="Kind of problem">
      <?php $first = true; foreach ($kinds as $k => $lbl): ?>
      <label><input type="radio" name="kind" value="<?= $e($k) ?>"<?= $first ? ' checked' : '' ?>> <?= $e($lbl) ?></label>
      <?php $first = false; endforeach; ?>
    </div>
    <label class="l" for="rpText">के भयो? · What happened?</label>
    <textarea id="rpText" name="text" maxlength="1200" required placeholder="जस्तै: टिकट काट्दा पैसा देखायो तर टिकट आएन · e.g. the ticket did not come after booking"><?= $e((string) ($_POST['text'] ?? '')) ?></textarea>
    <label class="l" for="rpPnr">टिकट नम्बर / PNR (भए मात्र) · Ticket no. (optional)</label>
    <input type="text" id="rpPnr" name="pnr" maxlength="20" autocapitalize="characters" value="<?= $e((string) ($_POST['pnr'] ?? '')) ?>">
    <button class="go" type="submit">📨 हेड अफिसमा पठाउनुहोस् · Send to head office</button>
  </form>
<?php endif; ?>
</div>
<?php
admin_footer();
