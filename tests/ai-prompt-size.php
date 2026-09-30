<?php
/**
 * =====================================================================
 *  ai-prompt-size.php — how much does the assistant make a brain read
 *  before it may say one word?
 *
 *  On a cloud API this never mattered: the prompt is processed at
 *  thousands of tokens a second and the size is only a bill. On the
 *  local brain (llama.cpp, 2 CPU cores) prompt intake is about 25
 *  tokens a second, so every 250 tokens of briefing is another TEN
 *  SECONDS before the first word — and that is what made the first
 *  end-to-end run time out at 70s on 25 Sep 2026.
 *
 *  This prints the prefix for each role so the cost is visible, and
 *  turns it into the number that actually matters: seconds.
 *
 *      php tests/ai-prompt-size.php
 *
 *  Always exits 0 — it is a measurement, not a gate.
 * =====================================================================
 */

declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/aiagent.php';
/* systemPrompt() opens with ai_system_prompt(), which lives here. The
   agent's own entry points pull it in on the way past; a test that
   reaches straight for the private method has to ask for it. */
require_once INCLUDE_PATH . '/aiprompt.php';

/* Devanagari costs far more tokens per character than Latin, so a flat
   chars/4 would flatter a Nepali prompt badly. 3.6 is the rough blended
   rate for this codebase's mixed English + Devanagari briefing. */
const CHARS_PER_TOKEN = 3.6;
const PROMPT_TOK_PER_SEC = 25.0;   // measured on the live VPS, 2 vCPU

$prompt = new ReflectionMethod(AiAgent::class, 'systemPrompt');
$prompt->setAccessible(true);

$roles = [
    'customer (web)'  => ['role' => 'customer', 'channel' => 'web'],
    'customer (wa)'   => ['role' => 'customer', 'channel' => 'whatsapp'],
    'staff (web)'     => ['role' => 'staff',    'channel' => 'web'],
    'admin (web)'     => ['role' => 'admin',    'channel' => 'web'],
];

printf("%-16s %10s %10s %8s %8s %9s\n", 'role', 'prompt', 'tools', 'tokens', 'tools', 'seconds');
printf("%s\n", str_repeat('-', 66));

foreach ($roles as $label => $over) {
    $ctx = array_merge([
        'role' => 'customer', 'admin' => null, 'adminId' => 0, 'scopeAdminId' => null,
        'name' => '', 'phone' => '', 'channel' => 'web', 'userId' => 0, 'stageKey' => '',
    ], $over);

    $sys   = (string) $prompt->invoke(null, $ctx);
    $tools = AiTools::catalogue($ctx);
    $json  = (string) json_encode($tools, JSON_UNESCAPED_UNICODE);

    $sysTok   = (int) (strlen($sys) / CHARS_PER_TOKEN);
    $toolTok  = (int) (strlen($json) / CHARS_PER_TOKEN);
    $secs     = ($sysTok + $toolTok) / PROMPT_TOK_PER_SEC;

    printf(
        "%-16s %10d %10d %8d %8d %8.0fs\n",
        $label, strlen($sys), count($tools), $sysTok, $toolTok, $secs
    );
}

printf("%s\n", str_repeat('-', 66));
echo "prompt chars, tool count, then the token estimate for each and what\n";
echo "that costs in seconds on the local brain before it writes a word.\n";
echo "llama.cpp re-uses the cached prefix between turns, so this is the\n";
echo "FIRST message of a conversation; later ones pay only for what changed.\n";
