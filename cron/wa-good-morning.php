<?php
/**
 * cron/wa-good-morning.php — 6 AM (10 Oct 2026, includes/waagentroute.php):
 *   0 6 * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/wa-good-morning.php >> .../logs/wa-good-morning.log 2>&1
 *
 * 1. Every agent whose route runs today gets a free good-morning with
 *    today's buses and [Available] [Busy] [Offline] — only inside their
 *    Meta 24 h window; a closed window gets a web push (the paid
 *    good_morning_agent template only with wa_good_morning_tpl_on = 1).
 * 2. The admin number gets yesterday's ticket delivery numbers and the
 *    count of customer questions the bot could not answer ("prashna") —
 *    free, only inside its window.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waagentroute.php';

$out = WaAgentRoute::goodMorning();

try {
    $admin = WaAgentRoute::adminNumber();
    if ($admin !== '' && WaAgentRoute::windowOpen($admin) && Security::rateLimit('wa_gm_admin', $admin . date('Ymd'), 1, 86400)) {
        $y = date('Y-m-d', time() - 86400);
        $c = static fn(string $sql): int => (int) Database::scalar($sql, ['d' => $y], 0);
        $tickets   = $c("SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed','completed') AND DATE(confirmed_at) = :d");
        $delivered = $c("SELECT COUNT(DISTINCT booking_id) FROM delivery_log WHERE status = 'delivered' AND DATE(created_at) = :d");
        $agentDone = $c("SELECT COUNT(DISTINCT booking_id) FROM delivery_log WHERE status = 'accepted' AND DATE(created_at) = :d");
        $missed    = $c("SELECT COUNT(DISTINCT booking_id) FROM delivery_log WHERE status = 'exhausted' AND DATE(created_at) = :d");
        $questions = (int) Database::scalar("SELECT COUNT(*) FROM ai_unanswered_questions WHERE status = 'new'", [], 0);
        $text = "🌅 शुभ प्रभात! हिजो (" . $y . ")\n"
            . "🎫 टिकट: " . $tickets . "\n"
            . "✅ ग्राहकलाई सिधै पुगेको: " . $delivered . "\n"
            . "🧑‍💼 एजेन्टले पुर्‍याएको: " . $agentDone . "\n"
            . "📵 नपुगेको (फोन गर्नुपर्ने): " . $missed . "\n"
            . ($questions > 0 ? "❓ बोटलाई नआएका प्रश्न: " . $questions . " — \"prashna\" लेख्नुहोस्\n" : '')
            . "\nसबै command: \"bot help\"";
        $out['admin'] = WaAgentRoute::send($admin, $text, null, null, 'admin_digest', null)['success'] ? 1 : 0;
    }
} catch (Throwable $e) {
    Logger::warning('good morning admin digest failed: ' . $e->getMessage(), [], 'whatsapp');
}
cron_done($out);
