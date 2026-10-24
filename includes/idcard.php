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
 *   Back   the rules of the card, the holder\'s own signature from the
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
        // 23 Oct 2026 (digital identity): what the public card / verify page may show - only with the
        // office's public fields and the person on the website; the links the person typed in their CV
        $pub   = (int) ($r['show_on_website'] ?? 0) === 1 ? array_flip(StaffApps::publicFields()) : [];
        $links = self::links((string) ($r['cv_json'] ?? ''));
        $issued = substr((string) ($r['approved_at'] ?: $r['created_at']), 0, 10);
        return [
            'kind'       => 'staff',
            'expires'    => self::plusMonths($issued, 12),
            'public'     => isset($pub['phone']),
            'waDigits'   => isset($pub['phone']) ? $cc . $r['phone'] : '',
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
        // 23 Oct 2026 (digital identity): contact on the public card only when the person agreed (public_ok)
        // and the card is on the website (web_live); the full number with its country like the directory
        $pub = (int) ($r['public_ok'] ?? 0) === 1 && (int) ($r['web_live'] ?? 1) === 1 && $active;
        $digits = preg_replace('/\D/', '', (string) $r['phone']) ?? '';
        if (strlen($digits) === 10) {
            $digits = (countryDialCode(resolvePhoneCountry('', $raw)) ?: '91') . $digits;
        }
        $links = self::links((string) ($r['cv_json'] ?? ''));
        $issued = substr((string) ($r['reviewed_at'] ?: $r['created_at']), 0, 10);
        $end = trim((string) ($r['agreement_end'] ?? ''));
        // 23 Oct 2026 review: a card past its "valid till" date is not valid, whatever the account says
        if ($active && $end !== '' && $end !== '0000-00-00' && substr($end, 0, 10) < date('Y-m-d')) {
            $active = false;
        }
        return [
            'kind'       => $org ? 'partner' : 'agent',
            'expires'    => $end !== '' && $end !== '0000-00-00' ? substr($end, 0, 10) : self::plusMonths($issued, AgentApplications::TERM_MONTHS),
            'public'     => $pub,
            'waDigits'   => $pub ? $digits : '',
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
            'issued'     => substr((string) ($r['reviewed_at'] ?: $r['created_at']), 0, 10),
            'valid'      => $active,
            'standing'   => $active ? ($org ? 'Active partner' : 'Active agent') : ($end !== '' && $end !== '0000-00-00' && substr($end, 0, 10) < date('Y-m-d') ? 'Agreement ended' : 'Account switched off'),
            'standingNe' => $active ? ($org ? 'सक्रिय साझेदार' : 'सक्रिय एजेन्ट') : ($end !== '' && $end !== '0000-00-00' && substr($end, 0, 10) < date('Y-m-d') ? 'सम्झौता सकियो' : 'खाता बन्द'),
            'contact'    => $org ? trim((string) ($f['p_rep'] ?? '')) : '',
        ];
    }

    /** Own-browser downloads immediately after applying. Pending cards never authorize company work. */
    public static function applicant(string $type, int $id): ?array
    {
        if ($id < 1) { return null; }
        $r = $type === 'staff' ? StaffApps::find($id) : AgentApplications::find($id);
        if ($r === null || (string)$r['status'] === 'rejected') { return null; }
        $ref = (string)(($r['ref'] ?? '') ?: ($type === 'staff' ? StaffApps::refFor($id) : AgentApplications::refFor($id)));
        $official = self::person($ref);
        if ($official !== null) {
            $official['memberCode'] = $ref;
            if ($type !== 'staff' && !empty($r['admin_id'])) {
                require_once __DIR__ . '/agentwallet.php';
                $official['memberCode'] = AgentWallet::agentCodeLabel((int)$r['admin_id']) ?: $ref;
            }
            return $official + ['pending' => false];
        }
        $staff = $type === 'staff'; $org = !$staff && (string)$r['type'] === 'org';
        $photo = $staff && !empty($r['photo_path']) && ($abs = StaffApps::absPath((string)$r['photo_path'])) !== null ? (string)file_get_contents($abs) : null;
        $sig = $staff && !empty($r['signature_path']) && ($abs = StaffApps::absPath((string)$r['signature_path'])) !== null ? (string)file_get_contents($abs) : null;
        if (!$staff) { $photo = self::fromDataUri((string)($org ? ($r['logo'] ?? '') : ($r['photo'] ?? ''))); $sig = self::fromDataUri((string)($r['signature'] ?? '')); }
        return [
            'kind' => $staff ? 'staff' : ($org ? 'partner' : 'agent'), 'ref' => $ref, 'memberCode' => $ref,
            'name' => (string)($staff ? $r['full_name'] : $r['name']),
            'roleEn' => $staff ? 'Staff applicant' : ($org ? 'Partner applicant' : 'Agent applicant'),
            'roleNe' => $staff ? 'कर्मचारी आवेदक' : ($org ? 'साझेदार आवेदक' : 'एजेन्ट आवेदक'),
            'place' => trim((string)($staff ? ($r['district'] ?? '') : ($r['area'] ?? ''))),
            'phone' => $staff ? '+' . (countryDialCode((string)$r['phone_country']) ?: '977') . ' ' . $r['phone'] : (string)(($r['phone_raw'] ?? '') ?: $r['phone']),
            'photo' => $photo, 'sig' => $sig, 'issued' => substr((string)$r['created_at'], 0, 10), 'expires' => '',
            'pending' => true, 'valid' => false, 'standing' => 'Pending office approval', 'standingNe' => 'कार्यालयको स्वीकृति बाँकी',
            'public' => false, 'waDigits' => '', 'email' => '', 'facebook' => '', 'contact' => '',
        ];
    }
    /** Only an exact, recent submission marker from this browser may open applicant cards. */
    public static function applicantSession(): ?array
    {
        $own = $_SESSION['a4_mine'] ?? null;
        if (!is_array($own) || !in_array($own['type'] ?? '', ['staff','agent'], true) || (int)($own['id'] ?? 0) < 1 || (int)($own['t'] ?? 0) <= time() - 3600 || (int)$own['t'] > time() + 60) { return null; }
        return ['type' => $own['type'], 'id' => (int)$own['id']];
    }
    /** Fixed downloaded Canva background; all personal values are drawn fresh from the saved record. */
    private static function cardCanvas(int $w, int $h, bool $logo = false): \GdImage
    {
        $im = imagecreatetruecolor($w, $h); imagealphablending($im, true);
        imagefill($im, 0, 0, self::c($im, [2, 24, 64]));
        $file = dirname(__DIR__) . '/assets/cards/canva-company-base.png';
        $src = is_file($file) ? @imagecreatefrompng($file) : false;
        if ($src !== false) {
            $sw = imagesx($src); $sh = imagesy($src); $sx = $logo ? 0 : (int)($sw * .5);
            imagecopyresampled($im, $src, 0, 0, $sx, 0, $w, $h, $sw - $sx, $sh); imagedestroy($src);
        }
        return $im;
    }
    private static function statusText(array $p): string
    {
        return !empty($p['pending']) ? 'PENDING OFFICE APPROVAL' : ($p['valid'] ? 'ACTIVE - SCAN TO VERIFY' : 'NOT ACTIVE - CHECK WITH OFFICE');
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
        $W = self::W; $H = self::H; $im = self::cardCanvas($W, $H);
        $white = self::c($im, [255,255,255]); $gold = self::c($im, [225,181,83]); $soft = self::c($im,[202,215,237]);
        DocArt::mark($im, 35, 42, 142, 105, true);
        self::t($im, 25, 194, 63, $white, 'S HARI GLOBAL', true);
        self::t($im, 13.5, 196, 105, $gold, 'PRIVATE LIMITED', true);
        self::t($im, 11, 196, 131, $soft, 'INDIA - NEPAL BUS SERVICE');
        imageline($im, 35, 176, $W-35, 176, $gold);
        $title = strtoupper($p['kind']) . (!empty($p['pending']) ? ' APPLICATION CARD' : ' IDENTITY CARD');
        self::tC($im, 16, 0, $W, 198, $gold, $title, true);
        self::roundRect($im, 168, 247, 470, 543, 26, $gold);
        self::roundRect($im, 175, 254, 463, 536, 22, $white);
        $drawn = $p['photo'] !== null && self::rounded($im, (string)$p['photo'], 181, 260, 276, 270, 18, $p['kind']==='partner');
        if (!$drawn) { self::roundRect($im,181,260,457,530,18,self::c($im,[229,237,249])); self::tC($im,68,181,457,344,self::c($im,[10,42,90]),self::initials((string)$p['name']),true); }
        $names = self::wrap((string)$p['name'], 28, $W-72, 2);
        foreach ($names as $i=>$name) self::tC($im,28,0,$W,565+$i*41,$white,$name,true);
        self::tC($im,15,0,$W,651,$soft,self::fit((string)$p['roleEn'].' · '.$p['roleNe'],15,$W-60));
        self::roundRect($im,85,687,$W-85,733,14,$gold);
        $code = (string)($p['memberCode'] ?? $p['ref']);
        self::tC($im,17,85,$W-85,699,self::c($im,[3,25,57]),strtoupper($p['kind']).' CODE  '.$code,true);
        self::t($im,11.5,40,766,$gold,'MOBILE'); self::t($im,16,40,787,$white,self::fit((string)$p['phone'],16,342),true);
        self::t($im,11.5,40,827,$gold,'OFFICE / AREA'); self::t($im,14,40,848,$white,self::fit((string)($p['place'] ?: 'S Hari Global'),14,342));
        self::t($im,11,40,883,$soft,'Valid till: '.((string)($p['expires'] ?? '') !== '' ? self::date((string)$p['expires']) : 'After office approval'));
        self::t($im,10.5,40,913,$soft,'Ref. '.$p['ref'].' · '.$p['issued']);
        self::roundRect($im,424,759,602,937,12,$white);
        $payload=self::verifyUrl((string)$p['ref']);$qrSide=GoldSheet::qrSide($payload,156);GoldSheet::qr($im,$payload,424+intdiv(178-$qrSide,2),759+intdiv(178-$qrSide,2),156);
        $status = self::c($im,!empty($p['pending']) ? [247,190,75] : ($p['valid'] ? [83,216,172] : [255,137,137]));
        imagefilledrectangle($im,0,952,$W,$H,$status);
        self::tC($im,12.5,0,$W,963,self::c($im,[3,25,57]),self::statusText($p),true);
        self::tC($im,9.5,0,$W,986,self::c($im,[3,25,57]),!empty($p['pending']) ? 'NOT VALID AS COMPANY AUTHORIZATION' : 'www.shreehariglobal.in');
        return $im;
    }

    private static function back(array $p): \GdImage
    {
        $W=self::W; $H=self::H; $im=imagecreatetruecolor($W,$H); $white=self::c($im,[255,255,255]);imagefill($im,0,0,$white);
        $ink=self::c($im,[8,30,65]);$muted=self::c($im,[81,101,128]);$gold=self::c($im,[225,181,83]);$co=Settings::company();
        imagefilledrectangle($im,0,0,$W,142,$ink); self::tC($im,22,0,$W,36,$white,'S HARI GLOBAL',true);self::tC($im,13,0,$W,78,$gold,'CARD INFORMATION · परिचयपत्र विवरण');
        self::t($im,14,38,174,$ink,'CODE  '.(string)($p['memberCode'] ?? $p['ref']),true);
        $rules=[
          !empty($p['pending']) ? 'Application received. Office approval is still pending. This card does not authorize work or collections for the company.' : 'This card is valid only while the online QR status shows active. Check the QR before dealing with the holder.',
          'The QR shows the current company status. It never shows the holder\'s government ID, bank details or date of birth.',
          'This card is personal. If lost, contact the S Hari Global office. It is not a ticket, payment receipt or replacement for an agreement.',
        ];
        $y=222;
        foreach($rules as $i=>$text) { self::t($im,15,38,$y,$gold,(string)($i+1).'.',true);foreach(self::wrap($text,14,$W-116,5) as $line) { self::t($im,14,73,$y,$ink,$line);$y+=27; }$y+=19; }
        foreach(self::wrap('It must open only ' . preg_replace('~^https?://~','',appUrl('verify-id.php')),11.5,$W-76,2) as $line) {self::t($im,11.5,38,$y,$ink,$line);$y+=23;}
        foreach(self::wrap('No QR reader? Call the office on ' . $co['phone'] . ' with the card No.',11.5,$W-76,2) as $line) {self::t($im,11.5,38,$y,$muted,$line);$y+=23;}
        self::t($im,12,38,$y+8,$muted,'Holder signature · आवेदकको सही');
        if($p['sig']!==null) { self::picture($im,(string)$p['sig'],38,$y+36,260,100,true); } else { self::t($im,12,38,$y+48,$muted,'Not provided'); }
        $y=max($y+160,728); self::t($im,13,38,$y,$ink,self::fit((string)$co['legal'],13,$W-76),true);
        foreach(self::wrap((string)$co['address'],12,$W-76,3) as $line) { $y+=25;self::t($im,12,38,$y,$muted,$line); }
        self::t($im,13,38,$y+38,$ink,(string)$co['phone'],true);self::t($im,12,38,$y+65,$muted,self::fit((string)$co['email'],12,$W-76));
        imagefilledrectangle($im,0,$H-54,$W,$H,$ink);self::tC($im,13,0,$W,$H-38,$white,'www.shreehariglobal.in',true);
        return $im;
    }

    /* =================================================================
     *  23 Oct 2026 (digital identity): expiry, public links, the visiting
     *  card and the print-ready PDF
     * ================================================================= */

    /** YYYY-MM-DD plus $months, '' when the date is unknown. */
    public static function plusMonths(string $ymd, int $months): string
    {
        $t = strtotime($ymd);
        return $t ? date('Y-m-d', strtotime('+' . $months . ' months', $t)) : '';
    }

    /** The links a person typed in their CV (team portal): facebook only for now. */
    private static function links(string $cvJson): array
    {
        $cv = json_decode($cvJson, true);
        $l = is_array($cv) && is_array($cv['links'] ?? null) ? $cv['links'] : [];
        $fb = trim((string) ($l['facebook'] ?? ''));
        return ['facebook' => preg_match('~^https://(www\.|m\.)?(facebook\.com|fb\.com|fb\.me)/[\w./?=&%-]{1,120}$~i', $fb) === 1 ? $fb : ''];
    }

    /** The WhatsApp link with a professional prefilled line, or '' when the number is not public. */
    public static function waLink(array $p): string
    {
        $d = (string) ($p['waDigits'] ?? '');
        if (strlen($d) < 11) {
            return '';
        }
        $co = Settings::company();
        $txt = 'Namaste ' . $p['name'] . ' ji, I found your ' . $co['name'] . ' ' . $p['roleEn'] . ' card (' . $p['ref'] . ') on '
             . preg_replace('~^(https?://)?(www\.)?~', '', (string) $co['web']) . '. I would like to enquire about bus tickets.';
        return 'https://wa.me/' . $d . '?text=' . rawurlencode($txt);
    }

    public const VW = 1050;   // 89 x 51 mm at 300 dpi
    public const VH = 602;

    /** PNG bytes of the visiting card: 'front' | 'back' | 'sheet' (both). */
    public static function visitingPng(array $p, string $side = 'front'): string
    {
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
        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    private static function vFront(array $p): \GdImage
    {
        $W=self::VW;$H=self::VH;$im=self::cardCanvas($W,$H); DocArt::mark($im,60,52,240,145,true);
        $white=self::c($im,[255,255,255]);$gold=self::c($im,[225,181,83]);$soft=self::c($im,[202,215,237]);$ink=self::c($im,[3,25,57]);$co=Settings::company();
        self::t($im,34,330,64,$white,'S HARI GLOBAL',true);self::t($im,17,334,118,$gold,'P R I V A T E   L I M I T E D');self::t($im,14,334,151,$soft,'INDIA - NEPAL TRAVEL & BUS SERVICE');
        imageline($im,55,214,$W-55,214,$gold);
        $names=self::wrap((string)$p['name'],28,675,2);$y=249;
        foreach($names as $name) {self::t($im,28,55,$y,$white,$name,true);$y+=42;}
        self::t($im,15,57,$y+2,$gold,self::fit((string)$p['roleEn'],15,680),true);
        $y+=38;self::roundRect($im,55,$y,730,$y+34,9,self::c($im,[21,49,86]));self::t($im,13.5,68,$y+8,$gold,strtoupper($p['kind']).' CODE  '.(string)($p['memberCode']??$p['ref']),true);
        $y+=58;self::t($im,16,55,$y,$white,self::fit((string)$p['phone'],16,680),true);
        self::t($im,13,55,$y+34,$soft,self::fit((string)$co['email'],13,680));self::t($im,13,55,$y+65,$soft,'www.shreehariglobal.in');
        self::t($im,11.5,55,$y+94,$soft,self::fit((string)$co['address'],11.5,680));
        self::roundRect($im,793,243,985,427,18,$gold);self::roundRect($im,799,249,979,421,15,$white);
        $drawn=$p['photo']!==null && self::rounded($im,(string)$p['photo'],805,255,168,160,12,$p['kind']==='partner');
        if(!$drawn) {self::roundRect($im,805,255,973,415,12,self::c($im,[230,237,248]));self::tC($im,40,805,973,308,$ink,self::initials((string)$p['name']),true);}
        self::roundRect($im,808,430,988,592,9,$white);$payload=self::verifyUrl((string)$p['ref']);$qrSide=GoldSheet::qrSide($payload,150);GoldSheet::qr($im,$payload,808+intdiv(180-$qrSide,2),430+intdiv(162-$qrSide,2),150);
        imagefilledrectangle($im,0,$H-27,780,$H,!empty($p['pending'])?$gold:($p['valid']?self::c($im,[83,216,172]):self::c($im,[255,137,137])));
        self::tC($im,10.5,0,780,$H-20,$ink,self::statusText($p),true);
        return $im;
    }

    private static function vBack(array $p): \GdImage
    {
        $W=self::VW;$H=self::VH;$im=self::cardCanvas($W,$H);$co=Settings::company();$white=self::c($im,[255,255,255]);$gold=self::c($im,[225,181,83]);$soft=self::c($im,[202,215,237]);
        DocArt::mark($im,65,60,200,140,true);self::t($im,34,310,76,$white,'S HARI GLOBAL',true);self::t($im,17,314,131,$gold,'PRIVATE LIMITED');self::t($im,16,67,260,$white,'INDIA - NEPAL BUS SERVICE',true);
        self::t($im,15,67,311,$soft,'Office  '.(string)$co['phone']);self::t($im,14,67,350,$soft,self::fit((string)$co['email'],14,690));
        $y=395;foreach(self::wrap((string)$co['address'],13,685,2) as $line){self::t($im,13,67,$y,$soft,$line);$y+=27;}
        self::t($im,16,67,493,$gold,'www.shreehariglobal.in',true);self::t($im,11.5,67,539,$soft,'Scan the QR to check the holder\'s current status.');
        self::roundRect($im,805,328,1007,530,14,$white);$payload=self::verifyUrl((string)$p['ref']);$qrSide=GoldSheet::qrSide($payload,196);GoldSheet::qr($im,$payload,805+intdiv(202-$qrSide,2),328+intdiv(202-$qrSide,2),196);
        return $im;
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

    /**
     * A print-ready A4 PDF: 'id' (the ID card), 'visiting' (the visiting card) or
     * 'all' (both) — every side at true size with crop marks, then a larger preview.
     */
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
            $pdf->rect(0, 0, $pdf->width(), 44, [3, 25, 57], true);
            $pdf->text(36, 18, 'S HARI GLOBAL PRIVATE LIMITED', 12, 'F2', [255, 255, 255]);
            // the standard PDF fonts are Latin only: a Devanagari name is left to the card images
            $latin = trim((string) preg_replace('/[^\x20-\x7E]+/', '', (string) $p['name']));
            $pdf->text(36, 32, $title . '  -  ' . ($latin !== '' ? $latin . '  -  ' : '') . $p['ref'], 9, 'F1', [255, 215, 160]);
            $pdf->rect(0, 44, $pdf->width(), 4, [225, 181, 83], true);
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
            $pdf->textCenter(0, $pdf->width(), $pdf->height() - 30, 'Cut on the crop marks, or give this PDF to a card printer. The QR opens '
                . preg_replace('~^https?://~', '', appUrl('verify-id.php')) . ' and shows the current standing.', 8, 'F1', [92, 107, 133]);
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
