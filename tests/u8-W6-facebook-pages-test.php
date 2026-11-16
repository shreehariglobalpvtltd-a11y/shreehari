<?php
/**
 * =====================================================================
 *  u8-W6-facebook-pages-test.php — Master v2 P6-8a/b pages (16 Nov 2026)
 *
 *    1. the menu: "📣 FB पोस्ट · FB posts" right after Facebook Messenger
 *       AI, with the same gate (dashboard.view)
 *    2. admin/social-posts.php: the three tabs, publishing OFF shown;
 *       a bad CSRF changes nothing; save → a DRAFT (audited); a picture
 *       from another site is refused with the text kept; edit; approve;
 *       back to draft; delete; "Draft from timetable" makes a draft only;
 *       "Draft from offer" with no running offer is refused; a support
 *       login (no Settings permission) sees the lists, no editor, and its
 *       POST changes nothing; a scanner login is turned away; without the
 *       table the page shows a notice, not an error
 *    3. admin/support-inbox.php: a ticket from source 'facebook' reads
 *       "Facebook" with the Messenger icon, links the Messenger inbox
 *       instead of an empty phone, and the "facebook" door filter works
 *
 *  No Graph call, no setting written (Settings cache in the child only).
 *  Every row it makes is deleted.
 *
 *    php -c .claude/php-dev.ini tests/u8-W6-facebook-pages-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child: one page in its own process ---- */
if (($argv[1] ?? '') === '--child') {
    $cfg  = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $page = (string) $cfg['page'];
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.86';
    $_SERVER['SCRIPT_NAME']    = '/admin/' . $page;
    $_SERVER['REQUEST_URI']    = '/admin/' . $page;
    $_GET  = (array) ($cfg['get'] ?? []);
    $post  = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) $cfg['admin']]);
    if ($a === null) { fwrite(STDERR, "no such admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if (isset($cfg['role'])) {                     // a hand-built session: this role, no extra grant
        $_SESSION[ADMIN_SESSION_KEY]['role'] = (string) $cfg['role'];
        $_SESSION[ADMIN_SESSION_KEY]['permissions'] = [];
    }
    Settings::get('company_name');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    $c['staff_setup_wizard'] = ['value' => '0', 'type' => 'bool', 'public' => false];
    $rp->setValue(null, $c);
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
require_once INCLUDE_PATH . '/socialposts.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { exit("Refusing to run: DB_NAME is not shari_test.\n"); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
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
$noErr = static fn(string $h): bool => !preg_match('/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught)/', $h);

echo "\n=== U8 W6 Facebook pages ===\n\n";
if (!SocialPosts::ready(true)) {
    echo "  \033[31mFAIL\033[0m  social_posts missing: php tests/apply-sql.php database/upgrade-2026-11-16b-W6-facebook.sql\n";
    exit(1);
}
$super = (int) Database::scalar("SELECT MIN(id) FROM admins WHERE is_active = 1 AND role = 'superadmin'", [], 0);
if ($super <= 0) { echo "  SKIP  no active super-admin\n"; exit(0); }
$startId    = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM social_posts', [], 0);
$startAudit = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$tag        = 'U8W6' . random_int(1000, 9999);
$ticketId   = 0;
$renamed    = false;
$newest = static fn(): ?array => Database::fetch('SELECT * FROM social_posts WHERE id > :s ORDER BY id DESC LIMIT 1', ['s' => $startId]);
$audit  = static fn(string $action, int $id): ?array => Database::fetch(
    "SELECT * FROM audit_logs WHERE id > :a AND action = :ac AND entity_type = 'social_post' AND entity_id = :i ORDER BY id DESC LIMIT 1",
    ['a' => $startAudit, 'ac' => $action, 'i' => (string) $id]);
$P = static fn(array $post, array $more = []): string => child(['page' => 'social-posts.php', 'admin' => $GLOBALS['super'], 'post' => $post] + $more);

try {
    echo "-- 1. the menu\n";
    $nav = json_decode(trim(child(['what' => 'nav', 'page' => 'x', 'admin' => $super])), true) ?: [];
    $hrefs = array_column($nav, 'href');
    $at = array_search('social-posts.php', $hrefs, true);
    $fb = array_search('facebook.php', $hrefs, true);
    check('the menu carries FB posts', $at !== false && ($nav[$at]['label'] ?? '') === '📣 FB पोस्ट · FB posts');
    // admin_nav() re-sorts each trunk by THIS admin's usage (OpsHub::score), so the
    // order is checked where the menu is written: the next entry after facebook.php
    preg_match_all("/'href'\s*=>\s*'([^']+)'/", (string) file_get_contents(dirname(__DIR__) . '/admin/_guard.php'), $mm);
    $defs = $mm[1] ?? [];
    $fbDef = array_search('facebook.php', $defs, true);
    check('…right after Facebook Messenger AI', $at !== false && $fb !== false && $fbDef !== false && ($defs[$fbDef + 1] ?? '') === 'social-posts.php');
    check('…with the same gate as facebook.php (dashboard.view)', $at !== false && $nav[$at]['perm'] === 'dashboard.view' && $nav[$fb]['perm'] === 'dashboard.view');

    echo "\n-- 2. admin/social-posts.php\n";
    $g = child(['page' => 'social-posts.php', 'admin' => $super]);
    check('the page renders without a warning', $noErr($g) && str_contains($g, 'FB posts'), substr(strip_tags($g), 0, 200));
    check('the three tabs', str_contains($g, 'ड्राफ्ट · Draft') && str_contains($g, 'स्वीकृत · Approved') && str_contains($g, 'प्रकाशित · Published'));
    check('publishing OFF is shown', str_contains($g, 'Publishing OFF'));
    check('the editor and the two quick-draft buttons', str_contains($g, 'नयाँ पोस्ट · New post') && str_contains($g, 'Draft from offer') && str_contains($g, 'Draft from timetable'));
    check('every form carries the CSRF token', substr_count($g, 'name="' . CSRF_TOKEN_NAME . '"') >= 3);

    $before = (int) Database::scalar('SELECT COUNT(*) FROM social_posts', [], 0);
    $h = $P(['action' => 'save', 'id' => '0', 'topic' => $tag . ' csrf', 'text' => 'nope', 'publish_at' => date('Y-m-d\TH:i', time() + 7200)], ['csrf' => 'bad']);
    check('a bad CSRF token changes nothing', (int) Database::scalar('SELECT COUNT(*) FROM social_posts', [], 0) === $before && str_contains($h, 'Session expired'));

    $when = date('Y-m-d', strtotime('+3 days')) . 'T09:30';
    $P(['action' => 'save', 'id' => '0', 'topic' => $tag . ' new', 'text' => "नमस्ते — {$tag} post", 'media_path' => '/assets/img/favicon-32.png', 'link_url' => 'https://www.shreehariglobal.in/', 'publish_at' => $when]);
    $row = $newest();
    $id  = (int) ($row['id'] ?? 0);
    check('Save makes a DRAFT with what was typed', $row !== null && $row['status'] === 'draft' && $row['caption_ne'] === "नमस्ते — {$tag} post"
        && $row['media_path'] === 'assets/img/favicon-32.png' && $row['publish_at'] === str_replace('T', ' ', $when) . ':00' && (int) $row['created_by'] === $super);
    $ad = $audit('social.draft', $id);
    check('…audited with who did it', $ad !== null && (int) $ad['actor_id'] === $super);

    $h = $P(['action' => 'save', 'id' => '0', 'topic' => $tag . ' foreign', 'text' => 'Keep this text ' . $tag, 'media_path' => 'https://evil.example.com/x.png', 'publish_at' => $when]);
    check('a picture from another site is refused, nothing saved', ($newest()['id'] ?? 0) == $id && str_contains($h, 'our own website'));
    check('…and the typed text is kept in the form', str_contains($h, 'Keep this text ' . $tag));

    $h = child(['page' => 'social-posts.php', 'admin' => $super, 'get' => ['edit' => (string) $id]]);
    check('Edit opens the draft in the editor', str_contains($h, 'Edit draft #' . $id) && str_contains($h, "नमस्ते — {$tag} post"));
    $P(['action' => 'save', 'id' => (string) $id, 'topic' => $tag . ' new', 'text' => 'Edited ' . $tag, 'media_path' => '', 'link_url' => '', 'publish_at' => $when]);
    $row = SocialPosts::get($id);
    check('Save on a draft edits it (picture removed → text post)', $row['caption_ne'] === 'Edited ' . $tag && $row['kind'] === 'text' && $row['media_path'] === null);
    check('…the edit is audited old → new', ($ed = $audit('social.edit', $id)) !== null && str_contains((string) $ed['old_value'], 'नमस्ते') && str_contains((string) $ed['new_value'], 'Edited'));

    $P(['action' => 'approve', 'id' => (string) $id]);
    check('Approve → approved, audited', SocialPosts::get($id)['status'] === 'approved' && (int) SocialPosts::get($id)['approved_by'] === $super && $audit('social.approved', $id) !== null);
    $h = child(['page' => 'social-posts.php', 'admin' => $super, 'get' => ['tab' => 'approved']]);
    check('the Approved tab lists it with Back to draft', str_contains($h, 'Edited ' . $tag) && str_contains($h, 'Back to draft') && $noErr($h));
    $h = $P(['action' => 'save', 'id' => (string) $id, 'topic' => 'x', 'text' => 'sneaky edit', 'publish_at' => $when]);
    check('an approved post cannot be edited', SocialPosts::get($id)['caption_ne'] === 'Edited ' . $tag && str_contains($h, 'Back to draft'));
    $P(['action' => 'delete', 'id' => (string) $id]);
    check('an approved post cannot be deleted', SocialPosts::get($id)['status'] === 'approved');

    /* a login without the Settings permission */
    $sv = child(['page' => 'social-posts.php', 'admin' => $super, 'role' => 'support', 'get' => ['tab' => 'approved']]);
    check('support sees the lists but no editor and no buttons', str_contains($sv, 'Edited ' . $tag) && !str_contains($sv, 'नयाँ पोस्ट') && !str_contains($sv, 'Back to draft') && $noErr($sv));
    $h = $P(['action' => 'to_draft', 'id' => (string) $id], ['role' => 'support']);
    check('…and its POST changes nothing', SocialPosts::get($id)['status'] === 'approved' && str_contains($h, 'Settings permission'));
    $sc = child(['page' => 'social-posts.php', 'admin' => $super, 'role' => 'scanner']);
    check('a scanner login is turned away', !str_contains($sc, 'Edited ' . $tag) && !str_contains($sc, 'नयाँ पोस्ट'));

    $P(['action' => 'to_draft', 'id' => (string) $id]);
    check('Back to draft, audited approved → draft', SocialPosts::get($id)['status'] === 'draft'
        && ($bd = $audit('social.back_to_draft', $id)) !== null && str_contains((string) $bd['old_value'], 'approved') && str_contains((string) $bd['new_value'], 'draft'));
    $P(['action' => 'delete', 'id' => (string) $id]);
    check('Delete removes the draft, audited', Database::fetch('SELECT id FROM social_posts WHERE id = :i', ['i' => $id]) === null && $audit('social.delete', $id) !== null);

    $P(['action' => 'from_timetable']);
    $tt = $newest();
    check('Draft from timetable makes a DRAFT only', $tt !== null && (int) $tt['id'] > $id && $tt['status'] === 'draft' && $tt['caption_ne'] === SocialPosts::timetableText());
    $cnt = (int) Database::scalar('SELECT COUNT(*) FROM social_posts', [], 0);
    require_once INCLUDE_PATH . '/offercard.php';
    if (OfferCard::offer() === null) {
        $h = $P(['action' => 'from_offer']);
        check('Draft from offer with no running offer is refused, nothing made', (int) Database::scalar('SELECT COUNT(*) FROM social_posts', [], 0) === $cnt && str_contains($h, 'No offer is running'));
    } else {
        check('Draft from offer: an offer runs here (refusal not checked)', true);
    }
    $h = $P(['action' => 'publish_now', 'id' => (string) ($tt['id'] ?? 0)]);
    check('there is no "publish now" door', str_contains($h, 'Unknown action') && SocialPosts::get((int) $tt['id'])['status'] === 'draft');

    echo "\n-- 3. admin/support-inbox.php\n";
    $ticketId = (int) Database::insert('support_tickets', [
        'ticket_ref' => $tag . '-FB', 'name' => $tag . ' Messenger visitor', 'phone' => '', 'subject' => 'Help', 'message' => $tag . ' needs a person',
        'category' => 'other', 'priority' => 'normal', 'status' => 'open', 'source' => 'facebook',
    ]);
    $si = child(['page' => 'support-inbox.php', 'admin' => $super, 'get' => ['status' => 'all', 'q' => $tag]]);
    $at = strpos($si, $tag . '-FB');
    $rowHtml = $at !== false ? substr($si, $at, (int) (strpos($si, '</tr>', $at) ?: $at + 3000) - $at) : '';
    check('the Help Desk renders', $noErr($si) && $rowHtml !== '', substr(strip_tags($si), 0, 160));
    check('a Facebook ticket reads "Facebook" with the Messenger icon', str_contains($rowHtml, '<use href="#a-msg"/></svg> Facebook'));
    check('…links the Messenger inbox instead of an empty phone', str_contains($rowHtml, '/admin/messenger-inbox.php') && !str_contains($rowHtml, 'https://wa.me/"'));
    $sf = child(['page' => 'support-inbox.php', 'admin' => $super, 'get' => ['status' => 'all', 'source' => 'facebook']]);
    check('the door filter offers Facebook and finds it', str_contains($sf, '<option value="facebook" selected>Facebook</option>') && str_contains($sf, $tag . '-FB'));

    echo "\n-- 4. without the table\n";
    Database::query('RENAME TABLE social_posts TO social_posts_u8w6p');
    $renamed = true;
    $h = child(['page' => 'social-posts.php', 'admin' => $super]);
    check('the page shows the notice, not an error', str_contains($h, 'not on this server yet') && $noErr($h) && !str_contains($h, 'नयाँ पोस्ट'));
    $h = $P(['action' => 'save', 'id' => '0', 'text' => 'x', 'publish_at' => $when]);
    check('…and a save is refused kindly', str_contains($h, 'not on this server yet') && $noErr($h));
    Database::query('RENAME TABLE social_posts_u8w6p TO social_posts');
    $renamed = false;
} catch (Throwable $e) {
    check('no exception escaped', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($renamed) {
        try { Database::query('RENAME TABLE social_posts_u8w6p TO social_posts'); } catch (Throwable $e) { echo "  !! could not rename social_posts back: " . $e->getMessage() . "\n"; }
    }
    Database::run('DELETE FROM social_posts WHERE id > :s', ['s' => $startId]);
    Database::run("DELETE FROM audit_logs WHERE id > :a AND entity_type = 'social_post' AND CAST(entity_id AS UNSIGNED) > :s", ['a' => $startAudit, 's' => $startId]);
    if ($ticketId > 0) {
        Database::run('DELETE FROM support_messages WHERE ticket_id = :t', ['t' => $ticketId]);
        Database::run('DELETE FROM support_tickets WHERE id = :t', ['t' => $ticketId]);
    }
    check('every test row was removed', (int) Database::scalar('SELECT COUNT(*) FROM social_posts WHERE id > :s', ['s' => $startId], 0) === 0
        && (int) Database::scalar('SELECT COUNT(*) FROM support_tickets WHERE ticket_ref LIKE :t', ['t' => $tag . '%'], 0) === 0);
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
