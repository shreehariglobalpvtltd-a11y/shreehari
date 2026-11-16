<?php
/**
 * =====================================================================
 *  u9-X4-fb-comments-test.php — Facebook Page comments (Master v2
 *  P6-6b + P6-7b, 16 Nov 2026; includes/fbcomments.php,
 *  cron/fb-comments.php, admin/fb-comments.php).
 *
 *   A. classify(): fare / how-to-book / both; complaint, abuse and an
 *      angry emoji → negative; their own ticket, a PNR or a phone number
 *      → personal; a link → other.
 *   B. replyText(): fixed words in the writer's language, the fare page
 *      (appUrl ne/hi/en bus), the booking link + office number; short.
 *   C. poll() with a fake Graph: switch off → no call; on → the Page's
 *      posts (2 pages, the paging link's token dropped), a post with no
 *      comment not opened, comments stored once, older than 7 days left
 *      out, the Page's own reply marks its thread answered, a complaint /
 *      abuse flagged, a hidden comment hidden (and back), no token in any
 *      URL.
 *   D. permission errors: recorded ONCE, the next run makes no call, it
 *      asks again after 6 hours, a good answer clears it, a refusal to
 *      REPLY never stops the reading; "Check again" clears it (audited);
 *      the real settings row is written and put back.
 *   E. autoReply(): off → nothing; on → one reply per thread to the
 *      thread's top comment, fare → fare page, book → link + office
 *      number; personal / flagged / older than 24 h / already answered
 *      never; claimed before sending (no second post, even after a failed
 *      send); 20 an hour; a permission refusal gives the claim back.
 *   F. officeReply(): switch off / empty / our own Page refused; posted to
 *      the thread's top; status, kind, who, audit old → new; a refusal is
 *      kept and audited.
 *   G. suggest(): the WhatsApp FAQ draft is side-effect free; never for a
 *      complaint.
 *   H. admin/fb-comments.php: the menu entry beside FB posts with the
 *      Messenger inbox's gate; the three tabs; the public warning; a POST
 *      reply (fake Graph); a bad CSRF posts nothing; a scanner is turned
 *      away.
 *   I. cron/fb-comments.php: idle while off; idle without the table.
 *
 *  Settings are changed for THIS process only, except section D's one
 *  real write, which is put back. Every row it makes is deleted.
 *
 *    php -c .claude/php-dev.ini tests/u9-X4-fb-comments-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

const U9X4_PAGE = '990417990417';

/** The fake Graph API: state lives in $GLOBALS['G']. */
function fakeGraph(): Closure
{
    return static function (string $method, string $url, array $params): array {
        $G = &$GLOBALS['G'];
        $G['calls'][] = [$method, $url, $params];
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        if ($method === 'GET' && ($G['readError'] ?? null) !== null) {
            return ['error' => $G['readError']];
        }
        if ($method === 'GET' && str_ends_with($path, '/posts')) {
            if (($q['after'] ?? '') === 'p2') {
                return ['data' => $G['posts2'] ?? []];
            }
            return ['data' => $G['posts1'] ?? [], 'paging' => ['next' => 'https://graph.facebook.com/v21.0/' . U9X4_PAGE . '/posts?access_token=LEAKEDTOKEN&limit=10&after=p2']];
        }
        if ($method === 'GET' && str_ends_with($path, '/comments')) {
            $post = basename(dirname($path));
            return ['data' => $G['comments'][$post] ?? []];
        }
        if ($method === 'POST' && str_ends_with($path, '/comments')) {
            $target = basename(dirname($path));
            $G['posted'][] = [$target, (string) ($params['message'] ?? '')];
            $mode = $G['postMode'] ?? 'ok';
            if ($mode === 'perm') {
                return ['error' => ['message' => '(#200) The page needs the pages_manage_engagement permission', 'code' => 200]];
            }
            if ($mode === 'fail') {
                return ['error' => ['message' => 'An unexpected error has occurred. Please retry your request later.', 'code' => 2]];
            }
            $G['n'] = ($G['n'] ?? 0) + 1;
            return ['id' => '5550' . $G['n']];
        }
        return ['error' => ['message' => 'unexpected call ' . $method . ' ' . $path, 'code' => 0]];
    };
}

/* ---- child: one admin page in its own process ---- */
if (($argv[1] ?? '') === '--child') {
    $cfg  = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $page = (string) $cfg['page'];
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.94';
    $_SERVER['SCRIPT_NAME']    = '/admin/' . $page;
    $_SERVER['REQUEST_URI']    = '/admin/' . $page;
    $_GET  = (array) ($cfg['get'] ?? []);
    $post  = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    require_once INCLUDE_PATH . '/fbcomments.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) $cfg['admin']]);
    if ($a === null) { fwrite(STDERR, "no such admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if (isset($cfg['role'])) {
        $_SESSION[ADMIN_SESSION_KEY]['role'] = (string) $cfg['role'];
        $_SESSION[ADMIN_SESSION_KEY]['permissions'] = [];
    }
    Settings::get('company_name');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    $c['staff_setup_wizard'] = ['value' => '0', 'type' => 'bool', 'public' => false];
    foreach ((array) ($cfg['settings'] ?? []) as $k => $v) {
        $c[$k] = ['value' => (string) $v[0], 'type' => (string) $v[1], 'public' => false];
    }
    $rp->setValue(null, $c);
    // admin/fb-comments.php calls Settings::flush(); keep this child's settings through it
    $GLOBALS['__u9x4_cache'] = $c;
    FbComments::$memState = [];
    $GLOBALS['G'] = ['calls' => [], 'posted' => []];
    FbComments::$transport = fakeGraph();
    register_shutdown_function(static function (): void {
        if (($GLOBALS['G']['posted'] ?? []) !== []) {
            echo "\n<!--U9X4POSTED " . json_encode($GLOBALS['G']['posted'], JSON_UNESCAPED_UNICODE) . "-->";
        }
    });
    if (($cfg['what'] ?? '') === 'nav') {
        require_once dirname(__DIR__) . '/admin/_guard.php';
        echo json_encode(array_map(static fn(array $n): array => ['href' => (string) $n['href'], 'perm' => (string) $n['perm'], 'label' => (string) $n['label']], admin_nav()), JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME]  = ($cfg['csrf'] ?? 'valid') === 'valid' ? Security::csrfToken() : 'not-the-token';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    }
    require dirname(__DIR__) . '/admin/' . $page;
    exit(0);
}

define('SHG_APP', true);
chdir(dirname(__DIR__));
require 'includes/bootstrap.php';
require_once INCLUDE_PATH . '/fbcomments.php';
require_once INCLUDE_PATH . '/socialposts.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
/** A setting for THIS process only (the shared row is never written). */
function setLocal(string $k, string $v, string $type = 'string'): void {
    Settings::get($k);
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    $c[$k] = ['value' => $v, 'type' => $type, 'public' => false];
    $rp->setValue(null, $c);
}
function child(array $cfg): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out . $err;
}
function cronRun(): array {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(dirname(__DIR__) . '/cron/fb-comments.php');
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ['', -1]; }
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [$out, proc_close($p)];
}
function ct(int $secondsAgo): string { return gmdate('Y-m-d\TH:i:s', time() - $secondsAgo) . '+0000'; }
function cm(string $id, string $msg, int $ago, string $fromId, string $name, string $parent = '', bool $hidden = false): array {
    $c = ['id' => $id, 'message' => $msg, 'created_time' => ct($ago), 'from' => ['id' => $fromId, 'name' => $name], 'is_hidden' => $hidden];
    if ($parent !== '') { $c['parent'] = ['id' => $parent]; }
    return $c;
}
function row(string $cid): ?array { return Database::fetch('SELECT * FROM fb_comments WHERE comment_id = :c', ['c' => $cid]); }

echo "\n=== U9 X4 Facebook Page comments ===\n\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') {
    echo "  \033[33mSKIP\033[0m  DB_NAME is not shari_test — refusing to write\n";
    exit(0);
}
if (!FbComments::ready(true)) {
    echo "  \033[31mFAIL\033[0m  fb_comments missing: php tests/apply-sql.php database/upgrade-2026-11-16c-X4-fb-comments.sql\n";
    exit(1);
}
$admin      = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1", [], 0);
if ($admin <= 0) { echo "  SKIP  no active super-admin\n"; exit(0); }
$startAudit = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$stateRow   = Database::fetch("SELECT svalue, stype FROM settings WHERE skey = 'fb_comments_state'");
$switchRows = static fn(): string => (string) json_encode(Database::fetchAll(
    "SELECT skey, svalue, stype FROM settings WHERE skey IN ('fb_comments_on', 'fb_comment_ai_on', 'fb_page_token', 'fb_page_id') ORDER BY skey"));
$switches0  = $switchRows();
$renamed    = false;
$P  = U9X4_PAGE;
$A  = $P . '_11';          // a post with comments
$B  = $P . '_12';          // a post nobody commented on
$C  = $P . '_13';          // a post on the second page of posts
$id = static fn(int $n): string => '9904170011_' . $n;
$audit = static fn(string $action, int $rowId): ?array => Database::fetch(
    "SELECT * FROM audit_logs WHERE id > :a AND action = :ac AND entity_type = 'fb_comment' AND entity_id = :i ORDER BY id DESC LIMIT 1",
    ['a' => $startAudit, 'ac' => $action, 'i' => (string) $rowId]);
$cleanup = static function () use ($P): void {
    Database::run("DELETE FROM fb_comments WHERE post_id LIKE :p OR comment_id LIKE '9904170011\\_%' OR comment_id LIKE '9904179999\\_%'", ['p' => $P . '\\_%']);
};
$G = ['calls' => [], 'posted' => []];

try {
    $cleanup();
    FbComments::$memState  = [];
    FbComments::$transport = fakeGraph();
    setLocal('fb_comments_on', '0', 'bool');
    setLocal('fb_comment_ai_on', '0', 'bool');
    setLocal('fb_page_token', 'EAAu9x4testtokenxxxxxxxxxxxxxxxxxxxxxxxxxx', 'string');
    setLocal('fb_page_id', $P, 'string');

    echo "-- A. classify --\n";
    $k = static fn(string $t): string => FbComments::classify($t)['kind'];
    check('a fare question (romanised Nepali)', $k('Surat bata Rupaidiha bhada kati ho?') === 'fare');
    check('a fare question (Devanagari)', $k('रुपैडिहा सम्म भाडा कति हो?') === 'fare');
    check('a fare question (English "price?")', $k('Price?') === 'fare');
    check('a how-to-book question', $k('Ticket kasari book garne?') === 'book' && $k('How to book? Number please') === 'book');
    check('fare + booking in one', $k('fare kati ho ani booking kasari?') === 'fare+book');
    check('a complaint is negative', $k('Worst service, thagi company') === 'negative' && $k('maile paisa tire tara ticket aayena') === 'negative');
    check('…in Devanagari too', $k('ठगी कम्पनी हो यो') === 'negative');
    check('an abusive word is negative', $k('muji company') === 'negative' && FbComments::classify('muji company')['reason'] !== '');
    check('an angry emoji is negative', $k('bus 😡') === 'negative');
    check('"latest" is not "late" (whole words)', $k('latest timetable?') !== 'negative');
    check('their own ticket is personal', $k('mero ticket ko seat number kun ho?') === 'personal');
    check('a PNR is personal', $k('SHG-AB12CD bhada kati?') === 'personal');
    check('a phone number is personal', $k('call me 9812345678 fare?') === 'personal');
    check('a link is left to the office', $k('cheap fare www.example.com') === 'other');
    check('"facebook" is not "book"', $k('nice facebook page') === 'other');
    check('a thank-you is other', $k('Jay ho 🙏') === 'other' && $k('') === 'other');

    echo "\n-- B. the fixed reply --\n";
    $rNe = FbComments::replyText('fare', 'Surat bata Rupaidiha bhada kati ho?');
    $rHi = FbComments::replyText('fare', 'Surat se Rupaidiha ka kiraya kitna hai?');
    $rEn = FbComments::replyText('fare', 'What is the price?');
    check('fare (Nepali): the Nepali fare page', str_contains($rNe, appUrl('ne/bus')) && str_contains($rNe, 'भाडा'), $rNe);
    check('fare (Hindi): the Hindi fare page', str_contains($rHi, appUrl('hi/bus')) && str_contains($rHi, 'किराया'), $rHi);
    check('fare (English): the fare page', str_contains($rEn, appUrl('bus')) && !str_contains($rEn, '/ne/bus'), $rEn);
    $rB = FbComments::replyText('book', 'How to book?');
    check('book: the booking link + the office number line', str_contains($rB, '🎫 Book online: ' . appUrl('')) && str_contains($rB, '📞 Office: ' . Settings::officePhone() . ' (call / WhatsApp)'), $rB);
    $rFB = FbComments::replyText('fare+book', 'fare kati ho ani booking kasari?');
    check('fare + book: both, still short', str_contains($rFB, appUrl('ne/bus')) && str_contains($rFB, appUrl('')) && mb_strlen($rFB) < 300 && substr_count($rFB, "\n") <= 2);
    check('no markdown, no invented fare', !str_contains($rFB, '**') && !preg_match('/₹\s*\d/u', $rNe . $rB . $rFB));

    echo "\n-- C. reading the comments --\n";
    $G['posts1'] = [
        ['id' => $A, 'message' => 'Daily bus Gujarat → Rupaidiha', 'created_time' => ct(7200), 'comments' => ['summary' => ['total_count' => 9]]],
        ['id' => $B, 'message' => 'Tihar offer', 'created_time' => ct(9000), 'comments' => ['summary' => ['total_count' => 0]]],
    ];
    $G['posts2'] = [['id' => $C, 'message' => 'New timetable', 'created_time' => ct(86400 * 2), 'comments' => ['summary' => ['total_count' => 1]]]];
    $G['comments'][$A] = [            // newest first, as Meta sends them
        cm($id(9012), 'muji company', 300, '7012', 'Bad Guy'),
        cm($id(9005), 'bhada kati ho, 2 jana?', 900, '7005', 'Sita', $id(9001)),
        cm($id(9004), 'mero ticket ko seat number kun ho?', 1200, '7004', 'Hari'),
        cm($id(9002), 'Ticket kasari book garne?', 1500, '7002', 'Gita'),
        cm($id(9001), 'Surat bata Rupaidiha bhada kati ho?', 1800, '7001', 'Ram B'),
        cm($id(9003), 'Worst service, thagi company 😡', 2000, '7003', 'Angry One'),
        cm($id(9008), 'Namaste! Fare is on our website.', 2400, $P, 'S Hari Global', $id(9007)),
        cm($id(9007), 'price?', 3000, '7007', 'Old Asker'),
        cm($id(9009), 'spam spam', 3200, '7009', 'Spammer', '', true),
        cm($id(9010), 'fare kati?', 30 * 3600, '7010', 'Yesterday'),
        cm($id(9011), 'fare kati?', 8 * 86400, '7011', 'Too Old'),
    ];
    $G['comments'][$C] = [cm($id(9020), 'How to book? Number please', 600, '7020', 'Asha')];

    $G['calls'] = [];
    $o = FbComments::poll();
    check('switch off: nothing is read, no Graph call', isset($o['idle']) && $G['calls'] === [] && row($id(9001)) === null, (string) ($o['idle'] ?? ''));
    setLocal('fb_comments_on', '1', 'bool');
    $o = FbComments::poll();
    check('switch on: three posts over two pages', (int) $o['posts'] === 3, json_encode($o));
    check('…eleven new comments, two flagged', (int) $o['new'] === 11 && (int) $o['flagged'] === 2, json_encode($o));
    $urls = implode(' ', array_column($G['calls'], 1));
    check('…no token in any URL (the paging link\'s token dropped)', !str_contains($urls, 'access_token') && !str_contains($urls, 'LEAKEDTOKEN') && !str_contains($urls, 'EAAu9x4'));
    check('…a post with no comment is not opened', !str_contains($urls, $B . '/comments') && str_contains($urls, $A . '/comments') && str_contains($urls, $C . '/comments'));
    check('…older than 7 days left out', row($id(9011)) === null && row($id(9010)) !== null);
    $r1 = row($id(9001));
    check('…stored: name, post, thread, new', $r1 !== null && $r1['from_name'] === 'Ram B' && $r1['post_id'] === $A && $r1['thread_id'] === $id(9001)
        && $r1['parent_id'] === null && $r1['status'] === 'new' && (int) $r1['is_page'] === 0 && str_starts_with((string) $r1['post_text'], 'Daily bus'));
    check('…a reply keeps its thread', row($id(9005))['parent_id'] === $id(9001) && row($id(9005))['thread_id'] === $id(9001));
    check('…a complaint is flagged with its reason', row($id(9003))['status'] === 'flagged' && str_contains((string) row($id(9003))['flagged_reason'], 'complaint'));
    check('…abuse is flagged', row($id(9012))['status'] === 'flagged');
    check('…the Page\'s own reply is ours and marks its thread answered',
        (int) row($id(9008))['is_page'] === 1 && row($id(9008))['status'] === 'replied' && row($id(9007))['status'] === 'replied');
    check('…a hidden comment is hidden', row($id(9009))['status'] === 'hidden');
    check('…no Facebook user id is kept', !Database::exists("SELECT 1 FROM fb_comments WHERE from_name LIKE '70%' OR message LIKE '%7001%'"));

    $o = FbComments::poll();
    check('read again: nothing new, nothing doubled', (int) $o['new'] === 0
        && (int) Database::scalar('SELECT COUNT(*) FROM fb_comments WHERE post_id IN (:a, :c)', ['a' => $A, 'c' => $C], 0) === 11);
    $G['comments'][$A][8]['is_hidden'] = false;
    FbComments::poll();
    check('un-hidden on Facebook → new again', row($id(9009))['status'] === 'new');
    $G['comments'][$A][8]['is_hidden'] = true;
    FbComments::poll();
    check('…and hidden again', row($id(9009))['status'] === 'hidden');
    $c = FbComments::counts();
    check('counts: comments / flagged / all', $c['flagged'] >= 2 && $c['all'] >= 11 && $c['comments'] === $c['all'] - (int) Database::scalar('SELECT COUNT(*) FROM fb_comments WHERE is_page = 1', [], 0));
    $fl = FbComments::listTab('flagged');
    check('the Flagged tab holds only flagged comments', $fl !== [] && array_unique(array_column($fl, 'status')) === ['flagged']);
    $all = FbComments::listTab('all');
    check('All holds our Page\'s reply too; Comments does not', in_array($id(9008), array_column($all, 'comment_id'), true)
        && !in_array($id(9008), array_column(FbComments::listTab('comments'), 'comment_id'), true));
    check('newest first', array_column(FbComments::listTab('comments'), 'comment_id')[0] === $id(9012));
    // review 16 Nov: the flag survives hide / show; a hidden complaint is classified; an edit into a complaint is flagged
    $G['comments'][$A][0]['is_hidden'] = true;
    FbComments::poll();
    check('a flagged comment hidden on Facebook → hidden', row($id(9012))['status'] === 'hidden');
    $G['comments'][$A][0]['is_hidden'] = false;
    FbComments::poll();
    check('…shown again → flagged again, not new', row($id(9012))['status'] === 'flagged' && (string) row($id(9012))['flagged_reason'] !== '');
    $G['comments'][$A][] = cm($id(9013), 'thagi company ho yo', 2500, '7013', 'Hidden Angry', '', true);
    $G['comments'][$A][] = cm($id(9014), 'bhada kati?', 2600, '7014', 'Editor');
    FbComments::poll();
    check('a complaint first read while hidden keeps its reason', row($id(9013))['status'] === 'hidden' && (string) row($id(9013))['flagged_reason'] !== '');
    $nA = count($G['comments'][$A]);
    $G['comments'][$A][$nA - 2]['is_hidden'] = false;
    $G['comments'][$A][$nA - 1]['message'] = 'bhada kati? worst service, thagi';
    FbComments::poll();
    check('…shown on Facebook → flagged', row($id(9013))['status'] === 'flagged');
    check('a question edited into a complaint is flagged', row($id(9014))['status'] === 'flagged' && str_contains((string) row($id(9014))['flagged_reason'], 'complaint'));

    echo "\n-- D. permission errors --\n";
    $G['readError'] = ['message' => '(#10) This endpoint requires the pages_read_engagement permission', 'code' => 10];
    $o = FbComments::poll();
    $st = FbComments::state();
    check('a refusal to read is recorded', isset($o['refused']) && str_contains((string) $st['error'], 'pages_read_engagement') && (int) $st['code'] === 10 && (int) $st['tries'] === 1);
    $G['calls'] = [];
    $o = FbComments::poll();
    check('…once: the next run makes no Graph call at all', isset($o['idle']) && $G['calls'] === [] && (int) FbComments::state()['tries'] === 1);
    FbComments::$memState['at'] = date('Y-m-d H:i:s', time() - 7 * 3600);
    $since = FbComments::$memState['since'];
    FbComments::poll();
    check('…after 6 hours it asks once more (same error: no new record)', count($G['calls']) === 1 && (int) FbComments::state()['tries'] === 2 && FbComments::state()['since'] === $since);
    $G['readError'] = null;
    FbComments::$memState['at'] = date('Y-m-d H:i:s', time() - 7 * 3600);
    FbComments::poll();
    check('…a good answer clears it', FbComments::state() === []);
    FbComments::$memState = ['error' => '(#200) needs pages_manage_engagement', 'code' => 200, 'where' => 'reply', 'at' => date('Y-m-d H:i:s'), 'tries' => 1];
    $G['calls'] = [];
    FbComments::poll();
    check('a refusal to REPLY never stops the reading', $G['calls'] !== [] && FbComments::waiting('reply') && !FbComments::waiting('read'));
    FbComments::clearError($admin);
    check('"Check again" clears it, audited', FbComments::state() === [] && Database::exists(
        "SELECT 1 FROM audit_logs WHERE id > :a AND action = 'fb.comments_recheck'", ['a' => $startAudit]));
    // the real settings row: written once, then put back
    FbComments::$memState = null;
    FbComments::recordError('(#10) u9x4 test permission', 10, 'posts');
    Settings::flush();
    $real = Settings::getArray('fb_comments_state', []);
    check('the real settings row fb_comments_state holds it (JSON)', ($real['error'] ?? '') === '(#10) u9x4 test permission' && (string) Database::scalar("SELECT stype FROM settings WHERE skey = 'fb_comments_state'", [], '') === 'json');
    FbComments::clearError($admin);
    Settings::flush();
    check('…and "Check again" empties it', Settings::getArray('fb_comments_state', ['x']) === []);
    FbComments::$memState = [];
    setLocal('fb_comments_on', '1', 'bool');
    setLocal('fb_comment_ai_on', '0', 'bool');
    setLocal('fb_page_token', 'EAAu9x4testtokenxxxxxxxxxxxxxxxxxxxxxxxxxx', 'string');
    setLocal('fb_page_id', $P, 'string');

    echo "\n-- E. automatic replies --\n";
    $G['posted'] = [];
    $o = FbComments::autoReply();
    check('AI off: nothing is posted', $G['posted'] === [] && ($o['ai'] ?? '') === 'off');
    setLocal('fb_comment_ai_on', '1', 'bool');
    $o = FbComments::autoReply();
    $targets = array_column($G['posted'], 0);
    check('AI on: three replies', (int) $o['auto'] === 3 && count($G['posted']) === 3, json_encode($o));
    check('…to the fare question, the booking question and the other post\'s question', $targets === [$id(9001), $id(9002), $id(9020)], implode(',', $targets));
    check('…the fare reply carries the fare page (Nepali)', str_contains($G['posted'][0][1], appUrl('ne/bus')));
    check('…the booking reply carries the link + office number', str_contains($G['posted'][1][1], appUrl('')) && str_contains($G['posted'][1][1], Settings::officePhone()));
    check('…never the personal question, the complaint, the abuse, the answered thread, the hidden one or yesterday\'s',
        !array_intersect([$id(9004), $id(9003), $id(9012), $id(9007), $id(9009), $id(9010)], $targets));
    check('…one per thread: the second fare question in the same thread waits for the office', row($id(9005))['status'] === 'new' && row($id(9005))['reply_kind'] === null);
    $r1 = row($id(9001));
    check('…stored: replied, auto, Meta\'s id, the words', $r1['status'] === 'replied' && $r1['reply_kind'] === 'auto' && $r1['our_reply_id'] === '55501' && $r1['reply_text'] === $G['posted'][0][1]);
    check('…audited', $audit('fb.comment_auto_reply', (int) $r1['id']) !== null);
    $G['posted'] = [];
    FbComments::autoReply();
    check('a second run posts nothing again', $G['posted'] === []);

    // a reply under a thread a person already answered, read back from Facebook, keeps the AI out
    $G['comments'][$C][] = cm($id(9021), '55503 reply', 500, $P, 'S Hari Global', $id(9020));
    $G['comments'][$C][] = cm($id(9022), 'bhada kati ho?', 400, '7022', 'Mohan', $id(9020));
    FbComments::poll();
    $G['posted'] = [];
    FbComments::autoReply();
    check('a fare question under an answered thread is left to the office', $G['posted'] === [] && row($id(9022))['status'] === 'new');

    // 20 an hour
    for ($i = 1; $i <= 20; $i++) {
        Database::insert('fb_comments', ['comment_id' => '9904179999_' . $i, 'post_id' => $P . '_99', 'thread_id' => '9904179999_' . $i,
            'message' => 'x', 'created_time' => date('Y-m-d H:i:s'), 'status' => 'replied', 'reply_kind' => 'auto', 'replied_at' => date('Y-m-d H:i:s')]);
    }
    $G['comments'][$C][] = cm($id(9030), 'fare kati?', 200, '7030', 'Capped');
    FbComments::poll();
    $G['posted'] = [];
    $o = FbComments::autoReply();
    check('at 20 automatic replies this hour: none more', $G['posted'] === [] && str_contains((string) ($o['ai'] ?? ''), 'hourly cap') && row($id(9030))['reply_kind'] === null);
    Database::run("DELETE FROM fb_comments WHERE comment_id LIKE '9904179999\\_%'");
    // review 16 Nov: 60 older thank-yous (never answered, so still 'new') must not hide the fresh question below
    for ($i = 1; $i <= 60; $i++) {
        Database::insert('fb_comments', ['comment_id' => '9904179999_t' . $i, 'post_id' => $P . '_98', 'thread_id' => '9904179999_t' . $i,
            'message' => 'Jay ho 🙏', 'created_time' => date('Y-m-d H:i:s', time() - 3600 - $i), 'status' => 'new']);
    }

    // a failed send: claimed, never sent twice
    $G['postMode'] = 'fail';
    $G['posted'] = [];
    $o = FbComments::autoReply();
    $r30 = row($id(9030));
    check('60 older thank-yous do not hide a fresh fare question', ($G['posted'][0][0] ?? '') === $id(9030));
    Database::run("DELETE FROM fb_comments WHERE comment_id LIKE '9904179999\\_%'");
    check('a failed send keeps the claim and the reason', (int) $o['auto_failed'] === 1 && $r30['reply_kind'] === 'auto' && $r30['status'] === 'new'
        && str_contains((string) $r30['reply_error'], 'unexpected') && count($G['posted']) === 1);
    $G['postMode'] = 'ok';
    $G['posted'] = [];
    FbComments::autoReply();
    check('…and the next run does not post it again', $G['posted'] === []);

    // a permission refusal: nothing went out, the claim is given back
    $G['comments'][$C][] = cm($id(9031), 'price?', 100, '7031', 'Perm');
    FbComments::poll();
    $G['postMode'] = 'perm';
    $o = FbComments::autoReply();
    $r31 = row($id(9031));
    check('a permission refusal gives the claim back and is recorded', $r31['reply_kind'] === null && $r31['replied_at'] === null
        && str_contains((string) $r31['reply_error'], 'pages_manage_engagement') && FbComments::state()['where'] === 'reply' && isset($o['refused']));
    $G['posted'] = [];
    $o = FbComments::autoReply();
    check('…then the AI waits (no call) while reading goes on', $G['posted'] === [] && ($o['ai'] ?? '') === 'waiting');
    FbComments::$memState = [];
    $G['postMode'] = 'ok';

    echo "\n-- F. the office's public reply --\n";
    $r4 = row($id(9004));
    setLocal('fb_comments_on', '0', 'bool');
    $G['posted'] = [];
    $res = FbComments::officeReply((int) $r4['id'], 'Namaste Hari ji, hamro office le call garcha.', $admin);
    check('switch off: refused, nothing posted', !$res['ok'] && str_contains($res['why'], 'fb_comments_on') && $G['posted'] === []);
    setLocal('fb_comments_on', '1', 'bool');
    check('empty: refused', !FbComments::officeReply((int) $r4['id'], "  \n ", $admin)['ok']);
    check('too long: refused', !FbComments::officeReply((int) $r4['id'], str_repeat('a', 1001), $admin)['ok']);
    check('our own Page\'s comment: refused', !FbComments::officeReply((int) row($id(9008))['id'], 'x', $admin)['ok'] && $G['posted'] === []);
    $res = FbComments::officeReply((int) $r4['id'], "Namaste Hari ji,\nhamro office le call garcha.", $admin);
    $r4b = row($id(9004));
    check('posted in public under its thread', $res['ok'] && $G['posted'] === [[$id(9004), "Namaste Hari ji,\nhamro office le call garcha."]]);
    check('…stored: replied, office, who, when', $r4b['status'] === 'replied' && $r4b['reply_kind'] === 'office' && (int) $r4b['replied_by'] === $admin
        && $r4b['our_reply_id'] !== null && $r4b['replied_at'] !== null);
    $a = $audit('fb.comment_reply', (int) $r4['id']);
    check('…audited with old → new', $a !== null && str_contains((string) $a['old_value'], '"new"') && str_contains((string) $a['new_value'], '"replied"') && str_contains((string) $a['new_value'], 'office'));
    $G['posted'] = [];
    FbComments::officeReply((int) row($id(9005))['id'], 'Namaste Sita ji!', $admin);
    check('a reply to a reply goes under the thread\'s top comment', ($G['posted'][0][0] ?? '') === $id(9001));
    $G['postMode'] = 'fail';
    $res = FbComments::officeReply((int) row($id(9003))['id'], 'Sorry, please call the office.', $admin);
    check('a refusal is shown, kept and audited', !$res['ok'] && str_contains($res['why'], 'unexpected')
        && str_contains((string) row($id(9003))['reply_error'], 'unexpected') && row($id(9003))['status'] === 'flagged'
        && $audit('fb.comment_reply_failed', (int) row($id(9003))['id']) !== null);
    $G['postMode'] = 'ok';

    echo "\n-- G. the suggested draft --\n";
    $calls0 = (int) Database::scalar('SELECT COUNT(*) FROM ai_agent_calls', [], 0);
    $sug = FbComments::suggest('Surat bata Rupaidiha bhada kati ho?');
    check('the WhatsApp FAQ draft writes nothing', (int) Database::scalar('SELECT COUNT(*) FROM ai_agent_calls', [], 0) === $calls0, $sug === null ? '(no draft)' : mb_substr($sug, 0, 80));
    check('…and is never offered for a complaint', FbComments::suggest('Worst service, thagi company') === null);

    echo "\n-- H. the page --\n";
    $nav = json_decode(trim(child(['what' => 'nav', 'page' => 'x', 'admin' => $admin])), true) ?: [];
    $hrefs = array_column($nav, 'href');
    $at = array_search('fb-comments.php', $hrefs, true);
    $mi = array_search('messenger-inbox.php', $hrefs, true);
    check('the menu carries FB comments', $at !== false && ($nav[$at]['label'] ?? '') === '💬 FB कमेन्ट · FB comments');
    preg_match_all("/'href'\s*=>\s*'([^']+)'/", (string) file_get_contents(dirname(__DIR__) . '/admin/_guard.php'), $mm);
    $defs = $mm[1] ?? [];
    $sp = array_search('social-posts.php', $defs, true);
    check('…right after FB posts', $sp !== false && ($defs[$sp + 1] ?? '') === 'fb-comments.php');
    check('…with the Messenger inbox\'s gate (customers.view)', $at !== false && $mi !== false && $nav[$at]['perm'] === 'customers.view' && $nav[$mi]['perm'] === 'customers.view');

    $on = ['fb_comments_on' => ['1', 'bool'], 'fb_comment_ai_on' => ['0', 'bool'], 'fb_page_token' => ['EAAu9x4testtokenxxxxxxxxxxxxxxxxxxxxxxxxxx', 'string'], 'fb_page_id' => [$P, 'string']];
    $noErr = static fn(string $h): bool => !preg_match('/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught)/', $h);
    $g = child(['page' => 'fb-comments.php', 'admin' => $admin, 'settings' => $on]);
    check('Comments tab renders', $noErr($g) && str_contains($g, 'Ram B') && str_contains($g, 'Reading ON') && !str_contains($g, 'EAAu9x4'), substr(strip_tags($g), 0, 160));
    check('…the three tabs', str_contains($g, 'टिप्पणी · Comments') && str_contains($g, 'फ्ल्याग · Flagged') && str_contains($g, 'सबै · All'));
    $gf = child(['page' => 'fb-comments.php', 'admin' => $admin, 'settings' => $on, 'get' => ['tab' => 'flagged']]);
    check('Flagged tab: the complaint, not the fare question', $noErr($gf) && str_contains($gf, 'Angry One') && !str_contains($gf, 'Ram B'));
    $r22 = (int) row($id(9022))['id'];
    $go = child(['page' => 'fb-comments.php', 'admin' => $admin, 'settings' => $on, 'get' => ['tab' => 'comments', 'c' => (string) $r22]]);
    check('an open reply box warns that the reply is public', $noErr($go) && str_contains($go, 'This reply is public on the Page') && str_contains($go, 'name="text"'));
    $off = child(['page' => 'fb-comments.php', 'admin' => $admin, 'settings' => ['fb_comments_on' => ['0', 'bool']]]);
    check('switch off: the page says so and offers no reply', $noErr($off) && str_contains($off, 'Reading OFF') && !str_contains($off, 'जवाफ लेख्ने · Reply'));
    $bad = child(['page' => 'fb-comments.php', 'admin' => $admin, 'settings' => $on, 'csrf' => 'bad', 'post' => ['action' => 'reply', 'id' => (string) $r22, 'text' => 'csrf test']]);
    check('a bad CSRF posts nothing', !str_contains($bad, 'U9X4POSTED') && row($id(9022))['reply_kind'] === null);
    $ok = child(['page' => 'fb-comments.php', 'admin' => $admin, 'settings' => $on, 'post' => ['action' => 'reply', 'id' => (string) $r22, 'text' => 'Namaste Mohan ji! Bhada: ' . appUrl('ne/bus')]]);
    check('a reply from the page is posted (to the thread top) and stored', str_contains($ok, 'U9X4POSTED') && str_contains($ok, $id(9020))
        && row($id(9022))['reply_kind'] === 'office' && row($id(9022))['status'] === 'replied');
    $sc = child(['page' => 'fb-comments.php', 'admin' => $admin, 'role' => 'scanner']);
    check('a scanner login is turned away', !str_contains($sc, 'Ram B') && !str_contains($sc, 'टिप्पणी · Comments'));

    echo "\n-- I. the cron --\n";
    $dbOn = Database::scalar("SELECT svalue FROM settings WHERE skey = 'fb_comments_on'", [], '0');
    [$out, $code] = cronRun();
    if ((string) $dbOn !== '1') {
        check('switch off: the cron is idle and exits 0', $code === 0 && str_contains($out, 'fb_comments_on is off'), trim((string) strtok($out, "\n")));
    } else {
        check('the cron exits 0 (fb_comments_on is on in this database)', $code === 0, trim((string) strtok($out, "\n")));
    }
    Database::query('RENAME TABLE fb_comments TO fb_comments_u9x4');
    $renamed = true;
    [$out, $code] = cronRun();
    check('no table: the cron is idle and exits 0', $code === 0 && str_contains($out, 'fb_comments table missing'), trim((string) strtok($out, "\n")));
    check('no table: lists empty, reply refused, poll idle', !FbComments::ready(true) && FbComments::listTab('all') === [] && FbComments::counts()['all'] === 0
        && !FbComments::officeReply(1, 'x', $admin)['ok'] && isset(FbComments::poll()['idle']));
    $gn = child(['page' => 'fb-comments.php', 'admin' => $admin, 'settings' => $on]);
    check('no table: the page shows a notice, not an error', $noErr($gn) && str_contains($gn, 'upgrade-2026-11-16c-X4-fb-comments.sql'));
    Database::query('RENAME TABLE fb_comments_u9x4 TO fb_comments');
    $renamed = false;
    FbComments::ready(true);

    echo "\n-- J. the timetable post --\n";
    $tt = SocialPosts::timetableText();
    check('the timetable draft says 60 minutes, as the ticket does', $tt === '' || (str_contains($tt, '60 minutes early') && !str_contains($tt, '30 minutes')));
} catch (Throwable $e) {
    check('no exception escaped', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($renamed) {
        try { Database::query('RENAME TABLE fb_comments_u9x4 TO fb_comments'); } catch (Throwable $e) { echo "  !! could not rename fb_comments back: " . $e->getMessage() . "\n"; }
    }
    FbComments::$transport = null;
    FbComments::$memState  = null;
    FbComments::ready(true);
    $cleanup();
    Database::run("DELETE FROM audit_logs WHERE id > :a AND (entity_type = 'fb_comment' OR action = 'fb.comments_recheck')", ['a' => $startAudit]);
    Database::run("DELETE FROM rate_limits WHERE bucket = 'fb_comment_reply' AND identifier = :i", ['i' => (string) $admin]);
    // the one real settings write, put back
    if ($stateRow === null) {
        Database::run("DELETE FROM settings WHERE skey = 'fb_comments_state'");
    } else {
        Database::run("UPDATE settings SET svalue = :v, stype = :t WHERE skey = 'fb_comments_state'", ['v' => $stateRow['svalue'], 't' => $stateRow['stype']]);
    }
    Settings::flush();
    check('every test row was removed', (int) Database::scalar("SELECT COUNT(*) FROM fb_comments WHERE post_id LIKE :p", ['p' => U9X4_PAGE . '\\_%'], 0) === 0);
    check('fb_comments_state is as it was', Database::fetch("SELECT svalue, stype FROM settings WHERE skey = 'fb_comments_state'") == $stateRow);
    check('the switch / token rows in the database were never written', $switchRows() === $switches0);
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
