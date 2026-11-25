<?php
/**
 * =====================================================================
 *  includes/bankmail.php — BankMail: read the Global IME Bank credit
 *  alerts that arrive in a Gmail box (25 Nov 2026, mp1 "pay").
 *
 *  Plain-PHP IMAP over TLS (stream_socket_client ssl://host:993) - no
 *  ext-imap (not installed on the VPS, and gone from PHP 8.4). The box is
 *  opened READ-ONLY (EXAMINE) and every message is fetched with
 *  BODY.PEEK[], so nothing in the mailbox ever changes (not even "read").
 *
 *  Each new message from an allowed sender is parsed (parse() is pure and
 *  unit-tested with fixture e-mails): From, Message-ID, Date, the TOPMOST
 *  Authentication-Results (added by Gmail itself: dkim=pass with header.d
 *  = bankmail_dkim_domain), the text (quoted-printable / base64, HTML made
 *  text), then a generic credit-alert reader: amount (NPR / NRs / Rs / रु,
 *  commas, Devanagari digits), transaction / reference id, remarks /
 *  narration and any PNR in it. Only credit (deposit / credited) alerts
 *  are kept; a debit is ignored. bankmail_amount_regex / bankmail_txn_regex
 *  (capture group 1) let the owner fix the reading for the bank's real
 *  wording without a code change.
 *
 *  A credit goes to PayCredits::record() + tryAutoConfirm() - which, not
 *  this file, decides whether money may confirm a ticket.
 *
 *  Settings: bankmail_on (off), bankmail_host (imap.gmail.com), bankmail_user,
 *  bankmail_app_password (secret), bankmail_from (allowed senders: addresses
 *  or @domains, comma list), bankmail_dkim_domain, bankmail_authserv
 *  (mx.google.com), pay_bankmail_autoconfirm_on (on), bankmail_last_uid,
 *  bankmail_uidvalidity, bankmail_last_poll (JSON, for the admin page).
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/paycredits.php';

/** A tiny IMAP4rev1 client: just what a read-only poll needs. */
final class BankMailImap
{
    /** @var resource */
    private $in;
    /** @var resource */
    private $out;
    private int $tag = 0;

    /**
     * @param resource      $in  where server responses are read from
     * @param resource|null $out where commands are written (the same socket in real use)
     */
    public function __construct($in, $out = null)
    {
        $this->in  = $in;
        $this->out = $out ?? $in;
    }

    public static function connect(string $host, int $port = 993, int $timeout = 15): self
    {
        if (preg_match('/^[A-Za-z0-9.\-]{1,190}$/', $host) !== 1) {
            throw new RuntimeException('Bad IMAP host name.');
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $errno = 0;
        $errstr = '';
        $s = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!is_resource($s)) {
            throw new RuntimeException('Could not reach ' . $host . ':' . $port . ' (' . ($errstr !== '' ? $errstr : 'error ' . $errno) . ')');
        }
        stream_set_timeout($s, 25);
        $me = new self($s);
        $greet = $me->readLine();
        if (!str_starts_with($greet, '* OK')) {
            throw new RuntimeException('IMAP server did not greet with OK.');
        }
        return $me;
    }

    private function readLine(): string
    {
        $l = fgets($this->in);
        if ($l === false) {
            $meta = stream_get_meta_data($this->in);
            throw new RuntimeException(!empty($meta['timed_out']) ? 'IMAP server timed out.' : 'IMAP connection closed.');
        }
        return $l;
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->in, min(65536, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->in);
                if (!empty($meta['timed_out']) || feof($this->in)) {
                    throw new RuntimeException('IMAP literal cut short.');
                }
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /**
     * Send one command and read until its tagged reply.
     *
     * @return array{ok:bool, status:string, responses:list<array{text:string, lits:list<string>}>}
     */
    public function command(string $cmd): array
    {
        $tag = 'S' . str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
        if (fwrite($this->out, $tag . ' ' . $cmd . "\r\n") === false) {
            throw new RuntimeException('IMAP write failed.');
        }
        $responses = [];
        $cur = null;
        for ($guard = 0; $guard < 200000; $guard++) {
            $line = $this->readLine();
            if ($cur === null && str_starts_with($line, $tag . ' ')) {
                $rest = trim(substr($line, strlen($tag) + 1));
                return ['ok' => str_starts_with(strtoupper($rest), 'OK'), 'status' => $rest, 'responses' => $responses];
            }
            if ($cur === null) {
                $cur = ['text' => '', 'lits' => []];
            }
            if (preg_match('/\{(\d{1,9})\}\r?\n$/', $line, $m) === 1) {
                $cur['text'] .= substr($line, 0, -strlen($m[0])) . ' ';
                $cur['lits'][] = $this->readBytes((int) $m[1]);
                continue;   // the response goes on after the literal
            }
            $cur['text'] .= rtrim($line, "\r\n");
            $responses[] = $cur;
            $cur = null;
        }
        throw new RuntimeException('IMAP reply too long.');
    }

    public static function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    public function login(string $user, string $pass): void
    {
        if (preg_match('/[\x00-\x1f\x7f-\xff]/', $user . $pass) === 1) {
            throw new RuntimeException('The Gmail address / app password has characters IMAP LOGIN cannot send.');
        }
        $r = $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($pass));
        if (!$r['ok']) {
            throw new RuntimeException('Gmail refused the login (check the address, the 16-letter app password and that IMAP is on).');
        }
    }

    /**
     * Open the mailbox READ-ONLY.
     *
     * @return array{exists:int, uidvalidity:string}
     */
    public function examine(string $box = 'INBOX'): array
    {
        $r = $this->command('EXAMINE ' . self::quote($box));
        if (!$r['ok']) {
            throw new RuntimeException('Could not open ' . $box . '.');
        }
        $exists = 0;
        $validity = '';
        foreach ($r['responses'] as $x) {
            if (preg_match('/^\*\s+(\d+)\s+EXISTS/i', $x['text'], $m) === 1) {
                $exists = (int) $m[1];
            }
            if (preg_match('/UIDVALIDITY\s+(\d+)/i', $x['text'], $m) === 1) {
                $validity = $m[1];
            }
        }
        return ['exists' => $exists, 'uidvalidity' => $validity];
    }

    /** @return list<int> */
    public function uidSearch(string $criteria): array
    {
        $r = $this->command('UID SEARCH ' . $criteria);
        if (!$r['ok']) {
            throw new RuntimeException('IMAP search failed.');
        }
        $out = [];
        foreach ($r['responses'] as $x) {
            if (preg_match('/^\*\s+SEARCH\b(.*)$/i', $x['text'], $m) === 1) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $n) {
                    if (ctype_digit($n)) {
                        $out[] = (int) $n;
                    }
                }
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /** The whole message, without marking it read (BODY.PEEK). '' = gone. */
    public function uidFetch(int $uid): string
    {
        $r = $this->command('UID FETCH ' . $uid . ' (UID BODY.PEEK[])');
        if (!$r['ok']) {
            throw new RuntimeException('IMAP fetch failed.');
        }
        foreach ($r['responses'] as $x) {
            if ($x['lits'] !== [] && preg_match('/\bUID\s+' . $uid . '\b/', $x['text']) === 1) {
                return $x['lits'][0];
            }
        }
        foreach ($r['responses'] as $x) {
            if ($x['lits'] !== []) {
                return $x['lits'][0];
            }
        }
        return '';
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (Throwable $e) {
            // closing anyway
        }
        if (is_resource($this->out)) {
            @fclose($this->out);
        }
    }
}

final class BankMail
{
    public static function on(): bool
    {
        return Settings::getBool('bankmail_on', false);
    }

    /** @return array{host:string, user:string, pass:string, from:list<string>, dkim:string, authserv:string} */
    public static function config(): array
    {
        $from = array_values(array_filter(array_map(static fn(string $s): string => strtolower(trim($s)),
            explode(',', Settings::getString('bankmail_from', '')))));
        return [
            'host'     => trim(Settings::getString('bankmail_host', 'imap.gmail.com')) ?: 'imap.gmail.com',
            'user'     => trim(Settings::getString('bankmail_user', '')),
            'pass'     => str_replace(' ', '', Settings::getString('bankmail_app_password', '')),
            'from'     => $from,
            'dkim'     => strtolower(trim(Settings::getString('bankmail_dkim_domain', ''))),
            'authserv' => strtolower(trim(Settings::getString('bankmail_authserv', 'mx.google.com'))),
        ];
    }

    /* =================================================================
     *  parse — pure: raw RFC 822 text in, facts out
     * ================================================================= */

    /**
     * @param array<string,string>|null $opts dkim_domain, authserv, amount_regex, txn_regex (null = from Settings)
     * @return array<string,mixed> from, message_id, date, subject, dkim_ok, dkim_domains, text,
     *         is_credit, is_debit, amount, currency, txn_id, remarks, pnrs, pnr, why
     */
    public static function parse(string $raw, ?array $opts = null): array
    {
        if ($opts === null) {
            $opts = [
                'dkim_domain'  => strtolower(trim(Settings::getString('bankmail_dkim_domain', ''))),
                'authserv'     => strtolower(trim(Settings::getString('bankmail_authserv', 'mx.google.com'))),
                'amount_regex' => Settings::getString('bankmail_amount_regex', ''),
                'txn_regex'    => Settings::getString('bankmail_txn_regex', ''),
            ];
        }
        [$headers, $body] = self::splitMessage($raw);
        $h1 = static function (string $name) use ($headers): string {
            foreach ($headers as [$n, $v]) {
                if ($n === $name) {
                    return $v;
                }
            }
            return '';
        };
        $from = self::address(self::decodeHeader($h1('from')));
        $msgId = trim($h1('message-id'));
        if (preg_match('/<([^>]{1,250})>/', $msgId, $m) === 1) {
            $msgId = $m[1];
        }
        $dateRaw = trim($h1('date'));
        $ts = $dateRaw !== '' ? strtotime($dateRaw) : false;
        $subject = self::decodeHeader($h1('subject'));

        /* DKIM: ONLY the topmost Authentication-Results, and only when it was written by
           the receiving server (authserv-id) - a header the sender typed lower down counts for nothing */
        $dkimDomains = [];
        $auth = $h1('authentication-results');
        $authserv = strtolower(trim((string) ($opts['authserv'] ?? '')));
        $servOk = $auth !== '' && ($authserv === '' || strtolower(trim((string) strtok($auth, ';'))) === $authserv);
        if ($servOk && preg_match_all('/\bdkim\s*=\s*pass\b([^;]*)/i', $auth, $dm, PREG_SET_ORDER) > 0) {
            foreach ($dm as $x) {
                if (preg_match('/header\.(?:d|i)\s*=\s*@?([A-Za-z0-9.\-]+)/i', $x[1], $dd) === 1) {
                    $dkimDomains[] = strtolower($dd[1]);
                }
            }
        }
        $want = strtolower(trim((string) ($opts['dkim_domain'] ?? '')));
        $dkimOk = false;
        if ($want !== '') {
            foreach ($dkimDomains as $d) {
                if ($d === $want || str_ends_with($d, '.' . $want)) {
                    $dkimOk = true;
                }
            }
        }

        $text = self::bodyText($headers, $body);
        $all  = self::normDigits(trim($subject . "\n" . $text));

        $isDebit  = preg_match('/\b(debited|withdrawn|withdrawal|debit\s+alert)\b|डेबिट|झिकिएको/iu', $all) === 1;
        $isCredit = !$isDebit && preg_match('/\b(credited|deposited|deposit|credit\s+alert|received)\b|क्रेडिट|जम्मा/iu', $all) === 1;

        $amount = self::amountIn($all, (string) ($opts['amount_regex'] ?? ''));
        $txn    = self::txnIn($all, (string) ($opts['txn_regex'] ?? ''));
        $remarks = '';
        if (preg_match('/(?:remarks?|narration|description|purpose|particulars|message)\s*[:\-]\s*([^\n]{1,200}?)(?=\s+(?:txn|transaction|trans\.|ref(?:erence)?\b|balance|avail|date|amount|thank)|\n|$)/iu', $all, $rm) === 1) {
            $remarks = trim($rm[1], " \t.,;");
        }
        $pnrs = PayCredits::pnrsIn($remarks);
        if ($pnrs === []) {
            $pnrs = PayCredits::pnrsIn($all);
        }
        $why = '';
        if ($isDebit) {
            $why = 'debit alert';
        } elseif (!$isCredit) {
            $why = 'not a credit alert';
        }
        return [
            'from'         => $from,
            'message_id'   => mb_substr($msgId, 0, 255),
            'date'         => $ts !== false ? date('Y-m-d H:i:s', $ts) : '',
            'subject'      => mb_substr($subject, 0, 200),
            'dkim_ok'      => $dkimOk,
            'dkim_domains' => $dkimDomains,
            'text'         => $text,
            'is_credit'    => $isCredit,
            'is_debit'     => $isDebit,
            'amount'       => $amount,
            'currency'     => 'NPR',
            'txn_id'       => $txn,
            'remarks'      => mb_substr($remarks !== '' ? $remarks : $subject, 0, 255),
            'pnrs'         => $pnrs,
            'pnr'          => count($pnrs) === 1 ? $pnrs[0] : '',
            'why'          => $why,
        ];
    }

    /** @return array{0:list<array{0:string,1:string}>, 1:string} headers (lower-case name, unfolded value) in order + body */
    public static function splitMessage(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + 2);
        $head = (string) preg_replace("/\n[ \t]+/", ' ', $head);
        $out = [];
        foreach (explode("\n", $head) as $line) {
            $c = strpos($line, ':');
            if ($c === false || $c === 0) {
                continue;
            }
            $out[] = [strtolower(trim(substr($line, 0, $c))), trim(substr($line, $c + 1))];
        }
        return [$out, $body];
    }

    public static function decodeHeader(string $v): string
    {
        if (!str_contains($v, '=?')) {
            return trim($v);
        }
        if (function_exists('iconv_mime_decode')) {
            $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($d) && $d !== '') {
                return trim($d);
            }
        }
        return trim(mb_decode_mimeheader($v));
    }

    public static function address(string $from): string
    {
        if (preg_match('/<\s*([^<>\s]+@[^<>\s]+)\s*>/', $from, $m) === 1) {
            return strtolower($m[1]);
        }
        if (preg_match('/([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+)/', $from, $m) === 1) {
            return strtolower($m[1]);
        }
        return '';
    }

    /**
     * The readable text of a (multipart) message: text/plain preferred, else the
     * HTML part made into text.
     *
     * @param list<array{0:string,1:string}> $headers
     */
    public static function bodyText(array $headers, string $body, int $depth = 0): string
    {
        $get = static function (string $n) use ($headers): string {
            foreach ($headers as [$k, $v]) {
                if ($k === $n) {
                    return $v;
                }
            }
            return '';
        };
        $ctype = strtolower($get('content-type'));
        if ($ctype === '') {
            $ctype = 'text/plain';
        }
        if ($depth < 6 && str_starts_with($ctype, 'multipart/') && preg_match('/boundary\s*=\s*"?([^";]+)"?/i', $get('content-type'), $bm) === 1) {
            $parts = explode('--' . trim($bm[1]), $body);
            $plain = '';
            $html  = '';
            foreach (array_slice($parts, 1) as $p) {
                if (str_starts_with($p, '--')) {
                    break;   // closing boundary
                }
                [$ph, $pb] = self::splitMessage(ltrim($p, "\r\n"));
                $pct = '';
                foreach ($ph as [$k, $v]) {
                    if ($k === 'content-type') {
                        $pct = strtolower($v);
                    }
                }
                if (str_starts_with($pct, 'multipart/')) {
                    $sub = self::bodyText($ph, $pb, $depth + 1);
                    if ($plain === '') {
                        $plain = $sub;
                    }
                } elseif ($pct === '' || str_starts_with($pct, 'text/plain')) {
                    if ($plain === '') {
                        $plain = self::decodePart($ph, $pb, false);
                    }
                } elseif (str_starts_with($pct, 'text/html')) {
                    if ($html === '') {
                        $html = self::decodePart($ph, $pb, true);
                    }
                }
            }
            return trim($plain !== '' ? $plain : $html);
        }
        return trim(self::decodePart($headers, $body, str_starts_with($ctype, 'text/html')));
    }

    /** @param list<array{0:string,1:string}> $headers */
    private static function decodePart(array $headers, string $body, bool $isHtml): string
    {
        $enc = '';
        $ct  = '';
        foreach ($headers as [$k, $v]) {
            if ($k === 'content-transfer-encoding') {
                $enc = strtolower(trim($v));
            } elseif ($k === 'content-type') {
                $ct = $v;
            }
        }
        if ($enc === 'base64') {
            $body = (string) base64_decode((string) preg_replace('/\s+/', '', $body), false);
        } elseif ($enc === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        }
        $cs = preg_match('/charset\s*=\s*"?([A-Za-z0-9_\-]+)"?/i', $ct, $m) === 1 ? strtoupper($m[1]) : 'UTF-8';
        if ($cs !== 'UTF-8' && $cs !== 'US-ASCII') {
            $conv = @mb_convert_encoding($body, 'UTF-8', $cs);
            if (is_string($conv)) {
                $body = $conv;
            }
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
        }
        return $isHtml ? self::htmlToText($body) : $body;
    }

    public static function htmlToText(string $html): string
    {
        $t = (string) preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', ' ', $html);
        $t = (string) preg_replace('#<br\s*/?>|</(p|div|tr|li|h[1-6]|table)>#i', "\n", $t);
        $t = (string) preg_replace('#</t[dh]>#i', ' ', $t);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\u{00A0}", ' ', $t);
        $t = (string) preg_replace('/[ \t]+/', ' ', $t);
        $t = (string) preg_replace("/\n\s*\n+/", "\n", $t);
        return trim($t);
    }

    /** Devanagari digits -> ASCII (रु २,४०० -> रु 2,400). */
    public static function normDigits(string $s): string
    {
        return strtr($s, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
    }

    private static function num(string $s): float
    {
        return round((float) str_replace(',', '', $s), 2);
    }

    /** The credited amount (0 = none found). */
    public static function amountIn(string $t, string $override = ''): float
    {
        if (trim($override) !== '') {
            $r = @preg_match($override, $t, $m);
            if ($r === 1 && isset($m[1]) && preg_match('/^[0-9][0-9,]*(\.[0-9]{1,2})?$/', trim($m[1])) === 1) {
                return self::num(trim($m[1]));
            }
            if ($r !== false) {
                return 0.0;   // the owner's rule decides; no guessing past it
            }
        }
        $cur = '(?:NPR|NRs\.?|Rs\.?|रु\.?|रू\.?)';
        $n   = '([0-9][0-9,]*(?:\.[0-9]{1,2})?)';
        foreach ([
            '/(?:credited|deposited)\s+(?:with|by|for|of)?\s*(?:an?\s+amount\s+of\s+)?' . $cur . '\s*' . $n . '/iu',
            '/' . $cur . '\s*' . $n . '\s*(?:has\s+been|is|was|have\s+been)?\s*(?:credited|deposited)/iu',
            '/(?:credit(?:ed)?\s+)?(?:amount|amt)\s*(?:of)?\s*[:\-]?\s*' . $cur . '?\s*' . $n . '/iu',
        ] as $re) {
            if (preg_match($re, $t, $m) === 1 && self::num($m[1]) > 0) {
                return self::num($m[1]);
            }
        }
        if (preg_match_all('/' . $cur . '\s*' . $n . '/iu', $t, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($mm as $x) {
                $before = strtolower(substr($t, max(0, $x[0][1] - 30), min(30, $x[0][1])));
                if (!str_contains($before, 'bal') && self::num($x[1][0]) > 0) {
                    return self::num($x[1][0]);
                }
            }
        }
        return 0.0;
    }

    /** The bank's transaction / reference id ('' = none). Never a PNR. */
    public static function txnIn(string $t, string $override = ''): string
    {
        $ok = static fn(string $v): bool => preg_match('/\d/', $v) === 1 && stripos($v, 'SHG') !== 0 && strlen($v) >= 6;
        if (trim($override) !== '') {
            $r = @preg_match($override, $t, $m);
            if ($r === 1 && isset($m[1]) && $ok(trim($m[1]))) {
                return mb_substr(trim($m[1]), 0, 80);
            }
            if ($r !== false) {
                return '';
            }
        }
        if (preg_match_all('/(?<![a-z])(?:txn|transaction|trans\.|ref(?:erence)?|trace|utr|rrn)(?![a-z])\s*(?:id|no\.?|number|num|#)?\s*[:#\-]?\s*([A-Z0-9][A-Z0-9\-\/]{5,40})/iu', $t, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $x) {
                $v = rtrim($x[1], '-/');
                if ($ok($v)) {
                    return mb_substr($v, 0, 80);
                }
            }
        }
        return '';
    }

    /* =================================================================
     *  the poll (cron/bank-mail.php) and the Test button
     * ================================================================= */

    /** IMAP SEARCH key for the allowed senders: FROM "a" / OR FROM "a" FROM "b" / … */
    public static function fromCriteria(array $from): string
    {
        $keys = [];
        foreach ($from as $f) {
            $f = ltrim(trim((string) $f), '@');
            if ($f !== '' && preg_match('/^[A-Za-z0-9._%+\-@]+$/', $f) === 1) {
                $keys[] = 'FROM ' . BankMailImap::quote($f);
            }
        }
        if ($keys === []) {
            return '';
        }
        $c = array_pop($keys);
        while ($keys !== []) {
            $c = 'OR ' . array_pop($keys) . ' ' . $c;
        }
        return $c;
    }

    /**
     * Read new alerts. $imap is for tests (an already-connected client).
     *
     * @return array<string,mixed> counts + ok / msg
     */
    public static function poll(int $max = 50, ?BankMailImap $imap = null): array
    {
        $res = ['ok' => true, 'msg' => '', 'seen' => 0, 'credits' => 0, 'new' => 0, 'duplicates' => 0, 'debits' => 0,
                'other' => 0, 'confirmed' => 0, 'unmatched' => 0, 'last_uid' => Settings::getInt('bankmail_last_uid', 0)];
        if (!self::on()) {
            return ['ok' => true, 'msg' => 'off'] + $res;
        }
        if (!PayCredits::ready()) {
            return self::finish(['ok' => false, 'msg' => 'bank_credits table missing (migration not applied)'] + $res);
        }
        $cfg = self::config();
        $criteriaFrom = self::fromCriteria($cfg['from']);
        if ($criteriaFrom === '') {
            return self::finish(['ok' => false, 'msg' => 'No allowed sender (bankmail_from) — nothing is read'] + $res);
        }
        $mine = $imap === null;
        try {
            if ($imap === null) {
                if ($cfg['user'] === '' || $cfg['pass'] === '') {
                    return self::finish(['ok' => false, 'msg' => 'Gmail address / app password not saved'] + $res);
                }
                $imap = BankMailImap::connect($cfg['host']);
                $imap->login($cfg['user'], $cfg['pass']);
            }
            $box = $imap->examine('INBOX');
            $last = Settings::getInt('bankmail_last_uid', 0);
            $was  = Settings::getString('bankmail_uidvalidity', '');
            if ($box['uidvalidity'] !== '' && $was !== $box['uidvalidity']) {
                if ($was !== '') {
                    $last = 0;   // the box was rebuilt: UIDs mean something else now (Message-ID still dedupes)
                }
                Settings::set('bankmail_uidvalidity', $box['uidvalidity'], 'string', 'payments');
            }
            $crit = ($last > 0 ? 'UID ' . ($last + 1) . ':*' : 'SINCE ' . date('j-M-Y', time() - 2 * 86400)) . ' ' . $criteriaFrom;
            $uids = array_values(array_filter($imap->uidSearch($crit), static fn(int $u): bool => $u > $last));
            $uids = array_slice($uids, 0, max(1, min(200, $max)));
            foreach ($uids as $uid) {
                $raw = $imap->uidFetch($uid);
                $res['seen']++;
                if ($raw !== '') {
                    self::handle($raw, $res);
                }
                $last = max($last, $uid);
                Settings::set('bankmail_last_uid', (string) $last, 'int', 'payments');
            }
            $res['last_uid'] = $last;
            $res['msg'] = $res['seen'] . ' read · ' . $res['credits'] . ' credits · ' . $res['confirmed'] . ' confirmed · ' . $res['unmatched'] . ' need a person';
        } catch (Throwable $e) {
            $res['ok']  = false;
            $res['msg'] = self::scrub($e->getMessage(), $cfg['pass']);
        } finally {
            if ($mine && $imap !== null) {
                $imap->logout();
            }
        }
        return self::finish($res);
    }

    /** @param array<string,mixed> $res */
    private static function handle(string $raw, array &$res): void
    {
        $p = self::parse($raw);
        if ($p['is_debit']) {
            $res['debits']++;
            return;
        }
        if (!$p['is_credit']) {
            $res['other']++;
            return;
        }
        $res['credits']++;
        $id = PayCredits::record([
            'source' => 'gmail', 'currency' => 'NPR', 'amount' => $p['amount'], 'txn_id' => $p['txn_id'],
            'ref_text' => $p['remarks'], 'pnr' => $p['pnr'], 'sender' => $p['from'], 'dkim_ok' => $p['dkim_ok'],
            'message_id' => $p['message_id'], 'received_at' => $p['date'], 'raw_excerpt' => $p['subject'] . "\n" . mb_substr($p['text'], 0, 1500),
        ]);
        $row = PayCredits::get($id);
        if ($row === null || (string) $row['state'] !== 'new') {
            $res['duplicates']++;
            return;
        }
        $res['new']++;
        $r = PayCredits::tryAutoConfirm($id);
        if ($r['ok']) {
            $res['confirmed']++;
        } elseif ($r['state'] === 'unmatched') {
            $res['unmatched']++;
        }
    }

    /** @param array<string,mixed> $res */
    private static function finish(array $res): array
    {
        try {
            Settings::set('bankmail_last_poll', ['at' => date('Y-m-d H:i:s'), 'ok' => (bool) $res['ok'], 'msg' => mb_substr((string) $res['msg'], 0, 200)], 'json', 'payments');
            Settings::flush();
        } catch (Throwable $e) {
            // the cron log still has it
        }
        return $res;
    }

    private static function scrub(string $msg, string $secret): string
    {
        return mb_substr($secret !== '' ? str_replace($secret, '***', $msg) : $msg, 0, 200);
    }

    /**
     * The "Test connection" button: LOGIN + open the inbox read-only.
     *
     * @return array{ok:bool, msg:string}
     */
    public static function testConnection(): array
    {
        $cfg = self::config();
        if ($cfg['user'] === '' || $cfg['pass'] === '') {
            return ['ok' => false, 'msg' => 'Gmail address and app password are needed first.'];
        }
        $imap = null;
        try {
            $imap = BankMailImap::connect($cfg['host']);
            $imap->login($cfg['user'], $cfg['pass']);
            $box = $imap->examine('INBOX');
            $warn = [];
            if ($cfg['from'] === []) {
                $warn[] = 'no allowed sender yet';
            }
            if ($cfg['dkim'] === '') {
                $warn[] = 'no DKIM domain yet (nothing can auto-confirm)';
            }
            return ['ok' => true, 'msg' => 'Logged in to ' . $cfg['host'] . ' · INBOX has ' . $box['exists'] . ' messages' . ($warn !== [] ? ' · ' . implode(', ', $warn) : '')];
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => self::scrub($e->getMessage(), $cfg['pass'])];
        } finally {
            if ($imap !== null) {
                $imap->logout();
            }
        }
    }
}
