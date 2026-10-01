<?php
/**
 * TicketSync — ONE hook for "the booking changed, make the ticket match"
 * (owner requirement R3f / R3e, 1 Oct 2026).
 *
 * Before this, thirteen call sites hand-rolled their own subset of the job:
 * some re-minted the QR inside the open transaction (a deadlock retry bumped
 * REV twice and a rollback still deleted the rendered PNG), some redrew the
 * PNG and some left it for the next download, one path reissued twice per
 * seat change, cancellations never voided the ticket, and the "your ticket
 * changed" WhatsApp was silently swallowed by the first-ticket idempotency
 * guard. Every booking mutation now calls afterBookingChange() once and the
 * steps below happen in one order, every time:
 *
 *   1. Inside a transaction?  Park the whole job on Database::afterCommit()
 *      and return ['deferred' => true]; it runs right after the COMMIT and is
 *      dropped on rollback — the ticket is derived data, the booking is the
 *      record (same rationale as BookingService::confirm()).
 *   2. No ticket row yet (booking still pending)?  Remove any stray rendered
 *      file for the PNR and stop — issue() mints a correct ticket at confirm.
 *   3. void / delete → tickets.is_void = 1, PNG/PDF/invoice unlinked, cache
 *      columns nulled, audited. No regeneration. (reconfirm clears is_void.)
 *   4. Ticket::reissue() — new signed QR, old files gone, REV n+1 (or a
 *      silent refresh with $opts['silent'] when nobody has seen the ticket).
 *   5. The imageUrl() memo for the PNR is dropped so the link this same
 *      request hands out carries the new revision.
 *   6. The PNG is redrawn at once (download / WhatsApp never wait on a cold
 *      render); the PDF stays lazy unless $opts['pdf'].
 *   7. ONE audit row `ticket.regenerated` with old/new REV, the kind, the
 *      reason the desk typed (7th Logger::audit arg) and the notify outcome.
 *   8. Confirmed booking + passenger-relevant kind (or $opts['notify'] ===
 *      true) + settings notify_ticket_edit → Notify::ticketChanged(), whose
 *      real result is recorded — never claimed.
 *
 * Kinds: reschedule | missed_rebook | seat | seat_cancelled | passenger |
 *        contact | payment | agent | paid | stops | trip_change | reconfirm |
 *        void | delete
 *
 * $opts: silent (bool, no REV), notify (bool|null), pdf (bool), png (bool,
 *        default true), reason (string), actor (string, e.g. 'whatsapp:+91…').
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/ticket.php';

final class TicketSync
{
    public const KINDS = [
        'reschedule', 'missed_rebook', 'seat', 'seat_cancelled', 'passenger', 'contact',
        'payment', 'agent', 'paid', 'stops', 'trip_change', 'reconfirm', 'void', 'delete',
    ];

    /** Kinds the passenger is told about by default (the picture they hold is wrong). */
    private const NOTIFY_KINDS = ['reschedule', 'missed_rebook', 'seat', 'seat_cancelled', 'stops', 'trip_change', 'reconfirm'];

    /** @var list<array<string,mixed>> every completed run of this request, oldest first */
    private static array $journal = [];

    /**
     * @param array<string,mixed> $ctx display strings for the change notice
     *        (oldDate, newDate, seats, oldSeats, old, new, name, seat, refund)
     * @param array<string,mixed> $opts see the file header
     * @return array<string,mixed> ['deferred'=>true] inside a transaction, else the summary
     */
    public static function afterBookingChange(int $bookingId, string $kind, array $ctx = [], array $opts = []): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('TicketSync: unknown change kind "' . $kind . '".');
        }
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('TicketSync: booking id required.');
        }

        if (Database::inTransaction()) {
            Database::afterCommit(static function () use ($bookingId, $kind, $ctx, $opts): void {
                self::afterBookingChange($bookingId, $kind, $ctx, $opts);
            });
            return ['deferred' => true, 'bookingId' => $bookingId, 'kind' => $kind];
        }

        try {
            $out = self::run($bookingId, $kind, $ctx, $opts);
        } catch (Throwable $e) {
            Logger::error('TicketSync failed', ['booking' => $bookingId, 'kind' => $kind, 'e' => $e->getMessage()], 'ticket');
            $out = [
                'deferred' => false, 'ok' => false, 'bookingId' => $bookingId, 'kind' => $kind,
                'hadTicket' => null, 'voided' => false, 'oldRev' => null, 'rev' => null,
                'imageUrl' => '', 'notified' => false, 'notifyDetail' => '', 'link' => '',
                'detail' => 'Ticket sync failed: ' . $e->getMessage(),
            ];
        }
        self::$journal[] = $out;
        return $out;
    }

    /** The most recent completed run (optionally for one booking), or null. */
    public static function last(?int $bookingId = null): ?array
    {
        for ($i = count(self::$journal) - 1; $i >= 0; $i--) {
            if ($bookingId === null || (int) (self::$journal[$i]['bookingId'] ?? 0) === $bookingId) {
                return self::$journal[$i];
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    public static function journal(): array
    {
        return self::$journal;
    }

    /** Tests only: forget this request's journal. */
    public static function resetJournal(): void
    {
        self::$journal = [];
    }

    /**
     * One-line flash fragment for admin pages: what happened to the ticket
     * and whether the passenger was told. '' for a deferred / missing result.
     */
    public static function note(?array $r): string
    {
        if ($r === null || !empty($r['deferred'])) {
            return '';
        }
        if (empty($r['ok'])) {
            return ' Ticket PNG and PDF regenerate on next open.';
        }
        if (!empty($r['voided'])) {
            return ' Ticket voided — old PNG/PDF removed.';
        }
        if (empty($r['hadTicket'])) {
            return ' Ticket is issued at confirmation and will carry these details.';
        }
        $s = ' Ticket regenerated' . ((int) ($r['rev'] ?? 0) > 0 ? ' (REV ' . (int) $r['rev'] . ')' : '') . ' — old PNG/PDF replaced.';
        if (!empty($r['notifyAttempted'])) {
            $s .= !empty($r['notified'])
                ? ' Passenger notified on WhatsApp — the message names the change and links the updated ticket.'
                : ' (WhatsApp not sent: ' . truncate((string) ($r['notifyDetail'] ?? 'unknown'), 120) . ')';
        }
        return $s;
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string,mixed> */
    private static function run(int $bookingId, string $kind, array $ctx, array $opts): array
    {
        $reason = trim((string) ($opts['reason'] ?? ''));
        $actor  = trim((string) ($opts['actor'] ?? ''));
        $out = [
            'deferred' => false, 'ok' => true, 'bookingId' => $bookingId, 'kind' => $kind,
            'hadTicket' => false, 'voided' => false, 'oldRev' => 0, 'rev' => 0,
            'imageUrl' => '', 'notifyAttempted' => false, 'notified' => false, 'notifyDetail' => '', 'link' => '',
            'detail' => '',
        ];

        $booking = Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);
        if ($booking === null) {
            $out['ok']     = false;
            $out['detail'] = 'Booking not found.';
            return $out;
        }
        $pnr    = (string) ($booking['pnr'] ?? '');
        $ticket = Database::fetch('SELECT * FROM tickets WHERE booking_id = :b LIMIT 1', ['b' => $bookingId]);

        if ($ticket === null) {
            // Pending booking: nothing is minted yet, but a file left over from
            // an earlier life of this PNR must not be served later.
            self::unlinkFiles($pnr);
            $out['detail'] = 'No ticket yet — issued at confirmation.';
            return $out;
        }
        $out['hadTicket'] = true;
        $oldRev = (int) ($ticket['reissue_count'] ?? 0);
        $out['oldRev'] = $oldRev;

        /* ---- void / delete: the ticket must stop looking live ---------- */
        if ($kind === 'void' || $kind === 'delete') {
            Database::update('tickets', [
                'is_void' => 1, 'pdf_path' => null, 'png_path' => null, 'invoice_path' => null,
            ], 'id = :id', ['id' => (int) $ticket['id']]);
            self::unlinkFiles($pnr);
            Ticket::forgetRevision($pnr);
            $out['voided'] = true;
            $out['rev']    = $oldRev;
            $out['detail'] = 'Ticket voided (' . $kind . ') — rendered PNG/PDF/invoice removed' . ($actor !== '' ? ' · by ' . $actor : '');
            Logger::audit('ticket.voided', 'booking', $pnr,
                ['is_void' => (int) ($ticket['is_void'] ?? 0), 'rev' => $oldRev],
                ['is_void' => 1, 'kind' => $kind, 'status' => (string) ($booking['status'] ?? '')],
                $out['detail'], $reason);
            return $out;
        }

        if ($kind === 'reconfirm' && (int) ($ticket['is_void'] ?? 0) === 1) {
            Database::update('tickets', ['is_void' => 0], 'id = :id', ['id' => (int) $ticket['id']]);
        }

        /* ---- regenerate ------------------------------------------------ */
        $silent = !empty($opts['silent']);
        Ticket::reissue($bookingId, !$silent);   // new QR + hash, files unlinked, REV / reissued_at
        Ticket::forgetRevision($pnr);

        $pngNote = '';
        if (($opts['png'] ?? true) !== false) {
            try {
                Ticket::pngPath($bookingId, true);
            } catch (Throwable $e) {
                $pngNote = ' · PNG redraw deferred to next open (' . $e->getMessage() . ')';
                Logger::warning('TicketSync PNG redraw failed', ['pnr' => $pnr, 'e' => $e->getMessage()], 'ticket');
            }
        }
        if (!empty($opts['pdf'])) {
            try {
                Ticket::pdfPath($bookingId, true);
            } catch (Throwable $e) {
                Logger::warning('TicketSync PDF redraw failed', ['pnr' => $pnr, 'e' => $e->getMessage()], 'ticket');
            }
        }

        $fresh  = Database::fetch('SELECT reissue_count, qr_hash FROM tickets WHERE booking_id = :b LIMIT 1', ['b' => $bookingId]) ?? [];
        $newRev = (int) ($fresh['reissue_count'] ?? $oldRev);
        $out['rev']      = $newRev;
        $out['imageUrl'] = Ticket::imageUrl($pnr);

        /* ---- tell the passenger? --------------------------------------- */
        $want = $opts['notify'] ?? null;
        $shouldNotify = (string) ($booking['status'] ?? '') === 'confirmed'
            && $want !== false
            && ($want === true || in_array($kind, self::NOTIFY_KINDS, true))
            && Settings::getBool('notify_ticket_edit', true);
        if ($shouldNotify) {
            $out['notifyAttempted'] = true;
            try {
                if (!class_exists('Notify')) {
                    require_once __DIR__ . '/notify.php';
                }
                if (!class_exists('BookingService')) {
                    require_once __DIR__ . '/booking.php';
                }
                $detail = BookingService::detail($pnr);
                if ($detail === null) {
                    $out['notifyDetail'] = 'booking detail unavailable';
                } else {
                    $r = Notify::ticketChanged($detail, $kind, $ctx);
                    $out['notified']     = (bool) ($r['ok'] ?? false);
                    $out['notifyDetail'] = (string) ($r['detail'] ?? '');
                    $out['link']         = (string) ($r['link'] ?? '');
                }
            } catch (Throwable $e) {
                $out['notifyDetail'] = 'notify failed: ' . $e->getMessage();
                Logger::error('TicketSync notify failed', ['pnr' => $pnr, 'e' => $e->getMessage()], 'ticket');
            }
        }

        $out['detail'] = 'Ticket regenerated after ' . $kind . ' — REV ' . $oldRev . ' → ' . $newRev
            . ($silent ? ' (silent refresh, not counted)' : '')
            . ($actor !== '' ? ' · by ' . $actor : '')
            . $pngNote
            . ($out['notifyAttempted']
                ? ($out['notified'] ? ' · passenger notified' : ' · passenger NOT notified: ' . $out['notifyDetail'])
                : ' · no passenger notice');

        Logger::audit('ticket.regenerated', 'booking', $pnr,
            ['rev' => $oldRev, 'qr_hash' => substr((string) ($ticket['qr_hash'] ?? ''), 0, 12)],
            ['rev' => $newRev, 'qr_hash' => substr((string) ($fresh['qr_hash'] ?? ''), 0, 12), 'kind' => $kind,
             'ctx' => $ctx, 'silent' => $silent, 'notified' => $out['notified'], 'notify_attempted' => $out['notifyAttempted']],
            $out['detail'], $reason);

        return $out;
    }

    /** Remove every rendered file for a PNR (PNG + PDF ticket, GST invoice). */
    private static function unlinkFiles(string $pnr): void
    {
        if ($pnr === '') {
            return;
        }
        if (defined('TICKET_PATH')) {
            @unlink(TICKET_PATH . '/ticket_' . $pnr . '.png');
            @unlink(TICKET_PATH . '/ticket_' . $pnr . '.pdf');
        }
        if (defined('INVOICE_PATH')) {
            @unlink(INVOICE_PATH . '/invoice_' . $pnr . '.pdf');
        }
    }
}
