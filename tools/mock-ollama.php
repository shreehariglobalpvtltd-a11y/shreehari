<?php
/**
 * tools/mock-ollama.php — a fake Ollama (and OpenAI-compatible) model server
 * for the local tests (30 Oct 2026, af1). NOT for the website: it only runs
 * under PHP's built-in web server.
 *
 *     php -S 127.0.0.1:11555 tools/mock-ollama.php
 *
 * tests/agent-pro-wa-test.php and tests/agentpro-formread-test.php start it
 * themselves. It answers like Ollama:
 *
 *   GET  /api/version            {"version": "0.12.0-mock"}
 *   GET  /api/tags               qwen3.5:4b (vision, tools, thinking) + gemma3:4b
 *   POST /api/show               the model's capabilities
 *   GET  /api/ps                 nothing loaded
 *   POST /api/pull               a download "started"
 *   POST /api/chat               a JSON answer worked out from the message:
 *        - text: intent book / status / balance / company / question with
 *          seats, date (bholi / भोलि / कल = tomorrow), phone and pickup town
 *        - an image with the ID-card schema: "Test Person Kumar", 1994-03-12,
 *          Aadhaar 2345 6789 0124
 *        - an image with the paper-form schema (page_number): the owner's
 *          sample as a model would read it — page 1 the applicant, page 2
 *          only office marks; a picture 801 px wide is "not a form", one
 *          803 px wide answers after 4 s (to test giving way to WhatsApp)
 *        - MOCKFAIL anywhere -> HTTP 500
 *   GET  /v1/models, POST /v1/chat/completions   the OpenAI-compatible shape
 *
 * Every request is appended to <temp>/mock-ollama-requests.jsonl as
 * {path, model, think, format (bool), images (bool)} so the tests can check
 * what was sent. Env MOCK_OLLAMA_EMPTY=1: the server answers but has no model.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Run it with PHP's built-in server:  php -S 127.0.0.1:11555 " . __FILE__ . "\n");
        exit(1);
    }
    http_response_code(403);
    exit('Forbidden');
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = (string) file_get_contents('php://input');
$in = json_decode($body, true);
$in = is_array($in) ? $in : [];

$out = static function (int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
};

$images = false;
foreach ((array) ($in['messages'] ?? []) as $m) {
    if (!empty($m['images'])) {
        $images = true;
    }
    if (is_array($m['content'] ?? null)) {
        foreach ($m['content'] as $part) {
            if (($part['type'] ?? '') === 'image_url') {
                $images = true;
            }
        }
    }
}
@file_put_contents(sys_get_temp_dir() . '/mock-ollama-requests.jsonl', json_encode([
    'path' => $path, 'model' => $in['model'] ?? null, 'think' => $in['think'] ?? null,
    'format' => isset($in['format']) || isset($in['response_format']), 'images' => $images,
]) . "\n", FILE_APPEND);

$empty = getenv('MOCK_OLLAMA_EMPTY') === '1';
$caps = ['qwen3.5:4b' => ['completion', 'vision', 'tools', 'thinking'], 'gemma3:4b' => ['completion', 'vision']];

/** The answer object for a chat request (decoded JSON the model would write), or null for a failure. */
function mock_answer(array $in): ?array
{
    $system = '';
    $user = '';
    $image = '';
    foreach ((array) ($in['messages'] ?? []) as $m) {
        $content = $m['content'] ?? '';
        if (is_array($content)) {
            $t = '';
            foreach ($content as $part) {
                if (($part['type'] ?? '') === 'text') {
                    $t .= (string) $part['text'];
                } elseif (($part['type'] ?? '') === 'image_url') {
                    $image = (string) preg_replace('#^data:[^,]+,#', '', (string) ($part['image_url']['url'] ?? ''));
                }
            }
            $content = $t;
        }
        if (($m['role'] ?? '') === 'system') {
            $system .= (string) $content;
        } else {
            $user .= (string) $content;
        }
        if (!empty($m['images'][0])) {
            $image = (string) $m['images'][0];
        }
    }
    if (str_contains($system . $user, 'MOCKFAIL')) {
        return null;
    }
    $format = $in['format'] ?? ($in['response_format']['json_schema']['schema'] ?? []);
    $props = is_array($format) ? (array) ($format['properties'] ?? []) : [];

    if ($image !== '') {
        $size = @getimagesizefromstring((string) base64_decode($image));
        $w = is_array($size) ? (int) $size[0] : 0;
        if (isset($props['page_number'])) {
            if ($w === 803) {
                sleep(4);
            }
            if ($w === 801) {
                return ['page_number' => 0, 'is_form' => false, 'is_id_card' => false, 'fields' => (object) [], 'confidence' => (object) []];
            }
            $page = preg_match('/page (\d+) of/', $user, $m) === 1 ? (int) $m[1] : 1;
            if ($page === 2) {
                // only office marks on page 2: the reader must not take them
                return ['page_number' => 2, 'is_form' => true, 'is_id_card' => false,
                        'fields' => ['city' => 'Surat', 'full_name' => 'Surat Office', 'id_number' => 'KYC verified'],
                        'confidence' => ['city' => 'high', 'full_name' => 'medium']];
            }
            return ['page_number' => 1, 'is_form' => true, 'is_id_card' => false,
                    'fields' => [
                        'full_name' => 'Ganesh Mandhoj shahi', 'phone' => '96241177 65', 'email' => '-', 'dob' => '03/01/1993',
                        'occupation' => 'Business', 'city' => '', 'address' => 'Surat', 'id_type' => '', 'id_number' => '6197 1671 0143',
                        'pan' => 'IBYPS7609G', 'education' => 'SLC', 'experience' => 'In Business', 'skills' => 'Marketing, Cooking',
                        'languages' => 'Hindi, Gujrati, Nepali', 'bank_holder' => '', 'bank_name' => '', 'bank_account' => '-',
                        'bank_ifsc' => '', 'upi_id' => '9624117765',
                    ],
                    'confidence' => ['full_name' => 'high', 'phone' => 'medium', 'dob' => 'high', 'occupation' => 'high', 'address' => 'high',
                                     'id_number' => 'high', 'pan' => 'medium', 'education' => 'high', 'experience' => 'medium', 'skills' => 'medium',
                                     'languages' => 'high', 'upi_id' => 'high']];
        }
        return ['is_id_document' => true, 'full_name' => 'Test Person Kumar', 'full_name_local' => '', 'guardian' => '', 'dob' => '1994-03-12',
                'dob_bs' => '', 'gender' => 'Male', 'id_number' => '2345 6789 0124', 'address' => '', 'district' => '', 'state' => 'Gujarat',
                'pincode' => '', 'expires' => '', 'confidence' => ['full_name' => 'high', 'dob' => 'medium', 'id_number' => 'high']];
    }

    // Text: the message itself (the brain wraps it after "Message:")
    $msg = preg_match('/Message:\s*\n(.*)$/s', $user, $m) === 1 ? $m[1] : $user;
    $msg = strtr($msg, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
    $low = strtolower($msg);                              // byte-wise: no mbstring needed in a bare php -S
    // "tomorrow" from the caller's own "Today" (the mock's clock may be in another time zone)
    $tomorrow = preg_match('/Tomorrow:\s*(\d{4}-\d{2}-\d{2})/', $user, $m) === 1 ? $m[1]
        : (preg_match('/Today(?: is|:)\s*(\d{4}-\d{2}-\d{2})/', $system . $user, $m) === 1
            ? gmdate('Y-m-d', (int) strtotime($m[1] . ' 12:00 UTC') + 86400) : date('Y-m-d', strtotime('+1 day')));
    $a = [];
    if (preg_match('/\bSHG-\d{4}-\d+/i', $msg, $m) === 1) {
        $a = ['intent' => 'status', 'pnr' => strtoupper($m[0])];
    } elseif (preg_match('/balance|paisa|बैलेंस|wallet/u', $low) === 1) {
        $a = ['intent' => 'balance'];
    } elseif (preg_match('/(?<!\d)([1-9]|1[0-2])(?!\d)\s*(?:seat|jana|सिट|सीट|जना)|(?:seat|सिट|सीट)\s*([1-9])/u', $low) === 1) {
        preg_match('/(?<!\d)([1-9]|1[0-2])(?!\d)/', $low, $s);
        $a = ['intent' => 'book', 'seats' => (int) ($s[1] ?? 1)];
        if (preg_match('/bholi|भोलि|कल|tomorrow/u', $low) === 1) {
            $a['date'] = $tomorrow;
        }
        if (preg_match('/(?<!\d)(\d{10})(?!\d)/', $msg, $p) === 1) {
            $a['phone'] = $p[1];
        }
        foreach (['mehsana' => '/mehsana|मेहसाना/u', 'surat' => '/surat|सुरत/u', 'ahmedabad' => '/ahmedabad|अहमदाबाद/u'] as $town => $re) {
            if (preg_match($re, $low) === 1) {
                $a['pickup'] = $town;
                $a['boarding'] = ucfirst($town);
                break;
            }
        }
    } elseif (preg_match('/office|कार्यालय/u', $low) === 1) {
        $a = ['intent' => 'company'];
    } else {
        $a = ['intent' => 'question', 'answer' => 'MOCK: the office will help you with that.'];
    }
    return $a;
}

if ($path === '/api/version' && $method === 'GET') {
    $out(200, ['version' => '0.12.0-mock']);
} elseif ($path === '/api/tags') {
    $out(200, ['models' => $empty ? [] : [
        ['name' => 'qwen3.5:4b', 'model' => 'qwen3.5:4b', 'size' => 3400000000,
         'details' => ['family' => 'qwen35', 'parameter_size' => '4B', 'quantization_level' => 'Q4_K_M']],
        ['name' => 'gemma3:4b', 'model' => 'gemma3:4b', 'size' => 3300000000,
         'details' => ['family' => 'gemma3', 'parameter_size' => '4B', 'quantization_level' => 'Q4_K_M']],
    ]]);
} elseif ($path === '/api/show') {
    $name = (string) ($in['model'] ?? $in['name'] ?? '');
    isset($caps[$name]) ? $out(200, ['capabilities' => $caps[$name], 'details' => ['family' => 'mock']]) : $out(404, ['error' => "model '$name' not found"]);
} elseif ($path === '/api/ps') {
    $out(200, ['models' => []]);
} elseif ($path === '/api/pull') {
    header('Content-Type: application/x-ndjson');
    echo json_encode(['status' => 'pulling manifest']) . "\n";
} elseif ($path === '/api/chat' && $method === 'POST') {
    $a = mock_answer($in);
    if ($a === null) {
        $out(500, ['error' => 'mock failure']);
    } else {
        $out(200, ['model' => $in['model'] ?? '', 'created_at' => gmdate('c'), 'done' => true,
                   'message' => ['role' => 'assistant', 'content' => json_encode($a, JSON_UNESCAPED_UNICODE)],
                   'prompt_eval_count' => 120, 'eval_count' => 30]);
    }
} elseif ($path === '/v1/models') {
    $out(200, ['object' => 'list', 'data' => [['id' => 'mock-openai', 'object' => 'model']]]);
} elseif ($path === '/v1/chat/completions' && $method === 'POST') {
    $a = mock_answer($in);
    if ($a === null) {
        $out(500, ['error' => ['message' => 'mock failure']]);
    } else {
        $out(200, ['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($a, JSON_UNESCAPED_UNICODE)]]],
                   'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]]);
    }
} else {
    $out(404, ['error' => 'not found: ' . $path]);
}
