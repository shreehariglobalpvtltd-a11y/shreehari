<?php
/**
 * admin/agreement-points.php — correct the agreement points customers read.
 *
 * Owner, 2 Oct 2026: "agreement ma bhayeko kura live customer ma dekhinchha,
 * tara tyaslai admin panel bata point haru sachauna, edit garna milne khalko
 * hunu paryo."
 *
 * One screen per agreement (Agent / Partner – Pvt Ltd) and language
 * (नेपाली / हिन्दी / English / ગુજરાતી): every point of the live page with its
 * wording in a box. Change the words, hide a point, put it back to the
 * original, or add a new point under a section. Saved changes reach
 * /agreements/ within a minute (includes/agreementtext.php,
 * assets/js/agreement-live.js). The four languages are separate texts — a
 * correction in Nepali does not change the Hindi page.
 *
 * Viewing: dashboard.view. Saving: super-admin or settings.manage. Every
 * change is in the activity log.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agreementtext.php';
$admin = admin_boot('dashboard.view');

$canEdit = Auth::isSuperadmin() || Auth::can('settings.manage');
$doc  = (string) ($_GET['doc'] ?? 'agent');
$lang = (string) ($_GET['lang'] ?? 'ne');
if (!isset(AgreementText::DOCS[$doc]))  { $doc = 'agent'; }
if (!isset(AgreementText::LANGS[$lang])) { $lang = 'ne'; }
$self  = '/admin/agreement-points.php?' . http_build_query(['doc' => $doc, 'lang' => $lang]);
$flash = null;
$ready = AgreementText::available();

/* ---- Save ------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canEdit) {
        $flash = ['bad', 'Only a super-admin (or staff with settings.manage) can change the agreement.'];
    } elseif (!$ready) {
        $flash = ['bad', 'The agreement_points table is missing — run database/upgrade-2026-10-02-agreement-points.sql.'];
    } else {
        $uid = (int) ($admin['id'] ?? 0);
        $errors = [];
        $before = AgreementText::publicPayload($doc, $lang)['v'];
        foreach ((array) ($_POST['t'] ?? []) as $num => $text) {
            $num = (string) $num;
            $err = AgreementText::savePoint($doc, $lang, $num, (string) $text, isset($_POST['h'][$num]), $uid);
            if ($err !== null) { $errors[] = $err; }
        }
        foreach ((array) ($_POST['a'] ?? []) as $num => $text) {
            $err = AgreementText::saveAdded($doc, $lang, (string) $num, (string) $text, isset($_POST['ar'][$num]), $uid);
            if ($err !== null) { $errors[] = $err; }
        }
        foreach ((array) ($_POST['add'] ?? []) as $sec => $new) {
            $txt = trim((string) ($new['text'] ?? ''));
            if ($txt === '') { continue; }
            $err = AgreementText::addPoint($doc, $lang, (string) $sec, (string) ($new['num'] ?? ''), $txt, $uid);
            if ($err !== null) { $errors[] = $err; }
        }
        $changed = AgreementText::publicPayload($doc, $lang)['v'] !== $before;
        if ($errors !== []) {
            $flash = ['bad', ($changed ? 'Some changes were saved, but: ' : 'Not saved: ') . implode(' · ', array_slice($errors, 0, 6))];
        } else {
            $flash = ['ok', $changed
                ? 'Saved. The live page shows the change within a minute — ' . AgreementText::LANGS[$lang] . ' only; correct the other languages too.'
                : 'Nothing changed.'];
        }
    }
}

$sections = AgreementText::original($doc, $lang);
$rows     = [];
$added    = [];
foreach (AgreementText::changes($doc, $lang) as $r) {
    if ((int) $r['is_added'] === 1) { $added[(string) $r['section']][] = $r; }
    else                            { $rows[(string) $r['num']] = $r; }
}
$nPoints = array_sum(array_map(static fn(array $s): int => count($s['points']), $sections));
$nEdited = count(array_filter($rows, static fn(array $r): bool => $r['body'] !== null && (string) $r['body'] !== ''));
$nHidden = count(array_filter($rows, static fn(array $r): bool => (int) $r['hidden'] === 1));
$nAdded  = array_sum(array_map('count', $added));

// Changes per doc / language for the switch (so the office sees what is touched where).
$touched = [];
if ($ready) {
    foreach (Database::fetchAll('SELECT doc, lang, COUNT(*) n FROM agreement_points GROUP BY doc, lang') as $t) {
        $touched[$t['doc'] . '|' . $t['lang']] = (int) $t['n'];
    }
}
$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;
$e    = static fn($v): string => Security::e((string) $v);

admin_header('Agreement points', 'agreement-points');
if ($flash !== null) { echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>'; }
?>
<style>
.ap-top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 12px}
.ap-sw{display:inline-flex;border:1.5px solid var(--line);border-radius:12px;overflow:hidden;background:var(--card)}
.ap-sw a{padding:8px 14px;font-weight:700;font-size:13px;text-decoration:none;color:inherit;display:inline-flex;gap:6px;align-items:center}
.ap-sw a + a{border-left:1.5px solid var(--line)}
.ap-sw a.on{background:#0f766e;color:#fff}
.ap-sw a small{font-size:10.5px;font-weight:800;background:#fde68a;color:#7a4a00;border-radius:999px;padding:0 6px}
.ap-stats{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
.ap-stats span{font-size:12.5px;font-weight:700;padding:4px 10px;border-radius:999px;background:var(--head);border:1px solid var(--line)}
.ap-note{font-size:12.5px;color:var(--mut);margin:0 0 14px;line-height:1.5}
.ap-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px}
.ap-tools input[type=search]{flex:1 1 240px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font-size:14px}
.ap-sec{background:var(--card);border:1px solid var(--line);border-radius:14px;margin:0 0 14px;overflow:hidden}
.ap-sec h3{margin:0;padding:11px 14px;font-size:14.5px;background:var(--head);border-bottom:1px solid var(--line);display:flex;gap:8px;align-items:center}
.ap-sec h3 .no{display:inline-flex;min-width:26px;height:26px;border-radius:8px;background:#0f766e;color:#fff;align-items:center;justify-content:center;font-size:13px}
.ap-pt{display:grid;grid-template-columns:54px minmax(0,1fr) 150px;gap:10px;padding:10px 14px;border-bottom:1px solid var(--line)}
.ap-pt:last-child{border-bottom:0}
.ap-pt .num{font:800 13px/1.2 ui-monospace,Consolas,monospace;padding-top:8px}
.ap-pt textarea{width:100%;min-height:62px;resize:vertical;padding:9px 11px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font:14px/1.5 'Noto Sans','Noto Sans Devanagari','Noto Sans Gujarati',system-ui,sans-serif}
.ap-pt textarea[readonly]{background:var(--head)}
.ap-pt.dirty textarea{border-color:#d97706;box-shadow:0 0 0 2px rgba(217,119,6,.15)}
.ap-pt.is-edited .num::after{content:"✏️";display:block;font-size:12px;margin-top:4px}
.ap-pt.is-hidden textarea{opacity:.5;text-decoration:line-through}
.ap-pt .side{display:flex;flex-direction:column;gap:6px;font-size:12.5px;padding-top:4px}
.ap-pt .side label{display:flex;gap:6px;align-items:center;font-weight:600}
.ap-pt .meta{font-size:11.5px;color:var(--mut);margin-top:4px}
.ap-pt .meta code{font-size:11px;background:var(--head);padding:0 4px;border-radius:4px}
.ap-pt details{font-size:12px;color:var(--mut);margin-top:4px}
.ap-pt details div{white-space:pre-wrap;padding:6px 8px;background:var(--head);border-radius:8px;margin-top:4px;color:var(--ink)}
.ap-pt.added{background:rgba(15,118,110,.05)}
.ap-add{padding:10px 14px}
.ap-add summary{cursor:pointer;font-weight:700;font-size:13px;color:#0f766e}
.ap-add .row{display:grid;grid-template-columns:90px minmax(0,1fr);gap:8px;margin-top:8px}
.ap-add input,.ap-add textarea{padding:8px 10px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font:14px/1.5 inherit}
.ap-save{position:sticky;bottom:0;z-index:5;display:flex;gap:10px;align-items:center;padding:10px 12px;margin-top:10px;background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:0 -6px 20px rgba(0,0,0,.08)}
.ap-save .cnt{font-size:12.5px;font-weight:700;color:#b45309}
[data-theme="dark"] .ap-sw a small{background:#4a3d00;color:#ffe28a}
@media(max-width:720px){.ap-pt{grid-template-columns:40px minmax(0,1fr)}.ap-pt .side{grid-column:2;flex-direction:row;flex-wrap:wrap}}
</style>

<div class="ap-top">
  <nav class="ap-sw" aria-label="Agreement">
    <?php foreach (AgreementText::DOCS as $d => $dl): $n = array_sum(array_map(static fn($l) => $touched[$d . '|' . $l] ?? 0, array_keys(AgreementText::LANGS))); ?>
      <a href="?<?= $e(http_build_query(['doc' => $d, 'lang' => $lang])) ?>" class="<?= $d === $doc ? 'on' : '' ?>"><?= $d === 'agent' ? '👤' : '🏢' ?> <?= $e($dl) ?><?= $n > 0 ? ' <small>' . $n . '</small>' : '' ?></a>
    <?php endforeach; ?>
  </nav>
  <nav class="ap-sw" aria-label="Language">
    <?php foreach (AgreementText::LANGS as $l => $ll): $n = $touched[$doc . '|' . $l] ?? 0; ?>
      <a href="?<?= $e(http_build_query(['doc' => $doc, 'lang' => $l])) ?>" class="<?= $l === $lang ? 'on' : '' ?>"><?= $e($ll) ?><?= $n > 0 ? ' <small>' . $n . '</small>' : '' ?></a>
    <?php endforeach; ?>
  </nav>
  <a class="btn ghost" href="<?= $e(AgreementText::url($doc, $lang)) ?>" target="_blank" rel="noopener">👁 Open the live page</a>
  <a class="btn ghost" href="/agreements/" target="_blank" rel="noopener">🌐 /agreements/</a>
  <a class="btn ghost" href="agreements.php">🤝 Applications &amp; KYC</a>
</div>

<div class="ap-stats">
  <span><?= (int) $nPoints ?> points</span>
  <span>✏️ <?= (int) $nEdited ?> corrected</span>
  <span>🙈 <?= (int) $nHidden ?> hidden</span>
  <span>＋ <?= (int) $nAdded ?> added</span>
</div>
<p class="ap-note">
  Change the words of a point and press <b>Save</b> — the page customers read (<?= $e(AgreementText::url($doc, $lang)) ?>) shows it within a minute.
  <b>{{ }}</b> marks a box people fill in on the form (₹ amount, hours, tick boxes): keep each one exactly once — you may move it in the sentence.
  Each language is its own text, so a correction made here in <b><?= $e(AgreementText::LANGS[$lang]) ?></b> must also be made in the other three.
  Nothing on this screen changes a signed paper agreement.
</p>

<?php if (!$ready): ?>
  <div class="flash bad">The <code>agreement_points</code> table is missing — run <code>database/upgrade-2026-10-02-agreement-points.sql</code>. The points below are read-only until then.</div>
<?php endif; ?>
<?php if ($sections === []): ?>
  <div class="flash bad">The page <?= $e(AgreementText::url($doc, $lang)) ?> was not found on the server.</div>
<?php else: ?>

<div class="ap-tools">
  <input type="search" id="apQ" placeholder="Find a point — a number (7.3) or any word" aria-label="Find a point" data-sync-ignore>
  <label style="display:inline-flex;gap:6px;align-items:center;font-size:13px;font-weight:600"><input type="checkbox" id="apOnly"> only corrected / hidden / added</label>
</div>

<form method="post" id="apForm">
  <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
  <?php foreach ($sections as $sec): ?>
    <section class="ap-sec" data-sec="<?= $e($sec['no']) ?>">
      <h3><?php if ($sec['no'] !== ''): ?><span class="no"><?= $e($sec['no']) ?></span><?php endif; ?><?= $e($sec['title']) ?></h3>
      <?php foreach ($sec['points'] as $p):
          $row    = $rows[$p['num']] ?? null;
          $edited = $row !== null && $row['body'] !== null && (string) $row['body'] !== '';
          $hidden = $row !== null && (int) $row['hidden'] === 1;
          $cur    = $edited ? (string) $row['body'] : $p['text'];
      ?>
        <div class="ap-pt<?= $edited ? ' is-edited' : '' ?><?= $hidden ? ' is-hidden' : '' ?>" data-num="<?= $e($p['num']) ?>" data-touched="<?= ($edited || $hidden) ? '1' : '0' ?>">
          <div class="num"><?= $e($p['num']) ?></div>
          <div>
            <textarea name="t[<?= $e($p['num']) ?>]" data-orig="<?= $e($p['text']) ?>" data-cur="<?= $e($cur) ?>" rows="3"<?= $canEdit && $ready ? '' : ' readonly' ?> aria-label="Point <?= $e($p['num']) ?>"><?= $e($cur) ?></textarea>
            <?php if ($p['keys'] !== []): ?><div class="meta">Boxes in this point: <?php foreach ($p['keys'] as $kk): ?><code>{{<?= $e($kk) ?>}}</code> <?php endforeach; ?></div><?php endif; ?>
            <?php if ($edited): ?>
              <details><summary>Original wording<?= $row['by_name'] || $row['by_user'] ? ' · changed by ' . $e($row['by_name'] ?: $row['by_user']) : '' ?> · <?= $e(date('j M Y, g:i A', strtotime((string) $row['updated_at']))) ?></summary><div><?= $e($p['text']) ?></div></details>
            <?php endif; ?>
          </div>
          <div class="side">
            <label><input type="checkbox" name="h[<?= $e($p['num']) ?>]" value="1"<?= $hidden ? ' checked' : '' ?><?= $canEdit && $ready ? '' : ' disabled' ?>> 🙈 Hide this point</label>
            <?php if ($canEdit && $ready): ?><button type="button" class="btn ghost btn-sm ap-reset" title="Put the original wording back in the box (save to apply)">↺ Original</button><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php foreach ($added[$sec['no']] ?? [] as $ar): ?>
        <div class="ap-pt added" data-num="<?= $e($ar['num']) ?>" data-touched="1">
          <div class="num"><?= $e($ar['num']) ?></div>
          <div>
            <textarea name="a[<?= $e($ar['num']) ?>]" data-cur="<?= $e($ar['body']) ?>" rows="3"<?= $canEdit && $ready ? '' : ' readonly' ?>><?= $e($ar['body']) ?></textarea>
            <div class="meta">＋ Added by the office<?= $ar['by_name'] || $ar['by_user'] ? ' (' . $e($ar['by_name'] ?: $ar['by_user']) . ')' : '' ?> · <?= $e(date('j M Y', strtotime((string) $ar['created_at']))) ?></div>
          </div>
          <div class="side">
            <label><input type="checkbox" name="ar[<?= $e($ar['num']) ?>]" value="1"<?= $canEdit && $ready ? '' : ' disabled' ?>> 🗑 Remove</label>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if ($canEdit && $ready && $sec['no'] !== ''):
          // Next free number under this section, e.g. after 1.5 → 1.6.
          $max = 0;
          foreach (array_merge(array_column($sec['points'], 'num'), array_column($added[$sec['no']] ?? [], 'num')) as $nn) {
              if (preg_match('~^' . preg_quote($sec['no'], '~') . '\.(\d+)$~', (string) $nn, $mm)) { $max = max($max, (int) $mm[1]); }
          }
      ?>
        <details class="ap-add">
          <summary>＋ Add a point to section <?= $e($sec['no']) ?></summary>
          <div class="row">
            <input name="add[<?= $e($sec['no']) ?>][num]" value="<?= $e($sec['no'] . '.' . ($max + 1)) ?>" aria-label="Number">
            <textarea name="add[<?= $e($sec['no']) ?>][text]" rows="3" placeholder="The new point, in <?= $e(AgreementText::LANGS[$lang]) ?>"></textarea>
          </div>
        </details>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <?php if ($canEdit && $ready): ?>
  <div class="ap-save">
    <button class="btn ok" type="submit">💾 Save changes</button>
    <span class="cnt" id="apDirty"></span>
    <span class="muted" style="font-size:12px">Only what you changed is saved. Each change is in the activity log.</span>
  </div>
  <?php endif; ?>
</form>
<?php endif; ?>

<script>
(function(){
  var form = document.getElementById('apForm'); if (!form) return;
  var cnt = document.getElementById('apDirty');
  function grow(t){ t.style.height = 'auto'; t.style.height = Math.min(600, t.scrollHeight + 4) + 'px'; }
  function dirty(pt){
    var t = pt.querySelector('textarea'), h = pt.querySelector('input[name^="h["]'), r = pt.querySelector('input[name^="ar["]');
    var d = (t && t.value !== (t.getAttribute('data-cur') || '')) || (h && h.checked !== h.defaultChecked) || (r && r.checked);
    pt.classList.toggle('dirty', !!d);
    pt.classList.toggle('is-hidden', !!(h && h.checked));
    return d;
  }
  function count(){
    var n = 0;
    form.querySelectorAll('.ap-pt').forEach(function(pt){ if (dirty(pt)) n++; });
    form.querySelectorAll('.ap-add textarea').forEach(function(t){ if (t.value.trim() !== '') n++; });
    if (cnt) cnt.textContent = n ? n + ' unsaved change' + (n === 1 ? '' : 's') : '';
    window.__apDirty = n > 0;
  }
  form.querySelectorAll('textarea').forEach(function(t){ grow(t); t.addEventListener('input', function(){ grow(t); count(); }); });
  form.addEventListener('change', count);
  form.querySelectorAll('.ap-reset').forEach(function(b){
    b.addEventListener('click', function(){
      var pt = b.closest('.ap-pt'), t = pt.querySelector('textarea'), h = pt.querySelector('input[name^="h["]');
      t.value = t.getAttribute('data-orig') || ''; grow(t); if (h) h.checked = false; count();
    });
  });
  form.addEventListener('submit', function(){ window.__apDirty = false; });
  window.addEventListener('beforeunload', function(e){ if (window.__apDirty) { e.preventDefault(); e.returnValue = ''; } });

  /* Find + "only changed" */
  var q = document.getElementById('apQ'), only = document.getElementById('apOnly');
  function filter(){
    var needle = (q.value || '').trim().toLowerCase(), o = only.checked;
    document.querySelectorAll('.ap-sec').forEach(function(sec){
      var shown = 0;
      sec.querySelectorAll('.ap-pt').forEach(function(pt){
        var t = pt.querySelector('textarea');
        var ok = (!o || pt.getAttribute('data-touched') === '1' || pt.classList.contains('dirty'))
              && (!needle || pt.getAttribute('data-num').indexOf(needle) === 0 || (t && t.value.toLowerCase().indexOf(needle) >= 0));
        pt.style.display = ok ? '' : 'none'; if (ok) shown++;
      });
      sec.style.display = (shown || (!needle && !o)) ? '' : 'none';
    });
  }
  q.addEventListener('input', filter); only.addEventListener('change', filter);
  count();
})();
</script>
<?php
admin_footer();
