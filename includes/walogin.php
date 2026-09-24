<?php
/**
 * =====================================================================
 *  WaLogin — a member of staff signs in over WhatsApp (24 Sep 2026).
 *
 *  Owner ask: "login system ni hos, WhatsApp ma login garna milos,
 *  user le password ra ID le — WhatsApp bata nai bot le manage garos."
 *
 *  Until now the assistant knew a member of staff by ONE thing: the
 *  number on their staff record (AiTools::whoIs). A seller writing from
 *  a second handset, a counter sharing one phone, a manager travelling
 *  with a Nepali SIM — all of them were customers to the bot. This class
 *  is the door for them:
 *
 *      login SHG-027 mypassword        (agent code, username or email)
 *      otp 482913                      (when the office asks for a code)
 *      logout  ·  ma ko hu             (who am I signed in as?)
 *
 *  WHAT KEEPS IT SAFE
 *  ------------------
 *   · the same credentials as the web login (admins.password_hash), the
 *     same generic failure text, the same failed_logins / locked_until
 *     counters and the same LoginLog rows — a guess on WhatsApp costs
 *     exactly what a guess on the login page costs;
 *   · a one-time code to the REGISTERED mobile of that account when
 *     wa_login_otp is on (default), and ALWAYS for office roles: a
 *     password alone is never enough to become the manager from a
 *     number nobody has seen;
 *   · a session is a row in wa_logins with an expiry, one live row per
 *     number, revocable from Admin → AI Activity; every message re-checks
 *     the account (active, not locked, not "must change password");
 *   · the password message is intercepted BEFORE anything else reads the
 *     text — it never reaches the model, the drafts queue or a log — and
 *     the reply asks the person to delete it;
 *   · everything is behind wa_login_on (OFF). With the switch off, a
 *     "login …" message is still swallowed here (so the password still
 *     goes nowhere) and answered with "switched off".
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaLogin
{
    /** kv_store scope: a password accepted, a code still to come. */
    private const PENDING_SCOPE = 'wa_login_pending';
    private const PENDING_TTL   = 600;          // 10 minutes to type the code

    /** Roles the assistant knows how to serve (the same list whoIs honours). */
    private const ROLES  = ['agent', 'counter', 'manager', 'superadmin'];
    /** Roles that always need the second factor, whatever the setting says. */
    private const OFFICE = ['manager', 'superadmin'];

    private const OTP_NS = 'walogin:';

    public static function enabled(): bool
    {
        return Settings::getBool('wa_login_on', false);
    }

    /* =================================================================
     *  Reading the message
     * ================================================================= */

    /**
     * Is this message a login / logout / otp / whoami command?
     *
     * @return array{cmd: string, id?: string, password?: string, code?: string}|null
     */
    public static function command(string $text): ?array
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($t === '' || mb_strlen($t) > 200) {
            return null;
        }
        $lower = mb_strtolower($t);

        if (preg_match('/^(?:logout|log out|log-out|signout|sign out|लगआउट|लग आउट|साइन आउट)\s*[.!]?$/u', $lower) === 1) {
            return ['cmd' => 'logout'];
        }
        if (preg_match('/^(?:whoami|who am i|ma ko hu|ma ko hun|म को हुँ|म को हु|mero login|login status|login\?)\s*[?.!]?$/u', $lower) === 1) {
            return ['cmd' => 'whoami'];
        }
        if (preg_match('/^(?:otp|code|कोड)\s*[:\-]?\s*(\d{4,8})\s*$/u', $lower, $m) === 1) {
            return ['cmd' => 'otp', 'code' => $m[1]];
        }
        // login <id> <password …> — the password is everything after the id.
        if (preg_match('/^(?:login|log in|log-in|signin|sign in|लगइन|लग इन|साइन इन)\s*[:\-]?\s+(\S+)\s+(.+)$/su', $t, $m) === 1) {
            return ['cmd' => 'login', 'id' => trim($m[1]), 'password' => trim($m[2])];
        }
        if (preg_match('/^(?:login|log in|log-in|signin|sign in|लगइन|लग इन|साइन इन)(?:\s+\S+)?\s*[.!?]?$/u', $lower) === 1) {
            return ['cmd' => 'help'];
        }

        return null;
    }

    /* =================================================================
     *  The conversation
     * ================================================================= */

    /**
     * Answer a login-related message, or null when the message is not one.
     * Never throws: a failure is a reply, not a crash of the whole bot.
     *
     * @return array{text: string, media: ?string}|null
     */
    public static function handle(string $phoneDigits, string $text): ?array
    {
        $cmd = self::command($text);
        if ($cmd === null || $phoneDigits === '') {
            return null;
        }

        try {
            if (!self::enabled()) {
                /* Swallow the password even when the door is shut — the
                   message must not travel on to the model or a draft. */
                if ($cmd['cmd'] === 'login' || $cmd['cmd'] === 'help') {
                    return self::out("🔒 WhatsApp लगइन अहिले बन्द छ। कृपया वेबसाइटको एजेन्ट पोर्टलबाट साइन इन गर्नुहोस्: "
                        . appUrl('admin/login.php?portal=agent') . "\n"
                        . (self::mentionsSecret($cmd) ? "सुरक्षाका लागि पासवर्ड भएको सन्देश मेटाउनुहोस्।" : ''));
                }
                return null;
            }

            return match ($cmd['cmd']) {
                'login'  => self::login($phoneDigits, (string) $cmd['id'], (string) $cmd['password']),
                'otp'    => self::otp($phoneDigits, (string) $cmd['code']),
                'logout' => self::logout($phoneDigits),
                'whoami' => self::whoami($phoneDigits),
                default  => self::help(),
            };
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
            return self::out('लगइन अहिले मिलेन। केही बेरपछि फेरि प्रयास गर्नुहोस्, वा अफिसलाई फोन गर्नुहोस्।');
        }
    }

    private static function help(): array
    {
        return self::out(
            "🔑 स्टाफ लगइन (WhatsApp)\n"
            . "यसरी लेख्नुहोस्:\n"
            . "login <एजेन्ट कोड वा युजरनेम> <पासवर्ड>\n"
            . "जस्तै: login SHG-0027 mypassword\n\n"
            . "पछि: logout लेखे बाहिर निस्किन्छ, \"ma ko hu\" लेखे को हो भनेर बताउँछ।\n"
            . "पासवर्ड पठाएपछि त्यो सन्देश मेटाउनुहोस्।"
        );
    }

    private static function login(string $phone, string $id, string $password): array
    {
        $deleteNote = "\n\nसुरक्षाका लागि पासवर्ड भएको सन्देश अब मेटाउनुहोस्।";
        $generic    = self::out('❌ लगइन मिलेन — कोड/युजरनेम वा पासवर्ड गलत छ। फेरि प्रयास गर्नुहोस्, वा अफिसलाई सम्पर्क गर्नुहोस्।' . $deleteNote);

        if ($password === '' || mb_strlen($password) > 128) {
            return $generic;
        }
        // Five tries per number in 15 minutes, then a 15-minute lockout —
        // the same budget the web login gives one IP.
        if (!Security::rateLimit('wa_login', $phone, MAX_LOGIN_ATTEMPTS, 900, LOGIN_LOCKOUT_MIN * 60)) {
            Logger::warning('WhatsApp login rate limit hit', ['phone' => $phone], 'whatsapp');
            return self::out('⏳ धेरै प्रयास भयो। ' . LOGIN_LOCKOUT_MIN . ' मिनेट पछि फेरि प्रयास गर्नुहोस्।' . $deleteNote);
        }

        $admin = self::resolve($id);
        if ($admin === null) {
            // Burn the same time a real check takes, so a missing account is
            // not distinguishable from a wrong password by the clock.
            password_verify($password, '$2y$11$usesomesillystringfoeu1f3.O0hOZ8W4l0sN2q7dPzYlXqR5Z8Vy');
            LoginLog::record(Security::clean($id, 60), 'unknown_user');
            Logger::warning('WhatsApp login: unknown id', ['phone' => $phone], 'whatsapp');
            return $generic;
        }

        $username = (string) $admin['username'];
        $adminId  = (int) $admin['id'];

        if (!in_array((string) $admin['role'], self::ROLES, true) || (int) $admin['is_active'] !== 1) {
            LoginLog::record($username, 'disabled', $adminId);
            return $generic;
        }
        if (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time()) {
            LoginLog::record($username, 'locked', $adminId);
            return self::out('🔒 यो खाता केही समयका लागि लक भएको छ। केही बेरपछि फेरि प्रयास गर्नुहोस्।' . $deleteNote);
        }

        if (!Security::verifyPassword($password, (string) $admin['password_hash'])) {
            $failed = (int) $admin['failed_logins'] + 1;
            $update = ['failed_logins' => $failed];
            if ($failed >= MAX_LOGIN_ATTEMPTS) {
                $update['locked_until'] = date('Y-m-d H:i:s', time() + (LOGIN_LOCKOUT_MIN * 60));
            }
            Database::update('admins', $update, 'id = :id', ['id' => $adminId]);
            LoginLog::record($username, 'bad_password', $adminId);
            Logger::warning('WhatsApp login failed', ['phone' => $phone, 'admin' => $adminId, 'attempt' => $failed], 'whatsapp');
            return $generic;
        }

        if ((int) ($admin['must_change_pw'] ?? 1) === 1) {
            return self::out('🔑 पासवर्ड पहिले वेबसाइटमा बदल्नुपर्छ (अफिसले दिएको अस्थायी पासवर्ड हो)। यहाँबाट साइन इन गर्नुहोस्: '
                . appUrl('admin/login.php?portal=agent') . ' — त्यसपछि WhatsApp मा फेरि login गर्नुहोस्।' . $deleteNote);
        }

        /* The second factor. The registered mobile is the one place a code
           can prove the person holds the account, not just the password. */
        $registered = normalisePhone((string) ($admin['phone'] ?? ''));
        $needOtp    = Settings::getBool('wa_login_otp', true) || in_array((string) $admin['role'], self::OFFICE, true);
        if ($needOtp && $registered === '') {
            LoginLog::record($username, 'disabled', $adminId);
            return self::out('⚠️ यो खातामा दर्ता गरिएको मोबाइल नम्बर छैन, त्यसैले WhatsApp लगइन मिल्दैन। अफिसलाई Staff मा नम्बर थप्न भन्नुहोस्।' . $deleteNote);
        }
        if ($needOtp && $registered !== $phone) {
            $otp = Auth::issueOtp(self::OTP_NS . $registered, 'login', 'whatsapp');
            if (!($otp['ok'] ?? false)) {
                return self::out('⏳ ' . (string) ($otp['error'] ?? 'कोड अहिले पठाउन सकिएन।') . $deleteNote);
            }
            require_once INCLUDE_PATH . '/notify.php';
            $company = Settings::getString('company_name', APP_NAME);
            Notify::whatsapp(
                $registered,
                $company . ' WhatsApp लगइन कोड: ' . $otp['code'] . "\n"
                . 'नम्बर ' . self::mask($phone) . ' बाट साइन इन गर्न खोजिँदैछ। तपाईं होइन भने यो कोड कसैलाई नदिनुहोस्।' . "\n"
                . OTP_EXPIRY_MINUTES . ' मिनेट सम्म मान्य।',
                null,
                resolvePhoneCountry('', (string) ($admin['phone'] ?? '')) ?: null
            );
            self::pendingSet($phone, ['adminId' => $adminId, 'registered' => $registered, 'at' => time()]);
            Logger::audit('staff.login_whatsapp_otp', 'admin', (string) $adminId, null, ['from' => $phone],
                'WhatsApp login: password accepted, one-time code sent to the registered mobile');

            return self::out('✅ पासवर्ड मिल्यो। एक पटकको कोड तपाईंको दर्ता भएको नम्बर (' . self::mask($registered) . ') मा पठाइयो।'
                . "\nयहाँ यसरी लेख्नुहोस्: otp 123456" . $deleteNote);
        }

        $opened = self::open($phone, $admin, $needOtp ? 'password+otp' : 'password');
        $opened['text'] .= $deleteNote;

        return $opened;
    }

    private static function otp(string $phone, string $code): array
    {
        $pending = self::pendingGet($phone);
        if ($pending === null) {
            return self::out('कुनै लगइन पर्खिरहेको छैन। पहिले "login <कोड> <पासवर्ड>" लेख्नुहोस्।');
        }
        $registered = (string) ($pending['registered'] ?? '');
        $adminId    = (int) ($pending['adminId'] ?? 0);
        $result     = Auth::verifyOtp(self::OTP_NS . $registered, $code, 'login');
        if (!($result['ok'] ?? false)) {
            $admin = self::adminRow($adminId);
            LoginLog::record((string) ($admin['username'] ?? 'wa-login'), 'bad_password', $adminId ?: null);
            return self::out('❌ कोड मिलेन — ' . (string) ($result['error'] ?? '') . "\nफेरि \"otp 123456\" लेख्नुहोस्, वा नयाँ login गर्नुहोस्।");
        }
        self::pendingClear($phone);

        $admin = self::adminRow($adminId);
        if ($admin === null || (int) $admin['is_active'] !== 1 || !in_array((string) $admin['role'], self::ROLES, true)) {
            return self::out('❌ यो खाता अहिले सक्रिय छैन। अफिसलाई सम्पर्क गर्नुहोस्।');
        }

        return self::open($phone, $admin, 'password+otp');
    }

    /** Open the session and say hello. */
    private static function open(string $phone, array $admin, string $method): array
    {
        $adminId = (int) $admin['id'];
        $hours   = max(1, min(24 * 30, Settings::getInt('wa_login_ttl_hours', 12)));
        $now     = date('Y-m-d H:i:s');
        $until   = date('Y-m-d H:i:s', time() + $hours * 3600);

        // One live session per number: the new one replaces the old.
        self::revokePhone($phone, null);
        Database::insert('wa_logins', [
            'phone'      => $phone,
            'admin_id'   => $adminId,
            'method'     => $method,
            'created_at' => $now,
            'expires_at' => $until,
            'last_seen_at' => $now,
        ]);
        Database::update('admins', ['failed_logins' => 0, 'last_login_at' => $now], 'id = :id', ['id' => $adminId]);
        // A good sign-in is not a guess: the five-tries budget starts afresh.
        try {
            Database::delete('rate_limits', 'bucket = :b AND identifier = :i', ['b' => 'wa_login', 'i' => $phone]);
        } catch (Throwable $ignored) {
        }

        LoginLog::record((string) $admin['username'], 'success', $adminId);
        Logger::audit('staff.login_whatsapp', 'admin', (string) $adminId, null,
            ['from' => $phone, 'method' => $method, 'until' => $until],
            'Signed in over WhatsApp as ' . (string) $admin['role']);

        // A fresh role means a fresh conversation — nothing a customer chat
        // parked (a quote, a draft) may carry over into a staff session.
        self::forgetChats($phone);

        $code = '';
        try {
            require_once INCLUDE_PATH . '/agentwallet.php';
            $code = AgentWallet::agentCodeLabel($adminId);
        } catch (Throwable $ignored) {
        }

        $lines = ['✅ नमस्ते ' . (string) $admin['full_name'] . '! तपाईं ' . self::roleLabel((string) $admin['role'])
                  . ($code !== '' ? ' (' . $code . ')' : '') . ' को रूपमा साइन इन हुनुभयो।'];
        $lines[] = 'यो लगइन ' . formatDate(substr($until, 0, 10), 'D, j M') . ' ' . substr($until, 11, 5) . ' सम्म चल्छ। बाहिर निस्कन "logout" लेख्नुहोस्।';
        $lines[] = '';
        if (in_array((string) $admin['role'], self::OFFICE, true)) {
            $lines[] = 'अब सोध्नुहोस्: "aaja kasto cha", "agent SHG-0027 ko hisab", "9876543210 ko customer", "pending payment".';
        } else {
            $lines[] = 'अब सोध्नुहोस्: "mero aaja ko hisab", "mero commission", "mero ticket haru", वा यात्रुको नाम र नम्बर पठाएर टिकट काट्नुहोस्।';
            if (Settings::getBool('wa_bulk_on', false)) {
                $lines[] = 'धेरै टिकट एकैपटक काट्न "FORMAT" लेख्नुहोस्।';
            }
        }

        return self::out(implode("\n", $lines));
    }

    private static function logout(string $phone): array
    {
        $had = self::session($phone);
        self::revokePhone($phone, null);
        self::pendingClear($phone);
        self::forgetChats($phone);
        if ($had !== null) {
            Logger::audit('staff.logout_whatsapp', 'admin', (string) $had['admin']['id'], null, ['from' => $phone], 'Signed out over WhatsApp');
            LoginLog::record((string) $had['admin']['username'], 'logout', (int) $had['admin']['id']);
            return self::out('👋 तपाईं साइन आउट हुनुभयो। फेरि स्टाफ काम गर्न "login <कोड> <पासवर्ड>" लेख्नुहोस्।');
        }

        return self::out('तपाईं WhatsApp मा साइन इन हुनुभएको थिएन। स्टाफ हुनुहुन्छ भने "login <कोड> <पासवर्ड>" लेख्नुहोस्।');
    }

    private static function whoami(string $phone): array
    {
        require_once INCLUDE_PATH . '/aitools.php';
        $ctx = AiTools::whoIs($phone);
        if (($ctx['role'] ?? 'customer') === 'customer') {
            return self::out('तपाईं यो नम्बर (' . self::mask($phone) . ') बाट ग्राहकको रूपमा हुनुहुन्छ। स्टाफ हुनुहुन्छ भने "login <कोड> <पासवर्ड>" लेख्नुहोस्।');
        }
        $admin = $ctx['admin'] ?? [];
        $code  = '';
        try {
            require_once INCLUDE_PATH . '/agentwallet.php';
            $code = AgentWallet::agentCodeLabel((int) ($ctx['adminId'] ?? 0));
        } catch (Throwable $ignored) {
        }
        $via = isset($ctx['login']) && is_array($ctx['login'])
            ? 'WhatsApp लगइन, ' . formatDate(substr((string) $ctx['login']['expires_at'], 0, 10), 'D, j M') . ' ' . substr((string) $ctx['login']['expires_at'], 11, 5) . ' सम्म'
            : 'स्टाफ रेकर्डको नम्बर';

        return self::out('👤 ' . (string) ($admin['full_name'] ?? $ctx['name'] ?? '') . ' — ' . self::roleLabel((string) ($admin['role'] ?? ''))
            . ($code !== '' ? ' (' . $code . ')' : '') . "\n" . 'पहिचान: ' . $via . '।');
    }

    /* =================================================================
     *  Sessions — read by AiTools::whoIs on every message
     * ================================================================= */

    /**
     * The live sign-in for this number, re-checked against the account,
     * or null. Touches last_seen_at at most once a minute.
     *
     * @return array{admin: array<string,mixed>, login: array<string,mixed>}|null
     */
    public static function session(string $phoneDigits): ?array
    {
        if ($phoneDigits === '' || !self::enabled()) {
            return null;
        }
        try {
            /* PHP's clock on both sides (APP_TIMEZONE), never the database's
               NOW(): on a host where MySQL runs in UTC the two differ by hours
               and a session would outlive its expiry by exactly that much. */
            $row = Database::fetch(
                'SELECT * FROM wa_logins WHERE phone = :p AND revoked_at IS NULL AND expires_at > :now
                  ORDER BY id DESC LIMIT 1',
                ['p' => $phoneDigits, 'now' => date('Y-m-d H:i:s')]
            );
        } catch (Throwable $e) {
            return null;                             // table not migrated yet
        }
        if ($row === null) {
            return null;
        }

        $admin = self::adminRow((int) $row['admin_id']);
        if ($admin === null
            || (int) $admin['is_active'] !== 1
            || (int) ($admin['must_change_pw'] ?? 1) !== 0
            || (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time())
            || !in_array((string) $admin['role'], self::ROLES, true)) {
            self::revoke((int) $row['id'], null);
            return null;
        }

        $seen = (string) ($row['last_seen_at'] ?? '');
        if ($seen === '' || strtotime($seen) < time() - 60) {
            try {
                Database::update('wa_logins', ['last_seen_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $row['id']]);
            } catch (Throwable $ignored) {
            }
        }

        return ['admin' => $admin, 'login' => $row];
    }

    /** Revoke one session (office action or self). */
    public static function revoke(int $id, ?int $by): void
    {
        try {
            Database::update('wa_logins', ['revoked_at' => date('Y-m-d H:i:s'), 'revoked_by' => $by ?: null],
                'id = :id AND revoked_at IS NULL', ['id' => $id]);
        } catch (Throwable $ignored) {
        }
    }

    /** Every live session of one number. */
    public static function revokePhone(string $phoneDigits, ?int $by): void
    {
        if ($phoneDigits === '') {
            return;
        }
        try {
            Database::update('wa_logins', ['revoked_at' => date('Y-m-d H:i:s'), 'revoked_by' => $by ?: null],
                'phone = :p AND revoked_at IS NULL', ['p' => $phoneDigits]);
        } catch (Throwable $ignored) {
        }
    }

    /** Every live session of one account — when the office deactivates it. */
    public static function revokeAdmin(int $adminId, ?int $by): void
    {
        if ($adminId <= 0) {
            return;
        }
        try {
            Database::update('wa_logins', ['revoked_at' => date('Y-m-d H:i:s'), 'revoked_by' => $by ?: null],
                'admin_id = :a AND revoked_at IS NULL', ['a' => $adminId]);
        } catch (Throwable $ignored) {
        }
    }

    /**
     * Live sessions for the office screen, newest first.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function active(int $limit = 100): array
    {
        try {
            return Database::fetchAll(
                'SELECT w.*, a.full_name, a.username, a.role
                   FROM wa_logins w LEFT JOIN admins a ON a.id = w.admin_id
                  WHERE w.revoked_at IS NULL AND w.expires_at > :now
                  ORDER BY w.id DESC LIMIT ' . max(1, min(500, $limit)),
                ['now' => date('Y-m-d H:i:s')]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /* =================================================================
     *  Plumbing
     * ================================================================= */

    /**
     * The account behind an id typed on a phone: an agent code (SHG-027,
     * shg27, 27 when it looks like one), an email, or a username.
     *
     * @return array<string,mixed>|null
     */
    private static function resolve(string $id): ?array
    {
        $id = Security::clean($id, 191);
        if ($id === '') {
            return null;
        }
        $cols = 'id, username, full_name, email, phone, role, password_hash, is_active, locked_until, failed_logins, must_change_pw';

        // Agent code first: "SHG-0027", "shg27".
        if (preg_match('/^shg[-\s]*0*(\d{1,4})$/i', $id, $m) === 1) {
            require_once INCLUDE_PATH . '/agentwallet.php';
            $adminId = AgentWallet::adminForAgentCode((int) $m[1]);
            return $adminId !== null
                ? Database::fetch("SELECT $cols FROM admins WHERE id = :id LIMIT 1", ['id' => $adminId])
                : null;
        }
        if (str_contains($id, '@')) {
            $email = Security::email($id);
            return $email === ''
                ? null
                : Database::fetch("SELECT $cols FROM admins WHERE LOWER(email) = :e ORDER BY id LIMIT 1", ['e' => $email]);
        }
        // A bare number is an agent code too (owner: "SHG-027 / 27").
        if (preg_match('/^\d{1,4}$/', $id) === 1) {
            require_once INCLUDE_PATH . '/agentwallet.php';
            $adminId = AgentWallet::adminForAgentCode((int) $id);
            if ($adminId !== null) {
                return Database::fetch("SELECT $cols FROM admins WHERE id = :id LIMIT 1", ['id' => $adminId]);
            }
        }

        return Database::fetch("SELECT $cols FROM admins WHERE LOWER(username) = :u LIMIT 1", ['u' => mb_strtolower($id)]);
    }

    private static function adminRow(int $adminId): ?array
    {
        if ($adminId <= 0) {
            return null;
        }
        return Database::fetch(
            'SELECT id, username, full_name, email, phone, role, is_active, locked_until, must_change_pw, permissions
               FROM admins WHERE id = :id LIMIT 1',
            ['id' => $adminId]
        );
    }

    private static function pendingSet(string $phone, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $done = Database::update('kv_store', ['kvalue' => $json, 'updated_by' => 'walogin'],
            'kscope = :s AND kkey = :k', ['s' => self::PENDING_SCOPE, 'k' => $phone]);
        if ($done === 0) {
            Database::insertIgnore('kv_store', ['kscope' => self::PENDING_SCOPE, 'kkey' => $phone, 'kvalue' => $json, 'updated_by' => 'walogin']);
        }
    }

    private static function pendingGet(string $phone): ?array
    {
        try {
            $row = Database::fetch('SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k',
                ['s' => self::PENDING_SCOPE, 'k' => $phone]);
        } catch (Throwable $e) {
            return null;
        }
        $v = $row !== null ? json_decode((string) $row['kvalue'], true) : null;
        if (!is_array($v) || (time() - (int) ($v['at'] ?? 0)) > self::PENDING_TTL) {
            self::pendingClear($phone);
            return null;
        }
        return $v;
    }

    private static function pendingClear(string $phone): void
    {
        try {
            Database::delete('kv_store', 'kscope = :s AND kkey = :k', ['s' => self::PENDING_SCOPE, 'k' => $phone]);
        } catch (Throwable $ignored) {
        }
    }

    /** Drop whatever conversation state this number had as its old self. */
    private static function forgetChats(string $phone): void
    {
        try {
            require_once INCLUDE_PATH . '/aiagent.php';
            AiAgent::forget($phone);
        } catch (Throwable $ignored) {
        }
        try {
            if (!class_exists('WaBooking')) {
                require_once INCLUDE_PATH . '/wabooking.php';
            }
            WaBooking::clear($phone);
        } catch (Throwable $ignored) {
        }
        try {
            Database::delete('kv_store', 'kscope = :s AND kkey = :k', ['s' => 'wa_bulk', 'k' => $phone]);
        } catch (Throwable $ignored) {
        }
    }

    private static function mentionsSecret(array $cmd): bool
    {
        return ($cmd['cmd'] ?? '') === 'login';
    }

    /** "9876543210" → "98xxxxxx10": enough to recognise, not enough to dial. */
    public static function mask(string $digits): string
    {
        $n = strlen($digits);
        return $n <= 4 ? $digits : substr($digits, 0, 2) . str_repeat('x', max(0, $n - 4)) . substr($digits, -2);
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'agent'      => 'एजेन्ट',
            'counter'    => 'काउन्टर स्टाफ',
            'manager'    => 'म्यानेजर',
            'superadmin' => 'सुपर एडमिन',
            default      => 'स्टाफ',
        };
    }

    /** @return array{text: string, media: ?string} */
    private static function out(string $text, ?string $media = null): array
    {
        return ['text' => $text, 'media' => $media];
    }
}
