<?php
/**
 * cron/social-publish.php — send approved social posts that are due.
 *
 *   every 10 min:  /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/social-publish.php
 *
 *  Behind social_publish_on (OFF) and social_daily_cap. A row is claimed
 *  before it is sent, so a retry never posts twice.
 */

declare(strict_types=1);

require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/socialposts.php';
require_once INCLUDE_PATH . '/health.php';

$t0 = microtime(true);
$r  = SocialPosts::work(5);
try { Health::beat('social-publish', (int) round((microtime(true) - $t0) * 1000), $r, ($r['failed'] ?? 0) === 0); } catch (Throwable $e) {}
cron_done($r, ($r['failed'] ?? 0) === 0);
