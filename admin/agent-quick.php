<?php
/**
 * admin/agent-quick.php — the office's quick "add agent" (29 Oct 2026).
 *
 * 30 Oct 2026 (AgentPro, one form system): the office now fills the SAME
 * form the agents use, in office mode — /join.php?office=1 (main fields
 * only, WhatsApp check can be skipped, code + ID card + visiting card at
 * once). This page only:
 *   - redirects every visit (and any old bookmarked POST) to that form;
 *   - still shows the finished cards for an old "?done=AGR-…" link, so a
 *     result someone saved or shared yesterday keeps working.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/idcard.php';
$admin = admin_boot('customers.view');
$e     = static fn($v): string => Security::e((string) ($v ?? ''));

$doneRef = strtoupper(Security::clean((string) ($_GET['done'] ?? ''), 20));
$person  = null;
if ($doneRef !== '' && AgentApplications::available()) {
    try {
        $person = IdCard::person($doneRef);
    } catch (Throwable $ex) {
        $person = null;
    }
}
if ($person === null || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // The one office form. 303 so an old form POST is not re-sent.
    Response::redirect('join.php?office=1', 303);
}
$done = $_SESSION['shg_quick_done'] ?? null;
if ($done !== null && ($done['ref'] ?? '') !== $doneRef) {
    $done = null;
}

admin_header('Quick agent & ID card', 'agent-quick');
?>
<style>
.aq-wrap{max-width:980px}
.aq-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.aq-cards figure{margin:0;background:var(--head);border:1px solid var(--line);border-radius:16px;padding:12px;text-align:center}
.aq-cards img{max-width:100%;border-radius:10px;box-shadow:0 6px 18px rgba(9,27,84,.18);background:#fff}
.aq-cards figcaption{font-size:12px;color:var(--mut);margin-top:8px;font-weight:700}
.aq-cards .wide{grid-column:1 / -1}
.aq-acts{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}
.aq-acts .btn{min-height:48px;display:inline-flex;align-items:center;gap:6px}
@media(max-width:760px){.aq-cards{grid-template-columns:1fr}}
</style>

<div class="aq-wrap">
  <div class="flash ok" style="font-size:15px">✅ <b><?= $e($person['name']) ?></b> is an approved <?= $person['kind'] === 'partner' ? 'partner' : 'agent' ?><?= ($person['agentCode'] ?? '') !== '' ? ' — agent code <b>' . $e($person['agentCode']) . '</b>' : '' ?> · card <?= $e($person['ref']) ?>.
    <?php if ($done !== null && (string) ($done['login'] ?? '') !== '' && str_starts_with((string) $done['login'], 'not made')): ?><br>⚠ Login <?= $e($done['login']) ?><?php endif; ?>
    <?php if ($done !== null && !empty($done['sent'])): ?><br>📲 Sign-in details were sent to the agent on WhatsApp.<?php endif; ?></div>
  <div class="panel">
    <h2>🪪 ID card &amp; visiting card · परिचयपत्र</h2>
    <div style="padding:6px 18px 18px">
      <div class="aq-acts">
        <a class="btn ok" href="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;pdf=all">⬇ Download PDF (print-ready)</a>
        <a class="btn" href="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;png=sheet&amp;dl=1">🖼 ID card PNG</a>
        <a class="btn" href="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;png=sheet&amp;v=1&amp;dl=1">🖼 Visiting card PNG</a>
        <?php if ($done !== null && (string) ($done['hand'] ?? '') !== ''): ?><a class="btn" style="background:#25d366;color:#fff" href="<?= $e($done['hand']) ?>" target="_blank" rel="noopener">📲 Send login on WhatsApp</a><?php endif; ?>
        <a class="btn ghost" href="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>">Send card / more →</a>
      </div>
      <div class="aq-cards">
        <figure><img src="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;png=front" alt="ID card front"><figcaption>ID card · front</figcaption></figure>
        <figure><img src="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;png=back" alt="ID card back"><figcaption>ID card · back</figcaption></figure>
        <figure class="wide"><img src="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;png=front&amp;v=1" alt="Visiting card front"><figcaption>Visiting card · front</figcaption></figure>
        <figure class="wide"><img src="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;png=back&amp;v=1" alt="Visiting card back"><figcaption>Visiting card · back</figcaption></figure>
      </div>
      <div class="aq-acts">
        <a class="btn ghost" href="/admin/agreements.php?tab=apps&amp;id=<?= (int) ($done['id'] ?? (int) Database::scalar('SELECT id FROM agent_applications WHERE ref = :r', ['r' => $person['ref']], 0)) ?>#detail">Open the full record</a>
        <a class="btn" href="/join.php?office=1">＋ नयाँ सदस्य · Add member (office form)</a>
      </div>
    </div>
  </div>
</div>
<?php admin_footer();
