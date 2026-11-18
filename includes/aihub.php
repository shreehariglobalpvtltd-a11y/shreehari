<?php
/**
 * includes/aihub.php — AiHub: one view of the whole AI + messaging pipeline (19 Nov 2026).
 *
 * Owner (19 Nov): "local model, Claude, helper - sabai eutai thau bata kam garos;
 * ticket katda, kura garda eutai main line; WhatsApp, Facebook, marketing ek satha,
 * digital visual effect bata manage garne".
 *
 * The pipeline itself already exists (AiAgent ladder: Gemini/Claude/... then, since
 * 19 Nov, the VPS model as the last rung; ApWa for agents; WaBooking for the form).
 * This class only READS what it did, so admin/ai-hub.php can draw it:
 *   status()  - health of every engine (Claude, Gemini, VPS model, WhatsApp, Facebook, AiSensy)
 *   flow()    - counts for the live flow picture: channels -> S Hari AI -> brains / outcomes
 *   feed()    - the latest events, never a passenger's words or full number
 *   ping()    - one tiny live test call per engine (office button; a few paise at most)
 *
 * Every query is guarded: a missing table gives 0, never an error.
 */
declare(strict_types=1);

final class AiHub
{
    public const ENGINES = ['claude', 'gemini', 'local', 'whatsapp', 'facebook', 'aisensy'];

    /** @return array<string, mixed> */
    public static function status(bool $fresh = false): array
    {
        $since = date('Y-m-d H:i:s', time() - 86400);
        $use = [];
        foreach (self::rows("SELECT provider, COUNT(*) n, SUM(ok) good, ROUND(AVG(ms)) ms, MAX(IF(ok=1, created_at, NULL)) last_ok,
                                    ROUND(SUM(COALESCE(cost_usd,0)),4) usd
                               FROM ai_usage WHERE created_at >= ? GROUP BY provider", [$since]) as $r) {
            $use[(string) $r['provider']] = $r;
        }
        $brain = static function (string $provider, bool $keyed) use ($use): array {
            $u = $use[$provider] ?? null;
            $n = (int) ($u['n'] ?? 0);
            $good = (int) ($u['good'] ?? 0);
            $ok = !$keyed ? false : ($n === 0 ? null : $good * 2 >= $n);
            return ['ok' => $ok, 'keyed' => $keyed, 'calls' => $n, 'good' => $good, 'ms' => (int) ($u['ms'] ?? 0),
                    'lastOk' => (string) ($u['last_ok'] ?? ''), 'usd' => (float) ($u['usd'] ?? 0)];
        };
        $out = [];
        $out['claude'] = $brain('anthropic', Settings::getString('anthropic_api_key', '') !== '')
            + ['name' => 'Claude', 'sub' => 'Haiku · Opus', 'model' => trim(Settings::getString('ai_staff_model', 'claude-haiku-5-5'))];
        $out['gemini'] = $brain('gemini', Settings::getString('gemini_api_key', '') !== '')
            + ['name' => 'Gemini', 'sub' => 'helper · सहयोगी', 'model' => trim(Settings::getString('gemini_model', ''))];

        // The VPS model (Ollama): its own cached probe, no model call.
        $local = ['ok' => false, 'keyed' => false, 'calls' => 0, 'good' => 0, 'ms' => 0, 'lastOk' => '', 'usd' => 0.0,
                  'name' => 'Local AI', 'sub' => 'VPS · Ollama', 'model' => ''];
        try {
            require_once INCLUDE_PATH . '/agentpro-ai.php';
            $h = ApAi::health($fresh);
            $u = $brain('local', true);
            $local = ['ok' => (bool) $h['ok'], 'keyed' => ApAi::base() !== ''] + $u
                + ['name' => 'Local AI', 'sub' => 'VPS · ' . ($h['kind'] ?? 'ollama'), 'model' => ApAi::pickModel('chat') ?: ApAi::pickModel('vision'),
                   'error' => (string) ($h['error'] ?? ''), 'backup' => Settings::getBool('ai_local_backup_on', true),
                   'replace' => ApAi::configured()];
            $local['ok'] = (bool) $h['ok'];
        } catch (Throwable $e) {
            $local['error'] = 'probe failed';
        }
        $out['local'] = $local;

        // WhatsApp: what the last 24 h of sends say, plus the main refusal.
        $wa = self::row("SELECT SUM(status='sent') sent, SUM(status='failed') failed FROM message_logs
                          WHERE channel='whatsapp' AND created_at >= ?", [$since]) ?? [];
        $sent = (int) ($wa['sent'] ?? 0);
        $failed = (int) ($wa['failed'] ?? 0);
        $top = self::row("SELECT error, COUNT(*) n FROM message_logs WHERE channel='whatsapp' AND status='failed' AND created_at >= ?
                           GROUP BY error ORDER BY n DESC LIMIT 1", [$since]);
        $code = '';
        if ($top && preg_match('/code (\d{5,6})/', (string) $top['error'], $m)) {
            $code = $m[1];
        }
        $out['whatsapp'] = ['name' => 'WhatsApp', 'sub' => 'Meta Cloud API', 'sent' => $sent, 'failed' => $failed,
            'ok' => ($sent + $failed) === 0 ? null : $sent >= $failed, 'topCode' => $code, 'topN' => (int) ($top['n'] ?? 0),
            'hint' => self::codeHint($code), 'inbound' => (int) self::scalar("SELECT COUNT(*) FROM wa_inbound WHERE created_at >= ?", [$since])];

        $fbIn = (int) self::scalar("SELECT COUNT(*) FROM fb_inbound WHERE direction='in' AND created_at >= ?", [$since]);
        $fbOut = self::row("SELECT SUM(status='sent') s, SUM(status='failed') f FROM fb_inbound WHERE direction='out' AND created_at >= ?", [$since]) ?? [];
        $out['facebook'] = ['name' => 'Facebook', 'sub' => 'Page + Messenger', 'ok' => Settings::getString('fb_page_token', '') === '' ? false
            : ((int) ($fbOut['f'] ?? 0) > (int) ($fbOut['s'] ?? 0) ? false : true),
            'inbound' => $fbIn, 'sent' => (int) ($fbOut['s'] ?? 0), 'failed' => (int) ($fbOut['f'] ?? 0),
            'on' => Settings::getBool('fb_msgr_on', false)];

        $as = (int) self::scalar("SELECT COUNT(*) FROM message_logs WHERE created_at >= ? AND (provider='aisensy' OR error LIKE '%AiSensy%' OR error LIKE '%No active plan%')", [$since]);
        $out['aisensy'] = ['name' => 'AiSensy', 'sub' => 'old provider · पुरानो', 'ok' => $as === 0 ? true : false, 'calls' => $as,
            'mode' => Settings::getString('aisensy_mode', 'off'),
            'threads' => (int) self::scalar("SELECT COUNT(*) FROM message_logs WHERE created_at >= ? AND error LIKE '%131070%'", [$since])];
        return $out;
    }

    /** Plain words for the Meta error codes the office actually sees. */
    public static function codeHint(string $code): string
    {
        return match ($code) {
            '131070' => 'AiSensy app ले कुराकानी समातेको छ — Meta Business मा AiSensy हटाउनुहोस् · AiSensy still owns the chats: remove it from the WABA',
            '132001' => 'Template यो भाषामा भेटिएन — Meta मा approve भएपनि केही घण्टा लाग्छ · Template not found in this language yet',
            '131047' => '24 घण्टा भित्र customer ले लेखेको छैन — template चाहिन्छ · Outside the 24 h window: needs an approved template',
            '131026' => 'नम्बरमा WhatsApp छैन वा पुग्न सकेन · Number not on WhatsApp / undeliverable',
            '131042' => 'Meta billing / भुक्तानी समस्या · Payment method problem in Meta',
            '' => '',
            default => 'Meta error ' . $code,
        };
    }

    /**
     * Counts for the flow picture over the last $hours.
     *
     * @return array{hours: int, channels: array<string,int>, routers: array<string,int>, brains: array<string,array{n:int,ok:int}>, outcomes: array<string,int>}
     */
    public static function flow(int $hours = 24): array
    {
        $hours = max(1, min(24 * 30, $hours));
        $since = date('Y-m-d H:i:s', time() - $hours * 3600);
        $ch = [
            'whatsapp' => (int) self::scalar("SELECT COUNT(*) FROM wa_inbound WHERE created_at >= ?", [$since]),
            'facebook' => (int) self::scalar("SELECT COUNT(*) FROM fb_inbound WHERE direction='in' AND created_at >= ?", [$since]),
            'web'      => (int) self::scalar("SELECT COUNT(*) FROM ai_turns WHERE channel='web' AND created_at >= ?", [$since]),
            'desk'     => 0,
        ];
        $tags = [];
        try {
            require_once INCLUDE_PATH . '/bookingchannel.php';
            foreach (self::rows('SELECT ' . BookingChannel::sqlExpr('b') . " tag, COUNT(*) n FROM bookings b WHERE b.created_at >= ? GROUP BY tag", [$since]) as $r) {
                $tags[(string) $r['tag']] = (int) $r['n'];
            }
        } catch (Throwable $e) {
            $tags = [];
        }
        $ch['desk'] = ($tags['COUNTER'] ?? 0) + ($tags['AGENT'] ?? 0) + ($tags['ADMIN'] ?? 0);
        $ch['web'] += $tags['ONLINE'] ?? 0;

        $routers = [];
        foreach (self::rows("SELECT router, COUNT(*) n FROM ai_turns WHERE created_at >= ? GROUP BY router", [$since]) as $r) {
            $routers[(string) $r['router']] = (int) $r['n'];
        }
        $brains = [];
        foreach (self::rows("SELECT provider, COUNT(*) n, SUM(ok) ok FROM ai_usage WHERE created_at >= ? GROUP BY provider", [$since]) as $r) {
            $brains[(string) $r['provider']] = ['n' => (int) $r['n'], 'ok' => (int) $r['ok']];
        }
        $msg = self::row("SELECT SUM(status='sent') s, SUM(status='failed') f FROM message_logs WHERE created_at >= ?", [$since]) ?? [];
        $outcomes = [
            'tickets' => array_sum($tags),
            'wa_tickets' => (int) ($tags['WHATSAPP'] ?? 0) + (int) ($tags['MESSENGER'] ?? 0),
            'sent'    => (int) ($msg['s'] ?? 0),
            'failed'  => (int) ($msg['f'] ?? 0),
            'human'   => (int) self::scalar("SELECT COUNT(*) FROM support_tickets WHERE created_at >= ?", [$since]),
        ];
        return ['hours' => $hours, 'channels' => $ch, 'routers' => $routers, 'brains' => $brains, 'outcomes' => $outcomes, 'tags' => $tags];
    }

    /**
     * The latest events, newest first. Numbers are masked to the last 3 digits and
     * no message text is shown.
     *
     * @return list<array{at: string, kind: string, icon: string, text: string, ok: bool}>
     */
    public static function feed(int $limit = 30): array
    {
        $limit = max(5, min(100, $limit));
        $ev = [];
        foreach (self::rows("SELECT created_at, provider, model, channel, role, tag, ms, ok FROM ai_usage ORDER BY id DESC LIMIT $limit") as $r) {
            $ev[] = ['at' => (string) $r['created_at'], 'kind' => 'ai', 'icon' => '🧠', 'ok' => (bool) $r['ok'],
                'text' => self::brainLabel((string) $r['provider']) . ' · ' . (string) $r['model'] . ' · ' . ((string) $r['channel'] ?: '—')
                    . ' · ' . (string) $r['role'] . ' · ' . number_format((int) $r['ms'] / 1000, 1) . ' s'];
        }
        foreach (self::rows("SELECT created_at, channel, purpose, status, to_number, error FROM message_logs ORDER BY id DESC LIMIT $limit") as $r) {
            $code = preg_match('/code (\d{5,6})/', (string) $r['error'], $m) ? ' · ' . $m[1] : '';
            $ev[] = ['at' => (string) $r['created_at'], 'kind' => 'msg', 'icon' => (string) $r['channel'] === 'whatsapp' ? '💬' : '✉️',
                'ok' => (string) $r['status'] === 'sent',
                'text' => ((string) $r['purpose'] ?: 'message') . ' → …' . substr(preg_replace('/\D/', '', (string) $r['to_number']) ?? '', -3)
                    . ' · ' . (string) $r['status'] . $code];
        }
        foreach (self::rows("SELECT created_at, msg_type, from_digits FROM wa_inbound ORDER BY id DESC LIMIT $limit") as $r) {
            $ev[] = ['at' => (string) $r['created_at'], 'kind' => 'in', 'icon' => '📥', 'ok' => true,
                'text' => 'WhatsApp in · ' . (string) $r['msg_type'] . ' · …' . substr((string) $r['from_digits'], -3)];
        }
        foreach (self::rows("SELECT created_at, kind, direction, status FROM fb_inbound ORDER BY id DESC LIMIT $limit") as $r) {
            $ev[] = ['at' => (string) $r['created_at'], 'kind' => 'fb', 'icon' => '📘', 'ok' => (string) $r['status'] !== 'failed',
                'text' => 'Messenger ' . (string) $r['direction'] . ' · ' . (string) $r['kind'] . ' · ' . (string) $r['status']];
        }
        try {
            require_once INCLUDE_PATH . '/bookingchannel.php';
            foreach (self::rows('SELECT b.created_at, b.pnr, b.status, b.total_amount, ' . BookingChannel::sqlExpr('b') . " tag FROM bookings b ORDER BY b.id DESC LIMIT $limit") as $r) {
                $ev[] = ['at' => (string) $r['created_at'], 'kind' => 'ticket', 'icon' => '🎫', 'ok' => (string) $r['status'] !== 'cancelled',
                    'text' => 'टिकट · ' . (string) $r['pnr'] . ' · ' . BookingChannel::label((string) $r['tag']) . ' · ₹' . number_format((float) $r['total_amount'])
                        . ' · ' . (string) $r['status']];
            }
        } catch (Throwable $e) {
            // no bookings view: the feed simply has no ticket lines
        }
        usort($ev, static fn(array $a, array $b): int => strcmp($b['at'], $a['at']));
        return array_slice($ev, 0, $limit);
    }

    /** @return list<array<string,mixed>> the last campaigns, for the marketing tile */
    public static function marketing(int $limit = 5): array
    {
        return self::rows('SELECT id, name, channel, status, total, sent, failed, delivered, read_n, created_at FROM mk_campaigns ORDER BY id DESC LIMIT ' . max(1, min(20, $limit)));
    }

    public static function brainLabel(string $provider): string
    {
        return match ($provider) {
            'anthropic' => 'Claude',
            'gemini'    => 'Gemini',
            'local'     => 'Local AI',
            default     => ucfirst($provider),
        };
    }

    /**
     * One tiny live test of an engine (office button). Claude / Gemini / local get a
     * 5-token "OK" question; WhatsApp and Facebook a read-only Graph GET. Nothing is sent
     * to any customer.
     *
     * @return array{ok: bool, ms: int, detail: string}
     */
    public static function ping(string $engine): array
    {
        $t0 = microtime(true);
        $done = static fn(bool $ok, string $d): array => ['ok' => $ok, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'detail' => mb_substr($d, 0, 300)];
        try {
            switch ($engine) {
                case 'claude':
                    $k = Settings::getString('anthropic_api_key', '');
                    if ($k === '') { return $done(false, 'No Claude key · Claude key छैन'); }
                    $model = trim(Settings::getString('ai_staff_model', 'claude-haiku-5-5')) ?: 'claude-haiku-5-5';
                    [$h, $r] = self::http('https://api.anthropic.com/v1/messages',
                        ["x-api-key: $k", 'anthropic-version: 2023-06-01', 'content-type: application/json'],
                        (string) json_encode(['model' => $model, 'max_tokens' => 16, 'messages' => [['role' => 'user', 'content' => 'Reply with exactly: OK']]]));
                    $txt = (string) ($r['content'][0]['text'] ?? '');
                    self::meter('anthropic', $model, (array) ($r['usage'] ?? []), $t0, $h === 200, 'anthropic');
                    return $done($h === 200, $h === 200 ? $model . ' → ' . $txt : 'HTTP ' . $h . ' ' . (string) ($r['error']['message'] ?? ''));
                case 'gemini':
                    $k = Settings::getString('gemini_api_key', '');
                    $m = trim(Settings::getString('gemini_model', '')) ?: 'gemini-3.5-flash-lite';
                    if ($k === '') { return $done(false, 'No Gemini key · Gemini key छैन'); }
                    [$h, $r] = self::http('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($m) . ':generateContent',
                        ['content-type: application/json', 'x-goog-api-key: ' . $k],
                        (string) json_encode(['contents' => [['parts' => [['text' => 'Reply with exactly: OK']]]]]));
                    self::meter('gemini', $m, [], $t0, $h === 200, 'gemini');
                    return $done($h === 200, $h === 200 ? $m . ' → ' . (string) ($r['candidates'][0]['content']['parts'][0]['text'] ?? '') : 'HTTP ' . $h);
                case 'local':
                    require_once INCLUDE_PATH . '/agentpro-ai.php';
                    ApAi::resetHealth();
                    if (!ApAi::available(true)) { return $done(false, 'Model server not answering · ' . ApAi::base()); }
                    $r = ApAi::chat([['role' => 'user', 'content' => 'Reply with exactly: OK']], ['purpose' => 'chat', 'timeout' => 60, 'max_tokens' => 8]);
                    self::meter('local', (string) $r['model'], ['in' => (int) $r['tokensIn'], 'out' => (int) $r['tokensOut'], 'cache_read' => 0, 'cache_write' => 0], $t0, (bool) $r['ok'], null);
                    return $done((bool) $r['ok'], $r['ok'] ? $r['model'] . ' → ' . trim((string) $r['text']) : (string) $r['error']);
                case 'whatsapp':
                    $cfg = ROOT_PATH . '/whatsapp/config.php';
                    if (!is_file($cfg)) { return $done(false, 'whatsapp/config.php missing'); }
                    require_once $cfg;
                    if (!defined('META_ACCESS_TOKEN') || !defined('META_PHONE_NUMBER_ID')) { return $done(false, 'Meta token not set'); }
                    [$h, $r] = self::http(rtrim((string) META_API_BASE, '/') . '/' . META_PHONE_NUMBER_ID . '?fields=display_phone_number,quality_rating,status,messaging_limit_tier',
                        ['Authorization: Bearer ' . META_ACCESS_TOKEN]);
                    $apps = '';
                    if (defined('META_WABA_ID')) {
                        [, $a] = self::http(rtrim((string) META_API_BASE, '/') . '/' . META_WABA_ID . '/subscribed_apps', ['Authorization: Bearer ' . META_ACCESS_TOKEN]);
                        $apps = implode(', ', array_map(static fn($x): string => (string) ($x['whatsapp_business_api_data']['name'] ?? '?'), (array) ($a['data'] ?? [])));
                    }
                    return $done($h === 200, $h === 200
                        ? (string) ($r['display_phone_number'] ?? '') . ' · ' . (string) ($r['status'] ?? '') . ' · ' . (string) ($r['quality_rating'] ?? '') . ' · ' . (string) ($r['messaging_limit_tier'] ?? '')
                          . ($apps !== '' ? ' · apps: ' . $apps : '')
                        : 'HTTP ' . $h . ' ' . (string) ($r['error']['message'] ?? ''));
                case 'facebook':
                    $tok = Settings::getString('fb_page_token', '');
                    if ($tok === '') { return $done(false, 'No Page token · Page token छैन'); }
                    [$h, $r] = self::http('https://graph.facebook.com/v21.0/me?fields=name,followers_count', ['Authorization: Bearer ' . $tok]);
                    return $done($h === 200, $h === 200 ? (string) ($r['name'] ?? '') . ' · followers ' . (int) ($r['followers_count'] ?? 0) : 'HTTP ' . $h . ' ' . (string) ($r['error']['message'] ?? ''));
                default:
                    return $done(false, 'unknown engine');
            }
        } catch (Throwable $e) {
            return $done(false, $e->getMessage());
        }
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private static function http(string $url, array $headers, ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            return [0, []];
        }
        $c = curl_init($url);
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8];
        if ($body !== null) {
            $o[CURLOPT_POST] = true;
            $o[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($c, $o);
        $raw = curl_exec($c);
        $h = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        $j = json_decode((string) $raw, true);
        return [$h, is_array($j) ? $j : []];
    }

    private static function meter(string $provider, string $model, array $usage, float $t0, bool $ok, ?string $shape): void
    {
        try {
            require_once INCLUDE_PATH . '/aimeter.php';
            AiMeter::context(['channel' => 'probe', 'role' => 'admin', 'tag' => 'hub_test']);
            $u = $shape === 'anthropic' ? AiMeter::fromAnthropic($usage) : ($usage !== [] ? $usage : []);
            AiMeter::record($provider, $model, $u, (int) round((microtime(true) - $t0) * 1000), $ok);
        } catch (Throwable $e) {
            // metering never breaks a test
        }
    }

    /** @return list<array<string,mixed>> */
    private static function rows(string $sql, array $p = []): array
    {
        try {
            return Database::fetchAll($sql, $p);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array<string,mixed>|null */
    private static function row(string $sql, array $p = []): ?array
    {
        try {
            return Database::fetch($sql, $p);
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function scalar(string $sql, array $p = []): mixed
    {
        try {
            return Database::scalar($sql, $p, 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}
