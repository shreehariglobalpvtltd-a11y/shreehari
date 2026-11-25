<?php
/**
 * =====================================================================
 *  CompanyLocations — the company's own places: parkings, offices,
 *  pickup yards (21 Nov 2026).
 *
 *  Owner: "S Hari Parking, Rupaidiha–Nepalgunj border pari ko parking,
 *  Chiloda, Mehsana, Surat, Nepalgunj, Rupaidiha afno parking - sabai thau
 *  ko location, map, photo, contact details setting ma halne thau rakhdinus,
 *  m fill garchhu; save garepachhi customer ma dekhiyos; aile naam matra
 *  dekhinchha."
 *
 *  These are PLACES, not ticket desks: a desk (CounterDesk) sells and keeps
 *  a drawer; a place is where a passenger goes. So they live in their own
 *  table and never become a seller.
 *
 *  - The office fills each place on admin/locations.php: names (English /
 *    Nepali), what it is, address, Google Maps link, phone, WhatsApp, the
 *    person to ask, hours, up to three photos, and "show on website".
 *  - The customer sees a place on /offices ONLY when it is switched on AND
 *    has at least a map link or an address - an empty seed row never shows.
 *  - A ticket's pickup map QR uses a place's map link when the pickup is in
 *    that place's town and the stop itself has no pin (Ticket::mapQrs).
 *
 *  Six places are seeded (owner's list); rows with no address stay hidden.
 *  Every read degrades to [] while the table is missing.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class CompanyLocations
{
    public const KINDS = [
        'parking' => ['पार्किङ', 'Parking'],
        'office'  => ['कार्यालय', 'Office'],
        'pickup'  => ['चढ्ने ठाउँ', 'Pickup point'],
        'border'  => ['बोर्डर', 'Border point'],
    ];

    /**
     * The owner's list (21 Nov 2026). Filled from the owner's message + the
     * shop signboard photo the same day; a row with an address or map is
     * shown on the website at once, the rest stay hidden until filled.
     * [name, name_ne, kind, town, address, map, phone, person]
     */
    private const SEED = [
        ['S Hari Parking — Nana Chiloda, Ahmedabad', 'एस हरि पार्किङ — नाना चिलोडा, अहमदाबाद', 'parking', 'Chiloda', '', '', '9157001507', ''],
        ['Head Office — Silver Complex, Mehsana', 'मुख्य कार्यालय — सिल्भर कम्प्लेक्स, मेहसाना', 'office', 'Mehsana', 'Silver Complex, Mehsana, Gujarat', '', '9104801507', ''],
        ['Surat — Kamrej Char Rasta', 'सुरत — कामरेज चार रस्ता', 'pickup', 'Surat', 'Kamrej Char Rasta, Surat, Gujarat', '', '7359301507', ''],
        ['Rupaidiha — own parking', 'रुपैडिहा — आफ्नै पार्किङ', 'parking', 'Rupaidiha', '', '', '', ''],
        ['Rupaidiha / Nepalgunj border — parking', 'रुपैडिहा / नेपालगञ्ज बोर्डर — पार्किङ', 'border', 'Rupaidiha', '', '', '', ''],
        ['Nepalgunj Office — Puspalal Chowk', 'नेपालगञ्ज कार्यालय — पुष्पलाल चोक', 'office', 'Nepalgunj',
            'Puspalal Chowk-2, Nepalgunj Sub-Metropolitan City, Banke, Nepal', 'https://www.google.com/maps?q=28.071306,81.632472',
            '+977 9866201375 / 9825788450', 'Lok B. Sunar · Ripesh Magar'],
    ];

    public const MAX_PHOTOS = 3;

    private static ?bool $ready = null;

    public static function ensure(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            Database::query(
                "CREATE TABLE IF NOT EXISTS company_locations (
                    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name           VARCHAR(160)  NOT NULL,
                    name_ne        VARCHAR(160)  NULL,
                    kind           VARCHAR(12)   NOT NULL DEFAULT 'parking',
                    town           VARCHAR(60)   NULL,
                    address        VARCHAR(255)  NULL,
                    address_ne     VARCHAR(255)  NULL,
                    map_url        VARCHAR(500)  NULL,
                    phone          VARCHAR(60)   NULL,
                    whatsapp       VARCHAR(30)   NULL,
                    contact_person VARCHAR(120)  NULL,
                    hours          VARCHAR(120)  NULL,
                    note           VARCHAR(255)  NULL,
                    photos         TEXT          NULL,
                    show_public    TINYINT(1)    NOT NULL DEFAULT 0,
                    sort_order     INT           NOT NULL DEFAULT 0,
                    updated_by     INT UNSIGNED  NULL,
                    created_at     DATETIME      NOT NULL,
                    updated_at     DATETIME      NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            if ((int) Database::scalar('SELECT COUNT(*) FROM company_locations', [], 0) === 0) {
                foreach (self::SEED as $i => [$en, $ne, $kind, $town, $addr, $map, $phone, $person]) {
                    Database::insert('company_locations', ['name' => $en, 'name_ne' => $ne, 'kind' => $kind, 'town' => $town,
                        'address' => $addr ?: null, 'map_url' => $map ?: null, 'phone' => $phone ?: null, 'contact_person' => $person ?: null,
                        'whatsapp' => $town === 'Nepalgunj' ? '9779866201375' : null,
                        'show_public' => ($addr !== '' || $map !== '') ? 1 : 0, 'sort_order' => ($i + 1) * 10, 'created_at' => date('Y-m-d H:i:s')]);
                }
            }
            self::$ready = true;
        } catch (Throwable $e) {
            Logger::warning('company_locations unavailable: ' . $e->getMessage());
            self::$ready = false;
        }
        return self::$ready;
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        if (!self::ensure()) {
            return [];
        }
        return array_map([self::class, 'shape'], Database::fetchAll('SELECT * FROM company_locations ORDER BY sort_order, id'));
    }

    public static function get(int $id): ?array
    {
        if (!self::ensure()) {
            return null;
        }
        $r = Database::fetch('SELECT * FROM company_locations WHERE id = :i', ['i' => $id]);
        return $r !== null ? self::shape($r) : null;
    }

    /** Places the customer sees: switched on and with a map link or an address. */
    public static function forPublic(): array
    {
        return array_values(array_filter(self::all(), static fn(array $p): bool =>
            $p['show_public'] && ($p['map'] !== '' || $p['address'] !== '')));
    }

    /** A place's map link for a pickup in its town ('' when none is filled). */
    public static function mapForTown(string $label): string
    {
        $l = mb_strtolower($label);
        foreach (self::all() as $p) {
            $t = mb_strtolower(trim($p['town']));
            if ($t !== '' && $p['map'] !== '' && str_contains($l, $t)) {
                return $p['map'];
            }
        }
        return '';
    }

    /**
     * For an office card (wx1-c1 review): the best place of this exact town that
     * the office let the customer see (show_public) and that has $field ('map' /
     * 'whatsapp') filled. Office first, then pickup, then parking; a border point
     * or a hidden place is never used. null when none.
     */
    public static function publicPlaceForTown(string $town, string $field): ?array
    {
        $key = (string) preg_replace('/[^a-z]/', '', strtolower($town));
        if ($key === '') {
            return null;
        }
        $rank = ['office' => 0, 'pickup' => 1, 'parking' => 2];
        $best = null;
        foreach (self::all() as $p) {
            if (!$p['show_public'] || !isset($rank[$p['kind']]) || trim((string) ($p[$field] ?? '')) === '') {
                continue;
            }
            if ((string) preg_replace('/[^a-z]/', '', strtolower((string) $p['town'])) !== $key) {
                continue;
            }
            if ($best === null || $rank[$p['kind']] < $rank[$best['kind']]) {
                $best = $p;
            }
        }
        return $best;
    }

    /** @param array<string,mixed> $r */
    private static function shape(array $r): array
    {
        $photos = json_decode((string) ($r['photos'] ?? '[]'), true);
        return [
            'id'          => (int) $r['id'],
            'name'        => (string) $r['name'],
            'nameNe'      => (string) ($r['name_ne'] ?? ''),
            'kind'        => isset(self::KINDS[(string) $r['kind']]) ? (string) $r['kind'] : 'parking',
            'town'        => (string) ($r['town'] ?? ''),
            'address'     => (string) ($r['address'] ?? ''),
            'addressNe'   => (string) ($r['address_ne'] ?? ''),
            'map'         => (string) ($r['map_url'] ?? ''),
            'phone'       => (string) ($r['phone'] ?? ''),
            'whatsapp'    => (string) ($r['whatsapp'] ?? ''),
            'person'      => (string) ($r['contact_person'] ?? ''),
            'hours'       => (string) ($r['hours'] ?? ''),
            'note'        => (string) ($r['note'] ?? ''),
            'photos'      => is_array($photos) ? array_values(array_filter($photos, 'is_string')) : [],
            'show_public' => (int) ($r['show_public'] ?? 0) === 1,
            'sort'        => (int) ($r['sort_order'] ?? 0),
            'filled'      => trim((string) ($r['map_url'] ?? '')) !== '' || trim((string) ($r['address'] ?? '')) !== '',
        ];
    }

    /** A Google Maps link, or a bare "lat,lng" turned into one; anything else is refused. */
    public static function cleanMap(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\s*(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*$/', $raw, $m) === 1) {
            return 'https://www.google.com/maps?q=' . $m[1] . ',' . $m[2];
        }
        $host = strtolower((string) parse_url($raw, PHP_URL_HOST));
        if (!str_starts_with(strtolower($raw), 'https://') || !preg_match('/(^|\.)(google\.[a-z.]+|goo\.gl|maps\.app\.goo\.gl|g\.co)$/', $host)) {
            throw new RuntimeException('Google Maps को link (https://maps.app.goo.gl/… वा https://www.google.com/maps/…) वा "lat,lng" मात्र हाल्नुहोस्।');
        }
        return mb_substr($raw, 0, 500);
    }

    /**
     * Save one place. $photos is the new list of photo paths (already saved).
     *
     * @param array<string,mixed> $in
     */
    public static function save(array $in, int $adminId, ?int $id = null, ?array $photos = null): int
    {
        if (!self::ensure()) {
            throw new RuntimeException('Locations table is not ready.');
        }
        $name = trim(Security::clean((string) ($in['name'] ?? ''), 160));
        if ($name === '') {
            throw new RuntimeException('नाम लेख्नुहोस् · Give the place a name.');
        }
        $kind = (string) ($in['kind'] ?? 'parking');
        $wa = (string) preg_replace('/\D/', '', (string) ($in['whatsapp'] ?? ''));
        $data = [
            'name'           => $name,
            'name_ne'        => trim(Security::clean((string) ($in['name_ne'] ?? ''), 160)) ?: null,
            'kind'           => isset(self::KINDS[$kind]) ? $kind : 'parking',
            'town'           => trim(Security::clean((string) ($in['town'] ?? ''), 60)) ?: null,
            'address'        => trim(Security::clean((string) ($in['address'] ?? ''), 255)) ?: null,
            'address_ne'     => trim(Security::clean((string) ($in['address_ne'] ?? ''), 255)) ?: null,
            'map_url'        => self::cleanMap((string) ($in['map_url'] ?? '')) ?: null,
            'phone'          => trim(Security::clean((string) ($in['phone'] ?? ''), 60)) ?: null,
            'whatsapp'       => $wa !== '' ? substr($wa, 0, 15) : null,
            'contact_person' => trim(Security::clean((string) ($in['contact_person'] ?? ''), 120)) ?: null,
            'hours'          => trim(Security::clean((string) ($in['hours'] ?? ''), 120)) ?: null,
            'note'           => trim(Security::clean((string) ($in['note'] ?? ''), 255)) ?: null,
            'show_public'    => !empty($in['show_public']) ? 1 : 0,
            'sort_order'     => (int) ($in['sort_order'] ?? 0),
            'updated_by'     => $adminId > 0 ? $adminId : null,
            'updated_at'     => date('Y-m-d H:i:s'),
        ];
        if ($photos !== null) {
            $data['photos'] = json_encode(array_slice(array_values($photos), 0, self::MAX_PHOTOS));
        }
        if ($id !== null && $id > 0) {
            $before = self::get($id);
            Database::update('company_locations', $data, 'id = :i', ['i' => $id]);
            Logger::audit('location.save', 'company_location', (string) $id, $before, $data, 'Company place edited');
            return $id;
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        $new = (int) Database::insert('company_locations', $data);
        Logger::audit('location.add', 'company_location', (string) $new, null, $data, 'Company place added');
        return $new;
    }

    public static function delete(int $id): void
    {
        if (!self::ensure()) {
            return;
        }
        $before = self::get($id);
        Database::run('DELETE FROM company_locations WHERE id = :i', ['i' => $id]);
        Logger::audit('location.delete', 'company_location', (string) $id, $before, null, 'Company place removed');
    }
}
