<?php
/**
 * tests/partner-logo-review-test.php — 22 Oct 2026 (review, #18): a Pvt.
 * Ltd. partner's logo from the public form (/agreements/apply.html) goes on
 * the home page with the approval, so admin/agreements.php must show it in
 * the review and must not publish it before the office decided on it.
 *
 *   php -c .claude/php-dev.ini tests/partner-logo-review-test.php
 *
 *   1. a manager sees the logo in the application (next to the photo), the
 *      "goes on the website when approved" note, the logo in the card
 *      preview and a Show / Remove choice with nothing pre-ticked; support
 *      staff see "shown to a manager only" and none of the image
 *   2. Save with status Approved and no choice (or a made-up one) = refused,
 *      nothing changed, the logo not served; "Remove it" = approved with the
 *      logo gone; "Show this logo" = approved and the logo served
 *   3. "Approve & put live" asks the same, also for an approved card whose
 *      "Live on website" is off
 *   4. a partner already approved saves without the choice; the Website
 *      card's "Remove the logo" takes it off; a partner with no logo and an
 *      individual agent approve exactly as before
 *
 * admin/agreements.php is driven over HTTP at SHG_TEST_BASE (default
 * http://localhost:8893) as two temporary office users; the checks are
 * skipped when no server answers there. Runs against a TEST database only;
 * every row it writes (applications 98000777xx, the two users, their
 * sign-in and audit rows) is removed at the end. Nothing is sent. Exit 1 on
 * any failure.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentapplications.php';

$DBN = (string) DB_NAME;
if (!str_contains($DBN, 'test') && !str_starts_with($DBN, 'lt_')) {
    fwrite(STDERR, "refusing to run against '$DBN' (not a test database)\n");
    exit(2);
}
if (!AgentApplications::available() || !AgentApplications::logoReady()) {
    fwrite(STDERR, "run database/upgrade-2026-10-19-agent-website-card.sql and upgrade-2026-10-22-team-portal.sql first\n");
    exit(2);
}

$PASS = 0; $FAIL = 0;
function check(string $label, bool $ok, string $why = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $label" . ($why !== '' ? "  [$why]" : '') . "\n"; }
}

const PLR_PW = 'ZzLogoReview@12345';
$BASE = rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8893'), '/');

/** @return array{code:int, body:string, location:string} */
function plr_req(string $method, string $url, string $jar, array $form = []): array
{
    global $BASE;
    $ch = curl_init($BASE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
    ]);
    if ($form !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsz  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hsz);
    return ['code' => $code, 'body' => substr($raw, $hsz), 'location' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : ''];
}

function plr_login(string $user, string $jar): bool
{
    $lp  = plr_req('GET', '/admin/login.php', $jar);
    $tok = preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m) ? $m[1] : '';
    $li  = plr_req('POST', '/admin/login.php', $jar, ['shg_csrf' => $tok, 'username' => $user, 'password' => PLR_PW]);
    return $li['code'] === 302 && !str_contains($li['location'], 'login.php');
}

function plr_csrf(string $body): string
{
    return preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $body, $m) ? $m[1] : '';
}

/** The application's own panel (#detail), without the Website card. */
function plr_detail(string $body): string
{
    $a = strpos($body, 'id="detail"');
    $b = strpos($body, 'id="web"');
    return $a !== false && $b !== false && $b > $a ? substr($body, $a, $b - $a) : '';
}

function plr_png(int $r, int $g, int $b): string
{
    $im = imagecreatetruecolor(90, 40);
    imagefill($im, 0, 0, (int) imagecolorallocate($im, $r, $g, $b));
    ob_start();
    imagepng($im);
    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
}

function plr_app(string $type, string $name, string $phone, ?string $logo): array
{
    $fields = ['p_name' => $name, 'p_phone' => $phone, 'p_area' => 'Nepalgunj'];
    $fields += $type === 'org' ? ['p_rep' => 'ZZ Rep'] : ['p_idno' => 'ZZLOGO-' . $phone, 'p_idtype' => '1', 'p_addr' => 'ZZ Tole'];
    $v = AgentApplications::validate(['type' => $type, 'lang' => 'en', 'fields' => $fields, 'public_ok' => true] + ($logo !== null ? ['logo' => $logo] : []));
    if (!$v['ok']) {
        throw new RuntimeException('test form refused: ' . json_encode($v['errors']));
    }
    $made = AgentApplications::create($v['clean'], '127.0.0.1');
    return AgentApplications::find((int) $made['id']) ?? [];
}

function plr_row(int $id): array
{
    return AgentApplications::find($id) ?? [];
}

function plr_served(array $app): bool
{
    return !empty($app['web_key']) && AgentApplications::publicPhoto((string) $app['web_key'], 'logo') !== null;
}

function plr_audit(string $ref, string $word): bool
{
    return Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agreement_app.logo' AND entity_id = :r AND new_value LIKE :w LIMIT 1",
        ['r' => $ref, 'w' => '%' . $word . '%']);
}

$USERS = ['zz-logo-super' => 'superadmin', 'zz-logo-support' => 'support'];
$jars  = [];
$cleanup = static function () use ($USERS): void {
    $refs = array_column(Database::fetchAll("SELECT ref FROM agent_applications WHERE phone LIKE '98000777%'"), 'ref');
    foreach ($refs as $ref) {
        if ($ref !== null && $ref !== '') {
            Database::run("DELETE FROM audit_logs WHERE entity_type = 'agent_application' AND entity_id = :r", ['r' => (string) $ref]);
        }
    }
    Database::run("DELETE FROM agent_applications WHERE phone LIKE '98000777%'");
    foreach (array_keys($USERS) as $u) {
        $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
        if ($id > 0) {
            Database::run("DELETE FROM audit_logs WHERE actor_type = 'admin' AND actor_id = :i", ['i' => $id]);
            Database::run("DELETE FROM audit_logs WHERE entity_type = 'admin' AND entity_id = :i", ['i' => (string) $id]);
            Database::run('DELETE FROM admin_login_events WHERE admin_id = :i', ['i' => $id]);
            Database::run('DELETE FROM admins WHERE id = :i', ['i' => $id]);
        }
        Database::run('DELETE FROM admin_login_events WHERE username = :u', ['u' => $u]);
    }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'");
};

try {
    $cleanup();

    echo "\n=== source: the gate is in admin/agreements.php ===\n";
    $src = (string) file_get_contents(dirname(__DIR__) . '/admin/agreements.php');
    check('approving checks the logo before AgentApplications::update', preg_match("/'approved'[^\n]*\\\$logoOf\\(\\\$was\\)[^\n]*\n\s*\\\$logoDecide\\(\\\$was, 'approved'\\);\s*\}\s*\n\s*\\\$changed = AgentApplications::update/", $src) === 1);
    check('"Approve & put live" checks it before its update', preg_match("/\\\$logoDecide\\(\\\$was, [^\n]*put live[^\n]*\n\s*\}\s*\n\s*AgentApplications::update\\(\\\$id, \\['status' => 'approved'\\]/", $src) === 1);
    check('no radio is pre-ticked', !preg_match('/name="logo_ok" value="[01]"[^>]*checked/', $src));

    $hc = plr_req('GET', '/admin/login.php', sys_get_temp_dir() . '/plr_probe_' . getmypid())['code'];
    @unlink(sys_get_temp_dir() . '/plr_probe_' . getmypid());
    if ($hc !== 200) {
        echo "  SKIP  HTTP checks ($BASE/admin/login.php answered $hc)\n";
    } else {
        foreach ($USERS as $u => $role) {
            Database::insert('admins', ['username' => $u, 'password_hash' => Security::hashPassword(PLR_PW), 'full_name' => 'ZZ ' . $u,
                'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
            $jars[$u] = sys_get_temp_dir() . '/plr_' . $u . '_' . getmypid() . '.cookies';
            @unlink($jars[$u]);
        }
        $sj = $jars['zz-logo-super'];
        $uj = $jars['zz-logo-support'];
        check('the manager (super-admin) signs in', plr_login('zz-logo-super', $sj));
        check('support staff sign in', plr_login('zz-logo-support', $uj));

        $logoA = plr_png(200, 20, 20);
        $logoB = plr_png(20, 60, 200);
        $A = plr_app('org', 'ZZ Logo Alpha Pvt Ltd', '9800077701', $logoA);
        $B = plr_app('org', 'ZZ Logo Beta Pvt Ltd', '9800077702', $logoB);
        $C = plr_app('org', 'ZZ Logo Gamma Pvt Ltd', '9800077703', null);
        $D = plr_app('ind', 'ZZ Logo Agent', '9800077704', null);
        $bA = substr($logoA, 22, 60);
        $bB = substr($logoB, 22, 60);
        check('the public form stored both partners\' logos (status new, live switch on)', !empty($A['logo']) && !empty($B['logo'])
            && $A['status'] === 'new' && (int) $A['web_live'] === 1);

        echo "\n=== 1. the review shows the logo ===\n";
        $g = plr_req('GET', '/admin/agreements.php?tab=apps&id=' . $A['id'], $sj);
        $det = plr_detail($g['body']);
        check('the manager sees the logo in the application itself (beside the photo)', $g['code'] === 200
            && str_contains($det, 'class="agr-logo" src="data:image/png;base64,' . $bA), 'HTTP ' . $g['code']);
        check('… with "goes on the website when approved"', str_contains($det, 'Goes on the website when approved'));
        check('… a Show / Remove choice in the approval form, nothing pre-ticked', str_contains($det, 'name="logo_ok" value="1"')
            && str_contains($det, 'name="logo_ok" value="0"') && !preg_match('/name="logo_ok" value="[01]"[^>]*checked/', $det));
        check('the card preview shows the logo, as the home page does', str_contains($g['body'], 'class="agr-card-logo" src="data:image/png;base64,' . $bA));
        check('"Approve & put live" asks the same', substr_count($g['body'], 'name="logo_ok" value="1"') === 2);
        check('the Website card offers "Remove the logo"', str_contains($g['body'], 'name="logo_remove" value="1"'));
        $csrf = plr_csrf($g['body']);
        check('the page carries its CSRF field', $csrf !== '');

        $gs = plr_req('GET', '/admin/agreements.php?tab=apps&id=' . $A['id'], $uj);
        check('support staff: "Company logo sent — shown to a manager only"', $gs['code'] === 200
            && str_contains(plr_detail($gs['body']), 'Company logo sent — shown to a manager only'), 'HTTP ' . $gs['code']);
        check('… and none of the image anywhere on the page', !str_contains($gs['body'], $bA));

        echo "\n=== 2. Save with status Approved ===\n";
        $base = ['shg_csrf' => $csrf, 'action' => 'app', 'id' => $A['id'], 'status' => 'approved', 'kyc_status' => 'verified', 'agreement_status' => 'none'];
        $p = plr_req('POST', '/admin/agreements.php?tab=apps&id=' . $A['id'], $sj, $base);
        $now = plr_row((int) $A['id']);
        check('no logo choice = refused, the office is told why', $p['code'] === 200 && str_contains($p['body'], 'sent a company logo'), 'HTTP ' . $p['code']);
        check('… nothing changed (status new, KYC as it was, logo kept)', $now['status'] === 'new' && $now['kyc_status'] === $A['kyc_status'] && !empty($now['logo']));
        check('… and the logo is not served', !plr_served($now));
        $p = plr_req('POST', '/admin/agreements.php?tab=apps&id=' . $A['id'], $sj, $base + ['logo_ok' => 'yes']);
        check('a made-up choice is refused too', plr_row((int) $A['id'])['status'] === 'new' && str_contains($p['body'], 'sent a company logo'));
        $p = plr_req('POST', '/admin/agreements.php?tab=apps&id=' . $A['id'], $sj, $base + ['logo_ok' => '0']);
        $now = plr_row((int) $A['id']);
        check('"Remove it" = approved', $p['code'] === 302 && $now['status'] === 'approved' && $now['kyc_status'] === 'verified', 'HTTP ' . $p['code']);
        check('… with the logo gone and nothing served', empty($now['logo']) && !plr_served($now));
        $dirA = array_values(array_filter(AgentApplications::directory(), static fn($r) => ($r['name'] ?? '') === 'ZZ Logo Alpha Pvt Ltd'));
        check('… the home page list has the partner, with no logo', count($dirA) === 1 && $dirA[0]['logo'] === '');
        check('… and the removal is in the activity log', plr_audit((string) $A['ref'], 'removed'));

        echo "\n=== 3. Approve & put live ===\n";
        $g = plr_req('GET', '/admin/agreements.php?tab=apps&id=' . $B['id'], $sj);
        $csrf = plr_csrf($g['body']) ?: $csrf;
        $live = ['shg_csrf' => $csrf, 'action' => 'golive', 'id' => $B['id']];
        $p = plr_req('POST', '/admin/agreements.php?tab=apps&id=' . $B['id'], $sj, $live);
        $now = plr_row((int) $B['id']);
        check('no logo choice = refused, still new, logo not served', $p['code'] === 200 && str_contains($p['body'], 'sent a company logo')
            && $now['status'] === 'new' && !plr_served($now), 'HTTP ' . $p['code']);
        $p = plr_req('POST', '/admin/agreements.php?tab=apps&id=' . $B['id'], $sj, $live + ['logo_ok' => '1']);
        $now = plr_row((int) $B['id']);
        check('"Show this logo" = approved, live, and the logo is served', $p['code'] === 302 && $now['status'] === 'approved'
            && (int) $now['web_live'] === 1 && plr_served($now), 'HTTP ' . $p['code']);
        check('… and the choice is in the activity log', plr_audit((string) $B['ref'], 'shown'));
        $g = plr_req('GET', '/admin/agreements.php?tab=apps&id=' . $B['id'], $sj);
        check('the application now says "On the website now", with no choice to make', str_contains(plr_detail($g['body']), 'On the website now')
            && !str_contains($g['body'], 'name="logo_ok"'));
        Database::run('UPDATE agent_applications SET web_live = 0 WHERE id = :i', ['i' => (int) $B['id']]);
        $p = plr_req('POST', '/admin/agreements.php?tab=apps&id=' . $B['id'], $sj, $live);
        check('approved but "Live on website" off: putting live asks again', str_contains($p['body'], 'sent a company logo')
            && (int) plr_row((int) $B['id'])['web_live'] === 0 && !plr_served(plr_row((int) $B['id'])));
        $p = plr_req('POST', '/admin/agreements.php?tab=apps&id=' . $B['id'], $sj, $live + ['logo_ok' => '1']);
        check('… and goes live with "Show this logo"', $p['code'] === 302 && plr_served(plr_row((int) $B['id'])));

        echo "\n=== 4. after approval, and the others ===\n";
        $p = plr_req('POST', '/admin/agreements.php?tab=apps&id=' . $B['id'], $sj, ['shg_csrf' => $csrf, 'action' => 'app', 'id' => $B['id'],
            'status' => 'approved', 'kyc_status' => 'verified', 'agreement_status' => 'none', 'note' => 'ZZ logo checked']);
        $now = plr_row((int) $B['id']);
        check('an approved partner saves without the choice (the logo stays)', $p['code'] === 302 && ($now['note'] ?? '') === 'ZZ logo checked'
            && plr_served($now), 'HTTP ' . $p['code']);
        $p = plr_req('POST', '/admin/agreements.php?tab=apps&id=' . $B['id'], $sj, ['shg_csrf' => $csrf, 'action' => 'card', 'id' => $B['id'],
            'name' => 'ZZ Logo Beta Pvt Ltd', 'phone' => '9800077702', 'area' => 'Nepalgunj', 'web_live' => '1', 'logo_remove' => '1']);
        $now = plr_row((int) $B['id']);
        check('Website card "Remove the logo" takes it off', $p['code'] === 302 && str_contains($p['location'], 'saved=1')
            && empty($now['logo']) && !plr_served($now) && (int) $now['web_live'] === 1, 'HTTP ' . $p['code'] . ' ' . $p['location']);
        check('… and is in the activity log', plr_audit((string) $B['ref'], 'removed'));
        $p = plr_req('POST', '/admin/agreements.php?tab=apps&id=' . $C['id'], $sj, ['shg_csrf' => $csrf, 'action' => 'app', 'id' => $C['id'],
            'status' => 'approved', 'kyc_status' => 'verified', 'agreement_status' => 'none']);
        check('a partner with no logo approves as before', $p['code'] === 302 && plr_row((int) $C['id'])['status'] === 'approved', 'HTTP ' . $p['code']);
        $p = plr_req('POST', '/admin/agreements.php?tab=apps&id=' . $D['id'], $sj, ['shg_csrf' => $csrf, 'action' => 'golive', 'id' => $D['id']]);
        check('an individual agent goes live as before', $p['code'] === 302 && AgentApplications::isLive(plr_row((int) $D['id'])), 'HTTP ' . $p['code']);
        $ucsrf = preg_match('/name="csrf" content="([a-f0-9]+)"/', $gs['body'], $m) ? $m[1] : '';
        $p = plr_req('POST', '/admin/agreements.php?tab=apps&id=' . $C['id'], $uj, ['shg_csrf' => $ucsrf, 'action' => 'app', 'id' => $C['id'],
            'status' => 'rejected', 'logo_ok' => '1']);
        check('support staff still cannot change anything (403)', $ucsrf !== '' && $p['code'] === 403 && plr_row((int) $C['id'])['status'] === 'approved', 'HTTP ' . $p['code']);
    }
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    try { $cleanup(); } catch (Throwable $e) { echo '  cleanup: ' . $e->getMessage() . "\n"; }
    foreach ($jars as $j) { @unlink($j); }
}

echo "\n----------------------------------------\n";
echo "  $PASS passed, $FAIL failed\n";
echo "----------------------------------------\n\n";
exit($FAIL === 0 ? 0 : 1);
