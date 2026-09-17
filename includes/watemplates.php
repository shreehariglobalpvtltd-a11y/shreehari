<?php
/**
 * =====================================================================
 *  includes/watemplates.php — the office's WhatsApp message book
 *  (17 Sep 2026)
 *
 *  Every message the office sends by hand used to be typed into the
 *  phone, or built inline on one page (admin/bookings.php) with a
 *  hard-coded +91 on another. This file is the single place those
 *  messages come from:
 *
 *    REGISTRY   what can be sent, to whom, and which permission it needs
 *    compose()  the message for one purpose + one target, built from the
 *               SAME readers the panel pages use (BookingService::detail,
 *               AgentWallet::statement/balances/advanceSummary/loans …),
 *               so a WhatsApp statement and the on-screen statement can
 *               never disagree.
 *    agentRecipient()  the agent's number AND country (+91 / +977) — the
 *               piece the booking path always had and the agent path did
 *               not (admin_profiles.country_code, upgrade-2026-09-wa-templates.sql).
 *
 *  Nothing here sends. admin/api/wa-send.php previews, sends through
 *  Notify::whatsapp() (which logs to message_logs) or hands the staff phone
 *  a wa.me link. Wording is English with a short Nepali line, like every
 *  passenger message in Notify. Bodies deliberately say "Agent ID" and
 *  never "code" — Notify::logMessage masks "code ####" as an OTP.
 *
 *  Included on demand: require_once INCLUDE_PATH . '/watemplates.php'.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/agentwallet.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/seats.php';
require_once __DIR__ . '/ticket.php';
require_once __DIR__ . '/notify.php';

final class WaTemplates
{
    /**
     * What the office can send. Keys are the `purpose` stored on
     * message_logs. perm = the Auth::can() key the sender must hold;
     * target = what the button must name (agent | booking | office);
     * contentSidKey = the settings row holding an approved Twilio Content
     * template for this purpose (blank = free text, which WhatsApp only
     * delivers inside an open 24-hour chat with that number).
     *
     * @var array<string, array{label:string, audience:string, perm:string, target:string, media:bool, contentSidKey:string, hint:string}>
     */
    public const REGISTRY = [
        'agent_statement' => ['label' => 'Account statement (PDF)', 'audience' => 'agent', 'perm' => 'commissions.view', 'target' => 'agent', 'media' => true,  'contentSidKey' => 'twilio_content_sid_agent_statement', 'hint' => 'Opening / closing balances, every movement in the period, PDF attached'],
        'agent_history' => ['label' => 'Booking history', 'audience' => 'agent', 'perm' => 'commissions.view', 'target' => 'agent', 'media' => false, 'contentSidKey' => 'twilio_content_sid_agent_statement', 'hint' => 'Every ticket the agent sold in the period'],
        'agent_commission' => ['label' => 'Commission earned / paid / pending', 'audience' => 'agent', 'perm' => 'commissions.view', 'target' => 'agent', 'media' => false, 'contentSidKey' => 'twilio_content_sid_agent_statement', 'hint' => 'This month and lifetime'],
        'agent_advance' => ['label' => 'Advance / loan balance', 'audience' => 'agent', 'perm' => 'commissions.view', 'target' => 'agent', 'media' => false, 'contentSidKey' => 'twilio_content_sid_agent_statement', 'hint' => 'Open advances and loans with what is still outstanding'],
        'agent_cash_settlement' => ['label' => 'Cash settlement details', 'audience' => 'agent', 'perm' => 'commissions.view', 'target' => 'agent', 'media' => false, 'contentSidKey' => 'twilio_content_sid_settlement', 'hint' => 'Cash held now and the last handovers / payouts with voucher numbers'],
        'agent_outstanding' => ['label' => 'Outstanding balance', 'audience' => 'agent', 'perm' => 'commissions.view', 'target' => 'agent', 'media' => false, 'contentSidKey' => 'twilio_content_sid_payment_reminder', 'hint' => 'What the agent owes / is owed on balance today'],
        'agent_settlement_done' => ['label' => 'Settlement receipt', 'audience' => 'agent', 'perm' => 'commissions.pay', 'target' => 'agent', 'media' => false, 'contentSidKey' => 'twilio_content_sid_settlement', 'hint' => 'Confirms one payout / cash handover with its voucher number'],
        'agent_payment_reminder' => ['label' => 'Payment reminder', 'audience' => 'agent', 'perm' => 'commissions.pay', 'target' => 'agent', 'media' => false, 'contentSidKey' => 'twilio_content_sid_payment_reminder', 'hint' => 'Asks the agent to hand over the cash they hold'],
        'agent_daily_summary' => ['label' => 'Daily summary', 'audience' => 'agent', 'perm' => 'commissions.view', 'target' => 'agent', 'media' => false, 'contentSidKey' => 'twilio_content_sid_agent_statement', 'hint' => "Today's sales, passengers, commission and balances"],
        'agent_monthly_summary' => ['label' => 'Monthly summary', 'audience' => 'agent', 'perm' => 'commissions.view', 'target' => 'agent', 'media' => false, 'contentSidKey' => 'twilio_content_sid_agent_statement', 'hint' => "This month's sales, passengers, commission and balances"],
        'booking_ticket' => ['label' => 'Ticket (image + links)', 'audience' => 'customer', 'perm' => 'bookings.view', 'target' => 'booking', 'media' => true,  'contentSidKey' => 'twilio_content_sid', 'hint' => 'The confirmed ticket exactly as the automatic send'],
        'booking_confirmation' => ['label' => 'Booking status', 'audience' => 'customer', 'perm' => 'bookings.view', 'target' => 'booking', 'media' => false, 'contentSidKey' => 'twilio_content_sid_booking_detail', 'hint' => 'Confirmed / awaiting payment / cancelled wording that matches the booking'],
        'booking_passengers' => ['label' => 'Passenger & seat details', 'audience' => 'customer', 'perm' => 'bookings.view', 'target' => 'booking', 'media' => false, 'contentSidKey' => 'twilio_content_sid_booking_detail', 'hint' => 'Route, date, bus, seats with names, pickup and drop'],
        'customer_payment_reminder' => ['label' => 'Payment reminder', 'audience' => 'customer', 'perm' => 'payments.verify', 'target' => 'booking', 'media' => false, 'contentSidKey' => 'twilio_content_sid_payment_reminder', 'hint' => 'Amount due, how to pay, and when the hold expires'],
        'admin_daily_summary' => ['label' => 'Office daily summary', 'audience' => 'office', 'perm' => 'dashboard.view', 'target' => 'office', 'media' => false, 'contentSidKey' => 'twilio_content_sid_agent_statement', 'hint' => "Today's tickets, money, cancellations, pending payments"],
        'admin_monthly_summary' => ['label' => 'Office monthly summary', 'audience' => 'office', 'perm' => 'dashboard.view', 'target' => 'office', 'media' => false, 'contentSidKey' => 'twilio_content_sid_agent_statement', 'hint' => "This month's tickets, money, commission and cash with agents"],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function registry(): array
    {
        return self::REGISTRY;
    }

    /** The purposes a page may offer for one target type. @return array<string, string> purpose => label */
    public static function purposesFor(string $target): array
    {
        $out = [];
        foreach (self::REGISTRY as $k => $r) {
            if ($r['target'] === $target && Auth::can($r['perm'])) {
                $out[$k] = $r['label'];
            }
        }
        $out['text'] = trim($out['text']);
            return $out;
    }

    /* =================================================================
     *  Recipients
     * ================================================================= */

    /**
     * The agent's WhatsApp number and country. Precedence for the number:
     * admin_profiles.whatsapp, then admins.phone. Country: the profile's
     * country_code (977 / 91) set by the office, else a 977 / 91 prefix the
     * number itself carries, else unknown (Notify then applies
     * whatsapp_default_country for a bare 10-digit number).
     *
     * @return array{id:int, name:string, code:string, raw:string, digits:string, hint:?string, country:string}
     */
    public static function agentRecipient(int $adminId): array
    {
        $row = Database::fetch('SELECT id, full_name, username, phone FROM admins WHERE id = :i LIMIT 1', ['i' => $adminId]);
        if ($row === null) {
            throw new RuntimeException('Agent not found.');
        }
        $profile = AgentWallet::profile($adminId);
        $raw     = trim((string) ($profile['whatsapp'] ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($row['phone'] ?? ''));
        }
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        $country = (string) ($profile['country_code'] ?? '');
        $hint    = $country === '977' ? 'NP' : ($country === '91' ? 'IN' : null);
        if ($hint === null && $digits !== '') {
            $rc   = resolvePhoneCountry('', $raw);
            $hint = $rc !== '' ? $rc : null;
            if ($hint === null && strlen($digits) >= 12) {
                $hint = str_starts_with($digits, '977') ? 'NP' : (str_starts_with($digits, '91') ? 'IN' : null);
            }
        }
        return [
            'id'      => (int) $row['id'],
            'name'    => (string) ($row['full_name'] ?: $row['username']),
            'code'    => AgentWallet::agentCodeLabel($adminId),
            'raw'     => $raw,
            'digits'  => $digits,
            'hint'    => $hint,
            'country' => $hint === 'NP' ? '977' : ($hint === 'IN' ? '91' : ''),
        ];
    }

    /**
     * International digits (no '+') the way Notify::intlDigits() dials them,
     * so the preview and the wa.me hand-off show the exact number the API
     * would use. 10 digits take the hinted country, else the office default.
     */
    public static function intl(string $digits, ?string $hint): string
    {
        $digits = preg_replace('/\D/', '', $digits) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10) {
            $cc = $hint === 'NP' ? '977' : ($hint === 'IN' ? '91' : Settings::getString('whatsapp_default_country', '91'));
            return $cc . $digits;
        }
        return $digits;
    }

    /**
     * Has this number written to the business in the last 24 hours? WhatsApp
     * only delivers FREE-FORM business messages inside that window; outside
     * it an approved template is required. api/whatsapp-webhook.php records
     * every inbound message here (kv_store scope 'wa_inbound').
     */
    public static function inSessionWindow(string $intlDigits): bool
    {
        try {
            $at = Database::scalar(
                "SELECT kvalue FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :k LIMIT 1",
                ['k' => $intlDigits],
                null
            );
        } catch (Throwable $e) {
            return false;
        }
        return $at !== null && (time() - (int) $at) < 24 * 3600;
    }

    /** Record an inbound message from a number (called by the webhook). */
    public static function noteInbound(string $intlDigits): void
    {
        $intlDigits = preg_replace('/\D/', '', $intlDigits) ?? '';
        if ($intlDigits === '') {
            return;
        }
        try {
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'webhook')
                 ON DUPLICATE KEY UPDATE kvalue = :v2, updated_by = 'webhook'",
                ['k' => $intlDigits, 'v' => (string) time(), 'v2' => (string) time()]
            );
        } catch (Throwable $e) {
            Logger::warning('wa_inbound note failed: ' . $e->getMessage(), [], 'whatsapp');
        }
    }

    /* =================================================================
     *  Period statistics (the same numbers cron/daily-summary.php sends)
     * ================================================================= */

    /**
     * One agent's numbers between $d0 (inclusive) and $d1 (exclusive),
     * both 'Y-m-d H:i:s' or 'Y-m-d'.
     *
     * @return array{bookings:int, confirmed:int, cancelled:int, pax:int, revenue:float, commission:float, cash:float, rows:array<int,array<string,mixed>>}
     */
    public static function agentPeriodStats(int $aid, string $d0, string $d1, int $rowLimit = 40): array
    {
        $out = ['bookings' => 0, 'confirmed' => 0, 'cancelled' => 0, 'pax' => 0, 'revenue' => 0.0, 'commission' => 0.0, 'cash' => 0.0, 'rows' => []];
        try {
            $agg = Database::fetch(
                "SELECT COUNT(*) AS n,
                        SUM(CASE WHEN status IN ('confirmed','completed') THEN 1 ELSE 0 END) AS confirmed,
                        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                        COALESCE(SUM(CASE WHEN status IN ('confirmed','completed') THEN total_amount ELSE 0 END), 0) AS revenue
                   FROM bookings
                  WHERE sold_by_admin_id = :a AND created_at >= :d0 AND created_at < :d1",
                ['a' => $aid, 'd0' => $d0, 'd1' => $d1]
            ) ?? [];
            $out['bookings']  = (int) ($agg['n'] ?? 0);
            $out['confirmed'] = (int) ($agg['confirmed'] ?? 0);
            $out['cancelled'] = (int) ($agg['cancelled'] ?? 0);
            $out['revenue']   = (float) ($agg['revenue'] ?? 0);
            $out['pax'] = (int) Database::scalar(
                'SELECT COUNT(*) FROM booking_passengers bp JOIN bookings b ON b.id = bp.booking_id
                  WHERE b.sold_by_admin_id = :a AND b.created_at >= :d0 AND b.created_at < :d1',
                ['a' => $aid, 'd0' => $d0, 'd1' => $d1], 0
            );
            $out['commission'] = (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM agent_ledger
                  WHERE agent_admin_id = :a AND account = 'commission'
                    AND entry_type IN ('commission', 'commission_void') AND created_at >= :d0 AND created_at < :d1",
                ['a' => $aid, 'd0' => $d0, 'd1' => $d1], 0
            );
            $out['cash'] = (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM agent_ledger
                  WHERE agent_admin_id = :a AND entry_type = 'cash_due' AND created_at >= :d0 AND created_at < :d1",
                ['a' => $aid, 'd0' => $d0, 'd1' => $d1], 0
            );
            $out['rows'] = Database::fetchAll(
                "SELECT b.pnr, b.status, b.total_amount, b.created_at, bl.travel_date, bl.seat_count,
                        r.from_city, r.to_city,
                        (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id = b.id ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS passenger
                   FROM bookings b
                   LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                   LEFT JOIN schedules s ON s.id = bl.schedule_id
                   LEFT JOIN routes r ON r.id = s.route_id
                  WHERE b.sold_by_admin_id = :a AND b.created_at >= :d0 AND b.created_at < :d1
                  ORDER BY b.id DESC
                  LIMIT " . max(1, min(200, $rowLimit)),
                ['a' => $aid, 'd0' => $d0, 'd1' => $d1]
            );
        } catch (Throwable $e) {
            Logger::warning('agentPeriodStats: ' . $e->getMessage(), ['agent' => $aid], 'whatsapp');
        }
        $out['text'] = trim($out['text']);
            return $out;
    }

    /**
     * Company-wide numbers for a period.
     *
     * @return array{bookings:int, confirmed:int, cancelled:int, pax:int, revenue:float, pending:int, commission:float, cashWithAgents:float}
     */
    public static function adminPeriodStats(string $d0, string $d1): array
    {
        $out = ['bookings' => 0, 'confirmed' => 0, 'cancelled' => 0, 'pax' => 0, 'revenue' => 0.0, 'pending' => 0, 'commission' => 0.0, 'cashWithAgents' => 0.0];
        try {
            $agg = Database::fetch(
                "SELECT COUNT(*) AS n,
                        SUM(CASE WHEN status IN ('confirmed','completed') THEN 1 ELSE 0 END) AS confirmed,
                        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                        COALESCE(SUM(CASE WHEN status IN ('confirmed','completed') THEN total_amount ELSE 0 END), 0) AS revenue
                   FROM bookings WHERE created_at >= :d0 AND created_at < :d1",
                ['d0' => $d0, 'd1' => $d1]
            ) ?? [];
            $out['bookings']  = (int) ($agg['n'] ?? 0);
            $out['confirmed'] = (int) ($agg['confirmed'] ?? 0);
            $out['cancelled'] = (int) ($agg['cancelled'] ?? 0);
            $out['revenue']   = (float) ($agg['revenue'] ?? 0);
            $out['pax'] = (int) Database::scalar(
                'SELECT COUNT(*) FROM booking_passengers bp JOIN bookings b ON b.id = bp.booking_id
                  WHERE b.created_at >= :d0 AND b.created_at < :d1', ['d0' => $d0, 'd1' => $d1], 0
            );
            $out['pending'] = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE status = 'pending'", [], 0);
            $out['commission'] = (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM agent_ledger
                  WHERE account = 'commission' AND entry_type IN ('commission', 'commission_void')
                    AND created_at >= :d0 AND created_at < :d1", ['d0' => $d0, 'd1' => $d1], 0
            );
            $out['cashWithAgents'] = (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM agent_ledger WHERE account = 'cash'", [], 0
            );
        } catch (Throwable $e) {
            Logger::warning('adminPeriodStats: ' . $e->getMessage(), [], 'whatsapp');
        }
        $out['text'] = trim($out['text']);
            return $out;
    }

    /* =================================================================
     *  Composer
     * ================================================================= */

    /**
     * Build one message.
     *
     * $ctx: agent_id | pnr | from, to (Y-m-d) | ledger_id | note | phone, country
     * $actor: the signed-in admin row (id, full_name ...)
     *
     * @return array{purpose:string, label:string, to:string, intl:string, hint:?string, text:string,
     *               mediaUrl:?string, mediaType:string, templateVars:array<string,string>,
     *               contentSidKey:string, bookingId:?int, agentId:?int, recipientName:string, attachments:array<int,string>}
     */
    public static function compose(string $purpose, array $ctx, array $actor): array
    {
        $reg = self::REGISTRY[$purpose] ?? null;
        if ($reg === null) {
            throw new RuntimeException('Unknown message type.');
        }
        $co   = Settings::company();
        $name = (string) ($co['name'] ?? Settings::getString('company_name', APP_NAME));
        $note = trim((string) ($ctx['note'] ?? ''));
        // An optional line the office adds on the sheet; always on its own line.
        $note = $note !== '' ? "\n📝 " . Security::clean($note, 300) . "\n" : '';
        $days = max(1, Settings::getInt('wa_statement_link_days', 7));

        $out = [
            'purpose' => $purpose, 'label' => (string) $reg['label'], 'to' => '', 'intl' => '', 'hint' => null,
            'text' => '', 'mediaUrl' => null, 'mediaType' => '', 'templateVars' => [],
            'contentSidKey' => (string) $reg['contentSidKey'], 'bookingId' => null, 'agentId' => null,
            'recipientName' => '', 'attachments' => [],
        ];

        /* ---- Office summaries ------------------------------------- */
        if ($reg['target'] === 'office') {
            $to = Settings::getString('admin_whatsapp', '') ?: Settings::officeWhatsApp();
            if ($to === '') {
                throw new RuntimeException('No office WhatsApp number is set (Settings → admin_whatsapp).');
            }
            [$d0, $d1, $label] = self::period($purpose, $ctx);
            $s = self::adminPeriodStats($d0, $d1);
            $out['to'] = $to; $out['intl'] = self::intl($to, null); $out['recipientName'] = 'Office';
            $out['text'] = "🚌 " . $name . " — " . ($purpose === 'admin_daily_summary' ? 'Daily' : 'Monthly') . " summary\n"
                . "📅 " . $label . "\n"
                . "🎫 Bookings: " . $s['bookings'] . " (confirmed " . $s['confirmed'] . ", cancelled " . $s['cancelled'] . ")\n"
                . "👥 Passengers: " . $s['pax'] . "\n"
                . "💰 Revenue: " . inr($s['revenue']) . "\n"
                . "⏳ Payments awaiting verification: " . $s['pending'] . "\n"
                . "🤝 Agent commission accrued: " . inr($s['commission']) . "\n"
                . "💵 Cash currently with agents: " . inr($s['cashWithAgents'])
                . $note . ""
                . "सारांश — " . $label;
            $out['text'] = trim($out['text']);
            return $out;
        }

        /* ---- Customer / booking messages --------------------------- */
        if ($reg['target'] === 'booking') {
            $pnr = strtoupper(Security::clean((string) ($ctx['pnr'] ?? ''), 40));
            $b   = $pnr !== '' ? BookingService::detail($pnr) : null;
            if ($b === null) {
                throw new RuntimeException('Booking not found.');
            }
            $scope = Auth::bookingScopeAdminId();
            if ($scope !== null && (int) ($b['sold_by_admin_id'] ?? 0) !== $scope) {
                throw new RuntimeException('Booking not found.');
            }
            $phone = (string) ($b['contact_phone'] ?? '');
            if (Notify::usablePhone($phone) === '') {
                throw new RuntimeException('This booking has no usable contact number.');
            }
            $hint = Notify::bookingCountryHint($b);
            $out['to'] = $phone; $out['intl'] = self::intl($phone, $hint); $out['hint'] = $hint;
            $out['bookingId'] = (int) ($b['id'] ?? 0);
            $out['agentId']   = (int) ($b['sold_by_admin_id'] ?? 0) ?: null;
            $lead = '';
            foreach ((array) ($b['passengers'] ?? []) as $p) { $lead = (string) ($p['full_name'] ?? ''); if ($lead !== '') { break; } }
            $out['recipientName'] = $lead;
            $leg   = (array) (($b['legs'] ?? [])[0] ?? []);
            $route = trim((string) ($leg['from_city'] ?? ($b['from_city'] ?? ''))) . ' → ' . trim((string) ($leg['to_city'] ?? ($b['to_city'] ?? '')));
            $date  = formatDate((string) ($leg['travel_date'] ?? ($b['travel_date'] ?? null)));
            $dep   = (string) ($leg['dep_time'] ?? ($b['dep_time'] ?? ''));
            $seats = implode(', ', array_map('strval', (array) ($b['seats'] ?? [])));
            $amount = inr((float) ($b['total_amount'] ?? 0));
            $status = (string) ($b['status'] ?? '');

            switch ($purpose) {
                case 'booking_ticket':
                    if ($status !== 'confirmed') {
                        throw new RuntimeException('The ticket can only be sent for a confirmed booking (this one is ' . $status . ').');
                    }
                    $img = Ticket::imageUrl($pnr);
                    $out['text'] = "🚌 " . $name . "\n"
                        . "Your booking " . $pnr . " is CONFIRMED ✅\n"
                        . "Amount: " . $amount . "\n"
                        . "Your ticket (image): " . $img . "\n"
                        . "Print copy (PDF): " . Ticket::downloadUrl($pnr) . "\n"
                        . "Have a safe journey." . $note;
                    $out['mediaUrl'] = Settings::getBool('whatsapp_send_pdf', true) ? $img : null;
                    $out['mediaType'] = 'image';
                    $out['templateVars'] = Notify::ticketVars($b);
                    $out['attachments'] = [$img];
                    break;
                case 'booking_passengers':
                    $lines = [];
                    foreach ((array) ($b['passengers'] ?? []) as $p) {
                        $lines[] = '• ' . ($p['seat_no'] !== '' ? Seats::displayLabel((string) $p['seat_no'], (string) ($leg['coach_type'] ?? 'sleeper'), (string) ($b['booking_mode'] ?? 'sharing')) . ' — ' : '')
                            . (string) ($p['full_name'] ?? '')
                            . (!empty($p['age']) ? ' (' . (int) $p['age'] . ')' : '')
                            . (!empty($p['gender']) ? ' ' . mb_substr((string) $p['gender'], 0, 1) : '')
                            . (!empty($p['id_number']) ? ' · ' . (string) ($p['id_type'] ?? 'ID') . ' ' . (string) $p['id_number'] : '');
                    }
                    $out['text'] = "🚌 " . $name . "\n"
                        . "Booking " . $pnr . " · " . ucfirst($status) . "\n"
                        . $route . " · " . $date . ($dep !== '' ? ' · ' . substr($dep, 0, 5) : '') . "\n"
                        . (!empty($leg['bus_number']) ? "Bus: " . (string) $leg['bus_number'] . "\n" : '')
                        . "Passengers:\n" . implode("\n", $lines) . "\n"
                        . (!empty($leg['boarding_stop']) ? "Pickup: " . self::stopName((string) $leg['boarding_stop']) . "\n" : '')
                        . (!empty($leg['drop_stop']) ? "Drop: " . self::stopName((string) $leg['drop_stop']) . "\n" : '')
                        . "Amount: " . $amount . $note . ""
                        . "यात्रु तथा सिट विवरण माथि छ।";
                    break;
                case 'customer_payment_reminder':
                    if ($status !== 'pending') {
                        throw new RuntimeException('A payment reminder only applies to a booking still awaiting payment (this one is ' . $status . ').');
                    }
                    $upi  = Settings::getString('upi_id', '');
                    $exp  = (string) ($b['expires_at'] ?? '');
                    $out['text'] = "🚌 " . $name . "\n"
                        . "Namaste" . ($lead !== '' ? ' ' . $lead : '') . "! Your booking " . $pnr . " (" . $route . ", " . $date . ") is waiting for payment.\n"
                        . "Amount due: " . $amount . "\n"
                        . ($upi !== '' ? "Pay by UPI to " . $upi . " and reply with the screenshot / UTR.\n" : "Pay and reply with the screenshot / UTR.\n")
                        . ($exp !== '' ? "Seats are held until " . date('d M, H:i', (int) strtotime($exp)) . ".\n" : '')
                        . "Office: " . Settings::officePhone() . $note . ""
                        . "भुक्तानी बाँकी छ — कृपया माथिको रकम तिरेर स्क्रिनसट पठाउनुहोस्।";
                    break;
                default: // booking_confirmation
                    $img = $status === 'confirmed' ? Ticket::imageUrl($pnr) : '';
                    if ($status === 'confirmed') {
                        $out['text'] = "Namaste! " . $name . "\nBooking " . $pnr . " CONFIRMED ✅\n" . $route . " | " . $date . "\nSeats: " . $seats . "\nAmount: " . $amount . "\nTicket: " . $img . $note . "शुभ यात्रा!";
                        $out['attachments'] = [$img];
                    } elseif ($status === 'cancelled') {
                        $out['text'] = "Namaste! " . $name . "\nBooking " . $pnr . " is CANCELLED.\n" . $route . " | " . $date . $note . "रिफन्ड सम्बन्धी प्रश्न भए यहीँ जवाफ दिनुहोस्।";
                    } else {
                        $out['text'] = "Namaste! " . $name . "\nBooking " . $pnr . " received — payment verification pending.\n" . $route . " | " . $date . "\nAmount: " . $amount . $note . "भुक्तानी प्रमाणित भएपछि टिकट यहीँ पठाइनेछ।";
                    }
                    break;
            }
            $out['text'] = trim($out['text']);
            return $out;
        }

        /* ---- Agent messages ---------------------------------------- */
        $agentId = (int) ($ctx['agent_id'] ?? 0);
        if ($agentId <= 0) {
            throw new RuntimeException('Choose an agent.');
        }
        $scope = Auth::bookingScopeAdminId();
        if ($scope !== null && $scope !== $agentId) {
            throw new RuntimeException('Agent not found.');
        }
        $rcpt = self::agentRecipient($agentId);
        if (Notify::usablePhone($rcpt['digits']) === '') {
            throw new RuntimeException('This agent has no usable WhatsApp / mobile number on file.');
        }
        $out['to'] = $rcpt['digits']; $out['intl'] = self::intl($rcpt['digits'], $rcpt['hint']); $out['hint'] = $rcpt['hint'];
        $out['agentId'] = $agentId; $out['recipientName'] = $rcpt['name'];
        $who  = $rcpt['name'] . ($rcpt['code'] !== '' ? ' (Agent ID ' . $rcpt['code'] . ')' : '');
        $bal  = AgentWallet::balances($agentId);
        $net  = AgentWallet::netPosition($agentId);
        $head = "🚌 " . $name . "\n" . $who . "\n";
        $balLine = "Commission due to you: " . inr($bal['commission']) . " · Cash you hold: " . inr($bal['cash']) . "\n";
        $netLine = $net['net'] > 0.009
            ? "Net: you owe the company " . inr($net['net'])
            : ($net['net'] < -0.009 ? "Net: the company owes you " . inr(-$net['net']) : "Net: settled");

        switch ($purpose) {
            case 'agent_statement': {
                [$from, $to, $label] = self::range($ctx);
                $st = AgentWallet::statement($agentId, $from, $to, '');
                $sum = ['commission' => 0.0, 'commission_void' => 0.0, 'payout' => 0.0, 'cash_due' => 0.0, 'cash_handover' => 0.0, 'adjustment' => 0.0];
                foreach ($st['rows'] as $r) { $t = (string) $r['entry_type']; if (isset($sum[$t])) { $sum[$t] += (float) $r['amount']; } }
                $pdf = null;
                try {
                    require_once __DIR__ . '/statement.php';
                    $pdf = Statement::agentStatementPdf($agentId, $from, $to, (int) ($actor['id'] ?? 0));
                } catch (Throwable $e) {
                    Logger::warning('statement pdf: ' . $e->getMessage(), ['agent' => $agentId], 'whatsapp');
                }
                $out['text'] = $head
                    . "Account statement · " . $label . "\n"
                    . "Opening: commission due " . inr(AgentWallet::openingBalance($agentId, $from, 'commission')) . " · cash held " . inr(AgentWallet::openingBalance($agentId, $from, 'cash')) . "\n"
                    . "Commission earned " . inr($sum['commission']) . " · reversed " . inr(-$sum['commission_void']) . " · paid out " . inr(-$sum['payout']) . "\n"
                    . "Cash collected " . inr($sum['cash_due']) . " · handed over " . inr(-$sum['cash_handover']) . "\n"
                    . ($sum['adjustment'] != 0.0 ? "Advances / salary / corrections: " . inr($sum['adjustment']) . "\n" : '')
                    . "Closing: " . $balLine . $netLine . "\n"
                    . ($pdf !== null ? "Statement PDF (" . $days . " days): " . $pdf['url'] . "\n" : '')
                    . $note . "खाता विवरण — " . $label;
                if ($pdf !== null) { $out['mediaUrl'] = $pdf['url']; $out['mediaType'] = 'document'; $out['attachments'] = [$pdf['url']]; }
                break;
            }
            case 'agent_history': {
                [$from, $to, $label] = self::range($ctx);
                $s = self::agentPeriodStats($agentId, $from . ' 00:00:00', addDaysISO($to, 1) . ' 00:00:00', 40);
                $lines = [];
                foreach ($s['rows'] as $r) {
                    $lines[] = '• ' . formatDate((string) ($r['travel_date'] ?? null), 'd M') . ' ' . (string) $r['pnr'] . ' ' . (string) ($r['passenger'] ?? '') . ' ×' . (int) ($r['seat_count'] ?? 1) . ' ' . inr((float) $r['total_amount']) . ' ' . ucfirst((string) $r['status']);
                }
                $out['text'] = $head . "Booking history · " . $label . "\n"
                    . "Tickets: " . $s['bookings'] . " (confirmed " . $s['confirmed'] . ", cancelled " . $s['cancelled'] . ") · Passengers: " . $s['pax'] . " · Sales: " . inr($s['revenue']) . "\n"
                    . ($lines !== [] ? implode("\n", $lines) . ($s['bookings'] > count($lines) ? "\n… and " . ($s['bookings'] - count($lines)) . " more" : '') . "\n" : "No tickets in this period.\n")
                    . $note . "बुकिङ इतिहास — " . $label;
                break;
            }
            case 'agent_commission': {
                $sum = AgentWallet::summary($agentId);
                $out['text'] = $head . "Commission summary\n"
                    . "This month earned: " . inr((float) ($sum['earnedMonth'] ?? 0)) . "\n"
                    . "Lifetime earned: " . inr((float) ($sum['earned'] ?? 0)) . " · reversed: " . inr((float) ($sum['reversed'] ?? 0)) . " · paid out: " . inr((float) ($sum['paidOut'] ?? 0)) . "\n"
                    . "Pending (due to you now): " . inr($bal['commission']) . "\n"
                    . $note . "कमिसन विवरण — बाँकी " . inr($bal['commission']);
                break;
            }
            case 'agent_advance': {
                AgentWallet::syncLoanRecovery($agentId);
                $adv   = AgentWallet::advanceSummary($agentId);
                $loans = AgentWallet::loans($agentId, 'open');
                $lines = [];
                foreach ($loans as $l) {
                    $lines[] = '• ' . AgentWallet::loanLabel($l) . ' · ' . formatDate((string) $l['issued_on'], 'd M Y') . ' · given ' . inr((float) $l['principal']) . ' · recovered ' . inr((float) $l['recovered']) . ' · outstanding ' . inr((float) $l['outstanding']);
                }
                $out['text'] = $head . "Advance / loan balance\n"
                    . "Advances given: " . inr((float) $adv['given']) . " · repaid: " . inr((float) $adv['repaid']) . " · outstanding: " . inr((float) $adv['outstanding']) . "\n"
                    . ($lines !== [] ? implode("\n", $lines) . "\n" : "No open advance or loan.\n")
                    . $balLine . $note . "पेश्की / ऋण बाँकी " . inr((float) $adv['outstanding']);
                break;
            }
            case 'agent_cash_settlement': {
                $rows = AgentWallet::entries($agentId, 8, '');
                $lines = [];
                foreach ($rows as $r) {
                    $t = (string) $r['entry_type'];
                    if (!in_array($t, ['cash_handover', 'payout'], true)) { continue; }
                    $lines[] = '• ' . formatDate(substr((string) $r['created_at'], 0, 10), 'd M') . ' ' . ($t === 'payout' ? 'Payout to you' : 'Cash handed over') . ' ' . inr(abs((float) $r['amount'])) . (!empty($r['ref']) ? ' · ' . (string) $r['ref'] : '');
                }
                $overdue = AgentWallet::settlementOverdueDays($agentId);
                $out['text'] = $head . "Cash settlement\n"
                    . "Cash you hold now: " . inr($bal['cash']) . ($overdue > 0 ? " (settlement overdue by " . $overdue . " days)" : '') . "\n"
                    . "Commission due to you: " . inr($bal['commission']) . "\n"
                    . ($lines !== [] ? "Recent settlements:\n" . implode("\n", $lines) . "\n" : '')
                    . $netLine . $note . "नगद हिसाब — हातमा " . inr($bal['cash']);
                break;
            }
            case 'agent_outstanding': {
                $out['text'] = $head . "Outstanding balance\n" . $balLine . $netLine . "\n"
                    . ($net['net'] > 0.009 ? "Please hand over " . inr($net['net']) . " at the office to settle. " : '')
                    . "Office: " . Settings::officePhone() . $note . "बाँकी हिसाब — " . ($net['net'] > 0.009 ? "तपाईंले बुझाउनुपर्ने " . inr($net['net']) : ($net['net'] < -0.009 ? "कम्पनीले तिर्नुपर्ने " . inr(-$net['net']) : "हिसाब मिलेको छ"));
                break;
            }
            case 'agent_payment_reminder': {
                $overdue = AgentWallet::settlementOverdueDays($agentId);
                $dueDays = Settings::getInt('agent_settlement_due_days', 0);
                $out['text'] = $head . "Payment reminder\n"
                    . "You are holding " . inr($bal['cash']) . " in cash from ticket sales" . ($overdue > 0 ? ", " . $overdue . " days past the " . $dueDays . "-day settlement window" : '') . ".\n"
                    . "Please hand it over at the office" . ($bal['commission'] > 0.009 ? " — your commission of " . inr($bal['commission']) . " will be settled at the same time" : '') . ".\n"
                    . "Office: " . Settings::officePhone() . $note . "कृपया बिक्रीको नगद " . inr($bal['cash']) . " अफिसमा बुझाउनुहोस्।";
                break;
            }
            case 'agent_settlement_done': {
                $lid = (int) ($ctx['ledger_id'] ?? 0);
                $row = $lid > 0 ? Database::fetch('SELECT * FROM agent_ledger WHERE id = :i AND agent_admin_id = :a', ['i' => $lid, 'a' => $agentId]) : null;
                if ($row === null) {
                    $row = Database::fetch("SELECT * FROM agent_ledger WHERE agent_admin_id = :a AND entry_type IN ('payout','cash_handover') ORDER BY id DESC LIMIT 1", ['a' => $agentId]);
                }
                if ($row === null) {
                    throw new RuntimeException('No settlement on record for this agent yet.');
                }
                $t = (string) $row['entry_type'];
                $what = $t === 'payout' ? 'Commission payout to you' : ($t === 'cash_handover' ? 'Cash handed over by you' : 'Account adjustment');
                $out['text'] = $head . "Settlement receipt\n"
                    . $what . ": " . inr(abs((float) $row['amount'])) . "\n"
                    . "Date: " . formatDate(substr((string) $row['created_at'], 0, 10)) . (!empty($row['ref']) ? " · Voucher " . (string) $row['ref'] : '') . "\n"
                    . (!empty($row['note']) ? "Note: " . (string) $row['note'] . "\n" : '')
                    . "Balance now — " . $balLine . $netLine . $note . "भुक्तानी रसिद — " . inr(abs((float) $row['amount']));
                break;
            }
            case 'agent_daily_summary':
            case 'agent_monthly_summary': {
                [$d0, $d1, $label] = self::period($purpose, $ctx);
                $s = self::agentPeriodStats($agentId, $d0, $d1, 0);
                $out['text'] = $head . ($purpose === 'agent_daily_summary' ? 'Daily' : 'Monthly') . " summary · " . $label . "\n"
                    . "Tickets sold: " . $s['bookings'] . " · Passengers: " . $s['pax'] . " · Sales: " . inr($s['revenue']) . "\n"
                    . "Commission this period: " . inr($s['commission']) . " · Cash collected: " . inr($s['cash']) . "\n"
                    . $balLine . $netLine . $note . "सारांश — " . $label;
                break;
            }
            default:
                throw new RuntimeException('Unknown message type.');
        }
        $out['text'] = trim($out['text']);
            return $out;
    }

    /** [$fromYmd, $toYmd, label] from ctx (defaults: this month to today). @return array{0:string,1:string,2:string} */
    private static function range(array $ctx): array
    {
        $from = (string) ($ctx['from'] ?? '');
        $to   = (string) ($ctx['to'] ?? '');
        if (!Security::isValidDate($from)) { $from = date('Y-m-01'); }
        if (!Security::isValidDate($to))   { $to = todayISO(); }
        if ($to < $from) { [$from, $to] = [$to, $from]; }
        return [$from, $to, formatDate($from, 'd M Y') . ' – ' . formatDate($to, 'd M Y')];
    }

    /** Daily / monthly window as datetime bounds + label. @return array{0:string,1:string,2:string} */
    private static function period(string $purpose, array $ctx): array
    {
        $daily = str_contains($purpose, 'daily');
        $on = (string) ($ctx['on'] ?? ($ctx['from'] ?? ''));
        if ($daily) {
            $day = Security::isValidDate($on) ? $on : todayISO();
            return [$day . ' 00:00:00', addDaysISO($day, 1) . ' 00:00:00', formatDate($day)];
        }
        $month = preg_match('/^\d{4}-\d{2}/', $on) === 1 ? substr($on, 0, 7) : date('Y-m');
        $d0 = $month . '-01';
        $d1 = date('Y-m-01', (int) strtotime($d0 . ' +1 month'));
        return [$d0 . ' 00:00:00', $d1 . ' 00:00:00', date('F Y', (int) strtotime($d0))];
    }

    /** "Name · Landmark @ HH:MM [lat,lng]" → "Name (HH:MM)". */
    private static function stopName(string $label): string
    {
        $label = preg_replace('/\s*\[[^\]]*\]\s*$/', '', $label) ?? $label;
        $time  = '';
        if (preg_match('/@\s*(\d{1,2}:\d{2})/', $label, $m)) { $time = $m[1]; }
        $namePart = trim((string) preg_replace('/@.*$/', '', $label));
        $namePart = trim((string) explode('·', str_replace('—', '·', $namePart))[0]);
        return $namePart . ($time !== '' ? ' (' . $time . ')' : '');
    }
}
