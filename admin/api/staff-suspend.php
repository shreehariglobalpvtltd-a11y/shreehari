<?php
/** POST /admin/api/staff-suspend.php {id | ref, reason} — see admin/api/_staff-decision.php. */
declare(strict_types=1);
const STAFF_DECISION = 'suspend';
require __DIR__ . '/_staff-decision.php';
