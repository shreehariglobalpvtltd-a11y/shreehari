<?php
/**
 * includes/homeboard.php — what the ONE home (admin/home.php) shows (30 Oct 2026).
 *
 * Owner: "ONE dashboard — agent, admin, staff sabai euta dashboard bhitra,
 * khali kholda pharak. Counter le counter ko kam, agent le agent ko kam,
 * sabai le ticket katna milos, admin le admin ko kam."
 *
 * Big tiles by what the signed-in person may do. Every tile carries the SAME
 * check the page it opens boots with (admin_boot / Auth::can /
 * isSellingStaff / isCounterAgent), so a tile never leads to a 403 and
 * nobody gains access they did not have. Role names are read only to keep
 * the read-only viewer out of the member pages.
 *
 * Call with the person signed in (Auth reads the session).
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class HomeBoard
{
    /** Tiles shown up front; the rest fold under "＋ थप काम · More". */
    public const HERO = 8;

    /** The order for field people (agent, counter, scanner, crew, official). */
    private const FIELD = ['sell', 'counter', 'mytix', 'cut', 'mychalani', 'chalani', 'seat', 'wallet', 'desk', 'id', 'shift',
                           'scan', 'bus', 'pay', 'support', 'register', 'statement', 'cashbook', 'reschedule', 'map', 'refunds', 'papers'];

    /** The order for the office (anyone holding dashboard.view). */
    private const OFFICE = ['sell', 'counter', 'approvals', 'pay', 'team', 'support', 'reports', 'admin', 'refunds', 'cut', 'register',
                            'chalani', 'seat', 'scan', 'map', 'statement', 'cashbook', 'reschedule', 'shift', 'desk', 'addmember',
                            'id', 'papers', 'bus', 'alltools'];

    private function __construct() {}

    public static function isOffice(): bool
    {
        return Auth::can('dashboard.view');
    }

    /**
     * Every tile this person may open, in their order, split into the hero row and the rest.
     *
     * @param array<string, mixed>      $admin  Auth::admin()
     * @param array<string, mixed>|null $m      AgentPro::member() (null when AgentPro is not installed)
     * @param array<string, int>        $counts AgentPro::counts() — only read for the office badges
     * @return array{hero: list<array<string, mixed>>, more: list<array<string, mixed>>}
     */
    public static function tiles(array $admin, ?array $m, array $counts = []): array
    {
        $id       = (int) ($admin['id'] ?? 0);
        $viewer   = (string) ($admin['role'] ?? '') === 'viewer';
        $isAgent  = Auth::isCounterAgent();
        $selling  = Auth::isSellingStaff();
        $office   = self::isOffice();
        $bv       = Auth::can('bookings.view');
        $deskStaff = !$isAgent && !$office && Auth::can('bookings.edit');
        $deskCode = '';
        if (!$isAgent && $bv) {
            try {
                $deskCode = (string) (CounterDesk::forAdmin($id)['code'] ?? '');
            } catch (Throwable $e) {
                $deskCode = '';
            }
        }
        $crew = (string) ($m['crewRole'] ?? '');
        $badge = static fn(int $n): int => $office ? max(0, $n) : 0;   // company numbers only for the office

        $all = [
            'sell'      => ['🎫', 'टिकट काट्नुहोस्', 'Sell a ticket', '/admin/smartbook.php', $selling && $bv],
            'counter'   => ['🖥', 'काउन्टर मोड (सिट नक्सा)', 'Counter mode (seat map)', '/index.php?counter=1#/', $selling],
            'mytix'     => ['🧾', 'मेरो टिकट', 'My tickets', '/admin/bookings.php', $isAgent && $bv],
            'cut'       => ['🙋', 'मैले काटेको टिकट', 'Tickets I cut', '/admin/bookings.php?mine=1', !$isAgent && $selling && $bv],
            'register'  => ['📋', 'टिकट रजिस्टर', 'Tickets register', '/admin/bookings.php', !$isAgent && $bv],
            'mychalani' => ['📋', 'मेरो चलानी (मेरा यात्रु)', 'My chalani', '/admin/agent-passengers.php?view=manifest', $isAgent && $bv],
            'chalani'   => ['🚌', 'बसको चलानी', 'Chalani per bus', '/admin/chalan.php', !$isAgent && $bv],
            'seat'      => ['💺', 'सिट नक्सा · सिट सार्ने', 'Seat map & shift seat', '/admin/seatmap.php', Auth::can('schedules.view')],
            'wallet'    => ['💰', 'वालेट र हिसाब', 'Wallet & statement', '/admin/agent.php', $isAgent && $bv],
            'shift'     => ['🕒', 'मेरो सिफ्ट र नगद', 'My shift & cash', '/admin/shift.php', !$isAgent && $bv && Auth::can('bookings.edit')],
            'desk'      => ['🏪', 'मेरो डेस्क', 'My desk', '/admin/my-desk.php', !$isAgent && $bv && ($deskCode !== '' || Auth::can('reports.view') || $deskStaff)],
            'id'        => ['🪪', 'मेरो ID कार्ड', 'My ID card', '/me/#id', !$viewer],
            'scan'      => ['📷', 'टिकट स्क्यान', 'Scan ticket', '/admin/scan.php', Auth::can('tickets.scan')],
            'bus'       => ['🛰', 'बस लोकेसन सुरु', 'Start bus location', '/me/#work', $crew !== ''],
            'pay'       => ['💳', 'भुक्तानी जाँच', 'Verify payments', '/admin/payments.php', Auth::can('payments.view'), $badge((int) ($counts['pendingPay'] ?? 0))],
            'approvals' => ['✅', 'स्वीकृति', 'Approvals', '/admin/team.php?tab=approvals', Auth::can('commissions.view'),
                            $badge((int) ($counts['applications'] ?? 0) + (int) ($counts['withdrawals'] ?? 0) + (int) ($counts['deposits'] ?? 0))],
            'team'      => ['👥', 'टिम र ID कार्ड', 'Team & ID cards', '/admin/team.php', Auth::can('staff.manage') || Auth::can('commissions.view')],
            'support'   => ['💬', 'ग्राहक सेवा', 'Support inbox', '/admin/support-inbox.php', Auth::can('support.view')],
            'refunds'   => ['↩️', 'रिफन्ड', 'Refunds', '/admin/refunds.php', Auth::can('refunds.view')],
            'reports'   => ['📊', 'रिपोर्ट', 'Reports', '/admin/analytics.php', $office],
            'admin'     => ['🏢', 'एडमिन प्यानल', 'Admin panel', '/admin/index.php', $office],
            'map'       => ['🗺', 'बस लाइभ नक्सा', 'Live bus map', '/admin/map.php', Auth::can('schedules.view')],
            'statement' => ['📜', 'मासिक हिसाब', 'Monthly statement', '/admin/agent-statement.php', ($isAgent && $bv) || ($bv && Auth::can('commissions.view'))],
            'cashbook'  => ['📒', 'क्यास बुक', 'Cash book', '/admin/cash-book.php', !$isAgent && $bv],
            'reschedule'=> ['📅', 'मिति सार्नुहोस्', 'Change date', '/admin/reschedule.php', $bv],
            'addmember' => ['＋', 'नयाँ सदस्य', 'Add a member', '/join.php?office=1', Auth::can('staff.manage')],
            'papers'    => ['📑', 'मेरो फारम, CV, सम्झौता', 'My form, CV & agreement', '/team/', !$viewer],
            'alltools'  => ['🧭', 'सबै उपकरण', 'All tools', '/admin/hub.php', $office && !$viewer],
        ];

        $out = [];
        foreach ($office ? self::OFFICE : self::FIELD as $key) {
            $t = $all[$key] ?? null;
            if ($t === null || empty($t[4])) {
                continue;
            }
            $out[] = ['key' => $key, 'icon' => $t[0], 'ne' => $t[1], 'en' => $t[2], 'href' => $t[3], 'badge' => (int) ($t[5] ?? 0)];
        }
        return ['hero' => array_slice($out, 0, self::HERO), 'more' => array_slice($out, self::HERO)];
    }

    /** Tickets this person sold (or cut at the counter for an agent code) today. Guarded: 0 on any error. */
    public static function todaySold(int $adminId): int
    {
        if ($adminId <= 0) {
            return 0;
        }
        try {
            $both = CounterDesk::hasColumn('bookings', 'booked_by_admin_id');
            return (int) Database::scalar(
                'SELECT COUNT(*) FROM bookings
                  WHERE ' . ($both ? '(sold_by_admin_id = :a OR booked_by_admin_id = :b)' : 'sold_by_admin_id = :a') . "
                    AND status IN ('confirmed','completed') AND created_at >= CURDATE()",
                $both ? ['a' => $adminId, 'b' => $adminId] : ['a' => $adminId], 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * The other active IDs on this account's own mobile (the "switch ID" button shows only when > 0).
     * Read from the account row, never from the browser.
     */
    public static function otherIds(int $adminId): int
    {
        try {
            $phone = (string) Database::scalar('SELECT phone FROM admins WHERE id = :i', ['i' => $adminId], '');
            $p10 = normalisePhone($phone);
            if (strlen($p10) !== 10 || isPlaceholderPhone($p10)) {
                return 0;
            }
            // 31 Oct 2026 (review): the same WHOLE number (+91 vs +977 share 10 digits) — never the other country's twin
            require_once __DIR__ . '/teamportal.php';
            $own = TeamPortal::intlFor($p10, ['id' => $adminId, 'phone' => $phone]);
            $n = 0;
            foreach (Auth::adminsByPhone($p10) as $a) {
                if ((int) $a['id'] !== $adminId && $own !== '' && TeamPortal::intlFor($p10, $a) === $own) {
                    $n++;
                }
            }
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * The number strip under the ID: an agent's own money and sales, a desk's drawer, or what waits
     * for the office. Only this person's own figures, never a company total, unless they hold dashboard.view.
     *
     * @param array<string, int> $counts
     * @return list<array{text: string, href: string, hot: bool}>
     */
    public static function strip(array $admin, array $counts = []): array
    {
        $id = (int) ($admin['id'] ?? 0);
        $out = [];
        if (Auth::isCounterAgent()) {
            try {
                $net = (float) (AgentWallet::netPosition($id)['net'] ?? 0);
            } catch (Throwable $e) {
                $net = 0.0;
            }
            $out[] = match (true) {
                $net > 0.004  => ['text' => '💵 कम्पनीलाई बुझाउन बाँकी · To hand over: ' . inr($net), 'href' => '/admin/agent.php', 'hot' => true],
                $net < -0.004 => ['text' => '💚 तपाईंले पाउन बाँकी · Due to you: ' . inr(-$net), 'href' => '/admin/agent.php', 'hot' => false],
                default       => ['text' => '✅ हिसाब बराबर · All square', 'href' => '/admin/agent.php', 'hot' => false],
            };
            $out[] = ['text' => '🎫 आज मैले काटेको · Sold by me today: ' . self::todaySold($id), 'href' => '/admin/bookings.php', 'hot' => false];
            return $out;
        }
        if (self::isOffice()) {
            $add = static function (int $n, string $what, string $href, string $perm) use (&$out): void {
                if ($n > 0 && Auth::can($perm)) {
                    $out[] = ['text' => $n . ' ' . $what, 'href' => $href, 'hot' => true];
                }
            };
            $add((int) ($counts['applications'] ?? 0), 'आवेदन स्वीकृति पर्खँदै · applications to approve', '/admin/team.php?tab=approvals', 'commissions.view');
            $add((int) ($counts['withdrawals'] ?? 0), 'पैसा झिक्ने अनुरोध · withdrawal requests', '/admin/team.php?tab=money', 'commissions.view');
            $add((int) ($counts['deposits'] ?? 0), 'एजेन्टको भुक्तानी मिलाउन · agent payments to match', '/admin/team.php?tab=money', 'commissions.view');
            $add((int) ($counts['pendingPay'] ?? 0), 'ग्राहक भुक्तानी जाँच्न · customer payments to verify', '/admin/payments.php', 'payments.view');
            $add((int) ($counts['kycWaiting'] ?? 0), 'KYC कागजात जाँच्न · KYC documents to check', '/admin/team.php?tab=agents&kyc=submitted', 'staff.manage');
            return $out;
        }
        if (Auth::can('bookings.edit')) {
            try {
                require_once __DIR__ . '/countershift.php';
                if (CounterShift::enabled()) {
                    $sh = CounterShift::current($id);
                    $out[] = $sh !== null
                        ? ['text' => '🕒 सिफ्ट खुला — दराजमा ' . inr((float) (CounterShift::live($sh)['expected'] ?? 0)) . ' · Shift open — in the drawer', 'href' => '/admin/shift.php', 'hot' => false]
                        : ['text' => '🕒 सिफ्ट सुरु गर्नुहोस् · Start your shift', 'href' => '/admin/shift.php', 'hot' => true];
                }
            } catch (Throwable $e) {
            }
            $out[] = ['text' => '🎫 आज मैले काटेको · Cut by me today: ' . self::todaySold($id), 'href' => '/admin/bookings.php?mine=1', 'hot' => false];
        }
        return $out;
    }
}
