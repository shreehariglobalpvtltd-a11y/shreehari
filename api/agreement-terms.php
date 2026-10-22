<?php
/**
 * /api/agreement-terms.php — every term of one agreement, for the online
 * forms (22 Oct 2026, owner: "agreement garne bela sart haru k k chha sabai
 * lekhidinus" — the applicant reads all the terms inside the form, on the
 * phone, right where they sign).
 *
 * GET ?kind=staff|agent|partner&lang=ne|hi|en|gu
 *  -> {"ok":true,"kind":"agent","lang":"ne","langs":["ne","hi","en","gu"],
 *      "title":"...","url":"/agreements/agent-agreement-ne.html",
 *      "sections":[{"no":"1","title":"...","points":[{"num":"1.1","html":"..."}]}]}
 *
 * The wording is AgreementPrint's (the deployed agreement pages plus the
 * office's own edits) — the same words the A4 form prints. Public: the
 * agreements are public pages already.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agreementprint.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        Response::error('This endpoint takes GET.', 405);
    }
    Security::requireRateLimit('agr_terms', Security::clientIp(), 120, 300);

    $kind = (string) ($_GET['kind'] ?? '');
    if (!isset(AgreementPrint::LANGS[$kind])) {
        Response::error('kind must be staff, agent or partner.', 422);
    }
    $lang = AgreementPrint::lang($kind, (string) ($_GET['lang'] ?? 'ne'));
    $titles = [
        'staff'   => 'कर्मचारी सम्झौता · Staff agreement',
        'agent'   => 'एजेन्ट सम्झौता · Agent agreement',
        'partner' => 'साझेदार कम्पनी सम्झौता · Partner (Pvt. Ltd.) agreement',
    ];
    header('Cache-Control: public, max-age=300');
    Response::json([
        'ok'       => true,
        'kind'     => $kind,
        'lang'     => $lang,
        'langs'    => AgreementPrint::LANGS[$kind],
        'title'    => $titles[$kind],
        'url'      => $kind === 'staff' ? '/agreements/staff-agreement.html' : '/agreements/' . $kind . '-agreement-' . $lang . '.html',
        'sections' => AgreementPrint::sections($kind, $lang),
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
