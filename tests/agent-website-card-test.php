<?php
/**
 * tests/agent-website-card-test.php — 19 Oct 2026, owner: agents live on the
 * website with name, photo, address, number and WhatsApp; the office adds,
 * edits and removes them; KYC carries a bank account.
 *
 *   php -c .claude/php-dev.ini tests/agent-website-card-test.php
 *
 * Runs against a TEST database only (name contains "test" or starts "lt_"),
 * after database/upgrade-2026-10-19-agent-website-card.sql. Every row it
 * writes is removed at the end. Exit 1 on any failure.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentapplications.php';

$DBN = (string) DB_NAME;
if (!str_contains($DBN, 'test') && !str_starts_with($DBN, 'lt_')) {
    fwrite(STDERR, "refusing to run against '$DBN' (not a test database)\n");
    exit(2);
}

$PASS = 0; $FAIL = 0;
function check(string $label, bool $ok, string $why = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $label" . ($why !== '' ? "  [$why]" : '') . "\n"; }
}
function throws(callable $fn): string
{
    try { $fn(); } catch (Throwable $e) { return $e->getMessage() !== '' ? $e->getMessage() : 'threw'; }
    return '';
}
$findIn = static function (string $name): ?array {
    foreach (AgentApplications::directory() as $it) {
        if ($it['name'] === $name) { return $it; }
    }
    return null;
};

echo "\n=== agent website card (19 Oct 2026) ===\n\n";
AgentApplications::resetCache();
check('migration applied (web_* columns)', AgentApplications::webColumnsAvailable());

$TAG = 'Zz Webcard ' . bin2hex(random_bytes(3));
$ids = [];
$ACC = [];
try {
    // a 200x150 test photo, made here
    $im = imagecreatetruecolor(200, 150);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 80, 200));
    ob_start(); imagepng($im); $png = (string) ob_get_clean(); imagedestroy($im);
    $photo = AgentApplications::shrinkPhoto($png);
    check('shrinkPhoto makes a JPEG data URI', str_starts_with($photo, 'data:image/jpeg;base64,'));
    check('shrinkPhoto refuses a non-image', throws(static fn() => AgentApplications::shrinkPhoto('hello')) !== '');

    check('office add refuses an empty name', throws(static fn() => AgentApplications::adminCreate(['name' => '', 'phone' => '9812345678'], 1, true)) !== '');
    check('office add refuses a fake number', throws(static fn() => AgentApplications::adminCreate(['name' => $TAG, 'phone' => '1111111111'], 1, true)) !== '');

    $made = AgentApplications::adminCreate([
        'name' => $TAG, 'phone' => '+977 9812345678', 'type' => 'ind', 'area' => 'Nepalgunj',
        'web_addr' => 'Bus Park Road, Nepalgunj-4', 'photo' => $photo,
        'public_ok' => 1, 'web_photo' => 1, 'web_live' => 1, 'kyc_status' => 'none',
        'b_name' => $TAG, 'b_bank' => 'Nabil Bank', 'b_acc' => '0123456789012', 'b_ifsc' => 'ab cd', 'p_idno' => '12-34-567',
    ], 1, true);
    $ids[] = $made['id'];
    $app = AgentApplications::find($made['id']);
    check('office add is Approved', ($app['status'] ?? '') === 'approved');
    check('phone stored as digits, typed +977 kept', ($app['phone'] ?? '') === '9812345678' && str_starts_with((string) $app['phone_raw'], '+977'));
    check('bank details in fields_json', ($app['fields']['b_acc'] ?? '') === '0123456789012' && ($app['fields']['b_ifsc'] ?? '') === 'ABCD');
    check('bank typed = KYC submitted', ($app['kyc_status'] ?? '') === 'submitted');
    check('web key made', preg_match('/^[a-f0-9]{20}$/', (string) $app['web_key']) === 1);
    check('isLive', AgentApplications::isLive($app));

    $it = $findIn($TAG);
    check('on the public list', $it !== null);
    check('list: full phone + Nepal WhatsApp', $it !== null && $it['phone'] === '9812345678' && $it['wa'] === '9779812345678');
    check('list: public address', $it !== null && $it['addr'] === 'Bus Park Road, Nepalgunj-4');
    check('list: photo URL by key', $it !== null && $it['photo'] === '/api/agent-photo.php?k=' . $app['web_key']);
    check('list: no bank / ID leak', $it !== null && !str_contains(json_encode($it), '0123456789') && !str_contains(json_encode($it), '12-34-567'));
    $img = AgentApplications::publicPhoto((string) $app['web_key']);
    check('photo served', $img !== null && $img[0] === 'image/jpeg' && strlen($img[1]) > 100);
    check('bad key = no photo', AgentApplications::publicPhoto('zz') === null && AgentApplications::publicPhoto(str_repeat('a', 20)) === null);

    // hide the photo and the number
    AgentApplications::saveCard($made['id'], ['web_photo' => 0, 'public_ok' => 0], 1, false);
    $it = $findIn($TAG);
    check('photo off -> no URL, not served', $it !== null && $it['photo'] === '' && AgentApplications::publicPhoto((string) $app['web_key']) === null);
    check('number off -> masked, no WhatsApp', $it !== null && str_contains($it['phone'], '•') && $it['wa'] === '');

    // edit name, without KYC rights the bank stays
    $changed = AgentApplications::saveCard($made['id'], ['name' => $TAG . ' X', 'b_acc' => '999'], 1, false);
    $app = AgentApplications::find($made['id']);
    check('edit name', $app['name'] === $TAG . ' X' && in_array('name', $changed, true));
    check('no KYC right -> bank untouched', ($app['fields']['b_acc'] ?? '') === '0123456789012');

    // hide from website
    AgentApplications::saveCard($made['id'], ['web_live' => 0], 1, false);
    check('Live off -> off the list', $findIn($TAG . ' X') === null);
    AgentApplications::saveCard($made['id'], ['web_live' => 1], 1, false);
    check('Live on -> back', $findIn($TAG . ' X') !== null);

    // a not-approved application is never listed
    AgentApplications::update($made['id'], ['status' => 'reviewing'], 1);
    check('not approved -> off the list', $findIn($TAG . ' X') === null);

    // remove
    $ref = AgentApplications::remove($made['id'], 1);
    check('remove', $ref !== '' && AgentApplications::find($made['id']) === null);
    $ids = [];

    // one list: agent accounts come in as cards
    $accName = $TAG . ' Acc';
    $accId = Database::insert('admins', [
        'username' => 'zzwc' . bin2hex(random_bytes(3)), 'password_hash' => password_hash('x' . random_int(0, 99999), PASSWORD_DEFAULT),
        'full_name' => $accName, 'phone' => '9812399988', 'role' => 'agent', 'is_active' => 1,
    ]);
    $hiddenId = Database::insert('admins', [
        'username' => 'zzwh' . bin2hex(random_bytes(3)), 'password_hash' => password_hash('x' . random_int(0, 99999), PASSWORD_DEFAULT),
        'full_name' => $accName . ' Own', 'phone' => '9812399977', 'role' => 'agent', 'is_active' => 1,
    ]);
    $ACC = [$accId, $hiddenId];
    $res = AgentApplications::importAgents(1, [$hiddenId]);
    check('import made cards for the accounts', isset($res['made'][$accId], $res['made'][$hiddenId]));
    $cardId = (int) Database::scalar('SELECT id FROM agent_applications WHERE admin_id = :a', ['a' => $accId], 0);
    $ids[] = $cardId;
    $ids[] = (int) Database::scalar('SELECT id FROM agent_applications WHERE admin_id = :a', ['a' => $hiddenId], 0);
    $card = AgentApplications::find($cardId);
    check('imported card: approved, linked, live, full number', $card['status'] === 'approved' && (int) $card['admin_id'] === $accId && (int) $card['web_live'] === 1 && (int) $card['public_ok'] === 1);
    check('imported account on the website once', count(array_filter(AgentApplications::directory(), static fn($i) => $i['name'] === $accName)) === 1);
    check('hidden account not on the website', $findIn($accName . ' Own') === null);
    $again = AgentApplications::importAgents(1);
    check('import again makes nothing new for them', !isset($again['made'][$accId]) && !isset($again['made'][$hiddenId]));
    AgentApplications::saveCard($cardId, ['phone' => '+91 9876511122', 'web_addr' => 'Station Road'], 1, false);
    $it = $findIn($accName);
    check('card number edit shows on the website', $it !== null && $it['phone'] === '9876511122' && $it['wa'] === '919876511122' && $it['addr'] === 'Station Road');
    Database::update('admins', ['is_active' => 0], 'id = :id', ['id' => $accId]);
    check('switched-off account: card not shown', $findIn($accName) === null);
    $agRow = array_values(array_filter(AgentApplications::agents(), static fn($a) => (int) $a['id'] === $accId))[0] ?? [];
    check('agents() carries the card id', (int) ($agRow['card_id'] ?? 0) === $cardId);

    // the apply page sends the bank fields; the page source carries them
    $page = (string) file_get_contents(dirname(__DIR__) . '/agreements/apply.html');
    check('apply form has bank fields', str_contains($page, 'id="b_acc"') && str_contains($page, "'b_name', 'b_bank', 'b_acc', 'b_ifsc', 'b_upi'"));
    $c = AgentApplications::cleanFields(['b_acc' => '123', 'b_ifsc' => 'SBIN0001', 'zz' => 'x']);
    check('cleanFields keeps bank, drops unknown', ($c['b_acc'] ?? '') === '123' && !isset($c['zz']));
} finally {
    foreach ($ids as $id) {
        Database::run('DELETE FROM agent_applications WHERE id = :id', ['id' => $id]);
    }
    foreach ($ACC as $id) {
        Database::run('DELETE FROM agent_applications WHERE admin_id = :id', ['id' => $id]);
        Database::run('DELETE FROM admins WHERE id = :id', ['id' => $id]);
    }
}

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL === 0 ? 0 : 1);
