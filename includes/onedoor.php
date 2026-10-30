<?php
/**
 * includes/onedoor.php — the ONE staff door (30 Oct 2026).
 *
 * Owner (30 Oct, Nepali/Hindi): "login system dherai messy chha. Number diyo
 * bhane number + WhatsApp OTP, natra WhatsApp ma 'hi' pathaune; natra simple
 * agent ID ra simple password. Login minimalistic, smooth, sabai ko lagi
 * eutai. Forgot password WhatsApp bata — hi wa code — automatic confirm."
 *
 * admin/login.php draws the screens; this class holds every decision, so the
 * flows can be tested from the CLI (tests/one-door-test.php):
 *
 *   start   one box "मोबाइल नम्बर वा ID · Mobile number or ID" (no lookup)
 *   mobile  "send hi on WhatsApp" · "6-digit code on WhatsApp" · password
 *           (the password picks the account when 2-3 IDs share the mobile;
 *           the same password on several → "which ID?")
 *   id      SHG-0029 / 29 / SHG-S-0003 / username / email → password
 *   pick    "which ID?" after a WhatsApp proof or a shared password
 *   admin_otp  the office 2FA code (unchanged)
 *   forgot* WhatsApp code or "hi" → which ID → new password for THAT ID only
 *
 * Nothing here opens a session by itself: every path ends in Auth
 * (adminLogin, agentLoginVerify / agentLoginPick, otpDoorOpen) or TeamPortal
 * (login, pick, setResetPassword) — the same rate limits, lockouts, 2FA,
 * password stamp and session registry as before. WhatsApp proof (code or hi)
 * opens only Auth::OTP_DOOR_ROLES without 2FA; office roles need their
 * password. A mobile number never shows whether it is registered.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/teamportal.php';
require_once __DIR__ . '/waverify.php';

final class OneDoor
{
    public const KEY = 'one_door';
    public const TTL = 1800;                 // a half-finished door step lives 30 minutes
    public const STEPS = ['start', 'mobile', 'id', 'code', 'wa', 'pick', 'admin_otp',
                          'forgot', 'forgot_code', 'forgot_pick', 'forgot_pw'];

    /** Tests switch the ~1 s even-timing pad off (it only exists so timing tells nothing). */
    public static bool $evenTiming = true;

    private function __construct() {}

    public static function on(): bool
    {
        return Auth::oneDoorOn();
    }

    /** The "send hi on WhatsApp" sign-in (setting login_one_door_hi_on, default ON). */
    public static function hiOn(): bool
    {
        return Settings::getBool('login_one_door_hi_on', true) && WaVerify::ready();
    }

    /* =================================================================
     *  What was typed
     * ================================================================= */

    /**
     * Sort the one box without touching the accounts: a mobile (any form: +91 …, 0…, spaces),
     * or anything else = an ID. An A-1 / S-1 team code is an ID too (31 Oct 2026, review): it used
     * to be swapped for the member's mobile here, which then sat in the page — anyone typing A-1,
     * A-2 … read every member's number. Its mobile is now looked up only behind the password
     * (password()), and never shown.
     *
     * @return array{kind: string, phone: string, cc: string, id: string}
     */
    public static function classify(string $raw, string $cc = '91'): array
    {
        $raw = trim(Security::clean($raw, 80));
        $cc  = TeamPortal::cc($cc);
        if ($raw === '') {
            return ['kind' => '', 'phone' => '', 'cc' => $cc, 'id' => ''];
        }
        $up = strtoupper($raw);
        if (preg_match('/^[AS]-[1-9][0-9]*$/', $up) === 1) {
            return ['kind' => 'id', 'phone' => '', 'cc' => $cc, 'id' => $up];
        }
        if (preg_match('/^\+?[0-9][0-9\s\-().]{8,20}$/', $raw) === 1) {
            $p = TeamPortal::phone10($raw);
            if ($p !== '') {
                $d = preg_replace('/\D/', '', $raw) ?? '';
                if (str_starts_with($d, '00')) {
                    $d = substr($d, 2);
                }
                if (strlen($d) === 13 && str_starts_with($d, '977')) {
                    $cc = '977';
                } elseif (strlen($d) === 12 && str_starts_with($d, '91')) {
                    $cc = '91';
                }
                return ['kind' => 'mobile', 'phone' => $p, 'cc' => $cc, 'id' => ''];
            }
        }
        return ['kind' => 'id', 'phone' => '', 'cc' => $cc, 'id' => Security::clean($raw, 60)];
    }

    /** A-1 / S-1 (the team registry code) → [mobile10, cc], lifted from team/index.php. */
    private static function teamCodeMobile(string $code): ?array
    {
        try {
            require_once __DIR__ . '/teamregistry.php';
            $ref = TeamRegistry::refForCode($code);
            if (!$ref) {
                return null;
            }
            [$k, $i] = explode(':', TeamRegistry::key($ref));
            $who = $k === 'staff' ? StaffApps::find((int) $i) : AgentApplications::find((int) $i);
            if ($who === null) {
                return null;
            }
            $raw = $k === 'staff' ? '+' . countryDialCode((string) $who['phone_country']) . $who['phone'] : (string) (($who['phone_raw'] ?? '') ?: $who['phone']);
            $digits = preg_replace('/\D/', '', $raw) ?? '';
            $p = substr($digits, -10);
            return strlen($p) === 10 ? [$p, str_starts_with($digits, '977') ? '977' : '91'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /* =================================================================
     *  The half-finished door, kept in this browser session
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

    /** Step 1: remember what was typed. @return array{step: string, error?: string} */
    public static function who(string $raw, string $cc): array
    {
        $c = self::classify($raw, $cc);
        if ($c['kind'] === '') {
            return ['step' => 'start', 'error' => 'मोबाइल नम्बर वा ID लेख्नुहोस् · Type your mobile number or ID.'];
        }
        self::clear();   // 31 Oct 2026 (review): a new start leaves no earlier pick / reset / 2FA step behind
        $_SESSION[self::KEY] = $c + ['t' => time()];
        return ['step' => $c['kind']];
    }

    /** "Start again", "Change", a finished sign-in: every half-done door step goes (Auth::DOOR_KEYS). */
    public static function clear(): void
    {
        Auth::clearDoorState();
    }

    /** "+91 98•••••210" — only what was typed, never anything from the records. */
    public static function masked(?array $s = null): string
    {
        $s ??= self::state();
        if ($s === null) {
            return '';
        }
        if (($s['kind'] ?? '') !== 'mobile') {
            return (string) ($s['id'] ?? '');
        }
        $p = (string) $s['phone'];
        return '+' . $s['cc'] . ' ' . substr($p, 0, 2) . '•••••' . substr($p, -3);
    }

    /* =================================================================
     *  Password (mobile or ID)
     * ================================================================= */

    /** @return array{step?: string, go?: string, error?: string, notice?: string} */
    public static function password(string $pw): array
    {
        $s = self::state();
        if ($s === null || !in_array($s['kind'] ?? '', ['mobile', 'id'], true)) {
            return ['step' => 'start', 'error' => 'फेरि सुरु गर्नुहोस् · Please start again.'];
        }
        if ($pw === '') {
            return ['step' => (string) $s['kind'], 'error' => 'पासवर्ड लेख्नुहोस् · Type your password.'];
        }
        // 31 Oct 2026 (review): a wrong password answers in the same ~1 s whatever is behind the box —
        // one bcrypt per ID on a mobile used to tell how many IDs (if any) a number carries.
        $t0 = microtime(true);
        if ($s['kind'] === 'mobile') {
            $r = self::mobilePassword((string) $s['phone'], (string) $s['cc'], $pw, 'mobile');
        } elseif (preg_match('/^[AS]-[1-9][0-9]*$/', (string) $s['id']) === 1 && ($m = self::teamCodeMobile((string) $s['id'])) !== null) {
            $r = self::mobilePassword($m[0], $m[1], $pw, 'id');   // A-1 / S-1: its mobile, looked up only here
        } else {
            $r = self::adminPassword(Auth::resolveLoginId((string) $s['id']), $pw, 'id');
        }
        if (isset($r['error']) && !isset($r['go'])) {
            self::evenTime($t0);
        }
        return $r;
    }

    /** Mobile + password: TeamPortal::login over every ID on the number (the password picks the ID). */
    private static function mobilePassword(string $phone, string $cc, string $pw, string $back): array
    {
        $r = TeamPortal::login($phone, $pw, $cc, true);
        if ($r['ok']) {
            if (($r['kind'] ?? '') === 'pick') {
                $_SESSION[self::KEY]['src'] = 'pw';
                return ['step' => 'pick'];
            }
            self::clear();
            return ['go' => ($r['kind'] ?? '') === 'staff' ? 'team' : 'home'];
        }
        if (!empty($r['twofaUser'])) {
            return self::adminPassword((string) $r['twofaUser'], $pw, $back);
        }
        $e = (string) ($r['error'] ?? '');
        if ($back === 'id') {
            // a team code answers exactly like any other ID (a code that exists must not look different)
            return ['step' => 'id', 'error' => str_contains($e, 'Too many') && empty($r['phoneLocked']) ? $e : self::pwError('')];
        }
        return ['step' => 'mobile', 'error' => $e !== '' ? $e : 'मिलेन · Did not match.'];
    }

    /** One account by username: Auth::adminLogin (rate limit, lockout, 2FA, password stamp). */
    private static function adminPassword(string $username, string $pw, string $back): array
    {
        $r = Auth::adminLogin($username, $pw);
        if ($r['ok']) {
            self::clear();
            return ['go' => 'home'];
        }
        if (!empty($r['twofa'])) {
            return ['step' => 'admin_otp', 'notice' => !empty($r['wait'])
                ? 'कोड भर्खरै पठाइएको छ — त्यही कोड लेख्नुहोस्, वा एक मिनेटपछि फेरि मगाउनुहोस् · A code was sent a moment ago: enter that code, or ask for a new one after a minute.'
                : 'तपाईंको WhatsApp मा ६ अङ्कको कोड पठाइयो · A 6-digit code was sent to your registered WhatsApp.'];
        }
        $e = (string) ($r['error'] ?? '');
        if (!str_contains($e, 'Too many') && (str_contains($e, 'locked') || str_contains($e, 'disabled'))) {
            // 31 Oct 2026 (review): "locked" / "switched off" only to someone who typed that ID's own
            // password — to anyone else the same "did not match" as an unknown ID (no ID enumeration)
            $hash = (string) Database::scalar('SELECT password_hash FROM admins WHERE username = :u', ['u' => Security::clean($username, 60)], '');
            if ($hash === '' || !Security::verifyPassword($pw, $hash)) {
                $e = '';
            }
        }
        return ['step' => $back, 'error' => self::pwError($e)];
    }

    private static function pwError(string $e): string
    {
        return match (true) {
            str_contains($e, 'Too many')  => 'धेरै पटक गलत भयो — ' . LOGIN_LOCKOUT_MIN . ' मिनेटपछि फेरि · Too many tries, wait ' . LOGIN_LOCKOUT_MIN . ' minutes.',
            str_contains($e, 'locked')    => 'यो खाता केही मिनेटका लागि बन्द छ · This account is locked for a few minutes.',
            str_contains($e, 'disabled')  => 'यो खाता बन्द छ — कार्यालयमा सम्पर्क गर्नुहोस् · This account is switched off. Please call the office.',
            default                       => 'ID वा पासवर्ड मिलेन · ID or password did not match.',
        };
    }

    /* =================================================================
     *  WhatsApp: a 6-digit code
     * ================================================================= */

    /** Every number gets the same reply and about the same time — registered or not. */
    public static function codeSend(): array
    {
        $s = self::state();
        if ($s === null || ($s['kind'] ?? '') !== 'mobile') {
            return ['step' => 'start', 'error' => 'फेरि सुरु गर्नुहोस् · Please start again.'];
        }
        $t0 = microtime(true);
        // 31 Oct 2026 (review): the WHOLE WhatsApp number (country + mobile) — the code goes to that number
        // and opens only accounts whose own number it is (a +977 member's code never reaches the +91 twin)
        $intl = self::loginIntl((string) $s['phone'], (string) $s['cc']);
        $_SESSION[self::KEY]['code_intl'] = $intl;
        $r = Auth::agentLoginStart('', (string) $s['phone'], $intl);
        if (!$r['ok'] && empty($r['nomatch']) && str_contains((string) ($r['error'] ?? ''), 'Too many attempts')) {
            // the per-IP throttle: about this browser, not the number
            return ['step' => 'mobile', 'error' => 'धेरै पटक भयो — ' . LOGIN_LOCKOUT_MIN . ' मिनेटपछि फेरि · Too many tries, wait ' . LOGIN_LOCKOUT_MIN . ' minutes.'];
        }
        self::evenTime($t0);
        $_SESSION[self::KEY]['sent'] = time();
        return ['step' => 'code', 'notice' => 'यो नम्बर दर्ता छ भने WhatsApp मा कोड पठाइयो · If this number is registered, a code is on its way on WhatsApp.'];
    }

    public static function codeVerify(string $otp): array
    {
        $s = self::state();
        if ($s === null || ($s['kind'] ?? '') !== 'mobile') {
            return ['step' => 'start', 'error' => 'फेरि सुरु गर्नुहोस् · Please start again.'];
        }
        $intl = (string) ($s['code_intl'] ?? '') ?: self::loginIntl((string) $s['phone'], (string) $s['cc']);
        $r = Auth::agentLoginVerify('', (string) $s['phone'], $otp, $intl);
        if ($r['ok'] && empty($r['choose'])) {
            self::clear();
            return ['go' => 'home'];
        }
        if ($r['ok']) {
            self::keepPick((array) $r['choose']);
            return ['step' => 'pick'];
        }
        $e = (string) ($r['error'] ?? '');
        return ['step' => 'code', 'error' => str_contains($e, 'Too many')
            ? 'धेरै पटक भयो — नयाँ कोड माग्नुहोस् वा केही मिनेट पर्खनुहोस् · Too many tries: ask for a new code or wait a few minutes.'
            : 'कोड मिलेन वा पुरानो भयो · That code did not match or has expired.'];
    }

    /* =================================================================
     *  WhatsApp: send "hi" (reverse verification)
     * ================================================================= */

    /** The WhatsApp number the "hi" must come from: the account's own, else what was typed. */
    public static function loginIntl(string $phone10, string $cc): string
    {
        $want = TeamPortal::cc($cc) . $phone10;
        $have = [];
        foreach (Auth::otpDoorAccounts($phone10) as $a) {
            $i = TeamPortal::intlFor($phone10, $a);
            if ($i !== '') {
                $have[$i] = true;
            }
        }
        return ($have === [] || isset($have[$want])) ? $want : (string) array_key_first($have);
    }

    public static function waStart(): array
    {
        $s = self::state();
        if ($s === null || ($s['kind'] ?? '') !== 'mobile') {
            return ['step' => 'start', 'error' => 'फेरि सुरु गर्नुहोस् · Please start again.'];
        }
        if (!self::hiOn()) {
            return ['step' => 'mobile', 'error' => 'अहिले "hi" ले लगइन बन्द छ — कोड वा पासवर्ड प्रयोग गर्नुहोस् · "hi" sign-in is off right now; use the code or your password.'];
        }
        $ip = Security::clientIp();
        $p  = (string) $s['phone'];
        if (!Security::rateLimit('wa_verify_ip', $ip, 8, 600) || !Security::rateLimit('wa_verify_phone', $p, 4, 600)) {
            return ['step' => 'mobile', 'error' => 'धेरै पटक भयो — १० मिनेटपछि फेरि · Too many tries, wait 10 minutes.'];
        }
        $intl = self::loginIntl($p, (string) $s['cc']);
        try {
            $w = WaVerify::start('login', $p, '', $ip, $intl);
        } catch (Throwable $e) {
            Logger::warning('One door: hi sign-in could not start: ' . $e->getMessage());
            return ['step' => 'mobile', 'error' => 'अहिले मिलेन — कोड वा पासवर्ड प्रयोग गर्नुहोस् · Not available right now; use the code or your password.'];
        }
        $_SESSION[self::KEY]['wa'] = ['token' => $w['token'], 'url' => $w['url'], 'text' => $w['text'], 'intl' => $intl];
        return ['step' => 'wa'];
    }

    public static function waFinish(): array
    {
        $s = self::state();
        if ($s === null || ($s['kind'] ?? '') !== 'mobile' || !is_array($s['wa'] ?? null)) {
            return ['step' => 'start', 'error' => 'फेरि सुरु गर्नुहोस् · Please start again.'];
        }
        if (!Security::rateLimit('one_door_wa_finish', Security::clientIp(), 20, 600)) {
            return ['step' => 'wa', 'error' => 'धेरै पटक भयो — १० मिनेटपछि फेरि · Too many tries, wait 10 minutes.'];
        }
        $w = $s['wa'];
        if (!WaVerify::consumeLogin((string) $w['token'], (string) $w['intl'])) {
            return ['step' => 'wa', 'error' => 'अझै प्रमाणित भएन — WhatsApp मा Send थिचेर फर्कनुहोस् · Not verified yet: tap Send in WhatsApp, then come back.'];
        }
        unset($_SESSION[self::KEY]['wa']);
        $r = Auth::otpDoorOpen((string) $s['phone'], 'hi', (string) $w['intl']);
        if ($r['ok'] && empty($r['choose'])) {
            self::clear();
            return ['go' => 'home'];
        }
        if ($r['ok']) {
            self::keepPick((array) $r['choose']);
            return ['step' => 'pick'];
        }
        // only the proven owner of the number ever reads this
        return ['step' => 'mobile', 'error' => 'यो नम्बरमा WhatsApp ले खुल्ने खाता छैन — पासवर्डले भित्र जानुहोस् · No account here opens with WhatsApp; use your password.'];
    }

    /* =================================================================
     *  Which ID? (one mobile, 2-3 accounts)
     * ================================================================= */

    /** @param list<array<string, mixed>> $choose */
    private static function keepPick(array $choose): void
    {
        $rows = [];
        foreach ($choose as $c) {
            $code = '';
            $des  = '';
            try {
                if (is_file(__DIR__ . '/agentpro.php')) {
                    require_once __DIR__ . '/agentpro.php';
                    if (AgentPro::available()) {
                        $m = AgentPro::member((int) $c['id'], false);
                        $code = (string) ($m['idNo'] ?? '');
                        $des  = (string) ($m['designation'] ?? '');
                    }
                }
            } catch (Throwable $e) {
            }
            $rows[] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'role' => (string) $c['role'], 'code' => $code, 'designation' => $des];
        }
        $_SESSION[self::KEY]['src']  = 'otp';
        $_SESSION[self::KEY]['pick'] = $rows;
    }

    /** @return list<array{id: int, name: string, role: string, code: string}> */
    public static function pickList(): array
    {
        $s = self::state();
        if ($s === null) {
            return [];
        }
        if (($s['src'] ?? '') === 'otp') {
            $p = $_SESSION['ap_login_pick'] ?? null;
            return is_array($p) && (int) ($p['t'] ?? 0) >= time() - 300 ? (array) ($s['pick'] ?? []) : [];
        }
        return ($s['src'] ?? '') === 'pw' ? TeamPortal::pickList() : [];
    }

    public static function pick(int $adminId): array
    {
        $s = self::state();
        $src = (string) ($s['src'] ?? '');
        if ($s === null || $src === '') {
            return ['step' => 'start', 'error' => 'फेरि लगइन गर्नुहोस् · Please sign in again.'];
        }
        $r = $src === 'otp' ? Auth::agentLoginPick($adminId) : TeamPortal::pick($adminId);
        if ($r['ok']) {
            self::clear();
            return ['go' => 'home'];
        }
        unset($_SESSION[self::KEY]['src'], $_SESSION[self::KEY]['pick']);
        if ($src === 'pw' && TeamPortal::pickList() !== []) {
            $_SESSION[self::KEY]['src'] = 'pw';
            return ['step' => 'pick', 'error' => (string) $r['error']];
        }
        return ['step' => 'mobile', 'error' => (string) ($r['error'] ?? 'फेरि लगइन गर्नुहोस् · Please sign in again.')];
    }

    /* =================================================================
     *  Forgot password — WhatsApp only, then ONE ID gets the new one.
     *  31 Oct 2026 (review): only the IDs WhatsApp may open anyway
     *  (Auth::OTP_DOOR_ROLES without 2FA) and staff-portal records. An
     *  office account (manager, accountant, superadmin …) is never reset by
     *  WhatsApp alone — its 2FA code would go to the very same WhatsApp —
     *  a superadmin resets it (team-member.php "Advanced").
     * ================================================================= */

    /** @return array<string, mixed>|null the reset in progress ($_SESSION['team_reset'], shared with TeamPortal) */
    public static function reset(): ?array
    {
        $r = $_SESSION['team_reset'] ?? null;
        return is_array($r) && !empty($r['phone']) && (int) ($r['t'] ?? 0) >= time() - self::TTL ? $r : null;
    }

    public static function forgotSend(string $raw, string $cc): array
    {
        $c = self::classify($raw, $cc);
        if ($c['kind'] !== 'mobile') {
            return ['step' => 'forgot', 'error' => 'दर्ता गरेको मोबाइल नम्बर लेख्नुहोस् · Type the mobile number you registered with.'];
        }
        $r = TeamPortal::sendResetCode($c['phone'], $c['cc'], true);   // same reply + even timing for every number
        if (!$r['ok']) {
            return ['step' => 'forgot', 'error' => (string) $r['error']];
        }
        self::clear();   // 31 Oct 2026 (review): a new reset starts clean (no older proof, pick or 2FA step)
        $_SESSION['team_reset'] = ['phone' => $c['phone'], 'cc' => $c['cc'], 't' => time(), 'any' => true]
            + (isset($r['debugCode']) ? ['debug' => $r['debugCode']] : []);
        return ['step' => 'forgot_code', 'notice' => 'यो नम्बर दर्ता छ भने WhatsApp मा कोड पठाइयो · If this number is registered, a code is on its way on WhatsApp.'];
    }

    /** "Send hi instead" straight from the first forgot screen: no code is sent, the "hi" proves the number. */
    public static function forgotHiFirst(string $raw, string $cc): array
    {
        $c = self::classify($raw, $cc);
        if ($c['kind'] !== 'mobile') {
            return ['step' => 'forgot', 'error' => 'दर्ता गरेको मोबाइल नम्बर लेख्नुहोस् · Type the mobile number you registered with.'];
        }
        self::clear();
        $_SESSION['team_reset'] = ['phone' => $c['phone'], 'cc' => $c['cc'], 't' => time(), 'any' => true];
        return self::forgotHi();
    }

    public static function forgotHi(): array
    {
        $r = self::reset();
        if ($r === null) {
            return ['step' => 'forgot', 'error' => 'मोबाइल नम्बर लेख्नुहोस् · Type your mobile number.'];
        }
        if (!self::hiOn()) {
            return ['step' => 'forgot_code', 'error' => 'अहिले "hi" बन्द छ — कोड प्रयोग गर्नुहोस् · "hi" is off right now; use the code.'];
        }
        try {
            TeamPortal::startVerify((string) $r['phone'], TeamPortal::cc($r['cc'] ?? '91'), true);
        } catch (TeamPortalError $e) {
            return ['step' => 'forgot_code', 'error' => $e->getMessage()];
        }
        return ['step' => 'forgot_code'];
    }

    public static function forgotProve(string $code): array
    {
        $r = self::reset();
        if ($r === null) {
            return ['step' => 'forgot', 'error' => 'मोबाइल नम्बर लेख्नुहोस् · Type your mobile number.'];
        }
        $p = TeamPortal::proveReset((string) $r['phone'], $code, true);
        if (!$p['ok']) {
            return ['step' => 'forgot_code', 'error' => (string) $p['error']];
        }
        $choices = (array) $p['choices'];
        if (count($choices) === 1) {
            $_SESSION['team_reset']['proven']['who'] = (string) $choices[0]['who'];
            return ['step' => 'forgot_pw'];
        }
        return ['step' => 'forgot_pick'];
    }

    public static function forgotPick(string $who): array
    {
        foreach (TeamPortal::resetChoices() as $c) {
            if ($c['who'] === $who) {
                $_SESSION['team_reset']['proven']['who'] = $who;
                return ['step' => 'forgot_pw'];
            }
        }
        return TeamPortal::resetChoices() === []
            ? ['step' => 'forgot', 'error' => 'समय सकियो — फेरि सुरु गर्नुहोस् · That took too long, please start again.']
            : ['step' => 'forgot_pick', 'error' => 'कुन ID हो छान्नुहोस् · Pick the ID.'];
    }

    /** The ID whose password is being set (after the proof), or null. */
    public static function forgotWho(): ?array
    {
        $who = (string) ($_SESSION['team_reset']['proven']['who'] ?? '');
        foreach ($who === '' ? [] : TeamPortal::resetChoices() as $c) {
            if ($c['who'] === $who) {
                return $c;
            }
        }
        return null;
    }

    public static function forgotSet(string $pw, string $pw2): array
    {
        $c = self::forgotWho();
        if ($c === null) {
            return ['step' => TeamPortal::resetChoices() !== [] ? 'forgot_pick' : 'forgot', 'error' => 'फेरि सुरु गर्नुहोस् · Please start again.'];
        }
        $r = TeamPortal::setResetPassword($c['who'], $pw, $pw2);
        if (!$r['ok']) {
            return ['step' => !empty($r['restart']) ? 'forgot' : 'forgot_pw', 'error' => (string) $r['error']];
        }
        self::clear();
        if (($r['kind'] ?? '') === 'twofa') {
            // a 2FA account keeps its code: the new password goes through Auth::adminLogin like any sign-in
            $a = Auth::adminLogin((string) $r['username'], $pw);
            if ($a['ok']) {
                return ['go' => 'home', 'flash' => 'saved'];
            }
            return !empty($a['twofa'])
                ? ['step' => 'admin_otp', 'notice' => '✅ नयाँ पासवर्ड राखियो — अब WhatsApp मा आएको कोड लेख्नुहोस् · New password saved. Now enter the code sent to your WhatsApp.']
                : ['step' => 'start', 'notice' => '✅ नयाँ पासवर्ड राखियो — अब भित्र जानुहोस् · New password saved; please sign in.'];
        }
        return ['go' => ($r['kind'] ?? '') === 'staff' ? 'team' : 'home', 'flash' => 'saved'];
    }

    /* ================================================================= */

    private static function evenTime(float $t0): void
    {
        if (!self::$evenTiming) {
            return;
        }
        $target = 0.9 + random_int(0, 300) / 1000;
        $left = $target - (microtime(true) - $t0);
        if ($left > 0) {
            usleep((int) ($left * 1_000_000));
        }
    }
}
