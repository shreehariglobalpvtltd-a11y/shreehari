<?php
/**
 * =====================================================================
 *  includes/codelogin.php — the code-first staff door (15 Nov 2026).
 *
 *  Owner: "PIN / pattern / password options haraye. Code pahila → PIN /
 *  pattern → aafno dashboard. Pahilo patak code + DOB + mobile, tara OTP
 *  ya admin le activate gare pachhi matra PIN rakhne; PIN bina dashboard
 *  nakhulos; birse ma 'Forgot PIN / Pattern?'."
 *
 *    c_code     Agent / Staff / Admin code (SHG-0008, SHG-S-0003, username)
 *    c_unlock   PIN keypad or pattern (an unknown code sees the same screen)
 *    c_setup    first time: registered DOB + registered mobile
 *    c_forgot   forgot PIN / pattern: the same check
 *    c_prove    prove the REGISTERED WhatsApp ("hi" + YES, or a 6-digit
 *               code) — or an activation code the office issued
 *    c_create   choose a 6-digit PIN or a pattern
 *    c_confirm  the same again
 *
 *  Rules kept here, so tests can drive every path from the CLI
 *  (tests/code-login-test.php):
 *    - the account is the one the CODE names, looked up on the server and kept
 *      in this session ($_SESSION['code_login']['aid']); no form ever carries
 *      an account id or a role;
 *    - DOB + mobile are only COMPARED with the record (ap_members.dob,
 *      admins.phone), never written; a match alone sets nothing — the
 *      registered WhatsApp, or an office activation code, must prove it;
 *    - the answer to a DOB / mobile check is the same whether it matched or
 *      not (no personal detail is confirmed to whoever is typing);
 *    - PIN / pattern are bcrypt (QuickUnlock::set), never stored in the
 *      browser and never logged; the first entry waits for its confirm as a
 *      hash in the server session only;
 *    - every session opens through Auth::openAfterQuick (switched-off / locked
 *      accounts refused, office 2FA still asks its WhatsApp code).
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/quickunlock.php';
require_once __DIR__ . '/teamportal.php';
require_once __DIR__ . '/waverify.php';

final class CodeLogin
{
    public const KEY          = 'code_login';
    public const TTL          = 1800;   // a half-done door lives 30 minutes
    public const PROVEN_TTL   = 900;    // after the proof, 15 minutes to choose the PIN
    public const ACT_TTL_H    = 24;     // an office activation code lives 24 hours
    public const ACT_TRIES    = 5;
    public const PIN_LEN      = 6;
    public const STEPS = ['c_code', 'c_unlock', 'c_setup', 'c_forgot', 'c_prove', 'c_create', 'c_confirm'];

    /** Tests switch the ~1 s even-timing pad off and catch the WhatsApp code here. */
    public static bool $evenTiming = true;
    /** @var callable|null fn(string $intl, string $code): bool */
    public static $deliverHook = null;

    private function __construct() {}

    /* =================================================================
     *  Switches
     * ================================================================= */

    /** The code-first door (setting login_code_first_on, default ON). */
    public static function on(): bool
    {
        return QuickUnlock::on() && Settings::getBool('login_code_first_on', true);
    }

    /** No dashboard without a PIN / pattern (setting login_pin_required, default ON). */
    public static function required(): bool
    {
        return self::on() && Settings::getBool('login_pin_required', true);
    }

    private static ?bool $ready = null;

    /** The 15 Nov 2026 migration ran (recovery requests table). */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_recovery_requests'",
                    [], 0) > 0;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /* =================================================================
     *  The half-done door, in this browser session only
     * ================================================================= */

    /** @return array<string, mixed>|null */
    public static function state(): ?array
    {
        $s = $_SESSION[self::KEY] ?? null;
        if (!is_array($s) || (int) ($s['t'] ?? 0) < time() - self::TTL) {
            unset($_SESSION[self::KEY]);
            return null;
        }
        return $s;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }

    /** The code as shown on screen ("SHG-0008"), never a name, mobile or DOB. */
    public static function shownCode(): string
    {
        return (string) (self::state()['code'] ?? '');
    }

    /* =================================================================
     *  Codes
     * ================================================================= */

    /** The account's printed code: SHG-0008 (agent), SHG-S-0003 (staff), else its username. */
    public static function codeFor(int $adminId): string
    {
        try {
            require_once __DIR__ . '/agentwallet.php';
            $c = AgentWallet::agentCodeLabel($adminId);
            if ($c !== '') {
                return $c;
            }
        } catch (Throwable $e) {
        }
        try {
            $n = (int) Database::scalar('SELECT staff_no FROM ap_members WHERE admin_id = :a', ['a' => $adminId], 0);
            if ($n > 0) {
                return 'SHG-S-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            }
        } catch (Throwable $e) {
        }
        return (string) Database::scalar('SELECT username FROM admins WHERE id = :i', ['i' => $adminId], '');
    }

    /** A typed code -> the admins row it names (active or not), or null. */
    public static function lookup(string $raw): ?array
    {
        $user = Auth::resolveLoginId($raw);
        if ($user === '') {
            return null;
        }
        return Database::fetch('SELECT * FROM admins WHERE username = :u LIMIT 1', ['u' => $user]);
    }

    private static function looksLikeMobile(string $raw): bool
    {
        return preg_match('/^\+?[0-9][0-9\s\-().]{8,20}$/', trim($raw)) === 1 && strlen((string) preg_replace('/\D/', '', $raw)) >= 10;
    }

    /** @return array{pin: bool, pattern: bool} */
    public static function kinds(int $adminId): array
    {
        return $adminId > 0 ? QuickUnlock::status($adminId) : ['pin' => false, 'pattern' => false];
    }

    /* =================================================================
     *  Step 1: the code
     * ================================================================= */

    /** @return array{step: string, error?: string} */
    public static function enter(string $raw): array
    {
        $raw = trim(Security::clean($raw, 60));
        if ($raw === '') {
            return ['step' => 'c_code', 'error' => 'आफ्नो कोड लेख्नुहोस् · Type your code (for example SHG-0008).'];
        }
        if (self::looksLikeMobile($raw)) {
            return ['step' => 'c_code', 'error' => 'मोबाइल होइन, आफ्नो कोड लेख्नुहोस् (ID कार्डमा छ) · Type your code from your ID card (SHG-0008 / SHG-S-0003), not the mobile number.'];
        }
        if (!Security::rateLimit('code_login_enter', Security::clientIp(), 40, 600, 600)) {
            return ['step' => 'c_code', 'error' => 'धेरै पटक भयो — १० मिनेटपछि · Too many tries, wait 10 minutes.'];
        }
        Auth::clearDoorState();
        $a   = self::lookup($raw);
        $aid = $a !== null ? (int) $a['id'] : 0;
        $_SESSION[self::KEY] = [
            'aid'  => $aid,
            'code' => $aid > 0 ? self::codeFor($aid) : strtoupper($raw),
            't'    => time(),
        ];
        if ($aid === 0) {
            Logger::info('Code door: a code that names no account', ['ip' => Security::clientIp()], 'security');
        }
        return ['step' => self::nextAfterCode()];
    }

    /** Remembered phone: start from the account the signed device cookie names (nothing typed). */
    public static function enterRemembered(): array
    {
        $d = QuickUnlock::rememberedDevice();
        if ($d === null) {
            return ['step' => 'c_code'];
        }
        Auth::clearDoorState();
        $_SESSION[self::KEY] = ['aid' => (int) $d['id'], 'code' => self::codeFor((int) $d['id']), 't' => time(), 'dev' => true];
        return ['step' => self::nextAfterCode()];
    }

    /** An active account with no PIN / pattern sets one up; everyone else (unknown codes too) sees the unlock. */
    private static function nextAfterCode(): string
    {
        $s = self::state();
        $aid = (int) ($s['aid'] ?? 0);
        if ($aid > 0) {
            $active = (int) Database::scalar('SELECT is_active FROM admins WHERE id = :i', ['i' => $aid], 0) === 1;
            $k = self::kinds($aid);
            if ($active && !$k['pin'] && !$k['pattern']) {
                $_SESSION[self::KEY]['purpose'] = 'setup';
                return 'c_setup';
            }
        }
        return 'c_unlock';
    }

    /* =================================================================
     *  Step 2: PIN / pattern
     * ================================================================= */

    /** @return array{step?: string, go?: string, error?: string, notice?: string} */
    public static function unlock(string $kind, string $value): array
    {
        $s = self::state();
        if ($s === null) {
            return ['step' => 'c_code', 'error' => 'फेरि कोड लेख्नुहोस् · Please type your code again.'];
        }
        $no = ['step' => 'c_unlock', 'error' => 'कोड वा PIN / pattern मिलेन · Code or PIN / pattern did not match.'];
        $t0 = microtime(true);
        if (!in_array($kind, QuickUnlock::KINDS, true)) {
            return $no;
        }
        $aid = (int) ($s['aid'] ?? 0);
        if (!Security::rateLimit('code_login_try', strtoupper((string) $s['code']), 12, 900, 900)) {
            return ['step' => 'c_unlock', 'error' => 'धेरै पटक गलत भयो — १५ मिनेटपछि फेरि · Too many tries, wait 15 minutes.'];
        }
        if ($aid === 0) {
            Security::rateLimit('quick_unlock', Security::clientIp(), 20, 900, 900);   // an unknown code spends the same IP budget
            self::evenTime($t0);
            return $no;
        }
        $row = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $aid]);
        if ($row === null) {
            self::evenTime($t0);
            return $no;
        }
        if ((int) $row['is_active'] !== 1) {
            // switched off: said only to someone who typed that account's own PIN / pattern
            $col = $kind === 'pin' ? 'pin_hash' : 'pattern_hash';
            $v = $kind === 'pattern' ? (string) (QuickUnlock::canonicalPattern($value) ?? '') : $value;
            $ok = $v !== '' && (string) ($row[$col] ?? '') !== '' && Security::verifyPassword($v, (string) $row[$col]);
            Logger::audit('admin.login.code_blocked', 'admin', (string) $aid, null, null, 'Code door: switched-off account tried to sign in');
            self::evenTime($t0);
            return $ok
                ? ['step' => 'c_unlock', 'error' => 'यो खाता बन्द छ — कार्यालयमा सम्पर्क गर्नुहोस् · This account is switched off. Please call the office.']
                : $no;
        }
        $m = QuickUnlock::match([$aid], $kind, $value);
        if (!$m['ok']) {
            Logger::audit('admin.login.code_fail', 'admin', (string) $aid, null, ['kind' => $kind], 'Code door: wrong ' . $kind);
            self::evenTime($t0);
            $until = QuickUnlock::lockedUntil($aid);
            if ($until > 0) {
                $min = max(1, (int) ceil(($until - time()) / 60));
                return ['step' => 'c_unlock', 'error' => 'धेरै पटक गलत भयो — ' . $min . ' मिनेटपछि फेरि, वा "PIN / Pattern बिर्सनुभयो?" · Too many wrong tries: wait ' . $min . ' minutes, or use "Forgot PIN / Pattern?".'];
            }
            return str_contains((string) ($m['error'] ?? ''), 'Too many') ? ['step' => 'c_unlock', 'error' => (string) $m['error']] : $no;
        }
        return self::open($m['admin'], $kind);
    }

    /** @param array<string, mixed> $admin */
    private static function open(array $admin, string $how): array
    {
        try {
            $r = Auth::openAfterQuick($admin, $how);
        } catch (RuntimeException $e) {
            return ['step' => 'c_unlock', 'error' => $e->getMessage()];
        }
        self::clear();
        if (!empty($r['twofa'])) {
            return ['step' => 'admin_otp', 'notice' => 'तपाईंको WhatsApp मा ६ अङ्कको कोड पठाइयो · A 6-digit code was sent to your registered WhatsApp.'];
        }
        return ['go' => 'home'];
    }

    /* =================================================================
     *  First-time setup / forgot: DOB + registered mobile
     * ================================================================= */

    /** "Forgot PIN / Pattern?" from the unlock screen. */
    public static function forgot(): array
    {
        $s = self::state();
        if ($s === null) {
            return ['step' => 'c_code', 'error' => 'फेरि कोड लेख्नुहोस् · Please type your code again.'];
        }
        $_SESSION[self::KEY]['purpose'] = 'reset';
        unset($_SESSION[self::KEY]['idok'], $_SESSION[self::KEY]['ver'], $_SESSION[self::KEY]['pend'], $_SESSION[self::KEY]['wa']);
        return ['step' => 'c_forgot'];
    }

    /** The DOB on the approved member record, or ''. */
    private static function recordDob(int $aid): string
    {
        try {
            $d = (string) Database::scalar('SELECT dob FROM ap_members WHERE admin_id = :a', ['a' => $aid], '');
        } catch (Throwable $e) {
            $d = '';
        }
        return Security::isValidDate(substr($d, 0, 10)) ? substr($d, 0, 10) : '';
    }

    /**
     * Compare what was typed with the record. The reply is the same whatever the
     * result; the result is kept in this session only and decides whether the
     * WhatsApp proof below is real.
     *
     * @return array{step: string, error?: string, notice?: string}
     */
    public static function identity(string $dob, string $mobile): array
    {
        $s = self::state();
        if ($s === null) {
            return ['step' => 'c_code', 'error' => 'फेरि कोड लेख्नुहोस् · Please type your code again.'];
        }
        $back = ($s['purpose'] ?? 'setup') === 'reset' ? 'c_forgot' : 'c_setup';
        $dob = trim($dob);
        $p10 = TeamPortal::phone10($mobile);
        if (!Security::isValidDate($dob) || $p10 === '') {
            return ['step' => $back, 'error' => 'जन्म मिति र १० अङ्कको मोबाइल नम्बर लेख्नुहोस् · Enter your date of birth and 10-digit mobile number.'];
        }
        $aid = (int) ($s['aid'] ?? 0);
        $ip  = Security::clientIp();
        if (!Security::rateLimit('code_login_id_ip', $ip, 10, 3600, 3600)
            || !Security::rateLimit('code_login_id_acc', $aid > 0 ? 'a' . $aid : 'c' . strtoupper((string) $s['code']), 5, 86400, 86400)) {
            return ['step' => $back, 'error' => 'धेरै पटक प्रयास भयो — भोलि फेरि, वा कार्यालयलाई भन्नुहोस् · Too many tries. Try tomorrow, or ask the office below.'];
        }
        $t0 = microtime(true);
        $match = false;
        $why = 'no_account';
        if ($aid > 0) {
            $row = Database::fetch('SELECT id, phone, is_active FROM admins WHERE id = :i', ['i' => $aid]);
            $recDob = self::recordDob($aid);
            $recP10 = TeamPortal::phone10((string) ($row['phone'] ?? ''));
            if ($row === null || (int) $row['is_active'] !== 1) {
                $why = 'inactive';
            } elseif ($recDob === '' || $recP10 === '') {
                $why = 'no_details';
            } elseif (hash_equals($recDob, $dob) && hash_equals($recP10, $p10)) {
                $match = true;
                $why = 'match';
                $_SESSION[self::KEY]['p10']  = $recP10;
                $_SESSION[self::KEY]['intl'] = TeamPortal::intlFor($recP10, ['id' => $aid, 'phone' => (string) $row['phone']]);
            } else {
                $why = 'no_match';
            }
        }
        $_SESSION[self::KEY]['idok'] = $match;
        $_SESSION[self::KEY]['idwhy'] = $why;
        $_SESSION[self::KEY]['t'] = time();
        unset($_SESSION[self::KEY]['wa'], $_SESSION[self::KEY]['ver']);
        Logger::audit('admin.login.identity_check', 'admin', (string) $aid, null,
            ['purpose' => (string) ($s['purpose'] ?? 'setup'), 'result' => $why], 'Code door: DOB + mobile checked (values not logged)');
        self::evenTime($t0);
        return ['step' => 'c_prove', 'notice' => 'अब दर्ता गरिएको WhatsApp बाट पुष्टि गर्नुहोस् · Now confirm from your REGISTERED WhatsApp number.'];
    }

    /* =================================================================
     *  The proof: registered WhatsApp ("hi" + YES, or a code), or the office
     * ================================================================= */

    /** Start "send hi" — a real token only when the details matched; otherwise one that proves nothing. */
    public static function hiStart(): array
    {
        $s = self::state();
        if ($s === null || !isset($s['idok'])) {
            return ['step' => 'c_code', 'error' => 'फेरि सुरु गर्नुहोस् · Please start again.'];
        }
        $ip = Security::clientIp();
        if (!Security::rateLimit('wa_verify_ip', $ip, 8, 600)) {
            return ['step' => 'c_prove', 'error' => 'धेरै पटक भयो — १० मिनेटपछि फेरि · Too many tries, wait 10 minutes.'];
        }
        if (!empty($s['idok'])) {
            try {
                $w = WaVerify::start('reset', (string) $s['p10'], '', $ip, (string) $s['intl']);
                $_SESSION[self::KEY]['wa'] = ['token' => $w['token'], 'url' => $w['url'], 'text' => $w['text']];
                return ['step' => 'c_prove'];
            } catch (Throwable $e) {
                Logger::warning('Code door: hi proof could not start: ' . $e->getMessage(), [], 'security');
                return ['step' => 'c_prove', 'error' => 'अहिले मिलेन — कोड वा कार्यालय प्रयोग गर्नुहोस् · Not available right now; use the code or ask the office.'];
            }
        }
        $tok = '';
        for ($i = 0; $i < 6; $i++) {
            $tok .= 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0, 31)];
        }
        $text = 'hi VERIFY ' . $tok . ' — PASSWORD RESET · पासवर्ड रिसेट';
        $_SESSION[self::KEY]['wa'] = ['token' => $tok, 'url' => 'https://wa.me/' . WaVerify::companyNumber() . '?text=' . rawurlencode($text), 'text' => $text, 'decoy' => true];
        return ['step' => 'c_prove'];
    }

    public static function hiDone(): array
    {
        $s = self::state();
        if ($s === null || !is_array($s['wa'] ?? null)) {
            return ['step' => 'c_prove', 'error' => 'पहिले "hi पठाउनुहोस्" थिच्नुहोस् · Tap "Send hi" first.'];
        }
        if (!Security::rateLimit('code_login_hi_done', Security::clientIp(), 30, 600)) {
            return ['step' => 'c_prove', 'error' => 'धेरै पटक भयो — १० मिनेटपछि फेरि · Too many tries, wait 10 minutes.'];
        }
        $ok = !empty($s['idok']) && empty($s['wa']['decoy'])
            && WaVerify::consumeReset((string) $s['wa']['token'], (string) $s['intl']);
        if (!$ok) {
            return ['step' => 'c_prove', 'error' => 'अझै पुष्टि भएन — दर्ता गरिएको WhatsApp बाट Send थिचेर YES पठाउनुहोस् · Not confirmed yet: send it from your REGISTERED WhatsApp, then reply YES.'];
        }
        unset($_SESSION[self::KEY]['wa']);
        return self::proven('whatsapp_hi');
    }

    /** A 6-digit code to the REGISTERED WhatsApp — sent only when the details matched; the reply is the same. */
    public static function codeSend(): array
    {
        $s = self::state();
        if ($s === null || !isset($s['idok'])) {
            return ['step' => 'c_code', 'error' => 'फेरि सुरु गर्नुहोस् · Please start again.'];
        }
        $t0 = microtime(true);
        if (!empty($s['idok'])) {
            $aid = (int) $s['aid'];
            $res = Auth::issueOtp('pin:' . $aid, 'reset', 'whatsapp');
            if (!empty($res['ok'])) {
                $sent = self::deliver((string) $s['intl'], (string) $res['code']);
                Logger::info('Code door: setup / reset code ' . ($sent ? 'sent' : 'NOT delivered'), ['admin' => $aid], 'otp');
                if (APP_DEBUG && self::$deliverHook === null) {
                    $_SESSION[self::KEY]['debug'] = (string) $res['code'];
                }
            } elseif (str_contains((string) ($res['error'] ?? ''), 'wait')) {
                return ['step' => 'c_prove', 'error' => 'एक मिनेट पर्खेर फेरि मगाउनुहोस् · Please wait a minute before asking for another code.'];
            }
        }
        $_SESSION[self::KEY]['sent'] = time();
        self::evenTime($t0);
        return ['step' => 'c_prove', 'notice' => 'विवरण मिलेको भए दर्ता गरिएको WhatsApp मा कोड पठाइयो · If the details match, a code was sent to the registered WhatsApp.'];
    }

    public static function codeCheck(string $otp): array
    {
        $s = self::state();
        if ($s === null || !isset($s['idok'])) {
            return ['step' => 'c_code', 'error' => 'फेरि सुरु गर्नुहोस् · Please start again.'];
        }
        if (!Security::rateLimit('code_login_otp', Security::clientIp(), 10, 600, 900)) {
            return ['step' => 'c_prove', 'error' => 'धेरै पटक भयो — नयाँ कोड माग्नुहोस् वा कार्यालयलाई भन्नुहोस् · Too many tries: ask for a new code or ask the office.'];
        }
        $ok = !empty($s['idok']) && !empty(Auth::verifyOtp('pin:' . (int) $s['aid'], $otp, 'reset')['ok']);
        if (!$ok) {
            Logger::audit('admin.login.setup_code_fail', 'admin', (string) (int) ($s['aid'] ?? 0), null, null, 'Code door: wrong / expired WhatsApp setup code');
            return ['step' => 'c_prove', 'error' => 'कोड मिलेन वा पुरानो भयो · That code did not match or has expired.'];
        }
        unset($_SESSION[self::KEY]['debug']);
        return self::proven('whatsapp_code');
    }

    /** The office-issued activation code (admin/login-requests.php) for THIS account. */
    public static function activation(string $code): array
    {
        $s = self::state();
        if ($s === null) {
            return ['step' => 'c_code', 'error' => 'फेरि कोड लेख्नुहोस् · Please type your code again.'];
        }
        $back = isset($s['idok']) ? 'c_prove' : (($s['purpose'] ?? 'setup') === 'reset' ? 'c_forgot' : 'c_setup');
        $no = ['step' => $back, 'error' => 'सक्रिय गर्ने कोड मिलेन वा पुरानो भयो · That activation code did not match or has expired.'];
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (!self::ready() || strlen($code) !== 8) {
            return $no;
        }
        if (!Security::rateLimit('code_login_act', Security::clientIp(), 8, 3600, 3600)) {
            return ['step' => $back, 'error' => 'धेरै पटक भयो — एक घण्टापछि · Too many tries, wait an hour.'];
        }
        $aid = (int) ($s['aid'] ?? 0);
        if ($aid === 0) {
            return $no;
        }
        $rows = Database::fetchAll(
            "SELECT id, act_hash, act_tries FROM login_recovery_requests
              WHERE admin_id = :a AND status = 'approved' AND act_hash IS NOT NULL AND act_expires > NOW() AND act_tries < :m
              ORDER BY id DESC LIMIT 3",
            ['a' => $aid, 'm' => self::ACT_TRIES]
        );
        foreach ($rows as $r) {
            if (Security::verifyPassword($code, (string) $r['act_hash'])) {
                $won = Database::run("UPDATE login_recovery_requests SET status = 'used', act_hash = NULL WHERE id = :i AND status = 'approved'", ['i' => (int) $r['id']]);
                if ($won === 1) {
                    Logger::audit('admin.login.activation_used', 'admin', (string) $aid, null, ['request' => (int) $r['id']], 'Code door: office activation code used');
                    return self::proven('office_activation');
                }
            }
        }
        foreach ($rows as $r) {
            Database::query('UPDATE login_recovery_requests SET act_tries = act_tries + 1 WHERE id = :i', ['i' => (int) $r['id']]);
        }
        Logger::audit('admin.login.activation_fail', 'admin', (string) $aid, null, null, 'Code door: wrong activation code');
        return $no;
    }

    private static function proven(string $how): array
    {
        $_SESSION[self::KEY]['ver'] = time();
        $_SESSION[self::KEY]['how'] = $how;
        $_SESSION[self::KEY]['t']   = time();
        unset($_SESSION[self::KEY]['pend']);
        Logger::audit('admin.login.verified', 'admin', (string) (int) (self::state()['aid'] ?? 0), null,
            ['how' => $how, 'purpose' => (string) (self::state()['purpose'] ?? 'setup')], 'Code door: ownership proven, may set PIN / pattern');
        return ['step' => 'c_create', 'notice' => '✅ पुष्टि भयो — अब ६ अङ्कको PIN वा pattern राख्नुहोस् · Confirmed. Now choose a 6-digit PIN or a pattern.'];
    }

    /** Proven within the last 15 minutes, for a real account. */
    public static function proved(): bool
    {
        $s = self::state();
        return $s !== null && (int) ($s['aid'] ?? 0) > 0 && (int) ($s['ver'] ?? 0) >= time() - self::PROVEN_TTL;
    }

    /** "Ask the office": an admin-assisted setup / recovery request. */
    public static function request(string $note): array
    {
        $s = self::state();
        if ($s === null) {
            return ['step' => 'c_code', 'error' => 'फेरि कोड लेख्नुहोस् · Please type your code again.'];
        }
        $back = isset($s['idok']) ? 'c_prove' : (($s['purpose'] ?? 'setup') === 'reset' ? 'c_forgot' : 'c_setup');
        if (!self::ready()) {
            return ['step' => $back, 'error' => 'अहिले मिलेन — कार्यालयमा फोन गर्नुहोस् · Not available right now; please call the office.'];
        }
        if (!Security::rateLimit('code_login_req', Security::clientIp(), 4, 86400)) {
            return ['step' => $back, 'error' => 'अनुरोध पहिले नै पठाइयो — कार्यालयले सम्पर्क गर्छ · Your request is already with the office.'];
        }
        $aid = (int) ($s['aid'] ?? 0);
        $done = ['step' => $back, 'notice' => '📨 कार्यालयमा अनुरोध पुग्यो। कार्यालयले तपाईंलाई चिनेर ८ अक्षरको सक्रिय कोड दिन्छ — त्यो यहीँ लेख्नुहोस् · Request sent. The office will confirm who you are and give you an 8-character activation code — type it here.'];
        if ($aid > 0 && Database::exists("SELECT 1 FROM login_recovery_requests WHERE admin_id = :a AND status = 'open'", ['a' => $aid])) {
            return $done;
        }
        $why = (string) ($s['idwhy'] ?? 'asked');
        Database::insert('login_recovery_requests', [
            'admin_id'      => $aid > 0 ? $aid : null,
            'code_typed'    => mb_substr((string) $s['code'], 0, 40),
            'kind'          => ($s['purpose'] ?? 'setup') === 'reset' ? 'reset' : 'setup',
            'reason'        => in_array($why, ['no_details', 'no_match', 'match', 'inactive', 'no_account'], true) ? ($why === 'match' ? 'no_number' : $why) : 'asked',
            'details_match' => isset($s['idok']) ? (!empty($s['idok']) ? 1 : 0) : null,
            'contact_note'  => mb_substr(trim(Security::clean($note, 160)), 0, 160),
            'status'        => 'open',
            'ip_address'    => mb_substr(Security::clientIp(), 0, 45),
        ]);
        Logger::audit('admin.login.recovery_request', 'admin', (string) $aid, null, ['kind' => ($s['purpose'] ?? 'setup'), 'reason' => $why], 'Code door: asked the office to verify');
        return $done;
    }

    /* =================================================================
     *  Choose + confirm the PIN / pattern
     * ================================================================= */

    /** null = fine, else a user-safe reason (new PINs are exactly 6 digits). */
    public static function newProblem(string $kind, string $value): ?string
    {
        if ($kind === 'pin') {
            if (preg_match('/^(\d{4}|\d{6})$/', $value) !== 1) {
                return 'PIN ४ वा ६ अङ्कको हुनुपर्छ · The PIN must be 4 or 6 digits.';
            }
            return QuickUnlock::pinProblem($value);
        }
        if ($kind === 'pattern') {
            return QuickUnlock::patternProblem($value);
        }
        return 'PIN वा pattern छान्नुहोस् · Choose a PIN or a pattern.';
    }

    public static function createFirst(string $kind, string $value): array
    {
        if (!self::proved()) {
            return ['step' => 'c_code', 'error' => 'समय सकियो — फेरि सुरु गर्नुहोस् · That took too long, please start again.'];
        }
        if (($why = self::newProblem($kind, $value)) !== null) {
            return ['step' => 'c_create', 'error' => $why];
        }
        $v = $kind === 'pattern' ? (string) QuickUnlock::canonicalPattern($value) : $value;
        $_SESSION[self::KEY]['pend'] = ['kind' => $kind, 'h' => Security::hashPassword($v), 'len' => $kind === 'pin' ? strlen($v) : 0];
        return ['step' => 'c_confirm'];
    }

    public static function createConfirm(string $value): array
    {
        if (!self::proved()) {
            return ['step' => 'c_code', 'error' => 'समय सकियो — फेरि सुरु गर्नुहोस् · That took too long, please start again.'];
        }
        $s = (array) self::state();
        $p = $s['pend'] ?? null;
        if (!is_array($p)) {
            return ['step' => 'c_create'];
        }
        $kind = (string) $p['kind'];
        $v = $kind === 'pattern' ? (string) (QuickUnlock::canonicalPattern($value) ?? '') : $value;
        if ($v === '' || !Security::verifyPassword($v, (string) $p['h'])) {
            unset($_SESSION[self::KEY]['pend']);
            return ['step' => 'c_create', 'error' => 'दुवै पटक उही भएन — फेरि सुरुदेखि · The two did not match. Please start again.'];
        }
        $aid = (int) $s['aid'];
        try {
            QuickUnlock::set($aid, $kind, $v);
        } catch (RuntimeException $e) {
            return ['step' => 'c_create', 'error' => $e->getMessage()];
        }
        $reset = ($s['purpose'] ?? 'setup') === 'reset';
        if ($reset) {
            QuickUnlock::remove($aid, $kind === 'pin' ? 'pattern' : 'pin');   // a forgotten credential stops working
        }
        Logger::audit($reset ? 'admin.login.reset_done' : 'admin.login.setup_done', 'admin', (string) $aid, null,
            ['kind' => $kind, 'how' => (string) ($s['how'] ?? '')], 'Code door: ' . $kind . ($reset ? ' reset' : ' set up') . ' after ' . (string) ($s['how'] ?? 'proof'));
        $admin = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $aid]);
        if ($admin === null) {
            return ['step' => 'c_code'];
        }
        $r = self::open($admin, $kind);
        if (isset($r['go'])) {
            $r['flash'] = 'pin_saved';
        }
        return $r;
    }

    /* ================================================================= */

    private static function deliver(string $intl, string $code): bool
    {
        if (self::$deliverHook !== null) {
            return (bool) (self::$deliverHook)($intl, $code);
        }
        require_once __DIR__ . '/notify.php';
        try {
            $text = Settings::getString('company_name', APP_NAME) . ' — PIN / pattern राख्ने कोड: ' . $code . ' (' . OTP_EXPIRY_MINUTES
                  . ' मिनेट)। अरूले मागे नपठाउनुहोस्। · PIN setup code. Never share it.';
            return Notify::whatsapp($intl, $text, null, null, [], null, ['purpose' => 'otp']) === true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function evenTime(float $t0): void
    {
        if (!self::$evenTiming) {
            return;
        }
        $left = (0.8 + random_int(0, 300) / 1000) - (microtime(true) - $t0);
        if ($left > 0) {
            usleep((int) ($left * 1_000_000));
        }
    }
}
