<?php
/**
 * =====================================================================
 *  includes/paycredits.php — PayCredits: the shared ledger of money the
 *  BANK says arrived (25 Nov 2026, mp1 "pay").
 *
 *  Two feeders write one table (bank_credits,
 *  database/upgrade-2026-11-25-mp1-pay-credits.sql):
 *    gmail   — a Global IME Bank credit alert e-mail (BankMail, cron/bank-mail.php)
 *    bankapi — a signed India bank / UPI API webhook (BankApi, payment/bank-webhook.php)
 *
 *  Money rule (owner, 19 Nov: "auto-confirm only on authenticated provider/bank
 *  success matching booking, amount, currency and transaction"). A credit
 *  confirms a ticket by itself ONLY when ALL of these hold:
 *    - the source was authenticated: gmail = DKIM pass from the bank's domain
 *      AND the sender is on the allow-list AND the credited account in the
 *      alert ends with OUR account digits (bankmail_account_hint) - a genuine,
 *      signed alert for money paid into someone else's account is re-sendable;
 *      bankapi = HMAC signature verified
 *      AND bankapi_mode = live (sandbox rows are 'test' and never confirm);
 *    - it carries a transaction id never used before (UNIQUE source+txn, and
 *      not on any other booking's payment). The same txn on a DIFFERENT e-mail
 *      (or a different amount / remark) is never dropped as a replay: it is
 *      kept without the txn id, 'unmatched', with a bell;
 *    - the remarks name exactly ONE booking, that booking is pending payment,
 *      is not pay-at-boarding, and carries no proof with this txn elsewhere;
 *    - the amount equals what is due EXACTLY (NPR credit: the NPR frozen on
 *      the sale, else the INR due x npr_per_inr; INR credit: the INR due).
 *  Then it goes through BookingService::submitPaymentProof + PayAuto (the same
 *  approveReviewed() gate a person uses) and OpsHub::markPaid ticks the
 *  chalani "PAID - Nepali online (Gmail Auto)" / "PAID - Indian UPI (API Auto)".
 *
 *  GMAIL = A SUGGESTION, NOT AUTO-PAID (owner-safety, 25 Nov 2026): a free-text
 *  Gmail alert is not trusted for unattended auto-PAID until the real Global IME
 *  alert template is locked, so pay_bankmail_autoconfirm_on is OFF by default
 *  (gmailAutoOn()). While it is off, a Gmail credit that passes EVERY check above
 *  is stored as a SUGGESTION: state 'matched' with booking_id = that one booking,
 *  acted_at still NULL (isSuggestion()), note "सुझाव · Suggested: <PNR> NPR <amount>",
 *  a bell "बैंक इमेल: रू X आयो · PNR Y — … Confirm थिच्नुहोस्" - and the booking stays
 *  pending. A person checks the bank app and presses Confirm on Payment
 *  Management = link() (approveReviewed via 'queue'), chalani "PAID - Nepali
 *  online (Gmail)" with "confirmed by <name>" in the tick note. The India bank
 *  API (signed webhook, live mode) path is unchanged.
 *
 *  Anything else: state 'unmatched' + an admin bell with the reason. A person
 *  can then "Link to PNR" (approveReviewed via 'queue': PaymentRisk + a person
 *  stay in the loop, and the amount must still match) or Ignore it.
 *
 *  MANY GMAIL BOXES (25 Nov 2026, mp2 "bank Gmail"): a Gmail credit from a box the
 *  owner added on Payment Management carries mail_account_id; it is checked against
 *  THAT box's rules (on / paused, its senders, its DKIM domain, its currency, its
 *  account digits - BankMailAccounts), never the global bankmail_* settings (those
 *  stay the rules of the old single box, mail_account_id NULL). An India box sends
 *  INR credits: expected = the booking's INR due, the same suggestion flow.
 *  AMOUNT-ONLY (both currencies, Gmail): an alert with NO PNR is matched by amount -
 *  exactly ONE booking from the last 72 h that still owes money (pending, or confirmed
 *  with a balance; never pay-at-boarding) owing EXACTLY the credited amount = a
 *  SUGGESTION with match_kind 'amount' (note "PNR छैन — रकम मात्र मिल्यो"), NEVER auto,
 *  whatever pay_bankmail_autoconfirm_on says; two or more = unmatched "रकम मिल्ने धेरै
 *  टिकट" with up to 5 PNRs in the note for the person to choose.
 *
 *  Never writes bookings.status itself. Quietly off when the table is missing.
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class PayCredits
{
    public const LABEL_AUTO = [
        'gmail'   => 'PAID - Nepali online (Gmail Auto)',
        'bankapi' => 'PAID - Indian UPI (API Auto)',
    ];
    public const LABEL_LINKED = [
        'gmail'   => 'PAID - Nepali online (Linked)',
        'bankapi' => 'PAID - Indian UPI (Linked)',
    ];
    /** A person confirmed a Gmail SUGGESTION (OpsHub::markPaid keeps 40 characters: who confirmed goes in the tick note). */
    public const LABEL_CONFIRMED = [
        'gmail'   => 'PAID - Nepali online (Gmail)',
    ];
    /** The same three for an INR Gmail credit (an India bank box, mp2). */
    public const LABEL_GMAIL_INR = [
        'auto'      => 'PAID - Indian online (Gmail Auto)',
        'linked'    => 'PAID - Indian online (Linked)',
        'confirmed' => 'PAID - Indian online (Gmail)',
    ];
    public const SUGGEST_NOTE = 'सुझाव · Suggested: ';
    public const AMOUNT_NOTE  = 'PNR छैन — रकम मात्र मिल्यो · amount-only match';
    /** How far back an amount-only match looks for a booking that still owes money. */
    public const AMOUNT_WINDOW_H = 72;
    /** a booking made up to this long AFTER the alert's time still counts (clock drift between bank and us) */
    public const AMOUNT_SLACK_S = 600;
    private const PREFIX = ['gmail' => 'GIME-', 'bankapi' => 'UPIAPI-'];
    /** A Gmail credit in INR (an India bank box) is not Global IME. */
    private const PREFIX_GMAIL_INR = 'INBANK-';
    /** No single booking is a crore: a bigger figure means the alert was misread (and would not fit DECIMAL(12,2) safely). */
    public const MAX_AMOUNT = 10000000.0;

    private static ?bool $ready = null;
    private static ?bool $acctCol = null;
    private static ?bool $mailCols = null;
    /** What the last record() call did: 'new' | 'replay' | 'conflict' ('' = nothing stored). */
    private static string $lastOutcome = '';

    /** The bank_credits table exists (the migration ran). */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Database::fetch("SHOW TABLES LIKE 'bank_credits'") !== null;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** Test hook / after the migration ran in the same request. */
    public static function flush(): void
    {
        self::$ready = null;
        self::$acctCol = null;
        self::$mailCols = null;
    }

    /** bank_credits.mail_account_id + match_kind are there (the mp2 bank Gmail migration). */
    public static function mailCols(): bool
    {
        if (self::$mailCols === null) {
            try {
                self::$mailCols = self::ready() && Database::fetch("SHOW COLUMNS FROM bank_credits LIKE 'mail_account_id'") !== null
                    && Database::fetch("SHOW COLUMNS FROM bank_credits LIKE 'match_kind'") !== null;
            } catch (Throwable $e) {
                self::$mailCols = false;
            }
        }
        return self::$mailCols;
    }

    /**
     * The rules a Gmail credit is checked against: the box it came from (mail_account_id), or -
     * no box - the old single-box settings (bankmail_on, bankmail_from, NPR, bankmail_account_hint).
     *
     * @param array<string,mixed> $c a bank_credits row
     * @return array{acct:?array, off:string, currency:string, hint:string, noHint:string, sender:callable(string):bool, ready:bool}
     */
    public static function gmailRules(array $c): array
    {
        $aid = self::mailCols() ? (int) ($c['mail_account_id'] ?? 0) : 0;
        if ($aid <= 0) {
            return [
                'acct' => null, 'ready' => true,
                'off' => Settings::getBool('bankmail_on', false) ? '' : 'Gmail bank alerts are off (bankmail_on) — link it by hand',
                'currency' => 'NPR', 'hint' => self::accountHint(),
                'noHint' => 'Our Global IME account digits (bankmail_account_hint) are not saved — cannot tell the money came to us',
                'sender' => static fn(string $f): bool => self::senderAllowed($f),
            ];
        }
        require_once __DIR__ . '/bankmailaccounts.php';
        $a = BankMailAccounts::get($aid);
        if ($a === null) {
            return [
                'acct' => null, 'ready' => false, 'off' => 'The bank Gmail box this credit came from was removed — link it by hand',
                'currency' => (string) ($c['currency'] ?? 'NPR'), 'hint' => '', 'noHint' => 'The bank Gmail box was removed',
                'sender' => static fn(string $f): bool => false,
            ];
        }
        return [
            'acct' => $a, 'ready' => BankMailAccounts::isReady($a),
            'off' => (int) $a['is_active'] === 1 ? '' : 'Bank Gmail box ' . $a['email'] . ' is paused — link it by hand',
            'currency' => (string) $a['currency'], 'hint' => BankMailAccounts::hint($a),
            'noHint' => 'Our account digits for ' . $a['email'] . ' are not saved — cannot tell the money came to us',
            'sender' => static fn(string $f): bool => BankMailAccounts::senderAllowed($a, $f),
        ];
    }

    /** The chalani label for a Gmail credit: $kind auto | linked | confirmed (NPR = Global IME, INR = an India bank box). */
    public static function gmailLabel(string $cur, string $kind): string
    {
        if ($cur === 'INR') {
            return self::LABEL_GMAIL_INR[$kind] ?? self::LABEL_GMAIL_INR['linked'];
        }
        return match ($kind) {
            'auto'      => self::LABEL_AUTO['gmail'],
            'confirmed' => self::LABEL_CONFIRMED['gmail'],
            default     => self::LABEL_LINKED['gmail'],
        };
    }

    /** bank_credits.credit_account is there (the same migration adds it). */
    public static function accountCol(): bool
    {
        if (self::$acctCol === null) {
            try {
                self::$acctCol = self::ready() && Database::fetch("SHOW COLUMNS FROM bank_credits LIKE 'credit_account'") !== null;
            } catch (Throwable $e) {
                self::$acctCol = false;
            }
        }
        return self::$acctCol;
    }

    /** 'new' (a row was stored), 'replay' (the same credit again: the old row), 'conflict' (same txn, another alert). */
    public static function lastOutcome(): string
    {
        return self::$lastOutcome;
    }

    /** The money code + the confirm path, loaded only when needed. */
    private static function boot(): void
    {
        foreach (['fare', 'seats', 'qr', 'pdf', 'ticket', 'counterdesk', 'booking', 'notify', 'payevents', 'payauto', 'opshub'] as $f) {
            if (is_file(__DIR__ . '/' . $f . '.php')) {
                require_once __DIR__ . '/' . $f . '.php';
            }
        }
    }

    /* =================================================================
     *  PNRs in free text
     * ================================================================= */

    /**
     * Every distinct PNR written in a remark, normalised to upper case:
     * SHG-YYYY-NNNNN (new, 5 digits), SHG-YYYY-NNNN (the owner's 4-digit form,
     * e.g. SHG-2026-0842) and the legacy SHG-<ROUTE>-<STAMP>-<RAND>. Spaces or
     * underscores in place of the dashes are accepted ("shg 2026 0842").
     *
     * @return list<string>
     */
    public static function pnrsIn(string $text): array
    {
        $p   = defined('PNR_PREFIX') ? preg_quote((string) PNR_PREFIX, '/') : 'SHG';
        $out = [];
        $t   = strtoupper($text);
        // legacy first: four segments, so a new-style number can never be read as one
        if (preg_match_all('/(?<![A-Z0-9])' . $p . '-([A-Z0-9]{1,6})-([A-Z0-9]{5,8})-([A-Z0-9]{4})(?![A-Z0-9])/', $t, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $x) {
                $out[$x[0]] = true;
                $t = str_replace($x[0], ' ', $t);
            }
        }
        if (preg_match_all('/(?<![A-Z0-9])' . $p . '[\s\-_]*((?:19|20)\d{2})[\s\-_]*(\d{4,5})(?![0-9])/', $t, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $x) {
                $out[(defined('PNR_PREFIX') ? (string) PNR_PREFIX : 'SHG') . '-' . $x[1] . '-' . $x[2]] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * The booking numbers one written PNR may stand for: SHG-2026-0842 and
     * SHG-2026-00842 are the same ticket (the system prints 5 digits).
     *
     * @return list<string>
     */
    public static function pnrCandidates(string $pnr): array
    {
        $pnr = strtoupper(trim($pnr));
        if (preg_match('/^([A-Z]+)-(\d{4})-(\d{4,5})$/', $pnr, $m) === 1) {
            $n = (int) $m[3];
            $c = [sprintf('%s-%s-%05d', $m[1], $m[2], $n)];
            if ($n < 10000) {
                $c[] = sprintf('%s-%s-%04d', $m[1], $m[2], $n);
            }
            return array_values(array_unique($c));
        }
        return $pnr !== '' ? [$pnr] : [];
    }

    /** @return list<array<string,mixed>> the bookings a written PNR names */
    public static function bookingsFor(string $pnr): array
    {
        $c = self::pnrCandidates($pnr);
        if ($c === []) {
            return [];
        }
        $ph = [];
        $pa = [];
        foreach ($c as $i => $v) {
            $ph[] = ':p' . $i;
            $pa['p' . $i] = $v;
        }
        return Database::fetchAll('SELECT * FROM bookings WHERE pnr IN (' . implode(',', $ph) . ') LIMIT 3', $pa);
    }

    /* =================================================================
     *  Money helpers
     * ================================================================= */

    /** INR still owed: total minus verified payments. */
    public static function dueInr(array $b): float
    {
        $paid = (float) Database::scalar("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = :b AND status = 'verified'",
            ['b' => (int) $b['id']], 0);
        return round(max(0.0, (float) ($b['total_amount'] ?? 0) - $paid), 2);
    }

    /**
     * The NPR a Nepali payer must send for $dueInr: the NPR frozen on the sale
     * (Ticket::nprOf - never today's rate) when the window took it in NPR, else
     * the INR due x npr_per_inr (the rate pay.php shows the same customer).
     * A peg changed after the QR was shown gives a different figure - that lands
     * in the review queue, never "fixed" with a tolerance.
     */
    public static function expectedNpr(array $b, float $dueInr): float
    {
        self::boot();
        if (class_exists('Ticket') && Ticket::frozenNpr($b) > 0) {
            return (float) Ticket::nprOf($b, $dueInr);
        }
        return (float) round($dueInr * Settings::getFloat('npr_per_inr', 1.6));
    }

    /** Gmail: the From address is on bankmail_from (addresses or @domains). */
    public static function senderAllowed(string $from): bool
    {
        $from = strtolower(trim($from));
        if ($from === '' || !str_contains($from, '@')) {
            return false;
        }
        foreach (explode(',', strtolower(Settings::getString('bankmail_from', ''))) as $a) {
            $a = trim($a);
            if ($a === '') {
                continue;
            }
            if ($a[0] === '@' ? str_ends_with($from, $a) : $from === $a) {
                return true;
            }
        }
        return false;
    }

    /** The last 4-6 digits of the company's own Global IME account (bankmail_account_hint); '' = not set. */
    public static function accountHint(): string
    {
        $d = (string) preg_replace('/\D/', '', Settings::getString('bankmail_account_hint', ''));
        return strlen($d) >= 4 ? substr($d, -6) : '';
    }

    /**
     * The credited account an alert names ('XXXXXXX789012', 'XXXX9012') ends with
     * our digits. At least 4 digits must be visible; with fewer visible than the
     * hint has, the visible ones are compared.
     */
    public static function accountMatches(string $acct, string $hint): bool
    {
        $hint = (string) preg_replace('/\D/', '', $hint);
        if (strlen($hint) < 4 || preg_match('/(\d+)$/', strtoupper(trim($acct)), $m) !== 1 || strlen($m[1]) < 4) {
            return false;
        }
        $n = min(strlen($m[1]), strlen($hint));
        return substr($m[1], -$n) === substr($hint, -$n);
    }

    /**
     * May a Gmail credit mark a ticket PAID with no person (pay_bankmail_autoconfirm_on)?
     * OFF by default (owner-safety, 25 Nov 2026): a full match is then only a suggestion.
     */
    public static function gmailAutoOn(): bool
    {
        return Settings::getBool('pay_bankmail_autoconfirm_on', false);
    }

    /**
     * A Gmail credit that passed every auto check while auto-PAID was off: state 'matched',
     * a booking, and nobody has acted on it yet (an auto-confirmed or linked credit always
     * carries acted_at). The booking is NOT paid - a person presses Confirm (link()).
     *
     * @param array<string,mixed>|null $c
     */
    public static function isSuggestion(?array $c): bool
    {
        return $c !== null && (string) ($c['state'] ?? '') === 'matched' && empty($c['acted_at']) && !empty($c['booking_id']);
    }

    /** payments.utr_number this credit is recorded under (an INR Gmail credit: INBANK-, not GIME-). */
    public static function proofRef(string $source, string $txn, string $currency = ''): string
    {
        $clean = preg_replace('/[^A-Za-z0-9\-\/]/', '', $txn) ?? '';
        $pre = $source === 'gmail' && strtoupper($currency) === 'INR' ? self::PREFIX_GMAIL_INR : (self::PREFIX[$source] ?? 'BANK-');
        return mb_substr($pre . $clean, 0, 60);
    }

    /** Long digit runs (account numbers) masked to their last 4 for the stored excerpt. */
    public static function redact(string $s, int $max = 1200): string
    {
        $s = self::text($s, 20000, true);
        $s = (string) preg_replace_callback('/\d{10,}/', static fn(array $m): string => str_repeat('X', strlen($m[0]) - 4) . substr($m[0], -4), $s);
        $s = (string) preg_replace('/[ \t]+/', ' ', $s);
        return mb_substr(trim($s), 0, $max);
    }

    /**
     * Text from a bank e-mail / webhook made safe for a utf8mb4 column under
     * STRICT_TRANS_TABLES: invalid bytes scrubbed (a raw 8-bit subject), control
     * characters gone, cut to $max characters.
     */
    public static function text(mixed $v, int $max, bool $lines = false): string
    {
        $s = mb_scrub(is_scalar($v) ? (string) $v : '', 'UTF-8');
        $s = (string) preg_replace($lines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]+/', $lines ? '' : ' ', $s);
        return mb_substr(trim($s), 0, $max);
    }

    /** An amount that fits the ledger: 0 for anything not a positive figure below MAX_AMOUNT. */
    public static function cleanAmount(mixed $v): float
    {
        $a = is_numeric($v) ? (float) $v : 0.0;
        return (is_finite($a) && $a > 0 && $a < self::MAX_AMOUNT) ? round($a, 2) : 0.0;
    }

    /**
     * The admin bell (notifications, audience admin) - best effort, never throws.
     * $tab = the Payment Management tab the bell opens ('np' | 'in').
     */
    public static function alert(string $title, string $body, ?int $bookingId = null, string $tab = 'np'): void
    {
        try {
            Database::insert('notifications', [
                'audience'   => 'admin',
                'booking_id' => $bookingId,
                'icon'       => '🏦',
                'title'      => Security::clean(self::text($title, 191), 191),
                'body'       => Security::clean(self::text($body, 500), 500),
                'link'       => '/admin/payment-center.php?tab=' . ($tab === 'in' ? 'in' : 'np'),
            ]);
        } catch (Throwable $e) {
            Logger::warning('PayCredits bell failed', ['err' => $e->getMessage()], 'payment');
        }
    }

    /* =================================================================
     *  The ledger
     * ================================================================= */

    /**
     * Store one credit. Idempotent for a REPLAY only: the same Message-ID (the
     * same e-mail), or - with no Message-ID (a webhook) - the same txn id with
     * the same amount and remark, returns the row already there and changes
     * nothing (lastOutcome() = 'replay').
     *
     * The same txn id on a different e-mail, or with another amount / remark, is
     * NOT a replay (a Gmail txn id is read from free text and may be the payer's
     * wallet or a date): it is stored without the txn id, 'unmatched', with a
     * note naming the earlier credit and an admin bell (lastOutcome() = 'conflict').
     *
     * @param array<string,mixed> $c source, currency, amount, txn_id, ref_text, pnr, sender, dkim_ok,
     *                               message_id, received_at, raw_excerpt, credit_account, state ('new'|'test'), note
     * @return int the row id (0 = the table is not there yet)
     */
    public static function record(array $c): int
    {
        self::$lastOutcome = '';
        if (!self::ready()) {
            return 0;
        }
        $source = (string) ($c['source'] ?? '');
        if (!in_array($source, ['gmail', 'bankapi'], true)) {
            throw new InvalidArgumentException('Unknown credit source.');
        }
        $currency = strtoupper((string) ($c['currency'] ?? ($source === 'gmail' ? 'NPR' : 'INR')));
        if (!in_array($currency, ['NPR', 'INR'], true)) {
            throw new InvalidArgumentException('Credit currency must be NPR or INR.');
        }
        $txn    = self::text($c['txn_id'] ?? '', 80);
        $msgId  = self::text($c['message_id'] ?? '', 255);
        $ref    = self::text($c['ref_text'] ?? '', 255);
        $amount = self::cleanAmount($c['amount'] ?? 0);
        $state  = (string) ($c['state'] ?? 'new') === 'test' ? 'test' : 'new';
        $note   = self::text($c['note'] ?? '', 255);

        /* the same e-mail again = a replay */
        if ($msgId !== '' && ($id = self::byMessageId($msgId)) > 0) {
            self::$lastOutcome = 'replay';
            return $id;
        }
        $seenOn = 0;
        if ($txn !== '') {
            $prev = Database::fetch('SELECT id, message_id, amount, ref_text FROM bank_credits WHERE source = :s AND txn_id = :t LIMIT 1',
                ['s' => $source, 't' => $txn]);
            if ($prev !== null) {
                $same = $msgId === '' && (string) ($prev['message_id'] ?? '') === ''
                    && abs((float) $prev['amount'] - $amount) < 0.005 && (string) ($prev['ref_text'] ?? '') === $ref;
                if ($same) {
                    self::$lastOutcome = 'replay';
                    return (int) $prev['id'];
                }
                $seenOn = (int) $prev['id'];
            }
        }
        $pnr = strtoupper(self::text($c['pnr'] ?? '', 40));
        if ($pnr === '') {
            $all = self::pnrsIn($ref);
            $pnr = count($all) === 1 ? $all[0] : '';
        }
        if ($seenOn > 0) {
            $note = mb_substr('Txn ' . mb_substr($txn, 0, 40) . ' already seen on credit #' . $seenOn
                . ' — a second payment or the same alert sent again? Check the bank statement.' . ($note !== '' ? ' · ' . $note : ''), 0, 255);
        }
        $ts = !empty($c['received_at']) ? strtotime((string) $c['received_at']) : false;
        if ($ts === false || $ts < 946684800 || $ts > time() + 7 * 86400) {
            $ts = time();   // a Date header from nowhere (or year 99999) never breaks the insert
        }
        $row = [
            'source'      => $source,
            'currency'    => $currency,
            'amount'      => $amount,
            'txn_id'      => $txn !== '' && $seenOn === 0 ? $txn : null,
            'ref_text'    => $ref !== '' ? $ref : null,
            'pnr'         => $pnr !== '' ? $pnr : null,
            'sender'      => self::text($c['sender'] ?? '', 190) ?: null,
            'dkim_ok'     => !empty($c['dkim_ok']) ? 1 : 0,
            'message_id'  => $msgId !== '' ? $msgId : null,
            'received_at' => date('Y-m-d H:i:s', $ts),
            'raw_excerpt' => self::redact((string) ($c['raw_excerpt'] ?? '')) ?: null,
            'state'       => $seenOn > 0 && $state !== 'test' ? 'unmatched' : $state,
            'note'        => $note !== '' ? $note : null,
            'created_at'  => date('Y-m-d H:i:s'),
        ];
        if (self::accountCol()) {
            $row['credit_account'] = self::text($c['credit_account'] ?? '', 40) ?: null;
        }
        if (self::mailCols() && (int) ($c['mail_account_id'] ?? 0) > 0) {
            $row['mail_account_id'] = (int) $c['mail_account_id'];   // the bank Gmail box (mp2): its rules apply
        }
        try {
            $id = (int) Database::insert('bank_credits', $row);
        } catch (Throwable $e) {
            $again = $msgId !== '' ? self::byMessageId($msgId) : 0;   // a racing poll stored the same e-mail first
            if ($again === 0 && $txn !== '' && $seenOn === 0) {
                $again = (int) Database::scalar('SELECT id FROM bank_credits WHERE source = :s AND txn_id = :t LIMIT 1', ['s' => $source, 't' => $txn], 0);
            }
            if ($again > 0) {
                self::$lastOutcome = 'replay';
                return $again;
            }
            throw $e;
        }
        self::$lastOutcome = $seenOn > 0 ? 'conflict' : 'new';
        if ($seenOn > 0) {
            Logger::warning('bank credit: txn id seen before', ['id' => $id, 'earlier' => $seenOn, 'source' => $source], 'payment');
            if ($state !== 'test') {
                self::alert('Bank credit needs a person · ' . $currency . ' ' . number_format($amount, 2),
                    (string) $row['note'] . ' (credit #' . $id . ', ' . ($source === 'gmail' ? 'Gmail' : 'bank API') . ').', null, $currency === 'INR' ? 'in' : 'np');
            }
        }
        return $id;
    }

    private static function byMessageId(string $msgId): int
    {
        return (int) Database::scalar('SELECT id FROM bank_credits WHERE message_id = :m LIMIT 1', ['m' => $msgId], 0);
    }

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        return self::ready() ? Database::fetch('SELECT * FROM bank_credits WHERE id = :i', ['i' => $id]) : null;
    }

    /**
     * Confirm the ticket this credit pays for, when (and only when) every rule
     * in the file header holds. A row that is not 'new' is left alone, so a
     * replayed e-mail / webhook is a no-op.
     *
     * @return array{ok:bool, state:string, why:string, bookingId:?int}
     */
    public static function tryAutoConfirm(int $id): array
    {
        $c = self::get($id);
        if ($c === null) {
            return ['ok' => false, 'state' => '', 'why' => 'no such credit', 'bookingId' => null];
        }
        if ((string) $c['state'] !== 'new') {
            return ['ok' => false, 'state' => (string) $c['state'], 'why' => 'already ' . $c['state'], 'bookingId' => $c['booking_id'] !== null ? (int) $c['booking_id'] : null];
        }
        // claim it: two polls racing on one credit - only one goes on
        $claimed = Database::query("UPDATE bank_credits SET state = 'unmatched', note = 'checking…' WHERE id = :i AND state = 'new'", ['i' => $id])->rowCount();
        if ($claimed !== 1) {
            return ['ok' => false, 'state' => 'unmatched', 'why' => 'busy', 'bookingId' => null];
        }
        self::boot();
        $source = (string) $c['source'];
        $txn    = trim((string) ($c['txn_id'] ?? ''));
        $amount = round((float) $c['amount'], 2);
        $cur    = (string) $c['currency'];

        /* 1. the source */
        $rules = $source === 'gmail' ? self::gmailRules($c) : null;
        if ($source === 'gmail') {
            /* pay_bankmail_autoconfirm_on is read at step 5: while it is off (the default) a credit that
               passes every check below is only a SUGGESTION for a person, never PAID.
               $rules = the box's own rules (mp2), or the old single-box settings for a credit without a box */
            if ($rules['off'] !== '') {
                return self::unmatched($id, $rules['off'], null);
            }
            if ((int) $c['dkim_ok'] !== 1) {
                return self::unmatched($id, 'No DKIM pass from the bank domain — could be a forged e-mail', null);
            }
            if (!($rules['sender'])((string) ($c['sender'] ?? ''))) {
                return self::unmatched($id, 'Sender ' . mb_substr((string) $c['sender'], 0, 80) . ' is not on the allowed list', null);
            }
            if ($cur !== $rules['currency']) {
                return self::unmatched($id, 'A Gmail bank alert ' . ($rules['acct'] !== null ? 'from ' . $rules['acct']['email'] . ' ' : '') . 'must be ' . $rules['currency'], null);
            }
            if (!$rules['ready']) {
                return self::unmatched($id, 'Bank Gmail box setup incomplete (DKIM domain / our account digits) — link it by hand', null);
            }
            /* a genuine, DKIM-signed alert for money paid into SOMEONE ELSE's account can be re-sent
               to our box unchanged: the money must have come to OUR account */
            $hint = $rules['hint'];
            if ($hint === '') {
                return self::unmatched($id, $rules['noHint'], null);
            }
            $acct = self::accountCol() ? trim((string) ($c['credit_account'] ?? '')) : '';
            if (!self::accountMatches($acct, $hint)) {
                return self::unmatched($id, match (true) {
                    !self::accountCol() => 'Credited account not stored (run the mp1-pay-credits migration again) — check the bank statement',
                    $acct === ''        => 'No credited account in the alert — cannot tell the money came to our account',
                    $acct === '?'       => 'Two different accounts in the alert — cannot tell which one was credited',
                    default             => 'Credit not to our account (alert says A/C …' . substr($acct, -4) . ')',
                }, null);
            }
        } else {
            if (!PayAuto::on() || !Settings::getBool('bankapi_on', false) || Settings::getString('bankapi_mode', 'sandbox') !== 'live') {
                return self::unmatched($id, 'Bank API is off or in sandbox — link it by hand', null);
            }
            if ((int) $c['dkim_ok'] !== 1) {
                return self::unmatched($id, 'Webhook signature was not verified', null);
            }
            if ($cur !== 'INR') {
                return self::unmatched($id, 'A bank API credit must be INR', null);
            }
        }
        /* 2. the transaction */
        if ($txn === '') {
            return self::unmatched($id, 'No transaction id in the credit', null);
        }
        if ($amount <= 0) {
            return self::unmatched($id, 'No amount in the credit', null);
        }
        $usedAgain = (int) Database::scalar("SELECT COUNT(*) FROM bank_credits WHERE txn_id = :t AND id <> :i AND state IN ('matched','linked')",
            ['t' => $txn, 'i' => $id], 0);
        if ($usedAgain > 0) {
            return self::unmatched($id, 'This transaction id already paid another ticket', null);
        }
        /* 3. the booking */
        $pnrs = self::pnrsIn((string) ($c['ref_text'] ?? ''));
        if (trim((string) ($c['pnr'] ?? '')) !== '') {
            $pnrs = array_values(array_unique(array_merge([strtoupper((string) $c['pnr'])], $pnrs)));
        }
        if ($pnrs === [] && $source === 'gmail' && self::mailCols()) {
            return self::amountOnly($id, $txn, $amount, $cur, (string) ($c['received_at'] ?? ''));   // mp2: no PNR - exactly one booking owing exactly this = a suggestion, never auto
        }
        if ($pnrs === []) {
            return self::unmatched($id, 'No PNR in the remarks', null);
        }
        if (count($pnrs) > 1) {
            return self::unmatched($id, 'More than one PNR in the remarks (' . implode(', ', array_slice($pnrs, 0, 3)) . ')', null);
        }
        $found = self::bookingsFor($pnrs[0]);
        if (count($found) !== 1) {
            return self::unmatched($id, $found === [] ? 'PNR ' . $pnrs[0] . ' not found' : 'PNR ' . $pnrs[0] . ' matches more than one booking', null);
        }
        $b   = $found[0];
        $bid = (int) $b['id'];
        if ((string) $b['status'] !== 'pending') {
            return self::unmatched($id, (string) $b['pnr'] . ' is ' . $b['status'] . ' (not waiting for payment) — check, refund by hand if paid twice', $bid);
        }
        if ((int) ($b['is_cod'] ?? 0) === 1) {
            return self::unmatched($id, (string) $b['pnr'] . ' is pay-at-boarding — settle it on Payments / Cash book', $bid);
        }
        $ref = self::proofRef($source, $txn, $cur);
        $elsewhere = (int) Database::scalar('SELECT COUNT(*) FROM payments WHERE (utr_number = :u OR utr_number = :t) AND booking_id <> :b',
            ['u' => $ref, 't' => $txn, 'b' => $bid], 0);
        if ($elsewhere > 0) {
            return self::unmatched($id, 'This transaction id is already on another booking', $bid);
        }
        /* 4. the amount, exactly */
        $due = self::dueInr($b);
        if ($due <= 0) {
            return self::unmatched($id, (string) $b['pnr'] . ': nothing is due', $bid);
        }
        $expected = $cur === 'NPR' ? self::expectedNpr($b, $due) : $due;
        if ($expected <= 0 || abs($amount - round($expected, 2)) > 0.004) {
            return self::unmatched($id, sprintf('Amount %s %s, but %s needs %s %s', $cur, number_format($amount, 2), (string) $b['pnr'], $cur, number_format($expected, 2)), $bid);
        }

        /* 5. Gmail with auto-PAID off (the default): every check passed - a suggestion for a person,
              the booking stays pending, nothing is recorded on it. A credit from a bank Gmail box (mp2)
              also needs bankmail_on for auto-PAID (PayAuto's own gate reads it): else a suggestion too. */
        if ($source === 'gmail' && (!self::gmailAutoOn() || ($rules['acct'] !== null && !Settings::getBool('bankmail_on', false)))) {
            return self::suggest($id, $b, $amount, $cur, $expected, 'pnr');
        }

        /* 6. record the proof, then the same gate a person uses */
        $tag = $source === 'gmail' ? ($cur === 'INR' ? 'Bank credit alert (Gmail)' : 'Global IME credit (Gmail)') : 'Bank UPI API';
        try {
            self::recordProof($b, $source, $txn, $ref, $due, $amount, $cur, $tag, 'auto');
        } catch (Throwable $e) {
            return self::unmatched($id, 'Could not record the proof: ' . mb_substr($e->getMessage(), 0, 120), $bid);
        }
        $auto = PayAuto::confirmFromBankCredit($bid, $source, $txn, $ref, $due, $cur, $amount);
        if (!$auto['ok']) {
            return self::unmatched($id, 'Proof is on ' . $b['pnr'] . ' (Verify Payments), not confirmed: ' . $auto['why'], $bid);
        }
        self::tick($bid, $source === 'gmail' ? self::gmailLabel($cur, 'auto') : self::LABEL_AUTO[$source], $due, $txn, PayAuto::systemAdmin() ?? ['id' => 0, 'role' => 'superadmin']);
        Database::update('bank_credits', [
            'state' => 'matched', 'booking_id' => $bid, 'pnr' => (string) $b['pnr'], 'acted_at' => date('Y-m-d H:i:s'),
            'note' => mb_substr('Auto-confirmed ' . $b['pnr'] . ' (' . $cur . ' ' . number_format($amount, 2) . ')', 0, 255),
        ] + (self::mailCols() ? ['match_kind' => 'pnr'] : []), 'id = :i', ['i' => $id]);
        return ['ok' => true, 'state' => 'matched', 'why' => 'confirmed', 'bookingId' => $bid];
    }

    /**
     * The bookings that still owe EXACTLY $amount in $cur (NPR = the NPR the customer had to send,
     * INR = the INR due) and were made in the AMOUNT_WINDOW_H hours BEFORE the money arrived
     * ($receivedTs; up to AMOUNT_SLACK_S after it for clock drift): pending, or confirmed with a
     * balance; never pay-at-boarding. A booking another credit already suggests is still in the
     * list (column 'suggested' = 1) so an ambiguous amount stays ambiguous. Newest first, at most
     * 500 looked at.
     *
     * @return list<array<string,mixed>> booking rows (+ owed, suggested)
     */
    public static function owingExactly(float $amount, string $cur, int $exceptCredit = 0, ?int $receivedTs = null): array
    {
        self::boot();
        if ($amount <= 0) {
            return [];
        }
        $at  = min($receivedTs ?? time(), time());
        $cod = class_exists('CounterDesk') && !CounterDesk::hasColumn('bookings', 'is_cod') ? '' : ' AND COALESCE(b.is_cod, 0) = 0';
        $rows = Database::fetchAll(
            "SELECT b.*, (b.total_amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.booking_id = b.id AND p.status = 'verified'), 0)) AS owed,
                    EXISTS (SELECT 1 FROM bank_credits s WHERE s.booking_id = b.id AND s.state = 'matched' AND s.acted_at IS NULL AND s.id <> :me) AS suggested
               FROM bookings b
              WHERE b.created_at >= :since AND b.created_at <= :until AND b.status IN ('pending','confirmed')$cod
             HAVING owed > 0.004
           ORDER BY b.id DESC LIMIT 500",
            ['since' => date('Y-m-d H:i:s', $at - self::AMOUNT_WINDOW_H * 3600),
             'until' => date('Y-m-d H:i:s', $at + self::AMOUNT_SLACK_S), 'me' => $exceptCredit]
        );
        $out = [];
        foreach ($rows as $b) {
            $due  = round((float) $b['owed'], 2);
            $want = $cur === 'NPR' ? self::expectedNpr($b, $due) : $due;
            if ($want > 0 && abs($amount - round($want, 2)) <= 0.004) {
                $out[] = $b;
            }
        }
        return $out;
    }

    /**
     * A Gmail credit with NO PNR (mp2): exactly one booking owing exactly this amount = a SUGGESTION
     * (match_kind 'amount', never auto - not even with pay_bankmail_autoconfirm_on); none or several =
     * unmatched (several: up to 5 PNRs in the note, for the person to choose).
     *
     * @return array{ok:bool, state:string, why:string, bookingId:?int}
     */
    private static function amountOnly(int $id, string $txn, float $amount, string $cur, string $receivedAt = ''): array
    {
        $amt = $cur . ' ' . number_format($amount, 2);
        $ts  = $receivedAt !== '' ? strtotime($receivedAt) : false;
        $ts  = $ts === false ? time() : $ts;
        if ($ts < time() - self::AMOUNT_WINDOW_H * 3600) {
            /* an old alert read late (new box, resumed box): money that came before today's bookings
               must never be offered for them on the amount alone */
            return self::unmatched($id, 'पुरानो alert, PNR छैन · old alert (' . date('Y-m-d H:i', $ts) . '), no PNR — link it by hand', null);
        }
        $cands = self::owingExactly($amount, $cur, $id, $ts);
        if (count($cands) === 1 && (int) ($cands[0]['suggested'] ?? 0) === 1) {
            return self::unmatched($id, mb_substr('रकम मिल्ने टिकट ' . (string) $cands[0]['pnr'] . ' मा पहिले नै सुझाव छ · '
                . (string) $cands[0]['pnr'] . ' already has a suggestion (' . $amt . ') — second payment? link it by hand', 0, 255), null);
        }
        if ($cands === []) {
            return self::unmatched($id, 'No PNR in the alert, and no booking from the last ' . self::AMOUNT_WINDOW_H . ' h owes exactly ' . $amt . ' — link it by hand', null);
        }
        if (count($cands) > 1) {
            $list = implode(', ', array_slice(array_map(static fn(array $b): string => (string) $b['pnr'], $cands), 0, 5));
            return self::unmatched($id, mb_substr('रकम मिल्ने धेरै टिकट · several bookings match the amount (' . $amt . '): ' . $list
                . (count($cands) > 5 ? ' +' . (count($cands) - 5) : '') . ' — नाम / फोन हेरेर Link to PNR', 0, 255), null);
        }
        $b   = $cands[0];
        $bid = (int) $b['id'];
        $ref = self::proofRef('gmail', $txn, $cur);
        if ((int) Database::scalar('SELECT COUNT(*) FROM payments WHERE (utr_number = :u OR utr_number = :t) AND booking_id <> :b',
                ['u' => $ref, 't' => $txn, 'b' => $bid], 0) > 0) {
            return self::unmatched($id, 'This transaction id is already on another booking', $bid);
        }
        $due = round((float) $b['owed'], 2);
        return self::suggest($id, $b, $amount, $cur, $cur === 'NPR' ? self::expectedNpr($b, $due) : $due, 'amount');
    }

    /**
     * Keep a 'new' credit for a person WITHOUT trying to confirm anything: an
     * ambiguous bank alert (BankMail: two different amounts, two different txn
     * ids, credit and debit words). Same end as a failed auto-confirm: unmatched,
     * the reason as its note, an admin bell. Any other state is left alone.
     *
     * @return array{ok:bool, state:string, why:string, bookingId:?int}
     */
    public static function hold(int $id, string $why): array
    {
        $c = self::get($id);
        if ($c === null) {
            return ['ok' => false, 'state' => '', 'why' => 'no such credit', 'bookingId' => null];
        }
        if ((string) $c['state'] !== 'new') {
            return ['ok' => false, 'state' => (string) $c['state'], 'why' => 'already ' . $c['state'], 'bookingId' => $c['booking_id'] !== null ? (int) $c['booking_id'] : null];
        }
        $claimed = Database::query("UPDATE bank_credits SET state = 'unmatched', note = 'checking…' WHERE id = :i AND state = 'new'", ['i' => $id])->rowCount();
        if ($claimed !== 1) {
            return ['ok' => false, 'state' => 'unmatched', 'why' => 'busy', 'bookingId' => null];
        }
        return self::unmatched($id, $why !== '' ? $why : 'Kept for a person', null);
    }

    /**
     * A person links a credit to a PNR ("Link to PNR"), or confirms a Gmail
     * SUGGESTION ("✅ Confirm" - the same call). The amount must still match
     * exactly, and approveReviewed() runs via 'queue', so PaymentRisk can still
     * ask for the bank-statement tick, a reason or a second person.
     *
     * @return array{ok:bool, msg:string, bookingId:?int}
     */
    public static function link(int $id, string $pnr, int $adminId, bool $bankChecked = true, string $note = ''): array
    {
        $c = self::get($id);
        if ($c === null) {
            throw new RuntimeException('Credit not found.');
        }
        $suggested = self::isSuggestion($c);
        if (!$suggested && in_array((string) $c['state'], ['matched', 'linked'], true)) {
            throw new RuntimeException('This credit already paid ' . ($c['pnr'] ?? 'a ticket') . '.');
        }
        if ((string) $c['state'] === 'test') {
            throw new RuntimeException('A sandbox test credit is not real money — it cannot pay a ticket.');
        }
        self::boot();
        $admin = Database::fetch('SELECT id, role, full_name, username FROM admins WHERE id = :i AND is_active = 1', ['i' => $adminId]);
        if ($admin === null) {
            throw new RuntimeException('Unknown staff account.');
        }
        $who = mb_substr(trim((string) (($admin['full_name'] ?? '') ?: ($admin['username'] ?? ''))) ?: '#' . (int) $admin['id'], 0, 60);
        $source = (string) $c['source'];
        $txn    = trim((string) ($c['txn_id'] ?? ''));
        $amount = round((float) $c['amount'], 2);
        $cur    = (string) $c['currency'];
        if ($txn === '' || $amount <= 0) {
            throw new RuntimeException('This credit has no own transaction id or no amount — check the bank statement, then type the bank reference on the booking page (payment proof) and approve it on Verify Payments.');
        }
        $found = self::bookingsFor(Security::clean($pnr, 40));
        if (count($found) !== 1) {
            throw new RuntimeException($found === [] ? 'PNR not found.' : 'That PNR matches more than one booking.');
        }
        $b   = $found[0];
        $bid = (int) $b['id'];
        if ((string) $b['status'] !== 'pending') {
            throw new RuntimeException((string) $b['pnr'] . ' is ' . $b['status'] . ' — only a booking waiting for payment can be linked.');
        }
        if ((int) ($b['is_cod'] ?? 0) === 1) {
            throw new RuntimeException((string) $b['pnr'] . ' is pay-at-boarding — settle it on Payments / Cash book.');
        }
        $ref = self::proofRef($source, $txn, $cur);
        if ((int) Database::scalar('SELECT COUNT(*) FROM payments WHERE (utr_number = :u OR utr_number = :t) AND booking_id <> :b',
                ['u' => $ref, 't' => $txn, 'b' => $bid], 0) > 0
            || (int) Database::scalar("SELECT COUNT(*) FROM bank_credits WHERE txn_id = :t AND id <> :i AND state IN ('matched','linked')", ['t' => $txn, 'i' => $id], 0) > 0) {
            throw new RuntimeException('This transaction id is already on another booking.');
        }
        $due = self::dueInr($b);
        $expected = $cur === 'NPR' ? self::expectedNpr($b, $due) : $due;
        if ($due <= 0 || abs($amount - round($expected, 2)) > 0.004) {
            throw new RuntimeException(sprintf('Amount does not match: credit %s %s, %s needs %s %s.', $cur, number_format($amount, 2), (string) $b['pnr'], $cur, number_format($expected, 2)));
        }
        $byAmount = $suggested && self::mailCols() && (string) ($c['match_kind'] ?? '') === 'amount';
        $bank = $cur === 'INR' ? 'Bank credit alert' : 'Global IME credit';
        $tag = $source === 'gmail' ? ($suggested ? $bank . ' (Gmail, confirmed' . ($byAmount ? ', amount-only' : '') . ')' : $bank . ' (Gmail, linked)') : 'Bank UPI API (linked)';
        if (!Database::exists('SELECT 1 FROM payments WHERE booking_id = :b AND utr_number = :u', ['b' => $bid, 'u' => $ref])) {
            self::recordProof($b, $source, $txn, $ref, $due, $amount, $cur, $tag, $suggested ? 'confirm' : 'link');
        }
        $note = Security::clean($note, 120);
        Database::update('bank_credits', ['booking_id' => $bid, 'pnr' => (string) $b['pnr']], 'id = :i', ['i' => $id]);
        try {
            $r = BookingService::approveReviewed($bid, ['id' => (int) $admin['id'], 'role' => (string) $admin['role']], [
                'via' => 'queue', 'bankChecked' => $bankChecked, 'amountSeen' => $due,
                'note' => mb_substr('Bank credit #' . $id . ($suggested ? ' (Gmail suggestion' . ($byAmount ? ', amount-only' : '') . ') confirmed' : ' linked') . ': txn ' . mb_substr($txn, -14) . ', ' . $cur . ' ' . number_format($amount, 2) . ($note !== '' ? ' · ' . $note : ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            Database::update('bank_credits', ['note' => mb_substr('Proof on ' . $b['pnr'] . '; not approved: ' . $e->getMessage(), 0, 255)], 'id = :i', ['i' => $id]);
            throw new RuntimeException($e->getMessage());
        }
        if (($r['state'] ?? '') !== 'confirmed') {
            Database::update('bank_credits', ['note' => 'First approval saved on ' . $b['pnr'] . '; a second person approves on Verify Payments'], 'id = :i', ['i' => $id]);
            return ['ok' => false, 'msg' => (string) $b['pnr'] . ': first approval saved — a manager / accountant gives the second on Verify Payments.', 'bookingId' => $bid];
        }
        $label = $source === 'gmail' ? self::gmailLabel($cur, $suggested ? 'confirmed' : 'linked')
            : ($suggested ? (self::LABEL_CONFIRMED[$source] ?? self::LABEL_LINKED[$source]) : self::LABEL_LINKED[$source]);
        self::tick($bid, $label, $due, $txn, ['id' => (int) $admin['id'], 'role' => (string) $admin['role']], $suggested ? 'confirmed by ' . $who : '');
        Database::update('bank_credits', [
            'state' => 'linked', 'booking_id' => $bid, 'pnr' => (string) $b['pnr'], 'acted_by' => (int) $admin['id'], 'acted_at' => date('Y-m-d H:i:s'),
            'note' => mb_substr(($suggested ? 'Confirmed ' . $b['pnr'] . ' (Gmail suggestion) by ' . $who : 'Linked to ' . $b['pnr'] . ' by #' . (int) $admin['id']) . ($note !== '' ? ' · ' . $note : ''), 0, 255),
        ], 'id = :i', ['i' => $id]);
        Logger::audit('bank_credit.link', 'bank_credit', (string) $id, ['state' => $suggested ? 'suggested' : (string) $c['state']],
            ['state' => 'linked', 'pnr' => (string) $b['pnr'], 'txn' => mb_substr($txn, -14), 'amount' => $amount, 'currency' => $cur],
            $suggested ? 'Gmail suggestion confirmed by a person (bank app checked)' : 'Bank credit linked to a PNR by hand');
        return ['ok' => true, 'msg' => (string) $b['pnr'] . ' confirmed — ticket sent.', 'bookingId' => $bid];
    }

    /**
     * A person sets a credit aside (not ours, a refund, a test, a Gmail suggestion the
     * bank app does not show). Never for a credit that paid a ticket.
     */
    public static function ignore(int $id, int $adminId, string $note): void
    {
        $c = self::get($id);
        if ($c === null) {
            throw new RuntimeException('Credit not found.');
        }
        if (!self::isSuggestion($c) && in_array((string) $c['state'], ['matched', 'linked'], true)) {
            throw new RuntimeException('This credit already paid a ticket — it cannot be ignored.');
        }
        $note = mb_substr(Security::clean($note, 200), 0, 200);
        Database::update('bank_credits', [
            'state' => 'ignored', 'acted_by' => $adminId, 'acted_at' => date('Y-m-d H:i:s'),
            'note' => 'Ignored' . ($note !== '' ? ': ' . $note : ''),
        ], 'id = :i', ['i' => $id]);
        Logger::audit('bank_credit.ignore', 'bank_credit', (string) $id, ['state' => (string) $c['state']], ['state' => 'ignored'], $note !== '' ? $note : 'Ignored');
    }

    /**
     * The newest credits for the admin page.
     *
     * @param array<string,mixed> $f source, currency (NPR | INR: every source and box in it), state ('open' =
     *                            new + unmatched + suggestions, 'suggested' = suggestions only), limit
     * @return list<array<string,mixed>> (+ mail_email: the bank Gmail box, when the mp2 migration ran)
     */
    public static function listRecent(array $f = []): array
    {
        if (!self::ready()) {
            return [];
        }
        $w = [];
        $p = [];
        if (in_array((string) ($f['source'] ?? ''), ['gmail', 'bankapi'], true)) {
            $w[] = 'c.source = :s';
            $p['s'] = (string) $f['source'];
        }
        if (in_array((string) ($f['currency'] ?? ''), ['NPR', 'INR'], true)) {
            $w[] = 'c.currency = :cur';
            $p['cur'] = (string) $f['currency'];
        }
        $boxes = self::mailCols() && Database::fetch("SHOW TABLES LIKE 'bank_mail_accounts'") !== null;
        $st = (string) ($f['state'] ?? '');
        $sug = "(c.state = 'matched' AND c.acted_at IS NULL AND c.booking_id IS NOT NULL)";   // isSuggestion()
        if ($st === 'open') {
            $w[] = "(c.state IN ('new','unmatched') OR $sug)";
        } elseif ($st === 'suggested') {
            $w[] = $sug;
        } elseif (in_array($st, ['new', 'matched', 'unmatched', 'linked', 'ignored', 'test'], true)) {
            $w[] = 'c.state = :st';
            $p['st'] = $st;
        }
        $lim = max(1, min(500, (int) ($f['limit'] ?? 50)));
        return Database::fetchAll(
            'SELECT c.*, b.pnr AS booking_pnr, a.full_name AS acted_name' . ($boxes ? ', m.email AS mail_email' : '') . '
               FROM bank_credits c
          LEFT JOIN bookings b ON b.id = c.booking_id
          LEFT JOIN admins a ON a.id = c.acted_by'
            . ($boxes ? ' LEFT JOIN bank_mail_accounts m ON m.id = c.mail_account_id' : '')
            . ($w !== [] ? ' WHERE ' . implode(' AND ', $w) : '') .
            " ORDER BY c.id DESC LIMIT $lim",
            $p
        );
    }

    /** @return array<string,int> state => count for one source ('suggested' counted apart from 'matched') */
    public static function counts(string $source): array
    {
        if (!self::ready()) {
            return [];
        }
        $out = [];
        foreach (Database::fetchAll("SELECT CASE WHEN state = 'matched' AND acted_at IS NULL AND booking_id IS NOT NULL THEN 'suggested' ELSE state END AS st, COUNT(*) AS n
                                       FROM bank_credits WHERE source = :s GROUP BY st", ['s' => $source]) as $r) {
            $out[(string) $r['st']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * What the Payment Management page shows beside a Gmail SUGGESTION: the booking,
     * its lead passenger, what is due (INR + the NPR the customer had to send) next to
     * what the bank alert says arrived, and the checks re-read now (DKIM, our account,
     * allowed sender) - a ✗ means the Confirm button is not offered.
     *
     * @param array<string,mixed> $c a bank_credits row
     * @return array{pnr:string, status:string, name:string, due_inr:float, due_npr:float, credited:float, currency:string,
     *               amount_ok:bool, checks:list<array{0:string,1:bool}>, can_confirm:bool}|null null = not a suggestion / no booking
     */
    public static function suggestionView(array $c): ?array
    {
        if (!self::isSuggestion($c)) {
            return null;
        }
        $b = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $c['booking_id']]);
        if ($b === null) {
            return null;
        }
        $name = '';
        $more = 0;
        try {
            $names = Database::fetchAll('SELECT DISTINCT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY is_primary DESC, id LIMIT 20', ['b' => (int) $b['id']]);
            $name  = trim((string) ($names[0]['full_name'] ?? ''));
            $more  = max(0, count($names) - 1);
        } catch (Throwable $e) {
            // no passenger table on a bare database
        }
        $cur  = (string) $c['currency'];
        $paid = round((float) $c['amount'], 2);
        $due  = self::dueInr($b);
        $npr  = $cur === 'NPR' ? self::expectedNpr($b, $due) : 0.0;
        $want = $cur === 'NPR' ? $npr : $due;
        $amountOk = $due > 0 && $want > 0 && abs($paid - round($want, 2)) <= 0.004;
        /* the box's own rules (mp2), or the old single-box settings for a credit without a box */
        $rules = (string) ($c['source'] ?? '') === 'gmail' ? self::gmailRules($c)
            : ['hint' => self::accountHint(), 'sender' => static fn(string $f): bool => self::senderAllowed($f), 'acct' => null, 'off' => ''];
        $hint = (string) $rules['hint'];
        $checks = [
            ['DKIM', (int) $c['dkim_ok'] === 1],
            ['A/C …' . ($hint !== '' ? substr($hint, -4) : '?'), $hint !== '' && self::accountMatches(trim((string) ($c['credit_account'] ?? '')), $hint)],
            ['Sender', ($rules['sender'])((string) ($c['sender'] ?? ''))],
        ];
        if ($rules['off'] !== '') {
            $checks[] = ['Gmail box on', false];
        }
        $allOk = !in_array(false, array_column($checks, 1), true);
        $kind = self::mailCols() ? (string) ($c['match_kind'] ?? '') : '';
        return [
            'pnr' => (string) $b['pnr'], 'status' => (string) $b['status'], 'name' => $name . ($more > 0 ? ' +' . $more : ''),
            'due_inr' => $due, 'due_npr' => $npr, 'credited' => $paid, 'currency' => $cur, 'amount_ok' => $amountOk,
            'checks' => $checks, 'can_confirm' => $allOk && $amountOk && (string) $b['status'] === 'pending' && (int) ($b['is_cod'] ?? 0) !== 1,
            'kind' => $kind !== '' ? $kind : 'pnr',
            'phone' => (string) ($b['contact_phone'] ?? ''),
        ];
    }

    /* =================================================================
     *  internals
     * ================================================================= */

    /** The proof on the booking (the same path a typed UTR / ICICI takes) + where it came from. */
    private static function recordProof(array $b, string $source, string $txn, string $ref, float $due, float $amount, string $cur, string $tag, string $how): void
    {
        BookingService::submitPaymentProof((string) $b['pnr'], [
            'utr'           => $ref,
            'payerName'     => $tag,
            'method'        => $source === 'gmail' ? 'bank' : 'upi',
            'claimedAmount' => $due,
        ]);
        $upd = [
            'match_hint' => mb_substr(($source === 'gmail' ? 'bankmail_' : 'upiapi_') . $how, 0, 20),
            'match_note' => mb_substr($tag . ' · txn ' . $txn . ' · ' . $cur . ' ' . number_format($amount, 2), 0, 255),
        ];
        if ($cur === 'NPR' && class_exists('CounterDesk') && CounterDesk::hasColumn('payments', 'local_amount')) {
            $upd['local_currency'] = 'NPR';
            $upd['local_amount']   = $amount;
        }
        try {
            Database::update('payments', $upd, 'booking_id = :b AND utr_number = :u', ['b' => (int) $b['id'], 'u' => $ref]);
        } catch (Throwable $e) {
            Logger::warning('PayCredits: payment tag failed', ['pnr' => $b['pnr'] ?? '', 'err' => $e->getMessage()], 'payment');
        }
    }

    /** The chalani tick (best effort: the ticket is already confirmed). $label ≤ 40 characters (OpsHub::markPaid). */
    private static function tick(int $bookingId, string $label, float $due, string $txn, array $admin, string $extra = ''): void
    {
        try {
            if (!class_exists('OpsHub')) {
                require_once __DIR__ . '/opshub.php';
            }
            OpsHub::markPaid($bookingId, $label, $due, mb_substr('UTR ' . mb_substr($txn, 0, 60) . ($extra !== '' ? ' · ' . $extra : ''), 0, 160), $admin);
        } catch (Throwable $e) {
            Logger::warning('PayCredits: chalani tick failed', ['booking' => $bookingId, 'err' => $e->getMessage()], 'payment');
        }
    }

    /**
     * A Gmail credit that passed every auto check while auto-PAID is off: kept as a
     * SUGGESTION (state 'matched', booking_id, acted_at NULL - isSuggestion()) with
     * a bell asking a person to check the bank app and press Confirm. The booking is
     * left exactly as it was (pending, no proof recorded).
     *
     * @param array<string,mixed> $b the one matching booking
     * @return array{ok:bool, state:string, why:string, bookingId:?int}
     */
    private static function suggest(int $id, array $b, float $amount, string $cur, float $expected, string $kind = 'pnr'): array
    {
        $bid = (int) $b['id'];
        $pnr = (string) $b['pnr'];
        $amt = number_format($amount, 2);
        $byAmount = $kind === 'amount';
        Database::update('bank_credits', [
            'state' => 'matched', 'booking_id' => $bid, 'pnr' => $pnr, 'acted_by' => null, 'acted_at' => null,
            'note' => mb_substr(self::SUGGEST_NOTE . $pnr . ' ' . $cur . ' ' . $amt . ($byAmount ? ' · ' . self::AMOUNT_NOTE : ''), 0, 255),
        ] + (self::mailCols() ? ['match_kind' => $byAmount ? 'amount' : 'pnr'] : []), 'id = :i', ['i' => $id]);
        self::alert('बैंक इमेल: ' . ($cur === 'INR' ? '₹' : 'रू') . ' ' . $amt . ' आयो · PNR ' . $pnr . ($byAmount ? ' (रकम मात्र मिल्यो)' : '') . ' — Payment Management मा Confirm थिच्नुहोस्',
            'Gmail credit #' . $id . ': ' . $cur . ' ' . $amt . ' for ' . $pnr . ' (due ' . $cur . ' ' . number_format($expected, 2) . '). '
            . ($byAmount
                ? 'The alert has NO PNR: only the amount matches this one booking (last ' . self::AMOUNT_WINDOW_H . ' h) — नाम / फोन र बैंक app हेरेर मात्र Confirm थिच्नुहोस्।'
                : 'DKIM, sender, our account, PNR and amount all match, but Gmail auto-PAID is off: बैंक app मा पैसा आएको हेरेर Confirm थिच्नुहोस्।'),
            $bid, $cur === 'INR' ? 'in' : 'np');
        Logger::info('bank credit suggested', ['id' => $id, 'pnr' => $pnr, 'amount' => $amount, 'kind' => $kind], 'payment');
        return ['ok' => false, 'state' => 'matched', 'why' => 'suggested', 'bookingId' => $bid, 'kind' => $byAmount ? 'amount' : 'pnr'];
    }

    /** @return array{ok:bool, state:string, why:string, bookingId:?int} */
    private static function unmatched(int $id, string $why, ?int $bookingId, bool $bell = true): array
    {
        $c = self::get($id) ?? [];
        Database::update('bank_credits', ['state' => 'unmatched', 'note' => mb_substr($why, 0, 255)]
            + ($bookingId !== null ? ['booking_id' => $bookingId] : []), 'id = :i', ['i' => $id]);
        if ($bell) {
            $amt = (string) ($c['currency'] ?? '') . ' ' . number_format((float) ($c['amount'] ?? 0), 2);
            self::alert('Bank credit needs a person · ' . $amt,
                $why . ' (txn …' . substr((string) ($c['txn_id'] ?? ''), -6) . ', ' . (($c['source'] ?? '') === 'gmail' ? 'Gmail' : 'bank API') . ').',
                $bookingId, (($c['source'] ?? '') === 'gmail' && ($c['currency'] ?? 'NPR') !== 'INR') ? 'np' : 'in');
        }
        Logger::info('bank credit unmatched', ['id' => $id, 'why' => $why], 'payment');
        return ['ok' => false, 'state' => 'unmatched', 'why' => $why, 'bookingId' => $bookingId];
    }
}
