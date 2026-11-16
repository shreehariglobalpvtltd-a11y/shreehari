<?php
/**
 * cron/social-publish.php — publish APPROVED Facebook posts whose time has
 * come (Master v2 P6-8a, 16 Nov 2026; queue from 24 Sep 2026).
 *
 *   every 10 min:
 *   4-59/10 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/social-publish.php >> /var/www/shreehariglobal.in/public_html/logs/social-publish.log 2>&1
 *
 * Does nothing while social_publish_on is off (the default) or before the
 * social_posts table exists. Never publishes a draft. A row is claimed
 * before it is sent, so a retry never posts twice; a permission refusal
 * from Meta marks the post failed with Meta's sentence and messages nobody
 * (includes/socialposts.php). A failed post is the post's problem, not the
 * job's, so the heartbeat stays green.
 */

declare(strict_types=1);

require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/socialposts.php';

try {
    Settings::flush();
    $r  = SocialPosts::work(5);
    $ok = true;
} catch (Throwable $e) {
    $r  = ['error' => mb_substr($e->getMessage(), 0, 200)];
    $ok = false;
}
cron_done($r, $ok);
