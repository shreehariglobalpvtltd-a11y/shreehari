<?php
/**
 * =====================================================================
 *  includes/agentpro-form.php — the FILLED application form (30 Oct 2026).
 *
 *  Owner: "form bharepachi ID card, visiting card ani afnai bhareko
 *  digital form — agent / staff le ni download garna milos, admin le ni".
 *
 *  One renderer, three doors:
 *    /admin/team-form.php?app=<id>|?admin=<id>[&auto=pdf]   the office
 *    /me/form.php[?auto=pdf]                                 the member
 *    /join-form.php?t=<applicantToken>[&auto=pdf]            the applicant
 *
 *  It looks like the printed 2-page paper form (a third A4 sheet only when
 *  long answers do not fit - the page script moves them; company letterhead with
 *  the TM logo, CIN and address; photo; every field; documents; signature
 *  and thumb; verification; declaration; office-use box), but every line
 *  is filled from ap_applications, with ap_members / admins / admin_profiles
 *  after approval (agent code / staff no, verify QR, KYC status). A member
 *  without an application row (created before Agent Pro) gets the same
 *  form from their member record.
 *
 *  PRIVACY
 *    full = true   the person themself, or a super-admin / manager: the ID
 *                  number, PAN and bank account in full, ID-document and
 *                  thumbprint pictures.
 *    full = false  other office roles: ID / PAN / account masked, no
 *                  document or thumbprint pictures (the photo stays - it
 *                  is printed on the public ID card anyway).
 *  Every picture is embedded as a small re-encoded JPEG (data: URI), so
 *  nothing private gets a URL and html2canvas never meets a tainted image.
 *
 *  Download: Print (the browser), PDF (html2canvas 1.4.1 + jsPDF 2.5.1 from
 *  cdnjs, jsdelivr fallback, loaded on the first tap - same as
 *  admin/application-print.php), PNG (one file per page).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/agentpro.php';
require_once __DIR__ . '/agentpro-card.php';
if (!class_exists('Brand', false) && is_file(__DIR__ . '/brand.php')) {
    require_once __DIR__ . '/brand.php';
}

final class ApForm
{
    /** How long an applicant's own link (/join-form.php?t=) keeps working after sending. */
    public const APPLICANT_DAYS = 30;

    /** Role => [Nepali, English] for the "applying as" line. */
    private const ROLES = [
        'agent'      => ['एजेन्ट', 'Agent'],
        'counter'    => ['काउन्टर कर्मचारी', 'Counter staff'],
        'manager'    => ['व्यवस्थापक', 'Manager'],
        'accountant' => ['लेखापाल', 'Accountant'],
        'support'    => ['ग्राहक सहायता', 'Customer support'],
        'scanner'    => ['बोर्डिङ कर्मचारी', 'Boarding staff'],
        'official'   => ['अधिकारी', 'Official'],
        'superadmin' => ['निर्देशक', 'Director'],
        'operations' => ['सञ्चालन', 'Operations'],
    ];

    private const STATUS = [
        'draft'     => ['अधुरो', 'Draft — not sent yet', '#8A5A00'],
        'submitted' => ['पठाइएको', 'Sent — waiting for the office', '#1F3C88'],
        'approved'  => ['स्वीकृत', 'Approved', '#11703F'],
        'rejected'  => ['अस्वीकृत', 'Not approved', '#B3261E'],
    ];

    /* =================================================================
     *  Finding the row
     * ================================================================= */

    /**
     * The application that made this member: the approved one first, else
     * the newest sent one, else the newest of any status.
     *
     * @return array<string, mixed>|null
     */
    public static function applicationFor(int $adminId): ?array
    {
        if ($adminId <= 0 || !AgentPro::available()) {
            return null;
        }
        return Database::fetch(
            "SELECT * FROM ap_applications WHERE admin_id = :a
              ORDER BY (status = 'approved') DESC, (status = 'submitted') DESC, id DESC LIMIT 1",
            ['a' => $adminId]
        );
    }

    /**
     * May an applicant open their own form by draft key? Sent or approved,
     * and not more than APPLICANT_DAYS after it was sent.
     *
     * @param array<string, mixed>|null $app
     */
    public static function applicantMayOpen(?array $app): bool
    {
        if ($app === null || !in_array((string) ($app['status'] ?? ''), ['submitted', 'approved'], true)) {
            return false;
        }
        $sent = strtotime((string) ($app['submitted_at'] ?? ''));
        return $sent !== false && $sent > 0 && $sent >= time() - self::APPLICANT_DAYS * 86400;
    }

    /**
     * The applicant's own credential for /join-form.php: "<id>-<32 hex>",
     * an HMAC of the row id and its draft key under APP_KEY. It is NOT the
     * draft key (office pages print draft keys in their "Finish / edit"
     * links, and the form here shows everything unmasked), and no office
     * page prints it: only the public "submit" answer hands it out.
     *
     * @param array<string, mixed> $app ap_applications row
     */
    public static function applicantToken(array $app): string
    {
        $id = (int) ($app['id'] ?? 0);
        $key = (string) ($app['draft_key'] ?? '');
        if ($id <= 0 || preg_match('/^[a-f0-9]{32}$/', $key) !== 1) {
            return '';
        }
        return $id . '-' . substr(hash_hmac('sha256', 'apform|' . $id . '|' . $key, APP_KEY), 0, 32);
    }

    /**
     * /join-form.php?t=<token> for this application ('' when it has no draft key).
     *
     * @param array<string, mixed> $app
     */
    public static function applicantUrl(array $app): string
    {
        $t = self::applicantToken($app);
        return $t === '' ? '' : '/join-form.php?t=' . $t;
    }

    /**
     * The application an applicant token opens, or null (bad shape, no
     * such row, or the HMAC does not match - compared in constant time).
     *
     * @return array<string, mixed>|null
     */
    public static function byApplicantToken(string $token): ?array
    {
        if (preg_match('/^([1-9][0-9]{0,9})-[a-f0-9]{32}$/', $token, $mt) !== 1) {
            return null;
        }
        $app = AgentPro::application((int) $mt[1]);
        if ($app === null) {
            return null;
        }
        $want = self::applicantToken($app);
        return $want !== '' && hash_equals($want, $token) ? $app : null;
    }

    /** Headers for a page that carries personal data: never cached, never indexed, no referrer. */
    public static function noStore(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, private, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
    }

    /* =================================================================
     *  The page
     * ================================================================= */

    /**
     * The whole HTML page: two A4 sheets + a toolbar.
     *
     * @param array<string, mixed>|null $app    ap_applications row (null = member only)
     * @param array<string, mixed>|null $member AgentPro::member() (null = not approved yet)
     * @param array{full?: bool, mode?: string, auto?: string, back?: string, backLabel?: string} $opt
     */
    public static function render(?array $app, ?array $member, array $opt = []): string
    {
        $vm = self::model($app, $member, (bool) ($opt['full'] ?? false));
        return self::html($vm, $opt);
    }

    /**
     * Everything the two sheets print, already formatted (not yet escaped).
     *
     * @param array<string, mixed>|null $app
     * @param array<string, mixed>|null $m
     * @return array<string, mixed>
     */
    public static function model(?array $app, ?array $m, bool $full): array
    {
        $fromMember = $app === null;
        $a = $app ?? self::appFromMember($m ?? []);
        $g = static fn(string $k): string => trim((string) ($a[$k] ?? ''));
        $status = $g('status') !== '' ? $g('status') : 'approved';
        $approved = $status === 'approved';
        $isStaff = $g('kind') === 'staff';
        $isOrg = !$isStaff && $g('agent_kind') === 'org';
        $mm = $m ?? [];
        $adminId = (int) ($a['admin_id'] ?? ($mm['id'] ?? 0));
        // the member record counts only once the office approved (an inactive login is not a member yet)
        $live = $m !== null && ($approved || $fromMember);
        $mv = static fn(string $k): string => $live ? trim((string) ($mm[$k] ?? '')) : '';

        $co = Settings::company();
        $co = array_map(static fn($v) => is_string($v) ? AgentPro::fixMojibake($v) : $v, $co);
        $country = $g('country') !== '' ? $g('country') : ($live ? (string) ($mm['country'] ?? 'IN') : 'IN');

        /* ---- who ---- */
        $role = $g('role_wanted') !== '' ? $g('role_wanted') : ($isStaff ? (string) ($mm['role'] ?? 'counter') : 'agent');
        $roleNe = self::ROLES[$role] ?? [ucfirst($role), ucfirst($role)];
        $applyingAs = $isStaff
            ? $roleNe[0] . ' · ' . $roleNe[1]
            : ($isOrg ? 'एजेन्सी / फर्म · Agency (firm)' : 'एजेन्ट (व्यक्ति) · Agent (person)');
        $designation = $mv('designation') !== '' ? $mv('designation') : $g('designation');

        /* ---- identity numbers ---- */
        $idType = $g('id_type');
        $idLabel = AgentPro::ID_TYPES[$idType] ?? ($idType !== '' ? $idType : '');
        $idFull = $full ? AgentPro::decrypt($a['id_number_enc'] ?? null) : '';
        // a member made before Agent Pro: admin_profiles.id_number may hold the FULL typed number
        // (admin/agents.php, admin/agent.php), so it is masked below unless this viewer may see it all
        $idRaw = $fromMember ? $g('id_number_raw') : '';
        if ($idFull === '' && $full && $idRaw !== '') {
            $idFull = $idRaw;
        }
        if ($idFull !== '' && $idType === 'aadhaar') {
            $d = preg_replace('/\D/', '', $idFull) ?? '';
            $idFull = strlen($d) === 12 ? trim(chunk_split($d, 4, ' ')) : $idFull;
        }
        $idShown = $idFull !== '' ? $idFull : $g('id_number_masked');
        if (!$full && $idShown !== '') {
            // always through maskId when masked (an already-masked value stays as it is)
            $idShown = AgentPro::maskId($idType, $idShown);
        }
        $pan = strtoupper($g('pan'));
        $panShown = $pan === '' ? '' : ($full ? $pan : AgentPro::maskId('pan', $pan));

        /* ---- bank ---- */
        $acct = $full ? AgentPro::decrypt($a['bank_account_enc'] ?? null) : '';
        $last4 = $g('bank_account_last4');
        if ($acct === '' && $last4 === '' && $live) {
            $last4 = (string) ($mm['bank']['last4'] ?? '');
            if ($full && $last4 !== '' && $adminId > 0) {
                $acct = AgentPro::bankAccount($adminId);
            }
        }
        $acctShown = $acct !== '' ? $acct : ($last4 !== '' ? '•••• •••• ' . $last4 : '');
        $bank = static function (string $appCol, string $memKey) use ($g, $live, $mm): string {
            $v = $g($appCol);
            return $v !== '' ? $v : ($live ? trim((string) ($mm['bank'][$memKey] ?? '')) : '');
        };

        /* ---- dates ---- */
        $dob = $g('dob') !== '' ? $g('dob') : $mv('dob');
        $dobShown = '';
        if ($dob !== '' && ($ts = strtotime($dob)) !== false) {
            $age = (int) floor((time() - $ts) / (365.2425 * 86400));
            $dobShown = date('d/m/Y', $ts) . ($age > 0 && $age < 120 ? '  (' . $age . ' वर्ष · yrs)' : '');
        }
        $fmt = static fn(string $v, string $f = 'd M Y'): string => $v !== '' && ($t = strtotime($v)) !== false && $t > 0 ? date($f, $t) : '';
        $sentAt = $g('submitted_at') !== '' ? $g('submitted_at') : ($fromMember ? (string) ($mm['joined'] ?? '') : '');

        /* ---- names of office people ---- */
        $who = static function (int $id): string {
            if ($id <= 0) {
                return '';
            }
            $r = Database::fetch('SELECT full_name, username FROM admins WHERE id = :i', ['i' => $id]);
            return $r === null ? '#' . $id : (trim((string) $r['full_name']) !== '' ? trim((string) $r['full_name']) : (string) $r['username']);
        };
        $decidedBy = $who((int) ($a['decided_by'] ?? 0));
        $officeBy  = $who((int) ($a['office_filled_by'] ?? 0));

        /* ---- WhatsApp proof ---- */
        $via = $g('wa_verified_via');
        $waAt = $g('wa_verified_at');
        $waLine = match ($via) {
            'otp'     => 'WhatsApp OTP कोड · one-time code',
            'reverse' => 'WhatsApp बाट JOIN सन्देश · JOIN message from the number',
            'office'  => 'कार्यालयले प्रमाणित · verified by the office' . ($officeBy !== '' ? ' (' . $officeBy . ')' : ''),
            default   => $fromMember && $live ? 'सदस्य खाता · member account' : '',
        };
        if ($waLine !== '' && $waAt !== '') {
            $waLine .= ' — ' . $fmt($waAt, 'd M Y, H:i');
        }

        /* ---- what the readers found on the ID ---- */
        $readers = [];
        foreach (jsonColumn($a['readout'] ?? null) as $slot => $r) {
            if (is_array($r)) {
                foreach ((array) ($r['sources'] ?? []) as $src) {
                    if (is_string($src) && $src !== '') {
                        $readers[strtoupper($src)] = true;
                    }
                }
                if (isset($r['reader']) && is_string($r['reader'])) {
                    $readers[strtoupper($r['reader'])] = true;
                }
            }
        }

        /* ---- pictures ---- */
        $photo = '';
        $mPhoto = $live ? (string) ($mm['photo'] ?? '') : '';
        if ($mPhoto !== '' && str_starts_with($mPhoto, 'agents/') && !str_contains($mPhoto, '..')) {
            $photo = self::dataImg(UPLOAD_PATH . '/' . $mPhoto, 520);
        }
        if ($photo === '' && ($abs = AgentPro::privateAbs($g('selfie'))) !== null) {
            $photo = self::dataImg($abs, 520);
        }
        $thumb = '';
        if ($full && ($abs = AgentPro::privateAbs($g('thumb'))) !== null) {
            $thumb = self::dataImg($abs, 420, 88);
        }
        $docs = [];
        $doc = static function (string $label, string $rel) use (&$docs, $full): void {
            if ($rel === '') {
                return;
            }
            $abs = AgentPro::privateAbs($rel);
            $isPdf = $abs !== null && str_ends_with(strtolower($abs), '.pdf');
            $docs[] = [
                'label' => $label,
                'img'   => $full && $abs !== null && !$isPdf ? self::dataImg($abs, 460, 72) : '',
                'kind'  => $abs === null ? 'missing' : ($isPdf ? 'PDF' : 'image'),
            ];
        };
        $doc('परिचयपत्र — अगाडि · ID — front', $g('doc_front'));
        $doc('परिचयपत्र — पछाडि · ID — back', $g('doc_back'));
        foreach (jsonColumn($a['extras'] ?? null) as $x) {
            if (is_array($x)) {
                $doc(AgentPro::fixMojibake(trim((string) ($x['label'] ?? 'Document'))) ?: 'Document', (string) ($x['path'] ?? ''));
            }
        }
        if ($fromMember && $live) {
            if (!empty($mm['hasDoc1'])) { $docs[] = ['label' => 'KYC 1 · ID', 'img' => '', 'kind' => 'on file']; }
            if (!empty($mm['hasDoc2'])) { $docs[] = ['label' => 'KYC 2 · ID back / address', 'img' => '', 'kind' => 'on file']; }
        }

        /* ---- code + verify QR (only a real, approved member has them) ---- */
        $code = $live ? (string) ($mm['idNo'] ?? '') : '';
        $qr = '';
        $verifyUrl = '';
        if ($live && (string) ($mm['cardToken'] ?? '') !== '') {
            $verifyUrl = ApCard::verifyUrl($mm);
            try {
                $qr = QrCode::dataUri($verifyUrl, 6, 2);
            } catch (Throwable $e) {
                $qr = '';
            }
        }
        $passkeys = 0;
        if ($live && $adminId > 0) {
            try {
                $passkeys = (int) Database::scalar('SELECT COUNT(*) FROM ap_passkeys WHERE admin_id = :a', ['a' => $adminId], 0);
            } catch (Throwable $e) {
                $passkeys = 0;
            }
        }
        $kyc = $live ? (string) ($mm['kycStatus'] ?? '') : ($status === 'submitted' ? 'submitted' : '');

        /* ---- sections of page 1 ---- */
        // "Surat, Surat" when the city is also the district: each place once
        $join = static function (array $parts): string {
            $seen = [];
            foreach ($parts as $p) {
                $p = trim((string) $p);
                if ($p !== '' && !isset($seen[mb_strtolower($p)])) {
                    $seen[mb_strtolower($p)] = $p;
                }
            }
            return implode(', ', $seen);
        };
        $city = $join([$g('city'), $g('district')]);
        $addr = $join([$g('address'), $g('city') !== '' && !str_contains(mb_strtolower($g('address')), mb_strtolower($g('city'))) ? $g('city') : '',
            $g('district'), $g('state'), $g('pincode')]);
        if ($addr === '') {
            $addr = $mv('address');
        }
        $addr .= $addr !== '' ? ' — ' . ($country === 'NP' ? 'Nepal' : 'India') : '';
        $phone = $g('phone') !== '' ? $g('phone') : $mv('phone');
        $wa = $g('whatsapp');
        $tel = static function (string $raw) use ($country): string {
            if ($raw === '') {
                return '';
            }
            $intl = AgentPro::intlDigits($raw, $country === 'NP' ? '977' : '91');
            return $intl !== '' ? AgentPro::displayPhone($intl) : $raw;
        };

        $applicant = [
            ['पूरा नाम', 'Full name', $g('full_name') !== '' ? $g('full_name') : $mv('name'), 'w2 big'],
        ];
        if ($isOrg) {
            $applicant[] = ['एजेन्सी / फर्मको नाम', 'Agency / firm name', $g('org_name'), 'w2'];
        }
        $applicant = array_merge($applicant, [
            ['मोबाइल', 'Mobile', $tel($phone) . ($phone !== '' && $waAt !== '' ? '  ✓ WhatsApp' : ''), ''],
            ['इमेल', 'E-mail', $g('email') !== '' ? $g('email') : $mv('email'), ''],
            ['जन्म मिति', 'Date of birth', $dobShown, ''],
            ['लिङ्ग', 'Gender', self::gender($g('gender') !== '' ? $g('gender') : $mv('gender')), ''],
            ['बुबा / पति', 'Father / husband', $g('guardian') !== '' ? $g('guardian') : $mv('guardian'), ''],
            ['पेशा', 'Occupation', $g('occupation'), ''],
            ['काम गर्ने ठाउँ', 'Work area', $city !== '' ? $city : $mv('branch'), ''],
            ['दोस्रो WhatsApp', 'Other WhatsApp', $wa !== '' && $wa !== $phone ? $tel($wa) : '', ''],
            ['घरको ठेगाना', 'Home address', $addr, 'w2'],
        ]);
        $identity = [
            ['परिचयपत्रको प्रकार', 'ID type', $idLabel, ''],
            ['परिचयपत्र नम्बर', 'ID number', $idShown, 'mono'],
            ['म्याद', 'Valid till', $fmt($g('id_expires_on'), 'd/m/Y'), ''],
            ['PAN', 'PAN', $panShown, 'mono'],
        ];
        $education = [
            ['शिक्षा', 'Education', $g('education'), ''],
            ['अनुभव', 'Experience', $g('experience'), ''],
            ['सीप', 'Skills', $g('skills'), ''],
            ['भाषा', 'Languages', $g('languages'), ''],
        ];
        $bankName = $bank('bank_name', 'name');
        $branch = $bank('bank_branch', 'branch');
        $payment = [
            ['खातावालाको नाम', 'Account name', $bank('bank_holder', 'holder'), ''],
            ['बैंक · शाखा', 'Bank · branch', implode(' · ', array_filter([$bankName, $branch])), ''],
            ['खाता नम्बर', 'Account number', $acctShown, 'mono'],
            ['IFSC', 'IFSC', $bank('bank_ifsc', 'ifsc'), 'mono'],
            ['UPI', 'UPI ID', $bank('upi_id', 'upi'), ''],
            ['eSewa / Khalti', 'eSewa / Khalti', implode(' · ', array_filter([$bank('esewa_id', 'esewa') !== '' ? 'eSewa ' . $bank('esewa_id', 'esewa') : '', $bank('khalti_id', 'khalti') !== '' ? 'Khalti ' . $bank('khalti_id', 'khalti') : ''])), ''],
        ];
        $position = [
            ['आवेदन', 'Applying as', $applyingAs, ''],
            ['पद', 'Designation', $designation, ''],
            [$isStaff ? 'कर्मचारी नं.' : 'एजेन्ट कोड', $isStaff ? 'Staff no.' : 'Agent code', $code !== '' ? $code : ($approved ? '' : 'स्वीकृत भएपछि · after approval'), $code !== '' ? 'mono strong' : ''],
            ['सदस्य भएको', 'Member since', $live ? $fmt((string) ($mm['joined'] ?? '')) : '', ''],
        ];

        /* ---- page 2: verification, checklist, approval ---- */
        $hasPhoto = $photo !== '' || $g('selfie') !== '';
        $hasBoth = $g('doc_front') !== '' && ($g('doc_back') !== '' || in_array($idType, ['pan', 'passport'], true));
        $bankOk = $acctShown !== '' || $bank('upi_id', 'upi') !== '' || $bank('esewa_id', 'esewa') !== '' || $bank('khalti_id', 'khalti') !== '';
        $checklist = [
            ['पासपोर्ट साइज फोटो / लाइभ सेल्फी', 'Passport-size photo / live selfie', $hasPhoto],
            ['परिचयपत्र (दुवै तर्फ)', 'ID document (both sides)', $hasBoth],
            ['PAN (भए)', 'PAN (if any)', $pan !== '' || $idType === 'pan'],
            ['बैंक विवरण वा UPI', 'Bank details or UPI', $bankOk],
            ['WhatsApp नम्बर प्रमाण', 'WhatsApp number proof', $waAt !== '' || ($fromMember && $live)],
            ['औंठाछाप', 'Thumb impression', $g('thumb') !== ''],
            ['घोषणा / सम्झौता स्वीकार', 'Declaration accepted', $sentAt !== '' && $status !== 'draft'],
        ];
        $verify = [
            ['WhatsApp प्रमाण', 'WhatsApp proof', $waLine !== '' ? $waLine : 'छैन · not yet'],
            ['फोटो', 'Photo', $g('selfie') !== '' ? 'लाइभ क्यामेरा / अपलोड · camera or upload ✓' : ($photo !== '' ? 'प्रोफाइल फोटो · profile photo ✓' : 'छैन · none')],
            ['परिचयपत्र जाँच', 'ID read by', $readers !== [] ? implode(' · ', array_keys($readers)) : 'हातले भरिएको · typed by hand'],
            ['औंठा / अनुहार लगइन', 'Fingerprint / face sign-in', $passkeys > 0 ? 'खुला · on (' . $passkeys . ')' : 'छैन · off'],
        ];
        if ($officeBy !== '') {
            $verify[] = ['कार्यालयले भरेको', 'Filled by the office', $officeBy];
        }
        $approval = [
            ['अवस्था', 'Status', (self::STATUS[$status][0] ?? $status) . ' · ' . (self::STATUS[$status][1] ?? $status)],
            ['पठाएको मिति', 'Sent on', $fmt($sentAt, 'd M Y, H:i')],
            ['निर्णय', 'Decided by', $decidedBy !== '' ? $decidedBy . ' — ' . $fmt($g('decided_at'), 'd M Y, H:i') : ($fromMember && $live ? 'कार्यालय · office' : '')],
            [$isStaff ? 'कर्मचारी नं.' : 'एजेन्ट कोड', $isStaff ? 'Staff no.' : 'Agent code', $code],
            ['KYC', 'KYC', self::kycLabel($kyc)],
            ['कार्ड', 'ID card', $live && (string) ($mm['validUntil'] ?? '') !== '' ? 'मान्य · valid till ' . $fmt((string) $mm['validUntil']) . ' (' . (string) ($mm['status'] ?? '') . ')' : ''],
        ];
        if ($g('decision_note') !== '') {
            $approval[] = ['टिप्पणी', 'Note', $g('decision_note')];
        }

        $ref = $g('ref');
        $seal = substr(hash_hmac('sha256', 'apform|' . (int) ($a['id'] ?? 0) . '|' . $ref . '|' . $adminId . '|' . $status . '|'
            . implode('|', [$g('full_name'), $phone, $g('id_number_masked'), $g('bank_account_last4'), $g('submitted_at'), $g('decided_at')]), APP_KEY), 0, 16);

        return [
            'co'         => $co,
            'logo'       => class_exists('Brand', false) ? Brand::markUrl(120) : '/assets/img/logo-mark-tm.png',
            'tagline'    => Settings::getString('company_tagline', 'Your Trusted Travel Partner'),
            'kindKey'    => $isStaff ? 'staff' : 'agent',
            'kindShort'  => $isStaff ? 'कर्मचारी · STAFF' : ($isOrg ? 'एजेन्सी · AGENCY' : 'एजेन्ट · AGENT'),
            'titleNe'    => $isStaff ? 'कर्मचारी आवेदन फारम' : 'एजेन्ट आवेदन फारम',
            'titleEn'    => ($isStaff ? 'Staff application' : 'Agent application') . ' · ' . (string) $co['legal'],
            'ref'        => $ref !== '' ? $ref : ($code !== '' ? $code : 'M-' . $adminId),
            'date'       => $fmt($sentAt, 'd/m/Y'),
            'status'     => $status,
            'statusTxt'  => (self::STATUS[$status][0] ?? $status) . ' · ' . (self::STATUS[$status][1] ?? $status),
            'statusCol'  => self::STATUS[$status][2] ?? '#1F3C88',
            'code'       => $code,
            'codeLabel'  => $isStaff ? 'कर्मचारी नं. · Staff no.' : 'एजेन्ट कोड · Agent code',
            'name'       => $g('full_name') !== '' ? $g('full_name') : $mv('name'),
            'photo'      => $photo,
            'qr'         => $qr,
            'verifyUrl'  => $verifyUrl,
            'thumb'      => $thumb,
            'hasThumb'   => $g('thumb') !== '',
            'full'       => $full,
            'sections'   => [
                // 5th = grid: 'c4' puts four short fields on one row (keeps page 1 on one A4)
                ['👤', $isStaff ? 'कर्मचारी' : 'आवेदक', $isStaff ? 'Employee' : 'Applicant', $applicant, ''],
                ['🪪', 'परिचयपत्र', 'Identity', $identity, 'c4'],
                ['🎓', 'शिक्षा र अनुभव', 'Education & experience', $education, ''],
                ['🏦', 'बैंक / भुक्तानी', 'Bank / payment', $payment, ''],
                ['⭐', 'पद', 'Position', $position, 'c4'],
            ],
            'docs'       => $docs,
            'checklist'  => $checklist,
            'verify'     => $verify,
            'approval'   => $approval,
            'place'      => $g('city') !== '' ? $g('city') : $mv('branch'),
            'signedOn'   => $fmt($sentAt, 'd/m/Y'),
            'consent'    => $sentAt !== '' && $status !== 'draft',
            'approved'   => $approved,
            'decidedBy'  => $decidedBy,
            'decidedAt'  => $fmt($g('decided_at'), 'd/m/Y'),
            'kycOk'      => $kyc === 'verified',
            'seal'       => strtoupper($seal),
            'file'       => 'SHG-form-' . preg_replace('/[^A-Za-z0-9-]/', '-', $ref !== '' ? $ref : ($code !== '' ? $code : 'member-' . $adminId)),
        ];
    }

    /**
     * A member who never went through the join form (made before Agent
     * Pro, or by hand): the same form from their member record.
     *
     * @param array<string, mixed> $m AgentPro::member()
     * @return array<string, mixed>
     */
    private static function appFromMember(array $m): array
    {
        $type = array_search((string) ($m['idType'] ?? ''), [
            'aadhaar' => 'Aadhaar', 'citizenship' => 'Citizenship', 'pan' => 'PAN', 'voter' => 'Voter ID',
            'dl' => 'Driving Licence', 'passport' => 'Passport',
        ], true);
        return [
            'id'               => 0,
            'ref'              => '',
            'status'           => 'approved',
            'kind'             => !empty($m['isAgent']) ? 'agent' : 'staff',
            'agent_kind'       => !empty($m['isOrg']) ? 'org' : 'person',
            'role_wanted'      => (string) ($m['role'] ?? ''),
            'full_name'        => !empty($m['isOrg']) ? (string) ($m['contact'] ?? '') : (string) ($m['name'] ?? ''),
            'org_name'         => !empty($m['isOrg']) ? (string) ($m['name'] ?? '') : '',
            'phone'            => (string) ($m['phone'] ?? ''),
            'email'            => (string) ($m['email'] ?? ''),
            'dob'              => (string) ($m['dob'] ?? ''),
            'gender'           => (string) ($m['gender'] ?? ''),
            'guardian'         => (string) ($m['guardian'] ?? ''),
            'address'          => (string) ($m['address'] ?? ''),
            'city'             => (string) ($m['branch'] ?? ''),
            'country'          => (string) ($m['country'] ?? 'IN'),
            'id_type'          => $type === false ? (string) ($m['idType'] ?? '') : (string) $type,
            // admin_profiles.id_number: masked by AgentPro::approve, but the FULL number when typed on the older pages
            'id_number_raw'    => (string) ($m['idMasked'] ?? ''),
            'id_number_masked' => AgentPro::maskId($type === false ? strtolower((string) ($m['idType'] ?? '')) : (string) $type, (string) ($m['idMasked'] ?? '')),
            'admin_id'         => (int) ($m['id'] ?? 0),
            'submitted_at'     => '',
        ];
    }

    private static function gender(string $g): string
    {
        return match ($g) {
            'Male'   => 'पुरुष · Male',
            'Female' => 'महिला · Female',
            'Other'  => 'अन्य · Other',
            default  => $g,
        };
    }

    private static function kycLabel(string $k): string
    {
        return match ($k) {
            'verified'  => 'प्रमाणित · verified ✓',
            'submitted' => 'जाँच हुँदै · being checked',
            'rejected'  => 'अस्वीकृत · rejected',
            ''          => '',
            default     => 'बाँकी · pending',
        };
    }

    /**
     * A private picture as a small JPEG data URI (white behind any
     * transparency - a thumbprint PNG stays black on white).
     */
    public static function dataImg(string $abs, int $maxEdge, int $quality = 82): string
    {
        if (!is_file($abs) || filesize($abs) > 20 * 1024 * 1024) {
            return '';
        }
        $src = AgentPro::loadImage($abs);
        if ($src === null) {
            return '';
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $s = min(1.0, $maxEdge / max(1, $w, $h));
        $nw = max(1, (int) round($w * $s));
        $nh = max(1, (int) round($h * $s));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, (int) imagecolorallocate($dst, 255, 255, 255));
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        ob_start();
        imagejpeg($dst, null, $quality);
        $bin = (string) ob_get_clean();
        imagedestroy($dst);
        return $bin === '' ? '' : 'data:image/jpeg;base64,' . base64_encode($bin);
    }

    /* =================================================================
     *  HTML
     * ================================================================= */

    /**
     * @param array<string, mixed> $vm
     * @param array<string, mixed> $opt
     */
    private static function html(array $vm, array $opt): string
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $co = $vm['co'];
        $auto = (string) ($opt['auto'] ?? '');
        $back = (string) ($opt['back'] ?? '');
        $backLabel = (string) ($opt['backLabel'] ?? '← फर्कनुहोस् · Back');
        $mode = (string) ($opt['mode'] ?? 'admin');

        // "नेपाली · English", once when both are the same word (PAN, IFSC, KYC)
        $lbl = static fn(string $ne, string $en): string => $ne === $en ? $e($en) : $e($ne) . ' · ' . $e($en);
        $field = static function (array $f) use ($e, $lbl): string {
            [$ne, $en, $v, $cls] = $f;
            $v = trim((string) $v);
            return '<div class="fi ' . $e($cls) . ($v === '' ? ' empty' : '') . '"><span class="l">' . $lbl((string) $ne, (string) $en) . '</span>'
                . '<span class="v">' . ($v === '' ? '—' : $e($v)) . '</span></div>';
        };
        $section = static function (string $icon, string $ne, string $en, string $inner, string $extra = '') use ($e): string {
            return '<section class="sx"><div class="sxh"><i>' . $icon . '</i><b>' . $e($ne) . ' · ' . $e($en) . '</b>' . $extra . '</div>' . $inner . '</section>';
        };
        $rows = static fn(array $list): string => '<dl class="kv">' . implode('', array_map(
            static fn(array $r): string => '<div><dt>' . $lbl((string) $r[0], (string) $r[1]) . '</dt><dd>' . ($r[2] === '' ? '—' : $e($r[2])) . '</dd></div>',
            $list
        )) . '</dl>';

        $legal = mb_strtoupper((string) $co['legal']);
        $head = static function (bool $cont) use ($e, $vm, $co, $legal): string {
            $contact = '☎ ' . $e($co['phone']) . ($co['email'] !== '' ? ' · ✉ ' . $e($co['email']) : '') . ' · 🌐 ' . $e($co['web']);
            return '<header class="lh' . ($cont ? ' cont' : '') . '"><img src="' . $e($vm['logo']) . '" alt="' . $e($co['legal']) . '">'
                . '<div class="co"><b>' . $e($legal) . '</b>'
                . ($cont ? '' : '<span>' . $e($co['address']) . '</span><span>' . $contact . '</span>')
                . '</div><div class="ids"><span class="kb">' . $e($vm['kindShort']) . '</span>'
                . ($cont ? '' : '<span>' . ($co['cin'] !== '' ? 'CIN ' . $e($co['cin']) . '<br>' : '') . $e($vm['tagline']) . (trim((string) ($co['service'] ?? '')) !== '' ? '<br>' . $e($co['service']) : '') . '</span>')
                . '</div></header>';
        };
        $foot = static fn(int $p): string => '<footer class="pf"><span>फारम नं. · Form no. <b>' . $e($vm['ref']) . '</b></span><span>'
            . $e($co['web']) . '</span><span>सिल · Seal <b>' . $e($vm['seal']) . '</b></span><span>पाना <span class="pn">' . $p . '</span> / <span class="pt">2</span> · page</span></footer>';

        /* ---- page 1 ---- */
        $photo = $vm['photo'] !== ''
            ? '<div class="pic" role="img" aria-label="Photo" style="background-image:url(' . $e($vm['photo']) . ')"></div>'
            : '<div class="pic none">पासपोर्ट साइज<br>फोटो<br><b>Photo</b></div>';
        $qr = $vm['qr'] !== ''
            ? '<div class="hqr"><img src="' . $e($vm['qr']) . '" alt="Verify QR"><span>प्रमाणित गर्नुहोस्<br>Scan to verify</span></div>'
            : '<div class="hqr none"><span>QR स्वीकृत भएपछि<br>QR after approval</span></div>';
        $hero = '<div class="hero">' . $photo . '<div class="hx"><span class="kb light">' . $e($vm['kindShort']) . '</span>'
            . '<h1>' . $e($vm['titleNe']) . '</h1><p>' . $e($vm['titleEn']) . '</p>'
            . '<div class="hl"><div><small>फारम नं. · Form no.</small><b>' . $e($vm['ref']) . '</b></div>'
            . '<div><small>मिति · Date</small><b>' . ($vm['date'] !== '' ? $e($vm['date']) : '—') . '</b></div>'
            . '<div><small>' . $e($vm['codeLabel']) . '</small><b class="code">' . ($vm['code'] !== '' ? $e($vm['code']) : '—') . '</b></div></div>'
            . '<span class="st" style="background:' . $e($vm['statusCol']) . '">' . $e($vm['statusTxt']) . '</span></div>' . $qr . '</div>';

        $p1 = '';
        foreach ($vm['sections'] as [$icon, $ne, $en, $fields, $grid]) {
            $p1 .= $section($icon, $ne, $en, '<div class="fg' . ($grid !== '' ? ' ' . $e($grid) : '') . '">' . implode('', array_map($field, $fields)) . '</div>');
        }
        $note = $vm['full'] ? '' : '<p class="mask">🔒 परिचयपत्र, PAN र खाता नम्बर लुकाइएको — पूरा विवरण सुपर-एडमिन / म्यानेजरले मात्र देख्छन्। · ID, PAN and account numbers are masked for your role.</p>';

        /* ---- page 2 ---- */
        $tiles = '';
        foreach (array_slice($vm['docs'], 0, 8) as $d) {
            $tiles .= '<figure class="doc">' . ($d['img'] !== ''
                    ? '<div class="dimg" role="img" aria-label="' . $e($d['label']) . '" style="background-image:url(' . $e($d['img']) . ')"></div>'
                    : '<div class="dimg none">' . ($d['kind'] === 'PDF' ? '📄 PDF ✓' : ($d['kind'] === 'missing' ? '—' : '✓ ' . $e($vm['full'] ? $d['kind'] : 'फाइलमा · on file'))) . '</div>')
                . '<figcaption>' . $e($d['label']) . '</figcaption></figure>';
        }
        $more = count($vm['docs']) > 8 ? '<p class="more">+ ' . (count($vm['docs']) - 8) . ' थप कागजात · more documents on file</p>' : '';
        if ($tiles === '') {
            $tiles = '<p class="more">अनलाइन कागजात छैन · no documents sent online</p>';
        }
        $docsBox = $section('🗂️', 'कागजात', 'Documents', '<div class="docs">' . $tiles . '</div>' . $more,
            '<small>' . ($vm['full'] ? 'अनलाइन पठाइएको · as sent online' : 'चित्र म्यानेजरले मात्र · pictures for a manager only') . '</small>');

        $thumbBox = $vm['thumb'] !== ''
            ? '<div class="tb ink" role="img" aria-label="Thumb impression" style="background-image:url(' . $e($vm['thumb']) . ')"></div>'
            : '<div class="tb">' . ($vm['hasThumb'] ? '✓ फाइलमा<br>on file' : '') . '</div>';
        $sign = $section('✍️', 'सही र औंठाछाप', 'Signature & thumbprints',
            '<div class="signrow"><div class="sbox"><div class="esign">' . ($vm['consent']
                ? '✓ अनलाइन स्वीकार गरियो · accepted online<br><small>' . $e($vm['name']) . ' — ' . $e($vm['signedOn']) . '</small>'
                : '') . '</div><span>आवेदकको सही · Applicant\'s signature</span></div>'
            . '<figure>' . $thumbBox . '<figcaption>दायाँ औंठा · Right thumb</figcaption></figure>'
            . '<figure><div class="tb"></div><figcaption>बायाँ औंठा · Left thumb</figcaption></figure></div>');

        $verify = $section('🛡️', 'डिजिटल प्रमाणीकरण', 'Digital verification', $rows($vm['verify']));
        $check = '';
        foreach ($vm['checklist'] as [$ne, $en, $ok]) {
            $check .= '<li class="' . ($ok ? 'ok' : '') . '"><i>' . ($ok ? '✓' : '') . '</i>' . $e($ne) . ' · ' . $e($en) . '</li>';
        }
        $checkBox = $section('☑️', 'संलग्न कागजात', 'KYC checklist', '<ul class="chk">' . $check . '</ul>');
        $approval = $section('🏢', 'स्वीकृति', 'Approval', $rows($vm['approval']));

        $decl = '<section class="decl"><p>म माथिका विवरण साँचो हुन् र कागजात मेरै हुन् भनी घोषणा गर्छु। ' . $e($co['legal'])
            . ' को ' . ($vm['kindKey'] === 'staff' ? 'कर्मचारी' : 'एजेन्ट') . ' नियम तथा सम्झौता स्वीकार गर्छु।<br>'
            . 'I declare that the details above are true and the documents are mine. I agree to the ' . ($vm['kindKey'] === 'staff' ? 'staff rules' : 'agent agreement')
            . ' of ' . $e($co['legal']) . '.</p>'
            . '<div class="dl"><div><small>स्थान · Place</small><b>' . $e($vm['place']) . '</b></div><div><small>मिति · Date</small><b>' . $e($vm['signedOn']) . '</b></div>'
            . '<div><small>सही · Signature</small><b>' . ($vm['consent'] ? '✓ e-consent' : '') . '</b></div></div></section>';

        $tick = static fn(bool $on): string => '<i class="bx' . ($on ? ' on' : '') . '">' . ($on ? '✓' : '') . '</i>';
        $office = '<section class="office"><div><small>कार्यालय प्रयोग · Office use — checked by (name)</small><b>' . $e($vm['decidedBy']) . '</b></div>'
            . '<div class="ticks">' . $tick(false) . ' सक्कल ID देखियो · Original ID seen<br>' . $tick($vm['kycOk']) . ' KYC प्रमाणित · KYC verified<br>'
            . $tick($vm['approved']) . ' स्वीकृत · Approved ' . ($vm['decidedAt'] !== '' ? $e($vm['decidedAt']) : '') . '</div>'
            . '<div><small>सही, मिति र छाप · Signature, date &amp; stamp</small><b></b></div></section>';

        $counters = trim((string) ($co['counters'] ?? ''));
        // company_ceo (the owner's spelling, same as the cards), not company_operator
        $ceo = trim((string) ($co['ceo'] ?? ''));
        $p2foot = '<p class="cofoot">' . ($ceo !== '' ? 'CEO &amp; Director: ' . $e($ceo) . ' · ' : '') . ($counters !== '' ? $e($counters) : '')
            . ($vm['verifyUrl'] !== '' ? '<br>Verify: ' . $e($vm['verifyUrl']) : '') . '</p>';

        $sheet1 = '<div class="sheet">' . $head(false) . '<div class="body">' . $hero . $p1 . $note . '</div>' . $foot(1) . '</div>';
        $sheet2 = '<div class="sheet">' . $head(true) . '<div class="body">' . $docsBox . $sign . '<div class="two">' . $verify . $approval . '</div>'
            . $checkBox . $decl . $office . $p2foot . '</div>' . $foot(2) . '</div>';

        $kcol = $vm['kindKey'] === 'staff' ? '#11703F' : '#1F3C88';
        $title = $vm['titleEn'] . ' · ' . $vm['name'];
        $bar = '<div class="bar">' . ($back !== '' ? '<a href="' . $e($back) . '">' . $e($backLabel) . '</a>' : '')
            . '<button type="button" class="pri" id="doPrint">🖨 छाप्नुहोस् · Print</button>'
            . '<button type="button" class="pri" id="doPdf">📄 PDF डाउनलोड · Download PDF</button>'
            . '<button type="button" id="doPng">🖼 PNG</button>'
            . '<small id="msg"' . ($mode === 'applicant' ? '' : ' data-pages="1"') . '>' . ($mode === 'applicant' ? 'यो लिंक ' . self::APPLICANT_DAYS . ' दिनसम्म मात्र खुल्छ — PDF सेभ गर्नुहोस्। · This link works for ' . self::APPLICANT_DAYS . ' days — save the PDF.' : 'A4 · २ पाना · 2 pages') . '</small></div>';

        return '<!doctype html><html lang="ne"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . $e($title) . '</title>'
            . '<link rel="icon" type="image/png" href="/assets/img/favicon-32.png">'
            . '<style>' . self::css($kcol) . '</style></head><body>' . $bar
            . '<main id="pages">' . $sheet1 . $sheet2 . '</main>'
            // a continuation sheet: the script moves whatever does not fit on a page onto one of these
            . '<template id="contTpl"><div class="sheet more">' . $head(true) . '<div class="body"></div>' . $foot(0) . '</div></template>'
            . '<script>' . self::js($vm['file'], $auto) . '</script></body></html>';
    }

    private static function css(string $k): string
    {
        return <<<CSS
@font-face{font-family:NotoDeva;src:url(/assets/fonts/NotoSansDevanagari.ttf) format("truetype");font-display:swap}
@font-face{font-family:Poppins;src:url(/assets/fonts/Poppins-Regular.ttf) format("truetype");font-weight:400;font-display:swap}
@font-face{font-family:Poppins;src:url(/assets/fonts/Poppins-SemiBold.ttf) format("truetype");font-weight:600;font-display:swap}
@font-face{font-family:Poppins;src:url(/assets/fonts/Poppins-Bold.ttf) format("truetype");font-weight:700;font-display:swap}
@page{size:A4;margin:0}
*{box-sizing:border-box}
html,body{margin:0;background:#E9EDF3;color:#0F1B33;font:11px/1.4 Poppins,NotoDeva,"Segoe UI",Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{--k:{$k};--ink:#13224F;--mut:#5A6478;--line:#C9D1DE;--band:#EEF1F6}
.bar{position:sticky;top:0;z-index:5;display:flex;gap:8px;align-items:center;justify-content:center;flex-wrap:wrap;padding:8px 16px;background:#0F1B33;color:#fff}
.bar a,.bar button{font:600 14px/1 Poppins,NotoDeva,sans-serif;min-height:48px;padding:0 16px;border-radius:12px;border:0;cursor:pointer;text-decoration:none;color:#0F1B33;background:#fff;display:inline-flex;align-items:center}
.bar .pri{background:#F07A2E;color:#fff}
.bar button:disabled{opacity:.6}
.bar small{flex-basis:100%;text-align:center;opacity:.85;font-size:12px}
#pages{padding:6px 0 24px;transform-origin:0 0}
@media screen{body{overflow-x:hidden}}
@media (max-width:600px){.bar{gap:6px;padding:8px}.bar a,.bar button{font-size:13px;padding:0 12px}}
.sheet{width:210mm;height:297mm;margin:12px auto;background:#fff;box-shadow:0 6px 30px rgba(15,27,51,.18);position:relative;overflow:hidden;display:flex;flex-direction:column;padding:9mm 11mm 6mm}
.body{flex:1 1 auto;min-height:0;overflow:hidden;padding-top:3mm}
.lh{display:flex;align-items:center;gap:12px;border-bottom:3px solid var(--ink);padding-bottom:6px}
.lh img{height:56px;width:auto}
.lh .co{flex:1;min-width:0}
.lh .co b{display:block;font-size:18px;font-weight:700;letter-spacing:.3px;color:var(--ink)}
.lh .co span{display:block;font-size:9.5px;color:#3A4A66}
.lh .ids{text-align:right;font-size:9px;color:#3A4A66}
.lh .ids span{display:block}
.lh.cont{border-bottom-width:2px;padding-bottom:4px}.lh.cont img{height:30px}.lh.cont .co b{font-size:13px}
.kb{display:inline-block;margin:0 0 3px;padding:3px 10px;border-radius:999px;background:var(--ink);color:#fff;font-size:10px;font-weight:700;letter-spacing:.3px}
.kb.light{background:#fff;color:var(--ink);align-self:flex-start}
.hero{display:flex;gap:12px;align-items:stretch;background:linear-gradient(135deg,#2B3140,#454C5C);color:#fff;border-radius:12px;padding:9px;margin-bottom:3mm}
.pic{width:30mm;height:37mm;flex:none;border-radius:8px;background:#fff center/cover no-repeat;border:2px solid #fff}
.pic.none{display:flex;align-items:center;justify-content:center;text-align:center;color:#6B7280;font-size:9px;background:#F4F6FA}
.hx{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:center}
.hx h1{margin:2px 0 0;font:700 21px/1.2 NotoDeva,Poppins,sans-serif}
.hx p{margin:1px 0 6px;font-size:10.5px;opacity:.9}
.hl{display:flex;gap:14px;flex-wrap:wrap}
.hl div{min-width:28mm;border-bottom:1px solid rgba(255,255,255,.5);padding-bottom:2px}
.hl small{display:block;font-size:8.5px;opacity:.8}.hl b{font-size:12px;letter-spacing:.3px}.hl .code{color:#FFD58A}
.st{align-self:flex-start;margin-top:6px;padding:3px 10px;border-radius:999px;font-size:9.5px;font-weight:700;color:#fff;border:1px solid rgba(255,255,255,.5)}
.hqr{width:30mm;flex:none;background:#fff;border-radius:8px;color:#0F1B33;text-align:center;font-size:8px;line-height:1.25;padding:4px;display:flex;flex-direction:column;align-items:center;justify-content:center}
.hqr img{width:25mm;height:25mm;image-rendering:pixelated}.hqr.none{color:#6B7280}
.sx{margin:0 0 2.6mm;border-radius:10px;background:#FAFBFD;border:1px solid #E3E8F0}
.sxh{display:flex;align-items:center;gap:8px;background:var(--band);border-radius:10px 10px 0 0;padding:4px 9px;border-bottom:1.5px solid var(--ink)}
.sxh i{font-style:normal;width:20px;height:20px;border-radius:50%;background:var(--ink);display:inline-flex;align-items:center;justify-content:center;font-size:10px}
.sxh b{font-size:11.5px;color:var(--ink)}.sxh small{margin-left:auto;font-size:8.5px;color:var(--mut)}
.fg{display:grid;grid-template-columns:1fr 1fr;column-gap:7mm;padding:2px 9px 5px}
.fg.c4{grid-template-columns:repeat(4,minmax(0,1fr));column-gap:4mm}
.fi{border-bottom:1px solid var(--line);padding:3px 0 2px;min-height:8.4mm}
.fi.w2{grid-column:1/-1}
.fi .l{display:block;font-size:8.5px;color:var(--mut)}
.fi .v{display:block;font-size:12px;font-weight:600;color:var(--k);word-break:break-word}
.fi.big .v{font-size:14.5px}
.fi.mono .v{font-family:Consolas,"Courier New",monospace;letter-spacing:.6px}
.fi.strong .v{color:#B3261E}
.fi.empty .v{color:#B8C0CC;font-weight:400}
.mask{margin:1mm 0 0;font-size:9px;color:#8A5A00;background:#FFF6E0;border-radius:8px;padding:4px 8px}
.docs{display:grid;grid-template-columns:repeat(4,1fr);gap:3mm;padding:6px 9px 7px}
.doc{margin:0;border:1.5px dashed #B9C2D0;border-radius:8px;padding:3px;background:#fff}
.dimg{height:34mm;border-radius:6px;background:#F4F6FA center/contain no-repeat}
.dimg.none{display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;color:#11703F}
.doc figcaption{font-size:8px;text-align:center;color:#3A4A66;margin-top:2px;line-height:1.2}
.more{margin:0 9px 6px;font-size:9px;color:var(--mut)}
.signrow{display:flex;gap:5mm;align-items:flex-end;padding:6px 9px 7px}
.sbox{flex:1;border:1.5px solid #B9C2D0;border-radius:10px;height:27mm;display:flex;flex-direction:column;justify-content:space-between;padding:5px 8px;background:#fff}
.sbox .esign{font-size:10.5px;font-weight:600;color:#11703F}.sbox .esign small{font-weight:400;color:#3A4A66}
.sbox span{font-size:8.5px;color:var(--mut)}
.signrow figure{margin:0;text-align:center}
.tb{width:21mm;height:27mm;border:1.5px dashed #9AA6B8;border-radius:50%;background:#fff center/contain no-repeat;display:flex;align-items:center;justify-content:center;font-size:8px;color:#11703F}
.tb.ink{border-style:solid;border-radius:8px}
.signrow figcaption{font-size:8px;color:var(--mut);margin-top:2px}
.two{display:grid;grid-template-columns:1fr 1fr;gap:3mm}
.two .sx{margin-bottom:2.6mm}
.kv{margin:0;padding:3px 9px 5px}
.kv div{display:flex;gap:6px;border-bottom:1px dotted var(--line);padding:2px 0}
.kv dt{flex:0 0 33mm;font-size:8.5px;color:var(--mut)}
.kv dd{margin:0;flex:1;font-size:9.5px;font-weight:600;color:var(--k);word-break:break-word}
.chk{list-style:none;margin:0;padding:5px 9px 6px;display:grid;grid-template-columns:1fr 1fr;gap:2px 7mm;font-size:9.5px}
.chk li{display:flex;align-items:center;gap:6px;color:#3A4A66}
.chk i{font-style:normal;width:13px;height:13px;border:1.5px solid #3A4A66;border-radius:3px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#11703F}
.chk li.ok{color:#0F1B33}
.decl{border:1px solid #E3E8F0;border-radius:10px;padding:6px 9px;margin:0 0 2.6mm;background:#FAFBFD}
.decl p{margin:0 0 4px;font-size:9.5px;color:#0F1B33}
.dl{display:grid;grid-template-columns:1fr 1fr 1.3fr;gap:6mm}
.dl div{border-bottom:1px solid #6B7280;padding-bottom:1px}.dl small,.office small{display:block;font-size:8.5px;color:var(--mut)}.dl b{font-size:11px;color:var(--k)}
.office{display:grid;grid-template-columns:1fr 1.2fr 1fr;gap:3mm}
.office>div{border:1.5px dashed #9AA6B8;border-radius:10px;padding:5px 8px;min-height:20mm;font-size:9px}
.office b{display:block;font-size:11px;color:var(--k);margin-top:3px}
.ticks{line-height:2}
.bx{font-style:normal;display:inline-flex;width:12px;height:12px;border:1.5px solid #3A4A66;border-radius:2px;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#11703F;vertical-align:-2px}
.cofoot{margin:2.5mm 0 0;font-size:8.5px;color:var(--mut);text-align:center;word-break:break-all}
.pf{display:flex;justify-content:space-between;gap:8px;border-top:2px solid var(--ink);padding-top:3px;margin-top:2px;font-size:8.5px;color:#3A4A66}
.pf b{font-family:Consolas,monospace;color:#0F1B33;letter-spacing:.3px}
@media print{html,body{background:#fff}.bar{display:none!important}#pages{transform:none!important;height:auto!important;width:auto!important;margin:0!important;padding:0}.sheet{margin:0;box-shadow:none;break-after:page;page-break-after:always}.sheet:last-child{break-after:auto;page-break-after:auto}}
CSS;
    }

    private static function js(string $file, string $auto): string
    {
        $f = json_encode($file, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $a = json_encode($auto, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return <<<JS
(function () {
  'use strict';
  var NAME = {$f}, AUTO = {$a}, pages = document.getElementById('pages'), msg = document.getElementById('msg'), busy = false;
  /* phone: shrink the two A4 sheets to the screen width (print and the PDF use the real size) */
  function fit() {
    var vw = document.documentElement.clientWidth, s = Math.min(1, (vw - 16) / 794);
    pages.style.transform = ''; pages.style.height = ''; pages.style.width = ''; pages.style.marginLeft = '';
    if (s >= 1) return;
    pages.style.width = '794px';
    pages.style.marginLeft = Math.max(0, (vw - 794 * s) / 2) + 'px';
    pages.style.transform = 'scale(' + s + ')';
    pages.style.height = (pages.scrollHeight * s + 24) + 'px';
  }
  /* nothing is ever cut off: whatever does not fit on an A4 sheet (a long address, an agency,
     long skills) moves, section by section, onto a continuation sheet right after it */
  function paginate() {
    var tpl = document.getElementById('contTpl');
    if (!tpl || !tpl.content || !tpl.content.firstElementChild) return;
    for (var i = 0; i < 12; i++) {
      var sheets = pages.querySelectorAll('.sheet');
      if (i >= sheets.length) break;
      var sh = sheets[i], body = sh.querySelector('.body'), extra = null, guard = 0;
      while (body && body.scrollHeight > body.clientHeight + 1 && body.children.length > 1 && guard++ < 40) {
        if (!extra) { extra = tpl.content.firstElementChild.cloneNode(true); sh.parentNode.insertBefore(extra, sh.nextSibling); }
        var nb = extra.querySelector('.body');
        nb.insertBefore(body.lastElementChild, nb.firstChild);
      }
    }
    var all = pages.querySelectorAll('.sheet'), n = all.length;
    Array.prototype.forEach.call(all, function (s, k) {
      var pn = s.querySelector('.pn'), pt = s.querySelector('.pt');
      if (pn) pn.textContent = String(k + 1);
      if (pt) pt.textContent = String(n);
    });
    if (msg && msg.hasAttribute('data-pages')) msg.textContent = 'A4 · ' + n + ' पाना · pages';
  }
  fit(); window.addEventListener('resize', fit);
  var fonts = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
  var loaded = new Promise(function (r) { if (document.readyState === 'complete') r(); else window.addEventListener('load', r); });
  var ready = Promise.all([fonts, loaded]).then(function () { try { paginate(); } catch (e) {} fit(); });
  function load(urls) {
    return new Promise(function (ok, bad) {
      var i = 0;
      (function next() {
        if (i >= urls.length) { bad(new Error('load')); return; }
        var s = document.createElement('script'); s.src = urls[i++]; s.onload = ok; s.onerror = next; document.head.appendChild(s);
      })();
    });
  }
  function libs(pdf) {
    var a = window.html2canvas ? Promise.resolve() : load(['https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js']);
    var b = !pdf || (window.jspdf && window.jspdf.jsPDF) ? Promise.resolve() : load(['https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js', 'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js']);
    return Promise.all([a, b]);
  }
  function save(blob, file) {
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = file;
    document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
  }
  function run(kind, btn) {
    if (busy) return;
    busy = true;
    var label = btn.textContent, sy = window.scrollY, pdf = null, all = [];
    btn.disabled = true; btn.textContent = '⏳ …';
    ready.then(function () { return libs(kind === 'pdf'); }).then(function () {
      pages.style.transform = ''; pages.style.height = ''; pages.style.marginLeft = ''; window.scrollTo(0, 0);
      all = Array.prototype.slice.call(pages.querySelectorAll('.sheet'));
      if (kind === 'pdf') pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait', compress: true });
      var chain = Promise.resolve();
      all.forEach(function (sh, i) {
        chain = chain.then(function () {
          if (msg) msg.textContent = '⏳ पाना ' + (i + 1) + ' / ' + all.length + ' · page';
          return window.html2canvas(sh, { scale: kind === 'png' ? 2.5 : 2, backgroundColor: '#ffffff', useCORS: true, logging: false });
        }).then(function (cv) {
          if (kind === 'pdf') {
            if (i > 0) pdf.addPage();
            pdf.addImage(cv.toDataURL('image/jpeg', 0.86), 'JPEG', 0, 0, 210, 297);
            return null;
          }
          return new Promise(function (r) {
            cv.toBlob(function (b) { save(b, NAME + '-p' + (i + 1) + '.png'); setTimeout(r, 700); }, 'image/png');
          });
        });
      });
      return chain;
    }).then(function () {
      if (pdf) save(pdf.output('blob'), NAME + '.pdf');
      if (msg) msg.textContent = '✓ ' + (kind === 'pdf' ? 'PDF' : 'PNG') + ' — डाउनलोडमा हेर्नुहोस् · saved to Downloads';
    }).catch(function () {
      if (msg) msg.textContent = 'अहिले बन्न सकेन — "छाप्नुहोस्" थिचेर "Save as PDF" रोज्नुहोस्। · Could not build it now: use Print → Save as PDF.';
    }).then(function () {
      busy = false; btn.disabled = false; btn.textContent = label; fit(); window.scrollTo(0, sy);
    });
  }
  document.getElementById('doPrint').addEventListener('click', function () { ready.then(function () { window.print(); }); });
  document.getElementById('doPdf').addEventListener('click', function () { run('pdf', this); });
  document.getElementById('doPng').addEventListener('click', function () { run('png', this); });
  if (AUTO === 'pdf') ready.then(function () { setTimeout(function () { run('pdf', document.getElementById('doPdf')); }, 400); });
  else if (AUTO === 'print') ready.then(function () { setTimeout(function () { window.print(); }, 300); });
})();
JS;
    }
}
