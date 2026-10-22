<?php
/** POST /admin/api/staff-reject.php {id | ref, reason} — see admin/api/_staff-decision.php. */
declare(strict_types=1);
const STAFF_DECISION = 'reject';
require __DIR__ . '/_staff-decision.php';
