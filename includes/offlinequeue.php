<?php
/**
 * includes/offlinequeue.php — the counter keeps working when the internet dies.
 *
 * WHAT IT IS NOT: an offline ticket. A seat cannot be sold honestly without
 * the server — two desks with no signal would both sell L12. So with no
 * internet the desk writes a REQUEST (name, mobile, pickup, how many, cash
 * taken) and hands over a slip that says "seat on confirmation". When the
 * phone is online again the request comes here and is sold through the very
 * same door every desk sale uses, QuickTicket::sell() → BookingService — the
 * server picks the bus and the berth, every rule applies, nothing is bypassed.
 *
 * EXACTLY ONCE. The phone makes a UUID when the request is written and sends
 * it every time it retries. The row is claimed by INSERT IGNORE on that UUID
 * BEFORE the sale; a second arrival (retry, double tap, two tabs) finds the
 * row and is answered with the stored outcome — never a second ticket.
 *
 * NOTHING IS DROPPED SILENTLY. A request that cannot be seated (bus full,
 * service off, bad date) is kept as 'failed' with the reason. Cash was
 * taken for it, so it stays on the office's list until a person writes what
 * was done (re-seated by hand / money returned). A sale that crashed half
 * way is reconciled from the booking register, never retried blind.
 *
 * Staff only, and a desk only ever sees its own requests; the office
 * (dashboard.view) sees all. Switch: offline_queue_on (default off).
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/quickticket.php';

final class OfflineQueue
{
    public const MAX_BATCH = 20;

    public static function enabled(): bool
    {
        return Settings::getBool('offline_queue_on', false);
    }

    public static function validUuid(string $u): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $u) === 1;
    }

    /** The marker the sale carries so an interrupted attempt can be found again. */
    public static function tag(string $uuid): string
    {
        return 'Offline request ' . substr(strtolower($uuid), 0, 8);
    }

    /**
     * Take one request from a phone and seat it — once.
     *
     * @param array<string,mixed> $item  uuid, name, phone, country, gender, seats, direction, date, boarding, pay, cash, note, takenAt
     * @param array<string,mixed> $staff Auth::admin()
     * @param callable(array,array):array|null $seller test seam; default QuickTicket::sell
     * @return array{uuid:string, status:string, pnr:?string, seats:list<string>, total:?float, reason:?string, repeat:bool}
     */
    public static function sync(array $item, array $staff, ?callable $seller = null): array
    {
        $uuid    = strtolower(trim((string) ($item['uuid'] ?? '')));
        $adminId = (int) ($staff['id'] ?? 0);
        if (!self::validUuid($uuid)) {
            throw new RuntimeException('This request has no valid id - write it again.');
        }
        if ($adminId <= 0) {
            throw new RuntimeException('Sign in again.');
        }

        $date    = (string) ($item['date'] ?? '');
        $taken   = (string) ($item['takenAt'] ?? '');
        $takenTs = $taken !== '' ? strtotime($taken) : false;

        $claimed = Database::insertIgnore('offline_requests', [
            'uuid'        => $uuid,
            'admin_id'    => $adminId,
            'name'        => Security::clean((string) ($item['name'] ?? ''), 120),
            'phone'       => Security::clean((string) ($item['phone'] ?? ''), 20) ?: null,
            'country'     => Security::clean((string) ($item['country'] ?? ''), 2) ?: null,
            'gender'      => Security::clean((string) ($item['gender'] ?? ''), 10) ?: null,
            'seats'       => max(1, min(20, (int) ($item['seats'] ?? 1))),
            'direction'   => Security::clean((string) ($item['direction'] ?? ''), 10) ?: null,
            'travel_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null,
            'boarding'    => Security::clean((string) ($item['boarding'] ?? ''), 191) ?: null,
            'pay'         => Security::clean((string) ($item['pay'] ?? ''), 10) ?: null,
            'cash_taken'  => max(0, round((float) ($item['cash'] ?? 0), 2)),
            'note'        => Security::clean((string) ($item['note'] ?? ''), 255) ?: null,
            'taken_at'    => $takenTs !== false ? date('Y-m-d H:i:s', $takenTs) : null,
            'received_at' => date('Y-m-d H:i:s'),
            'status'      => 'received',
        ]);

        $row = Database::fetch('SELECT * FROM offline_requests WHERE uuid = :u', ['u' => $uuid]);
        if ($row === null) {
            throw new RuntimeException('The request could not be stored - it stays on the phone, try again.');
        }
        if ((int) $row['admin_id'] !== $adminId) {
            throw new RuntimeException('This request belongs to another desk.');
        }
        if ($claimed === 0) {
            /* Seen before. 'received' means an earlier attempt died between the
               claim and the outcome: look for the ticket it may have made rather
               than selling again. */
            if ((string) $row['status'] === 'received') {
                $row = self::reconcile($row);
            }
            return self::answer($row, true);
        }

        try {
            $input = [
                'name'      => (string) $row['name'],
                'phone'     => (string) ($row['phone'] ?? ''),
                'country'   => (string) ($row['country'] ?? ''),
                'gender'    => (string) ($row['gender'] ?? ''),
                'seats'     => (int) $row['seats'],
                'direction' => (string) ($row['direction'] ?? ''),
                'date'      => (string) ($row['travel_date'] ?? ''),
                'boarding'  => (string) ($row['boarding'] ?? ''),
                'pay'       => (string) ($row['pay'] ?? 'cash'),
                'prefer'    => [],
                'note'      => trim(self::tag($uuid) . ' · ' . (string) ($row['note'] ?? ''), " ·"),
            ];
            $res = $seller !== null ? $seller($input, $staff) : QuickTicket::sell($input, $staff);

            Database::update('offline_requests', [
                'status'       => 'ticketed',
                'booking_id'   => ((int) ($res['bookingId'] ?? 0)) ?: null,
                'pnr'          => ((string) ($res['pnr'] ?? '')) ?: null,
                'total_amount' => isset($res['total']) ? round((float) $res['total'], 2) : null,
            ], 'id = :i', ['i' => (int) $row['id']]);
        } catch (Throwable $e) {
            Database::update('offline_requests', [
                'status'      => 'failed',
                'fail_reason' => mb_substr($e instanceof RuntimeException ? $e->getMessage() : 'The server could not issue this ticket.', 0, 255),
            ], 'id = :i', ['i' => (int) $row['id']]);
            if (!($e instanceof RuntimeException)) {
                Logger::error('offline request crashed', ['uuid' => $uuid, 'e' => $e->getMessage()]);
            }
        }

        $row = Database::fetch('SELECT * FROM offline_requests WHERE id = :i', ['i' => (int) $row['id']]) ?? $row;
        return self::answer($row, false);
    }

    /**
     * An attempt that never recorded its outcome: did it make a ticket? The
     * sale stamps the request tag into the payment note, so the register can
     * answer. Found → ticketed. Not found after two minutes → failed (a
     * person decides); younger than that → still 'received', the phone asks
     * again shortly.
     */
    private static function reconcile(array $row): array
    {
        $b = Database::fetch(
            "SELECT b.id, b.pnr, b.total_amount
               FROM payments p JOIN bookings b ON b.id = p.booking_id
              WHERE p.admin_note LIKE :t AND b.sold_by_admin_id = :a
              ORDER BY b.id DESC LIMIT 1",
            ['t' => '%' . self::tag((string) $row['uuid']) . '%', 'a' => (int) $row['admin_id']]
        );
        if ($b !== null) {
            Database::update('offline_requests', [
                'status' => 'ticketed', 'booking_id' => (int) $b['id'], 'pnr' => (string) $b['pnr'], 'total_amount' => (float) $b['total_amount'],
            ], 'id = :i', ['i' => (int) $row['id']]);
        } elseif (time() - (int) strtotime((string) $row['received_at']) > 120) {
            Database::update('offline_requests', [
                'status'      => 'failed',
                'fail_reason' => 'The first attempt was interrupted and no ticket was found. Sell it again by hand if the passenger is still travelling.',
            ], 'id = :i', ['i' => (int) $row['id']]);
        }
        return Database::fetch('SELECT * FROM offline_requests WHERE id = :i', ['i' => (int) $row['id']]) ?? $row;
    }

    /** @return array{uuid:string, status:string, pnr:?string, seats:list<string>, total:?float, reason:?string, repeat:bool} */
    private static function answer(array $row, bool $repeat): array
    {
        $seats = [];
        if (!empty($row['booking_id'])) {
            $seats = array_map('strval', array_column(
                Database::fetchAll('SELECT seat_no FROM booking_seats WHERE booking_id = :b ORDER BY id', ['b' => (int) $row['booking_id']]),
                'seat_no'
            ));
        }
        return [
            'uuid'   => (string) $row['uuid'],
            'status' => (string) $row['status'],
            'pnr'    => $row['pnr'] !== null ? (string) $row['pnr'] : null,
            'seats'  => $seats,
            'total'  => $row['total_amount'] !== null ? (float) $row['total_amount'] : null,
            'reason' => $row['fail_reason'] !== null ? (string) $row['fail_reason'] : null,
            'repeat' => $repeat,
        ];
    }

    /**
     * Requests that still need a person: failed, or ticketed for a different
     * amount than the cash the desk took.
     *
     * @return list<array<string,mixed>>
     */
    public static function needingAttention(?int $scopeAdminId, int $limit = 100): array
    {
        $limit  = max(1, min(300, $limit));
        $scope  = $scopeAdminId !== null ? ' AND r.admin_id = :a' : '';
        $params = $scopeAdminId !== null ? ['a' => $scopeAdminId] : [];
        return Database::fetchAll(
            "SELECT r.*, a.full_name AS admin_name
               FROM offline_requests r LEFT JOIN admins a ON a.id = r.admin_id
              WHERE r.resolved_at IS NULL
                AND (r.status = 'failed'
                     OR (r.status = 'ticketed' AND r.cash_taken > 0 AND r.total_amount IS NOT NULL
                         AND ABS(r.cash_taken - r.total_amount) > 1))
                    {$scope}
              ORDER BY r.received_at DESC
              LIMIT {$limit}",
            $params
        );
    }

    /** A person writes what was done about a failed / mismatched request. */
    public static function resolve(int $id, int $byAdminId, string $note): bool
    {
        if (mb_strlen(trim($note)) < 3) {
            throw new RuntimeException('Write what was done (re-seated by hand, money returned...).');
        }
        return Database::update('offline_requests', [
            'resolved_by'  => $byAdminId,
            'resolved_at'  => date('Y-m-d H:i:s'),
            'resolve_note' => mb_substr(trim($note), 0, 255),
        ], 'id = :i AND resolved_at IS NULL', ['i' => $id]) > 0;
    }
}
