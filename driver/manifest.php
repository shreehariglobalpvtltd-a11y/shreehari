<?php
/**
 * driver/manifest.php - the driver app installs as its own app ("SHG Driver"),
 * separate from the customer app: own id, own scope /driver/. (20 Oct 2026)
 */
declare(strict_types=1);
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode([
    'id'               => '/driver/',
    'name'             => 'SHG Driver - S Hari Global',
    'short_name'       => 'SHG Driver',
    'description'      => 'Driver app: start the trip and the bus position goes to the office and the passengers.',
    'start_url'        => '/driver/',
    'scope'            => '/driver/',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#08122A',
    'theme_color'      => '#08122A',
    'icons'            => [
        ['src' => '/assets/img/icon-192.png', 'type' => 'image/png', 'sizes' => '192x192', 'purpose' => 'any'],
        ['src' => '/assets/img/icon-512.png', 'type' => 'image/png', 'sizes' => '512x512', 'purpose' => 'any'],
        ['src' => '/assets/img/icon-maskable-512.png', 'type' => 'image/png', 'sizes' => '512x512', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
