<?php
/**
 * =====================================================================
 *  SocialPosts — the marketing queue (24 Sep 2026).
 *
 *  A post is a row: channel (facebook / instagram / telegram), a caption
 *  in three languages, an optional picture, when to publish, and a state.
 *  The office drafts (by hand or with the assistant's help), APPROVES, and
 *  cron/social-publish.php sends what is due — never more than
 *  social_daily_cap a day, never while social_publish_on is off, never the
 *  same row twice (a row is claimed before it is sent).
 *
 *  Publishers use the platforms' own HTTP APIs: a Facebook Page and an
 *  Instagram business account need a system-user token with no App Review
 *  (own page only); Telegram needs a bot in the channel. Every failure is
 *  kept on the row with the platform's own sentence.
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class SocialPosts
{
    public const CHANNELS = ['facebook', 'instagram', 'telegram'];

    /** @var callable|null test hook: fn(array $post, string $caption): string remote id */
    public static $publisher = null;

    public static function enabled(): bool
    {
        return Settings::getBool('social_publish_on', false);
    }

    /** @param array<string,mixed> $in */
    public static function create(array $in, int $adminId): int
    {
        $channel = (string) ($in['channel'] ?? '');
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new RuntimeException('Choose facebook, instagram or telegram.');
        }
        $when = trim((string) ($in['publish_at'] ?? ''));
        $ts   = $when !== '' ? strtotime($when) : time() + 600;
        if ($ts === false) {
            throw new RuntimeException('Give a valid publish time.');
        }
        $en = trim((string) ($in['caption_en'] ?? '')); $hi = trim((string) ($in['caption_hi'] ?? '')); $ne = trim((string) ($in['caption_ne'] ?? ''));
        if ($en === '' && $hi === '' && $ne === '') {
            throw new RuntimeException('Write a caption in at least one language.');
        }
        $media = trim((string) ($in['media_path'] ?? ''));
        if ($channel === 'instagram' && $media === '') {
            throw new RuntimeException('Instagram needs a picture.');
        }
        $id = (int) Database::insert('social_posts', [
            'channel'    => $channel,
            'kind'       => $media !== '' ? 'photo' : 'text',
            'topic'      => mb_substr(trim((string) ($in['topic'] ?? '')), 0, 60) ?: null,
            'caption_en' => $en !== '' ? mb_substr($en, 0, 2000) : null,
            'caption_hi' => $hi !== '' ? mb_substr($hi, 0, 2000) : null,
            'caption_ne' => $ne !== '' ? mb_substr($ne, 0, 2000) : null,
            'media_path' => $media !== '' ? mb_substr($media, 0, 255) : null,
            'link_url'   => mb_substr(trim((string) ($in['link_url'] ?? '')), 0, 255) ?: null,
            'publish_at' => date('Y-m-d H:i:s', $ts),
            'status'     => 'draft',
            'created_by' => $adminId,
        ]);
        Logger::audit('social.draft', 'social_post', (string) $id, null, ['channel' => $channel], 'by admin #' . $adminId);
        return $id;
    }

    public static function setStatus(int $id, string $status, int $adminId): void
    {
        if (!in_array($status, ['draft', 'approved', 'cancelled'], true)) {
            throw new RuntimeException('Only draft, approved or cancelled can be set by hand.');
        }
        $row = Database::fetch('SELECT status FROM social_posts WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw new RuntimeException('Post not found.');
        }
        if (in_array($row['status'], ['published', 'publishing'], true)) {
            throw new RuntimeException('That post is already ' . $row['status'] . '.');
        }
        Database::update('social_posts', ['status' => $status, 'approved_by' => $status === 'approved' ? $adminId : null, 'error' => null], 'id = :id', ['id' => $id]);
        Logger::audit('social.' . $status, 'social_post', (string) $id, ['status' => $row['status']], ['status' => $status], 'by admin #' . $adminId);
    }

    /** The one caption a channel gets: all three languages, in the order our customers read. */
    public static function caption(array $post): string
    {
        $parts = array_filter([$post['caption_ne'] ?? '', $post['caption_hi'] ?? '', $post['caption_en'] ?? ''], static fn($s): bool => trim((string) $s) !== '');
        $txt = implode("\n\n", array_map('trim', $parts));
        if (!empty($post['link_url']) && !str_contains($txt, (string) $post['link_url'])) {
            $txt .= "\n\n" . $post['link_url'];
        }
        return $txt;
    }

    public static function sentToday(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM social_posts WHERE status = 'published' AND DATE(published_at) = CURDATE()", [], 0);
    }

    /**
     * Publish everything approved and due. Returns a summary line for cron.
     * @return array<string,mixed>
     */
    public static function work(int $max = 5): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => []];
        if (!self::enabled()) {
            $out['idle'] = 'social_publish_on is off';
            return $out;
        }
        $cap = max(1, Settings::getInt('social_daily_cap', 6));
        $tried = [0];   // a post that failed this run waits for the next run, not the next loop
        for ($i = 0; $i < $max; $i++) {
            if (self::sentToday() >= $cap) {
                $out['skipped'][] = 'daily cap ' . $cap . ' reached';
                break;
            }
            $row = Database::fetch("SELECT * FROM social_posts WHERE status = 'approved' AND publish_at <= NOW() AND attempts < 3 AND id NOT IN (" . implode(',', array_map('intval', $tried)) . ") ORDER BY publish_at, id LIMIT 1");
            if ($row === null) {
                break;
            }
            // Claim before sending: a second worker (or a retry) never sends it twice.
            $claimed = Database::run("UPDATE social_posts SET status = 'publishing', attempts = attempts + 1 WHERE id = :id AND status = 'approved'", ['id' => (int) $row['id']]);
            $tried[] = (int) $row['id'];
            if ($claimed !== 1) {
                continue;
            }
            try {
                $remote = self::publish($row);
                Database::update('social_posts', ['status' => 'published', 'remote_id' => mb_substr($remote, 0, 120), 'published_at' => date('Y-m-d H:i:s'), 'error' => null], 'id = :id', ['id' => (int) $row['id']]);
                $out['sent']++;
            } catch (Throwable $e) {
                $again = (int) $row['attempts'] + 1 < 3;
                Database::update('social_posts', ['status' => $again ? 'approved' : 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)], 'id = :id', ['id' => (int) $row['id']]);
                $out['failed']++;
                Logger::warning('social publish failed', ['id' => (int) $row['id'], 'e' => $e->getMessage()]);
            }
        }
        return $out;
    }

    /** @return string the platform's id for the post */
    public static function publish(array $post): string
    {
        $caption = self::caption($post);
        if (self::$publisher !== null) {
            return (string) (self::$publisher)($post, $caption);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is not available on this server.');
        }
        $mediaUrl = !empty($post['media_path']) ? appUrl(ltrim((string) $post['media_path'], '/')) : '';
        return match ((string) $post['channel']) {
            'telegram'  => self::telegram($caption, $mediaUrl),
            'facebook'  => self::facebook($caption, $mediaUrl),
            'instagram' => self::instagram($caption, $mediaUrl),
            default     => throw new RuntimeException('Unknown channel.'),
        };
    }

    /* ---------------- drivers ---------------- */

    private static function telegram(string $caption, string $mediaUrl): string
    {
        $token = Settings::getString('telegram_bot_token', '');
        $chat  = Settings::getString('telegram_channel', '');
        if ($token === '' || $chat === '') {
            throw new RuntimeException('Telegram: set telegram_bot_token and telegram_channel in Settings.');
        }
        $url  = self::telegramUrl($token, $mediaUrl !== '' ? 'sendPhoto' : 'sendMessage');
        $body = $mediaUrl !== '' ? ['chat_id' => $chat, 'photo' => $mediaUrl, 'caption' => mb_substr($caption, 0, 1024)] : ['chat_id' => $chat, 'text' => mb_substr($caption, 0, 4096), 'disable_web_page_preview' => false];
        $r = self::http($url, $body);
        if (empty($r['ok'])) {
            throw new RuntimeException('Telegram: ' . ($r['description'] ?? 'no answer'));
        }
        return (string) ($r['result']['message_id'] ?? '');
    }

    /**
     * https://api.telegram.org/bot<token>/<method> — and the token goes in
     * RAW. A bot token looks like 123456789:AAH_long-secret, and percent
     * encoding turns that colon into %3A, which Telegram answers with a 404
     * for every single post. The token is checked instead: only the
     * characters a real one contains, and it must carry the colon that
     * separates the bot id from the secret, so nothing can be smuggled into
     * the path. Public because the test suite proves both halves of this
     * without touching the network.
     */
    public static function telegramUrl(string $token, string $method): string
    {
        $token = trim($token);
        if (preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token) !== 1) {
            throw new RuntimeException('Telegram: telegram_bot_token does not look like a bot token (123456789:AA...).');
        }
        if (preg_match('/^[a-zA-Z]+$/', $method) !== 1) {
            throw new RuntimeException('Telegram: bad method name.');
        }
        return 'https://api.telegram.org/bot' . $token . '/' . $method;
    }

    private static function facebook(string $caption, string $mediaUrl): string
    {
        $page = Settings::getString('meta_page_id', ''); $tok = Settings::getString('meta_page_token', '');
        if ($page === '' || $tok === '') {
            throw new RuntimeException('Facebook: set meta_page_id and meta_page_token in Settings.');
        }
        $url  = 'https://graph.facebook.com/v21.0/' . rawurlencode($page) . ($mediaUrl !== '' ? '/photos' : '/feed');
        $body = $mediaUrl !== '' ? ['url' => $mediaUrl, 'caption' => $caption, 'access_token' => $tok] : ['message' => $caption, 'access_token' => $tok];
        $r = self::http($url, $body);
        if (!empty($r['error'])) {
            throw new RuntimeException('Facebook: ' . ($r['error']['message'] ?? 'error'));
        }
        return (string) ($r['post_id'] ?? $r['id'] ?? '');
    }

    private static function instagram(string $caption, string $mediaUrl): string
    {
        $ig = Settings::getString('ig_user_id', ''); $tok = Settings::getString('meta_page_token', '');
        if ($ig === '' || $tok === '' || $mediaUrl === '') {
            throw new RuntimeException('Instagram: set ig_user_id and meta_page_token in Settings, and give the post a picture.');
        }
        $c = self::http('https://graph.facebook.com/v21.0/' . rawurlencode($ig) . '/media', ['image_url' => $mediaUrl, 'caption' => mb_substr($caption, 0, 2200), 'access_token' => $tok]);
        if (empty($c['id'])) {
            throw new RuntimeException('Instagram: ' . ($c['error']['message'] ?? 'could not create the container'));
        }
        $p = self::http('https://graph.facebook.com/v21.0/' . rawurlencode($ig) . '/media_publish', ['creation_id' => (string) $c['id'], 'access_token' => $tok]);
        if (empty($p['id'])) {
            throw new RuntimeException('Instagram: ' . ($p['error']['message'] ?? 'could not publish'));
        }
        return (string) $p['id'];
    }

    /** @return array<string,mixed> */
    private static function http(string $url, array $form): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form), CURLOPT_TIMEOUT => 25]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('network: ' . $err);
        }
        $j = json_decode((string) $body, true);
        return is_array($j) ? $j : ['error' => ['message' => 'non-JSON answer']];
    }

    /* ---------------- drafting help ---------------- */

    /**
     * A caption in three languages from a topic line, using the assistant's
     * Claude key when there is one; otherwise a plain template. Never
     * invents a price: the fare and the phone come from settings.
     * @return array{en:string,hi:string,ne:string}
     */
    public static function draft(string $topic): array
    {
        require_once INCLUDE_PATH . '/fare.php';
        $dir   = Fare::dirFares();
        $phone = Settings::officePhone();
        $wa    = Settings::officeWhatsApp();
        $fallback = [
            'en' => "🚌 Surat → Rupaidiha (Nepal border) daily AC sleeper. " . ($topic !== '' ? $topic . ' ' : '') . "Sharing sleeper ₹" . number_format((float) $dir['toNepal']) . " per person. Book: " . rtrim(APP_URL, '/') . " · WhatsApp wa.me/" . $wa . " · " . $phone,
            'hi' => "🚌 सूरत → रूपईडीहा (नेपाल बॉर्डर) रोज़ AC स्लीपर। " . ($topic !== '' ? $topic . ' ' : '') . "शेयरिंग स्लीपर ₹" . number_format((float) $dir['toNepal']) . " प्रति व्यक्ति। बुक: " . rtrim(APP_URL, '/') . " · WhatsApp wa.me/" . $wa . " · " . $phone,
            'ne' => "🚌 सुरत → रुपैडिहा (नेपाल बोर्डर) हरेक दिन AC स्लिपर। " . ($topic !== '' ? $topic . ' ' : '') . "सेयरिङ स्लिपर ₹" . number_format((float) $dir['toNepal']) . " प्रति व्यक्ति। बुक: " . rtrim(APP_URL, '/') . " · WhatsApp wa.me/" . $wa . " · " . $phone,
        ];
        $key = Settings::getString('anthropic_api_key', '');
        if ($key === '' || !function_exists('curl_init')) {
            return $fallback;
        }
        try {
            $model = Settings::getString('ai_agent_model', 'claude-sonnet-5');
            $req = [
                'model' => $model, 'max_tokens' => 1200,
                'system' => "You write short social-media captions for S Hari Global, a daily AC sleeper bus Surat/Gujarat → Rupaidiha (Nepal border). Facts you may use: sharing sleeper ₹" . number_format((float) $dir['toNepal']) . " per person towards Nepal, ₹" . number_format((float) $dir['toIndia']) . " towards India; office phone " . $phone . "; WhatsApp wa.me/" . $wa . "; website " . rtrim(APP_URL, '/') . ". Never invent a price, a date or a discount. Warm, plain, 2–4 short lines, one emoji at most per line, end with how to book. Answer ONLY with JSON: {\"en\":\"…\",\"hi\":\"…\",\"ne\":\"…\"} — Hindi in Devanagari, Nepali in Devanagari.",
                'messages' => [['role' => 'user', 'content' => 'Topic: ' . mb_substr($topic, 0, 300)]],
            ];
            if (!str_contains(strtolower($model), 'haiku')) {
                $req['thinking'] = ['type' => 'adaptive']; $req['output_config'] = ['effort' => 'low'];
            }
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($req, JSON_UNESCAPED_UNICODE), CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01']]);
            $body = (string) curl_exec($ch); curl_close($ch);
            $j = json_decode($body, true);
            $text = '';
            foreach ((array) ($j['content'] ?? []) as $b) { if (($b['type'] ?? '') === 'text') { $text .= (string) ($b['text'] ?? ''); } }
            if (preg_match('/\{.*\}/s', $text, $m)) {
                $d = json_decode($m[0], true);
                if (is_array($d) && !empty($d['en'])) {
                    return ['en' => (string) $d['en'], 'hi' => (string) ($d['hi'] ?? ''), 'ne' => (string) ($d['ne'] ?? '')];
                }
            }
        } catch (Throwable $e) {
            Logger::warning('social draft failed', ['e' => $e->getMessage()]);
        }
        return $fallback;
    }
}
