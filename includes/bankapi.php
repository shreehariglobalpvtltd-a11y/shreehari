<?php
/**
 * =====================================================================
 *  includes/bankapi.php — BankApi: a plug-and-play India bank / UPI API
 *  (25 Nov 2026, mp1 "pay").
 *
 *  The owner's bank gives a merchant id, an API key / secret and a webhook
 *  secret. The bank POSTs a payment notice to payment/bank-webhook.php;
 *  this file checks it and hands a credit to the shared ledger
 *  (PayCredits, source 'bankapi', currency INR):
 *    - POST only; raw body HMAC-SHA256 with bankapi_webhook_secret, sent in
 *      bankapi_sig_header (X-Signature) as hex or base64 (bankapi_sig_format),
 *      compared in constant time. No secret saved = nothing is accepted.
 *    - optional timestamp header (bankapi_ts_header, X-Timestamp): older or
 *      newer than 5 minutes = refused (replay window).
 *    - the merchant id in the body must equal bankapi_merchant_id.
 *    - the status must be a success word; anything else is answered and dropped.
 *    - field names come from bankapi_field_map (JSON of dot-paths: utr, amount,
 *      reference, status, merchant_id, currency) so any bank's JSON fits.
 *    - bankapi_mode = sandbox: the row is stored as 'test' and NEVER confirms.
 *  Live: PayCredits::tryAutoConfirm() (exact INR amount, one pending booking).
 *
 *  Dynamic UPI QR: upiUri() adds tr=<PNR> (the bank's reference) to the usual
 *  upi://pay link, used only while the API is live. The VPA / name are the
 *  company's existing upi_id / upi_name - not a second copy.
 *
 *  Settings: bankapi_on (off), bankapi_mode (sandbox), bankapi_bank_name,
 *  bankapi_merchant_id, bankapi_key, bankapi_secret, bankapi_webhook_secret
 *  (secrets), bankapi_key_header (X-Api-Key), bankapi_sig_header (X-Signature),
 *  bankapi_sig_format (hex), bankapi_ts_header (X-Timestamp), bankapi_status_url,
 *  bankapi_field_map, bankapi_last_webhook (JSON, for the admin page).
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/paycredits.php';

final class BankApi
{
    public const FIELD_DEFAULTS = [
        'utr'         => 'utr',
        'amount'      => 'amount',
        'reference'   => 'reference',
        'status'      => 'status',
        'merchant_id' => 'merchant_id',
        'currency'    => 'currency',
    ];
    private const SUCCESS = ['success', 'successful', 'succeeded', 'paid', 'captured', 'completed', 'complete', 'credit', 'credited', 's', '0', '00', '000'];
    public const REPLAY_WINDOW = 300;

    public static function on(): bool
    {
        return Settings::getBool('bankapi_on', false);
    }

    public static function mode(): string
    {
        return Settings::getString('bankapi_mode', 'sandbox') === 'live' ? 'live' : 'sandbox';
    }

    public static function webhookUrl(): string
    {
        return appUrl('payment/bank-webhook.php');
    }

    /** @return array<string,string> */
    public static function fieldMap(): array
    {
        $map = self::FIELD_DEFAULTS;
        $raw = Settings::get('bankapi_field_map', null);
        if (is_string($raw) && trim($raw) !== '') {
            $raw = json_decode($raw, true);
        }
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (isset($map[$k]) && is_string($v) && preg_match('/^[A-Za-z0-9_.\-]{1,80}$/', $v) === 1) {
                    $map[$k] = $v;
                }
            }
        }
        return $map;
    }

    /** A value by dot-path ("data.payment.utr"); null when absent. */
    public static function dig(array $data, string $path): mixed
    {
        $cur = $data;
        foreach (explode('.', $path) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) {
                return null;
            }
            $cur = $cur[$k];
        }
        return $cur;
    }

    public static function sign(string $body, string $secret, string $format = 'hex'): string
    {
        $raw = hash_hmac('sha256', $body, $secret, true);
        return $format === 'base64' ? base64_encode($raw) : bin2hex($raw);
    }

    public static function verify(string $body, string $given): bool
    {
        $secret = Settings::getString('bankapi_webhook_secret', '');
        if ($secret === '' || trim($given) === '') {
            return false;
        }
        $fmt   = Settings::getString('bankapi_sig_format', 'hex') === 'base64' ? 'base64' : 'hex';
        $given = trim($given);
        if (stripos($given, 'sha256=') === 0) {
            $given = substr($given, 7);
        }
        $want = self::sign($body, $secret, $fmt);
        return hash_equals($want, $fmt === 'hex' ? strtolower($given) : $given);
    }

    /** "X-Signature" -> "x-signature" */
    public static function headerKey(string $name): string
    {
        return strtolower(trim($name));
    }

    /** Request headers as lower-case name => value (FPM / CLI safe). @return array<string,string> */
    public static function requestHeaders(): array
    {
        $out = [];
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                $out[strtolower((string) $k)] = (string) $v;
            }
        }
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'HTTP_')) {
                $out[strtolower(str_replace('_', '-', substr($k, 5)))] ??= (string) $v;
            }
        }
        return $out;
    }

    /**
     * Everything the webhook does, minus PHP's I/O (so the test can drive it).
     *
     * @param array<string,string> $headers lower-case names
     * @return array{code:int, body:array<string,mixed>}
     */
    public static function handleWebhook(string $method, string $raw, array $headers, string $ip = ''): array
    {
        $out = static function (int $code, array $body, string $note = '') : array {
            try {
                Settings::set('bankapi_last_webhook', ['at' => date('Y-m-d H:i:s'), 'code' => $code,
                    'state' => (string) ($body['state'] ?? ($body['error'] ?? '')), 'note' => mb_substr($note, 0, 120)], 'json', 'payments');
                Settings::flush();
            } catch (Throwable $e) {
            }
            return ['code' => $code, 'body' => $body];
        };
        if (strtoupper($method) !== 'POST') {
            return ['code' => 405, 'body' => ['ok' => false, 'error' => 'POST only']];
        }
        if (!self::on()) {
            return ['code' => 404, 'body' => ['ok' => false, 'error' => 'disabled']];
        }
        if ($ip !== '' && !Security::rateLimit('bankapi_webhook', $ip, 120, 60)) {
            return ['code' => 429, 'body' => ['ok' => false, 'error' => 'slow down']];
        }
        if (strlen($raw) > 65536) {
            return $out(413, ['ok' => false, 'error' => 'too large']);
        }
        $sigHeader = self::headerKey(Settings::getString('bankapi_sig_header', 'X-Signature') ?: 'X-Signature');
        if (!self::verify($raw, (string) ($headers[$sigHeader] ?? ''))) {
            Logger::warning('Bank webhook: bad signature', ['ip' => $ip], 'payment');
            return $out(401, ['ok' => false, 'error' => 'bad signature'], 'bad signature');
        }
        $tsHeader = self::headerKey(Settings::getString('bankapi_ts_header', 'X-Timestamp') ?: 'X-Timestamp');
        $tsRaw = trim((string) ($headers[$tsHeader] ?? ''));
        if ($tsRaw !== '') {
            $ts = ctype_digit($tsRaw) ? (int) $tsRaw : (int) strtotime($tsRaw);
            if ($ts > 100000000000) {
                $ts = intdiv($ts, 1000);   // milliseconds
            }
            if ($ts <= 0 || abs(time() - $ts) > self::REPLAY_WINDOW) {
                return $out(401, ['ok' => false, 'error' => 'stale timestamp'], 'stale timestamp');
            }
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            parse_str($raw, $data);
        }
        if (!is_array($data) || $data === []) {
            return $out(400, ['ok' => false, 'error' => 'empty payload']);
        }
        $map = self::fieldMap();
        $f = static fn(string $k): string => trim((string) (is_scalar($v = self::dig($data, $map[$k])) ? $v : ''));
        $mid = trim(Settings::getString('bankapi_merchant_id', ''));
        if ($mid === '' || !hash_equals($mid, $f('merchant_id'))) {
            Logger::warning('Bank webhook: wrong merchant', ['ip' => $ip], 'payment');
            return $out(403, ['ok' => false, 'error' => 'merchant'], 'wrong merchant id');
        }
        $status = strtolower($f('status'));
        if (!in_array($status, self::SUCCESS, true)) {
            return $out(200, ['ok' => true, 'state' => 'ignored', 'why' => 'not a success status'], 'status ' . mb_substr($status, 0, 20));
        }
        $utr    = $f('utr');
        $amtRaw = str_replace(',', '', $f('amount'));
        $cur    = strtoupper($f('currency'));
        if ($cur === '356') {
            $cur = 'INR';
        }
        if ($utr === '' || preg_match('/^[A-Za-z0-9\-\/]{4,60}$/', $utr) !== 1 || !is_numeric($amtRaw) || (float) $amtRaw <= 0) {
            return $out(422, ['ok' => false, 'error' => 'utr / amount missing'], 'utr / amount missing');
        }
        if ($cur !== '' && $cur !== 'INR') {
            return $out(422, ['ok' => false, 'error' => 'currency'], 'currency ' . mb_substr($cur, 0, 8));
        }
        if (!PayCredits::ready()) {
            return $out(503, ['ok' => false, 'error' => 'not ready'], 'bank_credits missing');
        }
        $sandbox = self::mode() !== 'live';
        $id = PayCredits::record([
            'source' => 'bankapi', 'currency' => 'INR', 'amount' => (float) $amtRaw, 'txn_id' => $utr,
            'ref_text' => $f('reference'), 'sender' => trim(Settings::getString('bankapi_bank_name', '')) ?: 'bank api',
            'dkim_ok' => true, 'received_at' => date('Y-m-d H:i:s'),
            'raw_excerpt' => mb_substr($raw, 0, 1500), 'state' => $sandbox ? 'test' : 'new',
            'note' => $sandbox ? 'Sandbox test - never confirms' : '',
        ]);
        $outcome = PayCredits::lastOutcome();
        if ($outcome === 'replay') {   // the same notice again (same UTR, amount and reference)
            $row = PayCredits::get($id);
            return $out(200, ['ok' => true, 'state' => 'duplicate', 'id' => $id, 'credit' => (string) ($row['state'] ?? '')], 'duplicate utr');
        }
        if ($outcome === 'conflict') {   // same UTR, another amount / reference: kept for a person, bell rung
            return $out(200, ['ok' => true, 'state' => $sandbox ? 'test' : 'unmatched', 'id' => $id], 'utr seen before with another amount / reference');
        }
        if ($sandbox) {
            return $out(200, ['ok' => true, 'state' => 'test', 'id' => $id], 'sandbox');
        }
        $r = PayCredits::tryAutoConfirm($id);
        return $out(200, ['ok' => true, 'state' => $r['ok'] ? 'confirmed' : $r['state'], 'id' => $id], $r['why']);
    }

    /** upi://pay?pa&pn&am&cu=INR&tn=PNR&tr=PNR - the dynamic QR for one booking's INR due. */
    public static function upiUri(string $vpa, string $name, float $amount, string $pnr): string
    {
        return 'upi://pay?' . http_build_query([
            'pa' => $vpa,
            'pn' => $name,
            'am' => number_format($amount, 2, '.', ''),
            'cu' => 'INR',
            'tn' => $pnr,
            'tr' => $pnr,
        ]);
    }

    /** The dynamic (tr=PNR) QR is used only while the API is on and live. */
    public static function upiOn(): bool
    {
        return self::on() && self::mode() === 'live' && trim(Settings::getString('upi_id', '')) !== '';
    }

    /** @return array<string,mixed> */
    public static function lastWebhook(): array
    {
        $v = Settings::get('bankapi_last_webhook', null);
        if (is_string($v)) {
            $v = json_decode($v, true);
        }
        return is_array($v) ? $v : [];
    }

    /**
     * The "Test" button: what is missing, and (when a status URL is set) the
     * HTTP code the bank answers with the API key.
     *
     * @return array{ok:bool, msg:string, checks:list<array{0:string,1:bool,2:string}>}
     */
    public static function testConnection(): array
    {
        $g = static fn(string $k): string => trim(Settings::getString($k, ''));
        $checks = [
            ['Merchant ID', $g('bankapi_merchant_id') !== '', ''],
            ['Webhook secret', $g('bankapi_webhook_secret') !== '', 'without it every webhook is refused'],
            ['UPI ID (VPA)', $g('upi_id') !== '', ''],
            ['Mode', true, self::mode()],
        ];
        $url = $g('bankapi_status_url');
        $okAll = !in_array(false, array_map(static fn(array $c): bool => $c[1], $checks), true);
        $msg = $okAll ? 'Settings complete' : 'Some settings are missing';
        if ($url !== '') {
            if (preg_match('#^https://[A-Za-z0-9.\-]+(:\d+)?(/[^\s]*)?$#', $url) !== 1) {
                $checks[] = ['Status URL', false, 'must be an https:// address'];
                $okAll = false;
            } elseif (!function_exists('curl_init')) {
                $checks[] = ['Status URL', false, 'curl is not installed'];
            } else {
                $ch = curl_init($url);
                $hdr = [];
                $key = $g('bankapi_key');
                if ($key !== '') {
                    $name = preg_replace('/[^A-Za-z0-9\-]/', '', $g('bankapi_key_header') ?: 'X-Api-Key') ?: 'X-Api-Key';
                    $hdr[] = $name . ': ' . $key;
                }
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $hdr, CURLOPT_SSL_VERIFYPEER => true]);
                curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);
                $good = $code >= 200 && $code < 300;
                $checks[] = ['Status URL', $good, $code > 0 ? 'HTTP ' . $code : ('no answer: ' . mb_substr($err, 0, 80))];
                $okAll = $okAll && $good;
                $msg .= ' · bank answered ' . ($code > 0 ? 'HTTP ' . $code : 'nothing');
            }
        }
        $last = self::lastWebhook();
        $checks[] = ['Last webhook', $last !== [], $last !== [] ? ((string) ($last['at'] ?? '') . ' · HTTP ' . (string) ($last['code'] ?? '') . ' · ' . (string) ($last['state'] ?? '')) : 'none yet'];
        return ['ok' => $okAll, 'msg' => $msg, 'checks' => $checks];
    }
}
