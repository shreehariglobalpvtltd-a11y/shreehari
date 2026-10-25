<?php
/**
 * includes/staffapplications.php — staff applications (22 Oct 2026).
 *
 * Owner: the agent pattern, for STAFF. A driver, conductor, helper, office
 * hand, accountant, mechanic, cleaner or guard applies online at
 * /agreements/staff.html, the office decides in Admin -> Staff applications,
 * and an approved person appears in "Our team" on the home page with the
 * fields Settings 'staff_public_fields' allows (name, photo, phone,
 * district, role by default).
 *
 *   Files    uploads/staff/<id>/<24 random hex>.<ext>, 0640, under a 0750
 *            folder. nginx denies /uploads/staff/ (deploy/nginx-…conf) and
 *            the names are random anyway; the office reads them only through
 *            admin/staff-file.php with a short-lived signed link, and the
 *            website only ever gets the 240 px face thumbnail of an approved,
 *            listed person (/api/staff-photo.php?k=<web_key>).
 *   Phone    proven with the WhatsApp code (Auth::issueOtp purpose 'verify');
 *            when WhatsApp could not deliver the code the form still goes
 *            through with phone_verified = 0, and the office sees that.
 *   Docs     DocVerify (Gemini + Tesseract, the agent form's checker) reads
 *            the ID front and a driver's licence. Here a mismatch never
 *            blocks the form — it is a score the office sees (green / amber /
 *            red), because a real driver with a blurry photo is still a
 *            driver. Off with Settings 'staff_doc_verify' or 'agr_doc_verify'.
 *   Money    nothing. Approval does not create a payroll row (staff_members).
 *
 * One application per mobile (UNIQUE phone). A rejected applicant who
 * applies again gets the same row back as a fresh "pending" application.
 */

declare(strict_types=1);

require_once __DIR__ . '/docverify.php';
require_once __DIR__ . '/agentapplications.php';   // 22 Oct 2026 (review, #13): AgentApplications::imageFits(), one pixel rule for every form

/** Our own refusals — the text is safe to show. A PDOException never is. */
final class StaffAppError extends RuntimeException {}

final class StaffApps
{
    public const ROLES = ['driver', 'conductor', 'helper', 'office', 'accountant', 'mechanic', 'cleaner', 'security', 'other'];
    public const ROLE_LABELS = [
        'driver'     => ['Driver', 'चालक'],
        'conductor'  => ['Conductor', 'कन्डक्टर / सह-चालक'],
        'helper'     => ['Helper', 'हेल्पर / खलासी'],
        'office'     => ['Office', 'अफिस'],
        'accountant' => ['Accountant', 'लेखापाल'],
        'mechanic'   => ['Mechanic', 'मेकानिक'],
        'cleaner'    => ['Cleaner', 'सफाइ'],
        'security'   => ['Security', 'सुरक्षा गार्ड'],
        'other'      => ['Other', 'अन्य'],
    ];
    public const ID_TYPES = ['citizenship', 'aadhaar', 'passport', 'driving_license', 'pan'];
    public const ID_LABELS = [
        'citizenship'     => 'Citizenship · नागरिकता',
        'aadhaar'         => 'Aadhaar · आधार',
        'passport'        => 'Passport · राहदानी',
        'driving_license' => 'Driving licence · सवारी चालक अनुमतिपत्र',
        'pan'             => 'PAN',
    ];
    /** ID cards with a back side the office needs. */
    public const ID_TWO_SIDED = ['citizenship', 'aadhaar', 'driving_license'];
    // 23 Oct 2026 (review): 'diploma' - the one form offers "Diploma / ITI" (upgrade-2026-10-23-staff-diploma.sql)
    public const QUALS = ['below_see', 'see', 'plus2', 'diploma', 'bachelor', 'master', 'other'];
    public const QUAL_LABELS = [
        'below_see' => 'Below SEE / 10th', 'see' => 'SEE / SLC / 10th', 'plus2' => '+2 / 12th', 'diploma' => 'Diploma / ITI',
        'bachelor'  => 'Bachelor', 'master' => 'Master', 'other' => 'Other',
    ];
    public const STATUSES = ['pending', 'under_review', 'approved', 'rejected', 'suspended', 'exited'];
    public const STATUS_LABELS = [
        'pending' => 'Pending', 'under_review' => 'Under review', 'approved' => 'Approved',
        'rejected' => 'Rejected', 'suspended' => 'Suspended', 'exited' => 'Exited',
    ];
    public const GENDERS = ['male', 'female', 'other'];

    public const AGREEMENT_VERSION = '1.1';
    public const PUBLIC_FIELDS     = ['full_name', 'photo_path', 'phone', 'district', 'role_applied'];

    public const PHOTO_MAX = 2 * 1024 * 1024;   // the owner's limits; the page shrinks photos far below them
    public const DOC_MAX   = 5 * 1024 * 1024;   // PHP's upload_max_filesize (2 MB on the VPS) is the tighter one
    public const SIG_MAX   = 153600;
    public const FACE_MAX  = 120000;            // a 256 x 256 grey JPEG is ~10-25 KB
    public const MIN_AGE   = 18;
    public const MAX_AGE   = 75;

    /**
     * Upload slots: form field => [column, kind]. kind: photo = JPG / PNG,
     * doc = JPG / PNG / PDF, png = the finger signature, face = biometric frame.
     */
    public const FILES = [
        'photo'     => ['photo_path', 'photo'],
        'id_front'  => ['id_front_path', 'doc'],
        'id_back'   => ['id_back_path', 'doc'],
        'qual_cert' => ['qual_cert_path', 'doc'],
        'marksheet' => ['marksheet_path', 'doc'],
        'exp_cert'  => ['experience_cert_path', 'doc'],
        'license'   => ['license_photo_path', 'doc'],
        'signature' => ['signature_path', 'png'],
        'thumb'     => ['thumb_path', 'png'],   // 22 Oct 2026: thumbprint by the phone camera (upgrade-2026-10-22-thumbprint.sql), optional
    ];
    public const FILE_LABELS = [
        'photo' => 'Photo', 'id_front' => 'ID front', 'id_back' => 'ID back', 'qual_cert' => 'Certificate',
        'marksheet' => 'Marksheet', 'exp_cert' => 'Experience letter', 'license' => 'Driving licence', 'signature' => 'Signature',
        'thumb' => 'Thumbprint',
    ];
    private const MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

    /** Request-more-info messages the office picks from (Nepali first, English under). */
    public const INFO_TEMPLATES = [
        'photo'   => ['Clearer photo of you', 'तपाईंको अनुहार स्पष्ट देखिने नयाँ फोटो पठाउनुहोस्। · Please send a clear new photo of your face.'],
        'id'      => ['ID photo again', 'परिचयपत्रको अगाडि र पछाडिको फोटो उज्यालोमा, सबै अक्षर देखिने गरी फेरि पठाउनुहोस्। · Please send the front and back of your ID again, in good light.'],
        'license' => ['Licence photo / expiry', 'सवारी चालक अनुमतिपत्रको स्पष्ट फोटो र म्याद सकिने मिति पठाउनुहोस्। · Please send a clear photo of your driving licence and its expiry date.'],
        'cert'    => ['Certificate / marksheet', 'शैक्षिक प्रमाणपत्र वा मार्कसिट पठाउनुहोस्। · Please send your certificate or marksheet.'],
        'exp'     => ['Experience letter', 'पहिलेको कामको अनुभव पत्र पठाउनुहोस्। · Please send a letter from your previous employer.'],
        'bank'    => ['Bank details', 'तलबको लागि बैंक खाताको विवरण (खातावाला, बैंक, खाता नम्बर) पठाउनुहोस्। · Please send your bank account details for salary.'],
        'call'    => ['Please call the office', 'कृपया कार्यालयमा फोन गर्नुहोस्। · Please call the office.'],
    ];

    private static ?bool $ready = null;
    private static ?bool $locReady = null;

    /** 22 Oct 2026: work locations offered first when the office sets one ("Our team" is grouped by it). */
    public const LOCATIONS = ['Nepalgunj', 'Surat'];

    private function __construct() {}

    /* =================================================================
     *  Basics
     * ================================================================= */

    public static function available(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            self::$ready = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_applications' AND COLUMN_NAME = 'doc_verify_status'",
                [], 0
            );
        } catch (Throwable $e) {
            self::$ready = false;
        }
        return self::$ready;
    }

    /** base_location (upgrade-2026-10-22-team-portal.sql) is there. */
    public static function locationReady(): bool
    {
        if (self::$locReady === null) {
            try {
                self::$locReady = self::available() && (bool) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_applications' AND COLUMN_NAME = 'base_location'",
                    [], 0
                );
            } catch (Throwable $e) {
                self::$locReady = false;
            }
        }
        return self::$locReady;
    }

    /** @return list<string> the work locations in use, plus the usual ones */
    public static function locations(): array
    {
        $out = self::LOCATIONS;
        if (self::locationReady()) {
            foreach (Database::fetchAll(
                "SELECT DISTINCT base_location FROM staff_applications WHERE base_location IS NOT NULL AND base_location <> '' ORDER BY base_location LIMIT 100") as $r) {
                if (!in_array((string) $r['base_location'], $out, true)) {
                    $out[] = (string) $r['base_location'];
                }
            }
        }
        return $out;
    }

    public static function refFor(int $id): string
    {
        return 'STF-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /** "STF-000012", "stf12", "12" -> 12; anything else 0. */
    public static function idFromRef(string $ref): int
    {
        return preg_match('/^(?:STF-?)?0*(\d{1,9})$/i', trim($ref), $m) === 1 ? (int) $m[1] : 0;
    }

    public static function roles(): array { return array_values(array_unique(array_merge(self::ROLES,array_keys(Settings::getArray('team_staff_positions',[]))))); }
    public static function roleLabel(string $role, bool $nepali = false): string
    {
        return self::ROLE_LABELS[$role][$nepali ? 1 : 0] ?? (string)(Settings::getArray('team_staff_positions',[])[$role]['label'] ?? ucfirst($role));
    }

    public static function applyOpen(): bool
    {
        return Settings::getBool('staff_apply_on', true);
    }

    public static function docVerifyOn(): bool
    {
        return Settings::getBool('staff_doc_verify', true) && DocVerify::mode() === 'strict';
    }

    public static function biometricOn(): bool
    {
        return Settings::getBool('staff_biometric_on', false);
    }

    /** @return list<string> the fields of an approved person the website may show */
    public static function publicFields(): array
    {
        $v = Settings::get('staff_public_fields', null);
        if (is_string($v)) {
            $v = json_decode($v, true);
        }
        if (!is_array($v)) {
            return self::PUBLIC_FIELDS;
        }
        return array_values(array_intersect(self::PUBLIC_FIELDS, array_map('strval', $v)));
    }

    /** One line of applicant text: no control / direction characters, single spaces. */
    public static function oneLine(mixed $v, int $max): string
    {
        $s = Security::clean(is_scalar($v) ? (string) $v : '', $max * 2);
        $s = preg_replace('/[\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u', '', $s) ?? '';
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        return mb_substr($s, 0, $max, 'UTF-8');
    }

    /** Devanagari digits -> ASCII, upper case, separators dropped. */
    public static function normId(string $s): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(DocVerify::devDigits($s))) ?? '';
    }

    /** Is this a plausible number for that kind of ID? Checked on normId(). */
    public static function idNumberOk(string $type, string $number): bool
    {
        $n = self::normId($number);
        return match ($type) {
            'aadhaar'         => preg_match('/^[2-9]\d{11}$/', $n) === 1,
            'citizenship'     => preg_match('/^\d{5,20}$/', $n) === 1,
            'passport'        => preg_match('/^[A-Z0-9]{6,9}$/', $n) === 1,
            // Nepal 01-06-12345678 (digits) / India MH12 20110012345
            'driving_license' => preg_match('/^[A-Z0-9]{8,20}$/', $n) === 1 && preg_match_all('/\d/', $n) >= 6,
            // India ABCDE1234F / Nepal 9 digits
            'pan'             => preg_match('/^(?:[A-Z]{5}\d{4}[A-Z]|\d{9})$/', $n) === 1,
            default           => false,
        };
    }

    /** "+977 98XXXXXXXX" -> [digits, 'NP']; country from the picker code. */
    private static function phoneOf(mixed $raw, mixed $cc): array
    {
        $country = (string) $cc === '91' ? 'IN' : ((string) $cc === '977' ? 'NP' : '');
        $digits  = normalisePhone(is_scalar($raw) ? (string) $raw : '');
        if ($country === '') {
            $country = resolvePhoneCountry('', is_scalar($raw) ? (string) $raw : '') ?: 'NP';
        }
        return [$digits, $country];
    }

    private static function phoneOk(string $digits): bool
    {
        return strlen($digits) === 10 && Security::isValidPhone($digits) && !isPlaceholderPhone($digits);
    }

    private static function dateOrNull(mixed $v): ?string
    {
        $s = is_scalar($v) ? trim((string) $v) : '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $s;
    }

    /* =================================================================
     *  Validation (the form's POST + $_FILES)
     * ================================================================= */

    /**
     * @param array<string, mixed> $in    the form fields
     * @param array<string, mixed> $files $_FILES
     * @return array{ok: bool, errors: array<string, string>, clean: array<string, mixed>, files: array<string, array{bytes: string, mime: string}>}
     */
    public static function validate(array $in, array $files, array $have = []): array
    {
        $e = [];
        $c = [];

        $c['full_name'] = self::oneLine($in['full_name'] ?? '', 120);
        if (mb_strlen($c['full_name']) < 3 || preg_match('/\p{L}/u', $c['full_name']) !== 1) {
            $e['full_name'] = 'पूरा नाम लेख्नुहोस् · Enter your full name.';
        }
        [$c['phone'], $c['phone_country']] = self::phoneOf($in['phone'] ?? '', $in['cc'] ?? '');
        if (!self::phoneOk($c['phone'])) {
            $e['phone'] = '१० अङ्कको मोबाइल नम्बर लेख्नुहोस् · Enter a 10-digit mobile number.';
        }
        $wa = normalisePhone((string) ($in['whatsapp'] ?? ''));
        $c['whatsapp'] = $wa !== '' && $wa !== $c['phone'] ? $wa : null;
        if ($c['whatsapp'] !== null && !self::phoneOk($c['whatsapp'])) {
            $e['whatsapp'] = 'WhatsApp नम्बर १० अङ्कको हुनुपर्छ · WhatsApp number must be 10 digits.';
        }
        $email = trim((string) ($in['email'] ?? ''));
        $c['email'] = $email !== '' ? Security::email($email) : '';
        if ($email !== '' && $c['email'] === '') {
            $e['email'] = 'इमेल मिलेन · That email does not look right.';
        }
        $c['email'] = $c['email'] !== '' ? $c['email'] : null;

        // 22 Oct 2026 (owner: "main main kura thik chha, baki form optional gardinus"): the one form for all
        // three kinds asks only the main things - name, mobile, job, ID, photo, signature, the agreement (and
        // the licence for a driver). Everything else is optional, and checked only when it is filled in.
        $c['dob'] = self::dateOrNull($in['dob'] ?? '');
        if ($c['dob'] !== null) {
            $age = (int) (new DateTimeImmutable($c['dob']))->diff(new DateTimeImmutable('today'))->y;
            if ($c['dob'] > date('Y-m-d') || $age < self::MIN_AGE) {
                $e['dob'] = 'कम्तीमा १८ वर्ष पुगेको हुनुपर्छ · You must be at least 18 years old.';
            } elseif ($age > self::MAX_AGE) {
                $e['dob'] = 'जन्म मिति जाँच गर्नुहोस् (ईस्वी) · Please check the date of birth (AD).';
            }
        }
        $c['gender'] = in_array($in['gender'] ?? '', self::GENDERS, true) ? (string) $in['gender'] : null;

        $c['address_perm']    = self::oneLine($in['address_perm'] ?? '', 300);
        $c['address_current'] = self::oneLine($in['address_current'] ?? '', 300);
        $c['district']        = self::oneLine($in['district'] ?? '', 80);
        $c['province']        = self::oneLine($in['province'] ?? '', 80);
        $c['country']         = ($in['country'] ?? '') === 'IN' ? 'IN' : 'NP';
        $c['address_perm']    = $c['address_perm'] !== '' ? $c['address_perm'] : null;
        $c['district']        = $c['district'] !== '' ? $c['district'] : null;
        $c['address_current'] = $c['address_current'] !== '' ? $c['address_current'] : null;
        $c['province']        = $c['province'] !== '' ? $c['province'] : null;

        $c['role_applied'] = empty($in['role']) ? 'other' : (in_array($in['role'], self::roles(), true) ? (string) $in['role'] : '');
        foreach ((array)(Settings::getArray('team_staff_positions',[])[$c['role_applied']]['required']??[]) as $required) { if (trim((string)($in[$required]??''))==='') { $e[$required]='Required for this position.'; } }
        if ($c['role_applied'] === '') {
            $e['role'] = 'कुन काम? छान्नुहोस् · Choose the job you are applying for.';
        }

        $c['id_type']   = in_array($in['id_type'] ?? '', self::ID_TYPES, true) ? (string) $in['id_type'] : '';
        $c['id_number'] = self::oneLine($in['id_number'] ?? '', 40);
        if ($c['id_type'] === '') {
            $e['id_type'] = 'परिचयपत्रको प्रकार छान्नुहोस् · Choose the ID type.';
        } elseif (!self::idNumberOk($c['id_type'], $c['id_number'])) {
            $e['id_number'] = 'परिचयपत्र नम्बर मिलेन — कार्डमा जस्तो छ त्यस्तै लेख्नुहोस् · The ID number does not look right.';
        }

        $c['qualification'] = in_array($in['qualification'] ?? '', self::QUALS, true) ? (string) $in['qualification'] : null;
        $years = is_numeric($in['experience_years'] ?? null) ? (int) $in['experience_years'] : 0;
        $c['experience_years'] = max(0, min(50, $years));
        $c['prev_employer']    = self::oneLine($in['prev_employer'] ?? '', 120) ?: null;
        $c['prev_role']        = self::oneLine($in['prev_role'] ?? '', 80) ?: null;

        // Drivers: the licence is the job.
        $c['license_number']   = self::oneLine($in['license_number'] ?? '', 40) ?: null;
        $c['license_category'] = self::oneLine($in['license_category'] ?? '', 40) ?: null;
        $c['license_expiry']   = self::dateOrNull($in['license_expiry'] ?? '');
        // Licence details may be supplied later; validate values only when present.
        if ($c['license_number'] !== null && !self::idNumberOk('driving_license', $c['license_number'])) {
            $e['license_number'] = 'लाइसेन्स नम्बर जाँच गर्नुहोस् · Check the licence number.';
        }
        if ($c['license_expiry'] !== null && $c['license_expiry'] < date('Y-m-d')) {
            $e['license_expiry'] = 'लाइसेन्सको म्याद सकिएको छ · The licence has expired.';
        }

        $refs = [];
        foreach ([1, 2, 3, 4] as $i) {   // 3 + 4: the witnesses (23 Oct 2026)
            $rn = self::oneLine($in['ref' . $i . '_name'] ?? '', 80);
            $rp = normalisePhone((string) ($in['ref' . $i . '_phone'] ?? ''));
            $rr = self::oneLine($in['ref' . $i . '_relation'] ?? '', 40);
            if ($rn === '' && $rp === '') {
                continue;
            }
            if (mb_strlen($rn) < 2) {
                $e['ref' . $i . '_name'] = 'चिनारीको नाम लेख्नुहोस् · Enter the reference\'s name.';
            }
            if (!self::phoneOk($rp)) {
                $e['ref' . $i . '_phone'] = 'चिनारीको १० अङ्कको फोन लेख्नुहोस् · Enter the reference\'s 10-digit phone.';
            }
            $refs[] = ['name' => $rn, 'phone' => $rp, 'relation' => $rr];
        }
        $c['references_json'] = $refs !== [] ? json_encode($refs, JSON_UNESCAPED_UNICODE) : null;

        $c['emergency_contact_name']  = self::oneLine($in['emergency_name'] ?? '', 80);
        $c['emergency_contact_phone'] = normalisePhone((string) ($in['emergency_phone'] ?? ''));
        $c['emergency_relation']      = self::oneLine($in['emergency_relation'] ?? '', 40) ?: null;
        if ($c['emergency_contact_phone'] !== '' && !self::phoneOk($c['emergency_contact_phone'])) {
            $e['emergency_phone'] = 'आपतकालीन सम्पर्कको १० अङ्कको फोन लेख्नुहोस् · Enter the emergency contact\'s phone.';
        }
        $c['emergency_contact_name']  = $c['emergency_contact_name'] !== '' ? $c['emergency_contact_name'] : null;
        $c['emergency_contact_phone'] = $c['emergency_contact_phone'] !== '' ? $c['emergency_contact_phone'] : null;

        // Bank: optional on the form (the office can take it at joining).
        $c['bank_holder']        = self::oneLine($in['bank_holder'] ?? '', 80) ?: null;
        $c['bank_name']          = self::oneLine($in['bank_name'] ?? '', 80) ?: null;
        $c['bank_branch']        = self::oneLine($in['bank_branch'] ?? '', 80) ?: null;
        $c['bank_account']       = preg_replace('/[^0-9A-Za-z]/', '', (string) ($in['bank_account'] ?? '')) ?: null;
        $c['bank_ifsc_or_swift'] = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) ($in['bank_ifsc'] ?? '')) ?? '') ?: null;
        if ($c['bank_account'] !== null && (strlen($c['bank_account']) < 6 || strlen($c['bank_account']) > 30)) {
            $e['bank_account'] = 'खाता नम्बर जाँच गर्नुहोस् · Check the account number.';
        }
        if ($c['bank_ifsc_or_swift'] !== null && preg_match('/^(?:[A-Z]{4}0[A-Z0-9]{6}|[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?)$/', $c['bank_ifsc_or_swift']) !== 1) {
            $e['bank_ifsc'] = 'IFSC (११ अक्षर) वा SWIFT (८/११ अक्षर) मिलेन · IFSC or SWIFT code does not look right.';
        }

        // Skipping the rules submits an application, without recording acceptance.
        $c['agreement_version'] = !empty($in['agree']) ? self::AGREEMENT_VERSION : null;
        $c['show_on_website']   = empty($in['public_ok']) ? 0 : 1;

        // Files
        $got = [];
        foreach (self::FILES as $slot => [, $kind]) {
            $f = self::readUpload($files[$slot] ?? null, $kind);
            if ($f === null) {
                continue;
            }
            if (isset($f['error'])) {
                $e[$slot] = $f['error'];
                continue;
            }
            $got[$slot] = $f;
        }
        $need = ['id_front' => 'परिचयपत्रको फोटो चाहिन्छ · Photo of your ID.'];
        foreach ($need as $slot => $msg) {
            // 23 Oct 2026: an applicant correcting their form keeps the pictures already sent ($have)
            if (!isset($got[$slot]) && !isset($e[$slot]) && !in_array($slot, $have, true)) {
                $e[$slot] = $msg;
            }
        }

        return ['ok' => $e === [], 'errors' => $e, 'clean' => $c, 'files' => $got];
    }

    /**
     * One $_FILES entry checked by its BYTES (never the name or the browser's
     * type). null = nothing chosen; ['error' => text] = refused.
     *
     * @return array{bytes: string, mime: string}|array{error: string}|null
     */
    public static function readUpload(mixed $file, string $kind): ?array
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return ['error' => 'फाइल धेरै ठूलो भयो · That file is too large (PDF up to 2 MB, or send a photo instead).'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            return ['error' => 'फाइल आइपुगेन — फेरि छान्नुहोस् · The file did not arrive, please choose it again.'];
        }
        if ((int) ($file['size'] ?? 0) > self::uploadMax($kind) || (int) filesize($tmp) > self::uploadMax($kind)) {
            return ['error' => 'फाइल धेरै ठूलो भयो · That file is too large.'];
        }
        return self::checkBytes((string) file_get_contents($tmp), $kind);
    }

    private static function uploadMax(string $kind): int
    {
        return match ($kind) { 'photo' => self::PHOTO_MAX, 'png' => self::SIG_MAX, 'face' => self::FACE_MAX, default => self::DOC_MAX };
    }

    /**
     * The second half of readUpload(): the bytes themselves checked for $kind
     * (tests call it directly — is_uploaded_file() is false on the CLI).
     *
     * @return array{bytes: string, mime: string}|array{error: string}
     */
    public static function checkBytes(string $bytes, string $kind): array
    {
        if (strlen($bytes) > self::uploadMax($kind)) {
            return ['error' => 'फाइल धेरै ठूलो भयो · That file is too large.'];
        }
        $mime  = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        $allowed = match ($kind) {
            'photo' => ['image/jpeg', 'image/png'],
            'png'   => ['image/png'],
            'face'  => ['image/jpeg'],
            default => ['image/jpeg', 'image/png', 'application/pdf'],
        };
        if (!in_array($mime, $allowed, true)) {
            return ['error' => $kind === 'doc' ? 'JPG, PNG वा PDF मात्र · JPG, PNG or PDF only.' : 'फोटो (JPG / PNG) मात्र · A photo (JPG / PNG) only.'];
        }
        $info = $mime !== 'application/pdf' ? @getimagesizefromstring($bytes) : false;
        if ($mime !== 'application/pdf' && !is_array($info)) {
            return ['error' => 'फोटो खुलेन — फेरि खिच्नुहोस् · That photo could not be opened.'];
        }
        // 22 Oct 2026 (review, #13): a few KB of PNG can declare 20000 x 20000 px (the ID card, the thumb and the
        // check would need GBs to open it). The form shrinks every photo to 900-1400 px, so only a forged upload gets here.
        if ($mime !== 'application/pdf' && !AgentApplications::imageFits($info)) {
            return ['error' => 'फोटो धेरै ठूलो (पिक्सेल) — फेरि खिच्नुहोस् · That photo is too large (too many pixels), please take it again.'];
        }
        if ($mime === 'application/pdf' && !str_starts_with($bytes, '%PDF-')) {
            return ['error' => 'PDF खुलेन · That PDF could not be opened.'];
        }
        return ['bytes' => $bytes, 'mime' => $mime];
    }

    /* =================================================================
     *  Store
     * ================================================================= */

    /** @return array<string, mixed>|null */
    public static function findByPhone(string $phone): ?array
    {
        return Database::fetch('SELECT * FROM staff_applications WHERE phone = :p LIMIT 1', ['p' => $phone]);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $r = $id > 0 ? Database::fetch('SELECT * FROM staff_applications WHERE id = :id', ['id' => $id]) : null;
        if ($r !== null) {
            $r['ref'] = self::refFor((int) $r['id']);
        }
        return $r;
    }

    /**
     * Store a validated application and its files.
     *
     * @param array<string, mixed> $c     validate()['clean'] + phone_verified, doc_verify_json, doc_verify_status,
     *                                    biometric_face_hash, biometric_webauthn_cred
     * @param array<string, array{bytes: string, mime: string}> $files validate()['files'] (+ 'face')
     * @return array{id: int, ref: string, reapplied: bool}
     */
    public static function create(array $c, array $files, string $ip, string $ua): array
    {
        $old = self::findByPhone((string) $c['phone']);
        if ($old !== null && (string) $old['status'] !== 'rejected') {
            throw new StaffAppError('यो मोबाइल नम्बरबाट आवेदन ' . self::refFor((int) $old['id']) . ' पहिले नै आएको छ। केही बदल्नु परे कार्यालयमा फोन गर्नुहोस्: '
                . Settings::officePhone() . ' · An application from this number is already with the office.');
        }

        $row = $c + ['ip_address' => mb_substr($ip, 0, 45), 'user_agent' => mb_substr($ua, 0, 255)];
        foreach (self::FILES as [$col]) {
            unset($row[$col]);
        }
        unset($row['face']);

        if ($old !== null) {
            // A rejected applicant applies again: the same row, as new.
            $id = (int) $old['id'];
            // 23 Oct 2026 (review): nothing of the earlier applicant's checks carries over - the document check, the
            // phone passkey / face frame and the office's KYC verdict start again (values sent now still win: $c is first)
            $row += [
                'status' => 'pending', 'rejected_reason' => null, 'approved_by' => null, 'approved_at' => null,
                'status_changed_by' => null, 'status_changed_at' => null, 'info_requested_at' => null, 'reminded_at' => null,
                'office_note' => null,
                'doc_verify_json' => null, 'doc_verify_status' => 'unchecked',
                'biometric_face_hash' => null, 'biometric_webauthn_cred' => null,
            ];
            require_once __DIR__ . '/teamportal.php';   // the KYC columns are the team portal's (upgrade-2026-10-22-office-manage.sql)
            if (TeamPortal::kycReady()) {
                $row += ['kyc_status' => 'submitted', 'kyc_verified_by' => null, 'kyc_verified_at' => null];
            }
            foreach (self::FILES as [$col]) {
                $row[$col] = null;
            }
            Database::update('staff_applications', $row, 'id = :id', ['id' => $id]);
            Logger::audit('staff_app.reapply', 'staff_application', self::refFor($id), ['status' => 'rejected'], ['status' => 'pending'],
                'Rejected applicant applied again from the online form');
        } else {
            $id = Database::insert('staff_applications', $row);
        }

        $saved = [];
        try {
            foreach ($files as $slot => $f) {
                $saved[$slot] = self::saveFile($id, $slot, $f['bytes'], $f['mime']);
            }
            $set = [];
            foreach ($saved as $slot => $rel) {
                if (isset(self::FILES[$slot])) {
                    $set[self::FILES[$slot][0]] = $rel;
                }
            }
            if ($set !== []) {
                Database::update('staff_applications', $set, 'id = :id', ['id' => $id]);
            }
        } catch (Throwable $ex) {
            foreach ($saved as $rel) {
                self::unlinkRel($rel);
            }
            if ($old === null) {
                Database::delete('staff_applications', 'id = :id', ['id' => $id]);
            }
            throw $ex;
        }

        // The old (rejected) files go once the new ones are safely stored.
        if ($old !== null) {
            foreach (self::FILES as [$col]) {
                if (!empty($old[$col]) && !in_array($old[$col], $saved, true)) {
                    self::unlinkRel((string) $old[$col]);
                }
            }
        }

        if (!empty($c['agreement_version'])) {
            self::recordSign('apply', $id, (string) $c['full_name'], (string) $c['phone'], (int) ($c['phone_verified'] ?? 0),
                $saved['signature'] ?? null, $ip, $ua);
        }

        require_once __DIR__.'/teamregistry.php'; if(TeamRegistry::ready()){TeamRegistry::ensure('staff:'.$id);}
        return ['id' => $id, 'ref' => self::refFor($id), 'reapplied' => $old !== null];
    }

    /**
     * 23 Oct 2026 (owner: "form apply, edit"): the applicant corrects the application they sent while the office
     * has not decided it (ApplyEdit proved the number in this session). The same row is saved over; a picture is
     * replaced only when a new one was sent. The number cannot change here. A new ID photo or a new photo clears
     * the earlier document check; the status and the office's own notes stay.
     *
     * @param array<string, mixed> $c     validate()['clean'] (+ phone_verified)
     * @param array<string, array{bytes: string, mime: string}> $files validate()['files']
     * @return array{id: int, ref: string}
     */
    public static function applicantEdit(int $id, array $c, array $files, string $ip, string $ua): array
    {
        $old = self::find($id);
        if ($old === null || (string) $old['phone'] !== (string) $c['phone'] || !in_array((string) $old['status'], ['pending', 'under_review'], true)) {
            throw new StaffAppError('That application cannot be changed from here.');
        }
        $row = $c;
        foreach (self::FILES as [$col]) {
            unset($row[$col]);
        }
        unset($row['face'], $row['phone'], $row['phone_country']);
        if (empty($c['agreement_version'])) { unset($row['agreement_version']); }
        if (isset($files['id_front']) || isset($files['photo'])) {
            $row['doc_verify_json'] = null;
            $row['doc_verify_status'] = 'unchecked';
        }
        Database::update('staff_applications', $row, 'id = :id', ['id' => $id]);

        $saved = [];
        try {
            foreach ($files as $slot => $f) {
                $saved[$slot] = self::saveFile($id, $slot, $f['bytes'], $f['mime']);
            }
        } catch (Throwable $ex) {
            foreach ($saved as $rel) {
                self::unlinkRel($rel);
            }
            throw $ex;
        }
        $set = [];
        foreach ($saved as $slot => $rel) {
            if (isset(self::FILES[$slot])) {
                $set[self::FILES[$slot][0]] = $rel;
            }
        }
        if ($set !== []) {
            Database::update('staff_applications', $set, 'id = :id', ['id' => $id]);
            foreach ($set as $col => $rel) {
                if (!empty($old[$col]) && (string) $old[$col] !== $rel) {
                    self::unlinkRel((string) $old[$col]);
                }
            }
        }
        if (!empty($c['agreement_version']) && isset($saved['signature'])) {
            self::recordSign('apply', $id, (string) $c['full_name'], (string) $c['phone'], (int) ($c['phone_verified'] ?? 0), $saved['signature'], $ip, $ua);
        }
        Logger::audit('staff_app.applicant_edit', 'staff_application', self::refFor($id), null,
            ['files' => array_keys($saved)], 'Applicant corrected their own form (WhatsApp-proven number)');
        return ['id' => $id, 'ref' => self::refFor($id)];
    }

    /** Write one file under uploads/staff/<id>/ with a random name; returns 'staff/<id>/<name>'. */
    public static function saveFile(int $id, string $slot, string $bytes, string $mime): string
    {
        $ext = self::MIME_EXT[$mime] ?? null;
        if ($ext === null || $id <= 0) {
            throw new StaffAppError('That file type is not accepted.');
        }
        // 22 Oct 2026 (review, #13): every stored image (the form, the sign box, the portal) can end up on the ID card
        if ($mime !== 'application/pdf' && !AgentApplications::imageFits($bytes)) {
            throw new StaffAppError('फोटो धेरै ठूलो (पिक्सेल) · That image is too large (too many pixels).');
        }
        $dir = UPLOAD_PATH . '/staff/' . $id;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the staff upload folder.');
        }
        $name = ($slot === 'face' ? 'face-' : '') . bin2hex(random_bytes(12)) . '.' . $ext;
        $abs  = $dir . '/' . $name;
        $tmp  = $abs . '.tmp';
        if (file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes) || !rename($tmp, $abs)) {
            @unlink($tmp);
            throw new RuntimeException('Could not store an uploaded file.');
        }
        @chmod($abs, 0640);
        if ($slot === 'photo') {
            self::makeThumb($abs);
        }
        return 'staff/' . $id . '/' . $name;
    }

    /** A 240 px square face crop next to the photo (<name>-t.jpg) — the only image the website ever gets. */
    private static function makeThumb(string $abs): void
    {
        if (!function_exists('imagecreatefromstring')) {
            return;
        }
        $bytes = (string) file_get_contents($abs);
        if (!AgentApplications::imageFits($bytes)) {
            return;   // 22 Oct 2026 (review, #13): never open an image whose header asks for GBs (no thumb = initials)
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $x = (int) (($w - $side) / 2);
        $y = (int) max(0, ($h - $side) / 3);   // a portrait's face sits in the upper part
        $dst = imagecreatetruecolor(240, 240);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, $x, $y, 240, 240, $side, $side);
        $t = self::thumbOf($abs);
        imagejpeg($dst, $t, 82);
        imagedestroy($src);
        imagedestroy($dst);
        @chmod($t, 0640);
    }

    private static function thumbOf(string $path): string
    {
        return (string) preg_replace('/\.(?:jpe?g|png)$/i', '', $path) . '-t.jpg';
    }

    /** 'staff/<id>/<name>' -> the absolute path, only when it is really inside uploads/staff/. */
    public static function absPath(string $rel): ?string
    {
        if (preg_match('~^staff/\d{1,12}/(?:face-)?[a-f0-9]{24}(?:-t)?\.(?:jpg|png|pdf)$~', $rel) !== 1) {
            return null;
        }
        $base = realpath(UPLOAD_PATH . '/staff');
        $abs  = realpath(UPLOAD_PATH . '/' . $rel);
        if ($base === false || $abs === false || !is_file($abs) || !str_starts_with($abs, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $abs;
    }

    private static function unlinkRel(string $rel): void
    {
        $abs = self::absPath($rel);
        if ($abs !== null) {
            @unlink($abs);
            $t = self::thumbOf($abs);
            if ($t !== $abs && is_file($t)) {
                @unlink($t);
            }
        }
    }

    /** One accepted agreement: name + phone (+ proven or not) + time + IP. */
    public static function recordSign(string $event, ?int $staffId, string $name, string $phone, int $verified, ?string $sigRel, string $ip, string $ua): int
    {
        return Database::insert('agreement_signatures', [
            'doc' => 'staff', 'version' => self::AGREEMENT_VERSION, 'event' => $event, 'party_type' => 'staff',
            'party_id' => $staffId, 'full_name' => mb_substr($name, 0, 120), 'phone' => $phone, 'phone_verified' => $verified ? 1 : 0,
            'signature_path' => $sigRel, 'ip_address' => mb_substr($ip, 0, 45), 'user_agent' => mb_substr($ua, 0, 255),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function signs(int $staffId): array
    {
        return Database::fetchAll(
            "SELECT * FROM agreement_signatures WHERE doc = 'staff' AND party_type = 'staff' AND party_id = :id ORDER BY id",
            ['id' => $staffId]
        );
    }

    /* =================================================================
     *  Document check (DocVerify) — a score, never a gate
     * ================================================================= */

    /** The image a file holds as the data: URI DocVerify reads (PDFs -> null). */
    public static function dataUri(string $bytes, string $mime): ?string
    {
        if ($mime === 'application/pdf') {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        $real = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        return in_array($real, ['image/jpeg', 'image/png', 'image/webp'], true) ? 'data:' . $real . ';base64,' . base64_encode($bytes) : null;
    }

    /**
     * Read the ID (and a driver's licence). $recallOnly = only answers the
     * page already got in this session (the form never waits for Gemini);
     * the cron reads the rest.
     *
     * @param array<string, mixed> $c     full_name, id_number, role_applied, license_number
     * @param array<string, ?string> $uris 'id' => data URI|null, 'license' => data URI|null
     * @return array{json: string, status: string}
     */
    public static function verifyDocs(array $c, array $uris, bool $recallOnly): array
    {
        $want = ['id' => (string) ($c['id_number'] ?? '')];
        if (($c['role_applied'] ?? '') === 'driver' && !empty($c['license_number'])) {
            $want['license'] = (string) $c['license_number'];
        }
        $out = [];
        foreach ($want as $doc => $number) {
            $uri = $uris[$doc] ?? null;
            if ($uri === null || $uri === '') {
                $out[$doc] = ['status' => 'unchecked', 'note' => 'no image (PDF or missing)'];
                continue;
            }
            $key = DocVerify::key($uri, (string) $c['full_name'], $number);
            $r   = DocVerify::recall($key);
            if ($r === null && !$recallOnly) {
                $r = DocVerify::check($uri, (string) $c['full_name'], $number);
            }
            if ($r === null || ($r['status'] ?? '') === 'unavailable') {
                $out[$doc] = ['status' => 'unchecked', 'note' => $r === null ? 'queued' : 'reader busy'];
                continue;
            }
            $out[$doc] = [
                'status' => (string) $r['status'], 'name_ok' => $r['name_ok'], 'number_ok' => $r['number_ok'], 'is_doc' => $r['is_doc'],
                'engine' => (string) $r['engine'], 'seen_name' => (string) $r['seen_name'], 'seen_number' => (string) $r['seen_number'],
                'doc_type' => (string) ($r['doc_type'] ?? ''), 'problems' => array_values((array) $r['problems']),
                'summary' => DocVerify::summary($r), 'checked_at' => date('c'),
            ];
        }
        return ['json' => (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status' => self::verifyStatus($out)];
    }

    /** @param array<string, array<string, mixed>> $docs */
    public static function verifyStatus(array $docs): string
    {
        $pass = $fail = $open = 0;
        foreach ($docs as $d) {
            match ($d['status'] ?? '') { 'pass' => $pass++, 'fail' => $fail++, default => $open++ };
        }
        if ($pass + $fail === 0) {
            return 'unchecked';
        }
        if ($fail === 0 && $open === 0) {
            return 'pass';
        }
        // nothing passed and something failed = red; some of each, or a pass with one still unread = amber
        return $pass === 0 ? 'fail' : 'partial';
    }

    /** Re-read a stored application's documents now (office button, cron). */
    public static function recheck(int $id): string
    {
        $r = self::find($id);
        if ($r === null) {
            throw new StaffAppError('That application is not there.');
        }
        $uris = [];
        foreach (['id' => 'id_front_path', 'license' => 'license_photo_path'] as $doc => $col) {
            $abs = !empty($r[$col]) ? self::absPath((string) $r[$col]) : null;
            $bytes = $abs !== null ? (string) file_get_contents($abs) : '';
            $uris[$doc] = $bytes !== '' ? self::dataUri($bytes, (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '') : null;
        }
        $v = self::verifyDocs($r, $uris, false);
        Database::update('staff_applications', ['doc_verify_json' => $v['json'], 'doc_verify_status' => $v['status']], 'id = :id', ['id' => $id]);
        return $v['status'];
    }

    /* =================================================================
     *  The office
     * ================================================================= */

    /**
     * @param array{q?: string, status?: string, role?: string, district?: string} $f
     * @return list<array<string, mixed>>
     */
    public static function list(array $f = [], int $limit = 300): array
    {
        $where = ['1=1'];
        $p = [];
        if (in_array($f['status'] ?? '', self::STATUSES, true)) {
            $where[] = 'status = :st';
            $p['st'] = $f['status'];
        }
        if (in_array($f['role'] ?? '', self::roles(), true)) {
            $where[] = 'role_applied = :ro';
            $p['ro'] = $f['role'];
        }
        if (($f['district'] ?? '') !== '') {
            $where[] = 'district = :di';
            $p['di'] = $f['district'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $rid = self::idFromRef($q);
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $or = ['full_name LIKE :q1'];
            $p['q1'] = '%' . $q . '%';
            if (strlen($digits) >= 4) {
                $or[] = 'phone LIKE :q2';
                $p['q2'] = '%' . $digits . '%';
            }
            if ($rid > 0 && stripos($q, 'stf') !== false) {
                $or[] = 'id = :q3';
                $p['q3'] = $rid;
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        $loc = self::locationReady() ? 'base_location' : 'NULL AS base_location';
        $rows = Database::fetchAll(
            "SELECT id, full_name, phone, phone_country, phone_verified, district, $loc, role_applied, status, doc_verify_status,
                    photo_path, show_on_website, display_order, created_at, approved_at
               FROM staff_applications
              WHERE " . implode(' AND ', $where) . "
              ORDER BY FIELD(status, 'pending', 'under_review', 'approved', 'suspended', 'rejected', 'exited'), created_at DESC
              LIMIT " . max(1, min(1000, $limit)),
            $p
        );
        foreach ($rows as &$r) {
            $r['ref'] = self::refFor((int) $r['id']);
        }
        unset($r);
        return $rows;
    }

    /** @return array<string, int> per status + total + listed */
    public static function counts(): array
    {
        $out = array_fill_keys(self::STATUSES, 0) + ['total' => 0, 'listed' => 0, 'stale' => 0];
        foreach (Database::fetchAll('SELECT status, COUNT(*) n, SUM(show_on_website) w FROM staff_applications GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
            $out['total'] += (int) $r['n'];
            if ($r['status'] === 'approved') {
                $out['listed'] = (int) $r['w'];
            }
        }
        $out['stale'] = (int) Database::scalar(
            "SELECT COUNT(*) FROM staff_applications WHERE status IN ('pending','under_review') AND created_at < NOW() - INTERVAL 7 DAY", [], 0);
        return $out;
    }

    /** @return list<string> */
    public static function districts(): array
    {
        return array_map(static fn($r) => (string) $r['district'], Database::fetchAll(
            "SELECT DISTINCT district FROM staff_applications WHERE district IS NOT NULL AND district <> '' ORDER BY district LIMIT 200"));
    }

    /**
     * The office's decision. approve | reject | suspend | review | info | exit | reopen.
     * Writes the audit trail and WhatsApps the applicant.
     *
     * @param array{reason?: string, template?: string, note?: string} $in
     * @return array{status: string, wa: bool, waLink: ?string}
     */
    public static function decide(int $id, string $action, int $by, array $in = []): array
    {
        $r = self::find($id);
        if ($r === null) {
            throw new StaffAppError('That application is not there.');
        }
        $old    = (string) $r['status'];
        $reason = self::oneLine($in['reason'] ?? '', 300);
        $now    = date('Y-m-d H:i:s');
        $set    = ['status_changed_by' => $by ?: null, 'status_changed_at' => $now];
        $msg    = '';

        switch ($action) {
            case 'approve':
                require_once __DIR__.'/teamregistry.php'; if(TeamRegistry::ready()){TeamRegistry::validateApproval('staff:'.$id);}
                if (!in_array($old, ['pending', 'under_review', 'suspended', 'rejected'], true)) {
                    throw new StaffAppError('Only a pending, under-review, suspended or rejected application can be approved.');
                }
                $set += ['status' => 'approved', 'approved_by' => $by ?: null, 'approved_at' => $now, 'rejected_reason' => null];
                if (empty($r['web_key'])) {
                    $set['web_key'] = bin2hex(random_bytes(10));
                }
                $msg = self::waText('approved', $r);
                break;
            case 'reject':
            case 'suspend':
                if (mb_strlen($reason) < 5) {
                    throw new StaffAppError('Write the reason (at least a few words) — the applicant receives it on WhatsApp.');
                }
                if ($action === 'suspend' && $old !== 'approved') {
                    throw new StaffAppError('Only an approved staff member can be suspended.');
                }
                if ($action === 'reject' && !in_array($old, ['pending', 'under_review'], true)) {
                    throw new StaffAppError('Only a pending or under-review application can be rejected.');
                }
                $set += ['status' => $action === 'reject' ? 'rejected' : 'suspended', 'rejected_reason' => $reason];
                $msg = self::waText($action === 'reject' ? 'rejected' : 'suspended', $r, $reason);
                break;
            case 'review':
                $set += ['status' => 'under_review'];
                break;
            case 'info':
                $tpl = self::INFO_TEMPLATES[(string) ($in['template'] ?? '')] ?? null;
                if ($tpl === null) {
                    throw new StaffAppError('Pick what you need from the applicant.');
                }
                $set += ['status' => in_array($old, ['pending', 'under_review'], true) ? 'under_review' : $old, 'info_requested_at' => $now];
                $note = self::oneLine($in['note'] ?? '', 200);
                $msg = self::waText('info', $r, $tpl[1] . ($note !== '' ? "\n" . $note : ''));
                break;
            case 'exit':
                if (!in_array($old, ['approved', 'suspended'], true)) {
                    throw new StaffAppError('Only a staff member who worked here can be marked as exited.');
                }
                $set += ['status' => 'exited', 'show_on_website' => 0];
                break;
            case 'reopen':
                $set += ['status' => 'pending', 'rejected_reason' => null];
                break;
            default:
                throw new StaffAppError('Unknown action.');
        }

        Database::update('staff_applications', $set, 'id = :id', ['id' => $id]);
        Logger::audit('staff_app.' . $action, 'staff_application', (string) $r['ref'], ['status' => $old], ['status' => $set['status'] ?? $old]
            + ($action === 'info' ? ['asked' => (string) ($in['template'] ?? '')] : []), 'Staff application ' . $action, $reason);

        if ($action==='approve') { Database::afterCommit(static function()use($id):void { require_once __DIR__.'/carddelivery.php'; CardDelivery::send(StaffApps::refFor($id)); }); }
        $wa = false;
        if ($msg !== '') {
            $wa = self::sendWa($r, $msg, 'staff_app_' . $action);
        }
        return ['status' => (string) ($set['status'] ?? $old), 'wa' => $wa === true, 'waLink' => is_string($wa) ? $wa : null];
    }

    /** Website card: on / off, the order (lower first) and the work location (null = leave it). */
    public static function setWebsite(int $id, bool $on, ?int $order, int $by, ?string $loc = null): void
    {
        $r = self::find($id);
        if ($r === null) {
            throw new StaffAppError('That application is not there.');
        }
        $set = ['show_on_website' => $on ? 1 : 0];
        if ($order !== null) {
            $set['display_order'] = max(-9999, min(9999, $order));
        }
        if ($loc !== null && self::locationReady()) {
            $loc = self::oneLine($loc, 60);
            if ($loc !== (string) ($r['base_location'] ?? '')) {
                $set['base_location'] = $loc !== '' ? $loc : null;
            }
        }
        if ($on && empty($r['web_key'])) {
            $set['web_key'] = bin2hex(random_bytes(10));
        }
        Database::update('staff_applications', $set, 'id = :id', ['id' => $id]);
        Logger::audit('staff_app.website', 'staff_application', (string) $r['ref'],
            ['show_on_website' => (int) $r['show_on_website'], 'display_order' => (int) $r['display_order']], $set, 'Website card changed');
    }

    public static function setNote(int $id, string $note): void
    {
        Database::update('staff_applications', ['office_note' => self::oneLine($note, 500) ?: null], 'id = :id', ['id' => $id]);
    }

    /* =================================================================
     *  WhatsApp
     * ================================================================= */

    /** @param array<string, mixed> $r */
    public static function waText(string $kind, array $r, string $extra = ''): string
    {
        $name = trim((string) ($r['full_name'] ?? ''));
        $ref  = self::refFor((int) $r['id']);
        $role = self::roleLabel((string) ($r['role_applied'] ?? ''), true);
        $co   = Settings::getString('company_name', 'S Hari Global');
        $tel  = Settings::officePhone();
        return match ($kind) {
            'approved'  => "🎉 नमस्ते {$name}! {$co} मा तपाईंको {$role} आवेदन {$ref} स्वीकृत भयो।\n"
                         . "कर्मचारी सम्झौता पढेर फोनबाटै सही गर्नुहोस्: " . appUrl('agreements/staff-agreement.html?ref=' . $ref) . "\n"
                         // 22 Oct 2026: the team portal - ID card, CV, password made with a WhatsApp code
                         . "🪪 टिम पोर्टल (परिचयपत्र, CV): " . appUrl('team/') . " — पहिलो पटक \"पासवर्ड बनाउनुहोस्\" थिच्नुहोस्।\n"
                         . "कार्यालयले काम सुरु गर्ने दिन र समयको लागि फोन गर्नेछ। · Your application is approved — please read and sign the staff agreement at the link above.",
            'rejected'  => "नमस्ते {$name}, {$co} मा तपाईंको आवेदन {$ref} अहिलेलाई स्वीकृत हुन सकेन।\nकारण: {$extra}\n"
                         . "प्रश्न भए कार्यालयमा फोन गर्नुहोस्: {$tel} · Your application was not approved this time.",
            'suspended' => "नमस्ते {$name}, {$co} ले तपाईंलाई कामबाट अस्थायी रूपमा निलम्बन (suspend) गरेको छ। ({$ref})\nकारण: {$extra}\n"
                         . "कार्यालयमा सम्पर्क गर्नुहोस्: {$tel} · You have been suspended from duty — please contact the office.",
            'info'      => "नमस्ते {$name}, तपाईंको आवेदन {$ref} हेर्दैछौं। अझ एउटा कुरा चाहियो:\n{$extra}\n"
                         . "यही WhatsApp मा पठाउनुहोस् वा कार्यालयमा फोन गर्नुहोस्: {$tel}",
            'reminder'  => "नमस्ते {$name}, {$co} मा तपाईंको आवेदन {$ref} कार्यालयले हेर्दैछ। केही थप चाहिए हामी WhatsApp गर्नेछौं।\n"
                         . "थप जानकारीको लागि: {$tel} · We are still reviewing your application.",
            default     => '',
        };
    }

    /**
     * true = delivered by the API; a wa.me link = not delivered, the office
     * can tap it to send by hand (Notify::whatsapp's fallback); false = failed.
     *
     * @param array<string, mixed> $r
     */
    public static function sendWa(array $r, string $text, string $purpose): bool|string
    {
        try {
            $sent = Notify::whatsapp((string) $r['phone'], $text, null, (string) ($r['phone_country'] ?? 'NP'), [], null, ['purpose' => $purpose]);
            return $sent === true ? true : (is_string($sent) && str_starts_with($sent, 'https://') ? $sent : false);
        } catch (Throwable $e) {
            Logger::warning('Staff application WhatsApp not sent: ' . $e->getMessage(), ['ref' => self::refFor((int) $r['id'])], 'whatsapp');
            return false;
        }
    }

    /* =================================================================
     *  Signed links (admin/staff-file.php)
     * ================================================================= */

    public static function fileSig(int $id, string $slot, int $exp, int $adminId): string
    {
        return substr(hash_hmac('sha256', $id . '|' . $slot . '|' . $exp . '|' . $adminId, APP_KEY . '|staff-file'), 0, 32);
    }

    /** A link to one stored file for THIS admin, good for 15 minutes. */
    public static function fileUrl(int $id, string $slot, int $adminId, bool $thumb = false): string
    {
        $exp = time() + 900;
        return '/admin/staff-file.php?' . http_build_query(['id' => $id, 'f' => $slot, 'e' => $exp, 's' => self::fileSig($id, $slot, $exp, $adminId)]
            + ($thumb ? ['t' => 1] : []));
    }

    /** The column a slot reads ('photo' ... 'signature', 'face'). */
    public static function slotColumn(string $slot): ?string
    {
        return $slot === 'face' ? null : (self::FILES[$slot][0] ?? null);
    }

    /* =================================================================
     *  The website
     * ================================================================= */

    /**
     * Approved and listed people, with only the public fields. Built key by
     * key, so a new column can never leak by accident.
     *
     * @return list<array<string, mixed>>
     */
    public static function directory(): array
    {
        if (!self::available()) {
            return [];
        }
        $pub  = array_flip(self::publicFields());
        // 22 Oct 2026: the work location groups the cards. It is the company's
        // branch, never the person's home, so it is not one of the public fields.
        $loc  = self::locationReady() ? 'base_location' : 'NULL AS base_location';
        $rows = Database::fetchAll(
            "SELECT id, full_name, phone, phone_country, district, $loc, role_applied, photo_path, web_key
               FROM staff_applications
              WHERE status = 'approved' AND show_on_website = 1
              ORDER BY display_order ASC, approved_at ASC, id ASC
              LIMIT 500"
        );
        $out = [];
        foreach ($rows as $r) {
            $role = (string) $r['role_applied'];
            $it = [
                'name'  => isset($pub['full_name']) ? (string) $r['full_name'] : '',
                'role'  => isset($pub['role_applied']) ? $role : '',
                'roleEn'=> isset($pub['role_applied']) ? self::roleLabel($role) : '',
                'roleNe'=> isset($pub['role_applied']) ? self::roleLabel($role, true) : '',
                'district' => isset($pub['district']) ? (string) ($r['district'] ?? '') : '',
                'loc'   => (string) ($r['base_location'] ?? ''),
                'photo' => '',
                'phone' => '',
                'tel'   => '',
                'wa'    => '',
            ];
            if (isset($pub['photo_path']) && !empty($r['photo_path']) && preg_match('/^[a-f0-9]{20}$/', (string) $r['web_key']) === 1) {
                $it['photo'] = '/api/staff-photo.php?k=' . $r['web_key'];
            }
            if (isset($pub['phone']) && strlen((string) $r['phone']) === 10) {
                $cc = countryDialCode((string) $r['phone_country']) ?: '977';
                $it['phone'] = '+' . $cc . ' ' . $r['phone'];
                $it['tel']   = '+' . $cc . $r['phone'];
                $it['wa']    = $cc . $r['phone'];
            }
            $out[] = $it;
        }
        return $out;
    }

    /**
     * The home page's Top 3 staff (23 Oct 2026, owner: "Top 3 Staff ... with rankings"). Staff do not sell
     * tickets, so the order is the office's own (display_order - the office pins people), then a person with a
     * public photo, then the office's KYC check, then experience, then the longest serving. Only the public
     * fields the office allows (staff_public_fields) leave this method - experience and KYC only decide the
     * order and are never shown; the badge says "office verified" only when the office verified the KYC.
     *
     * @return list<array{name: string, role: string, area: string, photo: string, phone: string, tel: string, wa: string, metric: string}>
     */
    public static function top(int $limit = 3): array
    {
        if (!self::available()) {
            return [];
        }
        $pub = array_flip(self::publicFields());
        if (!isset($pub['full_name'])) {
            return [];   // a Top 3 without names would say nothing
        }
        $loc = self::locationReady() ? 'base_location' : 'NULL AS base_location';
        require_once __DIR__ . '/teamportal.php';
        $kyc = TeamPortal::kycReady() ? 'kyc_status' : "'none' AS kyc_status";
        $rows = Database::fetchAll(
            "SELECT id, full_name, phone, phone_country, district, $loc, role_applied, photo_path, web_key,
                    display_order, experience_years, approved_at, $kyc
               FROM staff_applications
              WHERE status = 'approved' AND show_on_website = 1
              LIMIT 500"
        );
        $hasPic = static fn(array $r): int => isset($pub['photo_path']) && !empty($r['photo_path'])
            && preg_match('/^[a-f0-9]{20}$/', (string) $r['web_key']) === 1 ? 1 : 0;
        usort($rows, static fn(array $x, array $y): int =>
            [(int) $x['display_order'], -$hasPic($x), (string) $x['kyc_status'] === 'verified' ? 0 : 1, -(int) $x['experience_years'], (string) $x['approved_at'], (int) $x['id']]
            <=> [(int) $y['display_order'], -$hasPic($y), (string) $y['kyc_status'] === 'verified' ? 0 : 1, -(int) $y['experience_years'], (string) $y['approved_at'], (int) $y['id']]);
        $out = [];
        foreach (array_slice($rows, 0, max(1, $limit)) as $r) {
            $role = (string) $r['role_applied'];
            $it = [
                'name'   => (string) $r['full_name'],
                'role'   => isset($pub['role_applied']) ? self::roleLabel($role, true) . ' · ' . self::roleLabel($role) : '',
                'area'   => trim((string) ($r['base_location'] ?? '')) ?: (isset($pub['district']) ? (string) ($r['district'] ?? '') : ''),
                'photo'  => $hasPic($r) ? '/api/staff-photo.php?k=' . $r['web_key'] : '',
                'phone'  => '',
                'tel'    => '',
                'wa'     => '',
                'metric' => (string) $r['kyc_status'] === 'verified' ? '✔ कार्यालय प्रमाणित · Office verified' : '',
            ];
            if (isset($pub['phone']) && strlen((string) $r['phone']) === 10) {
                $cc = countryDialCode((string) $r['phone_country']) ?: '977';
                $it['phone'] = '+' . $cc . ' ' . $r['phone'];
                $it['tel']   = '+' . $cc . $r['phone'];
                $it['wa']    = $cc . $r['phone'];
            }
            $out[] = $it;
        }
        return $out;
    }

    /**
     * The 240 px thumbnail behind /api/staff-photo.php?k=… — only for an
     * approved, listed person while the photo is a public field.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function publicPhoto(string $key): ?array
    {
        if (preg_match('/^[a-f0-9]{20}$/', $key) !== 1 || !self::available() || !in_array('photo_path', self::publicFields(), true)) {
            return null;
        }
        $rel = Database::scalar(
            "SELECT photo_path FROM staff_applications
              WHERE web_key = :k AND status = 'approved' AND show_on_website = 1 AND photo_path IS NOT NULL LIMIT 1",
            ['k' => $key], null
        );
        $abs = is_string($rel) ? self::absPath($rel) : null;
        if ($abs === null) {
            return null;
        }
        $thumb = self::thumbOf($abs);
        $use   = is_file($thumb) ? $thumb : $abs;
        $bytes = (string) file_get_contents($use);
        $mime  = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        return in_array($mime, ['image/jpeg', 'image/png'], true) ? [$mime, $bytes] : null;
    }

    /* =================================================================
     *  Phase 2 — biometric stubs (Settings 'staff_biometric_on')
     * ================================================================= */

    /**
     * A WebAuthn registration answer the page sent, checked against the
     * challenge this session was given. Only the public credential is kept:
     * the phone's fingerprint never leaves the phone.
     *
     * @return string|null JSON to store, or null when it does not check out
     */
    public static function webauthnCred(mixed $raw, string $challenge, string $origin): ?string
    {
        if (!is_string($raw) || $raw === '' || strlen($raw) > 8000 || $challenge === '') {
            return null;
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || !is_string($j['id'] ?? null) || !is_string($j['clientDataJSON'] ?? null) || !is_string($j['attestationObject'] ?? null)) {
            return null;
        }
        $b64u = static fn(string $s): string|false => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        $cd = $b64u($j['clientDataJSON']);
        $client = is_string($cd) ? json_decode($cd, true) : null;
        if (!is_array($client) || ($client['type'] ?? '') !== 'webauthn.create'
            || !hash_equals($challenge, (string) ($client['challenge'] ?? '')) || ($client['origin'] ?? '') !== $origin) {
            return null;
        }
        return (string) json_encode([
            'id' => mb_substr($j['id'], 0, 512), 'type' => 'public-key',
            'clientDataJSON' => $j['clientDataJSON'], 'attestationObject' => $j['attestationObject'],
            'transports' => array_values(array_filter((array) ($j['transports'] ?? []), 'is_string')),
            'registered_at' => date('c'),
        ], JSON_UNESCAPED_SLASHES);
    }
}
