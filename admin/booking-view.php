<?php
/**
 * admin/booking-view.php?pnr=... — one booking in full.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/tripstatus.php';   // edit-window gate for seat changes (5 Sep 2026)
require_once INCLUDE_PATH . '/passengerdocs.php'; // passenger photo / ID documents (17 Sep 2026)
require_once INCLUDE_PATH . '/ticketedit.php';    // Quick Edit: fare fix, correction requests, Auto Assign (15 Oct 2026)
$admin = admin_boot('bookings.view');

$base  = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$pnr   = Security::clean($_GET['pnr'] ?? '', 40);
$flash = null;
$bvEdited = false;   // 15 Oct 2026: a correction just saved - offer Share / Send ticket under the flash
/* Desk isolation (7 Oct 2026): a clerk sees only their own desk's tickets on
   this page too, not just in the lists. Emptying the PNR turns every action
   block below and the detail load into "not found". */
if ($pnr !== '' && !CounterDesk::bookingVisible(null, $pnr)) {
    Logger::warning('Counter tried to open a booking of another desk', ['admin' => $admin['username'] ?? '', 'pnr' => $pnr]);
    $pnr = '';
}
$waManualLink = '';   // click-to-chat URL when no WhatsApp API is configured

/**
 * May the signed-in staff member cancel the booking behind this PNR?
 * (4 Sep 2026 — agent bulk-cancel.)
 *
 * The cancel blocks below run BEFORE the full booking detail is loaded, so
 * the seller is read here with one narrow query. `bookings.cancel` (office)
 * may cancel any ticket; an agent holds `bookings.cancel_own` and may cancel
 * only what they sold — the same rule the bulk endpoint enforces per PNR,
 * spelled out once in Auth::mayCancelBooking().
 */
function bv_may_cancel(string $pnr): bool
{
    $row = Database::fetch('SELECT sold_by_admin_id FROM bookings WHERE pnr = :p LIMIT 1', ['p' => $pnr]);
    if ($row === null) {
        return Auth::can('bookings.cancel');   // nothing to own — office-only path errors later
    }
    return Auth::mayCancelBooking($row);
}

/* ---- Regenerate the ticket after an edit (UI v3, 23 Sep; ported 24 Sep;
   ONE hook since 1 Oct 2026) -------------------------------------------
   Every edit goes through TicketSync::afterBookingChange(): the QR is
   re-minted (REV n+1, old PNG/PDF/invoice gone), the PNG redrawn at once so
   every link is the corrected ticket, ONE audit row carries the desk's
   reason, and - for the kinds the passenger must hear about, or when
   $notify is true - Notify::ticketChanged() is sent and its REAL outcome
   reported in the flash (the first-ticket idempotency guard used to swallow
   it while this page said "notified"). The plain re-SEND stays on the
   resend_wa button: two messages for one correction is the duplicate the
   owner asked us to remove. Nothing here can fail the edit itself. */
function bv_sync_ticket(int $bookingId, string $pnr, string $kind, array $ctx = [], ?bool $notify = null, string $reason = ''): string
{
    /* 9 Oct 2026 (Office chat): an edit to a ticket that has an office <-> agent
       conversation leaves one grey line there ("Ticket updated by … — seat"),
       so the seller sees the change where they asked for it. Never creates a
       conversation; never blocks the edit. */
    try {
        $who = (string) ((Auth::admin()['full_name'] ?? '') ?: (Auth::admin()['username'] ?? 'staff'));
        $what = ['passenger' => 'passenger name', 'contact' => 'contact', 'payment' => 'payment', 'seat' => 'seat', 'reschedule' => 'date', 'stops' => 'pickup / drop'][$kind] ?? $kind;
        StaffMsg::system($bookingId, '✏️ Ticket updated by ' . $who . ' — ' . $what . ' · टिकट अपडेट भयो');
    } catch (Throwable $e) {
        // the chat line is a courtesy
    }
    try {
        $opts = ['reason' => $reason];
        if ($notify !== null) {
            $opts['notify'] = $notify;
        }
        return TicketSync::note(TicketSync::afterBookingChange($bookingId, $kind, $ctx, $opts));
    } catch (Throwable $e) {
        Logger::warning('Ticket regeneration after edit failed', ['pnr' => $pnr, 'err' => $e->getMessage()]);
        return ' Ticket PNG and PDF regenerate on next open.';
    }
}

/** One row in message_logs for a staff-triggered resend (the delivery pill reads it). */
function bv_log_message(int $bookingId, string $to, string $status, string $provider, string $note): void
{
    try {
        $row = [
            'booking_id' => $bookingId, 'channel' => 'whatsapp', 'provider' => $provider,
            'to_number' => substr($to, 0, 32), 'body' => mb_substr('Ticket resend (admin)', 0, 1000),
            'status' => $status, 'provider_ref' => '', 'error' => $note !== '' ? mb_substr($note, 0, 255) : null,
        ];
        // purpose (1 Oct 2026): a staff resend must never count as the FIRST
        // ticket for the idempotency guard or the retry cron.
        try { Database::insert('message_logs', $row + ['purpose' => 'ticket_resend']); }
        catch (Throwable $e) { Database::insert('message_logs', $row); }
    } catch (Throwable $e) { /* message_logs is informational */ }
}

/**
 * Correct the latest payment row's method / reference / note (5 Sep 2026;
 * one function since 15 Oct 2026 so the Quick Edit block and an approved
 * "Request correction" run the SAME path). A method moved between cash and
 * UPI / bank moves the seller's cash_due with it (AgentWallet::syncCashDue).
 * The caller checks the right to do it.
 *
 * @return array{changed: list<string>, flash: string}
 */
function bv_apply_payment(array $b, string $method, string $utr, string $note, int $adminId, string $pnr): array
{
    $payId = (int) Database::scalar('SELECT COALESCE(MAX(id),0) FROM payments WHERE booking_id = :b', ['b' => (int) $b['id']], 0);
    if ($payId <= 0) { throw new RuntimeException('This booking has no payment record yet.'); }
    $cur = Database::fetch('SELECT method, utr_number, admin_note, status FROM payments WHERE id = :i', ['i' => $payId]) ?? [];
    $method = strtolower(trim($method));
    $upd = []; $oldVals = []; $newVals = [];
    if ($method !== '' && in_array($method, TicketEdit::PAY_METHODS, true) && $method !== (string) ($cur['method'] ?? '')) { $upd['method'] = $method; $oldVals['method'] = $cur['method'] ?? null; $newVals['method'] = $method; }
    if ($utr !== '' && $utr !== (string) ($cur['utr_number'] ?? '')) { $upd['utr_number'] = $utr; $oldVals['utr_number'] = $cur['utr_number'] ?? null; $newVals['utr_number'] = $utr; }
    if ($note !== '' && $note !== (string) ($cur['admin_note'] ?? '')) { $upd['admin_note'] = $note; $oldVals['admin_note'] = $cur['admin_note'] ?? null; $newVals['admin_note'] = $note; }
    if ($upd === []) {
        return ['changed' => [], 'flash' => 'Nothing changed on the payment.'];
    }
    Database::update('payments', $upd, 'id = :i', ['i' => $payId]);
    Logger::audit('payment.edit', 'payment', (string) $payId, $oldVals, $newVals, 'Payment details edited on ' . $pnr . ' by admin #' . $adminId, $note);
    // 3 Oct 2026: a method corrected between cash and UPI/bank moves
    // the seller's "cash held" with it (it was decided only at sale).
    if (isset($upd['method'])) {
        $fresh = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $b['id']]);
        if ($fresh !== null) { AgentWallet::syncCashDue($fresh, 'payment method corrected'); }
    }
    // the invoice prints the method, the ticket PNG the payment pill
    // (office-side: REV + redraw + audit, no passenger message)
    return ['changed' => array_keys($upd), 'flash' => 'Payment record updated (' . implode(', ', array_keys($upd)) . ').'
        . bv_sync_ticket((int) $b['id'], $pnr, 'payment', ['new' => implode(', ', array_keys($upd))], null, $note)];
}

/**
 * 15 Oct 2026 (owner: agent_cancel_mode): when the office set agent cancels
 * to 'request', an agent who may cancel only their OWN sales files a cancel
 * request instead (the office settles cancel + refund by hand). Office roles
 * holding bookings.cancel are never affected.
 */
function bv_cancel_is_request(): bool
{
    return !Auth::can('bookings.cancel') && Auth::can('bookings.cancel_own')
        && Settings::getString('agent_cancel_mode', 'direct') === 'request';
}

/* ---- Cancel action ----------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            if (!bv_may_cancel($pnr)) {
                throw new RuntimeException('You may only cancel tickets you sold yourself.');
            }
            $reason = Security::clean($_POST['reason'] ?? 'Cancelled by staff', 255);
            if (bv_cancel_is_request()) {
                $cRow = BookingService::findByPnr($pnr);
                if ($cRow === null) { throw new RuntimeException('Booking not found.'); }
                $rid = TicketEdit::request($cRow, 'cancel_request', '', $reason !== '' ? $reason : 'Cancel requested by the agent', $admin);
                $flash = ['ok', 'Cancel request #' . $rid . ' sent to the office — they cancel it and settle any refund by hand. The ticket stays valid until then.'];
            } else {
                $res = BookingService::cancel($pnr, $reason, false);
                $flash = ['ok', 'Booking cancelled. Refund: ' . inr((float) ($res['refund']['amount'] ?? 0))
                              . ' (' . (int) ($res['refund']['percent'] ?? 0) . '%).'];
                // An open "please cancel" request is answered by this cancel.
                TicketEdit::closeOpenCancels((int) ($res['booking']['id'] ?? 0), (string) (($admin['full_name'] ?? '') ?: ($admin['username'] ?? 'staff')) . ' #' . (int) $admin['id']);
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Per-seat cancel action ----------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'cancel_seat') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            if (!bv_may_cancel($pnr)) {
                throw new RuntimeException('You may only cancel tickets you sold yourself.');
            }
            $seat   = strtoupper(Security::clean($_POST['seat_no'] ?? '', 10));
            $reason = Security::clean($_POST['reason'] ?? 'Seat cancelled by staff', 255);
            if (bv_cancel_is_request()) {   // 15 Oct 2026: agent_cancel_mode = request
                $cRow = BookingService::findByPnr($pnr);
                if ($cRow === null || (int) $cRow['id'] !== (int) ($_POST['booking_id'] ?? 0)) { throw new RuntimeException('Booking not found.'); }
                $rid   = TicketEdit::request($cRow, 'cancel_request', $seat, $reason, $admin);
                $flash = ['ok', 'Cancel request #' . $rid . ' for seat ' . $seat . ' sent to the office — they settle it by hand.'];
            } else {
                $res    = BookingService::cancelSeat((int) ($_POST['booking_id'] ?? 0), $seat, (int) $admin['id'], $reason);
                $seatMode = (string) Database::scalar('SELECT booking_mode FROM bookings WHERE id = :i', ['i' => (int) ($_POST['booking_id'] ?? 0)], 'sharing');
                $flash  = ['ok', 'Seat ' . Security::e(Seats::displayLabel($seat, 'sleeper', $seatMode !== '' ? $seatMode : 'sharing')) . ' cancelled. Refund for this seat: '
                                . inr((float) ($res['refund']['amount'] ?? 0))
                                . ' (' . (int) ($res['refund']['percent'] ?? 0) . '%).'];
                $pnr = (string) ($res['booking']['pnr'] ?? $pnr);
                // an agent's open request for THIS seat is now settled
                TicketEdit::closeOpenCancels((int) ($_POST['booking_id'] ?? 0), (string) (($admin['full_name'] ?? '') ?: ($admin['username'] ?? 'staff')) . ' #' . (int) $admin['id'], $seat);
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Edit passenger details (bookings.edit permission) --------------
   After an edit the passenger is told WHAT changed (SHG AI BRAIN 1.6, 10 Sep
   2026; worded 17 Sep 2026) by Notify::ticketChanged() - since 1 Oct 2026
   sent from TicketSync (bv_sync_ticket above) together with the regeneration,
   so the picture they receive is the corrected one and the flash reports the
   real delivery outcome. Off via settings notify_ticket_edit = 0. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'edit_passenger') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            /* 15 Oct 2026 (Quick Edit): checked field by field. Office roles keep
               bookings.edit; a counter AGENT may correct only the fields the owner
               switched on (settings ticket_edit_rules -> Auth::mayEditField) on a
               ticket they sold, while its trip is still editable. */
            if (!Auth::can('bookings.edit') && !Auth::isCounterAgent()) {
                Auth::requireAdmin('bookings.edit');   // the usual refusal page
            }
            $bid = (int) ($_POST['booking_id'] ?? 0);
            $reason  = Security::clean($_POST['reason'] ?? '', 255);   // SHG AI BRAIN 1.6: why, in the desk's words

            $cur = Database::fetch('SELECT id, pnr, status, contact_phone, contact_country_code, contact_email, sold_by_admin_id FROM bookings WHERE id = :id', ['id' => $bid]);
            if ($cur === null || (string) $cur['pnr'] !== $pnr) {
                throw new RuntimeException('Booking not found.');
            }
            /* Agent scope (17 Sep 2026). This handler runs BEFORE the page-level
               guard further down (it has to — the guard nulls $b, and $b is not
               loaded yet here), so it applies the rule itself: a counter agent
               may edit only a booking they sold. Mirrors BookingService::
               cancelSeat(); a foreign PNR reads as "not found", because
               confirming it exists is itself a disclosure. No scoped role holds
               bookings.edit today — this keeps that true by construction. */
            $editScope = Auth::bookingScopeAdminId();
            if ($editScope !== null && (int) ($cur['sold_by_admin_id'] ?? 0) !== $editScope) {
                throw new RuntimeException('Booking not found.');
            }
            if (Auth::isCounterAgent()) {
                // An agent corrects a LIVE ticket of their own, before the trip closes.
                if (!in_array((string) $cur['status'], ['pending', 'confirmed'], true)) {
                    throw new RuntimeException('Only a pending or confirmed ticket can be corrected.');
                }
                $agSid  = (int) Database::scalar("SELECT schedule_id FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound' LIMIT 1", ['b' => $bid], 0);
                $agGate = $agSid > 0 ? TripStatus::editableFor($agSid) : ['editable' => true];
                if (!($agGate['editable'] ?? false)) {
                    throw new RuntimeException('Trip is ' . ($agGate['label'] ?? 'closed') . ' — ask the office (Request correction or Office chat).');
                }
            }
            $isSuper = Auth::isSuperadmin();
            $mayF    = static fn(string $f): bool => Auth::mayEditField($cur, $f);
            $deny    = static function (string $what): void {
                throw new RuntimeException('Your role may not change the ' . $what . ' on this ticket — use “Request correction” or Office chat.');
            };

            // What the desk typed. Every Quick Edit box comes PREFILLED with the
            // current value, so an untouched box compares equal below and a
            // no-op save stays a no-op; an empty box still means "keep".
            $newPhone = (string) preg_replace('/\D/', '', (string) ($_POST['contact_phone'] ?? ''));
            $newEmail = Security::clean($_POST['contact_email'] ?? '', 120);
            // 15 Oct 2026: the pickup / drop are now picked from the route's real
            // stops, with "Other…" still allowed for a stop that is not listed.
            // A typed "Other" stop loses any "@ 21:00" / "[lat,lng]" tail, so it can
            // never pose as a configured stop's time on the ticket (as at the sale).
            $otherStop = static function (string $raw): string {
                $s = (string) preg_replace('/\s*\[[^\]]*\]\s*$/u', '', Security::clean($raw, 120));
                $s = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\s*@\s*[0-2]?\d:\d{2}\s*$/u', '', $s)));
                if (mb_strlen($s) < 3) {
                    throw new RuntimeException('Type the other stop (at least 3 letters).');
                }
                return $s;
            };
            $newBoard = Security::clean($_POST['boarding_stop'] ?? '', 191);
            if ($newBoard === '__other__') { $newBoard = $otherStop((string) ($_POST['boarding_other'] ?? '')); }
            $newDrop  = Security::clean($_POST['drop_stop'] ?? '', 191);
            if ($newDrop === '__other__') { $newDrop = $otherStop((string) ($_POST['drop_other'] ?? '')); }
            $remark   = Security::clean($_POST['remark'] ?? '', 180);
            $paxIn    = is_array($_POST['pax'] ?? null) ? $_POST['pax'] : [];
            $staffWho = (string) (($admin['full_name'] ?? '') ?: ($admin['username'] ?? 'staff'));

            /* 15 Oct 2026 (1G-rollback): ONE transaction - a failure halfway
               (say the stops saved but a name refused) used to leave a
               half-applied edit with no audit row. The audit is written inside,
               the ticket is re-synced after the commit. */
            $done = Database::transaction(static function () use (
                $bid, $pnr, $admin, $reason, $isSuper, $mayF, $deny,
                $newPhone, $newEmail, $newBoard, $newDrop, $remark, $paxIn, $staffWho
            ): array {
                $changes  = [];
                $oldVals  = [];   // 5 Sep 2026: every edit records what it was → what it is now
                $newVals  = [];
                $paxNames = [];   // 17 Sep 2026: who was edited, for the change notice
                $cur = Database::fetchForUpdate('SELECT id, pnr, contact_phone, contact_country_code, contact_email, admin_note FROM bookings WHERE id = :id', ['id' => $bid])[0] ?? null;
                if ($cur === null) { throw new RuntimeException('Booking not found.'); }
                $curLeg = Database::fetch("SELECT boarding_stop, drop_stop FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound' LIMIT 1", ['b' => $bid]) ?? [];

                // Contact details
                $upd = ['updated_at' => date('Y-m-d H:i:s')];
                if ($newPhone !== '' && $newPhone !== (string) $cur['contact_phone']) {
                    if (!$mayF('phone')) { $deny('phone number'); }
                    if (strlen($newPhone) < 10 || strlen($newPhone) > 15) { throw new RuntimeException('Enter a valid phone number (10-15 digits).'); }
                    $upd['contact_phone'] = $newPhone; $changes[] = 'phone';
                    $oldVals['contact_phone'] = $cur['contact_phone']; $newVals['contact_phone'] = $newPhone;
                    /* India and Nepal share the 10-digit mobile format, so the dialing
                       code is CAPTURED at booking (contact_country_code) and the
                       notifier trusts it. A corrected number that carries its own
                       prefix (+977… / +91…) re-derives the code here; a bare 10-digit
                       correction says nothing about the country, so the stored code
                       is kept (17 Sep 2026). */
                    $newCc = countryDialCode(resolvePhoneCountry('', $newPhone));
                    if ($newCc !== '' && $newCc !== (string) ($cur['contact_country_code'] ?? '')) {
                        $upd['contact_country_code'] = $newCc;
                        $oldVals['contact_country_code'] = $cur['contact_country_code']; $newVals['contact_country_code'] = $newCc;
                    }
                }
                if ($newEmail !== '' && $newEmail !== (string) ($cur['contact_email'] ?? '')) {
                    if (!$mayF('email')) { $deny('email'); }
                    $upd['contact_email'] = $newEmail; $changes[] = 'email';
                    $oldVals['contact_email'] = $cur['contact_email']; $newVals['contact_email'] = $newEmail;
                }

                // Boarding/drop stops
                $legUpd = [];
                if ($newBoard !== '' && $newBoard !== (string) ($curLeg['boarding_stop'] ?? '')) {
                    if (!$mayF('pickup')) { $deny('pickup point'); }
                    $legUpd['boarding_stop'] = $newBoard; $changes[] = 'boarding stop';
                    $oldVals['boarding_stop'] = $curLeg['boarding_stop'] ?? null; $newVals['boarding_stop'] = $newBoard;
                }
                if ($newDrop !== '' && $newDrop !== (string) ($curLeg['drop_stop'] ?? '')) {
                    if (!$mayF('pickup')) { $deny('drop point'); }
                    $legUpd['drop_stop'] = $newDrop; $changes[] = 'drop stop';
                    $oldVals['drop_stop'] = $curLeg['drop_stop'] ?? null; $newVals['drop_stop'] = $newDrop;
                }
                $stopsText = '';
                if ($legUpd !== []) {
                    /* 15 Oct 2026 (1A-stops): a pickup with its own fare (Admin ->
                       Routes) re-prices the seat. The ticket keeps the fare it was
                       sold at, so a stop change that moves the price is refused
                       for everyone but a super-admin, naming the difference; the
                       super-admin's override is written to the audit row. */
                    $fareDiff = TicketEdit::stopFareDelta($bid,
                        (string) ($curLeg['boarding_stop'] ?? ''), (string) ($curLeg['drop_stop'] ?? ''),
                        (string) ($legUpd['boarding_stop'] ?? ($curLeg['boarding_stop'] ?? '')), (string) ($legUpd['drop_stop'] ?? ($curLeg['drop_stop'] ?? '')));
                    if (abs($fareDiff) >= 0.005) {
                        if (!$isSuper) {
                            throw new RuntimeException('The fare for that pickup / drop differs by ' . inr(abs($fareDiff))
                                . ' (' . ($fareDiff > 0 ? 'dearer' : 'cheaper') . ') — ask the office (Request correction → Fare), or cancel and rebook.');
                        }
                        $oldVals['fare_difference'] = 0; $newVals['fare_difference'] = $fareDiff;
                    }
                    Database::update('booking_legs', $legUpd,
                        "booking_id = :b AND leg_type = 'outbound'",
                        ['b' => $bid]);
                    $stopsText = trim((isset($legUpd['boarding_stop']) ? 'pickup ' . $legUpd['boarding_stop'] : '')
                        . (isset($legUpd['drop_stop']) ? (isset($legUpd['boarding_stop']) ? ' · ' : '') . 'drop ' . $legUpd['drop_stop'] : ''));
                }

                if (count($upd) > 1) {
                    Database::update('bookings', $upd, 'id = :id', ['id' => $bid]);
                    // The payment row carries the payer's number too (invoice, refund
                    // desk); a corrected contact must not leave it stale (17 Sep 2026).
                    if (isset($upd['contact_phone'])) {
                        Database::update('payments', ['payer_phone' => $newPhone], 'booking_id = :b', ['b' => $bid]);
                    }
                }

                // Per-passenger updates (name / age / gender / ID document)
                foreach ($paxIn as $paxId => $paxData) {
                    $paxId = (int) $paxId;
                    if (!is_array($paxData)) { continue; }
                    $curPax = Database::fetch('SELECT full_name, age, gender, id_type, id_number, seat_no FROM booking_passengers WHERE id = :id AND booking_id = :b', ['id' => $paxId, 'b' => $bid]);
                    if ($curPax === null) { continue; }
                    $pUpd = [];
                    $pName = Security::clean((string) ($paxData['name'] ?? ''), 120);
                    if ($pName !== '' && $pName !== (string) $curPax['full_name']) { $pUpd['full_name'] = $pName; }
                    $pAge = isset($paxData['age']) && $paxData['age'] !== '' ? (int) $paxData['age'] : null;
                    if ($pAge !== null && $pAge !== (int) ($curPax['age'] ?? 0)) { $pUpd['age'] = $pAge; }
                    $pGender = in_array($paxData['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? (string) $paxData['gender'] : null;
                    if ($pGender !== null && $pGender !== (string) ($curPax['gender'] ?? '')) { $pUpd['gender'] = $pGender; }
                    $pIdT = Security::clean((string) ($paxData['id_type'] ?? ''), 60);
                    if ($pIdT !== '' && $pIdT !== (string) ($curPax['id_type'] ?? '')) { $pUpd['id_type'] = $pIdT; }
                    $pIdN = Security::clean((string) ($paxData['id_number'] ?? ''), 60);
                    if ($pIdN !== '' && $pIdN !== (string) ($curPax['id_number'] ?? '')) { $pUpd['id_number'] = $pIdN; }
                    if ($pUpd === []) { continue; }
                    foreach (['full_name' => 'name', 'age' => 'age', 'gender' => 'gender', 'id_type' => 'id', 'id_number' => 'id'] as $col => $right) {
                        if (isset($pUpd[$col]) && !$mayF($right)) { $deny($right === 'id' ? 'ID document' : 'passenger ' . $right); }
                    }
                    $seatKey = (string) ($curPax['seat_no'] ?: ('#' . $paxId));

                    /* 15 Oct 2026 (1G-validate): a gender correction must not put a
                       man and a woman of DIFFERENT bookings in one shared cabin -
                       recomputeUnitLock below would then lock that cabin against
                       every future sale. The passenger moves first (Change seat).
                       A super-admin may still record the truth. */
                    if (isset($pUpd['gender']) && !$isSuper && $seatKey !== '' && $seatKey[0] !== '#'
                        && TicketEdit::genderClash($bid, $seatKey, (string) $pUpd['gender'])) {
                        throw new RuntimeException('Seat ' . $seatKey . ' shares its cabin with a passenger of another booking — move the passenger to another cabin first (Change seat), then correct the gender.');
                    }

                    Database::run(
                        "UPDATE booking_passengers SET " . implode(', ', array_map(fn($k) => "$k = :$k", array_keys($pUpd))) . " WHERE id = :id AND booking_id = :bid",
                        array_merge($pUpd, ['id' => $paxId, 'bid' => $bid])
                    );

                    /* A gender correction changes who may share the cabin, so
                       the cabin's lock has to be recomputed — schedule_unit_locks
                       is the authoritative shared-cabin state and nothing here
                       was touching it. Without this, fixing a wrongly-entered
                       gender left the cabin locked to the wrong sex: it stayed
                       'female_only' after its only occupant was corrected to
                       Male, and kept refusing every legitimate sharing sale
                       into it until someone cancelled the booking. */
                    if (isset($pUpd['gender']) && $seatKey !== '' && $seatKey[0] !== '#') {
                        $legRow = Database::fetch(
                            'SELECT bl.schedule_id, b.booking_mode
                               FROM booking_legs bl
                               JOIN bookings b ON b.id = bl.booking_id
                              WHERE bl.booking_id = :b LIMIT 1',
                            ['b' => $bid]
                        );
                        if ($legRow !== null) {
                            $gSid   = (int) $legRow['schedule_id'];
                            $gCoach = Seats::coachForSchedule($gSid);
                            Seats::recomputeUnitLock(
                                $gSid,
                                Seats::unitKey($seatKey, $gCoach, (string) ($legRow['booking_mode'] ?? 'sharing')),
                                $gCoach
                            );
                        }
                    }

                    $changes[] = 'passenger ' . $seatKey;
                    $paxNames[] = (string) ($pUpd['full_name'] ?? $curPax['full_name']);
                    foreach ($pUpd as $fk => $fv) {
                        $oldVals['pax ' . $seatKey . ' ' . $fk] = $curPax[$fk] ?? null;
                        $newVals['pax ' . $seatKey . ' ' . $fk] = $fv;
                    }
                }

                /* 15 Oct 2026 (1A-remarks): an internal remark is APPENDED to
                   bookings.admin_note with who and when - never replacing the
                   earlier ones (the per-seat cancel log lives there too). */
                if ($remark !== '') {
                    if (!$mayF('remark')) { $deny('remarks'); }
                    $note = BookingService::appendNote((string) ($cur['admin_note'] ?? ''), $remark . ' — ' . $staffWho . ' [' . date('j M H:i') . ']');
                    Database::update('bookings', ['admin_note' => $note], 'id = :id', ['id' => $bid]);
                    $changes[] = 'remark';
                    $oldVals['admin_note'] = $cur['admin_note'] ?? null; $newVals['admin_note'] = $note;
                }

                if ($changes !== []) {
                    /* The QR payload is signed over the contact phone
                       (Ticket::qrPayload), so a corrected number re-mints the QR
                       exactly as a seat change does — otherwise the PNG on the
                       passenger's phone keeps a QR signed for a number the booking
                       no longer has (17 Sep 2026). */
                    Logger::audit('booking.edit_passenger', 'booking', $pnr, $oldVals, $newVals,
                        'Edited ' . implode(', ', $changes) . ' by admin #' . $admin['id'], $reason);
                }
                return [
                    'changes'  => $changes,
                    'paxNames' => $paxNames,
                    'contact'  => isset($upd['contact_phone']) || isset($upd['contact_email']),
                    'stops'    => $stopsText,
                ];
            });

            $changes = $done['changes'];
            $onTicket = array_values(array_diff($changes, ['remark']));
            if ($changes === []) {
                $flash = ['ok', 'Nothing changed — the values you typed match the booking already.'];
            } elseif ($onTicket === []) {
                // An internal remark is not on the ticket: no re-issue, no message.
                $flash = ['ok', 'Remark added to the booking (internal — the passenger is not told).'];
            } else {
                // Any edit: new QR / REV, dropped caches, redrawn PNG, one audit row.
                // A phone / email change is a 'contact' notice (it reaches the NEW
                // number); a pickup / drop change alone is a 'stops' notice (15 Oct
                // 2026 - it used to read as a passenger-details edit); anything
                // else is a 'passenger' notice naming who changed.
                // notify=true: office-side kinds by default, but this desk edit has
                // always told the passenger (settings notify_ticket_edit).
                $kind = $done['contact'] ? 'contact' : ($done['paxNames'] !== [] ? 'passenger' : 'stops');
                $regenNote = bv_sync_ticket($bid, $pnr, $kind, $kind === 'stops'
                    ? ['new' => (string) $done['stops']]
                    : ['name' => implode(', ', array_unique($done['paxNames'])), 'new' => implode(', ', $onTicket)],
                    true, $reason);
                $flash = ['ok', 'Booking details updated: ' . implode(', ', $changes) . '.' . $regenNote];
                $bvEdited = true;
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

$b = BookingService::detail($pnr);

/* ---- Agent scope --------------------------------------------------
   A counter agent may open a ticket they sold and no other. Without this the
   PNR in the URL is the only thing between an agent and every passenger
   record in the company — and a PNR is printed on the ticket, so it is not a
   secret. Nulling $b here also disarms every action block below, since each
   one is guarded on $b !== null.

   Treated as "not found" rather than "forbidden" on purpose: confirming that
   a PNR exists is itself a disclosure. */
$scopeId = Auth::bookingScopeAdminId();
if ($b !== null && $scopeId !== null && (int) ($b['sold_by_admin_id'] ?? 0) !== $scopeId) {
    Logger::warning('Agent tried to open a booking they did not sell', [
        'admin' => $admin['username'] ?? '',
        'pnr'   => $pnr,
    ]);
    $b = null;
}

/* ---- Passenger photo / ID documents (17 Sep 2026) --------------------
   The office asked for the picture, not just the ID number: a face photo
   for the manifest, the front / back of the citizenship or Aadhaar for the
   border. Both actions sit AFTER the scope guard above ($b is null for a
   foreign PNR), are CSRF-checked, need bookings.edit (the same right as
   editing a passenger's name) and honour the office switch
   settings.passenger_docs_on. Validation, storage, scope and audit live in
   PassengerDocs; this is only the form glue. Files are served through
   admin/passenger-doc.php, never by their upload path. */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'upload_pax_doc') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            Auth::requireAdmin('bookings.edit');
            if (!Settings::getBool('passenger_docs_on', true)) {
                throw new RuntimeException('Passenger photo / ID uploads are switched off in Settings.');
            }
            $paxId  = (int) ($_POST['pax_id'] ?? 0);
            $kind   = Security::clean($_POST['kind'] ?? 'photo', 20);
            $paxRow = null;
            foreach (($b['passengers'] ?? []) as $pp) {
                if ((int) $pp['id'] === $paxId) { $paxRow = $pp; break; }
            }
            if ($paxRow === null) {
                throw new RuntimeException('That passenger is not on this booking.');
            }
            if (!isset($_FILES['doc']) || (int) ($_FILES['doc']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Choose a photo or document file first.');
            }
            $res   = PassengerDocs::attach($paxId, $_FILES['doc'], $kind, (int) $admin['id']);
            $flash = ['ok', PassengerDocs::label((string) $res['kind']) . ' attached to ' . (string) $paxRow['full_name']
                . ' (seat ' . Seats::displayLabel((string) $paxRow['seat_no'], 'sleeper', (string) ($b['booking_mode'] ?? 'sharing'))
                . ', ' . fileSizeLabel((int) $res['size']) . '). Stored privately — it is not printed on the ticket.'];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'remove_pax_doc') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            Auth::requireAdmin('bookings.edit');
            if (!Settings::getBool('passenger_docs_on', true)) {
                throw new RuntimeException('Passenger photo / ID uploads are switched off in Settings.');
            }
            $docId = (int) ($_POST['doc_id'] ?? 0);
            $doc   = PassengerDocs::get($docId);
            // The document must belong to THIS booking — the id in the form is
            // not trusted to point anywhere else.
            if ($doc === null || (int) ($doc['booking_id'] ?? 0) !== (int) $b['id']) {
                throw new RuntimeException('Document not found.');
            }
            PassengerDocs::remove($docId, (int) $admin['id']);
            $flash = ['ok', PassengerDocs::label((string) ($doc['kind'] ?? '')) . ' removed from ' . (string) ($doc['full_name'] ?: 'the passenger') . '.'];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Change seat on the ticket page (5 Sep 2026) --------------------
   Same engine the admin seat map uses (Seats::transferSeat: schedule row
   FOR UPDATE + physical-space check + UNIQUE(schedule_id, seat_no)), so a
   double booking is impossible; then the QR + PDF are re-minted because the
   QR payload carries the seat list. Gated on the trip edit window. */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'change_seat') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            // 15 Oct 2026 (Quick Edit): the seat right per role (Auth::mayEditField
            // - bookings.edit, or the agent / counter field rules).
            if (!Auth::mayEditField($b, 'seat')) {
                if (!Auth::can('bookings.edit')) { Auth::requireAdmin('bookings.edit'); }
                throw new RuntimeException('Your role may not move seats on this ticket — use Office chat.');
            }
            if (!in_array((string) $b['status'], ['pending', 'confirmed'], true)) {
                throw new RuntimeException('Only a pending or confirmed booking can change seat.');
            }
            $legRow = $b['legs'][0] ?? null;
            if ($legRow === null) { throw new RuntimeException('This booking has no journey leg.'); }
            $sid   = (int) $legRow['schedule_id'];
            $tGate = TripStatus::editableFor($sid);
            if (!($tGate['editable'] ?? false) && !Auth::isSuperadmin()) {
                throw new RuntimeException('Trip is ' . ($tGate['label'] ?? 'closed') . ' — seat changes are locked.');
            }
            /* 15 Oct 2026 (1B-freeseats): THIS departure's coach. An extra bus
               may run its own layout (schedules.coach_type_override); the route's
               coach put the unit key and the gender check in the wrong layout. */
            $coach = Seats::coachForSchedule($sid);
            $fromSeat = strtoupper(Security::clean($_POST['from_seat'] ?? '', 10));
            $toSeat   = strtoupper(Security::clean($_POST['to_seat'] ?? '', 10));
            if (!in_array($fromSeat, array_map('strval', $legRow['seats'] ?? []), true)) {
                throw new RuntimeException('Seat ' . Seats::displayLabel($fromSeat, $coach, (string) ($b['booking_mode'] ?? 'sharing')) . ' is not on this booking.');
            }
            $seatReason = Security::clean($_POST['reason'] ?? '', 255);
            // transferSeat() owns the ticket (TicketSync 'seat', after its commit):
            // QR re-minted ONCE - this page used to reissue a second time, so one
            // seat change printed REV +2 - PNG redrawn, audited with the reason,
            // passenger told. Its real outcome is read back for the flash.
            $res = Seats::transferSeat($sid, $fromSeat, $toSeat, $coach, (int) $admin['id'], $seatReason);
            Logger::audit('booking.seat_change', 'booking', $pnr, ['seat' => $res['from'] ?? $fromSeat], ['seat' => $res['to'] ?? $toSeat],
                'Seat changed on the ticket page by admin #' . $admin['id'], $seatReason);
            $notice = TicketSync::note(TicketSync::last((int) $b['id']));
            $flash = ['ok', 'Seat changed ' . Seats::displayLabel((string) ($res['from'] ?? $fromSeat), $coach, (string) ($b['booking_mode'] ?? 'sharing')) . ' → ' . Seats::displayLabel((string) ($res['to'] ?? $toSeat), $coach, (string) ($b['booking_mode'] ?? 'sharing')) . '.' . $notice];
            $b = BookingService::detail($pnr);
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Edit payment method / reference (5 Sep 2026) --------------------
   The latest payment row's METHOD (upi / esewa / cash / bank / wallet / cod),
   UTR / transaction reference and office note. Status changes stay on the
   approve / reject / cash-collected buttons, which move money and seats. */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'edit_payment') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            // 15 Oct 2026: payments.verify, or the counter / agent field rule
            // (Auth::mayEditField 'payment'); the body is bv_apply_payment().
            if (!Auth::mayEditField($b, 'payment')) {
                if (!Auth::can('payments.verify')) { Auth::requireAdmin('payments.verify'); }
                throw new RuntimeException('Your role may not change the payment record — use “Request correction”.');
            }
            $payRes = bv_apply_payment($b,
                (string) ($_POST['pay_method'] ?? ''),
                Security::clean($_POST['utr_number'] ?? '', 60),
                Security::clean($_POST['pay_note'] ?? '', 255),
                (int) $admin['id'], $pnr);
            $flash = ['ok', $payRes['flash']];
            $bvEdited = $payRes['changed'] !== [];
            $b = BookingService::detail($pnr);
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Fare / discount correction (15 Oct 2026, owner: Quick Edit) ----
   SUPER-ADMIN ONLY. TicketEdit::correctFare() moves the booking total /
   discount, the latest payment, the seller's commission (re-rated, UPDATED in
   place) and the cash they hold in ONE transaction, audited with the reason.
   Every other role asks through "Request correction" below. Refunds are not
   touched - the owner settles those by hand. */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'fare_correct') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            if (!Auth::isSuperadmin()) {
                throw new RuntimeException('Only a super-admin corrects a fare — use “Request correction”.');
            }
            $num = static function (string $k): ?float {
                $v = str_replace([',', '₹', ' '], '', trim((string) ($_POST[$k] ?? '')));
                if ($v === '') { return null; }
                if (!is_numeric($v)) { throw new RuntimeException('Type the amount in numbers only.'); }
                return round((float) $v, 2);
            };
            $ft = $num('new_total');
            $fd = $num('new_discount');
            // Both boxes come prefilled: an unchanged total next to a changed
            // discount means "move the total by the discount".
            if ($ft !== null && abs($ft - (float) $b['total_amount']) < 0.005) { $ft = null; }
            if ($fd !== null && abs($fd - (float) ($b['coupon_discount'] ?? 0)) < 0.005) { $fd = null; }
            if ($ft === null && $fd === null) {
                $flash = ['ok', 'Nothing changed — that is the fare on the ticket already.'];
            } else {
                $fr = TicketEdit::correctFare((int) $b['id'], $ft, $fd, (int) $admin['id'], (string) ($_POST['reason'] ?? ''));
                $flash = ['ok', 'Fare corrected ' . inr($fr['oldTotal']) . ' → ' . inr($fr['newTotal'])
                    . ($fr['commission'] !== null ? ' · agent commission ' . inr($fr['commission']['old']) . ' → ' . inr($fr['commission']['new']) : '')
                    . ($fr['cash'] !== null ? ' · cash held ' . inr($fr['cash']['old']) . ' → ' . inr($fr['cash']['new']) : '')
                    . '. Refunds are not touched.' . TicketSync::note(TicketSync::last((int) $b['id']))];
                $bvEdited = true;
            }
            $b = BookingService::detail($pnr);
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Request correction (15 Oct 2026, owner: approval for money edits) --
   Anyone who can open this ticket (the scope guard above already nulled a
   foreign one) may ASK the office to correct the fare, the payment method,
   the selling agent, or to cancel. The request is a ticket_edit_audit row
   (outcome 'requested') + the office bell; nothing on the booking changes. */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'edit_request') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            $rField = (string) ($_POST['req_field'] ?? '');
            $rValue = match ($rField) {
                'fare'           => (string) ($_POST['req_fare'] ?? ''),
                'payment_method' => (string) ($_POST['req_method'] ?? ''),
                'cancel_request' => '',
                'agent'          => (static function (): string {
                    $raw = trim((string) ($_POST['req_agent'] ?? ''));
                    if ($raw === '' || in_array(strtolower($raw), ['0', 'office', 'none'], true)) { return '0'; }
                    $aid = AgentWallet::resolveAgentCodeFromString($raw);
                    if ($aid === null) { throw new RuntimeException('Type the agent code, e.g. SHG-0007 (or "office").'); }
                    return (string) $aid;
                })(),
                default          => throw new RuntimeException('Choose what should be corrected.'),
            };
            $rid = TicketEdit::request($b, $rField, $rValue, (string) ($_POST['req_reason'] ?? ''), $admin);
            $flash = ['ok', 'Request #' . $rid . ' sent to the office — a super-admin or manager applies or refuses it on this page. Nothing on the ticket has changed yet.'];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Apply / refuse a correction request (15 Oct 2026) -------------
   Super-admin or a manager (canManageSettings). "Apply" runs the SAME path a
   direct edit uses, with the stored value, inside one transaction that holds
   the request row FOR UPDATE (two approvers cannot apply it twice), then
   marks it done naming the approver. A cancel request is never applied from
   here - the normal Cancel button does it (owner settles refunds by hand). */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'edit_decide') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            if (!Auth::isSuperadmin() && !Auth::canManageSettings()) {
                throw new RuntimeException('Only a super-admin or manager decides correction requests.');
            }
            $reqId    = (int) ($_POST['request_id'] ?? 0);
            $decision = (string) ($_POST['decision'] ?? '');
            $decNote  = Security::clean($_POST['decide_note'] ?? '', 120);
            $by       = (string) (($admin['full_name'] ?? '') ?: ($admin['username'] ?? 'staff')) . ' #' . (int) $admin['id'];
            $bk       = $b;
            $flashMsg = Database::transaction(static function () use ($reqId, $decision, $decNote, $by, $bk, $admin, $pnr): string {
                $row = Database::fetchForUpdate(
                    "SELECT * FROM ticket_edit_audit WHERE id = :i AND booking_id = :b AND outcome = 'requested' AND changed_by LIKE 'staff:%'",
                    ['i' => $reqId, 'b' => (int) $bk['id']]
                )[0] ?? null;
                if ($row === null) {
                    throw new RuntimeException('That request is already closed.');
                }
                $field = (string) $row['field_changed'];
                $label = TicketEdit::REQUEST_FIELDS[$field] ?? $field;
                if ($decision === 'refuse') {
                    TicketEdit::close($reqId, 'refused', $by, $decNote);
                    Logger::audit('booking.edit_request_refused', 'booking', $pnr, [$field => $row['old_value']], [$field => $row['new_value']],
                        'Correction request #' . $reqId . ' refused by ' . $by, $decNote);
                    return 'Request #' . $reqId . ' (' . $label . ') refused.';
                }
                if ($decision !== 'apply') {
                    throw new RuntimeException('Unknown decision.');
                }
                $why = 'request #' . $reqId . ($decNote !== '' ? ' — ' . $decNote : '') . ' (' . (string) $row['detail'] . ')';
                switch ($field) {
                    case 'fare':
                        if (!Auth::isSuperadmin()) {
                            throw new RuntimeException('A fare correction is applied by a super-admin — you may refuse it.');
                        }
                        $fr  = TicketEdit::correctFare((int) $bk['id'], (float) $row['new_value'], null, (int) $admin['id'], $why);
                        $msg = 'Fare corrected ' . inr($fr['oldTotal']) . ' → ' . inr($fr['newTotal']) . '.';
                        break;
                    case 'payment_method':
                        if (!Auth::can('payments.verify')) {
                            throw new RuntimeException('Your role cannot change a payment record.');
                        }
                        $pr  = bv_apply_payment($bk, (string) $row['new_value'], '', '', (int) $admin['id'], $pnr);
                        $msg = $pr['flash'];
                        break;
                    case 'agent':
                        if (!Auth::mayReassignSeller()) {
                            throw new RuntimeException('Your role cannot change the selling agent.');
                        }
                        $ar  = AgentWallet::reassignSeller($bk, (int) $row['new_value'], (int) $admin['id']);
                        $msg = 'Selling agent changed to ' . TicketEdit::sellerLabel((int) $row['new_value'])
                            . ($ar['commission'] > 0 ? ' · commission re-posted ' . inr($ar['commission']) : '') . '. Cash-in-hand is not moved.';
                        break;
                    default:   // cancel_request
                        throw new RuntimeException('A cancel is done with the Cancel button below (the refund is settled by hand) — the request closes by itself then. Or refuse it.');
                }
                TicketEdit::close($reqId, 'done', $by, $decNote);
                Logger::audit('booking.edit_request_applied', 'booking', $pnr, [$field => $row['old_value']], [$field => $row['new_value']],
                    'Correction request #' . $reqId . ' applied by ' . $by, $decNote);
                return 'Request #' . $reqId . ' applied: ' . $msg;
            });
            $flash = ['ok', $flashMsg];
            $bvEdited = $decision === 'apply';
            $b = BookingService::detail($pnr);
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Upload payment proof on the customer's behalf (6 Sep 2026) ----
   Until now BookingService::attachScreenshot() had exactly one caller: the
   public api/payment.php. A customer who could not upload from their own
   phone — a bad connection at the counter, a feature phone, a screenshot
   sitting in the office WhatsApp instead of the app — had no path at all,
   and the office could only record the payment as counter-received, which
   produces no image to verify against later.

   Deliberately reuses the customer pipeline rather than writing to
   payments/ directly: attachScreenshot() carries the MIME + size
   validation, the random 64-bit filename and the sha256, and
   submitPaymentProof() emits booking.payment_uploaded so the customer gets
   the same notification they would have got had they uploaded it
   themselves. Both are audited with the staff id, because an
   office-uploaded proof must be distinguishable from one the passenger
   sent. */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'upload_proof') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            // Same permission as verifying a payment: whoever may approve
            // money may also attach the evidence for it.
            Auth::requireAdmin('payments.verify');

            $hasFile = isset($_FILES['proof']) && ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            $utr     = Security::clean($_POST['proof_utr'] ?? '', 60);
            $method  = strtolower(trim((string) ($_POST['proof_method'] ?? 'upi')));
            if (!in_array($method, ['upi', 'esewa', 'cash', 'bank', 'wallet', 'cod'], true)) {
                $method = 'upi';
            }

            if (!$hasFile && $utr === '') {
                throw new RuntimeException('Attach a screenshot or type the transaction reference.');
            }

            $stored = null;
            if ($hasFile) {
                $info   = BookingService::attachScreenshot($pnr, $_FILES['proof']);
                $stored = $info['file'] ?? null;
            }

            BookingService::submitPaymentProof($pnr, [
                'utr'           => $utr,
                'payerName'     => Security::clean($_POST['proof_payer'] ?? '', 120),
                'method'        => $method,
                'hasScreenshot' => $hasFile,
            ]);

            Logger::audit('payment.proof.staff_upload', 'booking', $pnr, null,
                ['file' => $stored, 'utr' => $utr, 'method' => $method],
                'Proof uploaded on behalf of the customer by admin #' . $admin['id']);

            $flash = ['ok', 'Proof recorded for ' . $pnr . ' — it is now in the verification queue.'];
            $b = BookingService::detail($pnr);
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Resend WhatsApp ticket (confirmed bookings only) ------------- */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'resend_wa') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (($b['status'] ?? '') !== 'confirmed') {
        $flash = ['bad', 'Tickets can only be re-sent for confirmed bookings.'];
    } elseif (!Auth::can('payments.verify')) {
        $flash = ['bad', 'Your role cannot re-send tickets.'];
    } else {
        try {
            $r = Notify::resendTicketWhatsApp($b);
            /* Keep the click-to-chat URL out of the escaped flash text and
               render it as a real button below — printed as plain text it
               was a 200-character line nobody could click, which made the
               no-Twilio path look like a failure instead of "press send". */
            $flash = [$r['ok'] ? 'ok' : 'bad', $r['detail']];
            $waManualLink = $r['link'] ?? '';
            // Who re-sent what, and whether it went out (5 Sep 2026): the
            // resend used to leave no trace anywhere.
            $sendStatus = $r['ok'] ? 'sent' : ($waManualLink !== '' ? 'skipped' : 'failed');
            if (($r['provider'] ?? '') !== 'aisensy') {   // AiSensy already wrote its own row
                bv_log_message((int) $b['id'], (string) $b['contact_phone'], $sendStatus, $waManualLink !== '' ? 'manual' : 'twilio', $r['ok'] ? '' : (string) $r['detail']);
            }
            Logger::audit('booking.resend_wa', 'booking', $pnr, null, ['status' => $sendStatus, 'to' => (string) $b['contact_phone']],
                'Ticket resend on WhatsApp by admin #' . $admin['id']);
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Cash on delivery: record the money as collected --------------
   COD confirms on submit so the passenger's ticket works, but the fare is
   still owed and the payment row stays 'cod_pending'. This is the only place
   that can close it, so the counter can reconcile the day's cash. */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'cod_settle') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            Auth::requireAdmin('payments.verify');
            $note = Security::clean($_POST['note'] ?? '', 255);
            BookingService::settleCod((int) $b['id'], (int) $admin['id'], $note);
            $flash = ['ok', 'Cash recorded for ' . $pnr . ' — the payment is now settled.'];
            $b = BookingService::detail($pnr);
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Channel tag (5 Oct 2026) --------------------------------------
   "This booking began as a WhatsApp request" - ticked at the desk at sale
   time, corrected here afterwards. Anyone who may edit bookings; audited. */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'channel_wa') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            Auth::requireAdmin('bookings.edit');
            $on = ($_POST['on'] ?? '') === '1';
            if (!BookingChannel::markWhatsapp((int) $b['id'], $on, 'set on the booking page by admin #' . (int) $admin['id'])) {
                throw new RuntimeException('The channel could not be saved.');
            }
            $flash = ['ok', $on ? 'Marked as a WhatsApp request.' : 'WhatsApp mark removed.'];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Reversible payment review (§4) ------------------------------
   An authorised admin can move a booking between confirmed and rejected at
   any time (e.g. undo a mistaken approval). Every transition is audit-logged
   with old→new state inside BookingService, and the customer's ticket
   re-locks / unlocks on their next status refresh. */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'review') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $decision = (string) ($_POST['decision'] ?? '');
        $note     = Security::clean($_POST['note'] ?? '', 255);
        try {
            /* 9 Oct 2026 (anti-fake): the same approval gate as the verify
               queue - BookingService::approveReviewed() / PaymentRisk. Medium
               risk needs the bank-statement tick, high risk a written reason
               (and a second person when pay_two_step_on). Never auto-rejects. */
            require_once INCLUDE_PATH . '/paymentrisk.php';
            if ($decision === 'approve') {
                Auth::requireAdmin('payments.verify');
                $res = BookingService::approveReviewed((int) $b['id'], ['id' => (int) $admin['id'], 'role' => (string) ($admin['role'] ?? '')], [
                    'note' => $note, 'bankChecked' => !empty($_POST['bank_checked']),
                    'amountSeen' => $_POST['amount_seen'] ?? null, 'via' => 'booking',
                ]);
                $flash = $res['state'] === 'awaiting_second'
                    ? ['ok', 'First approval recorded — a super-admin, manager or accountant (not you) must give the second approval within 24 h.']
                    : ['ok', 'Payment approved — ticket issued for ' . ($res['booking']['pnr'] ?? $pnr) . '.'];
            } elseif ($decision === 'reject') {
                Auth::requireAdmin('payments.reject');
                $rcode = Security::clean($_POST['reason_code'] ?? '', 40);
                $reasonText = isset(PaymentRisk::REASONS[$rcode]) && !($rcode === 'other' && $note === '')
                    ? PaymentRisk::customerText($rcode, $note)
                    : ($note !== '' ? $note : 'Payment could not be verified.');
                $r = BookingService::rejectReviewed((int) $b['id'], (int) $admin['id'], $reasonText, ['reasonCode' => $rcode, 'note' => $note, 'via' => 'booking']);
                $flash = ['ok', 'Booking ' . ($r['pnr'] ?? $pnr) . ' marked rejected — seats released.'];
            } elseif ($decision === 'hold') {
                Auth::requireAdmin('payments.verify');
                $h = PaymentRisk::hold((int) $b['id'], ['id' => (int) $admin['id'], 'role' => (string) ($admin['role'] ?? '')], $note, 'booking');
                $flash = ['ok', $h['sent'] ? 'Asked the customer on WhatsApp for a clearer proof. The booking stays pending.' : 'Hold recorded. WhatsApp did not go out by itself — tap the WhatsApp button above to send it.'];
                $waManualLink = $h['sent'] ? '' : (string) $h['link'];
            } else {
                $flash = ['bad', 'Unknown decision.'];
            }
            $b = BookingService::detail($pnr); // refresh so the page shows the new state
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════
   SUPERADMIN POWER ACTIONS — edit, force, delete
   All gated on isSuperadmin() — no regular role can reach these.
═══════════════════════════════════════════════════════════════════ */
$isSuperHere = Auth::isSuperadmin();

/* ── Force-cancel (no refund policy, seats released) ──
   Was writing to bookings.cancelled_reason (real column: cancel_reason) and
   UPDATE booking_seats SET status='cancelled' — booking_seats has no `status`
   column at all, so the second query threw and the whole thing left seats
   still marked as booked (the UNIQUE key kept blocking resale). This now
   mirrors BookingService::cancel() minus the refund slab: authoritative
   column names, Seats::releaseBooking() (which DELETEs the row and recomputes
   gender locks), and commission/wallet reversal. Whole thing in a
   transaction so a partial failure leaves nothing half-cancelled. */
if ($b !== null && $isSuperHere && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'force_cancel') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired.'];
    } else {
        try {
            // Runs through the engine (BookingService::forceCancel) rather
            // than an inline transaction — that copy had drifted and was
            // missing the row lock, the loyalty-point reconciliation and the
            // booking.cancelled event, so a force-cancelled passenger kept
            // paying for points they never used and was never told at all.
            BookingService::forceCancel((int) $b['id'], (string) ($_POST['reason'] ?? 'Force-cancelled by admin'));
            $flash = ['ok', 'Booking force-cancelled. Seats released, commission voided, redeemed points returned. No refund processing — handle manually if needed.'];
            $b = BookingService::detail($pnr);
        } catch (Throwable $e) { $flash = ['bad', $e->getMessage()]; }
    }
}

/* ── Reset to pending (re-open for review) ── */
if ($b !== null && $isSuperHere && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'reset_pending') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired.'];
    } else {
        try {
            Database::run(
                "UPDATE bookings SET status='pending', updated_at=NOW() WHERE id=:id",
                ['id' => $b['id']]
            );
            // Keep the latest payment row in step (5 Sep 2026): a re-opened
            // booking whose payment still read 'verified' or 'rejected' sat
            // in the queue with contradictory pills until someone re-approved.
            $lastPayId = (int) Database::scalar('SELECT COALESCE(MAX(id),0) FROM payments WHERE booking_id = :b', ['b' => $b['id']], 0);
            $oldPayStatus = null;
            if ($lastPayId > 0) {
                $oldPayStatus = (string) Database::scalar('SELECT status FROM payments WHERE id = :i', ['i' => $lastPayId], '');
                if (in_array($oldPayStatus, ['verified', 'rejected'], true)) {
                    Database::update('payments', ['status' => 'pending'], 'id = :i', ['i' => $lastPayId]);
                }
            }
            // 3 Oct 2026: back to pending = not sold yet, so the seller's
            // commission is reversed; confirming again restores it (accrue()).
            AgentWallet::voidFor($b, 'booking set back to pending');
            Logger::audit('booking.reset_pending', 'booking', $pnr, ['status' => (string) $b['status'], 'payment' => $oldPayStatus], ['status' => 'pending', 'payment' => 'pending'], 'superadmin reset to pending');
            $flash = ['ok', 'Booking reset to PENDING — it will now show in the payment verification queue.'];
            $b = BookingService::detail($pnr);
        } catch (Throwable $e) { $flash = ['bad', $e->getMessage()]; }
    }
}

/* ── Edit contact / passenger name ── */
if ($b !== null && $isSuperHere && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'edit_contact') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired.'];
    } else {
        try {
            $newPhone = (string) preg_replace('/\D/', '', (string) ($_POST['contact_phone'] ?? ''));
            $newEmail = Security::clean($_POST['contact_email'] ?? '', 120);
            $newNote  = Security::clean($_POST['admin_note'] ?? '', 180);
            $paxNames = is_array($_POST['pax_name'] ?? null) ? $_POST['pax_name'] : [];
            $contactReason = Security::clean($_POST['reason'] ?? '', 255);
            $staffWho = (string) (($admin['full_name'] ?? '') ?: ($admin['username'] ?? 'staff'));
            $bidC     = (int) $b['id'];
            /* 15 Oct 2026: one transaction (1G-rollback), the audit inside, and
               the internal note is APPENDED with who / when - it used to REPLACE
               bookings.admin_note, wiping the per-seat cancel log kept there. */
            [$oldVals, $newVals, $upd] = Database::transaction(static function () use (
                $bidC, $pnr, $admin, $newPhone, $newEmail, $newNote, $paxNames, $contactReason, $staffWho
            ): array {
                $cur = Database::fetchForUpdate('SELECT contact_phone, contact_country_code, contact_email, admin_note FROM bookings WHERE id = :id', ['id' => $bidC])[0] ?? null;
                if ($cur === null) { throw new RuntimeException('Booking not found.'); }
                $upd = ['updated_at' => date('Y-m-d H:i:s')];
                $oldVals = []; $newVals = [];
                if ($newPhone !== '' && $newPhone !== (string) $cur['contact_phone']) {
                    $upd['contact_phone'] = $newPhone; $oldVals['contact_phone'] = $cur['contact_phone']; $newVals['contact_phone'] = $newPhone;
                    // Same dialing-code rule as edit_passenger (17 Sep 2026): re-derived
                    // only when the typed number carries its own +977 / +91 prefix.
                    $newCc = countryDialCode(resolvePhoneCountry('', $newPhone));
                    if ($newCc !== '' && $newCc !== (string) ($cur['contact_country_code'] ?? '')) {
                        $upd['contact_country_code'] = $newCc; $oldVals['contact_country_code'] = $cur['contact_country_code'] ?? null; $newVals['contact_country_code'] = $newCc;
                    }
                }
                if ($newEmail !== '' && $newEmail !== (string) ($cur['contact_email'] ?? '')) { $upd['contact_email'] = $newEmail; $oldVals['contact_email'] = $cur['contact_email'] ?? null; $newVals['contact_email'] = $newEmail; }
                if ($newNote  !== '') {
                    $note = BookingService::appendNote((string) ($cur['admin_note'] ?? ''), $newNote . ' — ' . $staffWho . ' [' . date('j M H:i') . ']');
                    $upd['admin_note'] = $note; $oldVals['admin_note'] = $cur['admin_note'] ?? null; $newVals['admin_note'] = $note;
                }
                if (count($upd) > 1) {
                    Database::update('bookings', $upd, 'id = :id', ['id' => $bidC]);
                    if (isset($upd['contact_phone'])) {   // the payer's number on the payment row too (17 Sep 2026)
                        Database::update('payments', ['payer_phone' => $newPhone], 'booking_id = :b', ['b' => $bidC]);
                    }
                }
                // Update passengers if pax name provided
                foreach ($paxNames as $paxId => $paxName) {
                    $paxName = Security::clean((string) $paxName, 120);
                    if ($paxName !== '') {
                        $was = (string) Database::scalar('SELECT full_name FROM booking_passengers WHERE id = :id AND booking_id = :bid', ['id' => (int) $paxId, 'bid' => $bidC], '');
                        if ($was === $paxName) { continue; }
                        Database::run(
                            "UPDATE booking_passengers SET full_name=:n WHERE id=:id AND booking_id=:bid",
                            ['n' => $paxName, 'id' => (int) $paxId, 'bid' => $bidC]
                        );
                        $oldVals['pax #' . (int) $paxId] = $was; $newVals['pax #' . (int) $paxId] = $paxName;
                    }
                }
                if ($oldVals !== [] || $newVals !== []) {
                    Logger::audit('booking.edit_contact', 'booking', $pnr, $oldVals, $newVals, 'superadmin contact edit by admin #' . $admin['id'],
                        $contactReason);
                }
                return [$oldVals, $newVals, $upd];
            });
            if ($oldVals === [] && $newVals === []) {
                $flash = ['ok', 'Nothing changed.'];
            } else {
                // Say what changed; an internal note alone is not the passenger's business.
                $contactWhat = [];
                if (isset($upd['contact_phone'])) { $contactWhat[] = 'phone'; }
                if (isset($upd['contact_email'])) { $contactWhat[] = 'email'; }
                foreach (array_keys($newVals) as $nk) {
                    if (str_starts_with((string) $nk, 'pax #')) { $contactWhat[] = 'passenger name'; break; }
                }
                // Any edit: new QR / REV, redrawn PNG, one audit row; the passenger
                // is told only when something THEY see changed.
                $contactRegen = bv_sync_ticket((int) $b['id'], $pnr, $contactWhat === ['passenger name'] ? 'passenger' : 'contact',
                    ['new' => implode(', ', $contactWhat)], $contactWhat !== [], $contactReason);
                $flash = ['ok', 'Booking details updated.' . $contactRegen];
            }
            $b = BookingService::detail($pnr);
        } catch (Throwable $e) { $flash = ['bad', $e->getMessage()]; }
    }
}

/* ── Reassign the selling agent (requirement 3: change WHO sold a ticket,
      even after it exists — "ticket banisakepachhi pani agent assign/change
      garna milne"). Moves the sale AND its commission/cash to the new seller,
      atomically, and audit-logs the correction. 0 = detach to the office.
      15 Oct 2026: Auth::mayReassignSeller() - super-admin, or a manager /
      anyone granted bookings.reassign - and no longer in the red panel. ── */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'reassign_agent') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired.'];
    } else {
        try {
            if (!Auth::mayReassignSeller()) {
                throw new RuntimeException('Your role cannot change the selling agent — use “Request correction”.');
            }
            $newAgentId = (int) ($_POST['new_agent_id'] ?? 0);   // 0 = office / online, no agent
            $res = AgentWallet::reassignSeller($b, $newAgentId, (int) $admin['id']);
            if ($res['old'] === $res['new']) {
                $flash = ['bad', 'That is already the selling agent — nothing changed.'];
            } else {
                $who = $newAgentId > 0
                    ? trim((AgentWallet::agentCodeLabel($newAgentId) !== '' ? AgentWallet::agentCodeLabel($newAgentId) . ' · ' : '')
                        . (string) Database::scalar("SELECT COALESCE(NULLIF(full_name,''), username) FROM admins WHERE id = :i", ['i' => $newAgentId], 'agent #' . $newAgentId))
                    : 'the office (online — no agent)';
                $tail = $res['commission'] > 0
                    ? ' Commission re-posted: ' . inr($res['commission']) . '.'
                    : ' Commission attribution updated (nothing to pay on this booking yet). Cash-in-hand is not moved.';
                $flash = ['ok', 'Selling agent changed to ' . $who . '.' . $tail];
            }
            $b = BookingService::detail($pnr);
        } catch (Throwable $e) { $flash = ['bad', $e->getMessage()]; }
    }
}

/* ── Hard delete (removes from DB entirely — irreversible!) ── */
if ($isSuperHere && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'hard_delete') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired.'];
    } else {
        try {
            $confirm = (string) ($_POST['confirm_delete'] ?? '');
            if ($confirm !== $pnr) {
                $flash = ['bad', 'Type the PNR exactly to confirm deletion.'];
            } elseif ($b === null) {
                $flash = ['bad', 'Booking not found.'];
            } else {
                $bid = (int) $b['id'];
                // Previously the explicit DELETEs only covered 5 tables and
                // orphaned tickets, commissions, agent_wallet_entries and
                // payment_screenshots (which cascades via payments). Every
                // one of those has ON DELETE CASCADE to bookings/payments
                // (schema.sql:444, 501, 565, 590, 471), so a single
                // DELETE FROM bookings clears them all in dependency order.
                //
                // Seats::releaseBooking() runs first (outside the FK cascade)
                // so schedule_unit_locks and the gender-lock rollup are
                // recomputed the same way BookingService::cancel() does it —
                // otherwise the shared-cabin gender flag would linger after
                // the last female was hard-deleted.
                // The rendered PNG/PDF/invoice must not outlive the row (they were
                // orphaned on disk before): void + unlink through the one hook.
                // ONCE (15 Oct 2026: a duplicated copy wrote two ticket.voided rows).
                try { TicketSync::afterBookingChange($bid, 'delete', [], ['reason' => 'superadmin permanent delete']); }
                catch (Throwable $e) { Logger::warning('Ticket cleanup before hard delete failed', ['pnr' => $pnr, 'e' => $e->getMessage()]); }
                Database::transaction(static function () use ($bid, $b): void {
                    Seats::releaseBooking($bid);
                    Database::update('commissions', ['status' => 'void'], 'booking_id = :b', ['b' => $bid]);
                    // 3 Oct 2026: agent_ledger has no foreign key — remove the
                    // booking's commission AND cash-held rows with it (audited),
                    // instead of leaving a reversed commission and a cash_due
                    // for a ticket that no longer exists.
                    AgentWallet::purgeBookingRows($bid, 'booking hard-deleted');
                    Database::run("DELETE FROM bookings WHERE id = :id", ['id' => $bid]);
                });
                Logger::audit('booking.hard_delete', 'booking', $pnr, ['status' => (string) $b['status'], 'amount' => (float) $b['total_amount'], 'phone' => (string) $b['contact_phone']], null, 'superadmin permanent delete');
                // Redirect so a reload cannot re-delete
                Response::redirect('admin/bookings.php');
            }
        } catch (Throwable $e) { $flash = ['bad', $e->getMessage()]; }
    }
}

admin_header('Booking ' . $pnr, 'bookings');

if ($b === null) {
    echo '<div class="flash bad">No booking found with reference ' . Security::e($pnr) . '.</div>';
    echo '<p><a class="btn ghost" href="' . $base . '/admin/bookings.php">← Back to bookings</a></p>';
    admin_footer();
    exit;
}

/**
 * Share + Send-ticket buttons (15 Oct 2026, 1F-resend). Share hands the
 * keyed ticket picture link to the phone's share sheet (clipboard copy on a
 * desktop). Send: the resend form for payments.verify holders, else the
 * WhatsApp composer button (admin/api/wa-send.php scopes an agent to their
 * own sales, so the button never appears for a ticket the API would refuse).
 */
function bv_share_tools(array $b, string $pnr, bool $withSend): string
{
    if ((string) ($b['status'] ?? '') !== 'confirmed') {
        return '';
    }
    $url = Ticket::imageUrl($pnr);
    $h = '<button type="button" class="btn ghost" data-share-url="' . Security::e($url) . '" data-share-title="' . Security::e('Ticket ' . $pnr) . '">🔗 Share ticket</button>';
    if (!$withSend || Notify::usablePhone($b['contact_phone'] ?? '') === '') {
        return $h;
    }
    if (Auth::can('payments.verify')) {
        $h .= '<form method="post" style="display:inline-flex;margin:0" onsubmit="return confirm(\'Send the corrected ticket to ' . Security::e((string) $b['contact_phone']) . ' on WhatsApp?\')">'
            . Security::csrfField() . '<button class="btn ok" type="submit" name="action" value="resend_wa">📲 Send ticket on WhatsApp</button></form>';
    } else {
        $h .= admin_wa_button('booking_ticket', ['pnr' => $pnr], '📲 Send ticket on WhatsApp', ['class' => 'btn ok']);
    }
    return $h;
}

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
    // After a successful correction: share / send the corrected ticket right here.
    if ($bvEdited && $flash[0] === 'ok' && ($share = bv_share_tools($b, $pnr, true)) !== '') {
        echo '<div class="qe-after">' . $share . '<span class="muted">The ticket was regenerated — share or send the corrected copy.</span></div>';
    }
}
if (!empty($waManualLink)) {
    echo '<div class="flash ok" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">'
       . '<span>Send it yourself in one tap &mdash; WhatsApp opens with the message already written.</span>'
       . '<a class="btn ok" target="_blank" rel="noopener" href="' . Security::e($waManualLink) . '">'
       . '&#128172; Open WhatsApp</a></div>';
}

$leg = $b['legs'][0] ?? [];
$pay = $b['payment'] ?? [];
$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

/* Message-delivery history — every SMS / WhatsApp / email attempt this
   booking generated, newest first. Notify::logMessage writes one row per
   attempt (see includes/notify.php), so the operator sees not just "did
   the last resend succeed" but the full trail: booking-confirmed WA →
   COD-settled SMS → operator-manual resend → each provider ref, each
   error, each status. The pill next to the resend button reads the FIRST
   WhatsApp row here (the most recent attempt). */
$msgLogs = Database::fetchAll(
    'SELECT channel, provider, to_number, status, provider_ref, error, created_at
       FROM message_logs
      WHERE booking_id = :b
      ORDER BY id DESC
      LIMIT 20',
    ['b' => (int) $b['id']]
);
$waLatest = null;
foreach ($msgLogs as $row) {
    if ($row['channel'] === 'whatsapp') { $waLatest = $row; break; }
}
$waPillLabel = null;
if ($waLatest !== null) {
    switch ($waLatest['status']) {
        case 'sent':    $waPillLabel = ['ok',   '✅ WA sent',    $waLatest['created_at'], (string) ($waLatest['error'] ?? '')]; break;
        case 'failed':  $waPillLabel = ['bad',  '❌ WA failed',  $waLatest['created_at'], (string) ($waLatest['error'] ?? '')]; break;
        case 'skipped': $waPillLabel = ['muted','⚠️ WA skipped', $waLatest['created_at'], (string) ($waLatest['error'] ?? '')]; break;
        case 'queued':  $waPillLabel = ['warn', '⏳ WA queued',  $waLatest['created_at'], '']; break;
    }
}

/* ---- Agent code lookup ------------------------------------------- */
$agentCodes = Settings::getArray('agent_codes', []);
$soldById   = (int) ($b['sold_by_admin_id'] ?? 0);
$agentCode  = '';
if ($soldById > 0 && isset($agentCodes[$soldById])) {
    $agentCode = 'SHG-' . str_pad((string) ($agentCodes[$soldById] ?? ''), 4, '0', STR_PAD_LEFT);
}

/* ---- Reassign-agent picker (superadmin power action) --------------
   The full active-agent roster, sorted by their SHG serial so the office
   picks a seller the same way they read the staff list. */
$currentSeller = !empty($b['agent'])
    ? trim(($agentCode !== '' ? $agentCode . ' · ' : '') . (string) ($b['agent']['full_name'] ?: $b['agent']['username']))
    : 'Office / Online (no agent)';
$reassignAgents = [];
if (Auth::mayReassignSeller()) {   // 15 Oct 2026: was super-admin only
    foreach (Database::fetchAll(
        "SELECT id, username, full_name FROM admins WHERE role = 'agent' AND is_active = 1"
    ) as $ra) {
        $ra['code']    = AgentWallet::agentCodeLabel((int) $ra['id']);
        $ra['codeNum'] = AgentWallet::agentCodeFor((int) $ra['id']) ?? 99999;
        $reassignAgents[] = $ra;
    }
    usort($reassignAgents, static fn(array $x, array $y): int => $x['codeNum'] <=> $y['codeNum']);
}

/* ---- Commission earned on THIS booking (referral ledger) --------- */
$commissionRow = null;
try {
    $commissionRow = Database::fetch(
        'SELECT amount, rule_mode, rule_value, status FROM commissions WHERE booking_id = :b LIMIT 1',
        ['b' => (int) ($b['id'] ?? 0)]
    );
} catch (Throwable $e) { $commissionRow = null; }

/* ---- Counter-agent commission (agent_ledger) ---------------------
   The `commissions` table above only covers customer-app referral codes;
   a counter / agent sale's money lives in agent_ledger (AgentWallet::accrue).
   Show it in the same place so the selling agent sees "Commission ₹200" on
   their own PNR instead of hunting through the wallet history (3 Sep 2026). */
$ledgerCommission = null;
if (!empty($b['sold_by_admin_id'])) {
    try {
        $ledgerCommission = Database::fetch(
            "SELECT amount, note, created_at,
                    (SELECT COALESCE(SUM(v.amount), 0) FROM agent_ledger v
                      WHERE v.booking_id = agent_ledger.booking_id AND v.entry_type = 'commission_void') AS voided
               FROM agent_ledger
              WHERE booking_id = :b AND entry_type = 'commission'
              ORDER BY id DESC LIMIT 1",
            ['b' => (int) ($b['id'] ?? 0)]
        );
    } catch (Throwable $e) { $ledgerCommission = null; }
}

/* ---- Payment proof (screenshot) attached to this booking --------- */
$hasProof = false;
if (Auth::can('payments.view')) {
    try {
        $hasProof = (int) Database::scalar(
            'SELECT COUNT(*) FROM payment_screenshots WHERE booking_id = :b',
            ['b' => (int) ($b['id'] ?? 0)], 0) > 0;
    } catch (Throwable $e) { $hasProof = false; }
}

/* ---- Same UTR / same screenshot on another booking (8 Oct 2026, D8-06) ----
   One real UPI transfer could be typed or uploaded against several bookings
   and a busy desk verifying by eye approved each one. Display only: a red
   pill under the UTR / proof naming the other PNR(s). It never blocks,
   rejects or decides anything - the desk looks and decides. Same audience
   as the proof link (payments.view). A UTR shorter than 6 letters/digits
   ("cash", "0") is not compared. Uses ix_payments_utr / ix_shots_hash. */
$utrAlsoOn  = [];
$shotAlsoOn = [];
if (Auth::can('payments.view') && !empty($b['id'])) {
    $utrHere = trim((string) ($pay['utr_number'] ?? ''));
    if ($utrHere !== '' && preg_match('/[A-Za-z0-9]{6,}/', $utrHere)) {
        try {
            $utrList = (string) Database::scalar(
                "SELECT GROUP_CONCAT(DISTINCT b2.pnr ORDER BY b2.id SEPARATOR ',')
                   FROM payments p2 JOIN bookings b2 ON b2.id = p2.booking_id
                  WHERE p2.utr_number = :u AND p2.booking_id <> :b",
                ['u' => $utrHere, 'b' => (int) $b['id']], ''
            );
            $utrAlsoOn = array_values(array_filter(explode(',', $utrList)));
        } catch (Throwable $e) { $utrAlsoOn = []; }
    }
    if ($hasProof) {
        try {
            $shotList = (string) Database::scalar(
                "SELECT GROUP_CONCAT(DISTINCT bx.pnr ORDER BY bx.id SEPARATOR ',')
                   FROM payment_screenshots s1
                   JOIN payment_screenshots s2 ON s2.sha256 = s1.sha256 AND s2.booking_id <> s1.booking_id
                   JOIN bookings bx ON bx.id = s2.booking_id
                  WHERE s1.booking_id = :b AND s1.sha256 IS NOT NULL AND s1.sha256 <> ''",
                ['b' => (int) $b['id']], ''
            );
            $shotAlsoOn = array_values(array_filter(explode(',', $shotList)));
        } catch (Throwable $e) { $shotAlsoOn = []; }
    }
}
/** A red "also on" pill: up to 4 PNR links, then "+N more". */
$alsoOnPill = static function (string $what, array $pnrs) use ($base): string {
    if ($pnrs === []) { return ''; }
    $links = [];
    foreach (array_slice($pnrs, 0, 4) as $p) {
        $links[] = '<a href="' . $base . '/admin/booking-view.php?pnr=' . rawurlencode($p) . '" style="color:inherit;text-decoration:underline">' . Security::e($p) . '</a>';
    }
    $more = count($pnrs) > 4 ? ' +' . (count($pnrs) - 4) . ' more' : '';
    return '<div class="also-on" style="margin-top:6px;font-family:var(--f-ui)"><span class="pill nodot st-bad" role="note" style="display:inline-block;white-space:normal;line-height:1.45;padding:4px 10px">&#9888; '
         . Security::e($what) . ' also on ' . implode(', ', $links) . $more . '</span></div>';
};

/* ---- Passenger photos / ID documents (17 Sep 2026) ----------------
   One grouped read for the whole page; [] until the migration has run or
   while the office switch is off. */
$paxDocsOn    = Settings::getBool('passenger_docs_on', true);
$paxDocsAvail = $paxDocsOn && PassengerDocs::available();
$paxDocs      = $paxDocsAvail ? PassengerDocs::listFor((int) ($b['id'] ?? 0)) : [];
$paxDocsTotal = 0;
foreach ($paxDocs as $pdList) { $paxDocsTotal += count($pdList); }

/* ---- Other tickets on this contact number (17 Sep 2026) -----------
   A family books three PNRs from one phone (assertNoDuplicate only refuses
   the SAME seats within 10 minutes, and never for staff) and until now
   nothing on the ticket page said so — the desk found the siblings by
   searching the number by hand. Scoped exactly like the page: a counter
   agent sees only the siblings they sold. The walk-in placeholder
   0000000000 is skipped, or it would list every walk-in in the company. */
$siblings = [];
if (Notify::usablePhone($b['contact_phone'] ?? '') !== '') {
    try {
        $sibSql = "SELECT b.id, b.pnr, b.status, b.total_amount, b.booking_mode, l.travel_date,
                          r.from_city, r.to_city, r.coach_type,
                          (SELECT GROUP_CONCAT(bs.seat_no ORDER BY LENGTH(bs.seat_no), bs.seat_no SEPARATOR ',')
                             FROM booking_seats bs WHERE bs.booking_id = b.id AND bs.released_at IS NULL) AS seats,
                          (SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id) AS pax
                     FROM bookings b
                     LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
                     LEFT JOIN schedules s ON s.id = l.schedule_id
                     LEFT JOIN routes r ON r.id = s.route_id
                    WHERE b.contact_phone = :p AND b.id <> :id";
        $sibParams = ['p' => (string) $b['contact_phone'], 'id' => (int) $b['id']];
        if ($scopeId !== null) {
            $sibSql .= ' AND b.sold_by_admin_id = :scope';
            $sibParams['scope'] = $scopeId;
        }
        $siblings = Database::fetchAll($sibSql . ' ORDER BY l.travel_date DESC, b.id DESC LIMIT 20', $sibParams);
    } catch (Throwable $e) { $siblings = []; }
}
?>
<style>
.source-card{border-left:4px solid var(--blue);background:var(--card);border:1px solid var(--line);border-left:4px solid var(--blue);border-radius:14px;padding:18px 22px;margin-bottom:22px}
.source-card h3{margin:0 0 12px;font-size:15px}
.source-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 24px}
.source-grid dt{font-size:12px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;margin:0}
.source-grid dd{margin:2px 0 10px;font-size:14px;font-weight:600}
.source-badge{display:inline-block;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700;vertical-align:middle}
.source-badge.online{background:#e2ecfb;color:#1c3b72}
.source-badge.agent{background:#fff4d1;color:#8a6d00}
.source-badge.counter{background:#ffe6c7;color:#7a4a00}
.source-badge.admin-src{background:#f0e6ff;color:#5a3fb0}
:root[data-theme="dark"] .source-badge.online{background:#1c3b72;color:#b8d4fb}
:root[data-theme="dark"] .source-badge.agent{background:#4a3d00;color:#ffe28a}
:root[data-theme="dark"] .source-badge.counter{background:#4a3200;color:#ffcc8a}
:root[data-theme="dark"] .source-badge.admin-src{background:#2e1f5e;color:#d4bfff}
.audit-timeline{border-left:3px solid var(--line);margin-left:16px;padding-left:20px}
.audit-entry{position:relative;margin-bottom:16px;padding:10px 14px;background:var(--head);border-radius:8px}
.audit-entry::before{content:'';position:absolute;left:-27px;top:14px;width:10px;height:10px;border-radius:50%;background:var(--blue);border:2px solid var(--card)}
.audit-time{font-size:12px;color:var(--mut);font-family:ui-monospace,Menlo,Consolas,monospace}
.audit-actor{font-weight:700;font-size:13px;margin-top:2px}
.audit-action{display:inline-block;font-size:12px;padding:2px 8px;border-radius:4px;background:var(--line);margin-top:4px}
.audit-detail{font-size:13px;margin-top:4px;color:var(--mut)}
.board-yes{color:var(--success);font-weight:600}
.board-no{color:var(--mut)}

/* WhatsApp delivery pill next to the Resend button — status at a glance
   without the operator opening the message log. Colour follows the same
   ok/warn/bad palette the flash messages use. */
.wa-pill{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:600;line-height:1;white-space:nowrap;border:1px solid transparent}
.wa-pill small{font-size:11px;opacity:.75;font-weight:500}
.wa-pill.wa-ok{background:#e7f6ec;color:#0a6b3b;border-color:#b8dfc4}
.wa-pill.wa-bad{background:#fdecec;color:#8a1f1f;border-color:#f4c2c2}
.wa-pill.wa-warn{background:#fff4d1;color:#8a6d00;border-color:#e6d18a}
.wa-pill.wa-muted{background:#eef1f6;color:#556;border-color:#dde2ea}
:root[data-theme="dark"] .wa-pill.wa-ok{background:#0e3d24;color:#a5d9b9;border-color:#1e6a3d}
:root[data-theme="dark"] .wa-pill.wa-bad{background:#3d1414;color:#f0b6b6;border-color:#7a2626}
:root[data-theme="dark"] .wa-pill.wa-warn{background:#3d3200;color:#e6d18a;border-color:#66551a}
:root[data-theme="dark"] .wa-pill.wa-muted{background:#232833;color:#aab;border-color:#333a48}

/* Compact message history — read-only trail of every SMS/WA/email
   attempt for this booking, newest first. */
.msg-log{margin-top:12px;font-size:13px;border:1px solid var(--line);border-radius:10px;overflow:hidden}
.msg-log summary{padding:10px 14px;cursor:pointer;font-weight:600;background:var(--head);list-style:none}
.msg-log summary::-webkit-details-marker{display:none}
.msg-log summary::before{content:'▸ ';color:var(--mut);font-size:11px}
.msg-log[open] summary::before{content:'▾ '}
.msg-log table{width:100%;border-collapse:collapse}
.msg-log th,.msg-log td{padding:8px 12px;text-align:left;border-top:1px solid var(--line);font-size:12px}
.msg-log th{background:var(--head);color:var(--mut);font-weight:600;text-transform:uppercase;letter-spacing:.03em;font-size:11px}
.msg-log tr:hover td{background:var(--head)}
.msg-log .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600}
.msg-log .badge.sent{background:#e7f6ec;color:#0a6b3b}
.msg-log .badge.failed{background:#fdecec;color:#8a1f1f}
.msg-log .badge.skipped{background:#fff4d1;color:#8a6d00}
.msg-log .badge.queued{background:#e2ecfb;color:#1c3b72}

/* ── Superadmin power panel ── */
.power-panel{border:2px solid #C00;border-radius:14px;padding:18px 20px;margin-bottom:22px;background:#FFF8F8}
.power-panel h3{color:#8a1f1f;font-size:14px;margin:0 0 12px;display:flex;align-items:center;gap:6px}
.power-actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.power-panel details{margin-top:10px}
.power-panel summary{font-size:13px;font-weight:700;cursor:pointer;color:#8a1f1f;padding:6px 0}
.power-panel .sub-form{margin-top:10px;display:grid;gap:8px}
.power-panel label{font-size:12px;font-weight:700;color:var(--muted);margin-bottom:2px}
.power-panel input,.power-panel textarea{width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:7px;font-size:14px}
.danger-zone{border:1.5px dashed #C00;border-radius:10px;padding:12px 14px;margin-top:10px}
.danger-zone p{font-size:12px;color:#8a1f1f;margin:0 0 8px}
:root[data-theme="dark"] .power-panel{background:#2a0e0e;border-color:#a00}
:root[data-theme="dark"] .power-panel h3,:root[data-theme="dark"] .power-panel summary,:root[data-theme="dark"] .danger-zone p{color:#f88}

/* ── Edit booking panel ── */
.edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.edit-section h3{font-size:13px;margin:0 0 8px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}
.edit-row{margin-bottom:10px}
.edit-row label{display:block;font-size:12px;font-weight:700;color:var(--mut);margin-bottom:3px}
.edit-row input{width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:var(--card);color:var(--ink)}
.pax-edit-row{display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
.pax-edit-row input,.pax-edit-row select{padding:7px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:var(--card);color:var(--ink)}

/* ── Passenger photos / ID documents (17 Sep 2026) ── */
.pdoc-pax{display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap;padding:10px 0;border-top:1px dashed var(--line)}
.pdoc-pax:first-of-type{border-top:0}
.pdoc-who{flex:0 0 170px;font-weight:700;font-size:13px;line-height:1.3}
.pdoc-who .mono{margin-right:6px;color:var(--blue)}
.pdoc-who small{display:block;color:var(--mut);font-weight:500;font-size:11.5px}
.pdoc-strip{display:flex;gap:8px;flex-wrap:wrap;flex:1 1 240px;min-height:32px}
.pdoc{width:104px;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--soft);text-align:center;font-size:11px}
.pdoc .pdoc-th{display:flex;align-items:center;justify-content:center;height:74px;background:var(--card);color:var(--mut)}
.pdoc img{width:100%;height:74px;object-fit:cover;display:block}
.pdoc .pill{font-size:10px;padding:1px 7px;margin:5px 0 2px}
.pdoc-acts{display:flex;justify-content:center;gap:6px;align-items:center;padding:0 4px 4px}
.pdoc-acts a{color:var(--mut);display:inline-flex;padding:3px}
.pdoc-acts form{margin:0;display:inline-flex}
.pdoc-x{background:none;border:0;color:var(--bad);cursor:pointer;padding:3px;display:inline-flex;line-height:1}
.pdoc-up{display:flex;gap:6px;flex-wrap:wrap;align-items:center;flex:1 1 300px}
.pdoc-up select,.pdoc-up input[type=file]{padding:6px 8px;border:1px solid var(--line);border-radius:8px;font-size:12.5px;background:var(--card);color:var(--ink);max-width:100%;min-width:0}
.pdoc-up input[type=file]{flex:1 1 160px}
@media(max-width:820px){.pdoc-who{flex:1 1 100%}}

/* ── Mobile responsive ── */
@media(max-width:820px){
  div[style*="grid-template-columns:1fr 1fr"]{grid-template-columns:1fr !important}
  .edit-grid{grid-template-columns:1fr}
  .source-grid{grid-template-columns:1fr 1fr}
  .power-actions{flex-direction:column}
  .power-actions form,.power-actions a{width:100%}
  .power-actions .btn{width:100%;text-align:center}
  .toolbar{flex-direction:column;gap:8px}
  .toolbar form{width:100%}
  .toolbar .btn,.toolbar button{width:100%;text-align:center}
  .row-actions{flex-direction:column;gap:8px}
  .row-actions form{width:100%;display:flex;flex-direction:column;gap:6px}
  .row-actions input[type="text"]{width:100%}
  .row-actions .btn,.row-actions button{width:100%;text-align:center}
  .pax-edit-row{flex-direction:column;align-items:stretch}
  .pax-edit-row input,.pax-edit-row select{width:100%}
}
@media(pointer:coarse){
  .btn,.toolbar .btn,.power-actions .btn,button[type="submit"]{min-height:44px}
}

/* ── Quick Edit (15 Oct 2026) — one card on top, every box prefilled ── */
.qe-bar{position:sticky;top:var(--tb-h,56px);z-index:20;display:flex;gap:6px;overflow-x:auto;-webkit-overflow-scrolling:touch;
  margin:0 0 14px;padding:8px;background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:0 4px 14px rgba(20,40,80,.08);scrollbar-width:thin}
.qe-bar a,.qe-bar button{flex:0 0 auto;display:inline-flex;align-items:center;gap:4px;padding:8px 12px;border-radius:999px;border:1px solid var(--line);
  background:var(--head);color:var(--ink);font:700 13px/1.1 var(--f-ui,inherit);text-decoration:none;cursor:pointer;white-space:nowrap;min-height:38px}
.qe-bar a:hover,.qe-bar button:hover{filter:brightness(.96)}
.qe-bar .bad{color:var(--bad,#8a1f1f)}
.qe{padding:0}
.qe>summary{list-style:none;cursor:pointer;padding:14px 18px;display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 10px}
.qe>summary::-webkit-details-marker{display:none}
.qe>summary h2{margin:0;font-size:17px}
.qe>summary .muted{font-size:12.5px}
.qe-body{padding:0 18px 16px;display:flex;flex-direction:column;gap:14px}
.qe-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.qe-chips b{font-size:12px;color:var(--mut);margin-right:2px}
.qe-chip{display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;border:1px solid var(--line);background:var(--card);
  color:var(--ink);font-size:12.5px;font-weight:700;text-decoration:none;min-height:36px}
.qe-chip.req{border-style:dashed}
.qe-chip.off{opacity:.45;cursor:not-allowed}
.qe-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:12px}
.qe-block{border:1px solid var(--line);border-radius:12px;padding:12px 14px;background:var(--head);min-width:0}
.qe-block h3{margin:0 0 8px;font-size:12.5px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.qe-row{display:flex;flex-direction:column;gap:3px;margin-bottom:8px;min-width:0}
.qe-row label{font-size:12px;font-weight:700;color:var(--mut)}
.qe input[type=text],.qe input[type=tel],.qe input[type=email],.qe input[type=number],.qe select{width:100%;min-width:0;max-width:100%;padding:8px 10px;
  border:1px solid var(--line);border-radius:8px;font-size:14px;background:var(--card);color:var(--ink);box-sizing:border-box}
.qe-pax{display:grid;grid-template-columns:52px minmax(0,2fr) 70px minmax(0,1fr) minmax(0,1fr) minmax(0,1fr);gap:6px;align-items:center;margin-bottom:6px}
.qe-pax .mono{font-weight:800}
.qe-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.qe-actions input[type=text]{flex:1 1 220px}
.qe-hint{font-size:12px;color:var(--mut);margin:4px 0 0}
.qe-req-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.qe-req-list li{border:1px solid var(--line);border-radius:10px;padding:8px 10px;background:var(--card);font-size:13px}
.qe-req-list form{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
.qe-after{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:-6px 0 14px}
.qe-after .muted{font-size:12.5px}
@media(max-width:640px){
  .qe-pax{grid-template-columns:44px minmax(0,1fr) 64px;}
  .qe-pax .qe-wide{grid-column:2 / -1}
  .qe-bar{top:var(--tb-h,52px)}
}
</style>

<?php if ($isSuperHere): ?>
<div class="power-panel">
  <h3>⚡ Superadmin Controls — <?= Security::e($pnr) ?></h3>
  <div class="power-actions">
    <!-- Force Cancel -->
    <form method="post" style="display:inline">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="force_cancel">
      <input type="hidden" name="reason" value="Force-cancelled by admin">
      <button type="submit" class="btn btn-warn btn-sm"
              onclick="return confirm('Force-cancel <?= Security::e($pnr) ?>? Seats released, NO automatic refund.')">
        🚫 Force Cancel
      </button>
    </form>
    <!-- Reset to Pending -->
    <?php if (in_array($b['status'] ?? '', ['confirmed','rejected','cancelled'], true)): ?>
    <form method="post" style="display:inline">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="reset_pending">
      <button type="submit" class="btn btn-ghost btn-sm"
              onclick="return confirm('Reset this booking to PENDING status?')">
        🔄 Reset → Pending
      </button>
    </form>
    <?php endif; ?>
    <!-- Download ticket link -->
    <?php /* Ticket::imageUrl() carries the HMAC key. Hand-building this URL
         worked only because a signed-in staff session satisfies tier 3 of
         download-ticket.php's gate — paste it into WhatsApp and it dies. */ ?>
    <a href="<?= Security::e(Ticket::imageUrl($pnr)) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">🎟️ Ticket (Image)</a>
    <a href="<?= $base ?>/download-ticket.php?pnr=<?= urlencode($pnr) ?>" target="_blank" class="btn btn-ghost btn-sm">📄 PDF</a>
    <a href="<?= $base ?>/download-ticket.php?pnr=<?= urlencode($pnr) ?>&amp;dup=1&amp;print=1" target="_blank" class="btn btn-ghost btn-sm" title="Reprint stamped DUPLICATE COPY">🖨️ Duplicate</a>
    <!-- WhatsApp passenger. Gated on usablePhone(), not on "not empty": the
         walk-in placeholder 0000000000 is not empty, so this button used to
         render as a live wa.me/910000000000 link and messaged a stranger in
         India about someone else's booking. -->
    <?php if (Notify::usablePhone($b['contact_phone'] ?? '') !== ''):
      /* 17 Sep 2026: one-click sends through admin/api/wa-send.php — previewed,
         logged, template-aware. The old hand-built wa.me link stays as each
         button's 'fallback' (<noscript>) and is shown exactly as before when
         wa_admin_tools_enabled is off or the role may not send, so nothing is
         lost. The ticket only for a confirmed booking and the payment reminder
         only while it is still pending: the composer refuses both otherwise. */
      $bvWaLink = 'https://wa.me/91' . preg_replace('/\D/', '', (string) $b['contact_phone']);
      $bvStatus = (string) ($b['status'] ?? '');
      $bvPnr    = (string) ($b['pnr'] ?? $pnr);
      $bvWaOpts = ['class' => 'btn btn-ghost btn-sm', 'fallback' => $bvWaLink];
      $bvWa = ($bvStatus === 'confirmed' ? admin_wa_button('booking_ticket', ['pnr' => $bvPnr], 'Ticket on WhatsApp', $bvWaOpts) : '')
            . admin_wa_button('booking_passengers', ['pnr' => $bvPnr], 'Details on WhatsApp', $bvWaOpts)
            . ($bvStatus === 'pending' ? admin_wa_button('customer_payment_reminder', ['pnr' => $bvPnr], 'Payment reminder', $bvWaOpts) : '');
    ?>
    <?php if ($bvWa !== ''): ?>
    <?= $bvWa ?>
    <?php else: ?>
    <a href="<?= Security::e($bvWaLink) ?>"
       target="_blank" class="btn btn-ghost btn-sm">💬 WhatsApp Passenger</a>
    <?php endif; ?>
    <?php else: ?>
    <span class="btn btn-ghost btn-sm" style="opacity:.5;cursor:default" title="This walk-in has no phone on file">💬 No phone on file</span>
    <?php endif; ?>
  </div>

  <?php /* 16 Oct 2026: what a Cash (pay at boarding) customer gave for verification
     on SmartBook - address, ID, second mobile. Call before the bus if unsure. */
  $cvAddr = trim((string) ($b['contact_address'] ?? ''));
  if (!empty($b['is_cod']) && ($cvAddr !== '' || trim((string) ($b['id_number'] ?? '')) !== '')): ?>
  <div class="card" style="margin:10px 0;padding:12px 14px;border-left:4px solid #e8771f">
    <b>🛡️ Cash verification · नगद प्रमाणीकरण</b>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:6px 14px;margin-top:6px;font-size:14px">
      <?php if ($cvAddr !== ''): ?><div><small class="muted">Address · ठेगाना</small><br><?= Security::e($cvAddr) ?></div><?php endif; ?>
      <?php if (trim((string) ($b['id_number'] ?? '')) !== ''): ?><div><small class="muted">ID · परिचयपत्र</small><br><?= Security::e(trim((string) ($b['id_type'] ?? '') . ' ' . (string) $b['id_number'])) ?></div><?php endif; ?>
      <?php if (trim((string) ($b['alt_phone'] ?? '')) !== ''): ?><div><small class="muted">Second mobile · दोस्रो मोबाइल</small><br><a href="tel:+<?= Security::e(preg_replace('/\D/', '', (string) ($b['alt_country_code'] ?? '')) . preg_replace('/\D/', '', (string) $b['alt_phone'])) ?>"><?= Security::e((string) $b['alt_phone']) ?></a></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Edit Contact + Passengers -->
  <details>
    <summary>✏️ Edit Contact / Passenger Names</summary>
    <form method="post" class="sub-form">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="edit_contact">
      <div>
        <label>Reason (audit)</label>
        <input type="text" name="reason" placeholder="Why this change" maxlength="255">
      </div>
      <div>
        <label>Contact Phone</label>
        <input type="tel" name="contact_phone" placeholder="<?= Security::e((string)$b['contact_phone']) ?>" maxlength="15">
      </div>
      <div>
        <label>Contact Email</label>
        <input type="email" name="contact_email" placeholder="<?= Security::e((string)($b['contact_email'] ?? '')) ?>" maxlength="120">
      </div>
      <?php foreach (($b['passengers'] ?? []) as $pax): ?>
      <div>
        <label>Passenger #<?= (int)($pax['berth_no'] ?? 1) ?> Name</label>
        <input type="text" name="pax_name[<?= (int)$pax['id'] ?>]"
               placeholder="<?= Security::e((string)$pax['full_name']) ?>" maxlength="120">
      </div>
      <?php endforeach; ?>
      <div>
        <label>Add an internal note <span style="font-weight:500">(added under the earlier notes with your name — never replaces them)</span></label>
        <textarea name="admin_note" rows="2" maxlength="180" placeholder="Internal note — not shown to customer"></textarea>
      </div>
      <button type="submit" class="btn btn-ok btn-sm" style="width:auto">💾 Save Changes</button>
    </form>
  </details>
  <?php /* 15 Oct 2026: "Assign / change selling agent" moved to the Quick Edit
       card (#reassign) — a manager holds bookings.reassign now too. */ ?>

  <!-- Hard Delete -->
  <details>
    <summary>🗑️ Permanently Delete This Booking</summary>
    <div class="danger-zone">
      <p>⚠️ This cannot be undone. The booking, passengers, seats and payment records are removed from the database permanently.</p>
      <form method="post" class="sub-form">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="hard_delete">
        <div>
          <label>Type the PNR to confirm: <b><?= Security::e($pnr) ?></b></label>
          <input type="text" name="confirm_delete" placeholder="<?= Security::e($pnr) ?>" required autocomplete="off" maxlength="40">
        </div>
        <?php /* 8 Oct 2026 (D9-02): the red danger face (the inline #C00 lost to the royal button face) */ ?>
        <button type="submit" class="btn danger btn-sm" style="width:auto"
                onclick="return confirm('PERMANENTLY DELETE <?= Security::e($pnr) ?>? This cannot be undone.')">
          💀 Delete Forever
        </button>
      </form>
    </div>
  </details>
</div>
<?php endif; ?>

<p style="margin-top:-8px">
  <a class="muted" href="<?= $base ?>/admin/bookings.php">← All bookings</a>
  &nbsp;·&nbsp; <?= admin_pill((string) $b['status']) ?>
</p>

<?php
/* ========================================================================
 *  QUICK EDIT (15 Oct 2026, owner: "ticket ma galti bhayo bhane ek thau
 *  bata chhito milaune — Search → Quick Edit → Change → Save")
 *
 *  One sticky action bar and one compact card at the top of the ticket.
 *  Every box is PREFILLED with the current value and posts to the SAME
 *  handlers as before (edit_passenger, change_seat, edit_payment,
 *  reassign_agent) — an untouched box compares equal there, so a save that
 *  changes nothing changes nothing. Which boxes a role sees is
 *  Auth::mayEditField() (bookings.edit / payments.verify for the office,
 *  settings ticket_edit_rules for agents and counters). A field a role may
 *  not change becomes a "Request correction" (fare / payment / agent /
 *  cancel) or an Office-chat preset. Works at 360 px: blocks stack, the
 *  action bar scrolls sideways inside itself.
 * ======================================================================== */
$qeLeg = $leg;
foreach (($b['legs'] ?? []) as $qeL) {
    if ((string) ($qeL['leg_type'] ?? '') === 'outbound') { $qeLeg = $qeL; break; }
}
$qeSid    = (int) ($qeLeg['schedule_id'] ?? 0);
$qeRoute  = (int) ($qeLeg['schedule_route_id'] ?? 0);
$qeMode   = (string) (($b['booking_mode'] ?? '') ?: 'sharing');
$qeCoach  = $qeSid > 0 ? Seats::coachForSchedule($qeSid) : 'sleeper';
$qeLive   = in_array((string) $b['status'], ['pending', 'confirmed'], true);
$qeGate   = $qeSid > 0 ? TripStatus::editableFor($qeSid) : ['editable' => true, 'label' => ''];
$qeAgentLocked = Auth::isCounterAgent() && !($qeGate['editable'] ?? true);
$qeMay = [];
foreach (['name', 'phone', 'email', 'pickup', 'gender', 'age', 'id', 'seat', 'payment', 'remark', 'fare', 'agent'] as $qeF) {
    $qeMay[$qeF] = $qeLive && !$qeAgentLocked && Auth::mayEditField($b, $qeF);
}
$qeMay['seat']    = $qeMay['seat'] && (($qeGate['editable'] ?? true) || Auth::isSuperadmin());
$qeMay['payment'] = $qeMay['payment'] && !empty($pay);
$qeCanResched = $qeLive && (Auth::can('bookings.edit') || Auth::can('schedules.edit') || Auth::isSuperadmin());
$qeFieldRole  = in_array(StaffMsg::role(), ['agent', 'counter'], true);
$qeChat       = static fn(string $q): string => $base . '/admin/staff-chat.php?pnr=' . rawurlencode($pnr) . '&q=' . $q;
$qeLbl        = static fn(string $s): string => Seats::displayLabel($s, $qeCoach, $qeMode);

// What may only be ASKED for (the office applies it — owner rule for money).
$qeReqFields = [];
if (TicketEdit::available() && $qeLive) {
    if (!$qeMay['fare'])                                  { $qeReqFields['fare'] = TicketEdit::REQUEST_FIELDS['fare']; }
    if (!$qeMay['payment'] && !empty($pay))               { $qeReqFields['payment_method'] = TicketEdit::REQUEST_FIELDS['payment_method']; }
    if (!$qeMay['agent'])                                 { $qeReqFields['agent'] = TicketEdit::REQUEST_FIELDS['agent']; }
    if (!Auth::mayCancelBooking($b) || bv_cancel_is_request()) { $qeReqFields['cancel_request'] = TicketEdit::REQUEST_FIELDS['cancel_request']; }
}
$qeApprover = Auth::isSuperadmin() || Auth::canManageSettings();
$qeRequests = TicketEdit::requests((int) $b['id'], false, 10);
$qeShowVal  = static function (string $field, ?string $v): string {
    $v = (string) $v;
    return match ($field) {
        'fare'           => $v !== '' ? inr((float) $v) : '—',
        'payment_method' => $v !== '' ? strtoupper($v) : '—',
        'agent'          => TicketEdit::sellerLabel((int) $v),
        default          => $v !== '' ? $v : '—',
    };
};

// Route stops for the pickup / drop pickers ("Other…" stays allowed), in the
// canonical "Name @ HH:MM" form a sale stores, so the ticket keeps the time.
$qeStopLabel = static fn(array $s): string => trim((string) $s['name']) . (!empty($s['time']) ? ' @ ' . substr((string) $s['time'], 0, 5) : '');
$qeBoardOpts = $qeRoute > 0 ? array_values(array_map($qeStopLabel, Boarding::stopsFor($qeRoute))) : [];
$qeDropOpts  = $qeRoute > 0 ? array_values(array_map($qeStopLabel,
    array_filter(Boarding::allStopsFor($qeRoute, 'drop'), static fn(array $s): bool => (bool) $s['active']))) : [];
$qeStopPick = static function (string $name, string $otherName, string $id, string $label, array $opts, string $cur): string {
    $h = '<select name="' . $name . '" id="' . $id . '" class="qe-stop" data-old="' . Security::e($cur) . '" data-label="' . Security::e($label) . '">';
    if ($cur === '') {
        $h .= '<option value="" selected>— not set —</option>';
    } elseif (!in_array($cur, $opts, true)) {
        $h .= '<option value="' . Security::e($cur) . '" selected>' . Security::e($cur) . ' (on ticket)</option>';
    }
    foreach ($opts as $o) {
        $h .= '<option value="' . Security::e($o) . '"' . ($o === $cur ? ' selected' : '') . '>' . Security::e($o) . '</option>';
    }
    return $h . '<option value="__other__">Other… (type it)</option></select>'
        . '<input type="text" name="' . $otherName . '" class="qe-other" maxlength="191" placeholder="Type the stop" hidden disabled aria-label="' . Security::e($label) . ' (other)">';
};

/* Change seat: THIS departure's own coach and free seats (an extra bus has
   its own), and only seats the passenger's gender may take — the same hard
   rules AutoBerth applies at the sale (women-only berth, cabin held for the
   other gender). Per passenger: an option lists the "from" seats it fits. */
$qeSeatOk = []; $qeSeatList = []; $qeSeatErr = ''; $qeAutoPick = null; $qeAutoFrom = '';
$qePaxBySeat = [];
if ($qeMay['seat'] && ($qeSid <= 0 || empty($qeLeg['seats']))) {
    $qeSeatErr = 'This booking has no active departure or seat allocation. Review the original ticket before changing seats.';
}
foreach (($b['passengers'] ?? []) as $qeP) { $qePaxBySeat[(string) $qeP['seat_no']] = $qeP; }
if ($qeMay['seat'] && $qeSid > 0 && !empty($qeLeg['seats'])) {
    try {
        $qeAv       = Seats::availabilityForSchedule($qeSid, $qeMode);
        $qeSeatList = array_values(array_map('strval', (array) ($qeAv['available'] ?? [])));
        $qeShared   = Seats::isSharedBooking($qeCoach, $qeMode);
        foreach ($qeLeg['seats'] as $qeFs) {
            $qeG = (string) ($qePaxBySeat[(string) $qeFs]['gender'] ?? '');
            foreach ($qeSeatList as $qeTs) {
                if (!$qeShared || TicketEdit::seatOk($qeAv, $qeTs, $qeG, $qeCoach)) { $qeSeatOk[$qeTs][] = (string) $qeFs; }
            }
        }
        // One-click Auto Assign for one passenger (?auto_from=L5&pref=lower#seat)
        $qeAutoFrom = strtoupper(Security::clean($_GET['auto_from'] ?? '', 10));
        if ($qeAutoFrom !== '' && in_array($qeAutoFrom, array_map('strval', $qeLeg['seats']), true)) {
            $qeRow = $qePaxBySeat[$qeAutoFrom] ?? ['gender' => ''];
            // moved alone, so placed as an adult (the child-beside-adult rule needs the party)
            if ((int) ($qeRow['age'] ?? 0) > 0 && (int) $qeRow['age'] < 12) { $qeRow['age'] = null; $qeRow['special_need'] = ''; }
            $qeAutoPick = TicketEdit::autoSeats($qeSid, $qeMode, [$qeRow], (string) ($_GET['pref'] ?? 'any'), 1);
        }
    } catch (Throwable $e) { $qeSeatErr = $e->getMessage(); }
}
$qeFirstFrom = (string) ($qeAutoFrom !== '' ? $qeAutoFrom : ($qeLeg['seats'][0] ?? ''));
$qeAutoSeat  = ($qeAutoPick['ok'] ?? false) ? (string) ($qeAutoPick['seats'][0] ?? '') : '';

// The one-tap "Wrong …" chips: focus the box, open the flow, or ask.
$qeChips = [
    ['Name',    $qeMay['name'],    '#qe-name',   'qe-name',  '',               'q_name'],
    ['Phone',   $qeMay['phone'],   '#qe-phone',  'qe-phone', '',               'q_phone'],
    ['Date',    $qeCanResched,     $base . '/admin/reschedule.php?pnr=' . urlencode($pnr), '', '', 'q_date'],
    ['Seat',    $qeMay['seat'],    '#seat',      'qe-to',    '',               'q_seat'],
    ['Pickup',  $qeMay['pickup'],  '#qe-board',  'qe-board', '',               'q_pickup'],
    ['Fare',    $qeMay['fare'],    '#fare',      'qe-fare',  'fare',           'q_fare'],
    ['Payment', $qeMay['payment'], '#qe-pay',    'qe-pay',   'payment_method', 'q_paid'],
    ['Agent',   $qeMay['agent'],   '#reassign',  'qe-agent', 'agent',          'q_agent'],
];
$qeEditAny = $qeMay['name'] || $qeMay['phone'] || $qeMay['email'] || $qeMay['pickup'] || $qeMay['gender'] || $qeMay['age'] || $qeMay['id'] || $qeMay['remark'];
$qePrimary = '';
foreach (($b['passengers'] ?? []) as $qeP) {
    if ($qePrimary === '' || (int) ($qeP['is_primary'] ?? 0) === 1) { $qePrimary = (string) $qeP['full_name']; }
    if ((int) ($qeP['is_primary'] ?? 0) === 1) { break; }
}
$qePhone = Notify::usablePhone($b['contact_phone'] ?? '') !== '' ? (string) $b['contact_phone'] : '';
?>
<nav class="qe-bar" aria-label="Ticket actions">
  <a href="#quick">✏️ Quick Edit</a>
  <?php if ($qeCanResched): ?><a href="<?= $base ?>/admin/reschedule.php?pnr=<?= urlencode($pnr) ?>">📅 Change Date</a><?php endif; ?>
  <?php if ($qeMay['seat']): ?><a href="#seat">💺 Change Seat</a><?php endif; ?>
  <?php if ($qeCanResched && !empty($qeLeg['travel_date'])): ?><a href="<?= $base ?>/admin/reschedule.php?pnr=<?= urlencode($pnr) ?>&amp;date=<?= urlencode((string) $qeLeg['travel_date']) ?>" title="Other buses on the same day">🚌 Change Bus</a><?php endif; ?>
  <?php if ($qeLive && Auth::mayCancelBooking($b)): ?><a href="#cancel" class="bad"><?= bv_cancel_is_request() ? '✕ Request cancel' : '✕ Cancel' ?></a><?php endif; ?>
  <a href="#audit">📋 History</a>
  <?php if ((string) $b['status'] === 'confirmed'): ?><button type="button" data-share-url="<?= Security::e(Ticket::imageUrl($pnr)) ?>" data-share-title="<?= Security::e('Ticket ' . $pnr) ?>">🔗 Share</button><?php endif; ?>
</nav>

<details class="panel qe" id="quick" open>
  <summary><h2>✏️ Quick Edit · <span class="mono"><?= Security::e($pnr) ?></span></h2>
    <span class="muted">Current values are filled in — change only what is wrong, then Save. Every change is audited old → new.</span></summary>
  <div class="qe-body">
    <div class="qe-chips" role="group" aria-label="Quick fixes">
      <b>Wrong:</b>
      <?php foreach ($qeChips as [$cLabel, $cMay, $cHref, $cFocus, $cReq, $cQ]):
        if ($cMay) {
            echo '<a class="qe-chip" href="' . Security::e($cHref) . '"' . ($cFocus !== '' ? ' data-focus="' . $cFocus . '"' : '') . '>' . Security::e($cLabel) . '</a>';
        } elseif ($cReq !== '' && isset($qeReqFields[$cReq])) {
            echo '<a class="qe-chip req" href="#req" data-req="' . $cReq . '" title="Ask the office to correct it">' . Security::e($cLabel) . ' · ask</a>';
        } elseif ($qeFieldRole && $qeLive) {
            echo '<a class="qe-chip req" href="' . Security::e($qeChat($cQ)) . '" title="Ask the office in Office chat">' . Security::e($cLabel) . ' · chat</a>';
        } else {
            echo '<span class="qe-chip off" title="Your role cannot change this">' . Security::e($cLabel) . '</span>';
        }
      endforeach; ?>
    </div>

    <?php if (!$qeLive): ?>
      <p class="qe-hint">This ticket is <b><?= Security::e((string) $b['status']) ?></b> — nothing on it can be changed any more.</p>
    <?php elseif ($qeAgentLocked): ?>
      <p class="qe-hint">⚠️ The trip is <b><?= Security::e((string) ($qeGate['label'] ?? 'closed')) ?></b> — ask the office for any correction (Office chat or Request correction).</p>
    <?php endif; ?>

    <?php if ($qeEditAny): ?>
    <form method="post" class="qe-form" id="qe-main">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="edit_passenger">
      <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
      <div class="qe-grid">
        <?php if ($qeMay['phone'] || $qeMay['email']): ?>
        <div class="qe-block">
          <h3>📞 Contact</h3>
          <?php if ($qeMay['phone']): ?>
          <div class="qe-row"><label for="qe-phone">Mobile / WhatsApp</label>
            <input type="tel" id="qe-phone" name="contact_phone" inputmode="tel" maxlength="15" value="<?= Security::e((string) $b['contact_phone']) ?>" data-old="<?= Security::e((string) $b['contact_phone']) ?>" data-label="Phone"></div>
          <?php endif; ?>
          <?php if ($qeMay['email']): ?>
          <div class="qe-row"><label for="qe-email">Email</label>
            <input type="email" id="qe-email" name="contact_email" maxlength="120" value="<?= Security::e((string) ($b['contact_email'] ?? '')) ?>" data-old="<?= Security::e((string) ($b['contact_email'] ?? '')) ?>" data-label="Email"></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($qeMay['pickup']): ?>
        <div class="qe-block">
          <h3>📍 Pickup / drop</h3>
          <div class="qe-row"><label for="qe-board">Boarding / चढ्ने ठाउँ</label>
            <?= $qeStopPick('boarding_stop', 'boarding_other', 'qe-board', 'Pickup', $qeBoardOpts, (string) ($qeLeg['boarding_stop'] ?? '')) ?></div>
          <div class="qe-row"><label for="qe-drop">Drop / ओर्लिने ठाउँ</label>
            <?= $qeStopPick('drop_stop', 'drop_other', 'qe-drop', 'Drop', $qeDropOpts, (string) ($qeLeg['drop_stop'] ?? '')) ?></div>
          <p class="qe-hint">A stop with a different fare is refused<?= Auth::isSuperadmin() ? ' for other roles (your override is audited)' : ' — ask the office' ?>.</p>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($qeMay['name'] || $qeMay['age'] || $qeMay['gender'] || $qeMay['id']): ?>
      <div class="qe-block">
        <h3>🧍 Passengers</h3>
        <?php $qeFirstName = true; foreach (($b['passengers'] ?? []) as $pax): $pid = (int) $pax['id']; $pSeat = Seats::displayLabel((string) $pax['seat_no'], $qeCoach, $qeMode); ?>
        <div class="qe-pax">
          <span class="mono"><?= Security::e($pSeat) ?></span>
          <?php if ($qeMay['name']): ?>
            <input type="text" name="pax[<?= $pid ?>][name]" <?= $qeFirstName ? 'id="qe-name"' : '' ?> maxlength="120" value="<?= Security::e((string) $pax['full_name']) ?>" data-old="<?= Security::e((string) $pax['full_name']) ?>" data-label="<?= Security::e($pSeat) ?> name" aria-label="<?= Security::e($pSeat) ?> name">
          <?php else: ?><span><?= Security::e((string) $pax['full_name']) ?></span><?php endif; ?>
          <?php if ($qeMay['age']): ?>
            <input type="number" name="pax[<?= $pid ?>][age]" min="1" max="120" value="<?= $pax['age'] !== null ? (int) $pax['age'] : '' ?>" data-old="<?= $pax['age'] !== null ? (int) $pax['age'] : '' ?>" data-label="<?= Security::e($pSeat) ?> age" aria-label="Age" placeholder="Age">
          <?php else: ?><span><?= $pax['age'] !== null ? (int) $pax['age'] : '—' ?></span><?php endif; ?>
          <?php if ($qeMay['gender']): ?>
            <select name="pax[<?= $pid ?>][gender]" class="qe-wide" data-old="<?= Security::e((string) ($pax['gender'] ?? '')) ?>" data-label="<?= Security::e($pSeat) ?> gender" aria-label="Gender">
              <?php if ((string) ($pax['gender'] ?? '') === ''): ?><option value="">—</option><?php endif; ?>
              <?php foreach (['Male', 'Female', 'Other'] as $gOpt): ?><option value="<?= $gOpt ?>" <?= ($pax['gender'] ?? '') === $gOpt ? 'selected' : '' ?>><?= $gOpt ?></option><?php endforeach; ?>
            </select>
          <?php else: ?><span class="qe-wide"><?= Security::e((string) ($pax['gender'] ?? '—')) ?></span><?php endif; ?>
          <?php if ($qeMay['id']): ?>
            <input type="text" name="pax[<?= $pid ?>][id_type]" class="qe-wide" maxlength="60" value="<?= Security::e((string) ($pax['id_type'] ?? '')) ?>" data-old="<?= Security::e((string) ($pax['id_type'] ?? '')) ?>" data-label="<?= Security::e($pSeat) ?> ID type" placeholder="ID type" aria-label="ID type" title="Aadhaar / Citizenship / Passport">
            <input type="text" name="pax[<?= $pid ?>][id_number]" class="qe-wide" maxlength="60" value="<?= Security::e((string) ($pax['id_number'] ?? '')) ?>" data-old="<?= Security::e((string) ($pax['id_number'] ?? '')) ?>" data-label="<?= Security::e($pSeat) ?> ID number" placeholder="ID number" aria-label="ID number">
          <?php endif; ?>
        </div>
        <?php $qeFirstName = false; endforeach; ?>
        <?php if ($qeMay['gender'] && $qeMode !== 'private'): ?><p class="qe-hint">A gender that would share a cabin with another booking's passenger of the other gender is refused — move the seat first.</p><?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="qe-actions">
        <?php if ($qeMay['remark']): ?>
          <input type="text" name="remark" maxlength="180" placeholder="Add a remark (internal, kept with your name)" aria-label="Remark" data-add-label="Remark">
        <?php endif; ?>
        <input type="text" name="reason" maxlength="255" placeholder="Reason for the change (audit)" aria-label="Reason for the change">
        <button type="submit" class="btn ok">💾 Save changes</button>
      </div>
      <p class="qe-hint">You will see a Before → After summary before it saves. The ticket QR / PNG regenerate and the passenger is told what changed.</p>
    </form>
    <?php endif; ?>

    <div class="qe-grid">
      <?php if ($qeMay['seat']): ?>
      <div class="qe-block" id="seat">
        <h3>💺 Change seat <span class="muted" style="text-transform:none;font-weight:600"><?= count($qeSeatList) ?> free on this bus</span></h3>
        <?php if ($qeSeatErr !== ''): ?><p class="qe-hint">Seat map unavailable: <?= Security::e($qeSeatErr) ?></p>
        <?php else: ?>
        <form method="get" action="<?= $base ?>/admin/booking-view.php#seat" class="qe-actions" style="margin-bottom:8px">
          <input type="hidden" name="pnr" value="<?= Security::e($pnr) ?>">
          <select name="auto_from" aria-label="Passenger to place" style="flex:1 1 90px">
            <?php foreach ($qeLeg['seats'] as $sn): ?><option value="<?= Security::e((string) $sn) ?>" <?= (string) $sn === $qeFirstFrom ? 'selected' : '' ?>><?= Security::e($qeLbl((string) $sn) . ' · ' . (string) ($qePaxBySeat[(string) $sn]['full_name'] ?? '')) ?></option><?php endforeach; ?>
          </select>
          <select name="pref" aria-label="Berth preference" style="flex:1 1 90px">
            <?php foreach (['any' => 'Any berth', 'lower' => 'Lower first', 'upper' => 'Upper first'] as $pv => $pl): ?><option value="<?= $pv ?>" <?= (string) ($_GET['pref'] ?? 'any') === $pv ? 'selected' : '' ?>><?= $pl ?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="btn ghost">✨ Auto Assign</button>
        </form>
        <?php if ($qeAutoPick !== null): ?>
          <p class="qe-hint"><?= $qeAutoSeat !== '' ? '✨ Suggested: <b>' . Security::e($qeLbl($qeAutoSeat)) . '</b> — chosen below; press Move seat to save.' : 'No berth on this bus keeps every rule for this passenger.' ?></p>
        <?php endif; ?>
        <form method="post" class="qe-actions" id="qe-seat-form"
              onsubmit="return confirm('Seat: ' + this.from_seat.options[this.from_seat.selectedIndex].text + ' → ' + (this.to_seat.options[this.to_seat.selectedIndex] || {}).text + '\nThe ticket QR and PNG are re-issued.')">
          <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="change_seat">
          <select name="from_seat" id="qe-from" aria-label="From seat" style="flex:1 1 90px">
            <?php foreach ($qeLeg['seats'] as $sn): ?><option value="<?= Security::e((string) $sn) ?>" <?= (string) $sn === $qeFirstFrom ? 'selected' : '' ?>><?= Security::e($qeLbl((string) $sn)) ?></option><?php endforeach; ?>
          </select>
          <select name="to_seat" id="qe-to" aria-label="To seat" required style="flex:1 1 90px">
            <option value="">— free seat —</option>
            <?php foreach ($qeSeatOk as $ts => $froms): $fits = in_array($qeFirstFrom, $froms, true); ?>
              <option value="<?= Security::e((string) $ts) ?>" data-for="<?= Security::e(implode(' ', $froms)) ?>" <?= $fits ? '' : 'hidden disabled' ?> <?= (string) $ts === $qeAutoSeat ? 'selected' : '' ?>><?= Security::e($qeLbl((string) $ts)) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="reason" maxlength="255" placeholder="Reason (audit)" aria-label="Reason">
          <button type="submit" class="btn ok">💺 Move seat</button>
        </form>
        <p class="qe-hint">Only seats this passenger may take are listed (women-only berths and cabins held for the other gender are hidden). Double booking is blocked by the seat lock.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($qeMay['payment']): ?>
      <div class="qe-block">
        <h3>💳 Payment record</h3>
        <form method="post" class="qe-form">
          <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="edit_payment">
          <div class="qe-row"><label for="qe-pay">Method</label>
            <select name="pay_method" id="qe-pay" data-old="<?= Security::e((string) ($pay['method'] ?? '')) ?>" data-label="Payment method">
              <?php foreach (TicketEdit::PAY_METHODS as $pm): ?><option value="<?= $pm ?>" <?= ($pay['method'] ?? '') === $pm ? 'selected' : '' ?>><?= strtoupper($pm) ?></option><?php endforeach; ?>
            </select></div>
          <div class="qe-row"><label for="qe-utr">UTR / reference</label>
            <input type="text" name="utr_number" id="qe-utr" maxlength="60" value="<?= Security::e((string) ($pay['utr_number'] ?? '')) ?>" data-old="<?= Security::e((string) ($pay['utr_number'] ?? '')) ?>" data-label="UTR"></div>
          <div class="qe-actions"><input type="text" name="pay_note" maxlength="255" placeholder="Office note (optional)" aria-label="Office note" data-add-label="Office note">
            <button type="submit" class="btn ok">💾 Save payment</button></div>
          <p class="qe-hint">Status is <?= admin_pill((string) ($pay['status'] ?? 'pending')) ?> — Approve / Reject / Cash collected change it. Cash ↔ UPI moves the seller's cash held.</p>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($qeMay['fare']): ?>
      <div class="qe-block" id="fare">
        <h3>₹ Fare correction <span class="muted" style="text-transform:none;font-weight:600">super-admin</span></h3>
        <form method="post" class="qe-form">
          <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="fare_correct">
          <div class="qe-row"><label for="qe-fare">Total fare ₹</label>
            <input type="number" step="0.01" min="0" name="new_total" id="qe-fare" value="<?= Security::e(number_format((float) $b['total_amount'], 2, '.', '')) ?>" data-old="<?= Security::e(number_format((float) $b['total_amount'], 2, '.', '')) ?>" data-label="Total fare"></div>
          <div class="qe-row"><label for="qe-disc">Approved discount ₹</label>
            <input type="number" step="0.01" min="0" name="new_discount" id="qe-disc" value="<?= Security::e(number_format((float) ($b['coupon_discount'] ?? 0), 2, '.', '')) ?>" data-old="<?= Security::e(number_format((float) ($b['coupon_discount'] ?? 0), 2, '.', '')) ?>" data-label="Discount"></div>
          <div class="qe-actions"><input type="text" name="reason" maxlength="255" required minlength="3" placeholder="Reason (required)" aria-label="Reason">
            <button type="submit" class="btn ok">₹ Correct fare</button></div>
          <p class="qe-hint">Moves the payment amount, the seller's commission (re-rated) and cash held with it, in one step. A discount alone moves the total by the same amount. Refunds are not touched.</p>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($qeMay['agent']): ?>
      <div class="qe-block" id="reassign">
        <h3>🧑‍💼 Selling agent</h3>
        <form method="post" onsubmit="return confirm('Agent: <?= Security::e(str_replace("'", '', $currentSeller)) ?> → ' + this.new_agent_id.options[this.new_agent_id.selectedIndex].text.trim() + '\nThe commission follows the new seller; cash stays with whoever collected it.')">
          <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="reassign_agent">
          <div class="qe-row"><label for="qe-agent">Now: <b><?= Security::e($currentSeller) ?></b></label>
            <select name="new_agent_id" id="qe-agent">
              <option value="0" <?= $soldById === 0 ? 'selected' : '' ?>>— Office / Online (no agent) —</option>
              <?php foreach ($reassignAgents as $ra): ?>
                <option value="<?= (int) $ra['id'] ?>" <?= (int) $ra['id'] === $soldById ? 'selected' : '' ?>><?= Security::e(trim(($ra['code'] !== '' ? $ra['code'] . ' · ' : '') . ($ra['full_name'] ?: $ra['username']))) ?></option>
              <?php endforeach; ?>
            </select></div>
          <button type="submit" class="btn ok">🧑‍💼 Reassign seller</button>
          <p class="qe-hint">The old seller's commission is reversed and the new seller's posted from the ledger — audit-logged.</p>
        </form>
      </div>
      <?php endif; ?>

      <?php if (Auth::isSellingStaff()): ?>
      <div class="qe-block">
        <h3>➕ New ticket</h3>
        <p class="qe-hint" style="margin:0 0 8px">This PNR is never changed by these — a new ticket is sold through the normal counter checkout, then shows under "Other tickets on this number".</p>
        <div class="qe-actions">
          <?php if ($qeLive && $qeRoute > 0 && !empty($qeLeg['travel_date'])): ?>
          <a class="btn ghost" href="<?= $base ?>/admin/new-booking.php?<?= Security::e(http_build_query(array_filter(['route' => $qeRoute, 'date' => (string) $qeLeg['travel_date'], 'sid' => $qeSid, 'cphone' => $qePhone, 'cname' => $qePrimary], static fn($v) => $v !== '' && $v !== 0))) ?>">➕ Add passenger (new ticket)</a>
          <?php endif; ?>
          <a class="btn ghost" href="<?= $base ?>/admin/new-booking.php?<?= Security::e(http_build_query(array_filter(['cphone' => $qePhone, 'cname' => $qePrimary], static fn($v) => $v !== ''))) ?>">🔀 Different route → new ticket</a>
        </div>
        <?php if ($qeLive): ?><p class="qe-hint">Moving to another route? Sell the new ticket, then cancel this one with the Cancel button — the refund is settled by hand.</p><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($qeReqFields !== []): ?>
    <div class="qe-block" id="req">
      <h3>📝 Request correction <span class="muted" style="text-transform:none;font-weight:600">the office applies or refuses it here</span></h3>
      <form method="post" class="qe-actions" id="qe-req-form">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="edit_request">
        <select name="req_field" id="qe-req-field" aria-label="What is wrong" style="flex:1 1 160px">
          <?php foreach ($qeReqFields as $rk => $rl): ?><option value="<?= Security::e($rk) ?>"><?= Security::e($rl) ?></option><?php endforeach; ?>
        </select>
        <?php if (isset($qeReqFields['fare'])): ?>
          <input type="number" step="0.01" min="0" name="req_fare" data-for="fare" placeholder="Correct total ₹ (now <?= Security::e(inr((float) $b['total_amount'])) ?>)" aria-label="Correct total fare" style="flex:1 1 160px">
        <?php endif; ?>
        <?php if (isset($qeReqFields['payment_method'])): ?>
          <select name="req_method" data-for="payment_method" aria-label="Correct payment method" style="flex:1 1 120px">
            <?php foreach (TicketEdit::PAY_METHODS as $pm): ?><option value="<?= $pm ?>" <?= ($pay['method'] ?? '') === $pm ? 'selected' : '' ?>><?= strtoupper($pm) ?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>
        <?php if (isset($qeReqFields['agent'])): ?>
          <input type="text" name="req_agent" data-for="agent" maxlength="20" placeholder="Agent code (SHG-0007) or office" aria-label="Correct agent code" style="flex:1 1 160px">
        <?php endif; ?>
        <input type="text" name="req_reason" required minlength="3" maxlength="200" placeholder="Why (required)" aria-label="Why" style="flex:2 1 200px">
        <button type="submit" class="btn warn">📝 Send request</button>
      </form>
      <?php if (isset($qeReqFields['cancel_request'])): ?><p class="qe-hint">A cancel request is settled by the office by hand (cancel + refund) — the ticket stays valid until then.</p><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($qeRequests !== []): ?>
    <div class="qe-block" id="requests">
      <h3>🗂️ Corrections asked on this ticket</h3>
      <ul class="qe-req-list">
        <?php foreach ($qeRequests as $rq): $rf = (string) $rq['field_changed']; $rOpen = (string) $rq['outcome'] === 'requested'; ?>
        <li>
          <b>#<?= (int) $rq['id'] ?> · <?= Security::e(TicketEdit::REQUEST_FIELDS[$rf] ?? $rf) ?></b>
          <?php if ($rf !== 'cancel_request'): ?>: <?= Security::e($qeShowVal($rf, $rq['old_value'])) ?> → <b><?= Security::e($qeShowVal($rf, $rq['new_value'])) ?></b><?php else: ?>: <?= Security::e((string) $rq['new_value']) ?><?php endif; ?>
          · <?= admin_pill($rOpen ? 'pending' : ((string) $rq['outcome'] === 'done' ? 'confirmed' : 'rejected')) ?>
          <div class="muted" style="font-size:12px"><?= Security::e((string) $rq['detail']) ?> · <?= Security::e(formatDate((string) $rq['changed_at'], 'j M, g:i A')) ?></div>
          <?php if ($rOpen && $qeApprover): ?>
          <form method="post">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="edit_decide">
            <input type="hidden" name="request_id" value="<?= (int) $rq['id'] ?>">
            <input type="text" name="decide_note" maxlength="120" placeholder="Note (optional)" aria-label="Decision note" style="flex:1 1 160px">
            <?php if ($rf === 'cancel_request'): ?>
              <a class="btn ghost" href="#cancel">Use the Cancel button</a>
            <?php elseif ($rf === 'fare' && !Auth::isSuperadmin()): ?>
              <span class="muted" style="font-size:12px;align-self:center">A super-admin applies fare corrections.</span>
            <?php else: ?>
              <button type="submit" name="decision" value="apply" class="btn ok" onclick="return confirm('Apply request #<?= (int) $rq['id'] ?>?')">✓ Apply</button>
            <?php endif; ?>
            <button type="submit" name="decision" value="refuse" class="btn bad">✕ Refuse</button>
          </form>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</details>

<script>
/* Quick Edit (15 Oct 2026): Before → After summary, chips, stop "Other…",
   seat list per passenger, request field, Share. Progressive: without
   JavaScript every form still posts and the server re-checks everything. */
(function () {
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function pairOf(sel) { var n = sel.nextElementSibling; return n && n.classList.contains('qe-other') ? n : null; }
  $$('select.qe-stop').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var o = pairOf(sel); if (!o) return;
      var other = sel.value === '__other__';
      o.hidden = !other; o.disabled = !other; if (other) o.focus();
    });
  });
  $$('form.qe-form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      var lines = [];
      $$('[data-old]', f).forEach(function (el) {
        if (el.disabled) return;
        var v = String(el.value || '').trim(), o = el.getAttribute('data-old') || '';
        if (el.classList.contains('qe-stop') && v === '__other__') { var p = pairOf(el); v = p ? String(p.value || '').trim() : ''; }
        if (v === '' || v === o) return;
        lines.push((el.getAttribute('data-label') || el.name) + ': ' + (o || '—') + ' → ' + v);
      });
      $$('[data-add-label]', f).forEach(function (el) {
        if (!el.disabled && String(el.value || '').trim()) lines.push(el.getAttribute('data-add-label') + ': + ' + String(el.value).trim());
      });
      if (!lines.length) { e.preventDefault(); alert('Nothing changed yet — edit a box first. / केही बदलिएको छैन।'); return; }
      if (!confirm('Save these changes?\n\n' + lines.join('\n'))) e.preventDefault();
    });
  });
  $$('.qe-chip[data-focus], .qe-chip[data-req]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var q = document.getElementById('quick'); if (q) q.open = true;
      var req = a.getAttribute('data-req'), el;
      if (req) { var rs = document.getElementById('qe-req-field'); if (rs) { rs.value = req; rs.dispatchEvent(new Event('change')); } el = rs; }
      else el = document.getElementById(a.getAttribute('data-focus'));
      if (!el) return;
      e.preventDefault();
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      try { el.focus({ preventScroll: true }); } catch (x) { el.focus(); }
    });
  });
  var from = document.getElementById('qe-from'), to = document.getElementById('qe-to');
  if (from && to) from.addEventListener('change', function () {
    $$('option[data-for]', to).forEach(function (op) {
      var ok = (' ' + op.getAttribute('data-for') + ' ').indexOf(' ' + from.value + ' ') > -1;
      op.hidden = !ok; op.disabled = !ok; if (!ok && op.selected) to.value = '';
    });
  });
  var rf = document.getElementById('qe-req-field');
  if (rf) {
    var paint = function () { $$('#qe-req-form [data-for]').forEach(function (el) { var on = el.getAttribute('data-for') === rf.value; el.hidden = !on; el.disabled = !on; }); };
    rf.addEventListener('change', paint); paint();
  }
  // Share (delegated: the toolbar's button sits further down the page).
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-share-url]') : null;
    if (!b) return;
    var u = b.getAttribute('data-share-url'), t = b.getAttribute('data-share-title') || '';
    if (navigator.share) { navigator.share({ title: t, text: t, url: u }).catch(function () {}); return; }
    var done = function () { b.textContent = '✓ Link copied'; };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(u).then(done, function () { window.prompt('Copy the ticket link', u); });
    else window.prompt('Copy the ticket link', u);
  });
})();
</script>

<?php /* 9 Oct 2026 — Office chat about THIS ticket: the agent asks the office,
         the office messages the seller; the last lines show here. Scoped by
         StaffMsg (an agent sees only their own thread on their own sale). A
         request is a conversation - nothing here cancels or refunds. */ ?>
<?= StaffMsg::bookingPanel($b) ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:start">
  <div class="panel">
    <h2>Journey</h2>
    <table>
      <tr><th>Route</th><td><?= Security::e(($leg['from_city'] ?? '—') . ' → ' . ($leg['to_city'] ?? '—')) ?></td></tr>
      <tr><th>Bus</th><td><?= Security::e($leg['bus_name'] ?? '—') ?> <span class="muted">(<?= Security::e($leg['route_code'] ?? '') ?>)</span></td></tr>
      <tr><th>Travel date</th><td><?= Security::e(formatDate($leg['travel_date'] ?? null)) ?> · <?= Security::e(formatTime($leg['dep_time'] ?? null)) ?></td></tr>
      <tr><th>Pickup / चढ्ने ठाउँ</th><td><?= Security::e($leg['boarding_stop'] ?? '—') ?></td></tr>
      <tr><th>Drop / ओर्लिने ठाउँ</th><td><?= Security::e($leg['drop_stop'] ?? '—') ?></td></tr>
      <tr><th>Seats</th><td class="mono"><?= Security::e(implode(', ', array_map(static fn($seat) => Seats::displayLabel((string) $seat, 'sleeper', (string) ($b['booking_mode'] ?? 'sharing')), $b['seats'] ?? []))) ?></td></tr>
      <?php /* The walk-in placeholder is not a number anyone can ring, so it
               is shown as what it means rather than as 0000000000 digits. */ ?>
      <tr><th>Contact</th><td class="mono"><?= Notify::usablePhone($b['contact_phone'] ?? '') !== ''
            ? Security::e((string) $b['contact_phone'])
            : '<span class="muted">walk-in · no phone</span>' ?><?= !empty($b['contact_email']) ? ' · ' . Security::e((string) $b['contact_email']) : '' ?></td></tr>
      <tr><th>Booked by</th><td>
        <?php if (!empty($b['agent'])): ?>
          <strong><?= Security::e((string) ($b['agent']['full_name'] ?: $b['agent']['username'])) ?></strong><?php if (!empty($b['agent']['phone'])): ?> <span class="mono">· <?= Security::e((string) $b['agent']['phone']) ?></span><?php endif; ?>
          <span class="muted">(<?= Security::e(ucfirst((string) ($b['agent']['role'] ?? 'agent'))) ?>)</span>
        <?php else: ?>
          <span class="muted">Online — customer self-booking</span>
        <?php endif; ?>
        <?php if (!empty($b['source'])): ?> <span class="muted">· via <?= Security::e((string) $b['source']) ?></span><?php endif; ?>
      </td></tr>
      <?php /* 15 Oct 2026 (1A-remarks): the internal remarks (bookings.admin_note -
           Quick Edit remarks, per-seat cancel lines), read-only, for the office
           and the counter. Never on the ticket; not shown to agents. */ ?>
      <?php if (trim((string) ($b['admin_note'] ?? '')) !== '' && (Auth::can('bookings.edit') || StaffMsg::isOffice())): ?>
      <tr><th>Remarks</th><td style="white-space:pre-line;font-size:13px"><?= Security::e(trim((string) $b['admin_note'])) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="panel">
    <h2>Fare &amp; payment</h2>
    <table>
      <tr><th>Base total</th><td><?= Security::e(inr((float) $b['base_total'])) ?></td></tr>
      <?php foreach ([['group_discount','Group discount'],['coupon_discount', ((string)($b['coupon_code'] ?? '') !== '' ? 'Coupon' : 'Discount')],['tier_discount','Member discount'],['points_value','Loyalty points']] as $d): ?>
        <?php if ((float) ($b[$d[0]] ?? 0) > 0): ?>
          <tr><th><?= $d[1] ?></th><td>− <?= Security::e(inr((float) $b[$d[0]])) ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ((float) ($b['tax_amount'] ?? 0) > 0): ?><tr><th>Tax</th><td><?= Security::e(inr((float) $b['tax_amount'])) ?></td></tr><?php endif; ?>
      <tr><th>Total</th><td><strong style="font-size:17px"><?= Security::e(inr((float) $b['total_amount'])) ?></strong></td></tr>
      <tr><th>Method</th><td><?= Security::e(strtoupper((string) ($pay['method'] ?? '—'))) ?> · <?= admin_pill((string) ($pay['status'] ?? 'pending')) ?></td></tr>
      <tr><th>UTR / Ref</th><td class="mono"><?= Security::e($pay['utr_number'] ?? '—') ?><?= $alsoOnPill('UTR', $utrAlsoOn) ?></td></tr>
      <?php if ($hasProof): ?>
      <tr><th>Payment proof</th><td><a class="btn ghost" style="font-size:12px;padding:5px 12px" href="/admin/screenshot.php?id=<?= (int) ($b['id'] ?? 0) ?>" target="_blank" rel="noopener">🖼️ View proof</a><?= $alsoOnPill('Screenshot', $shotAlsoOn) ?></td></tr>
      <?php endif; ?>
      <?php
        /* QR / pay-link trail. A UPI QR is paid inside the passenger's bank
           app and tells us nothing, so this is intent only — never proof of
           payment. Useful the other way round: an old pending booking with
           nothing here was never even opened. */
        require_once INCLUDE_PATH . '/payevents.php';
        $trail = PayEvents::forBooking((int) ($b['id'] ?? 0), 12);
        $human = array_values(array_filter($trail, static fn(array $e): bool => (int) $e['is_bot'] === 0));
      ?>
      <tr><th>QR / pay link</th><td>
        <?php if ($human === []): ?>
          <span class="muted">never opened</span>
        <?php else: ?>
          <div style="font-size:12px;line-height:1.7">
          <?php foreach (array_slice($human, 0, 6) as $e): ?>
            <div>
              <strong><?= $e['kind'] === 'qr_view' ? 'QR opened' : 'Pay link tapped' ?></strong>
              · <?= Security::e(PayEvents::device((string) $e['user_agent'])) ?>
              · <?= Security::e(timeAgo((string) $e['created_at'])) ?>
              <span class="muted mono" style="font-size:11px"><?= Security::e((string) $e['ip']) ?></span>
            </div>
          <?php endforeach; ?>
          </div>
          <div class="muted" style="font-size:11px;margin-top:4px">Shows interest only — a UPI QR never reports the payment back.</div>
        <?php endif; ?>
      </td></tr>
    </table>
  </div>
</div>

<?php
/* ========================================================================
 *  1a. Booking Source Card
 * ======================================================================== */
$source = strtolower((string) ($b['source'] ?? 'web'));
$sourceBadgeClass = match($source) {
    'agent'   => 'agent',
    'counter' => 'counter',
    'admin'   => 'admin-src',
    default   => 'online',
};
$sourceLabel = match($source) {
    'agent'   => 'Agent',
    'counter' => 'Counter',
    'admin'   => 'Admin',
    default   => 'Online',
};
$bookedByLabel = 'Online Customer';
if (!empty($b['agent'])) {
    $name = (string) ($b['agent']['full_name'] ?: $b['agent']['username']);
    $bookedByLabel = $name . ($agentCode !== '' ? ' (' . $agentCode . ')' : '');
} elseif ($source === 'admin') {
    $bookedByLabel = 'Admin';
} elseif ($source === 'counter') {
    $bookedByLabel = 'Counter Staff';
}

/* Agent phone — mask for non-superadmins */
$agentPhone = '';
if (!empty($b['agent']['phone'])) {
    $phone = (string) $b['agent']['phone'];
    if (Auth::isSuperadmin()) {
        $agentPhone = $phone;
    } else {
        // Show last 4 digits only
        $agentPhone = str_repeat('*', max(0, strlen($phone) - 4)) . substr($phone, -4);
    }
}

$createdAt  = (string) ($b['created_at'] ?? '');
$updatedAt  = (string) ($b['updated_at'] ?? '');
$hasUpdated = $updatedAt !== '' && $updatedAt !== $createdAt;
?>
<div class="source-card">
  <h3>Booking Source</h3>
  <dl class="source-grid">
    <div>
      <dt>Source</dt>
      <dd><span class="source-badge <?= $sourceBadgeClass ?>"><?= Security::e($sourceLabel) ?></span></dd>
    </div>
    <div>
      <dt>Booked by</dt>
      <dd><?= Security::e($bookedByLabel) ?></dd>
    </div>
    <div>
      <dt>Channel</dt>
      <dd><?php $bvChan = BookingChannel::of((int) ($b['id'] ?? 0)); echo BookingChannel::badge($bvChan); ?>
        <?php if (Auth::can('bookings.edit') && BookingChannel::hasTagColumn()): ?>
        <form method="post" data-no-guard style="display:inline-block;margin-left:6px">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="channel_wa">
          <input type="hidden" name="on" value="<?= $bvChan === 'WHATSAPP' ? '0' : '1' ?>">
          <button class="btn ghost sm" type="submit" style="font-size:12px;padding:4px 9px"><?= $bvChan === 'WHATSAPP' ? 'Not a WhatsApp request' : '💬 Came by WhatsApp' ?></button>
        </form>
        <?php endif; ?></dd>
    </div>
    <?php
      // 5 Oct 2026: the ad / campaign link that brought this online customer.
      require_once INCLUDE_PATH . '/adattribution.php';
      $adSrc = AdAttribution::forBooking((int) ($b['id'] ?? 0));
    ?>
    <?php if ($adSrc !== null): ?>
    <div>
      <dt>Came from</dt>
      <dd>📣 <?= Security::e(AdAttribution::label((string) $adSrc['src'])) ?><?php if (!empty($adSrc['campaign'])): ?> <span class="muted">· <?= Security::e((string) $adSrc['campaign']) ?></span><?php endif; ?></dd>
    </div>
    <?php endif; ?>
    <?php if ($agentPhone !== ''): ?>
    <div>
      <dt>Agent contact</dt>
      <dd class="mono"><?= Security::e($agentPhone) ?></dd>
    </div>
    <?php endif; ?>
    <?php if ($commissionRow !== null): ?>
    <div>
      <dt>Commission</dt>
      <dd>₹<?= number_format((float) $commissionRow['amount'], 2) ?>
        <span class="muted" style="font-weight:500">·
          <?= $commissionRow['rule_mode'] === 'percent'
                ? Security::e(rtrim(rtrim((string) $commissionRow['rule_value'], '0'), '.')) . '%'
                : '₹' . Security::e(rtrim(rtrim((string) $commissionRow['rule_value'], '0'), '.')) . '/pax' ?>
          · <?= Security::e(ucfirst((string) $commissionRow['status'])) ?></span>
      </dd>
    </div>
    <?php endif; ?>
    <?php if ($ledgerCommission !== null): ?>
    <div>
      <dt>Agent commission</dt>
      <dd>₹<?= number_format((float) $ledgerCommission['amount'], 2) ?>
        <span class="muted" style="font-weight:500">· wallet ledger<?= (float) ($ledgerCommission['voided'] ?? 0) !== 0.0 ? ' · voided ₹' . number_format(abs((float) $ledgerCommission['voided']), 2) : '' ?><?= !empty($ledgerCommission['note']) ? ' · ' . Security::e((string) $ledgerCommission['note']) : '' ?></span>
      </dd>
    </div>
    <?php endif; ?>
    <div>
      <dt>Booking date</dt>
      <dd><?= $createdAt !== '' ? Security::e(formatDate($createdAt, 'D, j M Y · g:i A')) : '<span class="muted">—</span>' ?></dd>
    </div>
    <?php if ($hasUpdated): ?>
    <div>
      <dt>Last updated</dt>
      <dd><?= Security::e(formatDate($updatedAt, 'D, j M Y · g:i A')) ?> <span class="muted">(<?= Security::e(timeAgo($updatedAt)) ?>)</span></dd>
    </div>
    <?php endif; ?>
    <?php if (Auth::isSuperadmin() && !empty($b['ip_address'])): ?>
    <div>
      <dt>IP address</dt>
      <dd class="mono"><?= Security::e((string) $b['ip_address']) ?></dd>
    </div>
    <?php endif; ?>
    <?php if (!empty($b['ticket_number'])): ?>
    <div>
      <dt>Ticket no.</dt>
      <dd class="mono"><?= Security::e((string) $b['ticket_number']) ?></dd>
    </div>
    <?php endif; ?>
  </dl>
</div>

<?php
/* ========================================================================
 *  1c. Enhanced Passenger Table — with boarding status
 * ======================================================================== */
$ticket = $b['ticket'] ?? null;
?>
<div class="panel" id="pax">
  <h2>Passengers</h2>
  <div class="tbl-scroll">
  <table>
    <thead><tr><th>Seat</th><th>Name</th><th>Age</th><th>Gender</th><th>Boarding</th><?php if (Auth::mayCancelBooking($b) && in_array($b['status'], ['pending', 'confirmed'], true) && count($b['passengers']) > 1): ?><th>Actions</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($b['passengers'] as $p): ?>
      <tr>
        <td class="mono"><?= Security::e(Seats::displayLabel((string) $p['seat_no'], 'sleeper', (string) ($b['booking_mode'] ?? 'sharing'))) ?></td>
        <td><?= Security::e((string) $p['full_name']) ?><?php if ((int) ($p['is_primary'] ?? 0) === 1): ?> <span class="pill" style="background:#e2ecfb;color:#1c3b72;font-size:10px;padding:1px 6px">Primary</span><?php endif; ?><?php if (!empty($p['special_need'])): ?> <span class="pill" style="background:#e6f4ea;color:#0f5c36;font-size:10px;padding:1px 6px" title="Priority boarding">🩺 <?= Security::e(ucfirst((string) $p['special_need'])) ?></span><?php endif; ?><?php $pdN = count($paxDocs[(int) $p['id']] ?? []); if ($pdN > 0): ?> <a class="pill st-info nodot" style="font-size:10px;padding:1px 6px" href="#paxdocs" title="Photo / ID documents on file">📎 <?= $pdN ?></a><?php endif; ?></td>
        <td><?= $p['age'] !== null ? (int) $p['age'] : '—' ?></td>
        <td><?= Security::e((string) ($p['gender'] ?? '—')) ?></td>
        <td>
          <?php if (!empty($p['boarded_at'])): ?>
            <span class="board-yes">Boarded at <?= Security::e(formatDate($p['boarded_at'], 'g:i A')) ?></span>
          <?php else: ?>
            <span class="board-no">Not boarded</span>
          <?php endif; ?>
        </td>
        <?php if (Auth::mayCancelBooking($b) && in_array($b['status'], ['pending', 'confirmed'], true) && count($b['passengers']) > 1): ?>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('Cancel seat <?= Security::e(Seats::displayLabel((string) $p['seat_no'], 'sleeper', (string) ($b['booking_mode'] ?? 'sharing'))) ?> (<?= Security::e((string) $p['full_name']) ?>)? The seat will be released and the proportional refund slab applied.')">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="cancel_seat">
            <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
            <input type="hidden" name="seat_no" value="<?= Security::e((string) $p['seat_no']) ?>">
            <input type="hidden" name="reason" value="Per-seat cancel by admin">
            <button class="btn bad" type="submit" style="font-size:11px;padding:4px 10px;white-space:nowrap"><?= bv_cancel_is_request() ? '✕ Request seat cancel' : '✕ Cancel seat' ?></button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($ticket !== null && (int) ($ticket['scan_count'] ?? 0) > 0): ?>
    <div style="padding:10px 18px;font-size:13px;color:var(--mut);border-top:1px solid var(--line)">
      Ticket scanned <?= (int) $ticket['scan_count'] ?> time<?= (int) $ticket['scan_count'] !== 1 ? 's' : '' ?>
      <?php if (!empty($ticket['scanned_at'])): ?>
        — last at <?= Security::e(formatDate($ticket['scanned_at'], 'j M Y g:i A')) ?>
        <?php if (!empty($ticket['scanned_by_name'])): ?> by <?= Security::e((string) $ticket['scanned_by_name']) ?><?php endif; ?>
      <?php endif; ?>
      <?php if ((int) ($ticket['is_void'] ?? 0) === 1): ?>
        <span class="pill" style="background:#f7dcdc;color:#8a1f1f;margin-left:6px">VOID</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php /* ---- Other tickets on this contact number (17 Sep 2026) — query above ---- */ ?>
<?php if ($siblings !== []): ?>
<div class="panel">
  <h2><svg class="a-ic"><use href="#a-phone"/></svg> Other tickets on this number
    <span class="muted" style="font-weight:500;font-size:13px">· <?= count($siblings) ?><?= count($siblings) === 20 ? '+' : '' ?> · <span class="mono"><?= Security::e((string) $b['contact_phone']) ?></span></span>
    <a class="btn ghost sm" style="margin-left:auto" href="<?= $base ?>/admin/passengers.php?phone=<?= urlencode((string) $b['contact_phone']) ?>"><svg class="a-ic"><use href="#a-id-card"/></svg> Passenger register</a>
  </h2>
  <div class="tbl-scroll">
  <table>
    <thead><tr><th>PNR</th><th>Travel date</th><th>Route</th><th>Seats</th><th>Pax</th><th>Amount</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($siblings as $sb): $sbSeats = array_values(array_filter(explode(',', (string) ($sb['seats'] ?? '')))); ?>
      <tr>
        <td class="mono"><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $sb['pnr']) ?>"><?= Security::e((string) $sb['pnr']) ?></a></td>
        <td><?= !empty($sb['travel_date']) ? Security::e(formatDate((string) $sb['travel_date'])) : '<span class="muted">—</span>' ?></td>
        <td><?= Security::e(($sb['from_city'] ?? '—') . ' → ' . ($sb['to_city'] ?? '—')) ?></td>
        <td class="mono"><?= $sbSeats !== [] ? Security::e(Seats::displayLabels($sbSeats, (string) ($sb['coach_type'] ?: 'sleeper'), (string) ($sb['booking_mode'] ?: 'sharing'))) : '<span class="muted">—</span>' ?></td>
        <td><?= (int) $sb['pax'] ?></td>
        <td><?= Security::e(inr((float) $sb['total_amount'])) ?></td>
        <td><?= admin_pill((string) $sb['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if (Auth::can('bookings.edit') && in_array($b['status'], ['pending', 'confirmed'], true)):
  $editGate = !empty($leg['schedule_id']) ? TripStatus::editableFor((int) $leg['schedule_id']) : ['editable' => true, 'label' => ''];
?>
<div class="panel" id="edit">
  <h2>📎 Documents &amp; payment proof</h2>
  <?php /* 15 Oct 2026: the edit form, Change seat and Payment record moved into
       the Quick Edit card at the top (#quick) — prefilled, one screen. This
       panel keeps the passenger documents and the office proof upload. */ ?>
  <p class="muted" style="margin:10px 18px 0;font-size:12.5px">Passenger details, seat and payment are corrected in <a href="#quick">✏️ Quick Edit</a> at the top.<?php if (!($editGate['editable'] ?? true)): ?> ⚠️ This trip is <b><?= Security::e((string) ($editGate['label'] ?? 'closed')) ?></b> — seat changes are locked<?= Auth::isSuperadmin() ? ' (super-admin override applies)' : '' ?>.<?php endif; ?></p>

  <?php /* ---- Passenger photos & ID documents (17 Sep 2026) ----------------
       Kept OUTSIDE the edit form above on purpose: a file input needs
       enctype="multipart/form-data" and its own submit, and nesting forms
       is invalid HTML — so the edit form's parsing stays exactly as it was.
       One small upload form and one tiny remove form per passenger. The
       thumbnails come through admin/passenger-doc.php (gated), never from
       the upload path. Hidden entirely while the office switch is off. */ ?>
  <?php if ($paxDocsOn): ?>
  <div style="border-top:1px solid var(--line);padding:14px 18px" id="paxdocs">
    <h3 style="margin:0 0 4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap"><svg class="a-ic"><use href="#a-camera"/></svg> Passenger photos &amp; ID documents<?php if ($paxDocsTotal > 0): ?> <span class="pill st-info nodot"><?= $paxDocsTotal ?> on file</span><?php endif; ?></h3>
    <?php if (!$paxDocsAvail): ?>
      <p class="muted" style="margin:0;font-size:12.5px">Not set up on this database yet — run <span class="mono">database/upgrade-2026-09-passenger-documents.sql</span> once and this block comes alive.</p>
    <?php else: ?>
    <p class="muted" style="margin:0 0 10px;font-size:12.5px">A face photo for the manifest and the front / back of the ID for the border. Stored privately: never printed on the ticket, never on a public link — only signed-in staff can open them. JPG, PNG or WEBP (PDF scans for IDs), up to <?= PassengerDocs::maxMb() ?> MB each.</p>
    <?php foreach (($b['passengers'] ?? []) as $pax): $pdList = $paxDocs[(int) $pax['id']] ?? []; ?>
    <div class="pdoc-pax">
      <div class="pdoc-who">
        <span class="mono"><?= Security::e(Seats::displayLabel((string) $pax['seat_no'], 'sleeper', (string) ($b['booking_mode'] ?? 'sharing'))) ?></span><?= Security::e((string) $pax['full_name']) ?>
        <small><?= Security::e(trim((string) ($pax['id_type'] ?? '') . ' ' . (string) ($pax['id_number'] ?? '')) ?: 'no ID number on file') ?></small>
      </div>
      <div class="pdoc-strip">
        <?php if ($pdList === []): ?>
          <span class="muted" style="font-size:12px;align-self:center">No photo or document yet.</span>
        <?php endif; ?>
        <?php foreach ($pdList as $pd): $pdUrl = $base . '/admin/passenger-doc.php?id=' . (int) $pd['id']; $pdLabel = PassengerDocs::label((string) $pd['kind']); ?>
        <div class="pdoc">
          <a class="pdoc-th" href="<?= Security::e($pdUrl) ?>" target="_blank" rel="noopener" title="<?= Security::e($pdLabel . ' · ' . fileSizeLabel((int) $pd['file_size']) . ' · ' . formatDate((string) $pd['created_at'], 'j M Y g:i A')) ?>">
            <?php if (PassengerDocs::isImage($pd)): ?>
              <img src="<?= Security::e($pdUrl) ?>" alt="<?= Security::e($pdLabel) ?>" loading="lazy">
            <?php else: ?>
              <svg class="a-ic lg"><use href="#a-pdf"/></svg>
            <?php endif; ?>
          </a>
          <span class="pill st-info nodot"><?= Security::e($pdLabel) ?></span>
          <div class="pdoc-acts">
            <a href="<?= Security::e($pdUrl . '&dl=1') ?>" title="Download"><svg class="a-ic sm"><use href="#a-download"/></svg></a>
            <form method="post" onsubmit="return confirm('Remove this document? The file is deleted.')">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="remove_pax_doc">
              <input type="hidden" name="doc_id" value="<?= (int) $pd['id'] ?>">
              <button type="submit" class="pdoc-x" title="Remove"><svg class="a-ic sm"><use href="#a-trash"/></svg></button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <form method="post" enctype="multipart/form-data" class="pdoc-up">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="upload_pax_doc">
        <input type="hidden" name="pax_id" value="<?= (int) $pax['id'] ?>">
        <select name="kind" aria-label="Document kind">
          <?php foreach (PassengerDocs::kinds() as $kk => $kl): ?><option value="<?= Security::e($kk) ?>"><?= Security::e($kl) ?></option><?php endforeach; ?>
        </select>
        <input type="file" name="doc" accept="image/jpeg,image/png,image/webp,application/pdf" required aria-label="Photo or document file">
        <button type="submit" class="btn ok sm"><svg class="a-ic"><use href="#a-upload"/></svg> Upload</button>
      </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php /* ---- Upload proof for the customer (6 Sep 2026) ----------------
       For the passenger who cannot upload from their own phone: a bad line
       at the counter, a feature phone, or a screenshot that arrived in the
       office WhatsApp instead of the app. Runs the same pipeline as the
       customer's own upload, so the file is validated and renamed the same
       way and the customer still gets the "proof received" message.
       Hidden once the booking is confirmed — there is nothing left to
       verify, and re-opening a settled sale is what Reject is for. */ ?>
  <?php if (Auth::can('payments.verify') && in_array((string) $b['status'], ['pending', 'rejected'], true)): ?>
  <div style="border-top:1px solid var(--line);padding:14px 18px">
    <h3 style="margin:0 0 4px">📎 Upload proof for the customer</h3>
    <p class="muted" style="margin:0 0 10px;font-size:12.5px">
      Use this when the passenger sent the screenshot to the office instead of
      the app. It goes into the verification queue exactly as theirs would, and
      is logged against your account.
    </p>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="upload_proof">
      <input type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf"
             style="padding:7px 9px;border:1px solid var(--line);border-radius:8px;max-width:100%">
      <label style="font-size:12.5px">Method
        <select name="proof_method" style="margin-left:4px;padding:7px 9px;border:1px solid var(--line);border-radius:8px">
          <?php foreach (['upi', 'esewa', 'bank', 'wallet', 'cash'] as $pm): ?>
            <option value="<?= $pm ?>" <?= ($pay['method'] ?? '') === $pm ? 'selected' : '' ?>><?= strtoupper($pm) ?></option>
          <?php endforeach; ?>
        </select></label>
      <input type="text" name="proof_utr" placeholder="UTR / txn reference" maxlength="60"
             style="padding:7px 9px;border:1px solid var(--line);border-radius:8px;min-width:170px">
      <input type="text" name="proof_payer" placeholder="Paid by (optional)" maxlength="120"
             style="padding:7px 9px;border:1px solid var(--line);border-radius:8px;min-width:150px">
      <button type="submit" class="btn ok">📎 Record proof</button>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php /* ---- FRAUD: Risk & review history (9 Oct 2026) -------------------
     The payment risk score (PaymentRisk) with its reasons in words and the
     other PNRs it points at, plus every review decision kept with what the
     reviewer saw (payment_reviews). Advisory: nothing here rejects or
     cancels; the approve form below asks for the checks the level needs. */
  $bvRisk = null; $bvReviews = []; $bvFirst = null;
  if (Auth::can('payments.view') && !empty($b['id'])) {
      try {
          require_once INCLUDE_PATH . '/paymentrisk.php';
          $bvRisk    = PaymentRisk::score((int) $b['id']);
          $bvReviews = PaymentRisk::reviewsFor((int) $b['id'], 20);
          $bvFirst   = PaymentRisk::openFirstApproval((int) $b['id']);
      } catch (Throwable $e) { $bvRisk = null; }
  }
  $bvLvl = (string) ($bvRisk['level'] ?? 'low');
?>
<?php if ($bvRisk !== null && ($bvRisk['level'] !== 'skip' || $bvReviews !== [])): ?>
<style>
.rv-lv-high{--rv:var(--danger);--rv-bg:var(--danger-soft)}.rv-lv-medium{--rv:var(--warning);--rv-bg:var(--warning-soft)}.rv-lv-low{--rv:var(--success);--rv-bg:var(--success-soft)}.rv-lv-skip{--rv:var(--muted);--rv-bg:var(--surface-sunken)}
.rv-panel{box-shadow:inset 6px 0 0 var(--rv),var(--shadow-1) !important}
.rv-body{padding:4px 16px 14px 20px}
.rv-chip{display:inline-flex;align-items:baseline;gap:6px;font-weight:800;font-size:13px;padding:5px 12px;border-radius:999px;color:var(--rv);background:var(--rv-bg)}
.rv-list{list-style:none;margin:10px 0 0;padding:0;display:flex;flex-direction:column;gap:6px}
.rv-list li{display:flex;gap:8px;align-items:flex-start;font-size:13px;line-height:1.4}
.rv-list .w{flex:0 0 auto;min-width:38px;text-align:center;font:800 11px/1.6 var(--f-mono);border-radius:6px;padding:0 5px;color:var(--danger);background:var(--danger-soft)}
.rv-list .w.neg{color:var(--success);background:var(--success-soft)}
.rv-list small{display:block;color:var(--muted);font-size:12px}
.rv-tl{list-style:none;margin:14px 0 0;padding:0 0 0 14px;border-left:2px solid var(--line-strong)}
.rv-tl li{position:relative;margin:0 0 10px;font-size:12.5px;line-height:1.45}
.rv-tl li::before{content:'';position:absolute;left:-20px;top:4px;width:10px;height:10px;border-radius:50%;background:var(--royal);box-shadow:0 0 0 3px var(--surface)}
.rv-tl li.st-reject::before{background:var(--danger)}.rv-tl li.st-hold::before{background:var(--warning)}.rv-tl li.st-approve::before{background:var(--success)}
.rv-tl b{font-size:13px}
.rv-two{margin-top:10px;padding:10px 12px;border-radius:12px;background:var(--royal-tint);font-size:13px}
</style>
<div class="panel rv-panel rv-lv-<?= Security::e($bvLvl) ?>" id="riskPanel">
  <h2>🛡️ Risk &amp; review history · जोखिम</h2>
  <div class="rv-body">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <span class="rv-chip"><?= Security::e(['high' => 'HIGH RISK', 'medium' => 'MEDIUM RISK', 'low' => 'LOW RISK', 'skip' => 'NOT SCORED'][$bvLvl] ?? strtoupper($bvLvl)) ?> <small><?= (int) $bvRisk['score'] ?></small></span>
    <?php if (!empty($bvRisk['bankSigned'])): ?><span class="rv-chip rv-lv-low">🏦 Bank-verified (ICICI)</span><?php endif; ?>
    <span class="muted" style="font-size:12.5px">
      <?php if ($bvLvl === 'skip'): ?><?= $bvRisk['skip'] === 'cod' ? 'Cash on delivery — collected at boarding.' : 'Seller sale — the money was taken at the desk.' ?>
      <?php elseif ($bvLvl === 'high'): ?>Approve only after the bank statement check and a written reason<?= PaymentRisk::twoStepOn() ? ', plus a second person' : '' ?>.
      <?php elseif ($bvLvl === 'medium'): ?>Approve only after ticking the bank-statement check.
      <?php else: ?>No strong warning signs — still check the amount.<?php endif; ?>
    </span>
  </div>
  <?php if ($bvRisk['reasons'] !== []): ?>
    <ul class="rv-list">
      <?php foreach ($bvRisk['reasons'] as $rs): $w = (int) $rs['weight']; ?>
        <li><span class="w<?= $w < 0 ? ' neg' : '' ?>"><?= $w > 0 ? '+' . $w : $w ?></span><span><?= Security::e((string) $rs['label']) ?> · <span lang="ne"><?= Security::e((string) $rs['labelNe']) ?></span>
          <small><?= Security::e((string) $rs['detail']) ?><?php foreach ((array) $rs['pnrs'] as $op): ?> <a class="mono" href="<?= $base ?>/admin/booking-view.php?pnr=<?= rawurlencode((string) $op) ?>"><?= Security::e((string) $op) ?></a><?php endforeach; ?></small></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if ($bvFirst !== null): ?>
    <div class="rv-two">👥 <b>First approval</b> by <?= Security::e((string) (($bvFirst['full_name'] ?? '') ?: ($bvFirst['username'] ?? ''))) ?> · <?= Security::e(timeAgo((string) $bvFirst['created_at'])) ?>.
      A super-admin, manager or accountant who is not them gives the second approval below (within 24 h).</div>
  <?php endif; ?>
  <?php if ($bvReviews !== []): ?>
    <ul class="rv-tl" aria-label="Review history">
      <?php foreach ($bvReviews as $rv): $st = (string) $rv['step']; ?>
        <li class="st-<?= Security::e($st === 'approve1' ? 'approve' : $st) ?>">
          <b><?= Security::e(['approve1' => '👥 1st approval', 'approve' => '✓ Approved', 'reject' => '✕ Rejected', 'hold' => '⏸ Asked for clearer proof', 'escalate' => '⚑ Escalated'][$st] ?? $st) ?></b>
          · <?= Security::e((string) (($rv['full_name'] ?? '') ?: ($rv['username'] ?? 'system'))) ?><?= !empty($rv['admin_role']) ? ' (' . Security::e((string) $rv['admin_role']) . ')' : '' ?>
          · <span class="muted"><?= Security::e(formatDate((string) $rv['created_at'], 'j M, g:i A')) ?> · via <?= Security::e((string) ($rv['via'] ?? '')) ?></span><br>
          <span class="muted">Risk then: <?= Security::e(strtoupper((string) $rv['risk_level'])) ?> <?= (int) $rv['risk_score'] ?><?= !empty($rv['risk_codes']) ? ' · ' . Security::e((string) $rv['risk_codes']) : '' ?><?= (int) $rv['bank_checked'] === 1 ? ' · bank statement checked' : '' ?><?= $rv['amount_seen'] !== null ? ' · saw ' . Security::e(inr((float) $rv['amount_seen'])) : '' ?><?= !empty($rv['reason_code']) ? ' · reason ' . Security::e((string) $rv['reason_code']) : '' ?></span>
          <?php if (!empty($rv['note'])): ?><br>“<?= Security::e((string) $rv['note']) ?>”<?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ((Auth::can('payments.verify') || Auth::can('payments.reject')) && in_array($b['status'], ['pending', 'confirmed', 'rejected'], true)): ?>
<div class="panel">
  <h2>Payment review</h2>
  <p class="muted" style="margin-top:-6px">
    Booking is <?= admin_pill((string) $b['status']) ?> · payment <?= admin_pill((string) ($pay['status'] ?? 'pending')) ?>.
    You can change this decision at any time — every change is logged and the customer's ticket re-locks or unlocks on their next refresh.
  </p>
  <?php if ((int) ($b['is_cod'] ?? 0) === 1 && ($pay['status'] ?? '') === 'cod_pending'): ?>
    <div style="background:#fff4d1;border:1px solid #f0d48a;border-radius:10px;padding:12px 14px;margin-bottom:12px">
      <b style="font-size:13.5px">💵 Cash on delivery — <?= Security::e(inr((float) $b['total_amount'])) ?> still to collect</b>
      <p class="muted" style="font-size:12.5px;margin:4px 0 10px">
        The ticket is already valid; the fare is collected in cash at the boarding counter.
        Record it here once you have the money — that settles the payment and posts the agent commission and loyalty points.
      </p>
      <?php if (Auth::can('payments.verify')): ?>
        <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"
              onsubmit="return confirm('Record <?= Security::e(inr((float) $b['total_amount'])) ?> cash as collected for this booking?')">
          <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
          <input type="text" name="note" placeholder="Who collected it / receipt no. (optional)"
                 style="flex:1;min-width:200px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
          <button class="btn ok" type="submit" name="action" value="cod_settle">💵 Cash collected</button>
        </form>
      <?php else: ?>
        <p class="muted" style="font-size:12.5px;margin:0">Your role cannot settle payments.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="row-actions" style="gap:10px;flex-wrap:wrap;align-items:center">
    <?php if (Auth::can('payments.verify') && in_array($b['status'], ['pending', 'rejected'], true)): ?>
      <?php $bvNeedTick = in_array($bvLvl, ['medium', 'high'], true); $bvNeedNote = $bvLvl === 'high'; ?>
      <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"
            onsubmit="return confirm('Approve this payment and issue the ticket? Any released seats will be re-claimed.')">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="decision" value="approve">
        <input type="text" name="amount_seen" inputmode="decimal" placeholder="Amount seen ₹ (optional)" style="width:170px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
        <input type="text" name="note" <?= $bvNeedNote ? 'required minlength="4"' : '' ?> placeholder="<?= $bvNeedNote ? 'Why approve? (required — HIGH risk)' : 'Note (optional)' ?>" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;min-width:200px">
        <label style="display:inline-flex;gap:6px;align-items:center;font-size:12.5px;font-weight:700;min-height:40px"><input type="checkbox" name="bank_checked" value="1" <?= $bvNeedTick ? 'required' : '' ?> style="width:20px;height:20px"> I checked the bank statement<?= $bvNeedTick ? ' (required)' : '' ?></label>
        <button class="btn ok" type="submit" name="action" value="review"><?= $bvFirst !== null ? '✓ Give 2nd approval' : '✓ Approve payment' ?></button>
      </form>
      <?php if ($b['status'] === 'pending'): ?>
      <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="decision" value="hold">
        <input type="text" name="note" placeholder="Add a line to the message (optional)" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
        <button class="btn warn" type="submit" name="action" value="review" title="WhatsApp the customer for a clearer proof — nothing else changes">⏸ Hold — ask for clearer proof</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (Auth::can('payments.reject') && in_array($b['status'], ['pending', 'confirmed'], true)): ?>
      <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"
            onsubmit="return confirm('Reject this payment and release its seats? The customer is notified and their ticket re-locks.')">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="decision" value="reject">
        <select name="reason_code" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
          <option value="">Reason…</option>
          <?php foreach ((class_exists('PaymentRisk') ? PaymentRisk::REASONS : []) as $rcK => $rcV): ?><option value="<?= Security::e($rcK) ?>"><?= Security::e($rcV[0]) ?></option><?php endforeach; ?>
        </select>
        <input type="text" name="note" placeholder="Extra note (shown to customer)" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
        <button class="btn bad" type="submit" name="action" value="review">✕ Reject payment</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($b['status'] === 'confirmed'): ?>
    <p class="muted" style="font-size:12px;margin-top:10px">⚠️ Rejecting a confirmed booking automatically releases the seats, re-locks the customer's ticket, refunds their redeemed loyalty points and reverses the counter agent's wallet commission (reinstated if you approve again). It does <strong>not</strong> void the already-issued ticket PDF or any referral commission payout — check those by hand.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="toolbar">
  <?php if ($b['status'] === 'confirmed'): ?>
    <a class="btn" href="<?= Security::e(Ticket::imageUrl($pnr)) ?>" target="_blank" rel="noopener">🎟️ Ticket PNG</a>
    <a class="btn ghost" href="<?= Security::e(Ticket::downloadUrl($pnr)) ?>" target="_blank">⬇️ Ticket PDF</a>
    <a class="btn ghost" href="<?= Security::e(Ticket::downloadUrl($pnr, true)) ?>" target="_blank">⬇️ Invoice</a>
    <?php if (Auth::can('payments.verify')): ?>
      <form method="post" style="display:inline-flex;align-items:center;gap:8px" onsubmit="return confirm('Re-send the ticket to <?= Security::e((string) $b['contact_phone']) ?> on WhatsApp?')">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <button class="btn ok" type="submit" name="action" value="resend_wa">📲 Resend WhatsApp ticket</button>
        <?php if ($waPillLabel !== null): ?>
          <span class="wa-pill wa-<?= Security::e($waPillLabel[0]) ?>"
                title="<?= Security::e($waPillLabel[1] . ' · ' . $waPillLabel[2] . ($waPillLabel[3] !== '' ? ' · ' . $waPillLabel[3] : '')) ?>">
            <?= Security::e($waPillLabel[1]) ?>
            <small><?= Security::e(date('j M · g:i A', strtotime((string) $waPillLabel[2]))) ?></small>
          </span>
        <?php else: ?>
          <span class="wa-pill wa-muted" title="No WhatsApp message has been sent for this booking yet.">⏳ WA not sent yet</span>
        <?php endif; ?>
      </form>
    <?php elseif (Notify::usablePhone($b['contact_phone'] ?? '') !== ''): ?>
      <?php /* 15 Oct 2026 (1F-resend): roles without payments.verify (agents) send
           through the WhatsApp composer — admin/api/wa-send.php scopes an agent
           to their own sales, so this never offers a ticket it would refuse. */ ?>
      <?= admin_wa_button('booking_ticket', ['pnr' => $pnr], '📲 Send ticket on WhatsApp', ['class' => 'btn ok']) ?>
    <?php endif; ?>
    <button type="button" class="btn ghost" data-share-url="<?= Security::e(Ticket::imageUrl($pnr)) ?>" data-share-title="<?= Security::e('Ticket ' . $pnr) ?>">🔗 Share ticket</button>
  <?php elseif ($b['status'] === 'pending'): ?>
    <a class="btn ok" href="<?= $base ?>/admin/payments.php">Go to verification queue →</a>
  <?php endif; ?>

  <?php /* Change date / trip — same-route date move that keeps the PNR /
           payment / commission (vs cancel + rebook). Same door as
           reschedule.php since 17 Sep 2026: booking-edit OR schedule-edit OR
           superadmin, so the counter desk (bookings.edit) and the selling
           agent (schedules.edit) both see it — the engine scopes an agent to
           their own sales and the page re-checks trip state, so a departed
           trip is refused there. It used to demand schedules.manage, which
           only managers hold, so the counter never saw the button. */ ?>
  <?php if (in_array($b['status'], ['pending', 'confirmed'], true) && (Auth::can('bookings.edit') || Auth::can('schedules.edit') || Auth::isSuperadmin())): ?>
    <a class="btn ghost" href="<?= $base ?>/admin/reschedule.php?pnr=<?= urlencode($pnr) ?>">📅 Change date / trip</a>
  <?php endif; ?>

  <?php /* Refund desk — carries the PNR across so the operator does not have to
           re-find it there (refunds.php highlights + scrolls to the row). */ ?>
  <?php if (Auth::can('refunds.view')): ?>
    <a class="btn ghost" href="<?= $base ?>/admin/refunds.php?pnr=<?= urlencode($pnr) ?>">💸 Refund desk</a>
  <?php endif; ?>

  <?php /* Every traveller booked from this number, across PNRs (17 Sep 2026). */ ?>
  <?php if (Notify::usablePhone($b['contact_phone'] ?? '') !== ''): ?>
    <a class="btn ghost" href="<?= $base ?>/admin/passengers.php?phone=<?= urlencode((string) $b['contact_phone']) ?>">🧍 Passengers on this number</a>
  <?php endif; ?>

  <?php /* Missed bus (Point 11) — 24h grace rebooking onto the next same-route
           service, keeps PNR / payment / commission. Reachable by managers,
           superadmin AND the SELLING AGENT (schedules.edit) so a late entry can
           be made under the agent's own login; the page enforces the window. */ ?>
  <?php if (in_array($b['status'], ['pending', 'confirmed'], true) && (Auth::can('bookings.edit') || Auth::can('schedules.edit') || Auth::isSuperadmin())): ?>
    <a class="btn ghost" href="<?= $base ?>/admin/missed-bus.php?pnr=<?= urlencode($pnr) ?>">🚌 Missed bus — rebook (24h)</a>
  <?php endif; ?>

  <?php if (in_array($b['status'], ['pending', 'confirmed'], true) && Auth::mayCancelBooking($b)):
    $bvCancelReq = bv_cancel_is_request();   // 15 Oct 2026: agent_cancel_mode = request ?>
    <form method="post" class="row-actions" id="cancel" style="margin-left:auto"
          onsubmit="return confirm('<?= $bvCancelReq ? 'Send a cancel request to the office? They cancel it and settle any refund by hand.' : 'Cancel this booking? Seats will be released and the refund slab applied.' ?>')">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="text" name="reason" placeholder="Reason" <?= $bvCancelReq ? 'required minlength="3"' : '' ?> style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
      <button class="btn bad" type="submit" name="action" value="cancel"><?= $bvCancelReq ? 'Request cancellation' : 'Cancel booking' ?></button>
    </form>
  <?php endif; ?>
</div>

<?php
/* ========================================================================
 *  Message delivery history — every SMS / WhatsApp / email attempt this
 *  booking generated, so the operator does not need to leave this page to
 *  answer "did the ticket reach the customer?". Collapsed by default (the
 *  pill next to the resend button already answers the common question);
 *  opens to a compact table with provider, recipient, status, error.
 * ======================================================================== */
if ($msgLogs !== []): ?>
<details class="msg-log">
  <summary>Message log · <?= count($msgLogs) ?> attempt<?= count($msgLogs) === 1 ? '' : 's' ?></summary>
  <table>
    <thead>
      <tr><th>Time</th><th>Channel</th><th>Provider</th><th>To</th><th>Status</th><th>Notes</th></tr>
    </thead>
    <tbody>
      <?php foreach ($msgLogs as $m): ?>
        <tr>
          <td><?= Security::e(date('j M · g:i A', strtotime((string) $m['created_at']))) ?></td>
          <td><?= Security::e(strtoupper((string) $m['channel'])) ?></td>
          <td><?= Security::e((string) $m['provider']) ?></td>
          <td><?= Security::e((string) $m['to_number']) ?></td>
          <td><span class="badge <?= Security::e((string) $m['status']) ?>"><?= Security::e((string) $m['status']) ?></span></td>
          <td class="muted" title="<?= Security::e((string) ($m['provider_ref'] ?: '')) ?>">
            <?= Security::e((string) ($m['error'] ?: ($m['provider_ref'] ? 'ref: ' . $m['provider_ref'] : ''))) ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</details>
<?php endif; ?>

<?php
/* ========================================================================
 *  1b. Audit Trail Panel
 *
 *  PDO on this system does NOT support reusing named placeholders.
 *  Every placeholder name must be unique.
 * ======================================================================== */
$bookingId = (int) $b['id'];

/* The payment and ticket rows this booking owns. Read as INTEGERS and
   compared as integers below.
   16 Oct 2026: this used to match audit_logs.entity_id (VARCHAR,
   utf8mb4_unicode_ci) against CAST(id AS CHAR), which carries the
   connection collation — utf8mb4_general_ci on a stock MariaDB. The
   server then refused the whole statement with "Illegal mix of collations
   ... for operation '='" (SQLSTATE HY000, 1267), the catch below swallowed
   it, and the office was shown "No audit entries recorded for this
   booking" on EVERY booking, however much history it had. Comparing a
   VARCHAR column to integer literals coerces the column to a number, so no
   collation is involved at all. */
$ownIds = [];
foreach (['payment' => 'payments', 'ticket' => 'tickets'] as $kind => $table) {
    $ownIds[$kind] = [];
    try {
        foreach (Database::fetchAll("SELECT id FROM `$table` WHERE booking_id = :b", ['b' => $bookingId]) as $r) {
            $ownIds[$kind][] = (int) $r['id'];
        }
    } catch (Throwable $e) {
        Logger::error('booking-view: could not list ' . $table . ' for the audit trail', ['booking' => $pnr, 'err' => $e->getMessage()], 'admin');
    }
}
$auditLogs = [];
try {
    $inList = static fn(array $ids): string => $ids === [] ? 'NULL' : implode(',', array_map('intval', $ids));   // ints only - safe to inline
    $auditLogs = Database::fetchAll(
        "SELECT al.* FROM audit_logs al
          WHERE (al.entity_type = 'booking' AND al.entity_id = :pnr1)
             OR (al.entity_type = 'booking' AND al.entity_id = :bid1)
             OR (al.entity_type = 'payment' AND al.entity_id IN (" . $inList($ownIds['payment']) . "))
             OR (al.entity_type = 'ticket'  AND al.entity_id IN (" . $inList($ownIds['ticket']) . "))
         ORDER BY al.created_at ASC",
        [
            'pnr1' => $pnr,
            'bid1' => (string) $bookingId,
        ]
    );
} catch (Throwable $e) {
    /* Still informational, so the page stays up — but never silently: an
       empty Audit Trail has to be an empty history, not a broken query. */
    Logger::error('booking-view: the audit trail query failed', ['booking' => $pnr, 'err' => $e->getMessage()], 'admin');
    $auditLogs = [];
}
?>
<div class="panel" id="audit">
  <h2>📋 Audit Trail
    <?php if (Auth::can('dashboard.view')): ?><a class="btn ghost sm" style="margin-left:auto;font-size:12px" href="<?= $base ?>/admin/activity-log.php?booking=<?= urlencode($pnr) ?>">Full activity log →</a><?php endif; ?></h2>
  <?php if ($auditLogs === []): ?>
    <div style="padding:18px;color:var(--mut);font-size:14px">No audit entries recorded for this booking.</div>
  <?php else: ?>
    <div style="padding:18px">
      <div class="audit-timeline">
        <?php foreach ($auditLogs as $log): ?>
          <div class="audit-entry">
            <div class="audit-time"><?= Security::e(formatDate($log['created_at'] ?? '', 'j M Y H:i')) ?></div>
            <div class="audit-actor">
              <?= Security::e(strtoupper((string) ($log['actor_type'] ?? 'system'))) ?>
              <?php if (!empty($log['actor_name'])): ?>
                · <?= Security::e((string) $log['actor_name']) ?>
              <?php endif; ?>
            </div>
            <div class="audit-action"><?= Security::e((string) ($log['action'] ?? '')) ?></div>
            <?php if (!empty($log['detail'])): ?>
              <div class="audit-detail"><?= Security::e((string) $log['detail']) ?></div>
            <?php endif; ?>
            <?php /* 15 Oct 2026 (1G-summary): old → new for everyone who may edit
                 bookings (the counter sees what it changed); the IP stays super-admin. */ ?>
            <?php if (Auth::can('bookings.edit') && (!empty($log['old_value']) || !empty($log['new_value']))): ?>
                <div class="audit-detail" style="font-size:11px;margin-top:6px;overflow-wrap:anywhere">
                  <?php if (!empty($log['old_value'])): ?><span>Old: <?= Security::e(mb_substr((string) $log['old_value'], 0, 160)) ?></span><?php endif; ?>
                  <?php if (!empty($log['new_value'])): ?><span> → New: <?= Security::e(mb_substr((string) $log['new_value'], 0, 160)) ?></span><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (Auth::isSuperadmin()): ?>
              <?php if (!empty($log['ip_address'])): ?>
                <div class="audit-detail mono" style="font-size:11px">IP: <?= Security::e((string) $log['ip_address']) ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php
admin_footer();
