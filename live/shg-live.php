<?php
/**
 * =====================================================================
 *  live/shg-live.php - the SHARI NAV live hub (Phase 2, 22 Oct 2026).
 *
 *  One small long-running PHP process (systemd unit shg-live, user
 *  www-data). It knows nothing about the database:
 *
 *    127.0.0.1:8090/tcp  WebSocket, behind nginx  location ^~ /live/
 *                        wss://www.shreehariglobal.in/live/?ch=bus,rep
 *                        a phone may change its channels with {"sub":[...]}
 *    127.0.0.1:8091/udp  publish: "<hmac-sha256 hex>\n<json>" from
 *                        NavLive::publish(), key in /var/lib/shg-live/key
 *
 *  Every datagram {ch, d, t} goes, as one text frame, to the sockets that
 *  listen on ch. The last frame of each channel is kept 2 minutes so a
 *  phone that joins late sees the bus / the shared trip at once.
 *  Channels: share:<32 hex>, bus, rep.
 *
 *  Why PHP and not Node: the VPS has PHP 8.3 CLI and no Node runtime. The
 *  hub is plain RFC 6455 text frames, ping/pong and close - nothing a
 *  library is needed for. stream_select() is select(2): one process stays
 *  under ~1000 sockets (MAX_CLIENTS). Past that, run a second hub.
 *
 *  It is never reachable as a web page: nginx sends all of /live/ to the
 *  socket, and the guard below answers 404 anywhere but the CLI.
 * ===================================================================== */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const WS_ADDR     = 'tcp://127.0.0.1:8090';
const PUB_ADDR    = 'udp://127.0.0.1:8091';
const KEY_FILE    = '/var/lib/shg-live/key';
const STATS_FILE  = '/run/shg-live/stats.json';
const MAX_CLIENTS = 900;
const MAX_CH      = 6;
const MAX_FRAME   = 4096;          /* a phone only ever sends {"sub":[...]} */
const MAX_OUTBUF  = 262144;        /* a phone that cannot keep up is dropped */
const PING_S      = 25;
const IDLE_S      = 75;
const LAST_KEEP_S = 120;
const ORIGINS     = ['https://www.shreehariglobal.in', 'https://shreehariglobal.in',
                     'https://www.shreehariglobal.network', 'https://shreehariglobal.network'];

$key = trim((string) @file_get_contents(KEY_FILE));
if ($key === '') {
    fwrite(STDERR, "shg-live: no key in " . KEY_FILE . "\n");
    exit(1);
}
$ws  = @stream_socket_server(WS_ADDR, $errno, $errstr);
$pub = @stream_socket_server(PUB_ADDR, $errno2, $errstr2, STREAM_SERVER_BIND);
if ($ws === false || $pub === false) {
    fwrite(STDERR, "shg-live: cannot listen: $errstr $errstr2\n");
    exit(1);
}
stream_set_blocking($ws, false);
stream_set_blocking($pub, false);

/** @var array<int, array{s: resource, in: string, out: string, up: bool, ch: array<string,bool>, seen: int, born: int}> */
$C    = [];
$SUB  = [];      /* channel => [client id => true] */
$LAST = [];      /* channel => [frame, unix time] */
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });
    pcntl_signal(SIGINT, function () use (&$stop) { $stop = true; });
}

function logl(string $s): void { fwrite(STDOUT, date('H:i:s') . ' ' . $s . "\n"); }
function chOk(string $c): bool { return preg_match('/^(share:[a-f0-9]{32}|bus|rep)$/', $c) === 1; }
function frame(string $payload, int $op = 1): string
{
    $n = strlen($payload);
    $h = chr(0x80 | $op);
    if ($n < 126) {
        $h .= chr($n);
    } elseif ($n < 65536) {
        $h .= chr(126) . pack('n', $n);
    } else {
        $h .= chr(127) . pack('J', $n);
    }
    return $h . $payload;
}
function send(int $id, string $bytes): void
{
    global $C;
    if (!isset($C[$id])) {
        return;
    }
    $C[$id]['out'] .= $bytes;
    if (strlen($C[$id]['out']) > MAX_OUTBUF) {
        drop($id, 'slow');
    }
}
function drop(int $id, string $why = ''): void
{
    global $C, $SUB;
    if (!isset($C[$id])) {
        return;
    }
    foreach ($C[$id]['ch'] as $ch => $_) {
        unset($SUB[$ch][$id]);
        if (empty($SUB[$ch])) {
            unset($SUB[$ch]);
        }
    }
    @fclose($C[$id]['s']);
    unset($C[$id]);
}
function subscribe(int $id, array $list): void
{
    global $C, $SUB, $LAST;
    foreach ($C[$id]['ch'] as $ch => $_) {
        unset($SUB[$ch][$id]);
        if (empty($SUB[$ch])) {
            unset($SUB[$ch]);
        }
    }
    $C[$id]['ch'] = [];
    $now = time();
    foreach (array_slice(array_values(array_unique($list)), 0, MAX_CH) as $ch) {
        if (!is_string($ch) || !chOk($ch)) {
            continue;
        }
        $C[$id]['ch'][$ch] = true;
        $SUB[$ch][$id] = true;
        if (isset($LAST[$ch]) && $now - $LAST[$ch][1] <= LAST_KEEP_S) {
            send($id, $LAST[$ch][0]);
        }
    }
}
function handshake(int $id): void
{
    global $C;
    $buf = $C[$id]['in'];
    $end = strpos($buf, "\r\n\r\n");
    if ($end === false) {
        if (strlen($buf) > 8192) {
            drop($id, 'header');
        }
        return;
    }
    $C[$id]['in'] = (string) substr($buf, $end + 4);
    $lines = explode("\r\n", substr($buf, 0, $end));
    $req   = explode(' ', (string) array_shift($lines));
    $h = [];
    foreach ($lines as $l) {
        $p = strpos($l, ':');
        if ($p !== false) {
            $h[strtolower(trim(substr($l, 0, $p)))] = trim(substr($l, $p + 1));
        }
    }
    $origin = $h['origin'] ?? '';
    $wsKey  = $h['sec-websocket-key'] ?? '';
    if (($req[0] ?? '') !== 'GET' || stripos($h['upgrade'] ?? '', 'websocket') === false || $wsKey === ''
        || ($origin !== '' && !in_array($origin, ORIGINS, true))) {
        @fwrite($C[$id]['s'], "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        drop($id, 'bad request');
        return;
    }
    $accept = base64_encode(sha1($wsKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    send($id, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n");
    $C[$id]['up'] = true;
    $q = [];
    parse_str((string) parse_url($req[1] ?? '', PHP_URL_QUERY), $q);
    subscribe($id, explode(',', (string) ($q['ch'] ?? '')));
}
function frames(int $id): void
{
    global $C;
    while (isset($C[$id])) {
        $b = $C[$id]['in'];
        if (strlen($b) < 2) {
            return;
        }
        $op = ord($b[0]) & 0x0F;
        $masked = (ord($b[1]) & 0x80) !== 0;
        $len = ord($b[1]) & 0x7F;
        $o = 2;
        if ($len === 126) {
            if (strlen($b) < 4) {
                return;
            }
            $len = unpack('n', substr($b, 2, 2))[1];
            $o = 4;
        } elseif ($len === 127) {
            drop($id, 'too big');
            return;
        }
        if (!$masked || $len > MAX_FRAME) {
            drop($id, 'frame');
            return;
        }
        if (strlen($b) < $o + 4 + $len) {
            return;
        }
        $mask = substr($b, $o, 4);
        $data = substr($b, $o + 4, $len);
        $C[$id]['in'] = (string) substr($b, $o + 4 + $len);
        $plain = '';
        for ($i = 0; $i < $len; $i++) {
            $plain .= $data[$i] ^ $mask[$i % 4];
        }
        $C[$id]['seen'] = time();
        if ($op === 8) {
            send($id, frame('', 8));
            flush_one($id);
            drop($id, 'close');
            return;
        }
        if ($op === 9) {
            send($id, frame($plain, 10));
        } elseif ($op === 1) {
            $j = json_decode($plain, true);
            if (is_array($j) && isset($j['sub']) && is_array($j['sub'])) {
                subscribe($id, $j['sub']);
            }
        }
    }
}
function flush_one(int $id): void
{
    global $C;
    if (!isset($C[$id]) || $C[$id]['out'] === '') {
        return;
    }
    $n = @fwrite($C[$id]['s'], $C[$id]['out']);
    if ($n === false) {
        drop($id, 'write');
        return;
    }
    $C[$id]['out'] = (string) substr($C[$id]['out'], $n);
}
function publish(string $raw, string $key): void
{
    global $SUB, $LAST;
    $nl = strpos($raw, "\n");
    if ($nl === false) {
        return;
    }
    $mac = substr($raw, 0, $nl);
    $json = substr($raw, $nl + 1);
    if (!hash_equals(hash_hmac('sha256', $json, $key), $mac)) {
        return;
    }
    $j = json_decode($json, true);
    $ch = is_array($j) ? (string) ($j['ch'] ?? '') : '';
    if (!chOk($ch)) {
        return;
    }
    $f = frame($json);
    $LAST[$ch] = [$f, time()];
    foreach (array_keys($SUB[$ch] ?? []) as $id) {
        send($id, $f);
    }
}

logl('shg-live listening on ' . WS_ADDR . ' + ' . PUB_ADDR);
$nextId = 1;
$tick = 0;
while (!$stop) {
    $read = [$ws, $pub];
    $write = [];
    foreach ($C as $id => $c) {
        $read[] = $c['s'];
        if ($c['out'] !== '') {
            $write[] = $c['s'];
        }
    }
    $except = null;
    $n = @stream_select($read, $write, $except, 1, 0);
    if ($n === false) {
        usleep(100000);
        continue;
    }
    foreach ($read as $s) {
        if ($s === $ws) {
            $cs = @stream_socket_accept($ws, 0);
            if ($cs === false) {
                continue;
            }
            if (count($C) >= MAX_CLIENTS) {
                @fclose($cs);
                continue;
            }
            stream_set_blocking($cs, false);
            $C[$nextId++] = ['s' => $cs, 'in' => '', 'out' => '', 'up' => false, 'ch' => [], 'seen' => time(), 'born' => time()];
            continue;
        }
        if ($s === $pub) {
            for ($k = 0; $k < 200; $k++) {
                $raw = @stream_socket_recvfrom($pub, 65535, 0, $peer);
                if (!is_string($raw) || $raw === '') {
                    break;
                }
                if (strpos((string) $peer, '127.0.0.1:') === 0) {
                    publish($raw, $key);
                }
            }
            continue;
        }
        $id = null;
        foreach ($C as $cid => $c) {
            if ($c['s'] === $s) {
                $id = $cid;
                break;
            }
        }
        if (!is_int($id) || !isset($C[$id])) {
            continue;
        }
        $data = @fread($s, 8192);
        if ($data === false || ($data === '' && feof($s))) {
            drop($id, 'eof');
            continue;
        }
        $C[$id]['in'] .= $data;
        if (!$C[$id]['up']) {
            handshake($id);
        }
        if (isset($C[$id]) && $C[$id]['up']) {
            frames($id);
        }
    }
    foreach ($write as $s) {
        foreach ($C as $cid => $c) {
            if ($c['s'] === $s) {
                flush_one($cid);
                break;
            }
        }
    }
    /* once a second: pings, idle sockets, stale "last" frames, stats */
    $now = time();
    if ($now !== $tick) {
        $tick = $now;
        foreach ($C as $id => $c) {
            if (!$c['up'] && $now - $c['born'] > 10) {
                drop($id, 'no handshake');
            } elseif ($now - $c['seen'] > IDLE_S) {
                drop($id, 'idle');
            } elseif ($c['up'] && ($now - $c['seen']) % PING_S === PING_S - 1) {
                send($id, frame('', 9));
            }
        }
        if ($now % 10 === 0) {
            foreach ($LAST as $ch => $l) {
                if ($now - $l[1] > LAST_KEEP_S) {
                    unset($LAST[$ch]);
                }
            }
            $subs = ['bus' => count($SUB['bus'] ?? []), 'rep' => count($SUB['rep'] ?? []), 'share' => 0];
            foreach ($SUB as $ch => $ids) {
                if (strpos($ch, 'share:') === 0) {
                    $subs['share'] += count($ids);
                }
            }
            @file_put_contents(STATS_FILE . '.tmp', json_encode(['t' => $now, 'clients' => count($C), 'subs' => $subs]));
            @rename(STATS_FILE . '.tmp', STATS_FILE);
        }
    }
}
foreach (array_keys($C) as $id) {
    send($id, frame(pack('n', 1001), 8));
    flush_one($id);
    drop($id, 'shutdown');
}
logl('shg-live stopped');
