<?php
/**
 * =====================================================================
 *  EventBus — the automation backbone (in-process pub/sub).
 *
 *  Booking / trip / agent lifecycle code EMITS named events from
 *  post-commit positions (never inside a Database::transaction closure —
 *  see includes/db.php §"network side effects"), and the listeners
 *  registered at the bottom of this file fan out to WhatsApp / email /
 *  SMS through the Notify class.
 *
 *  Three hard rules, learned the expensive way:
 *
 *   1. emit() NEVER throws. Every handler runs inside its own try/catch;
 *      a broken WhatsApp send must never 500 a booking (production turns
 *      E_WARNING into a thrown ErrorException — the $boardingStop
 *      incident).
 *   2. Nothing here runs inside an open DB transaction. Emissions sit
 *      after commit at every call site; a deadlock retry must not
 *      re-send messages.
 *   3. Exactly-once sends use claim(): check the Settings toggle FIRST,
 *      then claim, then send — a claim taken while a channel is off
 *      would permanently eat the message (same contract as
 *      Notify::tripEventEnabled / trip_events).
 *
 *  Every emit is journalled into automation_log (best-effort: a missing
 *  table — migration not yet run on live — degrades to file logs only).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class EventBus
{
    /** @var array<string, list<callable>> */
    private static array $listeners = [];

    private static bool $registered = false;

    /** Subscribe a handler to one event name. */
    public static function on(string $event, callable $handler): void
    {
        self::$listeners[$event][] = $handler;
    }

    /**
     * Fire an event. Never throws; handler failures are logged to the
     * 'automation' channel and recorded on the automation_log row.
     *
     * @param array<string, mixed> $data
     */
    public static function emit(string $event, array $data = []): void
    {
        $errors = [];

        foreach (self::$listeners[$event] ?? [] as $handler) {
            try {
                $handler($data);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                try {
                    Logger::error('Event handler failed', [
                        'event' => $event,
                        'error' => $e->getMessage(),
                        'file'  => $e->getFile() . ':' . $e->getLine(),
                    ], 'automation');
                } catch (Throwable $ignored) {
                    // Logging must never take the request down either.
                }
            }
        }

        self::journal($event, $data, $errors === [] ? 'ok' : 'fail', implode(' | ', $errors));
    }

    /**
     * Exactly-once claim. INSERT IGNORE against automation_log's UNIQUE
     * dedupe_key; only the winner (rowCount > 0) may send.
     *
     * Returns FALSE when the table is missing (migration pending on
     * live) — deliberately: "no dedupe ledger" must mean "no repeated
     * alert spam every cron tick", not "send freely".
     */
    public static function claim(string $dedupeKey, string $event): bool
    {
        try {
            $stmt = Database::query(
                'INSERT IGNORE INTO automation_log (event, dedupe_key, status) VALUES (:e, :k, :s)',
                ['e' => substr($event, 0, 50), 'k' => substr($dedupeKey, 0, 120), 's' => 'ok']
            );
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            try {
                Logger::warning('EventBus claim unavailable (run upgrade-2026-08-automation.sql?)', [
                    'key' => $dedupeKey, 'error' => $e->getMessage(),
                ], 'automation');
            } catch (Throwable $ignored) {
            }
            return false;
        }
    }

    /** Best-effort journal row; a missing table degrades silently. */
    private static function journal(string $event, array $data, string $status, string $error): void
    {
        try {
            // Keep the payload light: ids and scalars, never the whole row.
            $slim = [];
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    foreach (['id', 'pnr', 'status', 'sold_by_admin_id', 'total_amount'] as $col) {
                        if (isset($v[$col])) {
                            $slim[$k . '.' . $col] = $v[$col];
                        }
                    }
                } elseif (is_scalar($v) || $v === null) {
                    $slim[$k] = $v;
                }
            }
            Database::query(
                'INSERT INTO automation_log (event, data, status, error) VALUES (:e, :d, :s, :err)',
                [
                    'e'   => substr($event, 0, 50),
                    'd'   => substr(json_encode($slim, JSON_UNESCAPED_UNICODE) ?: '{}', 0, 2000),
                    's'   => $status,
                    'err' => $error !== '' ? substr($error, 0, 500) : null,
                ]
            );
        } catch (Throwable $ignored) {
            // Table not migrated yet — the file log from emit() still has it.
        }
    }

    /**
     * Register the automation listeners exactly once (bootstrap calls
     * this via the require of events.php below).
     */
    public static function registerDefaultListeners(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // Notify (and its ticket.php dependency) is NOT bootstrap-loaded —
        // pull it in lazily so plain page views never pay for it.
        $notify = static function (): bool {
            require_once __DIR__ . '/notify.php';
            return true;
        };

        // AI memory (24 Sep 2026): the assistant remembers each person from
        // the register's own events. The listeners no-op while ai_memory_on
        // is off and never throw, so a booking cannot fail on a memory write.
        if (is_file(__DIR__ . '/aimemory.php')) {
            require_once __DIR__ . '/aimemory.php';
            AiMemory::registerListeners();
        }

        // Same lazy treatment for the customer vault: a page view that emits
        // no booking event should not pay to load it.
        $vault = static function (): bool {
            require_once __DIR__ . '/gemvault.php';
            require_once __DIR__ . '/boarding.php';
            require_once __DIR__ . '/quickticket.php';
            return true;
        };

        /* ---------------- the customer vault ----------------
           Deliberately its own listener rather than a call inside
           BookingService::create(): emit() runs after the transaction has
           committed and swallows every handler exception, so a vault problem
           can neither roll back a sale nor 500 the checkout. The worst case
           is a missing profile row, which the nightly hygiene job rebuilds.

           TWO events, on purpose:
             · created  — capture the CONTACT the moment it exists, even for a
                          booking that is never paid for. A phone number that
                          reached us is an asset whether or not that particular
                          seat sold.
             · approved — re-learn the travel habits, which only a settled
                          booking is evidence of. */

        self::on('booking.created', static function (array $d) use ($vault): void {
            $booking = $d['booking'] ?? [];
            if ($booking === []) {
                return;
            }
            $vault();
            GemVault::upsert(
                (string) ($booking['contact_phone'] ?? ''),
                '',
                (string) ($booking['contact_country_code'] ?? ''),
                match ((string) ($booking['source'] ?? 'web')) {
                    'counter' => 'counter',
                    'agent'   => 'agent',
                    'admin'   => 'admin',
                    'app'     => 'app',
                    default   => 'web',
                },
                isset($booking['user_id']) && $booking['user_id'] !== null ? (int) $booking['user_id'] : null
            );
        });

        self::on('booking.approved', static function (array $d) use ($vault): void {
            $booking = $d['booking'] ?? [];
            if ($booking === []) {
                return;
            }
            $vault();
            GemVault::noteBooking($booking);
        });

        /* ---------------- booking lifecycle ---------------- */

        self::on('booking.created', static function (array $d) use ($notify): void {
            $booking = $d['booking'] ?? [];
            if ($booking === []) {
                return;
            }
            $notify();
            // 'admin' too: a sale keyed in by office staff is paid on the spot, so
            // the "verify this payment" ping and the pay-instructions ack are wrong.
            $counter = in_array((string) ($booking['source'] ?? 'web'), ['counter', 'agent', 'admin'], true);
            if (!$counter && empty($booking['is_cod'])) {
                // Online-payment bookings only: admin gets the "verify this"
                // ping, the customer an ack with payment instructions. COD
                // confirms instantly (bookingConfirmed via booking.approved)
                // and counter sales were made by staff themselves.
                Notify::bookingReceived($booking);
                Notify::bookingPlaced($booking);
            }
        });

        self::on('booking.payment_uploaded', static function (array $d) use ($notify): void {
            $booking = $d['booking'] ?? [];
            if ($booking === []) {
                return;
            }
            $notify();
            Notify::paymentProofUploaded($booking, (string) ($d['kind'] ?? ''));
        });

        self::on('booking.approved', static function (array $d) use ($notify): void {
            $booking = $d['booking'] ?? [];
            if ($booking === []) {
                return;
            }
            $notify();
            Notify::bookingConfirmed($booking);
            Notify::agentBookingApproved($booking);
        });

        self::on('booking.rejected', static function (array $d) use ($notify): void {
            $booking = $d['booking'] ?? [];
            if ($booking === []) {
                return;
            }
            $notify();
            Notify::paymentRejected($booking, (string) ($d['reason'] ?? ''));
            Notify::agentBookingRejected($booking, (string) ($d['reason'] ?? ''));
        });

        self::on('booking.cancelled', static function (array $d) use ($notify): void {
            $booking = $d['booking'] ?? [];
            if ($booking === []) {
                return;
            }
            $notify();
            $refund = (array) ($d['refund'] ?? []);
            /* A QuickBot one-tap ticket undone inside its free window: nothing
               was paid, so the passenger gets the short undo copy and the
               office is not paged for a ₹0 refund (bell + register still show
               it). The AMOUNT decides, not the label: if the desk had already
               taken the cash (settleCod marks the COD payment verified), a real
               refund is due and the ordinary cancel copy must go out to both —
               a quiet "nothing to pay" would strand the passenger's money. */
            if ((string) ($d['via'] ?? '') === 'undo' && (float) ($refund['amount'] ?? 0) <= 0.0) {
                Notify::bookingUndone($booking);
            } else {
                Notify::bookingCancelled($booking, $refund);
            }
            Notify::agentBookingCancelled($booking);
        });

        self::on('booking.cod_settled', static function (array $d) use ($notify): void {
            $booking = $d['booking'] ?? [];
            if ($booking === []) {
                return;
            }
            $notify();
            Notify::codSettled($booking);
        });

        // booking.boarded / booking.expired / trip.* are journalled by
        // emit() itself; no outbound messages beyond what TripNotify
        // already sends per passenger.

        /* ---------------- agent lifecycle ---------------- */

        self::on('agent.registered', static function (array $d) use ($notify): void {
            $notify();
            Notify::agentWelcome(
                (int) ($d['admin_id'] ?? 0),
                (string) ($d['full_name'] ?? ''),
                (string) ($d['phone'] ?? '')
            );
        });

        /* ---------------- capacity alerts ---------------- */

        self::on('seats.low', static function (array $d) use ($notify): void {
            $notify();
            Notify::lowSeatAlert($d);
        });
    }
}

EventBus::registerDefaultListeners();
