<?php
/**
 * includes/idcard.php — ID cards for the people who work with us
 * (22 Oct 2026, owner: team portal).
 *
 * One card for each approved S Hari staff member (staff_applications),
 * agent (agent_applications type ind) and Pvt. Ltd. partner company
 * (agent_applications type org): a CR80 card, 638 x 1011 px portrait
 * (54 x 85.6 mm at 300 dpi), front and back, drawn with GD.
 *
 *   Front  logo + S HARI GLOBAL™ wordmark, the photo (a partner: its logo),
 *          name, role in English and Nepali, ID number, office / area,
 *          mobile, issue date, and a QR to /verify-id.php.
 *   Back   the rules of the card, the holder's own signature from the
 *          form, the company's seal line, address and helpline.
 *
 * The QR carries "<REF>.<12 hex>" — the ref (STF-000005, AGR-000035) and an
 * HMAC of it with APP_KEY (Security::sign, the ticket QR's recipe), so a
 * card number cannot be made up and walked. /verify-id.php shows the
 * person's CURRENT standing from the database: a card stays printed after
 * someone is suspended or leaves, the QR then says so.
 *
 * Fonts: assets/fonts/NotoSansDevanagari.ttf (GD on the VPS has no system
 * fonts), Nepali shaped through DevShape when it is available.
 */

declare(strict_types=1);

require_once __DIR__ . '/staffapplications.php';
require_once __DIR__ . '/agentapplications.php';
require_once __DIR__ . '/docart.php';
require_once __DIR__ . '/goldsheet.php';

final class IdCard
{
    public const W = 638;
    public const H = 1011;

    private const ROYAL  = [10, 42, 143];
    private const ROYAL2 = [31, 79, 224];
    private const ORANGE = [240, 122, 30];
    private const INK    = [22, 35, 60];
    private const MUTED  = [92, 107, 133];
    private const LINE   = [222, 228, 238];

    private function __construct() {}

    /* =================================================================
     *  Who is on a card
     * ================================================================= */

    /**
     * Everything a card shows, for a ref (STF-000005 / AGR-000035), or null.
     *
     * @return array{kind: string, ref: string, name: string, roleEn: string, roleNe: string, place: string,
     *               phone: string, photo: ?string, sig: ?string, issued: string, valid: bool, standing: string,
     *               standingNe: string, contact: string}|null
     */
    public static function person(string $ref): ?array
    {
        $ref = strtoupper(trim($ref));
        if (preg_match('/^STF-(\d{1,9})$/', $ref, $m) === 1) {
            return self::forStaff((int) $m[1]);
        }
        if (preg_match('/^AGR-(\d{1,9})$/', $ref, $m) === 1) {
            // the ref column is the card number; older rows may only have the id
            $id = AgentApplications::available() ? (int) Database::scalar('SELECT id FROM agent_applications WHERE ref = :r LIMIT 1', ['r' => $ref], 0) : 0;
            return self::forAgent($id > 0 ? $id : (int) $m[1]);
        }
        return null;
    }

    public static function forStaff(int $id): ?array
    {
        $r = StaffApps::available() ? StaffApps::find($id) : null;
        if ($r === null || !in_array((string) $r['status'], ['approved', 'suspended', 'exited'], true)) {
            return null;
        }
        $st = (string) $r['status'];
        $cc = countryDialCode((string) $r['phone_country']) ?: '977';
        $photo = !empty($r['photo_path']) && ($abs = StaffApps::absPath((string) $r['photo_path'])) !== null ? (string) file_get_contents($abs) : null;
        $sig   = !empty($r['signature_path']) && ($abs = StaffApps::absPath((string) $r['signature_path'])) !== null ? (string) file_get_contents($abs) : null;
        return [
            'kind'       => 'staff',
            'ref'        => (string) $r['ref'],
            'name'       => (string) $r['full_name'],
            'roleEn'     => StaffApps::roleLabel((string) $r['role_applied']),
            'roleNe'     => StaffApps::roleLabel((string) $r['role_applied'], true),
            'place'      => trim((string) (($r['base_location'] ?? '') ?: ($r['district'] ?? ''))),
            'phone'      => '+' . $cc . ' ' . $r['phone'],
            'photo'      => $photo,
            'sig'        => $sig,
            'issued'     => substr((string) ($r['approved_at'] ?: $r['created_at']), 0, 10),
            'valid'      => $st === 'approved',
            'standing'   => match ($st) { 'approved' => 'Active staff', 'suspended' => 'Suspended', default => 'No longer with S Hari Global' },
            'standingNe' => match ($st) { 'approved' => 'सक्रिय कर्मचारी', 'suspended' => 'निलम्बित', default => 'अब कम्पनीमा हुनुहुन्न' },
            'contact'    => '',
        ];
    }

    public static function forAgent(int $id): ?array
    {
        $r = AgentApplications::find($id);
        if ($r === null || (string) $r['status'] !== 'approved') {
            return null;
        }
        $org = (string) $r['type'] === 'org';
        $active = true;
        if (!empty($r['admin_id'])) {
            $active = (int) Database::scalar('SELECT is_active FROM admins WHERE id = :i', ['i' => (int) $r['admin_id']], 0) === 1;
        }
        $f = is_array($r['fields'] ?? null) ? $r['fields'] : [];
        $pic = $org && !empty($r['logo']) ? (string) $r['logo'] : (string) ($r['photo'] ?? '');
        $raw = (string) (($r['phone_raw'] ?? '') ?: $r['phone']);
        return [
            'kind'       => $org ? 'partner' : 'agent',
            'ref'        => (string) ($r['ref'] ?: AgentApplications::refFor((int) $r['id'])),
            'name'       => (string) $r['name'],
            'roleEn'     => $org ? 'Pvt. Ltd. Partner' : 'Authorised Agent',
            'roleNe'     => $org ? 'साझेदार कम्पनी' : 'अधिकृत एजेन्ट',
            'place'      => trim((string) (($r['area'] ?? '') ?: ($r['web_addr'] ?? ''))),
            'phone'      => $raw,
            'photo'      => self::fromDataUri($pic),
            'sig'        => self::fromDataUri((string) ($r['signature'] ?? '')),
            'issued'     => substr((string) ($r['reviewed_at'] ?: $r['created_at']), 0, 10),
            'valid'      => $active,
            'standing'   => $active ? ($org ? 'Active partner' : 'Active agent') : 'Account switched off',
            'standingNe' => $active ? ($org ? 'सक्रिय साझेदार' : 'सक्रिय एजेन्ट') : 'खाता बन्द',
            'contact'    => $org ? trim((string) ($f['p_rep'] ?? '')) : '',
        ];
    }

    /** A small JPEG data: URI of a photo (the verify page shows it inline), or null. */
    public static function thumb(?string $bytes, int $max = 240): ?string
    {
        if (($src = self::open($bytes)) === null) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $k = min(1.0, $max / max($w, $h));
        $dst = imagecreatetruecolor(max(1, (int) round($w * $k)), max(1, (int) round($h * $k)));
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        ob_start();
        imagejpeg($dst, null, 82);
        imagedestroy($src);
        imagedestroy($dst);
        return 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
    }

    /**
     * 22 Oct 2026 (review, #13): the one place a card opens a photo, logo or
     * signature. Only a JPEG / PNG / WEBP whose header declares at most
     * AgentApplications::IMG_MAX_SIDE px a side and IMG_MAX_PIXELS in all —
     * a 48 KB PNG of 20000 x 20000 px used to end verify-id.php, the office's
     * print page and /team/card.php with "memory exhausted" (on the live
     * libgd: GBs of RAM per scan). Anything else is null: the card shows the
     * initials / no signature instead, never an error.
     */
    private static function open(?string $bytes): ?\GdImage
    {
        if ($bytes === null || $bytes === '' || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || !in_array((string) ($info['mime'] ?? ''), AgentApplications::PHOTO_MIME, true)
            || !AgentApplications::imageFits($info)) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        return $src instanceof \GdImage ? $src : null;
    }

    private static function fromDataUri(string $uri): ?string
    {
        if (preg_match('~^data:image/(?:jpeg|jpg|png|webp);base64,(.+)$~s', $uri, $m) !== 1) {
            return null;
        }
        $b = base64_decode($m[1], true);
        return $b === false || $b === '' ? null : $b;
    }

    /* =================================================================
     *  The QR code and the verify link
     * ================================================================= */

    public static function code(string $ref): string
    {
        $ref = strtoupper(trim($ref));
        return $ref . '.' . substr(Security::sign('idcard|' . $ref), 0, 12);
    }

    /** The ref inside a scanned code, or null when it was not signed by us. */
    public static function parse(string $code): ?string
    {
        if (preg_match('/^((?:STF|AGR)-\d{1,9})\.([a-f0-9]{12})$/i', trim($code), $m) !== 1) {
            return null;
        }
        $ref = strtoupper($m[1]);
        return hash_equals(self::code($ref), $ref . '.' . strtolower($m[2])) ? $ref : null;
    }

    public static function verifyUrl(string $ref): string
    {
        return appUrl('verify-id.php') . '?c=' . rawurlencode(self::code($ref));
    }

    /* =================================================================
     *  Drawing
     * ================================================================= */

    /** PNG bytes of one side ('front' | 'back') or both side by side ('sheet'). */
    public static function png(array $p, string $side = 'front'): string
    {
        if ($side === 'sheet') {
            $gap = 40;
            $im = imagecreatetruecolor(self::W * 2 + $gap, self::H);
            imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
            $f = self::front($p);
            $b = self::back($p);
            imagecopy($im, $f, 0, 0, 0, 0, self::W, self::H);
            imagecopy($im, $b, self::W + $gap, 0, 0, 0, self::W, self::H);
            imagedestroy($f);
            imagedestroy($b);
        } else {
            $im = $side === 'back' ? self::back($p) : self::front($p);
        }
        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    private static function front(array $p): \GdImage
    {
        $W = self::W;
        $H = self::H;
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        imagesavealpha($im, false);
        $white = self::c($im, [255, 255, 255]);
        imagefill($im, 0, 0, $white);

        // ---- head: logo + wordmark on white, orange rule ----
        self::brand($im, 26, 22, 150);
        imagefilledrectangle($im, 0, 168, $W, 175, self::c($im, self::ORANGE));

        // ---- royal band with the kind of card ----
        self::gradient($im, 0, 176, $W, 420, self::ROYAL, self::ROYAL2);
        $kindEn = ['staff' => 'STAFF IDENTITY CARD', 'agent' => 'AGENT IDENTITY CARD', 'partner' => 'PARTNER IDENTITY CARD'][$p['kind']] ?? 'IDENTITY CARD';
        $kindNe = ['staff' => 'कर्मचारी परिचयपत्र', 'agent' => 'एजेन्ट परिचयपत्र', 'partner' => 'साझेदार परिचयपत्र'][$p['kind']] ?? 'परिचयपत्र';
        self::tC($im, 19, 0, $W, 196, $white, $kindEn, true);
        self::tC($im, 16, 0, $W, 232, self::c($im, [255, 215, 160]), $kindNe);

        // ---- photo (a partner: the logo, contained on white) ----
        $px = 184; $py = 276; $pw = 270; $ph = 300;
        self::roundRect($im, $px - 9, $py - 9, $px + $pw + 9, $py + $ph + 9, 26, $white);
        self::roundRect($im, $px - 3, $py - 3, $px + $pw + 3, $py + $ph + 3, 22, self::c($im, self::ORANGE));
        self::roundRect($im, $px, $py, $px + $pw, $py + $ph, 20, self::c($im, [238, 243, 252]));
        $drawn = $p['photo'] !== null && self::rounded($im, (string) $p['photo'], $px, $py, $pw, $ph, 20, $p['kind'] === 'partner');
        if (!$drawn) {
            $ini = self::initials((string) $p['name']);
            self::tC($im, 80, $px, $px + $pw, $py + 95, self::c($im, self::ROYAL), $ini, true);
        }

        // ---- name + role ----
        $ink = self::c($im, self::INK);
        $y = 600;
        $names = self::wrap((string) $p['name'], 27, $W - 80, 2);
        $ns = count($names) > 1 ? 23 : 27;
        foreach (count($names) > 1 ? self::wrap((string) $p['name'], $ns, $W - 70, 2) : $names as $line) {
            self::tC($im, $ns, 0, $W, $y, $ink, $line, true);
            $y += $ns > 24 ? 46 : 38;
        }
        $role = strtoupper((string) $p['roleEn']) . '  ·  ' . $p['roleNe'];
        $rw = self::w(15, $role) + 44;
        $rx = (int) (($W - $rw) / 2);
        self::roundRect($im, $rx, $y + 4, $rx + $rw, $y + 46, 21, self::c($im, [255, 228, 199]));
        self::tC($im, 15, 0, $W, $y + 12, self::c($im, [154, 74, 0]), $role, true);
        $y += 62;

        // ---- details (left) + QR (right), above the foot band ----
        $qrBox = 172;
        $qx = $W - 34 - $qrBox;
        $qy = max($y, 716);
        $muted = self::c($im, self::MUTED);
        $rows = [
            ['ID No.', (string) $p['ref']],
            [$p['kind'] === 'staff' ? 'Office' : 'Area', (string) ($p['place'] ?: '—')],
            ['Mobile', (string) $p['phone']],
            ['Issued', self::date((string) $p['issued'])],
        ];
        if ($p['contact'] !== '') {
            array_splice($rows, 1, 0, [['Contact', (string) $p['contact']]]);
        }
        // as many rows as fit (Issued, last, goes first), 44-50 px each
        $avail = $H - 58 - 8 - $qy;
        $rows = array_slice($rows, 0, max(2, min(count($rows), intdiv($avail, 44))));
        $step = min(50, intdiv($avail, count($rows)));
        $ly = $qy - 2;
        foreach ($rows as [$k, $v]) {
            self::t($im, 12.5, 40, $ly, $muted, $k);
            $v = self::fit($v, 16, $qx - 40 - 20);
            self::t($im, 16, 40, $ly + 19, $ink, $v, true);
            $ly += $step;
        }
        GoldSheet::qr($im, self::verifyUrl((string) $p['ref']), $qx, $qy, $qrBox);
        $side = GoldSheet::qrSide(self::verifyUrl((string) $p['ref']), $qrBox);
        self::tC($im, 11.5, $qx, $qx + $side, $qy + $side + 2, $muted, 'Scan to verify · जाँच्नुहोस्');

        // ---- foot band ----
        self::gradient($im, 0, $H - 58, $W, $H, self::ROYAL2, self::ROYAL);
        $co = Settings::company();
        self::tC($im, 14, 0, $W, $H - 44, $white, 'www.' . preg_replace('~^(https?://)?(www\.)?~', '', (string) $co['web']) . '   ·   ' . $co['phone'], true);
        return $im;
    }

    private static function back(array $p): \GdImage
    {
        $W = self::W;
        $H = self::H;
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        $white = self::c($im, [255, 255, 255]);
        imagefill($im, 0, 0, $white);
        $ink = self::c($im, self::INK);
        $muted = self::c($im, self::MUTED);

        self::gradient($im, 0, 0, $W, 120, self::ROYAL, self::ROYAL2);
        self::tC($im, 18, 0, $W, 26, $white, 'S HARI GLOBAL PRIVATE LIMITED', true);
        self::tC($im, 14, 0, $W, 64, self::c($im, [255, 215, 160]), 'एस हरि ग्लोबल प्राइभेट लिमिटेड');
        imagefilledrectangle($im, 0, 120, $W, 126, self::c($im, self::ORANGE));

        $rules = [
            ['This card belongs to S Hari Global Pvt. Ltd. Carry it while on duty and show it when asked.',
             'यो परिचयपत्र कम्पनीको सम्पत्ति हो। काम गर्दा साथमा राख्नुहोस्।'],
            ['It is valid only while the QR on the front says "Active". Scan it to check.',
             'अगाडिको QR ले "सक्रिय" देखाएसम्म मात्र मान्य।'],
            ['Never lend it. If found, please return it to any S Hari Global office or call the helpline.',
             'अरूलाई नदिनुहोस्। भेटिएमा नजिकको कार्यालयमा फिर्ता गर्नुहोस्।'],
        ];
        $y = 150;
        foreach ($rules as $i => [$en, $ne]) {
            self::t($im, 15, 34, $y, self::c($im, self::ORANGE), (string) ($i + 1) . '.', true);
            foreach (self::wrap($en, 13.5, $W - 100, 3) as $line) {
                self::t($im, 13.5, 62, $y, $ink, $line);
                $y += 26;
            }
            foreach (self::wrap($ne, 13, $W - 100, 2) as $line) {
                self::t($im, 13, 62, $y, $muted, $line);
                $y += 26;
            }
            $y += 12;
        }

        // ---- holder's signature (from the form) + the company ----
        $y += 22;
        $line = self::c($im, self::LINE);
        $bw = (int) (($W - 34 * 2 - 30) / 2);
        $lx = 34; $rx = 34 + $bw + 30;
        self::roundRect($im, $lx, $y, $lx + $bw, $y + 150, 14, self::c($im, [247, 249, 253]));
        self::roundRect($im, $rx, $y, $rx + $bw, $y + 150, 14, self::c($im, [247, 249, 253]));
        if ($p['sig'] !== null) {
            self::picture($im, (string) $p['sig'], $lx + 14, $y + 10, $bw - 28, 100, true);
        }
        imageline($im, $lx + 14, $y + 116, $lx + $bw - 14, $y + 116, $line);
        self::tC($im, 12, $lx, $lx + $bw, $y + 122, $muted, $p['kind'] === 'partner' ? 'Partner signature' : 'Holder signature');
        GoldSheet::seal($im, $rx + intdiv($bw, 2), $y + 62, 92);
        imageline($im, $rx + 14, $y + 116, $rx + $bw - 14, $y + 116, $line);
        self::tC($im, 12, $rx, $rx + $bw, $y + 122, $muted, 'Authorised signatory');
        $y += 176;

        $co = Settings::company();
        self::tC($im, 13, 0, $W, $y, $ink, 'Head office', true);
        $y += 26;
        foreach (self::wrap((string) $co['address'], 12.5, $W - 80, 2) as $l) {
            self::tC($im, 12.5, 0, $W, $y, $muted, $l);
            $y += 24;
        }
        self::tC($im, 12.5, 0, $W, $y + 2, $muted, 'CIN ' . $co['cin'] . '   ·   ' . $co['email']);

        // ---- how to check this card ----
        // 22 Oct 2026 (review, #14): verify-id.php has no box to type a card number into, so the card no longer
        // says "or open verify-id.php". It says which address the QR must open (a QR to a look-alike site is
        // caught by reading it) and that the office checks a card number by phone (admin/id-card.php?ref=).
        $vy = $H - 70 - 20 - 164;
        $box = $W - 34 * 2 - 36;
        self::roundRect($im, 34, $vy, $W - 34, $vy + 164, 18, self::c($im, [238, 244, 252]));
        self::tC($im, 14, 0, $W, $vy + 12, self::c($im, self::ROYAL), 'Card No. ' . $p['ref'] . '   ·   Issued ' . self::date((string) $p['issued']), true);
        self::tC($im, 12.5, 0, $W, $vy + 42, $muted, self::fit('Check it: scan the QR on the front. It must open only', 12.5, $box));
        self::tC($im, 13, 0, $W, $vy + 64, $ink, self::fit((string) preg_replace('~^https?://~', '', appUrl('verify-id.php')), 13, $box), true);
        self::tC($im, 12.5, 0, $W, $vy + 91, $muted, self::fit('No QR reader? Call the office on ' . $co['phone'] . ' with the card No.', 12.5, $box));
        $ny = $vy + 117;
        foreach (self::wrap('QR ले यही ठेगाना मात्र खोल्नुपर्छ, अरू खुले नक्कली हो। QR नचले कार्ड नं. सहित कार्यालयमा फोन गर्नुहोस्।', 12, $box, 2) as $l) {
            self::tC($im, 12, 0, $W, $ny, $muted, $l);
            $ny += 22;
        }

        self::gradient($im, 0, $H - 70, $W, $H, self::ROYAL2, self::ROYAL);
        self::tC($im, 13, 0, $W, $H - 60, self::c($im, [255, 215, 160]), 'Helpline · हेल्पलाइन', true);
        self::tC($im, 16, 0, $W, $H - 36, $white, (string) $co['phone'] . '   ·   WhatsApp ' . self::waShow((string) $co['whatsapp']), true);
        return $im;
    }

    /* ---------- pieces ---------- */

    /** The company mark + "S HARI GLOBAL™ / PRIVATE LIMITED" at (x, y), mark $h high. */
    private static function brand(\GdImage $im, int $x, int $y, int $h): void
    {
        $m = DocArt::mark($im, $x, $y + 6, 190, $h - 34);
        $tx = $x + (is_array($m) ? (int) $m['w'] : 0) + 18;
        $ink = self::c($im, self::ROYAL);
        self::t($im, 30, $tx, $y + 22, $ink, 'S HARI GLOBAL', true);
        $tw = self::w(30, 'S HARI GLOBAL');
        self::t($im, 13, $tx + $tw + 4, $y + 18, $ink, '™', true);
        self::t($im, 14.5, $tx + 2, $y + 74, self::c($im, self::ORANGE), 'PRIVATE  LIMITED', true);
        self::t($im, 11.5, $tx + 2, $y + 104, self::c($im, self::MUTED), 'India – Nepal bus service');
    }

    /** Draw image bytes into a box: cover (photo) or contain (logo, signature). */
    private static function picture(\GdImage $im, string $bytes, int $x, int $y, int $w, int $h, bool $contain): bool
    {
        $src = self::open($bytes);
        if ($src === null) {
            return false;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($contain) {
            $k = min($w / $sw, $h / $sh);
            $dw = max(1, (int) round($sw * $k));
            $dh = max(1, (int) round($sh * $k));
            imagefilledrectangle($im, $x, $y, $x + $w, $y + $h, self::c($im, [255, 255, 255]));
            imagecopyresampled($im, $src, $x + intdiv($w - $dw, 2), $y + intdiv($h - $dh, 2), 0, 0, $dw, $dh, $sw, $sh);
        } else {
            $k = max($w / $sw, $h / $sh);
            $cw = (int) round($w / $k);
            $ch = (int) round($h / $k);
            $cx = intdiv($sw - $cw, 2);
            $cy = max(0, (int) round(($sh - $ch) * 0.3));   // faces sit high in a selfie
            imagecopyresampled($im, $src, $x, $y, $cx, $cy, $w, $h, $cw, $ch);
        }
        imagedestroy($src);
        return true;
    }

    /** picture() inside a rounded box: what was under the four corners is put back. */
    private static function rounded(\GdImage $im, string $bytes, int $x, int $y, int $w, int $h, int $r, bool $contain): bool
    {
        $bak = imagecreatetruecolor($w, $h);
        imagecopy($bak, $im, 0, 0, $x, $y, $w, $h);
        if (!self::picture($im, $bytes, $x, $y, $w, $h, $contain)) {
            imagedestroy($bak);
            return false;
        }
        for ($i = 0; $i < $r; $i++) {
            for ($j = 0; $j < $r; $j++) {
                $dx = $r - $i - 0.5;
                $dy = $r - $j - 0.5;
                if ($dx * $dx + $dy * $dy <= $r * $r) {
                    continue;
                }
                foreach ([[$i, $j], [$w - 1 - $i, $j], [$i, $h - 1 - $j], [$w - 1 - $i, $h - 1 - $j]] as [$cx, $cy]) {
                    imagesetpixel($im, $x + $cx, $y + $cy, imagecolorat($bak, $cx, $cy));
                }
            }
        }
        imagedestroy($bak);
        return true;
    }

    private static function roundRect(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $col): void
    {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $col);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $col);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $col);
        }
    }

    private static function gradient(\GdImage $im, int $x1, int $y1, int $x2, int $y2, array $a, array $b): void
    {
        $n = max(1, $y2 - $y1);
        for ($i = 0; $i <= $n; $i++) {
            $t = $i / $n;
            imageline($im, $x1, $y1 + $i, $x2, $y1 + $i, self::c($im, [
                (int) round($a[0] + ($b[0] - $a[0]) * $t), (int) round($a[1] + ($b[1] - $a[1]) * $t), (int) round($a[2] + ($b[2] - $a[2]) * $t),
            ]));
        }
    }

    private static function c(\GdImage $im, array $rgb): int
    {
        return imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
    }

    private static function font(): string
    {
        return dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari.ttf';
    }

    /** Text with y = TOP edge; faux bold by a double strike (the face has no Bold). */
    private static function t(\GdImage $im, float $size, int $x, int $y, int $col, string $text, bool $bold = false): void
    {
        if ($text === '') {
            return;
        }
        $flat = dev_shape($text);
        $box = imagettfbbox($size, 0, self::font(), $flat);
        $base = $y - (int) min($box[5], $box[7]);
        if (class_exists('DevShape') && DevShape::needs($text)
            && DevShape::gdText($im, $size, $x, $base, $col, $text, $bold ? [[0, 0], [1, 0]] : [[0, 0]])) {
            return;
        }
        imagettftext($im, $size, 0, $x, $base, $col, self::font(), $flat);
        if ($bold) {
            imagettftext($im, $size, 0, $x + 1, $base, $col, self::font(), $flat);
        }
    }

    private static function w(float $size, string $text): int
    {
        if ($text === '') {
            return 0;
        }
        if (class_exists('DevShape') && DevShape::needs($text) && ($sw = DevShape::gdWidth($size, $text)) !== null) {
            return $sw;
        }
        $box = imagettfbbox($size, 0, self::font(), dev_shape($text));
        return (int) (max($box[2], $box[4]) - min($box[0], $box[6]));
    }

    private static function tC(\GdImage $im, float $size, int $xl, int $xr, int $y, int $col, string $text, bool $bold = false): void
    {
        self::t($im, $size, (int) (($xl + $xr - self::w($size, $text)) / 2), $y, $col, $text, $bold);
    }

    /** @return list<string> at most $max lines of $width px; the last one cut with … */
    private static function wrap(string $text, float $size, int $width, int $max): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $wd) {
            $try = $cur === '' ? $wd : $cur . ' ' . $wd;
            if ($cur !== '' && self::w($size, $try) > $width) {
                $lines[] = $cur;
                $cur = $wd;
            } else {
                $cur = $try;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        if (count($lines) > $max) {
            $lines = array_slice($lines, 0, $max);
            $lines[$max - 1] = self::fit($lines[$max - 1] . ' …', $size, $width);
        }
        return $lines;
    }

    private static function fit(string $text, float $size, int $width): string
    {
        if (self::w($size, $text) <= $width) {
            return $text;
        }
        while (mb_strlen($text) > 2 && self::w($size, $text . '…') > $width) {
            $text = mb_substr($text, 0, -1);
        }
        return rtrim($text) . '…';
    }

    private static function initials(string $name): string
    {
        $p = preg_split('/\s+/u', trim($name)) ?: [];
        $a = mb_substr((string) ($p[0] ?? ''), 0, 1);
        $b = count($p) > 1 ? mb_substr((string) end($p), 0, 1) : '';
        return mb_strtoupper($a . $b) ?: '★';
    }

    private static function date(string $ymd): string
    {
        $t = strtotime($ymd);
        return $t ? date('d M Y', $t) : '—';
    }

    private static function waShow(string $digits): string
    {
        $d = preg_replace('/\D/', '', $digits) ?? '';
        return strlen($d) === 12 ? '+' . substr($d, 0, 2) . ' ' . substr($d, 2, 5) . ' ' . substr($d, 7) : ($d !== '' ? '+' . $d : '');
    }
}
