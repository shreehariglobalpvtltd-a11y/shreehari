<?php
/**
 * admin/feedback.php — what passengers said about the journey.
 *
 * First reader of the `feedback` table, which shipped in schema.sql with
 * the original build and sat unused. Ratings arrive from api/feedback.php
 * once a trip is marked arrived.
 *
 * Gated on dashboard.view: this is company-wide sentiment, not a per-sale
 * record, and there is no seller column to scope it by — the same reasoning
 * enquiries.php uses. Counter agents hold bookings.view and are deliberately
 * not given a view of every passenger's opinion of every driver.
 *
 * The one write here is the is_public toggle. Ratings are never public on
 * submission; putting a passenger's words on the website is a moderation
 * decision and it belongs to a person, not to the submitter.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$base  = '';   // root-relative: the panel must stay on the request host
$flash = null;

/* ---- Publish / unpublish a comment ------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $id  = (int) ($_POST['id'] ?? 0);
        $act = (string) ($_POST['action'] ?? '');
        if ($id > 0 && in_array($act, ['publish', 'unpublish'], true)) {
            $to = $act === 'publish' ? 1 : 0;
            Database::update('feedback', ['is_public' => $to], 'id = :id', ['id' => $id]);
            Logger::audit('feedback.visibility', 'feedback', (string) $id, null,
                ['is_public' => $to], $act . 'ed by admin #' . $admin['id']);
            $flash = ['ok', 'Review #' . $id . ' ' . ($to ? 'published' : 'hidden') . '.'];
        } else {
            $flash = ['bad', 'Unknown action.'];
        }
    }
}

/* ---- Filters ------------------------------------------------------ */
$fStars = (string) ($_GET['stars'] ?? '');
$fFrom  = (string) ($_GET['from'] ?? '');
$fTo    = (string) ($_GET['to'] ?? '');

$where  = ['1=1'];
$params = [];
if ($fStars !== '' && ctype_digit($fStars) && (int) $fStars >= 1 && (int) $fStars <= 5) {
    $where[] = 'f.rating = :st';
    $params['st'] = (int) $fStars;
}
if ($fFrom !== '') { $where[] = 'DATE(f.created_at) >= :df'; $params['df'] = $fFrom; }
if ($fTo   !== '') { $where[] = 'DATE(f.created_at) <= :dt'; $params['dt'] = $fTo; }
$sqlWhere = implode(' AND ', $where);

/* ---- Headline numbers --------------------------------------------- */
/* One pass for every aggregate: on a table that grows one row per journey
   this stays a single index scan rather than five. */
$agg = Database::fetch(
    "SELECT COUNT(*) AS n,
            AVG(f.rating)      AS avg_overall,
            AVG(f.comfort)     AS avg_comfort,
            AVG(f.punctuality) AS avg_punct,
            AVG(f.staff)       AS avg_staff,
            SUM(f.rating <= 2) AS unhappy,
            SUM(f.comment IS NOT NULL AND f.comment <> '') AS with_comment
       FROM feedback f
      WHERE {$sqlWhere}",
    $params
) ?: [];

$total   = (int) ($agg['n'] ?? 0);
$avgAll  = $agg['avg_overall'] !== null ? round((float) $agg['avg_overall'], 2) : null;
$unhappy = (int) ($agg['unhappy'] ?? 0);

/* Rating spread, for the 5→1 bars. */
$spread = [];
foreach (Database::fetchAll(
    "SELECT f.rating AS r, COUNT(*) AS n FROM feedback f WHERE {$sqlWhere} GROUP BY f.rating", $params
) as $r) {
    $spread[(int) $r['r']] = (int) $r['n'];
}

/* ---- Rows ---------------------------------------------------------- */
$rows = Database::fetchAll(
    "SELECT f.*, b.pnr, r.from_city, r.to_city, s.travel_date
       FROM feedback f
       LEFT JOIN bookings b     ON b.id = f.booking_id
       LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
       LEFT JOIN schedules s    ON s.id = bl.schedule_id
       LEFT JOIN routes r       ON r.id = s.route_id
      WHERE {$sqlWhere}
      ORDER BY f.created_at DESC
      LIMIT 300",
    $params
);

/** ★★★★☆ for a 1..5 score, or a dash when the passenger skipped it. */
function fb_stars(?int $n): string
{
    if ($n === null || $n < 1) { return '<span class="muted">—</span>'; }
    return '<span title="' . $n . ' of 5">' . str_repeat('★', $n) . str_repeat('☆', 5 - $n) . '</span>';
}

admin_header('Ratings', 'feedback');
?>

<style>
  .fb-bars{display:grid;gap:5px;margin:6px 0 0}
  .fb-bar{display:grid;grid-template-columns:44px minmax(0,1fr) 44px;gap:8px;align-items:center;font-size:12px}
  .fb-bar .t{height:9px;border-radius:5px;background:var(--line);overflow:hidden}
  .fb-bar .t i{display:block;height:100%;background:var(--blue);border-radius:5px}
  .fb-star{color:#E8A33D;letter-spacing:1px;white-space:nowrap}
  .fb-comment{max-width:420px;overflow-wrap:anywhere}
</style>

<?php if ($flash !== null): ?>
  <p class="pill" style="display:block;padding:10px 12px;background:<?= $flash[0] === 'ok' ? '#d9f5e3' : '#ffdede' ?>;color:<?= $flash[0] === 'ok' ? '#0a6b33' : '#8a1f1f' ?>">
    <?= Security::e($flash[1]) ?>
  </p>
<?php endif; ?>

<div class="dash-hero">
  <div class="hcard">
    <span>Average rating</span>
    <b><?= $avgAll === null ? '—' : number_format($avgAll, 2) ?><?= $avgAll === null ? '' : ' <small style="font-size:13px;color:var(--mut)">/ 5</small>' ?></b>
    <small><?= $total ?> rating<?= $total === 1 ? '' : 's' ?></small>
  </div>
  <div class="hcard">
    <span>Comfort</span>
    <b><?= $agg['avg_comfort'] !== null ? number_format((float) $agg['avg_comfort'], 2) : '—' ?></b>
    <small>seat &amp; cabin</small>
  </div>
  <div class="hcard">
    <span>Punctuality</span>
    <b><?= $agg['avg_punct'] !== null ? number_format((float) $agg['avg_punct'], 2) : '—' ?></b>
    <small>on time</small>
  </div>
  <div class="hcard">
    <span>Staff</span>
    <b><?= $agg['avg_staff'] !== null ? number_format((float) $agg['avg_staff'], 2) : '—' ?></b>
    <small>driver &amp; crew</small>
  </div>
  <div class="hcard">
    <span>Needs a call</span>
    <b style="color:<?= $unhappy > 0 ? 'var(--bad, #C53030)' : 'inherit' ?>"><?= $unhappy ?></b>
    <small>rated 1–2 ★</small>
  </div>
</div>

<div class="panel">
  <h2>Rating spread</h2>
  <div class="fb-bars">
    <?php for ($i = 5; $i >= 1; $i--):
      $n = $spread[$i] ?? 0;
      $pct = $total > 0 ? round($n * 100 / $total) : 0; ?>
      <div class="fb-bar">
        <span class="fb-star"><?= $i ?>★</span>
        <span class="t"><i style="width:<?= $pct ?>%"></i></span>
        <span class="muted"><?= $n ?></span>
      </div>
    <?php endfor; ?>
  </div>
  <?php if ($total === 0): ?>
    <p class="muted" style="margin-top:12px">
      No ratings yet. Passengers are asked once their trip is marked
      <b>arrived</b> — either by a staff member on Trips, or automatically if
      <code>auto_arrive_hours</code> is set in Settings (it is off by default).
    </p>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Reviews</h2>
  <form method="get" class="dt-bar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
    <label style="font-size:12.5px">Stars
      <select name="stars" style="margin-left:4px;padding:7px 9px;border:1px solid var(--line);border-radius:8px">
        <option value="">any</option>
        <?php for ($i = 5; $i >= 1; $i--): ?>
          <option value="<?= $i ?>" <?= $fStars === (string) $i ? 'selected' : '' ?>><?= $i ?> ★</option>
        <?php endfor; ?>
      </select></label>
    <label style="font-size:12.5px">From
      <input type="date" name="from" value="<?= Security::e($fFrom) ?>" style="margin-left:4px;padding:6px 8px;border:1px solid var(--line);border-radius:8px"></label>
    <label style="font-size:12.5px">To
      <input type="date" name="to" value="<?= Security::e($fTo) ?>" style="margin-left:4px;padding:6px 8px;border:1px solid var(--line);border-radius:8px"></label>
    <button type="submit" class="btn">Filter</button>
    <?php if ($fStars !== '' || $fFrom !== '' || $fTo !== ''): ?>
      <a class="btn ghost" href="<?= $base ?>/admin/feedback.php">Clear</a>
    <?php endif; ?>
  </form>

  <div class="dt-wrap">
    <table class="dt card-table" data-controls="fbCtl">
      <thead>
        <tr>
          <th>When</th><th>Passenger</th><th>Trip</th>
          <th data-type="num">Overall</th><th>Comfort</th><th>Punctual</th><th>Staff</th>
          <th>Comment</th><th data-nosort>Website</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="9" class="muted" style="text-align:center;padding:18px">Nothing to show.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $f): ?>
        <tr>
          <td data-label="When" data-sort="<?= Security::e((string) $f['created_at']) ?>"><?= Security::e(substr((string) $f['created_at'], 0, 16)) ?></td>
          <td data-label="Passenger">
            <?= Security::e((string) ($f['name'] ?: '—')) ?>
            <?php if (!empty($f['pnr'])): ?>
              <br><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $f['pnr']) ?>" style="font-size:12px"><?= Security::e((string) $f['pnr']) ?></a>
            <?php endif; ?>
          </td>
          <td data-label="Trip">
            <?= Security::e(trim((string) ($f['from_city'] ?? '')) . ' → ' . trim((string) ($f['to_city'] ?? ''))) ?>
            <?php if (!empty($f['travel_date'])): ?><br><span class="muted" style="font-size:12px"><?= Security::e((string) $f['travel_date']) ?></span><?php endif; ?>
          </td>
          <td data-label="Overall" data-sort="<?= (int) $f['rating'] ?>" class="fb-star"><?= fb_stars((int) $f['rating']) ?></td>
          <td data-label="Comfort" class="fb-star"><?= fb_stars($f['comfort'] === null ? null : (int) $f['comfort']) ?></td>
          <td data-label="Punctual" class="fb-star"><?= fb_stars($f['punctuality'] === null ? null : (int) $f['punctuality']) ?></td>
          <td data-label="Staff" class="fb-star"><?= fb_stars($f['staff'] === null ? null : (int) $f['staff']) ?></td>
          <td data-label="Comment" class="fb-comment"><?= Security::e((string) ($f['comment'] ?? '')) ?></td>
          <td data-label="Website">
            <form method="post" style="margin:0">
              <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::csrfToken() ?>">
              <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <?php if ((int) $f['is_public'] === 1): ?>
                <input type="hidden" name="action" value="unpublish">
                <button type="submit" class="btn ghost" style="font-size:12px;padding:5px 10px">🌐 Shown — hide</button>
              <?php else: ?>
                <input type="hidden" name="action" value="publish">
                <button type="submit" class="btn ghost" style="font-size:12px;padding:5px 10px">Publish</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php admin_footer(); ?>
