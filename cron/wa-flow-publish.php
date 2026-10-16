<?php
/**
 * cron/wa-flow-publish.php — every 30 min (16 Oct 2026, owner: "approve bhaepachhi
 * submit gardinus"):
 *   0,30 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/wa-flow-publish.php >> .../logs/wa-flow-publish.log 2>&1
 *
 * The WhatsApp booking form (includes/waflow.php) sits on Meta as a DRAFT
 * while Meta answers "Integrity requirements not met" (the number's display
 * name was still under review). This job retries the SAME draft until Meta
 * publishes it, then submits the shg_book_form template once - the template
 * needs a published form. When both are done it costs nothing: no Meta call.
 * The admin number hears about each step (free, only inside its 24 h window).
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/waflow.php';

$out = ['flow' => WaFlow::flowId(), 'draft' => Settings::getBool('wa_flow_draft', false) ? 1 : 0,
        'template' => Settings::getString('wa_flow_template_status', '')];

$tell = static function (string $text): void {
    try {
        require_once INCLUDE_PATH . '/notify.php';
        require_once INCLUDE_PATH . '/waagentroute.php';
        $admin = WaAgentRoute::adminNumber();
        if ($admin !== '' && WaAgentRoute::windowOpen($admin)) {
            WaAgentRoute::send($admin, $text, null, null, 'admin_digest', null);
        }
    } catch (Throwable $e) {
        // the log line below still records the step
    }
};

if ($out['flow'] === '' || ($out['draft'] === 0 && $out['template'] !== '')) {
    cron_done($out + ['idle' => 1]);
    exit;
}

if ($out['draft'] === 1) {
    $p = WaFlow::publish();
    $out['publish'] = $p['ok'] ? 'ok' : $p['error'];
    if (!$p['ok']) {
        cron_done($out);   // not yet - Meta still refuses; try again next run
        exit;
    }
    Logger::audit('wa.flow_publish', 'whatsapp', $out['flow'], null, ['by' => 'cron'], 'WhatsApp booking form published by the retry job');
    $tell("✅ WhatsApp booking form Meta ma PUBLISH bhayo.\nAba customer le WhatsApp mai form bharera ticket kaatna sakchhan.");
}

if (Settings::getString('wa_flow_template_status', '') === '') {
    $t = WaFlow::createTemplate();
    $out['templateSubmit'] = $t['ok'] ? 'ok' : $t['error'];
    if ($t['ok']) {
        Logger::audit('wa.flow_template', 'whatsapp', WaFlow::TEMPLATE, null, ['by' => 'cron'], 'Form template submitted to Meta');
        $tell("📨 Template " . WaFlow::TEMPLATE . " Meta ma review ko lagi submit bhayo (prayah kehi minute).");
    } elseif (stripos($t['error'], 'already') !== false || stripos($t['error'], 'exist') !== false) {
        Settings::set('wa_flow_template_status', 'EXISTS', 'string', 'whatsapp');   // made by hand earlier
    }
}

cron_done($out);
