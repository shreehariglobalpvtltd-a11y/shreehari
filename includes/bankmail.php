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
 *  Authentication-Results (added by Gmail itself), read unit by unit with
 *  comments / quoted strings cut out: a "dkim=pass" unit with header.d (or
 *  header.i) = bankmail_dkim_domain, any dmarc unit = pass for it, and the
 *  From: domain aligned with the signer; the text (quoted-printable / base64,
 *  HTML made text), then a credit-alert reader that REFUSES AMBIGUITY - we do
 *  not know the bank's exact wording, and the remarks the payer types can sit
 *  anywhere in it (next line, next table row, any label), so nothing tries to
 *  find "the bank's" figure. Every money figure in the WHOLE alert (subject +
 *  body, remarks included: NPR / NRs / Rs / रु / रू / INR / ₹ on either side,
 *  an Amount / Amt / रकम label, "credited with 10.00", a bare 10.00; Devanagari
 *  digits) is collected; only a line that BEGINS with a balance label is left
 *  out. Exactly ONE distinct value = the amount, anything else = 0 (a person
 *  checks). The same for the transaction / reference id and the PNR: exactly
 *  one distinct one, else none. An alert with both credit and debit words is
 *  kept for a person (never dropped, never auto); a debit alone is ignored.
 *  bankmail_amount_regex must AGREE with that one figure; bankmail_txn_regex
 *  adds candidates; bankmail_account_regex reads the credited account (masked
 *  to its last digits, from bankText(): never from the remarks).
 *
 *  The Authentication-Results reader FAILS CLOSED: a topmost header with a double
 *  quote, a backslash, nested or unbalanced parentheses, or any non-ASCII /
 *  control character is not read at all (dkim_ok false, dkim_why 'auth header
 *  not simple') - Gmail's own header never needs any of them.
 *
 *  Lines (for the balance-label rule) are split ONLY on \n, after \r\n / \r
 *  became \n and U+2028 / U+2029 / U+0085 / VT / FF became a space, so a
 *  separator the payer types can never start a fake "Bal" line. NBSP and every
 *  other Unicode space become a plain space (text/plain and HTML alike).
 *
 *  A credit goes to PayCredits::record() + tryAutoConfirm() - which, not
 *  this file, decides whether money may confirm a ticket (it needs the
 *  credited account to end with bankmail_account_hint, an amount and a txn
 *  id) - or, when the alert is ambiguous, to PayCredits::hold(). While
 *  pay_bankmail_autoconfirm_on is off (the DEFAULT, owner-safety 25 Nov 2026:
 *  the real Global IME template is not locked yet) a credit that passes every
 *  check is only a SUGGESTION ('matched', booking still pending) that a person
 *  confirms on Payment Management after checking the bank app.
 *
 *  One odd message never stops the reader: each message is handled on its
 *  own; one that fails is logged, raises an admin bell and is stepped over
 *  (bankmail_last_uid always moves on). An unknown charset, an absurd
 *  amount or raw 8-bit header bytes are made harmless before the insert.
 *
 *  Settings: bankmail_on (off), bankmail_host (imap.gmail.com), bankmail_user,
 *  bankmail_app_password (secret), bankmail_from (allowed senders: addresses
 *  or @domains, comma list), bankmail_dkim_domain, bankmail_account_hint
 *  (last 4-6 digits of our Global IME account), bankmail_authserv
 *  (mx.google.com), pay_bankmail_autoconfirm_on (OFF = suggestions only), bankmail_last_uid,
 *  bankmail_uidvalidity, bankmail_last_poll (JSON, for the admin page).
 *
 *  MANY BOXES (25 Nov 2026, mp2 "bank Gmail"): the owner adds each bank Gmail box on
 *  Payment Management (BankMailAccounts, table bank_mail_accounts): "Google बाट Login"
 *  (GoogleOAuth + GmailApi, read-only Gmail API) or an app password (the IMAP reader
 *  below, imap.gmail.com). poll() loops the active boxes; each box's OWN rules (country
 *  -> currency NP=NPR / IN=INR, senders, DKIM domain, our account digits) are used for
 *  its mail - parse() / handle() / PayCredits never read the bankmail_* settings for a
 *  box. Every mail a box looks at is written to bank_mail_log (its "updates"). A box
 *  that is not READY (no DKIM domain or no account digits) logs what arrives and stores
 *  no credit. The bankmail_* settings above are the OLD single box: once the mp2
 *  migration ran they become account #1 (BankMailAccounts::migrateLegacy) and are read
 *  only through it; poll() with an injected IMAP client (tests) or before the migration
 *  still reads them directly (pollLegacy).
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/paycredits.php';
require_once __DIR__ . '/bankmailaccounts.php';
require_once __DIR__ . '/googleoauth.php';

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
     * A box's own parse rules (BankMailAccounts row): its DKIM domain and currency, Gmail's
     * own authserv-id, no global regex overrides - never the bankmail_* settings.
     *
     * @param array<string,mixed> $acct
     * @return array<string,string>
     */
    public static function optsFor(array $acct): array
    {
        return [
            'dkim_domain'   => BankMailAccounts::dkimDomain($acct),
            'authserv'      => 'mx.google.com',
            'amount_regex'  => '',
            'txn_regex'     => '',
            'account_regex' => '',
            'currency'      => (string) ($acct['currency'] ?? '') === 'INR' ? 'INR' : 'NPR',
        ];
    }

    /**
     * @param array<string,string>|null $opts dkim_domain, authserv, amount_regex, txn_regex, account_regex,
     *                                        currency (NPR default; INR for an India box) (null = from Settings)
     * @return array<string,mixed> from, message_id, date, subject, dkim_ok, dkim_domains, dkim_why, text,
     *         is_credit, is_debit, mixed, amount, amounts, currency, txn_id, txns, remarks, pnrs, pnr,
     *         account, hold ('' or why a person must check it), why
     */
    public static function parse(string $raw, ?array $opts = null): array
    {
        if ($opts === null) {
            $opts = [
                'dkim_domain'   => strtolower(trim(Settings::getString('bankmail_dkim_domain', ''))),
                'authserv'      => strtolower(trim(Settings::getString('bankmail_authserv', 'mx.google.com'))),
                'amount_regex'  => Settings::getString('bankmail_amount_regex', ''),
                'txn_regex'     => Settings::getString('bankmail_txn_regex', ''),
                'account_regex' => Settings::getString('bankmail_account_regex', ''),
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
        $msgId = mb_scrub(trim($h1('message-id')), 'UTF-8');
        if (preg_match('/<([^>]{1,250})>/', $msgId, $m) === 1) {
            $msgId = $m[1];
        }
        $dateRaw = trim($h1('date'));
        $ts = $dateRaw !== '' ? strtotime($dateRaw) : false;
        if ($ts !== false && ($ts < 946684800 || $ts > time() + 7 * 86400)) {
            $ts = false;   // year 99999 and friends
        }
        $subject = mb_scrub(self::decodeHeader($h1('subject')), 'UTF-8');   // raw 8-bit header bytes

        /* DKIM: ONLY the topmost Authentication-Results, and only when it was written by
           the receiving server (authserv-id) - a header the sender typed lower down counts for nothing.
           It is read as resinfo units (authResults): an spf comment or an smtp.mailfrom the sender
           chose ("dkim=pass+header.d=bank@evil") is never a DKIM result. A dmarc unit in the same
           header must pass for the bank domain, and the From: address must sit with the signer. */
        $want = strtolower(trim((string) ($opts['dkim_domain'] ?? '')));
        $authRes = self::authResults($h1('authentication-results'), (string) ($opts['authserv'] ?? ''));
        $dkimDomains = $authRes['dkim'];
        $dkimOk = $want !== '' && self::dkimAligned($authRes, $from, $want);

        $text = self::bodyText($headers, $body);
        $all  = self::normDigits(trim(self::normSpaces($subject . "\n" . $text)));

        /* AMBIGUITY-REFUSING: we do not know the bank's exact wording, and the remarks the PAYER
           types can sit anywhere (after "Remarks:", on the next line, in another table row, after
           any label), so nothing here tries to find "the bank's" figure. Every money figure and
           every txn / reference id in the WHOLE alert is collected; only exactly ONE distinct value
           is used. A second one - whoever wrote it - means a person checks. The payer can only ADD
           text, and added text can only make the alert ambiguous, never steer what is read. */
        $scan = self::scanText($all);   // balance lines blanked
        $creditW = preg_match(self::CREDIT_WORDS, $scan) === 1;
        $debitW  = preg_match(self::DEBIT_WORDS, $scan) === 1;
        $mixed    = $creditW && $debitW;   // kept for a person: never dropped, never auto
        $isDebit  = $debitW && !$creditW;
        $isCredit = $creditW;

        $amountRe = (string) ($opts['amount_regex'] ?? '');
        $amounts  = self::figures($all);
        $amount   = self::amountIn($all, $amountRe);
        $txns     = self::txnCandidates($all, (string) ($opts['txn_regex'] ?? ''));
        $txn      = count($txns) === 1 ? $txns[0] : '';
        $remarks = '';
        if (preg_match('/(?:remarks?|narration|description|purpose|particulars|message)\s*[:\-]\s*([^\n]{1,200}?)(?=\s+(?:txn|transaction|trans\.|ref(?:erence)?\b|balance|avail|date|amount|thank)|\n|$)/iu', $all, $rm) === 1) {
            $remarks = trim($rm[1], " \t.,;");
        }
        $ref = $remarks !== '' ? $remarks : $subject;
        /* the PNR: exactly one distinct one in the whole alert (SHG-2026-0842 = SHG-2026-00842) */
        $pnrs = [];
        foreach (PayCredits::pnrsIn($all) as $x) {
            $pnrs[PayCredits::pnrCandidates($x)[0] ?? $x] ??= $x;
        }
        $pnrs = array_values($pnrs);
        if (count($pnrs) > 1) {
            /* the ledger reads the PNR from ref_text too: it must see them all, never just one */
            $ref = 'PNRs ' . implode(', ', array_slice($pnrs, 0, 5)) . ' · ' . $ref;
        }
        $account = self::accountIn($all, (string) ($opts['account_regex'] ?? ''));

        $cur  = (string) ($opts['currency'] ?? 'NPR') === 'INR' ? 'INR' : 'NPR';
        $hold = '';
        $one  = count($amounts) === 1 ? $amounts[0] : 0.0;
        if ($mixed) {
            $hold = 'The alert has both credit and debit words — check the bank statement';
        } elseif (count($amounts) > 1) {
            $hold = 'Different amounts in one alert (' . $cur . ' ' . implode(', ' . $cur . ' ', array_map(static fn(float $v): string => number_format($v, 2), array_slice($amounts, 0, 4)))
                . ') — the payer may have typed one; check the bank statement';
        } elseif ($amount <= 0 && $one > 0 && $one < PayCredits::MAX_AMOUNT && trim($amountRe) !== '') {
            $hold = 'bankmail_amount_regex does not agree with the amount in the alert (' . $cur . ' ' . number_format($one, 2) . ') — check the bank statement';
        } elseif (count($txns) > 1) {
            $hold = 'Different transaction / reference ids in one alert (' . implode(', ', array_slice($txns, 0, 4)) . ') — check the bank statement';
        }
        $why = '';
        if ($isDebit) {
            $why = 'debit alert';
        } elseif ($mixed) {
            $why = 'credit and debit words';
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
            'dkim_why'     => (string) ($authRes['note'] ?? ''),
            'text'         => $text,
            'is_credit'    => $isCredit,
            'is_debit'     => $isDebit,
            'mixed'        => $mixed,
            'amount'       => $amount,
            'amounts'      => $amounts,
            'currency'     => $cur,
            'txn_id'       => $txn,
            'txns'         => $txns,
            'remarks'      => mb_substr($ref, 0, 255),
            'pnrs'         => $pnrs,
            'pnr'          => count($pnrs) === 1 ? $pnrs[0] : '',
            'account'      => $account,
            'hold'         => $hold,
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
     * One Authentication-Results value (the TOPMOST one) read as RFC 8601 resinfo units.
     * Comments "( … )" (nested) and quoted strings are cut out first, then the value is split
     * on ';'. The first unit must be the receiving server's authserv-id (optionally a version
     * number after it). A unit counts as DKIM only when it BEGINS with method "dkim" and result
     * "pass"; its domain comes from that unit's own header.d (else the domain after '@' in its
     * header.i), and a header.i outside its header.d, or a property written twice, drops the unit.
     *
     * FAIL-CLOSED first (authSimple): a value with a double quote, a backslash, nested or
     * unbalanced parentheses, or any non-ASCII / control character is not read at all -
     * serv_ok false, note 'auth header not simple' - so no quoting / comment trick (a ')'
     * inside a quoted local part, an unclosed '(' hiding dmarc=fail) can ever be parsed
     * into a pass. Gmail writes none of these in its own header.
     *
     * @return array{serv_ok:bool, dkim:list<string>, dmarc:list<array{result:string, from:string}>, note:string}
     *         dkim = the signing domain of every dkim=pass unit; dmarc = every dmarc unit, whatever it says;
     *         note = why the header was not read ('' = it was)
     */
    public static function authResults(string $value, string $authserv = ''): array
    {
        $out = ['serv_ok' => false, 'dkim' => [], 'dmarc' => [], 'note' => ''];
        if (trim($value) === '') {
            return $out;
        }
        if (!self::authSimple($value)) {
            $out['note'] = 'auth header not simple';
            return $out;
        }
        $units = array_map('trim', explode(';', self::stripCfws($value)));
        $authserv = strtolower(trim($authserv));
        $servId = strtolower((string) preg_replace('/\s+[0-9]+$/', '', $units[0]));   // authserv-id [version]
        if ($authserv !== '' && $servId !== $authserv) {
            return $out;
        }
        $out['serv_ok'] = true;
        foreach (array_slice($units, 1) as $u) {
            $isDmarc = preg_match('/^dmarc(?![A-Za-z0-9_\-])/i', $u) === 1;
            if (preg_match('/^([A-Za-z0-9_\-]+)\s*(?:\/\s*[0-9]+\s*)?=\s*([A-Za-z0-9_\-]+)(?=\s|$)(.*)$/s', $u, $um) !== 1) {
                if ($isDmarc) {
                    $out['dmarc'][] = ['result' => 'unreadable', 'from' => ''];
                }
                continue;
            }
            $method = strtolower($um[1]);
            $result = strtolower($um[2]);
            $props  = self::authProps($um[3]);
            if ($method === 'dmarc') {
                $out['dmarc'][] = $props === null ? ['result' => 'unreadable', 'from' => '']
                    : ['result' => $result, 'from' => self::cleanDomain($props['header.from'] ?? '')];
                continue;
            }
            if ($method !== 'dkim' || $result !== 'pass' || $props === null) {
                continue;
            }
            $d = '';
            if (array_key_exists('header.d', $props)) {
                $d = self::cleanDomain($props['header.d']);
                if ($d === '') {
                    continue;   // a header.d that is not a plain domain: not trusted
                }
            }
            $i = '';
            if (array_key_exists('header.i', $props)) {
                $iv = $props['header.i'];
                $at = strrpos($iv, '@');
                $i = self::cleanDomain($at === false ? $iv : substr($iv, $at + 1));
                if ($i === '' || ($d !== '' && !self::under($i, $d))) {
                    continue;   // RFC 6376: the i= domain is the d= domain or below it
                }
            }
            $dom = $d !== '' ? $d : $i;
            if ($dom !== '') {
                $out['dkim'][] = $dom;
            }
        }
        $out['dkim'] = array_values(array_unique($out['dkim']));
        return $out;
    }

    /**
     * Did the bank's own domain sign this message, for this From:?
     *   - a dkim=pass unit whose domain is $want or below it;
     *   - every dmarc unit in the same header says pass with header.from = the From: domain,
     *     $want or below it (no dmarc unit at all: the dkim unit decides);
     *   - the From: domain is $want or below it, and it and the signing domain are the same
     *     or one is a subdomain of the other (alerts.bank.np signed by bank.np, or the reverse).
     *
     * @param array{serv_ok:bool, dkim:list<string>, dmarc:list<array{result:string, from:string}>} $auth
     */
    public static function dkimAligned(array $auth, string $from, string $want): bool
    {
        $want = strtolower(trim($want));
        if ($want === '' || empty($auth['serv_ok'])) {
            return false;
        }
        $signed = array_values(array_filter($auth['dkim'], static fn(string $d): bool => self::under($d, $want)));
        if ($signed === []) {
            return false;
        }
        $at = strrpos($from, '@');
        $fromDom = $at === false ? '' : self::cleanDomain(substr($from, $at + 1));
        if ($fromDom === '' || !self::under($fromDom, $want)) {
            return false;
        }
        foreach ($auth['dmarc'] as $dm) {
            if ($dm['result'] !== 'pass' || $dm['from'] === '' || !self::under($dm['from'], $want) || $dm['from'] !== $fromDom) {
                return false;
            }
        }
        foreach ($signed as $d) {
            if (self::under($fromDom, $d) || self::under($d, $fromDom)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The Authentication-Results value is plain enough to read: printable ASCII only (no
     * control character, not even a tab - folding is already a space), no double quote,
     * no backslash, and parentheses that open and close one level deep, never nested,
     * never a ')' before its '(' and none left open.
     */
    public static function authSimple(string $v): bool
    {
        if ($v === '' || preg_match('/[^\x20-\x7E]/', $v) === 1 || strpbrk($v, "\"\\") !== false) {
            return false;
        }
        $depth = 0;
        $n = strlen($v);
        for ($k = 0; $k < $n; $k++) {
            if ($v[$k] === '(') {
                if (++$depth > 1) {
                    return false;   // nested
                }
            } elseif ($v[$k] === ')') {
                if (--$depth < 0) {
                    return false;   // a ')' with no '('
                }
            }
        }
        return $depth === 0;   // an unclosed '('
    }

    /**
     * An Authentication-Results value without its comments and quoted strings: a comment
     * (nesting, with \-escapes) becomes a space, a quoted string becomes "" - so nothing inside
     * either (a ';', a "dkim=pass") can ever look like a unit or a property. An unclosed
     * comment or quote drops the rest of the value.
     */
    public static function stripCfws(string $v): string
    {
        $out = '';
        $depth = 0;
        $inQuote = false;
        $n = strlen($v);
        for ($k = 0; $k < $n; $k++) {
            $c = $v[$k];
            if ($inQuote) {
                if ($c === '\\') {
                    $k++;
                } elseif ($c === '"') {
                    $inQuote = false;
                    $out .= '""';
                }
                continue;
            }
            if ($depth > 0) {
                if ($c === '\\') {
                    $k++;
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $out .= ' ';
                    }
                }
                continue;
            }
            if ($c === '"') {
                $inQuote = true;
            } elseif ($c === '(') {
                $depth = 1;
            } elseif ($c === ')') {
                $out .= ' ';   // a stray ')'
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    /**
     * The ptype.property=value pairs of one resinfo unit (after its method=result), keys lower-case.
     * null = a property written twice (the unit is not trusted).
     *
     * @return array<string,string>|null
     */
    private static function authProps(string $rest): ?array
    {
        $props = [];
        if (preg_match_all('/(?:^|\s)([A-Za-z0-9_\-]+)\s*\.\s*([A-Za-z0-9_\-]+)\s*=\s*(\S*)/', $rest, $pm, PREG_SET_ORDER) > 0) {
            foreach ($pm as $x) {
                $k = strtolower($x[1] . '.' . $x[2]);
                if (array_key_exists($k, $props)) {
                    return null;
                }
                $props[$k] = $x[3];
            }
        }
        return $props;
    }

    /** A plain host name, lower-case ('' = not one). */
    private static function cleanDomain(string $s): string
    {
        $s = strtolower(rtrim(trim($s), '.'));
        return preg_match('/^(?=.{3,253}$)[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?)+$/', $s) === 1 ? $s : '';
    }

    /** $host is $parent or a subdomain of it. */
    private static function under(string $host, string $parent): bool
    {
        return $host !== '' && $parent !== '' && ($host === $parent || str_ends_with($host, '.' . $parent));
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
        if ($cs !== 'UTF-8' && $cs !== 'US-ASCII' && $cs !== 'ASCII') {
            $body = self::toUtf8($body, $cs);
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
        }
        /* NBSP and the other Unicode spaces are a plain space in text/plain too ("NPR\u{00A0}10"
           must read as NPR 10, or the bank's own figure would be invisible beside the payer's) */
        return $isHtml ? self::htmlToText($body) : self::normSpaces($body);
    }

    /**
     * Every Unicode space (NBSP U+00A0, U+2000-U+200A, U+202F, U+205F, U+3000, U+1680 - \p{Zs})
     * and the line / paragraph separators U+2028 / U+2029, NEL U+0085, VT and FF become a plain
     * space. \n and \r are kept (scanText makes \r\n / \r one \n). Invalid UTF-8 is scrubbed first,
     * so the /u pattern can never fail and drop the text.
     */
    public static function normSpaces(string $s): string
    {
        $s = mb_scrub($s, 'UTF-8');
        $r = preg_replace('/[\p{Zs}\x{2028}\x{2029}\x{0085}\x{000B}\x{000C}]/u', ' ', $s);
        return is_string($r) ? $r : $s;
    }

    /**
     * One part's text into UTF-8. An unknown charset ("x-unknown-cs") makes
     * mb_convert_encoding() throw a ValueError that @ does not stop - it would
     * have stopped the whole reader - so: mbstring, else iconv, else as it is
     * (the caller's UTF-8 / ISO-8859-1 check takes it from there).
     */
    public static function toUtf8(string $s, string $cs): string
    {
        $cs = strtoupper(trim($cs));
        if ($cs === '' || in_array($cs, ['BASE64', 'UUENCODE', 'HTML-ENTITIES', 'HTML', 'QUOTED-PRINTABLE', 'QPRINT', '7BIT', '8BIT', 'PASS', 'AUTO', 'WCHAR'], true)) {
            return $s;   // transfer encodings / pseudo-charsets, not character sets
        }
        try {
            $conv = @mb_convert_encoding($s, 'UTF-8', $cs);
            if (is_string($conv)) {
                return $conv;
            }
        } catch (Throwable $e) {
            // mbstring does not know it
        }
        try {
            $conv = function_exists('iconv') ? @iconv($cs, 'UTF-8//IGNORE', $s) : false;
            if (is_string($conv)) {
                return $conv;
            }
        } catch (Throwable $e) {
            // nor iconv
        }
        return $s;
    }

    public static function htmlToText(string $html): string
    {
        /* a line break in the HTML SOURCE is only white space (as a browser shows it): lines come
           from <br> and the block ends alone, so a newline inside a cell can never start a line */
        $t = (string) preg_replace('/\r\n|\r|\n/', ' ', $html);
        $t = (string) preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', ' ', $t);
        /* tag breaks become a private-use marker FIRST; entities are decoded after, and any line
           separator an entity (&#10; &#13; &#8232;) carries becomes a space - only real tags make lines */
        $t = (string) preg_replace('#<br\s*/?>|</(p|div|tr|li|h[1-6]|table)>#i', "\u{E000}", $t);
        $t = (string) preg_replace('#</t[dh]>#i', ' ', $t);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace(["\r\n", "\r", "\n", "\u{2028}", "\u{2029}", "\u{0085}", "\v", "\f"], ' ', $t);
        $t = str_replace("\u{E000}", "\n", $t);
        $t = self::normSpaces($t);
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

    /**
     * The text with what the PAYER typed cut out, for the CREDITED ACCOUNT only
     * (accountIn): from a remarks / narration / narrative / purpose / particulars
     * label, a "description:" / "message:" label, or "description" / "message"
     * opening a line (an HTML table row), to the end of that line. The amount, the
     * transaction id and the PNR are NOT read this way any more (figures(),
     * txnCandidates(): the whole text, exactly one distinct value).
     */
    public static function bankText(string $t): string
    {
        return (string) preg_replace('/(?:\b(?:remarks?|narration|narrative|purpose|particulars)\b\s*[:\-]?|\b(?:description|message)\s*[:\-]|^[ \t]*(?:description|message)\b)[^\n]*/imu', ' ', $t);
    }

    /** A currency: NPR / NRs / Rs / INR as a whole word (Rs. too), रु / रू (रुपैयाँ too), ₹. */
    private const CUR = '(?:(?<![A-Za-z])(?:NPRs?|NRs|Rs|INR)(?![A-Za-z])|रु|रू|₹)';
    /** A figure right after a currency / label: digits, commas, at most two decimals. */
    private const NUM = '([0-9][0-9,]*(?:\.[0-9]{1,2})?)(?![0-9])';
    /** A figure standing on its own (currency AFTER it, a credit word before it): not the tail of a
        word, an id, a masked account or a date; at most 7 digits before a comma (a longer run of
        digits is an account / phone / txn number, not money). */
    private const NUM_ALONE = '(?<![0-9A-Za-z.,*#•\/\-])([0-9]{1,7}(?:,[0-9]{2,3})*(?:\.[0-9]{1,2})?)(?![0-9])';
    /** A line that BEGINS (trimmed) with a balance label - the only figures never counted. */
    private const BAL_LINE = '/^(?:(?:avl|avail|available|current|closing|ledger)[ \t]*\.?[ \t]*)?bal(?:ance)?(?![A-Za-z])|^(?:मौज्दात|ब्यालेन्स)/iu';
    private const CREDIT_WORDS = '/(?<![A-Za-z])(?:credited|deposited|deposit|credit[ \t]+alert|received)(?![A-Za-z])|क्रेडिट|जम्मा/iu';
    private const DEBIT_WORDS  = '/(?<![A-Za-z])(?:debited|withdrawn|withdrawal|debit[ \t]+alert)(?![A-Za-z])|डेबिट|झिकिएको/iu';

    /**
     * The text one trimmed line per line, every line that BEGINS with a balance
     * label (Balance, Bal, Avl Bal, Available Balance, Avail. Bal, मौज्दात,
     * ब्यालेन्स; after HTML-to-text) replaced by a lone \x1F, so nothing - no
     * figure, no credit / debit word - is read from it and no pattern runs across
     * it. A "bal" anywhere else in a line (a payer can type one) changes nothing.
     *
     * Lines are split ONLY on \n: \r\n and \r become \n first, and U+2028 / U+2029 /
     * U+0085 / VT / FF (and every Unicode space) become a plain space, so a separator the
     * payer types can never start a fake "Bal" line that blanks the bank's own figure.
     */
    public static function scanText(string $t): string
    {
        /* \r\n is a line end; a LONE \r is only a space (a payer-typed CR never starts a fake line) */
        $t = str_replace("\r", ' ', str_replace("\r\n", "\n", mb_scrub($t, 'UTF-8')));
        $t = self::normSpaces($t);
        $out = [];
        foreach (explode("\n", $t) as $line) {
            $line = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', (string) $line);
            if ($line !== '') {
                $out[] = preg_match(self::BAL_LINE, $line) === 1 ? "\x1F" : $line;
            }
        }
        return implode("\n", $out);
    }

    /**
     * EVERY distinct money figure in the text (subject + body, the remarks the payer
     * typed included), rounded to 2 dp, in the order first seen; 0 never counts:
     *   - a number next to a currency on either side (NPR 10.00, Rs.10, रु १०, 10.00 NPR, ₹10);
     *   - a number after an amount label, with or without currency (Amount: 10.00,
     *     Amount (NPR) 10, Amt 10, रकम: 10, the value on the next line too);
     *   - a number right after "credited" / "deposited" (with / by / for / of);
     *   - any bare 10.00 (two decimals: a table cell under a "Credit" column).
     * Only a line that BEGINS with a balance label is left out (scanText). More
     * figures can only make the alert ambiguous (amount 0) - never pick one.
     *
     * @return list<float>
     */
    public static function figures(string $t): array
    {
        $scan = self::scanText(self::normDigits($t));
        $c = self::CUR;
        $n = self::NUM;
        $a = self::NUM_ALONE;
        $out = [];
        foreach ([
            '/' . $c . '[ \t.:]{0,3}' . $n . '/iu',
            '/' . $a . '[ \t.:]{0,3}' . $c . '/iu',
            '/(?:(?<![A-Za-z])(?:amount|amt)(?![A-Za-z])\.?|रकम)[ \t]*(?:\([ \t]*' . $c . '[ \t.]*\)[ \t]*)?(?:(?:in|of)[ \t]+)?(?:' . $c . '[ \t.:]{0,3})?[:\-]?[ \t]*(?:\n[ \t]*)?(?:' . $c . '[ \t.:]{0,3})?' . $n . '/iu',
            '/(?<![A-Za-z])(?:credited|deposited)(?![A-Za-z])[ \t]+(?:(?:with|by|for|of)[ \t]+)?(?:(?:an?[ \t]+)?amount[ \t]+(?:of[ \t]+)?)?' . $a . '/iu',
            '/(?<![0-9A-Za-z.,*#•\/\-])([0-9]{1,7}(?:,[0-9]{2,3})*\.[0-9]{2})(?![0-9]|\.[0-9])/u',
        ] as $re) {
            if (preg_match_all($re, $scan, $mm, PREG_SET_ORDER) > 0) {
                foreach ($mm as $x) {
                    $v = self::num($x[1]);
                    if ($v > 0) {
                        $out[number_format($v, 2, '.', '')] ??= $v;
                    }
                }
            }
        }
        return array_values($out);
    }

    /**
     * The credited amount: the ONE distinct figure in the whole text (figures()),
     * 0 when there is none or more than one (a person checks), and 0 for a figure
     * that is not a positive amount below PayCredits::MAX_AMOUNT (NPR 1 crore), so
     * 'NPR 99999999999999' can never break the insert. bankmail_amount_regex, when
     * set, must AGREE: every group-1 match equal to that figure (no match, another
     * value or a broken pattern = 0) - it can confirm the figure, never choose one.
     */
    public static function amountIn(string $t, string $override = ''): float
    {
        $vals = self::figures($t);
        if (count($vals) !== 1) {
            return 0.0;
        }
        $v = $vals[0];
        if (!($v > 0 && $v < PayCredits::MAX_AMOUNT)) {
            return 0.0;
        }
        if (trim($override) !== '') {
            $r = @preg_match_all($override, self::normDigits($t), $mm, PREG_SET_ORDER);
            if ($r === false || $r === 0) {
                return 0.0;
            }
            foreach ($mm as $m) {
                $g = trim((string) ($m[1] ?? ''));
                if (preg_match('/^[0-9][0-9,]*(\.[0-9]{1,2})?$/', $g) !== 1 || abs(self::num($g) - $v) > 0.004) {
                    return 0.0;
                }
            }
        }
        return $v;
    }

    /**
     * EVERY distinct transaction / reference id in the whole text (the remarks
     * included): after a txn / transaction / trans. / ref / reference / trace / utr /
     * rrn / ट्रान्जेक्सन / कारोबार / सन्दर्भ label (id / no. / number after it), plus every
     * group-1 match of bankmail_txn_regex. A candidate needs a digit and 6+
     * characters and is never a PNR (SHG…). Compared without case; first spelling kept.
     *
     * @return list<string>
     */
    public static function txnCandidates(string $t, string $override = ''): array
    {
        $ok = static fn(string $v): bool => preg_match('/\d/', $v) === 1 && stripos($v, 'SHG') !== 0 && strlen($v) >= 6;
        $t = self::normDigits($t);
        $found = [];
        /* 25 Nov 2026 (mp2): the Indian bank labels too - UPI Ref No, UPI Ref, IMPS Ref No, NEFT Ref, Ref No,
           RRN, UTR, "reference number is …"; the id must carry a digit IN the pattern, so a word after a label
           ("transaction reference number is 4321…") never hides the label that follows it */
        if (preg_match_all('/(?:(?<![A-Za-z])(?:(?:upi|imps|neft|rtgs)[ \t]+ref(?:erence)?|txn|transaction|trans\.|ref(?:erence)?|trace|utr|rrn)(?![A-Za-z])|ट्रान्जेक्सन|कारोबार|सन्दर्भ)[\s.]*'
                . '(?:(?:id|no|number|num|nr|#|नं|नम्बर|आईडी)(?![A-Za-z])\.?[ \t]*){0,2}\s*[:#\-]?\s*(?:is[ \t]+)?(?=[A-Z\-\/]*[0-9])([A-Z0-9][A-Z0-9\-\/]{5,40})/iu', $t, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $x) {
                $found[] = rtrim($x[1], '-/');
            }
        }
        if (trim($override) !== '' && @preg_match_all($override, $t, $om, PREG_SET_ORDER) > 0) {
            foreach ($om as $x) {
                $found[] = trim((string) ($x[1] ?? ''));
            }
        }
        $out = [];
        foreach ($found as $v) {
            if ($ok($v)) {
                $out[strtoupper($v)] ??= mb_substr($v, 0, 80);
            }
        }
        return array_values($out);
    }

    /** The transaction / reference id: the ONE distinct candidate (txnCandidates()), else '' (never auto-confirms). */
    public static function txnIn(string $t, string $override = ''): string
    {
        $c = self::txnCandidates($t, $override);
        return count($c) === 1 ? $c[0] : '';
    }

    /**
     * The account the alert says was credited, masked to its last (up to 6)
     * digits: 'XXXXXXX789012', 'XXXX9012'. '' = none written, '?' = two
     * different accounts (e.g. "from A/C … to A/C …") - neither ever matches
     * our account, so such a credit waits for a person.
     *
     * The remarks / narration line is cut out first (bankText): the PAYER types
     * it, so an "A/C XXXX9012" written there must never count.
     */
    public static function accountIn(string $t, string $override = ''): string
    {
        $t = self::bankText($t);
        if (trim($override) !== '') {
            $r = @preg_match($override, $t, $m);
            if ($r === 1 && isset($m[1])) {
                return self::maskAccount((string) $m[1]);
            }
            if ($r !== false) {
                return '';   // the owner's rule decides; no guessing past it
            }
        }
        $mk  = '[0-9Xx*#•]';
        $key = '(?<![a-z])(?:a\/c|acct|account|खाता)(?![a-z])';
        $tokens = [];
        if (preg_match_all('/' . $key . '\s*(?:no\.?|number|num|#|नं\.?|नम्बर)?\s*[:\-]?\s*(' . $mk . '{3,30}(?:-' . $mk . '{1,30}){0,4})(?![0-9Xx*#•])/iu', $t, $mm) > 0) {
            foreach ($mm[1] as $x) {
                $tokens[] = self::maskAccount((string) $x);
            }
        }
        if (preg_match_all('/' . $key . '[^\n]{0,24}?\bend(?:ing|s)\s*(?:with|in)?\s*[:\-]?\s*(\d{3,6})(?![0-9])/iu', $t, $mm) > 0) {
            foreach ($mm[1] as $x) {
                $tokens[] = 'XXXX' . $x;
            }
        }
        $tokens = array_values(array_filter($tokens, static fn(string $s): bool => $s !== ''));
        if ($tokens === []) {
            return '';
        }
        $vis = static fn(string $s): int => preg_match('/(\d+)$/', $s, $m) === 1 ? strlen($m[1]) : 0;
        usort($tokens, static fn(string $a, string $b): int => $vis($b) <=> $vis($a));
        foreach ($tokens as $x) {
            if (!self::sameAccount($tokens[0], $x)) {
                return '?';
            }
        }
        return $tokens[0];
    }

    /** "0123456789012" -> "XXXXXXX789012", "****9012" -> "XXXX9012"; '' when fewer than 3 digits end it. */
    public static function maskAccount(string $s): string
    {
        $s = strtoupper(str_replace(['-', ' ', '•', '*', '#'], ['', '', 'X', 'X', 'X'], trim($s)));
        if (preg_match('/^[0-9X]{3,60}$/', $s) !== 1 || preg_match('/(\d+)$/', $s, $m) !== 1 || strlen($m[1]) < 3) {
            return '';
        }
        $keep = min(6, strlen($m[1]));
        return substr(str_repeat('X', strlen($s) - $keep) . substr($s, -$keep), -40);
    }

    /** Two masked accounts agree on every digit both show at the end. */
    public static function sameAccount(string $a, string $b): bool
    {
        if (preg_match('/(\d+)$/', $a, $x) !== 1 || preg_match('/(\d+)$/', $b, $y) !== 1) {
            return false;
        }
        $n = min(strlen($x[1]), strlen($y[1]));
        return $n >= 3 && substr($x[1], -$n) === substr($y[1], -$n);
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

    /** The counters one poll reports. */
    private static function counters(): array
    {
        return ['ok' => true, 'msg' => '', 'seen' => 0, 'credits' => 0, 'new' => 0, 'duplicates' => 0, 'debits' => 0,
                'other' => 0, 'confirmed' => 0, 'suggested' => 0, 'unmatched' => 0, 'failed' => 0, 'incomplete' => 0, 'last_uid' => 0];
    }

    /** One line for the panel / cron log. */
    private static function summary(array $res): string
    {
        return $res['seen'] . ' read · ' . $res['credits'] . ' credits · ' . $res['confirmed'] . ' confirmed · '
            . ($res['suggested'] > 0 ? $res['suggested'] . ' suggested (press Confirm) · ' : '') . $res['unmatched'] . ' need a person'
            . (($res['incomplete'] ?? 0) > 0 ? ' · ' . $res['incomplete'] . ' credit(s) not stored: setup incomplete' : '')
            . ($res['failed'] > 0 ? ' · ' . $res['failed'] . ' could not be read' : '');
    }

    /**
     * Has the cron anything to do? Before the mp2 migration: the old switch bankmail_on.
     * After it: an active box, or old settings waiting to become account #1.
     */
    public static function anythingToRun(): bool
    {
        if (!BankMailAccounts::ready()) {
            return self::on();
        }
        return BankMailAccounts::legacyPending()
            || array_filter(BankMailAccounts::active(), static fn(array $a): bool => BankMailAccounts::isReady($a)) !== [];
    }

    /**
     * Read new alerts.
     *
     * Every active bank Gmail box (BankMailAccounts) once the mp2 migration ran - a
     * Google box through the Gmail API (GmailApi), an app-password box through IMAP -
     * each with its own rules (pollAccount). A Google box marked "reconnect" is left
     * alone by the cron until the owner signs in again (Check now still tries it).
     *
     * $imap is for tests (an already-connected client); so is $each (callable(string
     * $raw, array &$res): void, in place of handle()): with either one - or before the
     * migration - the OLD single box (the bankmail_* settings) is read (pollLegacy).
     *
     * @return array<string,mixed> counts + ok / msg (+ accounts: how many boxes were read)
     */
    public static function poll(int $max = 50, ?BankMailImap $imap = null, ?callable $each = null): array
    {
        if ($imap !== null || $each !== null || !BankMailAccounts::ready()) {
            return self::pollLegacy($max, $imap, $each);
        }
        $res = self::counters() + ['accounts' => 0];
        if (!PayCredits::ready()) {
            return self::finish(['ok' => false, 'msg' => 'bank_credits table missing (migration not applied)'] + $res);
        }
        BankMailAccounts::migrateLegacy();
        $msgs = [];
        foreach (BankMailAccounts::active() as $a) {
            if ((string) $a['method'] === 'google' && (int) ($a['last_ok'] ?? 1) === 0 && (string) ($a['last_result'] ?? '') === GoogleOAuth::RECONNECT) {
                $msgs[] = $a['email'] . ': ' . GoogleOAuth::RECONNECT;
                continue;
            }
            $r = self::pollAccount($a, $max);
            $res['accounts']++;
            foreach (['seen', 'credits', 'new', 'duplicates', 'debits', 'other', 'confirmed', 'suggested', 'unmatched', 'failed', 'incomplete'] as $k) {
                $res[$k] += (int) ($r[$k] ?? 0);
            }
            if (!$r['ok']) {
                $res['ok'] = false;
            }
            $msgs[] = $a['email'] . ': ' . ($r['ok'] ? '' : '✖ ') . $r['msg'];
        }
        $res['msg'] = $msgs === [] ? 'no active bank Gmail account' : mb_substr(implode(' | ', $msgs), 0, 500);
        return self::finish($res);
    }

    /**
     * Read one box now (the cron, or "अहिले जाँच्नुहोस् · Check now"). $imap is a test hook for an
     * app-password box. The box's last_poll_at / last_ok / last_result say how it went.
     *
     * @param array<string,mixed> $a the bank_mail_accounts row
     * @return array<string,mixed> counts + ok / msg
     */
    public static function pollAccount(array $a, int $max = 50, ?BankMailImap $imap = null): array
    {
        $res = self::counters();
        $id  = (int) $a['id'];
        if (!PayCredits::ready()) {
            return ['ok' => false, 'msg' => 'bank_credits table missing (migration not applied)'] + $res;
        }
        if (!BankMailAccounts::isReady($a)) {
            /* no DKIM domain / account digits yet: read NOTHING, so no alert is used up before the
               rules exist - the first read after Edit -> Save starts from the last 2-3 days */
            $res['msg'] = BankMailAccounts::NOT_READY_MSG;
            BankMailAccounts::note($id, false, $res['msg']);
            return $res;
        }
        $secret = '';
        try {
            if ((string) $a['method'] === 'google') {
                require_once __DIR__ . '/gmailapi.php';
                GmailApi::poll($a, $max, $res);
            } else {
                $secret = BankMailAccounts::secret($a);
                self::pollImapAccount($a, $max, $res, $imap, $secret);
            }
            $res['msg'] = self::summary($res);
            BankMailAccounts::note($id, true, $res['msg']);
        } catch (Throwable $e) {
            $res['ok']  = false;
            $res['msg'] = self::scrub($e->getMessage(), $secret);
            if (!($e instanceof GoogleReconnect)) {
                BankMailAccounts::note($id, false, $res['msg']);
            }
            Logger::warning('BankMail: box check failed', ['account' => $id, 'err' => $res['msg']], 'payment');
        }
        return $res;
    }

    /**
     * An app-password box over IMAP (imap.gmail.com, the box's address + its sealed app password),
     * read-only, from its own last_uid / uidvalidity, FROM its senders (else its DKIM domain).
     *
     * @param array<string,mixed> $res
     */
    private static function pollImapAccount(array $a, int $max, array &$res, ?BankMailImap $imap, string $pass): void
    {
        $id   = (int) $a['id'];
        $mine = $imap === null;
        if ($imap === null) {
            if ($pass === '') {
                throw new RuntimeException('App password खुलेन / छैन — Edit मा फेरि राख्नुहोस् · the app password could not be opened, save it again.');
            }
            $imap = BankMailImap::connect('imap.gmail.com');
        }
        try {
            if ($mine) {
                $imap->login((string) $a['email'], $pass);
            }
            $box  = $imap->examine('INBOX');
            $last = (int) ($a['last_uid'] ?? 0);
            $was  = (string) ($a['uidvalidity'] ?? '');
            if ($box['uidvalidity'] !== '' && $was !== $box['uidvalidity']) {
                if ($was !== '') {
                    $last = 0;   // the box was rebuilt: UIDs mean something else now (Message-ID still dedupes)
                }
                Database::update('bank_mail_accounts', ['uidvalidity' => mb_substr($box['uidvalidity'], 0, 20)], 'id = :i', ['i' => $id]);
            }
            $senders = BankMailAccounts::senders($a);
            if ($senders === [] && BankMailAccounts::dkimDomain($a) !== '') {
                $senders = [BankMailAccounts::dkimDomain($a)];
            }
            $crit = trim(($last > 0 ? 'UID ' . ($last + 1) . ':*' : 'SINCE ' . date('j-M-Y', time() - 2 * 86400)) . ' ' . self::fromCriteria($senders));
            $uids = array_values(array_filter($imap->uidSearch($crit), static fn(int $u): bool => $u > $last));
            $uids = array_slice($uids, 0, max(1, min(200, $max)));
            foreach ($uids as $uid) {
                $raw = $imap->uidFetch($uid);   // a broken connection ends the run here: retried next time
                $res['seen']++;
                if ($raw !== '') {
                    try {
                        self::handle($raw, $res, $a, 'uid:' . $uid);
                    } catch (Throwable $e) {
                        $res['failed']++;
                        $why = self::scrub(PayCredits::text($e->getMessage(), 300), $pass);
                        Logger::warning('BankMail: message could not be read, stepped over', ['account' => $id, 'uid' => $uid, 'err' => $why], 'payment');
                        BankMailAccounts::log($id, 'unreadable', '', null, null, 'uid:' . $uid);
                        PayCredits::alert('Bank mail could not be read · ' . (string) $a['email'] . ' UID ' . $uid,
                            'Gmail message UID ' . $uid . ' in ' . (string) $a['email'] . ' could not be read and was stepped over (' . $why . '). If it is a credit alert, open it in Gmail and check the bank statement by hand.',
                            null, BankMailAccounts::tabFor((string) $a['country']));
                    }
                } else {
                    BankMailAccounts::log($id, 'gone', '', null, null, 'uid:' . $uid);
                }
                $last = max($last, $uid);
                Database::update('bank_mail_accounts', ['last_uid' => $last], 'id = :i', ['i' => $id]);
            }
            $res['last_uid'] = $last;
        } finally {
            if ($mine) {
                $imap->logout();
            }
        }
    }

    /**
     * The OLD single box (the bankmail_* settings) - tests with an injected client, and
     * every run before the mp2 migration. $imap is for tests (an already-connected client);
     * so is $each (callable(string $raw, array &$res): void, in place of handle()).
     *
     * A message that fails on its own (bad bytes, a value the database refuses,
     * anything thrown while handling it) is logged, raises an admin bell and is
     * stepped over - bankmail_last_uid still moves past it, so one odd e-mail
     * can never stop every later credit alert from being read. A failure of the
     * IMAP connection or of the database itself (saving bankmail_last_uid)
     * still ends the poll without moving on, and the next run tries again.
     *
     * @return array<string,mixed> counts + ok / msg
     */
    private static function pollLegacy(int $max = 50, ?BankMailImap $imap = null, ?callable $each = null): array
    {
        $res = ['last_uid' => Settings::getInt('bankmail_last_uid', 0)] + self::counters();
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
                $raw = $imap->uidFetch($uid);   // a broken connection ends the poll here: retried next run
                $res['seen']++;
                if ($raw !== '') {
                    try {
                        if ($each !== null) {
                            $each($raw, $res);
                        } else {
                            self::handle($raw, $res);
                        }
                    } catch (Throwable $e) {
                        $res['failed']++;
                        $why = self::scrub(PayCredits::text($e->getMessage(), 300), $cfg['pass']);
                        Logger::warning('BankMail: message could not be read, stepped over', ['uid' => $uid, 'err' => $why], 'payment');
                        PayCredits::alert('Bank mail could not be read · UID ' . $uid,
                            'Gmail message UID ' . $uid . ' could not be read and was stepped over (' . $why . '). If it is a credit alert, open it in Gmail and check the bank statement by hand.',
                            null, 'np');
                    }
                }
                $last = max($last, $uid);
                Settings::set('bankmail_last_uid', (string) $last, 'int', 'payments');
            }
            $res['last_uid'] = $last;
            $res['msg'] = $res['seen'] . ' read · ' . $res['credits'] . ' credits · ' . $res['confirmed'] . ' confirmed · '
                . ($res['suggested'] > 0 ? $res['suggested'] . ' suggested (press Confirm) · ' : '') . $res['unmatched'] . ' need a person'
                . ($res['failed'] > 0 ? ' · ' . $res['failed'] . ' could not be read' : '');
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

    /**
     * One message: parse, store, try to confirm.
     *
     * $acct = the bank Gmail box it came from (BankMailAccounts row): its own rules parse it
     * (optsFor), its sender rule is checked first ('not-bank-sender' stores nothing), a box that
     * is not READY stores no credit ('setup-incomplete'), the credit carries mail_account_id and
     * the box's currency, and one bank_mail_log row says what became of the mail ($msgRef =
     * "g:<gmail id>" / "uid:<n>", so it is read once). null = the old single box (no log).
     *
     * @param array<string,mixed>      $res
     * @param array<string,mixed>|null $acct
     */
    public static function handle(string $raw, array &$res, ?array $acct = null, string $msgRef = ''): void
    {
        $p = self::parse($raw, $acct !== null ? self::optsFor($acct) : null);
        $log = static function (string $outcome, ?int $creditId = null) use ($acct, $p, $msgRef): void {
            if ($acct !== null) {
                BankMailAccounts::log((int) $acct['id'], $outcome, (string) $p['subject'], $p['date'] !== '' ? (string) $p['date'] : null, $creditId, $msgRef);
            }
        };
        if ($acct !== null && BankMailAccounts::senderRule($acct, (string) $p['from']) === false) {
            $res['other']++;   // the search already asks for the bank's senders: this is someone else
            $log('not-bank-sender');
            return;
        }
        if ($p['is_debit']) {   // debit words only (credit AND debit words = is_credit + mixed: kept)
            $res['debits']++;
            $log('debit-ignored');
            return;
        }
        if (!$p['is_credit']) {
            $res['other']++;
            $log('not-credit');
            return;
        }
        if ($acct !== null && !BankMailAccounts::isReady($acct)) {
            $res['other']++;
            $res['incomplete'] = (int) ($res['incomplete'] ?? 0) + 1;   // no DKIM domain / account digits yet: logged, no credit, no suggestion
            /* (pollAccount does not read a box that is not ready; this is only a race with Edit)
               no msg_ref: the mail is read again once the box is ready (BankMailAccounts::rereadIncomplete) */
            if ($acct !== null) {
                BankMailAccounts::log((int) $acct['id'], 'setup-incomplete', (string) $p['subject'], $p['date'] !== '' ? (string) $p['date'] : null, null, '');
            }
            return;
        }
        $res['credits']++;
        $id = PayCredits::record([
            'source' => 'gmail', 'currency' => $acct !== null ? (string) $acct['currency'] : 'NPR', 'amount' => $p['amount'], 'txn_id' => $p['txn_id'],
            'ref_text' => $p['remarks'], 'pnr' => $p['pnr'], 'sender' => $p['from'], 'dkim_ok' => $p['dkim_ok'],
            'message_id' => $p['message_id'], 'received_at' => $p['date'], 'raw_excerpt' => $p['subject'] . "\n" . mb_substr($p['text'], 0, 1500),
            'credit_account' => $p['account'], 'mail_account_id' => $acct !== null ? (int) $acct['id'] : null,
        ]);
        $outcome = PayCredits::lastOutcome();
        if ($outcome === 'conflict') {
            $res['new']++;
            $res['unmatched']++;   // same txn id, another e-mail: kept for a person, bell rung by record()
            $log('unmatched', $id);
            return;
        }
        if ($outcome === 'replay') {
            $res['duplicates']++;
            $log('duplicate', $id);
            return;
        }
        $row = PayCredits::get($id);
        if ($row === null || (string) $row['state'] !== 'new') {
            $res['duplicates']++;
            $log('duplicate', $id > 0 ? $id : null);
            return;
        }
        $res['new']++;
        /* an ambiguous alert (two amounts, two txn ids, credit AND debit words) - or one whose
           Authentication-Results could not be read safely - is kept for a person with the reason:
           it never goes near tryAutoConfirm() */
        $hold = $p['hold'];
        if ($hold === '' && !$p['dkim_ok'] && (string) ($p['dkim_why'] ?? '') !== '') {
            $hold = 'No DKIM pass: ' . $p['dkim_why'] . ' (quotes, backslash, nested / unbalanced brackets or odd characters) — could be a forged e-mail; check the bank statement';
        }
        $r = $hold !== '' ? PayCredits::hold($id, $hold) : PayCredits::tryAutoConfirm($id);
        if ($r['ok']) {
            $res['confirmed']++;
            $log('confirmed', $id);
        } elseif ($r['why'] === 'suggested') {
            $res['suggested']++;   // every check passed, Gmail auto-PAID is off: a person presses Confirm
            $log(($r['kind'] ?? 'pnr') === 'amount' ? 'suggestion-amount' : 'suggestion', $id);
        } elseif ($r['state'] === 'unmatched') {
            $res['unmatched']++;
            $log('unmatched', $id);
        } else {
            $log('unmatched', $id);
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
            if (PayCredits::accountHint() === '') {
                $warn[] = 'our account digits not saved yet (nothing can auto-confirm)';
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
