<?php
/**
 * =====================================================================
 *  SocialPosts — the Facebook post queue (24 Sep 2026; ported 16 Nov 2026
 *  for Master v2 P6-8a/b from commit dba925a6 + 1d83dc40).
 *
 *  A post is a row of social_posts: the text, an optional picture from our
 *  own website, an optional link, when to publish, and a state:
 *
 *      draft ──Approve──▶ approved ──cron──▶ publishing ──▶ published
 *        ▲                   │                    │
 *        └────Back to draft──┴────────────────────┴──▶ failed (error kept)
 *
 *  The office writes a draft by hand or from a button (the running offer's
 *  caption from includes/offercard.php, or the timetable from the ACTIVE
 *  routes), edits it, and APPROVES it. cron/social-publish.php then sends
 *  what is approved and due — only while social_publish_on = 1 (default
 *  OFF), never more than social_daily_cap a day, never the same row twice
 *  (a row is claimed before it is sent). Nothing here ever publishes a
 *  draft, and no button publishes "now": approving is the only door.
 *
 *  Facebook only. The old queue also had Instagram and Telegram drivers;
 *  the live table keeps those enum values, the code does not offer them.
 *
 *  The Page token is the SAME one the Messenger assistant uses
 *  (fb_page_token, saved on admin/facebook.php) — read here, never
 *  written. It goes in the POST body, never in a URL. Publishing a Page
 *  post needs the pages_manage_posts permission on that token; when Meta
 *  answers with a permission error the post is marked FAILED at once with
 *  Meta's own sentence, and nobody is messaged about it.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class SocialPosts
{
    public const CHANNELS = ['facebook'];
    public const GRAPH    = 'v21.0';
    public const MAX_TRIES = 3;
    /** Exception code: Meta refused for good (permission / token) — no retry. */
    public const E_FINAL = 4031;
    /** A post picture may only come from these public folders of our own site
        (never KYC, passengers, statements, vault ...). NOT uploads/company/:
        that is the encrypted company-papers vault (includes/companydocs.php). */
    public const MEDIA_DIRS = ['assets/img/', 'uploads/social/', 'uploads/places/', 'uploads/tours/'];
    public const MAX_TEXT = 5000;

    /** @var callable|null test hook: fn(array $post, string $caption): string remote id */
    public static $publisher = null;
    /** Test seam for the Graph call: fn(string $url, array $form): array (decoded JSON). */
    public static ?Closure $transport = null;

    private static ?bool $ready = null;

    private function __construct() {}

    public static function enabled(): bool
    {
        return Settings::getBool('social_publish_on', false);
    }

    /** Is the social_posts table there? (live gets the files before the migration) */
    public static function ready(bool $fresh = false): bool
    {
        if ($fresh || self::$ready === null) {
            try {
                self::$ready = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_posts'",
                    [], 0
                ) > 0;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** The Page token the Messenger assistant already uses — read only. */
    public static function pageToken(): string
    {
        require_once INCLUDE_PATH . '/fbmessenger.php';
        return FbMessenger::token();
    }

    /* =================================================================
     *  Writing drafts
     * ================================================================= */

    /** @param array<string,mixed> $in  text | caption_ne/hi/en, media_path, link_url, publish_at, topic */
    public static function create(array $in, int $adminId): int
    {
        self::needTable();
        $row = self::clean($in);
        $row['status']     = 'draft';
        $row['created_by'] = $adminId > 0 ? $adminId : null;
        $id = Database::insert('social_posts', $row);
        Logger::audit('social.draft', 'social_post', (string) $id, null, self::view($row), 'by admin #' . $adminId);
        return $id;
    }

    /** Edit a draft (an approved post goes back to draft first). */
    public static function update(int $id, array $in, int $adminId): void
    {
        self::needTable();
        $old = self::get($id);
        if (!in_array($old['status'], ['draft', 'cancelled'], true)) {
            throw new RuntimeException('Only a draft can be edited — press “Back to draft” first. / ड्राफ्ट मात्र सच्याउन मिल्छ।');
        }
        $row = self::clean($in);
        $n = Database::update('social_posts', $row, "id = :id AND status IN ('draft','cancelled')", ['id' => $id]);
        if ($n === 0 && !in_array((string) (self::get($id)['status'] ?? ''), ['draft', 'cancelled'], true)) {
            throw new RuntimeException('The post was approved meanwhile — nothing changed. Open the page again.');
        }
        $before = self::view($old);
        $after  = self::view($row);
        $changed = array_keys(array_filter($after, static fn($v, $k): bool => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        Logger::audit('social.edit', 'social_post', (string) $id,
            array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), 'by admin #' . $adminId);
    }

    /**
     * approve | draft | cancelled. Approve clears the tries and the error so
     * a post that failed can go again after the office has fixed it.
     */
    public static function setStatus(int $id, string $status, int $adminId): void
    {
        self::needTable();
        $from = match ($status) {
            'approved'  => ['draft', 'cancelled'],
            'draft'     => ['approved', 'failed', 'cancelled'],
            'cancelled' => ['draft', 'approved', 'failed'],
            default     => throw new RuntimeException('Only draft, approved or cancelled can be set by hand.'),
        };
        $row = self::get($id);
        if (in_array($row['status'], ['published', 'publishing'], true)) {
            throw new RuntimeException('That post is already ' . $row['status'] . '.');
        }
        if (!in_array($row['status'], $from, true)) {
            throw new RuntimeException('That post is ' . $row['status'] . ' — it cannot become ' . $status . ' from there.');
        }
        $in = "'" . implode("','", $from) . "'";
        $n = Database::run(
            'UPDATE social_posts SET status = :s, approved_by = :a, attempts = 0, error = NULL WHERE id = :id AND status IN (' . $in . ')',
            ['s' => $status, 'a' => $status === 'approved' && $adminId > 0 ? $adminId : null, 'id' => $id]
        );
        if ($n !== 1) {
            throw new RuntimeException('The post changed meanwhile — open the page again.');
        }
        Logger::audit($status === 'draft' ? 'social.back_to_draft' : 'social.' . $status, 'social_post', (string) $id,
            ['status' => $row['status']], ['status' => $status], 'by admin #' . $adminId);
    }

    /** Delete a post that never went out (draft, failed or cancelled). */
    public static function delete(int $id, int $adminId): void
    {
        self::needTable();
        $row = self::get($id);
        $n = Database::run("DELETE FROM social_posts WHERE id = :id AND status IN ('draft','failed','cancelled')", ['id' => $id]);
        if ($n !== 1) {
            throw new RuntimeException('Only a draft (or a failed post) can be deleted — this one is ' . $row['status'] . '.');
        }
        Logger::audit('social.delete', 'social_post', (string) $id, self::view($row) + ['status' => $row['status']], null, 'by admin #' . $adminId);
        // a pamphlet made for this draft alone goes with it
        $media = (string) ($row['media_path'] ?? '');
        if (str_starts_with($media, 'uploads/social/')
            && !Database::exists('SELECT 1 FROM social_posts WHERE media_path = :m', ['m' => $media])) {
            $f = ROOT_PATH . '/' . $media;
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /** @return array<string,mixed> */
    public static function get(int $id): array
    {
        $row = Database::fetch('SELECT * FROM social_posts WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw new RuntimeException('Post not found.');
        }
        return $row;
    }

    /** @return array<int,array<string,mixed>> the posts of one tab */
    public static function listTab(string $tab, int $limit = 100): array
    {
        if (!self::ready()) {
            return [];
        }
        $where = match ($tab) {
            'approved'  => "status IN ('approved','publishing','failed')",
            'published' => "status = 'published'",
            default     => "status IN ('draft','cancelled')",
        };
        $order = $tab === 'published' ? 'published_at DESC, id DESC' : 'publish_at, id';
        return Database::fetchAll('SELECT * FROM social_posts WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . max(1, min(500, $limit)));
    }

    /** @return array{draft:int, approved:int, published:int} */
    public static function counts(): array
    {
        $c = ['draft' => 0, 'approved' => 0, 'published' => 0];
        if (!self::ready()) {
            return $c;
        }
        foreach (Database::fetchAll('SELECT status, COUNT(*) n FROM social_posts GROUP BY status') as $r) {
            $tab = match ((string) $r['status']) {
                'approved', 'publishing', 'failed' => 'approved',
                'published' => 'published',
                default => 'draft',
            };
            $c[$tab] += (int) $r['n'];
        }
        return $c;
    }

    /**
     * Check and shape what the office typed. Throws a RuntimeException with
     * a sentence the page can show.
     * @return array<string,mixed> the social_posts columns
     */
    public static function clean(array $in): array
    {
        $channel = (string) ($in['channel'] ?? 'facebook');
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new RuntimeException('Only Facebook posts can be made here.');
        }
        $when = trim((string) ($in['publish_at'] ?? ''));
        $ts   = $when !== '' ? strtotime($when) : time() + 600;
        if ($ts === false || $ts < strtotime('2020-01-01') || $ts > strtotime('+2 years')) {
            throw new RuntimeException('Give a valid publish time. / प्रकाशित हुने समय मिलेन।');
        }
        if (array_key_exists('text', $in)) {
            $ne = self::text((string) $in['text']);
            $hi = $en = '';
        } else {
            $ne = self::text((string) ($in['caption_ne'] ?? ''));
            $hi = self::text((string) ($in['caption_hi'] ?? ''));
            $en = self::text((string) ($in['caption_en'] ?? ''));
        }
        if ($ne === '' && $hi === '' && $en === '') {
            throw new RuntimeException('Write the post text first. / पोस्टको लेख लेख्नुहोस्।');
        }
        $media = self::mediaPath((string) ($in['media_path'] ?? ''));
        $link  = self::linkUrl((string) ($in['link_url'] ?? ''));
        $topic = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in['topic'] ?? '')) ?? ''), 0, 60);
        return [
            'channel'    => $channel,
            'kind'       => $media !== '' ? 'photo' : 'text',
            'topic'      => $topic !== '' ? $topic : null,
            'caption_ne' => $ne !== '' ? $ne : null,
            'caption_hi' => $hi !== '' ? $hi : null,
            'caption_en' => $en !== '' ? $en : null,
            'media_path' => $media !== '' ? $media : null,
            'link_url'   => $link !== '' ? $link : null,
            'publish_at' => date('Y-m-d H:i:s', $ts),
        ];
    }

    private static function text(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        return mb_substr(trim($s), 0, self::MAX_TEXT);
    }

    /**
     * A picture from our own site → its path ('uploads/social/x.png'), or ''.
     * Accepts '/uploads/…', 'uploads/…' or a full URL on our own host; refuses
     * another website, a private folder, a non-picture or a missing file.
     */
    public static function mediaPath(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $raw) === 1) {
            $host = strtolower((string) parse_url($raw, PHP_URL_HOST));
            $own  = strtolower((string) parse_url(APP_URL, PHP_URL_HOST));
            $bare = static fn(string $h): string => (string) preg_replace('/^www\./', '', $h);
            if ($host === '' || $bare($host) !== $bare($own)) {
                throw new RuntimeException('The picture must be on our own website (' . $bare($own) . '), not another site.');
            }
            $raw = (string) parse_url($raw, PHP_URL_PATH);
        }
        $path = ltrim((string) preg_replace('/[?#].*$/', '', $raw), '/');
        $okDir = false;
        foreach (self::MEDIA_DIRS as $d) {
            if (str_starts_with($path, $d)) {
                $okDir = true;
                break;
            }
        }
        if (!$okDir || str_contains($path, '..') || str_contains($path, '\\')
            || preg_match('#^[A-Za-z0-9._/-]+\.(jpe?g|png|webp|gif)$#i', $path) !== 1) {
            throw new RuntimeException('Picture: give a .jpg / .png / .webp from ' . implode(', ', array_map(static fn($d) => '/' . $d, self::MEDIA_DIRS)) . ' on our site.');
        }
        if (!is_file(ROOT_PATH . '/' . $path)) {
            throw new RuntimeException('Picture not found on the server: /' . $path);
        }
        return mb_substr($path, 0, 255);
    }

    private static function linkUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $scheme = strtolower((string) parse_url($raw, PHP_URL_SCHEME));
        if (filter_var($raw, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true) || strlen($raw) > 255) {
            throw new RuntimeException('The link must be a full web address starting with https://');
        }
        return $raw;
    }

    /** What the audit log keeps of a post (the text shortened). */
    private static function view(array $row): array
    {
        return [
            'topic'      => $row['topic'] ?? null,
            'text'       => mb_substr(self::caption($row), 0, 200),
            'media_path' => $row['media_path'] ?? null,
            'link_url'   => $row['link_url'] ?? null,
            'publish_at' => $row['publish_at'] ?? null,
        ];
    }

    private static function needTable(): void
    {
        if (!self::ready()) {
            throw new RuntimeException('The posts table is not on this server yet (database/upgrade-2026-11-16b-W6-facebook.sql).');
        }
    }

    /** The one text the Page gets: Nepali, Hindi, English in that order, the link once. */
    public static function caption(array $post): string
    {
        $parts = array_filter([$post['caption_ne'] ?? '', $post['caption_hi'] ?? '', $post['caption_en'] ?? ''], static fn($s): bool => trim((string) $s) !== '');
        $txt = implode("\n\n", array_map(static fn($s): string => trim((string) $s), $parts));
        if (!empty($post['link_url']) && !str_contains($txt, (string) $post['link_url'])) {
            $txt .= "\n\n" . $post['link_url'];
        }
        return $txt;
    }

    /* =================================================================
     *  Drafts from the live data (P6-8b) — a DRAFT only, never published
     * ================================================================= */

    /**
     * A draft from the offer that is running in Admin → Offers, with the
     * caption the promo card already uses and, when GD can draw it, the
     * Nepali pamphlet saved under uploads/social/ as the picture.
     * $offer is for tests; null = OfferCard::offer().
     */
    public static function fromOffer(int $adminId, ?array $offer = null, bool $withPicture = true): int
    {
        require_once INCLUDE_PATH . '/offercard.php';
        $offer ??= OfferCard::offer();
        if ($offer === null) {
            throw new RuntimeException('No offer is running right now — switch one on in Offers & Discounts first. / अहिले कुनै अफर चलिरहेको छैन।');
        }
        self::needTable();
        [$phone, $site] = self::contact();
        $media = $withPicture ? self::offerPicture($offer, $phone, $site) : '';
        try {
            return self::create([
                'topic'      => 'Offer ' . (string) $offer['code'],
                'text'       => OfferCard::caption($offer, $phone, $site),
                'media_path' => $media,
            ], $adminId);
        } catch (Throwable $e) {
            if ($media !== '' && is_file(ROOT_PATH . '/' . $media)) {
                @unlink(ROOT_PATH . '/' . $media);      // no draft, so no orphan pamphlet either
            }
            throw $e;
        }
    }

    /** A draft with today's timetable of the ACTIVE routes. */
    public static function fromTimetable(int $adminId): int
    {
        $text = self::timetableText();
        if ($text === '') {
            throw new RuntimeException('No active route has pickup times yet — nothing to post. / समय भएको कुनै रुट छैन।');
        }
        return self::create(['topic' => 'Timetable ' . date('j M'), 'text' => $text], $adminId);
    }

    /**
     * Every active route with the times of its ON pickups (Admin → Routes),
     * the arrival, the lowest fare each way, and how to book. '' when no
     * active route has a time. Never invents a stop, a time or a fare.
     */
    public static function timetableText(): string
    {
        require_once INCLUDE_PATH . '/offercard.php';
        [$phone, $site] = self::contact();
        $lines = ['🕒 हरेक दिन बस — समय तालिका · Daily bus timetable'];
        $n = 0;
        $seen = [];
        foreach (Database::fetchAll('SELECT id, from_city, to_city, dep_time, arr_time, day_offset FROM routes WHERE is_active = 1 ORDER BY sort_order, dep_time, id') as $r) {
            $stops = [];
            foreach (Database::fetchAll(
                "SELECT stop_name, stop_time FROM route_stops WHERE route_id = :r AND stop_type = 'boarding'" . Boarding::activeSql() . ' ORDER BY sort_order, id',
                ['r' => (int) $r['id']]
            ) as $s) {
                if ((string) ($s['stop_time'] ?? '') !== '') {
                    $stops[] = self::stopName((string) $s['stop_name']) . ' ' . self::clock((string) $s['stop_time']);
                }
            }
            if ($stops === [] && (string) ($r['dep_time'] ?? '') !== '') {
                $stops[] = trim((string) $r['from_city']) . ' ' . self::clock((string) $r['dep_time']);
            }
            $to   = trim((string) $r['to_city']);
            $head = '🚌 ' . trim((string) $r['from_city']) . ' → ' . $to;
            $key  = $head . '|' . implode('|', $stops) . '|' . (string) ($r['arr_time'] ?? '');
            if ($stops === [] || isset($seen[$key])) {
                continue;                       // a second bus on the same times reads as one line
            }
            $seen[$key] = true;
            $n++;
            $lines[] = '';
            $lines[] = $head;
            $lines[] = 'चढ्ने · Board: ' . implode(' · ', $stops);
            if ((string) ($r['arr_time'] ?? '') !== '') {
                $day = (int) ($r['day_offset'] ?? 0);
                $lines[] = 'पुग्ने · Arrive: ' . $to . ' ' . self::clock((string) $r['arr_time'])
                    . ($day === 1 ? ' (भोलिपल्ट · next day)' : ($day === 2 ? ' (पर्सिपल्ट · day after next)' : ($day > 2 ? ' (+' . $day . ' days)' : '')));
            }
        }
        if ($n === 0) {
            return '';
        }
        $go   = OfferCard::fareFrom('go');
        $back = OfferCard::fareFrom('back');
        $lines[] = '';
        $lines[] = '💺 भाडा · Fare: Gujarat → Rupaidiha ₹' . number_format($go['min']) . ($go['varies'] ? ' देखि · from' : '')
                 . ' | Rupaidiha → Gujarat ₹' . number_format($back['min']) . ($back['varies'] ? ' देखि · from' : '');
        $lines[] = 'चढ्ने ठाउँमा ६० मिनेट अगाडि पुग्नुहोस्। · Please reach your pickup 60 minutes early.';
        $lines[] = '📞 ' . $phone . '  ·  🌐 https://' . $site;
        return implode("\n", $lines);
    }

    private static function stopName(string $name): string
    {
        try {
            $d = Boarding::stopDisplay($name);
            $n = trim((string) ($d['name'] ?? ''));
            if ($n !== '') {
                return $n;
            }
        } catch (Throwable $e) {
        }
        return trim((string) (explode('·', $name)[0] ?? $name));
    }

    private static function clock(string $time): string
    {
        $ts = strtotime('2000-01-01 ' . trim($time));
        return $ts === false ? trim($time) : date('g:i A', $ts);
    }

    /** [office phone, bare site] — the same pair admin/promo-card.php prints. */
    private static function contact(): array
    {
        $phone = '';
        try {
            require_once INCLUDE_PATH . '/sitefacts.php';
            $phone = (string) (SiteFacts::all()['head']['phone'] ?? '');
        } catch (Throwable $e) {
        }
        if ($phone === '') {
            $phone = Settings::officePhone();
        }
        $site = (string) preg_replace('#^https?://#', '', rtrim(APP_URL, '/'));
        $site = (string) preg_replace('#^www\.#', '', $site);
        return [$phone, $site];
    }

    /** The Nepali offer pamphlet as a file Facebook can fetch, or '' (best effort). */
    private static function offerPicture(array $offer, string $phone, string $site): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return '';
        }
        try {
            require_once INCLUDE_PATH . '/ticket.php';
            $png = OfferCard::png('ne', $offer, $phone, $site, Ticket::logoFile());
            if ($png === '') {
                return '';
            }
            $dir = ROOT_PATH . '/uploads/social';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return '';
            }
            $code = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $offer['code'])), '-');
            $name = 'offer-' . ($code !== '' ? $code : 'x') . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.png';
            $tmp  = $dir . '/' . $name . '.tmp';
            if (file_put_contents($tmp, $png) !== strlen($png) || !rename($tmp, $dir . '/' . $name)) {
                @unlink($tmp);
                return '';
            }
            return 'uploads/social/' . $name;
        } catch (Throwable $e) {
            Logger::warning('social offer picture skipped: ' . $e->getMessage(), [], 'marketing');
            return '';
        }
    }

    /* =================================================================
     *  Publishing (cron/social-publish.php)
     * ================================================================= */

    public static function sentToday(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM social_posts WHERE status = 'published' AND published_at >= :a AND published_at < :b",
            ['a' => date('Y-m-d 00:00:00'), 'b' => date('Y-m-d 00:00:00', strtotime('+1 day'))], 0
        );
    }

    /**
     * Publish everything approved and due. Returns a summary for cron.
     * @return array<string,mixed>
     */
    public static function work(int $max = 5): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => []];
        if (!self::ready(true)) {
            $out['idle'] = 'social_posts table missing';
            return $out;
        }
        if (!self::enabled()) {
            $out['idle'] = 'social_publish_on is off';
            return $out;
        }
        $cap   = max(1, Settings::getInt('social_daily_cap', 6));
        $tried = [0];   // a post that failed this run waits for the next run, not the next loop
        for ($i = 0; $i < $max; $i++) {
            if (self::sentToday() >= $cap) {
                $out['skipped'][] = 'daily cap ' . $cap . ' reached';
                break;
            }
            $row = Database::fetch(
                "SELECT * FROM social_posts WHERE status = 'approved' AND channel = 'facebook' AND publish_at <= :now AND attempts < " . self::MAX_TRIES
                . ' AND id NOT IN (' . implode(',', array_map('intval', $tried)) . ') ORDER BY publish_at, id LIMIT 1',
                ['now' => date('Y-m-d H:i:s')]
            );
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
                Logger::audit('social.published', 'social_post', (string) $row['id'], ['status' => 'approved'], ['status' => 'published', 'remote_id' => $remote], 'by the post queue');
            } catch (Throwable $e) {
                // A permission / token refusal will not mend itself: failed now, error kept.
                $again = $e->getCode() !== self::E_FINAL && (int) $row['attempts'] + 1 < self::MAX_TRIES;
                Database::update('social_posts', ['status' => $again ? 'approved' : 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)], 'id = :id', ['id' => (int) $row['id']]);
                $out['failed']++;
                Logger::warning('social publish failed', ['id' => (int) $row['id'], 'final' => !$again, 'e' => mb_substr($e->getMessage(), 0, 300)], 'marketing');
            }
        }
        return $out;
    }

    /** @return string Facebook's id for the post */
    public static function publish(array $post): string
    {
        $caption = self::caption($post);
        if (self::$publisher !== null) {
            return (string) (self::$publisher)($post, $caption);
        }
        if ((string) ($post['channel'] ?? '') !== 'facebook') {
            throw new RuntimeException('Only Facebook posts are published by this queue.', self::E_FINAL);
        }
        $mediaUrl = !empty($post['media_path']) ? appUrl(ltrim((string) $post['media_path'], '/')) : '';
        return self::facebook($caption, $mediaUrl);
    }

    /** Meta's answer is a refusal that retrying cannot fix (permission or token). */
    public static function isFinalError(int $code, string $message): bool
    {
        return $code === 10 || $code === 190 || ($code >= 200 && $code <= 299)
            || preg_match('/pages_manage_posts|pages_read_engagement|permission/i', $message) === 1;
    }

    private static function facebook(string $caption, string $mediaUrl): string
    {
        $tok = self::pageToken();
        if ($tok === '') {
            throw new RuntimeException('Facebook: no Page token saved (Admin → Facebook Messenger AI).', self::E_FINAL);
        }
        $page = trim(Settings::getString('fb_page_id', ''));
        $node = preg_match('/^\d{5,25}$/', $page) === 1 ? $page : 'me';    // a Page token's "me" is the Page
        $url  = 'https://graph.facebook.com/' . self::GRAPH . '/' . $node . ($mediaUrl !== '' ? '/photos' : '/feed');
        $form = $mediaUrl !== '' ? ['url' => $mediaUrl, 'caption' => $caption] : ['message' => $caption];
        $form['access_token'] = $tok;
        $r = self::http($url, $form);
        if (!empty($r['error'])) {
            $msg  = (string) ($r['error']['message'] ?? 'error');
            $code = (int) ($r['error']['code'] ?? 0);
            throw new RuntimeException('Facebook: ' . $msg . ($code > 0 ? ' (code ' . $code . ')' : ''), self::isFinalError($code, $msg) ? self::E_FINAL : 0);
        }
        $id = (string) ($r['post_id'] ?? $r['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Facebook: the answer carried no post id.');
        }
        return $id;
    }

    /** @return array<string,mixed> */
    private static function http(string $url, array $form): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($url, $form);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is not available on this server.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => http_build_query($form),
            CURLOPT_TIMEOUT           => 25,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('network: ' . $err);
        }
        $j = json_decode((string) $body, true);
        return is_array($j) ? $j : ['error' => ['message' => 'non-JSON answer']];
    }
}
