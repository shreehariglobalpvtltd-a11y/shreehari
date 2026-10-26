<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/agentapplications.php';
require_once __DIR__ . '/staffapplications.php';
require_once __DIR__ . '/agentwallet.php';

/** Office workbench. Archiving preserves applications, tickets and ledger entries. */
final class TeamManager
{
    public static function allowed(array $actor): bool
    {
        return in_array((string) ($actor['role'] ?? ''), ['superadmin', 'manager'], true);
    }

    public static function rows(): array
    {
        $out = [];
        $linked = [];
        if (AgentApplications::available()) {
            foreach (Database::fetchAll('SELECT id,ref,name,phone,phone_raw,email,area,type,status,kyc_status,admin_id,web_live FROM agent_applications ORDER BY id DESC') as $r) {
                $r['kind'] = 'agent';
                $r['label'] = $r['type'] === 'org' ? 'Partner' : 'Agent';
                $r['live'] = $r['status'] === 'approved' && (int) $r['web_live'] === 1;
                $out[] = $r;
                if (!empty($r['admin_id'])) { $linked[(int) $r['admin_id']] = true; }
            }
        }
        if (StaffApps::available()) {
            foreach (Database::fetchAll('SELECT id,full_name AS name,phone,phone_country,email,district AS area,status,kyc_status,admin_id,show_on_website FROM staff_applications ORDER BY id DESC') as $r) {
                $r['ref'] = StaffApps::refFor((int) $r['id']);
                $r['kind'] = 'staff'; $r['label'] = 'Staff';
                $r['phone_raw'] = '+' . countryDialCode((string) $r['phone_country']) . $r['phone'];
                $r['live'] = $r['status'] === 'approved' && (int) $r['show_on_website'] === 1;
                $out[] = $r;
                if (!empty($r['admin_id'])) { $linked[(int) $r['admin_id']] = true; }
            }
        }
        foreach (Database::fetchAll("SELECT id,full_name,username,phone,email,is_active FROM admins WHERE role = 'agent' ORDER BY id DESC") as $r) {
            if (isset($linked[(int) $r['id']])) { continue; }
            $out[] = ['id' => $r['id'], 'ref' => 'ACC-' . $r['id'], 'name' => $r['full_name'] ?: $r['username'],
                'phone' => $r['phone'], 'phone_raw' => $r['phone'], 'email' => $r['email'], 'area' => '',
                'kind' => 'account', 'label' => 'Agent account', 'status' => $r['is_active'] ? 'active' : 'inactive',
                'kyc_status' => 'none', 'admin_id' => $r['id'], 'live' => false];
        }
        $archive = Settings::getArray('team_manager_archive', []);
        foreach ($out as &$r) {
            $r['key'] = $r['kind'] . ':' . $r['id'];
            $r['archived'] = isset($archive[$r['key']]);
            $r['code'] = !empty($r['admin_id']) ? AgentWallet::agentCodeLabel((int) $r['admin_id']) : '';
        }
        unset($r);
        return $out;
    }

    public static function find(string $key): ?array
    {
        foreach (self::rows() as $r) { if ($r['key'] === $key) { return $r; } }
        return null;
    }

    public static function archive(string $key, bool $restore, array $actor): void
    {
        if (!self::allowed($actor)) { throw new RuntimeException('Only a manager or superadmin can manage the team.'); }
        $row = self::find($key);
        if ($row === null) { throw new RuntimeException('Record not found.'); }
        if ((int) ($row['admin_id'] ?? 0) === (int) $actor['id']) { throw new RuntimeException('You cannot archive your own login.'); }
        Database::transaction(static function () use ($row, $key, $restore, $actor): void {
            // Serialize map edits, including the first insertion, on the settings table.
            Database::query("INSERT IGNORE INTO settings (skey,svalue,stype,sgroup,is_public) VALUES ('team_manager_archive','{}','json','agent',0)");
            $locked = Database::fetch("SELECT svalue FROM settings WHERE skey = 'team_manager_archive' FOR UPDATE");
            $map = json_decode((string) ($locked['svalue'] ?? '{}'), true) ?: [];
            if ($restore && !isset($map[$key]) || !$restore && isset($map[$key])) { return; }
            $table = match ($row['kind']) { 'agent' => 'agent_applications', 'staff' => 'staff_applications', default => null };
            $column = $row['kind'] === 'staff' ? 'show_on_website' : 'web_live';
            $aid = (int) ($row['admin_id'] ?? 0);
            $account = $aid > 0 ? Database::fetch("SELECT id,is_active FROM admins WHERE id = :id AND role = 'agent' FOR UPDATE", ['id' => $aid]) : null;
            if (!$restore) {
                $snap = ['at' => date('c'), 'by' => (int) $actor['id'], 'live' => $table ? (int) ($row[$column] ?? 0) : 0];
                if ($row['kind'] === 'staff') {
                    $snap['status'] = $row['status'];
                    Database::update('staff_applications', ['status' => 'suspended'], 'id = :id', ['id' => (int) $row['id']]);
                }
                if ($table !== null) { Database::update($table, [$column => 0], 'id = :id', ['id' => (int) $row['id']]); }
                if ($account !== null) {
                    $snap['was_active'] = (int) $account['is_active'];
                    $other = false;
                    foreach (self::rows() as $r) {
                        if ($r['key'] !== $key && (int) ($r['admin_id'] ?? 0) === $aid && !isset($map[$r['key']])) { $other = true; break; }
                    }
                    if (!$other) {
                        $snap['active'] = (int) $account['is_active'];
                        Database::update('admins', ['is_active' => 0], 'id = :id', ['id' => $aid]);
                        $profile = AgentWallet::profile($aid);
                        $snap['listing'] = (int) ($profile['public_listing'] ?? 0);
                        AgentApplications::setAgentProfile($aid, ['public_listing' => 0], (int) $actor['id']);
                    }
                }
                $map[$key] = $snap;
            } else {
                $snap = $map[$key];
                if ($table !== null) { Database::update($table, [$column => (int) $snap['live']], 'id = :id', ['id' => (int) $row['id']]); }
                if ($row['kind'] === 'staff' && isset($snap['status'])) {
                    Database::update('staff_applications', ['status' => $snap['status']], 'id = :id', ['id' => (int) $row['id']]);
                }
                if ($account !== null && !empty($snap['was_active'])) {
                    Database::update('admins', ['is_active' => 1], 'id = :id', ['id' => $aid]);
                }
                if ($account !== null && isset($snap['active'])) {
                    Database::update('admins', ['is_active' => (int) $snap['active']], 'id = :id', ['id' => $aid]);
                    AgentApplications::setAgentProfile($aid, ['public_listing' => (int) ($snap['listing'] ?? 0)], (int) $actor['id']);
                }
                unset($map[$key]);
            }
            Settings::set('team_manager_archive', $map, 'json', 'agent', false);
            Logger::audit($restore ? 'team.restore' : 'team.archive', 'team', $row['ref'], null, ['account' => $aid], 'Office team management by admin #' . (int) $actor['id']);
        });
    }

    public static function compensation(int $aid, array $in, array $actor): void
    {
        if (!self::allowed($actor) || !Auth::can('staff.manage')) { throw new RuntimeException('Staff management permission is required.'); }
        if (!Database::exists("SELECT 1 FROM admins WHERE id = :id AND role = 'agent'", ['id' => $aid])) { throw new RuntimeException('Link an agent login first.'); }
        $mode = (string) ($in['pay_mode'] ?? '');
        $basis = (string) ($in['basis'] ?? '');
        $reason = Security::clean((string) ($in['reason'] ?? ''), 255);
        if (!in_array($mode, ['commission','salary','both','none'], true) || !in_array($basis, ['flat','booking'], true)) { throw new RuntimeException('Choose the payment mode and commission basis.'); }
        if (mb_strlen($reason) < 5) { throw new RuntimeException('Write a reason of at least 5 characters.'); }
        foreach (['rate' => 10000, 'salary' => 10000000] as $k => $max) {
            if (!is_numeric($in[$k] ?? '') || !is_finite((float) $in[$k]) || (float) $in[$k] < 0 || (float) $in[$k] > $max) {
                throw new RuntimeException('Enter a valid non-negative ' . $k . ' amount.');
            }
        }
        $rate = in_array($mode, ['commission','both'], true) ? round((float) $in['rate'], 2) : 0.0;
        $salary = in_array($mode, ['salary','both'], true) ? round((float) $in['salary'], 2) : 0.0;
        Database::transaction(static function () use ($aid, $basis, $rate, $salary, $actor, $reason, $mode): void {
            AgentWallet::setCommissionOverride($aid, $basis, $rate, (int) $actor['id']);
            AgentWallet::setSalary($aid, $salary, (int) $actor['id']);
            Logger::audit('team.compensation', 'admin', (string) $aid, null, ['mode' => $mode, 'basis' => $basis, 'rate' => $rate, 'salary' => $salary], $reason);
        });
    }
}
