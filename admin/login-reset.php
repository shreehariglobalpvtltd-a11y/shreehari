<?php
/**
 * admin/login-reset.php — EASY LOGIN: who signs in how, and "reset to FirstName@2026" (25 Nov 2026).
 *
 * Owner: "default password = pahilo naam@2026; user le chahe badalna milne; admin le request
 * pachhi reset garera pheri yei." Every staff account (agents, counters, staff, office - never the
 * superadmin) and every driver / conductor is listed with the code to type, and whether the
 * default still opens it. Reset = back to FirstName@2026 (the old password stops, the person's
 * open sessions / driver phones are signed out). Who: staff.manage + the credentials right
 * (Auth::mayEditCredentials). CSRF-checked, audited.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/easylogin.php';
require_once INCLUDE_PATH . '/codelogin.php';

$admin = admin_boot('staff.manage');
$me    = (int) ($admin['id'] ?? 0);
$may   = Auth::mayEditCredentials('staff.manage');
$e     = static fn($v): string => Security::e((string) ($v ?? ''));
$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['err', 'यो पेज धेरै बेर खुला थियो — फेरि प्रयास गर्नुहोस्।'];
    } elseif (!$may) {
        $flash = ['err', 'यो काम मालिक (superadmin) ले मात्र गर्न मिल्छ · Only the owner can reset logins.'];
    } else {
        try {
            $kind = (string) ($_POST['kind'] ?? '');
            $id   = (int) ($_POST['id'] ?? 0);
            if ($kind === 'admin') {
                if ($id === $me) {
                    throw new RuntimeException('आफ्नै खाता यहाँबाट रिसेट हुँदैन · Not your own account.');
                }
                $pw = EasyLogin::resetAdmin($id, $me);
            } elseif ($kind === 'driver') {
                $pw = EasyLogin::resetDriver($id, $me);
            } else {
                throw new RuntimeException('Unknown account.');
            }
            $flash = ['ok', 'रिसेट भयो। अब पासवर्ड: ' . $pw . ' · Reset done. Password is now: ' . $pw];
        } catch (Throwable $ex) {
            $flash = ['err', $ex instanceof RuntimeException ? $ex->getMessage() : 'Reset failed.'];
        }
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$hasDef = static function (string $t): bool {
    try { return (int) Database::scalar('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c', ['t' => $t, 'c' => 'pw_is_default'], 0) > 0; } catch (Throwable) { return false; }
};
$admins = Database::fetchAll(
    "SELECT id, username, full_name, phone, role, is_active" . ($hasDef('admins') ? ', pw_is_default' : '') . "
       FROM admins WHERE role <> 'superadmin' AND is_active = 1 ORDER BY role, full_name LIMIT 600"
);
$drivers = [];
try {
    $drivers = Database::fetchAll(
        "SELECT id, full_name, phone, role, is_active" . ($hasDef('drivers') ? ', pw_is_default' : '') . ", (pin_hash IS NOT NULL) AS has_pin
           FROM drivers WHERE is_active = 1 ORDER BY full_name LIMIT 400"
    );
} catch (Throwable) {
}
$match = static function (array $r) use ($q): bool {
    if ($q === '') {
        return true;
    }
    $hay = mb_strtolower(implode(' ', [$r['full_name'] ?? '', $r['username'] ?? '', $r['phone'] ?? '', $r['code'] ?? '']));
    return str_contains($hay, mb_strtolower($q));
};
foreach ($admins as &$a) {
    $a['code'] = CodeLogin::codeFor((int) $a['id']);
}
unset($a);
$admins  = array_values(array_filter($admins, $match));
$drivers = array_values(array_filter($drivers, $match));
$typed = static function (string $code): string {
    if (preg_match('/^SHG-S-0*(\d+)$/', $code, $m) === 1) {
        return $m[1] . ' (स्टाफ)';
    }
    if (preg_match('/^SHG-0*(\d+)$/', $code, $m) === 1) {
        return $m[1];
    }
    return $code;
};
$csrf = Security::csrfField();

admin_header('Easy login · सजिलो लगइन', 'login-reset.php');
?>
<div class="wrap" style="max-width:1100px">
  <h1 style="margin:0 0 6px">🔑 सजिलो लगइन · Easy login</h1>
  <p class="muted" style="margin:0 0 12px">लगइन: मोड छान्नुहोस् → कोड (SHG-0001 = <b>1</b>) वा मोबाइल → पासवर्ड। सुरुको पासवर्ड <b>पहिलो नाम@<?= $e(EasyLogin::YEAR) ?></b> (जस्तै Ram@2026)। आफैँले बदलेपछि त्यो चल्दैन; यहाँ "रिसेट" थिचे फेरि पहिलो नाम@<?= $e(EasyLogin::YEAR) ?> हुन्छ।<?= EasyLogin::defaultOn() ? '' : ' <b style="color:#b42318">(अहिले default पासवर्ड बन्द छ: login_default_pw_on = 0)</b>' ?></p>
  <?php if ($flash !== null): ?><p style="padding:10px 12px;border-radius:10px;font-weight:700;background:<?= $flash[0] === 'ok' ? '#e7f6ec;color:#0a6b3b' : '#fdecec;color:#9b1c1c' ?>"><?= $e($flash[1]) ?></p><?php endif; ?>
  <?php if (!$may): ?><p class="muted">रिसेट मालिक (superadmin) ले मात्र गर्न मिल्छ — तपाईं सूची हेर्न सक्नुहुन्छ।</p><?php endif; ?>
  <form method="get" style="margin:0 0 12px;display:flex;gap:8px"><input name="q" value="<?= $e($q) ?>" placeholder="नाम, कोड वा मोबाइल खोज्नुहोस्" style="flex:1;min-height:42px;padding:0 12px"><button class="btn" type="submit">खोज</button></form>

  <h2 style="font-size:16px;margin:16px 0 6px">कर्मचारी, एजेन्ट, काउन्टर · Staff, agents, counters (<?= count($admins) ?>)</h2>
  <div style="overflow-x:auto"><table class="table" style="width:100%">
    <thead><tr><th>नाम</th><th>भूमिका</th><th>लगइनमा लेख्ने</th><th>मोबाइल</th><th>पासवर्ड</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($admins as $a): $def = EasyLogin::defaultFor((string) $a['full_name']); $on = EasyLogin::adminDefaultActive($a); ?>
      <tr>
        <td><b><?= $e($a['full_name'] ?: $a['username']) ?></b></td>
        <td><?= $e($a['role']) ?></td>
        <td><?= $e($typed((string) $a['code'])) ?></td>
        <td><?= $e($a['phone']) ?></td>
        <td><?= $on && $def !== '' ? '<span class="pill" style="background:#fff4e5;color:#7a4100">' . $e($def) . '</span>' : '<span class="muted">आफ्नै · own</span>' ?></td>
        <td><?php if ($may && (int) $a['id'] !== $me && $def !== ''): ?><form method="post" onsubmit="return confirm('<?= $e(($a['full_name'] ?: $a['username'])) ?> को पासवर्ड <?= $e($def) ?> मा रिसेट गर्ने?')"><?= $csrf ?><input type="hidden" name="kind" value="admin"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn btn-sm" type="submit">↺ रिसेट</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <h2 style="font-size:16px;margin:20px 0 6px">चालक, कन्डक्टर · Drivers &amp; conductors (<?= count($drivers) ?>)</h2>
  <div style="overflow-x:auto"><table class="table" style="width:100%">
    <thead><tr><th>नाम</th><th>भूमिका</th><th>लगइनमा लेख्ने</th><th>मोबाइल</th><th>पासवर्ड / PIN</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($drivers as $d): $def = EasyLogin::defaultFor((string) $d['full_name']); $on = EasyLogin::driverDefaultActive($d); ?>
      <tr>
        <td><b><?= $e($d['full_name']) ?></b></td>
        <td><?= $e($d['role']) ?></td>
        <td><?= (int) $d['id'] ?> वा मोबाइल</td>
        <td><?= $e($d['phone']) ?></td>
        <td><?= $on && $def !== '' ? '<span class="pill" style="background:#fff4e5;color:#7a4100">' . $e($def) . '</span>' : '' ?><?= (int) $d['has_pin'] === 1 ? ' <span class="muted">+ PIN</span>' : '' ?></td>
        <td><?php if ($may && $def !== ''): ?><form method="post" onsubmit="return confirm('<?= $e($d['full_name']) ?> लाई <?= $e($def) ?> मा रिसेट गर्ने? उसको फोन app बाट बाहिरिन्छ।')"><?= $csrf ?><input type="hidden" name="kind" value="driver"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn btn-sm" type="submit">↺ रिसेट</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php
admin_footer();
