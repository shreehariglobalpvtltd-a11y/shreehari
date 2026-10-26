<?php
/** POST /admin/api/staff-approve.php {id | ref} — see admin/api/_staff-decision.php. */
declare(strict_types=1);
const STAFF_DECISION = 'approve';
require __DIR__ . '/_staff-decision.php';
