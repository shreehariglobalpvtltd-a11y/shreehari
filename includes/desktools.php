<?php
/**
 * =====================================================================
 *  includes/desktools.php — small office-desk helpers (15 Nov 2026, P4).
 *
 *  ONE place for the rules four admin pages share:
 *
 *    maskId / idLabel   what a LIST may show of a passenger's ID number.
 *                       The rule is AgentPro::maskId (Aadhaar: last four
 *                       only, as UIDAI requires; others: last four with
 *                       the rest dotted). The full number stays in the
 *                       edit forms; a super-admin may press "dekhau" and
 *                       the full value is fetched once through
 *                       admin/api/id-reveal.php, which writes an audit row.
 *    agingBuckets       an agent's cash still owed (agent_ledger, account
 *                       'cash'), aged first-in-first-out: every handover
 *                       pays off the OLDEST cash first, what is left of
 *                       each sale is aged by its own date into
 *                       0-7 / 8-15 / 16-30 / 30+ days.
 *    cancelBand         hours between a cancel and the departure, as a band.
 *    ticket resend      the email and SMS a desk sends again on request.
 *                       Both stay behind office switches that default OFF
 *                       (desk_resend_email_on, desk_resend_sms_on; SMS also
 *                       needs sms_enabled) - the owner turns them on.
 *
 *  Pure parts (masking, aging, bands) touch no database, so
 *  tests/desk-tools-test.php can prove them with fixture rows.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class DeskTools
{
    /** Aging buckets, in display order. */
    public const AGING_BUCKETS = ['0-7', '8-15', '16-30', '30+'];

    /** Cancel-time bands, in display order: key => label. */
    public const CANCEL_BANDS = [
        'gt48'    => '> 48 h before',
        '24to48'  => '24 – 48 h before',
        'lt24'    => '< 24 h before',
        'after'   => 'After departure',
        'unknown' => 'Departure not known',
    ];

    /** The reveal kinds admin/api/id-reveal.php answers. */
    public const REVEAL_KINDS = ['bk', 'px', 'cu'];

    private static bool $revealUsed = false;

    /* =================================================================
     *  ID masking
     * ================================================================= */

    /** 'aadhaar' for every way the desk writes it (Aadhaar, Aadhar card, Adhar, UID), else the type lower-cased. */
    public static function idKind(?string $type): string
    {
        $t = strtolower(trim((string) $type));
        if ($t !== '' && preg_match('/a{1,2}dha{1,2}r|aada{1,2}r|^uid\b|uidai/', $t) === 1) {
            return 'aadhaar';
        }
        return $t;
    }

    /** What a list may show of one ID number ('' when there is none). */
    public static function maskId(?string $type, ?string $number): string
    {
        $number = trim((string) $number);
        if ($number === '') {
            return '';
        }
        $kind = self::idKind($type);
        try {
            if (!class_exists('AgentPro')) {
                require_once __DIR__ . '/agentpro.php';
            }
            $m = AgentPro::maskId($kind, $number);
        } catch (Throwable $e) {
            $m = self::maskFallback($kind, $number);
        }
        // AgentPro::maskId gives '' for an Aadhaar with under four digits - never show it whole.
        return $m !== '' ? $m : '••••';
    }

    /** The same rule as AgentPro::maskId, for a server where that file cannot load. */
    private static function maskFallback(string $kind, string $number): string
    {
        $n = strtoupper(preg_replace('/\s+/', '', $number) ?? '');
        if ($kind === 'aadhaar') {
            $d = preg_replace('/\D/', '', $n) ?? '';
            return strlen($d) >= 4 ? 'XXXX XXXX ' . substr($d, -4) : '';
        }
        $len = mb_strlen($n);
        return $len <= 4 ? $n : str_repeat('•', min(8, $len - 4)) . mb_substr($n, -4);
    }

    /** "Aadhaar XXXX XXXX 1234" — type + masked number, '' when there is no number. */
    public static function idLabel(?string $type, ?string $number): string
    {
        $m = self::maskId($type, $number);
        return $m === '' ? '' : trim(trim((string) $type) . ' ' . $m);
    }

    /**
     * The masked ID as HTML, plus a "dekhau" button for a super-admin. The
     * full value is never in the page: the button asks admin/api/id-reveal.php,
     * which writes the audit row. $empty is shown when there is no number.
     */
    public static function idHtml(?string $type, ?string $number, string $kind, string $ref, string $empty = '—'): string
    {
        $label = self::idLabel($type, $number);
        if ($label === '') {
            return '<span class="muted">' . Security::e($empty) . '</span>';
        }
        $h = '<span class="idm"><span data-idv>' . Security::e($label) . '</span>';
        if (in_array($kind, self::REVEAL_KINDS, true) && $ref !== '' && Auth::isSuperadmin()
            && $label !== trim(trim((string) $type) . ' ' . trim((string) $number))) {
            self::$revealUsed = true;
            $h .= ' <button type="button" class="idrv" data-idrv="' . Security::e($kind . ':' . $ref) . '"'
                . ' title="Show the full number (written to the audit log) · पूरा नम्बर देखाउ">dekhau</button>';
        }
        return $h . '</span>';
    }

    /** The style + script behind idHtml()'s buttons; '' when none was drawn. Call once near the end. */
    public static function revealAssets(): string
    {
        if (!self::$revealUsed) {
            return '';
        }
        $tok = Security::e(Security::csrfToken());
        return '<style>.idm{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap}'
            . '.idrv{min-height:44px;min-width:44px;padding:0 12px;border:1px solid var(--line);border-radius:10px;'
            . 'background:var(--card);color:var(--ink);font-weight:600;font-size:12.5px;cursor:pointer}.idrv:hover{background:var(--hover)}</style>'
            . '<script>(function(){var tok="' . $tok . '";'
            . 'document.addEventListener("click",function(e){var b=e.target&&e.target.closest?e.target.closest("[data-idrv]"):null;if(!b)return;e.preventDefault();'
            . 'if(!confirm("Show the full ID number? It is written to the audit log.\\nपूरा नम्बर देखाउने? यो audit log मा लेखिन्छ।"))return;'
            . 'var p=String(b.getAttribute("data-idrv")).split(":"),box=b.parentNode.querySelector("[data-idv]");b.disabled=true;'
            . 'fetch("/admin/api/id-reveal.php",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","Accept":"application/json","X-CSRF-Token":tok},'
            . 'body:JSON.stringify({kind:p[0],ref:p.slice(1).join(":")})}).then(function(r){return r.json();}).then(function(d){'
            . 'if(d&&d.ok){if(box)box.textContent=d.value;b.remove();}else{b.disabled=false;alert((d&&d.error)||"Not allowed.");}'
            . '}).catch(function(){b.disabled=false;alert("Network error - try again.");});});})();</script>';
    }

    /**
     * The full stored ID for one reveal request, or null when there is none.
     * bk = bookings.id (the cash-verification ID), px = booking_passengers.id,
     * cu = a customer's mobile (their account's ID, else the latest booking's).
     *
     * @return array{type: string, number: string, entity: string}|null
     */
    public static function revealValue(string $kind, string $ref): ?array
    {
        try {
            if ($kind === 'bk' && ctype_digit($ref)) {
                $r = Database::fetch('SELECT id_type, id_number FROM bookings WHERE id = :i', ['i' => (int) $ref]);
                $entity = 'booking';
            } elseif ($kind === 'px' && ctype_digit($ref)) {
                $r = Database::fetch('SELECT id_type, id_number FROM booking_passengers WHERE id = :i', ['i' => (int) $ref]);
                $entity = 'booking_passenger';
            } elseif ($kind === 'cu' && preg_match('/^\d{6,15}$/', $ref) === 1) {
                $entity = 'customer';
                $r = Database::fetch(
                    "SELECT id_type, id_number FROM users WHERE phone = :p AND id_number IS NOT NULL AND id_number <> '' LIMIT 1",
                    ['p' => $ref]
                ) ?? Database::fetch(
                    "SELECT id_type, id_number FROM bookings WHERE contact_phone = :p AND id_number IS NOT NULL AND id_number <> '' ORDER BY id DESC LIMIT 1",
                    ['p' => $ref]
                );
            } else {
                return null;
            }
        } catch (Throwable $e) {
            return null;
        }
        $num = trim((string) ($r['id_number'] ?? ''));
        return $num === '' ? null : ['type' => trim((string) ($r['id_type'] ?? '')), 'number' => $num, 'entity' => $entity];
    }

    /* =================================================================
     *  Agent cash aging
     * ================================================================= */

    /** The bucket for an age in whole days (a future-dated row counts as today). */
    public static function bucketFor(int $ageDays): string
    {
        return match (true) {
            $ageDays <= 7  => '0-7',
            $ageDays <= 15 => '8-15',
            $ageDays <= 30 => '16-30',
            default        => '30+',
        };
    }

    /**
     * Age one agent's cash account first-in-first-out.
     *
     * $rows are that agent's agent_ledger rows with account = 'cash'
     * ({amount, created_at}): a positive amount is cash taken for us
     * (cash_due, a debit adjustment), a negative one is money handed in
     * (cash_handover, a credit adjustment). Handovers clear the oldest
     * cash first; whatever is left of each positive row is aged by its own
     * date. Sums are kept in paise so a bucket never drifts by a rupee.
     *
     * @param  list<array{amount: mixed, created_at: mixed}> $rows
     * @return array{buckets: array<string, float>, total: float, credit: float, oldestDays: ?int}
     *         total = cash still owed (equals the balance when it is positive);
     *         credit = handed in beyond what was owed (an advance), else 0
     */
    public static function agingBuckets(array $rows, ?int $now = null): array
    {
        $now     = $now ?? time();
        $debits  = [];
        $credit  = 0;
        foreach ($rows as $i => $r) {
            $paise = (int) round(((float) ($r['amount'] ?? 0)) * 100);
            if ($paise > 0) {
                $ts = strtotime((string) ($r['created_at'] ?? ''));
                $debits[] = ['p' => $paise, 'ts' => $ts === false ? $now : $ts, 'i' => $i];
            } elseif ($paise < 0) {
                $credit += -$paise;
            }
        }
        usort($debits, static fn(array $a, array $b): int => [$a['ts'], $a['i']] <=> [$b['ts'], $b['i']]);

        $buckets = array_fill_keys(self::AGING_BUCKETS, 0);
        $oldest  = null;
        foreach ($debits as $d) {
            $paid    = min($credit, $d['p']);
            $credit -= $paid;
            $left    = $d['p'] - $paid;
            if ($left <= 0) {
                continue;
            }
            $age = max(0, intdiv($now - $d['ts'], 86400));
            $buckets[self::bucketFor($age)] += $left;
            $oldest = $oldest === null ? $age : max($oldest, $age);
        }

        $out = [];
        foreach ($buckets as $k => $p) {
            $out[$k] = round($p / 100, 2);
        }
        return [
            'buckets'    => $out,
            'total'      => round(array_sum($buckets) / 100, 2),
            'credit'     => round($credit / 100, 2),
            'oldestDays' => $oldest,
        ];
    }

    /* =================================================================
     *  Cancellation bands
     * ================================================================= */

    /**
     * Which band a cancel falls in, measured to the departure:
     *   more than 48 h before -> gt48; 24 h up to 48 h (both included) -> 24to48;
     *   0 up to (not incl.) 24 h -> lt24; after the departure -> after;
     *   no departure on file -> unknown.
     */
    public static function cancelBand(?int $depTs, ?int $cancelTs): string
    {
        if ($depTs === null || $cancelTs === null || $depTs <= 0 || $cancelTs <= 0) {
            return 'unknown';
        }
        $secs = $depTs - $cancelTs;
        return match (true) {
            $secs < 0          => 'after',
            $secs < 24 * 3600  => 'lt24',
            $secs <= 48 * 3600 => '24to48',
            default            => 'gt48',
        };
    }

    /* =================================================================
     *  Ticket resend by email / SMS (booking-view.php)
     * ================================================================= */

    public static function emailResendOn(): bool
    {
        return Settings::getBool('desk_resend_email_on', false) && Settings::getBool('email_enabled', true);
    }

    /** Why the email button is greyed out ('' when it works). */
    public static function emailOffReason(): string
    {
        if (!Settings::getBool('desk_resend_email_on', false)) {
            return 'Switched off — the office turns on desk_resend_email_on in Settings → Notifications.';
        }
        if (!Settings::getBool('email_enabled', true)) {
            return 'Email is switched off for the whole site (email_enabled).';
        }
        return '';
    }

    public static function smsResendOn(): bool
    {
        return Settings::getBool('desk_resend_sms_on', false) && Settings::getBool('sms_enabled', false);
    }

    /** Why the SMS button is greyed out ('' when it works). */
    public static function smsOffReason(): string
    {
        if (!Settings::getBool('sms_enabled', false)) {
            return 'The SMS channel is OFF (sms_enabled in Settings → Notifications).';
        }
        if (!Settings::getBool('desk_resend_sms_on', false)) {
            return 'SMS resend is switched off — the office turns on desk_resend_sms_on in Settings → Notifications.';
        }
        return '';
    }

    /** @return array{company: string, pnr: string, route: string, when: string, board: string, seats: string, amount: string} */
    private static function ticketFacts(array $b): array
    {
        $leg  = $b['legs'][0] ?? [];
        $mode = (string) (($b['booking_mode'] ?? '') ?: 'sharing');
        $raw  = $leg['seats'] ?? ($b['seats'] ?? []);
        $seats = implode(', ', array_map(static fn($s): string => class_exists('Seats')
            ? Seats::displayLabel((string) $s, 'sleeper', $mode) : (string) $s, is_array($raw) ? $raw : []));
        try {
            $company = (string) (Settings::companyFor((string) ($b['counter_code'] ?? ''), (string) ($b['entity'] ?? ''))['name'] ?? '');
        } catch (Throwable $e) {
            $company = '';
        }
        if ($company === '') {
            $company = Settings::getString('company_name', defined('APP_NAME') ? APP_NAME : '');
        }
        $date = (string) ($leg['travel_date'] ?? '');
        $dep  = (string) ($leg['dep_time'] ?? '');
        return [
            'company' => $company,
            'pnr'     => (string) ($b['pnr'] ?? ''),
            'route'   => !empty($leg['from_city']) ? trim((string) $leg['from_city']) . ' → ' . trim((string) ($leg['to_city'] ?? '')) : '',
            'when'    => $date !== '' ? formatDate($date) . ($dep !== '' ? ' · ' . substr($dep, 0, 5) : '') : '',
            'board'   => trim(explode('·', (string) ($leg['boarding_stop'] ?? ''))[0]),
            'seats'   => $seats,
            'amount'  => inr((float) ($b['total_amount'] ?? 0)),
        ];
    }

    /** @return array{subject: string, html: string, text: string} */
    public static function ticketEmail(array $b): array
    {
        $f    = self::ticketFacts($b);
        $img  = Ticket::imageUrl($f['pnr']);
        $pdf  = Ticket::downloadUrl($f['pnr']);
        $rows = ['Ticket' => $f['pnr'], 'Route' => $f['route'], 'Journey' => $f['when'], 'Pickup' => $f['board'],
                 'Seat(s)' => $f['seats'], 'Total' => $f['amount']];
        $table = '';
        $text  = $f['company'] . ' - your ticket ' . $f['pnr'] . "\n";
        foreach ($rows as $k => $v) {
            if (trim($v) === '') {
                continue;
            }
            $table .= '<tr><td style="padding:6px 10px;color:#555">' . e($k) . '</td><td style="padding:6px 10px"><strong>' . e($v) . '</strong></td></tr>';
            $text  .= $k . ': ' . $v . "\n";
        }
        $text .= 'Ticket: ' . $img . "\nPrint (PDF): " . $pdf . "\n";
        $html = Notify::wrapEmail('Your ticket · ' . $f['pnr'],
            '<p>Namaste,</p><p>As asked, here is your ticket again. The ticket picture is attached.</p>'
            . '<table style="border-collapse:collapse;border:1px solid #ddd">' . $table . '</table>'
            . '<p style="margin-top:14px"><a href="' . e($img) . '">Open the ticket</a> · <a href="' . e($pdf) . '">Printable PDF</a></p>'
            . '<p style="color:#666;font-size:12px">तपाईंको टिकट यसै इमेलमा संलग्न छ। राम्रो यात्रा होस्!</p>'
            . '<p>' . e($f['company']) . '</p>');
        return ['subject' => 'Your ticket ' . $f['pnr'] . ' · ' . $f['company'], 'html' => $html, 'text' => $text];
    }

    /**
     * Email the ticket (PNG attached) to $to. Never throws.
     *
     * @return array{ok: bool, to: string, reason: string}
     */
    public static function sendTicketEmail(array $b, string $to, int $adminId = 0): array
    {
        $out = ['ok' => false, 'to' => '', 'reason' => ''];
        try {
            if (!self::emailResendOn()) {
                $out['reason'] = self::emailOffReason();
                return $out;
            }
            $email = Security::email($to);
            if ($email === '') {
                $out['reason'] = 'Enter a valid email address.';
                return $out;
            }
            $out['to'] = $email;
            $mail = self::ticketEmail($b);
            $attachments = [];
            try {
                $png = Ticket::pngPath((int) ($b['id'] ?? 0));
                $size = is_file($png) ? (int) filesize($png) : 0;
                if ($size > 0 && $size < 8 * 1024 * 1024) {
                    $attachments[] = ['name' => 'ticket-' . (string) $b['pnr'] . '.png', 'mime' => 'image/png', 'data' => (string) file_get_contents($png)];
                }
            } catch (Throwable $e) {
                Logger::warning('Ticket resend email: PNG not attached', ['pnr' => (string) ($b['pnr'] ?? ''), 'err' => $e->getMessage()], 'mail');
            }
            $ok = Notify::email($email, $mail['subject'], $mail['html'], $mail['text'], $attachments);
            $out['ok'] = $ok;
            $out['reason'] = $ok ? '' : 'The mail server did not accept it (see logs, channel mail).';
            self::logEmail((int) ($b['id'] ?? 0), $email, $ok, $adminId, $out['reason']);
            return $out;
        } catch (Throwable $e) {
            Logger::warning('Ticket resend email failed', ['pnr' => (string) ($b['pnr'] ?? ''), 'err' => $e->getMessage()], 'mail');
            $out['reason'] = 'Email failed: ' . $e->getMessage();
            return $out;
        }
    }

    /** One message_logs row, so the booking's delivery history shows the email too. */
    private static function logEmail(int $bookingId, string $to, bool $ok, int $adminId, string $err): void
    {
        try {
            $row = ['booking_id' => $bookingId ?: null, 'channel' => 'email', 'provider' => 'email',
                    'to_number' => substr($to, 0, 32), 'body' => 'Ticket resend by email (desk)',
                    'status' => $ok ? 'sent' : 'failed', 'provider_ref' => '', 'error' => $err !== '' ? mb_substr($err, 0, 255) : null];
            try {
                Database::insert('message_logs', $row + ['purpose' => 'ticket_resend', 'admin_id' => $adminId ?: null]);
            } catch (Throwable $e) {
                Database::insert('message_logs', $row);   // an older table without purpose / admin_id
            }
        } catch (Throwable $e) {
            // message_logs is informational
        }
    }

    /** The plain SMS: the same facts the confirmation SMS carries. */
    public static function ticketSmsText(array $b): string
    {
        $f = self::ticketFacts($b);
        return $f['company'] . ': Ticket ' . $f['pnr'] . ' CONFIRMED.'
            . ($f['seats'] !== '' ? ' Seat ' . $f['seats'] . '.' : '')
            . ($f['route'] !== '' ? ' ' . $f['route'] . '.' : '')
            . ($f['when'] !== '' ? ' ' . $f['when'] . '.' : '')
            . ' Ticket: ' . Ticket::imageUrl($f['pnr']);
    }

    /**
     * SMS the ticket link to the booking's contact phone. Notify::sms()
     * writes its own message_logs row. Never throws.
     *
     * @return array{ok: bool, to: string, reason: string}
     */
    public static function sendTicketSms(array $b): array
    {
        $out = ['ok' => false, 'to' => '', 'reason' => ''];
        try {
            if (!self::smsResendOn()) {
                $out['reason'] = self::smsOffReason();
                return $out;
            }
            $phone = Notify::usablePhone($b['contact_phone'] ?? '');
            if ($phone === '') {
                $out['reason'] = 'This booking has no usable contact phone number on file.';
                return $out;
            }
            $out['to'] = (string) $b['contact_phone'];
            $ok = Notify::sms((string) $b['contact_phone'], self::ticketSmsText($b), Notify::bookingCountryHint($b), (int) ($b['id'] ?? 0) ?: null);
            $out['ok'] = $ok;
            $out['reason'] = $ok ? '' : 'The SMS provider did not accept it (see WhatsApp & SMS Logs).';
            return $out;
        } catch (Throwable $e) {
            Logger::warning('Ticket resend SMS failed', ['pnr' => (string) ($b['pnr'] ?? ''), 'err' => $e->getMessage()]);
            $out['reason'] = 'SMS failed: ' . $e->getMessage();
            return $out;
        }
    }
}
