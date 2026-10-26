<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/applicationimport.php';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { Response::error('POST required.', 405); }
    Security::requireCsrf();
    Response::$maxInputBytes = 8_200_000;
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > Response::$maxInputBytes) { Response::error('File too large (maximum 6 MB).', 413); }
    $kind = (string)Response::field('kind', 'id');
    $scope = (string)Response::field('application_kind', '');
    if (!in_array($scope, ['staff', 'agent', 'partner'], true)) { Response::error('Choose an application kind.', 400); }
    if (!in_array($kind, ['id','form'], true)) { Response::error('Unknown upload type.', 400); }
    $file = ApplicationImport::decode(Response::field('doc', null));
    if (!$file) { Response::invalid(['doc' => 'file'], 'स्पष्ट फोटो वा PDF राख्नुहोस् (अधिकतम 6 MB) · Choose a clear image or PDF, up to 6 MB.'); }
    // IDs are rasterized on the phone before upload so the same bytes can accompany the application.
    if ($kind === 'id' && $file['mime'] === 'application/pdf') { Response::error('Open the ID PDF in the form upload control first.', 422); }
    $ip = Security::clientIp();
    Security::requireRateLimit('application_import_ip', $ip, 20, 3600);
    Security::requireRateLimit('application_import_day', 'global', 300, 86400);
    $key = 'application-import:' . $kind . ':' . hash('sha256', $file['bytes']);
    $result = DocVerify::recall($key);
    if ($result === null) {
        @set_time_limit(110);
        try { $result = ApplicationImport::read($file, $kind); }
        catch (Throwable $e) { Logger::warning('Application OCR unavailable'); $result = ['status'=>'unavailable', 'fields'=>[]]; }
        if ($result['status'] !== 'unavailable') { DocVerify::remember($key, $result); }
    }
    if ($kind === 'id' && $result['status'] !== 'notdoc') { ApplicationImport::rememberId($file['bytes'], $result['status'], $scope); }
    if ($kind === 'form' && $result['status'] !== 'notdoc') { $result['receipt'] = ApplicationImport::retainForm($file, $scope, $result); }
    Logger::info('Application AI import', ['kind' => $kind, 'status' => $result['status'], 'field_count' => count(array_filter($result['fields']))]);
    Response::json(['ok' => true] + $result);
} catch (Throwable $e) { Response::serverError($e); }
