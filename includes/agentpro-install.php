<?php
/**
 * =====================================================================
 *  includes/agentpro-install.php — putting Agent Pro into the website
 *  (Oct 2026). Used by admin/agentpro-install.php (buttons, no terminal)
 *  and tools/agentpro-install.sh (a terminal):
 *
 *    1. the database upgrade   database/upgrade-2026-10-agent-pro.sql
 *    2. the menu               admin/_guard.php: Control Center (top),
 *                              Team & ID Cards + My ID & Wallet (Agents),
 *                              AI (VPS) & WhatsApp (Settings)
 *    3. the WhatsApp hooks     includes/wabot.php: the agents' desk sees
 *                              every message first; the VPS AI answers
 *                              passengers instead of the cloud model
 *
 *  Every edit of a PHP file: a backup copy first (*.agentpro-<time>.bak,
 *  never served), the new code parsed (PhpToken, TOKEN_PARSE) — a file that
 *  would not compile is never saved — and the file's own line endings kept.
 *  Each step checks first, so running it twice changes nothing. If an
 *  anchor line is not found (the live file differs), that file is left
 *  untouched and the step says which lines to paste by hand.
 *
 *  CLI (menu + hooks only, never the database):
 *      php includes/agentpro-install.php /path/to/website
 * =====================================================================
 */
declare(strict_types=1);

final class AgentProInstall
{
    public const TABLES = ['ap_members', 'ap_applications', 'ap_invites', 'ap_wa_links', 'ap_wa_state', 'ap_jobs',
                           'ap_deposit_requests', 'ap_passkeys', 'ap_ai_calls'];

    public const FILES = [
        'includes/agentpro.php', 'includes/agentpro-card.php', 'includes/agentpro-docs.php', 'includes/agentpro-ai.php',
        'includes/agentpro-wa.php', 'includes/agentpro-brain.php', 'includes/agentpro-edit.php', 'includes/agentpro-passkey.php',
        'includes/agentpro-install.php',
        'api/agentpro.php', 'api/agentpro-card.php',
        'admin/hub.php', 'admin/team.php', 'admin/team-member.php', 'admin/team-print.php', 'admin/team-doc.php',
        'admin/ai-local.php', 'admin/agentpro-install.php',
        'join.php', 'id.php', 'c.php', 'me/index.php', 'me/print.php', 'me/manifest.webmanifest',
        'cron/agentpro-worker.php',
        'assets/agentpro/ap.css', 'assets/agentpro/admin.css', 'assets/agentpro/join.js', 'assets/agentpro/me.js',
        'assets/agentpro/crest.svg', 'assets/agentpro/crest-dark.svg', 'assets/agentpro/vendor/jsQR.js',
        'assets/agentpro/fonts/Outfit-Regular.ttf', 'assets/agentpro/fonts/Outfit-Bold.ttf',
        'assets/agentpro/fonts/GeistMono-Regular.ttf', 'assets/agentpro/fonts/GeistMono-Bold.ttf',
        'assets/agentpro/fonts/ArsenalSC-Regular.ttf', 'assets/agentpro/fonts/Italiana-Regular.ttf',
        'assets/agentpro/fonts/Lora-BoldItalic.ttf',
        'database/upgrade-2026-10-agent-pro.sql',
    ];

    public const SQL_FILE = 'database/upgrade-2026-10-agent-pro.sql';

    /** After the Staff & Approvals line (section Agents). */
    public const MENU_AGENTS = [
        "        // Agent Pro (Oct 2026): Control Center, team + ID cards + join approvals, every member's own",
        "        // ID / visiting card / wallet page. See includes/agentpro.php and docs/AGENT-PRO-DESIGN.md.",
        "        ['href' => 'hub.php',          'icon' => 'grid',      'label' => 'Control Center',   'perm' => 'dashboard.view', 'section' => ''],",
        "        ['href' => 'team.php',         'icon' => 'id-card',   'label' => 'Team & ID Cards',  'perm' => 'commissions.view', 'section' => 'Agents'],",
        "        ['href' => '/me/',             'icon' => 'wallet',    'label' => 'My ID & Wallet',   'perm' => '', 'section' => 'Agents'],",
    ];

    /** After the AI Activity line (section Settings). */
    public const MENU_SETTINGS = [
        "        ['href' => 'ai-local.php',     'icon' => 'sparkle',   'label' => 'AI (VPS) & WhatsApp', 'perm' => 'dashboard.view', 'section' => 'Settings'],",
    ];

    /** @return list<string> */
    public static function missingFiles(string $root): array
    {
        return array_values(array_filter(self::FILES, static fn(string $f): bool => !is_file($root . '/' . $f)));
    }

    /** @return list<string> */
    public static function missingSchema(PDO $pdo): array
    {
        $have = array_flip(array_map('strval', $pdo->query("SHOW TABLES LIKE 'ap\\_%'")->fetchAll(PDO::FETCH_COLUMN)));
        $out = array_values(array_filter(self::TABLES, static fn(string $t): bool => !isset($have[$t])));
        $set = (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE skey = 'ap_wa_sell'")->fetchColumn();
        if ($set === 0) {
            $out[] = 'settings';
        }
        return $out;
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        return array_values(array_filter(array_map('trim', explode(';', $sql)), static fn(string $s): bool => $s !== ''));
    }

    /** @return array{ok: int, errors: list<string>} */
    public static function runSql(PDO $pdo, string $root): array
    {
        $file = $root . '/' . self::SQL_FILE;
        if (!is_file($file)) {
            return ['ok' => 0, 'errors' => ['Missing ' . self::SQL_FILE . ' — upload it first.']];
        }
        $ok = 0;
        $errors = [];
        foreach (self::statements((string) file_get_contents($file)) as $stmt) {
            try {
                $pdo->exec($stmt);
                $ok++;
            } catch (Throwable $e) {
                $errors[] = substr((string) preg_replace('/\s+/', ' ', $stmt), 0, 70) . ' … → ' . $e->getMessage();
            }
        }
        return ['ok' => $ok, 'errors' => $errors];
    }

    public static function menuDone(string $root): bool
    {
        $c = (string) @file_get_contents($root . '/admin/_guard.php');
        return str_contains($c, "'team.php'") && str_contains($c, "'ai-local.php'");
    }

    public static function hooksDone(string $root): bool
    {
        $c = (string) @file_get_contents($root . '/includes/wabot.php');
        return str_contains($c, 'ApWa::reply(') && str_contains($c, 'ApWa::customerTurn(');
    }

    /** @return string what happened */
    public static function patchMenu(string $root): string
    {
        $path = $root . '/admin/_guard.php';
        if (!is_file($path)) {
            throw new RuntimeException('admin/_guard.php not found.');
        }
        $code = (string) file_get_contents($path);
        $nl = str_contains($code, "\r\n") ? "\r\n" : "\n";
        $done = [];
        if (!str_contains($code, "'team.php'")) {
            if (preg_match("/^[ \\t]*\\['href'\\s*=>\\s*'staff\\.php'.*\\],[ \\t]*\\r?\\n/m", $code, $m, PREG_OFFSET_CAPTURE) !== 1) {
                throw new RuntimeException('Could not find the "Staff & Approvals" menu line in admin/_guard.php — paste the lines from 2-menu/AGENTPRO-MENU.txt by hand.');
            }
            $at = (int) $m[0][1] + strlen((string) $m[0][0]);
            $code = substr($code, 0, $at) . implode($nl, self::MENU_AGENTS) . $nl . substr($code, $at);
            $done[] = 'Control Center, Team & ID Cards, My ID & Wallet';
        }
        if (!str_contains($code, "'ai-local.php'")) {
            if (preg_match("/^[ \\t]*\\['href'\\s*=>\\s*'ai-activity\\.php'.*\\],[ \\t]*\\r?\\n/m", $code, $m, PREG_OFFSET_CAPTURE) === 1
                || preg_match("/^[ \\t]*\\['href'\\s*=>\\s*'settings\\.php'.*\\],[ \\t]*\\r?\\n/m", $code, $m, PREG_OFFSET_CAPTURE) === 1) {
                $at = (int) $m[0][1] + strlen((string) $m[0][0]);
                $code = substr($code, 0, $at) . implode($nl, self::MENU_SETTINGS) . $nl . substr($code, $at);
                $done[] = 'AI (VPS) & WhatsApp';
            } else {
                throw new RuntimeException('Could not find the Settings menu lines in admin/_guard.php — paste the lines from 2-menu/AGENTPRO-MENU.txt by hand.');
            }
        }
        if ($done === []) {
            return 'Menu items already there.';
        }
        return 'Menu: ' . implode(' · ', $done) . ' added. Backup: ' . self::write($path, $code);
    }

    /** @return string what happened */
    public static function patchWaBot(string $root): string
    {
        $path = $root . '/includes/wabot.php';
        if (!is_file($path)) {
            return 'No includes/wabot.php in this website — the WhatsApp desk needs the site\'s WhatsApp bot.';
        }
        $code = (string) file_get_contents($path);
        $nl = str_contains($code, "\r\n") ? "\r\n" : "\n";
        $done = [];
        if (!str_contains($code, 'ApWa::reply(')) {
            if (preg_match('/^([ \t]*)\$senderDigits\s*=\s*normalisePhone\(\$from\);[ \t]*\r?\n/m', $code, $m, PREG_OFFSET_CAPTURE) !== 1) {
                throw new RuntimeException('Could not find "$senderDigits = normalisePhone($from);" in includes/wabot.php — paste the hook from 2-menu/AGENTPRO-WABOT.txt by hand.');
            }
            $ind = (string) $m[1][0];
            $at = (int) $m[0][1] + strlen((string) $m[0][0]);
            $hook = $nl . $ind . '// Agent Pro (Oct 2026): the agents\' and staff WhatsApp desk (LINK + OTP, wallet, cards, ticket' . $nl
                  . $ind . '// sale and edits) and the join form\'s "JOIN 123456". Null = not ours. See includes/agentpro-wa.php.' . $nl
                  . $ind . "if (is_file(INCLUDE_PATH . '/agentpro-wa.php')) {" . $nl
                  . $ind . "    require_once INCLUDE_PATH . '/agentpro-wa.php';" . $nl
                  . $ind . '    $apReply = ApWa::reply($from, $body, $kind);' . $nl
                  . $ind . '    if ($apReply !== null) {' . $nl
                  . $ind . "        return self::out(\$apReply['text'], \$apReply['media'] ?? null);" . $nl
                  . $ind . '    }' . $nl
                  . $ind . '}' . $nl;
            $code = substr($code, 0, $at) . $hook . substr($code, $at);
            $done[] = 'agents\' desk';
        }
        if (!str_contains($code, 'ApWa::customerTurn(')) {
            if (preg_match('/^([ \t]*)\$agent\s*=\s*AiAgent::handle\(\$from,\s*\$body\);[ \t]*\r?\n/m', $code, $m, PREG_OFFSET_CAPTURE) !== 1) {
                throw new RuntimeException('Could not find "$agent = AiAgent::handle($from, $body);" in includes/wabot.php — paste the hook from 2-menu/AGENTPRO-WABOT.txt by hand.');
            }
            $ind = (string) $m[1][0];
            $repl = $ind . '// Agent Pro (Oct 2026): when the AI on this VPS is on, it answers instead of the cloud model.' . $nl
                  . $ind . "if (is_file(INCLUDE_PATH . '/agentpro-wa.php')) {" . $nl
                  . $ind . "    require_once INCLUDE_PATH . '/agentpro-wa.php';" . $nl
                  . $ind . '}' . $nl
                  . $ind . "\$agent = class_exists('ApWa') && ApWa::customerBrainOn() ? ApWa::customerTurn(\$from, \$body) : AiAgent::handle(\$from, \$body);" . $nl;
            $code = substr($code, 0, (int) $m[0][1]) . $repl . substr($code, (int) $m[0][1] + strlen((string) $m[0][0]));
            $done[] = 'VPS AI for passengers';
        }
        if ($done === []) {
            return 'WhatsApp hooks already there.';
        }
        return 'WhatsApp: ' . implode(' · ', $done) . ' hooked in. Backup: ' . self::write($path, $code);
    }

    /** Backup, prove the new code parses, then write. @return string the backup's file name */
    private static function write(string $path, string $code): string
    {
        try {
            PhpToken::tokenize($code, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new RuntimeException(basename($path) . ' would not compile after the change, so it was left as it was (' . $e->getMessage() . ').');
        }
        if (!is_writable($path)) {
            throw new RuntimeException(basename($path) . ' is not writable by PHP — make the change by hand (docs/AGENT-PRO-NEPALI.md).');
        }
        $backup = $path . '.agentpro-' . date('Ymd-His') . '.bak';
        if (!copy($path, $backup)) {
            throw new RuntimeException('Could not write a backup next to ' . basename($path) . ' — nothing was changed.');
        }
        if (file_put_contents($path, $code, LOCK_EX) === false) {
            throw new RuntimeException('Could not write ' . basename($path) . ' — the backup ' . basename($backup) . ' is untouched.');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        return basename($backup);
    }
}

// php includes/agentpro-install.php /path/to/website — menu + WhatsApp hooks (tools/agentpro-install.sh)
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
    $root = rtrim((string) ($argv[1] ?? ''), '/');
    if ($root === '' || !is_file($root . '/admin/_guard.php')) {
        fwrite(STDERR, "usage: php includes/agentpro-install.php /path/to/website\n");
        exit(2);
    }
    $fail = 0;
    foreach (['patchMenu', 'patchWaBot'] as $step) {
        try {
            echo '   ' . AgentProInstall::$step($root) . "\n";
        } catch (RuntimeException $e) {
            $fail++;
            fwrite(STDERR, '   ' . $e->getMessage() . "\n");
        }
    }
    exit($fail === 0 ? 0 : 1);
}
