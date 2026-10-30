<?php
/**
 * =====================================================================
 *  api/agentpro.php — JSON for the join form (/join.php) and the member
 *  portal (/me/). One file, ?a=<action>.
 *
 *  JOIN (no sign-in; a draft is identified by its 32-hex draft key, kept
 *  in the applicant's browser):
 *    start · get · save · otp_send · otp_verify · reverse · reverse_status
 *    upload (front|back|selfie|thumb|extra, + qr text) · read_status
 *    drop_extra · submit
 *  ME (signed-in staff session):
 *    me · wallet · photo · profile_save · bank_save · withdraw · deposit
 *    statement · kyc_upload · pk_reg_options · pk_reg_verify · pk_delete · logout
 *  SIGN-IN (no session yet):
 *    login_otp_start · login_otp_verify · pk_login_options · pk_login_verify
 *
 *  Every POST carries the session CSRF token (X-CSRF-Token header).
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/agentpro.php';

Security::sendHeaders(true);
if (!AgentPro::available()) {
    Response::error('Agent Pro is not installed yet.', 503);
}

$a = (string) ($_GET['a'] ?? '');
$post = Security::isPost();
if ($post && !in_array($a, ['csrf'], true)) {
    Security::requireCsrf();
}
try {
    Security::requireRateLimit('apapi_ip', Security::clientIp(), 240, 300);
} catch (Throwable $e) {
    Response::error('Too many requests — wait a minute.', 429);
}

/** The draft named by the request, or a 404. */
function ap_draft(): array
{
    $key = (string) Response::field('key', '');
    $d = AgentPro::draft($key);
    if ($d === null) {
        Response::error('Your form has expired — please start again.', 404);
    }
    return $d;
}

/** What the browser may see of a draft (never the encrypted numbers or file paths). */
function ap_draft_view(array $d): array
{
    $extras = array_map(static fn(array $x, int $i): array => ['i' => $i, 'label' => (string) ($x['label'] ?? '')],
        jsonColumn($d['extras'] ?? null), array_keys(jsonColumn($d['extras'] ?? null)));
    $out = [];
    foreach (['ref', 'status', 'kind', 'agent_kind', 'role_wanted', 'designation', 'full_name', 'org_name', 'phone', 'whatsapp',
              'email', 'dob', 'gender', 'guardian', 'address', 'city', 'district', 'state', 'country', 'pincode', 'id_type',
              'id_number_masked', 'id_expires_on', 'bank_holder', 'bank_name', 'bank_account_last4', 'bank_ifsc', 'bank_branch',
              'upi_id', 'esewa_id', 'khalti_id'] as $k) {
        $out[$k] = $d[$k] ?? null;
    }
    $out['verified'] = !empty($d['wa_verified_at']);
    $out['verifiedVia'] = $d['wa_verified_via'] ?? null;
    $out['has'] = ['front' => !empty($d['doc_front']), 'back' => !empty($d['doc_back']), 'selfie' => !empty($d['selfie']),
                   'thumb' => !empty($d['thumb'])];
    $out['extras'] = $extras;
    return $out;
}

/** Signed-in member or 401. */
function ap_me(): array
{
    $admin = Auth::admin();
    if ($admin === null) {
        Response::unauthorized('Please sign in.');
    }
    return $admin;
}

try {
    switch ($a) {

        /* ============================ JOIN ============================ */

        case 'start':
            Security::requirePost();
            Security::requireRateLimit('apjoin_start', Security::clientIp(), 12, 3600);
            $d = AgentPro::newDraft((string) Response::field('invite', ''));
            Response::success(['key' => $d['draft_key'], 'draft' => ap_draft_view($d)]);

        case 'get':
            $d = ap_draft();
            Response::success(['draft' => ap_draft_view($d)]);

        case 'save':
            Security::requirePost();
            $d = ap_draft();
            $in = Response::input();
            unset($in['key']);
            $d = AgentPro::saveDraft((string) $d['draft_key'], $in);
            Response::success(['draft' => ap_draft_view($d)]);

        case 'otp_send':
            Security::requirePost();
            $d = ap_draft();
            $r = AgentPro::sendJoinOtp((string) $d['draft_key'], (string) Response::field('phone', ''), (string) Response::field('country', ''));
            Response::success($r, 'Code sent on WhatsApp.');

        case 'otp_verify':
            Security::requirePost();
            $d = ap_draft();
            $r = AgentPro::verifyJoinOtp((string) $d['draft_key'], (string) Response::field('code', ''));
            Response::success($r + ['draft' => ap_draft_view(AgentPro::draft((string) $d['draft_key']) ?? $d)], 'Verified.');

        case 'reverse':
            Security::requirePost();
            $d = ap_draft();
            Response::success(AgentPro::reverseCode((string) $d['draft_key']));

        case 'reverse_status':
            $d = ap_draft();
            Response::success(['verified' => !empty($d['wa_verified_at']), 'phone' => $d['phone'], 'via' => $d['wa_verified_via']]);

        case 'upload':
            Security::requirePost();
            require_once INCLUDE_PATH . '/agentpro-docs.php';
            $d = ap_draft();
            Security::requireRateLimit('apjoin_upload', (string) $d['draft_key'], 40, 3600);
            $slot = (string) Response::field('slot', '');
            if (empty($_FILES['file'])) {
                Response::error('Choose a photo first.', 422);
            }
            $saved = AgentPro::storeDraftDoc((string) $d['draft_key'], $slot, $_FILES['file'], (string) Response::field('label', ''));
            $read = null;
            $queued = false;
            if (in_array($slot, ['front', 'back'], true)) {
                $type = (string) (Response::field('id_type', '') ?: ($d['id_type'] ?? ''));
                if ($type !== '' && $type !== (string) ($d['id_type'] ?? '') && isset(AgentPro::ID_TYPES[$type])) {
                    AgentPro::saveDraft((string) $d['draft_key'], ['id_type' => $type]);   // the photo says which ID it is
                }
                $qr = mb_substr((string) Response::field('qr', ''), 0, 8000);
                $read = $saved['mime'] === 'application/pdf'
                    ? ApDocRead::merge($qr !== '' && ($q = ApDocRead::parseQr($qr)) !== null ? [['src' => 'qr', 'fields' => $q]] : [])
                    : ApDocRead::read($saved['abs'], $type, $slot, $qr);
                AgentPro::noteReadout((string) $d['draft_key'], $slot, $read);
                // The vision model reads in the background; the form asks again in a while.
                require_once INCLUDE_PATH . '/agentpro-ai.php';
                if ($saved['mime'] !== 'application/pdf' && ApAi::enabled()) {
                    Database::insert('ap_jobs', ['phone' => 'form', 'kind' => 'doc', 'text' => json_encode([
                        'key' => $d['draft_key'], 'path' => $saved['path'], 'type' => $type, 'side' => $slot,
                    ])]);
                    require_once INCLUDE_PATH . '/agentpro-wa.php';
                    ApWa::kickWorker();
                    $queued = true;
                }
            }
            Response::success(['slot' => $slot, 'read' => $read, 'aiQueued' => $queued,
                'draft' => ap_draft_view(AgentPro::draft((string) $d['draft_key']) ?? $d)]);

        case 'read_status':
            $d = ap_draft();
            $all = jsonColumn($d['readout'] ?? null);
            $ai = [];
            foreach (['ai_front', 'ai_back'] as $k) {
                foreach ((array) ($all[$k]['fields'] ?? []) as $f => $v) {
                    $ai[$f] = $ai[$f] ?? $v;
                }
            }
            $waiting = (int) Database::scalar("SELECT COUNT(*) FROM ap_jobs WHERE kind = 'doc' AND status IN ('queued','running') AND text LIKE :k",
                ['k' => '%' . $d['draft_key'] . '%'], 0);
            Response::success(['ai' => $ai, 'waiting' => $waiting > 0]);

        case 'drop_extra':
            Security::requirePost();
            $d = ap_draft();
            AgentPro::dropDraftExtra((string) $d['draft_key'], (int) Response::field('i', -1));
            Response::success(['draft' => ap_draft_view(AgentPro::draft((string) $d['draft_key']) ?? $d)]);

        case 'submit':
            Security::requirePost();
            $d = ap_draft();
            if (Response::field('consent', '') !== '1' && Response::field('consent', false) !== true) {
                Response::invalid(['consent' => 'Please tick the consent box.']);
            }
            $app = AgentPro::submit((string) $d['draft_key']);
            Response::success(['ref' => $app['ref'], 'status' => $app['status']], 'Sent to the office.');

        /* ============================ SIGN IN ============================ */

        case 'login_otp_start':
            Security::requirePost();
            $r = Auth::agentLoginStart((string) Response::field('code', ''), (string) Response::field('mobile', ''));
            $r['ok'] ? Response::success(['resend' => $r['resend'] ?? 60], 'Code sent on WhatsApp.') : Response::error((string) $r['error'], 422);

        case 'login_otp_verify':
            Security::requirePost();
            $r = Auth::agentLoginVerify((string) Response::field('code', ''), (string) Response::field('mobile', ''), (string) Response::field('otp', ''));
            $r['ok'] ? Response::success(['csrf' => Security::csrfToken()]) : Response::error((string) $r['error'], 422);

        case 'pk_login_options':
            Security::requirePost();
            require_once INCLUDE_PATH . '/agentpro-passkey.php';
            Response::success(ApPasskey::loginOptions());

        case 'pk_login_verify':
            Security::requirePost();
            require_once INCLUDE_PATH . '/agentpro-passkey.php';
            Security::requireRateLimit('appk_login', Security::clientIp(), 20, 600);
            $admin = ApPasskey::loginVerify((array) Response::field('credential', []));
            ApPasskey::startSession($admin);
            Response::success(['csrf' => Security::csrfToken()]);

        case 'csrf':
            Response::success(['csrf' => Security::csrfToken()]);

        /* ============================ ME ============================ */

        case 'me':
            $admin = ap_me();
            require_once INCLUDE_PATH . '/agentpro-card.php';
            $id = (int) $admin['id'];
            $m = AgentPro::member($id);
            $pk = Database::fetchAll('SELECT id, label, created_at, last_used_at FROM ap_passkeys WHERE admin_id = :a ORDER BY id', ['a' => $id]);
            $wa = Database::fetchAll('SELECT phone, verified_at, last_seen_at FROM ap_wa_links WHERE admin_id = :a AND revoked_at IS NULL', ['a' => $id]);
            $cards = [];
            foreach (ApCard::SIDES as $s) {
                $cards[$s] = '/api/agentpro-card.php?m=' . $id . '&s=' . $s . '&v=' . $m['photoVer'];
            }
            unset($m['company']);
            Response::success([
                'member' => $m, 'cards' => $cards, 'passkeys' => $pk, 'whatsapp' => $wa,
                'share' => appUrl('c.php?u=' . rawurlencode((string) $m['cardToken'])),
                'verify' => appUrl('id.php?c=' . rawurlencode((string) $m['cardToken'])),
                'company' => ['name' => Settings::getString('company_name', APP_NAME), 'upi' => Settings::getString('upi_id', ''),
                              'upiName' => Settings::getString('upi_name', APP_NAME), 'wa' => Settings::bookingWhatsApp()],
                'csrf' => Security::csrfToken(),
            ]);

        case 'wallet':
            $admin = ap_me();
            $id = (int) $admin['id'];
            $entries = array_map(static fn(array $e): array => [
                'id' => (int) $e['id'], 'at' => (string) $e['created_at'], 'type' => (string) $e['entry_type'],
                'account' => (string) $e['account'], 'amount' => (float) $e['amount'], 'note' => (string) ($e['note'] ?? ''),
                'ref' => (string) ($e['ref'] ?? ''),
            ], AgentWallet::entries($id, 60));
            Response::success([
                'balances' => AgentWallet::balances($id), 'summary' => AgentWallet::summary($id),
                'deposit' => AgentWallet::depositInfo($id),
                'entries' => $entries,
                'payouts' => array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'amount' => (float) $r['amount'], 'status' => $r['status'],
                    'at' => $r['created_at'], 'note' => $r['decided_note'] ?? $r['note'] ?? ''], AgentWallet::payoutRequests($id, 20)),
                'deposits' => array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'amount' => (float) $r['amount'], 'status' => $r['status'],
                    'at' => $r['created_at'], 'utr' => $r['utr'], 'purpose' => $r['purpose'], 'note' => $r['decided_note'] ?? ''], AgentPro::deposits($id, 20)),
                'payoutTo' => trim((string) (AgentWallet::profile($id)['payout_method'] ?? '') . ' ' . (string) (AgentWallet::profile($id)['payout_account'] ?? '')),
                'minPayout' => Settings::getFloat('agent_payout_min', 0.0),
            ]);

        case 'photo':
            Security::requirePost();
            $admin = ap_me();
            if (empty($_FILES['file'])) {
                Response::error('Choose a photo.', 422);
            }
            AgentPro::setPhoto((int) $admin['id'], $_FILES['file'], (int) $admin['id']);
            $m = AgentPro::member((int) $admin['id']);
            Response::success(['photoUrl' => $m['photoUrl'], 'photoVer' => $m['photoVer']], 'Photo updated — your ID card uses it now.');

        case 'profile_save':
            Security::requirePost();
            $admin = ap_me();
            $in = array_intersect_key(Response::input(), array_flip([
                'vc_whatsapp', 'vc_phone', 'vc_email', 'vc_tagline', 'vc_greeting', 'vc_theme', 'emergency_name', 'emergency_phone',
                'blood_group', 'dob', 'gender', 'guardian', 'lang',
            ]));
            AgentPro::saveCard((int) $admin['id'], $in, (int) $admin['id']);
            Response::success(['member' => array_diff_key(AgentPro::member((int) $admin['id']) ?? [], ['company' => 1])], 'Saved — your cards are updated.');

        case 'bank_save':
            Security::requirePost();
            $admin = ap_me();
            AgentPro::saveBank((int) $admin['id'], Response::input(), (int) $admin['id']);
            Response::success(null, 'Payout details saved.');

        case 'withdraw':
            Security::requirePost();
            $admin = ap_me();
            $id = AgentWallet::requestPayout((int) $admin['id'], (float) Response::field('amount', 0), (string) Response::field('note', ''));
            try {
                Notify::adminNote('💸 Withdrawal request', ['Member' => (string) $admin['full_name'], 'Amount' => inr((float) Response::field('amount', 0)),
                    'Open' => appUrl('admin/team.php?tab=money')]);
            } catch (Throwable $e) {
            }
            Response::success(['id' => $id], 'Request sent to the office.');

        case 'deposit':
            Security::requirePost();
            $admin = ap_me();
            $id = AgentPro::requestDeposit((int) $admin['id'], (float) Response::field('amount', 0), (string) Response::field('purpose', 'handover'),
                (string) Response::field('method', 'upi'), (string) Response::field('utr', ''), $_FILES['proof'] ?? null, (string) Response::field('note', ''));
            try {
                Notify::adminNote('💵 Payment to verify', ['Member' => (string) $admin['full_name'], 'Amount' => inr((float) Response::field('amount', 0)),
                    'UTR' => (string) Response::field('utr', ''), 'Open' => appUrl('admin/team.php?tab=money')]);
            } catch (Throwable $e) {
            }
            Response::success(['id' => $id], 'Sent — the office will confirm.');

        case 'pay_link':
            Security::requirePost();
            $admin = ap_me();
            require_once INCLUDE_PATH . '/agentpro-card.php';
            $amt = round((float) Response::field('amount', 0), 2);
            $upi = Settings::getString('upi_id', '');
            if ($amt <= 0 || $upi === '') {
                Response::error($upi === '' ? 'The company has no UPI ID set.' : 'Enter an amount.', 422);
            }
            $exp = time() + 86400;
            $amtS = number_format($amt, 2, '.', '');
            $m = AgentPro::member((int) $admin['id'], false);
            Response::success([
                'qr'  => '/api/agentpro-card.php?pay=' . $amtS . '&m=' . (int) $admin['id'] . '&e=' . $exp . '&k=' . ApCard::sig((int) $admin['id'], 'pay' . $amtS, $exp),
                'upi' => upiLink($upi, Settings::getString('upi_name', APP_NAME), $amt, 'SHG ' . ($m['idNo'] ?? '')),
                'to'  => $upi,
            ]);

        case 'statement':
            Security::requirePost();
            $admin = ap_me();
            require_once INCLUDE_PATH . '/statement.php';
            require_once INCLUDE_PATH . '/reportpdf.php';
            $from = (string) Response::field('from', date('Y-m-01'));
            $to = (string) Response::field('to', date('Y-m-d'));
            $pdf = Statement::agentStatementPdf((int) $admin['id'], $from, $to, (int) $admin['id']);
            Response::success(['url' => $pdf['url']]);

        case 'kyc_upload':
            Security::requirePost();
            $admin = ap_me();
            if (empty($_FILES['file'])) {
                Response::error('Choose a file.', 422);
            }
            AgentWallet::saveKycDoc((int) $admin['id'], $_FILES['file'], ((string) Response::field('slot', '1')) === '2' ? '2' : '1');
            Response::success(null, 'Document saved. The office will check it.');

        case 'pk_reg_options':
            Security::requirePost();
            $admin = ap_me();
            require_once INCLUDE_PATH . '/agentpro-passkey.php';
            Response::success(ApPasskey::registerOptions($admin));

        case 'pk_reg_verify':
            Security::requirePost();
            $admin = ap_me();
            require_once INCLUDE_PATH . '/agentpro-passkey.php';
            $id = ApPasskey::registerVerify($admin, (array) Response::field('credential', []), (string) Response::field('label', ''));
            Response::success(['id' => $id], 'Fingerprint sign-in is on for this phone.');

        case 'pk_delete':
            Security::requirePost();
            $admin = ap_me();
            Database::delete('ap_passkeys', 'id = :i AND admin_id = :a', ['i' => (int) Response::field('id', 0), 'a' => (int) $admin['id']]);
            Response::success(null, 'Removed.');

        case 'logout':
            Security::requirePost();
            Auth::adminLogout();
            Response::success(null);
    }
    Response::notFound('Unknown action.');
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
} catch (Throwable $e) {
    Response::serverError($e);
}
