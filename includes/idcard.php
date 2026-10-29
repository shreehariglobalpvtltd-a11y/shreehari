<?php
/**
 * includes/idcard.php — ID cards & visiting cards for S Hari Global team
 * (22-25 Oct 2026, owner: team portal).
 *
 * One card for each approved S Hari staff member (staff_applications),
 * agent (agent_applications type ind), driver, and Pvt. Ltd. partner company
 * (agent_applications type org): a CR80 card, 638 x 1011 px portrait
 * (54 x 85.6 mm at 300 dpi), front and back, drawn with GD.
 *
 * Visiting card: 1050 x 602 px landscape (89 x 51 mm at 300 dpi).
 *
 * TWO DISTINCT QR CODES:
 *   1. Verification QR (Front):
 *      Destination: https://www.shreehariglobal.in/verify-id.php?c=<REF>.<HMAC>
 *      Label: "Scan to verify · जाँच्नुहोस्"
 *      Purpose: Verifies authentic standing in current company database.
 *
 *   2. Personal WhatsApp QR (Back):
 *      Destination: https://wa.me/<countrycode><number>
 *      Label: "Personal WhatsApp · व्यक्तिगत सम्पर्क (Does not verify identity / परिचय प्रमाणीकरण गर्दैन)"
 *      Purpose: Direct chat with the person; explicitly does not verify identity.
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
     * Everything a card shows, for a ref (STF-000005, AGR-000035, DRV-000012, SHG-XXXXX), or null.
     */
    public static function person(string $ref): ?array
    {
        require_once __DIR__.'/teamregistry.php';
        $ref=strtoupper(trim($ref));
        if (preg_match('/^[AS]-[1-9][0-9]*$/',$ref)) { $ref=TeamRegistry::refForCode($ref)??''; }
        $p=self::lookupPerson($ref); if (!$p) { return null; }
        $key=TeamRegistry::key($p['ref']); $m=TeamRegistry::meta($key);
        $p['memberCode']=$m['code']??$p['ref']; $p['route']=$m['route']??'';
        if (!empty($m['position_name'])) { $p['roleEn']=$m['position_name']; }
        $p['studio']=json_decode((string)($m['template_json']??''),true)?:null;
        if (!$p['public']) { $p['waDigits']=''; }
        $r=str_starts_with($key,'staff:')?StaffApps::find((int)substr($key,6)):AgentApplications::find((int)substr($key,6));
        $p['waDigits']=$p['public']?TeamRegistry::verifiedIntl($p['ref']):'';
        $p['kycVerified']=($r['kyc_status']??'')==='verified';
        if (!$p['kycVerified']) { $p['valid']=false; $p['standing']='KYC review pending'; $p['standingNe']='KYC जाँच बाँकी'; $p['waDigits']=''; }
        if (!empty($p['expires']) && $p['expires']<date('Y-m-d')) { $p['valid']=false; $p['standing']='Expired'; $p['waDigits']=''; }
        return $p;
    }

    private static function lookupPerson(string $ref): ?array
    {
        $ref = strtoupper(trim($ref));
        if (preg_match('/^STF-(\d{1,9})$/', $ref, $m) === 1) {
            return self::forStaff((int) $m[1]);
        }
        if (preg_match('/^AGR-(\d{1,9})$/', $ref, $m) === 1) {
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
        if (class_exists('Settings') && method_exists('Settings', 'getArray') && isset(Settings::getArray('team_manager_archive', [])['staff:' . $id])) { $st = 'exited'; }
        $cc = countryDialCode((string) $r['phone_country']) ?: '977';
        $photo = !empty($r['photo_path']) && ($abs = StaffApps::absPath((string) $r['photo_path'])) !== null && is_file($abs) ? (string) file_get_contents($abs) : null;
        $sig   = !empty($r['signature_path']) && ($abs = StaffApps::absPath((string) $r['signature_path'])) !== null && is_file($abs) ? (string) file_get_contents($abs) : null;
        $pub   = (int) ($r['show_on_website'] ?? 0) === 1 ? array_flip(StaffApps::publicFields()) : [];
        $links = self::links((string) ($r['cv_json'] ?? ''));
        $issued = substr((string) ($r['approved_at'] ?: $r['created_at']), 0, 10);
        $phoneDigits = preg_replace('/\D/', '', (string) $r['phone']) ?? '';
        return [
            'kind'       => 'staff',
            'expires'    => self::plusMonths($issued, 12),
            'public'     => isset($pub['phone']),
            'waDigits'   => $cc . $phoneDigits,
            'email'      => isset($pub['phone']) ? trim((string) ($r['email'] ?? '')) : '',
            'facebook'   => isset($pub['phone']) ? $links['facebook'] : '',
            'ref'        => (string) $r['ref'],
            'name'       => (string) $r['full_name'],
            'roleEn'     => StaffApps::roleLabel((string) $r['role_applied']),
            'roleNe'     => StaffApps::roleLabel((string) $r['role_applied'], true),
            'place'      => trim((string) (($r['base_location'] ?? '') ?: ($r['district'] ?? ''))),
            'phone'      => '+' . $cc . ' ' . $r['phone'],
            'photo'      => $photo,
            'sig'        => $sig,
            'issued'     => $issued,
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
        $archived = class_exists('Settings') && method_exists('Settings', 'getArray') && isset(Settings::getArray('team_manager_archive', [])['agent:' . $id]);
        if (!empty($r['admin_id'])) {
            $active = (int) Database::scalar('SELECT is_active FROM admins WHERE id = :i', ['i' => (int) $r['admin_id']], 0) === 1;
        }
        if ($archived) {
            $active = false;
        }
        $f = is_array($r['fields'] ?? null) ? $r['fields'] : [];
        $pic = $org && !empty($r['logo']) ? (string) $r['logo'] : (string) ($r['photo'] ?? '');
        $raw = (string) (($r['phone_raw'] ?? '') ?: $r['phone']);
        $pub = (int) ($r['public_ok'] ?? 0) === 1 && (int) ($r['web_live'] ?? 1) === 1 && $active;
        $digits = preg_replace('/\D/', '', (string) $r['phone']) ?? '';
        if (strlen($digits) === 10) {
            $digits = (countryDialCode(resolvePhoneCountry('', $raw)) ?: '91') . $digits;
        }
        $links = self::links((string) ($r['cv_json'] ?? ''));
        $issued = substr((string) ($r['reviewed_at'] ?: $r['created_at']), 0, 10);
        $end = trim((string) ($r['agreement_end'] ?? ''));
        // 29 Oct 2026: the agent's own SHG code (SHG-0027) prints big on the card when the card has a login.
        $agentCode = '';
        if (!empty($r['admin_id'])) {
            require_once __DIR__ . '/agentwallet.php';
            $agentCode = AgentWallet::agentCodeLabel((int) $r['admin_id']);
        }
        if ($active && $end !== '' && $end !== '0000-00-00' && substr($end, 0, 10) < date('Y-m-d')) {
            $active = false;
        }
        return [
            'kind'       => $org ? 'partner' : 'agent',
            'expires'    => $end !== '' && $end !== '0000-00-00' ? substr($end, 0, 10) : self::plusMonths($issued, AgentApplications::TERM_MONTHS),
            'public'     => $pub,
            'waDigits'   => $digits,
            'email'      => $pub ? trim((string) ($f['p_email'] ?? '')) : '',
            'facebook'   => $pub ? $links['facebook'] : '',
            'ref'        => (string) ($r['ref'] ?: AgentApplications::refFor((int) $r['id'])),
            'name'       => (string) $r['name'],
            'roleEn'     => $org ? 'Pvt. Ltd. Partner' : 'Authorised Agent',
            'roleNe'     => $org ? 'साझेदार कम्पनी' : 'अधिकृत एजेन्ट',
            'place'      => trim((string) (($r['area'] ?? '') ?: ($r['web_addr'] ?? ''))),
            'phone'      => $raw,
            'photo'      => self::fromDataUri($pic),
            'sig'        => self::fromDataUri((string) ($r['signature'] ?? '')),
            'issued'     => $issued,
            'valid'      => $active,
            'standing'   => $active ? ($org ? 'Active partner' : 'Active agent') : ($end !== '' && $end !== '0000-00-00' && substr($end, 0, 10) < date('Y-m-d') ? 'Agreement ended' : 'Account switched off'),
            'standingNe' => $active ? ($org ? 'सक्रिय साझेदार' : 'सक्रिय एजेन्ट') : ($end !== '' && $end !== '0000-00-00' && substr($end, 0, 10) < date('Y-m-d') ? 'सम्झौता सकियो' : 'खाता बन्द'),
            'contact'    => $org ? trim((string) ($f['p_rep'] ?? '')) : '',
            'agentCode'  => $agentCode,
        ];
    }

    public static function forDriver(int $id): ?array
    {
        try {
            $d = Database::fetch("SELECT * FROM drivers WHERE id = :id LIMIT 1", ['id' => $id]);
            if ($d === null) {
                return null;
            }
            $raw = (string) $d['phone'];
            $digits = preg_replace('/\D/', '', $raw) ?? '';
            $cc = countryDialCode(resolvePhoneCountry('', $raw)) ?: '977';
            $waDigits = strlen($digits) === 10 ? $cc . $digits : $digits;
            $active = (int) ($d['is_active'] ?? 1) === 1;
            $issued = substr((string) ($d['created_at'] ?? date('Y-m-d')), 0, 10);
            return [
                'kind'       => 'staff',
                'expires'    => !empty($d['licence_exp']) ? (string) $d['licence_exp'] : self::plusMonths($issued, 12),
                'public'     => true,
                'waDigits'   => $waDigits,
                'email'      => '',
                'facebook'   => '',
                'ref'        => 'DRV-' . str_pad((string) $d['id'], 6, '0', STR_PAD_LEFT),
                'name'       => (string) $d['full_name'],
                'roleEn'     => ucfirst((string) ($d['role'] ?: 'driver')),
                'roleNe'     => 'चालक (सवारी चालक)',
                'place'      => 'Nepalgunj / Surat route',
                'phone'      => '+' . $cc . ' ' . (strlen($digits) === 10 ? $digits : substr($digits, -10)),
                'photo'      => null,
                'sig'        => null,
                'issued'     => $issued,
                'valid'      => $active,
                'standing'   => $active ? 'Active driver' : 'Inactive driver',
                'standingNe' => $active ? 'सक्रिय चालक' : 'निष्क्रिय चालक',
                'contact'    => !empty($d['licence_no']) ? 'Lic: ' . $d['licence_no'] : '',
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

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
     *  The QR codes & URL helpers
     * ================================================================= */

    public static function code(string $ref): string
    {
        $ref = strtoupper(trim($ref));
        return $ref . '.' . substr(Security::sign('idcard|' . $ref), 0, 12);
    }

    public static function parse(string $code): ?string
    {
        if (preg_match('/^((?:STF|AGR|DRV|SHG)-[A-Z0-9-]+)\.([a-f0-9]{12})$/i', trim($code), $m) !== 1) {
            return null;
        }
        $ref = strtoupper($m[1]);
        return hash_equals(self::code($ref), $ref . '.' . strtolower($m[2])) ? $ref : null;
    }

    /** Official ID Verification URL */
    public static function verifyUrl(string $ref): string
    {
        return appUrl('verify-id.php') . '?c=' . rawurlencode(self::code($ref));
    }

    /** Personal WhatsApp URL — opens direct chat with this person */
    public static function waQrUrl(array $p): string {
        $d=(string)($p['waDigits']??'');
        return preg_match('/^(91[6-9][0-9]{9}|9779[78][0-9]{8})$/',$d)?'https://wa.me/'.$d:'';
    }

    public static function waLink(array $p): string
    {
        $d = (string) ($p['waDigits'] ?? '');
        if (!preg_match('/^(91[6-9][0-9]{9}|9779[78][0-9]{8})$/', $d)) {
            return '';
        }
        $co = Settings::company();
        $txt = 'Namaste ' . $p['name'] . ' ji, I found your ' . $co['name'] . ' ' . $p['roleEn'] . ' card (' . $p['ref'] . ') on '
             . preg_replace('~^(https?://)?(www\.)?~', '', (string) $co['web']) . '. I would like to enquire about bus tickets.';
        return 'https://wa.me/' . $d . '?text=' . rawurlencode($txt);
    }

    /* =================================================================
     *  Drawing ID Card
     * ================================================================= */

    public static function png(array $p, string $side = 'front'): string
    {
        if (!empty($p['studio'])) { return self::designPng($p,'id',$side,$p['studio']); }
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
        imageresolution($im,300,300);
        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    /* =================================================================
     *  29 Oct 2026 — the professional card (owner: "ekdum ramro,
     *  profesional manche le banako jasto, hamro website ko bus aunu
     *  paryo, logo with TM, agent code, name, website").
     *
     *  Every face is drawn at 2x (SS) and scaled down once, so circles,
     *  rounded corners and the livery swoosh come out smooth. Latin text
     *  is set in Poppins (OFL, assets/fonts); Nepali still goes through
     *  t() (Noto + HarfBuzz shaping). The coach is the website's own
     *  photo (assets/img/hd/bus-hero.webp, bus-cutout.webp) — never a
     *  drawn bus (feedback: realistic only).
     * ================================================================= */

    private const SS    = 2;
    private const NAVY  = [9, 27, 84];
    private const NAVY2 = [24, 58, 148];
    private const GOLD  = [246, 176, 38];

    private static function k(float $v): int
    {
        return (int) round($v * self::SS);
    }

    private static function ss(int $w, int $h): \GdImage
    {
        $im = imagecreatetruecolor($w * self::SS, $h * self::SS);
        imagealphablending($im, true);
        imagesavealpha($im, false);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        return $im;
    }

    private static function down(\GdImage $big, int $w, int $h): \GdImage
    {
        $im = imagecreatetruecolor($w, $h);
        imagecopyresampled($im, $big, 0, 0, 0, 0, $w, $h, imagesx($big), imagesy($big));
        imagedestroy($big);
        return $im;
    }

    /** One decoded site image (webp / png), kept for the request. Never destroy it. */
    private static function art(string $rel): ?\GdImage
    {
        static $cache = [];
        if (array_key_exists($rel, $cache)) {
            return $cache[$rel];
        }
        $im  = null;
        $abs = dirname(__DIR__) . '/' . ltrim($rel, '/');
        if (is_file($abs) && ($bytes = @file_get_contents($abs)) !== false) {
            $g = @imagecreatefromstring($bytes);
            if ($g instanceof \GdImage) {
                if (!imageistruecolor($g)) {
                    imagepalettetotruecolor($g);
                }
                imagealphablending($g, true);
                imagesavealpha($g, true);
                $im = $g;
            }
        }
        return $cache[$rel] = $im;
    }

    /** A site photo filling the box (cropped, focus fx / fy 0..1). */
    private static function cover(\GdImage $im, string $rel, int $x, int $y, int $w, int $h, float $fx = 0.5, float $fy = 0.5): bool
    {
        $src = self::art($rel);
        if ($src === null) {
            return false;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        $k  = max($w / $sw, $h / $sh);
        $cw = (int) round($w / $k);
        $ch = (int) round($h / $k);
        imagecopyresampled($im, $src, $x, $y, (int) round(($sw - $cw) * $fx), (int) round(($sh - $ch) * $fy), $w, $h, $cw, $ch);
        return true;
    }

    /** A site image (transparent PNG / WebP) fitted inside the box, optionally see-through (0 opaque .. 127 clear). */
    private static function fitArt(\GdImage $im, string $rel, int $x, int $y, int $w, int $h, string $align = 'center', int $fade = 0): bool
    {
        $src = self::art($rel);
        if ($src === null) {
            return false;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        $k  = min($w / $sw, $h / $sh);
        $dw = max(1, (int) round($sw * $k));
        $dh = max(1, (int) round($sh * $k));
        $dx = $align === 'left' ? $x : ($align === 'right' ? $x + $w - $dw : $x + intdiv($w - $dw, 2));
        $dy = $y + intdiv($h - $dh, 2);
        if ($fade <= 0) {
            imagecopyresampled($im, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
            return true;
        }
        $tmp = imagecreatetruecolor($dw, $dh);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
        imagecopyresampled($tmp, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        imagefilter($tmp, IMG_FILTER_COLORIZE, 0, 0, 0, max(0, min(127, $fade)));
        imagealphablending($tmp, true);
        imagecopy($im, $tmp, $dx, $dy, 0, 0, $dw, $dh);
        imagedestroy($tmp);
        return true;
    }

    /** A colour wash from alpha $a1 to $a2 (0 opaque .. 127 clear), top→bottom or left→right. */
    private static function shade(\GdImage $im, int $x1, int $y1, int $x2, int $y2, array $rgb, int $a1, int $a2, bool $vertical = true): void
    {
        $n = max(1, $vertical ? $y2 - $y1 : $x2 - $x1);
        for ($i = 0; $i <= $n; $i++) {
            $a = max(0, min(127, (int) round($a1 + ($a2 - $a1) * $i / $n)));
            $c = imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], $a);
            if ($vertical) {
                imageline($im, $x1, $y1 + $i, $x2, $y1 + $i, $c);
            } else {
                imageline($im, $x1 + $i, $y1, $x1 + $i, $y2, $c);
            }
        }
    }

    private static function pf(string $weight): string
    {
        $f = dirname(__DIR__) . '/assets/fonts/Poppins-' . $weight . '.ttf';
        return is_file($f) ? $f : self::font();
    }

    private static function latin(string $s): bool
    {
        return preg_match('/[\x{0900}-\x{097F}]/u', $s) !== 1;
    }

    /** Text with its cap-top at $y (Poppins; Nepali falls back to the shaped Noto path). */
    private static function pt(\GdImage $im, float $size, int $x, int $y, int $col, string $text, string $weight = 'SemiBold', float $track = 0.0): void
    {
        if ($text === '') {
            return;
        }
        if (!self::latin($text)) {
            self::t($im, $size, $x, $y, $col, $text, in_array($weight, ['Bold', 'SemiBold'], true));
            return;
        }
        $f   = self::pf($weight);
        $b   = imagettfbbox($size, 0, $f, 'H');
        $asc = -min($b[5], $b[7]);
        if ($track <= 0) {
            imagettftext($im, $size, 0, $x, $y + $asc, $col, $f, $text);
            return;
        }
        foreach (mb_str_split($text) as $ch) {
            imagettftext($im, $size, 0, $x, $y + $asc, $col, $f, $ch);
            $x += self::pw($size, $ch, $weight) + (int) round($track);
        }
    }

    private static function pw(float $size, string $text, string $weight = 'SemiBold', float $track = 0.0): int
    {
        if ($text === '') {
            return 0;
        }
        if (!self::latin($text)) {
            return self::w($size, $text);
        }
        if ($track > 0) {
            $w = 0;
            foreach (mb_str_split($text) as $ch) {
                $w += self::pw($size, $ch, $weight) + (int) round($track);
            }
            return $w - (int) round($track);
        }
        $b = imagettfbbox($size, 0, self::pf($weight), $text);
        return (int) (max($b[2], $b[4]) - min($b[0], $b[6]));
    }

    private static function ptC(\GdImage $im, float $size, int $xl, int $xr, int $y, int $col, string $text, string $weight = 'SemiBold', float $track = 0.0): void
    {
        self::pt($im, $size, (int) (($xl + $xr - self::pw($size, $text, $weight, $track)) / 2), $y, $col, $text, $weight, $track);
    }

    private static function pfit(string $text, float $size, int $width, string $weight = 'SemiBold'): string
    {
        if (self::pw($size, $text, $weight) <= $width) {
            return $text;
        }
        while (mb_strlen($text) > 2 && self::pw($size, $text . '…', $weight) > $width) {
            $text = mb_substr($text, 0, -1);
        }
        return rtrim($text) . '…';
    }

    /** @return list<string> */
    private static function pwrap(string $text, float $size, int $width, int $max, string $weight = 'SemiBold'): array
    {
        $lines = [];
        $cur = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $wd) {
            $try = $cur === '' ? $wd : $cur . ' ' . $wd;
            if ($cur !== '' && self::pw($size, $try, $weight) > $width) {
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
            $lines[$max - 1] = self::pfit($lines[$max - 1] . ' …', $size, $width, $weight);
        }
        return $lines;
    }

    /** The code printed big on the card: the agent's SHG code, else the member / card number. */
    private static function cardCode(array $p): array
    {
        $label = ['agent' => 'AGENT CODE', 'partner' => 'PARTNER CODE'][$p['kind']] ?? 'STAFF ID';
        $code  = (string) (($p['agentCode'] ?? '') !== '' ? $p['agentCode'] : ($p['memberCode'] ?? $p['ref']));
        return [$label, $code];
    }

    private static function web(): string
    {
        return 'www.' . preg_replace('~^(https?://)?(www\.)?~', '', (string) Settings::company()['web']);
    }

    /** The bus livery swoosh: navy, gold and orange bands sloping across the card. */
    private static function swoosh(\GdImage $im, int $W, int $yl, int $yr): void
    {
        $navy = self::c($im, self::NAVY);
        $gold = self::c($im, self::GOLD);
        $or   = self::c($im, self::ORANGE);
        $white = self::c($im, [255, 255, 255]);
        imagefilledpolygon($im, [0, $yl, $W, $yr, $W, $yr + self::k(14), 0, $yl + self::k(14)], $navy);
        imagefilledpolygon($im, [0, $yl + self::k(14), $W, $yr + self::k(14), $W, $yr + self::k(22), 0, $yl + self::k(22)], $gold);
        imagefilledpolygon($im, [0, $yl + self::k(22), $W, $yr + self::k(22), $W, $yr + self::k(27), 0, $yl + self::k(27)], $or);
        imagefilledpolygon($im, [0, $yl + self::k(27), $W, $yr + self::k(27), $W, imagesy($im), 0, imagesy($im)], $white);
    }

    /** The logo emblem (with ™) and the wordmark, for a dark background. */
    private static function lockup(\GdImage $im, int $x, int $y, float $scale, bool $sub = true): int
    {
        $m  = DocArt::mark($im, $x, $y, self::k(118 * $scale), self::k(62 * $scale), false, 'assets/img/logo-mark-tmw.png');
        $tx = $x + (is_array($m) ? (int) $m['w'] : self::k(118 * $scale)) + self::k(10 * $scale);
        $white = self::c($im, [255, 255, 255]);
        $sz = 19.5 * $scale * self::SS;
        self::pt($im, $sz, $tx, $y + self::k(6 * $scale), $white, 'S HARI GLOBAL', 'Bold');
        self::pt($im, 8 * $scale * self::SS, $tx + self::pw($sz, 'S HARI GLOBAL', 'Bold') + self::k(3 * $scale), $y + self::k(3 * $scale), $white, 'TM', 'SemiBold');
        self::pt($im, 8.6 * $scale * self::SS, $tx + self::k(1), $y + self::k(36 * $scale), self::c($im, self::GOLD), 'PRIVATE  LIMITED', 'SemiBold', 2.2 * $scale * self::SS);
        if ($sub) {
            self::pt($im, 8 * $scale * self::SS, $tx + self::k(1), $y + self::k(53 * $scale), self::c($im, [218, 228, 255]), 'India – Nepal  ·  AC Sleeper Coach', 'Medium');
        }
        return $tx;
    }

    private static function statusPill(\GdImage $im, array $p, int $xr, int $y, float $size): void
    {
        $txt = $p['valid'] ? 'ACTIVE' : 'NOT VALID';
        $tw  = self::pw($size, $txt, 'Bold', $size * 0.12);
        $h   = (int) round($size * 2.3);
        $w   = $tw + (int) round($size * 2.4);
        self::roundRect($im, $xr - $w, $y, $xr, $y + $h, intdiv($h, 2), self::c($im, $p['valid'] ? [16, 163, 107] : [210, 42, 42]));
        self::pt($im, $size, $xr - $w + (int) round($size * 1.2), $y + (int) round(($h - $size * 1.05) / 2), self::c($im, [255, 255, 255]), $txt, 'Bold', $size * 0.12);
    }

    /* -------------------------------------------------------------- ID front */
    private static function front(array $p): \GdImage
    {
        $W = self::W;
        $H = self::H;
        $im = self::ss($W, $H);
        $Wk = self::k($W);
        $white = self::c($im, [255, 255, 255]);
        $navy  = self::c($im, self::NAVY);
        $ink   = self::c($im, self::INK);
        $muted = self::c($im, self::MUTED);

        // 1. The coach on the road (the website's hero photo), darkened on the left for the logo.
        $hh = self::k(272);
        if (!self::cover($im, 'assets/img/hd/bus-hero.webp', 0, 0, $Wk, $hh, 0.5, 0.42)) {
            self::gradient($im, 0, 0, $Wk, $hh, self::NAVY, self::NAVY2);
        }
        self::shade($im, 0, 0, self::k(460), $hh, self::NAVY, 18, 127, false);
        self::shade($im, 0, 0, $Wk, self::k(110), self::NAVY, 40, 127);
        self::lockup($im, self::k(22), self::k(20), 1.0);
        self::swoosh($im, $Wk, self::k(250), self::k(214));

        // 2. Card title.
        $kindEn = ['staff' => 'STAFF IDENTITY CARD', 'agent' => 'AGENT IDENTITY CARD', 'partner' => 'PARTNER IDENTITY CARD'][$p['kind']] ?? 'IDENTITY CARD';
        $kindNe = ['staff' => 'कर्मचारी परिचयपत्र', 'agent' => 'एजेन्ट परिचयपत्र', 'partner' => 'साझेदार परिचयपत्र'][$p['kind']] ?? 'परिचयपत्र';
        self::ptC($im, self::k(12.5), 0, $Wk, self::k(294), $navy, $kindEn, 'Bold', self::k(2.6));
        self::tC($im, self::k(11.5), 0, $Wk, self::k(316), self::c($im, self::ORANGE), $kindNe, true);

        // 3. Photo in a white frame with an orange ring, status on its corner.
        $pw = self::k(206); $ph = self::k(236);
        $px = intdiv($Wk - $pw, 2); $py = self::k(350);
        self::roundRect($im, $px - self::k(9), $py - self::k(9), $px + $pw + self::k(9), $py + $ph + self::k(9), self::k(26), self::c($im, [228, 234, 246]));
        self::roundRect($im, $px - self::k(6), $py - self::k(6), $px + $pw + self::k(6), $py + $ph + self::k(6), self::k(24), self::c($im, self::ORANGE));
        self::roundRect($im, $px - self::k(2), $py - self::k(2), $px + $pw + self::k(2), $py + $ph + self::k(2), self::k(20), $white);
        self::roundRect($im, $px, $py, $px + $pw, $py + $ph, self::k(18), self::c($im, [236, 241, 251]));
        $drawn = $p['photo'] !== null && self::rounded($im, (string) $p['photo'], $px, $py, $pw, $ph, self::k(18), $p['kind'] === 'partner');
        if (!$drawn) {
            self::ptC($im, self::k(64), $px, $px + $pw, $py + self::k(80), self::c($im, self::NAVY2), self::initials((string) $p['name']), 'Bold');
        }
        self::statusPill($im, $p, $px + $pw + self::k(14), $py - self::k(16), self::k(10));

        // 4. Name and role.
        $y = $py + $ph + self::k(22);
        $nameSize = self::k(23);
        $names = self::pwrap((string) $p['name'], $nameSize, $Wk - self::k(70), 2, 'Bold');
        if (count($names) > 1) {
            $nameSize = self::k(19);
            $names = self::pwrap((string) $p['name'], $nameSize, $Wk - self::k(60), 2, 'Bold');
        }
        foreach ($names as $line) {
            self::ptC($im, $nameSize, 0, $Wk, $y, $navy, $line, 'Bold');
            $y += (int) round($nameSize * 1.45);
        }
        $roleEn = strtoupper((string) $p['roleEn']);
        $gap = self::k(10);
        $wEn = self::pw(self::k(11.5), $roleEn, 'SemiBold', self::k(1.2));
        $wNe = self::w(self::k(11.5), (string) $p['roleNe']);
        $rx = intdiv($Wk - ($wEn + $gap + $wNe), 2);
        self::pt($im, self::k(11.5), $rx, $y + self::k(2), self::c($im, [196, 84, 0]), $roleEn, 'SemiBold', self::k(1.2));
        self::t($im, self::k(11.5), $rx + $wEn + $gap, $y - self::k(2), $muted, (string) $p['roleNe']);
        $y += self::k(34);

        // 5. The code chip — the number people quote.
        [$codeLabel, $code] = self::cardCode($p);
        $cx1 = self::k(40); $cx2 = $Wk - self::k(40);
        self::roundRect($im, $cx1, $y, $cx2, $y + self::k(56), self::k(14), $navy);
        imagefilledrectangle($im, $cx1 + self::k(14), $y, $cx1 + self::k(20), $y + self::k(56), self::c($im, self::GOLD));
        self::pt($im, self::k(8.5), $cx1 + self::k(34), $y + self::k(11), self::c($im, self::GOLD), $codeLabel, 'SemiBold', self::k(1.6));
        self::pt($im, self::k(19), $cx1 + self::k(33), $y + self::k(25), $white, self::pfit($code, self::k(19), self::k(300), 'Bold'), 'Bold');
        $cardNo = 'Card ' . (string) $p['ref'];
        self::pt($im, self::k(8.5), $cx2 - self::k(16) - self::pw(self::k(8.5), $cardNo, 'Medium'), $y + self::k(23), self::c($im, [200, 212, 245]), $cardNo, 'Medium');
        $y += self::k(72);

        // 6. Details on the left, the verification QR on the right.
        $y += self::k(6);
        $qrBox = self::k(146);
        $qx = $Wk - self::k(40) - $qrBox;
        $qy = $y;
        $rows = [];
        if ((string) $p['contact'] !== '') {
            $rows[] = ['CONTACT', (string) $p['contact']];
        }
        $rows[] = ['MOBILE', (string) $p['phone']];
        $place = (string) (($p['route'] ?? '') ?: $p['place']);
        if ($place !== '') {
            $rows[] = [$p['kind'] === 'staff' ? 'OFFICE / ROUTE' : 'AREA', $place];
        }
        $rows[] = ['VALID TILL', self::date((string) ($p['expires'] ?? ''))];
        $ly = $y;
        $maxW = $qx - self::k(40) - self::k(18);
        foreach (array_slice($rows, 0, 3) as [$k, $v]) {
            self::pt($im, self::k(8.5), self::k(40), $ly, $muted, $k, 'SemiBold', self::k(1.3));
            self::pt($im, self::k(14), self::k(40), $ly + self::k(16), $ink, self::pfit($v, self::k(14), $maxW, 'SemiBold'), 'SemiBold');
            $ly += self::k(52);
        }
        self::roundRect($im, $qx - self::k(8), $qy - self::k(8), $qx + $qrBox + self::k(8), $qy + $qrBox + self::k(8), self::k(12), self::c($im, [240, 244, 252]));
        GoldSheet::qr($im, self::verifyUrl((string) $p['ref']), $qx, $qy, $qrBox);
        $side = GoldSheet::qrSide(self::verifyUrl((string) $p['ref']), $qrBox);
        self::ptC($im, self::k(8), $qx - self::k(10), $qx + $side + self::k(10), $qy + $side + self::k(12), $navy, 'SCAN TO VERIFY', 'Bold', self::k(1));

        // 7. The website, big, on the livery footer.
        $fy = self::k($H - 62);
        self::gradient($im, 0, $fy, $Wk, self::k($H), self::NAVY2, self::NAVY);
        imagefilledrectangle($im, 0, $fy, $Wk, $fy + self::k(4), self::c($im, self::GOLD));
        $co = Settings::company();
        self::ptC($im, self::k(16), 0, $Wk, $fy + self::k(14), $white, self::web(), 'Bold');
        self::ptC($im, self::k(8.5), 0, $Wk, $fy + self::k(40), self::c($im, [214, 224, 255]), 'Helpline ' . (string) $co['phone'], 'Medium');
        return self::down($im, $W, $H);
    }

    /* --------------------------------------------------------------- ID back */
    private static function back(array $p): \GdImage
    {
        $W = self::W;
        $H = self::H;
        $im = self::ss($W, $H);
        $Wk = self::k($W);
        $Hk = self::k($H);
        $white = self::c($im, [255, 255, 255]);
        $navy  = self::c($im, self::NAVY);
        $ink   = self::c($im, self::INK);
        $muted = self::c($im, self::MUTED);
        $co    = Settings::company();

        // Header band with the lockup.
        self::gradient($im, 0, 0, $Wk, self::k(122), self::NAVY, self::NAVY2);
        self::lockup($im, self::k(22), self::k(24), 0.95, false);
        self::pt($im, self::k(8.5), self::k(156), self::k(80), self::c($im, [218, 228, 255]), 'एस हरि ग्लोबल प्राइभेट लिमिटेड', 'Medium');
        imagefilledrectangle($im, 0, self::k(122), $Wk, self::k(126), self::c($im, self::GOLD));
        imagefilledrectangle($im, 0, self::k(126), $Wk, self::k(129), self::c($im, self::ORANGE));

        $rules = [
            ['This card belongs to S Hari Global Pvt. Ltd. Carry it while on duty and show it when asked.',
             'यो परिचयपत्र कम्पनीको सम्पत्ति हो। काम गर्दा साथमा राख्नुहोस्।'],
            ['It is valid only while the verification QR says "Active". Scan it to check standing.',
             'अगाडिको प्रमाणीकरण QR ले "सक्रिय" देखाएसम्म मात्र मान्य।'],
            ['Never lend it. If found, please return it to any S Hari Global office or call the helpline.',
             'अरूलाई नदिनुहोस्। भेटिएमा नजिकको कार्यालयमा फिर्ता गर्नुहोस्।'],
        ];
        $y = self::k(150);
        foreach ($rules as $i => [$en, $ne]) {
            imagefilledellipse($im, self::k(46), $y + self::k(9), self::k(22), self::k(22), self::c($im, self::ORANGE));
            self::ptC($im, self::k(9.5), self::k(35), self::k(57), $y + self::k(4), $white, (string) ($i + 1), 'Bold');
            foreach (self::pwrap($en, self::k(10.5), $Wk - self::k(110), 2, 'Medium') as $line) {
                self::pt($im, self::k(10.5), self::k(68), $y, $ink, $line, 'Medium');
                $y += self::k(19);
            }
            // One line each (they fit); fit() measures once, wrap() would measure every word.
            self::t($im, self::k(10.5), self::k(68), $y - self::k(2), $muted, self::fit($ne, self::k(10.5), $Wk - self::k(110)));
            $y += self::k(21);
            $y += self::k(10);
        }

        // Signatures.
        $y += self::k(8);
        $bw = intdiv($Wk - self::k(34 * 2 + 26), 2);
        $lx = self::k(34);
        $rx = $lx + $bw + self::k(26);
        $soft = self::c($im, [244, 247, 253]);
        $line = self::c($im, self::LINE);
        self::roundRect($im, $lx, $y, $lx + $bw, $y + self::k(130), self::k(14), $soft);
        self::roundRect($im, $rx, $y, $rx + $bw, $y + self::k(130), self::k(14), $soft);
        if ($p['sig'] !== null) {
            self::picture($im, (string) $p['sig'], $lx + self::k(14), $y + self::k(8), $bw - self::k(28), self::k(84), true);
        }
        imageline($im, $lx + self::k(14), $y + self::k(98), $lx + $bw - self::k(14), $y + self::k(98), $line);
        self::ptC($im, self::k(9), $lx, $lx + $bw, $y + self::k(106), $muted, $p['kind'] === 'partner' ? 'Partner signature' : 'Holder signature', 'Medium');
        GoldSheet::seal($im, $rx + intdiv($bw, 2), $y + self::k(50), self::k(78));
        imageline($im, $rx + self::k(14), $y + self::k(98), $rx + $bw - self::k(14), $y + self::k(98), $line);
        self::ptC($im, self::k(9), $rx, $rx + $bw, $y + self::k(106), $muted, 'Authorised signatory', 'Medium');
        $y += self::k(146);

        foreach (self::pwrap('Head office: ' . (string) $co['address'], self::k(9.5), $Wk - self::k(80), 2, 'SemiBold') as $l) {
            self::ptC($im, self::k(9.5), 0, $Wk, $y, $ink, $l, 'SemiBold');
            $y += self::k(17);
        }
        self::ptC($im, self::k(8.5), 0, $Wk, $y + self::k(2), $muted, 'CIN ' . (string) $co['cin'] . '   ·   ' . (string) $co['email'], 'Regular');

        // Card number + verification note (left) and the personal WhatsApp QR (right).
        $vy = $Hk - self::k(72 + 16 + 170);

        // The coach on its road, filling the space above the bottom box.
        $top  = $y + self::k(26);
        $road = $vy - self::k(40);
        if ($road - $top > self::k(90)) {
            imagefilledrectangle($im, self::k(34), $road, $Wk - self::k(34), $road + self::k(16), self::c($im, [52, 58, 72]));
            imagefilledrectangle($im, self::k(34), $road, $Wk - self::k(34), $road + self::k(2), self::c($im, self::GOLD));
            for ($x = self::k(50); $x < $Wk - self::k(60); $x += self::k(56)) {
                imagefilledrectangle($im, $x, $road + self::k(8), $x + self::k(28), $road + self::k(10), self::c($im, [226, 230, 238]));
            }
            self::fitArt($im, 'assets/img/hd/bus-cutout.webp', self::k(60), $top, $Wk - self::k(120), $road - $top + self::k(10), 'center');
            self::ptC($im, self::k(8.5), 0, $Wk, $road + self::k(20), self::c($im, self::NAVY2), 'SURAT  ·  AHMEDABAD  ·  RUPAIDIHA  ·  NEPALGUNJ', 'SemiBold', self::k(1.2));
        }
        self::roundRect($im, self::k(34), $vy, $Wk - self::k(34), $vy + self::k(170), self::k(16), self::c($im, [236, 242, 252]));
        $waUrl = self::waQrUrl($p);
        $wq = self::k(118);
        $wx = $Wk - self::k(34) - $wq - self::k(16);
        $wy = $vy + self::k(14);
        if ($waUrl !== '') {
            self::roundRect($im, $wx - self::k(6), $wy - self::k(6), $wx + $wq + self::k(6), $wy + $wq + self::k(6), self::k(10), $white);
            GoldSheet::qr($im, $waUrl, $wx, $wy, $wq);
            self::ptC($im, self::k(8), $wx - self::k(12), $wx + $wq + self::k(12), $wy + $wq + self::k(10), self::c($im, [18, 120, 60]), 'Personal WhatsApp', 'Bold');
            self::ptC($im, self::k(7), $wx - self::k(16), $wx + $wq + self::k(16), $wy + $wq + self::k(24), $muted, 'Does NOT verify ID', 'Medium');
        }
        $boxW = $waUrl !== '' ? $wx - self::k(34) - self::k(30) : $Wk - self::k(110);
        [$codeLabel, $code] = self::cardCode($p);
        self::pt($im, self::k(8), self::k(50), $vy + self::k(16), $muted, $codeLabel, 'SemiBold', self::k(1.4));
        self::pt($im, self::k(15), self::k(50), $vy + self::k(30), $navy, self::pfit($code, self::k(15), $boxW, 'Bold'), 'Bold');
        self::pt($im, self::k(9), self::k(50), $vy + self::k(60), $muted, self::pfit('Card ' . $p['ref'] . '  ·  issued ' . self::date((string) $p['issued']), self::k(9), $boxW, 'Medium'), 'Medium');
        self::pt($im, self::k(9), self::k(50), $vy + self::k(84), $muted, 'Check this card at', 'Medium');
        self::pt($im, self::k(10), self::k(50), $vy + self::k(100), $ink, self::pfit((string) preg_replace('~^https?://~', '', appUrl('verify-id.php')), self::k(10), $boxW, 'SemiBold'), 'SemiBold');
        self::t($im, self::k(9), self::k(50), $vy + self::k(122), self::c($im, [150, 70, 0]), self::fit('व्हाट्सएप QR व्यक्तिगत च्याटको लागि मात्र हो।', self::k(9), $boxW));

        // Helpline footer.
        $fy = $Hk - self::k(72);
        self::gradient($im, 0, $fy, $Wk, $Hk, self::NAVY2, self::NAVY);
        imagefilledrectangle($im, 0, $fy, $Wk, $fy + self::k(4), self::c($im, self::GOLD));
        self::ptC($im, self::k(8.5), 0, $Wk, $fy + self::k(14), self::c($im, self::GOLD), 'HELPLINE  ·  हेल्पलाइन', 'SemiBold', self::k(1.5));
        self::ptC($im, self::k(13.5), 0, $Wk, $fy + self::k(34), $white, (string) $co['phone'] . '   ·   ' . self::web(), 'Bold');
        return self::down($im, $W, $H);
    }

    public static function plusMonths(string $ymd, int $months): string
    {
        $t = strtotime($ymd);
        return $t ? date('Y-m-d', strtotime('+' . $months . ' months', $t)) : '';
    }

    private static function links(string $cvJson): array
    {
        $cv = json_decode($cvJson, true);
        $l = is_array($cv) && is_array($cv['links'] ?? null) ? $cv['links'] : [];
        $fb = trim((string) ($l['facebook'] ?? ''));
        return ['facebook' => preg_match('~^https://(www\.|m\.)?(facebook\.com|fb\.com|fb\.me)/[\w./?=&%-]{1,120}$~i', $fb) === 1 ? $fb : ''];
    }

    public const VW = 1050;
    public const VH = 602;

    public static function visitingPng(array $p, string $side = 'front'): string
    {
        if (!empty($p['studio'])) { return self::designPng($p,'visiting',$side,$p['studio']); }
        if ($side === 'sheet') {
            $gap = 40;
            $im = imagecreatetruecolor(self::VW, self::VH * 2 + $gap);
            imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
            $f = self::vFront($p);
            $b = self::vBack($p);
            imagecopy($im, $f, 0, 0, 0, 0, self::VW, self::VH);
            imagecopy($im, $b, 0, self::VH + $gap, 0, 0, self::VW, self::VH);
            imagedestroy($f);
            imagedestroy($b);
        } else {
            $im = $side === 'back' ? self::vBack($p) : self::vFront($p);
        }
        imageresolution($im,300,300);
        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------- visiting front */
    private static function vFront(array $p): \GdImage
    {
        $W = self::VW;
        $H = self::VH;
        $im = self::ss($W, $H);
        $Wk = self::k($W);
        $Hk = self::k($H);
        $white = self::c($im, [255, 255, 255]);
        $navy  = self::c($im, self::NAVY);
        $ink   = self::c($im, self::INK);
        $muted = self::c($im, self::MUTED);
        $co    = Settings::company();

        // Left: the coach (website photo) with the lockup above it and the website below.
        $lw = self::k(452);
        if (!self::cover($im, 'assets/img/hd/bus-hero.webp', 0, 0, $lw, $Hk, 0.6, 0.45)) {
            self::gradient($im, 0, 0, $lw, $Hk, self::NAVY, self::NAVY2);
        }
        self::shade($im, 0, 0, $lw, self::k(190), self::NAVY, 14, 127);
        self::shade($im, 0, $Hk - self::k(150), $lw, $Hk, self::NAVY, 127, 10);
        self::lockup($im, self::k(34), self::k(30), 1.05);
        self::pt($im, self::k(15.5), self::k(36), $Hk - self::k(104), $white, self::web(), 'Bold');
        self::pt($im, self::k(10.5), self::k(36), $Hk - self::k(76), self::c($im, [226, 234, 255]), 'Helpline ' . (string) $co['phone'], 'Medium');
        self::pt($im, self::k(8.5), self::k(36), $Hk - self::k(52), self::c($im, self::GOLD), 'Surat  ·  Ahmedabad  ·  Rupaidiha  ·  Nepalgunj', 'SemiBold');

        // The livery cut between photo and card face.
        $gold = self::c($im, self::GOLD);
        $or   = self::c($im, self::ORANGE);
        imagefilledpolygon($im, [$lw - self::k(30), 0, $lw + self::k(40), 0, $lw - self::k(10), $Hk, $lw - self::k(80), $Hk], $navy);
        imagefilledpolygon($im, [$lw + self::k(40), 0, $lw + self::k(52), 0, $lw + self::k(2), $Hk, $lw - self::k(10), $Hk], $gold);
        imagefilledpolygon($im, [$lw + self::k(52), 0, $lw + self::k(58), 0, $lw + self::k(8), $Hk, $lw + self::k(2), $Hk], $or);
        imagefilledpolygon($im, [$lw + self::k(58), 0, $Wk, 0, $Wk, $Hk, $lw + self::k(8), $Hk], $white);

        // Right: the person.
        $rx = self::k(528);
        $ps = self::k(128);
        $py = self::k(46);
        imagefilledellipse($im, $rx + intdiv($ps, 2), $py + intdiv($ps, 2), $ps + self::k(16), $ps + self::k(16), $or);
        imagefilledellipse($im, $rx + intdiv($ps, 2), $py + intdiv($ps, 2), $ps + self::k(6), $ps + self::k(6), $white);
        $drawn = $p['photo'] !== null && self::circle($im, (string) $p['photo'], $rx, $py, $ps, $p['kind'] === 'partner');
        if (!$drawn) {
            imagefilledellipse($im, $rx + intdiv($ps, 2), $py + intdiv($ps, 2), $ps, $ps, self::c($im, [236, 241, 251]));
            self::ptC($im, self::k(40), $rx, $rx + $ps, $py + self::k(40), self::c($im, self::NAVY2), self::initials((string) $p['name']), 'Bold');
        }
        $nx = $rx + $ps + self::k(24);
        $nw = $Wk - self::k(36) - $nx;
        $nameSize = self::k(22);
        $names = self::pwrap((string) $p['name'], $nameSize, $nw, 2, 'Bold');
        if (count($names) > 1) {
            $nameSize = self::k(18);
            $names = self::pwrap((string) $p['name'], $nameSize, $nw, 2, 'Bold');
        }
        $ny = $py + self::k(count($names) > 1 ? 4 : 16);
        foreach ($names as $line) {
            self::pt($im, $nameSize, $nx, $ny, $navy, $line, 'Bold');
            $ny += (int) round($nameSize * 1.42);
        }
        self::pt($im, self::k(10), $nx, $ny + self::k(2), self::c($im, [196, 84, 0]), strtoupper((string) $p['roleEn']), 'SemiBold', self::k(1.3));
        self::t($im, self::k(10.5), $nx, $ny + self::k(20), $muted, (string) $p['roleNe']);
        self::statusPill($im, $p, $Wk - self::k(36), self::k(22), self::k(8.5));

        // The code chip.
        [$codeLabel, $code] = self::cardCode($p);
        $cy = self::k(206);
        $cw = self::k(300);
        self::roundRect($im, $rx, $cy, $rx + $cw, $cy + self::k(50), self::k(12), $navy);
        imagefilledrectangle($im, $rx + self::k(12), $cy, $rx + self::k(17), $cy + self::k(50), $gold);
        self::pt($im, self::k(7.5), $rx + self::k(28), $cy + self::k(9), $gold, $codeLabel, 'SemiBold', self::k(1.4));
        self::pt($im, self::k(16.5), $rx + self::k(27), $cy + self::k(22), $white, self::pfit($code, self::k(16.5), $cw - self::k(40), 'Bold'), 'Bold');

        // Contact lines and the verification QR.
        $qr = self::k(124);
        $qx = $Wk - self::k(40) - $qr;
        $qy = $Hk - self::k(42) - $qr;
        $rows = [['MOBILE', (string) $p['phone']]];
        $place = (string) (($p['route'] ?? '') ?: $p['place']);
        if ($place !== '') {
            $rows[] = [$p['kind'] === 'staff' ? 'OFFICE / ROUTE' : 'AREA', $place];
        }
        if ((string) $p['contact'] !== '') {
            $rows[] = ['CONTACT', (string) $p['contact']];
        }
        if ((string) ($p['email'] ?? '') !== '') {
            $rows[] = ['E-MAIL', (string) $p['email']];
        }
        $rows[] = ['WEBSITE', self::web()];
        $dy = self::k(280);
        $maxW = $qx - self::k(24) - $rx;
        foreach (array_slice($rows, 0, 4) as [$k, $v]) {
            self::pt($im, self::k(7.5), $rx, $dy, $muted, $k, 'SemiBold', self::k(1.3));
            self::pt($im, self::k(12.5), $rx, $dy + self::k(13), $ink, self::pfit($v, self::k(12.5), $maxW, 'SemiBold'), 'SemiBold');
            $dy += self::k(46);
        }
        self::roundRect($im, $qx - self::k(8), $qy - self::k(8), $qx + $qr + self::k(8), $qy + $qr + self::k(8), self::k(12), self::c($im, [240, 244, 252]));
        GoldSheet::qr($im, self::verifyUrl((string) $p['ref']), $qx, $qy, $qr);
        $side = GoldSheet::qrSide(self::verifyUrl((string) $p['ref']), $qr);
        self::ptC($im, self::k(7.5), $qx - self::k(10), $qx + $side + self::k(10), $qy + $side + self::k(12), $navy, 'SCAN TO VERIFY', 'Bold', self::k(1));
        imagefilledrectangle($im, $lw, $Hk - self::k(7), $Wk, $Hk, $or);
        return self::down($im, $W, $H);
    }

    /* -------------------------------------------------------- visiting back */
    private static function vBack(array $p): \GdImage
    {
        $W = self::VW;
        $H = self::VH;
        $im = self::ss($W, $H);
        $Wk = self::k($W);
        $Hk = self::k($H);
        $white = self::c($im, [255, 255, 255]);
        $co    = Settings::company();

        self::gradient($im, 0, 0, $Wk, $Hk, self::NAVY, self::NAVY2);
        $soft = imagecolorallocatealpha($im, 255, 255, 255, 116);
        imagefilledellipse($im, $Wk - self::k(140), self::k(60), self::k(520), self::k(520), $soft);
        imagefilledellipse($im, self::k(60), $Hk - self::k(40), self::k(360), self::k(360), $soft);

        // The road and the coach (website cut-out) along the bottom.
        $road = self::k(486);
        imagefilledrectangle($im, 0, $road, $Wk, $Hk, self::c($im, [38, 44, 58]));
        imagefilledrectangle($im, 0, $road, $Wk, $road + self::k(5), self::c($im, self::GOLD));
        for ($x = self::k(20); $x < $Wk; $x += self::k(110)) {
            imagefilledrectangle($im, $x, $road + self::k(58), $x + self::k(60), $road + self::k(64), self::c($im, [230, 232, 238]));
        }
        self::fitArt($im, 'assets/img/hd/bus-cutout.webp', self::k(420), self::k(236), self::k(600), self::k(300), 'right');

        $waUrl = self::waQrUrl($p);
        self::lockup($im, self::k(48), self::k(44), 1.35, false);
        self::pt($im, self::k(11), self::k(50), self::k(140), self::c($im, [226, 234, 255]), 'एस हरि ग्लोबल प्राइभेट लिमिटेड', 'Medium');
        self::pt($im, self::k(13), self::k(50), self::k(186), self::c($im, self::GOLD), 'Daily AC sleeper coach  ·  Surat – Nepalgunj', 'SemiBold');
        self::pt($im, self::k(22), self::k(50), self::k(236), $white, self::web(), 'Bold');
        self::pt($im, self::k(12), self::k(50), self::k(282), self::c($im, [226, 234, 255]), (string) $co['phone'] . '   ·   ' . (string) $co['email'], 'Medium');
        [$codeLabel, $code] = self::cardCode($p);
        self::pt($im, self::k(10), self::k(50), self::k(330), self::c($im, self::GOLD), $codeLabel . '  ' . $code . '   ·   ' . (string) $p['name'], 'SemiBold');

        if ($waUrl !== '') {
            $q = self::k(130);
            $qx = $Wk - self::k(52) - $q;
            $qy = self::k(52);
            self::roundRect($im, $qx - self::k(14), $qy - self::k(14), $qx + $q + self::k(14), $qy + $q + self::k(58), self::k(16), $white);
            GoldSheet::qr($im, $waUrl, $qx, $qy, $q);
            self::ptC($im, self::k(8.5), $qx - self::k(10), $qx + $q + self::k(10), $qy + $q + self::k(10), self::c($im, [18, 120, 60]), 'Personal WhatsApp', 'Bold');
            self::ptC($im, self::k(7), $qx - self::k(14), $qx + $q + self::k(14), $qy + $q + self::k(28), self::c($im, self::MUTED), 'Chat only · does not verify ID', 'Medium');
        }
        return self::down($im, $W, $H);
    }

    private static function circle(\GdImage $im, string $bytes, int $x, int $y, int $d, bool $contain): bool
    {
        $bak = imagecreatetruecolor($d, $d);
        imagecopy($bak, $im, 0, 0, $x, $y, $d, $d);
        if (!self::picture($im, $bytes, $x, $y, $d, $d, $contain)) {
            imagedestroy($bak);
            return false;
        }
        $r = $d / 2;
        for ($i = 0; $i < $d; $i++) {
            for ($j = 0; $j < $d; $j++) {
                $dx = $i + 0.5 - $r;
                $dy = $j + 0.5 - $r;
                if ($dx * $dx + $dy * $dy > $r * $r) {
                    imagesetpixel($im, $x + $i, $y + $j, imagecolorat($bak, $i, $j));
                }
            }
        }
        imagedestroy($bak);
        return true;
    }

    public static function pdf(array $p, string $what = 'all'): string
    {
        require_once __DIR__ . '/pdf.php';
        $pdf = new Pdf();
        $mm = 72 / 25.4;
        $co = Settings::company();
        $first = true;
        $page = function (string $title, array $sides, float $wmm, float $hmm) use ($pdf, $mm, $p, $co, &$first): void {
            if (!$first) {
                $pdf->newPage();
            }
            $first = false;
            $pdf->rect(0, 0, $pdf->width(), 44, [10, 42, 143], true);
            $pdf->text(36, 18, 'S HARI GLOBAL PRIVATE LIMITED', 12, 'F2', [255, 255, 255]);
            $latin = trim((string) preg_replace('/[^\x20-\x7E]+/', '', (string) $p['name']));
            $pdf->text(36, 32, $title . '  -  ' . ($latin !== '' ? $latin . '  -  ' : '') . $p['ref'], 9, 'F1', [255, 215, 160]);
            $pdf->rect(0, 44, $pdf->width(), 4, [240, 122, 30], true);
            if (!empty($p['studio'])) { $sz=getimagesizefromstring($sides[0][1]); $wmm=$sz[0]/300*25.4; $hmm=$sz[1]/300*25.4; }
            $w = $wmm * $mm;
            $h = $hmm * $mm;
            $gap = 10 * $mm;
            $x = ($pdf->width() - ($w * 2 + $gap)) / 2;
            $y = 70;
            foreach ($sides as $i => [$label, $png]) {
                $cx = $x + $i * ($w + $gap);
                foreach ([[$cx, $y], [$cx + $w, $y], [$cx, $y + $h], [$cx + $w, $y + $h]] as [$mx, $my]) {
                    $pdf->line($mx - 14, $my, $mx - 4, $my, [120, 120, 120], 0.4);
                    $pdf->line($mx + 4, $my, $mx + 14, $my, [120, 120, 120], 0.4);
                    $pdf->line($mx, $my - 14, $mx, $my - 4, [120, 120, 120], 0.4);
                    $pdf->line($mx, $my + 4, $mx, $my + 14, [120, 120, 120], 0.4);
                }
                $pdf->imageFromString($png, $cx, $y, $w, $h);
                $pdf->textCenter($cx, $cx + $w, $y + $h + 16, $label . '  (' . $wmm . ' x ' . $hmm . ' mm)', 8, 'F1', [92, 107, 133]);
            }
            $py = $y + $h + 40;
            $k = min(($pdf->width() - 72) / $w, ($pdf->height() - 60 - $py) / $h, 2.2);
            $pdf->imageFromString($sides[0][1], ($pdf->width() - $w * $k) / 2, $py, $w * $k, $h * $k);
            $pdf->textCenter(0, $pdf->width(), $pdf->height() - 30, 'Cut on crop marks, or send to a card printer. QR opens '
                . preg_replace('~^https?://~', '', appUrl('verify-id.php')) . ' to check current standing.', 8, 'F1', [92, 107, 133]);
            $pdf->textCenter(0, $pdf->width(), $pdf->height() - 18, $co['legal'] . '  -  ' . $co['web'] . '  -  ' . $co['phone'], 8, 'F1', [92, 107, 133]);
        };
        if ($what === 'id' || $what === 'all') {
            $page('Identity card', [['Front', self::png($p, 'front')], ['Back', self::png($p, 'back')]], 54, 85.6);
        }
        if ($what === 'visiting' || $what === 'all') {
            $page('Visiting card', [['Front', self::visitingPng($p, 'front')], ['Back', self::visitingPng($p, 'back')]], 89, 51);
        }
        return $pdf->output();
    }

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
            $cy = max(0, (int) round(($sh - $ch) * 0.3));
            imagecopyresampled($im, $src, $x, $y, $cx, $cy, $w, $h, $cw, $ch);
        }
        imagedestroy($src);
        return true;
    }

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

    public static function designPng(array $p,string $doc,string $side,array $config): string {
        require_once __DIR__.'/carddesign.php'; $cfg=CardDesign::clean($config);
        $doc=$doc==='visiting'?'visiting':'id';$w=$doc==='id'?self::W:self::VW;$h=$doc==='id'?self::H:self::VH;
        if (($cfg['orient_'.$doc]==='landscape')!==($w>$h)) { [$w,$h]=[$h,$w]; }
        if ($side==='sheet') {
            $f=imagecreatefromstring(self::designPng($p,$doc,'front',$cfg));$b=imagecreatefromstring(self::designPng($p,$doc,'back',$cfg));
            $im=imagecreatetruecolor($w*2+40,$h);imagefill($im,0,0,imagecolorallocate($im,255,255,255));imagecopy($im,$f,0,0,0,0,$w,$h);imagecopy($im,$b,$w+40,0,0,0,$w,$h);imagedestroy($f);imagedestroy($b);
        } else {
            $im=imagecreatetruecolor($w,$h);
            $rgb=static fn(string $hex):array=>[hexdec(substr($hex,1,2)),hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2))];
            imagefill($im,0,0,self::c($im,$rgb($cfg['background'])));$accent=self::c($im,$rgb($cfg['accent']));
            imagefilledrectangle($im,0,0,$w,12,$accent);imagefilledrectangle($im,0,$h-12,$w,$h,$accent);
            $co=Settings::company();$text=['company'=>'S HARI GLOBAL','name'=>$p['name'],'role'=>$p['roleEn'],'code'=>$p['memberCode']??$p['ref'],'route'=>$p['route']??$p['place'],'phone'=>$p['public']?$p['phone']:'Contact the company office','contact'=>$co['phone'].' · '.$co['email'],'notice'=>'Official verification QR checks current status. WhatsApp QR opens personal chat only.','signature'=>'Holder signature'];
            foreach($cfg['fields'][$doc.'_'.($side==='back'?'back':'front')] as $field){
                if(!$field['visible']){continue;}$key=$field['key'];$x=(int)round($field['x']*$w);$y=(int)round($field['y']*$h);$fw=(int)round($field['w']*$w);$fh=(int)round($field['h']*$h);$color=self::c($im,$rgb($field['color']));$size=(float)$field['size'];
                if($key==='logo'){DocArt::mark($im,$x,$y,$fw,$fh,true);}
                elseif($key==='photo'){if(!empty($p['photo'])){self::picture($im,$p['photo'],$x,$y,$fw,$fh,false);}}
                elseif($key==='signature'){if(!empty($p['sig'])){self::picture($im,$p['sig'],$x,$y,$fw,$fh,true);}}
                elseif(in_array($key,['verify','whatsapp'],true)){
                    $payload=$key==='verify'?self::verifyUrl($p['ref']):self::waQrUrl($p);if($payload===''){continue;}
                    $max=min($fw,$fh-38);if($max<80){continue;}$actual=GoldSheet::qrSide($payload,$max);imagefilledrectangle($im,$x-8,$y-8,$x+$actual+8,$y+$actual+8,imagecolorallocate($im,255,255,255));GoldSheet::qr($im,$payload,$x,$y,$max);
                    self::t($im,9,$x,$y+$actual+8,$color,$key==='verify'?'Verify identity':'Personal WhatsApp',true);if($key==='whatsapp'){self::t($im,8,$x,$y+$actual+24,$color,'Does not verify identity');}
                }else{
                    $value=$key==='text'?$field['text']:($text[$key]??'');
                    foreach(self::wrap((string)$value,$size,$fw,max(1,(int)floor($fh/($size*1.6)))) as $line){self::t($im,$size,$x,$y,$color,$line,in_array($key,['name','company','code'],true));$y+=(int)ceil($size*1.6);}
                }
            }
        }
        if($cfg['bw']){imagefilter($im,IMG_FILTER_GRAYSCALE);}imageresolution($im,300,300);ob_start();imagepng($im,null,6);$out=(string)ob_get_clean();imagedestroy($im);return $out;
    }
}
