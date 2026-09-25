<?php
/**
 * social-posts-test.php — the marketing queue (24 Sep 2026).
 *
 *   A. create(): a channel we know, a caption in some language, a picture
 *      for Instagram, a sane time; lands as a draft.
 *   B. caption(): Nepali, Hindi, English in that order, the link once.
 *   C. work(): idle while off; on, publishes only approved + due rows,
 *      claims before sending, retries twice then fails, honours the cap.
 *   D. setStatus(): a published post cannot be pulled back.
 *   E. draft() without a key: the plain template with the live fare.
 *   F. cron/social-publish.php runs and reports.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/socialposts.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function fails(callable $fn, string $needle): bool {
    try { $fn(); return false; } catch (RuntimeException $e) { return str_contains($e->getMessage(), $needle); }
}

$wasOn  = Settings::getBool('social_publish_on', false);
$wasCap = Settings::getInt('social_daily_cap', 6);
$restore = static function () use ($wasOn, $wasCap): void {
    Settings::set('social_publish_on', $wasOn ? '1' : '0', 'bool', 'marketing');
    Settings::set('social_daily_cap', (string) $wasCap, 'int', 'marketing');
    Settings::flush(); SocialPosts::$publisher = null;
};
Database::run("DELETE FROM social_posts WHERE topic LIKE 'SPT %'");

echo "-- A. create --\n";
check('an unknown channel is refused', fails(static fn() => SocialPosts::create(['channel' => 'tiktok', 'caption_en' => 'x'], 1), 'facebook, instagram or telegram'));
check('no caption is refused', fails(static fn() => SocialPosts::create(['channel' => 'telegram'], 1), 'caption'));
check('Instagram without a picture is refused', fails(static fn() => SocialPosts::create(['channel' => 'instagram', 'caption_en' => 'x'], 1), 'picture'));
check('a nonsense time is refused', fails(static fn() => SocialPosts::create(['channel' => 'telegram', 'caption_en' => 'x', 'publish_at' => 'someday'], 1), 'valid publish time'));
$id = SocialPosts::create(['channel' => 'telegram', 'topic' => 'SPT dashain', 'caption_ne' => 'दशैं विशेष', 'caption_hi' => 'दशहरा स्पेशल', 'caption_en' => 'Dashain special', 'link_url' => 'https://www.shreehariglobal.in/', 'publish_at' => '2020-01-01 09:00'], 1);
$row = Database::fetch('SELECT * FROM social_posts WHERE id = :id', ['id' => $id]);
check('a good post lands as a draft', $row !== null && $row['status'] === 'draft' && $row['kind'] === 'text' && (int) $row['created_by'] === 1);

echo "\n-- B. caption --\n";
$cap = SocialPosts::caption($row);
check('Nepali first, then Hindi, then English', strpos($cap, 'दशैं') < strpos($cap, 'दशहरा') && strpos($cap, 'दशहरा') < strpos($cap, 'Dashain'));
check('the link once, at the end', substr_count($cap, 'https://www.shreehariglobal.in/') === 1 && str_ends_with($cap, 'https://www.shreehariglobal.in/'));

echo "\n-- C. work --\n";
Settings::set('social_publish_on', '0', 'bool', 'marketing'); Settings::flush();
$r = SocialPosts::work();
check('off: idle, nothing sent', ($r['idle'] ?? '') !== '' && $r['sent'] === 0);
Settings::set('social_publish_on', '1', 'bool', 'marketing'); Settings::set('social_daily_cap', '6', 'int', 'marketing'); Settings::flush();
$sent = [];
SocialPosts::$publisher = static function (array $post, string $caption) use (&$sent): string { $sent[] = ['id' => (int) $post['id'], 'caption' => $caption]; return 'remote-' . $post['id']; };
$r = SocialPosts::work();
check('on: a draft is not sent', $r['sent'] === 0 && $sent === []);
SocialPosts::setStatus($id, 'approved', 1);
$r = SocialPosts::work();
$row = Database::fetch('SELECT * FROM social_posts WHERE id = :id', ['id' => $id]);
check('an approved, due post goes out once', $r['sent'] === 1 && count($sent) === 1 && $sent[0]['id'] === $id);
check('…with the three-language caption', str_contains($sent[0]['caption'], 'दशैं') && str_contains($sent[0]['caption'], 'Dashain'));
check('…and is marked published with the remote id', $row['status'] === 'published' && $row['remote_id'] === 'remote-' . $id && $row['published_at'] !== null && (int) $row['attempts'] === 1);
$r = SocialPosts::work();
check('running again sends nothing more', $r['sent'] === 0 && count($sent) === 1);
$future = SocialPosts::create(['channel' => 'facebook', 'topic' => 'SPT later', 'caption_en' => 'Later', 'publish_at' => '2099-01-01 09:00'], 1);
SocialPosts::setStatus($future, 'approved', 1);
$r = SocialPosts::work();
check('a post scheduled for later waits', $r['sent'] === 0 && count($sent) === 1);
$bad = SocialPosts::create(['channel' => 'facebook', 'topic' => 'SPT fails', 'caption_en' => 'Will fail', 'publish_at' => '2020-01-01 09:00'], 1);
SocialPosts::setStatus($bad, 'approved', 1);
SocialPosts::$publisher = static function (array $post, string $caption): string { throw new RuntimeException('Graph API: token expired'); };
$r = SocialPosts::work();
$row = Database::fetch('SELECT status, attempts, error FROM social_posts WHERE id = :id', ['id' => $bad]);
check('a failed send goes back to approved with the error', $r['failed'] === 1 && $row['status'] === 'approved' && (int) $row['attempts'] === 1 && str_contains((string) $row['error'], 'token expired'));
SocialPosts::work(); SocialPosts::work();
$row = Database::fetch('SELECT status, attempts FROM social_posts WHERE id = :id', ['id' => $bad]);
check('the third failure parks it as failed', $row['status'] === 'failed' && (int) $row['attempts'] === 3, $row['status'] . '/' . $row['attempts']);
SocialPosts::work();
$row = Database::fetch('SELECT attempts FROM social_posts WHERE id = :id', ['id' => $bad]);
check('…and it is never tried again', (int) $row['attempts'] === 3);
Settings::set('social_daily_cap', '1', 'int', 'marketing'); Settings::flush();
SocialPosts::$publisher = static fn(array $p, string $c): string => 'r';
$capped = SocialPosts::create(['channel' => 'telegram', 'topic' => 'SPT capped', 'caption_en' => 'Capped', 'publish_at' => '2020-01-01 09:00'], 1);
SocialPosts::setStatus($capped, 'approved', 1);
$r = SocialPosts::work();
check('the daily cap holds the next post', $r['sent'] === 0 && $r['skipped'] !== [] && str_contains((string) $r['skipped'][0], 'daily cap'), json_encode($r['skipped']));
check('sentToday() counts what went out', SocialPosts::sentToday() >= 1);

echo "\n-- D. status rules --\n";
check('a published post cannot be pulled back', fails(static fn() => SocialPosts::setStatus($id, 'draft', 1), 'already published'));
check('only draft / approved / cancelled by hand', fails(static fn() => SocialPosts::setStatus($capped, 'published', 1), 'by hand'));
check('an unknown post is refused', fails(static fn() => SocialPosts::setStatus(99999999, 'approved', 1), 'not found'));
SocialPosts::setStatus($capped, 'cancelled', 1);
check('cancel clears the error and the approver', ($x = Database::fetch('SELECT status, approved_by, error FROM social_posts WHERE id = :id', ['id' => $capped])) !== null && $x['status'] === 'cancelled' && $x['approved_by'] === null && $x['error'] === null);

echo "\n-- E. the Telegram URL --\n";
/* A bot token is 123456789:AAH-secret, and the colon must reach Telegram
   as a colon: percent-encoding it into the path answers 404 for every post,
   which is how this was found. Nothing here touches the network. */
check('a real-looking token is carried into the path unchanged',
    SocialPosts::telegramUrl('123456789:AAH-ExampleSecretToken_1234567', 'sendMessage') === 'https://api.telegram.org/bot123456789:AAH-ExampleSecretToken_1234567/sendMessage');
check('sendPhoto is built the same way',
    str_ends_with(SocialPosts::telegramUrl('123456789:AAH-ExampleSecretToken_1234567', 'sendPhoto'), '/sendPhoto'));
foreach (['', 'abc', '123:short', '123456789:AAH/../escape-the-path-1234567', '123456789:AAH space in it 12345678'] as $bad) {
    check('a token that could not be real, or could escape the path, is refused: "' . mb_substr($bad, 0, 18) . '"',
        fails(static fn() => SocialPosts::telegramUrl($bad, 'sendMessage'), 'Telegram'));
}
check('a made-up method name is refused', fails(static fn() => SocialPosts::telegramUrl('123456789:AAH-ExampleSecretToken_1234567', '../../evil'), 'method'));

echo "\n-- F. draft without a key --\n";
$wasKey = Settings::getString('anthropic_api_key', '');
Settings::set('anthropic_api_key', '', 'string', 'ai'); Settings::flush();
$d = SocialPosts::draft('SPT Dashain');
require_once INCLUDE_PATH . '/fare.php';
$fare = number_format((float) Fare::dirFares()['toNepal']);
check('three languages come back', $d['en'] !== '' && $d['hi'] !== '' && $d['ne'] !== '');
check('with the live sharing fare and the topic', str_contains($d['en'], '₹' . $fare) && str_contains($d['en'], 'SPT Dashain') && str_contains($d['ne'], '₹' . $fare));
check('and the office phone', str_contains($d['hi'], Settings::officePhone()));
Settings::set('anthropic_api_key', $wasKey, 'string', 'ai'); Settings::flush();

echo "\n-- G. the cron --\n";
$out = (string) shell_exec(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/cron/social-publish.php') . ' 2>&1');
check('cron/social-publish.php runs and reports', $out !== '' && (str_contains($out, 'sent') || str_contains($out, 'idle') || str_contains($out, 'off')), trim(strtok($out, "\n")));

Database::run("DELETE FROM social_posts WHERE topic LIKE 'SPT %'");
$restore();

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
