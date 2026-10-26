<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
define('TEAM_PRIVATE_PATH', sys_get_temp_dir() . '/shg-import-' . getmypid());
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
$_SESSION['application_id_uploads']['agent:' . hash('sha256','sample-id')]['time'] = time() - ApplicationImport::TTL - 1;
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

testImport(!ApplicationImport::hasId('sample-id', 'staff'), 'An agent ID receipt cannot be used for staff');
ApplicationImport::rememberId('rejected-id', 'notdoc');
testImport(!ApplicationImport::hasId('rejected-id'), 'Rejected documents never receive ID receipts');
$r = ApplicationImport::fields(['is_application_form'=>true, 'phone'=>'+९७७ ९८१२३४५६७८', 'experience_years'=>'०', 'license_expiry'=>'२०२७-०२-३०', 'dob_ad'=>'१९९५-०५-१२'], 'form');
testImport($r['fields']['phone']==='9812345678' && $r['fields']['country_code']==='977', 'Explicit international numbers preserve the country');
testImport($r['fields']['experience_years']==='0' && $r['fields']['license_expiry']==='' && $r['fields']['dob']==='1995-05-12', 'Zero experience and valid dates normalize; impossible dates stay blank');
if (!str_ends_with((string)Database::scalar('SELECT DATABASE()'), '_test')) { throw new RuntimeException('Test database required'); }
require_once INCLUDE_PATH.'/pdf.php'; require_once INCLUDE_PATH.'/teamregistry.php';
$pdf = new Pdf(); $pdf->newPage(); $pdf->text(40,50,'SYNTHETIC APPLICATION - FIRST PAGE'); $pdf->newPage(); $pdf->text(40,50,'SYNTHETIC SECOND PAGE');
$bytes = $pdf->output(); $file = ApplicationImport::decode('data:application/pdf;base64,'.base64_encode($bytes));
testImport($file !== null, 'Multi-page synthetic PDF is accepted by MIME and bytes');
$tokens=[]; $keys=[]; Database::begin();
try {
    foreach (['staff','agent','partner'] as $scope) {
        $token=ApplicationImport::retainForm($file,$scope,['status'=>'unavailable','fields'=>[]]); $tokens[]=$token;
        testImport(ApplicationImport::retainForm($file,$scope,['status'=>'unavailable','fields'=>[]])===$token, "$scope upload retry keeps one private original");
        $u=ApplicationImport::formReceipt($token,$scope); $row=Database::fetch('SELECT * FROM team_reviews WHERE id=:i',['i'=>$u['id']]); $keys[]=$row['file_key'];
        testImport(TeamRegistry::open($row['file_key'])===$bytes, "$scope retains the exact original, including page two, during provider failure");
        $sealed=file_get_contents(TEAM_PRIVATE_PATH.'/'.$row['file_key']); testImport(!str_contains($sealed,'SYNTHETIC'), 'Original is encrypted at rest');
        $appId=$scope==='staff' ? Database::insert('staff_applications',['full_name'=>'Synthetic import','phone'=>'9800011111','role_applied'=>'office','id_type'=>'passport','id_number'=>'SYNTHETIC-ID']) : Database::insert('agent_applications',['ref'=>'AGR-'.bin2hex(random_bytes(4)),'type'=>$scope==='partner'?'org':'ind','name'=>'Synthetic import','phone'=>'9800011111','fields_json'=>'{}']);
        $person=($scope==='staff'?'staff:':'agent:').$appId;
        ApplicationImport::attachForm($token,$scope,$person); ApplicationImport::attachForm($token,$scope,$person);
        testImport(Database::scalar('SELECT person_key FROM team_reviews WHERE id=:i',['i'=>$u['id']])===$person, "$scope submit/retry binds the original to exactly one application");
        try { ApplicationImport::attachForm($token,$scope,($scope==='staff'?'staff:':'agent:').'99999999'); throw new LogicException('Reattachment accepted'); } catch (ImportReceiptError $e) { testImport(true, 'Receipt cannot attach to another application'); }
        try { ApplicationImport::formReceipt($token,$scope==='staff'?'agent':'staff'); throw new LogicException('Cross-kind accepted'); } catch (ImportReceiptError $e) { testImport(true, 'Receipt is application-kind isolated'); }
        $session=$_SESSION['application_form_uploads']; unset($_SESSION['application_form_uploads']);
        try { ApplicationImport::formReceipt($token,$scope); throw new LogicException('Cross-session accepted'); } catch (ImportReceiptError $e) { testImport(true, 'Receipt is session isolated'); } finally { $_SESSION['application_form_uploads']=$session; }
        $_SESSION['application_form_uploads'][$token]['time']=time()-ApplicationImport::TTL-1;
        try { ApplicationImport::formReceipt($token,$scope); throw new LogicException('Expired receipt accepted'); } catch (ImportReceiptError $e) { testImport(true, 'Expired receipt cannot submit'); }
    }
    testImport(ApplicationImport::decode('data:image/jpeg;base64,'.base64_encode($bytes))===null, 'PDF disguised as a JPEG is rejected');
} finally { Database::rollBack(); foreach($keys as $key) @unlink(TEAM_PRIVATE_PATH.'/'.$key); @rmdir(TEAM_PRIVATE_PATH); unset($_SESSION['application_form_uploads']); }
echo "$passed checks passed\n";
