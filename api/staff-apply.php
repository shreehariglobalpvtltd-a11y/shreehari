<?php
/**
 * /api/staff-apply.php — the staff application form /agreements/staff.html
 * and the staff agreement's sign box (22 Oct 2026).
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/staffapplications.php';
require_once INCLUDE_PATH . '/waverify.php';
require_once INCLUDE_PATH . '/applicationimport.php';

const STAFF_OTP_OK_SEC   = 3600;
const STAFF_OTP_SKIP_SEC = 120;

function staff_phone(): array
{
    $cc = (string) Response::field('cc', '91');
    $digits = normalisePhone((string) Response::field('phone', ''));
    return [$digits, $cc === '977' ? 'NP' : 'IN'];
}

function staff_phone_state(string $phone): string
{
    $ok = (int) ($_SESSION['staff_otp_ok'][$phone] ?? 0);
    if ($ok > time() - STAFF_OTP_OK_SEC || WaVerify::sessionVerified($phone)) {
        return 'verified';
    }
    $waTried = (int) ($_SESSION['wa_tried'][$phone] ?? 0);
    if ($waTried > 0 && $waTried < time() - STAFF_OTP_SKIP_SEC) {
        return 'skip';
    }
    $try = $_SESSION['staff_otp_try'][$phone] ?? null;
    if (is_array($try) && (empty($try['sent']) || (int) $try['t'] < time() - STAFF_OTP_SKIP_SEC)) {
        return 'skip';
    }
    return 'none';
}

function staff_origin(): string
{
    $u = parse_url(APP_URL);
    return ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
}

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        Security::requireRateLimit('staff_token', Security::clientIp(), 60, 300);
        $wa = preg_replace('/\D/', '', Settings::getString('company_whatsapp', '')) ?? '';
        Response::json([
            'ok'               => true,
            'csrf'             => Security::csrfToken(),
            'open'             => StaffApps::available() && StaffApps::applyOpen(),
            'docVerify'        => StaffApps::docVerifyOn(),
            'biometric'        => StaffApps::biometricOn(),
            'agreementVersion' => StaffApps::AGREEMENT_VERSION,
            'officePhone'      => Settings::officePhone(),
            'officeWa'         => strlen($wa) >= 10 ? $wa : '',
        ]);
    }
    if ($method !== 'POST') {
        Response::error('This endpoint takes GET or POST.', 405);
    }

    Security::requireCsrf();
    $ip = Security::clientIp();
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!Security::rateLimit('staff_apply_ip', $ip, 40, 3600)) {
        Response::error('धेरै पटक प्रयास भयो — केही बेरपछि फेरि प्रयास गर्नुहोस् वा फोन गर्नुहोस्: ' . Settings::officePhone(), 429);
    }
    if (!StaffApps::available()) {
        Logger::error('Staff apply: staff_applications table missing');
        Response::error('Applications are not open yet. Please call the office: ' . Settings::officePhone(), 503);
    }

    $isJson = str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');

    if ($isJson) {
        Response::$maxInputBytes = 400_000;
        $action = (string) Response::field('action', '');

        if ($action === 'otp') {
            [$phone, $country] = staff_phone();
            if (strlen($phone) !== 10 || !Security::isValidPhone($phone) || isPlaceholderPhone($phone)) {
                Response::invalid(['phone' => 'number'], '१० अङ्कको मोबाइल नम्बर लेख्नुहोस् · Enter a 10-digit mobile number.');
            }
            Security::requireRateLimit('staff_otp_ip', $ip, 8, 600);
            $capH = max(1, Settings::getInt('otp_global_hourly_cap', 150));
            $capD = max(1, Settings::getInt('otp_global_daily_cap', 800));
            $sent = false;
            if (Security::rateLimit('otp_send_global_h', 'global', $capH, 3600) && Security::rateLimit('otp_send_global_d', 'global', $capD, 86400)) {
                $res = Auth::issueOtp($phone, 'verify', 'whatsapp');
                if (!$res['ok']) {
                    Response::error((string) ($res['error'] ?? 'Could not send a code.'), 429);
                }
                $code = (string) $res['code'];
                try {
                    if (!class_exists('AiSensyAPI') && is_file(ROOT_PATH . '/whatsapp/aisensy.php')) {
                        require_once ROOT_PATH . '/whatsapp/aisensy.php';
                    }
                    if (class_exists('AiSensyAPI') && AiSensyAPI::enabled()) {
                        $r = AiSensyAPI::sendLoginOtp(countryDialCode($country) . $phone, $code, '');
                        $sent = (bool) $r['success'];
                    } else {
                        $text = Settings::getString('company_name', APP_NAME) . ' को कोड: ' . $code . ' (' . OTP_EXPIRY_MINUTES
                              . ' मिनेट सम्म मान्य)। यो कोड कसैलाई नबताउनुहोस्।';
                        $sent = Notify::whatsapp($phone, $text, null, $country, [], null, ['purpose' => 'otp']) === true;
                    }
                } catch (Throwable $e) {
                    $sent = false;
                }
                $_SESSION['staff_otp_try'][$phone] = ['t' => time(), 'sent' => $sent];
                Logger::info('Staff apply: code ' . ($sent ? 'sent' : 'NOT delivered'), ['phone' => maskPhone($phone)], 'otp');
                $out = ['ok' => true, 'sent' => $sent, 'resendIn' => OTP_RESEND_SECONDS, 'skipAfter' => $sent ? STAFF_OTP_SKIP_SEC : 0];
                if (APP_DEBUG) {
                    $out['debugCode'] = $code;
                }
                Response::json($out);
            }
            Logger::warning('Staff apply: code NOT sent, global OTP ceiling reached', ['phone' => maskPhone($phone)], 'otp');
            $_SESSION['staff_otp_try'][$phone] = ['t' => time(), 'sent' => false];
            Response::json(['ok' => true, 'sent' => false, 'resendIn' => OTP_RESEND_SECONDS, 'skipAfter' => 0]);
        }

        if ($action === 'verify') {
            [$phone] = staff_phone();
            $check = Auth::verifyOtp($phone, (string) Response::field('code', ''), 'verify');
            if (!$check['ok']) {
                Response::error((string) ($check['error'] ?? 'That code is incorrect.'), 401, ['code' => 'wrong']);
            }
            $_SESSION['staff_otp_ok'][$phone] = time();
            Response::json(['ok' => true, 'verified' => true]);
        }

        if ($action === 'challenge') {
            if (!StaffApps::biometricOn()) {
                Response::error('Not switched on.', 404);
            }
            $ch = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $_SESSION['staff_webauthn'] = ['c' => $ch, 't' => time()];
            Response::json(['ok' => true, 'challenge' => $ch, 'rpId' => (string) parse_url(APP_URL, PHP_URL_HOST),
                            'rpName' => Settings::getString('company_name', 'S Hari Global')]);
        }

        if ($action === 'sign') {
            Security::requireRateLimit('staff_sign_ip', $ip, 10, 3600);
            [$phone] = staff_phone();
            $name  = StaffApps::oneLine(Response::field('name', ''), 120);
            $receipt = (string)($_POST['import_receipt'] ?? '');
    ApplicationImport::formReceipt($receipt, 'staff');
    if ($edit === null || isset($files['id_front'])) {
        ApplicationImport::requireId($files['id_front']['bytes'] ?? null, ($_POST['import_reviewed'] ?? '') === '1', 'staff');
    } elseif (($_POST['import_reviewed'] ?? '') !== '1') {
        Response::invalid(['import_reviewed' => 'review'], 'Review the application details before sending.');
    }
    $state = staff_phone_state($phone);
            if ($state === 'none') {
                Response::invalid(['otp' => 'required'], 'पहिले मोबाइल नम्बर WhatsApp कोडले प्रमाणित गर्नुहोस् · Verify your mobile with the WhatsApp code first.');
            }
            $app = StaffApps::findByPhone($phone);
            if ($app === null || !in_array((string) $app['status'], ['pending', 'under_review', 'approved'], true)) {
                Response::invalid(['phone' => 'unknown'], 'यो नम्बरबाट कुनै आवेदन भेटिएन — पहिले अनलाइन फारम भर्नुहोस् · No application with this number. Please apply first.');
            }
            if (!DocVerify::nameMatches($name, (string) $app['full_name'])) {
                Response::invalid(['name' => 'name'], 'नाम आवेदनमा जस्तै लेख्नुहोस् · Write your name as on your application.');
            }
            $sig = Response::field('signature', '');
            $bin = is_string($sig) && preg_match('~^data:image/png;base64,([A-Za-z0-9+/=]+)$~', $sig, $m) === 1 ? base64_decode($m[1], true) : false;
            if ($bin === false || $bin === '' || strlen($bin) > StaffApps::SIG_MAX || (new finfo(FILEINFO_MIME_TYPE))->buffer($bin) !== 'image/png') {
                Response::invalid(['sig' => 'required'], 'औंलाले सही गर्नुहोस् · Please sign with your finger.');
            }
            $rel = StaffApps::saveFile((int) $app['id'], 'signature', $bin, 'image/png');
            $event = (string) $app['status'] === 'approved' ? 'approval' : 'confirm';
            StaffApps::recordSign($event, (int) $app['id'], $name, $phone, $state === 'verified' ? 1 : 0, $rel, $ip, $ua);
            Logger::audit('staff_app.signed', 'staff_application', StaffApps::refFor((int) $app['id']), null,
                ['event' => $event, 'version' => StaffApps::AGREEMENT_VERSION, 'phone_verified' => $state === 'verified'], 'Staff agreement signed online');
            Response::json(['ok' => true, 'ref' => StaffApps::refFor((int) $app['id']), 'version' => StaffApps::AGREEMENT_VERSION,
                            'signedAt' => date('d M Y, H:i'), 'verified' => $state === 'verified']);
        }

        Response::error('Unknown action.', 400);
    }

    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > 0 && $_POST === [] && $_FILES === []) {
        Response::error('फाइलहरू धेरै ठूला भए — PDF को सट्टा फोटो पठाउनुहोस् · The files are too large together.', 413);
    }
    if (!StaffApps::applyOpen()) {
        Response::error('अहिले अनलाइन आवेदन बन्द छ — कार्यालयमा फोन गर्नुहोस्: ' . Settings::officePhone(), 403);
    }

    require_once INCLUDE_PATH . '/applyedit.php';
    $edit = !empty($_POST['edit']) ? ApplyEdit::current('staff', normalisePhone((string) ($_POST['phone'] ?? ''))) : null;
    if (!empty($_POST['edit']) && $edit === null) {
        Response::error('सच्याउने समय सकियो — "पठाएको फारम सच्याउनुहोस्" फेरि खोल्नुहोस्', 409);
    }
    $have = [];
    if ($edit !== null) {
        $cur = StaffApps::find($edit['id']) ?? [];
        foreach (StaffApps::FILES as $slot => [$col]) {
            if ((string) ($cur[$col] ?? '') !== '') {
                $have[] = $slot;
            }
        }
    }
    $check = StaffApps::validate($_POST, $_FILES, $have);
    if (!$check['ok']) {
        Response::invalid($check['errors'], 'रातो भएको ठाउँ ठीक गर्नुहोस् · Please fix the boxes marked in red.');
    }
    $c     = $check['clean'];
    $files = $check['files'];

    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        Logger::info('Staff apply: honeypot filled', ['ip' => $ip]);
        Response::json(['ok' => true, 'staff_id' => 0, 'ref' => 'STF-' . str_pad((string) random_int(900000, 999999), 6, '0', STR_PAD_LEFT),
                        'status' => 'pending', 'message' => 'धन्यवाद! कार्यालयले छिट्टै फोन गर्नेछ।']);
    }

    $state = staff_phone_state((string) $c['phone']);
    if ($state === 'none') {
        Response::invalid(['otp' => 'required'], 'मोबाइल नम्बर WhatsApp कोडले प्रमाणित गर्नुहोस् · Please verify your mobile with the WhatsApp code.');
    }
    $c['phone_verified'] = $state === 'verified' ? 1 : 0;

    if (StaffApps::docVerifyOn() && ($edit === null || isset($files['id_front']) || isset($files['license']))) {
        $uris = [];
        foreach (['id' => 'id_front', 'license' => 'license'] as $doc => $slot) {
            $uris[$doc] = isset($files[$slot]) ? StaffApps::dataUri($files[$slot]['bytes'], $files[$slot]['mime']) : null;
        }
        $v = StaffApps::verifyDocs($c, $uris, true);
        $c['doc_verify_json']   = $v['json'];
        $c['doc_verify_status'] = $v['status'];
    }

    if (StaffApps::biometricOn()) {
        $face = StaffApps::readUpload($_FILES['face'] ?? null, 'face');
        if (is_array($face) && isset($face['bytes'])) {
            $files['face'] = $face;
            $c['biometric_face_hash'] = hash('sha256', $face['bytes']);
        }
    }
    foreach (['staff_webauthn', 'agr_webauthn'] as $chKey) {
        $chal = $_SESSION[$chKey] ?? null;
        if (!isset($c['biometric_webauthn_cred']) && is_array($chal) && (int) ($chal['t'] ?? 0) > time() - 3600) {
            $cred = StaffApps::webauthnCred($_POST['webauthn'] ?? null, (string) $chal['c'], staff_origin());
            if ($cred !== null) {
                $c['biometric_webauthn_cred'] = $cred;
                $usedChKey = $chKey;
            }
        }
    }

    $busy = 'धेरै आवेदन आयो — केही बेरपछि फेरि पठाउनुहोस् वा फोन गर्नुहोस्: ' . Settings::officePhone();
    if (!Auth::isAdmin() && !Security::rateLimit('staff_apply_ok_ip', $ip, 5, 3600)) {
        Response::error($busy, 429);
    }
    if (!Security::rateLimit('staff_apply_all_h', 'global', 30, 3600) || !Security::rateLimit('staff_apply_all_d', 'global', 150, 86400)) {
        Logger::warning('Staff apply: the site-wide budget is used up', ['ip' => $ip]);
        Response::error($busy, 429);
    }

    if ($edit !== null) {
        try {
            $made = Database::transaction(static function () use ($edit, $c, $files, $ip, $ua, $receipt): array {
                $made = StaffApps::applicantEdit($edit['id'], $c, $files, $ip, $ua);
                ApplicationImport::attachForm($receipt, 'staff', 'staff:' . $made['id']);
                return $made;
            });
        } catch (StaffAppError $e) {
            Response::error($e->getMessage(), 409);
        }
        ApplyEdit::close();
        require_once INCLUDE_PATH . '/facecheck.php';
        FaceCheck::attach('staff_applications', (int) $made['id']);
        $_SESSION['a4_mine'] = ['type' => 'staff', 'id' => (int) $made['id'], 't' => time()];
        Response::json(['ok' => true, 'staff_id' => $made['id'], 'ref' => $made['ref'], 'status' => 'pending', 'edited' => true,
                        'message' => 'तपाईंको आवेदन ' . $made['ref'] . ' सच्याइयो।']);
    }

    try {
        $made = Database::transaction(static function () use ($c, $files, $ip, $ua, $receipt): array {
            $made = StaffApps::create($c, $files, $ip, $ua);
            ApplicationImport::attachForm($receipt, 'staff', 'staff:' . $made['id']);
            return $made;
        });
        require_once INCLUDE_PATH . '/facecheck.php';
        FaceCheck::attach('staff_applications', (int) $made['id']);
        if (isset($usedChKey)) {
            unset($_SESSION[$usedChKey]);
        }
        $_SESSION['a4_mine'] = ['type' => 'staff', 'id' => (int) $made['id'], 't' => time()];
    } catch (StaffAppError $e) {
        Response::error($e->getMessage(), 409);
    }

    if (Security::rateLimit('staff_note', 'global', 10, 3600)) {
        try {
            Notify::adminNote('New staff application', [
                'Ref'      => $made['ref'] . ($made['reapplied'] ? ' (applied again)' : ''),
                'Job'      => StaffApps::roleLabel((string) $c['role_applied']),
                'Name'     => mb_substr((string) $c['full_name'], 0, 60),
                'Phone'    => '+' . countryDialCode((string) $c['phone_country']) . ' ' . $c['phone'] . ($c['phone_verified'] ? ' ✓' : ' (not verified)'),
                'District' => mb_substr((string) $c['district'], 0, 60),
                'Review'   => appUrl('admin/team.php?tab=approvals&ref=' . $made['ref']),
            ]);
        } catch (Throwable $e) {
            Logger::warning('Staff apply: office note not sent: ' . $e->getMessage());
        }
    }

    Response::json([
        'ok'       => true,
        'staff_id' => $made['id'],
        'ref'      => $made['ref'],
        'status'   => 'pending',
        'message'  => 'तपाईंको आवेदन ' . $made['ref'] . ' कार्यालयमा पुग्यो। कागजात हेरेर कार्यालयले फोन / WhatsApp गर्नेछ। · Your application reached the office.',
    ]);
} catch (ImportReceiptError $e) {
    Response::invalid(['import_receipt' => 'expired'], $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e);
}
