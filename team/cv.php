<?php
/**
 * /team/cv.php — "My CV" in the team portal (22 Oct 2026, owner: education
 * and experience for everyone; staff already gave theirs on the form).
 *
 *   GET            the form: education (up to 5), experience (up to 6),
 *                  languages, skills, about; a partner also fills its
 *                  company profile (registration, PAN, year, director …)
 *   POST           save (CSRF) — on the person's own staff / agent row
 *   ?print=1       a one-page CV to print or save as PDF
 *
 * A person with both a staff card and an agent card picks with ?t=staff|agent.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/teamportal.php';
require_once INCLUDE_PATH . '/teamui.php';

$me = TeamPortal::current();
if ($me === null) {
    TeamUi::go('/team/');
}
$h = static fn($v): string => Security::e((string) ($v ?? ''));
$t = (string) ($_GET['t'] ?? '');
if (!in_array($t, ['staff', 'agent'], true)) {
    $t = $me['agent'] !== null ? 'agent' : 'staff';
}
$row = $t === 'agent' ? $me['agent'] : $me['staff'];
if ($row === null) {
    TeamUi::go('/team/');
}
$org   = $t === 'agent' && (string) $row['type'] === 'org';
$id    = (int) $row['id'];
$err   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $err = 'यो पेज धेरै बेर खुला थियो — फेरि सेभ गर्नुहोस् · This page was open too long, please save again.';
    } else {
        try {
            TeamPortal::saveCv($t, $id, TeamPortal::cleanCv($_POST, $org));
            TeamUi::setFlash('ok', '✅ CV सेभ भयो · CV saved.');
            TeamUi::go('/team/cv.php?t=' . $t);
        } catch (Throwable $e) {
            Logger::exception($e);
            $err = 'सेभ हुन सकेन — फेरि प्रयास गर्नुहोस् · Could not save, please try again.';
        }
    }
}
$cv = TeamPortal::cv($row, $t);
$name = $t === 'agent' ? (string) $row['name'] : (string) $row['full_name'];
$ref  = (string) ($row['ref'] ?? '');
$role = $t === 'agent' ? ($org ? 'Pvt. Ltd. Partner · साझेदार कम्पनी' : 'Agent · एजेन्ट')
      : StaffApps::roleLabel((string) $row['role_applied']) . ' · ' . StaffApps::roleLabel((string) $row['role_applied'], true);

if (isset($_GET['print'])) {
    TeamUi::head('CV · ' . $name);
    $co = Settings::company();
    ?>
<div class="tp-card">
  <div class="tp-me" style="border-bottom:3px solid var(--orange);padding-bottom:12px;margin-bottom:6px">
    <div style="flex:1;min-width:0"><h1 style="font-size:24px;margin:0"><?= $h($name) ?></h1>
      <span class="tp-chip"><?= $h($role) ?></span> <span class="tp-chip mono"><?= $h($ref) ?></span>
      <p class="tp-sub" style="margin:6px 0 0">S Hari Global Pvt. Ltd. · <?= $h($t === 'staff' ? (($row['base_location'] ?? '') ?: ($row['district'] ?? '')) : ($row['area'] ?? '')) ?></p></div>
    <img src="/assets/img/logo-mark-96h.png" alt="" style="height:56px">
  </div>
  <?= TeamPortal::cvHtml($cv, $org) ?>
  <p class="tp-sub" style="margin-top:18px;font-size:12px">Printed <?= $h(date('d M Y')) ?> from the S Hari Global team portal · <?= $h($co['web']) ?></p>
  <div class="tp-noprint"><button class="b" type="button" onclick="window.print()">🖨️ Print / Save PDF</button><a class="b gh" href="/team/cv.php?t=<?= $h($t) ?>">← Edit</a></div>
</div>
<?php
    TeamUi::foot();
    exit;
}

TeamUi::head('My CV');
TeamUi::flash();
if ($err !== null) {
    echo '<div class="tp-msg bad">' . $h($err) . '</div>';
}
$edu = $cv['edu'];
while (count($edu) < 3) {
    $edu[] = ['level' => '', 'school' => '', 'year' => ''];
}
$exp = $cv['exp'];
while (count($exp) < 3) {
    $exp[] = ['org' => '', 'role' => '', 'years' => ''];
}
?>
<div class="tp-card">
  <h1>📄 <?= $org ? 'कम्पनी विवरण · Company profile' : 'मेरो CV · My CV' ?></h1>
  <p class="tp-sub"><?= $h($name) ?> · <?= $h($ref) ?> — कार्यालयले मात्र हेर्छ, वेबसाइटमा जाँदैन। · Only the office sees this; it is not on the website.</p>
  <?php if ($me['agent'] !== null && $me['staff'] !== null): ?>
  <p class="tp-links" style="justify-content:flex-start;margin:0 0 8px"><a href="/team/cv.php?t=staff"<?= $t === 'staff' ? ' style="text-decoration:none;color:var(--ink)"' : '' ?>>🧑‍✈️ Staff CV</a><a href="/team/cv.php?t=agent"<?= $t === 'agent' ? ' style="text-decoration:none;color:var(--ink)"' : '' ?>>👤 Agent CV</a></p>
  <?php endif; ?>
  <form method="post" action="/team/cv.php?t=<?= $h($t) ?>">
    <?= Security::csrfField() ?>
    <?php if ($org): ?>
    <h3>Company · कम्पनी</h3>
    <?php foreach (TeamPortal::ORG_FIELDS as $k => $lbl): ?>
      <label class="f" for="o_<?= $h($k) ?>"><?= $h($lbl) ?></label>
      <?php if ($k === 'services'): ?><textarea class="i" id="o_<?= $h($k) ?>" name="org[<?= $h($k) ?>]" maxlength="300" placeholder="Bus tickets, tours, cargo …"><?= $h($cv['org'][$k] ?? '') ?></textarea>
      <?php else: ?><input class="i" id="o_<?= $h($k) ?>" name="org[<?= $h($k) ?>]" maxlength="120" value="<?= $h($cv['org'][$k] ?? '') ?>"<?= $k === 'est' || $k === 'staff' ? ' inputmode="numeric"' : '' ?>><?php endif; ?>
    <?php endforeach; ?>
    <?php endif; ?>

    <h3><?= $org ? 'Director / owner education · सञ्चालकको शिक्षा' : 'Education · शिक्षा' ?></h3>
    <p class="tp-sub" style="margin:0 0 6px">Level · school / college · year</p>
    <?php foreach (array_slice($edu, 0, 5) as $i => $e): ?>
    <div class="tp-row">
      <select class="i" name="edu_level[]" aria-label="Education level <?= $i + 1 ?>"><option value="">—</option><?php foreach (TeamPortal::EDU_LEVELS as $k => $l): ?><option value="<?= $h($k) ?>"<?= $e['level'] === $k ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select>
      <input class="i" name="edu_school[]" maxlength="120" placeholder="School / college" value="<?= $h($e['school']) ?>" aria-label="School <?= $i + 1 ?>">
      <input class="i" name="edu_year[]" maxlength="4" inputmode="numeric" placeholder="Year" value="<?= $h($e['year']) ?>" aria-label="Year <?= $i + 1 ?>">
    </div>
    <?php endforeach; ?>

    <h3><?= $org ? 'Experience in travel · अनुभव' : 'Experience · अनुभव' ?></h3>
    <p class="tp-sub" style="margin:0 0 6px">Company / employer · job · years</p>
    <?php foreach (array_slice($exp, 0, 6) as $i => $e): ?>
    <div class="tp-row3">
      <input class="i" name="exp_org[]" maxlength="120" placeholder="Company / employer" value="<?= $h($e['org']) ?>" aria-label="Employer <?= $i + 1 ?>">
      <input class="i" name="exp_role[]" maxlength="80" placeholder="Job · पद" value="<?= $h($e['role']) ?>" aria-label="Job <?= $i + 1 ?>">
      <input class="i" name="exp_years[]" maxlength="4" inputmode="decimal" placeholder="Yrs" value="<?= $h($e['years']) ?>" aria-label="Years <?= $i + 1 ?>">
    </div>
    <?php endforeach; ?>

    <label class="f" for="langs">भाषा · Languages</label>
    <input class="i" id="langs" name="langs" maxlength="120" placeholder="Nepali, Hindi, Gujarati, English" value="<?= $h($cv['langs']) ?>">
    <label class="f" for="skills">सीप · Skills</label>
    <input class="i" id="skills" name="skills" maxlength="200" placeholder="<?= $t === 'staff' ? 'Heavy licence, Volvo AC, first aid …' : 'Ticketing, customer care, computer …' ?>" value="<?= $h($cv['skills']) ?>">
    <label class="f" for="about">आफ्नो बारेमा · About</label>
    <textarea class="i" id="about" name="about" maxlength="500"><?= $h($cv['about']) ?></textarea>
    <button class="b" type="submit">💾 सेभ गर्नुहोस् · Save</button>
  </form>
  <a class="b gh" href="/team/cv.php?t=<?= $h($t) ?>&amp;print=1">🖨️ CV हेर्नुहोस् / प्रिन्ट · View / print</a>
</div>
<?php
TeamUi::foot();
