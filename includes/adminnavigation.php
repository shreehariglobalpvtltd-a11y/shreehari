<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }

/** Presentation only: pass the existing permission-filtered admin_nav() result. */
final class AdminNavigation
{
    public const CATEGORIES = [
        'operations' => 'Ticket, Bus & Live Operations',
        'team' => 'Agent, Staff & Pvt Ltd',
        'finance' => 'Finance, Reports & Settings',
    ];

    public static function category(array $item): string
    {
        $page = explode('#', explode('?', $item['href'])[0])[0];
        if ($page === 'index.php') { return ''; }
        if (in_array($page, ['staff-applications.php', 'drivers.php'], true)) { return 'team'; }
        if (in_array($page, ['shift.php', 'cash-book.php', 'wa-queue.php', 'wa-routing.php'], true)) { return 'operations'; }
        if (in_array($page, ['staff.php', 'agreement-points.php'], true)) { return 'finance'; }
        return match ($item['section'] ?? '') {
            'Bookings & tickets', 'Seat management', 'Trips, buses & chalani' => 'operations',
            'Agents & counters' => 'team',
            default => 'finance',
        };
    }

    /** All links survive once, in their original order within each category. */
    public static function groups(array $items): array
    {
        $groups = array_fill_keys(array_keys(self::CATEGORIES), []);
        foreach ($items as $item) {
            $key = self::category($item);
            if ($key !== '') { $groups[$key][] = $item; }
        }
        return array_filter($groups, static fn(array $group): bool => $group !== []);
    }

    /** Staff includes drivers and conductors; the existing org type stays a partner. */
    public static function teamFamily(string $category): string
    {
        return in_array($category, ['staff', 'driver'], true) ? 'staff' : $category;
    }
}
