<?php
/**
 * =====================================================================
 *  includes/payqr.php — the payment QR library (25 Nov 2026, mp4).
 *
 *  Owner: "qr code haru sab upload garne system bandinus, admin panel
 *  baata update garn milos". Until now there was ONE Nepal QR picture
 *  (settings nepal_esewa_qr). Now any number of QR pictures live in
 *  pay_qr_codes (database/upgrade-2026-11-25-mp4-qr-library.sql), each with:
 *
 *    country    NP | IN
 *    kind       esewa | fonepay | khalti | gibl (Global IME bank) | bank | upi
 *    label      the office's own name for it ("NPJ counter eSewa")
 *    payee      the id / number / VPA printed under it
 *    desk_code  the counter (counter_locations.code) it belongs to, or NULL
 *    is_default the QR a ticket of that country prints when its desk has none
 *    is_active  off = never printed, kept in the list
 *
 *  Files: uploads/payqr/qr-<random>.png, re-encoded to PNG (never stored as
 *  sent), at most 900 px on the long side - the same handler as the old
 *  eSewa QR on admin/owner-setup.php.
 *
 *  Which QR a ticket prints (Ticket::esewaQrFile($booking), PayQr::pick):
 *    1. an active QR of the ticket's issuing desk (bookings.counter_code, the
 *       desk frozen on the sale) for that country, with its picture on disk;
 *    2. else the country's active default;
 *    3. else the old single setting (nepal_esewa_qr) and the fixed
 *       esewa-qr files - exactly as before this library.
 *  India keeps the dynamic UPI QR (amount + PNR, from upi_id) first; the IN
 *  default picture is used only where a static UPI picture is needed (the
 *  pay page when the dynamic QR cannot be drawn).
 *
 *  Old readers keep working: the NP default's file is written back into
 *  nepal_esewa_qr (syncLegacy) after every change, and the old single QR is
 *  brought into the library once as the NP default (importLegacy).
 *
 *  Safe before the migration: ready() is false and every reader falls back
 *  to the old setting. Never throws to a reader.
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class PayQr
{
    public const TABLE = 'pay_qr_codes';

    /** kind => what the office calls it */
    public const KINDS = [
        'esewa'   => 'eSewa',
        'fonepay' => 'Fonepay',
        'khalti'  => 'Khalti',
        'gibl'    => 'Global IME Bank',
        'bank'    => 'अरू बैंक · Other bank',
        'upi'     => 'UPI',
    ];

    public const COUNTRIES = ['NP' => '🇳🇵 नेपाल · Nepal', 'IN' => '🇮🇳 भारत · India'];

    /** A stored QR file (both the new qr-… names and the old esewa-npj-… one). */
    public const FILE_RX = '#^uploads/payqr/[a-z0-9-]+\.png$#';

    public const MAX_BYTES = 3 * 1024 * 1024;

    /** The old single Nepal QR (and its id / name) was copied in once. */
    public const LEGACY_FLAG = 'pay_qr_legacy_imported';

    private static ?bool $ready = null;

    /** @var list<array<string,mixed>>|null every row, memoised for one request */
    private static ?array $rows = null;

    private function __construct() {}

    /* =================================================================
     *  Reading
     * ================================================================= */

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Database::fetch("SHOW TABLES LIKE 'pay_qr_codes'") !== null;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** Forget the memo (after the migration ran, or after a write). */
    public static function reset(): void
    {
        self::$ready = null;
        self::$rows = null;
    }

    /** @return list<array<string,mixed>> */
    private static function rows(): array
    {
        if (self::$rows === null) {
            self::$rows = [];
            if (self::ready()) {
                try {
                    self::$rows = Database::fetchAll('SELECT * FROM pay_qr_codes ORDER BY country DESC, is_default DESC, sort, id');
                } catch (Throwable $e) {
                    self::$rows = [];
                }
            }
        }
        return self::$rows;
    }

    /** @return list<array<string,mixed>> */
    public static function all(?string $country = null, bool $activeOnly = false): array
    {
        $out = [];
        foreach (self::rows() as $r) {
            if ($country !== null && (string) $r['country'] !== $country) {
                continue;
            }
            if ($activeOnly && (int) $r['is_active'] !== 1) {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    public static function get(int $id): ?array
    {
        foreach (self::rows() as $r) {
            if ((int) $r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    /** The absolute path of a row's picture when it is a valid file on disk, else ''. */
    public static function absPath(?array $row): string
    {
        if ($row === null) {
            return '';
        }
        $rel = ltrim(str_replace('\\', '/', (string) ($row['file'] ?? '')), '/');
        if ($rel === '' || preg_match(self::FILE_RX, $rel) !== 1) {
            return '';
        }
        $p = rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/' . $rel;
        $sz = is_file($p) ? (int) @filesize($p) : 0;
        return $sz > 0 && $sz < self::MAX_BYTES ? $p : '';
    }

    /** The picture inline (data: URI), so it shows even where /uploads/ is closed to the web. */
    public static function dataUri(?array $row): string
    {
        $p = self::absPath($row);
        if ($p === '') {
            return '';
        }
        $raw = (string) @file_get_contents($p);
        return $raw === '' ? '' : 'data:image/png;base64,' . base64_encode($raw);
    }

    /** The country's active default (or null). */
    public static function defaultFor(string $country): ?array
    {
        foreach (self::all($country, true) as $r) {
            if ((int) $r['is_default'] === 1) {
                return $r;
            }
        }
        return null;
    }

    /** An active QR of this desk for this country (its default first), or null. */
    public static function forDesk(string $desk, string $country): ?array
    {
        $desk = self::cleanDesk($desk);
        if ($desk === '') {
            return null;
        }
        foreach (self::all($country, true) as $r) {
            if (strtoupper((string) ($r['desk_code'] ?? '')) === $desk && self::absPath($r) !== '') {
                return $r;
            }
        }
        return null;
    }

    /**
     * The desk that issued the ticket: ONLY the code frozen on the sale (bookings.counter_code).
     * Review fix: never the seller's profile desk (agent_counter_code) - only Ticket::loadBooking
     * joins it (pay.php does not), and it moves when the agent moves, so the ticket and its
     * pay page could show two different wallets. No frozen desk = the country default.
     */
    public static function deskOf(array $booking): string
    {
        return self::cleanDesk((string) ($booking['counter_code'] ?? ''));
    }

    /**
     * The QR this ticket / page shows for $country: the issuing desk's own,
     * else the country default - only rows whose picture is on disk. null =
     * the library has nothing usable (the caller keeps its old path).
     */
    public static function pick(?array $booking, string $country = 'NP'): ?array
    {
        try {
            if (!self::ready()) {
                return null;
            }
            if ($booking !== null) {
                $d = self::forDesk(self::deskOf($booking), $country);
                if ($d !== null) {
                    return $d;
                }
            }
            $def = self::defaultFor($country);
            return $def !== null && self::absPath($def) !== '' ? $def : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function kindLabel(string $kind): string
    {
        return self::KINDS[$kind] ?? $kind;
    }

    /** kind => the short Latin word printed before the payee on tickets / pay pages */
    public const PRINT_KINDS = [
        'esewa' => 'eSewa', 'fonepay' => 'Fonepay', 'khalti' => 'Khalti',
        'gibl'  => 'Global IME', 'bank' => 'Bank', 'upi' => 'UPI',
    ];

    /** The word printed before a row's payee ("Khalti: 98…") - never "eSewa" for another wallet. */
    public static function printLabel(string $kind): string
    {
        return self::PRINT_KINDS[$kind] ?? 'QR';
    }

    /* =================================================================
     *  Writing (the admin page: super-admin, CSRF, audited there)
     * ================================================================= */

    private static function cleanDesk(string $code): string
    {
        $c = strtoupper(trim($code));
        $c = preg_replace('/[^A-Z0-9_-]/', '', $c) ?? '';
        return substr($c, 0, 16);
    }

    /**
     * Validate the form fields. @return array{0: array<string,mixed>, 1: list<string>} [fields, errors]
     */
    public static function clean(array $in): array
    {
        $bad = [];
        $country = strtoupper(trim((string) ($in['country'] ?? 'NP')));
        if (!isset(self::COUNTRIES[$country])) {
            $bad[] = 'देश NP वा IN मात्र · country NP or IN';
            $country = 'NP';
        }
        $kind = strtolower(trim((string) ($in['kind'] ?? '')));
        if (!isset(self::KINDS[$kind])) {
            $bad[] = 'QR को किसिम छान्नुहोस् · choose the kind (eSewa, Fonepay, Khalti, bank, UPI)';
            $kind = $country === 'IN' ? 'upi' : 'esewa';
        }
        $label = mb_substr(Security::clean((string) ($in['label'] ?? ''), 80), 0, 80);
        if ($label === '') {
            $label = self::kindLabel($kind);
        }
        $payee = mb_substr(Security::clean((string) ($in['payee'] ?? ''), 160), 0, 160);
        if ($payee !== '' && preg_match('/[<>]/', $payee) === 1) {
            $bad[] = 'ID / नम्बरमा < > राख्न मिल्दैन · no < > in the id';
        }
        $desk = self::cleanDesk((string) ($in['desk_code'] ?? ''));
        if ($desk !== '') {
            try {
                if (!class_exists('CounterDesk')) {
                    require_once __DIR__ . '/counterdesk.php';
                }
                if (CounterDesk::get($desk) === null) {
                    $bad[] = 'काउन्टर ' . $desk . ' भेटिएन · unknown counter';
                }
            } catch (Throwable $e) {
                // no desk list on this server: keep the code as typed
            }
        }
        $sort = max(0, min(999, (int) ($in['sort'] ?? 0)));
        return [[
            'country'   => $country,
            'kind'      => $kind,
            'label'     => $label,
            'payee'     => $payee,
            'desk_code' => $desk !== '' ? $desk : null,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
            'sort'      => $sort,
        ], $bad];
    }

    /** The directory the pictures live in (created when missing). */
    public static function dir(): string
    {
        $dir = rtrim(defined('UPLOAD_PATH') ? UPLOAD_PATH : ROOT_PATH . '/uploads', '/\\') . '/payqr';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create uploads/payqr');
        }
        return $dir;
    }

    /** A browser upload ($_FILES row) -> the stored relative path. Throws with a Nepali reason. */
    public static function storeUpload(array $f): string
    {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
            throw new RuntimeException('QR को फोटो छान्नुहोस् · choose the QR image');
        }
        return self::storeImageFile((string) $f['tmp_name'], (int) ($f['size'] ?? 0));
    }

    /**
     * Re-encode one picture (PNG / JPG / WebP, 3 MB) to a PNG under uploads/payqr
     * with a random name; returns 'uploads/payqr/qr-….png'. Never keeps the file as sent.
     */
    public static function storeImageFile(string $path, int $size = -1): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('QR को फोटो छान्नुहोस् · choose the QR image');
        }
        $size = $size >= 0 ? $size : (int) filesize($path);
        $mime = (function_exists('mime_content_type') ? (string) @mime_content_type($path) : '') ?: '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) || $size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('PNG / JPG / WebP, 3 MB सम्म');
        }
        $src = @imagecreatefromstring((string) file_get_contents($path));
        if ($src === false) {
            throw new RuntimeException('फोटो खुलेन · image could not be read');
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $sc = min(1.0, 900 / max(1, $w, $h));
        $dst = imagecreatetruecolor(max(1, (int) round($w * $sc)), max(1, (int) round($h * $sc)));
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        $dir = self::dir();
        $name = 'qr-' . bin2hex(random_bytes(8)) . '.png';
        $ok = imagepng($dst, $dir . '/' . $name . '.tmp', 6) && rename($dir . '/' . $name . '.tmp', $dir . '/' . $name);
        imagedestroy($src);
        imagedestroy($dst);
        if (!$ok) {
            @unlink($dir . '/' . $name . '.tmp');
            throw new RuntimeException('Could not save the QR');
        }
        return 'uploads/payqr/' . $name;
    }

    /** Add a QR (fields from clean(), $file from storeImageFile). @return int the new id */
    public static function create(array $fields, string $file, int $by, bool $makeDefault = false): int
    {
        if (!self::ready()) {
            throw new RuntimeException('Migration database/upgrade-2026-11-25-mp4-qr-library.sql बाँकी · run the migration first');
        }
        if (preg_match(self::FILE_RX, $file) !== 1) {
            throw new RuntimeException('QR file not valid');
        }
        $now = date('Y-m-d H:i:s');
        $id = Database::insert(self::TABLE, [
            'country' => $fields['country'], 'kind' => $fields['kind'], 'label' => $fields['label'], 'payee' => $fields['payee'],
            'file' => $file, 'desk_code' => $fields['desk_code'], 'is_default' => 0, 'is_active' => (int) $fields['is_active'],
            'sort' => (int) $fields['sort'], 'created_by' => $by > 0 ? $by : null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        self::$rows = null;
        /* the first QR of a country becomes its default by itself */
        if ($makeDefault || (self::defaultFor((string) $fields['country']) === null && (int) $fields['is_active'] === 1)) {
            self::setDefault($id);
        }
        self::afterChange();
        return $id;
    }

    /** Change the fields of one QR. @return list<string> the changed field names */
    public static function update(int $id, array $fields): array
    {
        $row = self::get($id);
        if ($row === null) {
            throw new RuntimeException('QR भेटिएन · not found');
        }
        $changed = [];
        foreach (['country', 'kind', 'label', 'payee', 'desk_code', 'is_active', 'sort'] as $k) {
            if ((string) ($row[$k] ?? '') !== (string) ($fields[$k] ?? '')) {
                $changed[] = $k;
            }
        }
        if ($changed === []) {
            return [];
        }
        $upd = array_intersect_key($fields, array_flip($changed)) + ['updated_at' => date('Y-m-d H:i:s')];
        if (in_array('country', $changed, true)) {
            $upd['is_default'] = 0;   // a default belongs to its country
        }
        if ((int) ($fields['is_active'] ?? 0) !== 1) {
            $upd['is_default'] = 0;   // review fix: a QR switched off is never the ticket default
        }
        Database::update(self::TABLE, $upd, 'id = :i', ['i' => $id]);
        self::$rows = null;
        self::afterChange();
        return $changed;
    }

    /** Make this QR its country's ticket default (the others of that country lose it). */
    public static function setDefault(int $id): void
    {
        $row = self::get($id);
        if ($row === null) {
            throw new RuntimeException('QR भेटिएन · not found');
        }
        Database::run('UPDATE pay_qr_codes SET is_default = 0 WHERE country = :c AND id <> :i', ['c' => (string) $row['country'], 'i' => $id]);
        Database::run('UPDATE pay_qr_codes SET is_default = 1, is_active = 1, updated_at = :u WHERE id = :i', ['u' => date('Y-m-d H:i:s'), 'i' => $id]);
        self::$rows = null;
        self::afterChange();
    }

    /** A new picture for one QR; the old file is removed. @return string the old relative path */
    public static function replaceFile(int $id, string $file): string
    {
        $row = self::get($id);
        if ($row === null) {
            throw new RuntimeException('QR भेटिएन · not found');
        }
        if (preg_match(self::FILE_RX, $file) !== 1) {
            throw new RuntimeException('QR file not valid');
        }
        Database::update(self::TABLE, ['file' => $file, 'updated_at' => date('Y-m-d H:i:s')], 'id = :i', ['i' => $id]);
        self::$rows = null;
        self::afterChange();
        self::unlinkIfUnused((string) $row['file']);
        return (string) $row['file'];
    }

    /** Remove one QR and its picture. @return array<string,mixed>|null the removed row */
    public static function delete(int $id): ?array
    {
        $row = self::get($id);
        if ($row === null) {
            return null;
        }
        Database::delete(self::TABLE, 'id = :i', ['i' => $id]);
        self::$rows = null;
        self::afterChange();
        /* the old setting still points at the deleted picture (it was the NP default): it goes too */
        if (ltrim(str_replace('\\', '/', Settings::getString('nepal_esewa_qr', '')), '/') === (string) $row['file']) {
            Settings::set('nepal_esewa_qr', '', 'string', 'company');
            Settings::flush();
        }
        self::unlinkIfUnused((string) $row['file']);
        return $row;
    }

    /** Delete a picture no row (and not the old setting) points at any more. */
    private static function unlinkIfUnused(string $rel): void
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (preg_match(self::FILE_RX, $rel) !== 1) {
            return;
        }
        foreach (self::rows() as $r) {
            if ((string) $r['file'] === $rel) {
                return;
            }
        }
        if (ltrim(str_replace('\\', '/', Settings::getString('nepal_esewa_qr', '')), '/') === $rel) {
            return;
        }
        $p = rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/' . $rel;
        if (is_file($p)) {
            @unlink($p);
        }
    }

    /** After every write: the old setting follows the NP default, cached tickets redraw. */
    private static function afterChange(): void
    {
        self::syncLegacy();
        try {
            Settings::touchTicketIdentity();
            Settings::flush();
        } catch (Throwable $e) {
            // a ticket redraws on its own next change
        }
    }

    /**
     * Keep nepal_esewa_qr = the NP default's picture, so every older reader
     * (index.php boot, owner-setup, pay pages) shows the same QR as the ticket.
     * No NP default: a setting that still points at a library picture (one that
     * was switched off, lost its default or moved to India) is cleared, so the
     * old fallback never prints a QR the office just turned off. A setting that
     * points at a file outside the library (not imported yet) is left alone.
     */
    public static function syncLegacy(): void
    {
        try {
            $def = self::defaultFor('NP');
            if ($def === null) {
                $cur = ltrim(str_replace('\\', '/', Settings::getString('nepal_esewa_qr', '')), '/');
                if ($cur !== '') {
                    foreach (self::rows() as $r) {
                        if ((string) $r['file'] === $cur) {
                            Settings::set('nepal_esewa_qr', '', 'string', 'company');
                            Settings::flush();
                            break;
                        }
                    }
                }
                return;
            }
            $file = (string) $def['file'];
            if ($file !== Settings::getString('nepal_esewa_qr', '') && preg_match(self::FILE_RX, $file) === 1) {
                Settings::set('nepal_esewa_qr', $file, 'string', 'company');
                Settings::flush();
            }
        } catch (Throwable $e) {
            Logger::warning('pay QR legacy sync failed: ' . $e->getMessage(), [], 'payments');
        }
    }

    /**
     * The old single Nepal QR (nepal_esewa_qr + nepal_esewa_id / nepal_esewa_name)
     * as the NP default. Once ($force: again for a NEW old-style upload on
     * owner-setup.php). Idempotent: a file already in the library is never
     * added twice. @return int|null the new row id, or null when nothing was added
     */
    public static function importLegacy(int $by = 0, bool $force = false): ?int
    {
        try {
            if (!self::ready() || (!$force && Settings::getBool(self::LEGACY_FLAG, false))) {
                return null;
            }
            $file = ltrim(str_replace('\\', '/', Settings::getString('nepal_esewa_qr', '')), '/');
            $id = null;
            if ($file !== '' && preg_match(self::FILE_RX, $file) === 1 && self::absPath(['file' => $file]) !== '') {
                $have = (int) Database::scalar('SELECT COUNT(*) FROM pay_qr_codes WHERE file = :f', ['f' => $file], 0);
                if ($have === 0) {
                    $payee = trim(Settings::getString('nepal_esewa_id', ''));
                    $name = trim(Settings::getString('nepal_esewa_name', ''));
                    $now = date('Y-m-d H:i:s');
                    $id = Database::insert(self::TABLE, [
                        'country' => 'NP', 'kind' => 'esewa', 'label' => 'eSewa' . ($name !== '' ? ' · ' . mb_substr($name, 0, 60) : ' (NPJ)'),
                        'payee' => mb_substr($payee . ($payee !== '' && $name !== '' ? ' · ' . $name : ''), 0, 160),
                        'file' => $file, 'desk_code' => null, 'is_default' => 0, 'is_active' => 1, 'sort' => 0,
                        'created_by' => $by > 0 ? $by : null, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    self::$rows = null;
                    self::setDefault($id);
                }
            }
            if (!$force) {
                Settings::set(self::LEGACY_FLAG, '1', 'bool', 'payments');
                Settings::flush();
            }
            return $id;
        } catch (Throwable $e) {
            Logger::warning('pay QR legacy import failed: ' . $e->getMessage(), [], 'payments');
            return null;
        }
    }

    /** The old single setting was cleared on owner-setup: its library row stops being the default. */
    public static function legacyRemoved(string $oldFile): void
    {
        try {
            if (!self::ready() || $oldFile === '') {
                return;
            }
            Database::run("UPDATE pay_qr_codes SET is_default = 0 WHERE country = 'NP' AND file = :f", ['f' => ltrim($oldFile, '/')]);
            self::$rows = null;
        } catch (Throwable $e) {
            // the library keeps it; the office removes it there
        }
    }
}
