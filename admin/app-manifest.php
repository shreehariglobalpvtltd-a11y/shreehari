<?php
/**
 * admin/app-manifest.php — the staff app manifest with the right type
 * (12 Nov 2026). The server sends *.webmanifest as application/octet-stream;
 * Chrome forgives that, the Play Store packagers (PWABuilder / Bubblewrap)
 * do not. Same file, correct header. No session, no database.
 */
declare(strict_types=1);
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
readfile(__DIR__ . '/manifest.webmanifest');
