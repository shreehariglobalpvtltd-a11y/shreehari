<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
ob_start();
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/teammanager.php';
require_once INCLUDE_PATH . '/agentreport.php';
require_once INCLUDE_PATH . '/idcard.php';
if (!str_ends_with((string) Database::scalar('SELECT DATABASE()'), '_test')) { exit("Refusing to run outside a test database.\n"); }
$count = 0;
$failed = false;
$check = static function (bool $ok, string $message) use (&$count): void {
    if (!$ok) { throw new RuntimeException($message); }
    $count++; echo "PASS $message\n";
};
Database::begin();
try {
    $actorId = Database::insert('admins', ['username'=>'team-manager-test-owner','full_name'=>'Test owner','role'=>'superadmin','password_hash'=>password_hash('test-only-password', PASSWORD_BCRYPT),'is_active'=>1]);
    $aid = Database::insert('admins', ['username'=>'team-manager-test-agent','full_name'=>'Test agent','role'=>'agent','phone'=>'9876543120','password_hash'=>password_hash('test-only-password', PASSWORD_BCRYPT),'is_active'=>1]);
    $actor = ['id'=>$actorId,'role'=>'superadmin','username'=>'team-manager-test-owner','full_name'=>'Test owner','last_seen'=>time(),'permissions'=>[]];
    $_SESSION[ADMIN_SESSION_KEY] = $actor;
    $app = AgentApplications::adminCreate(['name'=>'Test agent','phone'=>'+919876543120','web_live'=>1], $actorId, true);
    AgentApplications::update($app['id'], ['admin_id'=>$aid], $actorId);
    $key = 'agent:' . $app['id'];
    $check(TeamManager::find($key)['live'], 'approved application listed live');
    try { TeamManager::archive($key, false, ['id'=>999,'role'=>'support']); $check(false, 'support denied'); }
    catch (RuntimeException $e) { $check(str_contains($e->getMessage(), 'Only a manager'), 'support cannot archive'); }
    TeamManager::archive($key, false, $actor);
    $check(TeamManager::find($key)['archived'], 'archived record retained');
    $check((int) AgentApplications::find($app['id'])['web_live'] === 0, 'archive hides public application');
    $check((int) Database::scalar('SELECT is_active FROM admins WHERE id=:i',['i'=>$aid]) === 0, 'archive disables unused agent login');
    $check(!IdCard::person($app['ref'])['valid'], 'archived QR is invalid');
    TeamManager::archive($key, true, $actor);
    $check(!TeamManager::find($key)['archived'] && TeamManager::find($key)['live'], 'restore returns previous visibility');
    $check((int) Database::scalar('SELECT is_active FROM admins WHERE id=:i',['i'=>$aid]) === 1, 'restore returns previous login state');
    $other = AgentApplications::adminCreate(['name'=>'Corrected form','phone'=>'+919876543120','web_live'=>1], $actorId, true);
    AgentApplications::update($other['id'], ['admin_id'=>$aid], $actorId);
    TeamManager::archive($key, false, $actor);
    $check((int) Database::scalar('SELECT is_active FROM admins WHERE id=:i',['i'=>$aid]) === 1, 'archiving duplicate keeps current shared login active');
    $check(!IdCard::person($app['ref'])['valid'] && IdCard::person($other['ref'])['valid'], 'only archived duplicate QR is invalid');
    $staff = Database::insert('staff_applications', ['full_name'=>'Test staff','phone'=>'9876543121','role_applied'=>'office','id_type'=>'passport','id_number'=>'SYNTHETIC-TEST','status'=>'approved','show_on_website'=>1]);
    TeamManager::archive('staff:' . $staff, false, $actor);
    $check(StaffApps::find($staff)['status'] === 'suspended' && !IdCard::person(StaffApps::refFor($staff))['valid'], 'staff archive ends portal access and invalidates QR');
    TeamManager::archive('staff:' . $staff, true, $actor);
    $check(StaffApps::find($staff)['status'] === 'approved', 'staff restore returns earlier status');
    foreach ([0,100,200] as $amount) {
        TeamManager::compensation($aid, ['pay_mode'=>'commission','basis'=>'booking','rate'=>$amount,'salary'=>0,'reason'=>'Test agreed terms'], $actor);
        $check(AgentWallet::commissionForBooking(6000,$aid,3) === (float) $amount, 'per-ticket commission ' . $amount . ' paid once for three passengers');
    }
    $check(str_contains(AgentReport::rateCard($aid)['label'], '/ ticket'), 'report names ticket basis');
    TeamManager::compensation($aid, ['pay_mode'=>'both','basis'=>'flat','rate'=>200,'salary'=>12000,'reason'=>'Test both payment modes'], $actor);
    $check(AgentWallet::commissionForBooking(6000,$aid,3) === 600.0 && AgentWallet::salaryFor($aid) === 12000.0, 'salary plus per-passenger commission');
    TeamManager::compensation($aid, ['pay_mode'=>'salary','basis'=>'booking','rate'=>200,'salary'=>12000,'reason'=>'Salary without commission'], $actor);
    $check(AgentWallet::commissionForBooking(6000,$aid,3) === 0.0 && AgentWallet::salaryFor($aid) === 12000.0, 'salary-only suppresses commission');
    foreach (['-1','NaN','100000000'] as $bad) {
        try { TeamManager::compensation($aid, ['pay_mode'=>'commission','basis'=>'booking','rate'=>$bad,'salary'=>0,'reason'=>'Bad test amount'], $actor); $check(false, 'bad amount refused'); }
        catch (RuntimeException $e) { $check(str_contains($e->getMessage(), 'valid'), 'invalid amount refused: ' . $bad); }
    }
    $_GET = ['person'=>'agent:' . $other['id']];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/admin/team-manager.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    ob_start();
    require dirname(__DIR__) . '/admin/team-manager.php';
    $html = ob_get_clean();
    foreach (['Save details','Save commission / salary','Cards PDF','Login ID / password','Per ticket / PNR','Archived · पुराना'] as $label) {
        $check(str_contains($html, $label), 'workbench renders ' . $label);
    }
    echo "SUCCESS: $count checks; no production data used.\n";
} catch (Throwable $e) { $failed = true; fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n"); }
finally { Database::rollBack(); Settings::flush(); }
ob_end_flush();
exit($failed ? 1 : 0);
