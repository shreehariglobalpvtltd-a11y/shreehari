<?php
/**
 * =====================================================================
 *  social-posts-test.php — the Facebook post queue (24 Sep 2026; ported
 *  16 Nov 2026 for Master v2 P6-8a/b, includes/socialposts.php).
 *
 *   A. create(): Facebook only, some text, a sane time, a picture only from
 *      our own public folders (another site, a private folder, a path
 *      escape, a missing file and a non-picture refused), a real link;
 *      lands as a DRAFT with an audit row.
 *   B. caption(): Nepali, Hindi, English in that order, the link once.
 *   C. work(): idle while off; on, publishes only approved + due Facebook
 *      rows, claims before sending, retries twice then fails, a permission
 *      refusal fails at once, honours the daily cap.
 *   D. status rules + edit + delete: a published post cannot be pulled
 *      back; edit only a draft (audited old/new); back to draft clears the
 *      tries; delete only what never went out (audited).
 *   E. the Graph call (no network): the Messenger page's token in the POST
 *      body (never the URL), /feed vs /photos, Meta's permission error →
 *      failed with Meta's words, a passing error → retried, no token →
 *      failed; the queue never messages anyone.
 *   F. Draft from timetable / offer: a DRAFT with the active routes' pickup
 *      times / the offer caption; never approved, routes untouched.
 *   G. cron/social-publish.php: idle while off; idle and exit 0 without
 *      the table (renamed for a moment, put back after).
 *
 *  Settings are changed for THIS process only (the shared settings rows
 *  are never written); every row it makes is deleted.
 *
 *    php -c .claude/php-dev.ini tests/social-posts-test.php
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/socialposts.php';
require_once INCLUDE_PATH . '/offercard.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function fails(callable $fn, string $needle): bool {
    try { $fn(); return false; } catch (RuntimeException $e) { return str_contains($e->getMessage(), $needle); }
}
/** A setting for THIS process only (the shared row is never written). */
function setLocal(string $k, string $v, string $type = 'string'): void {
    Settings::get($k);
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    $c[$k] = ['value' => $v, 'type' => $type, 'public' => false];
    $rp->setValue(null, $c);
}
function cronRun(): array {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(dirname(__DIR__) . '/cron/social-publish.php');
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ['', -1]; }
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [$out, proc_close($p)];
}

echo "\n=== Facebook post queue ===\n\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') {
    echo "  \033[33mSKIP\033[0m  DB_NAME is not shari_test — refusing to write\n";
    exit(0);
}
if (!SocialPosts::ready(true)) {
    echo "  \033[31mFAIL\033[0m  social_posts missing: php tests/apply-sql.php database/upgrade-2026-11-16b-W6-facebook.sql\n";
    exit(1);
}
$startId    = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM social_posts', [], 0);
$startAudit = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$admin      = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1", [], 1);
$renamed    = false;
$files      = [];
$audit = static fn(string $action, int $id): ?array => Database::fetch(
    'SELECT * FROM audit_logs WHERE id > :a AND action = :ac AND entity_type = :t AND entity_id = :i ORDER BY id DESC LIMIT 1',
    ['a' => $startAudit, 'ac' => $action, 't' => 'social_post', 'i' => (string) $id]);

try {
    setLocal('social_publish_on', '0', 'bool');
    setLocal('social_daily_cap', '6', 'int');
    setLocal('fb_page_token', '', 'string');
    setLocal('fb_page_id', '', 'string');

    echo "-- A. create --\n";
    check('Instagram / Telegram are not offered', fails(static fn() => SocialPosts::create(['channel' => 'telegram', 'text' => 'x'], $admin), 'Only Facebook'));
    check('no text is refused', fails(static fn() => SocialPosts::create(['text' => "  \n "], $admin), 'post text'));
    check('a nonsense time is refused', fails(static fn() => SocialPosts::create(['text' => 'x', 'publish_at' => 'someday'], $admin), 'valid publish time'));
    check('a picture from another website is refused', fails(static fn() => SocialPosts::create(['text' => 'x', 'media_path' => 'https://evil.example.com/a.png'], $admin), 'our own website'));
    check('a picture from a private folder is refused', fails(static fn() => SocialPosts::create(['text' => 'x', 'media_path' => '/uploads/passengers/a.jpg'], $admin), 'Picture'));
    check('a KYC path is refused', fails(static fn() => SocialPosts::create(['text' => 'x', 'media_path' => 'uploads/agents-kyc/1.jpg'], $admin), 'Picture'));
    check('the company-papers vault is refused', fails(static fn() => SocialPosts::create(['text' => 'x', 'media_path' => '/uploads/company/2026/11/a.png'], $admin), 'Picture')
        && !in_array('uploads/company/', SocialPosts::MEDIA_DIRS, true));
    check('a path escape is refused', fails(static fn() => SocialPosts::create(['text' => 'x', 'media_path' => '/assets/img/../../config/config.png'], $admin), 'Picture'));
    check('a non-picture is refused', fails(static fn() => SocialPosts::create(['text' => 'x', 'media_path' => '/assets/img/x.php'], $admin), 'Picture'));
    check('a missing picture is refused', fails(static fn() => SocialPosts::create(['text' => 'x', 'media_path' => '/assets/img/no-such-file-u8w6.png'], $admin), 'not found'));
    check('a link that is not a web address is refused', fails(static fn() => SocialPosts::create(['text' => 'x', 'link_url' => 'javascript:alert(1)'], $admin), 'web address'));
    check('our own picture by full URL is accepted (stored as a path)', SocialPosts::mediaPath(appUrl('assets/img/favicon-32.png') . '?v=3') === 'assets/img/favicon-32.png');

    $id = SocialPosts::create(['topic' => 'U8W6 tihar', 'caption_ne' => 'तिहार विशेष', 'caption_hi' => 'दिवाली स्पेशल', 'caption_en' => 'Tihar special',
        'link_url' => 'https://www.shreehariglobal.in/', 'publish_at' => '2020-01-01 09:00'], $admin);
    $row = Database::fetch('SELECT * FROM social_posts WHERE id = :id', ['id' => $id]);
    check('a good post lands as a DRAFT', $row !== null && $row['status'] === 'draft' && $row['channel'] === 'facebook' && $row['kind'] === 'text' && (int) $row['created_by'] === $admin);
    check('…with an audit row', $audit('social.draft', $id) !== null);
    $later = date('Y-m-d 09:00', strtotime('+1 year'));
    check('a time more than two years ahead is refused', fails(static fn() => SocialPosts::create(['text' => 'x', 'publish_at' => '2099-01-01 09:00'], $admin), 'valid publish time'));
    $pid = SocialPosts::create(['topic' => 'U8W6 photo', 'text' => 'With a picture', 'media_path' => '/assets/img/favicon-32.png', 'publish_at' => $later], $admin);
    $prow = SocialPosts::get($pid);
    check('a post with our picture is a photo post', $prow['kind'] === 'photo' && $prow['media_path'] === 'assets/img/favicon-32.png' && $prow['caption_ne'] === 'With a picture');

    echo "\n-- B. caption --\n";
    $cap = SocialPosts::caption($row);
    check('Nepali first, then Hindi, then English', strpos($cap, 'तिहार') < strpos($cap, 'दिवाली') && strpos($cap, 'दिवाली') < strpos($cap, 'Tihar'));
    check('the link once, at the end', substr_count($cap, 'https://www.shreehariglobal.in/') === 1 && str_ends_with($cap, 'https://www.shreehariglobal.in/'));

    echo "\n-- C. work --\n";
    $r = SocialPosts::work();
    check('off: idle, nothing sent', ($r['idle'] ?? '') === 'social_publish_on is off' && $r['sent'] === 0);
    setLocal('social_publish_on', '1', 'bool');
    $sent = [];
    SocialPosts::$publisher = static function (array $post, string $caption) use (&$sent): string { $sent[] = ['id' => (int) $post['id'], 'caption' => $caption]; return 'remote-' . $post['id']; };
    $r = SocialPosts::work();
    check('on: a draft is never sent', $r['sent'] === 0 && $sent === []);
    SocialPosts::setStatus($id, 'approved', $admin);
    check('approve is audited old → new', ($a = $audit('social.approved', $id)) !== null && str_contains((string) $a['old_value'], 'draft') && str_contains((string) $a['new_value'], 'approved'));
    $r = SocialPosts::work();
    $row = SocialPosts::get($id);
    check('an approved, due post goes out once', $r['sent'] === 1 && count($sent) === 1 && $sent[0]['id'] === $id);
    check('…with the three-language caption', str_contains($sent[0]['caption'], 'तिहार') && str_contains($sent[0]['caption'], 'Tihar'));
    check('…and is marked published with the remote id', $row['status'] === 'published' && $row['remote_id'] === 'remote-' . $id && $row['published_at'] !== null && (int) $row['attempts'] === 1);
    $r = SocialPosts::work();
    check('running again sends nothing more', $r['sent'] === 0 && count($sent) === 1);
    SocialPosts::setStatus($pid, 'approved', $admin);
    $r = SocialPosts::work();
    check('a post scheduled for later waits', $r['sent'] === 0 && count($sent) === 1);
    $tg = Database::insert('social_posts', ['channel' => 'telegram', 'topic' => 'U8W6 tg', 'caption_en' => 'tg', 'publish_at' => '2020-01-01 09:00', 'status' => 'approved']);
    $r = SocialPosts::work();
    check('an old Telegram row is never sent by this queue', $r['sent'] === 0 && SocialPosts::get($tg)['status'] === 'approved' && (int) SocialPosts::get($tg)['attempts'] === 0);

    $bad = SocialPosts::create(['topic' => 'U8W6 fails', 'text' => 'Will fail', 'publish_at' => '2020-01-01 09:00'], $admin);
    SocialPosts::setStatus($bad, 'approved', $admin);
    SocialPosts::$publisher = static function (array $post, string $caption): string { throw new RuntimeException('Facebook: temporarily unavailable'); };
    $r = SocialPosts::work();
    $row = SocialPosts::get($bad);
    check('a passing failure goes back to approved with the error', $r['failed'] === 1 && $row['status'] === 'approved' && (int) $row['attempts'] === 1 && str_contains((string) $row['error'], 'temporarily'));
    SocialPosts::work(); SocialPosts::work();
    $row = SocialPosts::get($bad);
    check('the third failure parks it as failed', $row['status'] === 'failed' && (int) $row['attempts'] === 3, $row['status'] . '/' . $row['attempts']);
    SocialPosts::work();
    check('…and it is never tried again', (int) SocialPosts::get($bad)['attempts'] === 3);

    $perm = SocialPosts::create(['topic' => 'U8W6 perm', 'text' => 'No permission', 'publish_at' => '2020-01-01 09:00'], $admin);
    SocialPosts::setStatus($perm, 'approved', $admin);
    SocialPosts::$publisher = static function (array $post, string $caption): string {
        throw new RuntimeException('Facebook: (#200) requires pages_manage_posts permission', SocialPosts::E_FINAL);
    };
    $r = SocialPosts::work();
    $row = SocialPosts::get($perm);
    check('a permission refusal fails at once (no retry)', $r['failed'] === 1 && $row['status'] === 'failed' && (int) $row['attempts'] === 1);
    check('…with Meta\'s own sentence kept', str_contains((string) $row['error'], 'pages_manage_posts'));

    setLocal('social_daily_cap', '1', 'int');
    SocialPosts::$publisher = static fn(array $p, string $c): string => 'r';
    $capped = SocialPosts::create(['topic' => 'U8W6 capped', 'text' => 'Capped', 'publish_at' => '2020-01-01 09:00'], $admin);
    SocialPosts::setStatus($capped, 'approved', $admin);
    $r = SocialPosts::work();
    check('the daily cap holds the next post', $r['sent'] === 0 && $r['skipped'] !== [] && str_contains((string) $r['skipped'][0], 'daily cap'), json_encode($r['skipped']));
    check('sentToday() counts what went out', SocialPosts::sentToday() >= 1);
    setLocal('social_daily_cap', '6', 'int');

    echo "\n-- D. status rules, edit, delete --\n";
    check('a published post cannot be pulled back', fails(static fn() => SocialPosts::setStatus($id, 'draft', $admin), 'already published'));
    check('only draft / approved / cancelled by hand', fails(static fn() => SocialPosts::setStatus($capped, 'published', $admin), 'by hand'));
    check('an unknown post is refused', fails(static fn() => SocialPosts::setStatus(99999999, 'approved', $admin), 'not found'));
    check('a failed post is not re-approved directly', fails(static fn() => SocialPosts::setStatus($bad, 'approved', $admin), 'cannot become'));
    check('an approved post cannot be edited', fails(static fn() => SocialPosts::update($capped, ['text' => 'x'], $admin), 'Only a draft'));
    check('a published post cannot be deleted', fails(static fn() => SocialPosts::delete($id, $admin), 'Only a draft'));
    check('an approved post cannot be deleted', fails(static fn() => SocialPosts::delete($capped, $admin), 'Only a draft'));
    SocialPosts::setStatus($bad, 'draft', $admin);
    $row = SocialPosts::get($bad);
    check('back to draft clears the tries and the error', $row['status'] === 'draft' && (int) $row['attempts'] === 0 && $row['error'] === null && $row['approved_by'] === null);
    $later2 = date('Y-m-d', strtotime('+200 days'));
    SocialPosts::update($bad, ['topic' => 'U8W6 fixed', 'text' => 'Fixed text', 'publish_at' => $later2 . 'T10:00'], $admin);
    $row = SocialPosts::get($bad);
    check('a draft can be edited (the form\'s date-time format)', $row['caption_ne'] === 'Fixed text' && $row['topic'] === 'U8W6 fixed' && $row['publish_at'] === $later2 . ' 10:00:00');
    $ed = $audit('social.edit', $bad);
    check('the edit is audited with old and new', $ed !== null && str_contains((string) $ed['old_value'], 'Will fail') && str_contains((string) $ed['new_value'], 'Fixed text'));
    SocialPosts::delete($bad, $admin);
    check('a draft can be deleted', Database::fetch('SELECT id FROM social_posts WHERE id = :i', ['i' => $bad]) === null);
    check('…and the delete is audited with what it was', ($dl = $audit('social.delete', $bad)) !== null && str_contains((string) $dl['old_value'], 'Fixed text'));
    SocialPosts::delete($perm, $admin);
    check('a failed post can be deleted', Database::fetch('SELECT id FROM social_posts WHERE id = :i', ['i' => $perm]) === null);
    $counts = SocialPosts::counts();
    check('counts(): the three tabs', isset($counts['draft'], $counts['approved'], $counts['published']) && $counts['published'] >= 1 && $counts['approved'] >= 1);
    $ids = array_map(static fn(array $r): int => (int) $r['id'], SocialPosts::listTab('approved'));
    check('the Approved tab lists waiting posts', in_array($capped, $ids, true) && in_array($pid, $ids, true));
    $ids = array_map(static fn(array $r): int => (int) $r['id'], SocialPosts::listTab('published'));
    check('the Published tab lists what went out', in_array($id, $ids, true));

    echo "\n-- E. the Graph call (no network) --\n";
    SocialPosts::$publisher = null;
    SocialPosts::setStatus($capped, 'draft', $admin);     // only this section's posts are due now
    $calls = [];
    $answer = ['id' => '1234567890_987654321'];
    SocialPosts::$transport = static function (string $url, array $form) use (&$calls, &$answer): array { $calls[] = ['url' => $url, 'form' => $form]; return $answer; };
    $g1 = SocialPosts::create(['topic' => 'U8W6 g1', 'text' => 'Graph text post', 'link_url' => 'https://example.com/x', 'publish_at' => '2020-01-01 09:00'], $admin);
    SocialPosts::setStatus($g1, 'approved', $admin);
    $r = SocialPosts::work();
    $row = SocialPosts::get($g1);
    check('no Page token: failed at once, nothing called', $r['failed'] === 1 && $calls === [] && $row['status'] === 'failed' && str_contains((string) $row['error'], 'no Page token'));
    SocialPosts::setStatus($g1, 'draft', $admin);
    SocialPosts::setStatus($g1, 'approved', $admin);
    setLocal('fb_page_token', 'EAAU8W6testPageToken0123456789abcdefABCDEF', 'string');
    setLocal('fb_page_id', '1234567890', 'string');
    $r = SocialPosts::work();
    $row = SocialPosts::get($g1);
    check('a text post goes to /<page>/feed on Graph v21.0', count($calls) === 1 && $calls[0]['url'] === 'https://graph.facebook.com/v21.0/1234567890/feed', $calls[0]['url'] ?? '');
    check('the Messenger page\'s token travels in the POST body, never the URL',
        ($calls[0]['form']['access_token'] ?? '') === 'EAAU8W6testPageToken0123456789abcdefABCDEF' && !str_contains($calls[0]['url'], 'EAAU8W6'));
    check('the message is the caption with the link', ($calls[0]['form']['message'] ?? '') === "Graph text post\n\nhttps://example.com/x");
    check('published with Facebook\'s id', $row['status'] === 'published' && $row['remote_id'] === '1234567890_987654321');

    setLocal('fb_page_id', '', 'string');
    $answer = ['id' => '55', 'post_id' => '1234567890_55'];
    check('editing an approved post is refused (it must go back to draft)',
        fails(static fn() => SocialPosts::update($pid, ['text' => 'With a picture', 'publish_at' => '2020-01-01 09:00'], $admin), 'Back to draft'));
    SocialPosts::setStatus($pid, 'draft', $admin);
    SocialPosts::update($pid, ['topic' => 'U8W6 photo', 'text' => 'With a picture', 'media_path' => 'assets/img/favicon-32.png', 'publish_at' => '2020-01-01 09:00'], $admin);
    SocialPosts::setStatus($pid, 'approved', $admin);
    $calls = [];
    SocialPosts::work();
    check('a photo post goes to me/photos with our picture URL', ($calls[0]['url'] ?? '') === 'https://graph.facebook.com/v21.0/me/photos'
        && ($calls[0]['form']['url'] ?? '') === appUrl('assets/img/favicon-32.png') && ($calls[0]['form']['caption'] ?? '') === 'With a picture', $calls[0]['url'] ?? '');
    check('…kept as the post id', SocialPosts::get($pid)['remote_id'] === '1234567890_55');

    $answer = ['error' => ['message' => '(#200) The user hasn\'t authorized the application to perform this action: pages_manage_posts', 'type' => 'OAuthException', 'code' => 200]];
    $g3 = SocialPosts::create(['topic' => 'U8W6 g3', 'text' => 'No permission yet', 'publish_at' => '2020-01-01 09:00'], $admin);
    SocialPosts::setStatus($g3, 'approved', $admin);
    $before = [
        'notifications' => (int) Database::scalar('SELECT COALESCE(MAX(id),0) FROM notifications', [], 0),
        'message_logs'  => (int) Database::scalar('SELECT COALESCE(MAX(id),0) FROM message_logs', [], 0),
        'wa_outbox'     => (int) Database::scalar('SELECT COALESCE(MAX(id),0) FROM wa_outbox', [], 0),
    ];
    SocialPosts::work();
    $row = SocialPosts::get($g3);
    check('Graph permission error → failed on the first try', $row['status'] === 'failed' && (int) $row['attempts'] === 1);
    check('…with Meta\'s sentence and code', str_contains((string) $row['error'], 'pages_manage_posts') && str_contains((string) $row['error'], 'code 200'));
    $mention = 0;
    foreach (['notifications', 'message_logs', 'wa_outbox'] as $t) {
        foreach (Database::fetchAll('SELECT * FROM ' . $t . ' WHERE id > :i', ['i' => $before[$t]]) as $x) {
            $mention += str_contains((string) json_encode($x, JSON_UNESCAPED_UNICODE), 'pages_manage_posts') || str_contains((string) json_encode($x), 'U8W6') ? 1 : 0;
        }
    }
    $src = (string) file_get_contents(INCLUDE_PATH . '/socialposts.php') . (string) file_get_contents(dirname(__DIR__) . '/cron/social-publish.php');
    check('nobody is messaged about it', $mention === 0 && !preg_match('/Notify::|WaSend|WhatsApp::|AdminAlerts|sendText|wa_outbox/', $src));
    check('isFinalError: 190 / 10 / 2xx / a permission sentence are final, a 2 is not',
        SocialPosts::isFinalError(190, 'x') && SocialPosts::isFinalError(10, 'x') && SocialPosts::isFinalError(299, 'x')
        && SocialPosts::isFinalError(100, 'requires pages_manage_posts') && !SocialPosts::isFinalError(2, 'Service temporarily unavailable'));
    $answer = ['error' => ['message' => 'An unexpected error has occurred. Please retry your request later.', 'code' => 2]];
    $g4 = SocialPosts::create(['topic' => 'U8W6 g4', 'text' => 'Passing error', 'publish_at' => '2020-01-01 09:00'], $admin);
    SocialPosts::setStatus($g4, 'approved', $admin);
    SocialPosts::work();
    check('a passing Graph error is retried later', SocialPosts::get($g4)['status'] === 'approved' && (int) SocialPosts::get($g4)['attempts'] === 1);
    SocialPosts::setStatus($g4, 'draft', $admin);
    SocialPosts::$transport = null;

    echo "\n-- F. drafts from the live data --\n";
    setLocal('social_publish_on', '0', 'bool');
    $routesBefore = (string) json_encode(Database::fetchAll('SELECT id, is_active, dep_time FROM routes ORDER BY id'));
    $tt = SocialPosts::timetableText();
    $active = Database::fetchAll('SELECT id, from_city, to_city FROM routes WHERE is_active = 1');
    $allIn = $active !== [];
    foreach ($active as $ar) {
        $allIn = $allIn && str_contains($tt, trim((string) $ar['from_city']) . ' → ' . trim((string) $ar['to_city']));
    }
    check('the timetable names every active route', $allIn, (string) count($active) . ' active');
    $st = Database::fetch("SELECT s.stop_name, s.stop_time FROM route_stops s JOIN routes r ON r.id = s.route_id WHERE r.is_active = 1 AND s.stop_type = 'boarding' AND s.stop_time IS NOT NULL" . Boarding::activeSql('s') . ' ORDER BY r.sort_order, s.sort_order LIMIT 1');
    check('…with a pickup and its time', $st === null || str_contains($tt, date('g:i A', (int) strtotime('2000-01-01 ' . $st['stop_time']))), (string) ($st['stop_name'] ?? ''));
    $inactive = Database::fetch("SELECT from_city, to_city FROM routes WHERE is_active = 0 AND CONCAT(from_city, '→', to_city) NOT IN (SELECT CONCAT(from_city, '→', to_city) FROM routes WHERE is_active = 1) LIMIT 1");
    check('…and no route that is switched off', $inactive === null || !str_contains($tt, trim((string) $inactive['from_city']) . ' → ' . trim((string) $inactive['to_city'])));
    check('…the lowest fares from the fare board', str_contains($tt, '₹' . number_format(OfferCard::fareFrom('go')['min'])));
    // 16 Nov 2026: 60 minutes, as the website, terms, report time, WhatsApp and (now) the ticket say.
    check('…reach the pickup 60 minutes early, as everywhere else', str_contains($tt, '६० मिनेट') && str_contains($tt, '60 minutes early')
        && !str_contains($tt, '30 minutes') && !str_contains($tt, '३० मिनेट'));
    $tid = SocialPosts::fromTimetable($admin);
    $trow = SocialPosts::get($tid);
    check('Draft from timetable makes a DRAFT only', $trow['status'] === 'draft' && $trow['approved_by'] === null && $trow['caption_ne'] === $tt);
    check('…audited', $audit('social.draft', $tid) !== null);
    check('…and changes no route', $routesBefore === (string) json_encode(Database::fetchAll('SELECT id, is_active, dep_time FROM routes ORDER BY id')));

    if (OfferCard::offer() === null) {
        check('Draft from offer with no running offer is refused', fails(static fn() => SocialPosts::fromOffer($admin), 'No offer is running'));
    } else {
        check('Draft from offer: an offer is running here (refusal not checked)', true);
    }
    $offer = ['code' => 'U8W6TEST', 'title' => 'Tihar U8W6', 'type' => 'percent', 'value' => 10.0, 'max' => 0.0, 'min' => 0.0, 'routeId' => null, 'until' => date('Y-m-d', strtotime('+5 days')), 'left' => null, 'ahead' => 24];
    [$ph, $site] = (static function (): array {
        $rm = new ReflectionMethod(SocialPosts::class, 'contact');
        return $rm->invoke(null);
    })();
    $oid = SocialPosts::fromOffer($admin, $offer);
    $orow = SocialPosts::get($oid);
    check('Draft from offer: a DRAFT with the offer caption', $orow['status'] === 'draft' && $orow['caption_ne'] === OfferCard::caption($offer, $ph, $site) && str_contains((string) $orow['caption_ne'], '10%'));
    $media = (string) ($orow['media_path'] ?? '');
    if ($media !== '') {
        $files[] = ROOT_PATH . '/' . $media;
        check('…with the pamphlet saved where Facebook can fetch it', str_starts_with($media, 'uploads/social/offer-u8w6test-') && is_file(ROOT_PATH . '/' . $media) && $orow['kind'] === 'photo');
        SocialPosts::delete($oid, $admin);
        check('…and deleting the draft removes its pamphlet', !is_file(ROOT_PATH . '/' . $media));
    } else {
        check('…no pamphlet (GD not able here) — text only', $orow['kind'] === 'text');
    }

    echo "\n-- G. the cron --\n";
    [$out, $code] = cronRun();
    check('switch off: the cron is idle and exits 0', $code === 0 && str_contains($out, 'social_publish_on is off'), trim((string) strtok($out, "\n")));
    Database::query('RENAME TABLE social_posts TO social_posts_u8w6');
    $renamed = true;
    [$out, $code] = cronRun();
    check('no table: the cron is idle and exits 0', $code === 0 && str_contains($out, 'social_posts table missing'), trim((string) strtok($out, "\n")));
    check('no table: ready() says so and create() refuses kindly', !SocialPosts::ready(true) && fails(static fn() => SocialPosts::create(['text' => 'x'], 1), 'not on this server'));
    check('no table: the tabs are empty, not an error', SocialPosts::listTab('draft') === [] && SocialPosts::counts()['draft'] === 0);
    $pngBefore = count(glob(ROOT_PATH . '/uploads/social/offer-u8w6test-*') ?: []);
    check('no table: Draft from offer refuses and leaves no pamphlet behind',
        fails(static fn() => SocialPosts::fromOffer($admin, $offer), 'not on this server')
        && count(glob(ROOT_PATH . '/uploads/social/offer-u8w6test-*') ?: []) === $pngBefore);
    Database::query('RENAME TABLE social_posts_u8w6 TO social_posts');
    $renamed = false;
    SocialPosts::ready(true);
} catch (Throwable $e) {
    check('no exception escaped', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($renamed) {
        try { Database::query('RENAME TABLE social_posts_u8w6 TO social_posts'); } catch (Throwable $e) { echo "  !! could not rename social_posts back: " . $e->getMessage() . "\n"; }
    }
    SocialPosts::$publisher = null;
    SocialPosts::$transport = null;
    Settings::flush();
    foreach (Database::fetchAll('SELECT media_path FROM social_posts WHERE id > :s AND media_path LIKE :p', ['s' => $startId, 'p' => 'uploads/social/%']) as $m) {
        $files[] = ROOT_PATH . '/' . $m['media_path'];
    }
    Database::run('DELETE FROM social_posts WHERE id > :s', ['s' => $startId]);
    // social_posts is this unit's own table: every post id above the start is a test row
    Database::run("DELETE FROM audit_logs WHERE id > :a AND entity_type = 'social_post' AND CAST(entity_id AS UNSIGNED) > :s", ['a' => $startAudit, 's' => $startId]);
    foreach ($files as $f) { if (is_file($f)) { @unlink($f); } }
    check('every test row was removed', (int) Database::scalar('SELECT COUNT(*) FROM social_posts WHERE id > :s', ['s' => $startId], 0) === 0);
    check('the switch row in the database was never written', Settings::getBool('social_publish_on', false) === false);
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
