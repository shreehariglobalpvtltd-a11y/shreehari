<?php
/**
 * admin/company.php — Company details & finance overview.
 * Minimal read-only page pulled from config + settings + Finance Master data.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$company = Settings::getString('company_name', APP_NAME);
$phone   = Settings::getString('company_phone', '');
$email   = Settings::getString('company_email', '');
$website = rtrim(APP_URL, '/');

/* ---------- Company identity (hardcoded from incorporation) ---------- */
$identity = [
    'name'     => 'S HARI GLOBAL PVT LTD',
    'nameNe'   => 'एस हरि ग्लोबल प्रा. लि.',
    'cin'      => 'U52291GJ2026PTC174029',
    'type'     => 'Private Limited Company',
    'state'    => 'Gujarat',
    'country'  => 'India',
    'address'  => 'Near Shilpa Garage, Silver Complex, Mehsana, Gujarat 384002, India',
    'currency' => 'INR (₹)',
];

/* ---------- Nepal entity ---------- */
$nepal = [
    'name'     => 'नेपाल साझेदार कम्पनी',
    'address'  => 'Nepalgunj, Nepal',
    'currency' => 'NPR (रु)',
];

/* ---------- Capital structure (from Finance Master defaults) ---------- */
$capital = [
    'authorized'    => '—',   // owner sets in Finance Master
    'paidUp'        => '—',
    'faceValue'     => '₹10,000 per share',
    'shareClasses'  => [
        ['id' => 'EQ',   'name' => 'साधारण शेयर (Equity)',        'voting' => true],
        ['id' => 'PREF', 'name' => 'प्राथमिक शेयर (Preference)',   'voting' => false],
    ],
    'ownerRef'      => '₹50,00,00,000 (₹50 Crore)',
];

/* ---------- Commission rules ---------- */
$commission = [
    'direct' => '₹200 per passenger',
    'team'   => '₹400 per passenger',
    'mode'   => 'Flat',
];

/* ---------- Business settings from DB ---------- */
$biz = [
    'seatHold'   => SEAT_HOLD_MINUTES . ' minutes',
    'maxSeats'   => MAX_SEATS_BOOKING,
    'pnrPrefix'  => PNR_PREFIX,
    'currency'   => DEFAULT_CURRENCY,
    'fxRate'     => NPR_PER_INR . ' NPR per 1 INR',
    'timezone'   => APP_TIMEZONE,
];

/* ---------- Quick DB stats ---------- */
$totalRoutes    = (int) Database::scalar("SELECT COUNT(*) FROM routes");
$totalBuses     = (int) Database::scalar("SELECT COUNT(*) FROM buses");
$totalSchedules = (int) Database::scalar("SELECT COUNT(*) FROM schedules WHERE is_active=1");
$totalBookings  = (int) Database::scalar("SELECT COUNT(*) FROM bookings");
try { $totalAgents = (int) Database::scalar("SELECT COUNT(*) FROM agents WHERE status='active'"); } catch (\Throwable $e) { $totalAgents = 0; }
$totalStaff     = (int) Database::scalar("SELECT COUNT(*) FROM admin_users WHERE status='active'");

admin_header('Company Details', 'company');
?>

<div class="cards">
  <div class="card"><div class="k">Routes</div><div class="v"><?= $totalRoutes ?></div></div>
  <div class="card"><div class="k">Buses</div><div class="v"><?= $totalBuses ?></div></div>
  <div class="card"><div class="k">Active schedules</div><div class="v"><?= $totalSchedules ?></div></div>
  <div class="card"><div class="k">All bookings</div><div class="v"><?= number_format($totalBookings) ?></div></div>
  <div class="card"><div class="k">Active agents</div><div class="v"><?= $totalAgents ?></div></div>
  <div class="card"><div class="k">Staff</div><div class="v"><?= $totalStaff ?></div></div>
</div>

<!-- Company Identity -->
<div class="panel">
  <h2>🏢 Company Identity</h2>
  <table>
    <tr><th style="width:36%">Registered Name</th><td><strong><?= Security::e($identity['name']) ?></strong></td></tr>
    <tr><th>Name (नेपाली)</th><td><?= Security::e($identity['nameNe']) ?></td></tr>
    <tr><th>CIN</th><td class="mono"><?= Security::e($identity['cin']) ?></td></tr>
    <tr><th>Company Type</th><td><?= Security::e($identity['type']) ?></td></tr>
    <tr><th>State of Registration</th><td><?= Security::e($identity['state']) ?></td></tr>
    <tr><th>Registered Address</th><td><?= Security::e($identity['address']) ?></td></tr>
    <tr><th>Currency</th><td><?= Security::e($identity['currency']) ?></td></tr>
    <tr><th>Website</th><td><a href="<?= Security::e($website) ?>" target="_blank"><?= Security::e($website) ?></a></td></tr>
    <?php if ($phone): ?><tr><th>Phone</th><td class="mono"><?= Security::e($phone) ?></td></tr><?php endif; ?>
    <?php if ($email): ?><tr><th>Email</th><td><?= Security::e($email) ?></td></tr><?php endif; ?>
  </table>
</div>

<!-- Nepal Entity -->
<div class="panel">
  <h2>🇳🇵 Nepal Entity</h2>
  <table>
    <tr><th style="width:36%">Name</th><td><?= Security::e($nepal['name']) ?></td></tr>
    <tr><th>Address</th><td><?= Security::e($nepal['address']) ?></td></tr>
    <tr><th>Currency</th><td><?= Security::e($nepal['currency']) ?></td></tr>
    <tr><th>FX Rate</th><td><?= Security::e($biz['fxRate']) ?></td></tr>
  </table>
</div>

<!-- Capital Structure -->
<div class="panel">
  <h2>💰 Capital Structure</h2>
  <table>
    <tr><th style="width:36%">Owner Reference Capital</th><td><strong><?= Security::e($capital['ownerRef']) ?></strong></td></tr>
    <tr><th>Face Value</th><td><?= Security::e($capital['faceValue']) ?></td></tr>
    <tr>
      <th>Share Classes</th>
      <td>
        <?php foreach ($capital['shareClasses'] as $sc): ?>
          <div style="margin-bottom:4px">
            <span class="mono"><?= Security::e($sc['id']) ?></span> —
            <?= Security::e($sc['name']) ?>
            <?= $sc['voting'] ? '✅ Voting' : '❌ No voting' ?>
          </div>
        <?php endforeach; ?>
      </td>
    </tr>
    <tr><th>Authorized Capital</th><td class="muted"><?= Security::e($capital['authorized']) ?> <small>(set in Finance Master)</small></td></tr>
    <tr><th>Paid-up Capital</th><td class="muted"><?= Security::e($capital['paidUp']) ?> <small>(set in Finance Master)</small></td></tr>
  </table>
</div>

<!-- Commission & Business -->
<div class="panel">
  <h2>📋 Commission & Business Rules</h2>
  <table>
    <tr><th style="width:36%">Commission Mode</th><td><?= Security::e($commission['mode']) ?></td></tr>
    <tr><th>Direct Agent</th><td><?= Security::e($commission['direct']) ?></td></tr>
    <tr><th>Team Agent</th><td><?= Security::e($commission['team']) ?></td></tr>
    <tr><th>Seat Hold Time</th><td><?= Security::e($biz['seatHold']) ?></td></tr>
    <tr><th>Max Seats / Booking</th><td><?= $biz['maxSeats'] ?></td></tr>
    <tr><th>PNR Prefix</th><td class="mono"><?= Security::e($biz['pnrPrefix']) ?></td></tr>
    <tr><th>Timezone</th><td><?= Security::e($biz['timezone']) ?></td></tr>
  </table>
</div>

<p class="muted" style="text-align:center;margin:18px 0;font-size:12px">
  Capital, shareholding and full accounting are managed in
  <strong>Finance Master</strong> (Admin → Reports → Finance Master).
</p>

<?php
admin_footer();
