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
 *
 * 19 Nov 2026 (WA-3): the stored form is first checked against THIS WhatsApp
 * account (WaFlow::ownership(), a free GET). A draft that lives in another
 * account - the old WABA's flow 1440536441555084 - is never retried again
 * ("Integrity requirements not met" every 30 min for weeks): it is dropped and,
 * with wa_flow_cron_recreate on, one fresh form is made in the current
 * account; after that the job retries THAT draft as before. When Meta cannot
 * be asked (network, token) nothing is changed and nothing is retried.
 * wa_flow_cron_recreate defaults to OFF (19 Nov 2026 review): no Meta write
 * happens by itself right after a deploy - the old form is only dropped and
 * the admin is told to press "Re-create form" (or switch this on).
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

/* Whose form is it? A form from another WhatsApp account can never be
   published for this number - stop retrying it. */
$own = WaFlow::ownership();
$out['owner'] = $own['state'] . ($own['status'] !== '' ? ':' . $own['status'] : '');
if ($own['state'] === 'unknown') {
    cron_done($out + ['skip' => 'could not ask Meta: ' . $own['error']]);   // change nothing; next run asks again
    exit;
}
if (in_array($own['state'], ['foreign', 'deprecated'], true)) {
    if (!Settings::getBool('wa_flow_cron_recreate', false)) {
        WaFlow::forget($own['state']);
        $tell("⚠️ WhatsApp booking form " . $out['flow'] . " yo WhatsApp account ma chhaina (" . $own['state'] . "), hataiyo.\n"
            . "Admin → WhatsApp form → \"Re-create form\" thichnus.");
        cron_done($out + ['dropped' => 1]);
        exit;
    }
    $p = WaFlow::publish(false, $own);   // drops the foreign form and makes a fresh one in this account
    $out['recreate'] = $p['ok'] ? 'ok' : ($p['id'] !== '' ? 'draft ' . $p['id'] . ': ' : '') . $p['error'];
    $out['newFlow']  = $p['id'];
    Logger::audit('wa.flow_recreate', 'whatsapp', $p['id'] !== '' ? $p['id'] : $out['flow'], null,
        ['by' => 'cron', 'old' => $out['flow'], 'why' => $own['state'], 'ok' => $p['ok']], 'WhatsApp booking form re-created in the current account');
    $tell("♻️ Purano WhatsApp booking form (" . $out['flow'] . ") arko account ko thiyo - hataiyo.\n"
        . ($p['ok'] ? "✅ Naya form " . $p['id'] . " PUBLISH bhayo." : ($p['id'] !== '' ? "Naya form " . $p['id'] . " DRAFT ma chha; yo job le publish garna kosis garchha." : "Naya form banena: " . $p['error'])));
    if (!$p['ok']) {
        cron_done($out);
        exit;
    }
} elseif ($out['draft'] === 1) {
    $p = WaFlow::publish(false, $own);
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
