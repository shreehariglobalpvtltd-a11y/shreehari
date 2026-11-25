<?php
/**
 * cron/seller-day.php — the evening day report on WhatsApp (25 Nov 2026, release dy).
 *
 *   every 15 minutes (crontab "*\/15 * * * *", see deploy/VPS-SETUP.md section 7):
 *   /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/seller-day.php >> .../logs/seller-day.log 2>&1
 *
 * From seller_day_report_hour (default 20 IST) every agent / counter / staff
 * person who sold at least one ticket today gets ONE message, their own day:
 * "नमस्ते <name> जी, आज <BS / AD>: टिकट N, नगद, अनलाइन, ड्यू बाँकी, तपाईंको
 * कमिसन" — free, only inside their open 24 h WhatsApp window; a closed
 * window gets a web push when they have one, else nothing (never a paid
 * template). The owner gets one total of all desks the same way.
 * One claim per person per day (automation_log), so reruns never send twice.
 *
 * Switches: seller_day_report_on (1), seller_day_report_hour (20),
 * seller_day_admin_on (1), seller_day_push_on (1). Logic: includes/sellerday.php.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/sellerday.php';

try {
    $out = SellerDay::evening();
} catch (Throwable $e) {
    Logger::warning('seller day report failed: ' . $e->getMessage(), [], 'whatsapp');
    cron_done(['error' => mb_substr($e->getMessage(), 0, 200)], false);
    return;
}
cron_done($out);
