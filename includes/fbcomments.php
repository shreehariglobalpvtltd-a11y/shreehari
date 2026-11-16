<?php
/**
 * =====================================================================
 *  FbComments — the comments under our Facebook Page's posts
 *  (16 Nov 2026, Master v2 P6-6b + P6-7b).
 *
 *  READ   cron/fb-comments.php asks the Graph API every 10 minutes for the
 *         Page's posts of the last 7 days and the comments under them
 *         (small pages, a hard cap per run) and keeps them in fb_comments
 *         (database/upgrade-2026-11-16c-X4-fb-comments.sql). Only while
 *         fb_comments_on = 1 (default OFF). The Page token is the SAME one
 *         the Messenger assistant uses (FbMessenger::token(), saved on
 *         admin/facebook.php) — read here, never written, and sent in the
 *         Authorization header, never in a URL.
 *
 *  FLAG   a complaint, an abusive word or an angry emoji marks the comment
 *         "flagged" for the office. Nobody is messaged, nothing is posted.
 *
 *  REPLY  admin/fb-comments.php: a person from the office writes a reply;
 *         it is posted IN PUBLIC under the comment's thread as the Page.
 *         CSRF, audited (fb.comment_reply).
 *
 *  AI     only while fb_comment_ai_on = 1 as well (default OFF): a fare
 *         question gets ONE short line with the fare page (appUrl('bus'),
 *         the route pages' index), a how-to-book question gets the booking
 *         link + the office number line the WhatsApp FAQ uses. Fixed
 *         words, never free AI text in public. A complaint / abuse / anger
 *         is NEVER answered (flagged instead); a personal question (their
 *         own ticket, a PNR, a phone number), a link or anything else is
 *         left to the office. At most one automatic reply per thread (and
 *         none where the Page or the office already answered, or a comment
 *         in the thread is flagged), at most 20 an hour, only on comments
 *         of the last 24 hours. A row is claimed before the reply is sent,
 *         so a retry never posts twice.
 *
 *  PERMISSIONS  reading needs pages_read_engagement (and, for names,
 *         pages_read_user_content); replying needs pages_manage_engagement.
 *         When Meta refuses for a permission / token reason, the error is
 *         recorded ONCE in the settings row fb_comments_state (JSON) and the
 *         cron does nothing else — it looks again after 6 hours, or at once
 *         when the office presses "Check again".
 *
 *  Every call from the cron is wrapped: a Graph or database problem ends
 *  the run quietly with a reason, never a fatal.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class FbComments
{
    public const GRAPH          = 'v21.0';
    public const DAYS           = 7;      // posts (and comments) this recent
    public const POST_LIMIT     = 10;     // posts per Graph page
    public const POST_PAGES     = 2;
    public const COMMENT_LIMIT  = 25;     // comments per Graph page
    public const COMMENT_PAGES  = 2;
    public const MAX_PER_RUN    = 300;    // comments looked at per run
    public const AUTO_PER_HOUR  = 20;
    public const AUTO_MAX_AGE_H = 24;     // the AI answers only fresh comments
    public const RETRY_AFTER_H  = 6;      // after a permission error
    public const MAX_REPLY      = 1000;
    public const TABS           = ['comments', 'flagged', 'all'];

    /** Test seam for the Graph call: fn(string $method, string $url, array $params): array (decoded JSON). */
    public static ?Closure $transport = null;
    /** Test seam: fb_comments_state in memory instead of the settings row. */
    public static ?array $memState = null;

    private static ?bool $ready = null;

    private function __construct() {}

    /* =================================================================
     *  Switches, token, table
     * ================================================================= */

    public static function on(): bool
    {
        return Settings::getBool('fb_comments_on', false);
    }

    public static function aiOn(): bool
    {
        return self::on() && Settings::getBool('fb_comment_ai_on', false);
    }

    /** The Messenger page's Page token — read only. */
    public static function token(): string
    {
        require_once INCLUDE_PATH . '/fbmessenger.php';
        return FbMessenger::token();
    }

    /** Is fb_comments there? (live gets the files before the migration) */
    public static function ready(bool $fresh = false): bool
    {
        if ($fresh || self::$ready === null) {
            try {
                self::$ready = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fb_comments'",
                    [], 0
                ) > 0;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** A Graph id: digits, maybe "page_post" / "post_comment". Nothing else reaches a path. */
    public static function validId(string $id): bool
    {
        return preg_match('/^[0-9]{1,40}(?:_[0-9]{1,40}){0,2}$/', $id) === 1;
    }

    /* =================================================================
     *  The permission-error memory (settings JSON fb_comments_state)
     * ================================================================= */

    /** @return array<string,mixed> {} or {error, code, where, at, tries} */
    public static function state(): array
    {
        if (self::$memState !== null) {
            return self::$memState;
        }
        try {
            return Settings::getArray('fb_comments_state', []);
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function saveState(array $st): void
    {
        if (self::$memState !== null) {
            self::$memState = $st;
            return;
        }
        Settings::set('fb_comments_state', $st === [] ? new stdClass() : $st, 'json', 'facebook');
    }

    /**
     * True while a recorded permission error is younger than RETRY_AFTER_H.
     * 'read': a refusal to READ stops the reading; a refusal to REPLY
     * (pages_manage_engagement missing) does not stop the office seeing the
     * comments. 'reply': any recorded refusal stops the automatic replies.
     */
    public static function waiting(string $for = 'read'): bool
    {
        $st = self::state();
        $at = (string) ($st['at'] ?? '');
        if ((string) ($st['error'] ?? '') === '' || $at === '') {
            return false;
        }
        if ($for === 'read' && (string) ($st['where'] ?? '') === 'reply') {
            return false;
        }
        $ts = strtotime($at);
        return $ts !== false && $ts > time() - self::RETRY_AFTER_H * 3600;
    }

    /** Forget a recorded error of this kind ('read' = posts / comments, 'reply'), after Meta said yes. */
    private static function healed(string $kind): void
    {
        $st = self::state();
        if ((string) ($st['error'] ?? '') === '') {
            return;
        }
        $isReply = (string) ($st['where'] ?? '') === 'reply';
        if (($kind === 'reply') !== $isReply) {
            return;
        }
        try {
            self::saveState([]);
            Logger::info('Facebook comments: Meta answers again (' . $kind . ')', [], 'facebook');
        } catch (Throwable $e) {
        }
    }

    /** Meta's answer is a permission / token refusal that waiting cannot fix. */
    public static function isPermissionError(int $code, string $message): bool
    {
        return $code === 10 || $code === 102 || $code === 190 || ($code >= 200 && $code <= 299)
            || preg_match('/pages_read_engagement|pages_manage_engagement|pages_read_user_content|permission|access token/i', $message) === 1;
    }

    /**
     * Write the error down ONCE: the same error again only moves its time
     * (so the cron waits another RETRY_AFTER_H), it is never logged twice.
     */
    public static function recordError(string $message, int $code, string $where): void
    {
        $message = mb_substr(trim($message), 0, 300);
        $st = self::state();
        $same = (string) ($st['error'] ?? '') === $message && (int) ($st['code'] ?? 0) === $code;
        try {
            self::saveState([
                'error' => $message,
                'code'  => $code,
                'where' => $where,
                'at'    => date('Y-m-d H:i:s'),
                'since' => $same ? (string) ($st['since'] ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s'),
                'tries' => $same ? (int) ($st['tries'] ?? 1) + 1 : 1,
            ]);
            if (!$same) {
                Logger::warning('Facebook comments: Meta refused (' . $where . ')', ['code' => $code, 'error' => $message], 'facebook');
            }
        } catch (Throwable $e) {
        }
    }

    /** "Check again" on admin/fb-comments.php: forget the error, the next run asks Meta. Audited. */
    public static function clearError(int $adminId): void
    {
        $old = self::state();
        if ($old === []) {
            return;
        }
        self::saveState([]);
        Logger::audit('fb.comments_recheck', 'facebook', 'fb_comments_state',
            ['error' => mb_substr((string) ($old['error'] ?? ''), 0, 200), 'at' => $old['at'] ?? null], ['error' => null],
            'by admin #' . $adminId);
    }

    /* =================================================================
     *  The cron
     * ================================================================= */

    /**
     * One run: read the comments, then (AI switch on) answer what may be
     * answered. Never two runs at once (a MySQL named lock).
     *
     * @return array<string,mixed> a summary for cron_done()
     */
    public static function run(): array
    {
        if (!self::ready(true)) {
            return ['idle' => 'fb_comments table missing'];
        }
        if (!self::on()) {
            return ['idle' => 'fb_comments_on is off'];
        }
        $locked = false;
        try {
            $locked = (int) Database::scalar("SELECT GET_LOCK('shg_fb_comments', 0)", [], 0) === 1;
        } catch (Throwable $e) {
            $locked = true;     // no named locks on this server: carry on, the row claims still hold
        }
        if (!$locked) {
            return ['idle' => 'another run is busy'];
        }
        try {
            $out = self::poll();
            if (!isset($out['idle']) && !isset($out['refused'])) {
                $out += self::autoReply();
            }
            return $out;
        } finally {
            try {
                Database::scalar("SELECT RELEASE_LOCK('shg_fb_comments')");
            } catch (Throwable $e) {
            }
        }
    }

    /**
     * Read the Page's recent posts and their comments into fb_comments.
     *
     * @return array<string,mixed>
     */
    public static function poll(): array
    {
        $out = ['posts' => 0, 'seen' => 0, 'new' => 0, 'flagged' => 0];
        if (!self::ready()) {
            return $out + ['idle' => 'fb_comments table missing'];
        }
        if (!self::on()) {
            return $out + ['idle' => 'fb_comments_on is off'];
        }
        if (self::token() === '') {
            return $out + ['idle' => 'no Page token saved'];
        }
        if (self::waiting('read')) {
            return $out + ['idle' => 'waiting after a Meta permission error'];
        }
        $page = trim(Settings::getString('fb_page_id', ''));
        $node = preg_match('/^\d{5,25}$/', $page) === 1 ? $page : 'me';    // a Page token's "me" is the Page
        $since = time() - self::DAYS * 86400;

        $posts = [];
        $path = $node . '/posts';
        $params = [
            'fields' => 'id,message,created_time,comments.limit(0).summary(true)',
            'since'  => (string) $since,
            'limit'  => (string) self::POST_LIMIT,
        ];
        for ($i = 0; $i < self::POST_PAGES && $path !== ''; $i++) {
            $r = self::call('GET', $path, $params);
            if (isset($r['error'])) {
                return self::refused($r, 'posts', $out);
            }
            foreach ((array) ($r['data'] ?? []) as $p) {
                if (is_array($p) && self::validId((string) ($p['id'] ?? ''))) {
                    $posts[] = $p;
                }
            }
            $path = (string) ($r['paging']['next'] ?? '');
            $params = [];
        }

        foreach ($posts as $p) {
            if ($out['seen'] >= self::MAX_PER_RUN) {
                break;
            }
            $out['posts']++;
            $pid = (string) $p['id'];
            if ((int) ($p['comments']['summary']['total_count'] ?? -1) === 0) {
                continue;                                   // nobody wrote under it
            }
            $batch = [];
            $cPath = $pid . '/comments';
            $cParams = [
                'fields' => 'id,message,created_time,from{id,name},parent{id},is_hidden',
                'filter' => 'stream',                     // replies too, each with its parent
                'order'  => 'reverse_chronological',
                'limit'  => (string) self::COMMENT_LIMIT,
            ];
            for ($j = 0; $j < self::COMMENT_PAGES && $cPath !== ''; $j++) {
                $r = self::call('GET', $cPath, $cParams);
                if (isset($r['error'])) {
                    if (self::isPermissionError((int) ($r['error']['code'] ?? 0), (string) ($r['error']['message'] ?? ''))) {
                        return self::refused($r, 'comments', $out);
                    }
                    $out['errors'] = (int) ($out['errors'] ?? 0) + 1;
                    break;
                }
                $old = false;
                foreach ((array) ($r['data'] ?? []) as $c) {
                    $ts = strtotime((string) ($c['created_time'] ?? ''));
                    if ($ts !== false && $ts < $since) {
                        $old = true;
                        continue;
                    }
                    if (is_array($c)) {
                        $batch[] = $c;
                    }
                }
                if ($old || count($batch) + $out['seen'] >= self::MAX_PER_RUN) {
                    break;
                }
                $cPath = (string) ($r['paging']['next'] ?? '');
                $cParams = [];
            }
            if ($batch !== []) {
                self::store($pid, (string) ($p['message'] ?? ''), $batch, $page, $out);
            }
        }

        // Meta let us read: a reading refusal written earlier is over.
        self::healed('read');
        return $out;
    }

    /** @param list<array<string,mixed>> $batch one post's comments as Meta sent them */
    private static function store(string $pid, string $postText, array $batch, string $pageId, array &$out): void
    {
        $owner = explode('_', $pid)[0];                     // a post id is "<page id>_<post>"
        $existing = [];
        foreach (Database::fetchAll('SELECT comment_id, message, status, is_page, flagged_reason FROM fb_comments WHERE post_id = :p', ['p' => $pid]) as $r) {
            $existing[(string) $r['comment_id']] = $r;
        }
        $pageAnswered = [];                                 // thread => when the Page last wrote in it
        $postText = mb_substr(self::clean($postText), 0, 200);
        foreach ($batch as $c) {
            if ($out['seen'] >= self::MAX_PER_RUN) {
                break;
            }
            $cid = (string) ($c['id'] ?? '');
            if (!self::validId($cid)) {
                continue;
            }
            $out['seen']++;
            $parent = (string) ($c['parent']['id'] ?? '');
            $parent = self::validId($parent) ? $parent : '';
            $thread = $parent !== '' ? $parent : $cid;
            $fromId = (string) ($c['from']['id'] ?? '');
            $isPage = $fromId !== '' && ($fromId === $owner || ($pageId !== '' && $fromId === $pageId));
            $msg    = self::clean((string) ($c['message'] ?? ''));
            $hidden = !empty($c['is_hidden']);
            $ts     = strtotime((string) ($c['created_time'] ?? ''));
            $at     = date('Y-m-d H:i:s', $ts !== false ? $ts : time());
            if ($isPage && strcmp($at, (string) ($pageAnswered[$thread] ?? '')) > 0) {
                $pageAnswered[$thread] = $at;
            }

            if (isset($existing[$cid])) {
                $was     = (string) $existing[$cid]['status'];
                $now     = $was;
                $reason  = $existing[$cid]['flagged_reason'] ?? null;
                $changed = (string) ($existing[$cid]['message'] ?? '') !== $msg;
                // Edited into a complaint / abuse after it was read: flag it (an edit never un-flags).
                if (!$isPage && $changed && in_array($was, ['new', 'hidden'], true)) {
                    $cl = self::classify($msg);
                    if ($cl['kind'] === 'negative') {
                        $reason = mb_substr($cl['reason'], 0, 190);
                        $now    = $was === 'new' ? 'flagged' : $was;
                    }
                }
                if (!$isPage && $hidden && in_array($now, ['new', 'flagged'], true)) {
                    $now = 'hidden';
                } elseif (!$isPage && !$hidden && $now === 'hidden') {
                    // Back on Facebook: a flagged comment stays flagged (and keeps its thread away from the AI).
                    $now = (string) ($reason ?? '') !== '' ? 'flagged' : 'new';
                }
                if ($now !== $was || $changed || $reason !== ($existing[$cid]['flagged_reason'] ?? null)) {
                    Database::update('fb_comments', ['message' => $msg, 'status' => $now, 'flagged_reason' => $reason], 'comment_id = :c', ['c' => $cid]);
                }
                continue;
            }

            $status = 'new';
            $reason = null;
            if ($isPage) {
                $status = 'replied';
            } else {
                // A hidden comment is classified too, so it is still flagged if Facebook shows it again.
                $cl = self::classify($msg);
                if ($cl['kind'] === 'negative') {
                    $status = 'flagged';
                    $reason = mb_substr($cl['reason'], 0, 190);
                }
                if ($hidden) {
                    $status = 'hidden';
                }
            }
            $name = mb_substr(self::clean((string) ($c['from']['name'] ?? '')), 0, 120);
            $id = Database::insertIgnore('fb_comments', [
                'comment_id'     => $cid,
                'post_id'        => $pid,
                'parent_id'      => $parent !== '' ? $parent : null,
                'thread_id'      => $thread,
                'post_text'      => $postText !== '' ? $postText : null,
                'from_name'      => $name !== '' ? $name : null,
                'is_page'        => $isPage ? 1 : 0,
                'message'        => $msg,
                'created_time'   => $at,
                'status'         => $status,
                'flagged_reason' => $reason,
            ]);
            if ($id > 0) {
                $out['new']++;
                if ($status === 'flagged') {
                    $out['flagged']++;
                }
            }
        }
        // The Page answered in a thread (here, by the AI, or in Meta Business
        // Suite): what was written before that answer is no longer waiting.
        foreach ($pageAnswered as $thread => $at) {
            Database::run(
                "UPDATE fb_comments SET status = 'replied'
                  WHERE thread_id = :t AND is_page = 0 AND status IN ('new','flagged') AND created_time <= :at",
                ['t' => (string) $thread, 'at' => $at]
            );
        }
    }

    /** @return array<string,mixed> the run's summary after Meta refused */
    private static function refused(array $r, string $where, array $out): array
    {
        $msg  = (string) ($r['error']['message'] ?? 'error');
        $code = (int) ($r['error']['code'] ?? 0);
        if (self::isPermissionError($code, $msg)) {
            self::recordError($msg, $code, $where);
            return $out + ['refused' => mb_substr($msg, 0, 160)];
        }
        Logger::warning('Facebook comments: Graph error (' . $where . ')', ['code' => $code, 'error' => mb_substr($msg, 0, 200)], 'facebook');
        return $out + ['idle' => 'Graph error: ' . mb_substr($msg, 0, 120)];
    }

    /**
     * The automatic public replies (fb_comment_ai_on). Fixed words only.
     *
     * @return array<string,mixed>
     */
    public static function autoReply(int $max = 10): array
    {
        $out = ['auto' => 0, 'auto_failed' => 0];
        if (!self::ready() || !self::aiOn()) {
            return $out + ['ai' => 'off'];
        }
        if (self::token() === '' || self::waiting('reply')) {
            return $out + ['ai' => 'waiting'];
        }
        $used = (int) Database::scalar(
            "SELECT COUNT(*) FROM fb_comments WHERE reply_kind = 'auto' AND replied_at >= :h",
            ['h' => date('Y-m-d H:i:s', time() - 3600)], 0
        );
        if ($used >= self::AUTO_PER_HOUR) {
            return $out + ['ai' => 'hourly cap ' . self::AUTO_PER_HOUR . ' reached'];
        }
        $rows = Database::fetchAll(
            "SELECT * FROM fb_comments
              WHERE status = 'new' AND is_page = 0 AND reply_kind IS NULL AND created_time >= :a
              ORDER BY created_time, id LIMIT " . self::MAX_PER_RUN,     // thank-yous stay 'new' too: a short scan would starve fresh questions
            ['a' => date('Y-m-d H:i:s', time() - self::AUTO_MAX_AGE_H * 3600)]
        );
        foreach ($rows as $r) {
            if ($out['auto'] >= $max || $used >= self::AUTO_PER_HOUR) {
                break;
            }
            $cl = self::classify((string) ($r['message'] ?? ''));
            if (!in_array($cl['kind'], ['fare', 'book', 'fare+book'], true)) {
                continue;                                   // the office answers it (or nobody needs to)
            }
            if (self::threadTaken((string) $r['thread_id'])) {
                continue;                                   // one automatic reply per thread, and never after a person
            }
            $now = date('Y-m-d H:i:s');
            // Claim before sending: a second worker (or a retry) never posts twice.
            $claimed = Database::run(
                "UPDATE fb_comments SET reply_kind = 'auto', replied_at = :now WHERE id = :id AND reply_kind IS NULL AND status = 'new'",
                ['now' => $now, 'id' => (int) $r['id']]
            );
            if ($claimed !== 1) {
                continue;
            }
            $used++;
            $text = self::replyText($cl['kind'], (string) $r['message']);
            $res  = self::postReply((string) $r['thread_id'], $text);
            if ($res['ok']) {
                Database::update('fb_comments', [
                    'status' => 'replied', 'our_reply_id' => $res['id'], 'reply_text' => $text, 'reply_error' => null,
                ], 'id = :id', ['id' => (int) $r['id']]);
                $out['auto']++;
                self::healed('reply');
                Logger::audit('fb.comment_auto_reply', 'fb_comment', (string) $r['id'],
                    ['status' => 'new'], ['status' => 'replied', 'kind' => $cl['kind'], 'our_reply_id' => $res['id']],
                    'automatic public reply (fb_comment_ai_on)');
            } elseif ($res['permission']) {
                // Nothing went out: the claim is given back, the cron waits.
                Database::update('fb_comments', ['reply_kind' => null, 'replied_at' => null, 'reply_error' => mb_substr($res['error'], 0, 255)],
                    'id = :id', ['id' => (int) $r['id']]);
                self::recordError($res['error'], $res['code'], 'reply');
                $out['auto_failed']++;
                $out['refused'] = mb_substr($res['error'], 0, 160);
                break;
            } else {
                // The claim stays: an answer that may have reached Meta is never sent again.
                Database::update('fb_comments', ['reply_text' => $text, 'reply_error' => mb_substr($res['error'], 0, 255)],
                    'id = :id', ['id' => (int) $r['id']]);
                $out['auto_failed']++;
                Logger::warning('Facebook comments: automatic reply failed', ['id' => (int) $r['id'], 'error' => mb_substr($res['error'], 0, 200)], 'facebook');
            }
        }
        return $out;
    }

    /** Someone (the Page, the office or the AI) already answered in this thread, or it holds a flagged comment. */
    private static function threadTaken(string $thread): bool
    {
        return Database::exists(
            "SELECT 1 FROM fb_comments WHERE thread_id = :t AND (reply_kind IS NOT NULL OR is_page = 1 OR status = 'flagged' OR flagged_reason IS NOT NULL) LIMIT 1",
            ['t' => $thread]
        );
    }

    /* =================================================================
     *  What a comment is about — fixed word lists, no AI call
     * ================================================================= */

    /* Prefix match (a word may go on: "thag" → "thagyo", "ठग" → "ठगी"). */
    private const NEG_STEM = ['complain', 'gunas', 'ujur', 'refund', 'fraud', 'froud', 'cheat', 'thag', 'dhok', 'scam', 'farji',
        'bekar', 'bekaar', 'bakwas', 'bakwaas', 'kharab', 'ghatiya', 'pathetic', 'horrib', 'terribl', 'useless', 'worst',
        'disappoint', 'harass', 'misbehav', 'galat', 'galti', 'samasya', 'problem', 'pareshan', 'cancel', 'radd', 'aayena',
        'ayena', 'aaena', 'pugena', 'milena', 'nahi aaya', 'nahi aya', 'nahi mila', 'not received', 'never again',
        'paisa firta', 'paise wapas', 'money back', 'lootera', 'lutera', 'gunda',
        'गुनासो', 'उजुरी', 'ठग', 'धोका', 'धोखा', 'फ्रड', 'बेकार', 'बकवास', 'खराब', 'घटिया', 'समस्या', 'गलत', 'गल्ती',
        'रिफन्ड', 'रिफंड', 'फिर्ता', 'रद्द', 'आएन', 'पुगेन', 'मिलेन', 'नहीं आया', 'नहीं मिला', 'परेशान', 'लुटेरा'];
    /* Whole word only ("late" must not catch "latest"). */
    private const NEG_WORD = ['bad', 'late', 'rude', 'wrong', 'fake', 'chor', 'lut', 'loot', 'dhilo', 'liar', 'jhut', 'jhuto', 'jhutha',
        'ढिलो', 'झुटो', 'झूठ', 'चोर', 'लुट', 'लूट'];
    private const ABUSE_STEM = ['chutiy', 'chutia', 'madarch', 'bhench', 'behench', 'bhosd', 'randi', 'haram', 'kutt', 'machikn',
        'muji', 'fuck', 'bastard', 'bitch',
        'मुजी', 'मचिक्न', 'रण्डी', 'रंडी', 'हरामी', 'कुत्त', 'चुतिय', 'भोसड', 'मादरच', 'बहनच', 'भेनच'];
    private const ABUSE_WORD = ['mc', 'bc', 'sala', 'saala', 'kukur', 'kukkur', 'gadha', 'idiot', 'stupid', 'shit', 'lado', 'puti',
        'साला', 'कुकुर', 'गधा'];
    private const ANGRY = ['😡', '🤬', '👎', '😠', '🖕', '💩', '😤', '😾'];

    /* Their own ticket / money / number — the office reads it, the AI never answers it. */
    private const PERSONAL = ['mero', 'mera', 'meri', 'my', 'hamro', 'hamara', 'humara', 'मेरो', 'मेरा', 'मेरी', 'हाम्रो', 'हमारा'];

    private const FARE_STEM = ['bhada', 'bhaada', 'bhara', 'bhaara', 'kiray', 'kiraay', 'kati parcha', 'kati parchha', 'kati parxa',
        'kati lagcha', 'kati lagchha', 'kati lagxa', 'kati ho', 'kati rupai', 'kati paisa', 'kitna', 'kitne ka', 'kitne paise',
        'kitne rupay', 'how much', 'ticket kati', 'ticket ko kati', 'ticket price', 'ketla', 'ketlu',
        'भाडा', 'भाड़ा', 'किराया', 'कति पर्छ', 'कति लाग्छ', 'कति हो', 'कितना', 'कितने का', 'कितने पैसे', 'ભાડ', 'કેટલા', 'ભાવ'];
    private const FARE_WORD = ['fare', 'fares', 'price', 'prices', 'rate', 'rates', 'cost', 'charge', 'charges', 'rs'];

    private const BOOK_STEM = ['book', 'how to book', 'kasari book', 'ticket kasari', 'ticket kaise', 'ticket kaha', 'ticket kata',
        'ticket chahiyo', 'ticket chahiye', 'ticket chaiyo', 'ticket chaiye', 'seat chahiyo', 'seat chahiye', 'reserv', 'contact',
        'sampark', 'whatsapp n', 'phone n', 'mobile n', 'inbox', 'detail', 'jankari',
        'बुक', 'सम्पर्क', 'संपर्क', 'नम्बर', 'नंबर', 'जानकारी', 'टिकट चाहियो', 'टिकट चाहिए', 'टिकट कसरी', 'टिकट कैसे', 'टिकट कहाँ'];
    private const BOOK_WORD = ['number', 'info', 'dm', 'pm', 'call'];

    /**
     * fare | book | fare+book (the AI may answer) — negative (flagged,
     * never answered) — personal | other (the office answers).
     *
     * @return array{kind: string, reason: string}
     */
    public static function classify(string $text): array
    {
        $raw = trim($text);
        if ($raw === '') {
            return ['kind' => 'other', 'reason' => 'no words (a sticker or a photo)'];
        }
        $t = ' ' . self::norm($raw) . ' ';
        if (self::hit($t, self::ABUSE_STEM) !== '' || self::hit($t, self::ABUSE_WORD, true) !== '') {
            return ['kind' => 'negative', 'reason' => 'अपशब्द · abusive word'];
        }
        if (($w = self::hit($t, self::NEG_STEM)) !== '' || ($w = self::hit($t, self::NEG_WORD, true)) !== '') {
            return ['kind' => 'negative', 'reason' => 'गुनासो · complaint / negative: "' . $w . '"'];
        }
        foreach (self::ANGRY as $em) {
            if (str_contains($raw, $em)) {
                return ['kind' => 'negative', 'reason' => 'रिसाएको · angry emoji ' . $em];
            }
        }
        if (self::hit($t, self::PERSONAL, true) !== '' || preg_match('/(?<![\p{L}\p{N}])shg[-\s]?[a-z0-9]{3,}/iu', $t) === 1
            || preg_match('/(?<!\d)(?:\+?\d[\s-]?){10,13}(?!\d)/', $raw) === 1) {
            return ['kind' => 'personal', 'reason' => 'their own ticket / number — the office answers'];
        }
        if (preg_match('~https?://|www\.|\.com\b|bit\.ly|wa\.me~i', $raw) === 1 || mb_strlen($raw) > 400) {
            return ['kind' => 'other', 'reason' => 'a link or a long text — the office looks'];
        }
        $fare = self::hit($t, self::FARE_STEM) !== '' || self::hit($t, self::FARE_WORD, true) !== '';
        $book = self::hit($t, self::BOOK_STEM) !== '' || self::hit($t, self::BOOK_WORD, true) !== '';
        if ($fare && $book) {
            return ['kind' => 'fare+book', 'reason' => 'fare + how to book'];
        }
        if ($fare) {
            return ['kind' => 'fare', 'reason' => 'fare question'];
        }
        if ($book) {
            return ['kind' => 'book', 'reason' => 'how to book'];
        }
        return ['kind' => 'other', 'reason' => ''];
    }

    /**
     * The one public line the AI may write — fixed words in the writer's
     * language (Nepali, Hindi, else English), with links built by appUrl():
     * the fare page (the route pages' index, which lists every active
     * route's fare and time) and the booking link + office number line the
     * WhatsApp FAQ uses.
     */
    public static function replyText(string $kind, string $text): string
    {
        $lang = 'en';
        try {
            require_once INCLUDE_PATH . '/aihandoff.php';
            $lang = AiHandoff::lang(['messageText' => $text]);
        } catch (Throwable $e) {
        }
        $lang = $lang === 'gu' ? 'hi' : (in_array($lang, ['ne', 'hi'], true) ? $lang : 'en');
        $t = static fn(string $ne, string $hi, string $en): string => match ($lang) { 'ne' => $ne, 'hi' => $hi, default => $en };
        $lines = [$t('🙏 नमस्ते!', '🙏 नमस्ते!', '🙏 Namaste!')];
        if (str_contains($kind, 'fare')) {
            $url = appUrl(match ($lang) { 'ne' => 'ne/bus', 'hi' => 'hi/bus', default => 'bus' });
            $lines[0] .= ' ' . $t('सबै रुटको भाडा र समय यहाँ हेर्नुहोस्: ', 'सभी रूट का किराया और समय यहाँ देखें: ', 'Fares and times for every route: ') . $url;
        }
        if (str_contains($kind, 'book')) {
            $lines[] = '🎫 ' . $t('अनलाइन बुक: ', 'ऑनलाइन बुक करें: ', 'Book online: ') . appUrl('');
            $lines[] = '📞 ' . $t('कार्यालय', 'ऑफिस', 'Office') . ': ' . Settings::officePhone() . ' (call / WhatsApp)';
        }
        return implode("\n", $lines);
    }

    /**
     * A draft for the office's own reply, from the WhatsApp FAQ desk —
     * WaFaq::suggest() is side-effect free (no memory, no log, sends
     * nothing) and answers from the fare board, the timetable and the
     * knowledge base (AiKb). The office reads and edits it; never posted
     * by itself. null = no idea.
     */
    public static function suggest(string $text): ?string
    {
        $text = trim($text);
        if ($text === '' || self::classify($text)['kind'] === 'negative') {
            return null;
        }
        try {
            require_once INCLUDE_PATH . '/wafaq.php';
            $s = WaFaq::suggest('', $text);
            return $s !== null && trim($s) !== '' ? mb_substr(trim($s), 0, self::MAX_REPLY) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /* =================================================================
     *  The office (admin/fb-comments.php)
     * ================================================================= */

    /**
     * Post a person's reply IN PUBLIC under the comment's thread, as the
     * Page. Audited either way.
     *
     * @return array{ok: bool, why: string}
     */
    public static function officeReply(int $id, string $text, int $adminId): array
    {
        $text = trim(self::clean($text, true));
        if (!self::ready()) {
            return ['ok' => false, 'why' => 'The fb_comments table is not on this server yet.'];
        }
        if (!self::on()) {
            return ['ok' => false, 'why' => 'FB कमेन्ट बन्द छ · FB comments are switched off (fb_comments_on) — nothing is posted.'];
        }
        if ($text === '') {
            return ['ok' => false, 'why' => 'पहिले जवाफ लेख्नुहोस् · Write the reply first.'];
        }
        if (mb_strlen($text) > self::MAX_REPLY) {
            return ['ok' => false, 'why' => '१००० अक्षरभित्र लेख्नुहोस् · Keep it under 1000 characters.'];
        }
        if (self::token() === '') {
            return ['ok' => false, 'why' => 'Page token छैन · No Page token is saved — see Facebook Messenger AI.'];
        }
        $row = self::get($id);
        if ($row === null) {
            return ['ok' => false, 'why' => 'Comment not found.'];
        }
        if ((int) $row['is_page'] === 1) {
            return ['ok' => false, 'why' => 'यो हाम्रै पेजको कमेन्ट हो · This is our own Page\'s comment.'];
        }
        if (!self::validId((string) $row['thread_id'])) {
            return ['ok' => false, 'why' => 'This comment cannot be answered (bad id).'];
        }
        if (!Security::rateLimit('fb_comment_reply', (string) $adminId, 30, 3600)) {
            return ['ok' => false, 'why' => 'धेरै जवाफ भए — एकछिन पछि · Too many replies this hour — wait a little.'];
        }
        $old = ['status' => (string) $row['status'], 'our_reply_id' => $row['our_reply_id'], 'reply_kind' => $row['reply_kind']];
        $res = self::postReply((string) $row['thread_id'], $text);
        if ($res['ok']) {
            Database::update('fb_comments', [
                'status'       => 'replied',
                'our_reply_id' => $res['id'],
                'reply_kind'   => 'office',
                'reply_text'   => $text,
                'replied_by'   => $adminId > 0 ? $adminId : null,
                'replied_at'   => date('Y-m-d H:i:s'),
                'reply_error'  => null,
            ], 'id = :id', ['id' => $id]);
            Logger::audit('fb.comment_reply', 'fb_comment', (string) $id, $old,
                ['status' => 'replied', 'our_reply_id' => $res['id'], 'reply_kind' => 'office', 'chars' => mb_strlen($text)],
                'public Facebook comment reply by admin #' . $adminId);
            self::healed('reply');
            return ['ok' => true, 'why' => ''];
        }
        Database::update('fb_comments', ['reply_error' => mb_substr($res['error'], 0, 255)], 'id = :id', ['id' => $id]);
        Logger::audit('fb.comment_reply_failed', 'fb_comment', (string) $id, $old,
            ['error' => mb_substr($res['error'], 0, 200), 'chars' => mb_strlen($text)], 'by admin #' . $adminId);
        if ($res['permission']) {
            self::recordError($res['error'], $res['code'], 'reply');
        }
        return ['ok' => false, 'why' => 'Facebook ले लिएन · Facebook refused: ' . $res['error']];
    }

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        if (!self::ready() || $id <= 0) {
            return null;
        }
        return Database::fetch('SELECT * FROM fb_comments WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> one tab, newest first */
    public static function listTab(string $tab, int $limit = 100): array
    {
        if (!self::ready()) {
            return [];
        }
        $where = match ($tab) {
            'flagged' => "c.is_page = 0 AND c.status = 'flagged'",
            'all'     => '1 = 1',
            default   => 'c.is_page = 0',
        };
        return Database::fetchAll(
            'SELECT c.*, a.full_name AS replied_by_name FROM fb_comments c LEFT JOIN admins a ON a.id = c.replied_by
              WHERE ' . $where . ' ORDER BY c.created_time DESC, c.id DESC LIMIT ' . max(1, min(300, $limit))
        );
    }

    /** @return array{comments: int, flagged: int, all: int, new: int} */
    public static function counts(): array
    {
        $c = ['comments' => 0, 'flagged' => 0, 'all' => 0, 'new' => 0];
        if (!self::ready()) {
            return $c;
        }
        try {
            $r = Database::fetch(
                "SELECT COUNT(*) AS a, SUM(is_page = 0) AS c, SUM(is_page = 0 AND status = 'flagged') AS f,
                        SUM(is_page = 0 AND status = 'new') AS n FROM fb_comments"
            );
            $c = ['comments' => (int) ($r['c'] ?? 0), 'flagged' => (int) ($r['f'] ?? 0), 'all' => (int) ($r['a'] ?? 0), 'new' => (int) ($r['n'] ?? 0)];
        } catch (Throwable $e) {
        }
        return $c;
    }

    /* =================================================================
     *  Pieces
     * ================================================================= */

    /**
     * POST /{thread}/comments as the Page. Facebook keeps two levels, so a
     * reply always goes under the thread's top comment.
     *
     * @return array{ok: bool, id: string, error: string, code: int, permission: bool}
     */
    public static function postReply(string $thread, string $text): array
    {
        $fail = static fn(string $e, int $code = 0): array => ['ok' => false, 'id' => '', 'error' => $e, 'code' => $code,
            'permission' => self::isPermissionError($code, $e)];
        if (!self::validId($thread)) {
            return $fail('bad comment id');
        }
        $r = self::call('POST', $thread . '/comments', ['message' => mb_substr($text, 0, self::MAX_REPLY)]);
        if (isset($r['error'])) {
            return $fail((string) ($r['error']['message'] ?? 'error'), (int) ($r['error']['code'] ?? 0));
        }
        $id = (string) ($r['id'] ?? '');
        return $id !== '' ? ['ok' => true, 'id' => mb_substr($id, 0, 80), 'error' => '', 'code' => 0, 'permission' => false]
                          : $fail('the answer carried no comment id');
    }

    /** Control characters out, whitespace tidied; a long text cut. The words of a reply keep their line breaks. */
    private static function clean(string $s, bool $keepLines = false): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        if (!$keepLines) {
            $s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;
        }
        $s = mb_substr(trim($s), 0, 4000);
        if (!$keepLines && $s !== '') {
            try {
                require_once INCLUDE_PATH . '/aihandoff.php';
                $s = AiHandoff::redact($s);             // a typed code / card number / password is never kept
            } catch (Throwable $e) {
            }
        }
        return $s;
    }

    private static function norm(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[?？!.,;:।॥"\'()\[\]{}*_~#]+/u', ' ', $t) ?? $t;
        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /** The first word of $words found in $t ('' = none). */
    private static function hit(string $t, array $words, bool $whole = false): string
    {
        foreach ($words as $w) {
            $re = '/(?<![\p{L}\p{M}\p{N}])' . preg_quote($w, '/') . ($whole ? '(?![\p{L}\p{M}\p{N}])' : '') . '/u';
            if (preg_match($re, $t) === 1) {
                return $w;
            }
        }
        return '';
    }

    /**
     * One Graph call. $path is "node/edge" or a paging "next" URL Meta gave
     * (its access_token, if any, is dropped). The token goes in the
     * Authorization header — never in the URL, never in a log.
     *
     * @return array<string,mixed> Meta's decoded answer; a failure is ['error' => [message, code]]
     */
    private static function call(string $method, string $path, array $params = []): array
    {
        if (preg_match('#^https://graph\.facebook\.com/#', $path) === 1) {
            $parts = parse_url($path);
            $query = [];
            parse_str((string) ($parts['query'] ?? ''), $query);
            unset($query['access_token']);
            $url = 'https://graph.facebook.com' . (string) ($parts['path'] ?? '/');
            $params = $query + $params;
        } elseif (preg_match('#^[A-Za-z0-9_]+(?:/[a-z_]+)?$#', $path) === 1) {
            $url = 'https://graph.facebook.com/' . self::GRAPH . '/' . $path;
        } else {
            return ['error' => ['message' => 'refused a Graph path that is not ours', 'code' => 0]];
        }
        if ($method === 'GET' && $params !== []) {
            $url .= '?' . http_build_query($params);
        }
        try {
            if (self::$transport !== null) {
                $r = (self::$transport)($method, $url, $method === 'GET' ? [] : $params);
                return is_array($r) ? $r : ['error' => ['message' => 'non-JSON answer', 'code' => 0]];
            }
            if (!function_exists('curl_init')) {
                return ['error' => ['message' => 'cURL is not available on this server', 'code' => 0]];
            }
            $tok = self::token();
            if ($tok === '') {
                return ['error' => ['message' => 'no Page token saved', 'code' => 0]];
            }
            $ch = curl_init($url);
            $opt = [
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_TIMEOUT           => 20,
                CURLOPT_CONNECTTIMEOUT_MS => 5000,
                CURLOPT_HTTPHEADER        => ['Authorization: Bearer ' . $tok],
            ];
            if ($method === 'POST') {
                $opt[CURLOPT_POST]       = true;
                $opt[CURLOPT_POSTFIELDS] = http_build_query($params);
            }
            curl_setopt_array($ch, $opt);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                return ['error' => ['message' => 'network: ' . $err, 'code' => 0]];
            }
            $j = json_decode((string) $body, true);
            return is_array($j) ? $j : ['error' => ['message' => 'non-JSON answer', 'code' => 0]];
        } catch (Throwable $e) {
            return ['error' => ['message' => 'Graph call failed: ' . $e->getMessage(), 'code' => 0]];
        }
    }
}
