<?php
/**
 * cron/fb-comments.php — read the comments under our Facebook Page's posts
 * (last 7 days) into fb_comments for Admin → FB comments, and — only while
 * fb_comment_ai_on is on too — answer a fare / how-to-book question with
 * one short public line (Master v2 P6-6b + P6-7b, 16 Nov 2026).
 *
 *   every 10 minutes (see the deploy note: the crontab line is the
 *   runuser www-data form of cron/social-publish.php, written to
 *   /var/log/shg-fb-comments.log)
 *
 * Does nothing while fb_comments_on is off (the default), before the
 * fb_comments table exists, or with no Page token. When Meta refuses for a
 * permission reason the error is written once to the settings row
 * fb_comments_state and the job waits 6 hours (includes/fbcomments.php);
 * that is the token's problem, not the job's, so the heartbeat stays green.
 * Complaints are never answered automatically, and nobody is messaged.
 */

declare(strict_types=1);

require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/fbcomments.php';

try {
    Settings::flush();
    $r  = FbComments::run();
    $ok = true;
} catch (Throwable $e) {
    $r  = ['error' => mb_substr($e->getMessage(), 0, 200)];
    $ok = false;
}
cron_done($r, $ok);
