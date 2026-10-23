<?php
/**
 * /agreements/document.php — RETIRED (23 Oct 2026, owner: "no duplicate agreement pages or old
 * forms should remain visible"). The hybrid "fill it in this browser, make a PDF" document doubled
 * the one online form: /agreements/apply.html carries the same fields, the agreement's every term,
 * KYC, GPS, thumbprint and e-signature, and makes the applicant's own A4 PDF (form + agreement,
 * black and white) right after sending. This address stays for old links and printed QR codes and
 * opens that form for the same kind. The old page is in git history (0a50619).
 */
declare(strict_types=1);

$kind = (string) ($_GET['kind'] ?? 'agent');
$to = ['staff' => '/agreements/apply.html?kind=staff', 'partner' => '/agreements/apply.html?kind=partner'][$kind] ?? '/agreements/apply.html';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Location: ' . $to, true, 301);
exit;
