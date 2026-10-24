<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require getenv('SHG_TEST_BOOTSTRAP') ?: dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/idcard.php';
$count=0;
function cardTest(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);$count++;echo "PASS $label\n";}
unset($_SESSION['a4_mine']);cardTest(IdCard::applicantSession()===null,'Missing own-submission session rejected');
$_SESSION['a4_mine']=['type'=>'staff','id'=>1,'t'=>time()-3601];cardTest(IdCard::applicantSession()===null,'Expired session rejected');
$_SESSION['a4_mine']=['type'=>'staff','id'=>1,'t'=>time()+1000];cardTest(IdCard::applicantSession()===null,'Future marker rejected');
$_SESSION['a4_mine']=['type'=>'other','id'=>1,'t'=>time()];cardTest(IdCard::applicantSession()===null,'Unknown marker type rejected');
$_SESSION['a4_mine']=['type'=>'staff','id'=>1,'t'=>time()];cardTest(IdCard::applicantSession()===['type'=>'staff','id'=>1],'Own submission marker accepted');unset($_SESSION['a4_mine']);
$p=['kind'=>'staff','ref'=>'STF-000000','memberCode'=>'STF-000000','name'=>'Sample Staff','roleEn'=>'Staff applicant','roleNe'=>'कर्मचारी आवेदक','place'=>'Mehsana','phone'=>'+91 00000 00000','photo'=>null,'sig'=>null,'issued'=>date('Y-m-d'),'expires'=>'','pending'=>true,'valid'=>false,'standing'=>'Pending office approval','standingNe'=>'स्वीकृति बाँकी','public'=>false,'waDigits'=>'','email'=>'','facebook'=>'','contact'=>''];
foreach(['front','back'] as $side){$png=IdCard::png($p,$side);$size=getimagesizefromstring($png);cardTest($size[0]===638&&$size[1]===1011,"ID $side renders at print dimensions");$png=IdCard::visitingPng($p,$side);$size=getimagesizefromstring($png);cardTest($size[0]===1050&&$size[1]===602,"Visiting $side renders at print dimensions");}
$pdf=IdCard::pdf($p,'all');cardTest(str_starts_with($pdf,'%PDF-'),'Combined card PDF renders');
echo "$count checks passed\n";
