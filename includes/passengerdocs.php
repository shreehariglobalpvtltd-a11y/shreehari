<?php
/**
 * =====================================================================
 *  PassengerDocs — passenger photo / ID-document store (17 Sep 2026).
 *
 *  Until now a passenger's document existed only as text
 *  (booking_passengers.id_type / id_number). The crew at the border and
 *  the desk checking a reschedule kept asking for the picture itself: the
 *  citizenship card, the Aadhaar, a face photo for the manifest. This is
 *  that store — one row per file, hung off the booking_passengers row,
 *  the bytes under uploads/passengers/YYYY/MM/.
 *
 *  It reuses the hardened upload pipeline payment screenshots already go
 *  through (Security::validateUpload: real MIME via finfo, an image must
 *  decode; Security::safeFilename: random 64-bit name; sha256 of the
 *  stored bytes) rather than carrying a second copy of those rules. Two
 *  rules are its own:
 *
 *   1. AGENT SCOPE. A counter agent may attach, list and remove documents
 *      only on a booking they sold (bookings.sold_by_admin_id = self) —
 *      the rule BookingService::cancelSeat() and Seats::transferSeat()
 *      already apply. Reads of a foreign document answer "not found".
 *   2. NEVER A PUBLIC LINK. nginx serves /uploads/ as static bytes, so a
 *      document's path is never printed anywhere. The one reader is
 *      admin/passenger-doc.php?id=, behind admin_boot('bookings.view')
 *      and the same scope check.
 *
 *  Every read degrades (to [] / null / 0 / false) while the
 *  passenger_documents table does not exist yet — a live server that has
 *  not run database/upgrade-2026-09-passenger-documents.sql keeps every
 *  ticket page rendering; only attach() refuses, with a message that
 *  names the migration.
 *
 *  attach() = validate + store the bytes, then record(); record() = the
 *  single writer of the row + audit line. Split on purpose: the CLI test
 *  cannot pass Security::validateUpload (is_uploaded_file() is false for
 *  a file no browser sent), so it drives record() directly.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class PassengerDocs
{
    /** kind => label, in the order the upload picker offers them. */
    public const KINDS = [
        'photo'    => 'Photo',
        'id_front' => 'ID front',
        'id_back'  => 'ID back',
        'other'    => 'Other',
    ];

    /** Every kind accepts an image; the id_* kinds also accept a PDF scan. */
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp'];

    /** Sub-folder of UPLOAD_PATH. file_path is stored relative to UPLOAD_PATH. */
    private const SUBDIR = 'passengers';

    private function __construct() {}

    /* -----------------------------------------------------------------
     *  Small answers the pages ask for
     * ----------------------------------------------------------------- */

    /** @return array<string, string> kind => label */
    public static function kinds(): array
    {
        return self::KINDS;
    }

    /** Human label for a kind ('id_front' -> 'ID front'). */
    public static function label(string $kind): string
    {
        return self::KINDS[$kind] ?? ucfirst(str_replace('_', ' ', $kind));
    }

    /**
     * Has the passenger_documents table been created on this database?
     * Checked once per request (static) so the ticket page pays one cheap
     * SHOW, not one per passenger. $recheck re-asks after a migration.
     */
    public static function available(bool $recheck = false): bool
    {
        static $has = null;
        if ($has === null || $recheck) {
            try {
                $has = Database::fetch("SHOW TABLES LIKE 'passenger_documents'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /** Office switch (settings.passenger_docs_on) AND the table exists. */
    public static function enabled(): bool
    {
        return Settings::getBool('passenger_docs_on', true) && self::available();
    }

    /** Upload ceiling in whole MB, for the hint under the file picker. */
    public static function maxMb(): int
    {
        return max(1, (int) round(MAX_UPLOAD_BYTES / 1048576));
    }

    /** Is this document row an image (thumbnail) rather than a PDF (icon)? */
    public static function isImage(array $doc): bool
    {
        return str_starts_with((string) ($doc['mime_type'] ?? ''), 'image/');
    }

    /* -----------------------------------------------------------------
     *  Writes
     * ----------------------------------------------------------------- */

    /**
     * Validate an uploaded file, store it under uploads/passengers/ and
     * record it against the passenger.
     *
     * @param array<string, mixed> $file one $_FILES entry
     * @param string $kind photo | id_front | id_back | other
     * @return array{id:int, file:string, kind:string, mime:string, size:int, pnr:string, name:string, seat:string}
     */
    public static function attach(int $paxId, array $file, string $kind, ?int $adminId): array
    {
        $kind = self::normaliseKind($kind);

        // Ownership + agent scope BEFORE anything touches the disk — the
        // rule attachScreenshot() follows, so a refused upload leaves no
        // orphan file behind.
        self::paxForWrite($paxId);

        $check = Security::validateUpload($file);
        if (!$check['ok']) {
            throw new RuntimeException((string) ($check['error'] ?? 'Upload failed.'));
        }

        $ext      = strtolower((string) ($check['ext'] ?? ''));
        $mime     = (string) ($check['mime'] ?? '');
        $isIdKind = str_starts_with($kind, 'id_');
        $okExt    = $isIdKind ? array_merge(self::IMAGE_EXT, ['pdf']) : self::IMAGE_EXT;
        $typeOk   = in_array($ext, $okExt, true)
            && (($ext === 'pdf' && $mime === 'application/pdf')
                || ($ext !== 'pdf' && str_starts_with($mime, 'image/')));
        if (!$typeOk) {
            throw new RuntimeException($isIdKind
                ? 'An ID document must be a JPG, PNG or WEBP image, or a PDF scan.'
                : 'A passenger photo must be a JPG, PNG or WEBP image.');
        }

        $ym     = date('Y') . '/' . date('m');
        $subDir = UPLOAD_PATH . '/' . self::SUBDIR . '/' . $ym;
        if (!ensureDir($subDir)) {
            throw new RuntimeException('Could not create the upload folder.');
        }

        $filename = Security::safeFilename($ext);
        $absolute = $subDir . '/' . $filename;
        $relative = self::SUBDIR . '/' . $ym . '/' . $filename;

        if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $absolute)) {
            throw new RuntimeException('Could not save the uploaded file.');
        }

        try {
            return self::record($paxId, $relative, $mime, (int) ($check['size'] ?? 0), $kind, $adminId);
        } catch (Throwable $e) {
            @unlink($absolute);   // a row that was never written must not leave a file
            throw $e;
        }
    }

    /**
     * Record a file that is ALREADY under uploads/ against a passenger:
     * the row + the audit line. attach() calls this after the move; the
     * CLI test calls it directly. Same ownership / scope checks as attach().
     *
     * @param string $relPath relative to UPLOAD_PATH, e.g. passengers/2026/09/x.png
     * @return array{id:int, file:string, kind:string, mime:string, size:int, pnr:string, name:string, seat:string}
     */
    public static function record(int $paxId, string $relPath, string $mime, int $size, string $kind, ?int $adminId): array
    {
        $kind    = self::normaliseKind($kind);
        $pax     = self::paxForWrite($paxId);
        $relPath = ltrim(str_replace('\\', '/', $relPath), '/');
        self::assertSafePath($relPath);

        $absolute = UPLOAD_PATH . '/' . $relPath;
        if (!is_file($absolute)) {
            throw new RuntimeException('The document file is missing on disk.');
        }
        if ($size <= 0) {
            $size = (int) (@filesize($absolute) ?: 0);
        }

        $row = [
            'booking_id'           => (int) $pax['booking_id'],
            'passenger_id'         => $paxId,
            'kind'                 => $kind,
            'file_path'            => $relPath,
            'mime_type'            => substr($mime, 0, 60),
            'file_size'            => max(0, $size),
            'sha256'               => hash_file('sha256', $absolute) ?: null,
            'uploaded_by_admin_id' => ($adminId !== null && $adminId > 0) ? $adminId : null,
            'uploaded_ip'          => Security::clientIp(),
        ];
        $id = Database::insert('passenger_documents', $row);

        Logger::audit('passenger.document', 'booking', (string) $pax['pnr'], null, [
            'doc'  => $id,
            'pax'  => $paxId,
            'seat' => (string) $pax['seat_no'],
            'name' => (string) $pax['full_name'],
            'kind' => $kind,
            'file' => $relPath,
            'size' => $row['file_size'],
        ], 'uploaded by admin #' . (int) $adminId);

        return [
            'id'   => $id,
            'file' => $relPath,
            'kind' => $kind,
            'mime' => (string) $row['mime_type'],
            'size' => (int) $row['file_size'],
            'pnr'  => (string) $pax['pnr'],
            'name' => (string) $pax['full_name'],
            'seat' => (string) $pax['seat_no'],
        ];
    }

    /**
     * Delete one document: the file, the row, and an audit line. A counter
     * agent can only remove documents on bookings they sold (a foreign id
     * reads as "not found", never as "forbidden" — confirming the row
     * exists is itself a disclosure).
     */
    public static function remove(int $docId, int $adminId): void
    {
        $doc = self::get($docId);
        if ($doc === null) {
            throw new RuntimeException('Document not found.');
        }
        $scope = Auth::bookingScopeAdminId();
        if ($scope !== null && (int) ($doc['sold_by_admin_id'] ?? 0) !== $scope) {
            throw new RuntimeException('Document not found.');
        }

        $absolute = self::absolutePath($doc);

        // Row first, file second: a row whose file is gone shows a plain
        // 404 on open, while a file whose row is gone is a harmless orphan.
        Database::delete('passenger_documents', 'id = :id', ['id' => $docId]);
        if (is_file($absolute)) {
            @unlink($absolute);
        }

        Logger::audit('passenger.document_remove', 'booking', (string) ($doc['pnr'] ?? ''), [
            'doc'  => $docId,
            'pax'  => (int) ($doc['passenger_id'] ?? 0),
            'kind' => (string) ($doc['kind'] ?? ''),
            'file' => (string) ($doc['file_path'] ?? ''),
        ], null, 'removed by admin #' . $adminId);
    }

    /* -----------------------------------------------------------------
     *  Reads — every one degrades when the table is missing
     * ----------------------------------------------------------------- */

    /**
     * Every document on a booking, grouped by passenger id:
     *   [ passengerId => [ row, row, … ], … ]
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function listFor(int $bookingId): array
    {
        if ($bookingId <= 0 || !self::available()) {
            return [];
        }
        try {
            $rows = Database::fetchAll(
                'SELECT id, passenger_id, kind, file_path, mime_type, file_size, sha256, uploaded_by_admin_id, created_at
                   FROM passenger_documents
                  WHERE booking_id = :b
                  ORDER BY passenger_id, id',
                ['b' => $bookingId]
            );
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['passenger_id']][] = $r;
        }
        return $out;
    }

    /**
     * One document row joined with what the reader page and the audit
     * line need: the booking's pnr and sold_by_admin_id (scope), the
     * passenger's name and seat (download filename).
     *
     * @return array<string, mixed>|null
     */
    public static function get(int $docId): ?array
    {
        if ($docId <= 0 || !self::available()) {
            return null;
        }
        try {
            return Database::fetch(
                'SELECT pd.*, b.pnr, b.sold_by_admin_id, b.contact_phone,
                        bp.full_name, bp.seat_no
                   FROM passenger_documents pd
                   JOIN bookings b ON b.id = pd.booking_id
                   LEFT JOIN booking_passengers bp ON bp.id = pd.passenger_id
                  WHERE pd.id = :id
                  LIMIT 1',
                ['id' => $docId]
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * How many documents sit on bookings made from one contact number —
     * the customer pages show it as a hint. Scoped when an admin id is
     * given (a counter agent's view of their own sales).
     */
    public static function countForPhone(string $phone, ?int $scopeAdminId = null): int
    {
        $phone = preg_replace('/\D/', '', $phone) ?? '';
        if ($phone === '' || !self::available()) {
            return 0;
        }
        try {
            $sql    = 'SELECT COUNT(*) FROM passenger_documents pd JOIN bookings b ON b.id = pd.booking_id WHERE b.contact_phone = :p';
            $params = ['p' => $phone];
            if ($scopeAdminId !== null) {
                $sql .= ' AND b.sold_by_admin_id = :s';
                $params['s'] = $scopeAdminId;
            }
            return (int) Database::scalar($sql, $params, 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Absolute path of a document row's file. Refuses anything that is not
     * uploads/passengers/YYYY/MM/<safe name> — the column is written only
     * by record(), but a reader that trusts a path is a reader that one
     * day serves config.php.
     */
    public static function absolutePath(array $doc): string
    {
        $rel = ltrim(str_replace('\\', '/', (string) ($doc['file_path'] ?? '')), '/');
        self::assertSafePath($rel);
        return UPLOAD_PATH . '/' . $rel;
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ----------------------------------------------------------------- */

    private static function normaliseKind(string $kind): string
    {
        $k = strtolower(trim($kind));
        if (!isset(self::KINDS[$k])) {
            throw new RuntimeException('Unknown document kind.');
        }
        return $k;
    }

    private static function assertSafePath(string $rel): void
    {
        if (preg_match('#^passengers/\d{4}/\d{2}/[A-Za-z0-9_-]+\.[a-z0-9]{2,5}$#', $rel) !== 1) {
            throw new RuntimeException('Invalid document path.');
        }
    }

    /**
     * The passenger row a write is aimed at, joined with its booking —
     * or a RuntimeException: the table is missing, the passenger does
     * not exist, or the booking was not sold by the signed-in agent.
     *
     * @return array<string, mixed>
     */
    private static function paxForWrite(int $paxId): array
    {
        if (!self::available()) {
            throw new RuntimeException('Passenger documents are not set up on this database yet — run database/upgrade-2026-09-passenger-documents.sql.');
        }
        $pax = $paxId > 0 ? Database::fetch(
            'SELECT bp.id, bp.booking_id, bp.full_name, bp.seat_no, b.pnr, b.sold_by_admin_id, b.status
               FROM booking_passengers bp
               JOIN bookings b ON b.id = bp.booking_id
              WHERE bp.id = :id
              LIMIT 1',
            ['id' => $paxId]
        ) : null;
        if ($pax === null) {
            throw new RuntimeException('Passenger not found.');
        }
        // Agent scoping: agents can only touch their own bookings (the
        // cancelSeat() rule, same wording shape).
        $scope = Auth::bookingScopeAdminId();
        if ($scope !== null && (int) ($pax['sold_by_admin_id'] ?? 0) !== $scope) {
            throw new RuntimeException('You can only attach documents to bookings you sold.');
        }
        return $pax;
    }
}
