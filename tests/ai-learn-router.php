<?php
/**
 * In front of tools/mock-ollama.php for tests/ai-learn-local-test.php: a
 * /api/chat that asks for the AiLearn article schema gets an article; every
 * other request (health, tags, show, …) goes to the shared mock. Runs ONLY
 * under `php -S`.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$raw  = $path === '/api/chat' ? (string) file_get_contents('php://input') : '';
$req  = $raw !== '' ? (json_decode($raw, true) ?: []) : [];

if (isset($req['format']['properties']['answer_en'])) {
    $log = getenv('SHG_MOCK_LOG');
    if ($log) { file_put_contents($log, $raw . "\n", FILE_APPEND); }
    $user = (string) (end($req['messages'])['content'] ?? '');
    $article = str_contains($user, 'unanswerable')
        ? ['answerable' => false, 'title' => '', 'answer_en' => '', 'answer_ne' => '', 'keywords' => '']
        : ['answerable' => true, 'title' => 'Luggage on the bus',
           'answer_en' => 'Each passenger may bring one bag in the boot and one small handbag on board.',
           'answer_ne' => 'हरेक यात्रुले डिक्कीमा एउटा झोला र भित्र एउटा सानो ह्यान्डब्याग ल्याउन सक्नुहुन्छ।',
           'keywords' => 'luggage, saman, bag'];
    header('Content-Type: application/json');
    echo json_encode(['model' => $req['model'] ?? '', 'done' => true, 'prompt_eval_count' => 120, 'eval_count' => 60,
        'message' => ['role' => 'assistant', 'content' => json_encode($article, JSON_UNESCAPED_UNICODE)]],
        JSON_UNESCAPED_UNICODE);
    return;
}

require dirname(__DIR__) . '/tools/mock-ollama.php';
