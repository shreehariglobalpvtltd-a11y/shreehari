<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require getenv('SHG_TEST_BOOTSTRAP') ?: dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/applicationimport.php';
$passed = 0;
function testImport(bool $v, string $s): void { global $passed; if (!$v) throw new RuntimeException($s); $passed++; echo "PASS $s\n"; }
$r = ApplicationImport::fields(['is_id_document' => true, 'holder_name_roman' => 'Ram Thapa', 'document_type' => 'citizenship', 'id_number' => '५२-०१-७५-१२३४५'], 'id');
testImport($r['status'] === 'read' && $r['fields']['idno'] === '52-01-75-12345', 'ID numbers normalize Devanagari digits');
testImport(ApplicationImport::fields(['is_id_document' => 'false'], 'id')['status'] === 'notdoc', 'String false is not a government ID');
testImport(ApplicationImport::fields(['is_id_document' => false, 'is_application_form' => true], 'id')['status'] === 'notdoc', 'A completed form cannot replace required government ID');
$r = ApplicationImport::fields(['is_application_form' => true, 'is_id_document' => false, 'holder_name_roman' => 'Ram Thapa', 'id_number' => '12345', 'phone' => 'bad', 'phone_verified' => true, 'agree' => true, 'status' => 'approved'], 'form');
testImport($r['status'] === 'read' && $r['fields']['name'] === 'Ram Thapa', 'Completed forms can supply identity fields as suggestions');
testImport($r['fields']['phone'] === '' && !isset($r['fields']['phone_verified'], $r['fields']['agree'], $r['fields']['status']), 'Import cannot fabricate consent, approval or phone verification');
ApplicationImport::rememberId('sample-id', 'read');
testImport(ApplicationImport::hasId('sample-id') && !ApplicationImport::hasId('other-id'), 'ID receipt binds exact bytes to this session');
$_SESSION['application_id_uploads'][hash('sha256','sample-id')]['time'] = time() - ApplicationImport::TTL - 1;
testImport(!ApplicationImport::hasId('sample-id'), 'Expired ID receipt is rejected');
unset($_SESSION['application_id_uploads']);
if ($fixture = getenv('SHG_IMPORT_AI_FIXTURE')) {
$pdf = file_get_contents($fixture);
$file = ApplicationImport::decode('data:application/pdf;base64,' . base64_encode($pdf));
testImport($file !== null && $file['mime'] === 'application/pdf', 'Real PDF accepted by bytes');
testImport(ApplicationImport::decode('data:image/jpeg;base64,' . base64_encode($pdf)) === null, 'Spoofed image MIME rejected');
$r = ApplicationImport::read($file, 'form');
echo json_encode(['ai_status' => $r['status'], 'name' => $r['fields']['name'] ?? '', 'phone' => $r['fields']['phone'] ?? '', 'emergency_name' => $r['fields']['emergency_name'] ?? ''], JSON_UNESCAPED_UNICODE), "\n";
testImport($r['status'] === 'read' && str_contains(strtolower($r['fields']['name'] ?? ''), 'ramesh') && ($r['fields']['phone'] ?? '') === '9841237654', 'AI reads handwriting-style fields from PDF');
testImport(str_contains(strtolower($r['fields']['emergency_name'] ?? ''), 'maya'), 'AI reads the second PDF page');
}
echo "$passed checks passed\n";
