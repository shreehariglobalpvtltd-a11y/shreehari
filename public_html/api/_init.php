<?php
/**
 * =====================================================================
 *  API bootstrap — included at the top of every endpoint in /api.
 *
 *  Loads the application, pins the JSON response type, and exposes the
 *  business-logic classes the endpoints build on.
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/notify.php';

/**
 * Convenience: read a required field or fail with 422.
 */
function requireField(string $key, string $label = ''): mixed
{
    $value = Response::field($key);

    if ($value === null || $value === '' || (is_array($value) && $value === [])) {
        Response::invalid([$key => ($label ?: $key) . ' is required.']);
    }

    return $value;
}
