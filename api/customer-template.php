<?php
/**
 * POST /api/customer-template.php — Customer Form Template CRUD
 *
 * A signed-in customer saves their passenger group (name / age / gender)
 * so repeat bookings auto-fill in one tap. Also generates a WhatsApp-
 * shareable link token for quick form filling.
 *
 * Actions:
 *   list   — all templates for the signed-in customer
 *   get    — one template by id (owner only)
 *   save   — create or update (id present = update)
 *   delete — remove a template
 *   token  — generate a short-lived link token for WhatsApp prefill
 *   apply  — redeem a token (no auth needed — the token IS the auth)
 *   auto   — auto-create a template from the customer's last booking
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    Security::requirePost();

    $in     = Response::input();
    $action = Security::clean($in['action'] ?? '', 20);

    // "apply" is the only action that works without a session — the token
    // itself authenticates (it is short-lived and single-use-ish).
    if ($action === 'apply') {
        $token = Security::clean($in['token'] ?? '', 40);
        if ($token === '') {
            Response::invalid(['token' => 'Token required.']);
        }
        $row = Database::fetch(
            'SELECT t.*, ft.passengers, ft.contact_name, ft.contact_email,
                    ft.id_type, ft.id_number, ft.country_code, ft.label, ft.phone
               FROM form_template_tokens t
               JOIN customer_form_templates ft ON ft.id = t.template_id
              WHERE t.token = :tk AND t.expires_at > NOW()
              LIMIT 1',
            ['tk' => $token]
        );
        if (!$row) {
            Response::json(['ok' => false, 'error' => 'Link expired or invalid.'], 410);
        }
        // Mark used (but allow re-use within the window — a reload should work)
        Database::run('UPDATE form_template_tokens SET used = 1 WHERE token = :tk', ['tk' => $token]);
        // Bump use_count
        Database::run('UPDATE customer_form_templates SET use_count = use_count + 1, last_used_at = NOW() WHERE id = :id', ['id' => (int) $row['template_id']]);

        $pax = json_decode($row['passengers'], true) ?: [];
        Response::json([
            'ok'   => true,
            'data' => [
                'label'       => $row['label'],
                'contactName' => $row['contact_name'],
                'contactEmail'=> $row['contact_email'],
                'phone'       => $row['phone'],
                'countryCode' => $row['country_code'],
                'idType'      => $row['id_type'],
                'idNumber'    => $row['id_number'],
                'passengers'  => $pax,
                'routeId'     => $row['route_id'] ? (int) $row['route_id'] : null,
                'travelDate'  => $row['travel_date'],
            ],
        ]);
    }

    // Everything else requires a signed-in customer.
    Security::requireCsrf();
    $user = Auth::user();
    if (!$user) {
        Response::json(['ok' => false, 'error' => 'Sign in first.'], 401);
    }
    $phone = (string) $user['phone'];
    Security::requireRateLimit('cust_template', Security::clientIp(), 30, 300);

    // ── LIST ──
    if ($action === 'list') {
        $rows = Database::fetchAll(
            'SELECT id, label, contact_name, passengers, use_count, last_used_at, created_at
               FROM customer_form_templates WHERE phone = :p ORDER BY use_count DESC, id DESC',
            ['p' => $phone]
        );
        $out = [];
        foreach ($rows as $r) {
            $pax = json_decode($r['passengers'], true) ?: [];
            $out[] = [
                'id'          => (int) $r['id'],
                'label'       => $r['label'],
                'contactName' => $r['contact_name'],
                'paxCount'    => count($pax),
                'passengers'  => $pax,
                'useCount'    => (int) $r['use_count'],
                'lastUsed'    => $r['last_used_at'],
                'created'     => $r['created_at'],
            ];
        }
        Response::json(['ok' => true, 'data' => $out]);
    }

    // ── GET ──
    if ($action === 'get') {
        $id  = (int) ($in['id'] ?? 0);
        $row = Database::fetch(
            'SELECT * FROM customer_form_templates WHERE id = :id AND phone = :p LIMIT 1',
            ['id' => $id, 'p' => $phone]
        );
        if (!$row) {
            Response::json(['ok' => false, 'error' => 'Template not found.'], 404);
        }
        Response::json(['ok' => true, 'data' => formatTemplate($row)]);
    }

    // ── SAVE (create or update) ──
    if ($action === 'save') {
        $id    = (int) ($in['id'] ?? 0);
        $label = trim(Security::clean($in['label'] ?? '', 120));
        if ($label === '') $label = 'My Group';

        $contactName  = trim(Security::clean($in['contactName'] ?? '', 120));
        $contactEmail = Security::email($in['contactEmail'] ?? '');
        $idType       = trim(Security::clean($in['idType'] ?? '', 60));
        $idNumber     = trim(Security::clean($in['idNumber'] ?? '', 60));
        $cc           = in_array((string) ($in['countryCode'] ?? ''), ['91', '977'], true) ? (string) $in['countryCode'] : '91';

        $rawPax = (array) ($in['passengers'] ?? []);
        if (count($rawPax) < 1 || count($rawPax) > 20) {
            Response::invalid(['passengers' => 'Add 1–20 passengers.']);
        }

        $passengers = [];
        foreach ($rawPax as $i => $p) {
            if (!is_array($p)) continue;
            $name = trim(Security::clean($p['name'] ?? '', 120));
            if ($name === '') continue;
            $age = isset($p['age']) ? max(0, min(99, (int) $p['age'])) : null;
            $gender = in_array($p['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $p['gender'] : null;
            $passengers[] = ['name' => $name, 'age' => $age, 'gender' => $gender];
        }
        if (count($passengers) < 1) {
            Response::invalid(['passengers' => 'At least one passenger name is required.']);
        }

        $data = [
            'phone'        => $phone,
            'label'        => $label,
            'contact_name' => $contactName ?: null,
            'contact_email'=> $contactEmail ?: null,
            'id_type'      => $idType ?: null,
            'id_number'    => $idNumber ?: null,
            'country_code' => $cc,
            'passengers'   => json_encode($passengers, JSON_UNESCAPED_UNICODE),
        ];

        if ($id > 0) {
            // Update — must own it
            $existing = Database::fetch(
                'SELECT id FROM customer_form_templates WHERE id = :id AND phone = :p LIMIT 1',
                ['id' => $id, 'p' => $phone]
            );
            if (!$existing) {
                Response::json(['ok' => false, 'error' => 'Template not found.'], 404);
            }
            Database::update('customer_form_templates', $data, 'id = :id', ['id' => $id]);
        } else {
            // Create — cap at 10 templates per customer
            $count = (int) Database::scalar('SELECT COUNT(*) FROM customer_form_templates WHERE phone = :p', ['p' => $phone], 0);
            if ($count >= 10) {
                Response::json(['ok' => false, 'error' => 'Maximum 10 templates. Delete an old one first.'], 409);
            }
            $id = Database::insert('customer_form_templates', $data);
        }

        $row = Database::fetch('SELECT * FROM customer_form_templates WHERE id = :id', ['id' => $id]);
        Response::json(['ok' => true, 'data' => formatTemplate($row), 'message' => 'Template saved.']);
    }

    // ── DELETE ──
    if ($action === 'delete') {
        $id = (int) ($in['id'] ?? 0);
        $existing = Database::fetch(
            'SELECT id FROM customer_form_templates WHERE id = :id AND phone = :p LIMIT 1',
            ['id' => $id, 'p' => $phone]
        );
        if (!$existing) {
            Response::json(['ok' => false, 'error' => 'Template not found.'], 404);
        }
        Database::run('DELETE FROM customer_form_templates WHERE id = :id', ['id' => $id]);
        Response::json(['ok' => true, 'message' => 'Template deleted.']);
    }

    // ── TOKEN — generate a WhatsApp-shareable link ──
    if ($action === 'token') {
        $templateId = (int) ($in['id'] ?? 0);
        $existing = Database::fetch(
            'SELECT id FROM customer_form_templates WHERE id = :id AND phone = :p LIMIT 1',
            ['id' => $templateId, 'p' => $phone]
        );
        if (!$existing) {
            Response::json(['ok' => false, 'error' => 'Template not found.'], 404);
        }
        $routeId   = isset($in['routeId']) ? (int) $in['routeId'] : null;
        $travelDate = Security::clean($in['travelDate'] ?? '', 10);
        if ($travelDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $travelDate)) {
            $travelDate = null;
        }

        $token = bin2hex(random_bytes(16));
        Database::insert('form_template_tokens', [
            'token'       => $token,
            'template_id' => $templateId,
            'route_id'    => $routeId ?: null,
            'travel_date' => $travelDate ?: null,
            'expires_at'  => date('Y-m-d H:i:s', time() + 72 * 3600), // 72 hours
        ]);

        $base = Settings::get('site_url', 'https://www.shreehariglobal.in');
        $link = $base . '/#/book?tpl=' . $token;

        Response::json([
            'ok'   => true,
            'data' => [
                'token' => $token,
                'link'  => $link,
                'expiresAt' => date('Y-m-d H:i:s', time() + 72 * 3600),
            ],
        ]);
    }

    // ── AUTO — create a template from the customer's last booking ──
    if ($action === 'auto') {
        $lastBooking = Database::fetch(
            'SELECT b.id, b.contact_phone, b.contact_email, b.id_type, b.id_number, b.contact_country_code
               FROM bookings b
              WHERE b.contact_phone = :p AND b.status IN ("confirmed","completed")
              ORDER BY b.id DESC LIMIT 1',
            ['p' => $phone]
        );
        if (!$lastBooking) {
            Response::json(['ok' => false, 'error' => 'No past bookings found.'], 404);
        }
        $paxRows = Database::fetchAll(
            'SELECT full_name, age, gender FROM booking_passengers WHERE booking_id = :bid ORDER BY is_primary DESC, id ASC',
            ['bid' => (int) $lastBooking['id']]
        );
        if (count($paxRows) < 1) {
            Response::json(['ok' => false, 'error' => 'No passenger data on last booking.'], 404);
        }
        $passengers = [];
        foreach ($paxRows as $p) {
            $passengers[] = [
                'name'   => $p['full_name'],
                'age'    => $p['age'] ? (int) $p['age'] : null,
                'gender' => $p['gender'],
            ];
        }
        // Check if a template with same passengers already exists
        $existing = Database::fetchAll(
            'SELECT id, passengers FROM customer_form_templates WHERE phone = :p',
            ['p' => $phone]
        );
        foreach ($existing as $ex) {
            $exPax = json_decode($ex['passengers'], true) ?: [];
            if (count($exPax) === count($passengers)) {
                $match = true;
                foreach ($exPax as $i => $ep) {
                    if (strtolower(trim($ep['name'] ?? '')) !== strtolower(trim($passengers[$i]['name'] ?? ''))) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    $row = Database::fetch('SELECT * FROM customer_form_templates WHERE id = :id', ['id' => (int) $ex['id']]);
                    Response::json(['ok' => true, 'data' => formatTemplate($row), 'message' => 'Template already exists.', 'existing' => true]);
                }
            }
        }

        $leadName = $paxRows[0]['full_name'] ?? '';
        $id = Database::insert('customer_form_templates', [
            'phone'        => $phone,
            'label'        => count($passengers) > 1 ? $leadName . ' + ' . (count($passengers) - 1) : $leadName,
            'contact_name' => $leadName,
            'contact_email'=> $lastBooking['contact_email'],
            'id_type'      => $lastBooking['id_type'],
            'id_number'    => $lastBooking['id_number'],
            'country_code' => $lastBooking['contact_country_code'] ?: '91',
            'passengers'   => json_encode($passengers, JSON_UNESCAPED_UNICODE),
        ]);

        $row = Database::fetch('SELECT * FROM customer_form_templates WHERE id = :id', ['id' => $id]);
        Response::json(['ok' => true, 'data' => formatTemplate($row), 'message' => 'Template created from your last booking.']);
    }

    Response::json(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}

function formatTemplate(array $row): array
{
    $pax = json_decode($row['passengers'], true) ?: [];
    return [
        'id'          => (int) $row['id'],
        'label'       => $row['label'],
        'contactName' => $row['contact_name'],
        'contactEmail'=> $row['contact_email'],
        'idType'      => $row['id_type'],
        'idNumber'    => $row['id_number'],
        'countryCode' => $row['country_code'],
        'passengers'  => $pax,
        'paxCount'    => count($pax),
        'useCount'    => (int) $row['use_count'],
        'lastUsed'    => $row['last_used_at'],
        'created'     => $row['created_at'],
    ];
}
