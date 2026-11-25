<?php
/**
 * =====================================================================
 *  PaymentAdapter — how an Online booking gets paid (15 Oct 2026,
 *  SmartBook v5 §7).
 *
 *  Today: ManualQr — the company's UPI QR / eSewa ID on the QR page
 *  (smartpay.php); the passenger sends the UTR or a screenshot and the
 *  office verifies it in Admin -> Payments (BookingService::approveReviewed).
 *
 *  Later: a gateway (ICICI today in UAT under /payment/, Razorpay /
 *  Cashfree / PhonePe PG or eSewa ePay tomorrow) is switched on in
 *  Settings `payment_gateway` WITHOUT touching the card or the QR page:
 *  the page asks PaymentAdapters::all() what to offer and the adapter
 *  returns either QR data or a redirect URL.
 *
 *  Verification stays with the office (owner rule: a bank success goes to
 *  the verify queue, nothing auto-confirms). A gateway callback lands in
 *  BookingService::submitPaymentProof() exactly like a typed UTR — it is
 *  idempotent per booking and the amount is compared by the risk score.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/qr.php';

interface PaymentAdapter
{
    /** 'manual_qr' | 'icici' | … */
    public function key(): string;

    /** Button text, en / hi / ne. @return array{en: string, hi: string, ne: string} */
    public function label(): array;

    /** Configured and allowed right now. */
    public function available(): bool;

    /**
     * What the QR page shows for this booking.
     *
     * @param array<string, mixed> $booking bookings row
     * @param float $due amount still owed (INR)
     * @return array<string, mixed> kind 'qr' {upi, esewa} | kind 'redirect' {url}
     */
    public function start(array $booking, float $due): array;
}

final class ManualQrAdapter implements PaymentAdapter
{
    public function key(): string
    {
        return 'manual_qr';
    }

    public function label(): array
    {
        return ['en' => 'UPI / eSewa QR', 'hi' => 'UPI / eSewa QR', 'ne' => 'UPI / eSewa QR'];
    }

    public function available(): bool
    {
        return trim(Settings::getString('upi_id', '')) !== '' || trim(Settings::getString('esewa_id', '')) !== ''
            || trim(Settings::getString('nepal_esewa_id', '')) !== '';
    }

    /**
     * 25 Nov 2026 (mp1): ONE Nepal QR everywhere - the picture the ticket prints
     * (Ticket::esewaQrFile(): the NPJ QR uploaded on Owner setup / Payment Management
     * first, else the fixed esewa-qr files) as an <img> src. An uploaded file is
     * sent inline (data:) so it shows even where /uploads/ is closed to the web.
     */
    public static function esewaQrSrc(?array $booking = null): string
    {
        return self::qrSrc(self::nepalQr($booking)['file']);
    }

    /**
     * 25 Nov 2026 (mp4): THE one resolver (PayQr::resolve - the ticket reads the same): the
     * issuing desk's Nepal QR, else the NP default, else the old single QR; the picture, the id
     * beside it and its wallet word come from that one source. $fallbackId / $fallbackName =
     * this sale's own eSewa, used only on the old path (no library row).
     * @return array{file: string, payee: string, name: string, kind: string, row: ?array, source: string}
     */
    public static function nepalQr(?array $booking, string $fallbackId = '', string $fallbackName = ''): array
    {
        try {
            if (!class_exists('PayQr')) {
                require_once __DIR__ . '/payqr.php';
            }
            return PayQr::resolve($booking, 'NP', $fallbackId, $fallbackName);
        } catch (Throwable $e) {
            return ['file' => '', 'payee' => $fallbackId, 'name' => $fallbackName, 'kind' => 'eSewa', 'row' => null, 'source' => $fallbackId !== '' ? 'legacy' : ''];
        }
    }

    /** A QR file on the server -> an <img> src (an uploaded file inline as data:). */
    public static function qrSrc(string $abs): string
    {
        if ($abs === '') {
            return '';
        }
        $root = rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/';
        $rel  = ltrim(str_replace('\\', '/', $abs), '/');
        $abs2 = str_replace('\\', '/', $abs);
        if (str_starts_with($abs2, $root)) {
            $rel = substr($abs2, strlen($root));
        }
        if (str_starts_with($rel, 'uploads/')) {
            $mime = (function_exists('mime_content_type') ? (string) @mime_content_type($abs) : '') ?: 'image/png';
            return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($abs));
        }
        return '/' . $rel;
    }

    public function start(array $booking, float $due): array
    {
        $pnr     = (string) $booking['pnr'];
        $upiId   = trim(Settings::getString('upi_id', ''));
        $upiName = trim(Settings::getString('upi_name', ''));
        /* 25 Nov 2026 (mp1): while the India bank API is live the QR also carries tr=<PNR>,
           the reference the bank's webhook sends back (BankApi::upiUri). */
        if (is_file(__DIR__ . '/bankapi.php')) {
            require_once __DIR__ . '/bankapi.php';
        }
        $link    = $upiId === '' ? '' : (class_exists('BankApi') && BankApi::upiOn()
            ? BankApi::upiUri($upiId, $upiName, $due, $pnr) : upiLink($upiId, $upiName, $due, $pnr));
        /* 25 Nov 2026 (mp1): a Nepal / NPR sale pays the Nepal company's own eSewa
           (nepal_esewa_id / nepal_esewa_name) - the same id and QR the ticket prints -
           in the NPR frozen on the sale when there is one, else the INR due x npr_per_inr. */
        $total   = (float) ($booking['total_amount'] ?? 0);
        $frozen  = strtoupper((string) ($booking['fx_currency'] ?? '')) === 'NPR' && (float) ($booking['fx_total'] ?? 0) > 0 && $total > 0;
        $npr     = $frozen ? round((float) $booking['fx_total'] * $due / $total) : round($due * Settings::getFloat('npr_per_inr', 1.6));
        if (is_file(__DIR__ . '/counterdesk.php')) {
            require_once __DIR__ . '/counterdesk.php';   // companyFor reads the desk's own entity
        }
        $co      = Settings::companyFor((string) ($booking['counter_code'] ?? ''), (string) ($booking['entity'] ?? ''));
        $npId    = trim(Settings::getString('nepal_esewa_id', ''));
        $nepal   = $frozen || ($co['saleEntity'] ?? 'IN') === 'NP' || ($co['entity'] ?? 'IN') === 'NP';
        $esewaId = $nepal && $npId !== '' ? $npId : trim(Settings::getString('esewa_id', ''));
        $esewaNm = $nepal && $npId !== '' ? trim(Settings::getString('nepal_esewa_name', '')) : trim(Settings::getString('esewa_name', ''));
        /* review fix (money): picture, id and wallet word from ONE resolver (the ticket's own) - a
           library row prints ITS payee and kind, never nepal_esewa_id beside another wallet's QR.
           Only when an eSewa is offered at all (as before). */
        $npQ = $esewaId !== '' || $nepal ? self::nepalQr($booking, $esewaId, $esewaNm) : null;
        if ($npQ !== null && $esewaId === '' && $npQ['row'] === null) {
            $npQ = null;   // mp4 review fix: no eSewa id and no library QR for this Nepal sale - nothing offered (as before)
        }
        return [
            'kind'  => 'qr',
            'upi'   => $upiId === '' ? null : [
                'id'   => $upiId,
                'name' => $upiName,
                'link' => $link,
                'qr'   => QrCode::dataUri($link, 7, 3),
            ],
            'esewa' => $npQ === null || ($npQ['payee'] === '' && $npQ['file'] === '') ? null : [
                'id'   => $npQ['payee'],
                'name' => $npQ['name'],
                'kind' => $npQ['kind'],
                'npr'  => $npr,
                'qr'   => self::qrSrc($npQ['file']),
            ],
        ];
    }
}

/** ICICI PG (UAT, /payment/ on the server): the bank result goes to the verify queue. */
final class IciciAdapter implements PaymentAdapter
{
    public function key(): string
    {
        return 'icici';
    }

    public function label(): array
    {
        return ['en' => 'Card / Net banking', 'hi' => 'कार्ड / नेट बैंकिंग', 'ne' => 'कार्ड / नेट बैंकिङ'];
    }

    public function available(): bool
    {
        return in_array('icici', PaymentAdapters::enabledKeys(), true) && is_file(ROOT_PATH . '/payment/initiate_payment.php');
    }

    public function start(array $booking, float $due): array
    {
        return ['kind' => 'redirect', 'url' => '/payment/initiate_payment.php?pnr=' . rawurlencode((string) $booking['pnr'])];
    }
}

final class SmartPay
{
    /** A UTR or a screenshot is on the booking (the office's verify queue has it). */
    public static function hasProof(int $bookingId): bool
    {
        return Database::exists("SELECT 1 FROM payments WHERE booking_id = :b AND utr_number IS NOT NULL AND TRIM(utr_number) <> ''", ['b' => $bookingId])
            || Database::exists('SELECT 1 FROM payment_screenshots WHERE booking_id = :b', ['b' => $bookingId]);
    }

    /**
     * The QR page's poll: the booking's state for the browser that owns it.
     *
     * @return array{status: string, proof: bool, expiresIn: int}|null null = not yours
     */
    public static function status(string $pnr, string $key = ''): ?array
    {
        $b = BookingService::findByPnr($pnr);
        // the browser that booked it / the verified owner, or the keyed link the QR page was opened with
        if ($b === null || (!Auth::customerMayOpen($b) && !($key !== '' && Ticket::checkDownloadToken($pnr, $key)))) {
            return null;
        }
        $exp = strtotime((string) ($b['expires_at'] ?? ''));
        return [
            'status'    => (string) $b['status'],
            'proof'     => self::hasProof((int) $b['id']),
            'expiresIn' => $exp !== false ? max(0, $exp - time()) : 0,
        ];
    }
}

final class PaymentAdapters
{
    /** Settings `payment_gateway`: comma list of gateways switched on ('' = QR only). @return list<string> */
    public static function enabledKeys(): array
    {
        $raw = strtolower(Settings::getString('payment_gateway', ''));
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /** Every adapter available right now, QR first. @return list<PaymentAdapter> */
    public static function all(): array
    {
        $out = [];
        foreach ([new ManualQrAdapter(), new IciciAdapter()] as $a) {
            if ($a->available()) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /**
     * ON-1 / WA-7 (19 Nov 2026): the first switched-on adapter that pays by
     * REDIRECT (a gateway page), as an absolute URL - '' when none is on
     * (payment_gateway empty: today's QR-only set-up). The WhatsApp "Pay ₹X"
     * button prefers it; without one the button opens pay.php. Never throws.
     */
    public static function redirectUrl(array $booking): string
    {
        try {
            foreach (self::all() as $a) {
                if ($a->key() === 'manual_qr') {
                    continue;            // the QR adapter never redirects (and drawing its QR here is waste)
                }
                $s = $a->start($booking, (float) ($booking['total_amount'] ?? 0));
                if (($s['kind'] ?? '') !== 'redirect') {
                    continue;
                }
                $url = trim((string) ($s['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                if (preg_match('~^https?://~i', $url) !== 1) {
                    $url = appUrl(ltrim($url, '/'));
                }
                return $url;
            }
        } catch (Throwable $e) {
        }
        return '';
    }

    /** What is still owed on a booking (INR): total minus verified payments. */
    public static function due(array $booking): float
    {
        $paid = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = :b AND status = 'verified'",
            ['b' => (int) $booking['id']]
        );
        return max(0.0, round((float) $booking['total_amount'] - $paid, 2));
    }
}

/**
 * =====================================================================
 *  CodPayClaim — a pay-at-boarding ticket paid ONLINE before boarding
 *  (16 Nov 2026, P1 - owner: "pay at boarding vane option ... redirect
 *  payment lina sab suvidha hunu paryo, free wala").
 *
 *  A pay-at-boarding (COD) ticket is confirmed at once and its ONE money
 *  row stays 'cod_pending' until the office has the money
 *  (BookingService::settleCod - the Payments page "Cash owed" tab, the
 *  chalani tick, the agent WhatsApp tool). A passenger may instead pay
 *  online through the free ways on pay.php (UPI app / UPI QR / eSewa) and
 *  type the UTR. That claim is written onto the SAME money row - the UTR,
 *  the amount due as the claimed amount, mode 'utr' and an "ONLINE CLAIM ·
 *  UPI …" note - so it shows up where the office already works that row:
 *  the Payments page COD tab (with its "UTR also on" duplicate check) and
 *  the booking page. A second row is never added (the fare would be
 *  counted twice), and checkout's submitPaymentProof() is not used - it
 *  would flip the row to 'pending' on a confirmed booking, which drops it
 *  out of every queue.
 *
 *  NEVER A CONFIRMATION (house rule). Nothing here marks the ticket paid,
 *  changes is_cod, the booking status or the row's status / method: the
 *  office checks the bank and settles the row (chalani tick "UPI" / "eSewa"
 *  records it as online money on the ticket). No message goes to the
 *  customer; the office gets a bell notification and its usual WhatsApp
 *  note (as for a UTR typed on WhatsApp, WaPayProof).
 *
 *  Who may claim: api/payment.php's rule - this browser booked it, the
 *  signed-in owner, the ticket's own download key (k), or the booking's
 *  mobile typed on the form (never the walk-in placeholder number).
 *  Checks: UTR shape (PaymentRisk::utrLooksWrong - 12-digit UPI UTR,
 *  6-20 letters/digits for eSewa, no made-up numbers), one claim per
 *  ticket, one use per UTR (any booking, any split-pay share), amount =
 *  what is due (server side). The caller rate-limits ('payment_proof',
 *  like the checkout's proof form).
 * =====================================================================
 */
final class CodPayClaim
{
    public const TAG = 'ONLINE CLAIM';

    /** The pay-at-boarding money row: the booking's latest payments row while it is still 'cod_pending'. */
    public static function row(int $bookingId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1';
        $p = $forUpdate ? (Database::fetchForUpdate($sql, ['b' => $bookingId])[0] ?? null) : Database::fetch($sql, ['b' => $bookingId]);
        return $p !== null && (string) $p['status'] === 'cod_pending' ? $p : null;
    }

    /** A confirmed pay-at-boarding ticket that still owes money on an unsettled money row. */
    public static function eligible(array $booking): bool
    {
        return (int) ($booking['is_cod'] ?? 0) === 1
            && (string) ($booking['status'] ?? '') === 'confirmed'
            && PaymentAdapters::due($booking) > 0
            && self::row((int) $booking['id']) !== null;
    }

    /**
     * The claim already sent for this ticket (a UTR on its unsettled money row), or null.
     *
     * @return array{utr:string, method:string, amount:float, at:string}|null
     */
    public static function current(int $bookingId): ?array
    {
        $p = self::row($bookingId);
        if ($p === null || trim((string) ($p['utr_number'] ?? '')) === '') {
            return null;
        }
        $m = preg_match('/' . self::TAG . ' · eSewa/u', (string) ($p['admin_note'] ?? '')) === 1
            || str_starts_with((string) ($p['gateway_payload'] ?? ''), '{"pay_claim":{"method":"esewa"') ? 'esewa' : 'upi';
        return [
            'utr'    => (string) $p['utr_number'],
            'method' => $m,
            'amount' => (float) ($p['claimed_amount'] ?? 0),
            'at'     => (string) ($p['updated_at'] ?? ''),
        ];
    }

    /** "4321 8765 1234", "4321-8765-1234", Devanagari digits -> "432187651234" (upper case, no spaces). */
    public static function normaliseUtr(string $raw): string
    {
        $s = strtr($raw, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
        $s = preg_replace('/[\s\-.\x{00A0}]+/u', '', $s) ?? '';
        return strtoupper(Security::clean($s, 40));
    }

    /** Does this request belong to the booking? The same rule as api/payment.php. */
    public static function owns(array $booking, string $key = '', string $typedPhone = ''): bool
    {
        $pnr         = strtoupper((string) ($booking['pnr'] ?? ''));
        $contact     = (string) ($booking['contact_phone'] ?? '');
        $realContact = $contact !== '' && !isPlaceholderPhone($contact);
        if (in_array($pnr, Auth::ownPnrs(), true)) {
            return true;
        }
        $me = Auth::user();
        if ($me !== null && (
            ((int) ($booking['user_id'] ?? 0) > 0 && (int) $booking['user_id'] === (int) ($me['id'] ?? 0))
            || ($realContact && normalisePhone((string) ($me['phone'] ?? '')) === normalisePhone($contact))
        )) {
            return true;
        }
        if ($key !== '') {
            require_once __DIR__ . '/ticket.php';
            if (Ticket::checkDownloadToken($pnr, $key)) {
                return true;
            }
        }
        $typed = $typedPhone !== '' ? normalisePhone($typedPhone) : '';
        return $realContact && $typed !== '' && $typed === normalisePhone($contact);
    }

    /**
     * Record the passenger's online payment claim on the pay-at-boarding money row.
     * Never confirms anything. Refusals throw RuntimeException with a Nepali · English
     * message and the HTTP status as the code (403 not yours, 409 already sent / UTR
     * used / nothing to pay, 422 bad UTR).
     *
     * @param array<string, mixed> $booking bookings row (id, pnr, status, is_cod, total_amount, contact_phone, user_id)
     * @return array{utr:string, method:string, amount:float}
     */
    public static function submit(array $booking, string $utrRaw, string $method, string $typedPhone = '', string $key = '', string $payer = ''): array
    {
        $bid = (int) ($booking['id'] ?? 0);
        $pnr = strtoupper((string) ($booking['pnr'] ?? ''));
        if ($bid <= 0) {
            throw new RuntimeException('बुकिङ भेटिएन · Booking not found.', 404);
        }
        if (!self::owns($booking, $key, $typedPhone)) {
            throw new RuntimeException('बुकिङ गर्दा दिएको मोबाइल नम्बर हाल्नुहोस् · Enter the mobile number used for this booking.', 403);
        }
        $method = strtolower(trim($method)) === 'esewa' ? 'esewa' : 'upi';
        $utr    = self::normaliseUtr($utrRaw);
        if ($utr === '') {
            throw new RuntimeException('UTR / कारोबार नम्बर लेख्नुहोस् · Type the UTR / transaction number.', 422);
        }
        require_once __DIR__ . '/paymentrisk.php';
        $bad = PaymentRisk::utrLooksWrong($utr, $method);
        if ($bad !== null) {
            throw new RuntimeException(($method === 'upi'
                ? 'UPI को UTR १२ अङ्कको हुन्छ — भुक्तानी एपमा हेरेर फेरि लेख्नुहोस् · '
                : 'eSewa को कारोबार कोड ६-२० अक्षर/अङ्कको हुन्छ · ') . $bad . '.', 422);
        }
        $payer = Security::clean($payer, 120);

        /* One UTR, one claim - even when two tickets send the same number in the same
           second: the check and the write run under a lock named after the UTR. */
        $lock = 'shg_payclaim_' . substr(hash('sha256', $utr), 0, 40);
        if ((int) Database::scalar('SELECT GET_LOCK(:k, 5)', ['k' => $lock], 0) !== 1) {
            throw new RuntimeException('अहिले व्यस्त छ, एकछिनमा फेरि पठाउनुहोस् · Busy right now - please send it again in a moment.', 409);
        }
        try {
            $saved = Database::transaction(static function () use ($bid, $utr, $method, $payer): array {
                $b = Database::fetchForUpdate('SELECT * FROM bookings WHERE id = :id', ['id' => $bid])[0] ?? null;
                if ($b === null || (int) $b['is_cod'] !== 1 || (string) $b['status'] !== 'confirmed') {
                    throw new RuntimeException('यो टिकटमा अनलाइन भुक्तानी लिन मिल्दैन · This ticket cannot take an online payment.', 409);
                }
                $due = PaymentAdapters::due($b);
                $p   = self::row($bid, true);
                if ($due <= 0 || $p === null) {
                    throw new RuntimeException('यो टिकटमा तिर्न बाँकी छैन · Nothing is due on this ticket.', 409);
                }
                if (trim((string) ($p['utr_number'] ?? '')) !== '') {
                    throw new RuntimeException('यो टिकटको भुक्तानी पहिले नै जाँचमा छ — अफिसले जाँचेपछि टिकटमा देखिन्छ · A payment for this ticket is already being checked.', 409);
                }
                $used = Database::fetch(
                    "SELECT p.id FROM payments p
                      WHERE p.utr_number = :u OR REPLACE(REPLACE(TRIM(p.utr_number), ' ', ''), '-', '') = :u2
                      LIMIT 1",
                    ['u' => $utr, 'u2' => $utr]
                );
                if ($used === null) {
                    try {
                        $used = Database::fetch(
                            "SELECT id FROM payment_shares WHERE utr = :u OR REPLACE(REPLACE(TRIM(utr), ' ', ''), '-', '') = :u2 LIMIT 1",
                            ['u' => $utr, 'u2' => $utr]
                        );
                    } catch (Throwable $e) {
                        $used = null;   // no split-pay table on this install
                    }
                }
                if ($used !== null) {
                    throw new RuntimeException('यो UTR पहिले नै प्रयोग भइसकेको छ · This UTR has already been used.', 409);
                }
                $note = self::TAG . ' · ' . ($method === 'esewa' ? 'eSewa' : 'UPI') . ' · INR ' . number_format($due, 2)
                    . ' · ' . date('j M H:i') . ' (pay link) - check the bank, then settle; nothing changed automatically';
                $old = trim((string) ($p['admin_note'] ?? ''));
                $upd = [
                    'utr_number' => $utr,
                    'mode'       => 'utr',
                    'admin_note' => mb_substr($note . ($old !== '' ? ' | ' . $old : ''), 0, 255),
                ];
                if ($payer !== '') {
                    $upd['payer_name'] = $payer;
                }
                if (PaymentRisk::hasCol('payments', 'claimed_amount')) {
                    $upd['claimed_amount'] = $due;
                }
                /* P1 refix: the claim's WAY survives the settle. Payments → COD, the booking
                   page and the cash book settle this row as method 'cod' with their own note
                   ("Cash collected"), which wipes the ONLINE CLAIM note; the ticket then reads
                   the UTR plus this marker to print PAID · अनलाइन (UPI / eSewa), not CASH
                   (Ticket::paidWay). Written only on an empty payload - a gateway's own
                   payload is never touched - and with no amount key, so PaymentRisk's
                   claimed-amount reader ignores it. */
                if (trim((string) ($p['gateway_payload'] ?? '')) === '') {
                    $upd['gateway_payload'] = (string) json_encode(['pay_claim' => ['method' => $method, 'utr' => $utr, 'at' => date('c')]],
                        JSON_UNESCAPED_SLASHES);
                }
                Database::update('payments', $upd, 'id = :id', ['id' => (int) $p['id']]);
                return ['due' => $due];
            });
        } finally {
            try {
                Database::scalar('SELECT RELEASE_LOCK(:k)', ['k' => $lock], 0);
            } catch (Throwable $e) {
                // the lock dies with the connection anyway
            }
        }

        $due  = (float) $saved['due'];
        $way  = $method === 'esewa' ? 'eSewa' : 'UPI';
        $tail = substr($utr, -4);
        require_once __DIR__ . '/payevents.php';
        PayEvents::log($bid, $pnr, 'pay_claim');
        Logger::audit('payment.cod_online_claim', 'booking', $pnr, null,
            ['method' => $method, 'utr_tail' => $tail, 'amount' => $due],
            'Pay-at-boarding ticket: the passenger says it was paid online (pay link); waiting for the office - nothing changed automatically');
        try {
            Database::insert('notifications', [
                'audience'   => 'admin',
                'booking_id' => $bid,
                'icon'       => '🧾',
                'title'      => Security::clean('Online payment to check · ' . $pnr, 191),
                'body'       => Security::clean('बसमा तिर्ने टिकट: यात्रुले ' . $way . ' बाट ' . inr($due) . ' तिरेको भन्छन् (UTR …' . $tail
                    . ')। बैंक जाँचेर Payments → COD वा चलानीमा ' . $way . ' टिक गर्नुहोस्। · Pay-on-boarding ticket: the passenger says '
                    . inr($due) . ' was paid by ' . $way . ', UTR …' . $tail . '. Check the bank, then settle (Payments → COD, or the chalani tick '
                    . $way . '). Nothing changed automatically.', 500),
            ]);
        } catch (Throwable $e) {
            Logger::warning('Online claim bell not written: ' . $e->getMessage(), ['pnr' => $pnr]);
        }
        try {
            require_once __DIR__ . '/notify.php';
            Notify::adminNote('🧾 Pay-on-boarding ticket paid online? Check the bank', [
                'PNR'    => $pnr,
                'Way'    => $way,
                'UTR'    => $utr,
                'Amount' => inr($due),
                'Open'   => appUrl('admin/payments.php?tab=cod&q=' . rawurlencode($pnr)),
            ], $bid);
        } catch (Throwable $e) {
            // the bell above and the money row are the record
        }

        return ['utr' => $utr, 'method' => $method, 'amount' => $due];
    }
}
