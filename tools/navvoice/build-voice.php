<?php
/**
 * tools/navvoice/build-voice.php — records SHARI NAV's spoken directions
 * (21 Oct 2026, owner: "Nepali voice … ekdam sweet, ElevenLabs jasto").
 *
 *   sudo -u www-data php tools/navvoice/build-voice.php <phrases.json> <lang> <voice> <outdir> [id,id,…]
 *
 * Every phrase of one language (assets/navvoice/phrases.json) is spoken by
 * a Gemini neural voice, here on the server (the key never leaves it), and
 * saved as a trimmed, loudness-matched 24 kHz mono WAV:
 *   - several phrases go to one request (the free TTS quota is ~10 requests
 *     per model per day) and the answer is cut at its pauses; a batch that
 *     does not cut cleanly is halved
 *   - every clip is transcribed back and compared with its text; a clip
 *     that does not match is spoken again (3 takes at most), the better
 *     take is kept, and nothing unchecked is saved unless asked
 *   - a re-run continues where the last one stopped
 * The WAVs are then encoded to MP3 and published under assets/navvoice/
 * <lang>-<voice>/ (see README.md next to this file). navigate.html plays
 * them; nothing here runs on a page request.
 *
 * Environment:
 *   NAVVOICE_MODELS=gemini-3.1-flash-tts-preview   one model = one consistent voice for the pack
 *   NAVVOICE_BATCH=18                               phrases per request (default 6)
 *   NAVVOICE_ASR=clip|batch                         check per clip, or one call per batch (default batch)
 *   NAVVOICE_ASR_MAX=120                            most transcription calls in one run
 *   NAVVOICE_FORCE=1                                record again even what is already good
 *   NAVVOICE_ALLOW_UNCHECKED=1                      keep clips the check could not hear
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('SHG_APP', true);
$root = is_file(dirname(__DIR__, 2) . '/includes/bootstrap.php') ? dirname(__DIR__, 2) : (getenv('SHG_ROOT') ?: '/var/www/shreehariglobal.in/public_html');
require_once $root . '/includes/bootstrap.php';

[$self, $file, $lang, $voice, $out] = $argv + [null, '', '', '', ''];
$only = isset($argv[5]) ? array_values(array_filter(array_map('trim', explode(',', $argv[5])))) : [];
if ($file === '' || $lang === '' || $voice === '' || $out === '') {
    fwrite(STDERR, "usage: php build-voice.php <phrases.json> <lang> <voice> <outdir> [ids]\n");
    exit(2);
}
$all = json_decode((string) file_get_contents($file), true);
$phrases = $all[$lang] ?? null;
if (!is_array($phrases) || $phrases === []) {
    fwrite(STDERR, "no phrases for $lang\n");
    exit(2);
}
foreach (array_keys($phrases) as $id) {                         // ids become file names
    if (preg_match('/^[a-z0-9_]{1,40}$/', (string) $id) !== 1) {
        fwrite(STDERR, "bad phrase id: $id\n");
        exit(2);
    }
}
if ($only !== []) {
    $phrases = array_intersect_key($phrases, array_flip($only));
}
$key = trim(Settings::getString('gemini_api_key', ''));
if ($key === '') {
    fwrite(STDERR, "no gemini_api_key\n");
    exit(2);
}
@mkdir($out, 0775, true);

const RATE = 24000;
/* lite models for the check: the WhatsApp assistant, voice notes and ID checks use the full flash models */
const ASR_MODELS = ['gemini-3.1-flash-lite', 'gemini-3.5-flash-lite'];
$GLOBALS['TTS'] = getenv('NAVVOICE_MODELS') ? array_values(array_filter(array_map('trim', explode(',', (string) getenv('NAVVOICE_MODELS')))))
    : ['gemini-3.8-flash-tts', 'gemini-3.8-flash-lite-tts', 'gemini-3.1-flash-tts-preview'];
$GLOBALS['TTS_DEAD'] = [];
$GLOBALS['ASR_DEAD'] = [];
$GLOBALS['ASR_LEFT'] = max(0, (int) (getenv('NAVVOICE_ASR_MAX') ?: 120));
define('BATCH', max(1, (int) (getenv('NAVVOICE_BATCH') ?: 6)));
define('ASR_MODE', getenv('NAVVOICE_ASR') === 'clip' ? 'clip' : 'batch');

function api(string $key, string $model, array $body, int $timeout = 120): array
{
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key]]);
    $raw = (string) curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$http, json_decode($raw, true), $raw];
}

/** PCM s16le 24 kHz for the text, walking the model ladder; a model that is out of quota or unusable is dropped. */
function speak(string $key, string $voice, string $text): ?string
{
    $body = ['contents' => [['role' => 'user', 'parts' => [['text' => $text]]]],
        'generationConfig' => ['responseModalities' => ['AUDIO'],
            'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]]]];
    for ($round = 0; $round < 4; $round++) {
        $live = array_diff($GLOBALS['TTS'], $GLOBALS['TTS_DEAD']);
        if ($live === []) {
            return null;
        }
        $perMinute = false;
        foreach ($live as $m) {
            [$h, $j, $raw] = api($key, $m, $body, 240);
            $d = $j['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;
            if ($h === 200 && is_string($d)) {
                return base64_decode($d);
            }
            if ($h === 429 && str_contains($raw, 'PerDay')) {
                echo "    tts $m: today's quota is used up\n";
                $GLOBALS['TTS_DEAD'][] = $m;
            } elseif ($h === 429) {
                $perMinute = true;
            } elseif (in_array($h, [400, 403, 404], true)) {
                echo "    tts $m HTTP $h (dropped) ", mb_substr($raw, 0, 140), "\n";
                $GLOBALS['TTS_DEAD'][] = $m;
            } elseif ($h !== 200) {
                echo "    tts $m HTTP $h ", mb_substr($raw, 0, 100), "\n";
            }
        }
        if (!$perMinute) {
            return null;                                         // no rate limit to wait out: this text gives no audio
        }
        sleep(30);
    }
    return null;
}

/** One ASR call on the lite ladder; null when it could not run (quota / errors / run cap). */
function asr(string $key, array $body): ?string
{
    if ($GLOBALS['ASR_LEFT'] <= 0) {
        return null;
    }
    for ($round = 0; $round < 3; $round++) {
        $live = array_diff(ASR_MODELS, $GLOBALS['ASR_DEAD']);
        if ($live === []) {
            return null;
        }
        foreach ($live as $m) {
            $GLOBALS['ASR_LEFT']--;
            [$h, $j, $raw] = api($key, $m, $body, 90);
            if ($h === 200) {
                return trim((string) ($j['candidates'][0]['content']['parts'][0]['text'] ?? ''));
            }
            if (($h === 429 && str_contains($raw, 'PerDay')) || in_array($h, [400, 403, 404], true)) {
                $GLOBALS['ASR_DEAD'][] = $m;
            }
            if ($GLOBALS['ASR_LEFT'] <= 0) {
                return null;
            }
        }
        sleep(15);
    }
    return null;
}

function transcribe(string $key, string $pcm, string $lang): ?string
{
    $name = $lang === 'hi' ? 'Hindi' : 'Nepali';
    return asr($key, ['contents' => [['parts' => [
        ['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode(wav($pcm))]],
        ['text' => "Transcribe this $name speech exactly, in Devanagari. Write numbers as words, as spoken. Output only the words."]]]],
        'generationConfig' => ['temperature' => 0]]);
}

/** One transcription for a whole batch: the clips joined with pauses, one line back per clip (null if the count is off). */
function transcribeMany(string $key, array $clips, string $lang): ?array
{
    $name = $lang === 'hi' ? 'Hindi' : 'Nepali';
    $gap = array_fill(0, (int) (RATE * 0.8), 0);
    $all = [];
    foreach ($clips as $c) {
        array_push($all, ...$c, ...$gap);
    }
    $n = count($clips);
    $body = ['contents' => [['parts' => [
        ['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode(wav(pack16($all)))]],
        ['text' => "This audio has exactly $n short $name phrases separated by pauses. Transcribe each one exactly, in Devanagari, numbers as words as spoken. Output exactly $n lines, one phrase per line, in order, nothing else."]]]],
        'generationConfig' => ['temperature' => 0]];
    for ($try = 0; $try < 2; $try++) {
        $txt = asr($key, $body);
        if ($txt === null) {
            return null;
        }
        $lines = [];
        foreach (preg_split('/\R/u', $txt) ?: [] as $l) {
            $l = trim((string) preg_replace('/^\s*\d+[.)]\s*/u', '', $l));
            if ($l !== '' && !str_starts_with($l, '```')) {
                $lines[] = $l;
            }
        }
        if (count($lines) === $n) {
            return $lines;
        }
    }
    return null;
}

function samples(string $pcm): array
{
    $s = array_values(unpack('v*', $pcm));
    foreach ($s as $i => $v) {
        if ($v >= 32768) {
            $s[$i] = $v - 65536;
        }
    }
    return $s;
}

function pack16(array $s): string
{
    $o = '';
    foreach ($s as $v) {
        $o .= pack('v', ((int) round(max(-32768, min(32767, $v)))) & 0xFFFF);
    }
    return $o;
}

function wav(string $pcm): string
{
    return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, RATE, RATE * 2, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
}

/** RMS per 20 ms window. */
function envelope(array $s): array
{
    $w = 480;
    $env = [];
    for ($i = 0, $n = count($s); $i < $n; $i += $w) {
        $sum = 0.0;
        $c = 0;
        for ($k = $i; $k < min($n, $i + $w); $k++) {
            $sum += $s[$k] * $s[$k];
            $c++;
        }
        $env[] = $c ? sqrt($sum / $c) : 0.0;
    }
    return $env;
}

/** Cut a batch at its pauses into exactly $want pieces (sample arrays), or null. */
function cut(array $s, int $want): ?array
{
    if ($want === 1) {
        return [$s];
    }
    $env = envelope($s);
    $gaps = [];
    $run = 0;
    foreach ($env as $i => $r) {
        if ($r < 260) {
            $run++;
            continue;
        }
        if ($run >= 18 && $i - $run > 0) {                       // >= 360 ms of quiet between two spoken parts
            $gaps[] = ['at' => $i - intdiv($run, 2), 'len' => $run];
        }
        $run = 0;
    }
    if (count($gaps) < $want - 1) {
        return null;
    }
    usort($gaps, static fn ($a, $b) => $b['len'] <=> $a['len']);
    $cuts = array_slice($gaps, 0, $want - 1);
    usort($cuts, static fn ($a, $b) => $a['at'] <=> $b['at']);
    $pieces = [];
    $from = 0;
    foreach ($cuts as $g) {
        $pieces[] = array_slice($s, $from * 480, ($g['at'] - $from) * 480);
        $from = $g['at'];
    }
    $pieces[] = array_slice($s, $from * 480);
    return $pieces;
}

/** Trim to the speech (+60 ms), 8 ms fades, match loudness (RMS of the loud part), keep the peak under -1 dBFS. */
function finish(array $s): ?array
{
    $env = envelope($s);
    $on = array_keys(array_filter($env, static fn ($r) => $r > 260));
    if ($on === []) {
        return null;
    }
    $a = max(0, ($on[0] - 3) * 480);
    $b = min(count($s), (end($on) + 4) * 480);
    $s = array_slice($s, $a, $b - $a);
    $loud = array_filter($env, static fn ($r) => $r > 900);
    $rms = $loud ? array_sum($loud) / count($loud) : 2500.0;
    $peak = 1.0;
    foreach ($s as $v) {
        $peak = max($peak, abs($v));
    }
    $g = min(3200.0 / max($rms, 1.0), 29000.0 / $peak);
    $n = count($s);
    $f = 192;                                                    // 8 ms
    foreach ($s as $i => $v) {
        $k = $g;
        if ($i < $f) {
            $k *= $i / $f;
        } elseif ($i > $n - $f) {
            $k *= ($n - $i) / $f;
        }
        $s[$i] = $v * $k;
    }
    return $s;
}

function norm(string $t): string
{
    $t = preg_replace('/[\s\p{P}\x{0964}\x{0965}\x{200C}\x{200D}-]+/u', '', mb_strtolower($t)) ?? $t;
    return str_replace(['ं', 'ँ', 'ऽ'], ['', '', ''], $t);       // anusvara / chandrabindu are often swapped by ASR
}

function similar(string $a, string $b): float
{
    $a = norm($a);
    $b = norm($b);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    $x = mb_str_split($a);
    $y = mb_str_split($b);
    $m = count($x);
    $n = count($y);
    $prev = range(0, $n);
    for ($i = 1; $i <= $m; $i++) {
        $cur = [$i];
        for ($j = 1; $j <= $n; $j++) {
            $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + ($x[$i - 1] === $y[$j - 1] ? 0 : 1));
        }
        $prev = $cur;
    }
    return 1 - $prev[$n] / max($m, $n);
}

/* ---------------------------------------------------------------- */

$indexFile = "$out/index.json";
$index = [];
if (is_file($indexFile)) {
    $index = json_decode((string) file_get_contents($indexFile), true);
    if (!is_array($index)) {
        fwrite(STDERR, "$indexFile is unreadable: fix or remove it first\n");
        exit(1);
    }
}
/* what the check should hear: a phrase may be written with numerals for the
   voice (३०० …) while it is spoken as words (तीन सय …) */
$expect = static fn (string $id): string => (string) ($all[$lang . '_check'][$id] ?? $phrases[$id]);
/** keeps the better take: a later, worse recording never replaces a good one */
$override = getenv('NAVVOICE_FORCE') === '1' || $only !== [];
$runBest = [];
$save = static function (string $id, array $s, string $heard, float $sim) use ($out, $indexFile, &$index, $override, &$runBest): bool {
    /* keep the better take; with FORCE or named ids only takes of this run compete */
    $prev = $override ? ($runBest[$id] ?? null) : ($index[$id]['sim'] ?? null);
    if ($prev !== null && is_file("$out/$id.wav") && $sim <= $prev) {
        echo "    $id: kept the earlier take ($prev)\n";
        return false;
    }
    file_put_contents("$out/$id.wav.tmp", wav(pack16($s)));
    rename("$out/$id.wav.tmp", "$out/$id.wav");
    $runBest[$id] = $sim;
    $index[$id] = ['sec' => round(count($s) / RATE, 2), 'heard' => $heard, 'sim' => round($sim, 3)];
    file_put_contents("$indexFile.tmp", json_encode($index, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    rename("$indexFile.tmp", $indexFile);
    return true;
};
/** [heard, similarity] — similarity -1 when the check itself could not run */
$verify = static function (string $id, array $s) use ($key, $lang, $expect): array {
    $heard = transcribe($key, pack16($s), $lang);
    return $heard === null ? ['', -1.0] : [$heard, similar($heard, $expect($id))];
};

$ids = array_keys($phrases);
if ($only === [] && getenv('NAVVOICE_FORCE') !== '1') {          // resume: what is already good is not recorded again
    $before = count($ids);
    $ids = array_values(array_filter($ids, static fn ($id) => !(is_file("$out/$id.wav") && ($index[$id]['sim'] ?? -1) >= 0.8)));
    if ($before !== count($ids)) {
        echo 'skipping ', $before - count($ids), " clips already recorded and checked\n";
    }
}
echo "$lang / $voice: ", count($ids), " phrases -> $out (batch ", BATCH, ', check ', ASR_MODE, ', models ', implode(',', $GLOBALS['TTS']), ")\n";
$queue = array_chunk($ids, BATCH);
$tries = [];                                                     // per phrase: TTS attempts
$nulls = 0;                                                      // consecutive requests with no audio
$unchecked = [];
$gaveUp = [];
while ($queue !== []) {
    if (array_diff($GLOBALS['TTS'], $GLOBALS['TTS_DEAD']) === []) {
        echo "stopping: every TTS model is out of today's quota (or unusable); run again tomorrow for the rest\n";
        break;
    }
    if (getenv('NAVVOICE_ALLOW_UNCHECKED') !== '1' && ($GLOBALS['ASR_LEFT'] <= 0 || array_diff(ASR_MODELS, $GLOBALS['ASR_DEAD']) === [])) {
        echo "stopping: the transcription check is unavailable (quota or cap); run again later\n";
        break;
    }
    $chunk = array_shift($queue);
    $text = implode("\n\n", array_map(static fn ($id) => $phrases[$id], $chunk));
    $pcm = speak($key, $voice, $text);
    if ($pcm === null) {
        echo '    no audio for: ', implode(',', $chunk), "\n";
        if (count($chunk) > 1) {
            $half = (int) ceil(count($chunk) / 2);
            array_unshift($queue, array_slice($chunk, 0, $half), array_slice($chunk, $half));
            continue;
        }
        $id0 = $chunk[0];
        $tries[$id0] = ($tries[$id0] ?? 0) + 1;
        if (++$nulls >= 3) {
            echo "stopping: three single phrases in a row gave no audio\n";
            break;
        }
        if ($tries[$id0] < 3) {
            $queue[] = $chunk;
        } else {
            $gaveUp[] = $id0;
        }
        continue;
    }
    $nulls = 0;
    $pieces = cut(samples($pcm), count($chunk));
    if ($pieces === null) {
        echo '  batch ', $chunk[0], '…: ', count($chunk), " phrases did not cut cleanly, halving\n";
        $half = (int) ceil(count($chunk) / 2);
        array_unshift($queue, array_slice($chunk, 0, $half), array_slice($chunk, $half));
        continue;
    }
    $fin = [];
    foreach ($chunk as $i => $id) {
        $fin[$id] = finish($pieces[$i]);
        $tries[$id] = ($tries[$id] ?? 0) + 1;
    }
    /* the check: one call for the batch, per clip if that does not line up */
    $heardAll = null;
    if (ASR_MODE === 'batch' && count(array_filter($fin)) > 1) {
        $heardAll = transcribeMany($key, array_values(array_filter($fin)), $lang);
    }
    $k = 0;
    $again = [];
    foreach ($chunk as $id) {
        if ($fin[$id] === null) {
            if ($tries[$id] < 3) {
                $again[] = $id;
            } else {
                $gaveUp[] = $id;
            }
            continue;
        }
        if ($heardAll !== null) {
            $h = $heardAll[$k];
            $sim = similar($h, $expect($id));
        } else {
            [$h, $sim] = $verify($id, $fin[$id]);
        }
        $k++;
        if ($sim < 0) {                                          // could not check: kept only when asked to
            $unchecked[] = $id;
            if (getenv('NAVVOICE_ALLOW_UNCHECKED') === '1' && !isset($index[$id])) {
                $save($id, $fin[$id], '', -1.0);
            }
            printf("  %-16s  ---  (not checked)\n", $id);
            continue;
        }
        if ($sim >= 0.6) {
            $save($id, $fin[$id], $h, $sim);
        }
        $redo = $sim < 0.8 && $tries[$id] < 3;
        printf("  %-16s %5.2f  %s%s\n", $id, $sim, $h, $sim < 0.8 ? ($redo ? '   <-- again' : '   <-- gave up') : '');
        if ($redo) {
            $again[] = $id;
        } elseif ($sim < 0.8) {
            $gaveUp[] = $id;
        }
    }
    if ($again !== []) {
        $queue[] = $again;
    }
}
$low = array_filter($index, static fn ($v) => ($v['sim'] ?? 0) < 0.8);
$missing = array_values(array_diff(array_keys($phrases), array_keys($index)));
echo 'done: ', count($index), ' clips, ', count($low), ' below 0.8 or unchecked, ', count($missing), ' missing',
    $missing ? ': ' . implode(',', $missing) : '', "\n";
if ($unchecked !== []) {
    echo 'not checked (the transcription check was unavailable): ', implode(',', array_unique($unchecked)), "\n";
}
if ($gaveUp !== []) {
    echo 'gave up after 3 takes: ', implode(',', array_unique($gaveUp)), "\n";
}
