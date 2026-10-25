<?php
/**
 * includes/carddelivery.php — Automated WhatsApp ID & Visiting Card Delivery & State Tracking.
 *
 * Implements:
 *  - Automatic WhatsApp Cloud API card delivery upon admin approval
 *  - Delivery tracking lifecycle: queued -> sent -> delivered (webhook confirmed) -> failed
 *  - Duplicate send prevention (idempotency by card ref)
 *  - Admin Retry / Resend capabilities
 *  - Manual WhatsApp Fallback (Web Share API with file attachment + prefilled wa.me text + download fallback)
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/idcard.php';
require_once __DIR__ . '/notify.php';

final class CardDelivery
{
    public const STATUS_QUEUED    = 'queued';
    public const STATUS_SENT      = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED    = 'failed';

    private function __construct() {}

    /**
     * Get or create delivery record key for a person reference.
     */
    private static function kvKey(string $ref): string
    {
        return 'card_delivery:' . strtoupper(trim($ref));
    }

    /**
     * Retrieve the card delivery status record for a given card reference.
     *
     * @return array{
     *   ref: string,
     *   status: string,
     *   phone: string,
     *   name: string,
     *   provider: string,
     *   provider_msg_id: ?string,
     *   id_card_url: string,
     *   visiting_card_url: string,
     *   pdf_url: string,
     *   error_message: ?string,
     *   created_at: string,
     *   sent_at: ?string,
     *   delivered_at: ?string,
     *   failed_at: ?string,
     *   retry_count: int
     * }|null
     */
    public static function getDelivery(string $ref): ?array
    {
        $ref = strtoupper(trim($ref));
        if ($ref === '') {
            return null;
        }

        try {
            $raw = Database::scalar(
                "SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = :k LIMIT 1",
                ['k' => self::kvKey($ref)]
            );
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (Throwable $e) {
            Logger::warning('CardDelivery::getDelivery failed: ' . $e->getMessage(), ['ref' => $ref], 'whatsapp');
        }

        return null;
    }

    /**
     * Save delivery record into persistent storage.
     */
    private static function saveDelivery(string $ref, array $record): void
    {
        $ref = strtoupper(trim($ref));
        $key = self::kvKey($ref);
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        try {
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('global', :k, :v, 'carddelivery')
                 ON DUPLICATE KEY UPDATE kvalue = :v2, updated_by = 'carddelivery'",
                ['k' => $key, 'v' => $json, 'v2' => $json]
            );
        } catch (Throwable $e) {
            Logger::warning('CardDelivery::saveDelivery failed: ' . $e->getMessage(), ['ref' => $ref], 'whatsapp');
        }
    }

    /**
     * Prepare public/download URLs for cards.
     * Uses signed or permanent card viewer URLs.
     */
    public static function cardUrls(string $ref): array
    {
        $base = rtrim(APP_URL, '/');
        return [
            'id_card_front' => $base . '/admin/id-card.php?ref=' . urlencode($ref) . '&png=front',
            'id_card_back'  => $base . '/admin/id-card.php?ref=' . urlencode($ref) . '&png=back',
            'visiting_front'=> $base . '/admin/id-card.php?ref=' . urlencode($ref) . '&v=front',
            'visiting_back' => $base . '/admin/id-card.php?ref=' . urlencode($ref) . '&v=back',
            'pdf'           => $base . '/admin/id-card.php?ref=' . urlencode($ref) . '&pdf=all',
            'verify'        => IdCard::verifyUrl($ref),
        ];
    }

    /**
     * Send ID Card and Visiting Card to applicant's verified WhatsApp number.
     *
     * @param string $ref Card reference (e.g. STF-000001, AGR-000001, DRV-000001)
     * @param bool $force Resend even if already delivered or sent
     * @return array{ok: bool, status: string, detail: string, manual: array}
     */
    public static function send(string $ref, bool $force = false): array
    {
        $ref = strtoupper(trim($ref));
        $p = IdCard::person($ref);
        if ($p === null) {
            return [
                'ok' => false,
                'status' => self::STATUS_FAILED,
                'detail' => 'Card record not found for ' . $ref,
                'manual' => self::manualFallback($ref),
            ];
        }

        $existing = self::getDelivery($ref);
        if (!$force && $existing !== null) {
            if ($existing['status'] === self::STATUS_DELIVERED) {
                return [
                    'ok' => true,
                    'status' => self::STATUS_DELIVERED,
                    'detail' => 'Cards already delivered via WhatsApp (webhook confirmed).',
                    'manual' => self::manualFallback($ref),
                ];
            }
            if ($existing['status'] === self::STATUS_SENT) {
                return [
                    'ok' => true,
                    'status' => self::STATUS_SENT,
                    'detail' => 'Cards already sent via WhatsApp; awaiting delivery confirmation.',
                    'manual' => self::manualFallback($ref),
                ];
            }
        }

        $phone = $p['waDigits'] !== '' ? $p['waDigits'] : preg_replace('/\D/', '', (string) $p['phone']);
        if ($phone === '' || strlen($phone) < 10) {
            $record = [
                'ref' => $ref,
                'status' => self::STATUS_FAILED,
                'phone' => (string) $p['phone'],
                'name' => (string) $p['name'],
                'provider' => 'none',
                'provider_msg_id' => null,
                'id_card_url' => '',
                'visiting_card_url' => '',
                'pdf_url' => '',
                'error_message' => 'No valid mobile number on file.',
                'created_at' => date('Y-m-d H:i:s'),
                'sent_at' => null,
                'delivered_at' => null,
                'failed_at' => date('Y-m-d H:i:s'),
                'retry_count' => ($existing['retry_count'] ?? 0) + 1,
            ];
            self::saveDelivery($ref, $record);
            return [
                'ok' => false,
                'status' => self::STATUS_FAILED,
                'detail' => 'Invalid or missing mobile number.',
                'manual' => self::manualFallback($ref),
            ];
        }

        $urls = self::cardUrls($ref);
        $company = Settings::getString('company_name', APP_NAME);

        // Build friendly WhatsApp message
        $greeting = "🎉 नमस्ते " . $p['name'] . " जी!\n\n"
                  . "तपाईंको " . $company . " (" . $p['roleEn'] . " / " . $p['roleNe'] . ") को आधिकारिक डिजिटल परिचयपत्र (ID Card) तथा भिजिटिङ कार्ड तयार भएको छ।\n\n"
                  . "📌 परिचयपत्र नं. (Card No): " . $ref . "\n"
                  . "🛡️ अनलाइन प्रमाणीकरण लिंक (Verify Online):\n" . $urls['verify'] . "\n\n"
                  . "📎 कार्ड डाउनलोड गर्नुहोस् (Download Cards):\n"
                  . "• परिचयपत्र (ID Card): " . $urls['id_card_front'] . "\n"
                  . "• भिजिटिङ कार्ड (Visiting Card): " . $urls['visiting_front'] . "\n"
                  . "• प्रिन्ट प्रति (Print-Ready PDF): " . $urls['pdf'] . "\n\n"
                  . "हार्दिक बधाई तथा स्वागत छ!\n" . $company;

        $record = [
            'ref' => $ref,
            'status' => self::STATUS_QUEUED,
            'phone' => $phone,
            'name' => (string) $p['name'],
            'provider' => 'cloud_api',
            'provider_msg_id' => null,
            'id_card_url' => $urls['id_card_front'],
            'visiting_card_url' => $urls['visiting_front'],
            'pdf_url' => $urls['pdf'],
            'error_message' => null,
            'created_at' => $existing['created_at'] ?? date('Y-m-d H:i:s'),
            'sent_at' => null,
            'delivered_at' => null,
            'failed_at' => null,
            'retry_count' => ($existing['retry_count'] ?? 0) + 1,
        ];

        // Attempt automated delivery via Meta Cloud API or configured Notify::whatsapp
        $sendOk = false;
        $errorMsg = null;
        $providerMsgId = null;

        try {
            $token   = Settings::getString('whatsapp_api_token', '');
            $phoneId = Settings::getString('whatsapp_phone_id', '');
            $driver  = Settings::getString('whatsapp_driver', 'click_to_chat');

            if ($token !== '' && $phoneId !== '') {
                // Post through WhatsApp Cloud API directly with media
                $version  = Settings::getString('whatsapp_api_version', 'v21.0');
                $endpoint = 'https://graph.facebook.com/' . $version . '/' . $phoneId . '/messages';

                // Send Message with ID card image header
                $payload = json_encode([
                    'messaging_product' => 'whatsapp',
                    'to'                => $phone,
                    'type'              => 'image',
                    'image'             => [
                        'link'    => $urls['id_card_front'],
                        'caption' => mb_substr($greeting, 0, 1024),
                    ],
                ], JSON_UNESCAPED_UNICODE);

                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $token,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_TIMEOUT        => 20,
                ]);

                $response = curl_exec($ch);
                $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $respJson = json_decode((string) $response, true);
                if ($status >= 200 && $status < 300) {
                    $sendOk = true;
                    if (is_array($respJson) && isset($respJson['messages'][0]['id'])) {
                        $providerMsgId = (string) $respJson['messages'][0]['id'];
                    }
                } else {
                    $errorMsg = 'Meta Cloud API HTTP ' . $status . ': ' . (string) ($respJson['error']['message'] ?? 'Send error');
                    Logger::error('Card WhatsApp delivery failed', ['ref' => $ref, 'phone' => $phone, 'resp' => $response], 'whatsapp');
                }
            } else {
                // Fallback to standard Notify::whatsapp
                $notifyRes = Notify::whatsapp($phone, $greeting, $urls['id_card_front']);
                if ($notifyRes === true) {
                    $sendOk = true;
                } else {
                    $errorMsg = 'WhatsApp API not configured or refused send.';
                }
            }
        } catch (Throwable $e) {
            $errorMsg = $e->getMessage();
            Logger::error('CardDelivery exception: ' . $e->getMessage(), ['ref' => $ref], 'whatsapp');
        }

        if ($sendOk) {
            $record['status'] = self::STATUS_SENT;
            $record['sent_at'] = date('Y-m-d H:i:s');
            $record['provider_msg_id'] = $providerMsgId;
            self::saveDelivery($ref, $record);

            Logger::audit('card.whatsapp_sent', $p['kind'], $ref, null, ['phone' => maskPhone($phone), 'msg_id' => $providerMsgId], 'Card sent via WhatsApp');

            return [
                'ok' => true,
                'status' => self::STATUS_SENT,
                'detail' => 'Card successfully dispatched to +' . $phone . ' via WhatsApp (awaiting delivery receipt).',
                'manual' => self::manualFallback($ref),
            ];
        }

        $record['status'] = self::STATUS_FAILED;
        $record['failed_at'] = date('Y-m-d H:i:s');
        $record['error_message'] = $errorMsg;
        self::saveDelivery($ref, $record);

        return [
            'ok' => false,
            'status' => self::STATUS_FAILED,
            'detail' => $errorMsg ?: 'WhatsApp automatic delivery could not be completed. Please use manual share below.',
            'manual' => self::manualFallback($ref),
        ];
    }

    /**
     * Mark a delivery as confirmed 'delivered' or 'read' via webhook.
     */
    public static function markDelivered(string $providerMsgId): bool
    {
        if (trim($providerMsgId) === '') {
            return false;
        }

        try {
            $rows = Database::fetchAll(
                "SELECT kkey, kvalue FROM kv_store WHERE kscope = 'global' AND kkey LIKE 'card_delivery:%'"
            );
            foreach ($rows as $r) {
                $decoded = json_decode((string) $r['kvalue'], true);
                if (is_array($decoded) && ($decoded['provider_msg_id'] ?? '') === $providerMsgId) {
                    $decoded['status'] = self::STATUS_DELIVERED;
                    $decoded['delivered_at'] = date('Y-m-d H:i:s');
                    $k = (string) $r['kkey'];
                    $v = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    Database::run("UPDATE kv_store SET kvalue = :v, updated_by = 'webhook' WHERE kscope = 'global' AND kkey = :k", ['v' => $v, 'k' => $k]);
                    Logger::info('Card delivery confirmed via webhook', ['ref' => $decoded['ref'], 'msg_id' => $providerMsgId], 'whatsapp');
                    return true;
                }
            }
        } catch (Throwable $e) {
            Logger::warning('markDelivered error: ' . $e->getMessage(), [], 'whatsapp');
        }

        return false;
    }

    /**
     * Build Manual WhatsApp Fallback structure.
     * Note: wa.me links cannot automatically attach local files, so we provide:
     * 1. Direct Web Share API payload with files
     * 2. Prefilled wa.me link with rich text and download links
     * 3. One-click direct downloads for PNG/PDF
     */
    public static function manualFallback(string $ref): array
    {
        $ref = strtoupper(trim($ref));
        $p = IdCard::person($ref);
        $phone = $p !== null ? ($p['waDigits'] ?: preg_replace('/\D/', '', (string) $p['phone'])) : '';
        $urls = self::cardUrls($ref);
        $company = Settings::getString('company_name', APP_NAME);

        $msg = "नमस्ते " . ($p['name'] ?? 'साझेदार') . " जी!\n"
             . "तपाईंको " . $company . " परिचयपत्र (ID Card) तथा भिजिटिङ कार्ड तयार छ।\n\n"
             . "📌 कार्ड नं: " . $ref . "\n"
             . "🛡️ अनलाइन प्रमाणीकरण: " . $urls['verify'] . "\n"
             . "📥 कार्ड डाउनलोड: " . $urls['id_card_front'] . "\n\n"
             . "धन्यवाद,\n" . $company;

        $waMe = $phone !== '' ? 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg) : '';

        return [
            'ref'       => $ref,
            'name'      => $p['name'] ?? '',
            'phone'     => $phone,
            'message'   => $msg,
            'wa_me_url' => $waMe,
            'urls'      => $urls,
            'notice'    => 'नोट: WhatsApp Web/App मा बाहिरी लिंकबाट सीधै फाइल स्वतः अट्याच हुँदैन। कार्ड डाउनलोड गरी वा "Share Cards" बटनबाट पठाउनुहोस्।',
        ];
    }
}
