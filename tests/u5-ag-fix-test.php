<?php
/**
 * u5 review fixes (19 Nov 2026):
 *   - AgentPro::payoutTemplate(): the shg_withdraw_update template alone; tellPayout() sends
 *     NO plain text once the template went (a fake WaTplMeta stands in — nothing is sent);
 *   - saveBank(): a changed bank NAME or BRANCH also takes away the office's
 *     "payout details checked" tick (before only UPI / account / IFSC / holder / wallets did);
 *   - admin/agent.php Settle: an open request shows its "Pay to" snapshot (CLI render).
 *
 *   php -c .claude/php-dev.ini tests/u5-ag-fix-test.php
 *
 * Throwaway rows only: admin 'zztest-u5fx-<tag>' (phone 90000xxxxx) — deleted at the end.
 * Needs database/upgrade-2026-11-19-u5-agent-payout.sql (payout_verified_at). CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/agentpro.php';

/** Stand-in for the template sender another unit provides: records, sends nothing. */
final class WaTplMeta
{
    /** @var list<array{0:string,1:string,2:array,3:string}> */
    public static array $calls = [];
    public static bool $ok = true;
    public static function sendNamed(string $phone, string $name, array $params, string $country): bool
    {
        self::$calls[] = [$phone, $name, $params, $country];
        return self::$ok;
    }
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? ' — ' . $extra : '') . "\n"; }
}

$tag = (string) random_int(1000, 9999);
$id = 0;
echo "\n=== u5 review fixes ===\n\n";
try {
    $id = (int) Database::insert('admins', ['username' => 'zztest-u5fx-' . $tag, 'password_hash' => 'x', 'full_name' => 'ZZTEST u5fx',
        'phone' => '90000' . $tag . '7', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0]);
    AgentPro::ensureMember($id, 'agent');

    /* ---- template first, never both ---- */
    $logs = static fn(): int => (int) Database::scalar('SELECT COUNT(*) FROM message_logs', [], 0);
    $before = $logs();
    check('payoutTemplate: true when the template sender says sent', AgentPro::payoutTemplate($id, 'paid', 500.0, 'UTR123456789'));
    $c = WaTplMeta::$calls[0] ?? null;
    check('template name + params (name, ₹ amount, status, reference)', $c !== null && $c[1] === 'shg_withdraw_update'
        && $c[2][0] === 'ZZTEST u5fx' && str_contains((string) $c[2][1], '500') && $c[2][2] === 'paid' && $c[2][3] === 'UTR123456789' && $c[3] === 'IN',
        json_encode($c));
    WaTplMeta::$calls = [];
    AgentPro::payoutTemplate($id, 'declined', 200.0, '');
    check('an empty detail goes as "-" (Meta refuses empty parameters)', (WaTplMeta::$calls[0][2][3] ?? null) === '-');
    AgentPro::tellPayout($id, 'paid', 500.0, 'UTR1', 'plain text that must NOT go');
    check('tellPayout: template sent → no plain WhatsApp text written', $logs() === $before, ($logs() - $before) . ' new message_logs rows');

    /* ---- bank name / branch clear the tick ---- */
    if (AgentPro::memberHas('payout_verified_at')) {
        AgentPro::saveBank($id, ['bank_holder' => 'ZZTEST HOLDER', 'bank_name' => 'ZZ Bank', 'bank_branch' => 'Nepalgunj'], 0);
        $tick = static function () use ($id): void {
            Database::update('ap_members', ['payout_verified_at' => date('Y-m-d H:i:s')], 'admin_id = :a', ['a' => $id]);
        };
        $ticked = static fn(): bool => Database::exists('SELECT 1 FROM ap_members WHERE admin_id = :a AND payout_verified_at IS NOT NULL', ['a' => $id]);
        $tick();
        AgentPro::saveBank($id, ['bank_holder' => 'ZZTEST HOLDER', 'bank_name' => 'ZZ Bank', 'bank_branch' => 'Nepalgunj'], 0);
        check('same details saved again: tick kept', $ticked());
        AgentPro::saveBank($id, ['bank_branch' => 'Rupaidiha'], 0);
        check('changed bank BRANCH: tick cleared', !$ticked());
        $tick();
        AgentPro::saveBank($id, ['bank_name' => 'Other Bank'], 0);
        check('changed bank NAME: tick cleared', !$ticked());
    } else {
        echo "  (skipped tick part: apply database/upgrade-2026-11-19-u5-agent-payout.sql)\n";
    }
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($id > 0) {
        try { Database::delete('agent_payout_requests', 'agent_admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $id]);
        try { Database::delete('ap_members', 'admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
        try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
        Database::delete('admins', 'id = :a', ['a' => $id]);
    }
}
echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
