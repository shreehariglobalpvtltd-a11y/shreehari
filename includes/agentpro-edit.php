<?php
/**
 * =====================================================================
 *  includes/agentpro-edit.php — change a ticket from a WhatsApp message
 *  (owner: "ticket har kura edit hun saknu paryo, WhatsApp msg bata").
 *
 *  Every change is two messages: a PREVIEW ("Name: Ram Thapa → Sita Thapa,
 *  reply ho") and the APPLY after "ho". Nothing here is a new way of
 *  editing a booking — each field uses exactly what the desk already uses:
 *
 *    name, age, gender   booking_passengers, as admin/booking-view.php edit_passenger
 *    phone               bookings.contact_phone (+ payments.payer_phone), same page
 *    pickup              booking_legs.boarding_stop, same page, a stop of that route
 *    seat                Seats::transferSeat(), the seat map's "Change seat"
 *    date                AiTools quote_ticket_fix → fix_ticket (BookingService::rebookLeg)
 *
 *  then Ticket::reissue() (new QR, "CORRECTED · REV n") and
 *  Notify::ticketChanged() to the passenger, with an audit row naming the
 *  WhatsApp number that asked.
 *
 *  Who may change what:
 *    office (manager / superadmin)  any live booking, every field
 *    agent / counter                only bookings they sold, every field
 *    passenger                      their own number's booking: name (twice,
 *                                   AiTools' rule), phone, date. Pickup and
 *                                   seat go to the office as a request.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/ticket.php';
require_once __DIR__ . '/seats.php';
require_once __DIR__ . '/boarding.php';
require_once __DIR__ . '/notify.php';

final class ApEdit
{
    public const FIELDS = ['name', 'phone', 'pickup', 'seat', 'date', 'gender', 'age'];

    /**
     * Work out a change and say what it would do. Nothing is written.
     *
     * @param array<string, mixed> $ctx role (customer|staff|admin), adminId, scopeAdminId, phone, turn
     * @return array{ok: bool, error?: string, change?: array<string, mixed>, lines?: list<array{0: string, 1: string, 2: string}>, office?: bool}
     */
    public static function preview(string $pnr, string $field, string $value, array $ctx, string $which = ''): array
    {
        $pnr = strtoupper(trim($pnr));
        $field = strtolower(trim($field));
        $value = trim($value);
        if (!in_array($field, self::FIELDS, true)) {
            return ['ok' => false, 'error' => 'field'];
        }
        $d = BookingService::detail($pnr);
        if ($d === null || !self::mayRead($d, $ctx)) {
            return ['ok' => false, 'error' => 'notfound'];
        }
        if (!in_array((string) $d['status'], ['pending', 'confirmed'], true)) {
            return ['ok' => false, 'error' => 'notlive', 'status' => (string) $d['status']];
        }
        $leg = $d['legs'][0] ?? [];
        if (!class_exists('TripStatus')) {
            require_once __DIR__ . '/tripstatus.php';
        }
        $gate = TripStatus::editableFor((int) ($leg['schedule_id'] ?? 0));
        if (!($gate['editable'] ?? false)) {
            return ['ok' => false, 'error' => 'departed', 'label' => (string) ($gate['label'] ?? '')];
        }
        $customer = ($ctx['role'] ?? 'customer') === 'customer';
        if ($customer && in_array($field, ['pickup', 'seat', 'age', 'gender'], true)) {
            return ['ok' => true, 'office' => true, 'change' => ['pnr' => $pnr, 'field' => $field, 'new' => $value, 'bookingId' => (int) $d['id']]];
        }

        $pax = $d['passengers'] ?? [];
        $target = self::pickPassenger($pax, $which);
        $change = ['pnr' => $pnr, 'bookingId' => (int) $d['id'], 'field' => $field];

        switch ($field) {
            case 'name':
                $new = Security::clean(preg_replace('/\s+/u', ' ', $value) ?? '', 120);
                if (mb_strlen($new) < 2 || preg_match('/\d/', $new) === 1) {
                    return ['ok' => false, 'error' => 'badname'];
                }
                if ($target === null) {
                    return ['ok' => false, 'error' => count($pax) > 1 ? 'whichpax' : 'nopax', 'names' => array_column($pax, 'full_name')];
                }
                $change += ['paxId' => (int) $target['id'], 'old' => (string) $target['full_name'], 'new' => self::titleCase($new)];
                break;
            case 'age':
                $age = (int) preg_replace('/\D/', '', $value);
                if ($age < 1 || $age > 110) {
                    return ['ok' => false, 'error' => 'badage'];
                }
                if ($target === null) {
                    return ['ok' => false, 'error' => count($pax) > 1 ? 'whichpax' : 'nopax', 'names' => array_column($pax, 'full_name')];
                }
                $change += ['paxId' => (int) $target['id'], 'old' => (string) ($target['age'] ?? ''), 'new' => (string) $age];
                break;
            case 'gender':
                require_once __DIR__ . '/agentpro-docs.php';
                $g = ApDocRead::gender($value) ?: (preg_match('/^(m|f|o)$/i', $value) ? ['m' => 'Male', 'f' => 'Female', 'o' => 'Other'][strtolower($value)] : '');
                if ($g === '') {
                    return ['ok' => false, 'error' => 'badgender'];
                }
                if ($target === null) {
                    return ['ok' => false, 'error' => count($pax) > 1 ? 'whichpax' : 'nopax', 'names' => array_column($pax, 'full_name')];
                }
                $change += ['paxId' => (int) $target['id'], 'old' => (string) ($target['gender'] ?? ''), 'new' => $g];
                break;
            case 'phone':
                $digits = preg_replace('/\D/', '', $value) ?? '';
                $nat = normalisePhone($value);
                if (!Security::isValidPhone($nat, true)) {
                    return ['ok' => false, 'error' => 'badphone'];
                }
                if ($customer || ($ctx['viaTools'] ?? false)) {
                    $change['via'] = 'tools';
                }
                $change += ['old' => (string) $d['contact_phone'], 'new' => $nat,
                            'cc' => countryDialCode(resolvePhoneCountry('', $value)), 'raw' => $digits];
                break;
            case 'pickup':
                $routeId = (int) Database::scalar('SELECT route_id FROM schedules WHERE id = :s', ['s' => (int) ($leg['schedule_id'] ?? 0)], 0);
                $stops = Boarding::stopsFor($routeId);
                $hit = self::matchStop($stops, $value);
                if ($hit === null) {
                    return ['ok' => false, 'error' => 'badstop', 'stops' => array_slice(array_map(static fn(array $s): string => Boarding::stopDisplay((string) $s['name'])['name'], $stops), 0, 12)];
                }
                $change += ['old' => (string) ($leg['boarding_stop'] ?? ''), 'new' => $hit, 'legId' => (int) ($leg['id'] ?? 0),
                            'time' => Boarding::timeForStop($routeId, $hit)];
                break;
            case 'seat':
                $want = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
                if ($want === '' || !Security::isValidSeat($want)) {
                    return ['ok' => false, 'error' => 'badseat'];
                }
                $from = $target !== null && ($target['seat_no'] ?? '') !== '' ? (string) $target['seat_no'] : (string) (($leg['seats'] ?? [])[0] ?? '');
                if ($from === '') {
                    return ['ok' => false, 'error' => 'nopax'];
                }
                $change += ['old' => $from, 'new' => $want, 'scheduleId' => (int) $leg['schedule_id']];
                break;
            case 'date':
                $date = self::parseDate($value);
                if ($date === '') {
                    return ['ok' => false, 'error' => 'baddate'];
                }
                $change += ['old' => (string) ($leg['travel_date'] ?? ''), 'new' => $date, 'via' => 'tools'];
                break;
        }
        if (($change['old'] ?? null) === ($change['new'] ?? null)) {
            return ['ok' => false, 'error' => 'same'];
        }

        // Date (and a passenger's phone) go through AiTools so the date rules,
        // seat locks and fare check are the desk's own; it stages its own preview.
        if (($change['via'] ?? '') === 'tools') {
            require_once __DIR__ . '/aitools.php';
            $args = ['pnr' => $pnr, 'reason' => 'WhatsApp desk correction'];
            if ($field === 'date') { $args['new_date'] = $change['new']; }
            if ($field === 'phone') { $args['new_phone'] = '+' . ($change['cc'] !== '' ? $change['cc'] . $change['new'] : $change['raw']); }
            $q = AiTools::run('quote_ticket_fix', $args, $ctx);
            if (!$q['ok']) {
                return ['ok' => false, 'error' => 'tools', 'say' => (string) $q['say']];
            }
            $change['args'] = $args;
            $change['seats'] = $q['data']['seats'] ?? [];
            $change['total'] = $q['data']['total'] ?? null;
        }
        return ['ok' => true, 'change' => $change];
    }

    /**
     * Apply a change that was previewed and confirmed in a LATER message.
     *
     * @param array<string, mixed> $change from preview()
     * @param array<string, mixed> $ctx    with messageText = the confirming message
     * @return array{ok: bool, error?: string, say?: string, pnr?: string, media?: ?string}
     */
    public static function apply(array $change, array $ctx): array
    {
        $pnr = (string) $change['pnr'];
        $d = BookingService::detail($pnr);
        if ($d === null || !self::mayRead($d, $ctx)) {
            return ['ok' => false, 'error' => 'notfound'];
        }
        $bid = (int) $d['id'];
        $field = (string) $change['field'];
        $who = 'WhatsApp ' . ($ctx['phone'] ?? '') . ' (' . ($ctx['role'] ?? '') . ($ctx['adminId'] ?? 0 ? ' #' . $ctx['adminId'] : '') . ')';

        if (($change['via'] ?? '') === 'tools') {
            require_once __DIR__ . '/aitools.php';
            $r = AiTools::run('fix_ticket', ($change['args'] ?? []) + ['confirm' => true], $ctx);
            return $r['ok'] ? ['ok' => true, 'pnr' => $pnr, 'media' => $r['media'] ?? null]
                            : ['ok' => false, 'error' => 'tools', 'say' => (string) $r['say']];
        }

        if ($field === 'name' && ($ctx['role'] ?? '') === 'customer') {
            require_once __DIR__ . '/aitools.php';
            $r = AiTools::run('rename_passenger', ['pnr' => $pnr, 'new_name' => $change['new'], 'old_name' => $change['old']], $ctx);
            return $r['ok'] ? ['ok' => true, 'pnr' => $pnr, 'media' => $r['media'] ?? null]
                            : ['ok' => false, 'error' => 'tools', 'say' => (string) $r['say']];
        }

        $kind = 'passenger';
        $notifyCtx = [];
        try {
            switch ($field) {
                case 'name':
                case 'age':
                case 'gender':
                    $col = $field === 'name' ? 'full_name' : $field;
                    Database::update('booking_passengers', [$col => $field === 'age' ? (int) $change['new'] : $change['new']],
                        'id = :id AND booking_id = :b', ['id' => (int) $change['paxId'], 'b' => $bid]);
                    if ($field === 'name') {
                        $notifyCtx = ['name' => $change['new']];
                    }
                    break;
                case 'phone':
                    $upd = ['contact_phone' => $change['new'], 'updated_at' => date('Y-m-d H:i:s')];
                    if (($change['cc'] ?? '') !== '') {
                        $upd['contact_country_code'] = $change['cc'];
                    }
                    Database::update('bookings', $upd, 'id = :id', ['id' => $bid]);
                    Database::update('payments', ['payer_phone' => $change['new']], 'booking_id = :b', ['b' => $bid]);
                    $kind = 'contact';
                    break;
                case 'pickup':
                    Database::update('booking_legs', array_filter(['boarding_stop' => $change['new'], 'boarding_time' => $change['time'] ?? null],
                        static fn($v): bool => $v !== null), "booking_id = :b AND leg_type = 'outbound'", ['b' => $bid]);
                    break;
                case 'seat':
                    $coach = Seats::coachForSchedule((int) $change['scheduleId']);
                    $res = Seats::transferSeat((int) $change['scheduleId'], (string) $change['old'], (string) $change['new'], $coach, (int) ($ctx['adminId'] ?? 0));
                    $kind = 'seat';
                    $notifyCtx = ['old' => (string) ($res['from'] ?? $change['old']), 'new' => (string) ($res['to'] ?? $change['new'])];
                    break;
                default:
                    return ['ok' => false, 'error' => 'field'];
            }
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => 'refused', 'say' => $e->getMessage()];
        }

        Ticket::reissue($bid);
        Logger::audit('booking.edit_whatsapp', 'booking', $pnr, [$field => $change['old'] ?? null], [$field => $change['new'] ?? null],
            'Ticket changed from ' . $who);
        $fresh = BookingService::detail($pnr) ?? $d;
        try {
            Notify::ticketChanged($fresh, $kind, $notifyCtx);
        } catch (Throwable $e) {
            Logger::warning('ticketChanged after a WhatsApp edit failed: ' . $e->getMessage(), [], 'whatsapp');
        }
        return ['ok' => true, 'pnr' => $pnr, 'media' => (string) $fresh['status'] === 'confirmed' ? Ticket::imageUrl($pnr) : null];
    }

    /** A passenger asked for something only the office does: tell the office. */
    public static function forwardToOffice(array $change, array $ctx): void
    {
        try {
            Notify::adminNote('✏️ Ticket change request (WhatsApp)', [
                'PNR'    => (string) $change['pnr'],
                'Change' => (string) $change['field'] . ' → ' . (string) ($change['new'] ?? ''),
                'From'   => (string) ($ctx['phone'] ?? ''),
            ], (int) ($change['bookingId'] ?? 0) ?: null);
        } catch (Throwable $e) {
            Logger::warning('Office note for a change request failed: ' . $e->getMessage(), [], 'whatsapp');
        }
    }

    /** Same rule as AiTools::mayRead — office any, agent / counter own sales, passenger own number. */
    public static function mayRead(array $d, array $ctx): bool
    {
        $role = (string) ($ctx['role'] ?? 'customer');
        if ($role === 'admin') {
            return true;
        }
        if ($role === 'staff') {
            $scope = $ctx['scopeAdminId'] ?? null;
            return $scope === null || (int) ($d['sold_by_admin_id'] ?? 0) === (int) $scope;
        }
        return (string) ($ctx['phone'] ?? '') !== '' && normalisePhone((string) ($d['contact_phone'] ?? '')) === (string) $ctx['phone'];
    }

    /** @param list<array<string, mixed>> $pax */
    private static function pickPassenger(array $pax, string $which): ?array
    {
        if ($pax === []) {
            return null;
        }
        if (count($pax) === 1) {
            return $pax[0];
        }
        $w = mb_strtolower(trim($which));
        if ($w === '') {
            return null;
        }
        foreach ($pax as $i => $p) {
            if ($w === (string) ($i + 1) || $w === mb_strtolower((string) ($p['seat_no'] ?? ''))
                || mb_strtolower(trim((string) $p['full_name'])) === $w
                || ($w !== '' && str_contains(mb_strtolower((string) $p['full_name']), $w))) {
                return $p;
            }
        }
        return null;
    }

    /** @param list<array<string, mixed>> $stops */
    private static function matchStop(array $stops, string $value): ?string
    {
        $want = Boarding::townKey($value);
        $best = null;
        foreach ($stops as $s) {
            $label = (string) ($s['name'] ?? '');
            if ($label === '') {
                continue;
            }
            if (Boarding::townKey($label) === $want || mb_strtolower($label) === mb_strtolower($value)) {
                return $label;
            }
            if ($best === null && $want !== '' && str_contains(mb_strtolower($label), mb_strtolower($value))) {
                $best = $label;
            }
        }
        return $best;
    }

    /** "2026-11-02", "02/11/2026", "2 Nov", "bholi", "bholi parsi" → Y-m-d, '' when unsure. */
    public static function parseDate(string $v): string
    {
        $v = mb_strtolower(trim($v));
        require_once __DIR__ . '/agentpro-docs.php';
        $v = ApDocRead::asciiDigits($v);
        if (preg_match('/\b(aaja|aj|today|आज)\b/u', $v)) { return date('Y-m-d'); }
        if (preg_match('/\b(parsi|parso|पर्सि|परसों|day after)\b/u', $v)) { return date('Y-m-d', strtotime('+2 day')); }
        if (preg_match('/\b(bholi|bholli|kal|tomorrow|भोलि|कल)\b/u', $v)) { return date('Y-m-d', strtotime('+1 day')); }
        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('/(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?/', $v, $m)) {
            $y = isset($m[3]) ? ((int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3]) : (int) date('Y');
            if (checkdate((int) $m[2], (int) $m[1], $y)) {
                $iso = sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
                return !isset($m[3]) && $iso < date('Y-m-d') ? sprintf('%04d-%02d-%02d', $y + 1, $m[2], $m[1]) : $iso;
            }
        }
        $ts = strtotime($v);
        if ($ts !== false && $ts > time() - 86400 && $ts < time() + 400 * 86400) {
            return date('Y-m-d', $ts);
        }
        return '';
    }

    private static function titleCase(string $s): string
    {
        return preg_match('/\p{Devanagari}/u', $s) === 1 ? $s : ucwords(mb_strtolower($s));
    }
}
