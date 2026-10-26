<?php
declare(strict_types=1);
// Test-only fixture: an office-verified payout destination on the linked application.
function verifiedPayoutFixture(int $aid): void {
    if (PHP_SAPI !== 'cli' || !str_ends_with((string)Database::scalar('SELECT DATABASE()'), '_test')) { throw new RuntimeException('Test database required.'); }
    require_once INCLUDE_PATH . '/teamregistry.php';
    $app = Database::fetch('SELECT id FROM agent_applications WHERE admin_id=:a LIMIT 1', ['a'=>$aid]);
    if (!$app) {
        $id = Database::insert('agent_applications', ['ref'=>'AGR-' . bin2hex(random_bytes(4)), 'type'=>'ind', 'name'=>'Synthetic payout fixture', 'phone'=>'9800012345', 'fields_json'=>'{}', 'admin_id'=>$aid]);
    } else { $id=(int)$app['id']; }
    TeamRegistry::ensure('agent:' . $id);
    Database::update('team_identity', ['payout_verified'=>1], 'person_key=:k', ['k'=>'agent:' . $id]);
}
