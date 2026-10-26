<?php
/** Durable marketing worker. Schedule once per minute, one small batch per run. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/wamarketing.php';
try {
    $result = WaMarketing::work(Settings::getInt('wa_marketing_batch_size', 10));
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(($result['ok'] ?? false) ? 0 : 1);
} catch (Throwable $e) {
    Logger::exception($e, 'whatsapp');
    fwrite(STDERR, "Marketing worker failed. Inspect application logs; uncertain sends are not retried.\n");
    exit(1);
}
