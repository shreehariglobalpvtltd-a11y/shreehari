<?php
/**
 * =====================================================================
 *  AgentApplications — "apply for an agreement" (27 Sep 2026).
 *
 *  The six fillable agreement pages under /agreements/ (partner = an
 *  organization / Pvt. Ltd., agent = an individual connector) used to save
 *  only on the visitor's phone. They now send the filled form to
 *  api/agreement-apply.php, which lands here:
 *
 *    validate()   required fields, phone 10-13 digits once +91 / +977 / 00
 *                 are taken off, email shape, only the input names the
 *                 pages really use (FIELDS), every value trimmed to 300
 *                 characters, one-line fields kept on one line, the photo
 *                 checked as a real JPEG / PNG / WEBP of at most 600 KB
 *                 (else dropped with a note — the application still goes
 *                 through)
 *    create()     one row in agent_applications. Exactly the same form
 *                 again (same phone, kind, fields, photo and choice) inside
 *                 24 h is a retry and gets ITS ref back — a double tap or a
 *                 slow network never makes two files. Anything different
 *                 from the same phone is a NEW row marked duplicate_of the
 *                 earlier one: nobody can "reserve" a number with junk and
 *                 have the real person's form thrown away, and the answer
 *                 never hands out a ref made by somebody else's request.
 *    list/counts  the office dashboard (admin/agreements.php)
 *    update()     status / KYC / agreement / note / linked account, audited
 *    directory()  the PUBLIC list of agents and partners (api/agent-directory.php)
 *
 *  PRIVACY — the public list carries exactly: name, kind (agent | partner),
 *  area, phone (full only when that person agreed, otherwise masked — see
 *  maskPhone()), and two positive badges (KYC verified, agreement signed).
 *  Existing agent accounts are on it only when the office ticks "Listed".
 *  Never an ID number, address, bank detail, email, photo or any other
 *  status. The photo is stored in the database as a data: URI rather than a
 *  file under /uploads, because nginx serves /uploads to anyone.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * A refusal the office may read as it is ("That is not an agent account.").
 * Anything else that goes wrong while saving — a database error above all —
 * is logged and shown as one plain line, never as SQL text.
 */
final class AgreementError extends RuntimeException
{
}

final class AgentApplications
{
    public const TYPES     = ['org', 'ind'];
    public const LANGS     = ['ne', 'hi', 'en', 'gu'];
    public const STATUSES  = ['new', 'reviewing', 'kyc_pending', 'agreement_pending', 'approved', 'rejected'];
    public const KYC       = ['none', 'submitted', 'verified', 'rejected'];
    public const AGREEMENT = ['none', 'sent', 'signed', 'expired'];

    /** Every input name the six agreement pages use. Anything else is dropped. */
    public const FIELDS = [
        'a_no', 'a_date', 'a_place', 'co_gst', 'co_email', 'co_sig', 'co_desig',
        'p_name', 'p_type', 'p_reg', 'p_tax', 'p_addr', 'p_office', 'p_phone', 'p_email',
        'p_rep', 'p_rep_desig', 'p_rep_phone', 'p_id', 'p_dir_id', 'p_route', 'p_area',
        'p_dob', 'p_occ', 'p_addr2', 'p_idtype', 'p_idno', 'p_pan',
        'a_start', 'a_end',
        'b_name', 'b_bank', 'b_acc', 'b_ifsc', 'b_upi',
        'org_rate', 'org_pct', 'org_target', 'org_fixed', 'org_day', 'org_dep',
        'ind_target', 'ind_fixed', 'ind_pct', 'ind_dep',
        'c31_hours', 'c62_years', 'c73',
        'w1_name', 'w1_addr', 'w2_name', 'w2_addr',
        'g1_name', 'g1_id', 'g1_addr', 'g2_name', 'g2_id', 'g2_addr',
        'g3_name', 'g3_id', 'g3_addr', 'g4_name', 'g4_id', 'g4_addr',
    ];

    /** Required on top of p_name / p_phone / p_area, per type. */
    public const REQUIRED = [
        'org' => ['p_name', 'p_phone', 'p_area', 'p_rep'],
        // Permanent address is starred in the owner's agent agreement (22222.docx).
        'ind' => ['p_name', 'p_phone', 'p_area', 'p_idno', 'p_addr'],
    ];

    /**
     * Fields that may run over several lines (addresses). Every other field
     * is one line: a newline in a name or an area could otherwise add
     * official-looking lines to the office's WhatsApp note.
     */
    public const MULTILINE = ['p_addr', 'p_addr2', 'p_office', 'w1_addr', 'w2_addr'];

    /**
     * KYC and bank details. Only a super-admin or a manager sees them in
     * full on the office page; everyone else sees them masked.
     *   KYC_TAIL   the last 4 characters stay visible (••••1234)
     *   KYC_HIDDEN not shown at all
     * p_addr / p_addr2 are an individual's home addresses (an organization's
     * p_addr is its registered office and stays visible).
     */
    public const KYC_TAIL   = ['p_idno', 'p_pan', 'b_acc', 'p_dir_id', 'g1_id', 'g2_id', 'g3_id', 'g4_id'];
    public const KYC_HIDDEN = ['p_dob', 'b_name', 'b_bank', 'b_ifsc', 'b_upi'];
    public const KYC_HOME   = ['p_addr', 'p_addr2'];

    public const FIELD_MAX       = 300;
    public const PHONE_MIN       = 10;       // digits, after normalisePhone()
    public const PHONE_MAX       = 13;
    public const PHOTO_MAX_BYTES = 614400;   // 600 KB decoded
    public const PHOTO_MIME      = ['image/jpeg', 'image/png', 'image/webp'];
    public const SIG_MAX_BYTES   = 153600;   // 150 KB decoded - a finger signature PNG is ~10-40 KB
    public const TERM_MONTHS     = 11;       // the contract's term
    public const DEDUPE_HOURS    = 24;

    /** Human labels for the office (English — the admin panel is English-first). */
    public const STATUS_LABELS = [
        'new'               => 'New',
        'reviewing'         => 'Reviewing',
        'kyc_pending'       => 'KYC pending',
        'agreement_pending' => 'Agreement pending',
        'approved'          => 'Approved',
        'rejected'          => 'Rejected',
    ];
    public const KYC_LABELS = [
        'none' => 'Not given', 'submitted' => 'Submitted', 'verified' => 'Verified', 'rejected' => 'Rejected',
    ];
    public const AGREEMENT_LABELS = [
        'none' => 'Not sent', 'sent' => 'Sent', 'signed' => 'Signed', 'expired' => 'Expired',
    ];

    /** Office-readable label for each stored field (the detail panel). */
    public const FIELD_LABELS = [
        'a_no' => 'Agreement no.', 'a_date' => 'Agreement date', 'a_place' => 'Place signed',
        'co_gst' => 'Company GST no.', 'co_email' => 'Company email',
        'co_sig' => 'Company signatory', 'co_desig' => 'Signatory designation',
        'p_name' => 'Name', 'p_type' => 'Organization type', 'p_reg' => 'Registration no.',
        'p_tax' => 'GST / PAN / tax no.', 'p_addr' => 'Address (registered / permanent)', 'p_office' => 'Office / branch address',
        'p_phone' => 'Mobile', 'p_email' => 'Email', 'p_rep' => 'Representative', 'p_rep_desig' => 'Representative designation',
        'p_rep_phone' => 'Representative mobile', 'p_id' => 'Partner / agent code or login ID (if given)',
        'p_dir_id' => 'Director ID no. (citizenship / Aadhaar / passport)', 'p_route' => 'Route', 'p_area' => 'Work area',
        'p_dob' => 'Date of birth / age', 'p_occ' => 'Occupation', 'p_addr2' => 'Current address',
        'p_idtype' => 'ID type', 'p_idno' => 'ID number', 'p_pan' => 'PAN',
        'a_start' => 'Start date', 'a_end' => 'End date',
        'b_name' => 'Account holder', 'b_bank' => 'Bank / branch', 'b_acc' => 'Account no.', 'b_ifsc' => 'IFSC', 'b_upi' => 'UPI',
        'org_rate' => 'Service fee / commission', 'org_pct' => 'Advance %', 'org_target' => 'Monthly target', 'org_fixed' => 'Fixed amount', 'org_day' => 'Payment day',
        'org_dep' => 'Security deposit (Rs)',
        'ind_target' => 'Passenger target', 'ind_fixed' => 'Fixed amount (Rs)', 'ind_pct' => 'Below target %', 'ind_dep' => 'Security deposit (Rs)',
        'c31_hours' => 'Clause 3.1: money deposited within (hours)', 'c62_years' => 'Clause 6.2: no diverting customers for (years)',
        'c73' => 'Clause 7.3 (extra 10% of a loss)',
        'w1_name' => 'Witness 1', 'w1_addr' => 'Witness 1 address', 'w2_name' => 'Witness 2', 'w2_addr' => 'Witness 2 address',
        'g1_name' => 'Nepal guarantor 1', 'g1_id' => 'Nepal guarantor 1 ID no.', 'g1_addr' => 'Nepal guarantor 1 address / mobile',
        'g2_name' => 'Nepal guarantor 2', 'g2_id' => 'Nepal guarantor 2 ID no.', 'g2_addr' => 'Nepal guarantor 2 address / mobile',
        'g3_name' => 'India guarantor 1', 'g3_id' => 'India guarantor 1 ID no.', 'g3_addr' => 'India guarantor 1 address / mobile',
        'g4_name' => 'India guarantor 2', 'g4_id' => 'India guarantor 2 ID no.', 'g4_addr' => 'India guarantor 2 address / mobile',
    ];

    /**
     * The radio choices on the pages (value = index), in English for the
     * office. They MUST stay equal, in the same order, to T.en.f.orgTypes and
     * T.en.f.idTypes in tools/agreements/content.js — the page posts only the
     * index. tests/agent-directory-test.php fails when the two drift apart.
     */
    public const ORG_TYPES = ['Pvt. Ltd.', 'Partnership firm', 'Proprietorship (one owner)', 'Other'];
    public const ID_TYPES  = ['Aadhaar card', 'Citizenship certificate', 'Passport', 'Voter ID', 'Other'];

    /** A stored field as the office should read it (radio index -> its word). */
    public static function displayValue(string $name, string $value): string
    {
        if ($name === 'p_type' && ctype_digit($value)) {
            return self::ORG_TYPES[(int) $value] ?? $value;
        }
        if ($name === 'p_idtype' && ctype_digit($value)) {
            return self::ID_TYPES[(int) $value] ?? $value;
        }
        if ($name === 'c73') {
            return match (strtolower($value)) {
                'yes', '1', 'true', 'on' => 'Applies',
                'no', '0', 'false'       => 'Does not apply',
                default                  => $value,
            };
        }
        return $value;
    }

    public const HIDDEN_TEXT = 'hidden — manager only';

    /** A KYC / bank field for this kind of application (see KYC_*). */
    public static function isKycField(string $name, string $type): bool
    {
        return in_array($name, self::KYC_TAIL, true) || in_array($name, self::KYC_HIDDEN, true)
            || ($type === 'ind' && in_array($name, self::KYC_HOME, true));
    }

    /**
     * A stored field as the office page prints it. $full = the viewer may
     * see KYC and bank details (super-admin / manager). Everyone else gets
     * the last 4 characters of an ID / account number (••••1234; a short
     * one is all bullets) and "hidden" for the rest.
     */
    public static function officeValue(string $name, string $value, string $type, bool $full): string
    {
        if ($full || !self::isKycField($name, $type)) {
            return self::displayValue($name, $value);
        }
        if (in_array($name, self::KYC_TAIL, true)) {
            $bare = preg_replace('/\s+/u', '', $value) ?? '';
            return mb_strlen($bare, 'UTF-8') >= 8 ? '••••' . mb_substr($bare, -4, null, 'UTF-8') : '••••';
        }
        return self::HIDDEN_TEXT;
    }

    private static ?bool $tableOk   = null;
    private static ?bool $profileOk = null;
    private static ?bool $kycOk     = null;
    private static ?bool $extraOk   = null;
    private static ?bool $verifyOk  = null;
    private static ?bool $webOk     = null;
    private static ?bool $logoOk    = null;

    /* =================================================================
     *  Schema presence — a page must never 500 because the migration has
     *  not run yet on some database.
     * ================================================================= */

    /**
     * The table is there WITH the retry / repeat columns. A database that
     * only has the first draft of the table reads as "not there yet", so the
     * page asks for the SQL to be run instead of failing on a missing column.
     */
    public static function available(): bool
    {
        if (self::$tableOk === null) {
            try {
                self::$tableOk = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_applications'
                        AND COLUMN_NAME IN ('id','payload_hash','duplicate_of')",
                    [], 0
                ) === 3;
            } catch (Throwable $e) {
                self::$tableOk = false;
            }
        }
        return self::$tableOk;
    }

    /** admin_profiles carries agreement_status / public_listing / public_phone. */
    public static function profileColumnsAvailable(): bool
    {
        if (self::$profileOk === null) {
            try {
                self::$profileOk = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_profiles'
                        AND COLUMN_NAME IN ('agreement_status','agreement_signed_at','agreement_end','public_listing','public_phone')",
                    [], 0
                ) === 5;
            } catch (Throwable $e) {
                self::$profileOk = false;
            }
        }
        return self::$profileOk;
    }

    private static function kycColumnAvailable(): bool
    {
        if (self::$kycOk === null) {
            try {
                self::$kycOk = Database::exists(
                    "SELECT 1 FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'kyc_status'"
                );
            } catch (Throwable $e) {
                self::$kycOk = false;
            }
        }
        return self::$kycOk;
    }

    /**
     * The simple online form's extras (18 Oct 2026): doc_photo, signature,
     * geo - database/upgrade-2026-10-18-agent-apply-kyc.sql. Without them an
     * application is still stored, just without those three.
     */
    public static function extraColumnsAvailable(): bool
    {
        if (self::$extraOk === null) {
            try {
                self::$extraOk = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_applications'
                        AND COLUMN_NAME IN ('doc_photo','signature','geo')",
                    [], 0
                ) === 3;
            } catch (Throwable $e) {
                self::$extraOk = false;
            }
        }
        return self::$extraOk;
    }

    /**
     * The document check's verdict (18 Oct 2026):
     * database/upgrade-2026-10-18-agent-doc-verify.sql.
     */
    public static function verifyColumnAvailable(): bool
    {
        if (self::$verifyOk === null) {
            try {
                self::$verifyOk = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_applications'
                        AND COLUMN_NAME = 'doc_verify'",
                    [], 0
                ) === 1;
            } catch (Throwable $e) {
                self::$verifyOk = false;
            }
        }
        return self::$verifyOk;
    }

    /**
     * The website card (19 Oct 2026): web_live, web_photo, web_addr, web_key
     * - database/upgrade-2026-10-19-agent-website-card.sql. Without them the
     * list works as before (approved = listed, no photo, no address).
     */
    public static function webColumnsAvailable(): bool
    {
        if (self::$webOk === null) {
            try {
                self::$webOk = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_applications'
                        AND COLUMN_NAME IN ('web_live','web_photo','web_addr','web_key')",
                    [], 0
                ) === 4;
            } catch (Throwable $e) {
                self::$webOk = false;
            }
        }
        return self::$webOk;
    }

    /** A partner company's logo (upgrade-2026-10-22-team-portal.sql, 22 Oct 2026). */
    public static function logoReady(): bool
    {
        if (self::$logoOk === null) {
            try {
                self::$logoOk = self::webColumnsAvailable() && (bool) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'logo'",
                    [], 0
                );
            } catch (Throwable $e) {
                self::$logoOk = false;
            }
        }
        return self::$logoOk;
    }

    /**
     * A finger signature: a real PNG / JPEG / WEBP of at most 150 KB, or
     * nothing. Same checks as cleanPhoto(), smaller budget.
     */
    public static function cleanSignature(mixed $sig): ?string
    {
        if (!is_string($sig) || trim($sig) === '' || strlen($sig) > (int) (self::SIG_MAX_BYTES * 4 / 3) + 200) {
            return null;
        }
        $c = self::cleanPhoto($sig);
        if ($c['photo'] === null) {
            return null;
        }
        $bin = base64_decode((string) substr((string) $c['photo'], (int) strpos((string) $c['photo'], ',') + 1), true);
        return ($bin !== false && strlen($bin) <= self::SIG_MAX_BYTES) ? $c['photo'] : null;
    }

    /** "lat,lng[,accuracy]" from the phone -> "27.123456,81.123456,25" or null. */
    public static function cleanGeo(mixed $geo): ?string
    {
        if (!is_string($geo) || preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)(?:\s*,\s*(\d{1,6}(?:\.\d+)?))?\s*$/', $geo, $m) !== 1) {
            return null;
        }
        $lat = (float) $m[1];
        $lng = (float) $m[2];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return null;
        }
        $acc = isset($m[3]) && $m[3] !== '' ? (string) (int) round((float) $m[3]) : '';
        return sprintf('%.6F,%.6F', $lat, $lng) . ($acc !== '' ? ',' . $acc : '');
    }

    /** Tests and long-running scripts: forget the schema answers. */
    public static function resetCache(): void
    {
        self::$tableOk = self::$profileOk = self::$kycOk = self::$extraOk = self::$verifyOk = self::$webOk = null;
    }

    /* =================================================================
     *  Small helpers
     * ================================================================= */

    public static function normaliseType(mixed $type): ?string
    {
        $t = strtolower(trim((string) (is_scalar($type) ? $type : '')));
        return in_array($t, self::TYPES, true) ? $t : null;
    }

    public static function normaliseLang(mixed $lang): string
    {
        $l = strtolower(trim((string) (is_scalar($lang) ? $lang : '')));
        return in_array($l, self::LANGS, true) ? $l : 'ne';
    }

    public static function refFor(int $id): string
    {
        return 'AGR-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * A ref that looks real but names no row — the honeypot's answer. It
     * sits just past the real sequence (a real ref is small and counts up),
     * so a bot cannot tell the two apart by the number.
     */
    public static function fakeRef(): string
    {
        $top = 0;
        if (self::available()) {
            try {
                $top = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM agent_applications', [], 0);
            } catch (Throwable $e) {
                $top = 0;
            }
        }
        return self::refFor($top + random_int(1, 3));
    }

    /** "Organization partner" / "Individual agent" — or the public kind word. */
    public static function typeLabel(string $type): string
    {
        return $type === 'org' ? 'Organization partner' : 'Individual agent';
    }

    /**
     * The public mask. A full mobile number (10 digits or more) keeps the
     * first 2 and last 3 digits: 9812345123 -> 98•••••123. A shorter number
     * (agent accounts may hold 8 or 9 digits) keeps only the last 2, so no
     * number ever shows more than half its digits: 12345678 -> ••••••78.
     * Fewer than 6 digits -> all bullets.
     */
    public static function maskPhone(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        $n = strlen($d);
        if ($n === 0) {
            return '';
        }
        if ($n < 6) {
            return str_repeat('•', $n);
        }
        if ($n < 10) {
            return str_repeat('•', $n - 2) . substr($d, -2);
        }
        return substr($d, 0, 2) . str_repeat('•', $n - 5) . substr($d, -3);
    }

    /**
     * End of the agreement: the signed date + 11 months, clamped to the last
     * day of the month (31 Mar + 11 months is 29 Feb / 28 Feb, not 2 Mar).
     */
    public static function agreementEnd(string $signedIso, int $months = self::TERM_MONTHS): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $signedIso);
        if ($d === false) {
            return '';
        }
        $first = $d->modify('first day of this month')->modify('+' . $months . ' months');
        $day   = min((int) $d->format('j'), (int) $first->format('t'));
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->format('Y-m-d');
    }

    /* =================================================================
     *  Validation
     * ================================================================= */

    /**
     * Invisible characters that only ever serve to disguise text: zero-width
     * space, the direction marks / embeddings / overrides / isolates (U+202E
     * turns "gpj.exe" around), word joiner, BOM, Arabic letter mark. The
     * zero-width (non-)joiners U+200C / U+200D are NOT here — Nepali and
     * Hindi spelling needs them.
     */
    private const INVISIBLE_RE = '/[\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u';

    /**
     * Only the known input names, each a trimmed string of at most 300
     * characters. Booleans (the c73 tick box) become '1' / '0'; arrays and
     * objects are dropped; empty values are not stored. Invisible direction
     * characters are removed everywhere; every field except the addresses
     * (MULTILINE) is folded onto one line.
     *
     * @return array<string, string>
     */
    public static function cleanFields(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }
        $out = [];
        foreach (self::FIELDS as $name) {
            if (!array_key_exists($name, $fields)) {
                continue;
            }
            $v = $fields[$name];
            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            } elseif (is_int($v) || is_float($v)) {
                $v = (string) $v;
            } elseif (!is_string($v)) {
                continue;
            }
            $v = self::oneLine(Security::clean($v, self::FIELD_MAX), in_array($name, self::MULTILINE, true));
            if ($v !== '') {
                $out[$name] = $v;
            }
        }
        return $out;
    }

    /**
     * Strip the invisible direction characters; fold every run of white
     * space (newlines included) into one space — or, for an address, keep
     * the line breaks as plain "\n".
     */
    public static function oneLine(string $v, bool $keepLines = false): string
    {
        $v = preg_replace(self::INVISIBLE_RE, '', $v) ?? '';
        if ($keepLines) {
            $v = preg_replace('/\r\n?|[\x{0085}\x{2028}\x{2029}]/u', "\n", $v) ?? '';
        } else {
            $v = preg_replace('/[\s\x{0085}\x{00A0}\x{2028}\x{2029}]+/u', ' ', $v) ?? '';
        }
        return trim($v);
    }

    /**
     * A photo is kept only when it is a real JPEG / PNG / WEBP of at most
     * 600 KB. Anything else is dropped with a note — never a refusal, the
     * office can take the photo at the counter.
     *
     * @return array{photo: ?string, note: string}  note: '' | 'too_big' | 'not_image'
     */
    public static function cleanPhoto(mixed $photo): array
    {
        if (!is_string($photo) || trim($photo) === '') {
            return ['photo' => null, 'note' => ''];
        }
        // Cheap size guard before decoding: base64 is 4/3 of the bytes.
        if (strlen($photo) > (int) (self::PHOTO_MAX_BYTES * 4 / 3) + 200) {
            return ['photo' => null, 'note' => 'too_big'];
        }
        if (preg_match('~^data:(image/(?:jpeg|jpg|png|webp));base64,([A-Za-z0-9+/=\s]+)$~', trim($photo), $m) !== 1) {
            return ['photo' => null, 'note' => 'not_image'];
        }
        $bin = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
        if ($bin === false || $bin === '') {
            return ['photo' => null, 'note' => 'not_image'];
        }
        if (strlen($bin) > self::PHOTO_MAX_BYTES) {
            return ['photo' => null, 'note' => 'too_big'];
        }
        $info = @getimagesizefromstring($bin);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!in_array($mime, self::PHOTO_MIME, true)) {
            return ['photo' => null, 'note' => 'not_image'];
        }
        // Re-encoded from the checked bytes with the type the bytes really are.
        return ['photo' => 'data:' . $mime . ';base64,' . base64_encode($bin), 'note' => ''];
    }

    /** Field error text in the applicant's language, in the words people use. */
    public static function message(string $key, string $lang): string
    {
        static $m = [
            'required' => [
                'ne' => 'यो भर्न जरुरी छ।',
                'hi' => 'यह भरना ज़रूरी है।',
                'en' => 'Please fill this in.',
                'gu' => 'આ ભરવું જરૂરી છે.',
            ],
            'phone' => [
                'ne' => 'सही मोबाइल नम्बर लेख्नुहोस् (कम्तीमा १० अंक)।',
                'hi' => 'सही मोबाइल नंबर लिखें (कम से कम 10 अंक)।',
                'en' => 'Enter a correct mobile number (at least 10 digits).',
                'gu' => 'સાચો મોબાઇલ નંબર લખો (ઓછામાં ઓછા 10 આંકડા).',
            ],
            'email' => [
                'ne' => 'सही इमेल लेख्नुहोस्, वा खाली छोड्नुहोस्।',
                'hi' => 'सही ईमेल लिखें, या खाली छोड़ दें।',
                'en' => 'Enter a correct email, or leave it empty.',
                'gu' => 'સાચો ઈમેલ લખો, અથવા ખાલી રાખો.',
            ],
            'type' => [
                'ne' => 'सम्झौताको किसिम छान्नुहोस्।',
                'hi' => 'अनुबंध का प्रकार चुनें।',
                'en' => 'Choose the kind of agreement.',
                'gu' => 'કરારનો પ્રકાર પસંદ કરો.',
            ],
            'invalid' => [
                'ne' => 'रातो देखिएका ठाउँ भर्नुहोस्।',
                'hi' => 'लाल निशान वाली जगह भरें।',
                'en' => 'Please fill the boxes marked in red.',
                'gu' => 'લાલ નિશાનીવાળાં ખાનાં ભરો.',
            ],
            'received' => [
                'ne' => 'आवेदन पुग्यो। तपाईंको नम्बर {ref} हो। कार्यालयले छिट्टै फोन गर्छ।',
                'hi' => 'आवेदन मिल गया। आपका नंबर {ref} है। ऑफ़िस जल्दी फ़ोन करेगा।',
                'en' => 'Application received. Your number is {ref}. The office will call you soon.',
                'gu' => 'અરજી મળી ગઈ. તમારો નંબર {ref} છે. ઓફિસ જલદી ફોન કરશે.',
            ],
            // Only for an EXACT resend of the same form (a double tap).
            'duplicate' => [
                'ne' => 'यो आवेदन पहिले नै पुगिसकेको छ। तपाईंको नम्बर {ref} हो। कार्यालयले फोन गर्छ।',
                'hi' => 'यह आवेदन पहले ही मिल चुका है। आपका नंबर {ref} है। ऑफ़िस फ़ोन करेगा।',
                'en' => 'This application has already reached us. Your number is {ref}. The office will call you.',
                'gu' => 'આ અરજી પહેલેથી મળી ગઈ છે. તમારો નંબર {ref} છે. ઓફિસ ફોન કરશે.',
            ],
            'photo' => [
                'ne' => 'फोटो धेरै ठूलो भयो वा मिलेन, त्यसैले राखिएन — कार्यालयमा ल्याउनुहोस्।',
                'hi' => 'फ़ोटो बहुत बड़ी थी या सही नहीं थी, इसलिए नहीं रखी — ऑफ़िस में ले आएँ।',
                'en' => 'The photo was too big or not a picture, so it was not kept — please bring one to the office.',
                'gu' => 'ફોટો બહુ મોટો હતો અથવા બરાબર ન હતો, એટલે રાખ્યો નથી — ઓફિસમાં લઈ આવો.',
            ],
            'busy' => [
                'ne' => 'धेरै पटक पठाइयो। केही बेरपछि फेरि पठाउनुहोस्, वा कार्यालयमा फोन गर्नुहोस्: {phone}',
                'hi' => 'बहुत बार भेजा गया। थोड़ी देर बाद फिर भेजें, या ऑफ़िस को फ़ोन करें: {phone}',
                'en' => 'Sent too many times. Please try again a little later, or call the office: {phone}',
                'gu' => 'ઘણી વાર મોકલાયું. થોડી વાર પછી ફરી મોકલો, અથવા ઓફિસને ફોન કરો: {phone}',
            ],
        ];
        $lang = in_array($lang, self::LANGS, true) ? $lang : 'ne';
        return $m[$key][$lang] ?? ($m[$key]['en'] ?? $key);
    }

    /**
     * Check one posted application.
     *
     * @param array<string, mixed> $in  {type, lang, fields, photo, public_ok}
     * @return array{ok: bool, errors: array<string, string>, clean: array<string, mixed>}
     */
    public static function validate(array $in): array
    {
        $lang   = self::normaliseLang($in['lang'] ?? 'ne');
        $type   = self::normaliseType($in['type'] ?? '');
        $fields = self::cleanFields($in['fields'] ?? []);
        $errors = [];

        if ($type === null) {
            $errors['type'] = self::message('type', $lang);
        }
        foreach (self::REQUIRED[$type ?? 'ind'] as $name) {
            if (($fields[$name] ?? '') === '') {
                $errors[$name] = self::message('required', $lang);
            }
        }
        if ($type === null) {
            // Without a type we cannot know the fourth required field — ask
            // for the three every agreement needs, nothing more.
            unset($errors['p_idno'], $errors['p_addr']);
        }

        // The length is checked on the number as it will be STORED: +91 /
        // +977 / 00 / a leading 0 come off first, so "0012345678" (10 typed
        // digits, 8 real ones) is refused rather than kept as 12345678.
        $phoneRaw = (string) ($fields['p_phone'] ?? '');
        $digits   = Security::digits($phoneRaw, 20);
        $phone    = normalisePhone($digits);
        if ($phoneRaw !== '' && (strlen($phone) < self::PHONE_MIN || strlen($phone) > self::PHONE_MAX)) {
            $errors['p_phone'] = self::message('phone', $lang);
        }

        $emailRaw = (string) ($fields['p_email'] ?? '');
        $email    = $emailRaw !== '' ? Security::email($emailRaw) : '';
        if ($emailRaw !== '' && $email === '') {
            $errors['p_email'] = self::message('email', $lang);
        }

        $photo = self::cleanPhoto($in['photo'] ?? null);
        $doc   = self::cleanPhoto($in['doc'] ?? null);

        $clean = [
            'type'       => $type,
            'lang'       => $lang,
            'fields'     => $fields,
            'name'       => mb_substr((string) ($fields['p_name'] ?? ''), 0, 160, 'UTF-8'),
            'phone'      => $phone,
            'phone_raw'  => mb_substr($phoneRaw, 0, 40, 'UTF-8'),
            'email'      => $email,
            'area'       => mb_substr((string) ($fields['p_area'] ?? ''), 0, 160, 'UTF-8'),
            'public_ok'  => filter_var($in['public_ok'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'photo'      => $photo['photo'],
            'photo_note' => $photo['note'],
            'doc'        => $doc['photo'],
            'signature'  => self::cleanSignature($in['signature'] ?? null),
            'geo'        => self::cleanGeo($in['geo'] ?? null),
        ];

        return ['ok' => $errors === [], 'errors' => $errors, 'clean' => $clean];
    }

    /* =================================================================
     *  Create (an exact resend inside 24 h is a retry; anything else from
     *  the same phone is a new row marked as a repeat)
     * ================================================================= */

    /**
     * Fingerprint of everything one form carries. Two sends match only when
     * the phone, kind, language, every field, the photo and the public-list
     * choice are all the same.
     *
     * @param array<string, mixed> $clean validate()['clean']
     */
    public static function payloadHash(array $clean): string
    {
        $photo = is_string($clean['photo'] ?? null) ? hash('sha256', (string) $clean['photo']) : '';
        $fields = is_array($clean['fields'] ?? null) ? $clean['fields'] : [];
        ksort($fields);
        $parts = [
            (string) ($clean['type'] ?? ''), (string) ($clean['lang'] ?? ''), (string) ($clean['phone'] ?? ''),
            $fields, (int) ($clean['public_ok'] ?? 0), $photo,
        ];
        // The simple form's extras join the fingerprint only when sent, so a
        // form without them hashes exactly as before.
        foreach (['doc', 'signature', 'geo'] as $k) {
            if (is_string($clean[$k] ?? null) && $clean[$k] !== '') {
                $parts[] = $k . ':' . hash('sha256', (string) $clean[$k]);
            }
        }
        return hash('sha256', (string) json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * An exact resend of a form stored in the last 24 h and not rejected —
     * the double tap, or the retry on a slow network. Answering it with ITS
     * ref tells the caller nothing new: only someone who holds the whole
     * form, photo included, can send it.
     *
     * @param array<string, mixed> $clean validate()['clean']
     * @return array{id: int, ref: string}|null
     */
    public static function findRetry(array $clean): ?array
    {
        $row = Database::fetch(
            "SELECT id, ref FROM agent_applications
              WHERE phone = :p AND type = :t AND payload_hash = :h
                AND status <> 'rejected' AND created_at >= :s
              ORDER BY id DESC LIMIT 1",
            [
                'p' => (string) $clean['phone'], 't' => (string) $clean['type'],
                'h' => self::payloadHash($clean), 's' => date('Y-m-d H:i:s', time() - self::DEDUPE_HOURS * 3600),
            ]
        );
        if ($row === null) {
            return null;
        }
        return ['id' => (int) $row['id'], 'ref' => (string) ($row['ref'] ?: self::refFor((int) $row['id']))];
    }

    /**
     * Store a validated application.
     *
     *   an exact resend (findRetry)  -> that row's ref, duplicate = true,
     *                                   nothing stored
     *   anything else                -> a new row. When the same phone +
     *                                   kind already has an open application
     *                                   from the last 24 h, the new row is
     *                                   duplicate_of the first one, so the
     *                                   office sees both side by side (a
     *                                   corrected form, a forgotten photo —
     *                                   or somebody else using that number).
     *
     * repeat_of is for the OFFICE only (the WhatsApp note); the applicant's
     * answer never carries a ref made by an earlier request.
     *
     * @param array<string, mixed> $clean validate()['clean']
     * @return array{id: int, ref: string, duplicate: bool, repeat_of: ?string}
     */
    public static function create(array $clean, string $ip = ''): array
    {
        $retry = self::findRetry($clean);
        if ($retry !== null) {
            return $retry + ['duplicate' => true, 'repeat_of' => null];
        }

        $earlier = Database::fetch(
            "SELECT id, duplicate_of FROM agent_applications
              WHERE phone = :p AND type = :t AND status <> 'rejected' AND created_at >= :s
              ORDER BY id ASC LIMIT 1",
            ['p' => (string) $clean['phone'], 't' => (string) $clean['type'],
             's' => date('Y-m-d H:i:s', time() - self::DEDUPE_HOURS * 3600)]
        );
        $dupOf = $earlier !== null ? (int) ($earlier['duplicate_of'] ?: $earlier['id']) : 0;

        $extra = [];
        if (self::extraColumnsAvailable()) {
            $extra = [
                'doc_photo' => is_string($clean['doc'] ?? null) ? $clean['doc'] : null,
                'signature' => is_string($clean['signature'] ?? null) ? $clean['signature'] : null,
                'geo'       => is_string($clean['geo'] ?? null) ? $clean['geo'] : null,
            ];
        }
        if (is_string($clean['doc_verify'] ?? null) && $clean['doc_verify'] !== '' && self::verifyColumnAvailable()) {
            $extra['doc_verify'] = mb_substr((string) $clean['doc_verify'], 0, 255, 'UTF-8');
        }
        // 19 Oct 2026: the applicant's "show me on the website" tick also
        // lets the office show the photo; nothing is public before approval.
        if (self::webColumnsAvailable()) {
            $extra['web_photo'] = (int) $clean['public_ok'] === 1 && is_string($clean['photo'] ?? null) ? 1 : 0;
            $extra['web_key']   = self::newWebKey();
        }

        $id = Database::insert('agent_applications', $extra + [
            'type'        => (string) $clean['type'],
            'lang'        => (string) $clean['lang'],
            'name'        => (string) $clean['name'],
            'phone'       => (string) $clean['phone'],
            'phone_raw'   => (string) $clean['phone_raw'] !== '' ? (string) $clean['phone_raw'] : null,
            'email'       => (string) $clean['email'] !== '' ? (string) $clean['email'] : null,
            'area'        => (string) $clean['area'] !== '' ? (string) $clean['area'] : null,
            'fields_json' => json_encode($clean['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'photo'       => $clean['photo'],
            'public_ok'   => (int) $clean['public_ok'],
            'payload_hash'=> self::payloadHash($clean),
            'duplicate_of'=> $dupOf > 0 ? $dupOf : null,
            'ip'          => $ip !== '' ? mb_substr($ip, 0, 45) : null,
        ]);
        $ref = self::refFor($id);
        Database::update('agent_applications', ['ref' => $ref], 'id = :id', ['id' => $id]);

        return ['id' => $id, 'ref' => $ref, 'duplicate' => false, 'repeat_of' => $dupOf > 0 ? self::refFor($dupOf) : null];
    }

    /* =================================================================
     *  Office reads
     * ================================================================= */

    /** @return array<string, mixed>|null  one application with fields decoded */
    public static function find(int $id): ?array
    {
        if ($id <= 0 || !self::available()) {
            return null;
        }
        $row = Database::fetch('SELECT * FROM agent_applications WHERE id = :id LIMIT 1', ['id' => $id]);
        if ($row === null) {
            return null;
        }
        $f = json_decode((string) ($row['fields_json'] ?? ''), true);
        $row['fields'] = is_array($f) ? $f : [];
        $row['repeat_of_ref'] = !empty($row['duplicate_of']) ? self::refFor((int) $row['duplicate_of']) : null;
        return $row;
    }

    /**
     * The dashboard list, newest first. Filters: q (name / phone / ref /
     * area), status, type. The photo is NOT selected here — it is only read
     * for the one application the office opens.
     *
     * @param array{q?: string, status?: string, type?: string} $filter
     * @return array<int, array<string, mixed>>
     */
    public static function list(array $filter = [], int $limit = 300): array
    {
        if (!self::available()) {
            return [];
        }
        $where  = ['1 = 1'];
        $params = [];
        $q = trim((string) ($filter['q'] ?? ''));
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $or = ['a.name LIKE :q1', 'a.ref LIKE :q2', 'a.area LIKE :q3'];
            $params['q1'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
            $params['q3'] = '%' . $q . '%';
            if (strlen($digits) >= 3) {
                $or[] = 'a.phone LIKE :q4';
                $params['q4'] = '%' . $digits . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        $status = (string) ($filter['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'a.status = :st';
            $params['st'] = $status;
        } elseif ($status === 'open') {
            $where[] = "a.status NOT IN ('approved','rejected')";
        }
        $type = self::normaliseType($filter['type'] ?? '');
        if ($type !== null) {
            $where[] = 'a.type = :ty';
            $params['ty'] = $type;
        }
        $limit = max(1, min(1000, $limit));

        return Database::fetchAll(
            'SELECT a.id, a.ref, a.type, a.lang, a.name, a.phone, a.email, a.area, a.public_ok, '
                . (self::webColumnsAvailable() ? 'a.web_live, a.web_photo' : '1 AS web_live, 0 AS web_photo') . ',
                    a.status, a.kyc_status, a.agreement_status, a.agreement_signed_at, a.agreement_end,
                    a.admin_id, a.note, a.reviewed_by, a.reviewed_at, a.created_at, a.duplicate_of,
                    (a.photo IS NOT NULL) AS has_photo, ad.full_name AS admin_name, o.ref AS repeat_of_ref
               FROM agent_applications a
               LEFT JOIN admins ad ON ad.id = a.admin_id
               LEFT JOIN agent_applications o ON o.id = a.duplicate_of
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY a.id DESC
              LIMIT ' . $limit,
            $params
        );
    }

    /**
     * Counts for the top cards.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $out = array_fill_keys(self::STATUSES, 0) + [
            'total' => 0, 'kyc_waiting' => 0, 'agreement_waiting' => 0, 'signed' => 0,
        ];
        if (!self::available()) {
            return $out;
        }
        foreach (Database::fetchAll('SELECT status, COUNT(*) AS n FROM agent_applications GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
            $out['total'] += (int) $r['n'];
        }
        $row = Database::fetch(
            "SELECT
                SUM(CASE WHEN status <> 'rejected' AND kyc_status = 'submitted' THEN 1 ELSE 0 END) AS kyc_waiting,
                SUM(CASE WHEN status <> 'rejected' AND agreement_status IN ('none','sent','expired') THEN 1 ELSE 0 END) AS agreement_waiting,
                SUM(CASE WHEN agreement_status = 'signed' OR status = 'approved' THEN 1 ELSE 0 END) AS signed
               FROM agent_applications"
        ) ?? [];
        foreach (['kyc_waiting', 'agreement_waiting', 'signed'] as $k) {
            $out[$k] = (int) ($row[$k] ?? 0);
        }
        return $out;
    }

    /**
     * Every agent account (admins.role = 'agent') with its KYC and agreement
     * state — the dashboard's second tab. Works before either migration: a
     * missing column reads as its default.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function agents(): array
    {
        $kyc  = self::kycColumnAvailable() ? 'p.kyc_status' : "'none'";
        $prof = self::profileColumnsAvailable();
        $agr  = $prof ? 'p.agreement_status, p.agreement_signed_at, p.agreement_end, p.public_listing, p.public_phone'
                      : "'none' AS agreement_status, NULL AS agreement_signed_at, NULL AS agreement_end, 0 AS public_listing, 0 AS public_phone";
        $rows = Database::fetchAll(
            "SELECT a.id, a.username, a.full_name, a.phone, a.is_active, a.created_at, " . (self::available() ? "(SELECT MAX(x.id) FROM agent_applications x WHERE x.admin_id = a.id)" : "NULL") . " AS card_id,
                    p.display_phone, p.counter_name, p.agent_kind, p.contact_person,
                    COALESCE($kyc, 'none') AS kyc_status, $agr
               FROM admins a
               LEFT JOIN admin_profiles p ON p.admin_id = a.id
              WHERE a.role = 'agent'
              ORDER BY a.is_active DESC, a.full_name ASC, a.id ASC"
        );
        foreach ($rows as &$r) {
            $r['kind']             = ((string) ($r['agent_kind'] ?? 'org')) === 'person' ? 'agent' : 'partner';
            $r['kyc_status']       = in_array((string) $r['kyc_status'], self::KYC, true) ? (string) $r['kyc_status'] : 'none';
            $r['agreement_status'] = in_array((string) ($r['agreement_status'] ?? ''), self::AGREEMENT, true) ? (string) $r['agreement_status'] : 'none';
            $r['public_listing']   = (int) ($r['public_listing'] ?? 0);   // opt-in: no row = not listed
            $r['public_phone']     = (int) ($r['public_phone'] ?? 0);
            $r['phone_any']        = (string) ($r['phone'] ?: ($r['display_phone'] ?? ''));
        }
        unset($r);
        return $rows;
    }

    /* =================================================================
     *  Office writes
     * ================================================================= */

    /**
     * Change an application: status, kyc_status, agreement_status (+ the
     * signed date — the end date follows as signed + 11 months), note, and
     * the linked agent account. Unknown values are ignored, not stored.
     * Audited. Linking an account copies the agreement state onto that
     * agent's profile. The applicant's public-list choices (full phone,
     * listed once approved) are copied ONLY when the account's phone is the
     * applicant's phone: the choice was made for that number, and a wrong
     * pick in the dropdown must never publish somebody else's number.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed> the fields that changed (new values)
     */
    public static function update(int $id, array $in, int $by): array
    {
        $app = self::find($id);
        if ($app === null) {
            throw new AgreementError('Application not found.');
        }
        $set = [];

        $st = (string) ($in['status'] ?? '');
        if (in_array($st, self::STATUSES, true) && $st !== $app['status']) {
            $set['status'] = $st;
        }
        $kyc = (string) ($in['kyc_status'] ?? '');
        if (in_array($kyc, self::KYC, true) && $kyc !== $app['kyc_status']) {
            $set['kyc_status'] = $kyc;
        }
        $agr = (string) ($in['agreement_status'] ?? '');
        if (in_array($agr, self::AGREEMENT, true)) {
            $dates = self::agreementDates($agr, (string) ($in['agreement_signed_at'] ?? ''), (string) ($app['agreement_signed_at'] ?? ''));
            if ($agr !== $app['agreement_status']) {
                $set['agreement_status'] = $agr;
            }
            if ($dates['signed'] !== (string) ($app['agreement_signed_at'] ?? '')) {
                $set['agreement_signed_at'] = $dates['signed'] !== '' ? $dates['signed'] : null;
            }
            if ($dates['end'] !== (string) ($app['agreement_end'] ?? '')) {
                $set['agreement_end'] = $dates['end'] !== '' ? $dates['end'] : null;
            }
        }
        if (array_key_exists('note', $in)) {
            $note = Security::clean($in['note'], 255);
            if ($note !== (string) ($app['note'] ?? '')) {
                $set['note'] = $note !== '' ? $note : null;
            }
        }
        $linkNow = false;
        if (array_key_exists('admin_id', $in)) {
            $aid = (int) $in['admin_id'];
            if ($aid > 0) {
                $ok = Database::exists("SELECT 1 FROM admins WHERE id = :id AND role = 'agent' LIMIT 1", ['id' => $aid]);
                if (!$ok) {
                    throw new AgreementError('That is not an agent account.');
                }
            }
            $cur = (int) ($app['admin_id'] ?? 0);
            if ($aid !== $cur) {
                $set['admin_id'] = $aid > 0 ? $aid : null;
                $linkNow = $aid > 0;
            }
        }

        if ($set === []) {
            return [];
        }
        $before = array_intersect_key($app, $set);
        $set['reviewed_by'] = $by > 0 ? $by : null;
        $set['reviewed_at'] = date('Y-m-d H:i:s');
        Database::update('agent_applications', $set, 'id = :id', ['id' => $id]);

        // Keep the linked agent's profile in step: on the first link, on
        // every agreement change while linked, and on approval. A link never
        // DOWNGRADES the agent's own agreement: an application still at "not
        // sent" leaves the agreement on the profile as it is.
        // The public-list choices follow only when the account's own number
        // (the one the public list would show) IS the applicant's number:
        //   full phone  <- the applicant's "may show my number" tick
        //   listed      <- on, once the application is approved (an approved
        //                  application is on the list; linking must not make
        //                  the person vanish from it)
        // Otherwise they stay as they are — off unless the office ticks them.
        $linked = array_key_exists('admin_id', $set) ? (int) ($set['admin_id'] ?? 0) : (int) ($app['admin_id'] ?? 0);
        $agrChanged  = isset($set['agreement_status']) || array_key_exists('agreement_signed_at', $set);
        $nowApproved = ($set['status'] ?? '') === 'approved';
        if ($linked > 0 && ($linkNow || $agrChanged || $nowApproved)) {
            $after = array_merge($app, $set);
            $prof  = [];
            if ($agrChanged || ($linkNow && (string) $after['agreement_status'] !== 'none')) {
                $prof['agreement_status']    = (string) $after['agreement_status'];
                $prof['agreement_signed_at'] = (string) ($after['agreement_signed_at'] ?? '');
            }
            if (($linkNow || $nowApproved) && self::accountPublicPhone($linked) === normalisePhone((string) $app['phone'])
                && normalisePhone((string) $app['phone']) !== '') {
                if ($linkNow) {
                    $prof['public_phone'] = (int) $app['public_ok'];
                }
                if ((string) $after['status'] === 'approved') {
                    $prof['public_listing'] = 1;
                }
            }
            if ($prof !== []) {
                try {
                    self::setAgentProfile($linked, $prof, $by);
                } catch (Throwable $e) {
                    Logger::warning('Agreement: linked profile not updated: ' . $e->getMessage());
                }
            }
        }

        $changed = $set;
        unset($changed['reviewed_by'], $changed['reviewed_at']);
        Logger::audit('agreement_app.update', 'agent_application', (string) ($app['ref'] ?: self::refFor($id)),
            $before, $changed, 'agreements dashboard');
        return $changed;
    }

    /* =================================================================
     *  The website card + office add / edit / remove (19 Oct 2026, owner:
     *  "admin le add remove edit manage garna milos ... name ra photo
     *  sahit address rakher live gardinu ... kyc garda bank account ni")
     * ================================================================= */

    /** The KYC / bank fields the office may type or correct (kept in fields_json). */
    public const OFFICE_KYC_FIELDS = ['p_addr', 'p_idtype', 'p_idno', 'p_pan', 'b_name', 'b_bank', 'b_acc', 'b_ifsc', 'b_upi'];

    public static function newWebKey(): string
    {
        return bin2hex(random_bytes(10));
    }

    /**
     * A photo the office uploaded (one $_FILES entry): any JPEG / PNG / WEBP
     * the phone or PC gives, shrunk to at most 640 px and stored as a JPEG
     * data: URI like the online form's photo. null = no file chosen.
     *
     * @param array<string, mixed>|null $file
     */
    public static function photoFromUpload(?array $file): ?string
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new AgreementError('The photo did not arrive — please choose it again.');
        }
        if ((int) ($file['size'] ?? 0) > 12 * 1024 * 1024) {
            throw new AgreementError('That photo is over 12 MB — please choose a smaller one.');
        }
        $bin = (string) file_get_contents((string) $file['tmp_name']);
        return self::shrinkPhoto($bin);
    }

    /** Raw image bytes -> a JPEG data: URI of at most 640 px (GD), or an AgreementError. */
    public static function shrinkPhoto(string $bin): string
    {
        $info = @getimagesizefromstring($bin);
        if (!is_array($info) || !in_array((string) ($info['mime'] ?? ''), self::PHOTO_MIME, true)) {
            throw new AgreementError('That file is not a photo (JPG, PNG or WEBP).');
        }
        if (!function_exists('imagecreatefromstring')) {
            $c = self::cleanPhoto('data:' . $info['mime'] . ';base64,' . base64_encode($bin));
            if ($c['photo'] === null) {
                throw new AgreementError('That photo is too big — please choose a smaller one.');
            }
            return $c['photo'];
        }
        $src = @imagecreatefromstring($bin);
        if ($src === false) {
            throw new AgreementError('That photo could not be opened.');
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, 640 / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($dst, null, 84);
        $jpg = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return 'data:image/jpeg;base64,' . base64_encode($jpg);
    }

    /**
     * A mobile the office typed: "+977 98…", "98…", "0091 …". Returns
     * [normalised digits, what was typed (kept when it carried a country)].
     *
     * @return array{0: string, 1: ?string}
     */
    private static function officePhone(string $typed): array
    {
        $typed  = trim($typed);
        $digits = normalisePhone($typed);
        if (strlen($digits) < self::PHONE_MIN || strlen($digits) > self::PHONE_MAX || isPlaceholderPhone($digits)) {
            throw new AgreementError('Please type a real mobile number (10 digits, with +91 / +977 if you know it).');
        }
        return [$digits, resolvePhoneCountry('', $typed) !== '' ? mb_substr($typed, 0, 30) : null];
    }

    /**
     * Edit an application's website card and (with KYC rights) its KYC and
     * bank details. Keys read from $in: name, phone, area, web_addr, email,
     * public_ok, web_photo, web_live, photo (a data: URI already shrunk),
     * and OFFICE_KYC_FIELDS when $kyc is true.
     *
     * @param array<string, mixed> $in
     * @return array<int, string> the names of what changed
     */
    public static function saveCard(int $id, array $in, int $by, bool $kyc): array
    {
        $app = self::find($id);
        if ($app === null) {
            throw new AgreementError('Application not found.');
        }
        $set = [];
        $name = self::oneLine(Security::clean((string) ($in['name'] ?? $app['name']), 160));
        if ($name === '') {
            throw new AgreementError('The name cannot be empty.');
        }
        if ($name !== (string) $app['name']) {
            $set['name'] = $name;
        }
        if (isset($in['phone']) && trim((string) $in['phone']) !== '') {
            [$digits, $raw] = self::officePhone((string) $in['phone']);
            if ($digits !== (string) $app['phone']) {
                $set['phone'] = $digits;
            }
            if ($raw !== null && $raw !== (string) ($app['phone_raw'] ?? '')) {
                $set['phone_raw'] = $raw;
            }
        }
        foreach (['area' => 160, 'email' => 160] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $v = self::oneLine(Security::clean((string) $in[$k], $max));
                if ($v !== (string) ($app[$k] ?? '')) {
                    $set[$k] = $v !== '' ? $v : null;
                }
            }
        }
        if (array_key_exists('public_ok', $in)) {
            $v = filter_var($in['public_ok'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            if ($v !== (int) $app['public_ok']) {
                $set['public_ok'] = $v;
            }
        }
        if (is_string($in['photo'] ?? null) && $in['photo'] !== '') {
            $c = self::cleanPhoto($in['photo']);
            if ($c['photo'] === null) {
                throw new AgreementError('That photo could not be used — please choose another.');
            }
            $set['photo'] = $c['photo'];
        }
        // 22 Oct 2026: a partner company's logo - public by nature, so no "show" switch.
        if (is_string($in['logo'] ?? null) && $in['logo'] !== '' && self::logoReady()) {
            $c = self::cleanPhoto($in['logo']);
            if ($c['photo'] === null) {
                throw new AgreementError('That logo could not be used — please choose another.');
            }
            $set['logo'] = $c['photo'];
        }
        if (self::webColumnsAvailable()) {
            if (array_key_exists('web_addr', $in)) {
                $v = self::oneLine(Security::clean((string) $in['web_addr'], 200));
                if ($v !== (string) ($app['web_addr'] ?? '')) {
                    $set['web_addr'] = $v !== '' ? $v : null;
                }
            }
            foreach (['web_photo', 'web_live'] as $k) {
                if (array_key_exists($k, $in)) {
                    $v = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                    if ($v !== (int) ($app[$k] ?? 0)) {
                        $set[$k] = $v;
                    }
                }
            }
            if (empty($app['web_key'])) {
                $set['web_key'] = self::newWebKey();
            }
        }
        $fieldsChanged = [];
        if ($kyc) {
            $f = is_array($app['fields'] ?? null) ? $app['fields'] : [];
            foreach (self::OFFICE_KYC_FIELDS as $k) {
                if (!array_key_exists($k, $in)) {
                    continue;
                }
                $v = $k === 'p_addr'
                    ? self::oneLine(Security::clean((string) $in[$k], self::FIELD_MAX), true)
                    : self::oneLine(Security::clean((string) $in[$k], 80));
                if ($k === 'p_idtype' && $v !== '' && !isset(self::ID_TYPES[(int) $v])) {
                    $v = '';
                }
                if ($k === 'b_ifsc') {
                    $v = strtoupper(str_replace(' ', '', $v));
                }
                if ($v !== (string) ($f[$k] ?? '')) {
                    if ($v === '') {
                        unset($f[$k]);
                    } else {
                        $f[$k] = $v;
                    }
                    $fieldsChanged[] = $k;
                }
            }
            if (isset($set['name'])) {
                $f['p_name'] = $set['name'];
            }
            if ($fieldsChanged !== [] || isset($set['name'])) {
                $set['fields_json'] = json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            }
            // Bank details typed in = KYC handed over; the office still marks it verified by hand.
            if ($fieldsChanged !== [] && (string) $app['kyc_status'] === 'none') {
                $set['kyc_status'] = 'submitted';
            }
        }
        if ($set === []) {
            return [];
        }
        $set['reviewed_by'] = $by > 0 ? $by : null;
        $set['reviewed_at'] = date('Y-m-d H:i:s');
        Database::update('agent_applications', $set, 'id = :id', ['id' => $id]);

        // Only the names of what changed go to the log — never ID or bank numbers.
        $changed = array_values(array_diff(array_keys($set), ['reviewed_by', 'reviewed_at', 'fields_json', 'web_key']));
        $changed = array_merge($changed, $fieldsChanged);
        Logger::audit('agreement_app.card', 'agent_application', (string) ($app['ref'] ?: self::refFor($id)),
            null, ['changed' => implode(', ', $changed)], 'agreements dashboard (by admin #' . $by . ')');
        return $changed;
    }

    /**
     * The office adds an agent by hand (walked into the office, phoned in).
     * Goes straight to Approved, so with "live" ticked the card is on the
     * website at once.
     *
     * @param array<string, mixed> $in name, phone, type, area, web_addr, email, photo, public_ok,
     *                                 web_photo, web_live, kyc_status + OFFICE_KYC_FIELDS
     * @return array{id: int, ref: string}
     */
    public static function adminCreate(array $in, int $by, bool $kyc): array
    {
        if (!self::available()) {
            throw new AgreementError('Run database/upgrade-2026-09-27-agent-applications.sql first.');
        }
        $name = self::oneLine(Security::clean((string) ($in['name'] ?? ''), 160));
        if ($name === '') {
            throw new AgreementError('Please type the agent\'s name.');
        }
        [$digits, $raw] = self::officePhone((string) ($in['phone'] ?? ''));
        $type = self::normaliseType($in['type'] ?? 'ind') ?? 'ind';
        $kycSt = (string) ($in['kyc_status'] ?? 'none');
        $row = [
            'type'         => $type,
            'lang'         => 'en',
            'name'         => $name,
            'phone'        => $digits,
            'phone_raw'    => $raw,
            'fields_json'  => json_encode(['p_name' => $name, 'p_phone' => $digits, 'p_occ' => 'Added by the office'], JSON_UNESCAPED_UNICODE) ?: '{}',
            'public_ok'    => 0,
            'status'       => 'approved',
            'kyc_status'   => in_array($kycSt, self::KYC, true) ? $kycSt : 'none',
            'payload_hash' => hash('sha256', 'office|' . $digits . '|' . microtime(true) . '|' . random_int(0, PHP_INT_MAX)),
            'reviewed_by'  => $by > 0 ? $by : null,
            'reviewed_at'  => date('Y-m-d H:i:s'),
        ];
        $id = Database::insert('agent_applications', $row);
        $ref = self::refFor($id);
        Database::update('agent_applications', ['ref' => $ref], 'id = :id', ['id' => $id]);
        Logger::audit('agreement_app.office_add', 'agent_application', $ref, null,
            ['name' => $name, 'phone' => $digits], 'agreements dashboard (by admin #' . $by . ')');
        // Everything else (area, address, photo, website switches, KYC) through the one edit path.
        unset($in['name'], $in['phone']);
        self::saveCard($id, $in, $by, $kyc);
        return ['id' => $id, 'ref' => $ref];
    }

    /**
     * One agent list (19 Oct 2026, owner: "aru agent list haru ni yesma
     * haldinu paryo, sabai agent eutai hun"). Every agent ACCOUNT that has no
     * application yet gets one here: Approved, linked to the account
     * (admin_id), filled from the account and its profile - name, mobile,
     * area, address, KYC status + ID, payout account, agreement - and live
     * on the website with the full number. From then on the card on Admin ->
     * Agreements is what the website shows. Safe to run again: an account
     * that already has an application is left alone.
     *
     * @param array<int, int> $hide account ids imported but NOT put live (the company's own login)
     * @return array{made: array<int, string>, skipped: int}
     */
    public static function importAgents(int $by, array $hide = []): array
    {
        if (!self::available()) {
            throw new AgreementError('Run database/upgrade-2026-09-27-agent-applications.sql first.');
        }
        $prof = self::profileColumnsAvailable();
        $rows = Database::fetchAll(
            "SELECT a.id, a.username, a.full_name, a.phone, a.email, a.is_active,
                    p.display_phone, p.whatsapp, p.country_code, p.counter_name, p.agent_kind, p.address,
                    p.id_type, p.id_number, p.photo_path, p.payout_method, p.payout_account,
                    COALESCE(p.kyc_status, 'none') AS kyc_status"
                . ($prof ? ', p.agreement_status, p.agreement_signed_at, p.agreement_end' : '') . "
               FROM admins a
               LEFT JOIN admin_profiles p ON p.admin_id = a.id
              WHERE a.role = 'agent'
                AND NOT EXISTS (SELECT 1 FROM agent_applications x WHERE x.admin_id = a.id)
              ORDER BY a.id ASC"
        );
        $made = [];
        $skipped = 0;
        $web = self::webColumnsAvailable();
        foreach ($rows as $r) {
            $name = self::oneLine(trim((string) ($r['full_name'] ?? '')));
            // A number typed as the name: use the login name until the office corrects it.
            if ($name === '' || preg_match('/^[\d\s+\-]+$/', $name) === 1) {
                $name = (string) $r['username'];
            }
            $typed = '';
            foreach (['phone', 'display_phone', 'whatsapp'] as $k) {
                $d = normalisePhone((string) ($r[$k] ?? ''));
                if (strlen($d) >= self::PHONE_MIN && !isPlaceholderPhone($d)) {
                    $typed = (string) $r[$k];
                    break;
                }
            }
            $digits = $typed !== '' ? normalisePhone($typed) : '';
            $cc = preg_replace('/\D/', '', (string) ($r['country_code'] ?? '')) ?? '';
            $raw = resolvePhoneCountry('', $typed) !== '' ? $typed
                : (in_array($cc, ['91', '977'], true) && strlen($digits) === 10 ? '+' . $cc . ' ' . $digits : null);

            $fields = ['p_name' => $name, 'p_phone' => $digits, 'p_occ' => 'Agent account ' . $r['username']];
            if (trim((string) ($r['address'] ?? '')) !== '') {
                $fields['p_addr'] = self::oneLine((string) $r['address'], true);
            }
            if (trim((string) ($r['id_number'] ?? '')) !== '') {
                $fields['p_idno'] = self::oneLine((string) $r['id_number']);
                $t = array_search(strtolower(trim((string) $r['id_type'])), array_map('strtolower', self::ID_TYPES), true);
                if ($t !== false) {
                    $fields['p_idtype'] = (string) $t;
                }
            }
            if (trim((string) ($r['payout_account'] ?? '')) !== '') {
                $isBank = stripos((string) $r['payout_method'], 'bank') !== false;
                $fields[$isBank ? 'b_acc' : 'b_upi'] = self::oneLine((string) $r['payout_account']);
                if (trim((string) $r['payout_method']) !== '') {
                    $fields['b_bank'] = self::oneLine((string) $r['payout_method']);
                }
            }
            $photo = null;
            $rel = trim((string) ($r['photo_path'] ?? ''));
            if ($rel !== '' && defined('UPLOAD_PATH')) {
                $abs = realpath(UPLOAD_PATH . '/' . $rel);
                $base = realpath(UPLOAD_PATH);
                if ($abs !== false && $base !== false && str_starts_with($abs, $base) && is_file($abs)) {
                    try {
                        $photo = self::shrinkPhoto((string) file_get_contents($abs));
                    } catch (Throwable $e) {
                        $photo = null;
                    }
                }
            }
            $agr = in_array((string) ($r['agreement_status'] ?? ''), self::AGREEMENT, true) ? (string) $r['agreement_status'] : 'none';
            $row = [
                'type'                => (string) ($r['agent_kind'] ?? 'person') === 'org' ? 'org' : 'ind',
                'lang'                => 'en',
                'name'                => mb_substr($name, 0, 160, 'UTF-8'),
                'phone'               => $digits,
                'phone_raw'           => $raw,
                'email'               => trim((string) ($r['email'] ?? '')) !== '' ? mb_substr((string) $r['email'], 0, 191) : null,
                'area'                => trim((string) ($r['counter_name'] ?? '')) !== '' ? mb_substr((string) $r['counter_name'], 0, 160, 'UTF-8') : null,
                'fields_json'         => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'photo'               => $photo,
                'public_ok'           => 1,
                'status'              => 'approved',
                'kyc_status'          => in_array((string) $r['kyc_status'], self::KYC, true) ? (string) $r['kyc_status'] : 'none',
                'agreement_status'    => $agr,
                'agreement_signed_at' => $agr !== 'none' && !empty($r['agreement_signed_at']) ? $r['agreement_signed_at'] : null,
                'agreement_end'       => $agr !== 'none' && !empty($r['agreement_end']) ? $r['agreement_end'] : null,
                'admin_id'            => (int) $r['id'],
                'note'                => 'Brought in from the agent accounts list',
                'payload_hash'        => hash('sha256', 'account|' . $r['id'] . '|' . microtime(true) . '|' . random_int(0, PHP_INT_MAX)),
                'reviewed_by'         => $by > 0 ? $by : null,
                'reviewed_at'         => date('Y-m-d H:i:s'),
            ];
            if ($web) {
                $row['web_live']  = (int) $r['is_active'] === 1 && !in_array((int) $r['id'], $hide, true) ? 1 : 0;
                $row['web_photo'] = $photo !== null ? 1 : 0;
                $row['web_addr']  = trim((string) ($r['address'] ?? '')) !== '' ? mb_substr(self::oneLine((string) $r['address']), 0, 200, 'UTF-8') : null;
                $row['web_key']   = self::newWebKey();
            }
            $id = Database::insert('agent_applications', $row);
            $ref = self::refFor($id);
            Database::update('agent_applications', ['ref' => $ref], 'id = :id', ['id' => $id]);
            $made[(int) $r['id']] = $ref;
        }
        $skipped = (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE role = 'agent'", [], 0) - count($made);
        if ($made !== []) {
            Logger::audit('agreement_app.import_accounts', 'agent_application', count($made) . ' agents', null,
                ['refs' => implode(', ', $made)], 'agreements dashboard (by admin #' . $by . ')');
        }
        return ['made' => $made, 'skipped' => $skipped];
    }

    /** Remove an application for good (a test, a duplicate, a person who left). Audited. */
    public static function remove(int $id, int $by): string
    {
        $app = self::find($id);
        if ($app === null) {
            throw new AgreementError('Application not found.');
        }
        $ref = (string) ($app['ref'] ?: self::refFor($id));
        Database::run('DELETE FROM agent_applications WHERE id = :id', ['id' => $id]);
        Logger::audit('agreement_app.delete', 'agent_application', $ref,
            ['name' => (string) $app['name'], 'phone' => (string) $app['phone'], 'status' => (string) $app['status']],
            null, 'agreements dashboard (by admin #' . $by . ')');
        return $ref;
    }

    /** Is this application's card on the website right now? */
    public static function isLive(array $app): bool
    {
        return (string) ($app['status'] ?? '') === 'approved'
            && (!self::webColumnsAvailable() || (int) ($app['web_live'] ?? 1) === 1)
            && trim((string) ($app['name'] ?? '')) !== '' && (string) ($app['phone'] ?? '') !== '';
    }

    /**
     * The photo behind /api/agent-photo.php?k=… — only for an approved card
     * that is live and whose photo the office allowed. [mime, bytes] or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function publicPhoto(string $key, string $which = 'photo'): ?array
    {
        if (preg_match('/^[a-f0-9]{20}$/', $key) !== 1 || !self::available() || !self::webColumnsAvailable()) {
            return null;
        }
        if ($which === 'logo') {
            // 22 Oct 2026: a live partner company's logo
            $photo = self::logoReady() ? Database::scalar(
                "SELECT logo FROM agent_applications
                  WHERE web_key = :k AND type = 'org' AND status = 'approved' AND web_live = 1 AND logo IS NOT NULL
                  LIMIT 1",
                ['k' => $key], null
            ) : null;
        } else {
            $photo = Database::scalar(
                "SELECT photo FROM agent_applications
                  WHERE web_key = :k AND status = 'approved' AND web_live = 1 AND web_photo = 1 AND photo IS NOT NULL
                  LIMIT 1",
                ['k' => $key], null
            );
        }
        if (!is_string($photo) || preg_match('~^data:(image/(?:jpeg|png|webp));base64,(.+)$~s', $photo, $m) !== 1) {
            return null;
        }
        $bin = base64_decode($m[2], true);
        return $bin === false || $bin === '' ? null : [$m[1], $bin];
    }

    /**
     * Agreement / public-list state of an existing agent account. Creates
     * the admin_profiles row when the agent never had one.
     *
     * @param array<string, mixed> $in agreement_status, agreement_signed_at, public_listing, public_phone
     */
    public static function setAgentProfile(int $adminId, array $in, int $by): array
    {
        if (!self::profileColumnsAvailable()) {
            throw new AgreementError('Run database/upgrade-2026-09-27-agent-applications.sql first.');
        }
        $a = Database::fetch("SELECT id, username FROM admins WHERE id = :id AND role = 'agent' LIMIT 1", ['id' => $adminId]);
        if ($a === null) {
            throw new AgreementError('Agent not found.');
        }
        // No profile row yet = not on the public list (the list is opt-in).
        $cur = Database::fetch(
            'SELECT agreement_status, agreement_signed_at, agreement_end, public_listing, public_phone
               FROM admin_profiles WHERE admin_id = :id LIMIT 1',
            ['id' => $adminId]
        ) ?? ['agreement_status' => 'none', 'agreement_signed_at' => null, 'agreement_end' => null, 'public_listing' => 0, 'public_phone' => 0];

        $new = [
            'agreement_status'    => (string) $cur['agreement_status'],
            'agreement_signed_at' => (string) ($cur['agreement_signed_at'] ?? ''),
            'agreement_end'       => (string) ($cur['agreement_end'] ?? ''),
            'public_listing'      => (int) $cur['public_listing'],
            'public_phone'        => (int) $cur['public_phone'],
        ];
        $agr = (string) ($in['agreement_status'] ?? '');
        if (in_array($agr, self::AGREEMENT, true)) {
            $dates = self::agreementDates($agr, (string) ($in['agreement_signed_at'] ?? ''), $new['agreement_signed_at']);
            $new['agreement_status']    = $agr;
            $new['agreement_signed_at'] = $dates['signed'];
            $new['agreement_end']       = $dates['end'];
        }
        foreach (['public_listing', 'public_phone'] as $k) {
            if (array_key_exists($k, $in)) {
                $new[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        $old = [
            'agreement_status' => (string) $cur['agreement_status'], 'agreement_signed_at' => (string) ($cur['agreement_signed_at'] ?? ''),
            'agreement_end' => (string) ($cur['agreement_end'] ?? ''), 'public_listing' => (int) $cur['public_listing'], 'public_phone' => (int) $cur['public_phone'],
        ];
        if ($new === $old) {
            return [];
        }
        Database::run(
            'INSERT INTO admin_profiles (admin_id, agreement_status, agreement_signed_at, agreement_end, public_listing, public_phone)
                  VALUES (:id, :st, :sg, :en, :pl, :pp)
             ON DUPLICATE KEY UPDATE agreement_status = :st2, agreement_signed_at = :sg2, agreement_end = :en2,
                                     public_listing = :pl2, public_phone = :pp2',
            [
                'id' => $adminId,
                'st' => $new['agreement_status'], 'sg' => $new['agreement_signed_at'] ?: null, 'en' => $new['agreement_end'] ?: null,
                'pl' => $new['public_listing'], 'pp' => $new['public_phone'],
                'st2' => $new['agreement_status'], 'sg2' => $new['agreement_signed_at'] ?: null, 'en2' => $new['agreement_end'] ?: null,
                'pl2' => $new['public_listing'], 'pp2' => $new['public_phone'],
            ]
        );
        Logger::audit('agent.agreement_update', 'admin', (string) $a['username'], $old, $new,
            'agreements dashboard (by admin #' . $by . ')');
        return $new;
    }

    /**
     * The number the public list would show for an agent account (its login
     * phone, else the profile's display phone), normalised; '' when none.
     */
    public static function accountPublicPhone(int $adminId): string
    {
        $acc = Database::fetch(
            'SELECT a.phone, p.display_phone
               FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id
              WHERE a.id = :id LIMIT 1',
            ['id' => $adminId]
        );
        if ($acc === null) {
            return '';
        }
        return normalisePhone((string) (($acc['phone'] ?? '') ?: ($acc['display_phone'] ?? '')));
    }

    /**
     * The dates that go with an agreement status.
     *   signed   -> the given date (or the one already on file, or today);
     *               end = signed + 11 months
     *   sent     -> no dates yet
     *   expired  -> dates kept as they were
     *   none     -> dates cleared
     *
     * @return array{signed: string, end: string}
     */
    public static function agreementDates(string $status, string $given, string $onFile): array
    {
        $given  = trim($given);
        $onFile = trim($onFile);
        $valid  = static fn(string $d): bool => $d !== '' && Security::isValidDate($d);
        switch ($status) {
            case 'signed':
                $signed = $valid($given) ? $given : ($valid($onFile) ? $onFile : date('Y-m-d'));
                return ['signed' => $signed, 'end' => self::agreementEnd($signed)];
            case 'expired':
                $signed = $valid($given) ? $given : ($valid($onFile) ? $onFile : '');
                return ['signed' => $signed, 'end' => $signed !== '' ? self::agreementEnd($signed) : ''];
            default:
                return ['signed' => '', 'end' => ''];
        }
    }

    /* =================================================================
     *  The public list
     * ================================================================= */

    /**
     * Approved applications (not yet linked to an account — once linked,
     * the account row speaks for them) and active agent accounts the office
     * has ticked "Listed" (opt-in: an account with no choice made is NOT on
     * the list). Someone with no phone number at all is left off — the list
     * is names AND numbers. Only the keys below ever leave this method.
     *
     * @return array<int, array{name: string, kind: string, area: string, phone: string, verified: bool, signed: bool, addr: string, photo: string, wa: string}>
     */
    public static function directory(): array
    {
        $today = date('Y-m-d');
        $items = [];
        $isSigned = static fn(string $st, ?string $end): bool =>
            $st === 'signed' && ($end === null || $end === '' || $end >= $today);
        $web = self::available() && self::webColumnsAvailable();
        // The WhatsApp / call number with its country code: what the person
        // typed decides India or Nepal; a bare 10-digit number reads as India.
        $intl = static function (string $digits, string $raw): string {
            $cc = countryDialCode(resolvePhoneCountry('', $raw));
            if (strlen($digits) === 10) {
                return ($cc !== '' ? $cc : '91') . $digits;
            }
            return $digits;
        };
        $photoUrl = static fn(array $r): string =>
            $web && (int) ($r['web_photo'] ?? 0) === 1 && (int) ($r['has_photo'] ?? 0) === 1 && !empty($r['web_key'])
                ? '/api/agent-photo.php?k=' . rawurlencode((string) $r['web_key']) : '';
        // 22 Oct 2026: a partner company's logo (no consent switch - it is the company's public mark)
        $logo = self::logoReady();
        $logoUrl = static fn(array $r): string =>
            $logo && (string) $r['type'] === 'org' && (int) ($r['has_logo'] ?? 0) === 1 && !empty($r['web_key'])
                ? '/api/agent-photo.php?k=' . rawurlencode((string) $r['web_key']) . '&v=logo' : '';

        // 19 Oct 2026: one list. Every approved application is a card - also
        // one linked to an agent account (the office edits name, number,
        // photo and address on the card). An account with a card is shown
        // through the card only; a switched-off account's card is not shown.
        $linkedCard = [];
        if (self::available()) {
            $webSel = $web ? 'x.web_live, x.web_photo, x.web_addr, x.web_key' : '1 AS web_live, 0 AS web_photo, NULL AS web_addr, NULL AS web_key';
            $webSel .= $logo ? ', (x.logo IS NOT NULL) AS has_logo' : ', 0 AS has_logo';
            $apps = Database::fetchAll(
                "SELECT x.type, x.name, x.phone, x.phone_raw, x.area, x.public_ok, x.kyc_status, x.agreement_status, x.agreement_end, x.admin_id,
                        (x.photo IS NOT NULL) AS has_photo, ad.is_active AS acc_active, $webSel
                   FROM agent_applications x
                   LEFT JOIN admins ad ON ad.id = x.admin_id
                  WHERE x.status = 'approved'
                  ORDER BY x.id ASC"
            );
            foreach ($apps as $r) {
                if (!empty($r['admin_id'])) {
                    $linkedCard[(int) $r['admin_id']] = true;
                    if ((int) ($r['acc_active'] ?? 0) !== 1) {
                        continue;
                    }
                }
                if ((int) ($r['web_live'] ?? 1) !== 1) {
                    continue;
                }
                $name = trim((string) $r['name']);
                if ($name === '') {
                    continue;
                }
                $phone = (string) $r['phone'];
                if ($phone === '') {
                    continue;
                }
                $full = (int) $r['public_ok'] === 1;
                $items[] = [
                    'name'     => $name,
                    'kind'     => (string) $r['type'] === 'org' ? 'partner' : 'agent',
                    'area'     => trim((string) ($r['area'] ?? '')),
                    'phone'    => $full ? $phone : self::maskPhone($phone),
                    'verified' => (string) $r['kyc_status'] === 'verified',
                    'signed'   => $isSigned((string) $r['agreement_status'], $r['agreement_end'] !== null ? (string) $r['agreement_end'] : null),
                    'addr'     => trim((string) ($r['web_addr'] ?? '')),
                    'photo'    => $photoUrl($r),
                    'logo'     => $logoUrl($r),
                    'wa'       => $full ? $intl($phone, (string) ($r['phone_raw'] ?? '')) : '',
                ];
            }
        }

        $kyc  = self::kycColumnAvailable() ? 'p.kyc_status' : "'none'";
        $prof = self::profileColumnsAvailable();
        $agrSel  = $prof ? 'p.agreement_status, p.agreement_end, p.public_phone' : "'none' AS agreement_status, NULL AS agreement_end, 0 AS public_phone";
        // Without the profile columns nobody has opted in, so no account is listed.
        $listing = $prof ? 'AND COALESCE(p.public_listing, 0) = 1' : 'AND 1 = 0';
        $agents = Database::fetchAll(
            "SELECT a.id, a.full_name, a.phone, p.display_phone, p.counter_name, p.agent_kind,
                    COALESCE($kyc, 'none') AS kyc_status, $agrSel
               FROM admins a
               LEFT JOIN admin_profiles p ON p.admin_id = a.id
              WHERE a.role = 'agent' AND a.is_active = 1 $listing
              ORDER BY a.id ASC"
        );
        foreach ($agents as $r) {
            // Has a card on Admin -> Agreements: listed (or hidden) through the card.
            if (isset($linkedCard[(int) $r['id']])) {
                continue;
            }
            $name = trim((string) ($r['full_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $phone = normalisePhone((string) ($r['phone'] ?: ($r['display_phone'] ?? '')));
            if ($phone === '') {
                continue;
            }
            $full = (int) ($r['public_phone'] ?? 0) === 1;
            $area = trim((string) ($r['counter_name'] ?? ''));
            $items[] = [
                'name'     => $name,
                'kind'     => (string) ($r['agent_kind'] ?? 'org') === 'person' ? 'agent' : 'partner',
                'area'     => $area,
                'phone'    => $full ? $phone : self::maskPhone($phone),
                'verified' => (string) $r['kyc_status'] === 'verified',
                'signed'   => $isSigned((string) ($r['agreement_status'] ?? 'none'), $r['agreement_end'] !== null ? (string) $r['agreement_end'] : null),
                'addr'     => '',
                'photo'    => '',
                'logo'     => '',
                'wa'       => $full ? $intl($phone, '') : '',
            ];
        }

        // Partners first, then agents; alphabetical inside each.
        usort($items, static function (array $x, array $y): int {
            if ($x['kind'] !== $y['kind']) {
                return $x['kind'] === 'partner' ? -1 : 1;
            }
            return strcasecmp($x['name'], $y['name']);
        });
        return $items;
    }
}
