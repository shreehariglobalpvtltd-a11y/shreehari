<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit; }

final class AiIdentity
{
    private const PERMISSIONS = ['bookings.view','bookings.edit','bookings.cancel','bookings.cancel_own',
        'payments.view','reports.view','reports.export','commissions.view','messages.view','support.view','support.reply'];

    public static function web(bool $staffChannel = false): array
    {
        $admin = $staffChannel ? Auth::admin() : null;
        if ($staffChannel && !$admin) { throw new RuntimeException('Please sign in to the staff panel.'); }
        if ($admin) {
            $fresh = Database::fetch('SELECT id,role,is_active,must_change_pw FROM admins WHERE id=:id', ['id'=>$admin['id']]);
            if (!$fresh || !$fresh['is_active'] || !empty($fresh['must_change_pw']) || $fresh['role'] !== $admin['role']) {
                throw new RuntimeException('Please sign in again and complete your account security checks.');
            }
            $perms = array_values(array_filter(self::PERMISSIONS, static fn($p)=>Auth::can($p)));
            return self::context((string)$admin['role'], (int)$admin['id'], 'staff', '', $perms,
                Auth::bookingScopeAdminId(), AiPrivacy::owner('admin',(string)$admin['id']));
        }
        $user = Auth::user();
        if ($user) {
            return self::context('customer', (int)$user['id'], 'customer', normalisePhone((string)$user['phone']), [], null,
                AiPrivacy::owner('user',(string)$user['id']));
        }
        if (empty($_SESSION['ai_guest_id'])) { $_SESSION['ai_guest_id'] = bin2hex(random_bytes(24)); }
        return self::context('anonymous', 0, 'customer', '', [], null, AiPrivacy::owner('guest',$_SESSION['ai_guest_id']));
    }

    // A signed provider webhook proves a sender, never an administrative session.
    public static function whatsapp(string $from): array
    {
        $digits = preg_replace('/\D/','',$from) ?? '';
        if (!preg_match('/^[1-9]\d{9,14}$/',$digits)) { throw new RuntimeException('Invalid sender.'); }
        return self::context('customer', 0, 'whatsapp', normalisePhone($digits), [], null,
            AiPrivacy::owner('whatsapp',$digits)) + ['recipient'=>$digits];
    }

    private static function context(string $role,int $id,string $channel,string $phone,array $perms,?int $scope,string $owner): array
    {
        return ['role'=>$role,'actor_id'=>$id,'channel'=>$channel,'phone'=>$phone,'permissions'=>$perms,
            'scope_id'=>$scope,'owner_key'=>$owner];
    }

    public static function can(array $ctx,string $permission): bool
    {
        return $ctx['channel']==='staff' && ($ctx['role']==='superadmin' || in_array($permission,$ctx['permissions'],true));
    }

    public static function owns(array $b,array $ctx): bool
    {
        if ($ctx['channel']==='staff') {
            return self::can($ctx,'bookings.view') && ($ctx['scope_id']===null || (int)($b['sold_by_admin_id']??0)===$ctx['scope_id']);
        }
        if ($ctx['role']==='anonymous') { return false; }
        return ($ctx['channel']==='customer' && $ctx['actor_id']>0 && (int)($b['user_id']??0)===$ctx['actor_id'])
            || ($ctx['phone']!=='' && hash_equals($ctx['phone'],normalisePhone((string)($b['contact_phone']??''))));
    }

    // Worker permissions are refreshed from the DB, never trusted from a saved job or model.
    public static function refresh(array $ctx): array
    {
        if ($ctx['channel']!=='staff') { return $ctx; }
        $row = Database::fetch('SELECT * FROM admins WHERE id=:id AND is_active=1',['id'=>$ctx['actor_id']]);
        if (!$row || !empty($row['must_change_pw'])) { throw new RuntimeException('Staff access expired.'); }
        $old = $_SESSION[ADMIN_SESSION_KEY]??null;
        $row['last_seen']=time();
        $row['permissions']=is_array($row['permissions']??null)?$row['permissions']:(json_decode((string)($row['permissions']??'[]'),true)?:[]);
        $_SESSION[ADMIN_SESSION_KEY]=$row;
        try { return self::web(true); }
        finally { if ($old===null) { unset($_SESSION[ADMIN_SESSION_KEY]); } else { $_SESSION[ADMIN_SESSION_KEY]=$old; } }
    }
}
