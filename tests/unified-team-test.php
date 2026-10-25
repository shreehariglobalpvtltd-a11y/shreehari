<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit;}
define('TEAM_PRIVATE_PATH','/root/shg-unified-test-private');
ob_start();require dirname(__DIR__).'/includes/bootstrap.php';require_once INCLUDE_PATH.'/teamregistry.php';require_once INCLUDE_PATH.'/teamfinance.php';require_once INCLUDE_PATH.'/carddelivery.php';require_once INCLUDE_PATH.'/carddesign.php';require_once INCLUDE_PATH.'/teamservices.php';
if(!str_ends_with((string)Database::scalar('SELECT DATABASE()'),'_test')){exit('TEST DATABASE ONLY');}
$checksCount=0;$check=static function(bool $ok,string $label)use(&$checksCount):void{if(!$ok){throw new RuntimeException($label);}echo 'PASS '.++$checksCount.' '.$label."\n";};
$reject=static function(callable $fn,string $label)use($check):void{try{$fn();}catch(Throwable $e){$check(true,$label.' ('.$e->getMessage().')');return;}$check(false,$label);};
$files=[];
try{
$owner=Database::insert('admins',['username'=>'unified-owner','full_name'=>'Synthetic Owner','role'=>'superadmin','password_hash'=>Security::hashPassword('synthetic-password'),'is_active'=>1]);
$aid=Database::insert('admins',['username'=>'legacy-login-id','full_name'=>'Synthetic Agent','role'=>'agent','phone'=>'+919876543210','password_hash'=>Security::hashPassword('legacy-password'),'is_active'=>1]);
$_SESSION[ADMIN_SESSION_KEY]=['id'=>$owner,'username'=>'unified-owner','full_name'=>'Synthetic Owner','role'=>'superadmin','last_seen'=>time(),'permissions'=>[]];
Settings::set('agent_wallet_enabled',true,'bool','agent',false);Settings::set('doc_verify_mode','off','string','team',false);
$im=imagecreatetruecolor(400,500);imagefill($im,0,0,imagecolorallocate($im,38,70,110));imagestring($im,5,100,200,'SYNTHETIC TEST',imagecolorallocate($im,255,255,255));ob_start();imagejpeg($im,null,90);$jpg=ob_get_clean();imagedestroy($im);$uri='data:image/jpeg;base64,'.base64_encode($jpg);
$app=AgentApplications::adminCreate(['name'=>'Synthetic Agent','phone'=>'+919876543210','p_addr'=>'Synthetic test address','p_idno'=>'SYNTHETIC-ID','area'=>'Test location'],(int)$owner,true);$id=(int)$app['id'];$ref=$app['ref'];$key='agent:'.$id;
$check(AgentApplications::find($id)['status']==='reviewing','office-created application does not auto-approve');
Database::update('agent_applications',['ip'=>'127.0.0.1','admin_id'=>$aid,'photo'=>$uri,'doc_photo'=>$uri,'signature'=>$uri,'phone_verified'=>0,'kyc_status'=>'verified','public_ok'=>1,'web_live'=>1],'id=:i',['i'=>$id]);
$fields=AgentApplications::find($id)['fields'];$fields['p_addr']='Synthetic test address';$fields['p_occ']='Synthetic digital application';Database::update('agent_applications',['fields_json'=>json_encode($fields)],'id=:i',['i'=>$id]);
$meta=TeamRegistry::ensure($key,(int)$owner);$code=$meta['code'];$check((bool)preg_match('/^A-[1-9][0-9]*$/',$code),'agent receives A-N code');$check(TeamRegistry::ensure($key)['id']===$meta['id'],'code allocation is idempotent');
$oldHash=Database::scalar('SELECT password_hash FROM admins WHERE id=:a',['a'=>$aid]);TeamRegistry::changeCode($key,'A-999991','Synthetic correction',(int)$owner);
$check(TeamRegistry::refForCode($code)===$ref&&TeamRegistry::refForCode('A-999991')===$ref,'old and current codes resolve to one permanent record');
$check(Database::scalar('SELECT password_hash FROM admins WHERE id=:a',['a'=>$aid])===$oldHash&&Database::scalar('SELECT username FROM admins WHERE id=:a',['a'=>$aid])==='legacy-login-id','code changes preserve legacy login and password');
$reject(fn()=>TeamRegistry::changeCode($key,'S-1','Wrong prefix',(int)$owner),'wrong code category refused');
TeamRegistry::save($key,['route'=>'Synthetic route','consent_note'=>'Synthetic applicant consent at test','delivery_consent'=>1,'payout_verified'=>1],(int)$owner);
$reject(fn()=>TeamRegistry::approve($key,(int)$owner),'unverified phone cannot be approved');
Database::update('agent_applications',['phone_verified'=>1],'id=:i',['i'=>$id]);
$check(TeamRegistry::approve($key,(int)$owner)===$ref,'reviewed application approval succeeds');
$check(TeamRegistry::approve($key,(int)$owner)===$ref&&(int)Database::scalar("SELECT COUNT(*) FROM admins WHERE username='legacy-login-id'")===1,'repeated approval links exactly one account');
$p=IdCard::person($ref);$check($p['memberCode']==='A-999991'&&$p['route']==='Synthetic route','cards use current display code and route');
$check(str_starts_with(IdCard::waLink($p),'https://wa.me/919876543210?text='),'personal QR opens exact Indian recipient');
$np=$p;$np['waDigits']='9779812345678';$check(str_starts_with(IdCard::waLink($np),'https://wa.me/9779812345678?text='),'personal QR supports Nepal country code');
$check(IdCard::waQrUrl($p)==='https://wa.me/919876543210','printed WhatsApp QR uses exact validated personal URL');$private=$p;$private['waDigits']='';$check(IdCard::waQrUrl($private)==='','printed QR never guesses a country or bypasses contact privacy');
$bad=$p;$bad['waDigits']='9876543210';$check(IdCard::waLink($bad)==='','local/ambiguous WhatsApp destination refused');
Database::update('agent_applications',['kyc_status'=>'submitted'],'id=:i',['i'=>$id]);$check(!IdCard::person($ref)['valid'],'legacy approved status cannot make unreviewed identity valid');Database::update('agent_applications',['kyc_status'=>'verified'],'id=:i',['i'=>$id]);
$check(IdCard::parse(IdCard::code($ref))===$ref&&!str_contains(IdCard::verifyUrl($ref),'wa.me'),'verification QR is signed and separate');
$check(IdCard::parse($ref.'.000000000000')===null,'tampered official QR refused');
foreach(['royal','minimal','orange']as$name){foreach(['portrait','landscape']as$ori){$cfg=CardDesign::preset($name);$cfg['orient_id']=$ori;foreach(['front','back']as$side){$png=IdCard::designPng($p,'id',$side,$cfg);$sz=getimagesizefromstring($png);$check($sz&&($ori==='portrait'?$sz[0]===638&&$sz[1]===1011:$sz[0]===1011&&$sz[1]===638),$name.' '.$ori.' '.$side.' dimensions');} $p['studio']=$cfg;$pdf=IdCard::pdf($p,'all');$check(str_starts_with($pdf,'%PDF-')&&strlen($pdf)>10000,$name.' '.$ori.' PDF contains both cards');}}
$p['studio']=null;
require_once INCLUDE_PATH.'/agreementprint.php';foreach(['agent','staff','partner']as$kind){$signed=$p;$signed['kind']=$kind;$pdf=AgreementPrint::digitalPdf($signed,'en');preg_match_all('~/Type /Page\b~',$pdf,$pages);$check(count($pages[0])>=2&&count($pages[0])<=4,'current '.$kind.' agreement exports 2–4 A4 pages without dropping terms');}

$bw=CardDesign::preset('minimal');$bw['bw']=true;$png=IdCard::designPng($p,'visiting','back',$bw);$check(getimagesizefromstring($png)[0]===1050,'visiting export 300-DPI pixel dimensions');
$check(CardDelivery::recipient($ref)==='919876543210','delivery recipient requires verified international number');
$res=CardDelivery::send($ref);$check($res['ok']&&$res['status']==='queued','complete PDF generated before delivery queue');$job=CardDelivery::getDelivery($ref);$files[]=$job['file_key'];
$check(str_starts_with(TeamRegistry::open($job['file_key']),'%PDF-'),'queued attachment is actual private PDF');
CardDelivery::send($ref);$check((int)Database::scalar('SELECT COUNT(*) FROM team_card_jobs WHERE ref=:r',['r'=>$ref])===1,'double approval/send does not duplicate job');
$check($job['state']!=='delivered','queueing never claims delivery');
Database::update('team_card_jobs',['state'=>'sent','provider_ref'=>'wamid.synthetic.test'],'id=:i',['i'=>$job['id']]);
CardDelivery::status('wamid.synthetic.test','delivered','',100);CardDelivery::status('wamid.synthetic.test','sent','',90);$check(CardDelivery::getDelivery($ref)['state']==='delivered','late sent webhook cannot downgrade delivered');
CardDelivery::status('wamid.synthetic.test','read','',110);CardDelivery::status('wamid.synthetic.test','failed','old',105);$check(CardDelivery::getDelivery($ref)['state']==='read','read receipt cannot be downgraded by failed replay');
$reject(fn()=>TeamRegistry::open('../config/config.php'),'private file traversal refused');
TeamFinance::terms((int)$aid,['basis'=>'percent','rate'=>10,'salary'=>1000,'effective_at'=>date('Y-m-d'),'reason'=>'Synthetic percent agreement'],(int)$owner);
$check(AgentWallet::commissionForBooking(2000,(int)$aid,3)===200.0,'percentage commission uses eligible fare once');
TeamFinance::terms((int)$aid,['basis'=>'booking','rate'=>120,'salary'=>1000,'effective_at'=>date('Y-m-d'),'reason'=>'Synthetic booking terms'],(int)$owner);$check(AgentWallet::commissionForBooking(2000,(int)$aid,3)===120.0,'per-booking commission counted once');
TeamFinance::terms((int)$aid,['basis'=>'flat','rate'=>100,'salary'=>1000,'effective_at'=>date('Y-m-d'),'reason'=>'Synthetic seat terms'],(int)$owner);$check(AgentWallet::commissionForBooking(2000,(int)$aid,3)===300.0,'per-passenger commission counted correctly');
$reject(fn()=>TeamFinance::terms((int)$aid,['basis'=>'percent','rate'=>101,'salary'=>0,'effective_at'=>date('Y-m-d'),'reason'=>'Invalid percentage'],(int)$owner),'percentage above 100 refused');
$future=date('Y-m-d',time()+86400);TeamFinance::terms((int)$aid,['basis'=>'booking','rate'=>500,'salary'=>1000,'effective_at'=>$future,'reason'=>'Future agreement'],(int)$owner);$check(AgentWallet::commissionForBooking(2000,(int)$aid,3)===300.0,'future terms do not change current earnings');
$check(AgentWallet::commissionForBooking(2000,(int)$aid,3,$future.' 12:00:00')===500.0,'future booking gets effective scheduled terms');
$token=str_repeat('b',32);TeamFinance::adjustment((int)$aid,1000,'bonus','Synthetic opening bonus',$token,(int)$owner);TeamFinance::adjustment((int)$aid,1000,'bonus','Synthetic opening bonus',$token,(int)$owner);$check(AgentWallet::balances((int)$aid)['commission']===1000.0,'replayed adjustment does not double earnings');
$req=AgentWallet::requestPayout((int)$aid,600,'Synthetic withdrawal');$check(TeamFinance::available((int)$aid)['available']===400.0&&TeamFinance::available((int)$aid)['pending']===600.0,'withdrawal reserves funds without paying');
$reject(fn()=>AgentWallet::requestPayout((int)$aid,100,'Duplicate'),'duplicate withdrawal request refused');
$reject(fn()=>TeamFinance::adjustment((int)$aid,500,'deduction','Reserved funds test',str_repeat('c',32),(int)$owner),'deduction cannot spend reserved funds');
$reject(fn()=>TeamFinance::pay((int)$req,'SYNTHETIC-PAY-1',(int)$owner),'payment before approval refused');
TeamFinance::approve((int)$req,(int)$owner);$check(AgentWallet::balances((int)$aid)['commission']===1000.0,'approval is not payment');
$reject(fn()=>TeamFinance::pay((int)$req,'',(int)$owner),'missing payment reference refused');
TeamFinance::pay((int)$req,'SYNTHETIC-PAY-1',(int)$owner);$check(AgentWallet::balances((int)$aid)['commission']===400.0&&TeamFinance::available((int)$aid)['pending']===0.0,'confirmed payment posts one debit and releases reservation');
$reject(fn()=>TeamFinance::pay((int)$req,'SYNTHETIC-PAY-2',(int)$owner),'paid withdrawal replay refused');
$reject(fn()=>AgentWallet::requestPayout((int)$aid,500,'Too much'),'over-balance withdrawal refused');
$req2=AgentWallet::requestPayout((int)$aid,100,'Decline test');AgentWallet::decidePayoutRequest((int)$req2,'declined',(int)$owner,'Synthetic reason');$check(TeamFinance::available((int)$aid)['available']===400.0,'rejection releases reservation without extra credit');
$check((int)Database::scalar('SELECT COUNT(*) FROM team_ledger_journal')>=2,'existing ledger writes have immutable history journal');
$before=AgentWallet::balances((int)$aid);$reject(fn()=>TeamServices::submit(['country'=>'NP','service'=>'partner_transfer','amount_minor'=>10000]),'unconfigured Nepal transfer disabled');$check(AgentWallet::balances((int)$aid)===$before,'disabled service never debits wallet');
$reject(fn()=>TeamServices::validate(['country'=>'IN','service'=>'recharge','amount_minor'=>-1]),'invalid recharge amount refused');
Settings::set('team_staff_positions',['test_position'=>['label'=>'Synthetic Position','required'=>['license_number']]],'json','team',false);$check(in_array('test_position',StaffApps::roles(),true)&&StaffApps::roleLabel('test_position')==='Synthetic Position','custom staff position uses existing agreement role system');
$staff=Database::insert('staff_applications',['full_name'=>'Synthetic Staff','phone'=>'9812345678','phone_country'=>'NP','role_applied'=>'office','id_type'=>'passport','id_number'=>'SYNTHETIC','ip_address'=>'127.0.0.1']);$sm=TeamRegistry::ensure('staff:'.$staff,(int)$owner);$check(str_starts_with($sm['code'],'S-'),'staff has independent S-N sequence');
$check(TeamRegistry::digital(TeamManager::find('staff:'.$staff)),'digitally submitted staff is eligible for unified directory');
$_GET=['person'=>$key];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/admin/team.php';$_SERVER['HTTP_HOST']='localhost';ob_start();require dirname(__DIR__).'/admin/team.php';$html=ob_get_clean();$check(str_contains($html,'Synthetic Agent')&&str_contains($html,'Commission / salary effective dates'),'unified panel renders real directory and management controls');
file_put_contents('/root/shg-unified-test-card.png',IdCard::designPng($p,'id','front',CardDesign::preset('royal')));file_put_contents('/root/shg-unified-test-cards.pdf',IdCard::pdf($p,'all'));
echo "SUCCESS $checksCount checks; synthetic test database only, no messages sent.\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n");exit(1);}finally{Settings::flush();foreach($files as$file){@unlink(TeamRegistry::privateDir().'/'.$file);}ob_end_flush();}
