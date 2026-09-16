<?php
/**
 * =====================================================================
 *  POST /api/feedback.php — the passenger rates a finished journey.
 *
 *  The `feedback` table has been in schema.sql since the first build
 *  (rating + comfort/punctuality/staff sub-scores, comment, is_public,
 *  FK to bookings, indexed) and had ZERO references anywhere in the
 *  codebase. This is the first thing to use it, so there is no migration
 *  and therefore no risk to existing data.
 *
 *  Authorisation reuses the pattern download-ticket.php:56-77 settled on,
 *  and for the same reason: PNRs are minted sequentially, so a PNR is not
 *  a secret and must never be treated as a bearer token. A caller must
 *  either hold the HMAC ticket key or be the signed-in owner.
 *
 *  Deliberately refuses until the trip has actually arrived — a rating
 *  submitted before the journey is not a rating of the journey.
 *
 *  Request  { pnr, k?, rating, comfort?, punctuality?, staff?, comment? }
 *  Response { pnr, rating, saved: true }
 * =====================================================================
 */

require __DIR__ . '/_init.php';

try {
    Security::requireCsrf();

    $ip = Security::clientIp();
    if (!Security::rateLimit('feedback', $ip, 10, 600)) {
        Response::error('Too many attempts. Please try again in a few minutes.', 429);
    }

    $pnr = Security::clean((string) Response::field('pnr', ''), 40);
    if (!Security::isValidPnr($pnr)) {
        Response::invalid(['pnr' => 'A valid booking reference is required.']);
    }

    $booking = BookingService::findByPnr($pnr);
    if ($booking === null) {
        // Same neutral answer as a booking that exists but is not yours, so
        // this endpoint cannot be used to test whether a PNR is real.
        Response::forbidden('This booking cannot be rated.');
    }

    /* ---- who is allowed to rate it -------------------------------- */
    $key       = (string) Response::field('k', '');
    $viaKey    = $key !== '' && Ticket::checkDownloadToken($pnr, $key);
    $viaOwner  = false;

    $sessionUser = $_SESSION[USER_SESSION_KEY] ?? null;
    if (is_array($sessionUser) && !empty($sessionUser['phone'])) {
        $viaOwner = normalisePhone((string) $sessionUser['phone'])
                 === normalisePhone((string) ($booking['contact_phone'] ?? ''));
    }

    if (!$viaKey && !$viaOwner) {
        Logger::warning('Feedback refused — not the ticket holder', ['pnr' => $pnr, 'ip' => $ip]);
        Response::forbidden('This booking cannot be rated.');
    }

    /* ---- only a journey that actually happened --------------------- */
    if ((string) $booking['status'] !== 'confirmed' && (string) $booking['status'] !== 'completed') {
        Response::error('Only a confirmed journey can be rated.', 409);
    }

    $arrived = (int) Database::scalar(
        "SELECT COUNT(*)
           FROM booking_legs bl
           JOIN schedules s ON s.id = bl.schedule_id
          WHERE bl.booking_id = :b AND s.status = 'arrived'",
        ['b' => (int) $booking['id']],
        0
    );
    if ($arrived === 0) {
        Response::error('You can rate the journey once the bus has arrived.', 409);
    }

    /* ---- one rating per booking ------------------------------------ */
    $existing = Database::scalar(
        'SELECT COUNT(*) FROM feedback WHERE booking_id = :b',
        ['b' => (int) $booking['id']],
        0
    );
    if ((int) $existing > 0) {
        Response::error('You have already rated this journey. Thank you!', 409);
    }

    /* ---- the scores ------------------------------------------------ */
    // 1..5, and a sub-score may be omitted entirely (NULL) rather than
    // forced to a number the passenger never chose.
    $score = static function (string $field, bool $required = false): ?int {
        $raw = Response::field($field, null);
        if ($raw === null || $raw === '') {
            if ($required) {
                Response::invalid([$field => 'Please choose a rating from 1 to 5.']);
            }
            return null;
        }
        $n = (int) $raw;
        if ($n < 1 || $n > 5) {
            Response::invalid([$field => 'Ratings run from 1 to 5.']);
        }
        return $n;
    };

    $rating = $score('rating', true);
    $comment = Security::clean((string) Response::field('comment', ''), 1000);

    Database::insert('feedback', [
        'booking_id'  => (int) $booking['id'],
        'user_phone'  => (string) ($booking['contact_phone'] ?? ''),
        // bookings has no name column — the passenger's name lives on
        // booking_passengers. Take the lead traveller's, so the panel can
        // show who left the rating without a second join at read time.
        'name'        => Security::clean((string) Database::scalar(
            'SELECT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY id ASC LIMIT 1',
            ['b' => (int) $booking['id']],
            ''
        ), 120),
        'rating'      => $rating,
        'comfort'     => $score('comfort'),
        'punctuality' => $score('punctuality'),
        'staff'       => $score('staff'),
        'comment'     => $comment !== '' ? $comment : null,
        // Never public on submission. A comment shown on the website is a
        // moderation decision, made in the panel, not by the submitter.
        'is_public'   => 0,
    ]);

    Logger::audit('feedback.submitted', 'booking', $pnr, null,
        ['rating' => $rating, 'hasComment' => $comment !== ''], 'Passenger rated the journey');

    Response::success(['pnr' => $pnr, 'rating' => $rating, 'saved' => true],
        'Thank you — your rating helps us run a better bus.');

} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
