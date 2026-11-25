<?php
/**
 * =====================================================================
 *  includes/googleoauth.php — GoogleOAuth: "Google बाट Login गर्नुहोस् (browser जस्तै)"
 *  for a bank Gmail box (25 Nov 2026, mp2 "bank Gmail").
 *
 *  The OAuth 2.0 web flow, read-only Gmail (scope gmail.readonly - nothing in
 *  the mailbox can ever change), with PKCE S256 on top of the client secret:
 *    start()  - super-admin presses the button on Payment Management ->
 *               admin/gmail-oauth.php?start=1&country=NP|IN[&account=<id>]: a random
 *               state (bin2hex 16) + a PKCE verifier go into the session with the
 *               country / account and a 10-minute expiry, single use; the browser goes
 *               to accounts.google.com (access_type=offline, prompt=consent, so Google
 *               hands back a refresh token; login_hint on a reconnect).
 *    finish() - Google sends the browser back to admin/gmail-oauth.php: the state must
 *               match (constant-time) and not be expired, it is consumed either way;
 *               the code is swapped at oauth2.googleapis.com/token (with the verifier);
 *               a refresh token and the gmail.readonly scope are REQUIRED; the box's
 *               address comes from gmail/v1/users/me/profile; the row is stored with both
 *               tokens sealed (SecretBox). Tokens are never echoed or logged.
 *    accessToken() - for the reader: the sealed access token while it has more than
 *               60 s left, else a refresh (grant_type=refresh_token). invalid_grant (the
 *               owner removed the app's access, or Google expired it) marks the box
 *               "Google access removed - reconnect" and stops.
 *
 *  Settings (super-admin only, AiOps prefix 'google_oauth_'): google_oauth_client_id,
 *  google_oauth_client_secret (a secret: never shown back), google_oauth_redirect
 *  (blank = https://<the .network admin host>/admin/gmail-oauth.php, OpsHub::staffUrl).
 *
 *  Every HTTP call goes through request(): https only, the three Google hosts only
 *  (hard-coded), 15 s timeouts, no redirects followed. GoogleOAuth::$http is a test
 *  hook (callable(string $method, string $url, array $form, array $headers): array
 *  {http:int, body?:string, json?:array}) so tests never touch the network.
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/secretbox.php';
require_once __DIR__ . '/bankmailaccounts.php';

/** The box's Google access is gone: the owner must press "फेरि जोड्नुहोस्" (reconnect). */
final class GoogleReconnect extends RuntimeException
{
}

final class GoogleOAuth
{
    public const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    public const GMAIL_BASE = 'https://gmail.googleapis.com/gmail/v1/users/me/';
    public const SCOPE      = 'https://www.googleapis.com/auth/gmail.readonly';
    public const HOSTS      = ['accounts.google.com', 'oauth2.googleapis.com', 'gmail.googleapis.com'];
    public const SESSION_KEY = 'shg_gmail_oauth';
    public const STATE_TTL  = 600;
    public const RECONNECT  = 'Google access removed - reconnect';

    /** @var callable|null test hook, see the file header */
    public static $http = null;

    public static function clientId(): string
    {
        return trim(Settings::getString('google_oauth_client_id', ''));
    }

    private static function clientSecret(): string
    {
        return trim(Settings::getString('google_oauth_client_secret', ''));
    }

    /** Client id + secret saved: the "Google बाट Login" button can be shown. */
    public static function configured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    /** The exact "Authorized redirect URI" to paste into Google Cloud. */
    public static function redirectUri(): string
    {
        $r = trim(Settings::getString('google_oauth_redirect', ''));
        if ($r !== '') {
            return $r;
        }
        if (!class_exists('OpsHub')) {
            require_once __DIR__ . '/opshub.php';
        }
        return OpsHub::staffUrl('admin/gmail-oauth.php');
    }

    /**
     * 25 Nov 2026 (mp4): the client_secret_….json file Google gives when the OAuth
     * client is made - {"web": {client_id, client_secret, redirect_uris}} (or
     * "installed"). Pure: nothing is stored here (the page saves id + secret through
     * the settings path; the file itself is never kept). 'warn' is set when our
     * redirect URI is not one of the file's redirect_uris (Google would refuse the login).
     *
     * @return array{ok: bool, error: string, warn: string, type: string, client_id: string, client_secret: string, redirect_uris: list<string>, redirect_ok: bool}
     */
    public static function parseClientJson(string $raw, string $ourRedirect): array
    {
        $out = ['ok' => false, 'error' => '', 'warn' => '', 'type' => '', 'client_id' => '', 'client_secret' => '', 'redirect_uris' => [], 'redirect_ok' => false];
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 64 * 1024) {
            $out['error'] = 'JSON file छान्नुहोस् (64 KB सम्म) · choose the client_secret JSON file';
            return $out;
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            $out['error'] = 'यो JSON होइन · not a valid JSON file';
            return $out;
        }
        $type = isset($j['web']) && is_array($j['web']) ? 'web' : (isset($j['installed']) && is_array($j['installed']) ? 'installed' : '');
        if ($type === '') {
            $out['error'] = 'Google OAuth client को file होइन ("web" / "installed" छैन) · not a Google OAuth client file';
            return $out;
        }
        $c   = $j[$type];
        $cid = trim((string) ($c['client_id'] ?? ''));
        $sec = trim((string) ($c['client_secret'] ?? ''));
        if (preg_match('/^[A-Za-z0-9\-_.]{6,200}\.apps\.googleusercontent\.com$/', $cid) !== 1) {
            $out['error'] = 'Client ID मिलेन (…apps.googleusercontent.com) · client_id missing or wrong';
            return $out;
        }
        if (preg_match('/^[A-Za-z0-9\-_]{10,120}$/', $sec) !== 1) {
            $out['error'] = 'Client Secret मिलेन (जस्तै GOCSPX-…) · client_secret missing or wrong';
            return $out;
        }
        $uris = [];
        foreach ((array) ($c['redirect_uris'] ?? []) as $u) {
            if (is_string($u) && trim($u) !== '') {
                $uris[] = trim($u);
            }
        }
        $norm = static fn(string $u): string => rtrim(strtolower($u), '/');
        $okUri = $ourRedirect !== '' && in_array($norm($ourRedirect), array_map($norm, $uris), true);
        $out = ['ok' => true, 'error' => '', 'warn' => '', 'type' => $type, 'client_id' => $cid, 'client_secret' => $sec, 'redirect_uris' => $uris, 'redirect_ok' => $okUri];
        if ($type === 'installed') {
            $out['warn'] = '⚠️ यो "Desktop app" client हो — Google Cloud मा "Web application" client बनाउनुहोस्, नत्र login हुँदैन · make a Web application client.';
        } elseif (!$okUri) {
            $out['warn'] = '⚠️ Redirect URI मिलेन — Google Cloud → Clients → यो client → Authorized redirect URIs मा ठ्याक्कै यो थप्नुहोस्: ' . $ourRedirect
                . ($uris !== [] ? ' (file मा: ' . implode(', ', array_slice($uris, 0, 3)) . ')' : ' (file मा कुनै redirect URI छैन)');
        }
        return $out;
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /* =================================================================
     *  HTTP (one door, test hook)
     * ================================================================= */

    /**
     * @param array<string,string>|null $form x-www-form-urlencoded body (POST)
     * @return array{http:int, json:array<string,mixed>, body:string}
     */
    public static function request(string $method, string $url, ?array $form = null, ?string $bearer = null): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !in_array(strtolower((string) ($parts['host'] ?? '')), self::HOSTS, true) || isset($parts['port']) || isset($parts['user'])) {
            throw new RuntimeException('Only Google HTTPS endpoints are called.');
        }
        $headers = ['Accept: application/json'];
        if ($bearer !== null && $bearer !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        if (self::$http !== null) {
            $r = (self::$http)(strtoupper($method), $url, $form ?? [], $headers);
            $body = is_array($r) ? (string) ($r['body'] ?? (isset($r['json']) ? json_encode($r['json']) : '')) : '';
            $j = is_array($r) && isset($r['json']) && is_array($r['json']) ? $r['json'] : json_decode($body, true);
            return ['http' => is_array($r) ? (int) ($r['http'] ?? 0) : 0, 'json' => is_array($j) ? $j : [], 'body' => $body];
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP curl is missing.');
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($form !== null) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Google could not be reached (' . mb_substr($err, 0, 120) . ').');
        }
        $j = json_decode((string) $raw, true);
        return ['http' => $http, 'json' => is_array($j) ? $j : [], 'body' => (string) $raw];
    }

    /** Google's own error words, made safe to show (never a token: they are not in error fields). */
    private static function googleError(array $j, int $http): string
    {
        $e = $j['error'] ?? '';
        if (is_array($e)) {   // the Gmail API shape {error:{code,message,status}}
            $msg = (string) ($e['message'] ?? ($e['status'] ?? ''));
        } else {
            $msg = trim((string) $e . (isset($j['error_description']) ? ': ' . (string) $j['error_description'] : ''));
        }
        $msg = mb_substr((string) preg_replace('/[^\p{L}\p{N} .,:;()\/_\-\'"]/u', '', $msg), 0, 160);
        return $msg !== '' ? $msg . ' (HTTP ' . $http . ')' : 'HTTP ' . $http;
    }

    /* =================================================================
     *  the sign-in
     * ================================================================= */

    /**
     * The Google sign-in address for a new box ($account null) or a reconnect. The state +
     * PKCE verifier go into $store (the session) for 10 minutes, single use.
     *
     * @param array<string,mixed>|null $account the box to reconnect
     * @param array<string,mixed>      $store   $_SESSION
     */
    public static function start(string $country, ?array $account, array &$store, int $adminId): string
    {
        if (!self::configured()) {
            throw new RuntimeException('Google Client ID र Client Secret पहिले सेभ गर्नुहोस् · save the Google client first.');
        }
        $country  = strtoupper($country) === 'IN' ? 'IN' : 'NP';
        $state    = bin2hex(random_bytes(16));
        $verifier = self::b64url(random_bytes(48));   // 64 characters (RFC 7636: 43-128)
        $store[self::SESSION_KEY] = [
            'state' => $state, 'verifier' => $verifier, 'country' => $country,
            'account' => $account !== null ? (int) $account['id'] : 0, 'admin' => $adminId, 'exp' => time() + self::STATE_TTL,
        ];
        $q = [
            'client_id'              => self::clientId(),
            'redirect_uri'           => self::redirectUri(),
            'response_type'          => 'code',
            'scope'                  => self::SCOPE,
            'access_type'            => 'offline',
            /* consent = Google hands back a refresh token every time; select_account = the owner picks
               (or adds) the bank's Gmail even when another Google account is signed in in this browser */
            'prompt'                 => $account !== null ? 'consent' : 'select_account consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
            'code_challenge'         => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method'  => 'S256',
        ];
        if ($account !== null && (string) ($account['email'] ?? '') !== '') {
            $q['login_hint'] = (string) $account['email'];
        }
        return self::AUTH_URL . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Google sent the browser back: check the state, swap the code, store the box.
     *
     * @param array<string,mixed> $get   $_GET
     * @param array<string,mixed> $store $_SESSION
     * @return array{ok:bool, msg:string, country:string, account_id:int}
     */
    public static function finish(array $get, array &$store, int $adminId): array
    {
        $p = $store[self::SESSION_KEY] ?? null;
        unset($store[self::SESSION_KEY]);   // single use: consumed whatever happens next
        $country = is_array($p) && ($p['country'] ?? '') === 'IN' ? 'IN' : 'NP';
        $fail = static fn(string $m): array => ['ok' => false, 'msg' => $m, 'country' => $country, 'account_id' => 0];
        $state = is_string($get['state'] ?? null) ? (string) $get['state'] : '';
        if (!is_array($p) || $state === '' || !is_string($p['state'] ?? null) || !hash_equals((string) $p['state'], $state)) {
            return $fail('यो Google जवाफ यो session को होइन वा पहिल्यै प्रयोग भइसक्यो — फेरि "Google बाट Login" थिच्नुहोस् · the sign-in did not match this session (or was used already).');
        }
        if ((int) ($p['exp'] ?? 0) < time()) {
            return $fail('१० मिनेट नाघ्यो — फेरि "Google बाट Login" थिच्नुहोस् · the sign-in took longer than 10 minutes.');
        }
        if ((int) ($p['admin'] ?? -1) !== $adminId) {
            return $fail('अर्कै admin ले सुरु गरेको login हो — फेरि सुरु गर्नुहोस् · started by another admin.');
        }
        if (isset($get['error'])) {
            $err = mb_substr((string) preg_replace('/[^A-Za-z0-9_\-]/', '', is_string($get['error']) ? $get['error'] : ''), 0, 60);
            return $fail($err === 'access_denied'
                ? 'Google मा "Allow / Continue" थिच्नुभएन — केही जोडिएन · access denied, nothing was connected.'
                : 'Google ले भन्यो: ' . ($err !== '' ? $err : 'error') . ' — केही जोडिएन · nothing was connected.');
        }
        $code = is_string($get['code'] ?? null) ? (string) $get['code'] : '';
        if ($code === '' || strlen($code) > 2048) {
            return $fail('Google बाट code आएन — फेरि Login गर्नुहोस् · no authorization code.');
        }
        if (!SecretBox::available()) {
            return $fail(SecretBox::whyNot());
        }
        if (!BankMailAccounts::ready()) {
            return $fail('Migration upgrade-2026-11-25-mp2-bank-gmail.sql बाँकी · run the migration first.');
        }
        $old = null;
        if ((int) ($p['account'] ?? 0) > 0) {
            $old = BankMailAccounts::get((int) $p['account']);
            if ($old === null) {
                return $fail('फेरि जोड्ने खाता अब छैन · the box to reconnect is gone.');
            }
        }
        try {
            $t = self::request('POST', self::TOKEN_URL, [
                'client_id' => self::clientId(), 'client_secret' => self::clientSecret(), 'code' => $code,
                'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code', 'code_verifier' => (string) ($p['verifier'] ?? ''),
            ]);
        } catch (Throwable $e) {
            return $fail('Google सँग कुरा भएन · ' . mb_substr($e->getMessage(), 0, 160));
        }
        $j = $t['json'];
        $access = is_string($j['access_token'] ?? null) ? (string) $j['access_token'] : '';
        if ($t['http'] !== 200 || $access === '') {
            return $fail('Google token मिलेन · ' . self::googleError($j, $t['http']) . ' — Client ID / Secret र redirect URI जाँच्नुहोस्।');
        }
        $refresh = is_string($j['refresh_token'] ?? null) ? (string) $j['refresh_token'] : '';
        if ($refresh === '') {
            return $fail('Google ले refresh token दिएन। myaccount.google.com/permissions मा गएर यो app को access हटाउनुहोस् (Remove access), अनि फेरि "Google बाट Login" गर्नुहोस्। · Google gave no refresh token: remove this app at myaccount.google.com/permissions and sign in again.');
        }
        $scopes = preg_split('/\s+/', trim((string) ($j['scope'] ?? ''))) ?: [];
        if (!in_array(self::SCOPE, $scopes, true)) {
            return $fail('Gmail पढ्ने अनुमति (gmail.readonly) दिइएन — Login गर्दा "View your email messages" मा टिक लगाउनुहोस् · the Gmail read permission was not granted.');
        }
        try {
            $prof = self::request('GET', self::GMAIL_BASE . 'profile', null, $access);
        } catch (Throwable $e) {
            return $fail('Gmail profile पढ्न सकिएन · ' . mb_substr($e->getMessage(), 0, 160));
        }
        $email = strtolower(trim(is_string($prof['json']['emailAddress'] ?? null) ? (string) $prof['json']['emailAddress'] : ''));
        if ($prof['http'] !== 200 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $fail('Gmail profile पढ्न सकिएन · ' . self::googleError($prof['json'], $prof['http']) . ' — Google Cloud मा "Gmail API" Enable छ?');
        }
        if ($old !== null && strtolower((string) $old['email']) !== $email) {
            return $fail('यो खाता ' . $old['email'] . ' को लागि हो, तर Google मा ' . $email . ' छानियो — सही Gmail छानेर फेरि जोड्नुहोस् · wrong Google account chosen.');
        }
        if ($old !== null) {
            $country = (string) $old['country'] === 'IN' ? 'IN' : 'NP';
        }
        $expires = (int) ($j['expires_in'] ?? 3600);
        try {
            $id = BankMailAccounts::save([
                'country' => $country, 'email' => $email, 'method' => 'google',
                'secret_enc' => SecretBox::seal($refresh, 'bankmail'), 'access_enc' => SecretBox::seal($access, 'bankmail'),
                'access_expires_at' => date('Y-m-d H:i:s', time() + max(60, min(86400, $expires)) - 30),
            ], $adminId);
        } catch (Throwable $e) {
            return $fail(mb_substr($e->getMessage(), 0, 200));
        }
        BankMailAccounts::note($id, true, 'Google login OK');
        Logger::audit('bank_mail.google_connect', 'bank_mail_account', (string) $id, null,
            ['country' => $country, 'email' => $email, 'method' => 'google', 'reconnect' => $old !== null],
            $old !== null ? 'Bank Gmail reconnected with Google sign-in' : 'Bank Gmail added with Google sign-in');
        $acct = BankMailAccounts::get($id) ?? [];
        return ['ok' => true, 'country' => $country, 'account_id' => $id,
            'msg' => '✅ ' . $email . ' जोडियो · connected (Google login, read-only).'
                . (BankMailAccounts::isReady($acct) ? '' : ' अब "Edit" मा बैंकको DKIM domain र हाम्रो खाताको अन्तिम ४–६ अंक राख्नुहोस् — त्यसपछि मात्र सुझाव आउँछ।')];
    }

    /* =================================================================
     *  tokens for the reader
     * ================================================================= */

    /**
     * A usable access token for a Google box (refreshed when < 60 s are left).
     *
     * @param array<string,mixed> $a the bank_mail_accounts row
     * @throws GoogleReconnect when Google withdrew the access (the box is marked)
     */
    public static function accessToken(array $a): string
    {
        $id  = (int) $a['id'];
        $exp = !empty($a['access_expires_at']) ? (int) strtotime((string) $a['access_expires_at']) : 0;
        $acc = SecretBox::open(isset($a['access_enc']) ? (string) $a['access_enc'] : null, 'bankmail');
        if ($acc !== '' && $exp - time() > 60) {
            return $acc;
        }
        $refresh = BankMailAccounts::secret($a);
        if ($refresh === '') {
            BankMailAccounts::note($id, false, self::RECONNECT);
            throw new GoogleReconnect(self::RECONNECT);
        }
        if (!self::configured()) {
            throw new RuntimeException('Google Client ID / Secret सेभ छैन · the Google client is not saved.');
        }
        $r = self::request('POST', self::TOKEN_URL, [
            'client_id' => self::clientId(), 'client_secret' => self::clientSecret(),
            'refresh_token' => $refresh, 'grant_type' => 'refresh_token',
        ]);
        $err = is_string($r['json']['error'] ?? null) ? (string) $r['json']['error'] : '';
        if ($err === 'invalid_grant') {
            Database::update('bank_mail_accounts', ['access_enc' => null, 'access_expires_at' => null], 'id = :i', ['i' => $id]);
            BankMailAccounts::note($id, false, self::RECONNECT);
            Logger::warning('BankMail: Google access withdrawn', ['account' => $id], 'payment');
            throw new GoogleReconnect(self::RECONNECT);
        }
        $new = is_string($r['json']['access_token'] ?? null) ? (string) $r['json']['access_token'] : '';
        if ($r['http'] !== 200 || $new === '') {
            throw new RuntimeException('Google token refresh failed · ' . self::googleError($r['json'], $r['http']));
        }
        $set = [
            'access_enc' => SecretBox::seal($new, 'bankmail'),
            'access_expires_at' => date('Y-m-d H:i:s', time() + max(60, min(86400, (int) ($r['json']['expires_in'] ?? 3600))) - 30),
        ];
        if (is_string($r['json']['refresh_token'] ?? null) && $r['json']['refresh_token'] !== '') {
            $set['secret_enc'] = SecretBox::seal((string) $r['json']['refresh_token'], 'bankmail');   // Google rotated it
        }
        Database::update('bank_mail_accounts', $set, 'id = :i', ['i' => $id]);
        return $new;
    }

    /** Best effort: tell Google to forget a token (the box is being removed). */
    public static function revoke(array $a): void
    {
        $tok = BankMailAccounts::secret($a);
        if ($tok === '') {
            return;
        }
        try {
            self::request('POST', self::REVOKE_URL, ['token' => $tok]);
        } catch (Throwable $e) {
            // removed here anyway; the owner can also remove it at myaccount.google.com/permissions
        }
    }
}
