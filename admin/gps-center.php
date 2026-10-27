<?php
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('buses.view');
require_once INCLUDE_PATH . '/gpscenter.php';
require_once INCLUDE_PATH . '/gpssimulator.php';
require_once INCLUDE_PATH . '/gpsfleet.php';
require_once INCLUDE_PATH . '/gpsloggerprofile.php';
$canEdit = Auth::can('buses.edit') || Auth::isSuperadmin();
$canCreate = Auth::can('routes.edit') || Auth::can('schedules.manage') || Auth::isSuperadmin();
$canPublish = Auth::can('settings.manage') || Auth::isSuperadmin();
$flash = $_SESSION['gps_center_flash'] ?? null; unset($_SESSION['gps_center_flash']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $mode = 'real';
    try {
        if (!$canEdit) { throw new RuntimeException('GPS editing permission required.'); }
        if (!Security::verifyCsrf()) { throw new RuntimeException('Session expired. Reload and try again.'); }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'connect') {
            $input = $_POST;
            $input['bus_name'] = Security::clean($input['bus_name'] ?? '', 120);
            $input['bus_number'] = Security::clean($input['bus_number'] ?? '', 30);
            $connected = GpsCenter::connect($input, (int) $admin['id'], $canCreate, $canPublish);
            $flash = ['ok', 'Bus GPS ready. Open its OwnTracks setup link on that bus phone; location permission and Import are completed on the phone.'];
        } elseif ($action === 'public_map') {
            if (!$canPublish) { throw new RuntimeException('Public sharing requires settings-management permission.'); }
            $busId = (int) ($_POST['bus_id'] ?? 0);
            if (!Database::exists('SELECT 1 FROM buses WHERE id=:b AND is_active=1', ['b' => $busId])) { throw new RuntimeException('Active bus not found.'); }
            $on = ($_POST['on'] ?? '') === '1'; Settings::set('gps_public_bus_' . $busId, $on, 'bool', 'automation'); Settings::flush();
            Logger::audit('gps_center.public_map', 'bus', (string) $busId, null, ['public' => $on], 'by admin #' . $admin['id']);
            $flash = ['ok', $on ? 'Public GPS map ON for this bus.' : 'Public GPS map OFF for this bus.'];
        } elseif (in_array($action, ['start', 'pause', 'reset'], true)) {
            $mode = 'sim'; GpsSimulator::save($action, $_POST, (int) $admin['id']);
            $flash = ['ok', 'Private demo updated. Real buses and public GPS data are unchanged.'];
        } else { throw new RuntimeException('Unknown action.'); }
        require_once INCLUDE_PATH . '/navlive.php'; NavLive::gpsChanged();
    } catch (Throwable $e) {
        $flash = ['bad', $e instanceof RuntimeException ? $e->getMessage() : 'Could not save GPS settings.'];
        if (!$e instanceof RuntimeException) { Logger::exception($e); }
        if (in_array((string) ($_POST['action'] ?? ''), ['start', 'pause', 'reset'], true)) { $mode = 'sim'; }
    }
    $_SESSION['gps_center_flash'] = $flash;
    header('Location: /admin/gps-center.php?mode=' . $mode, true, 303); exit;
}
$buses = GpsTrackers::busesWithTrackers();
$routes = Database::fetchAll('SELECT id,from_city,to_city FROM routes WHERE is_active=1 ORDER BY id');
$canSimulate = GpsSimulator::available();
$simulation = $canSimulate ? GpsSimulator::state() : ['routeId'=>0, 'count'=>3, 'speed'=>60];
$initialMode = $canSimulate && ($_GET['mode'] ?? '') === 'sim' ? 'sim' : 'real';
$csrf = Security::csrfField();
admin_header('Live Fleet & GPS' . ($canSimulate ? ' · Development simulation' : ''), 'gps-center');
require __DIR__ . '/_gps-center-view.php';
admin_footer();
