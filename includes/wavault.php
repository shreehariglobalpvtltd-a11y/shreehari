<?php
/**
 * =====================================================================
 *  WaVault — the owner's and the CEO's own papers on WhatsApp
 *  (14 Nov 2026).
 *
 *  Owner: "mero ra CEO ko assistant hos — Aadhaar card, documents Drive ko
 *  link ya direct upload gardinchu, chahiyeko bela mage pachi pathaune".
 *
 *  Only two numbers can use it: admin_whatsapp and ceo_whatsapp in
 *  Settings. Each sees ONLY the papers sent from that same number — the
 *  CEO never sees the owner's papers, and the reverse. For every other
 *  number this class returns null and the bot carries on as before.
 *
 *   save   a photo / PDF sent with the caption "save <name>" (or "rakh",
 *          "राख"), or the text "save <Google Drive link> <name>".
 *   list   "kagaj" / "vault" / "मेरो कागज" — the papers, numbered.
 *   send   "kagaj 3" or "kagaj aadhar" — that paper comes back to the same
 *          number as a WhatsApp document / photo.
 *   delete "kagaj hatau 3" — hidden from the list (the file stays on the
 *          server, encrypted, so a slip can be undone by the office).
 *   help   "vault help".
 *
 *  How the bytes are kept and moved:
 *   - stored ENCRYPTED with the company-documents seal (AES-256-GCM, key
 *     from APP_KEY) under uploads/vault/; there is no public URL at all;
 *   - sent by uploading the bytes straight to Meta (/media) and sending
 *     that media id to the same number, never as a link;
 *   - only JPEG, PNG, WebP and PDF, at most 10 MB, and the bytes must be
 *     what they claim (finfo), as WaMedia already insists;
 *   - a Drive link is fetched only from Google's own hosts, https only;
 *     the file must be shared "Anyone with the link".
 *   Every save / send / delete writes an audit line (title and number of
 *   the paper, never its content).
 *
 *  Switch: wa_vault_on (default on).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaVault
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const SUBDIR = 'vault';
    private const MIME_EXT = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** Tests replace the outside world. */
    /** @var callable|null fn(string $mediaId): ?array{bytes,mime} */
    public static $fetchMedia = null;
    /** @var callable|null fn(string $url): ?array{bytes,mime} */
    public static $fetchUrl = null;
    /** @var callable|null fn(string $to, string $bytes, string $mime, string $filename, string $caption): bool */
    public static $deliver = null;

    private static ?bool $ready = null;

    public static function enabled(): bool
    {
        return Settings::getBool('wa_vault_on', true);
    }

    /* -----------------------------------------------------------------
     *  Who may use it
     * ----------------------------------------------------------------- */

    /** The owner key (digits) for this sender, or '' when it is not the owner's or the CEO's number. */
    public static function ownerKey(string $from): string
    {
        $d = (string) preg_replace('/\D/', '', $from);
        if (strlen($d) < 10) {
            return '';
        }
        foreach (['admin_whatsapp', 'ceo_whatsapp'] as $key) {
            $own = (string) preg_replace('/\D/', '', Settings::getString($key, ''));
            if (strlen($own) < 10) {
                continue;
            }
            // Full number when the setting carries a country code, else the 10 digits.
            $match = strlen($own) > 10 ? ($d === $own || (strlen($d) === 10 && substr($own, -10) === $d)) : substr($d, -10) === $own;
            if ($match) {
                return strlen($own) > 10 ? $own : $d;
            }
        }
        return '';
    }

    /* -----------------------------------------------------------------
     *  The table
     * ----------------------------------------------------------------- */

    public static function ensure(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            Database::query(
                "CREATE TABLE IF NOT EXISTS personal_vault (
                    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    owner_key   VARCHAR(20)     NOT NULL,
                    title       VARCHAR(120)    NOT NULL,
                    mime        VARCHAR(40)     NOT NULL,
                    ext         VARCHAR(8)      NOT NULL,
                    size_bytes  INT UNSIGNED    NOT NULL DEFAULT 0,
                    sha256      CHAR(64)        NOT NULL,
                    file_path   VARCHAR(190)    NOT NULL,
                    source      VARCHAR(20)     NOT NULL DEFAULT 'whatsapp',
                    status      VARCHAR(10)     NOT NULL DEFAULT 'active',
                    created_at  DATETIME        NOT NULL,
                    deleted_at  DATETIME        NULL,
                    KEY idx_vault_owner (owner_key, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            self::$ready = true;
        } catch (Throwable $e) {
            Logger::warning('personal_vault table could not be created: ' . $e->getMessage(), [], 'whatsapp');
            self::$ready = false;
        }
        return self::$ready;
    }

    /** @return list<array<string,mixed>> newest first */
    public static function listFor(string $owner): array
    {
        if (!self::ensure()) {
            return [];
        }
        return Database::fetchAll(
            "SELECT id, title, mime, size_bytes, created_at FROM personal_vault
              WHERE owner_key = :o AND status = 'active' ORDER BY id DESC LIMIT 50",
            ['o' => $owner]
        );
    }

    /* -----------------------------------------------------------------
     *  The WhatsApp desk
     * ----------------------------------------------------------------- */

    /**
     * Answer this message, or null when it is not a vault message (or not
     * from the owner's / CEO's number).
     *
     * @param array<string,mixed> $media from WaMedia::describe()
     * @return array{text:string}|null
     */
    public static function handle(string $from, string $body, string $kind = 'text', array $media = []): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $owner = self::ownerKey($from);
        if ($owner === '') {
            return null;
        }
        $t = trim($body);
        $low = mb_strtolower($t);

        // A photo / PDF with "save <name>".
        if (in_array($kind, ['image', 'document'], true)) {
            if (!preg_match('/^(?:save|sev|rakh|rakha|rakhnu|राख|राख्नु|सेभ)\b\s*(.*)$/iu', $t, $m)) {
                return null;                   // an attachment without the word is someone else's job
            }
            return self::saveMedia($owner, $media, trim($m[1]));
        }

        if ($t === '') {
            return null;
        }
        if (preg_match('/^(?:vault|kagaj|kagat|कागज)\s*(?:help|madat|मद्दत)$/iu', $t)) {
            return ['text' => self::help()];
        }
        // "save <drive link> <name>"
        if (preg_match('~^(?:save|sev|rakh|राख)\s+(https://\S+)\s*(.*)$~iu', $t, $m)) {
            return self::saveLink($owner, $m[1], trim($m[2]));
        }
        // "kagaj hatau 3" / "kagaj delete 3"
        if (preg_match('/^(?:kagaj|kagat|vault|कागज)\s+(?:hatau|hatāu|delete|remove|हटाउ|हटाऊ)\s+#?(\d{1,6})$/iu', $t, $m)) {
            return self::remove($owner, (int) $m[1]);
        }
        // "kagaj" / "mero kagaj" / "vault" / "मेरो कागज" — the list
        if (preg_match('/^(?:mero\s+|mera\s+|my\s+|मेरो\s+)?(?:kagaj|kagat|kagajat|vault|कागज|कागजात)$/iu', $low)) {
            return ['text' => self::listText($owner)];
        }
        // "kagaj 3" / "kagaj aadhar" / "pathau aadhar"
        if (preg_match('/^(?:kagaj|kagat|vault|कागज|pathau|pathaunu|पठाउ|पठाऊ)\s+#?(.+)$/iu', $t, $m)) {
            return self::send($owner, $from, trim($m[1]));
        }
        return null;
    }

    public static function help(): string
    {
        return "🔐 तपाईंको निजी कागज (यो number ले मात्र देख्छ):\n"
             . "• राख्न: photo/PDF पठाउँदा caption मा \"save aadhar\" लेख्नुहोस्\n"
             . "• Drive बाट: \"save <Drive link> pan card\" (link 'Anyone with the link' हुनुपर्छ)\n"
             . "• सूची: \"kagaj\"\n"
             . "• माग्न: \"kagaj 2\" वा \"kagaj aadhar\"\n"
             . "• हटाउन: \"kagaj hatau 2\"";
    }

    private static function listText(string $owner): string
    {
        $rows = self::listFor($owner);
        if ($rows === []) {
            return "🔐 तपाईंको vault खाली छ।\n" . self::help();
        }
        $lines = ['🔐 तपाईंका कागज (' . count($rows) . '):'];
        foreach ($rows as $r) {
            $lines[] = '#' . (int) $r['id'] . ' ' . (string) $r['title'] . ' · ' . strtoupper((string) (self::MIME_EXT[(string) $r['mime']] ?? 'file'))
                . ' · ' . substr((string) $r['created_at'], 0, 10);
        }
        $lines[] = 'माग्न: "kagaj ' . (int) $rows[0]['id'] . '"  ·  हटाउन: "kagaj hatau ' . (int) $rows[0]['id'] . '"';
        return implode("\n", $lines);
    }

    /* -----------------------------------------------------------------
     *  Save
     * ----------------------------------------------------------------- */

    private static function cleanTitle(string $title, string $fallback): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}\p{M} ._()\-]+/u', ' ', $title)));
        if ($title === '') {
            $title = $fallback;
        }
        return mb_substr($title, 0, 120);
    }

    /** @param array<string,mixed> $media */
    private static function saveMedia(string $owner, array $media, string $title): array
    {
        $id = (string) ($media['id'] ?? '');
        if ($id === '') {
            return ['text' => '⚠️ File पढ्न सकिएन। फेरि पठाउनुहोस्।'];
        }
        try {
            if (self::$fetchMedia !== null) {
                $got = (self::$fetchMedia)($id);
            } else {
                require_once INCLUDE_PATH . '/wamedia.php';
                $got = WaMedia::download($id);
            }
        } catch (Throwable $e) {
            $got = null;
        }
        if (!is_array($got) || (string) ($got['bytes'] ?? '') === '') {
            return ['text' => '⚠️ File ल्याउन सकिएन (photo वा PDF, 10 MB भन्दा सानो)। फेरि पठाउनुहोस्।'];
        }
        $fallback = trim((string) ($media['filename'] ?? '')) !== '' ? (string) $media['filename'] : 'कागज ' . date('d M');
        return self::store($owner, (string) $got['bytes'], self::cleanTitle($title, $fallback), 'whatsapp');
    }

    /** The Google Drive / Docs URL that returns the file's bytes, or '' when it is not one. */
    public static function driveDownloadUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($host, ['drive.google.com', 'docs.google.com'], true) || !str_starts_with(strtolower($url), 'https://')) {
            return '';
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        if (preg_match('~^/(document|spreadsheets|presentation)/d/([A-Za-z0-9_\-]{10,})~', $path, $m)) {
            return 'https://docs.google.com/' . $m[1] . '/d/' . $m[2] . '/export?format=pdf';
        }
        $fid = '';
        if (preg_match('~/file/d/([A-Za-z0-9_\-]{10,})~', $path, $m)) {
            $fid = $m[1];
        } elseif (isset($q['id']) && preg_match('/^[A-Za-z0-9_\-]{10,}$/', (string) $q['id'])) {
            $fid = (string) $q['id'];
        }
        return $fid !== '' ? 'https://drive.google.com/uc?export=download&confirm=t&id=' . $fid : '';
    }

    private static function saveLink(string $owner, string $url, string $title): array
    {
        $dl = self::driveDownloadUrl($url);
        if ($dl === '') {
            return ['text' => '⚠️ Google Drive को file link मात्र लिन मिल्छ (drive.google.com वा docs.google.com)।'];
        }
        try {
            $got = self::$fetchUrl !== null ? (self::$fetchUrl)($dl) : self::httpFetch($dl);
        } catch (Throwable $e) {
            $got = null;
        }
        if (!is_array($got) || (string) ($got['bytes'] ?? '') === '') {
            return ['text' => "⚠️ Drive बाट file आएन। Drive मा Share → General access → \"Anyone with the link\" गरेर फेरि पठाउनुहोस्। (10 MB सम्म, photo वा PDF)"];
        }
        return self::store($owner, (string) $got['bytes'], self::cleanTitle($title, 'Drive कागज ' . date('d M')), 'drive');
    }

    /** https GET from Google's hosts only, redirects followed only to Google, capped. */
    private static function httpFetch(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 8,
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_MAXFILESIZE     => self::MAX_BYTES,
            CURLOPT_USERAGENT       => 'SHG-Vault/1.0',
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $last = strtolower((string) parse_url((string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), PHP_URL_HOST));
        curl_close($ch);
        $googleHost = static fn(string $h): bool => $h === 'google.com' || str_ends_with($h, '.google.com') || str_ends_with($h, '.googleusercontent.com');
        if ($body === false || $http !== 200 || !$googleHost($last) || strlen((string) $body) > self::MAX_BYTES) {
            Logger::warning('Vault: Drive fetch failed', ['http' => $http, 'host' => $last], 'whatsapp');
            return null;
        }
        return ['bytes' => (string) $body];
    }

    /** Check the bytes, seal them, keep one row. */
    public static function store(string $owner, string $bytes, string $title, string $source): array
    {
        if (!self::ensure()) {
            return ['text' => '⚠️ Vault अहिले तयार छैन। Office लाई भन्नुहोस्।'];
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return ['text' => '⚠️ File 10 MB भन्दा ठूलो वा खाली छ।'];
        }
        $mime = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        $ext  = self::MIME_EXT[$mime] ?? '';
        if ($ext === '') {
            return ['text' => '⚠️ Photo (JPG/PNG) वा PDF मात्र राख्न मिल्छ।'];
        }
        $sha = hash('sha256', $bytes);
        $dup = Database::fetch("SELECT id, title FROM personal_vault WHERE owner_key = :o AND sha256 = :s AND status = 'active' LIMIT 1",
            ['o' => $owner, 's' => $sha]);
        if ($dup !== null) {
            return ['text' => 'ℹ️ यो file पहिले नै छ: #' . (int) $dup['id'] . ' ' . (string) $dup['title']];
        }
        require_once INCLUDE_PATH . '/companydocs.php';
        $dir = rtrim(UPLOAD_PATH, '/\\') . '/' . self::SUBDIR;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return ['text' => '⚠️ File राख्ने ठाउँ बनेन। Office लाई भन्नुहोस्।'];
        }
        $rel  = self::SUBDIR . '/' . bin2hex(random_bytes(16)) . '.bin';
        $tmp  = rtrim(UPLOAD_PATH, '/\\') . '/' . $rel . '.tmp';
        if (@file_put_contents($tmp, CompanyDocs::seal($bytes), LOCK_EX) === false || !@rename($tmp, rtrim(UPLOAD_PATH, '/\\') . '/' . $rel)) {
            @unlink($tmp);
            return ['text' => '⚠️ File save भएन। फेरि पठाउनुहोस्।'];
        }
        $id = (int) Database::insert('personal_vault', [
            'owner_key'  => $owner,
            'title'      => $title,
            'mime'       => $mime,
            'ext'        => $ext,
            'size_bytes' => strlen($bytes),
            'sha256'     => $sha,
            'file_path'  => $rel,
            'source'     => $source,
            'status'     => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Logger::audit('vault.save', 'personal_vault', (string) $id, null, ['title' => $title, 'source' => $source, 'size' => strlen($bytes)],
            'Saved from ' . maskPhone(substr($owner, -10)));
        return ['text' => '✅ राखियो: #' . $id . ' ' . $title . ' (' . strtoupper($ext) . ', ' . self::kb(strlen($bytes)) . ")\n"
            . 'माग्दा: "kagaj ' . $id . '"  ·  सबै: "kagaj"'];
    }

    private static function kb(int $n): string
    {
        return $n >= 1048576 ? round($n / 1048576, 1) . ' MB' : max(1, (int) round($n / 1024)) . ' KB';
    }

    /* -----------------------------------------------------------------
     *  Send / delete
     * ----------------------------------------------------------------- */

    /** The one active paper of this owner that "kagaj <x>" means, or a list of choices. */
    public static function find(string $owner, string $want): array
    {
        if (!self::ensure()) {
            return [];
        }
        if (preg_match('/^#?(\d{1,6})$/', $want, $m)) {
            $r = Database::fetch("SELECT * FROM personal_vault WHERE id = :i AND owner_key = :o AND status = 'active'",
                ['i' => (int) $m[1], 'o' => $owner]);
            return $r !== null ? [$r] : [];
        }
        $words = array_filter(preg_split('/\s+/u', mb_strtolower($want)) ?: [], static fn(string $w): bool => mb_strlen($w) >= 2);
        if ($words === []) {
            return [];
        }
        $hits = [];
        foreach (Database::fetchAll("SELECT * FROM personal_vault WHERE owner_key = :o AND status = 'active' ORDER BY id DESC LIMIT 200", ['o' => $owner]) as $r) {
            $t = mb_strtolower((string) $r['title']);
            $all = true;
            foreach ($words as $w) {
                if (!str_contains($t, $w) && !str_contains(str_replace('aa', 'a', $t), str_replace('aa', 'a', $w))) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $hits[] = $r;
            }
        }
        return $hits;
    }

    private static function send(string $owner, string $from, string $want): array
    {
        $hits = self::find($owner, $want);
        if ($hits === []) {
            return ['text' => '🔎 "' . mb_substr($want, 0, 40) . '" भेटिएन। सबै हेर्न: "kagaj"'];
        }
        if (count($hits) > 1) {
            $lines = ['धेरै भेटिए — नम्बरले माग्नुहोस्:'];
            foreach (array_slice($hits, 0, 8) as $r) {
                $lines[] = '#' . (int) $r['id'] . ' ' . (string) $r['title'];
            }
            return ['text' => implode("\n", $lines)];
        }
        $doc = $hits[0];
        require_once INCLUDE_PATH . '/companydocs.php';
        $path = rtrim(UPLOAD_PATH, '/\\') . '/' . (string) $doc['file_path'];
        try {
            $bytes = is_file($path) ? CompanyDocs::open((string) file_get_contents($path)) : '';
        } catch (Throwable $e) {
            $bytes = '';
        }
        if ($bytes === '' || !hash_equals((string) $doc['sha256'], hash('sha256', $bytes))) {
            Logger::warning('Vault: stored file missing or changed', ['id' => (int) $doc['id']], 'whatsapp');
            return ['text' => '⚠️ #' . (int) $doc['id'] . ' को file खोल्न सकिएन। Office लाई भन्नुहोस्।'];
        }
        $name = preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', (string) $doc['title']) . '.' . (string) $doc['ext'];
        $caption = '🔐 #' . (int) $doc['id'] . ' ' . (string) $doc['title'];
        $to = (string) preg_replace('/\D/', '', $from);
        $ok = self::$deliver !== null
            ? (bool) (self::$deliver)($to, $bytes, (string) $doc['mime'], $name, $caption)
            : self::deliver($to, $bytes, (string) $doc['mime'], $name, $caption);
        Logger::audit('vault.send', 'personal_vault', (string) $doc['id'], null, ['ok' => $ok],
            'Sent to ' . maskPhone(substr($to, -10)) . ' (its owner)');
        return ['text' => $ok ? '📎 पठाइयो: #' . (int) $doc['id'] . ' ' . (string) $doc['title']
                             : '⚠️ पठाउन सकिएन। केही बेरपछि फेरि "kagaj ' . (int) $doc['id'] . '" लेख्नुहोस्।'];
    }

    /** Upload the bytes to Meta and send the media id: no link ever leaves the server. */
    private static function deliver(string $to, string $bytes, string $mime, string $filename, string $caption): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        require_once ROOT_PATH . '/whatsapp/config.php';
        require_once ROOT_PATH . '/whatsapp/api.php';
        if (META_ACCESS_TOKEN === '' || META_PHONE_NUMBER_ID === '') {
            return false;
        }
        $ch = curl_init(META_API_BASE . '/' . rawurlencode(META_PHONE_NUMBER_ID) . '/media');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . META_ACCESS_TOKEN],
            CURLOPT_POSTFIELDS     => ['messaging_product' => 'whatsapp', 'type' => $mime, 'file' => new CURLStringFile($bytes, $filename, $mime)],
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $mediaId = (string) (json_decode((string) $body, true)['id'] ?? '');
        if ($http < 200 || $http >= 300 || $mediaId === '') {
            Logger::warning('Vault: Meta media upload refused', ['http' => $http], 'whatsapp');
            return false;
        }
        $payload = str_starts_with($mime, 'image/')
            ? ['type' => 'image', 'image' => ['id' => $mediaId, 'caption' => mb_substr($caption, 0, 1024)]]
            : ['type' => 'document', 'document' => ['id' => $mediaId, 'filename' => mb_substr($filename, 0, 120), 'caption' => mb_substr($caption, 0, 1024)]];
        $r = waGraphPost($to, $payload, 'vault', null, null, 1);
        return !empty($r['success']);
    }

    private static function remove(string $owner, int $id): array
    {
        if (!self::ensure()) {
            return ['text' => '⚠️ Vault अहिले तयार छैन।'];
        }
        $doc = Database::fetch("SELECT id, title FROM personal_vault WHERE id = :i AND owner_key = :o AND status = 'active'", ['i' => $id, 'o' => $owner]);
        if ($doc === null) {
            return ['text' => '🔎 #' . $id . ' भेटिएन। सबै हेर्न: "kagaj"'];
        }
        Database::update('personal_vault', ['status' => 'deleted', 'deleted_at' => date('Y-m-d H:i:s')], 'id = :i', ['i' => $id]);
        Logger::audit('vault.delete', 'personal_vault', (string) $id, null, null, 'Hidden by its owner');
        return ['text' => '🗑️ हटाइयो: #' . $id . ' ' . (string) $doc['title'] . "\n(गल्ती भयो भने office ले फर्काउन सक्छ।)"];
    }
}
