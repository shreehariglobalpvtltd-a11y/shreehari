<?php
/**
 * includes/waverify.php — prove a mobile number with ONE WhatsApp message
 * (22 Oct 2026, owner: "WhatsApp redirect garera hi lekhne ani back aune").
 *
 *   1. The form (staff, agent, driver, the agreement's sign box) asks
 *      /api/wa-verify.php "start" -> a 6-letter token and a wa.me link to the
 *      company number with "hi VERIFY K7Q2M9" already typed.
 *   2. The person taps Send in their own WhatsApp. whatsapp/webhook.php
 *      (Meta) and whatsapp/aisensy-webhook.php hand every inbound text to
 *      WaVerify::inbound() BEFORE the bot: a VERIFY message from the SAME
 *      number the form typed marks the row verified and gets a short free
 *      reply (the person just wrote, so the 24 h window is open). From a
 *      different number it is refused and said so.
 *   3. Back on the form, "check" sees verified -> this session's proven
 *      numbers ($_SESSION['wa_verified'][phone] = time) - the same proof the
 *      code path gives.
 *
 * Why it proves the number: WhatsApp only lets the owner of a number send
 * from it. A token is single-use, lives 30 minutes, and is bound to the
 * number typed when it was made.
 */

declare(strict_types=1);

final class WaVerify
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O, 1/I
    public const TTL_SEC   = 1800;
    public const PROVEN_SEC = 3600;
    /* 30 Oct 2026 (owner: "number diyo bhane WhatsApp ma hi pathaune — login ekdam sajilo"): 'login' = the
       one staff door (admin/login.php) signs in by the same "hi VERIFY" message. Strict like 'reset': the
       sender must be the number on file, the message must carry the LOGIN word, a token opens once. */
    public const PURPOSES  = ['staff', 'agent', 'driver', 'sign', 'reset', 'customer', 'login'];
    public const FORM_PURPOSES = ['staff', 'agent', 'driver', 'sign'];
    /* 31 Oct 2026 (review): a "hi … LOGIN" / "hi … RESET" signs someone in (or sets a password), and the
       wa.me link that carries it can be forwarded: "tap Send to get today's commission" was enough to
       sign the sender's account in on the forwarder's browser. For these two the hi is no longer the
       proof by itself: the reply says what is being approved (time, IP) and asks for YES; only that
       YES, from the same number, proves it (confirmReply()). The hi marks the row (verified_from set,
       verified_at empty) = waiting for YES. */
    public const CONFIRM_PURPOSES = ['login', 'reset'];
    public const CONFIRM_WORDS = ['yes', 'हो', 'ho'];

    private static ?bool $ready = null;

    private function __construct() {}

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = (bool) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_verify'", [], 0);
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** The company number people write to (digits with country code). */
    public static function companyNumber(): string
    {
        foreach ([Settings::getString('wa_verify_to', ''), Settings::getString('company_whatsapp', ''), Settings::getString('admin_whatsapp', '')] as $raw) {
            $d = preg_replace('/\D/', '', $raw) ?? '';
            if (strlen($d) >= 11) {
                return $d;
            }
        }
        return '919800000000'; // fallback
    }

    /** A WhatsApp text that carries "VERIFY <6 letters>" (the bot leaves it to inbound()). */
    public static function isVerifyText(string $text): bool
    {
        return preg_match('/\bVERIFY\s*[:#-]?\s*[A-Z2-9]{6}\b/i', $text) === 1;
    }

    /**
     * @param ?string $intl the full WhatsApp number the sender must be. null = $country + $phone10.
     * @return array{token: string, text: string, url: string, to: string}
     */
    public static function start(string $purpose, string $phone10, string $country, string $ip = '', ?string $intl = null): array
    {
        $purpose = in_array($purpose, self::PURPOSES, true) ? $purpose : 'staff';
        $intl = $intl !== null ? (preg_replace('/\D/', '', $intl) ?? '') : (strtoupper($country) === 'NP' ? '977' : '91') . $phone10;
        $to = self::companyNumber();
        if ($to === '') {
            throw new RuntimeException('No company WhatsApp number is set.');
        }
        try {
            Database::query('DELETE FROM wa_verify WHERE expires_at < NOW() - INTERVAL 1 DAY');
        } catch (Throwable $e) {}

        $token = '';
        for ($i = 0; $i < 6 && $token === ''; $i++) {
            $t = '';
            for ($k = 0; $k < 6; $k++) {
                $t .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            try {
                if (!Database::exists('SELECT 1 FROM wa_verify WHERE token = :t', ['t' => $t])) {
                    $token = $t;
                }
            } catch (Throwable $e) {
                $token = $t;
            }
        }
        try {
            Database::insert('wa_verify', [
                'token' => $token, 'purpose' => $purpose, 'phone' => $phone10, 'intl' => $intl,
                'expires_at' => date('Y-m-d H:i:s', time() + self::TTL_SEC), 'ip_address' => mb_substr($ip, 0, 45),
            ]);
        } catch (Throwable $e) {
            // If table doesn't exist yet, we still record in session
        }

        $mine = is_array($_SESSION['wa_tokens'] ?? null) ? $_SESSION['wa_tokens'] : [];
        $mine[$token] = $phone10;
        $_SESSION['wa_tokens'] = array_slice($mine, -10, null, true);

        if ($purpose !== 'reset' && $purpose !== 'login' && empty($_SESSION['wa_tried'][$phone10])) {
            $_SESSION['wa_tried'][$phone10] = time();
        }
        $text = 'hi VERIFY ' . $token . match ($purpose) {
            'reset' => ' — PASSWORD RESET · पासवर्ड रिसेट',
            'login' => ' — LOGIN · लगइन',
            default => '',
        };
        return ['token' => $token, 'text' => $text, 'to' => $to, 'url' => 'https://wa.me/' . $to . '?text=' . rawurlencode($text)];
    }

    /**
     * @return array{status: string, phone: string}  status: verified | waiting | mismatch | expired | unknown
     */
    public static function check(string $token): array
    {
        $token = strtoupper(trim($token));
        $phone = (string) ($_SESSION['wa_tokens'][$token] ?? '');
        if ($phone === '') {
            return ['status' => 'unknown', 'phone' => ''];
        }
        try {
            $r = Database::fetch('SELECT * FROM wa_verify WHERE token = :t', ['t' => $token]);
        } catch (Throwable $e) {
            $r = null;
        }
        if ($r === null) {
            return ['status' => 'unknown', 'phone' => $phone];
        }
        if (!empty($r['verified_at'])) {
            if (in_array((string) $r['purpose'], self::FORM_PURPOSES, true)) {
                $at = strtotime((string) $r['verified_at']) ?: time();
                $_SESSION['wa_verified'][(string) $r['phone']] = min($at, time());
            }
            return ['status' => 'verified', 'phone' => (string) $r['phone']];
        }
        if (strtotime((string) $r['expires_at']) < time()) {
            return ['status' => 'expired', 'phone' => $phone];
        }
        if (in_array((string) $r['purpose'], self::CONFIRM_PURPOSES, true) && !empty($r['verified_from'])) {
            return ['status' => 'confirm', 'phone' => $phone];   // the hi came; the YES has not yet
        }
        return ['status' => !empty($r['mismatch_from']) ? 'mismatch' : 'waiting', 'phone' => $phone];
    }

    /** Did this session prove this 10-digit number in the last hour? */
    public static function sessionVerified(string $phone10): bool
    {
        return (int) ($_SESSION['wa_verified'][$phone10] ?? 0) > time() - self::PROVEN_SEC;
    }

    public static function markSessionVerified(string $phone10): void
    {
        $_SESSION['wa_verified'][$phone10] = time();
    }

    public static function consumeReset(string $token, string $expectedIntl): bool
    {
        $token = strtoupper(trim($token));
        $expectedIntl = preg_replace('/\D/', '', $expectedIntl) ?? '';
        $phone = (string) ($_SESSION['wa_tokens'][$token] ?? '');
        if ($token === '' || $expectedIntl === '' || $phone === '' || !self::ready()) {
            return false;
        }
        $r = Database::fetch('SELECT * FROM wa_verify WHERE token = :t', ['t' => $token]);
        if ($r === null || (string) $r['purpose'] !== 'reset' || empty($r['verified_at'])
            || (string) $r['phone'] !== $phone
            || (string) $r['intl'] !== $expectedIntl || (string) ($r['verified_from'] ?? '') !== $expectedIntl
            || strtotime((string) $r['expires_at']) < time()) {
            return false;
        }
        $won = Database::run("UPDATE wa_verify SET purpose = 'reset_used' WHERE id = :id AND purpose = 'reset'", ['id' => (int) $r['id']]);
        unset($_SESSION['wa_tokens'][$token]);
        return $won === 1;
    }

    /**
     * 30 Oct 2026 (one staff door): take a "hi VERIFY … LOGIN" proof — the same rules as consumeReset():
     * made in THIS browser session, verified from exactly $expectedIntl, not expired, and used once
     * ('login' -> 'login_used').
     */
    public static function consumeLogin(string $token, string $expectedIntl): bool
    {
        $token = strtoupper(trim($token));
        $expectedIntl = preg_replace('/\D/', '', $expectedIntl) ?? '';
        $phone = (string) ($_SESSION['wa_tokens'][$token] ?? '');
        if ($token === '' || $expectedIntl === '' || $phone === '' || !self::ready()) {
            return false;
        }
        $r = Database::fetch('SELECT * FROM wa_verify WHERE token = :t', ['t' => $token]);
        if ($r === null || (string) $r['purpose'] !== 'login' || empty($r['verified_at'])
            || (string) $r['phone'] !== $phone
            || (string) $r['intl'] !== $expectedIntl || (string) ($r['verified_from'] ?? '') !== $expectedIntl
            || strtotime((string) $r['expires_at']) < time()) {
            return false;
        }
        $won = Database::run("UPDATE wa_verify SET purpose = 'login_used' WHERE id = :id AND purpose = 'login'", ['id' => (int) $r['id']]);
        unset($_SESSION['wa_tokens'][$token]);
        return $won === 1;
    }

    public static function inbound(string $fromDigits, string $text): ?string
    {
        if (!self::ready()) {
            return null;
        }
        if (preg_match('/\bVERIFY\s*[:#-]?\s*([A-Z2-9]{6})\b/i', $text, $m) !== 1) {
            return self::confirmReply($fromDigits, $text);   // "YES" to a waiting LOGIN / RESET, else null (the bot)
        }
        $token = strtoupper($m[1]);
        $from  = preg_replace('/\D/', '', $fromDigits) ?? '';
        $r = Database::fetch('SELECT * FROM wa_verify WHERE token = :t', ['t' => $token]);
        $co = Settings::getString('company_name', 'S Hari Global');
        if ($r === null || strtotime((string) $r['expires_at']) < time()) {
            return "यो कोड पुरानो भयो वा मिलेन। फारममा फर्केर फेरि \"WhatsApp मा hi पठाउनुहोस्\" थिच्नुहोस्।\nThis code has expired - please tap the WhatsApp button on the form again.";
        }
        if (!empty($r['verified_at'])) {
            return "✅ यो नम्बर पहिले नै प्रमाणित भइसक्यो। फारममा फर्कनुहोस्।\nAlready verified - please go back to the form.";
        }
        $reset = (string) $r['purpose'] === 'reset';
        $login = (string) $r['purpose'] === 'login';
        $same = $from === (string) $r['intl'] || (strlen($from) >= 12 && substr($from, -10) === (string) $r['phone']);
        if ($reset || $login) {
            $same = (string) $r['intl'] !== '' && $from === (string) $r['intl'];
        }
        if (!$same) {
            Database::update('wa_verify', ['mismatch_from' => mb_substr($from, 0, 20)], 'id = :id', ['id' => (int) $r['id']]);
            Logger::info('WhatsApp verify: sent from a different number', ['token' => $token, 'from' => maskPhone($from)], 'whatsapp');
            if ($reset) {
                return "❌ यो कोड यो WhatsApp नम्बरको लागि होइन। यो पासवर्ड बदल्ने कोड हो — अरूले मागे नपठाउनुहोस्।\nThis code is not for this WhatsApp number. It is a PASSWORD RESET code - never send it for someone else.";
            }
            if ($login) {
                return "❌ यो कोड यो WhatsApp नम्बरको लागि होइन। यो लगइन कोड हो — अरूले मागे नपठाउनुहोस्।\nThis code is not for this WhatsApp number. It is a LOGIN code - never send it for someone else.";
            }
            return "❌ यो कोड अर्को नम्बर (" . maskPhone((string) $r['intl']) . ") को लागि हो। फारममा लेखेको नम्बरको WhatsApp बाट नै पठाउनुहोस्।\nThis code is for another number - send it from the WhatsApp of the number on the form.";
        }
        if ($login && preg_match('/\bLOGIN\b/i', $text) !== 1 && !str_contains($text, 'लगइन')) {
            Logger::info('WhatsApp verify: login token without the login word', ['token' => $token, 'from' => maskPhone($from)], 'whatsapp');
            return "⚠️ यो कोड " . $co . " को लगइनको लागि हो (LOGIN)। अरूले मागे नपठाउनुहोस्।\nआफैं लगइन गर्दै हुनुहुन्छ भने पूरा सन्देश (\"LOGIN\" सहित) पठाउनुहोस्।";
        }
        if ($reset && preg_match('/\bRESET\b/i', $text) !== 1 && !str_contains($text, 'रिसेट')) {
            Logger::info('WhatsApp verify: reset token without the reset words', ['token' => $token, 'from' => maskPhone($from)], 'whatsapp');
            return "⚠️ यो कोड " . $co . " टिम पोर्टलको पासवर्ड बदल्नको लागि हो (PASSWORD RESET)। अरूले मागे नपठाउनुहोस्।\nआफैं पासवर्ड बदल्दै हुनुहुन्छ भने पूरा सन्देश (\"PASSWORD RESET\" सहित) पठाउनुहोस्।";
        }
        if ($login || $reset) {
            // 31 Oct 2026 (review): the hi only ASKS — nothing is proven until a YES from this number
            Database::update('wa_verify', ['verified_from' => mb_substr($from, 0, 20)], 'id = :id', ['id' => (int) $r['id']]);
            Logger::info('WhatsApp verify: waiting for YES', ['token' => $token, 'purpose' => (string) $r['purpose'], 'from' => maskPhone($from)], 'whatsapp');
            $when = date('H:i', strtotime((string) ($r['created_at'] ?? '')) ?: time());
            $ip = trim((string) ($r['ip_address'] ?? ''));
            $where = $when . ($ip !== '' ? ', IP ' . $ip : '');
            return $login
                ? "🔐 " . $co . " — लगइन अनुरोध (" . $where . ")।\nतपाईं आफैं अहिले आफ्नो स्क्रिनमा लगइन गर्दै हुनुहुन्छ भने YES लेखेर पठाउनुहोस्।\nकसैले यो सन्देश पठाउन भनेको हो भने जवाफ नदिनुहोस् — कार्यालयमा फोन गर्नुहोस्।\n\nSign-in request (" . $where . "). If YOU are signing in on your own screen right now, reply YES. If someone asked you to send this message, do NOT reply - call the office."
                : "🔐 " . $co . " — पासवर्ड रिसेट अनुरोध (" . $where . ")।\nतपाईं आफैं अहिले पासवर्ड बदल्दै हुनुहुन्छ भने YES लेखेर पठाउनुहोस्।\nकसैले यो सन्देश पठाउन भनेको हो भने जवाफ नदिनुहोस् — कार्यालयमा फोन गर्नुहोस्।\n\nPASSWORD RESET request (" . $where . "). If YOU are changing your password right now, reply YES. If someone asked you to send this message, do NOT reply - call the office.";
        }
        Database::update('wa_verify', ['verified_at' => date('Y-m-d H:i:s'), 'verified_from' => mb_substr($from, 0, 20)], 'id = :id', ['id' => (int) $r['id']]);
        Logger::info('WhatsApp verify: number proven', ['token' => $token, 'purpose' => (string) $r['purpose'], 'from' => maskPhone($from)], 'whatsapp');
        return "✅ नमस्ते! तपाईंको नम्बर " . $co . " मा प्रमाणित भयो।\nअब फारम भएको पेजमा फर्कनुहोस् — आफैं हरियो देखिन्छ।\nYour number is verified - go back to the form.";
    }

    /**
     * 31 Oct 2026 (review): the YES that turns a waiting LOGIN / RESET hi into a proof. Only from the
     * very number the hi came from (= the number on file), only while that token lives, only one: two
     * requests waiting on one number are both cancelled (one of them may be someone else's link).
     * Anything that is not a YES, or a YES with nothing waiting, returns null and goes to the bot.
     */
    private static function confirmReply(string $fromDigits, string $text): ?string
    {
        $word = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $text)));
        if (!in_array($word, self::CONFIRM_WORDS, true)) {
            return null;
        }
        $from = preg_replace('/\D/', '', $fromDigits) ?? '';
        if (strlen($from) < 11) {
            return null;
        }
        $rows = Database::fetchAll(
            "SELECT id, purpose FROM wa_verify
              WHERE purpose IN ('" . implode("','", self::CONFIRM_PURPOSES) . "') AND verified_at IS NULL
                AND verified_from = :f AND intl = :f2 AND expires_at > NOW()",
            ['f' => $from, 'f2' => $from]
        );
        if ($rows === []) {
            return null;
        }
        if (count($rows) > 1) {
            foreach ($rows as $row) {
                Database::update('wa_verify', ['expires_at' => date('Y-m-d H:i:s', time() - 1)], 'id = :id', ['id' => (int) $row['id']]);
            }
            Logger::warning('WhatsApp verify: YES with several requests waiting — all cancelled', ['from' => maskPhone($from), 'n' => count($rows)], 'whatsapp');
            return "⚠️ यो नम्बरमा एकभन्दा बढी अनुरोध पर्खिरहेका थिए — सुरक्षाका लागि सबै रद्द गरियो। आफ्नै स्क्रिनबाट फेरि सुरु गर्नुहोस्।\nMore than one request was waiting on this number - for safety all were cancelled. Start again on your own screen.";
        }
        $won = Database::run('UPDATE wa_verify SET verified_at = :at WHERE id = :id AND verified_at IS NULL',
            ['at' => date('Y-m-d H:i:s'), 'id' => (int) $rows[0]['id']]);
        if ($won !== 1) {
            return null;
        }
        Logger::info('WhatsApp verify: YES — number proven', ['purpose' => (string) $rows[0]['purpose'], 'from' => maskPhone($from)], 'whatsapp');
        return (string) $rows[0]['purpose'] === 'login'
            ? "✅ लगइन स्वीकृत — पेजमा फर्कनुहोस्। तपाईंले गरेको होइन भने तुरुन्तै कार्यालयमा फोन गर्नुहोस्।\nSign-in approved - go back to the page. If it was not you, call the office now."
            : "✅ पासवर्ड रिसेट स्वीकृत — पेजमा फर्केर नयाँ पासवर्ड राख्नुहोस्। तपाईंले गरेको होइन भने कार्यालयमा फोन गर्नुहोस्।\nPASSWORD RESET approved - go back to the page and set the new password. If it was not you, call the office.";
    }
}
