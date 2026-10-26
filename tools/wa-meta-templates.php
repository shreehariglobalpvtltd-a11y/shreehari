<?php
/**
 * tools/wa-meta-templates.php — submit the 10 Oct 2026 templates to Meta
 * for approval on the Meta number's WhatsApp Business Account.
 *
 *     sudo -u www-data php tools/wa-meta-templates.php          # list + submit missing
 *     sudo -u www-data php tools/wa-meta-templates.php --list   # list only
 *
 * Submitting is free. SENDING a template costs money, so every one of these
 * stays behind an OFF switch (owner rule, 10 Oct: credit only for the
 * customer's ticket):
 *   ticket_delivery_failed  wa_route_admin_tpl_on   (admin, when no agent took a ticket)
 *   good_morning_agent      wa_good_morning_tpl_on  (agents whose window is closed)
 *   otp_verification        not wired - the edit codes go free in the open chat
 *   discount_offer          only through Campaigns (the owner's explicit send)
 * The existing shg_ticket_confirmed* templates are not touched.
 * Idempotent: a name that already exists is skipped. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/whatsapp/config.php';

if (META_ACCESS_TOKEN === '' || META_WABA_ID === '') {
    exit("Meta token / WABA id not configured.\n");
}

function graph(string $method, string $path, ?array $body = null): array
{
    $ch = curl_init(META_API_BASE . $path);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . META_ACCESS_TOKEN, 'Content-Type: application/json']];
    if ($method === 'POST') {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true) ?? []];
}

$templates = [
    [
        'name' => 'ticket_delivery_failed', 'language' => 'en', 'category' => 'UTILITY',
        'components' => [
            ['type' => 'BODY',
             'text' => "Ticket delivery alert.\n\nBooking No: {{1}}\nPassenger: {{2}}\nMobile: {{3}}\nReason: {{4}}\n\nPlease call the passenger and share the ticket.",
             'example' => ['body_text' => [['SHG-2026-00584', 'Ram Bahadur', '+919800000000', 'no agent answered']]]],
            ['type' => 'FOOTER', 'text' => 'S Hari Global Pvt Ltd'],
        ],
    ],
    [
        'name' => 'otp_verification', 'language' => 'en', 'category' => 'AUTHENTICATION',
        'message_send_ttl_seconds' => 600,
        'components' => [
            ['type' => 'BODY', 'add_security_recommendation' => true],
            ['type' => 'FOOTER', 'code_expiration_minutes' => 10],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copy code']]],
        ],
    ],
    [
        'name' => 'discount_offer', 'language' => 'en', 'category' => 'MARKETING',
        'components' => [
            ['type' => 'BODY',
             'text' => "Namaste {{1}}! Special offer on our {{2}} bus: {{3}} off with code {{4}}, valid till {{5}}. Book at shreehariglobal.in or reply to this message.",
             'example' => ['body_text' => [['Ram', 'Surat - Rupaidiha', '10%', 'DASHAIN10', '12 Nov 2026']]]],
            ['type' => 'FOOTER', 'text' => 'Reply STOP to stop offers'],
        ],
    ],
    [
        'name' => 'good_morning_agent', 'language' => 'en', 'category' => 'UTILITY',
        'components' => [
            ['type' => 'BODY',
             'text' => "Good morning {{1}}! Today's bus on your route {{2}} departs at {{3}}. Seats booked so far: {{4}}.\n\nReply available to receive today's tickets here.",
             'example' => ['body_text' => [['Lok', 'Surat - Rupaidiha', '13:00', '18']]]],
            ['type' => 'FOOTER', 'text' => 'S Hari Global Pvt Ltd'],
        ],
    ],
];

[$c, $list] = graph('GET', '/' . META_WABA_ID . '/message_templates?fields=name,status,category,language&limit=200');
if ($c !== 200) {
    exit('Listing failed: HTTP ' . $c . ' ' . json_encode($list['error'] ?? $list) . "\n");
}
$have = [];
foreach ((array) ($list['data'] ?? []) as $t) {
    $have[$t['name'] . '|' . $t['language']] = $t;
}
foreach ($templates as $tpl) {
    $key = $tpl['name'] . '|' . $tpl['language'];
    if (isset($have[$key])) {
        echo str_pad($tpl['name'], 26), ' exists  ', $have[$key]['status'], ' ', $have[$key]['category'], "\n";
        continue;
    }
    if (in_array('--list', $argv, true)) {
        echo str_pad($tpl['name'], 26), " missing\n";
        continue;
    }
    [$c, $r] = graph('POST', '/' . META_WABA_ID . '/message_templates', $tpl);
    echo str_pad($tpl['name'], 26), $c === 200 ? ' SUBMITTED id=' . ($r['id'] ?? '?') . ' status=' . ($r['status'] ?? '?') . ' category=' . ($r['category'] ?? '?')
        : ' FAILED HTTP ' . $c . ' ' . mb_substr(json_encode($r['error'] ?? $r, JSON_UNESCAPED_UNICODE), 0, 300), "\n";
}
