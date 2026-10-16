<?php
/**
 * admin/company-docs.php — the company's approved documents and knowledge
 * vault (24 Sep 2026).
 *
 * The WhatsApp assistant may only describe, and send, what is filed and
 * APPROVED here (includes/companydocs.php, gated by wa_ops_docs_on). This
 * screen is where a manager or the owner uploads a paper, classifies it
 * (type, sensitivity, audience), writes the shareable summary, approves,
 * replaces or archives it, sets a review / expiry date, and reads who has
 * opened or been sent what.
 *
 * Office roles (dashboard.view) may look; only a manager or the owner may
 * change anything; only the owner may file or approve a RESTRICTED paper.
 * Files are encrypted at rest and open only through company-doc-file.php.
 * Writes are CSRF-guarded and audited.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

require_once INCLUDE_PATH . '/companydocs.php';

$mayManage = CompanyDocs::mayManage($admin);
$migrated  = CompanyDocs::available(true);
$flash     = null;
$editing   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$mayManage) {
        $flash = ['bad', 'Only a manager or the owner may change company documents.'];
    } else {
        $act = (string) ($_POST['action'] ?? '');
        try {
            if ($act === 'toggle_docs') {
                $on = ($_POST['to'] ?? '') === '1';
                Settings::set('wa_ops_docs_on', $on, 'bool', 'ai');
                Logger::audit('company_doc.toggle', 'setting', 'wa_ops_docs_on', null, ['on' => $on], 'by admin #' . $admin['id']);
                $flash = ['ok', $on ? 'Documents vault is ON — the assistant may now search approved documents.' : 'Documents vault is OFF.'];
            } elseif ($act === 'save') {
                $id    = (int) ($_POST['id'] ?? 0) ?: null;
                $newId = CompanyDocs::save([
                    'title'       => (string) ($_POST['title'] ?? ''),
                    'doc_type'    => (string) ($_POST['doc_type'] ?? 'other'),
                    'sensitivity' => (string) ($_POST['sensitivity'] ?? 'internal'),
                    'audience'    => (array) ($_POST['audience'] ?? []),
                    'summary'     => (string) ($_POST['summary'] ?? ''),
                    'status'      => (string) ($_POST['status'] ?? 'draft'),
                    'review_at'   => (string) ($_POST['review_at'] ?? ''),
                    'expires_at'  => (string) ($_POST['expires_at'] ?? ''),
                    'notes'       => (string) ($_POST['notes'] ?? ''),
                ], $_FILES['file'] ?? null, (int) $admin['id'], $id);
                Logger::audit($id ? 'company_doc.update' : 'company_doc.create', 'company_document', (string) $newId, null, [
                    'status' => (string) ($_POST['status'] ?? 'draft'), 'sensitivity' => (string) ($_POST['sensitivity'] ?? ''),
                    'file' => isset($_FILES['file']) && (int) ($_FILES['file']['error'] ?? 4) === 0,
                ], 'by admin #' . $admin['id']);
                $flash = ['ok', $id ? 'Document updated (new version).' : 'Document filed.'];
            } elseif ($act === 'setstate') {
                $id = (int) ($_POST['id'] ?? 0);
                $st = (string) ($_POST['state'] ?? '');
                CompanyDocs::setStatus($id, $st, (int) $admin['id']);
                Logger::audit('company_doc.setstate', 'company_document', (string) $id, null, ['state' => $st], 'by admin #' . $admin['id']);
                $flash = ['ok', 'Document is now ' . Security::e($st) . '.'];
            } else {
                $flash = ['bad', 'Unknown action.'];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

if ($migrated && isset($_GET['edit'])) {
    $editing = CompanyDocs::get((int) $_GET['edit']);
    if ($editing !== null && !CompanyDocs::adminMaySee($admin, $editing)) {
        $editing = null;
    }
}

$docsOn = Settings::getBool('wa_ops_docs_on', false);
$docs   = array_values(array_filter(CompanyDocs::listAll(), static fn(array $d): bool => CompanyDocs::adminMaySee($admin, $d)));
$access = $mayManage ? CompanyDocs::recentAccess(100) : [];

$f = static function (string $key, string $default = '') use ($editing): string {
    return Security::e((string) ($editing[$key] ?? $default));
};
$editAud = $editing !== null ? (json_decode((string) ($editing['audience'] ?? '[]'), true) ?: []) : ['agent', 'counter', 'manager'];
$csrf    = '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . Security::csrfToken() . '">';

$sensPill = static function (string $s): string {
    $map = ['public' => 'cd-pub', 'internal' => 'cd-int', 'confidential' => 'cd-conf', 'restricted' => 'cd-res'];
    return '<span class="cd-pill ' . ($map[$s] ?? 'cd-int') . '">' . Security::e($s) . '</span>';
};

admin_header('Company Documents', 'company-docs');
?>
<style>
  .cd-note{font-size:13px;color:var(--mut);margin:0 0 14px;line-height:1.5}
  .cd-switch{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:12px 16px;border-radius:var(--r-lg,14px);margin:0 0 16px;
    background:<?= $docsOn ? 'var(--ok-bg)' : 'var(--soft)' ?>;color:<?= $docsOn ? 'var(--ok)' : 'var(--ink)' ?>;font-weight:600}
  .cd-form label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--mut);margin:10px 0 4px}
  .cd-form input[type=text],.cd-form input[type=date],.cd-form textarea,.cd-form select{width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font-size:14px;font-family:inherit}
  .cd-form textarea{min-height:90px;resize:vertical;line-height:1.5}
  .cd-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 16px}
  .cd-roles{display:flex;gap:14px;flex-wrap:wrap;margin:6px 0 2px}
  .cd-roles label{display:inline-flex;gap:6px;align-items:center;text-transform:none;letter-spacing:0;font-size:13.5px;color:var(--ink);margin:0}
  .cd-roles input{width:auto}
  .cd-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
  .cd-pill{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em}
  .cd-pub{background:var(--ok-bg);color:var(--ok)} .cd-int{background:var(--soft);color:var(--ink)}
  .cd-conf{background:var(--warn-bg);color:var(--warn)} .cd-res{background:var(--bad-bg);color:var(--bad)}
  .cd-st-approved{color:var(--ok);font-weight:700} .cd-st-draft{color:var(--warn);font-weight:700} .cd-st-archived{color:var(--mut)}
  .cd-inline{display:inline} .cd-inline .btn{padding:5px 9px;font-size:12px;min-height:32px}
  .cd-exp{color:var(--bad);font-weight:600}
</style>

<?php if ($flash !== null): ?>
  <p style="display:block;padding:10px 12px;border-radius:10px;background:<?= $flash[0] === 'ok' ? 'var(--ok-bg)' : 'var(--bad-bg)' ?>;color:<?= $flash[0] === 'ok' ? 'var(--ok)' : 'var(--bad)' ?>"><?= Security::e($flash[1]) ?></p>
<?php endif; ?>

<?php if (!$migrated): ?>
  <div class="panel"><p class="cd-note" style="color:var(--bad)">The documents vault tables are not installed yet. Apply
    <code>database/upgrade-2026-09-24-wa-ops-manager.sql</code> on this server first, then reload this page.</p></div>
  <?php admin_footer(); return; ?>
<?php endif; ?>

<div class="cd-switch">
  <span><?= $docsOn
      ? 'Documents vault is ON — the assistant answers from approved summaries and can send an approved file to an authorised number.'
      : 'Documents vault is OFF — the assistant does not see these documents yet.' ?></span>
  <?php if ($mayManage): ?>
  <form method="post" class="cd-inline">
    <?= $csrf ?>
    <input type="hidden" name="action" value="toggle_docs">
    <input type="hidden" name="to" value="<?= $docsOn ? '0' : '1' ?>">
    <button type="submit" class="btn<?= $docsOn ? ' ghost' : '' ?>"><?= $docsOn ? 'Switch OFF' : 'Switch ON' ?></button>
  </form>
  <?php endif; ?>
</div>

<?php if ($mayManage): ?>
<div class="panel">
  <h2><?= $editing ? 'Edit document (saves a new version)' : 'File a document' ?></h2>
  <p class="cd-note">The <b>summary</b> is the only text the assistant ever quotes — write what a person may be told, in plain words.
    The file itself is encrypted on disk and is sent only to a number whose role the sensitivity and audience allow; confidential and restricted
    papers also need the recipient's step-up verification and a confirmed yes. Identification numbers in the summary are masked automatically
    when the assistant speaks (PAN, GSTIN, CIN, Aadhaar, passport, account numbers).</p>
  <form method="post" class="cd-form" enctype="multipart/form-data">
    <?= $csrf ?>
    <input type="hidden" name="action" value="save">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

    <div class="cd-grid">
      <div>
        <label>Title</label>
        <input type="text" name="title" maxlength="200" required value="<?= $f('title') ?>" placeholder="e.g. GST registration certificate">
      </div>
      <div>
        <label>Type</label>
        <select name="doc_type">
          <?php $curT = (string) ($editing['doc_type'] ?? 'other');
          foreach (CompanyDocs::TYPES as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $curT === $k ? 'selected' : '' ?>><?= Security::e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Sensitivity</label>
        <select name="sensitivity">
          <?php $curS = (string) ($editing['sensitivity'] ?? 'internal');
          $help = ['public' => 'public — anyone, customers included', 'internal' => 'internal — our staff only', 'confidential' => 'confidential — managers and owner, verified + confirmed', 'restricted' => 'restricted — owner only, verified + confirmed'];
          foreach (CompanyDocs::SENSITIVITY as $s):
            if ($s === 'restricted' && (string) $admin['role'] !== 'superadmin') { continue; } ?>
            <option value="<?= $s ?>" <?= $curS === $s ? 'selected' : '' ?>><?= Security::e($help[$s]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <label>Who may see it (audience)</label>
    <div class="cd-roles">
      <?php foreach (CompanyDocs::AUDIENCE as $r): ?>
        <label><input type="checkbox" name="audience[]" value="<?= $r ?>" <?= in_array($r, $editAud, true) ? 'checked' : '' ?>> <?= ucfirst($r) ?></label>
      <?php endforeach; ?>
    </div>
    <p class="cd-note" style="margin-top:4px">Public / customer audience is only allowed on a <b>public</b> document.</p>

    <label>Approved summary — what the assistant may say (optional for a file, required for a text-only entry)</label>
    <textarea name="summary" maxlength="8000" placeholder="e.g. S Hari Global Pvt Ltd is registered under GST in Gujarat. The certificate is available to office staff on request."><?= $f('summary') ?></textarea>

    <div class="cd-grid">
      <div>
        <label><?= $editing && (string) ($editing['file_path'] ?? '') !== '' ? 'Replace file (leave empty to keep the current one)' : 'File (PDF, JPG, PNG, WEBP, TXT, MD, CSV, DOCX · max ' . (int) (MAX_UPLOAD_BYTES / 1048576) . ' MB)' ?></label>
        <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.md,.csv,.docx">
        <?php if ($editing && (string) ($editing['file_path'] ?? '') !== ''): ?>
          <p class="cd-note" style="margin:6px 0 0">Current: <?= Security::e((string) $editing['file_name']) ?> · <?= fileSizeLabel((int) $editing['file_size']) ?> · v<?= (int) $editing['version'] ?></p>
        <?php endif; ?>
      </div>
      <div>
        <label>Review on</label>
        <input type="date" name="review_at" value="<?= $f('review_at') ?>">
      </div>
      <div>
        <label>Expires on (assistant stops using it after this date)</label>
        <input type="date" name="expires_at" value="<?= $f('expires_at') ?>">
      </div>
    </div>

    <div class="cd-grid">
      <div>
        <label>Status</label>
        <select name="status">
          <?php $curSt = (string) ($editing['status'] ?? 'draft');
          foreach (CompanyDocs::STATES as $st): ?>
            <option value="<?= $st ?>" <?= $curSt === $st ? 'selected' : '' ?>><?= ucfirst($st) ?><?= $st === 'approved' ? ' (visible to the assistant)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Internal notes (never shown to anyone outside this screen)</label>
        <input type="text" name="notes" maxlength="500" value="<?= $f('notes') ?>" placeholder="e.g. renewed Aug 2026, original in the Mehsana office safe">
      </div>
    </div>

    <div class="cd-actions">
      <button type="submit" class="btn"><?= $editing ? 'Save new version' : 'File document' ?></button>
      <?php if ($editing): ?><a class="btn ghost" href="/admin/company-docs.php">Cancel / new</a><?php endif; ?>
    </div>
  </form>
</div>
<?php else: ?>
  <p class="cd-note">You can read the list below. Only a manager or the owner may file, approve, replace or archive a document.</p>
<?php endif; ?>

<div class="panel">
  <h2>Documents (<?= count($docs) ?>)</h2>
  <?php if ($docs === []): ?>
    <p class="cd-note">Nothing filed yet<?= $mayManage ? ' — start with the company profile, the refund policy and the luggage rules.' : '.' ?></p>
  <?php else: ?>
  <div class="dt-wrap">
    <table class="dt card-table">
      <thead><tr><th>Title</th><th>Type</th><th>Sensitivity</th><th>Audience</th><th>Status</th><th data-type="num">Ver</th><th>File</th><th>Expires</th><th data-nosort>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($docs as $d):
        $st  = (string) $d['status'];
        $aud = json_decode((string) ($d['audience'] ?? '[]'), true) ?: [];
        $exp = (string) ($d['expires_at'] ?? '');
        $expired = CompanyDocs::isExpired($d); ?>
        <tr>
          <td data-label="Title" class="wrap"><?= Security::e((string) $d['title']) ?><?php if ((string) ($d['summary'] ?? '') !== ''): ?><br><small style="color:var(--mut)"><?= Security::e(truncate(CompanyDocs::mask((string) $d['summary']), 110)) ?></small><?php endif; ?></td>
          <td data-label="Type"><?= Security::e(CompanyDocs::typeLabel((string) $d['doc_type'])) ?></td>
          <td data-label="Sensitivity"><?= $sensPill((string) $d['sensitivity']) ?></td>
          <td data-label="Audience"><?= Security::e(implode(', ', array_map('strval', $aud))) ?></td>
          <td data-label="Status"><span class="cd-st-<?= Security::e($st) ?>"><?= Security::e($st) ?></span><?= $expired ? ' <span class="cd-exp">expired</span>' : '' ?></td>
          <td data-label="Ver" class="num"><?= (int) $d['version'] ?></td>
          <td data-label="File"><?php if ((string) ($d['file_name'] ?? '') !== ''): ?><a href="/admin/company-doc-file.php?id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener"><?= Security::e((string) $d['file_name']) ?></a> <small><?= fileSizeLabel((int) $d['file_size']) ?></small><?php else: ?><span style="color:var(--mut)">text only</span><?php endif; ?></td>
          <td data-label="Expires"><?= $exp !== '' ? Security::e(formatDate($exp, 'j M Y')) : '—' ?></td>
          <td data-label="Actions">
            <?php if ($mayManage): ?>
            <div class="dt-acts">
              <a class="btn ghost" href="/admin/company-docs.php?edit=<?= (int) $d['id'] ?>">Edit</a>
              <?php if ($st !== 'approved'): ?>
                <form method="post" class="cd-inline"><?= $csrf ?><input type="hidden" name="action" value="setstate"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="state" value="approved"><button class="btn" type="submit">Approve</button></form>
              <?php else: ?>
                <form method="post" class="cd-inline"><?= $csrf ?><input type="hidden" name="action" value="setstate"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="state" value="draft"><button class="btn ghost" type="submit">Withdraw</button></form>
              <?php endif; ?>
              <?php if ($st !== 'archived'): ?>
                <form method="post" class="cd-inline"><?= $csrf ?><input type="hidden" name="action" value="setstate"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="state" value="archived"><button class="btn ghost" type="submit">Archive</button></form>
              <?php endif; ?>
            </div>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($mayManage): ?>
<div class="panel">
  <h2>Who accessed what (last <?= count($access) ?>)</h2>
  <p class="cd-note">Every search, view, send, link fetch and refusal — from WhatsApp, the staff panel and the one-time share links.</p>
  <?php if ($access === []): ?>
    <p class="cd-note">No access recorded yet.</p>
  <?php else: ?>
  <div class="dt-wrap">
    <table class="dt card-table">
      <thead><tr><th>When</th><th>Action</th><th>Document</th><th>Channel</th><th>Role</th><th>Number / staff</th><th>Result</th><th>Detail</th></tr></thead>
      <tbody>
      <?php foreach ($access as $a): ?>
        <tr>
          <td data-label="When" data-sort="<?= Security::e((string) $a['created_at']) ?>"><?= Security::e(substr((string) $a['created_at'], 0, 16)) ?></td>
          <td data-label="Action"><?= Security::e((string) $a['action']) ?></td>
          <td data-label="Document" class="wrap"><?= Security::e((string) ($a['title'] ?? ('#' . (int) $a['document_id']))) ?></td>
          <td data-label="Channel"><?= Security::e((string) $a['channel']) ?></td>
          <td data-label="Role"><?= Security::e((string) ($a['actor_role'] ?? '')) ?></td>
          <td data-label="Number / staff"><?= Security::e((string) ($a['phone'] ?? '') !== '' ? (string) $a['phone'] : ((int) ($a['actor_admin_id'] ?? 0) > 0 ? 'admin #' . (int) $a['actor_admin_id'] : '')) ?></td>
          <td data-label="Result"><?= (int) $a['ok'] === 1 ? '<span style="color:var(--ok);font-weight:700">✓</span>' : '<span style="color:var(--warn);font-weight:700">refused</span>' ?></td>
          <td data-label="Detail" class="wrap"><?= Security::e((string) ($a['detail'] ?? '')) ?><?= (string) ($a['purpose'] ?? '') !== '' ? ' <small>(' . Security::e((string) $a['purpose']) . ')</small>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
