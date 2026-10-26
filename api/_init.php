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
