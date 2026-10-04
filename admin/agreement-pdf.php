<?php
declare(strict_types=1);
require_once __DIR__ . '/_guard.php';
admin_boot('staff.manage');
require_once INCLUDE_PATH . '/agreementpdf.php';
header('Cache-Control: private, no-store');
$error = '';
$values = $_POST;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) { http_response_code(403); exit('Invalid request. Reload the form.'); }
    try {
        if (is_array($values['fares'] ?? null)) {
            $values['fares'] = array_values(array_filter($values['fares'], static fn($r) => !is_array($r) || ($r['route'] ?? '') !== '' || ($r['amount'] ?? '') !== ''));
        }
        $pdf = AgreementPdf::generate($values);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="shg-agent-agreement-hi.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Content-Type-Options: nosniff');
        echo $pdf; exit;
    } catch (InvalidArgumentException $e) { http_response_code(422); $error = $e->getMessage(); }
    catch (Throwable $e) { http_response_code(503); $error = 'Hindi PDF service is unavailable. Please ask the administrator to check the font and HarfBuzz setup.'; }
}
$field = static fn(string $key, string $default = '') => Security::e(is_string($values[$key] ?? null) ? $values[$key] : $default);
admin_header('Hindi agreement PDF', 'agreement-pdf');
?>
<main style="max-width:850px;margin:24px auto;padding:24px;background:white;color:#12264e;border-top:5px solid #a9781a">
<h2>सहयोग / एजेंट समझौता PDF</h2>
<p>हिंदी दस्तावेज़ के लिए स्वीकृत विवरण भरें। डाउनलोड से बुकिंग, किराया सेटिंग या एजेंट खाते में कोई बदलाव नहीं होगा।</p>
<?php if ($error !== ''): ?><p role="alert" style="color:#99232b"><?= Security::e($error) ?></p><?php endif; ?>
<form method="post" action="agreement-pdf.php" data-no-guard>
<?= Security::csrfField() ?>
<?php foreach (['agreement_no' => ['समझौता संख्या', 40], 'agent_name' => ['एजेंट / सहयोगी का नाम', 90], 'address' => ['पता', 180], 'payment_cycle' => ['कमीशन भुगतान चक्र', 90]] as $key => [$label, $max]): ?>
<p><label><?= $label ?><br><input name="<?= $key ?>" value="<?= $field($key) ?>" maxlength="<?= $max ?>" required style="width:100%;padding:10px"></label></p>
<?php endforeach; ?>
<p><label>तारीख <input type="date" name="date" value="<?= $field('date', date('Y-m-d')) ?>" required></label>
<label>मुद्रा <select name="currency"><option value="INR">INR</option><option value="NPR" <?= ($values['currency'] ?? '') === 'NPR' ? 'selected' : '' ?>>NPR</option></select></label></p>
<fieldset><legend>निश्चित किराया: कम से कम एक पंक्ति भरें</legend>
<?php for ($i = 0; $i < 6; $i++): $row = is_array($values['fares'][$i] ?? null) ? $values['fares'][$i] : []; ?>
<p><label>मार्ग / सीट <?= $i + 1 ?> <input name="fares[<?= $i ?>][route]" maxlength="90" value="<?= Security::e(is_string($row['route'] ?? null) ? $row['route'] : '') ?>" <?= $i === 0 ? 'required' : '' ?>></label>
<label>राशि <input type="number" name="fares[<?= $i ?>][amount]" min="0.01" max="9999999.99" step="0.01" value="<?= Security::e(is_string($row['amount'] ?? null) ? $row['amount'] : '') ?>" <?= $i === 0 ? 'required' : '' ?>></label></p>
<?php endfor; ?></fieldset>
<p><label>कमीशन प्रकार <select name="commission_type"><option value="fixed">प्रति टिकट निश्चित राशि</option><option value="percent" <?= ($values['commission_type'] ?? '') === 'percent' ? 'selected' : '' ?>>मूल किराये का प्रतिशत</option></select></label>
<label>दर <input type="number" name="commission_rate" min="0.01" max="9999999.99" step="0.01" value="<?= $field('commission_rate') ?>" required></label></p>
<p>कटौती की शर्त: रिकॉर्ड से प्रमाणित वास्तविक नुकसान, लिखित सूचना के बाद; नुकसान का 10% अतिरिक्त केवल तभी जब कंपनी कानूनी सलाह के बाद "लागू" पर निशान लगाए।</p>
<button type="submit" style="padding:12px 20px;background:#99232b;color:white;border:0;border-radius:6px">हिंदी PDF डाउनलोड करें</button>
</form></main>
<?php admin_footer(); ?>
