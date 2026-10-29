<?php
/**
 * =====================================================================
 *  OfficeTasks — the office's to-do list (29 Oct 2026, management
 *  upgrade 3.5 "Office operations").
 *
 *  One row per job: what, for whom, by when, how urgent, and where it
 *  stands (open → doing → done). Anyone in the office keeps their own
 *  list; a manager (staff.manage) hands work to anyone and sees every
 *  list. Due and overdue tasks ring the office bell once a day through
 *  AdminAlerts (rule "Task due"), the same bell every other alert uses.
 *
 *  Customer support tickets are NOT tasks — they stay on the helpdesk
 *  (support_tickets); this is the office's own work.
 *
 *  Table office_tasks self-creates; reads survive its absence.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class OfficeTasks
{
    public const PRIORITIES = ['high' => 'High · जरुरी', 'normal' => 'Normal', 'low' => 'Low'];
    public const STATUSES   = ['open' => 'To do', 'doing' => 'Doing', 'done' => 'Done'];

    private static bool $ready = false;

    public static function ensure(): bool
    {
        if (self::$ready) {
            return true;
        }
        try {
            Database::query(
                "CREATE TABLE IF NOT EXISTS office_tasks (
                    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    title        VARCHAR(191) NOT NULL,
                    details      VARCHAR(1000) NULL,
                    assigned_to  INT UNSIGNED NOT NULL,
                    created_by   INT UNSIGNED NULL,
                    due_date     DATE         NULL,
                    priority     VARCHAR(10)  NOT NULL DEFAULT 'normal',
                    status       VARCHAR(10)  NOT NULL DEFAULT 'open',
                    link         VARCHAR(255) NULL,
                    done_at      DATETIME     NULL,
                    created_at   DATETIME     NOT NULL,
                    updated_at   DATETIME     NOT NULL,
                    KEY idx_assignee (assigned_to, status),
                    KEY idx_due (status, due_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            self::$ready = true;
        } catch (Throwable $e) {
            Logger::warning('office_tasks unavailable: ' . $e->getMessage());
            self::$ready = false;
        }
        return self::$ready;
    }

    /** May $actorId give work to $assigneeId? Yourself always; anyone if you manage staff. */
    public static function mayAssign(int $actorId, int $assigneeId, bool $isManager): bool
    {
        return $assigneeId === $actorId || $isManager;
    }

    public static function create(int $actorId, int $assigneeId, string $title, string $details, ?string $due, string $priority, string $link = ''): int
    {
        if (!self::ensure()) {
            throw new RuntimeException('Tasks are not available yet — ask the developer to check the database.');
        }
        $title = trim(Security::clean($title, 191));
        if (mb_strlen($title) < 3) {
            throw new RuntimeException('Write what needs doing (at least 3 letters).');
        }
        $who = Database::fetch("SELECT id, role, is_active FROM admins WHERE id = :i", ['i' => $assigneeId]);
        if ($who === null || (int) $who['is_active'] !== 1 || (string) $who['role'] === 'agent') {
            throw new RuntimeException('Pick an active office person.');
        }
        if ($due !== null && $due !== '' && !Security::isValidDate($due)) {
            throw new RuntimeException('Pick a valid due date.');
        }
        $priority = isset(self::PRIORITIES[$priority]) ? $priority : 'normal';
        $link     = self::cleanLink($link);
        $now      = date('Y-m-d H:i:s');
        $id = Database::insert('office_tasks', [
            'title'       => $title,
            'details'     => ($d = trim(Security::clean($details, 1000))) !== '' ? $d : null,
            'assigned_to' => $assigneeId,
            'created_by'  => $actorId ?: null,
            'due_date'    => ($due !== null && $due !== '') ? $due : null,
            'priority'    => $priority,
            'status'      => 'open',
            'link'        => $link !== '' ? $link : null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        Logger::audit('task.create', 'office_task', (string) $id, null, ['to' => $assigneeId, 'due' => $due, 'priority' => $priority], $title);
        return $id;
    }

    /** Only a link inside the panel may ride on a task. */
    private static function cleanLink(string $link): string
    {
        $link = trim($link);
        return preg_match('~^/admin/[A-Za-z0-9_.\-/]+(\?[A-Za-z0-9_.\-=&%+]*)?$~', $link) === 1 ? $link : '';
    }

    public static function get(int $id): ?array
    {
        if (!self::ensure()) {
            return null;
        }
        return Database::fetch('SELECT * FROM office_tasks WHERE id = :i', ['i' => $id]);
    }

    /** Move a task along; the assignee, its creator or a manager may. */
    public static function setStatus(int $id, string $status, int $actorId, bool $isManager): void
    {
        if (!isset(self::STATUSES[$status])) {
            throw new RuntimeException('Unknown status.');
        }
        $t = self::get($id);
        if ($t === null) {
            throw new RuntimeException('Task not found.');
        }
        if (!$isManager && (int) $t['assigned_to'] !== $actorId && (int) ($t['created_by'] ?? 0) !== $actorId) {
            throw new RuntimeException('That task is not yours.');
        }
        Database::update('office_tasks', [
            'status'     => $status,
            'done_at'    => $status === 'done' ? date('Y-m-d H:i:s') : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :i', ['i' => $id]);
        Logger::audit('task.status', 'office_task', (string) $id, ['status' => $t['status']], ['status' => $status], (string) $t['title']);
    }

    /** Remove a task typed by mistake — its creator or a manager. */
    public static function delete(int $id, int $actorId, bool $isManager): void
    {
        $t = self::get($id);
        if ($t === null) {
            return;
        }
        if (!$isManager && (int) ($t['created_by'] ?? 0) !== $actorId) {
            throw new RuntimeException('Only the person who wrote the task (or a manager) can remove it.');
        }
        Database::delete('office_tasks', 'id = :i', ['i' => $id]);
        Logger::audit('task.delete', 'office_task', (string) $id, ['title' => $t['title'], 'to' => (int) $t['assigned_to']], null);
    }

    /**
     * Tasks for a list. $assignee null = everyone (manager view).
     * Open ones first, overdue and high priority on top.
     *
     * @return list<array<string,mixed>>
     */
    public static function list(?int $assignee, bool $includeDone = false, int $limit = 200): array
    {
        if (!self::ensure()) {
            return [];
        }
        $where  = [];
        $params = [];
        if ($assignee !== null) {
            $where[] = 't.assigned_to = :a';
            $params['a'] = $assignee;
        }
        if (!$includeDone) {
            $where[] = "t.status <> 'done'";
        } else {
            $where[] = "(t.status <> 'done' OR t.done_at >= :recent)";
            $params['recent'] = date('Y-m-d H:i:s', strtotime('-7 days'));
        }
        try {
            return Database::fetchAll(
                "SELECT t.*, a.full_name AS assignee_name, a.username AS assignee_user, c.full_name AS creator_name
                   FROM office_tasks t
                   LEFT JOIN admins a ON a.id = t.assigned_to
                   LEFT JOIN admins c ON c.id = t.created_by
                  " . ($where !== [] ? 'WHERE ' . implode(' AND ', $where) : '') . "
                  ORDER BY (t.status = 'done'), (t.due_date IS NULL), t.due_date,
                           FIELD(t.priority, 'high', 'normal', 'low'), t.id DESC
                  LIMIT " . max(1, min(500, $limit)),
                $params
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array{open: int, overdue: int, today: int} for one person (or everyone) */
    public static function counts(?int $assignee): array
    {
        if (!self::ensure()) {
            return ['open' => 0, 'overdue' => 0, 'today' => 0];
        }
        $w = $assignee !== null ? ' AND assigned_to = :a' : '';
        $p = ['t1' => todayISO(), 't2' => todayISO()] + ($assignee !== null ? ['a' => $assignee] : []);
        try {
            $r = Database::fetch(
                "SELECT COUNT(*) AS open_n, COALESCE(SUM(due_date < :t1), 0) AS overdue, COALESCE(SUM(due_date = :t2), 0) AS today
                   FROM office_tasks WHERE status <> 'done'" . $w,
                $p
            );
        } catch (Throwable $e) {
            $r = null;
        }
        return ['open' => (int) ($r['open_n'] ?? 0), 'overdue' => (int) ($r['overdue'] ?? 0), 'today' => (int) ($r['today'] ?? 0)];
    }

    /**
     * Open tasks due today or earlier, for the daily bell (AdminAlerts).
     *
     * @return list<array<string,mixed>>
     */
    public static function due(string $today): array
    {
        if (!self::ensure()) {
            return [];
        }
        try {
            return Database::fetchAll(
                "SELECT t.id, t.title, t.due_date, t.priority, a.full_name AS assignee_name
                   FROM office_tasks t LEFT JOIN admins a ON a.id = t.assigned_to
                  WHERE t.status <> 'done' AND t.due_date IS NOT NULL AND t.due_date <= :d
                  ORDER BY t.due_date, FIELD(t.priority, 'high', 'normal', 'low')
                  LIMIT 50",
                ['d' => $today]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * A day-by-day summary for the daily / weekly report (CSV): tickets
     * booked, confirmed money, cancellations, ratings, tasks done.
     *
     * @return list<array<string,mixed>>
     */
    public static function report(string $from, string $to): array
    {
        $days = [];
        for ($d = $from; $d <= $to; $d = addDaysISO($d, 1)) {
            $days[$d] = ['date' => $d, 'booked' => 0, 'confirmed_money' => 0.0, 'cancelled' => 0, 'ratings' => 0, 'rating_avg' => '', 'tasks_done' => 0];
            if (count($days) > 400) {
                break;
            }
        }
        $toEnd = addDaysISO($to, 1);
        $q = static function (string $sql, array $p): array {
            try {
                return Database::fetchAll($sql, $p);
            } catch (Throwable $e) {
                return [];
            }
        };
        foreach ($q('SELECT DATE(created_at) AS d, COUNT(*) AS n FROM bookings WHERE created_at >= :f AND created_at < :t GROUP BY DATE(created_at)', ['f' => $from, 't' => $toEnd]) as $r) {
            if (isset($days[(string) $r['d']])) { $days[(string) $r['d']]['booked'] = (int) $r['n']; }
        }
        foreach ($q("SELECT DATE(confirmed_at) AS d, COALESCE(SUM(total_amount), 0) AS m FROM bookings WHERE status = 'confirmed' AND confirmed_at >= :f AND confirmed_at < :t GROUP BY DATE(confirmed_at)", ['f' => $from, 't' => $toEnd]) as $r) {
            if (isset($days[(string) $r['d']])) { $days[(string) $r['d']]['confirmed_money'] = round((float) $r['m'], 2); }
        }
        foreach ($q("SELECT DATE(cancelled_at) AS d, COUNT(*) AS n FROM bookings WHERE status = 'cancelled' AND cancelled_at >= :f AND cancelled_at < :t GROUP BY DATE(cancelled_at)", ['f' => $from, 't' => $toEnd]) as $r) {
            if (isset($days[(string) $r['d']])) { $days[(string) $r['d']]['cancelled'] = (int) $r['n']; }
        }
        foreach ($q('SELECT DATE(created_at) AS d, COUNT(*) AS n, AVG(rating) AS a FROM feedback WHERE created_at >= :f AND created_at < :t GROUP BY DATE(created_at)', ['f' => $from, 't' => $toEnd]) as $r) {
            if (isset($days[(string) $r['d']])) { $days[(string) $r['d']]['ratings'] = (int) $r['n']; $days[(string) $r['d']]['rating_avg'] = $r['a'] !== null ? round((float) $r['a'], 1) : ''; }
        }
        if (self::ensure()) {
            foreach ($q("SELECT DATE(done_at) AS d, COUNT(*) AS n FROM office_tasks WHERE status = 'done' AND done_at >= :f AND done_at < :t GROUP BY DATE(done_at)", ['f' => $from, 't' => $toEnd]) as $r) {
                if (isset($days[(string) $r['d']])) { $days[(string) $r['d']]['tasks_done'] = (int) $r['n']; }
            }
        }
        return array_values($days);
    }
}
