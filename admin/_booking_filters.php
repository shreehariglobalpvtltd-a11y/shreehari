<?php
/**
 * admin/_booking_filters.php — filters shared by the bookings register
 * (admin/bookings.php) and its export (admin/export.php), so the file that is
 * downloaded is always exactly the list that is on screen (5 Oct 2026: the
 * export ignored the Counter and Payment-method filters and returned more rows
 * than the page showed).
 *
 *   phone = none | bad | ok      the customer's number on the booking
 *   reach = got | agent | nobody  where the WhatsApp ticket ended up
 *
 * Both add to $where / $params (alias `b` = bookings) and return the cleaned
 * values for the form and the paging / export links.
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * @param list<string>          $where
 * @param array<string, mixed>  $params
 * @param array<string, mixed>  $in     usually $_GET
 * @return array{phone: string, reach: string}
 */
function shg_booking_extra_filters(array &$where, array &$params, array $in): array
{
    $phone = Security::clean((string) ($in['phone'] ?? ''), 8);
    $reach = Security::clean((string) ($in['reach'] ?? ''), 8);

    // A usable number = 10-13 digits not starting with 0 (India / Nepal, with or
    // without the country code); 0000000000 / blank is the desk's "no number".
    $none = "(b.contact_phone IS NULL OR b.contact_phone = '' OR b.contact_phone REGEXP '^0+$')";
    $ok   = "b.contact_phone REGEXP '^[1-9][0-9]{9,12}$'";
    if ($phone === 'none') {
        $where[] = $none;
    } elseif ($phone === 'ok') {
        $where[] = $ok;
    } elseif ($phone === 'bad') {
        $where[] = "(NOT $none AND NOT $ok)";
    } else {
        $phone = '';
    }

    $hasQueue = false;
    if (in_array($reach, ['got', 'agent', 'nobody'], true)) {
        try {
            $hasQueue = (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_ticket_queue'",
                [], 0
            ) > 0;
        } catch (Throwable $e) {
            $hasQueue = false;
        }
    }
    if ($hasQueue) {
        $cust   = "EXISTS (SELECT 1 FROM wa_ticket_queue wq WHERE wq.booking_id = b.id AND wq.role IN ('customer','alt') AND wq.status IN ('sent','delivered','read'))";
        $seller = "EXISTS (SELECT 1 FROM wa_ticket_queue wq2 WHERE wq2.booking_id = b.id AND wq2.role = 'seller' AND wq2.status IN ('sent','delivered','read'))";
        if ($reach === 'got') {
            $where[] = $cust;
        } elseif ($reach === 'agent') {
            $where[] = "(NOT $cust AND $seller)";
        } else {
            $where[] = "(NOT $cust AND NOT $seller AND b.status IN ('confirmed','completed'))";
        }
    } else {
        $reach = '';
    }

    return ['phone' => $phone, 'reach' => $reach];
}
