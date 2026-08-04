<?php
/**
 * POST /api/enquiry.php — capture a homepage "Quick Booking" enquiry.
 *
 * The redesigned homepage opens straight onto a passenger card. Pressing
 * "Next →" saves the passenger's intent to the database immediately — no
 * OTP, no payment — so staff can follow up, and the visitor then carries
 * on into the normal search → seat → payment flow. Captured leads show up
 * in the admin panel under "Enquiries".
 *
 * Body: { name, phone, gender?, nationality?, age?, travelDate?, seats?, note? }
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    // Generous limit — this is a low-friction lead form, but still bounded
    // so a script cannot flood the enquiries table.
    Security::requireRateLimit('enquiry_create', Security::clientIp(), 20, 300);

    $name  = Security::clean(Response::field('name', ''), 120);
    $phone = Security::digits(Response::field('phone', ''), 20);

    $fields = [];
    if ($name === '') {
        $fields['name'] = 'Please enter the passenger name.';
    }
    if ($phone === '') {
        $fields['phone'] = 'Please enter a mobile number.';
    } elseif (!Security::isValidPhone($phone)) {
        $fields['phone'] = 'Enter a valid mobile number.';
    }
    if ($fields !== []) {
        Response::invalid($fields);
    }

    $genderIn = Response::field('gender', '');
    $gender   = in_array($genderIn, ['Male', 'Female', 'Other'], true) ? $genderIn : null;

    $nationality = Security::clean(Response::field('nationality', ''), 60);
    $nationality = $nationality !== '' ? $nationality : null;

    $ageRaw = (int) Response::field('age', 0);
    $age    = ($ageRaw >= 1 && $ageRaw <= 120) ? $ageRaw : null;

    $travelDate = Security::clean(Response::field('travelDate', ''), 10);
    if ($travelDate !== '' && (!Security::isValidDate($travelDate) || $travelDate < todayISO())) {
        Response::invalid(['travelDate' => 'Choose a valid travel date.']);
    }
    $travelDate = $travelDate !== '' ? $travelDate : null;

    $seats = max(1, min(40, (int) Response::field('seats', 1)));

    $note = Security::clean(Response::field('note', ''), 255);
    $note = $note !== '' ? $note : null;

    $id = Database::insert('enquiries', [
        'name'        => $name,
        'phone'       => $phone,
        'gender'      => $gender,
        'nationality' => $nationality,
        'age'         => $age,
        'travel_date' => $travelDate,
        'seats'       => $seats,
        'note'        => $note,
        'source'      => 'quick_booking',
        'status'      => 'new',
        'ip'          => Security::clientIp(),
        'user_agent'  => Security::userAgent(255),
    ]);

    Response::success([
        'id'         => $id,
        'travelDate' => $travelDate,
        'seats'      => $seats,
    ], 'Details saved — let’s pick your seat.');
} catch (Throwable $e) {
    Response::serverError($e);
}
