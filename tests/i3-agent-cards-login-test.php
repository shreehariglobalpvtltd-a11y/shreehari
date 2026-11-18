<?php
/**
 * =====================================================================
 *  i3-agent-cards-login-test.php — integration glue of 19 Nov 2026 (i3):
 *
 *    1. the card warm-up (ApCard::prerender) is queued when an ID is
 *       issued: approve, issueCode, reissueCard (a fake hook records it);
 *       on CLI the real one queues nothing (nobody waits for it)
 *    2. AgentPro::approve links a +977 agent to the Nepal desk
 *       np_agent_default_desk ONLY while np_agent_desk_on=1 (default 0)
 *    3. a Nepal member's card: Nepal flag first, "PAN 612454076", never
 *       a bare "CIN "; no number set -> the line is left out
 *    4. admin/quick-unlock.php + admin/setup.php offer only
 *       QuickUnlock::SEC_QUESTIONS_OFFERED (no date of birth) for NEW
 *       answers; SEC_QUESTIONS keeps 'dob' for saved answers
 *    5. agent-quick done page + team-member: A4 sheet links, design picker
 *       (Nepali · English labels; the PDF / visiting-card links follow it)
 *    6. cron/daily-schedule.php (runs nightly; rotate.php is weekly on
 *       live) prunes customer_sessions + admin_sessions; the prunes
 *       delete old ended rows and keep live ones. The real prunes run
 *       with a 365-day window on 400-day-old rows, so rows other suites
 *       seed (<= 200 days) on the shared DB are never touched, and only
 *       this test's own rows are asserted on
 *
 *  Settings are changed IN MEMORY only (the shared test DB is never
 *  written there). Every row it makes is deleted again.
 *
 *    php tests/i3-agent-cards-login-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
define('SHG_APP', true);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/agentpro-card.php';
require_once INCLUDE_PATH . '/quickunlock.php';
require_once INCLUDE_PATH . '/customerprofile.php';
require_once INCLUDE_PATH . '/adminsessions.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }

/** Settings in memory only (the shared test DB is never written). */
function setS(array $kv): void {
    Settings::get('x');
    $r = new ReflectionProperty(Settings::class, 'cache');
    $c = $r->getValue();
    foreach ($kv as $k => $v) {
        if ($v === null) { unset($c[$k]); continue; }
        $c[$k] = ['value' => (string) $v, 'type' => 'string', 'public' => false];
    }
    $r->setValue(null, $c);
    (new ReflectionProperty(Settings::class, 'decoded'))->setValue(null, []);
}

$ROOT = dirname(__DIR__);
$src = static fn(string $rel): string => (string) file_get_contents($ROOT . '/' . $rel);

if (!AgentPro::available()) {
    check('ap_* tables installed', false);
    echo "\nPASSED: $PASS   FAILED: $FAIL\n";
    exit(1);
}

$PH = ['9800000671', '9800000672'];   // Nepal mobiles (977)
$cleanup = static function () use ($PH): void {
    $ids = [];
    foreach (Database::fetchAll("SELECT id FROM admins WHERE full_name LIKE 'ZZI3 %' OR username LIKE 'zzi3%'") as $r) {
        $ids[] = (int) $r['id'];
    }
    foreach ($PH as $p) {
        foreach (Database::fetchAll('SELECT id FROM admins WHERE phone LIKE :p', ['p' => '%' . $p]) as $r) {
            $ids[] = (int) $r['id'];
        }
    }
    foreach (array_unique($ids) as $id) {
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        foreach (['agent_ledger' => 'agent_admin_id', 'agent_payout_requests' => 'agent_admin_id', 'ap_deposit_requests' => 'admin_id',
                  'ap_members' => 'admin_id', 'admin_profiles' => 'admin_id', 'ap_wa_links' => 'admin_id', 'admin_sessions' => 'admin_id',
                  'message_logs' => 'admin_id'] as $t => $c) {
            try { Database::delete($t, $c . ' = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        try { Database::delete('agent_profiles', 'admin_id = :i', ['i' => $id]); } catch (Throwable $e) {}
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    try { Database::delete('ap_applications', "full_name LIKE 'ZZI3 %'"); } catch (Throwable $e) {}
    foreach ($PH as $p) {
        try { Database::delete('ap_wa_state', 'phone LIKE :p', ['p' => '%' . $p]); } catch (Throwable $e) {}
        try { Database::delete('ap_wa_links', 'phone LIKE :p', ['p' => '%' . $p]); } catch (Throwable $e) {}
        try { Database::delete('message_logs', 'to_number LIKE :p', ['p' => '%' . $p]); } catch (Throwable $e) {}
    }
    try { Database::delete('customer_sessions', "label = 'zzi3-test'"); } catch (Throwable $e) {}
};
$cleanup();

setS(['whatsapp_driver' => 'click_to_chat', 'ap_wa_on' => '0', 'ap_ai_on' => '0', 'np_agent_desk_on' => '0', 'np_agent_default_desk' => 'NPJ',
      'ap_card_nepal_first' => '1',
      'nepal_company' => 'Shree Hari Global Pvt Ltd Nepal', 'nepal_office' => 'Pushpalal Chowk, Nepalgunj',
      'nepal_phone' => '+977 98662 01375', 'nepal_whatsapp' => '9779866201375', 'nepal_pan' => '612454076', 'nepal_reg' => '']);

$mgr = (int) Database::insert('admins', ['username' => 'zzi3_mgr', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
    'full_name' => 'ZZI3 Manager', 'phone' => '7000000799', 'role' => 'manager', 'is_active' => 1, 'must_change_pw' => 0]);

$warmed = [];
AgentPro::$cardWarmHook = static function (int $id) use (&$warmed): void { $warmed[] = $id; };

/** The Quick Agent path: name + Nepal mobile, office-verified, submitted, approved. */
$quickAgent = static function (string $name, string $phone) use ($mgr): array {
    $d = AgentPro::newDraft('', $mgr);
    $key = (string) $d['draft_key'];
    AgentPro::saveDraft($key, ['full_name' => $name, 'country' => 'NP'], true);
    AgentPro::officeVerify($key, $mgr, 'i3 test', $phone, 'NP', false);
    $app = AgentPro::submit($key, true, $mgr, false);
    return AgentPro::approve((int) $app['id'], $mgr, false);
};

try {
    /* =============================================================== */
    section('1+2. approve: card warm-up queued; Nepal desk only with the switch on');
    $r1 = $quickAgent('ZZI3 Nepal Off', $PH[0]);
    $id1 = (int) $r1['admin_id'];
    check('approve queued the card warm-up for the new member', in_array($id1, $warmed, true), json_encode($warmed));
    $cc1 = (string) (AgentWallet::profile($id1)['country_code'] ?? '');
    check('the applicant is a +977 member', $cc1 === '977', 'country_code=' . $cc1);
    check('np_agent_desk_on=0 (default): no Nepal desk attached', CounterDesk::forAdmin($id1)['code'] === '',
        CounterDesk::forAdmin($id1)['code']);

    $hasNpj = isset(CounterDesk::nepalDesks()['NPJ']);
    setS(['np_agent_desk_on' => '1']);
    $warmed = [];
    $r2 = $quickAgent('ZZI3 Nepal On', $PH[1]);
    $id2 = (int) $r2['admin_id'];
    check('approve (switch on) queued the warm-up too', in_array($id2, $warmed, true));
    if ($hasNpj) {
        check('np_agent_desk_on=1: +977 agent sits at np_agent_default_desk (NPJ)', CounterDesk::forAdmin($id2)['code'] === 'NPJ',
            CounterDesk::forAdmin($id2)['code']);
    } else {
        echo "  (skipped desk check: no active NPJ desk in this DB)\n";
    }
    setS(['np_agent_desk_on' => '0']);

    $warmed = [];
    AgentPro::reissueCard($id1, $mgr);
    check('reissueCard queues the warm-up (new QR token)', $warmed === [$id1], json_encode($warmed));
    $warmed = [];
    AgentPro::issueCode($id1, $mgr);
    check('issueCode queues the warm-up', $warmed === [$id1], json_encode($warmed));

    // the real warm-up on CLI: nothing queued (nobody should wait for it)
    AgentPro::$cardWarmHook = null;
    AgentPro::warmCards($id1);
    $q = (new ReflectionProperty(AgentPro::class, 'cardWarmQueued'))->getValue();
    check('on CLI the real warm-up queues no shutdown work', $q === [], json_encode($q));
    AgentPro::warmCards(0);
    check('warmCards(0) is a no-op and never throws', true);
    AgentPro::$cardWarmHook = static function (int $id): void { throw new RuntimeException('boom'); };
    $threw = false;
    try { AgentPro::warmCards($id1); } catch (Throwable $e) { $threw = true; }
    check('a failing warm-up never fails the caller', !$threw);
    AgentPro::$cardWarmHook = null;

    $aq = $src('admin/agent-quick.php');
    check('agent-quick.php: warm-up after the quick add (AgentPro::warmCards)', str_contains($aq, 'AgentPro::warmCards($aid)'));
    check('agent-quick.php: signed A4 ID + visiting card sheets', str_contains($aq, "'sheet-id', 86400") && str_contains($aq, "'sheet-vc', 86400"));
    check('agent-quick.php: design choice over ApCard::VARIANTS', str_contains($aq, 'ApCard::VARIANTS') && str_contains($aq, 'id="qaDesign"'));
    check('agent-quick.php: bilingual buttons', str_contains($aq, '🖨 A4 ID cards · ID कार्ड A4') && str_contains($aq, '🖨 A4 visiting cards · भिजिटिङ कार्ड A4'));
    $u = ApCard::signedUrl($id1, 'sheet-id', 86400, 'heritage');
    parse_str((string) parse_url($u, PHP_URL_QUERY), $qs);
    check('signed sheet link: kind=id, sheet=a4, variant=heritage, valid signature',
        ($qs['kind'] ?? '') === 'id' && ($qs['sheet'] ?? '') === 'a4' && ($qs['variant'] ?? '') === 'heritage'
        && ApCard::checkSig($id1, 'sheet-id', (int) $qs['e'], (string) $qs['k']));

    $tm = $src('admin/team-member.php');
    check('team-member.php: design picker over ApCard::VARIANTS', str_contains($tm, 'in_array($design, ApCard::VARIANTS, true)') && str_contains($tm, '&amp;design='));
    check('team-member.php: A4 sheet links (ID + visiting)', str_contains($tm, '&kind=id&sheet=a4&format=pdf') && str_contains($tm, '&kind=vc&sheet=a4&format=pdf'));
    check('team-member.php: the PDF link follows the picked design', str_contains($tm, "'&pdf=1' . \$designQ"));
    check('agent-quick.php: the design pick also moves the visiting-card link', str_contains($aq, 'id="qaVcOne"') && str_contains($aq, 'data-vcone='));
    foreach (['admin/agent-quick.php' => $aq, 'admin/team-member.php' => $tm] as $page => $s) {
        check("$page: design labels are Nepali · English",
            str_contains($s, 'शाही सुनौलो · Royal Gold') && str_contains($s, 'आधुनिक नीलो · Modern Blue') && str_contains($s, 'नेपाल-भारत सम्पदा · Heritage')
            && !str_contains($s, "'👑 Royal'") && !str_contains($s, "'🏛 Nepal-India Heritage'"));
    }

    /* =============================================================== */
    section('3. Nepal member card: flag first, PAN, never "CIN "');
    $m = AgentPro::member($id1) ?? [];
    check('member(): flagFirst NP for a +977 member', ($m['flagFirst'] ?? '') === 'NP', (string) ($m['flagFirst'] ?? ''));
    $pm = ApCard::prepare($m);
    check('card: Nepal flag first', !empty($pm['nepalFirst']));
    check('card: Nepal company', ($pm['company']['entity'] ?? '') === 'NP');
    check('registration line = "PAN 612454076"', ApCard::regLine($pm['company']) === 'PAN 612454076', ApCard::regLine($pm['company']));
    foreach (ApCard::VARIANTS as $v) {
        $txt = [];
        foreach (['id-front', 'id-back', 'vc-front', 'vc-back'] as $side) {
            foreach (ApCard::scene($m, $side, $v)->ops as $op) {
                if (($op['t'] ?? '') === 'text') { $txt[] = (string) $op['text']; }
            }
        }
        $bareCin = array_filter($txt, static fn(string $t): bool => preg_match('/^\s*CIN\b/', $t) === 1);
        check("$v: PAN printed, no CIN line", in_array('PAN 612454076', $txt, true) && $bareCin === [], implode(' | ', $bareCin));
    }
    setS(['nepal_pan' => '', 'nepal_reg' => '']);
    $m0 = ApCard::prepare(AgentPro::member($id1) ?? []);
    check('no Nepal number set: the line is left out (no bare "CIN "/"PAN ")', ApCard::regLine($m0['company']) === '', ApCard::regLine($m0['company']));
    setS(['nepal_pan' => '612454076']);
    setS(['ap_card_nepal_first' => '0']);
    $mi = AgentPro::member($id1) ?? [];
    check('ap_card_nepal_first=0: India flag first again', ($mi['flagFirst'] ?? '') === 'IN' && empty(ApCard::prepare($mi)['nepalFirst']));
    setS(['ap_card_nepal_first' => '1']);

    /* =============================================================== */
    section('4. security question: no date of birth for NEW answers');
    check('SEC_QUESTIONS_OFFERED has no dob', !isset(QuickUnlock::SEC_QUESTIONS_OFFERED['dob']) && count(QuickUnlock::SEC_QUESTIONS_OFFERED) >= 3);
    check('SEC_QUESTIONS keeps dob for saved answers', isset(QuickUnlock::SEC_QUESTIONS['dob']));
    foreach (['admin/quick-unlock.php', 'admin/setup.php'] as $page) {
        $s = $src($page);
        check("$page: choices come from SEC_QUESTIONS_OFFERED", substr_count($s, 'QuickUnlock::SEC_QUESTIONS_OFFERED') === 2, (string) substr_count($s, 'QuickUnlock::SEC_QUESTIONS_OFFERED'));
        check("$page: no SEC_QUESTIONS[...] / foreach SEC_QUESTIONS left", preg_match('/SEC_QUESTIONS(\[| as )/', $s) !== 1);
        check("$page: no date-of-birth input switch", !str_contains($s, "=== 'dob'") && !str_contains($s, "==='dob'"));
    }
    // a posted 'dob' key now resolves to no question, so secProblem refuses it
    $q = QuickUnlock::SEC_QUESTIONS_OFFERED['dob'] ?? '';
    check("a posted sec_q=dob is refused (no question)", QuickUnlock::secProblem($q, '1990-01-01') !== null);

    /* =============================================================== */
    section('6. daily prune of sign-in rows');
    $day = $src('cron/daily-schedule.php');
    check('cron/daily-schedule.php calls CustomerProfile::prune()', str_contains($day, 'CustomerProfile::prune()'));
    check('cron/daily-schedule.php calls AdminSessions::prune()', str_contains($day, 'AdminSessions::prune()'));
    check('cron/daily-schedule.php logs the counts', str_contains($day, "\$result['sessionsPurged'] = \$sessionsPurged"));
    $rot = $src('cron/rotate.php');
    check('cron/rotate.php (weekly) no longer prunes sign-in rows', !str_contains($rot, 'CustomerProfile::prune') && !str_contains($rot, 'AdminSessions::prune'));
    $hb = $src('cron/health-heartbeat.php');
    check('daily-schedule.php is a daily job in health-heartbeat', (bool) preg_match("/'daily-schedule\\.php'\\s*=>\\s*\\['every'\\s*=>\\s*1440/", $hb));

    if (CustomerProfile::rememberReady()) {
        $old = date('Y-m-d H:i:s', time() - 400 * 86400);
        $now = date('Y-m-d H:i:s');
        $oldId = (int) Database::insert('customer_sessions', ['user_id' => 4000000001, 'token_hash' => hash('sha256', 'zzi3-old-' . random_int(1, PHP_INT_MAX)),
            'label' => 'zzi3-test', 'created_at' => $old, 'last_seen' => $old, 'expires_at' => $old, 'ended_at' => $old]);
        $liveId = (int) Database::insert('customer_sessions', ['user_id' => 4000000001, 'token_hash' => hash('sha256', 'zzi3-live-' . random_int(1, PHP_INT_MAX)),
            'label' => 'zzi3-test', 'created_at' => $now, 'last_seen' => $now, 'expires_at' => date('Y-m-d H:i:s', time() + 30 * 86400)]);
        $n = CustomerProfile::prune(365);
        check('CustomerProfile::prune deletes an old ended row', !Database::exists('SELECT 1 FROM customer_sessions WHERE id = :i', ['i' => $oldId]), "n=$n");
        check('…and keeps a live remembered phone', Database::exists('SELECT 1 FROM customer_sessions WHERE id = :i', ['i' => $liveId]));
    } else {
        echo "  (skipped customer prune: customer_sessions not installed)\n";
    }
    if (AdminSessions::rememberReady()) {
        $old = date('Y-m-d H:i:s', time() - 400 * 86400);
        $now = date('Y-m-d H:i:s');
        $a = Database::insert('admin_sessions', ['token_hash' => hash('sha256', 'zzi3-a-old-' . random_int(1, PHP_INT_MAX)), 'admin_id' => $mgr,
            'via' => 'password', 'created_at' => $old, 'last_seen' => $old, 'ended_at' => $old]);
        $b = Database::insert('admin_sessions', ['token_hash' => hash('sha256', 'zzi3-a-rem-' . random_int(1, PHP_INT_MAX)), 'admin_id' => $mgr,
            'via' => 'password', 'created_at' => $old, 'last_seen' => $old, 'remember_until' => date('Y-m-d H:i:s', time() + 86400)]);
        $c = Database::insert('admin_sessions', ['token_hash' => hash('sha256', 'zzi3-a-new-' . random_int(1, PHP_INT_MAX)), 'admin_id' => $mgr,
            'via' => 'password', 'created_at' => $now, 'last_seen' => $now]);
        $n = AdminSessions::prune(365);
        $has = static fn($i): bool => Database::exists('SELECT 1 FROM admin_sessions WHERE id = :i', ['i' => (int) $i]);
        check('AdminSessions::prune deletes a 400-day-old ended row', !$has($a), "n=$n");
        check('…keeps an old row whose remember window is still open', $has($b));
        check('…keeps a live session', $has($c));
    } else {
        echo "  (skipped admin prune: remember columns not installed)\n";
    }
} finally {
    AgentPro::$cardWarmHook = null;
    $cleanup();
}
$left = (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE full_name LIKE 'ZZI3 %' OR username LIKE 'zzi3%'", [], 0)
      + (int) Database::scalar("SELECT COUNT(*) FROM customer_sessions WHERE label = 'zzi3-test'", [], 0);
check('test rows cleaned up', $left === 0, "$left left");

echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
