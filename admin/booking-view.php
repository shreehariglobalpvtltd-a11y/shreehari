<?php
/**
 * admin/booking-view.php?pnr=... — one booking in full.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/tripstatus.php';   // edit-window gate for seat changes (5 Sep 2026)
$admin = admin_boot('bookings.view');

$base  = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$pnr   = Security::clean($_GET['pnr'] ?? '', 40);
$flash = null;
$waManualLink = '';   // click-to-chat URL when no WhatsApp API is configured

/**
 * Drop the cached ticket + invoice PDFs so the next download regenerates
 * them from the edited booking (5 Sep 2026 — every edit path calls this;
 * before, only the passenger edit cleared the ticket and nothing cleared
 * the invoice).
 */
function bv_invalidate_pdfs(int $bookingId, string $pnr): void
{
    if (defined('TICKET_PATH')) {
        $p = TICKET_PATH . '/ticket_' . $pnr . '.pdf';
        if (is_file($p)) { @unlink($p); }
    }
    if (defined('INVOICE_PATH')) {
        $p = INVOICE_PATH . '/invoice_' . $pnr . '.pdf';
        if (is_file($p)) { @unlink($p); }
    }
    /* The PNG is the PRIMARY ticket now (5 Sep 2026) — the one WhatsApp links —
       and it carries the name, mobile, pickup and payment pill. Until 10 Sep it
       was left cached here, so an edited passenger kept receiving the OLD
       picture. Dropped with the PDFs: Ticket::pngPath() redraws on next open. */
    if (defined('TICKET_PATH')) {
        $p = TICKET_PATH . '/ticket_' . $pnr . '.png';
        if (is_file($p)) { @unlink($p); }
    }
    try {
        Database::update('tickets', ['pdf_path' => null, 'invoice_path' => null, 'png_path' => ''], 'booking_id = :b', ['b' => $bookingId]);
    } catch (Throwable $e) { /* no ticket row yet (pending) — nothing cached */ }
}

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

/** One row in message_logs for a staff-triggered resend (the delivery pill reads it). */
function bv_log_message(int $bookingId, string $to, string $status, string $provider, string $note): void
{
    try {
        Database::insert('message_logs', [
            'booking_id' => $bookingId, 'channel' => 'whatsapp', 'provider' => $provider,
            'to_number' => substr($to, 0, 32), 'body' => mb_substr('Ticket resend (admin)', 0, 1000),
            'status' => $status, 'provider_ref' => '', 'error' => $note !== '' ? mb_substr($note, 0, 255) : null,
        ]);
    } catch (Throwable $e) { /* message_logs is informational */ }
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
            $res = BookingService::cancel($pnr, $reason, false);
            $flash = ['ok', 'Booking cancelled. Refund: ' . inr((float) ($res['refund']['amount'] ?? 0))
                          . ' (' . (int) ($res['refund']['percent'] ?? 0) . '%).'];
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
            $res    = BookingService::cancelSeat((int) ($_POST['booking_id'] ?? 0), $seat, (int) $admin['id'], $reason);
            $flash  = ['ok', 'Seat ' . Security::e(Seats::displayLabel($seat)) . ' cancelled. Refund for this seat: '
                            . inr((float) ($res['refund']['amount'] ?? 0))
                            . ' (' . (int) ($res['refund']['percent'] ?? 0) . '%).'];
            $pnr = (string) ($res['booking']['pnr'] ?? $pnr);
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Edit passenger details (bookings.edit permission) -------------- */
/**
 * After an edit, tell the passenger (SHG AI BRAIN 1.6 "notify customer of the
 * change", 10 Sep 2026): the standard ticket WhatsApp, which links the PNG that
 * bv_invalidate_pdfs() just dropped — so the picture they receive is the
 * corrected one. Best effort: a WhatsApp outage never blocks the edit, and the
 * outcome is written to the audit trail either way (booking.edit_notify).
 * Off via settings notify_ticket_edit = 0.
 */
function bv_notify_edit(int $bookingId, string $pnr, string $what): string
{
    if (!Settings::getBool('notify_ticket_edit', true)) {
        return '';
    }
    try {
        $fresh = BookingService::detail($pnr);
        if ($fresh === null || (string) ($fresh['status'] ?? '') !== 'confirmed') {
            return '';
        }
        $r = Notify::resendTicketWhatsApp($fresh);
        Logger::audit('booking.edit_notify', 'booking', $pnr, null,
            ['what' => $what, 'ok' => (bool) ($r['ok'] ?? false), 'to' => (string) ($fresh['contact_phone'] ?? '')],
            (string) ($r['detail'] ?? ''));
        return ($r['ok'] ?? false)
            ? ' Passenger notified on WhatsApp with the updated ticket.'
            : ' (WhatsApp not sent: ' . truncate((string) ($r['detail'] ?? 'unknown'), 120) . ')';
    } catch (Throwable $e) {
        Logger::error('Edit notify failed: ' . $e->getMessage(), ['pnr' => $pnr]);
        return '';
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'edit_passenger') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            Auth::requireAdmin('bookings.edit');
            $bid = (int) ($_POST['booking_id'] ?? 0);
            $reason  = Security::clean($_POST['reason'] ?? '', 255);   // SHG AI BRAIN 1.6: why, in the desk's words
            $changes = [];
            $oldVals = [];   // 5 Sep 2026: every edit records what it was → what it is now
            $newVals = [];

            $cur = Database::fetch('SELECT id, pnr, contact_phone, contact_email FROM bookings WHERE id = :id', ['id' => $bid]);
            if ($cur === null || (string) $cur['pnr'] !== $pnr) {
                throw new RuntimeException('Booking not found.');
            }
            $curLeg = Database::fetch("SELECT boarding_stop, drop_stop FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound' LIMIT 1", ['b' => $bid]) ?? [];

            // Contact details
            $newPhone = preg_replace('/\D/', '', $_POST['contact_phone'] ?? '');
            $newEmail = Security::clean($_POST['contact_email'] ?? '', 120);
            $upd = ['updated_at' => date('Y-m-d H:i:s')];
            if ($newPhone !== '' && $newPhone !== (string) $cur['contact_phone']) {
                if (strlen($newPhone) < 10 || strlen($newPhone) > 15) { throw new RuntimeException('Enter a valid phone number (10-15 digits).'); }
                $upd['contact_phone'] = $newPhone; $changes[] = 'phone';
                $oldVals['contact_phone'] = $cur['contact_phone']; $newVals['contact_phone'] = $newPhone;
            }
            if ($newEmail !== '' && $newEmail !== (string) ($cur['contact_email'] ?? '')) {
                $upd['contact_email'] = $newEmail; $changes[] = 'email';
                $oldVals['contact_email'] = $cur['contact_email']; $newVals['contact_email'] = $newEmail;
            }

            // Boarding/drop stops
            $newBoard = Security::clean($_POST['boarding_stop'] ?? '', 191);
            $newDrop  = Security::clean($_POST['drop_stop'] ?? '', 191);
            if ($newBoard !== '' || $newDrop !== '') {
                $legUpd = [];
                if ($newBoard !== '' && $newBoard !== (string) ($curLeg['boarding_stop'] ?? '')) {
                    $legUpd['boarding_stop'] = $newBoard; $changes[] = 'boarding stop';
                    $oldVals['boarding_stop'] = $curLeg['boarding_stop'] ?? null; $newVals['boarding_stop'] = $newBoard;
                }
                if ($newDrop !== '' && $newDrop !== (string) ($curLeg['drop_stop'] ?? '')) {
                    $legUpd['drop_stop'] = $newDrop; $changes[] = 'drop stop';
                    $oldVals['drop_stop'] = $curLeg['drop_stop'] ?? null; $newVals['drop_stop'] = $newDrop;
                }
                if ($legUpd !== []) {
                    Database::update('booking_legs', $legUpd,
                        "booking_id = :b AND leg_type = 'outbound'",
                        ['b' => $bid]);
                }
            }

            if (count($upd) > 1) {
                Database::update('bookings', $upd, 'id = :id', ['id' => $bid]);
            }

            // Per-passenger updates (name / age / gender / ID document)
            foreach (($_POST['pax'] ?? []) as $paxId => $paxData) {
                $paxId = (int) $paxId;
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
                if ($pUpd !== []) {
                    Database::run(
                        "UPDATE booking_passengers SET " . implode(', ', array_map(fn($k) => "$k = :$k", array_keys($pUpd))) . " WHERE id = :id AND booking_id = :bid",
                        array_merge($pUpd, ['id' => $paxId, 'bid' => $bid])
                    );
                    $seatKey = (string) ($curPax['seat_no'] ?: ('#' . $paxId));

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
                    foreach ($pUpd as $fk => $fv) {
                        $oldVals['pax ' . $seatKey . ' ' . $fk] = $curPax[$fk] ?? null;
                        $newVals['pax ' . $seatKey . ' ' . $fk] = $fv;
                    }
                }
            }

            if ($changes === []) {
                $flash = ['ok', 'Nothing changed — the values you typed match the booking already.'];
            } else {
                /* The cached ticket PDF (and the invoice) still carry the OLD
                   boarding-stop / passenger name / phone; drop both caches so
                   the next download regenerates them with the edits above. */
                bv_invalidate_pdfs($bid, $pnr);

                Logger::audit('booking.edit_passenger', 'booking', $pnr, $oldVals, $newVals,
                    'Edited ' . implode(', ', $changes) . ' by admin #' . $admin['id'], $reason);
                $notice = bv_notify_edit($bid, $pnr, 'passenger details');
                $flash = ['ok', 'Booking details updated: ' . implode(', ', $changes) . '. Ticket PNG and PDF regenerate on next open.' . $notice];
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
            Auth::requireAdmin('bookings.edit');
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
            $coach = (string) (Database::scalar('SELECT r.coach_type FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :s', ['s' => $sid], 'sleeper') ?: 'sleeper');
            $fromSeat = strtoupper(Security::clean($_POST['from_seat'] ?? '', 10));
            $toSeat   = strtoupper(Security::clean($_POST['to_seat'] ?? '', 10));
            if (!in_array($fromSeat, array_map('strval', $legRow['seats'] ?? []), true)) {
                throw new RuntimeException('Seat ' . Seats::displayLabel($fromSeat, $coach, (string) ($b['booking_mode'] ?? 'sharing')) . ' is not on this booking.');
            }
            $res = Seats::transferSeat($sid, $fromSeat, $toSeat, $coach, (int) $admin['id']);
            Ticket::reissue((int) $b['id']);            // QR carries the seat list; PDFs regenerate
            bv_invalidate_pdfs((int) $b['id'], $pnr);
            Logger::audit('booking.seat_change', 'booking', $pnr, ['seat' => $res['from'] ?? $fromSeat], ['seat' => $res['to'] ?? $toSeat],
                'Seat changed on the ticket page by admin #' . $admin['id'], Security::clean($_POST['reason'] ?? '', 255));
            $notice = bv_notify_edit((int) $b['id'], $pnr, 'seat');
            $flash = ['ok', 'Seat changed ' . Seats::displayLabel((string) ($res['from'] ?? $fromSeat), $coach, (string) ($b['booking_mode'] ?? 'sharing')) . ' → ' . Seats::displayLabel((string) ($res['to'] ?? $toSeat), $coach, (string) ($b['booking_mode'] ?? 'sharing')) . '. Ticket QR, PNG and PDF re-issued.' . $notice];
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
            Auth::requireAdmin('payments.verify');
            $payId = (int) Database::scalar('SELECT COALESCE(MAX(id),0) FROM payments WHERE booking_id = :b', ['b' => (int) $b['id']], 0);
            if ($payId <= 0) { throw new RuntimeException('This booking has no payment record yet.'); }
            $cur = Database::fetch('SELECT method, utr_number, admin_note, status FROM payments WHERE id = :i', ['i' => $payId]) ?? [];
            $method = strtolower(trim((string) ($_POST['pay_method'] ?? '')));
            $utr    = Security::clean($_POST['utr_number'] ?? '', 60);
            $note   = Security::clean($_POST['pay_note'] ?? '', 255);
            $allowed = ['upi', 'esewa', 'cash', 'bank', 'wallet', 'cod'];
            $upd = []; $oldVals = []; $newVals = [];
            if ($method !== '' && in_array($method, $allowed, true) && $method !== (string) ($cur['method'] ?? '')) { $upd['method'] = $method; $oldVals['method'] = $cur['method'] ?? null; $newVals['method'] = $method; }
            if ($utr !== '' && $utr !== (string) ($cur['utr_number'] ?? '')) { $upd['utr_number'] = $utr; $oldVals['utr_number'] = $cur['utr_number'] ?? null; $newVals['utr_number'] = $utr; }
            if ($note !== '' && $note !== (string) ($cur['admin_note'] ?? '')) { $upd['admin_note'] = $note; $oldVals['admin_note'] = $cur['admin_note'] ?? null; $newVals['admin_note'] = $note; }
            if ($upd === []) {
                $flash = ['ok', 'Nothing changed on the payment.'];
            } else {
                Database::update('payments', $upd, 'id = :i', ['i' => $payId]);
                Logger::audit('payment.edit', 'payment', (string) $payId, $oldVals, $newVals, 'Payment details edited on ' . $pnr . ' by admin #' . $admin['id']);
                if (isset($upd['method'])) { bv_invalidate_pdfs((int) $b['id'], $pnr); }   // the invoice prints the method
                $flash = ['ok', 'Payment record updated (' . implode(', ', array_keys($upd)) . ').'];
            }
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
            bv_log_message((int) $b['id'], (string) $b['contact_phone'], $sendStatus, $waManualLink !== '' ? 'manual' : 'twilio', $r['ok'] ? '' : (string) $r['detail']);
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
            if ($decision === 'approve') {
                Auth::requireAdmin('payments.verify');
                $r = BookingService::confirm((int) $b['id'], (int) $admin['id'], $note);
                $flash = ['ok', 'Payment approved — ticket issued for ' . ($r['pnr'] ?? $pnr) . '.'];
            } elseif ($decision === 'reject') {
                Auth::requireAdmin('payments.reject');
                if ($note === '') { $note = 'Payment could not be verified.'; }
                $r = BookingService::reject((int) $b['id'], (int) $admin['id'], $note);
                $flash = ['ok', 'Booking ' . ($r['pnr'] ?? $pnr) . ' marked rejected — seats released.'];
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
            $newPhone = preg_replace('/\D/', '', $_POST['contact_phone'] ?? '');
            $newEmail = Security::clean($_POST['contact_email'] ?? '', 120);
            $newNote  = Security::clean($_POST['admin_note'] ?? '', 255);
            $upd = ['updated_at' => date('Y-m-d H:i:s')];
            $oldVals = []; $newVals = [];
            if ($newPhone !== '' && $newPhone !== (string) $b['contact_phone']) { $upd['contact_phone'] = $newPhone; $oldVals['contact_phone'] = $b['contact_phone']; $newVals['contact_phone'] = $newPhone; }
            if ($newEmail !== '' && $newEmail !== (string) ($b['contact_email'] ?? '')) { $upd['contact_email'] = $newEmail; $oldVals['contact_email'] = $b['contact_email'] ?? null; $newVals['contact_email'] = $newEmail; }
            if ($newNote  !== '') { $upd['admin_note'] = $newNote; $oldVals['admin_note'] = $b['admin_note'] ?? null; $newVals['admin_note'] = $newNote; }
            if (count($upd) > 1) {
                Database::update('bookings', $upd, 'id = :id', ['id' => $b['id']]);
            }
            // Update passengers if pax name provided
            foreach (($_POST['pax_name'] ?? []) as $paxId => $paxName) {
                $paxName = Security::clean((string) $paxName, 120);
                if ($paxName !== '') {
                    $was = (string) Database::scalar('SELECT full_name FROM booking_passengers WHERE id = :id AND booking_id = :bid', ['id' => (int) $paxId, 'bid' => $b['id']], '');
                    if ($was === $paxName) { continue; }
                    Database::run(
                        "UPDATE booking_passengers SET full_name=:n WHERE id=:id AND booking_id=:bid",
                        ['n' => $paxName, 'id' => (int) $paxId, 'bid' => $b['id']]
                    );
                    $oldVals['pax #' . (int) $paxId] = $was; $newVals['pax #' . (int) $paxId] = $paxName;
                }
            }
            if ($oldVals === [] && $newVals === []) {
                $flash = ['ok', 'Nothing changed.'];
            } else {
                bv_invalidate_pdfs((int) $b['id'], $pnr);   // the cached ticket/invoice carried the old name / phone
                Logger::audit('booking.edit_contact', 'booking', $pnr, $oldVals, $newVals, 'superadmin contact edit by admin #' . $admin['id'],
                    Security::clean($_POST['reason'] ?? '', 255));
                $notice = bv_notify_edit((int) $b['id'], $pnr, 'contact details');
                $flash = ['ok', 'Booking details updated. Ticket PNG and PDF regenerate on next open.' . $notice];
            }
            $b = BookingService::detail($pnr);
        } catch (Throwable $e) { $flash = ['bad', $e->getMessage()]; }
    }
}

/* ── Reassign the selling agent (requirement 3: change WHO sold a ticket,
      even after it exists — "ticket banisakepachhi pani agent assign/change
      garna milne"). Moves the sale AND its commission/cash to the new seller,
      atomically, and audit-logs the correction. 0 = detach to the office. ── */
if ($b !== null && $isSuperHere && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'reassign_agent') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired.'];
    } else {
        try {
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
                Database::transaction(static function () use ($bid, $b): void {
                    Seats::releaseBooking($bid);
                    Database::update('commissions', ['status' => 'void'], 'booking_id = :b', ['b' => $bid]);
                    AgentWallet::voidFor($b, 'booking hard-deleted');
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

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
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
if ($isSuperHere) {
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
.board-yes{color:#0a6b3b;font-weight:600}
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
.power-panel label{font-size:12px;font-weight:700;color:#555;margin-bottom:2px}
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
    <!-- WhatsApp passenger. Gated on usablePhone(), not on "not empty": the
         walk-in placeholder 0000000000 is not empty, so this button used to
         render as a live wa.me/910000000000 link and messaged a stranger in
         India about someone else's booking. -->
    <?php if (Notify::usablePhone($b['contact_phone'] ?? '') !== ''): ?>
    <a href="https://wa.me/91<?= preg_replace('/\D/', '', (string)$b['contact_phone']) ?>"
       target="_blank" class="btn btn-ghost btn-sm">💬 WhatsApp Passenger</a>
    <?php else: ?>
    <span class="btn btn-ghost btn-sm" style="opacity:.5;cursor:default" title="This walk-in has no phone on file">💬 No phone on file</span>
    <?php endif; ?>
  </div>

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
        <label>Admin Note (internal)</label>
        <textarea name="admin_note" rows="2" placeholder="Internal note — not shown to customer"></textarea>
      </div>
      <button type="submit" class="btn btn-ok btn-sm" style="width:auto">💾 Save Changes</button>
    </form>
  </details>

  <!-- Assign / change / add the selling agent (also on a customer online ticket) -->
  <details>
    <summary>🧑‍💼 Assign / change selling agent — add an agent to a customer ticket anytime (for settlement)</summary>
    <form method="post" class="sub-form"
          onsubmit="return confirm('Move this booking&#39;s sale — and its commission/cash — to the chosen seller?')">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="reassign_agent">
      <div>
        <label>Currently: <b><?= Security::e($currentSeller) ?></b>. Change seller to:</label>
        <select name="new_agent_id">
          <option value="0" <?= $soldById === 0 ? 'selected' : '' ?>>— Office / Online (no agent) —</option>
          <?php foreach ($reassignAgents as $ra): ?>
            <option value="<?= (int) $ra['id'] ?>" <?= (int) $ra['id'] === $soldById ? 'selected' : '' ?>>
              <?= Security::e(trim(($ra['code'] !== '' ? $ra['code'] . ' · ' : '') . ($ra['full_name'] ?: $ra['username']))) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <p style="font-size:12px;color:#8a6d00;margin:2px 0 6px">The old seller&#39;s commission is reversed and the new seller&#39;s is posted from the ledger — the change is audit-logged.</p>
      <button type="submit" class="btn btn-ok btn-sm" style="width:auto">🧑‍💼 Reassign seller</button>
    </form>
  </details>

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
        <button type="submit" class="btn btn-warn btn-sm" style="width:auto;background:#C00;border-color:#C00"
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
      <tr><th>UTR / Ref</th><td class="mono"><?= Security::e($pay['utr_number'] ?? '—') ?></td></tr>
      <?php if ($hasProof): ?>
      <tr><th>Payment proof</th><td><a class="btn ghost" style="font-size:12px;padding:5px 12px" href="/admin/screenshot.php?id=<?= (int) ($b['id'] ?? 0) ?>" target="_blank" rel="noopener">🖼️ View proof</a></td></tr>
      <?php endif; ?>
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
<div class="panel">
  <h2>Passengers</h2>
  <div class="tbl-scroll">
  <table>
    <thead><tr><th>Seat</th><th>Name</th><th>Age</th><th>Gender</th><th>Boarding</th><?php if (Auth::mayCancelBooking($b) && in_array($b['status'], ['pending', 'confirmed'], true) && count($b['passengers']) > 1): ?><th>Actions</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($b['passengers'] as $p): ?>
      <tr>
        <td class="mono"><?= Security::e(Seats::displayLabel((string) $p['seat_no'], 'sleeper', (string) ($b['booking_mode'] ?? 'sharing'))) ?></td>
        <td><?= Security::e((string) $p['full_name']) ?><?php if ((int) ($p['is_primary'] ?? 0) === 1): ?> <span class="pill" style="background:#e2ecfb;color:#1c3b72;font-size:10px;padding:1px 6px">Primary</span><?php endif; ?><?php if (!empty($p['special_need'])): ?> <span class="pill" style="background:#e6f4ea;color:#0f5c36;font-size:10px;padding:1px 6px" title="Priority boarding">🩺 <?= Security::e(ucfirst((string) $p['special_need'])) ?></span><?php endif; ?></td>
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
            <button class="btn bad" type="submit" style="font-size:11px;padding:4px 10px;white-space:nowrap">✕ Cancel seat</button>
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

<?php if (Auth::can('bookings.edit') && in_array($b['status'], ['pending', 'confirmed'], true)):
  $editGate = !empty($leg['schedule_id']) ? TripStatus::editableFor((int) $leg['schedule_id']) : ['editable' => true, 'label' => ''];
?>
<div class="panel" id="edit">
  <h2>✏️ Edit booking details</h2>
  <?php if (!($editGate['editable'] ?? true)): ?>
    <p class="muted" style="margin:10px 18px 0;font-size:12.5px">⚠️ This trip is <b><?= Security::e((string) ($editGate['label'] ?? 'closed')) ?></b> — passenger details can still be corrected for the record, but seat changes are locked<?= Auth::isSuperadmin() ? ' (super-admin override applies)' : '' ?>.</p>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="edit_passenger">
    <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
    <div class="edit-grid">
      <div class="edit-section">
        <h3>Contact</h3>
        <div class="edit-row">
          <label>Phone</label>
          <input type="tel" name="contact_phone" placeholder="<?= Security::e((string)$b['contact_phone']) ?>" maxlength="15">
        </div>
        <div class="edit-row">
          <label>Email</label>
          <input type="email" name="contact_email" placeholder="<?= Security::e((string)($b['contact_email'] ?? '')) ?>" maxlength="120">
        </div>
      </div>
      <div class="edit-section">
        <h3>Journey</h3>
        <div class="edit-row">
          <label>Boarding / चढ्ने ठाउँ</label>
          <input type="text" name="boarding_stop" placeholder="<?= Security::e($leg['boarding_stop'] ?? '—') ?>" maxlength="191">
        </div>
        <div class="edit-row">
          <label>Drop / ओर्लिने ठाउँ</label>
          <input type="text" name="drop_stop" placeholder="<?= Security::e($leg['drop_stop'] ?? '—') ?>" maxlength="191">
        </div>
      </div>
    </div>
    <h3 style="margin-top:16px">Passengers</h3>
    <?php foreach (($b['passengers'] ?? []) as $pax): ?>
    <div class="pax-edit-row">
      <span class="mono" style="min-width:40px"><?= Security::e(Seats::displayLabel((string) $pax['seat_no'], 'sleeper', (string) ($b['booking_mode'] ?? 'sharing'))) ?></span>
      <input type="text" name="pax[<?= (int)$pax['id'] ?>][name]" placeholder="<?= Security::e((string) $pax['full_name']) ?>" maxlength="120" style="flex:2">
      <input type="number" name="pax[<?= (int)$pax['id'] ?>][age]" placeholder="<?= $pax['age'] !== null ? (int) $pax['age'] : 'Age' ?>" min="1" max="120" style="width:60px">
      <select name="pax[<?= (int)$pax['id'] ?>][gender]" style="width:90px">
        <option value="">—</option>
        <option value="Male" <?= ($pax['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
        <option value="Female" <?= ($pax['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
        <option value="Other" <?= ($pax['gender'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
      </select>
      <input type="text" name="pax[<?= (int)$pax['id'] ?>][id_type]" placeholder="<?= Security::e((string) ($pax['id_type'] ?: 'ID type')) ?>" maxlength="60" style="width:110px" title="Aadhaar / Citizenship / Passport">
      <input type="text" name="pax[<?= (int)$pax['id'] ?>][id_number]" placeholder="<?= Security::e((string) ($pax['id_number'] ?: 'ID number')) ?>" maxlength="60" style="width:130px">
    </div>
    <?php endforeach; ?>
    <p class="muted" style="font-size:12px;margin:8px 0 0">Leave a box empty to keep its current value (shown as the placeholder). Every change is written to the audit trail below as old → new, and the ticket PDF regenerates on the next download.</p>
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="text" name="reason" maxlength="255" placeholder="Reason for the change (e.g. customer request, typo)" style="flex:1 1 260px;min-width:0;padding:8px 10px;border:1px solid var(--line);border-radius:8px" title="Written to the audit trail beside the old → new values">
      <button type="submit" class="btn ok" onclick="return confirm('Save these changes to the booking?')">💾 Save changes</button>
    </div>
  </form>

  <?php /* ---- Change seat (5 Sep 2026) ---- */
    $seatOptions = [];
    $seatErr = '';
    if (!empty($leg['schedule_id']) && !empty($leg['seats'])) {
        try {
            $rid  = (int) Database::scalar('SELECT route_id FROM schedules WHERE id = :s', ['s' => (int) $leg['schedule_id']], 0);
            $mode = ($b['booking_mode'] ?? 'sharing') === 'private' ? 'private' : 'sharing';
            $av   = Seats::availability($rid, (string) $leg['travel_date'], $mode);
            $seatOptions = $av['available'] ?? [];
        } catch (Throwable $e) { $seatErr = $e->getMessage(); }
    }
  ?>
  <?php if (!empty($leg['seats'])): ?>
  <div style="border-top:1px solid var(--line);padding:14px 18px">
    <h3 style="margin:0 0 8px">💺 Change seat</h3>
    <?php if ($seatErr !== ''): ?><p class="muted" style="font-size:12.5px">Seat map unavailable: <?= Security::e($seatErr) ?></p>
    <?php elseif (!($editGate['editable'] ?? true) && !Auth::isSuperadmin()): ?><p class="muted" style="font-size:12.5px">Locked — the trip is <?= Security::e((string) ($editGate['label'] ?? 'closed')) ?>.</p>
    <?php else: ?>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"
          onsubmit="return confirm('Move passenger from seat ' + this.from_seat.value + ' to ' + this.to_seat.value + '? The ticket QR and PDF are re-issued.')">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="change_seat">
      <label style="font-size:12.5px">From
        <select name="from_seat" style="margin-left:4px;padding:7px 9px;border:1px solid var(--line);border-radius:8px">
          <?php foreach ($leg['seats'] as $sn): ?><option value="<?= Security::e((string) $sn) ?>"><?= Security::e(Seats::displayLabel((string) $sn, 'sleeper', (string) ($mode ?? 'sharing'))) ?></option><?php endforeach; ?>
        </select></label>
      <label style="font-size:12.5px">To
        <select name="to_seat" style="margin-left:4px;padding:7px 9px;border:1px solid var(--line);border-radius:8px" required>
          <option value="">— free seat —</option>
          <?php foreach ($seatOptions as $sn): ?><option value="<?= Security::e((string) $sn) ?>"><?= Security::e(Seats::displayLabel((string) $sn, 'sleeper', (string) ($mode ?? 'sharing'))) ?></option><?php endforeach; ?>
        </select></label>
      <input type="text" name="reason" maxlength="255" placeholder="Reason (audit)" style="padding:7px 9px;border:1px solid var(--line);border-radius:8px;min-width:0;width:170px">
      <button type="submit" class="btn ok">💺 Move seat</button>
      <span class="muted" style="font-size:12px"><?= count($seatOptions) ?> free seat<?= count($seatOptions) === 1 ? '' : 's' ?> on this bus · double booking is blocked by the seat lock.</span>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php /* ---- Payment method / reference (5 Sep 2026) ---- */ ?>
  <?php if (Auth::can('payments.verify') && !empty($pay)): ?>
  <div style="border-top:1px solid var(--line);padding:14px 18px">
    <h3 style="margin:0 0 8px">💳 Payment record</h3>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="edit_payment">
      <label style="font-size:12.5px">Method
        <select name="pay_method" style="margin-left:4px;padding:7px 9px;border:1px solid var(--line);border-radius:8px">
          <?php foreach (['upi', 'esewa', 'cash', 'bank', 'wallet', 'cod'] as $pm): ?>
            <option value="<?= $pm ?>" <?= ($pay['method'] ?? '') === $pm ? 'selected' : '' ?>><?= strtoupper($pm) ?></option>
          <?php endforeach; ?>
        </select></label>
      <input type="text" name="utr_number" placeholder="<?= Security::e((string) ($pay['utr_number'] ?: 'UTR / txn reference')) ?>" maxlength="60" style="padding:7px 9px;border:1px solid var(--line);border-radius:8px;min-width:180px">
      <input type="text" name="pay_note" placeholder="Office note (optional)" maxlength="255" style="padding:7px 9px;border:1px solid var(--line);border-radius:8px;min-width:180px">
      <button type="submit" class="btn ok">💾 Save payment</button>
      <span class="muted" style="font-size:12px">Status is <?= admin_pill((string) ($pay['status'] ?? 'pending')) ?> — change it with Approve / Reject / Cash collected below.</span>
    </form>
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
      <form method="post" style="display:flex;gap:8px;align-items:center"
            onsubmit="return confirm('Approve this payment and issue the ticket? Any released seats will be re-claimed.')">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="decision" value="approve">
        <input type="text" name="note" placeholder="Note (optional)" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
        <button class="btn ok" type="submit" name="action" value="review">✓ Approve payment</button>
      </form>
    <?php endif; ?>
    <?php if (Auth::can('payments.reject') && in_array($b['status'], ['pending', 'confirmed'], true)): ?>
      <form method="post" style="display:flex;gap:8px;align-items:center"
            onsubmit="return confirm('Reject this payment and release its seats? The customer is notified and their ticket re-locks.')">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="decision" value="reject">
        <input type="text" name="note" placeholder="Reason (shown to customer)" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
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
    <?php endif; ?>
  <?php elseif ($b['status'] === 'pending'): ?>
    <a class="btn ok" href="<?= $base ?>/admin/payments.php">Go to verification queue →</a>
  <?php endif; ?>

  <?php /* Change date / trip — same-route date move that keeps the PNR /
           payment / commission (vs cancel + rebook). Manager + superadmin only
           (booking-edit AND schedule-management); the page re-checks trip
           state, so a departed trip is refused there. */ ?>
  <?php if (in_array($b['status'], ['pending', 'confirmed'], true) && Auth::can('bookings.edit') && Auth::can('schedules.manage')): ?>
    <a class="btn ghost" href="<?= $base ?>/admin/reschedule.php?pnr=<?= urlencode($pnr) ?>">📅 Change date / trip</a>
  <?php endif; ?>

  <?php /* Refund desk — carries the PNR across so the operator does not have to
           re-find it there (refunds.php highlights + scrolls to the row). */ ?>
  <?php if (Auth::can('refunds.view')): ?>
    <a class="btn ghost" href="<?= $base ?>/admin/refunds.php?pnr=<?= urlencode($pnr) ?>">💸 Refund desk</a>
  <?php endif; ?>

  <?php /* Missed bus (Point 11) — 24h grace rebooking onto the next same-route
           service, keeps PNR / payment / commission. Reachable by managers,
           superadmin AND the SELLING AGENT (schedules.edit) so a late entry can
           be made under the agent's own login; the page enforces the window. */ ?>
  <?php if (in_array($b['status'], ['pending', 'confirmed'], true) && (Auth::can('bookings.edit') || Auth::can('schedules.edit') || Auth::isSuperadmin())): ?>
    <a class="btn ghost" href="<?= $base ?>/admin/missed-bus.php?pnr=<?= urlencode($pnr) ?>">🚌 Missed bus — rebook (24h)</a>
  <?php endif; ?>

  <?php if (in_array($b['status'], ['pending', 'confirmed'], true) && Auth::mayCancelBooking($b)): ?>
    <form method="post" class="row-actions" style="margin-left:auto"
          onsubmit="return confirm('Cancel this booking? Seats will be released and the refund slab applied.')">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="text" name="reason" placeholder="Reason" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
      <button class="btn bad" type="submit" name="action" value="cancel">Cancel booking</button>
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

$auditLogs = [];
try {
    $auditLogs = Database::fetchAll(
        "SELECT al.* FROM audit_logs al
          WHERE (al.entity_type = 'booking' AND al.entity_id = :pnr1)
             OR (al.entity_type = 'booking' AND al.entity_id = :bid1)
             OR (al.entity_type = 'payment' AND al.entity_id IN (SELECT CAST(id AS CHAR) FROM payments WHERE booking_id = :bid2))
             OR (al.entity_type = 'ticket'  AND al.entity_id IN (SELECT CAST(id AS CHAR) FROM tickets  WHERE booking_id = :bid3))
         ORDER BY al.created_at ASC",
        [
            'pnr1' => $pnr,
            'bid1'  => (string) $bookingId,
            'bid2'  => $bookingId,
            'bid3'  => $bookingId,
        ]
    );
} catch (Throwable $e) {
    // Silently degrade — audit log is informational
    $auditLogs = [];
}
?>
<div class="panel">
  <h2>📋 Audit Trail</h2>
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
            <?php if (Auth::isSuperadmin()): ?>
              <?php if (!empty($log['old_value']) || !empty($log['new_value'])): ?>
                <div class="audit-detail" style="font-size:11px;margin-top:6px">
                  <?php if (!empty($log['old_value'])): ?><span>Old: <?= Security::e(mb_substr((string) $log['old_value'], 0, 120)) ?></span><?php endif; ?>
                  <?php if (!empty($log['new_value'])): ?><span> → New: <?= Security::e(mb_substr((string) $log['new_value'], 0, 120)) ?></span><?php endif; ?>
                </div>
              <?php endif; ?>
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
