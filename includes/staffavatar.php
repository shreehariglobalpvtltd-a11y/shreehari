<?php
/**
 * includes/staffavatar.php — who is signed in, at a glance (25 Nov 2026, release mp1-lg).
 *
 * Owner plan, section 2: "mathi profile photo, pura naam, ra kun mode ma chha
 * (Admin / Counter / Staff / Agent) rang ma". Every staff page draws its top bar
 * in admin/_guard.php admin_header(); this class gives it three things:
 *
 *   - the person's own photo, the one already uploaded for their ID card
 *     (admin_profiles.photo_path = agents/<file>, AgentPro::setPhotoFromFile;
 *     ap_members.photo_ver busts the cache) — never a URL built from a request;
 *   - when there is no photo: a coloured circle with their initials, the colour
 *     taken from a hash of the name so a person keeps the same colour;
 *   - a coloured MODE badge derived from the REAL role on the account
 *     (एडमिन Admin / काउन्टर Counter / स्टाफ Staff / एजेन्ट Agent). It is a
 *     label only — nothing here reads or changes a permission.
 *
 * Read-only, one small query per request (cached), and every lookup is inside
 * try/catch: a missing table or a broken row gives the initials, never an error
 * on the 134 pages that use the header. Switch: staff_avatar_on (default on).
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class StaffAvatar
{
    /** Mode key => Nepali word, English word, badge colour (white text, contrast >= 4.5:1). */
    public const MODES = [
        'admin'   => ['ne' => 'एडमिन',  'en' => 'Admin',   'bg' => '#6B2FA3'],
        'counter' => ['ne' => 'काउन्टर', 'en' => 'Counter', 'bg' => '#1D4FA3'],
        'staff'   => ['ne' => 'स्टाफ',   'en' => 'Staff',   'bg' => '#0E7C66'],
        'agent'   => ['ne' => 'एजेन्ट',  'en' => 'Agent',   'bg' => '#B4530A'],
    ];

    /** Initials circle colours — all dark enough for white letters. */
    private const PALETTE = ['#1D4FA3', '#0E7C66', '#B4530A', '#6B2FA3', '#A3263B', '#2F6B1F', '#8A5A00', '#0B6E8A'];

    /** @var array<int, string> admin id => photo URL ('' = none) */
    private static array $photoCache = [];

    public static function on(): bool
    {
        try {
            return Settings::getBool('staff_avatar_on', true);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The mode a role belongs to. Office roles = admin; agent / counter as named; the rest = staff. */
    public static function modeFor(string $role): string
    {
        return match ($role) {
            'agent'                                          => 'agent',
            'counter'                                        => 'counter',
            'superadmin', 'manager', 'accountant', 'operations' => 'admin',
            default                                          => 'staff',
        };
    }

    /** Up to two letters: first letter of the first two words, upper-cased. */
    public static function initials(string $name): string
    {
        $name  = trim((string) preg_replace('/\s+/u', ' ', $name));
        $words = $name === '' ? [] : explode(' ', $name);
        $out   = '';
        foreach (array_slice($words, 0, 2) as $w) {
            $out .= mb_substr($w, 0, 1);
        }
        $out = mb_strtoupper($out);
        return $out !== '' ? $out : '★';
    }

    /** The same name always gets the same colour. */
    public static function color(string $name): string
    {
        $n = mb_strtolower(trim($name));
        return self::PALETTE[$n === '' ? 0 : (int) (crc32($n) % count(self::PALETTE))];
    }

    /**
     * The photo URL for this account ('' = none / not found / any failure).
     * Only a stored "agents/<safe file name>" that exists under uploads/ is used.
     */
    public static function photoUrl(int $adminId): string
    {
        if ($adminId <= 0) {
            return '';
        }
        if (array_key_exists($adminId, self::$photoCache)) {
            return self::$photoCache[$adminId];
        }
        $url = '';
        try {
            $path = (string) (Database::scalar('SELECT photo_path FROM admin_profiles WHERE admin_id = :a', ['a' => $adminId], '') ?? '');
            if (preg_match('#^agents/[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.(?:jpe?g|png|webp)$#i', $path) === 1
                && !str_contains($path, '..')
                && (!defined('UPLOAD_PATH') || is_file(UPLOAD_PATH . '/' . $path))) {
                $ver = 1;
                try {
                    $ver = (int) (Database::scalar('SELECT photo_ver FROM ap_members WHERE admin_id = :a', ['a' => $adminId], 1) ?? 1);
                } catch (Throwable $e) {
                    $ver = 1;   // ap_members not there yet: no cache-bust number, the photo still shows
                }
                $url = '/uploads/' . $path . '?v=' . max(1, $ver);
            }
        } catch (Throwable $e) {
            $url = '';      // admin_profiles missing or the database hiccupped: initials
        }
        return self::$photoCache[$adminId] = $url;
    }

    /** Forget the per-request cache (tests). */
    public static function flush(): void
    {
        self::$photoCache = [];
    }

    /**
     * The avatar markup: the photo, else the initials circle. Escaped.
     *
     * @param array<string, mixed> $admin needs id, full_name / username
     */
    public static function html(array $admin, int $size = 32, string $cls = 'sa-av'): string
    {
        $name = trim((string) (($admin['full_name'] ?? '') ?: ($admin['username'] ?? '')));
        $size = max(16, min(96, $size));
        $url  = '';
        try {
            $url = self::photoUrl((int) ($admin['id'] ?? 0));
        } catch (Throwable $e) {
            $url = '';
        }
        if ($url !== '') {
            return '<img class="' . Security::e($cls) . ' sa-img" src="' . Security::e($url) . '" alt="" width="' . $size . '" height="' . $size . '" decoding="async">';
        }
        return '<span class="' . Security::e($cls) . ' sa-ini" style="background:' . Security::e(self::color($name)) . '" aria-hidden="true">'
             . Security::e(self::initials($name)) . '</span>';
    }

    /**
     * The coloured mode badge for this account's REAL role.
     *
     * @param array<string, mixed> $admin needs role
     */
    public static function badge(array $admin): string
    {
        $role = (string) ($admin['role'] ?? '');
        $key  = self::modeFor($role);
        $m    = self::MODES[$key];
        return '<span class="sa-mode sa-m-' . $key . '" style="background:' . $m['bg'] . '" title="' . Security::e(ucfirst($role)) . '">'
             . Security::e($m['ne']) . ' · ' . Security::e($m['en']) . '</span>';
    }

    /** CSS for the avatar, the badge and the phone bar slot (admin_header adds it once). */
    public static function css(): string
    {
        return <<<'CSS'
.sa-av{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;border-radius:50%;object-fit:cover;color:#fff;font-weight:800;line-height:1;letter-spacing:.02em;box-shadow:0 0 0 2px rgba(255,255,255,.55);vertical-align:middle;overflow:hidden}
.sa-av.sa-28{width:28px;height:28px;font-size:12px}
.sa-av.sa-32{width:32px;height:32px;font-size:13px}
.sa-av.sa-44{width:44px;height:44px;font-size:17px}
.sa-mode{display:inline-flex;align-items:center;min-height:20px;padding:0 8px;border-radius:99px;color:#fff;font-size:11.5px;font-weight:700;font-style:normal;line-height:1.2;white-space:nowrap;vertical-align:middle}
.tb .who .sa-av{margin-right:6px}
.tb .who .sa-mode{margin:0 2px 0 6px}
.tb .who a.who-me{text-decoration:none;display:inline-flex;align-items:center}
.tb-me{display:none}
.side-me-h{display:flex;align-items:center;gap:10px;min-width:0}
.side-me-h>div{min-width:0}
.side-me-h .sa-mode{margin-top:3px}
CSS;
    }

    /** Phone (<= 600px, staff_phone_min_on): one 36px slot between search and lock — still one 52px bar. */
    public static function phoneCss(): string
    {
        return <<<'CSS'
@media(max-width:600px){
  .tb.tb-av{grid-template-columns:44px minmax(0,1fr) 36px 44px;grid-template-areas:"menu search me lock"}
  .tb.tb-av:not(:has(.tb-search)){grid-template-areas:"menu brand me lock"}
  .tb.tb-av .tb-me{grid-area:me;display:flex;align-items:center;justify-content:center;width:36px;height:44px;text-decoration:none}
  .tb.tb-av .tb-me .sa-av{width:32px;height:32px;font-size:13px}
}
CSS;
    }
}
