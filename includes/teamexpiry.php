<?php
/**
 * =====================================================================
 *  TeamExpiry — agreements that run out (23 Oct 2026).
 *
 *  Owner prompt (registration system, point 10): a reminder on WhatsApp 30,
 *  15 and 7 days before the agreement ends; on the day after the end the
 *  agreement is "expired" (the ID card and the verify page already say
 *  "Agreement ended" once agreement_end has passed — IdCard::forAgent).
 *  Renewal is the flow that already exists: the person signs again at
 *  /team/agreement.php ("expired, to renew") and the office sets the
 *  agreement back to Signed on admin/agreements.php, which starts a new
 *  11-month term.
 *
 *  Who is checked:
 *    accounts      agent / partner logins (admin_profiles.agreement_status =
 *                  'signed' with an agreement_end) — the people who sell
 *    applications  approved agent / partner applications with a signed
 *                  agreement that are NOT linked to a login yet
 *  Staff are employees: their card has no agreement term, so they are not
 *  touched here.
 *
 *  Reminders are sent once per person, end date and step (30 / 15 / 7). A
 *  run that was missed sends only the nearest step, never three at once.
 *  The "sent" marks live in the settings map 'agreement_reminders' (no new
 *  table); marks for terms that ended more than 60 days ago are dropped.
 *
 *  Expiry writes ONLY agreement_status = 'expired' (the dates stay, so the
 *  office sees when it ended) and one audit line. The login is switched off
 *  too only when the office turned on 'agreement_expiry_lock' (default
 *  OFF: an agent who forgot to renew keeps selling until the office acts).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class TeamExpiry
{
    public const STEPS    = [30, 15, 7];
    public const MAP_KEY  = 'agreement_reminders';
    public const LOCK_KEY = 'agreement_expiry_lock';
    public const SEND_MAX = 25;   // WhatsApps per run

    private function __construct() {}

    /** The reminder step for $daysLeft days to go, or null (too early / already ended). */
    public static function step(int $daysLeft): ?int
    {
        if ($daysLeft < 0 || $daysLeft > self::STEPS[0]) {
            return null;
        }
        $hit = null;
        foreach (self::STEPS as $s) {
            if ($daysLeft <= $s) {
                $hit = $s;   // the smallest step that still covers the days left
            }
        }
        return $hit;
    }

    /**
     * Every signed agreement with an end date: accounts first, then approved
     * applications without a login.
     *
     * @return array<int, array{src: string, id: int, name: string, phone: string, kind: string, end: string}>
     */
    public static function signed(): array
    {
        $out = [];
        if (AgentApplications::profileColumnsAvailable()) {
            foreach (Database::fetchAll(
                "SELECT a.id, a.full_name, a.phone, p.display_phone, p.agent_kind, p.agreement_end
                   FROM admin_profiles p JOIN admins a ON a.id = p.admin_id AND a.role = 'agent'
                  WHERE p.agreement_status = 'signed' AND p.agreement_end IS NOT NULL"
            ) as $r) {
                $out[] = ['src' => 'account', 'id' => (int) $r['id'], 'name' => (string) $r['full_name'],
                          'phone' => (string) (($r['phone'] ?? '') ?: ($r['display_phone'] ?? '')),
                          'kind' => (string) ($r['agent_kind'] ?? '') === 'partner' ? 'partner' : 'agent',
                          'end' => substr((string) $r['agreement_end'], 0, 10)];
            }
        }
        if (AgentApplications::available()) {
            foreach (Database::fetchAll(
                "SELECT id, type, name, phone, phone_raw, agreement_end FROM agent_applications
                  WHERE status = 'approved' AND agreement_status = 'signed' AND agreement_end IS NOT NULL AND admin_id IS NULL"
            ) as $r) {
                $out[] = ['src' => 'application', 'id' => (int) $r['id'], 'name' => (string) $r['name'],
                          'phone' => (string) (($r['phone_raw'] ?? '') ?: $r['phone']),
                          'kind' => (string) $r['type'] === 'org' ? 'partner' : 'agent',
                          'end' => substr((string) $r['agreement_end'], 0, 10)];
            }
        }
        return array_values(array_filter($out, static fn(array $p): bool => $p['end'] !== '' && $p['end'] !== '0000-00-00'));
    }

    /**
     * One pass: expire what ended, remind what is close. $dry = count only.
     *
     * @return array{reminded: int, expired: int, locked: int, due30: int, failed: int}
     */
    public static function run(bool $dry = false, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $t0    = new DateTimeImmutable($today);
        $marks = Settings::getArray(self::MAP_KEY, []);
        $lock  = Settings::getBool(self::LOCK_KEY, false);
        $res   = ['reminded' => 0, 'expired' => 0, 'locked' => 0, 'due30' => 0, 'failed' => 0];
        $sent  = 0;

        foreach (self::signed() as $p) {
            $days = (int) $t0->diff(new DateTimeImmutable($p['end']))->format('%r%a');
            if ($days < 0) {
                $res['expired']++;
                if (!$dry) {
                    $res['locked'] += self::expire($p, $lock) ? 1 : 0;
                    self::send($p, self::text($p, null));
                }
                continue;
            }
            $step = self::step($days);
            if ($step === null) {
                continue;
            }
            $res['due30']++;
            $key = $p['src'] . ':' . $p['id'] . '|' . $p['end'] . '|' . $step;
            if (isset($marks[$key]) || $sent >= self::SEND_MAX) {
                continue;
            }
            if ($dry) {
                $res['reminded']++;
                continue;
            }
            // marked first: a WhatsApp that fails is not retried every run
            $marks[$key] = date('Y-m-d H:i');
            $sent++;
            if (self::send($p, self::text($p, $days)) === true) {
                $res['reminded']++;
            } else {
                $res['failed']++;
            }
        }

        if (!$dry) {
            $cut = $t0->modify('-60 days')->format('Y-m-d');
            foreach (array_keys($marks) as $k) {
                $end = explode('|', (string) $k)[1] ?? '';
                if ($end === '' || $end < $cut) {
                    unset($marks[$k]);
                }
            }
            Settings::set(self::MAP_KEY, $marks, 'json', 'agent', false);
        }
        return $res;
    }

    /** The WhatsApp text: $days = days left, null = ended. */
    public static function text(array $p, ?int $days): string
    {
        $who  = $p['kind'] === 'partner' ? 'partner' : 'agent';
        $end  = date('d M Y', strtotime($p['end']) ?: time());
        $link = appUrl('team/agreement.php');
        $name = trim((string) $p['name']);
        $hi   = '🙏 नमस्ते' . ($name !== '' ? ' ' . $name : '') . ' · S Hari Global';
        if ($days === null) {
            return $hi . "\n⛔ तपाईंको " . $who . " सम्झौता " . $end . " मा सकियो। ID card अब 'Agreement ended' देखाउँछ।\n"
                 . "Your " . $who . " agreement ended on " . $end . ".\n\n"
                 . "🔁 नवीकरण: यहाँ फेरि हस्ताक्षर गर्नुहोस् · Renew by signing again:\n" . $link . "\n"
                 . "कार्यालयले हेरेर नयाँ अवधि सुरु गर्नेछ · The office then starts the new term.";
        }
        return $hi . "\n⏳ तपाईंको " . $who . " सम्झौता " . $end . " मा सकिँदैछ (" . $days . " दिन बाँकी)।\n"
             . "Your " . $who . " agreement ends on " . $end . " (" . $days . " day" . ($days === 1 ? '' : 's') . " left).\n\n"
             . "🔁 नवीकरण गर्न: नयाँ ID कागज (चाहिए भने) राखेर फेरि हस्ताक्षर गर्नुहोस् · To renew, sign again (and upload a new ID if it changed):\n"
             . $link;
    }

    /** agreement_status -> 'expired' (+ the login off when the office asked for it). True when a login was switched off. */
    private static function expire(array $p, bool $lock): bool
    {
        $locked = false;
        if ($p['src'] === 'account') {
            Database::run("UPDATE admin_profiles SET agreement_status = 'expired' WHERE admin_id = :i AND agreement_status = 'signed'", ['i' => $p['id']]);
            if (AgentApplications::available()) {
                Database::run("UPDATE agent_applications SET agreement_status = 'expired' WHERE admin_id = :i AND agreement_status = 'signed'", ['i' => $p['id']]);
            }
            if ($lock) {
                $locked = Database::run("UPDATE admins SET is_active = 0 WHERE id = :i AND role = 'agent' AND is_active = 1", ['i' => $p['id']]) > 0;
            }
        } else {
            Database::run("UPDATE agent_applications SET agreement_status = 'expired' WHERE id = :i AND agreement_status = 'signed'", ['i' => $p['id']]);
        }
        Logger::audit('agreement.expired', $p['src'] === 'account' ? 'admin' : 'agent_application', (string) $p['id'],
            ['agreement_status' => 'signed', 'agreement_end' => $p['end']], ['agreement_status' => 'expired'] + ($locked ? ['is_active' => 0] : []),
            'cron/agreement-expiry.php: the agreement end date passed' . ($locked ? ' (login switched off: agreement_expiry_lock)' : ''));
        return $locked;
    }

    private static function send(array $p, string $text): bool|string
    {
        $phone = Notify::usablePhone($p['phone']);
        if ($phone === '') {
            return false;
        }
        try {
            return Notify::whatsapp($phone, $text, null, null, [], null, ['purpose' => 'agreement_renewal']);
        } catch (Throwable $e) {
            Logger::warning('TeamExpiry WhatsApp: ' . $e->getMessage(), ['src' => $p['src'], 'id' => $p['id']], 'whatsapp');
            return false;
        }
    }
}
