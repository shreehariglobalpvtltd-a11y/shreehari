<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit; }
require_once __DIR__.'/idcard.php';
require_once __DIR__.'/teamregistry.php';
final class CardDelivery {
    public const STATUS_QUEUED='queued', STATUS_SENT='sent', STATUS_DELIVERED='delivered', STATUS_FAILED='failed';
    public static function cardUrls(string $ref): array {
        $b=appUrl('admin/id-card.php').'?ref='.rawurlencode($ref);
        return ['id_card_front'=>$b.'&png=front','id_card_back'=>$b.'&png=back','visiting_front'=>$b.'&v=front','visiting_back'=>$b.'&v=back','pdf'=>$b.'&pdf=all','verify'=>IdCard::verifyUrl($ref)];
    }
    public static function recipient(string $ref): string {
        $key=TeamRegistry::key($ref); [$kind,$id]=explode(':',$key);
        $a=$kind==='staff'?StaffApps::find((int)$id):AgentApplications::find((int)$id);
        if (!$a || empty($a['phone_verified'])) { return ''; }
        $raw=$kind==='staff'?'+'.countryDialCode((string)$a['phone_country']).$a['phone']:(string)($a['phone_raw']?:$a['phone']);
        $d=preg_replace('/\D/','',$raw)??'';
        return preg_match('/^(91[6-9][0-9]{9}|9779[78][0-9]{8})$/',$d) ? $d : '';
    }
    public static function getDelivery(string $ref): ?array {
        if (!TeamRegistry::ready()) { return null; }
        $r=Database::fetch('SELECT * FROM team_card_jobs WHERE ref=:r ORDER BY id DESC LIMIT 1',['r'=>$ref]);
        if (!$r) { $raw=Database::scalar("SELECT kvalue FROM kv_store WHERE kscope='global' AND kkey=:k",['k'=>'card_delivery:'.$ref],null);$legacy=is_string($raw)?json_decode($raw,true):null;return is_array($legacy)?array_replace($legacy,['id'=>0,'state'=>'legacy','status'=>'legacy','version_hash'=>'','sent_at'=>null,'delivered_at'=>null,'error_message'=>'Legacy attempt retained. Review provider receipts before explicit resend.']):null; }
        $p=IdCard::person($ref);
        return $r+['status'=>$r['state'],'phone'=>$r['recipient'],'name'=>$p['name']??'','provider'=>'cloud_api','provider_msg_id'=>$r['provider_ref'],'error_message'=>$r['error'],'retry_count'=>(int)Database::scalar('SELECT COUNT(*) FROM team_card_jobs WHERE ref=:r',['r'=>$ref],0)-1,'sent_at'=>in_array($r['state'],['sent','delivered','read'])?$r['updated_at']:null,'delivered_at'=>in_array($r['state'],['delivered','read'])?$r['updated_at']:null];
    }
    public static function send(string $ref,bool $force=false): array {
        $ref=strtoupper(trim($ref));
        try {
            $p=IdCard::person($ref); if (!$p || !$p['valid'] || !$p['photo']) { throw new RuntimeException('An active approved card and approved photo are required.'); }
            $ref=$p['ref']; $key=TeamRegistry::key($ref); $m=TeamRegistry::meta($key); $to=self::recipient($ref);
            if (!$to || empty($m['delivery_consent'])) { throw new RuntimeException('Record WhatsApp card-delivery consent and verify the exact registered number first.'); }
            // Create the complete ID and visiting PDF BEFORE enqueueing a send; never give Meta an admin-only URL.
            $pdf=IdCard::pdf($p,'all');
            if (!str_starts_with($pdf,'%PDF-')) { throw new RuntimeException('Card PDF generation failed.'); }
            $hash=hash('sha256',serialize($p).($m['template_json']??''));
            return Database::transaction(static function()use($ref,$to,$hash,$pdf,$force,$m):array {
                Database::fetch('SELECT id FROM team_identity WHERE id=:i FOR UPDATE',['i'=>$m['id']]);
                $last=self::getDelivery($ref);
                if($last&&$last['state']==='legacy'&&!$force){return ['ok'=>false,'status'=>'legacy','detail'=>'Legacy attempt retained. Review it before explicit resend.','manual'=>self::manualFallback($ref)];}
                if ($last && $last['version_hash']===$hash && (!$force || in_array($last['state'],['queued','processing'],true))) {
                    return ['ok'=>true,'status'=>$last['state'],'detail'=>'Existing card job: '.$last['state'].'. Delivery requires a webhook receipt.','manual'=>self::manualFallback($ref)];
                }
                if ($force && $last && strtotime($last['created_at'])>time()-60) { throw new RuntimeException('Wait one minute before another resend.'); }
                $dedupe=hash('sha256',$ref.'|'.$to.'|'.$hash.($force?'|resend|'.($last['id']??0):''));
                $file=TeamRegistry::stash($pdf);
                Database::run("INSERT IGNORE INTO team_card_jobs(ref,recipient,version_hash,dedupe,file_key,parent_id) VALUES(:r,:t,:h,:d,:f,:p)",['r'=>$ref,'t'=>$to,'h'=>$hash,'d'=>$dedupe,'f'=>$file,'p'=>$force?($last['id']??null):null]);
                Logger::audit('card.queued','team',$ref,null,['recipient'=>maskPhone($to),'hash'=>$hash],'ID and visiting PDF generated; queued for official Cloud API');
                return ['ok'=>true,'status'=>'queued','detail'=>'ID and visiting cards generated and queued. No delivery confirmation yet.','manual'=>self::manualFallback($ref)];
            });
        } catch(Throwable $e) { return ['ok'=>false,'status'=>'failed','detail'=>$e->getMessage(),'manual'=>self::manualFallback($ref)]; }
    }
    public static function drain(int $limit=5): int {
        if (!TeamRegistry::ready()) { return 0; }
        require_once ROOT_PATH.'/whatsapp/api.php'; require_once __DIR__.'/watemplates.php';
        if (META_ACCESS_TOKEN==='' || META_PHONE_NUMBER_ID==='') {
            Database::run("UPDATE team_card_jobs SET error='Official Cloud API credentials missing; use manual sharing.' WHERE state='queued'"); return 0;
        }
        // A crashed/ambiguous send is held for explicit human resend, never blindly sent twice.
        Database::run("UPDATE team_card_jobs SET state='unknown',error='Send interrupted; confirm provider status before explicit resend.' WHERE state='processing' AND updated_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
        $rows=Database::fetchAll("SELECT * FROM team_card_jobs WHERE state='queued' ORDER BY id LIMIT ".max(1,min(20,$limit))); $count=0;
        foreach($rows as $r) {
            $p=IdCard::person($r['ref']); $m=TeamRegistry::meta(TeamRegistry::key($r['ref']));
            if (!$p || !$p['valid'] || empty($m['delivery_consent']) || self::recipient($r['ref'])!==$r['recipient'] || hash('sha256',serialize($p).($m['template_json']??''))!==$r['version_hash']) {
                Database::update('team_card_jobs',['state'=>'failed','error'=>'Approval, consent, phone or card version changed. Generate current cards again.'],'id=:i',['i'=>$r['id']]); continue;
            }
            $window=WaTemplates::inSessionWindow($r['recipient']); $template=Settings::getString('team_card_template','');
            if (!$window && $template==='') { Database::update('team_card_jobs',['error'=>'Waiting for an approved document-header template or recipient WhatsApp reply.'],'id=:i',['i'=>$r['id']]); continue; }
            $claimed=Database::transaction(static function()use($r):bool {
                $x=Database::fetch('SELECT state FROM team_card_jobs WHERE id=:i FOR UPDATE',['i'=>$r['id']]);
                if (($x['state']??'')!=='queued') { return false; }
                Database::update('team_card_jobs',['state'=>'processing','error'=>null],'id=:i',['i'=>$r['id']]); return true;
            }); if (!$claimed) { continue; }
            $attempted=false;
            try {
                $pdf=TeamRegistry::open($r['file_key']);
                $ch=curl_init(META_API_BASE.'/'.rawurlencode(META_PHONE_NUMBER_ID).'/media');
                curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.META_ACCESS_TOKEN],CURLOPT_POSTFIELDS=>['messaging_product'=>'whatsapp','type'=>'application/pdf','file'=>new CURLStringFile($pdf,'SHG-Cards.pdf','application/pdf')]]);
                $body=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$media=json_decode((string)$body,true);
                if ($http<200 || $http>=300 || empty($media['id'])) { throw new RuntimeException('Cloud API media upload rejected (HTTP '.$http.').'); }
                $document=['id'=>$media['id'],'filename'=>'SHG-Cards-'.$r['ref'].'.pdf'];
                $payload=$window?['type'=>'document','document'=>$document+['caption'=>'Your approved S Hari Global ID and visiting cards.']]:['type'=>'template','template'=>['name'=>$template,'language'=>['code'=>Settings::getString('team_card_template_lang','en')],'components'=>[['type'=>'header','parameters'=>[['type'=>'document','document'=>$document]]]]]];
                $attempted=true;
                $res=waGraphPost($r['recipient'],$payload,'team_cards',null,null,1);
                $state=!empty($res['success'])&&!empty($res['message_id'])?'sent':(((int)$res['http']===0 || (int)$res['http']>=500 || ((int)$res['http']>=200 && (int)$res['http']<300))?'unknown':'failed');
                Database::update('team_card_jobs',['state'=>$state,'provider_ref'=>$res['message_id']?:null,'sent_at'=>$state==='sent'?date('Y-m-d H:i:s'):null,'error'=>$state==='sent'?null:mb_substr((string)($res['error']?:'No message ID returned; check provider before resend.'),0,255)],'id=:i',['i'=>$r['id']]);
                if ($state==='sent') {
                    $ev=Database::fetch('SELECT * FROM team_card_events WHERE provider_ref=:w',['w'=>$res['message_id']]);
                    if ($ev) { self::status($res['message_id'],$ev['state'],$ev['error'],(int)$ev['event_at']); }
                }
                $count++;
            } catch(Throwable $e) { Database::update('team_card_jobs',['state'=>$attempted?'unknown':'failed','error'=>mb_substr($e->getMessage(),0,255)],'id=:i',['i'=>$r['id']]); }
        } return $count;
    }
    public static function status(string $wamid,string $state,string $error='',int $at=0): void {
        if (!TeamRegistry::ready() || !in_array($state,['sent','delivered','read','failed'],true) || $wamid==='') { return; }
        Database::transaction(static function()use($wamid,$state,$error,$at):void {
            Database::run("INSERT IGNORE INTO team_card_events(provider_ref,state,error,event_at) VALUES(:w,:s,:e,:a)",['w'=>$wamid,'s'=>$state,'e'=>mb_substr($error,0,255),'a'=>$at]);
            $old=Database::fetch('SELECT * FROM team_card_events WHERE provider_ref=:w FOR UPDATE',['w'=>$wamid]);
            $rank=['sent'=>1,'failed'=>2,'delivered'=>3,'read'=>4];
            $chosen=$rank[$state]>=$rank[$old['state']]?$state:$old['state'];
            Database::update('team_card_events',['state'=>$chosen,'error'=>$chosen==='failed'?mb_substr($error,0,255):'','event_at'=>max($at,(int)$old['event_at'])],'provider_ref=:w',['w'=>$wamid]);
            if(in_array($chosen,['delivered','read'],true)){Database::run('UPDATE team_card_jobs SET delivered_at=COALESCE(delivered_at,FROM_UNIXTIME(:t)) WHERE provider_ref=:w',['t'=>$at>0?$at:time(),'w'=>$wamid]);}
            Database::run("UPDATE team_card_jobs SET state=:s,error=:e WHERE provider_ref=:w",['s'=>$chosen,'e'=>$chosen==='failed'?mb_substr($error,0,255):null,'w'=>$wamid]);
        });
    }
    public static function markDelivered(string $id): bool { self::status($id,'delivered'); return true; }
    public static function manualFallback(string $ref): array
    {
        $ref = strtoupper(trim($ref));
        $p = IdCard::person($ref);
        $phone = $p !== null ? (self::recipient($p['ref'])) : '';
        $urls = self::cardUrls($ref);
        $company = Settings::getString('company_name', APP_NAME);

        $msg = "नमस्ते " . ($p['name'] ?? 'साझेदार') . " जी!\n"
             . "तपाईंको " . $company . " परिचयपत्र (ID Card) तथा भिजिटिङ कार्ड तयार छ।\n\n"
             . "📌 कार्ड नं: " . $ref . "\n"
             . "🛡️ अनलाइन प्रमाणीकरण: " . $urls['verify'] . "\n"
             . "📥 कार्ड डाउनलोड: " . appUrl('team/') . "\n\n"
             . "धन्यवाद,\n" . $company;

        $waMe = $phone !== '' ? 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg) : '';

        return [
            'ref'       => $ref,
            'name'      => $p['name'] ?? '',
            'phone'     => $phone,
            'message'   => $msg,
            'wa_me_url' => $waMe,
            'urls'      => $urls,
            'notice'    => 'नोट: WhatsApp Web/App मा बाहिरी लिंकबाट सीधै फाइल स्वतः अट्याच हुँदैन। कार्ड डाउनलोड गरी वा "Share Cards" बटनबाट पठाउनुहोस्।',
        ];
    }
}
