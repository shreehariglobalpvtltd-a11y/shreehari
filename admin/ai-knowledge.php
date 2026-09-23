<?php
/**
 * admin/ai-knowledge.php — the office curates what the assistant KNOWS.
 *
 * The WhatsApp / website assistant answers policy, FAQ and how-to questions
 * from ai_kb_articles (read by includes/aiknowledge.php, gated by ai_kb_on).
 * This screen is where the office writes and edits those answers, publishes
 * or archives them, turns the whole feature on or off, and sees the
 * questions customers asked that had NO answer yet (ai_unanswered_questions)
 * so it knows what to write next.
 *
 * The assistant never invents: only a PUBLISHED + verified article, whose
 * audience includes the asker's role, is ever shown. Publishing here is the
 * verification. Nothing on this page touches a booking, a fare or money.
 *
 * Office only (dashboard.view) — a counter agent never sees it. Writes are
 * CSRF-guarded and audited.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

require_once INCLUDE_PATH . '/aiknowledge.php';

$flash   = null;
$editing = null;                                   // the article being edited, if any
$migrated = true;

/* Is the feature schema present at all? */
try {
    Database::scalar('SELECT 1 FROM ai_kb_articles LIMIT 1', [], null);
} catch (Throwable $e) {
    $migrated = false;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $act = (string) ($_POST['action'] ?? '');
        try {
            if ($act === 'toggle_kb') {
                $on = ($_POST['to'] ?? '') === '1';
                Settings::set('ai_kb_on', $on, 'bool', 'ai');
                Logger::audit('ai_kb.toggle', 'setting', 'ai_kb_on', null, ['on' => $on], 'by admin #' . $admin['id']);
                $flash = ['ok', $on ? 'Knowledge base is ON — the assistant may now answer from it.' : 'Knowledge base is OFF.'];
            } elseif ($act === 'save') {
                $id = (int) ($_POST['id'] ?? 0) ?: null;
                $newId = AiKb::save([
                    'category'              => (string) ($_POST['category'] ?? 'company'),
                    'canonical_title'       => (string) ($_POST['title'] ?? ''),
                    'canonical_answer'      => (string) ($_POST['english'] ?? ''),
                    'english_content'       => (string) ($_POST['english'] ?? ''),
                    'nepali_content'        => (string) ($_POST['nepali'] ?? ''),
                    'hindi_content'         => (string) ($_POST['hindi'] ?? ''),
                    'roman_nepali_examples' => (string) ($_POST['roman_ne'] ?? ''),
                    'roman_hindi_examples'  => (string) ($_POST['roman_hi'] ?? ''),
                    'keywords'              => (string) ($_POST['keywords'] ?? ''),
                    'synonyms'              => (string) ($_POST['synonyms'] ?? ''),
                    'applicable_roles'      => (array) ($_POST['roles'] ?? []),
                    'source_reference'      => (string) ($_POST['source'] ?? ''),
                    'publication_status'    => (string) ($_POST['status'] ?? 'draft'),
                ], (int) $admin['id'], $id);
                Logger::audit($id ? 'ai_kb.update' : 'ai_kb.create', 'ai_kb_article', (string) $newId,
                    null, ['status' => (string) ($_POST['status'] ?? 'draft')], 'by admin #' . $admin['id']);
                $flash = ['ok', $id ? 'Article updated.' : 'Article created.'];
            } elseif ($act === 'setstate') {
                $id = (int) ($_POST['id'] ?? 0);
                $st = (string) ($_POST['state'] ?? '');
                AiKb::setState($id, $st, (int) $admin['id']);
                Logger::audit('ai_kb.setstate', 'ai_kb_article', (string) $id, null, ['state' => $st], 'by admin #' . $admin['id']);
                $flash = ['ok', 'Article is now ' . Security::e($st) . '.'];
            } elseif ($act === 'dismiss_q') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id > 0) {
                    Database::update('ai_unanswered_questions', ['status' => 'reviewed'], 'id = :id', ['id' => $id]);
                }
                $flash = ['ok', 'Marked as handled.'];
            } else {
                $flash = ['bad', 'Unknown action.'];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* Load an article into the form for editing. */
if ($migrated && isset($_GET['edit'])) {
    $editing = Database::fetch('SELECT * FROM ai_kb_articles WHERE id = :id', ['id' => (int) $_GET['edit']]);
}

$kbOn      = Settings::getBool('ai_kb_on', false);
$articles  = $migrated ? Database::fetchAll(
    "SELECT id, category, canonical_title, applicable_roles, publication_status, verification_status, version, updated_at
       FROM ai_kb_articles
      ORDER BY (publication_status = 'published') DESC, updated_at DESC LIMIT 500") : [];
$unanswered = $migrated ? Database::fetchAll(
    "SELECT id, normalized_question, language, frequency, updated_at
       FROM ai_unanswered_questions WHERE status = 'new'
      ORDER BY frequency DESC, updated_at DESC LIMIT 100") : [];

/* Form field values (edit target, or blank). */
$f = static function (string $key, string $default = '') use ($editing): string {
    return Security::e((string) ($editing[$key] ?? $default));
};
$editRoles = [];
if ($editing !== null) {
    $editRoles = json_decode((string) ($editing['applicable_roles'] ?? '[]'), true) ?: [];
}
$csrf = '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . Security::csrfToken() . '">';

admin_header('AI Knowledge', 'ai-knowledge');
?>
<style>
  .kb-note{font-size:13px;color:var(--mut);margin:0 0 14px;line-height:1.5}
  .kb-switch{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;
    padding:12px 16px;border-radius:var(--r-lg,14px);margin:0 0 16px;
    background:<?= $kbOn ? 'var(--ok-bg)' : 'var(--soft)' ?>;color:<?= $kbOn ? 'var(--ok)' : 'var(--ink)' ?>;font-weight:600}
  .kb-form label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--mut);margin:10px 0 4px}
  .kb-form input[type=text],.kb-form textarea,.kb-form select{width:100%;padding:9px 11px;border:1px solid var(--line);
    border-radius:9px;background:var(--card);color:var(--ink);font-size:14px;font-family:inherit}
  .kb-form textarea{min-height:70px;resize:vertical;line-height:1.5}
  .kb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:0 16px}
  .kb-roles{display:flex;gap:14px;flex-wrap:wrap;margin:6px 0 2px}
  .kb-roles label{display:inline-flex;gap:6px;align-items:center;text-transform:none;letter-spacing:0;font-size:13.5px;color:var(--ink);margin:0}
  .kb-roles input{width:auto}
  .kb-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
  .kb-pill{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em}
  .kb-pub{background:var(--ok-bg);color:var(--ok)} .kb-draft{background:var(--warn-bg);color:var(--warn)} .kb-arch{background:var(--soft);color:var(--mut)}
  .kb-inline{display:inline} .kb-inline .btn{padding:5px 9px;font-size:12px;min-height:32px}
</style>

<?php if ($flash !== null): ?>
  <p class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>" style="display:block;padding:10px 12px;border-radius:10px;background:<?= $flash[0] === 'ok' ? 'var(--ok-bg)' : 'var(--bad-bg)' ?>;color:<?= $flash[0] === 'ok' ? 'var(--ok)' : 'var(--bad)' ?>"><?= Security::e($flash[1]) ?></p>
<?php endif; ?>

<?php if (!$migrated): ?>
  <div class="panel"><p class="kb-note" style="color:var(--bad)">The knowledge tables are not installed yet. Apply
    <code>database/upgrade-2026-09-ai-kb.sql</code> on this server first, then reload this page.</p></div>
  <?php admin_footer(); return; ?>
<?php endif; ?>

<div class="kb-switch">
  <span><?= $kbOn
      ? 'Knowledge base is ON — the assistant answers from your published articles.'
      : 'Knowledge base is OFF — the assistant does not use these articles yet.' ?></span>
  <form method="post" class="kb-inline">
    <?= $csrf ?>
    <input type="hidden" name="action" value="toggle_kb">
    <input type="hidden" name="to" value="<?= $kbOn ? '0' : '1' ?>">
    <button type="submit" class="btn<?= $kbOn ? ' ghost' : '' ?>"><?= $kbOn ? 'Switch OFF' : 'Switch ON' ?></button>
  </form>
</div>

<div class="panel">
  <h2><?= $editing ? 'Edit answer' : 'Add an answer' ?></h2>
  <p class="kb-note">Write the answer as a person would say it. The assistant reads it back in the customer's own
    language and never adds anything you did not write. Publish only what is true today — anything not published
    stays hidden from the assistant.</p>
  <form method="post" class="kb-form">
    <?= $csrf ?>
    <input type="hidden" name="action" value="save">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

    <div class="kb-grid">
      <div>
        <label>Question / title</label>
        <input type="text" name="title" maxlength="200" required value="<?= $f('canonical_title') ?>"
               placeholder="e.g. How do I cancel and get a refund?">
      </div>
      <div>
        <label>Category</label>
        <input type="text" name="category" maxlength="80" value="<?= $f('category', 'company') ?>"
               placeholder="booking · payment · route · company …">
      </div>
    </div>

    <label>Answer — English</label>
    <textarea name="english" required placeholder="The answer, in plain English."><?= $f('english_content', (string) ($editing['canonical_answer'] ?? '')) ?></textarea>

    <div class="kb-grid">
      <div>
        <label>Answer — Nepali (optional)</label>
        <textarea name="nepali" placeholder="नेपालीमा जवाफ (नराखे अङ्ग्रेजी प्रयोग हुन्छ)"><?= $f('nepali_content') ?></textarea>
      </div>
      <div>
        <label>Answer — Hindi (optional)</label>
        <textarea name="hindi" placeholder="हिंदी में जवाब (न रखें तो अंग्रेज़ी इस्तेमाल होगी)"><?= $f('hindi_content') ?></textarea>
      </div>
    </div>

    <div class="kb-grid">
      <div>
        <label>Keywords (help matching)</label>
        <input type="text" name="keywords" value="<?= $f('keywords') ?>" placeholder="cancel refund money back">
      </div>
      <div>
        <label>Synonyms / other words</label>
        <input type="text" name="synonyms" value="<?= $f('synonyms') ?>" placeholder="firta, wapas, radda">
      </div>
    </div>

    <div class="kb-grid">
      <div>
        <label>Example phrasings — Roman Nepali</label>
        <input type="text" name="roman_ne" value="<?= $f('roman_nepali_examples') ?>" placeholder="cancel kasari garne, paisa firta">
      </div>
      <div>
        <label>Example phrasings — Roman Hindi</label>
        <input type="text" name="roman_hi" value="<?= $f('roman_hindi_examples') ?>" placeholder="cancel kaise kare, refund kaise">
      </div>
    </div>

    <label>Who may see this answer</label>
    <div class="kb-roles">
      <?php foreach (AiKb::ROLE_VOCAB as $r):
        $checked = $editing === null ? ($r === 'public') : in_array($r, $editRoles, true); ?>
        <label><input type="checkbox" name="roles[]" value="<?= $r ?>" <?= $checked ? 'checked' : '' ?>> <?= ucfirst($r) ?></label>
      <?php endforeach; ?>
    </div>

    <div class="kb-grid">
      <div>
        <label>Source / evidence (where this answer comes from)</label>
        <input type="text" name="source" maxlength="255" required value="<?= $f('source_reference', 'Office, ' . date('j M Y')) ?>">
      </div>
      <div>
        <label>Status</label>
        <select name="status">
          <?php $curSt = (string) ($editing['publication_status'] ?? 'draft');
          foreach (AiKb::STATES as $st): ?>
            <option value="<?= $st ?>" <?= $curSt === $st ? 'selected' : '' ?>><?= ucfirst($st) ?><?= $st === 'published' ? ' (visible to assistant)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="kb-actions">
      <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Add answer' ?></button>
      <?php if ($editing): ?><a class="btn ghost" href="/admin/ai-knowledge.php">Cancel / new</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="panel">
  <h2>Answers (<?= count($articles) ?>)</h2>
  <?php if ($articles === []): ?>
    <p class="kb-note">No answers yet. Add the questions customers ask most, above.</p>
  <?php else: ?>
  <div class="dt-wrap">
    <table class="dt card-table">
      <thead><tr><th>Title</th><th>Category</th><th>Audience</th><th>Status</th><th data-type="num">Ver</th><th data-nosort>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($articles as $a):
        $st = (string) $a['publication_status'];
        $cls = $st === 'published' ? 'kb-pub' : ($st === 'archived' ? 'kb-arch' : 'kb-draft');
        $roles = json_decode((string) ($a['applicable_roles'] ?? '[]'), true) ?: []; ?>
        <tr>
          <td data-label="Title" class="wrap"><?= Security::e((string) $a['canonical_title']) ?></td>
          <td data-label="Category"><?= Security::e((string) $a['category']) ?></td>
          <td data-label="Audience"><?= Security::e(implode(', ', array_map('strval', $roles))) ?></td>
          <td data-label="Status"><span class="kb-pill <?= $cls ?>"><?= Security::e($st) ?></span></td>
          <td data-label="Ver" class="num"><?= (int) $a['version'] ?></td>
          <td data-label="Actions">
            <div class="dt-acts">
              <a class="btn ghost" href="/admin/ai-knowledge.php?edit=<?= (int) $a['id'] ?>">Edit</a>
              <?php if ($st !== 'published'): ?>
                <form method="post" class="kb-inline"><?= $csrf ?><input type="hidden" name="action" value="setstate"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="state" value="published"><button class="btn" type="submit">Publish</button></form>
              <?php else: ?>
                <form method="post" class="kb-inline"><?= $csrf ?><input type="hidden" name="action" value="setstate"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="state" value="draft"><button class="btn ghost" type="submit">Unpublish</button></form>
              <?php endif; ?>
              <?php if ($st !== 'archived'): ?>
                <form method="post" class="kb-inline"><?= $csrf ?><input type="hidden" name="action" value="setstate"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="state" value="archived"><button class="btn ghost" type="submit">Archive</button></form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Questions with no answer yet (<?= count($unanswered) ?>)</h2>
  <p class="kb-note">What customers asked that the knowledge base could not answer. The most-asked are at the top —
    write those answers first. Phone numbers and booking codes are removed automatically.</p>
  <?php if ($unanswered === []): ?>
    <p class="kb-note">Nothing waiting — every recent question found an answer.</p>
  <?php else: ?>
  <div class="dt-wrap">
    <table class="dt card-table">
      <thead><tr><th>Question</th><th>Lang</th><th data-type="num">Times asked</th><th data-nosort>Action</th></tr></thead>
      <tbody>
      <?php foreach ($unanswered as $q): ?>
        <tr>
          <td data-label="Question" class="wrap"><?= Security::e((string) $q['normalized_question']) ?></td>
          <td data-label="Lang"><?= Security::e((string) $q['language']) ?></td>
          <td data-label="Times asked" class="num"><?= (int) $q['frequency'] ?></td>
          <td data-label="Action">
            <form method="post" class="kb-inline"><?= $csrf ?><input type="hidden" name="action" value="dismiss_q"><input type="hidden" name="id" value="<?= (int) $q['id'] ?>"><button class="btn ghost" type="submit">Mark handled</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
