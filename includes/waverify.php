<?php
/**
 * includes/waverify.php — prove a mobile number with ONE WhatsApp message
 * (22 Oct 2026, owner: "WhatsApp redirect garera hi lekhne ani back aune").
 *
 *   1. The form (staff, agent, the staff agreement's sign box) asks
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
 *
 * 22 Oct 2026 (review): a team-portal password reset (purpose 'reset') is a
 * separate kind of proof. Only TeamPortal::startVerify makes one (bound to
 * the full number on the account, never to a country the visitor picks),
 * the message must say PASSWORD RESET, it never counts as the forms' proof
 * ($_SESSION['wa_verified']) and it is spent once by consumeReset(). The
 * forms' proof is stamped with the time WhatsApp proved it, so polling an
 * old token again does not stretch the hour.
 */

declare(strict_types=1);

final class WaVerify
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O, 1/I
    public const TTL_SEC   = 1800;
    public const PROVEN_SEC = 3600;
    public const PURPOSES  = ['staff', 'agent', 'sign', 'reset'];   // reset: team portal password (22 Oct 2026)
    /** 22 Oct 2026 (review): what /api/wa-verify.php may start, and the only proofs that count for the forms. */
    public const FORM_PURPOSES = ['staff', 'agent', 'sign'];

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
        return '';
    }

    /** A WhatsApp text that carries "VERIFY <6 letters>" (the bot leaves it to inbound()). */
    public static function isVerifyText(string $text): bool
    {
        return preg_match('/\bVERIFY\s*[:#-]?\s*[A-Z2-9]{6}\b/i', $text) === 1;
    }

    /**
     * @param ?string $intl 22 Oct 2026 (review): the full WhatsApp number the
     *        sender must be, given by the caller (TeamPortal: from the
     *        account; '' = nobody can prove it). null = $country + $phone10.
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
        Database::query('DELETE FROM wa_verify WHERE expires_at < NOW() - INTERVAL 1 DAY');
        $token = '';
        for ($i = 0; $i < 6 && $token === ''; $i++) {
            $t = '';
            for ($k = 0; $k < 6; $k++) {
                $t .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            if (!Database::exists('SELECT 1 FROM wa_verify WHERE token = :t', ['t' => $t])) {
                $token = $t;
            }
        }
        Database::insert('wa_verify', [
            'token' => $token, 'purpose' => $purpose, 'phone' => $phone10, 'intl' => $intl,
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL_SEC), 'ip_address' => mb_substr($ip, 0, 45),
        ]);
        $mine = is_array($_SESSION['wa_tokens'] ?? null) ? $_SESSION['wa_tokens'] : [];
        $mine[$token] = $phone10;
        $_SESSION['wa_tokens'] = array_slice($mine, -10, null, true);
        // first press per number: a form may fall back to "send without proof" a while after it
        if ($purpose !== 'reset' && empty($_SESSION['wa_tried'][$phone10])) {
            $_SESSION['wa_tried'][$phone10] = time();
        }
        // 22 Oct 2026 (review): a reset says so in the very message the person sends, so a relayed "verify …" lure shows it
        $text = 'hi VERIFY ' . $token . ($purpose === 'reset' ? ' — PASSWORD RESET · पासवर्ड रिसेट' : '');
        return ['token' => $token, 'text' => $text, 'to' => $to, 'url' => 'https://wa.me/' . $to . '?text=' . rawurlencode($text)];
    }

    /**
     * Only tokens this session made. verified -> the number is remembered
     * as proven in this session (staff / agent / sign only: a 'reset' token
     * just reports its status here and is spent by consumeReset()).
     *
     * @return array{status: string, phone: string}  status: verified | waiting | mismatch | expired | unknown
     */
    public static function check(string $token): array
    {
        $token = strtoupper(trim($token));
        $phone = (string) ($_SESSION['wa_tokens'][$token] ?? '');
        if ($phone === '') {
            return ['status' => 'unknown', 'phone' => ''];
        }
        $r = Database::fetch('SELECT * FROM wa_verify WHERE token = :t', ['t' => $token]);
        if ($r === null) {
            return ['status' => 'unknown', 'phone' => $phone];
        }
        if (!empty($r['verified_at'])) {
            if (in_array((string) $r['purpose'], self::FORM_PURPOSES, true)) {
                // 22 Oct 2026 (review): the hour runs from the WhatsApp message, not from the last poll
                $at = strtotime((string) $r['verified_at']) ?: time();
                $_SESSION['wa_verified'][(string) $r['phone']] = min($at, time());
            }
            return ['status' => 'verified', 'phone' => (string) $r['phone']];
        }
        if (strtotime((string) $r['expires_at']) < time()) {
            return ['status' => 'expired', 'phone' => $phone];
        }
        return ['status' => !empty($r['mismatch_from']) ? 'mismatch' : 'waiting', 'phone' => $phone];
    }

    /** Did this session prove this 10-digit number in the last hour (staff / agent / sign form)? */
    public static function sessionVerified(string $phone10): bool
    {
        return (int) ($_SESSION['wa_verified'][$phone10] ?? 0) > time() - self::PROVEN_SEC;
    }

    /**
     * 22 Oct 2026 (review): spend a team-portal reset proof. True only for a
     * 'reset' token THIS session made, proven by a message FROM exactly
     * $expectedIntl (the account's full number), still inside its 30 minutes
     * and not spent before. Single use: the row is marked 'reset_used' by an
     * UPDATE that only one request can win.
     */
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
     * Every inbound WhatsApp text, before the bot. null = not a VERIFY
     * message (the bot carries on); a string = the reply to send, and the
     * bot stays quiet.
     */
    public static function inbound(string $fromDigits, string $text): ?string
    {
        if (preg_match('/\bVERIFY\s*[:#-]?\s*([A-Z2-9]{6})\b/i', $text, $m) !== 1 || !self::ready()) {
            return null;
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
        $same = $from === (string) $r['intl'] || (strlen($from) >= 12 && substr($from, -10) === (string) $r['phone']);
        if ($reset) {
            // a team-portal password opens an account: the full number with its country must match ('' = nobody can)
            $same = (string) $r['intl'] !== '' && $from === (string) $r['intl'];
        }
        if (!$same) {
            Database::update('wa_verify', ['mismatch_from' => mb_substr($from, 0, 20)], 'id = :id', ['id' => (int) $r['id']]);
            Logger::info('WhatsApp verify: sent from a different number', ['token' => $token, 'from' => maskPhone($from)], 'whatsapp');
            if ($reset) {
                // 22 Oct 2026 (review): no number in this reply - it would tell a stranger which country the account is in
                return "❌ यो कोड यो WhatsApp नम्बरको लागि होइन। यो पासवर्ड बदल्ने कोड हो — अरूले मागे नपठाउनुहोस्।\nThis code is not for this WhatsApp number. It is a PASSWORD RESET code - never send it for someone else.";
            }
            return "❌ यो कोड अर्को नम्बर (" . maskPhone((string) $r['intl']) . ") को लागि हो। फारममा लेखेको नम्बरको WhatsApp बाट नै पठाउनुहोस्।\nThis code is for another number - send it from the WhatsApp of the number on the form.";
        }
        if ($reset && preg_match('/\bRESET\b/i', $text) !== 1 && !str_contains($text, 'रिसेट')) {
            // 22 Oct 2026 (review): only a message that SAYS "password reset" approves one - a relayed
            // "please verify K7Q2M9 for your commission" lure does not
            Logger::info('WhatsApp verify: reset token without the reset words', ['token' => $token, 'from' => maskPhone($from)], 'whatsapp');
            return "⚠️ यो कोड " . $co . " टिम पोर्टलको पासवर्ड बदल्नको लागि हो (PASSWORD RESET)। अरूले मागे नपठाउनुहोस्।\nआफैं पासवर्ड बदल्दै हुनुहुन्छ भने पेजमा देखिएको पूरा सन्देश (\"PASSWORD RESET\" सहित) पठाउनुहोस्।\nThis code is for a PASSWORD RESET of a team account. If someone else asked you to send it, do not. If you are resetting your own password, send the whole message shown on the page.";
        }
        Database::update('wa_verify', ['verified_at' => date('Y-m-d H:i:s'), 'verified_from' => mb_substr($from, 0, 20)], 'id = :id', ['id' => (int) $r['id']]);
        Logger::info('WhatsApp verify: number proven', ['token' => $token, 'purpose' => (string) $r['purpose'], 'from' => maskPhone($from)], 'whatsapp');
        if ($reset) {
            return "✅ नम्बर प्रमाणित भयो — यो " . $co . " टिम पोर्टलको पासवर्ड बदल्नको लागि हो (PASSWORD RESET)।\nआफैं बदल्दै हुनुहुन्छ भने पेजमा फर्केर नयाँ पासवर्ड राख्नुहोस्। तपाईंले मागेको होइन भने तुरुन्तै कार्यालयमा फोन गर्नुहोस्।\nYour number is verified for a PASSWORD RESET of your " . $co . " team account. Go back to the page and set the new password. If this was not you, call the office now.";
        }
        return "✅ नमस्ते! तपाईंको नम्बर " . $co . " मा प्रमाणित भयो।\nअब फारम भएको पेजमा फर्कनुहोस् — आफैं हरियो देखिन्छ।\nYour number is verified - go back to the form.";
    }
}
