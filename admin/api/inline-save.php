<?php
/**
 * =====================================================================
 *  admin/api/inline-save.php — click a value, type, Enter (16 Nov 2026, v2.0 P3-6).
 *
 *  The save end of assets/js/admin-inline-edit.js. A page marks a value:
 *     <span data-ie-entity="route_stop" data-ie-field="fare" data-ie-id="406"
 *           data-ie-value="2200" data-ie-type="money" data-ie-confirm="1">₹2,200</span>
 *  and the script POSTs JSON {entity, field, id, value} with the staff
 *  CSRF token (X-CSRF-Token, meta shg-admin-csrf).
 *
 *  Whitelist — each entry carries the SAME permission, rules and audit row
 *  as the page form it shortcuts; nothing outside it can be written:
 *   route_stop.fare / stop_time / landmark  Admin → Routes (save_stop_fares /
 *        edit_stop): routes.view + routes.edit, fare blank = the direction
 *        default or ₹1–₹100000, time HH:MM, landmark up to 191 characters;
 *        Boarding::flush(); audited route_stop.fares / route_stop.edit.
 *   schedule.dep_time / fare                Bus Calendar (admin/calendar.php) —
 *        schedules.manage, and the very functions behind schedule-action.php
 *        set_dep_time / set_price (scheduleSetDepTime / scheduleSetPrice), so
 *        the gates, the HH:MM and ₹ rules and the trip.set_dep_time /
 *        trip.set_price audit rows are one code. Inline a price of 0 is
 *        refused (blank = the normal fare), so a typo cannot clear it.
 *   setting.extra_seats_per_bus             Extra seats (admin/extra-seats.php) —
 *        super-admin only, a whole number 0–40 (refused, never clamped),
 *        Settings::set + audit settings.extra_seats.
 *   coupon.discount_value                   Offers (admin/offers.php) —
 *        payments.view + coupons.edit, the form's rules: more than zero, a
 *        percent at most 100 and a percent offer must keep its maximum;
 *        audited offer.update (what the form's save writes for an edit).
 *   staff.full_name                         Staff (admin/staff.php) —
 *        staff.manage; a super-admin's row (and your own) only by a
 *        super-admin, as on team-member.php; audited staff.setname.
 *  60 saves / minute per admin.
 *
 *  Response: {ok, old, new, message[, raw, show, data]}; {ok:false, message}
 *  on refusal. raw = the next data-ie-value, show = the text to draw (when
 *  they differ from new — a cleared bus time shows the route's time again).
 * =====================================================================
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
/* Login only here: each whitelist entry names every permission its own page
   AND its form demand, checked after the CSRF token (route_stop keeps the
   routes.view this endpoint used to boot with). */
$admin = admin_boot();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

/** @param array<string,mixed> $payload */
$out = static function (array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

/* entity => perms (ALL required), super (super-admin only), fields, noId (the
   field names the row — a setting key — and the posted id is ignored) */
const IE_WHITELIST = [
    'route_stop' => ['perms' => ['routes.view', 'routes.edit'], 'fields' => ['fare', 'stop_time', 'landmark']],
    'schedule'   => ['perms' => ['schedules.manage'], 'fields' => ['dep_time', 'fare']],
    'setting'    => ['perms' => [], 'super' => true, 'noId' => true, 'fields' => ['extra_seats_per_bus']],
    'coupon'     => ['perms' => ['payments.view', 'coupons.edit'], 'fields' => ['discount_value']],
    'staff'      => ['perms' => ['staff.manage'], 'fields' => ['full_name']],
];

/** Nothing to write: the same value came back. */
function ie_same(mixed $v, array $more = []): array
{
    return ['ok' => true, 'old' => $v, 'new' => $v, 'message' => 'केही बदलिएन · No change.'] + $more;
}

/** "2,200" / "₹2200" / "Rs 2200" / "Rs. 2,200" -> "2200" ('' stays ''). */
function ie_number_text(string $v): string
{
    $v = str_replace([',', ' ', "\u{00A0}", '₹', '%'], '', trim($v));
    return (string) preg_replace('/^rs\.?/i', '', $v);
}

/* ---- route_stop: the same rules as admin/routes.php ---- */
function ie_route_stop(string $field, int $id, string $value): array
{
    $stop = Database::fetch('SELECT * FROM route_stops WHERE id = :id', ['id' => $id]);
    if ($stop === null) {
        throw new InvalidArgumentException('Stop भेटिएन · Stop not found.', 404);
    }
    $name = (string) $stop['stop_name'];
    if ($field === 'fare') {
        if (!Boarding::hasStopControls()) {
            throw new InvalidArgumentException('Run database/upgrade-2026-10-01-stop-fares.sql first - the fare column does not exist yet.');
        }
        // "2,200" / "₹2200" / "Rs 2200" is 2200 — the same reader as routes.php $stopFareIn
        if (method_exists('Boarding', 'parseRupees')) {
            $v = Boarding::parseRupees($value);
        } else {
            $v = str_replace([',', ' ', '₹'], '', $value);
            $v = $v === '' ? null : (is_numeric($v) ? (float) $v : false);
        }
        if ($v === null) {
            $new = null;
        } elseif ($v === false || $v < 1 || $v > 100000) {
            throw new InvalidArgumentException('भाडा ₹1–₹100000 वा खाली (default) · Enter the fare as a rupee amount between 1 and 100000, or leave it blank for the default.');
        } else {
            $new = round($v, 2);
        }
        $old = $stop['fare'] !== null ? round((float) $stop['fare'], 2) : null;
    } elseif ($field === 'stop_time') {
        if ($value === '') {
            $new = null;
        } elseif (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) !== 1) {
            throw new InvalidArgumentException('समय HH:MM मा · Enter stop time as HH:MM.');
        } else {
            $new = strlen($value) === 5 ? $value . ':00' : $value;
        }
        $old = $stop['stop_time'] !== null ? (string) $stop['stop_time'] : null;
    } else {
        $v   = Security::clean($value, 191);
        $new = $v !== '' ? $v : null;
        $old = $stop['landmark'] !== null && $stop['landmark'] !== '' ? (string) $stop['landmark'] : null;
    }

    $show = static function (string $f, $v): string {
        if ($v === null) {
            return $f === 'fare' ? 'default' : '—';
        }
        return $f === 'fare' ? inr((float) $v) : ($f === 'stop_time' ? substr((string) $v, 0, 5) : (string) $v);
    };
    $json = static fn(string $f, $v) => $v === null ? null : ($f === 'fare' ? (float) $v : ($f === 'stop_time' ? substr((string) $v, 0, 5) : (string) $v));

    if ($new === $old) {
        return ['ok' => true, 'old' => $json($field, $old), 'new' => $json($field, $new), 'message' => 'केही बदलिएन · No change.'];
    }

    Database::update('route_stops', [$field => $new], 'id = :id', ['id' => $id]);
    if (class_exists('Boarding') && method_exists('Boarding', 'flush')) {
        Boarding::flush();
    }
    if ($field === 'fare') {
        // the same audit row Admin → Routes "💾 Save pickup fares" writes
        Logger::audit('route_stop.fares', 'routes', (string) $stop['route_id'], [$name => $old], [$name => $new],
            'Pickup fare changed on route ' . $stop['route_id'] . ' (1 stop) · inline edit');
    } else {
        $k = $field === 'stop_time' ? 'time' : 'landmark';
        Logger::audit('route_stop.edit', 'routes', (string) $stop['route_id'], ['stop' => $name, $k => $old], ['stop' => $name, $k => $new],
            'Edited stop: ' . $name . ' (' . $k . ', inline edit)');
    }
    $label = ['fare' => 'भाडा · fare', 'stop_time' => 'समय · time', 'landmark' => 'landmark'][$field];
    return ['ok' => true, 'old' => $json($field, $old), 'new' => $json($field, $new),
            'message' => '✅ ' . $name . ' ' . $label . ': ' . $show($field, $old) . ' → ' . $show($field, $new)];
}

/* ---- schedule: Bus Calendar time / price through schedule-action.php's own rules ---- */
function ie_schedule(string $field, int $id, string $value, int $adminId): array
{
    if (!defined('SHG_SCHEDULE_ACTION_LIB')) {
        define('SHG_SCHEDULE_ACTION_LIB', true);
    }
    require_once __DIR__ . '/schedule-action.php';   // library mode: functions only

    $row = Database::fetch(
        'SELECT s.id, s.travel_date, s.slot, s.dep_time_override, s.fare_override,
                r.from_city, r.to_city, r.dep_time AS route_dep_time
           FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :id',
        ['id' => $id]
    );
    if ($row === null) {
        throw new InvalidArgumentException('बस भेटिएन · That trip no longer exists.', 404);
    }
    $what = (string) $row['from_city'] . ' → ' . (string) $row['to_city'] . ' ' . formatDate((string) $row['travel_date'])
          . ((int) $row['slot'] > 1 ? ' (Bus ' . (int) $row['slot'] . ')' : '');
    $note = ' · inline edit (Bus Calendar)';
    $routeHm = substr((string) $row['route_dep_time'], 0, 5);

    if ($field === 'dep_time') {
        if (preg_match('/^\d{1,2}:\d{2}:00$/', $value) === 1) {
            $value = substr($value, 0, -3);           // a time box may send seconds
        }
        $curHm = $row['dep_time_override'] !== null ? substr((string) $row['dep_time_override'], 0, 5) : null;
        $res   = scheduleSetDepTime($id, $value, $adminId, $note);
        if (!$res['ok']) {
            if (!empty($res['same'])) {
                $eff = $curHm ?? $routeHm;
                return ie_same($curHm, ['raw' => $eff, 'show' => $eff, 'data' => ['time' => $eff, 'retimed' => $curHm !== null, 'routeTime' => $routeHm]]);
            }
            throw new InvalidArgumentException('बचत भएन · ' . (string) $res['error']);
        }
        $oldHm = $res['old'] !== null ? substr((string) $res['old'], 0, 5) : null;
        $newHm = $res['new'] !== null ? substr((string) $res['new'], 0, 5) : null;
        $eff   = $newHm ?? $routeHm;
        return ['ok' => true, 'old' => $oldHm, 'new' => $newHm, 'raw' => $eff, 'show' => $eff,
                'data' => ['time' => $eff, 'retimed' => $newHm !== null, 'routeTime' => $routeHm],
                'message' => '✅ ' . $what . ' — प्रस्थान समय · departure: ' . ($oldHm ?? $routeHm) . ' → ' . $eff
                           . ($newHm === null ? ' (रुटको समय · route time)' : '')];
    }

    /* fare — blank = the normal fare; 0 or less is refused here (the old
       endpoint reads 0 as "clear", which a slip of the finger must not do).
       The test asks the very reader scheduleSetPrice() saves through
       (ScheduleMaker::fareInput, which rounds to paise first) what a typed
       value becomes: anything it turns into "clear" — 0, -5, but also 0.004,
       which is not 0 as typed and rounds to ₹0.00 — is refused, so only a
       blank box can put the bus back on the normal fare. A value fareInput
       rejects outright (abc, ₹2,00,000) falls through to scheduleSetPrice,
       which reports it in its own words. */
    $v = ie_number_text($value);
    if ($v !== '') {
        try {
            $clears = ScheduleMaker::fareInput($v) === null;
        } catch (RuntimeException $e) {
            $clears = false;
        }
        if ($clears) {
            throw new InvalidArgumentException('भाडा ₹0 हुँदैन — सामान्य भाडाका लागि खाली छोड्नुहोस् · The price must be more than ₹0 — leave it blank for the normal fare.');
        }
    }
    $res = scheduleSetPrice($id, $v, $adminId, $note);
    $fmt = static fn(?float $f): string => $f === null ? 'सामान्य भाडा · normal fare' : inr($f) . ' / seat';
    if (!$res['ok']) {
        if (!empty($res['same'])) {
            $cur = $row['fare_override'] !== null ? round((float) $row['fare_override'], 2) : null;
            return ie_same($cur, ['data' => ['fare' => $cur ?? 0]]);
        }
        throw new InvalidArgumentException('बचत भएन · ' . (string) $res['error']);
    }
    return ['ok' => true, 'old' => $res['old'], 'new' => $res['new'], 'data' => ['fare' => $res['new'] ?? 0],
            'message' => '✅ ' . $what . ' — भाडा · price: ' . $fmt($res['old']) . ' → ' . $fmt($res['new'])
                       . ' · बिक्री भइसकेका टिकट उही · tickets already sold keep their amount'];
}

/* ---- setting: extra seats per bus (admin/extra-seats.php form, super-admin) ---- */
function ie_setting(string $field, string $value): array
{
    if (preg_match('/^\d{1,2}$/', $value) !== 1 || (int) $value > 40) {
        throw new InvalidArgumentException('सिट संख्या ० देखि ४० · Enter a whole number of extra seats from 0 (off) to 40.');
    }
    $n   = (int) $value;
    $old = Seats::extraSeatCount();
    if ($n === $old) {
        return ie_same($old);
    }
    Settings::set('extra_seats_per_bus', (string) $n, 'int', 'booking');
    Logger::audit('settings.extra_seats', 'settings', 'extra_seats_per_bus', ['n' => $old], ['n' => $n], 'inline edit on admin/extra-seats.php');
    return ['ok' => true, 'old' => $old, 'new' => $n,
            'message' => '✅ ' . ($n > 0
                ? 'हरेक बसमा ' . $n . ' थप सिट · Every bus now carries ' . $n . ' extra seats (EX1–EX' . $n . ').'
                : 'थप सिट बन्द · Extra seats switched off. Seats already sold stay on their tickets.')];
}

/* ---- coupon: the offer's discount, by the offers form's own rules ---- */
function ie_coupon(int $id, string $value): array
{
    $row = Database::fetch('SELECT * FROM coupons WHERE id = :i', ['i' => $id]);
    if ($row === null) {
        throw new InvalidArgumentException('यो अफर भेटिएन · That offer no longer exists.', 404);
    }
    $v = ie_number_text($value);
    if ($v === '' || !is_numeric($v)) {
        throw new InvalidArgumentException('छुट अंकमा लेख्नुहोस् · Enter the discount as a number.');
    }
    $new  = round((float) $v, 2);
    $type = (string) $row['discount_type'] === 'percent' ? 'percent' : 'flat';
    $maxD = $row['max_discount'] !== null ? round((float) $row['max_discount'], 2) : null;
    if ($new <= 0) {
        throw new InvalidArgumentException('छुट शून्यभन्दा बढी हुनुपर्छ · The discount must be more than zero.');
    }
    if ($type === 'percent' && $new > 100) {
        throw new InvalidArgumentException('प्रतिशत छुट १००% भन्दा बढी हुँदैन · A percent discount cannot be over 100%.');
    }
    if ($type === 'percent' && $maxD === null) {
        /* An unbounded percent on a group booking is how a festival offer
           quietly gives away a whole ticket (the offers form's own rule). */
        throw new InvalidArgumentException('प्रतिशत अफरमा अधिकतम छुट चाहिन्छ — पहिले ✏️ Edit मा राख्नुहोस् · A percent offer needs a maximum discount, so one big booking cannot run away with it — set it under Edit first.');
    }
    $old = round((float) $row['discount_value'], 2);
    $fmt = static fn(float $x): string => $type === 'percent' ? rtrim(rtrim(number_format($x, 2), '0'), '.') . '%' : inr($x);
    if ($new === $old) {
        return ie_same($old);
    }
    Database::update('coupons', ['discount_value' => $new], 'id = :i', ['i' => $id]);
    // the row the offers form's "save" writes for an edit (offer.update)
    Logger::audit('offer.update', 'coupon', (string) $row['code'], ['discount_value' => $row['discount_value']], ['discount_value' => $new],
        'Offer edited on admin/offers.php · inline edit');
    return ['ok' => true, 'old' => $old, 'new' => $new,
            'message' => '✅ ' . (string) $row['code'] . ' छुट · discount: ' . $fmt($old) . ' → ' . $fmt($new)
                       . ' · पहिलेका बुकिङ उही · bookings already made keep theirs'];
}

/* ---- staff: the name on the staff list ---- */
function ie_staff(int $id, string $value, int $adminId): array
{
    $t = Database::fetch('SELECT id, username, full_name, role FROM admins WHERE id = :i', ['i' => $id]);
    if ($t === null) {
        throw new InvalidArgumentException('खाता भेटिएन · Staff account not found.', 404);
    }
    $isSuper = Auth::isSuperadmin();
    /* team-member.php's wall: the office renames someone else; a super-admin's
       row - and your own - only a super-admin. */
    if (!$isSuper && ((string) $t['role'] === 'superadmin' || (int) $t['id'] === $adminId)) {
        throw new InvalidArgumentException('⛔ अनुमति छैन · Only a super-admin can change this name.', 403);
    }
    $name = (string) preg_replace('/\s+/u', ' ', Security::clean($value, 120));
    if (mb_strlen($name) < 2) {
        throw new InvalidArgumentException('पूरा नाम लेख्नुहोस् · Enter the full name (2 letters or more).');
    }
    $old = (string) ($t['full_name'] ?? '');
    if ($name === $old) {
        return ie_same($old);
    }
    Database::update('admins', ['full_name' => $name], 'id = :i', ['i' => $id]);
    Logger::audit('staff.setname', 'admin', (string) $t['username'], ['full_name' => $old], ['full_name' => $name],
        'name changed · inline edit on admin/staff.php');
    if ((int) $t['id'] === $adminId && isset($_SESSION[ADMIN_SESSION_KEY]['full_name'])) {
        $_SESSION[ADMIN_SESSION_KEY]['full_name'] = $name;
    }
    return ['ok' => true, 'old' => $old, 'new' => $name,
            'message' => '✅ नाम · name: ' . ($old !== '' ? $old : '—') . ' → ' . $name . ' (@' . (string) $t['username'] . ')'];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $out(['ok' => false, 'message' => 'POST only.'], 405);
    }
    $raw = (string) file_get_contents('php://input');
    $in  = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($in)) {
        $in = $_POST;
    }
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $in[CSRF_TOKEN_NAME] ?? $in['csrf'] ?? '');
    if (!Security::verifyCsrf($token)) {
        $out(['ok' => false, 'message' => 'पेज धेरै बेर खुला रह्यो · Session expired — reload the page and try again.'], 419);
    }
    $entity = (string) ($in['entity'] ?? '');
    $field  = (string) ($in['field'] ?? '');
    $id     = (int) ($in['id'] ?? 0);
    $value  = $in['value'] ?? '';
    $rule   = IE_WHITELIST[$entity] ?? null;
    if ($rule === null || !in_array($field, $rule['fields'], true) || ($id <= 0 && empty($rule['noId'])) || (!is_scalar($value) && $value !== null)) {
        $out(['ok' => false, 'message' => 'यो यहाँबाट बदलिँदैन · This value cannot be edited here.'], 400);
    }
    $allowed = empty($rule['super']) || Auth::isSuperadmin();
    foreach ($rule['perms'] as $perm) {
        $allowed = $allowed && Auth::can($perm);
    }
    if (!$allowed) {
        $out(['ok' => false, 'message' => '⛔ अनुमति छैन · Your login may not change this.'], 403);
    }
    $adminId = (int) ($admin['id'] ?? 0);
    if (!Security::rateLimit('inline_save', 'admin:' . $adminId, 60, 60)) {
        $out(['ok' => false, 'message' => 'एकछिन पर्खनुहोस् · Too many saves — wait a moment.'], 429);
    }
    $value = trim((string) ($value ?? ''));

    try {
        $res = match ($entity) {
            'route_stop' => ie_route_stop($field, $id, $value),
            'schedule'   => ie_schedule($field, $id, $value, $adminId),
            'setting'    => ie_setting($field, $value),
            'coupon'     => ie_coupon($id, $value),
            'staff'      => ie_staff($id, $value, $adminId),
        };
    } catch (InvalidArgumentException $e) {
        $code = $e->getCode();
        $out(['ok' => false, 'message' => $e->getMessage()], in_array($code, [403, 404], true) ? $code : 422);
    }
    $out($res);
} catch (Throwable $e) {
    Logger::error('inline-save api', ['err' => $e->getMessage(), 'line' => $e->getLine()], 'admin');
    $out(['ok' => false, 'message' => 'केही मिलेन · Something went wrong — nothing was changed.'], 500);
}
