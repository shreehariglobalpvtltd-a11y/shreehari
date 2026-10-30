<?php
/**
 * cron/agentpro-worker.php — Agent Pro's background work. Every minute:
 *
 *     * * * * * php /path/to/public_html/cron/agentpro-worker.php >/dev/null 2>&1
 *
 *   - answers queued WhatsApp messages with the AI on this VPS (Twilio
 *     waits only 15 s for a reply, so its messages are queued and answered
 *     here; the sender already got "⏳"). One at a time — the model is a
 *     single CPU-bound resource.
 *   - reads uploaded ID photos with the vision model (join form) when the
 *     fast readers left fields empty
 *   - housekeeping: old jobs, idle conversations, expired drafts
 *
 * Also started at once in the background when a message is queued
 * (ApWa::kickWorker), so most answers do not wait for the next minute.
 * Runs at most ~55 s and never twice at the same time.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/agentpro-ai.php';
require_once INCLUDE_PATH . '/agentpro-wa.php';
require_once INCLUDE_PATH . '/agentpro-docs.php';
require_once INCLUDE_PATH . '/notify.php';

if (!AgentPro::available()) {
    cron_done(['skipped' => 'agent pro tables missing']);
    exit;
}
@set_time_limit(600);

$lockFile = @fopen(LOG_PATH . '/agentpro-worker.lock', 'c');
if ($lockFile === false || !flock($lockFile, LOCK_EX | LOCK_NB)) {
    cron_done(['skipped' => 'another worker is running']);
    exit;
}
Settings::set('ap_worker_seen', (string) time(), 'string', 'agentpro', false);

$end = microtime(true) + (in_array('--once', $argv ?? [], true) ? 120 : 55);
$done = 0;
$failed = 0;
while (microtime(true) < $end) {
    // Claim one job atomically: a second worker (if ever) can never take the same row.
    $job = Database::fetch("SELECT * FROM ap_jobs WHERE status = 'queued' AND attempts < 3 ORDER BY id LIMIT 1");
    if ($job === null) {
        break;
    }
    $claimed = Database::run("UPDATE ap_jobs SET status = 'running', attempts = attempts + 1, started_at = NOW() WHERE id = :i AND status = 'queued'",
        ['i' => (int) $job['id']]);
    if ($claimed === 0) {
        continue;
    }
    try {
        $ok = match ((string) $job['kind']) {
            'doc'   => apw_doc($job),
            default => ApWa::runJob($job),
        };
        Database::run("UPDATE ap_jobs SET status = :s, finished_at = NOW(), error = NULL WHERE id = :i",
            ['s' => $ok ? 'done' : 'failed', 'i' => (int) $job['id']]);
        $ok ? $done++ : $failed++;
    } catch (Throwable $e) {
        Logger::exception($e, 'whatsapp');
        $failed++;
        Database::run("UPDATE ap_jobs SET status = IF(attempts >= 3, 'failed', 'queued'), error = :e WHERE id = :i",
            ['e' => mb_substr($e->getMessage(), 0, 250), 'i' => (int) $job['id']]);
    }
}

/** Read an ID photo with the vision model; the join form polls for the result. */
function apw_doc(array $job): bool
{
    $p = json_decode((string) $job['text'], true);
    if (!is_array($p)) {
        return false;
    }
    $app = AgentPro::draft((string) ($p['key'] ?? ''));
    $abs = AgentPro::privateAbs((string) ($p['path'] ?? ''));
    if ($app === null || $abs === null) {
        return false;
    }
    $fields = ApDocRead::readWithAi($abs, (string) ($p['type'] ?? ''), (string) ($p['side'] ?? 'front'));
    if ($fields === null) {
        return false;
    }
    AgentPro::noteReadout((string) $p['key'], 'ai_' . (string) ($p['side'] ?? 'front'),
        ['fields' => $fields, 'sources' => array_fill_keys(array_keys($fields), 'ai')]);
    Database::update('ap_jobs', ['reply' => json_encode($fields, JSON_UNESCAPED_UNICODE)], 'id = :i', ['i' => (int) $job['id']]);
    return true;
}

// Housekeeping (cheap; runs every pass).
$purged = Database::run("DELETE FROM ap_jobs WHERE created_at < (NOW() - INTERVAL 14 DAY)");
Database::run("UPDATE ap_jobs SET status = 'queued' WHERE status = 'running' AND started_at < (NOW() - INTERVAL 15 MINUTE) AND attempts < 3");
Database::run("DELETE FROM ap_wa_state WHERE updated_at < (NOW() - INTERVAL 60 DAY)");
Database::run("DELETE FROM ap_ai_calls WHERE created_at < (NOW() - INTERVAL 90 DAY)");
// Drafts nobody finished in 30 days: drop them and their private files.
foreach (Database::fetchAll("SELECT * FROM ap_applications WHERE status = 'draft' AND updated_at < (NOW() - INTERVAL 30 DAY) LIMIT 200") as $d) {
    foreach (['doc_front', 'doc_back', 'selfie', 'thumb'] as $c) {
        if (($a = AgentPro::privateAbs((string) ($d[$c] ?? ''))) !== null) { @unlink($a); }
    }
    foreach (jsonColumn($d['extras'] ?? null) as $x) {
        if (($a = AgentPro::privateAbs((string) ($x['path'] ?? ''))) !== null) { @unlink($a); }
    }
    Database::delete('ap_applications', 'id = :i', ['i' => (int) $d['id']]);
}

flock($lockFile, LOCK_UN);
fclose($lockFile);
cron_done(['answered' => $done, 'failed' => $failed, 'old_jobs' => $purged]);
