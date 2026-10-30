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
 *   - reads uploaded ID photos with the AI ladder (VPS model, else Gemini)
 *     when the fast readers left fields empty
 *   - housekeeping: old jobs, idle conversations, expired drafts
 *
 * Also started at once in the background when a message is queued
 * (ApWa::kickWorker), so most answers do not wait for the next minute.
 * Runs at most ~55 s (120 s with --once).
 *
 * 30 Oct 2026 (af1): WhatsApp never waits behind a document.
 *   - two locks: agentpro-worker.lock (WhatsApp jobs) and
 *     agentpro-worker-doc.lock (ID photos). While a document is read the
 *     WhatsApp lock is let go, so the worker ApWa::kickWorker starts for a
 *     new message answers it at once; that turn's model call makes the
 *     document read hang up (ApAi gives way) and the photo is read again
 *     ~20 s later without losing an attempt.
 *   - a failed AI read is tried once more (a minute later) before it is
 *     marked failed; what was read is kept with its confidence and reader.
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

/** A non-blocking lock on LOG_PATH/$name, or null when another worker holds it. @return resource|null */
function apw_lock(string $name)
{
    $f = @fopen(LOG_PATH . '/' . $name, 'c');
    if ($f === false) {
        return null;
    }
    if (!flock($f, LOCK_EX | LOCK_NB)) {
        fclose($f);
        return null;
    }
    return $f;
}

/** @param resource|null $f */
function apw_unlock($f): void
{
    if (is_resource($f)) {
        flock($f, LOCK_UN);
        fclose($f);
    }
}

/**
 * Claim one queued job atomically (a second worker can never take the same row).
 * Documents: a retry waits a minute, a read that gave way to WhatsApp ~20 s.
 *
 * @return array<string, mixed>|null
 */
function apw_claim(bool $doc): ?array
{
    $sql = $doc
        ? "SELECT * FROM ap_jobs WHERE status = 'queued' AND attempts < 3 AND kind = 'doc'
             AND (error IS NULL OR started_at IS NULL
                  OR (error = 'preempted' AND started_at < (NOW() - INTERVAL 20 SECOND))
                  OR started_at < (NOW() - INTERVAL 60 SECOND))
           ORDER BY id LIMIT 1"
        : "SELECT * FROM ap_jobs WHERE status = 'queued' AND attempts < 3 AND kind <> 'doc' ORDER BY id LIMIT 1";
    for ($try = 0; $try < 3; $try++) {
        $job = Database::fetch($sql);
        if ($job === null) {
            return null;
        }
        $claimed = Database::run("UPDATE ap_jobs SET status = 'running', attempts = attempts + 1, started_at = NOW() WHERE id = :i AND status = 'queued'",
            ['i' => (int) $job['id']]);
        if ($claimed > 0) {
            $job['attempts'] = (int) $job['attempts'] + 1;
            return $job;
        }
    }
    return null;
}

$once = in_array('--once', $argv ?? [], true);
$end = microtime(true) + ($once ? 120 : 55);
$waLock = apw_lock('agentpro-worker.lock');
$docLock = apw_lock('agentpro-worker-doc.lock');
if ($waLock === null && $docLock === null) {
    cron_done(['skipped' => 'another worker is running']);
    exit;
}
Settings::set('ap_worker_seen', (string) time(), 'string', 'agentpro', false);
$housekeeping = $waLock !== null;

$done = 0;
$failed = 0;
$docs = 0;
while (microtime(true) < $end) {
    // WhatsApp first, always
    if ($waLock === null) {
        $waLock = apw_lock('agentpro-worker.lock');   // free again once the other worker finished
    }
    $job = $waLock !== null ? apw_claim(false) : null;
    if ($job !== null) {
        try {
            $ok = ApWa::runJob($job);
            Database::run("UPDATE ap_jobs SET status = :s, finished_at = NOW(), error = NULL WHERE id = :i",
                ['s' => $ok ? 'done' : 'failed', 'i' => (int) $job['id']]);
            $ok ? $done++ : $failed++;
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
            $failed++;
            Database::run("UPDATE ap_jobs SET status = IF(attempts >= 3, 'failed', 'queued'), error = :e WHERE id = :i",
                ['e' => mb_substr($e->getMessage(), 0, 250), 'i' => (int) $job['id']]);
        }
        continue;
    }
    if ($docLock === null) {
        break;
    }
    $job = apw_claim(true);
    if ($job === null) {
        break;
    }
    // Let go of WhatsApp while the (slow) document is read: a new message gets its own worker at once.
    apw_unlock($waLock);
    $waLock = null;
    try {
        $st = apw_doc($job);
    } catch (Throwable $e) {
        Logger::exception($e, 'agentpro');
        $st = (int) $job['attempts'] < 2 ? 'retry' : 'failed';
    }
    $docs++;
    match ($st) {
        'done'      => Database::run("UPDATE ap_jobs SET status = 'done', finished_at = NOW(), error = NULL WHERE id = :i", ['i' => (int) $job['id']]),
        'preempted' => Database::run("UPDATE ap_jobs SET status = 'queued', attempts = GREATEST(0, attempts - 1), error = 'preempted' WHERE id = :i", ['i' => (int) $job['id']]),
        'retry'     => Database::run("UPDATE ap_jobs SET status = 'queued', error = 'retry' WHERE id = :i", ['i' => (int) $job['id']]),
        default     => Database::run("UPDATE ap_jobs SET status = 'failed', finished_at = NOW(), error = :e WHERE id = :i",
                           ['e' => mb_substr(ApAi::lastError() !== '' ? ApAi::lastError() : 'no AI reader answered', 0, 250), 'i' => (int) $job['id']]),
    };
    $st === 'done' ? $done++ : ($st === 'failed' ? $failed++ : null);
}

/**
 * Read an ID photo with the AI ladder; the join form polls read_status for the result.
 *
 * @return string done | retry (once) | preempted (WhatsApp needed the model) | failed
 */
function apw_doc(array $job): string
{
    $p = json_decode((string) $job['text'], true);
    if (!is_array($p)) {
        return 'failed';
    }
    $app = AgentPro::draft((string) ($p['key'] ?? ''));
    $abs = AgentPro::privateAbs((string) ($p['path'] ?? ''));
    if ($app === null || $abs === null) {
        return 'failed';
    }
    $side = (string) ($p['side'] ?? 'front') === 'back' ? 'back' : 'front';
    $r = ApDocRead::readAi($abs, (string) ($p['type'] ?? ''), $side);
    if ($r === null) {
        return (int) $job['attempts'] < 2 ? 'retry' : 'failed';
    }
    if (!empty($r['preempted'])) {
        return 'preempted';
    }
    $fields = $r['fields'];
    $warnings = [];
    if (!empty($fields['_dob_bs'])) {
        $warnings[] = 'dob_from_bs';
    }
    unset($fields['_dob_bs']);
    // The readout keeps what the AI read, with its confidence and which reader (vps / gemini) — the office
    // review and the join form show "read by the AI — please check" from it.
    $all = jsonColumn($app['readout'] ?? null);
    $all['ai_' . $side] = [
        'fields'     => $fields,
        'sources'    => array_fill_keys(array_keys($fields), 'ai'),
        'confidence' => $r['confidence'],
        'reader'     => $r['reader'],
        'warnings'   => $warnings,
        'is_id'      => $r['is_id'],
        'at'         => date('c'),
    ];
    Database::update('ap_applications', ['readout' => json_encode($all, JSON_UNESCAPED_UNICODE)], 'id = :i', ['i' => (int) $app['id']]);
    Database::update('ap_jobs', ['reply' => json_encode(['fields' => $fields, 'confidence' => $r['confidence'], 'reader' => $r['reader'],
        'note' => $r['note']], JSON_UNESCAPED_UNICODE)], 'id = :i', ['i' => (int) $job['id']]);
    return 'done';
}

// Housekeeping (cheap; the WhatsApp worker does it).
$purged = 0;
if ($housekeeping) {
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
}

apw_unlock($waLock);
apw_unlock($docLock);
cron_done(['answered' => $done, 'failed' => $failed, 'docs' => $docs, 'old_jobs' => $purged]);
