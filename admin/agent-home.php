<?php
/**
 * admin/agent-home.php — मेरो हिसाब · the agent's wallet-style home (26 Nov 2026, fx1).
 *
 * One phone screen for an agent's whole day: the Book-ticket button, what is
 * due to the office, the credit limit used, today and this month, the last
 * five tickets and the shortcuts to the pages that already exist.
 *
 * READ-ONLY. There is no POST handler here and no money logic: every figure
 * comes from AgentReport::home(), which only repeats what AgentWallet
 * (headcount, netPosition), AgentStatement (the month's statement) and
 * AgentCredit (limit / hold) already say - so this page and the statement can
 * never show two different numbers.
 *
 * WHO SEES WHAT - the same rule as admin/agent.php:
 *   - an agent (admins.role = 'agent') sees only their own figures;
 *     Auth::bookingScopeAdminId() pins them to self whatever ?agent= says;
 *   - an office user needs commissions.view; with dashboard.view they may open
 *     a real agent with ?agent=<id> (supervision). Without one picked they get
 *     the "this is the agent page" notice and the agent picker.
 *
 * Switch: agent_home_on (bool, default 1). Off = this page forwards to
 * admin/agent.php and nothing else changes.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
// Office viewers need the commissions permission — an agent sees their own
// home, but counter/support roles must not read other sellers' money.
if (!Auth::isCounterAgent()) { Auth::requireAdmin('commissions.view'); }
require_once INCLUDE_PATH . '/agentreport.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentstatement.php';
require_once INCLUDE_PATH . '/agentcredit.php';

$e            = static fn($v): string => Security::e((string) $v);
$selfId       = (int) ($admin['id'] ?? 0);
$isAgent      = Auth::isCounterAgent();
$isSupervisor = Auth::can('dashboard.view');
$viewId       = $isAgent ? $selfId : 0;

// A supervisor may look at a real agent's home; nobody else can pass ?agent=.
if (!$isAgent && $isSupervisor && isset($_GET['agent']) && (int) $_GET['agent'] > 0 && AgentWallet::isAgentRow((int) $_GET['agent'])) {
    $viewId = (int) $_GET['agent'];
}
// An agent's own-book scope wins even if a custom permission grants dashboard access.
$scopeId = Auth::bookingScopeAdminId();
if ($scopeId !== null) { $viewId = $scopeId; }
$isOwn = $viewId > 0 && $viewId === $selfId;

if (!Settings::getBool('agent_home_on', true)) {
    Response::redirect('admin/agent.php' . (!$isOwn && $viewId > 0 ? '?agent=' . $viewId : ''));
}

/* ---- office user with no agent picked: say what this page is ---------- */
if ($viewId <= 0) {
    $agentList = $isSupervisor
        ? Database::fetchAll("SELECT id, username, full_name FROM admins WHERE role = 'agent' AND is_active = 1 ORDER BY full_name, username")
        : [];
    admin_header('एजेन्टको हिसाब · Agent home', 'agent-home');
    echo '<div class="panel" style="padding:16px 18px">'
       . '<p style="margin:0 0 6px;font-weight:800;font-size:16px">यो एजेन्टको पेज हो · This is the agent page</p>'
       . '<p class="muted" style="margin:0 0 12px;font-size:13.5px">एजेन्टले आफ्नो फोनमा यही देख्छ — आजको टिकट, कमिसन र अफिसलाई तिर्न बाँकी। '
       . 'An agent sees this on the phone: today, commission and what is due to the office.</p>';
    if ($agentList !== []) {
        echo '<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
           . '<label for="ahPick" style="font-size:13px;font-weight:700">एजेन्ट छान्नुहोस् · Pick an agent</label>'
           . '<select id="ahPick" name="agent" onchange="this.form.submit()" style="min-height:44px;font-size:16px;padding:8px 10px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);max-width:100%">'
           . '<option value="">—</option>';
        foreach ($agentList as $a) {
            echo '<option value="' . (int) $a['id'] . '">' . $e($a['full_name'] ?: $a['username']) . '</option>';
        }
        echo '</select><noscript><button class="btn" type="submit">Open</button></noscript></form>';
    } else {
        echo '<a class="btn" href="/admin/agents.php">Agents register →</a>';
    }
    echo '</div>';
    admin_footer();
    exit;
}

$viewing = Database::fetch('SELECT id, username, full_name, is_active FROM admins WHERE id = :id', ['id' => $viewId]) ?? [];
$h       = AgentReport::home($viewId);

$net      = (float) $h['net']['net'];
$cash     = (float) $h['net']['cash'];
$comm     = (float) $h['net']['commission'];
$credit   = $h['credit'];
$blocked  = $h['refusal'] !== null;
$canBook  = $isOwn && Auth::isSellingStaff();
$q        = $isOwn ? '' : '?agent=' . $viewId;            // office links keep the agent
$isNpDesk = (string) $h['desk']['currency'] !== 'INR';
$local    = static fn(float $inr): string => SellerDay::money($inr, $h['desk']);
$office   = $h['office'];
$telHref  = 'tel:' . (preg_replace('/[^0-9+]/', '', (string) $office['call']) ?? '');
$waHref   = 'https://wa.me/' . (string) $office['wa'];
$netCls   = $net > 0.5 ? 'owes' : ($net < -0.5 ? 'credit' : 'square');
$kycText  = ['submitted' => 'KYC जाँच हुँदैछ · KYC being checked', 'rejected' => 'KYC मिलेन — फेरि पठाउनुहोस् · KYC rejected, send again'][(string) $h['kyc']]
    ?? 'KYC बाँकी · KYC pending';
$req      = is_array($h['payoutReq']) ? $h['payoutReq'] : null;
$amountOf = static function (array $b): string {
    $inr = (float) $b['total_amount'];
    return Ticket::isNprSale($b) ? Ticket::money($b, $inr) : inr($inr);
};

admin_header($isOwn ? 'मेरो हिसाब · My home' : 'एजेन्टको हिसाब · ' . (string) ($viewing['full_name'] ?: ($viewing['username'] ?? '')), 'agent-home');
echo LiveSync::anchor('agent:' . $viewId, 'pill');
// admin_header() already shows the limit / hold banner to the agent themself; an office viewer gets it here.
if (!$isOwn) { echo AgentCredit::bannerHtml($viewId); }
?>
<style>
/* agent-home: one column on a phone, colours from the theme tokens only (light + dark). */
.ah{max-width:720px;margin:0 auto}
.ah-who{display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center;margin:0 0 12px;font-size:13px;color:var(--mut)}
.ah-who b{color:var(--ink);font-size:15px}
.ah-tag{display:inline-block;padding:3px 9px;border:1px solid var(--line);border-radius:99px;background:var(--head);color:var(--ink);font-size:12px;font-weight:700}
.ah-book{display:flex;align-items:center;justify-content:center;gap:10px;min-height:60px;padding:12px 16px;margin:0 0 12px;border-radius:16px;background:var(--orange);color:#fff;font-size:19px;font-weight:800;text-decoration:none;box-shadow:0 6px 18px rgba(18,38,78,.18)}
.ah-book small{font-size:13px;font-weight:700;opacity:.92}
.ah-book.off{background:var(--head);color:var(--mut);border:1px dashed var(--line);box-shadow:none}
.ah-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 12px}
.ah-btn{display:flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:var(--ink);font-size:14px;font-weight:700;text-decoration:none;text-align:center;line-height:1.25}
.ah-btn small{display:block;font-size:11.5px;font-weight:600;color:var(--mut)}
.ah-btn.go{background:var(--navy);border-color:var(--navy);color:#fff}
.ah-kyc{display:flex;align-items:center;gap:8px;min-height:44px;padding:8px 12px;margin:0 0 12px;border-radius:12px;border:1px solid var(--orange);background:var(--card);color:var(--ink);font-size:13.5px;font-weight:700;text-decoration:none}
.ah-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin:0 0 12px}
.ah-k{font-size:12px;font-weight:700;color:var(--mut)}
.ah-v{font-size:32px;font-weight:800;line-height:1.15;font-variant-numeric:tabular-nums;color:var(--ink);overflow-wrap:anywhere}
.ah-card.owes .ah-v{color:#c0392b}.ah-card.credit .ah-v{color:#0a8b4b}
.ah-s{font-size:12.5px;color:var(--mut);margin-top:2px}
.ah-kv{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}
.ah-kv div{background:var(--head);border:1px solid var(--line);border-radius:12px;padding:10px 12px;min-width:0}
.ah-kv span{display:block;font-size:11.5px;font-weight:700;color:var(--mut)}
.ah-kv b{font-size:18px;font-variant-numeric:tabular-nums;color:var(--ink);overflow-wrap:anywhere}
.ah-bar{height:10px;border-radius:99px;background:var(--line);overflow:hidden;margin-top:6px}
.ah-bar span{display:block;height:100%;background:#0a8b4b}
.ah-bar.warn span{background:var(--orange)}.ah-bar.full span{background:#c0392b}
.ah-hold{margin-top:10px;padding:10px 12px;border-radius:12px;border:1px solid #c0392b;color:var(--ink);font-size:13px;font-weight:700}
.ah-tiles{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 12px}
.ah-tile{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;min-width:0}
.ah-tile .n{font-size:24px;font-weight:800;line-height:1.2;color:var(--ink);font-variant-numeric:tabular-nums}
.ah-tile dl{margin:8px 0 0;display:grid;grid-template-columns:1fr auto;gap:4px 8px;font-size:13px}
.ah-tile dt{color:var(--mut)}.ah-tile dd{margin:0;font-weight:800;color:var(--ink);text-align:right;font-variant-numeric:tabular-nums}
.ah-h{margin:16px 0 8px;font-size:15px;font-weight:800;color:var(--ink)}
.ah-t{display:block;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px;margin:0 0 8px;color:var(--ink);text-decoration:none}
.ah-t-top{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
.ah-t-top b{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:14px}
.ah-t-mid{font-size:13.5px;margin-top:4px;overflow-wrap:anywhere}
.ah-t-bot{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;font-size:12.5px;color:var(--mut);margin-top:4px}
.ah-t-bot b{color:var(--ink);font-size:14px}
.ah-note{font-size:12px;color:var(--mut);margin:0 0 12px}
@media(max-width:380px){.ah-v{font-size:28px}.ah-tile{padding:12px}.ah-tile .n{font-size:21px}.ah-btn{font-size:13.5px;padding:8px}}
</style>

<div class="ah">
  <p class="ah-who">
    <b><?= $e($viewing['full_name'] ?: ($viewing['username'] ?? '')) ?></b>
    <?php if ((string) $h['code'] !== ''): ?><span class="ah-tag"><?= $e($h['code']) ?></span><?php endif; ?>
    <?php if ((string) $h['rate'] !== ''): ?><span class="ah-tag">कमिसन · <?= $e($h['rate']) ?></span><?php endif; ?>
    <span><?= $e($h['dateLabel']) ?></span>
    <?php if (!$isOwn): ?><span class="ah-tag">अफिसको दृश्य · office view</span><?php endif; ?>
  </p>

  <?php if ($canBook && !$blocked): ?>
    <a class="ah-book" id="ahBook" href="/admin/smartbook.php">🎟️ टिकट काट्नुहोस् <small>Book ticket</small></a>
  <?php elseif ($canBook): ?>
    <div class="ah-book off" id="ahBook" role="status">⛔ अहिले टिकट काट्न मिल्दैन <small>Selling is stopped</small></div>
    <div class="ah-row">
      <a class="ah-btn go" href="<?= $e($telHref) ?>">📞 अफिसमा फोन<small style="color:#fff;opacity:.85"><?= $e($office['call']) ?></small></a>
      <a class="ah-btn" href="<?= $e($waHref) ?>" target="_blank" rel="noopener">💬 WhatsApp<small>अफिस · office</small></a>
    </div>
  <?php endif; ?>

  <?php if (!$h['kycOk']): ?>
    <?php if ($isOwn): ?>
      <a class="ah-kyc" id="ahKyc" href="/me/">🪪 <?= $e($kycText) ?> →</a>
    <?php else: ?>
      <div class="ah-kyc" id="ahKyc">🪪 <?= $e($kycText) ?></div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- wallet: what is due, from the ledger (AgentWallet::netPosition = the statement's closing) -->
  <div class="ah-card <?= $netCls ?>" id="ahWallet">
    <div class="ah-k"><?php if ($netCls === 'owes'): ?>अफिसलाई तिर्न बाँकी · Due to office<?php elseif ($netCls === 'credit'): ?>अफिसबाट पाउन बाँकी · Office owes you<?php else: ?>हिसाब मिलेको छ · Settled<?php endif; ?></div>
    <div class="ah-v" data-ah="net"><?= $e(inr(abs($net))) ?></div>
    <div class="ah-s"><?= $e($h['owesNe']) ?><?php if ($isNpDesk && abs($net) >= 0.5): ?> · ≈ <?= $e($local(abs($net))) ?><?php endif; ?></div>
    <div class="ah-kv">
      <div><span>हातमा नगद · Cash held</span><b data-ah="cash"><?= $e(inr(max(0.0, $cash))) ?></b></div>
      <?php if ($comm >= 0): ?>
        <div><span>कमिसन पाउन बाँकी · Commission due</span><b data-ah="commission"><?= $e(inr($comm)) ?></b></div>
      <?php else: ?>
        <div><span>अग्रिम कट्न बाँकी · Advance to recover</span><b data-ah="commission"><?= $e(inr(-$comm)) ?></b></div>
      <?php endif; ?>
    </div>
    <?php if ((float) $credit['limit'] > 0.009):
      $ahPct = max(0, min(100, (int) $credit['pct']));
      $ahBar = $credit['blocked'] ? 'full' : ($credit['warn'] ? 'warn' : ''); ?>
      <div style="margin-top:12px" id="ahLimit">
        <div class="ah-k">सीमा · Credit limit — <?= $e(inr((float) $credit['outstanding'])) ?> / <?= $e(inr((float) $credit['limit'])) ?> (<?= (int) $credit['pct'] ?>%)</div>
        <div class="ah-bar <?= $ahBar ?>" role="img" aria-label="<?= (int) $credit['pct'] ?>% of the credit limit used"><span style="width:<?= $ahPct ?>%"></span></div>
      </div>
    <?php endif; ?>
    <?php if (!empty($credit['held'])): ?>
      <div class="ah-hold" id="ahHold">⛔ अफिसले बिक्री रोकेको छ · Selling stopped by the office<?= (string) $credit['holdReason'] !== '' ? ' — ' . $e($credit['holdReason']) : '' ?></div>
    <?php endif; ?>
    <?php if ((int) $credit['overdueDays'] > 0): ?>
      <div class="ah-s" style="margin-top:8px">⏰ नगद बुझाउन <?= (int) $credit['overdueDays'] ?> दिन ढिलो · cash handover <?= (int) $credit['overdueDays'] ?> day(s) late</div>
    <?php endif; ?>
  </div>

  <!-- today / this month -->
  <div class="ah-tiles">
    <div class="ah-tile" id="ahToday">
      <div class="ah-k">आज · Today</div>
      <div class="n"><?= (int) $h['today']['tickets'] ?> टिकट · <?= (int) $h['today']['heads'] ?> जना</div>
      <dl>
        <dt>टिकट मूल्य<br><small>ticket value</small></dt><dd data-ah="today-sales"><?= $e(inr((float) $h['today']['sales'])) ?></dd>
        <dt>कमिसन<br><small>commission</small></dt><dd data-ah="today-commission"><?= $e(inr((float) $h['today']['commission'])) ?></dd>
      </dl>
    </div>
    <div class="ah-tile" id="ahMonth">
      <div class="ah-k">यो महिना · This month</div>
      <div class="n"><?= (int) $h['month']['tickets'] ?> टिकट · <?= (int) $h['month']['heads'] ?> जना</div>
      <dl>
        <dt>टिकट मूल्य<br><small>ticket value</small></dt><dd data-ah="month-sales"><?= $e(inr((float) $h['month']['sales'])) ?></dd>
        <dt>कमिसन<br><small>commission</small></dt><dd data-ah="month-commission"><?= $e(inr((float) $h['month']['commission'])) ?></dd>
      </dl>
    </div>
  </div>
  <p class="ah-note">
    टिकट र मूल्य: काटेको मितिअनुसार (बाँकी / पे-एट-बोर्डिङ सहित)। कमिसन: स्टेटमेन्टअनुसार, <?= $e(formatDate((string) $h['monthFrom'], 'j M')) ?> – <?= $e(formatDate((string) $h['date'], 'j M Y')) ?>।
    <span>Tickets by the day they were cut; commission as on the statement.</span>
    <?php if ($isNpDesk): ?><br>हिसाब INR (₹) मा राखिन्छ · the account is kept in INR<?php endif; ?>
    <?php if (!$h['balanced']): ?><br>⚠️ स्टेटमेन्ट मिलेन — अफिसलाई भन्नुहोस् · the statement does not balance, tell the office<?php endif; ?>
  </p>

  <!-- last five tickets -->
  <h2 class="ah-h">पछिल्ला ५ टिकट · Last 5 tickets</h2>
  <?php if ($h['recent'] === []): ?>
    <div class="ah-card ah-s" style="text-align:center">अहिलेसम्म टिकट छैन · No tickets yet</div>
  <?php else: foreach ($h['recent'] as $b):
    $ahSeats = trim((string) ($b['seats'] ?? ''));
    $ahSeats = $ahSeats === '' ? '—' : Seats::displayLabels(explode(' ', $ahSeats), 'sleeper', (string) ($b['booking_mode'] ?? 'sharing'), ' ');
    $ahTravel = (string) ($b['travel_date'] ?? ''); ?>
    <a class="ah-t" href="/admin/booking-view.php?pnr=<?= urlencode((string) $b['pnr']) ?>">
      <div class="ah-t-top"><b><?= $e($b['pnr']) ?></b><?= admin_pill((string) $b['status']) ?></div>
      <div class="ah-t-mid"><?= $e(($b['from_city'] ?? '—') . ' → ' . ($b['to_city'] ?? '—')) ?><?= (string) ($b['passenger'] ?? '') !== '' ? ' · ' . $e($b['passenger']) : '' ?></div>
      <div class="ah-t-bot"><span><?= $ahTravel !== '' ? $e(SellerDay::dateLabel($ahTravel)) . ' · ' : '' ?>सिट <?= $e($ahSeats) ?></span><b><?= $e($amountOf($b)) ?></b></div>
    </a>
  <?php endforeach; endif; ?>

  <!-- shortcuts: the pages that already exist -->
  <h2 class="ah-h">छिटो बाटो · Shortcuts</h2>
  <div class="ah-row">
    <a class="ah-btn" href="/admin/bookings.php<?= $q ?>">🎫 <?= $isOwn ? 'मेरा टिकट' : 'टिकट' ?><small><?= $isOwn ? 'My tickets' : 'Tickets' ?></small></a>
    <a class="ah-btn" href="/admin/agent-passengers.php<?= $q ?>">🧑‍🤝‍🧑 <?= $isOwn ? 'मेरा यात्रु' : 'यात्रु' ?><small><?= $isOwn ? 'My passengers' : 'Passengers' ?></small></a>
    <a class="ah-btn" href="/admin/agent-statement.php<?= $q ?>">📜 स्टेटमेन्ट<small>Statement · PDF</small></a>
    <?php if ($isOwn): ?>
      <a class="ah-btn" href="/me/">🪪 मेरो ID कार्ड<small>My ID card</small></a>
    <?php elseif (Auth::can('customers.view')): ?>
      <a class="ah-btn" href="/admin/agent-360.php?agent=<?= $viewId ?>">🗂️ Agent 360<small>limit · KYC · targets</small></a>
    <?php endif; ?>
    <a class="ah-btn" href="/admin/agent-sales.php<?= $q ?>">📊 बिक्री<small>Sales report</small></a>
    <a class="ah-btn" href="/admin/agent.php<?= $q ?>">💼 पूरा हिसाब<small>Full panel</small></a>
    <?php if ($req !== null): ?>
      <a class="ah-btn" id="ahPayout" href="/admin/agent.php<?= $q === '' ? '?tab=wallet' : $q . '&amp;tab=wallet' ?>#payoutRequest">⏳ भुक्तानी माग <?= $e(inr((float) $req['amount'])) ?><small>Payout requested — waiting</small></a>
    <?php elseif ($isOwn && $comm > 0.009): ?>
      <a class="ah-btn" id="ahPayout" href="/admin/agent.php?tab=wallet#payoutRequest">💸 कमिसन माग्नुहोस्<small>Request payout</small></a>
    <?php endif; ?>
    <?php if ($isOwn && !($canBook && $blocked)): ?>
      <a class="ah-btn" href="<?= $e($waHref) ?>" target="_blank" rel="noopener">💬 अफिसलाई WhatsApp<small>Help · <?= $e($office['call']) ?></small></a>
    <?php endif; ?>
  </div>
</div>
<?php
admin_footer();
