<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit; }
require_once __DIR__.'/teammanager.php';
require_once __DIR__.'/teamportal.php';
final class TeamRegistry
{
    public static function ready(): bool {
        try { return Database::fetch("SHOW TABLES LIKE 'team_identity'") !== null; } catch (Throwable $e) { return false; }
    }
    public static function actor(): array {
        $a = Auth::admin();
        if (!$a) { Response::error('Sign in required.',401); }
        if (!TeamManager::allowed($a) || !Auth::can('staff.manage')) { Response::error('Team management permission required.',403); }
        return $a;
    }
    public static function key(string $ref): string {
        if (preg_match('/^(STF|AGR)-(\d+)$/', strtoupper($ref), $m) !== 1) { throw new RuntimeException('Choose an application record.'); }
        $id=(int)$m[2];
        if ($m[1]==='AGR') { $id=(int)Database::scalar('SELECT id FROM agent_applications WHERE ref=:r',['r'=>strtoupper($ref)],$id); }
        return ($m[1]==='STF'?'staff:':'agent:').$id;
    }
    public static function meta(string $key): array {
        return self::ready() ? (Database::fetch('SELECT * FROM team_identity WHERE person_key=:k',['k'=>$key]) ?? []) : [];
    }
    public static function digital(array $row): bool {
        if ($row['kind']==='account') { return false; }
        $a=$row['kind']==='staff' ? StaffApps::find((int)$row['id']) : AgentApplications::find((int)$row['id']);
        if (!$a) { return false; }
        $m=self::meta($row['key']);
        if (($m['origin']??'')==='assisted') { return true; }
        // Positive application evidence; imported accounts/office entries are retained only in the archive.
        return !empty($a['ip_address']) || !empty($a['ip']) || !empty($a['signature_path']) || (!empty($a['signature']) && ($a['fields']['p_occ']??'')!=='Added by the office');
    }
    public static function ensure(string $key, int $by=0): array {
        if (!self::ready()) { throw new RuntimeException('Unified team database upgrade required.'); }
        return Database::transaction(static function() use($key,$by): array {
            $r=TeamManager::find($key); if (!$r || $r['kind']==='account') { throw new RuntimeException('Application not found.'); }
            $prefix=$r['kind']==='staff'?'S':'A';
            Database::run('INSERT IGNORE INTO team_sequences(prefix,next_number) VALUES(:p,0)',['p'=>$prefix]);
            Database::fetch('SELECT * FROM team_sequences WHERE prefix=:p FOR UPDATE',['p'=>$prefix]);
            $m=self::meta($key); if ($m) { return $m; }
            do {
                Database::run('UPDATE team_sequences SET next_number=next_number+1 WHERE prefix=:p',['p'=>$prefix]);
                $n=Database::scalar('SELECT next_number FROM team_sequences WHERE prefix=:p',['p'=>$prefix]); $code=$prefix.'-'.$n;
            } while (Database::exists('SELECT 1 FROM team_code_history WHERE code=:c',['c'=>$code]));
            $id=Database::insert('team_identity',['person_key'=>$key,'code'=>$code,'origin'=>'digital']);
            Database::insert('team_code_history',['code'=>$code,'identity_id'=>$id,'changed_by'=>$by,'reason'=>'Initial code allocation']);
            return self::meta($key);
        });
    }
    public static function changeCode(string $key,string $code,string $reason,int $by): void {
        $code=strtoupper(trim($code));
        if (!preg_match('/^[AS]-[1-9][0-9]{0,8}$/',$code) || mb_strlen(trim($reason))<5) { throw new RuntimeException('Use A-1 / S-1 format and give a reason.'); }
        if (($key[0]==='s'?'S':'A')!==$code[0]) { throw new RuntimeException('Staff uses S- and agents/partners use A-.'); }
        Database::transaction(static function()use($key,$code,$reason,$by):void {
            $m=self::ensure($key,$by);
            Database::fetch('SELECT id FROM team_identity WHERE id=:i FOR UPDATE',['i'=>$m['id']]);
            if ($m['code']===$code) { return; }
            if (Database::exists('SELECT 1 FROM team_code_history WHERE code=:c',['c'=>$code])) { throw new RuntimeException('This code is already assigned or reserved in history.'); }
            Database::insert('team_code_history',['code'=>$code,'identity_id'=>$m['id'],'changed_by'=>$by,'reason'=>Security::clean($reason,255)]);
            Database::update('team_identity',['code'=>$code],'id=:i',['i'=>$m['id']]);
            Logger::audit('team.code_change','team',(string)$m['id'],['code'=>$m['code']],['code'=>$code],$reason);
        });
    }
    public static function refForCode(string $code): ?string {
        if (!self::ready()) { return null; }
        $m=Database::fetch('SELECT i.person_key FROM team_code_history h JOIN team_identity i ON i.id=h.identity_id WHERE h.code=:c',['c'=>strtoupper(trim($code))]);
        if (!$m) { return null; } [$kind,$id]=explode(':',$m['person_key']);
        return $kind==='staff'?StaffApps::refFor((int)$id):(string)Database::scalar('SELECT ref FROM agent_applications WHERE id=:i',['i'=>(int)$id],'');
    }
    public static function save(string $key,array $in,int $by): void {
        $m=self::ensure($key,$by);
        $consent=Security::clean((string)($in['consent_note']??''),255);
        if (mb_strlen($consent)<5) { throw new RuntimeException('Record how and when the applicant consented.'); }
        $set=['route'=>Security::clean((string)($in['route']??''),160),'position_name'=>Security::clean((string)($in['position_name']??''),120),'consent_note'=>$consent,
            'delivery_consent'=>isset($in['delivery_consent'])?1:0,'payout_verified'=>isset($in['payout_verified'])?1:0];
        Database::update('team_identity',$set,'id=:i',['i'=>$m['id']]);
        Logger::audit('team.profile','team',(string)$m['id'],array_intersect_key($m,$set),$set,'Applicant consent / assignment saved by #'.$by);
    }
    public static function verifiedIntl(string $ref): string {
        $key=self::key($ref); [$kind,$id]=explode(':',$key);
        $a=$kind==='staff'?StaffApps::find((int)$id):AgentApplications::find((int)$id);
        if (!$a || empty($a['phone_verified'])) { return ''; }
        $raw=$kind==='staff'?'+'.countryDialCode((string)$a['phone_country']).$a['phone']:(string)($a['phone_raw']?:$a['phone']);
        $d=preg_replace('/\D/','',$raw)??'';
        return preg_match('/^(91[6-9][0-9]{9}|9779[78][0-9]{8})$/',$d) ? $d : '';
        }
    public static function privateDir(): string {
        $dir=defined('TEAM_PRIVATE_PATH')?TEAM_PRIVATE_PATH:dirname(ROOT_PATH).'/private-team';
        if (!is_dir($dir) && !mkdir($dir,0750,true)) { throw new RuntimeException('Private storage unavailable.'); }
        return $dir;
    }
    public static function stash(string $bytes): string {
        require_once __DIR__.'/companydocs.php'; $key=bin2hex(random_bytes(24));
        if (file_put_contents(self::privateDir().'/'.$key,CompanyDocs::seal($bytes),LOCK_EX)===false) { throw new RuntimeException('Private upload could not be saved.'); }
        chmod(self::privateDir().'/'.$key,0640); Logger::adopt(self::privateDir().'/'.$key); return $key;
    }
    public static function open(string $key): string {
        require_once __DIR__.'/companydocs.php';
        if (!preg_match('/^[a-f0-9]{48}$/',$key) || !is_file(self::privateDir().'/'.$key)) { throw new RuntimeException('Private file not found.'); }
        return CompanyDocs::open((string)file_get_contents(self::privateDir().'/'.$key));
    }
    public static function validateApproval(string $key): array {
        require_once __DIR__.'/idcard.php'; $r=TeamManager::find($key); if (!$r || $r['archived']) { throw new RuntimeException('Choose a current application.'); }
        $s=$r['kind']==='staff'; $a=$s?StaffApps::find((int)$r['id']):AgentApplications::find((int)$r['id']);
        $m=self::meta($key); $missing=[];
        if (!self::digital($r)) { $missing[]='digital application'; }
        if (empty($a['phone_verified'])) { $missing[]='verified WhatsApp number'; }
        if (($a['kyc_status']??'')!=='verified') { $missing[]='manual KYC review of original documents'; }
        if ($s ? empty($a['photo_path']) : empty($a['photo'])) { $missing[]='approved passport photo'; }
        if ($s ? empty($a['id_front_path']) : empty($a['doc_photo'])) { $missing[]='identity document'; }
        if ($s ? empty($a['signature_path']) : empty($a['signature'])) { $missing[]='applicant digital signature'; }
        if ($s ? empty($a['address_perm']) && empty($a['address_current']) : empty($a['fields']['p_addr'])) { $missing[]='address'; }
        if (!$s && empty($m['route']) && empty($a['fields']['p_route'])) { $missing[]='assigned route'; }
        if ($s && ($a['role_applied']??'')==='driver' && (empty($a['license_number']) || empty($a['license_expiry']) || $a['license_expiry']<date('Y-m-d'))) { $missing[]='valid driver licence'; }
        if (empty($m['consent_note'])) { $missing[]='recorded applicant consent'; }
        if ($missing) { throw new RuntimeException('Complete before approval: '.implode(', ',$missing).'.'); }
        return $a;
    }
    public static function approve(string $key,int $by): string {
        return Database::transaction(static function()use($key,$by):string {
            [$kind,$id]=explode(':',$key); $table=$kind==='staff'?'staff_applications':'agent_applications';
            Database::fetch('SELECT id FROM '.$table.' WHERE id=:i FOR UPDATE',['i'=>(int)$id]);
            $a=self::validateApproval($key); $m=self::ensure($key,$by);
            if ($kind==='staff') {
                if ($a['status']!=='approved') { StaffApps::decide((int)$id,'approve',$by,[]); }
                // Staff keeps its portal password and Passkeys; no operational account/extra privileges are created.
                $ref=StaffApps::refFor((int)$id);
            } else {
                $aid=(int)($a['admin_id']??0);
                if (!$aid) {
                    $aid=AgentWallet::createAgent((string)$a['name'],(string)($a['phone_raw']?:$a['phone']),$a['type']==='org'?'org':'person',(string)($a['area']??''),null,$by,['username'=>$m['code'],'no_welcome'=>true]);
                }
                AgentApplications::update((int)$id,['status'=>'approved','admin_id'=>$aid],$by); $ref=$a['ref'];
            }
            Logger::audit('team.approved','team',(string)$m['id'],null,['ref'=>$ref,'code'=>$m['code']],'KYC originals and consent reviewed'); return $ref;
        });
    }
    public static function notices(string $target): array {
        if (!self::ready()) { return []; }
        return Database::fetchAll("SELECT * FROM team_notices WHERE target IN ('all',:t) AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY id DESC LIMIT 30",['t'=>$target]);
    }
}
