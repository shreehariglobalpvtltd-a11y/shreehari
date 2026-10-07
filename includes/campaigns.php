<?php
/**
 * =====================================================================
 *  Campaigns — "launch a message to a list" for marketing (5 Oct 2026)
 *
 *  Audience = a CustomerDirectory filter (date, agent, route, country,
 *  trips, lapsed…) frozen into mk_recipients when the list is built, so
 *  what you previewed is exactly who gets it. Three ways to send:
 *
 *    wa_template  AiSensy approved WhatsApp template — automatic, the cron
 *                 worker sends rate_per_min a minute, quiet hours and a
 *                 daily cap respected, delivery read back from AiSensy.
 *    wa_manual    one tap per person from YOUR WhatsApp (wa.me link with the
 *                 text filled in) — free, no template needed.
 *    sms          Brevo transactional SMS (needs SMS credits + DLT in India).
 *
 *  Opted-out numbers (STOP on WhatsApp, or blocked here), placeholders and
 *  staff numbers are never queued. Nothing is sent before "Launch".
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/customerdirectory.php';

final class Campaigns
{
    public const CHANNELS = [
        'wa_template' => 'WhatsApp template — automatic (AiSensy)',
        'wa_manual'   => 'WhatsApp one-tap — from your own phone (free)',
        'sms'         => 'SMS — Brevo',
    ];

    private function __construct() {}

    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = Database::fetch("SHOW TABLES LIKE 'mk_campaigns'") !== null;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function get(int $id): ?array
    {
        return Database::fetch('SELECT * FROM mk_campaigns WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $in */
    public static function save(array $in, int $by, int $id = 0): int
    {
        $channel = isset(self::CHANNELS[(string) ($in['channel'] ?? '')]) ? (string) $in['channel'] : 'wa_manual';
        $params = [];
        foreach (preg_split('/\R/', (string) ($in['params'] ?? '')) ?: [] as $line) {
            if (trim($line) !== '') {
                $params[] = mb_substr(trim($line), 0, 600);
            }
        }
        $row = [
            'name'            => mb_substr(trim(Security::clean((string) ($in['name'] ?? ''), 120)) ?: 'Campaign ' . date('d M'), 0, 120),
            'channel'         => $channel,
            'template_name'   => preg_match('/^[a-z0-9_]{1,80}$/', (string) ($in['template_name'] ?? '')) === 1 ? (string) $in['template_name'] : null,
            'template_params' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'header_image'    => preg_match('#^https://\S+$#i', trim((string) ($in['header_image'] ?? ''))) === 1 ? mb_substr(trim((string) $in['header_image']), 0, 500) : null,
            'message'         => mb_substr(trim((string) ($in['message'] ?? '')), 0, 1500),
            'link_url'        => preg_match('#^https?://\S+$#i', trim((string) ($in['link_url'] ?? ''))) === 1 ? mb_substr(trim((string) $in['link_url']), 0, 500) : null,
            'audience'        => mb_substr((string) ($in['audience'] ?? ''), 0, 1000),
            'rate_per_min'    => max(1, min(60, (int) ($in['rate_per_min'] ?? 20))),
        ];
        if ($id > 0) {
            Database::update('mk_campaigns', $row, "id = :id AND status IN ('draft','paused','scheduled')", ['id' => $id]);
            return $id;
        }
        return (int) Database::insert('mk_campaigns', $row + ['status' => 'draft', 'created_by' => $by]);
    }

    /** Freeze the audience into recipients (re-runnable while draft). */
    public static function buildAudience(int $id): int
    {
        $c = self::get($id);
        if ($c === null || !in_array((string) $c['status'], ['draft', 'paused', 'scheduled'], true)) {
            return 0;
        }
        // 6 Oct 2026: the AI autopilot (includes/mkautopilot.php) hand-picks its list — a
        // rebuild from the directory filter would widen it to every customer.
        if (str_starts_with((string) $c['audience'], 'autopilot=')) {
            return (int) $c['total'];
        }
        parse_str((string) $c['audience'], $q);
        $list = CustomerDirectory::phones(CustomerDirectory::filters($q), true, Settings::getInt('mk_max_recipients', 5000));
        Database::run("DELETE FROM mk_recipients WHERE campaign_id = :c AND state = 'queued'", ['c' => $id]);
        foreach (array_chunk($list, 200) as $chunk) {
            $vals = [];
            $p = [];
            foreach ($chunk as $i => $r) {
                $vals[] = "(:c$i, :p$i, :n$i)";
                $p["c$i"] = $id;
                $p["p$i"] = $r['intl'];
                $p["n$i"] = mb_substr($r['name'], 0, 120);
            }
            Database::run('INSERT IGNORE INTO mk_recipients (campaign_id, phone, name) VALUES ' . implode(',', $vals), $p);
        }
        self::recount($id);
        return (int) (self::get($id)['total'] ?? 0);
    }

    public static function recount(int $id): void
    {
        $s = Database::fetch(
            "SELECT COUNT(*) total,
                    SUM(state IN ('sent','delivered','read')) sent, SUM(state = 'failed') failed,
                    SUM(state IN ('delivered','read')) delivered, SUM(state = 'read') read_n,
                    SUM(state = 'queued') queued
               FROM mk_recipients WHERE campaign_id = :c",
            ['c' => $id]
        ) ?? [];
        Database::update('mk_campaigns', [
            'total' => (int) ($s['total'] ?? 0), 'sent' => (int) ($s['sent'] ?? 0), 'failed' => (int) ($s['failed'] ?? 0),
            'delivered' => (int) ($s['delivered'] ?? 0), 'read_n' => (int) ($s['read_n'] ?? 0),
        ], 'id = :id', ['id' => $id]);
    }

    /** The link in the message: the campaign link, else the home page tagged with the campaign. */
    public static function link(array $c): string
    {
        $u = (string) ($c['link_url'] ?? '');
        if ($u === '') {
            $u = appUrl('/');
        }
        // A wa.me link opens the WhatsApp order chat: a tag would end up inside the prefilled message.
        if (preg_match('~^https://(?:wa\.me|api\.whatsapp\.com)/~i', $u) === 1) {
            return $u;
        }
        return $u . (str_contains($u, '?') ? '&' : '?') . 'src=cmp' . (int) $c['id'];
    }

    /** {name} {first} {link} {phone} in a text. */
    public static function render(string $text, array $c, array $r): string
    {
        $name  = trim((string) ($r['name'] ?? ''));
        $first = $name !== '' ? explode(' ', $name)[0] : '';
        return strtr($text, [
            '{name}'  => $name !== '' ? $name : 'ग्राहक',
            '{first}' => $first !== '' ? $first : 'ग्राहक',
            '{link}'  => self::link($c),
            '{phone}' => Settings::officePhone(),
        ]);
    }

    /** Text for manual WhatsApp / SMS (with the opt-out line). */
    public static function textFor(array $c, array $r): string
    {
        $t = self::render((string) $c['message'], $c, $r);
        if (!str_contains((string) $c['message'], '{link}') && trim($t) !== '') {
            $t .= "\n" . self::link($c);
        }
        return $t . "\n\n" . Settings::getString('mk_optout_line', 'यस्ता सन्देश नचाहिए STOP लेख्नुहोस्।');
    }

    /**
     * Send to ONE recipient now (worker + test send).
     *
     * @return array{ok:bool, ref:string, error:string}
     */
    public static function sendOne(array $c, array $r): array
    {
        $phone = (string) $r['phone'];
        try {
            if ($c['channel'] === 'wa_template') {
                require_once ROOT_PATH . '/whatsapp/aisensy.php';
                if (!AiSensyAPI::enabled() || (string) $c['template_name'] === '') {
                    return ['ok' => false, 'ref' => '', 'error' => 'AiSensy or template not set'];
                }
                $params = array_map(static fn ($p) => self::render((string) $p, $c, $r), json_decode((string) $c['template_params'], true) ?: []);
                $x = AiSensyAPI::sendMessage($phone, (string) $c['template_name'], $params, $c['header_image'] ?: null);
                return ['ok' => !empty($x['success']), 'ref' => (string) ($x['message_id'] ?? ''), 'error' => mb_substr((string) ($x['error'] ?? ''), 0, 250)];
            }
            if ($c['channel'] === 'sms') {
                require_once INCLUDE_PATH . '/notify.php';
                $ok = Notify::sms($phone, mb_substr(self::textFor($c, $r), 0, 600));
                return ['ok' => $ok, 'ref' => '', 'error' => $ok ? '' : 'SMS provider did not accept it'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'ref' => '', 'error' => mb_substr($e->getMessage(), 0, 250)];
        }
        return ['ok' => false, 'ref' => '', 'error' => 'manual channel — send from the one-tap list'];
    }

    public static function quietNow(): bool
    {
        $h = (int) date('G');
        $from = Settings::getInt('mk_quiet_from', 21);
        $to   = Settings::getInt('mk_quiet_to', 8);
        return $from > $to ? ($h >= $from || $h < $to) : ($h >= $from && $h < $to);
    }

    public static function sentToday(): int
    {
        return (int) (Database::fetch("SELECT COUNT(*) n FROM mk_recipients WHERE sent_at >= CURDATE() AND state IN ('sent','delivered','read')")['n'] ?? 0);
    }

    /**
     * Cron worker: one minute's worth for every running campaign.
     *
     * @return array<string,int|string>
     */
    public static function work(): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'campaigns' => 0, 'synced' => 0];
        if (!self::ready()) {
            return ['skipped' => 'tables missing'];
        }
        Database::run("UPDATE mk_campaigns SET status = 'running' WHERE status = 'scheduled' AND start_at IS NOT NULL AND start_at <= NOW()");
        $lock = (int) (Database::fetch("SELECT GET_LOCK('shg_mk_worker', 0) AS l")['l'] ?? 0);
        if ($lock !== 1) {
            return ['skipped' => 'another worker is running'];
        }
        try {
            $out['synced'] = self::syncStatus(40);
            if (self::quietNow()) {
                $out['quiet'] = 'quiet hours';
                return $out;
            }
            $cap  = max(1, Settings::getInt('mk_daily_cap', 300));
            $left = $cap - self::sentToday();
            foreach (Database::fetchAll("SELECT * FROM mk_campaigns WHERE status = 'running' AND channel IN ('wa_template','sms') ORDER BY id") as $c) {
                if ($left <= 0) {
                    $out['cap'] = 'daily cap reached';
                    break;
                }
                $out['campaigns']++;
                $n = min((int) $c['rate_per_min'], $left);
                $batch = Database::fetchAll("SELECT * FROM mk_recipients WHERE campaign_id = :c AND state = 'queued' ORDER BY id LIMIT " . (int) $n, ['c' => (int) $c['id']]);
                if ($batch === []) {
                    Database::update('mk_campaigns', ['status' => 'done', 'finished_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $c['id']]);
                    self::recount((int) $c['id']);
                    continue;
                }
                $opt = CustomerDirectory::optedOut();
                foreach ($batch as $r) {
                    if (isset($opt[substr((string) $r['phone'], -10)])) {
                        Database::update('mk_recipients', ['state' => 'optout'], 'id = :id', ['id' => (int) $r['id']]);
                        continue;
                    }
                    Database::update('mk_recipients', ['state' => 'sending'], "id = :id AND state = 'queued'", ['id' => (int) $r['id']]);
                    $x = self::sendOne($c, $r);
                    /* 7 Oct 2026: an empty AiSensy wallet refuses EVERY number the
                       same way. Put this person back in the queue, pause the
                       campaign and stop - instead of marking the whole list
                       failed in a few minutes with no way back. */
                    if (!$x['ok'] && stripos((string) $x['error'], 'Conversation Credits') !== false) {
                        Database::update('mk_recipients', ['state' => 'queued'], 'id = :id', ['id' => (int) $r['id']]);
                        Database::update('mk_campaigns', ['status' => 'paused'], 'id = :id', ['id' => (int) $c['id']]);
                        $out['paused'] = 'AiSensy wallet empty - campaign #' . (int) $c['id'] . ' paused';
                        Logger::warning('Campaign paused: AiSensy wallet empty', ['campaign' => (int) $c['id']], 'marketing');
                        break;
                    }
                    Database::update('mk_recipients', [
                        'state' => $x['ok'] ? 'sent' : 'failed', 'provider_ref' => $x['ref'] ?: null,
                        'error' => $x['error'] ?: null, 'sent_at' => date('Y-m-d H:i:s'),
                    ], 'id = :id', ['id' => (int) $r['id']]);
                    $x['ok'] ? $out['sent']++ : $out['failed']++;
                    $left--;
                }
                self::recount((int) $c['id']);
            }
        } finally {
            Database::fetch("SELECT RELEASE_LOCK('shg_mk_worker')");
        }
        return $out;
    }

    /** Read delivered / read / failed back from AiSensy for recent sends. */
    public static function syncStatus(int $max): int
    {
        $rows = Database::fetchAll(
            "SELECT r.id, r.campaign_id, r.phone, r.provider_ref FROM mk_recipients r
               JOIN mk_campaigns c ON c.id = r.campaign_id AND c.channel = 'wa_template'
              WHERE r.state IN ('sent','delivered') AND r.provider_ref IS NOT NULL
                AND r.sent_at < NOW() - INTERVAL 2 MINUTE AND r.sent_at > NOW() - INTERVAL 3 DAY
                AND (r.checked_at IS NULL OR r.checked_at < NOW() - INTERVAL 20 MINUTE)
              ORDER BY r.checked_at IS NOT NULL, r.checked_at LIMIT " . (int) $max
        );
        if ($rows === []) {
            return 0;
        }
        require_once ROOT_PATH . '/whatsapp/aisensy.php';
        $touched = [];
        foreach ($rows as $r) {
            $s = AiSensyAPI::messageStatus((string) $r['provider_ref'], (string) $r['phone']);
            $st = ['DELIVERED' => 'delivered', 'READ' => 'read', 'FAILED' => 'failed'][$s['status']] ?? null;
            $set = ['checked_at' => date('Y-m-d H:i:s')];
            if ($st !== null) {
                $set['state'] = $st;
                if ($st === 'failed') {
                    $set['error'] = mb_substr((string) ($s['error'] ?? 'failed after sending'), 0, 250);
                }
            }
            Database::update('mk_recipients', $set, 'id = :id', ['id' => (int) $r['id']]);
            $touched[(int) $r['campaign_id']] = true;
        }
        foreach (array_keys($touched) as $cid) {
            self::recount($cid);
        }
        return count($rows);
    }

    /** Block / unblock a number for marketing. */
    public static function optOut(string $phone, string $reason, int $by): bool
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        $d10 = substr($d, -10);
        if (strlen($d10) !== 10) {
            return false;
        }
        Database::run('INSERT INTO mk_optout (phone, reason, created_by) VALUES (:p, :r, :b) ON DUPLICATE KEY UPDATE reason = VALUES(reason)',
            ['p' => $d10, 'r' => mb_substr($reason, 0, 120), 'b' => $by]);
        Database::run("UPDATE mk_recipients SET state = 'optout' WHERE state = 'queued' AND RIGHT(phone, 10) = :p", ['p' => $d10]);
        return true;
    }
}
