<?php
/**
 * GET /api/nav-offices.php — S Hari Global offices for the SHARI NAV map
 * (22 Oct 2026). The public offices the desk keeps in Admin -> Counters
 * (counter_locations: show_public = 1, active, with a map pin), so a new
 * office or a moved pin shows on the map without touching the page.
 *
 * -> {ok:true, offices:[{code, name, name_ne, name_hi, kind, address, hours, phone, whatsapp, lat, lng}]}
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$out = [];
try {
    foreach (Database::fetchAll(
        "SELECT code, name, name_ne, name_hi, office_kind, address, hours, phone, whatsapp, lat + 0 AS lat, lng + 0 AS lng
           FROM counter_locations
          WHERE is_active = 1 AND show_public = 1 AND public_status <> 'closed' AND lat IS NOT NULL AND lng IS NOT NULL
          ORDER BY sort_order, id LIMIT 60"
    ) as $o) {
        $out[] = [
            'code'     => (string) $o['code'],
            'name'     => (string) $o['name'],
            'name_ne'  => (string) ($o['name_ne'] ?? ''),
            'name_hi'  => (string) ($o['name_hi'] ?? ''),
            'kind'     => (string) ($o['office_kind'] ?? ''),
            'address'  => (string) ($o['address'] ?? ''),
            'hours'    => (string) ($o['hours'] ?? ''),
            'phone'    => (string) ($o['phone'] ?? ''),
            'whatsapp' => (string) ($o['whatsapp'] ?? ''),
            'lat'      => round((float) $o['lat'], 6),
            'lng'      => round((float) $o['lng'], 6),
        ];
    }
} catch (Throwable $e) {
    $out = [];                                            // an older schema without lat/lng: no pins
}
header('Cache-Control: public, max-age=600');
echo json_encode(['ok' => true, 'offices' => $out], JSON_UNESCAPED_UNICODE);
