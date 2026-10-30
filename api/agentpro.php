<?php
/**
 * =====================================================================
 *  api/agentpro.php — JSON for the join form (/join.php) and the member
 *  portal (/me/). One file, ?a=<action>.
 *
 *  JOIN (no sign-in; a draft is identified by its 32-hex draft key, kept
 *  in the applicant's browser — and in the "continue later" link
 *  /join.php?d=<key>, so it opens on another phone too):
 *    start · get · save (lenient=1: skip a bad field, say which) · otp_send
 *    otp_verify · reverse · reverse_status
 *    upload (front|back|selfie|thumb|extra, + qr text) · read_status
 *    scan_form (the hand-filled paper form: pages[] JPEG data URIs → ApFormRead)
 *    drop_extra · submit
 *  OFFICE (office=1 + an office sign-in, AgentPro::officeAllowed; join.php?office=1):
 *    the same actions on any draft or submitted form, plus
 *    office_role · office_verify (skip WhatsApp with a reason) · phone_check
 *    submit approve_now=1 saw_id=1 → approved at once, code issued
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
/** The paper form: up to 4 page JPEGs as data URIs (the page shrinks them to ~1600 px first). */
const AP_SCAN_BODY_MAX = 9_000_000;
if ($a === 'scan_form') {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > AP_SCAN_BODY_MAX) {
        Response::error('Those pages are too large — send fewer or smaller photos.', 413);
    }
    Response::$maxInputBytes = AP_SCAN_BODY_MAX;
} else {
    Response::$maxInputBytes = 200_000;   // every other JOIN / ME body is a few fields
}
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
function ap_draft_view(array $d, ?array $office = null): array
{
    $all = jsonColumn($d['extras'] ?? null);
    $extras = [];
    $paper = 0;
    foreach (array_values($all) as $i => $x) {
        $isPaper = is_array($x) && !empty($x['paper']);
        $paper += $isPaper ? 1 : 0;
        $extras[] = ['i' => $i, 'label' => (string) ($x['label'] ?? ''), 'paper' => $isPaper];
    }
    $out = [];
    foreach (['ref', 'status', 'kind', 'agent_kind', 'role_wanted', 'designation', 'full_name', 'org_name', 'phone', 'whatsapp',
              'email', 'dob', 'gender', 'guardian', 'address', 'city', 'district', 'state', 'country', 'pincode', 'id_type',
              'id_number_masked', 'id_expires_on', 'bank_holder', 'bank_name', 'bank_account_last4', 'bank_ifsc', 'bank_branch',
              'upi_id', 'esewa_id', 'khalti_id', 'occupation', 'education', 'experience', 'skills', 'languages', 'pan'] as $k) {
        $out[$k] = $d[$k] ?? null;
    }
    $out['verified'] = !empty($d['wa_verified_at']);
    $out['verifiedVia'] = $d['wa_verified_via'] ?? null;
    $out['has'] = ['front' => !empty($d['doc_front']), 'back' => !empty($d['doc_back']), 'selfie' => !empty($d['selfie']),
                   'thumb' => !empty($d['thumb']), 'paper' => $paper];
    $out['extras'] = $extras;
    $out['submittedAt'] = $d['submitted_at'] ?? null;
    if ($office !== null) {   // the office sees whose account it became
        $out['adminId'] = !empty($d['admin_id']) ? (int) $d['admin_id'] : null;
        $out['memberUrl'] = !empty($d['admin_id']) ? '/admin/team-member.php?id=' . (int) $d['admin_id'] : null;
        $out['officeFilled'] = !empty($d['office_filled_by']);
    }
    return $out;
}

/**
 * The office user when the request says office=1 (join.php?office=1), else
 * null. A request that claims office mode without the right sign-in is refused.
 */
function ap_office(): ?array
{
    if ((string) Response::field('office', '') !== '1') {
        return null;
    }
    $admin = Auth::admin();
    if (!AgentPro::officeAllowed($admin)) {
        Response::forbidden('Office mode needs an office sign-in (manager or staff permission).');
    }
    return $admin;
}

/** "This mobile already has an account" with the member page, for the office. */
function ap_phone_taken(?array $owner): void
{
    if ($owner === null) {
        return;
    }
    Response::error(((int) $owner['is_active'] === 1 ? 'This mobile already belongs to ' : 'An application from this mobile is already with the office: ')
        . (string) $owner['full_name'] . ' (#' . (int) $owner['id'] . ').', 409,
        ['admin_id' => (string) (int) $owner['id'], 'member_url' => '/admin/team-member.php?id=' . (int) $owner['id']]);
}

/** The ap_applications columns a paper-form read may fill (the K2 contract). */
const AP_SCAN_FIELDS = ['full_name', 'org_name', 'dob', 'gender', 'guardian', 'phone', 'email', 'address', 'city', 'district', 'state',
    'pincode', 'country', 'id_type', 'id_number', 'id_expires_on', 'bank_holder', 'bank_name', 'bank_account', 'bank_ifsc', 'bank_branch',
    'upi_id', 'esewa_id', 'khalti_id', 'occupation', 'education', 'experience', 'skills', 'languages', 'pan'];

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
            $off = ap_office();
            if ($off !== null) {
                Security::requireRateLimit('apjoin_office_start', (string) $off['id'], 300, 3600);
                $d = AgentPro::newDraft('', (int) $off['id']);
            } else {
                Security::requireRateLimit('apjoin_start', Security::clientIp(), 12, 3600);
                $d = AgentPro::newDraft((string) Response::field('invite', ''));
            }
            Response::success(['key' => $d['draft_key'], 'draft' => ap_draft_view($d, $off)]);

        case 'get':
            $off = ap_office();
            $d = ap_draft();
            Response::success(['draft' => ap_draft_view($d, $off)]);

        case 'save':
            Security::requirePost();
            $off = ap_office();
            $d = ap_draft();
            $in = Response::input();
            unset($in['key'], $in['office'], $in['lenient']);
            if ((string) Response::field('lenient', '') === '1') {
                // Values the paper-form reader filled: keep every good one, report the rest
                // (one wrong date must not lose the other twenty boxes).
                $skipped = [];
                if (array_key_exists('id_type', $in)) {   // the ID number is checked against its type: type first
                    $in = ['id_type' => $in['id_type']] + $in;
                }
                foreach ($in as $k => $v) {
                    try {
                        AgentPro::saveDraft((string) $d['draft_key'], [(string) $k => $v], $off !== null);
                    } catch (RuntimeException $e) {
                        $skipped[(string) $k] = $e->getMessage();
                    }
                }
                Response::success(['draft' => ap_draft_view(AgentPro::draft((string) $d['draft_key']) ?? $d, $off), 'skipped' => (object) $skipped]);
            }
            $d = AgentPro::saveDraft((string) $d['draft_key'], $in, $off !== null);
            Response::success(['draft' => ap_draft_view($d, $off)]);

        case 'otp_send':
            Security::requirePost();
            $off = ap_office();
            $d = ap_draft();
            if ($off !== null) {
                ap_phone_taken(AgentPro::phoneOwner((string) Response::field('phone', ''), (int) ($d['admin_id'] ?? 0)));
            }
            $r = AgentPro::sendJoinOtp((string) $d['draft_key'], (string) Response::field('phone', ''), (string) Response::field('country', ''));
            Response::success($r, 'Code sent on WhatsApp.');

        case 'otp_verify':
            Security::requirePost();
            $off = ap_office();
            $d = ap_draft();
            $r = AgentPro::verifyJoinOtp((string) $d['draft_key'], (string) Response::field('code', ''));
            Response::success($r + ['draft' => ap_draft_view(AgentPro::draft((string) $d['draft_key']) ?? $d, $off)], 'Verified.');

        /* ---- office only ---- */

        case 'phone_check':
            $off = ap_office() ?? Response::forbidden('Office only.');
            $owner = AgentPro::phoneOwner((string) Response::field('phone', ''), (int) (AgentPro::draft((string) Response::field('key', ''))['admin_id'] ?? 0));
            Response::success(['free' => $owner === null, 'owner' => $owner === null ? null : [
                'id' => (int) $owner['id'], 'name' => (string) $owner['full_name'], 'role' => (string) $owner['role'],
                'active' => (int) $owner['is_active'] === 1, 'member_url' => '/admin/team-member.php?id=' . (int) $owner['id'],
            ]]);

        case 'office_role':
            Security::requirePost();
            $off = ap_office() ?? Response::forbidden('Office only.');
            $d = ap_draft();
            $d = AgentPro::officeSetRole((string) $d['draft_key'], (string) Response::field('role', 'agent'),
                (string) Response::field('designation', ''), (string) Response::field('agent_kind', 'person'));
            Response::success(['draft' => ap_draft_view($d, $off)]);

        case 'office_verify':
            Security::requirePost();
            $off = ap_office() ?? Response::forbidden('Office only.');
            $d = ap_draft();
            $phone = (string) Response::field('phone', '') !== '' ? (string) Response::field('phone', '') : (string) ($d['phone'] ?? '');
            ap_phone_taken(AgentPro::phoneOwner($phone, (int) ($d['admin_id'] ?? 0)));
            $d = AgentPro::officeVerify((string) $d['draft_key'], (int) $off['id'], (string) Response::field('reason', ''),
                $phone, (string) Response::field('country', ''));
            Response::success(['draft' => ap_draft_view($d, $off)], 'Mobile accepted by the office.');

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
            $off = ap_office();
            $d = ap_draft();
            Security::requireRateLimit('apjoin_upload', (string) $d['draft_key'], 40, 3600);
            $slot = (string) Response::field('slot', '');
            if (empty($_FILES['file'])) {
                Response::error('Choose a photo first.', 422);
            }
            $saved = AgentPro::storeDraftDoc((string) $d['draft_key'], $slot, $_FILES['file'], (string) Response::field('label', ''), $off !== null);
            $read = null;
            $queued = false;
            if (in_array($slot, ['front', 'back'], true)) {
                $type = (string) (Response::field('id_type', '') ?: ($d['id_type'] ?? ''));
                if ($type !== '' && $type !== (string) ($d['id_type'] ?? '') && isset(AgentPro::ID_TYPES[$type])) {
                    AgentPro::saveDraft((string) $d['draft_key'], ['id_type' => $type], $off !== null);   // the photo says which ID it is
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
                'draft' => ap_draft_view(AgentPro::draft((string) $d['draft_key']) ?? $d, $off)]);

        case 'scan_form':
            // K2: the hand-filled paper form. Pages are stored with the draft first
            // (the office sees them whatever the reader manages), then read.
            Security::requirePost();
            $off = ap_office();
            $d = ap_draft();
            Security::requireRateLimit('apjoin_scan', (string) $d['draft_key'], 8, 3600);
            if ($off === null) {
                Security::requireRateLimit('apjoin_scan_ip', Security::clientIp(), 20, 3600);
            }
            $raw = Response::field('pages', []);
            $pages = AgentPro::storePaperPages((string) $d['draft_key'], is_array($raw) ? $raw : [], $off !== null);
            unset($raw);
            $kind = ($d['kind'] ?? '') === 'staff' ? 'staff' : 'agent';
            $r = ['status' => 'unavailable', 'fields' => [], 'confidence' => [], 'reader' => '',
                  'note' => 'The pages are attached for the office — the form reader is not switched on yet, please fill the boxes.'];
            if (is_file(INCLUDE_PATH . '/agentpro-formread.php')) {
                require_once INCLUDE_PATH . '/agentpro-formread.php';
                if (class_exists('ApFormRead') && method_exists('ApFormRead', 'read')) {
                    // a model on the VPS may take minutes; the session must not block this browser's other calls
                    session_write_close();
                    @set_time_limit(330);
                    try {
                        $got = ApFormRead::read(array_column($pages, 'abs'), $kind);
                        if (is_array($got)) {
                            $r = array_merge($r, $got);
                        }
                    } catch (Throwable $e) {
                        Logger::warning('AgentPro paper form read failed: ' . $e->getMessage(), [], 'agent');
                        $r['note'] = 'The reader could not read it this time — the pages are attached; please fill the boxes.';
                    }
                }
            }
            $fields = [];
            $conf = [];
            foreach ((array) ($r['fields'] ?? []) as $k => $v) {
                $k = (string) $k;
                if (!in_array($k, AP_SCAN_FIELDS, true) || !is_scalar($v) || trim((string) $v) === '') {
                    continue;
                }
                $fields[$k] = mb_substr(trim((string) $v), 0, 255);
                $c = (string) (($r['confidence'] ?? [])[$k] ?? 'low');
                $conf[$k] = in_array($c, ['high', 'medium', 'low'], true) ? $c : 'low';
            }
            if (isset($fields['id_type']) && !isset(AgentPro::ID_TYPES[$fields['id_type']])) {
                unset($fields['id_type'], $conf['id_type']);
            }
            $status = in_array((string) $r['status'], ['read', 'partial', 'notform', 'unavailable'], true) ? (string) $r['status'] : 'partial';
            $out = ['status' => $status, 'fields' => (object) $fields, 'confidence' => (object) $conf,
                    'reader' => in_array((string) $r['reader'], ['vps', 'gemini', 'tesseract'], true) ? (string) $r['reader'] : '',
                    'note' => mb_substr((string) ($r['note'] ?? ''), 0, 300), 'pages' => count($pages)];
            AgentPro::noteFormRead((string) $d['draft_key'], ['fields' => $fields, 'confidence' => $conf] + $out);
            Logger::info('AgentPro paper form', ['ref' => $d['ref'], 'status' => $status, 'reader' => $out['reader'],
                'filled' => array_keys($fields), 'pages' => count($pages), 'office' => $off['id'] ?? 0]);
            Response::json(['ok' => true] + $out + ['data' => $out + ['draft' => ap_draft_view(AgentPro::draft((string) $d['draft_key']) ?? $d, $off)]]);

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
            $off = ap_office();
            $d = ap_draft();
            AgentPro::dropDraftExtra((string) $d['draft_key'], (int) Response::field('i', -1), $off !== null);
            Response::success(['draft' => ap_draft_view(AgentPro::draft((string) $d['draft_key']) ?? $d, $off)]);

        case 'submit':
            Security::requirePost();
            $off = ap_office();
            $d = ap_draft();
            if (Response::field('consent', '') !== '1' && Response::field('consent', false) !== true) {
                Response::invalid(['consent' => 'Please tick the consent box.']);
            }
            if ($off === null) {
                $app = AgentPro::submit((string) $d['draft_key']);
                Response::success(['ref' => $app['ref'], 'status' => $app['status'],
                    'form_url' => '/join-form.php?k=' . $d['draft_key']], 'Sent to the office.');
            }
            // K1: the office filled it — send, and (approve_now=1) approve at once: login + agent code / staff no.
            if ($d['status'] === 'draft') {
                ap_phone_taken(AgentPro::phoneOwner((string) ($d['phone'] ?? ''), 0));
            }
            $app = AgentPro::submit((string) $d['draft_key'], true, (int) $off['id']);
            $adminId = (int) ($app['admin_id'] ?? 0);
            $out = ['ref' => (string) $app['ref'], 'status' => (string) $app['status'], 'admin_id' => $adminId, 'code' => '',
                    'member_url' => $adminId > 0 ? '/admin/team-member.php?id=' . $adminId : null,
                    'form_url' => '/admin/team-form.php?app=' . (int) $app['id']];
            if ((string) Response::field('approve_now', '') === '1' && $app['status'] !== 'rejected') {
                $sawId = in_array(Response::field('saw_id', ''), ['1', 1, true], true);
                $res = AgentPro::approve((int) $app['id'], (int) $off['id'], $sawId);
                $m = AgentPro::member((int) $res['admin_id']);
                $out['status'] = 'approved';
                $out['admin_id'] = (int) $res['admin_id'];
                $out['code'] = (string) (($res['code'] ?? '') !== '' ? $res['code'] : ($m['idNo'] ?? ''));
                $out['member_url'] = '/admin/team-member.php?id=' . (int) $res['admin_id'];
                $out['kyc'] = $sawId ? 'verified' : 'submitted';
                // a staff member's first password goes to them on WhatsApp (sendWelcome) — never back to this screen
            }
            Response::success($out, $out['status'] === 'approved' ? 'Approved — ID issued.' : 'Sent to approvals.');

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
