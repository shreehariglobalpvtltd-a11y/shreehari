<?php
/**
 * /agreements/form.php — the A4 letterhead application form for everyone
 * (22 Oct 2026, owner: "print garera ni bharna milos, online ni bharna milos
 * ... PDF ya JPG ... bhareko form chha bhane tyo bhareko form rakhdeu").
 *
 *   ?kind=staff|agent|partner   the blank form: print it and fill it by hand,
 *                               or save it as PDF / JPG
 *   ?mine=1                     the person's OWN application, just sent from
 *                               this browser (session mark left by
 *                               api/staff-apply.php / api/agreement-apply.php),
 *                               filled, with their photo and signature
 *
 * The page itself is admin/application-print.php in its public mode
 * (SHG_A4_PUBLIC): no admin session, no data in the URL, and nobody else's
 * application is reachable from here.
 */
declare(strict_types=1);

define('SHG_A4_PUBLIC', true);
require dirname(__DIR__) . '/admin/application-print.php';
