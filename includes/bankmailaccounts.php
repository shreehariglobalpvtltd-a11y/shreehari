<?php
/**
 * =====================================================================
 *  includes/bankmailaccounts.php — BankMailAccounts: the bank Gmail boxes the
 *  owner adds from Payment Management (25 Nov 2026, mp2 "bank Gmail").
 *
 *  Owner: "bank ko lagi gmail account add garxu admin panel bataoi login garn
 *  milos browser jasto ... tesma update haru sab aos; indian ko laini credential
 *  naaya samma same system hos".
 *
 *  One row per box (database/upgrade-2026-11-25-mp2-bank-gmail.sql):
 *    country NP -> NPR alerts (Global IME), IN -> INR alerts (until the India bank
 *    API credentials come); method 'google' (Google sign-in, gmail.readonly - the
 *    refresh token sealed in secret_enc) or 'app_password' (IMAP, the app password
 *    sealed in secret_enc). Secrets go through SecretBox only - never plaintext.
 *
 *  Each box carries ITS OWN rules - BankMail::parse / handle and PayCredits read
 *  these, never the global bankmail_* settings, for a credit from a box:
 *    senders      allowed From addresses / @domains (comma list). Blank = any sender
 *                 whose domain is the DKIM domain (DKIM must still pass for it);
 *    dkim_domain  the bank's signing domain;
 *    account_hint the last 4-6 digits of OUR bank account.
 *  A box is READY only with a DKIM domain AND our account digits; until then it
 *  reads and logs what arrives ("अधुरो · setup incomplete") but stores no credit
 *  and makes no suggestion.
 *
 *  bank_mail_log = the box's "updates" feed (every mail looked at + what became of
 *  it); the newest LOG_KEEP rows per box are kept.
 *
 *  The old single-box settings (bankmail_user + bankmail_app_password) become
 *  account #1 (NP) ONCE, sealed, when no account exists yet (migrateLegacy) - from
 *  then on they are read only through that account.
 *
 *  Quietly empty before the migration ran (ready() false).
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/secretbox.php';

final class BankMailAccounts
{
    public const LOG_KEEP = 200;
    /** The marker that the old single-box settings were looked at (account id, or 0 = skipped). */
    public const LEGACY_KEY = 'bankmail_legacy_account';
    /** What a box without its rules says: it is NOT read until DKIM domain + account digits are saved. */
    public const NOT_READY_MSG = 'अधुरो — Edit मा DKIM domain + खाताको अन्तिम अंक राख्नुहोस्; त्यसपछि मात्र mail पढिन्छ · setup incomplete, not reading yet';
    public const OUTCOMES = ['suggestion', 'suggestion-amount', 'confirmed', 'unmatched', 'duplicate', 'debit-ignored',
                             'not-credit', 'not-bank-sender', 'setup-incomplete', 'unreadable', 'gone'];

    private static ?bool $ready = null;
    private static ?bool $logReady = null;
    private static ?bool $creditCols = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Database::fetch("SHOW TABLES LIKE 'bank_mail_accounts'") !== null;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function logReady(): bool
    {
        if (self::$logReady === null) {
            try {
                self::$logReady = Database::fetch("SHOW TABLES LIKE 'bank_mail_log'") !== null;
            } catch (Throwable $e) {
                self::$logReady = false;
            }
        }
        return self::$logReady;
    }

    /** bank_credits.mail_account_id + match_kind are there (the mp2 migration). */
    public static function creditCols(): bool
    {
        if (self::$creditCols === null) {
            try {
                self::$creditCols = Database::fetch("SHOW COLUMNS FROM bank_credits LIKE 'mail_account_id'") !== null
                    && Database::fetch("SHOW COLUMNS FROM bank_credits LIKE 'match_kind'") !== null;
            } catch (Throwable $e) {
                self::$creditCols = false;
            }
        }
        return self::$creditCols;
    }

    public static function flush(): void
    {
        self::$ready = null;
        self::$logReady = null;
        self::$creditCols = null;
    }

    public static function currencyFor(string $country): string
    {
        return strtoupper($country) === 'IN' ? 'INR' : 'NPR';
    }

    /** The Payment Management tab for a country / currency. */
    public static function tabFor(string $countryOrCurrency): string
    {
        return in_array(strtoupper($countryOrCurrency), ['IN', 'INR'], true) ? 'in' : 'np';
    }

    /* =================================================================
     *  reading
     * ================================================================= */

    /** @return list<array<string,mixed>> */
    public static function all(?string $country = null): array
    {
        if (!self::ready()) {
            return [];
        }
        if ($country !== null) {
            return Database::fetchAll('SELECT * FROM bank_mail_accounts WHERE country = :c ORDER BY id', ['c' => strtoupper($country) === 'IN' ? 'IN' : 'NP']);
        }
        return Database::fetchAll('SELECT * FROM bank_mail_accounts ORDER BY id');
    }

    /** @return list<array<string,mixed>> */
    public static function active(): array
    {
        return self::ready() ? Database::fetchAll('SELECT * FROM bank_mail_accounts WHERE is_active = 1 ORDER BY id') : [];
    }

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        return self::ready() && $id > 0 ? Database::fetch('SELECT * FROM bank_mail_accounts WHERE id = :i', ['i' => $id]) : null;
    }

    /** Our account's last digits for this box ('' = not saved / fewer than 4). */
    public static function hint(array $a): string
    {
        $d = (string) preg_replace('/\D/', '', (string) ($a['account_hint'] ?? ''));
        return strlen($d) >= 4 ? substr($d, -6) : '';
    }

    public static function dkimDomain(array $a): string
    {
        return strtolower(trim((string) ($a['dkim_domain'] ?? '')));
    }

    /** @return list<string> lower-case addresses / @domains */
    public static function senders(array $a): array
    {
        return array_values(array_filter(array_map(static fn(string $s): string => strtolower(trim($s)), explode(',', (string) ($a['senders'] ?? '')))));
    }

    /** A DKIM domain AND our account digits: the box may store credits and make suggestions. */
    public static function isReady(array $a): bool
    {
        return self::dkimDomain($a) !== '' && self::hint($a) !== '';
    }

    /**
     * Is $from one of this box's bank senders? null = the box has no rule yet (no senders,
     * no DKIM domain). Blank senders = any address whose domain is the DKIM domain or below it.
     */
    public static function senderRule(array $a, string $from): ?bool
    {
        $from = strtolower(trim($from));
        $list = self::senders($a);
        $dk   = self::dkimDomain($a);
        if ($list === [] && $dk === '') {
            return null;
        }
        if ($from === '' || !str_contains($from, '@')) {
            return false;
        }
        if ($list === []) {
            $dom = substr($from, (int) strrpos($from, '@') + 1);
            return $dom === $dk || str_ends_with($dom, '.' . $dk);
        }
        foreach ($list as $s) {
            if ($s[0] === '@' ? str_ends_with($from, $s) : $from === $s) {
                return true;
            }
        }
        return false;
    }

    public static function senderAllowed(array $a, string $from): bool
    {
        return self::senderRule($a, $from) === true;
    }

    /** The decrypted secret (app password / Google refresh token), '' when it cannot be opened. */
    public static function secret(array $a): string
    {
        return SecretBox::open(isset($a['secret_enc']) ? (string) $a['secret_enc'] : null, 'bankmail');
    }

    public static function methodLabel(array $a): string
    {
        return (string) ($a['method'] ?? '') === 'google' ? 'Google login' : 'App password';
    }

    /** Credits this box stored today. */
    public static function creditsToday(int $id): int
    {
        if (!self::creditCols()) {
            return 0;
        }
        return (int) Database::scalar('SELECT COUNT(*) FROM bank_credits WHERE mail_account_id = :a AND created_at >= :d',
            ['a' => $id, 'd' => date('Y-m-d') . ' 00:00:00'], 0);
    }

    /** @return list<array<string,mixed>> the newest mails this box looked at */
    public static function recentLog(int $id, int $n = 20): array
    {
        if (!self::logReady()) {
            return [];
        }
        $n = max(1, min(200, $n));
        return Database::fetchAll("SELECT * FROM bank_mail_log WHERE account_id = :a ORDER BY id DESC LIMIT $n", ['a' => $id]);
    }

    /**
     * Which of $refs this box already logged.
     *
     * @param list<string> $refs
     * @return array<string,bool>
     */
    public static function seen(int $id, array $refs): array
    {
        if (!self::logReady() || $refs === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values(array_unique($refs)), 200) as $chunk) {
            $ph = [];
            $pa = ['a' => $id];
            foreach ($chunk as $i => $r) {
                $ph[] = ':r' . $i;
                $pa['r' . $i] = (string) $r;
            }
            foreach (Database::fetchAll('SELECT msg_ref FROM bank_mail_log WHERE account_id = :a AND msg_ref IN (' . implode(',', $ph) . ')', $pa) as $row) {
                $out[(string) $row['msg_ref']] = true;
            }
        }
        return $out;
    }

    /* =================================================================
     *  writing
     * ================================================================= */

    /**
     * Add a box or update the one with the same (country, email): method + sealed secrets
     * (+ rules when given). Rules / pause state of an existing box are kept unless passed.
     *
     * @param array<string,mixed> $row country, email, method, secret_enc, access_enc, access_expires_at,
     *                                 label, senders, dkim_domain, account_hint
     */
    public static function save(array $row, int $adminId): int
    {
        if (!self::ready()) {
            throw new RuntimeException('Migration upgrade-2026-11-25-mp2-bank-gmail.sql बाँकी · run the migration first.');
        }
        $country = strtoupper((string) ($row['country'] ?? 'NP')) === 'IN' ? 'IN' : 'NP';
        $email   = strtolower(trim((string) ($row['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            throw new RuntimeException('Gmail ठेगाना मिलेन · not an e-mail address.');
        }
        $method = (string) ($row['method'] ?? '') === 'google' ? 'google' : 'app_password';
        $set = [
            'method'            => $method,
            'secret_enc'        => $row['secret_enc'] ?? null,
            'access_enc'        => $row['access_enc'] ?? null,
            'access_expires_at' => $row['access_expires_at'] ?? null,
        ];
        foreach (['label' => 80, 'senders' => 500, 'dkim_domain' => 120, 'account_hint' => 12] as $k => $max) {
            if (array_key_exists($k, $row)) {
                $v = mb_substr(trim((string) $row[$k]), 0, $max);
                $set[$k] = $v !== '' ? $v : null;
            }
        }
        $old = Database::fetch('SELECT id FROM bank_mail_accounts WHERE country = :c AND email = :e', ['c' => $country, 'e' => $email]);
        if ($old !== null) {
            Database::update('bank_mail_accounts', $set + ['last_ok' => null, 'last_result' => null], 'id = :i', ['i' => (int) $old['id']]);
            return (int) $old['id'];
        }
        return (int) Database::insert('bank_mail_accounts', $set + [
            'country' => $country, 'currency' => self::currencyFor($country), 'email' => $email, 'is_active' => 1,
            'created_by' => $adminId > 0 ? $adminId : null, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $rules label, senders, dkim_domain, account_hint (already validated) */
    public static function updateRules(int $id, array $rules): void
    {
        $set = [];
        foreach (['label' => 80, 'senders' => 500, 'dkim_domain' => 120, 'account_hint' => 12] as $k => $max) {
            if (array_key_exists($k, $rules)) {
                $v = mb_substr(trim((string) $rules[$k]), 0, $max);
                $set[$k] = $v !== '' ? $v : null;
            }
        }
        if ($set !== []) {
            Database::update('bank_mail_accounts', $set, 'id = :i', ['i' => $id]);
        }
    }

    /**
     * The box has just become READY (Edit -> Save): read its recent mail again from the start
     * (the last 2-3 days), so an alert that came while it had no rules is not lost. Clears the
     * position and the 'setup-incomplete' log rows; the credits ledger dedupes by message id + txn.
     */
    public static function rereadIncomplete(int $id): void
    {
        Database::update('bank_mail_accounts', ['last_uid' => null, 'uidvalidity' => null, 'last_seen_ms' => null], 'id = :i', ['i' => $id]);
        if (self::logReady()) {
            Database::delete('bank_mail_log', "account_id = :i AND outcome = 'setup-incomplete'", ['i' => $id]);
        }
    }

    public static function setActive(int $id, bool $on): void
    {
        Database::update('bank_mail_accounts', ['is_active' => $on ? 1 : 0], 'id = :i', ['i' => $id]);
    }

    /** The box, its sealed tokens and its mail log go; the credits it stored stay in the ledger. */
    public static function remove(int $id): void
    {
        Database::delete('bank_mail_accounts', 'id = :i', ['i' => $id]);
        if (self::logReady()) {
            Database::delete('bank_mail_log', 'account_id = :i', ['i' => $id]);
        }
    }

    /** The result of the last check, for the panel. */
    public static function note(int $id, bool $ok, string $msg, array $extra = []): void
    {
        try {
            Database::update('bank_mail_accounts', [
                'last_poll_at' => date('Y-m-d H:i:s'), 'last_ok' => $ok ? 1 : 0,
                'last_result' => self::clean($msg, 255),
            ] + $extra, 'id = :i', ['i' => $id]);
        } catch (Throwable $e) {
            Logger::warning('BankMailAccounts::note failed', ['id' => $id, 'err' => $e->getMessage()], 'payment');
        }
    }

    /** One mail looked at (the "updates" feed); the newest LOG_KEEP per box are kept. */
    public static function log(int $id, string $outcome, string $subject, ?string $receivedAt, ?int $creditId, string $msgRef = ''): void
    {
        if (!self::logReady() || $id <= 0) {
            return;
        }
        try {
            $ts = $receivedAt !== null && $receivedAt !== '' ? strtotime($receivedAt) : false;
            Database::insert('bank_mail_log', [
                'account_id'  => $id,
                'msg_ref'     => $msgRef !== '' ? mb_substr($msgRef, 0, 64) : null,
                'received_at' => $ts !== false ? date('Y-m-d H:i:s', $ts) : null,
                'subject'     => self::clean($subject, 190) ?: null,
                'outcome'     => mb_substr($outcome, 0, 40),
                'credit_id'   => $creditId !== null && $creditId > 0 ? $creditId : null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            $edge = (int) Database::scalar('SELECT id FROM bank_mail_log WHERE account_id = :a ORDER BY id DESC LIMIT 1 OFFSET ' . (self::LOG_KEEP - 1), ['a' => $id], 0);
            if ($edge > 0) {
                Database::run('DELETE FROM bank_mail_log WHERE account_id = :a AND id < :e', ['a' => $id, 'e' => $edge]);
            }
        } catch (Throwable $e) {
            Logger::warning('BankMailAccounts::log failed', ['id' => $id, 'err' => $e->getMessage()], 'payment');
        }
    }

    /** Text safe for a utf8mb4 column (invalid bytes scrubbed, control characters gone), cut to $max. */
    private static function clean(string $s, int $max): string
    {
        $s = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_scrub($s, 'UTF-8'));
        return mb_substr(trim($s), 0, $max);
    }

    /* =================================================================
     *  the old single-box settings -> account #1, once
     * ================================================================= */

    /** Old settings filled and not looked at yet (the cron must run to move them). */
    public static function legacyPending(): bool
    {
        return self::ready() && Settings::getString(self::LEGACY_KEY, '') === ''
            && trim(Settings::getString('bankmail_user', '')) !== '' && trim(Settings::getString('bankmail_app_password', '')) !== '';
    }

    /**
     * bankmail_user + bankmail_app_password filled and no box exists yet: they become
     * account #1 (NP, app password, sealed) with bankmail_from / bankmail_dkim_domain /
     * bankmail_account_hint as its rules, bankmail_on as its on/off and the IMAP position.
     * Once only (bankmail_legacy_account); without sodium it waits (nothing stored in plain).
     *
     * @return int the new account id (0 = nothing done)
     */
    public static function migrateLegacy(): int
    {
        if (!self::legacyPending()) {
            return 0;
        }
        $user = strtolower(trim(Settings::getString('bankmail_user', '')));
        $pass = str_replace(' ', '', Settings::getString('bankmail_app_password', ''));
        if (filter_var($user, FILTER_VALIDATE_EMAIL) === false || $pass === '') {
            return 0;
        }
        if ((int) Database::scalar('SELECT COUNT(*) FROM bank_mail_accounts', [], 0) > 0) {
            Settings::set(self::LEGACY_KEY, '0', 'string', 'payments');   // a box was added first: the old settings stay unused
            Settings::flush();
            return 0;
        }
        if (!SecretBox::available()) {
            Logger::warning('BankMail: old Gmail settings not moved to an account yet (no sodium)', [], 'payment');
            return 0;
        }
        try {
            $id = (int) Database::insert('bank_mail_accounts', [
                'country' => 'NP', 'currency' => 'NPR', 'label' => 'Global IME (पुरानो सेटिङ · old settings)', 'email' => $user,
                'method' => 'app_password', 'secret_enc' => SecretBox::seal($pass, 'bankmail'),
                'senders' => mb_substr(trim(Settings::getString('bankmail_from', '')), 0, 500) ?: null,
                'dkim_domain' => mb_substr(strtolower(trim(Settings::getString('bankmail_dkim_domain', ''))), 0, 120) ?: null,
                'account_hint' => mb_substr((string) preg_replace('/\D/', '', Settings::getString('bankmail_account_hint', '')), 0, 12) ?: null,
                'is_active' => Settings::getBool('bankmail_on', false) ? 1 : 0,
                'last_uid' => Settings::getInt('bankmail_last_uid', 0) > 0 ? Settings::getInt('bankmail_last_uid', 0) : null,
                'uidvalidity' => mb_substr(Settings::getString('bankmail_uidvalidity', ''), 0, 20) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Logger::warning('BankMail: old Gmail settings could not become an account', ['err' => $e->getMessage()], 'payment');
            return 0;
        }
        Settings::set(self::LEGACY_KEY, (string) $id, 'string', 'payments');
        Settings::flush();
        Logger::audit('bank_mail.legacy_migrated', 'bank_mail_account', (string) $id, null,
            ['email' => $user, 'country' => 'NP', 'method' => 'app_password', 'active' => Settings::getBool('bankmail_on', false)],
            'Old single Gmail settings became bank Gmail account #' . $id);
        return $id;
    }

    /* =================================================================
     *  form checks (the panel)
     * ================================================================= */

    /** @return list<string> what is wrong with a senders list ('' list = fine) */
    public static function badSenders(string $list): array
    {
        $bad = [];
        foreach (array_filter(array_map('trim', explode(',', $list))) as $f) {
            if (preg_match('/^(@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}|[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})$/', $f) !== 1) {
                $bad[] = 'Sender "' . mb_substr($f, 0, 60) . '": ठेगाना वा @domain चाहिन्छ · an address or @domain';
            }
        }
        return $bad;
    }

    public static function goodDomain(string $d): bool
    {
        return $d === '' || preg_match('/^(?=.{3,120}$)[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)+$/', $d) === 1;
    }

    /** '' or 4-6 digits (spaces / dashes dropped); null = not valid. */
    public static function cleanHint(string $h): ?string
    {
        $h = (string) preg_replace('/[\s\-]+/', '', $h);
        return $h === '' || preg_match('/^\d{4,6}$/', $h) === 1 ? $h : null;
    }
}
