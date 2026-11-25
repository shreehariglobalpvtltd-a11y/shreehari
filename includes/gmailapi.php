<?php
/**
 * =====================================================================
 *  includes/gmailapi.php — GmailApi: read a bank Gmail box that was connected
 *  with "Google बाट Login" (25 Nov 2026, mp2 "bank Gmail").
 *
 *  Read-only (scope gmail.readonly): nothing in the mailbox changes, not even
 *  "read". One run:
 *    1. an access token (GoogleOAuth::accessToken: refreshed when < 60 s left;
 *       invalid_grant = the box is marked "Google access removed - reconnect" and
 *       the run stops);
 *    2. users/me/messages?q=<query>: "newer_than:3d", "after:<last seen - 2 min>"
 *       once the box has a position, and "from:(a OR b)" from the box's senders
 *       (blank senders = its DKIM domain; only [A-Za-z0-9._%+-@] reach the query);
 *       maxResults=50, up to 6 pages;
 *    3. the ids this box never logged (bank_mail_log.msg_ref "g:<id>"), OLDEST
 *       first, at most $max (50) per run: users/me/messages/<id>?format=raw,
 *       base64url-decoded, through the SAME BankMail::handle() path the IMAP
 *       reader uses, with the box's own rules (country / currency / senders /
 *       DKIM domain / account digits / mail_account_id);
 *    4. last_seen_ms = the newest internalDate handled.
 *  One bad message never blocks the rest: it is logged 'unreadable', a bell
 *  rings, the run goes on. A Google / network failure ends the run without
 *  marking anything seen (the next run tries again).
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/googleoauth.php';

final class GmailApi
{
    /** The Gmail search for this box. */
    public static function query(array $a): string
    {
        $q = 'newer_than:3d';
        $last = (int) ($a['last_seen_ms'] ?? 0);
        if ($last > 0) {
            $q .= ' after:' . max(1, intdiv($last, 1000) - 120);   // epoch seconds; the log dedupes the overlap
        }
        $from = [];
        $list = BankMailAccounts::senders($a);
        if ($list === [] && BankMailAccounts::dkimDomain($a) !== '') {
            $list = [BankMailAccounts::dkimDomain($a)];
        }
        foreach ($list as $s) {
            $s = ltrim(trim($s), '@');
            if ($s !== '' && preg_match('/^[A-Za-z0-9._%+\-@]{1,190}$/', $s) === 1) {
                $from[] = $s;
            }
        }
        if ($from !== []) {
            $q .= ' from:(' . implode(' OR ', array_values(array_unique($from))) . ')';
        }
        return $q;
    }

    private static function b64urlDecode(string $s): string
    {
        $s = strtr(trim($s), '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad > 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $d = base64_decode($s, true);
        return $d === false ? '' : $d;
    }

    /**
     * Read new mail of one Google box into $res (BankMail::poll counters).
     *
     * @param array<string,mixed> $a   the bank_mail_accounts row
     * @param array<string,mixed> $res counters
     * @throws GoogleReconnect|RuntimeException a failure of Google itself (the caller notes it)
     */
    public static function poll(array $a, int $max, array &$res): void
    {
        $id  = (int) $a['id'];
        $tok = GoogleOAuth::accessToken($a);
        $q   = self::query($a);
        $ids = [];
        $page = '';
        for ($i = 0; $i < 6; $i++) {   // 6 pages x 50 = the newest 300 ids at most (after: keeps it short once a box has a position)
            $url = GoogleOAuth::GMAIL_BASE . 'messages?' . http_build_query(['q' => $q, 'maxResults' => 50] + ($page !== '' ? ['pageToken' => $page] : []), '', '&', PHP_QUERY_RFC3986);
            $r = GoogleOAuth::request('GET', $url, null, $tok);
            if ($r['http'] !== 200) {
                $msg = is_array($r['json']['error'] ?? null) ? (string) ($r['json']['error']['message'] ?? '') : '';
                throw new RuntimeException('Gmail list failed (HTTP ' . $r['http'] . ($msg !== '' ? ': ' . mb_substr($msg, 0, 120) : '') . ')');
            }
            foreach ((array) ($r['json']['messages'] ?? []) as $m) {
                $gid = is_array($m) ? (string) ($m['id'] ?? '') : '';
                if (preg_match('/^[A-Za-z0-9]{1,60}$/', $gid) === 1) {
                    $ids[] = $gid;
                }
            }
            $page = is_string($r['json']['nextPageToken'] ?? null) ? (string) $r['json']['nextPageToken'] : '';
            if ($page === '' || preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $page) !== 1) {
                break;
            }
        }
        $ids  = array_reverse(array_values(array_unique($ids)));   // Gmail lists newest first: oldest first here
        $seen = BankMailAccounts::seen($id, array_map(static fn(string $g): string => 'g:' . $g, $ids));
        $todo = array_slice(array_values(array_filter($ids, static fn(string $g): bool => !isset($seen['g:' . $g]))), 0, max(1, min(200, $max)));
        $lastMs = (int) ($a['last_seen_ms'] ?? 0);
        foreach ($todo as $gid) {
            $ref = 'g:' . $gid;
            $g = GoogleOAuth::request('GET', GoogleOAuth::GMAIL_BASE . 'messages/' . rawurlencode($gid) . '?format=raw', null, $tok);
            if ($g['http'] === 404) {
                BankMailAccounts::log($id, 'gone', '', null, null, $ref);   // deleted between list and get
                continue;
            }
            if ($g['http'] !== 200) {
                throw new RuntimeException('Gmail message fetch failed (HTTP ' . $g['http'] . ')');   // retried next run
            }
            $res['seen']++;
            $ms  = is_numeric($g['json']['internalDate'] ?? null) ? (int) $g['json']['internalDate'] : 0;
            $raw = self::b64urlDecode(is_string($g['json']['raw'] ?? null) ? (string) $g['json']['raw'] : '');
            try {
                if ($raw === '') {
                    throw new RuntimeException('empty message');
                }
                BankMail::handle($raw, $res, $a, $ref);
            } catch (Throwable $e) {
                $res['failed']++;
                $why = mb_substr(PayCredits::text($e->getMessage(), 200), 0, 200);
                Logger::warning('BankMail (Gmail API): message could not be read, stepped over', ['account' => $id, 'gmail_id' => $gid, 'err' => $why], 'payment');
                BankMailAccounts::log($id, 'unreadable', '', $ms > 0 ? date('Y-m-d H:i:s', intdiv($ms, 1000)) : null, null, $ref);
                PayCredits::alert('Bank mail could not be read · ' . (string) $a['email'],
                    'Gmail message ' . $gid . ' in ' . (string) $a['email'] . ' could not be read and was stepped over (' . $why . '). If it is a credit alert, open it in Gmail and check the bank statement by hand.',
                    null, BankMailAccounts::tabFor((string) $a['country']));
            }
            if ($ms > $lastMs) {
                $lastMs = $ms;
                Database::update('bank_mail_accounts', ['last_seen_ms' => $lastMs], 'id = :i', ['i' => $id]);
            }
        }
    }
}
