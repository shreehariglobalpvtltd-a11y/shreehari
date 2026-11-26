<?php
/**
 * =====================================================================
 *  includes/watplmeta.php — the Meta template registry (19 Nov 2026, WA-2).
 *
 *  Before this file the templates in the owner's WABA were posted by hand
 *  and nothing in the code knew which one existed, which was approved, or
 *  which purpose should use which. Now:
 *
 *    DEFS          the small UTILITY templates this site may ask Meta for
 *                  (language en, single-line variables, an example for every
 *                  variable, body under 550 characters, a clear purpose);
 *    KNOWN         the 7 templates already in the WABA (posted by hand on
 *                  18 Nov) - known here, never submitted again;
 *    sync()        GET /{waba}/message_templates -> each status into
 *                  kv_store (scope wa_tpl_meta, key status.<name>);
 *    submitMissing POST only the DEFS names that the WABA does not have.
 *                  Idempotent. Submitting is free; it is still office-only:
 *                  tools/wa-meta-templates.php --submit, the superadmin
 *                  button on admin/wa-templates.php, or the retry cron when
 *                  wa_tpl_auto_submit = 1 (default 0);
 *    ready()       APPROVED, switched on (wa_tpl_meta_on) and not refused
 *                  by Meta in the last wa_tpl_refused_hours (132001 "does
 *                  not exist in en" right after approval - 18 Nov);
 *    forPurpose()  purpose -> [name, lang, vars, header, buttons] for a
 *                  READY template only (else null - the caller keeps its
 *                  free-text / queue path). Sending stays under
 *                  wa_paid_daily_limit (WaRoute::metaTemplate).
 *
 *  Nothing here sends a message. Never throws to its callers.
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaTplMeta
{
    public const SCOPE     = 'wa_tpl_meta';
    public const LANG      = 'en';
    public const TICKET_V2 = 'shg_ticket_v2';

    /** Templates whose body is the 6-variable ticket shape (name, from, to, date, seat, PNR) + the PNG header. */
    public const TICKET_IMAGE_SHAPE = ['shg_ticket_image', 'shg_ticket_v2'];

    /** Meta codes that mean "this template cannot be used right now" (not the message, not the number). */
    public const REFUSAL_CODES = [132000, 132001, 132015, 132016];

    /**
     * Already in WABA 1135278652802533 (posted by hand, 18 Nov 2026): known,
     * never re-submitted. 'vars' only where this code sends them itself.
     */
    public const KNOWN = [
        'shg_ticket_image'      => ['purpose' => 'ticket', 'header' => 'IMAGE', 'vars' => ['name', 'from', 'to', 'date', 'seat', 'pnr']],
        'shg_booking_cancelled' => ['purpose' => 'booking_cancelled', 'header' => '', 'vars' => []],
        'shg_payment_rejected'  => ['purpose' => 'payment_rejected', 'header' => '', 'vars' => []],
        'shg_payment_received'  => ['purpose' => 'payment_received', 'header' => '', 'vars' => []],
        'shg_booking_received'  => ['purpose' => 'booking_received', 'header' => '', 'vars' => ['pnr', 'route', 'date', 'amount']],
        'shg_booking_start'     => ['purpose' => 'booking_start', 'header' => '', 'vars' => []],
        'shg_office_alert'      => ['purpose' => 'office_alert', 'header' => '', 'vars' => ['label', 'text']],
    ];

    /**
     * The templates this site may submit. {BASE} becomes the site URL
     * (appUrl). Every body: no variable at its start or end, never two
     * variables side by side, no newline / tab inside a variable.
     */
    public const DEFS = [
        'shg_ticket_v2' => [
            'title'    => 'Ticket (duplicate of shg_ticket_image)',
            'purpose'  => ['ticket', 'ticket_resend', 'seller_copy'],
            'header'   => 'IMAGE',
            'vars'     => ['name', 'from', 'to', 'date', 'seat', 'pnr'],
            'examples' => ['Ram Bahadur', 'Surat', 'Rupaidiha', '20 Nov 2026', 'L3, L4', 'SHG-2026-00584'],
            'body'     => "Namaste {{1}}, your S Hari Global bus ticket is confirmed.\nFrom: {{2}}\nTo: {{3}}\nDate: {{4}}\nSeat: {{5}}\nPNR: {{6}}\nYour ticket is the picture above. Please show it when boarding and carry a photo ID.",
            'footer'   => 'S Hari Global',
        ],
        'shg_pay_request' => [
            'title'    => 'Payment request (seat held)',
            'purpose'  => ['pay'],
            'header'   => '',
            'vars'     => ['amount', 'seat', 'date'],
            'examples' => ['₹2,800', 'L3, L4', '20 Nov 2026'],
            'body'     => "Your seat booking is received. Amount to pay: {{1}}\nSeat: {{2}}\nTravel date: {{3}}\nTap Pay now to pay by UPI, then send the UTR number or a screenshot here. The office issues your ticket after checking the payment.",
            'footer'   => 'S Hari Global',
            'button'   => ['type' => 'URL', 'text' => 'Pay now', 'url' => '{BASE}/pay.php?pnr={{1}}', 'var' => 'pnr', 'example' => 'SHG-2026-00584'],
        ],
        'shg_trip_reminder' => [
            'title'    => 'Trip reminder (24 h / 2 h)',
            'purpose'  => ['reminder', 'reminder_24h', 'reminder_12h', 'reminder_2h'],
            'header'   => '',
            'vars'     => ['name', 'route', 'date', 'time', 'boarding'],
            'examples' => ['Ram Bahadur', 'Surat to Rupaidiha', '20 Nov 2026', '1:00 PM', 'Surat Kamrej Char Rasta'],
            'body'     => "Namaste {{1}}, this is a reminder of your bus journey.\nRoute: {{2}}\nDate: {{3}}\nTime: {{4}}\nBoarding point: {{5}}\nPlease reach the boarding point 15 minutes early with your ticket and a photo ID.",
            'footer'   => 'S Hari Global',
        ],
        'shg_bus_live' => [
            'title'    => 'Bus on the way (live tracking link)',
            'purpose'  => ['bus_live', 'departed', 'bus_near'],
            'header'   => '',
            'vars'     => ['name', 'route'],
            'examples' => ['Ram Bahadur', 'Surat to Rupaidiha'],
            'body'     => "Namaste {{1}}, your bus for {{2}} is on the way. Tap Track bus to see where it is right now.",
            'footer'   => 'S Hari Global',
            'button'   => ['type' => 'URL', 'text' => 'Track bus', 'url' => '{BASE}/{{1}}', 'var' => 'track_path', 'example' => 'track.php?pnr=SHG-2026-00584&k=0a1b2c3d4e5f6a7b8c9d'],
        ],
        'shg_ticket_changed' => [
            'title'    => 'Ticket changed (new ticket picture)',
            'purpose'  => ['ticket_update'],
            'header'   => 'IMAGE',
            'vars'     => ['name', 'pnr', 'change', 'date', 'seat'],
            'examples' => ['Ram Bahadur', 'SHG-2026-00584', 'travel date moved', '21 Nov 2026', 'L3, L4'],
            'body'     => "Namaste {{1}}, your ticket {{2}} has been updated.\nChange: {{3}}\nDate: {{4}}\nSeat: {{5}}\nThe new ticket is the picture above. Please use this one when boarding.",
            'footer'   => 'S Hari Global',
        ],
        'shg_agent_statement' => [
            'title'    => 'Agent statement (PDF)',
            'purpose'  => ['agent_statement'],
            'header'   => 'DOCUMENT',
            'vars'     => ['period', 'tickets', 'passengers', 'collected', 'commission', 'balance'],
            /* {{6}} carries BOTH wallet balances (commission due to the agent and
               the company cash the agent still holds) - never the commission
               wallet alone as "the balance" (review 19 Nov). Body var '7' is
               WaSend's header slot, so they share one variable. */
            'examples' => ['1 Oct 2026 to 31 Oct 2026', '42', '57', '₹1,18,400', '₹11,400', 'commission due to you ₹3,200 · company cash you hold ₹40,000'],
            'body'     => "Your account statement from S Hari Global.\nPeriod: {{1}}\nTickets: {{2}}\nPassengers: {{3}}\nCollected: {{4}}\nCommission: {{5}}\nWallet balances: {{6}}\nThe full statement is the PDF above. Reply here with any question.",
            'footer'   => 'S Hari Global',
        ],
        'shg_withdraw_update' => [
            'title'    => 'Withdrawal request update',
            'purpose'  => ['withdraw', 'withdraw_update'],
            'header'   => '',
            /* 19 Nov 2026 (i1): {{1}} is the member's NAME - AgentPro::payoutTemplate
               sends [name, amount, status, reference-or-reason] (never submitted
               to Meta before this change, so nothing approved is re-worded). */
            'vars'     => ['name', 'amount', 'status', 'note'],
            'examples' => ['Ramesh Kumar', '₹5,000', 'paid', 'UPI ref 431298765432'],
            'body'     => "Hello {{1}}, here is an update on your withdrawal request.\nAmount: {{2}}\nStatus: {{3}}\nNote: {{4}}\nThank you, S Hari Global office.",
            'footer'   => 'S Hari Global',
        ],
        'shg_daily_collection' => [
            'title'    => 'Daily collection report',
            'purpose'  => ['daily_collection', 'counter_day'],
            'header'   => '',
            'vars'     => ['date', 'counter', 'tickets', 'passengers', 'cash', 'online', 'total'],
            'examples' => ['19 Nov 2026', 'Nepalgunj counter', '18', '23', '₹31,200', '₹12,600', '₹43,800'],
            'body'     => "Daily collection report for {{1}}.\nCounter: {{2}}\nTickets: {{3}}\nPassengers: {{4}}\nCash: {{5}}\nOnline: {{6}}\nTotal: {{7}}\nSent by the S Hari Global booking system.",
            'footer'   => 'S Hari Global',
        ],
        'shg_driver_manifest' => [
            'title'    => 'Driver passenger list (chalani PDF)',
            'purpose'  => ['driver_list', 'driver_manifest'],
            'header'   => 'DOCUMENT',
            'vars'     => ['bus', 'date', 'passengers'],
            'examples' => ['Bus 03 GJ05 AB 1234', '20 Nov 2026', '38'],
            'body'     => "Passenger list for your trip.\nBus: {{1}}\nDate: {{2}}\nPassengers: {{3}}\nThe full list (chalani) is the PDF above. Please check names and seats at boarding.",
            'footer'   => 'S Hari Global',
        ],
        'shg_booking_form' => [
            'title'    => 'Booking form (WhatsApp Flow)',
            'purpose'  => ['booking_form'],
            'header'   => '',
            'vars'     => [],
            'examples' => [],
            'body'     => "Tap Book ticket below to open the bus booking form. Only your name and mobile number are needed; the office confirms your seat here on WhatsApp.",
            'footer'   => 'S Hari Global',
            'button'   => ['type' => 'FLOW', 'text' => 'Book ticket'],
        ],
    ];

    /** Tests replace the Graph call: fn(string $method, string $path, ?array $body): array{http:int, json:array} */
    public static $graphHook = null;

    /** Tests replace the sample upload: fn(string $kind): string (a header handle, '' = failed) */
    public static $uploadHook = null;

    private function __construct() {}

    /* -----------------------------------------------------------------
     *  Definitions
     * ----------------------------------------------------------------- */

    /** Is the booking Flow published (a flow id that is not a draft)? */
    public static function flowPublished(): string
    {
        $id = preg_replace('/\D/', '', Settings::getString('wa_flow_book_id', '')) ?? '';
        return ($id !== '' && !Settings::getBool('wa_flow_draft', false)) ? $id : '';
    }

    /** The DEFS with {BASE} filled in. @return array<string, array<string, mixed>> */
    public static function defs(): array
    {
        $base = rtrim(function_exists('appUrl') ? appUrl('') : '', '/');
        $out  = [];
        foreach (self::DEFS as $name => $d) {
            if (isset($d['button']['url'])) {
                $d['button']['url'] = str_replace('{BASE}', $base, (string) $d['button']['url']);
            }
            $out[$name] = $d;
        }
        return $out;
    }

    /** Every name this registry knows (DEFS + KNOWN). @return list<string> */
    public static function names(): array
    {
        return array_values(array_unique(array_merge(array_keys(self::KNOWN), array_keys(self::DEFS))));
    }

    /**
     * Problems with one definition (empty = fine): the rules Meta reviews
     * and the ones this site adds.
     *
     * @return list<string>
     */
    public static function validate(string $name, array $d): array
    {
        $bad  = [];
        $body = (string) ($d['body'] ?? '');
        $n    = count((array) ($d['vars'] ?? []));
        if (preg_match('/^[a-z0-9_]{1,512}$/', $name) !== 1) {
            $bad[] = 'name must be lower-case letters, digits and _';
        }
        if ($body === '' || mb_strlen($body) >= 550) {
            $bad[] = 'body must be 1-549 characters';
        }
        preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
        $nums = array_map('intval', $m[1]);
        if ($nums !== ($n > 0 ? range(1, $n) : [])) {
            $bad[] = 'variables must be {{1}}..{{' . $n . '}} in order, once each';
        }
        if (count((array) ($d['examples'] ?? [])) !== $n) {
            $bad[] = 'one example per variable';
        }
        foreach ((array) ($d['examples'] ?? []) as $ex) {
            if (trim((string) $ex) === '' || preg_match('/[\r\n\t]/', (string) $ex) === 1 || preg_match('/ {4,}/', (string) $ex) === 1) {
                $bad[] = 'examples are single-line, non-empty, no tabs';
                break;
            }
        }
        if (preg_match('/^\s*\{\{\d+\}\}/', $body) === 1 || preg_match('/\{\{\d+\}\}\s*[.!?]?\s*$/', $body) === 1) {
            $bad[] = 'a variable may not start or end the body';
        }
        if (preg_match('/\{\{\d+\}\}\s*\{\{\d+\}\}/', $body) === 1) {
            $bad[] = 'two variables side by side';
        }
        if (!in_array((string) ($d['header'] ?? ''), ['', 'IMAGE', 'DOCUMENT'], true)) {
            $bad[] = 'header must be none, IMAGE or DOCUMENT';
        }
        $btn = (array) ($d['button'] ?? []);
        if ($btn !== []) {
            if (($btn['type'] ?? '') === 'URL') {
                $url = (string) ($btn['url'] ?? '');
                if (preg_match('#^https?://[^\s]+\{\{1\}\}$#', $url) !== 1) {
                    $bad[] = 'URL button: one {{1}} at the very end of an absolute URL';
                }
                if (trim((string) ($btn['example'] ?? '')) === '' || trim((string) ($btn['var'] ?? '')) === '') {
                    $bad[] = 'URL button needs var + example';
                }
            } elseif (($btn['type'] ?? '') !== 'FLOW') {
                $bad[] = 'button must be URL or FLOW';
            }
            if (mb_strlen((string) ($btn['text'] ?? '')) < 1 || mb_strlen((string) ($btn['text'] ?? '')) > 25) {
                $bad[] = 'button text 1-25 characters';
            }
        }
        return $bad;
    }

    /**
     * The POST body for one definition. $handle = the header sample's
     * upload handle (media headers only); $flowId for a FLOW button.
     *
     * @return array<string, mixed>
     */
    public static function payload(string $name, array $d, string $handle = '', string $flowId = ''): array
    {
        $c = [];
        $h = (string) ($d['header'] ?? '');
        if ($h !== '') {
            $c[] = ['type' => 'HEADER', 'format' => $h, 'example' => ['header_handle' => [$handle]]];
        }
        $body = ['type' => 'BODY', 'text' => (string) $d['body']];
        if ((array) ($d['examples'] ?? []) !== []) {
            $body['example'] = ['body_text' => [array_map('strval', array_values((array) $d['examples']))]];
        }
        $c[] = $body;
        if (trim((string) ($d['footer'] ?? '')) !== '') {
            $c[] = ['type' => 'FOOTER', 'text' => (string) $d['footer']];
        }
        $btn = (array) ($d['button'] ?? []);
        if (($btn['type'] ?? '') === 'URL') {
            $url = (string) $btn['url'];
            $c[] = ['type' => 'BUTTONS', 'buttons' => [[
                'type' => 'URL', 'text' => (string) $btn['text'], 'url' => $url,
                'example' => [str_replace('{{1}}', (string) $btn['example'], $url)],
            ]]];
        } elseif (($btn['type'] ?? '') === 'FLOW') {
            $c[] = ['type' => 'BUTTONS', 'buttons' => [[
                'type' => 'FLOW', 'text' => (string) $btn['text'], 'flow_id' => $flowId,
                'navigate_screen' => 'BOOK', 'flow_action' => 'navigate',
            ]]];
        }
        return ['name' => $name, 'language' => self::LANG, 'category' => 'UTILITY', 'components' => $c];
    }

    /* -----------------------------------------------------------------
     *  Status (kv_store scope wa_tpl_meta)
     * ----------------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    private static function kvGet(string $key): ?array
    {
        try {
            $raw = Database::scalar('SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => self::SCOPE, 'k' => $key], null);
        } catch (Throwable $e) {
            return null;
        }
        $v = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($v) ? $v : null;
    }

    private static function kvSet(string $key, array $value): void
    {
        try {
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES (:s, :k, :v, 'watplmeta')
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = 'watplmeta'",
                ['s' => self::SCOPE, 'k' => $key, 'v' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
        } catch (Throwable $e) {
            Logger::warning('wa_tpl_meta kv write failed: ' . $e->getMessage(), ['key' => $key], 'whatsapp');
        }
    }

    /** Meta's last known status for this template: APPROVED / PENDING / REJECTED / PAUSED / MISSING / '' (never synced). */
    public static function status(string $name): string
    {
        $r = self::kvGet('status.' . $name);
        return $r === null ? '' : strtoupper((string) ($r['status'] ?? ''));
    }

    /** @return array<string, mixed>|null the stored row (status, category, language, id, at) */
    public static function info(string $name): ?array
    {
        return self::kvGet('status.' . $name);
    }

    /** When sync() last read the WABA (0 = never). */
    public static function lastSync(): int
    {
        return (int) (self::kvGet('last_sync')['at'] ?? 0);
    }

    public static function on(): bool
    {
        return Settings::getBool('wa_tpl_meta_on', true);
    }

    /** Meta refused a send on this template (132001 / paused): remember it a few hours. */
    public static function noteRefused(string $name, int $code): void
    {
        if (trim($name) === '' || !in_array($code, self::REFUSAL_CODES, true)) {
            return;
        }
        self::kvSet('refused.' . $name, ['at' => time(), 'code' => $code]);
    }

    public static function refusedRecently(string $name): bool
    {
        $r = self::kvGet('refused.' . $name);
        if ($r === null) {
            return false;
        }
        $hours = max(0, min(72, Settings::getInt('wa_tpl_refused_hours', 6)));
        return (int) ($r['at'] ?? 0) > time() - $hours * 3600;
    }

    /** APPROVED, switched on, and Meta has not just refused it. */
    public static function ready(string $name): bool
    {
        if (trim($name) === '' || !self::on()) {
            return false;
        }
        return self::status($name) === 'APPROVED' && !self::refusedRecently($name);
    }

    /**
     * The ticket template to use now: the first READY of
     * [whatsapp_template_name, shg_ticket_v2]. A configured name with no
     * known status (never synced: the office set it by hand) is still used,
     * as before this registry; one Meta has just refused is not.
     *
     * @return array{name: string, lang: string}
     */
    /**
     * 25 Nov 2026 (mp4): the EXACT language code the sending WABA has this template in
     * (the last sync's 'language', e.g. en / en_US / ne), else $fallback - a template is
     * sent in the language Meta holds it in, never a guess.
     */
    public static function langOf(string $name, string $fallback = self::LANG): string
    {
        $l = trim((string) (self::info($name)['language'] ?? ''));
        return preg_match('/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/', $l) === 1 ? $l : ($fallback !== '' ? $fallback : self::LANG);
    }

    public static function ticketTemplate(): array
    {
        $set  = trim(Settings::getString('whatsapp_template_name', ''));
        $lang = trim(Settings::getString('whatsapp_template_lang', 'en')) ?: 'en';
        foreach (array_unique(array_filter([$set, self::TICKET_V2])) as $cand) {
            if (self::ready($cand)) {
                return ['name' => $cand, 'lang' => self::langOf($cand, $cand === $set ? $lang : self::LANG)];
            }
        }
        if ($set !== '' && self::status($set) === '' && !self::refusedRecently($set)) {
            return ['name' => $set, 'lang' => $lang];
        }
        return ['name' => '', 'lang' => $lang];
    }

    /* -----------------------------------------------------------------
     *  Purpose -> template
     * ----------------------------------------------------------------- */

    /** The template name a purpose maps to (whatever its status), '' = none. */
    public static function nameFor(string $purpose): string
    {
        $p = strtolower(trim($purpose));
        if (in_array($p, ['ticket', 'ticket_resend', 'seller_copy', 'ticket_alt'], true)) {
            return self::ticketTemplate()['name'];
        }
        if ($p === 'ticket_update') {
            return self::ready('shg_ticket_changed') ? 'shg_ticket_changed' : self::ticketTemplate()['name'];
        }
        if (in_array($p, ['booking_received', 'pay_request'], true)) {
            $p = 'pay';
        }
        if ($p === 'counter_report') {
            $p = 'counter_day';
        }
        foreach (self::DEFS as $name => $d) {
            if (in_array($p, (array) $d['purpose'], true)) {
                return $name;
            }
        }
        return '';
    }

    /** Variable names + header kind for a template name this registry knows. @return array{vars: list<string>, header: string, button: array}|null */
    private static function shape(string $name): ?array
    {
        if (isset(self::DEFS[$name])) {
            $d = self::defs()[$name];
            return ['vars' => (array) $d['vars'], 'header' => (string) $d['header'], 'button' => (array) ($d['button'] ?? [])];
        }
        if (isset(self::KNOWN[$name])) {
            return ['vars' => self::KNOWN[$name]['vars'], 'header' => self::KNOWN[$name]['header'], 'button' => []];
        }
        return null;
    }

    /** A template variable: one line, no tab, no run of 4+ spaces, never empty. */
    public static function flat(string $s): string
    {
        $s = trim((string) preg_replace('/\s*\R\s*/u', ' · ', $s));
        $s = (string) preg_replace('/\t+/', ' ', $s);
        $s = (string) preg_replace('/ {4,}/', '   ', $s);
        $s = mb_substr($s, 0, 500);
        return $s !== '' ? $s : '-';
    }

    /**
     * What to send for this purpose, ONLY when its template is ready.
     *
     * $ctx: the semantic values the template's 'vars' name (name, pnr,
     * amount, …), 'header' (the media URL for an IMAGE / DOCUMENT header)
     * and the URL button's value (pnr / track_path). The ticket purposes
     * may instead pass 'vars' = Notify::ticketVars() ('1'..'6', '7' = PNG).
     * A missing value is sent as '-' (never invented); a missing media
     * header or button value means null (that template cannot go).
     *
     * @return array{name: string, lang: string, vars: list<string>, header: array<string,string>, buttons: list<array<string,mixed>>}|null
     */
    public static function forPurpose(string $purpose, array $ctx = []): ?array
    {
        try {
            $name = self::nameFor($purpose);
            if ($name === '') {
                return null;
            }
            $isTicket = in_array(strtolower($purpose), ['ticket', 'ticket_resend', 'seller_copy', 'ticket_alt'], true)
                     || ($name !== 'shg_ticket_changed' && strtolower($purpose) === 'ticket_update');
            if ($isTicket) {
                $t = self::ticketTemplate();
                if ($t['name'] === '' || $t['name'] !== $name) {
                    return null;
                }
                $tv = (array) ($ctx['vars'] ?? []);
                if ($tv === []) {
                    return null;
                }
                $hdr = (string) ($tv['7'] ?? ($ctx['header'] ?? ''));
                unset($tv['7']);
                ksort($tv, SORT_NATURAL);
                return ['name' => $name, 'lang' => $t['lang'], 'vars' => array_map(static fn($v): string => self::flat((string) $v), array_values($tv)),
                        'header' => $hdr !== '' ? ['image' => $hdr] : [], 'buttons' => []];
            }
            if (!self::ready($name)) {
                return null;
            }
            $s = self::shape($name);
            if ($s === null) {
                return null;
            }
            if ($name === 'shg_booking_form' && self::flowPublished() === '') {
                return null;
            }
            $vars = [];
            foreach ($s['vars'] as $k) {
                $vars[] = self::flat((string) ($ctx[$k] ?? ''));
            }
            $header = [];
            if ($s['header'] !== '') {
                $url = trim((string) ($ctx['header'] ?? ''));
                if (preg_match('#^https://#i', $url) !== 1) {
                    return null;
                }
                $header = [$s['header'] === 'DOCUMENT' ? 'document' : 'image' => $url];
            }
            $buttons = [];
            $btn = $s['button'];
            if (($btn['type'] ?? '') === 'URL') {
                $v = trim((string) ($ctx[(string) $btn['var']] ?? ''));
                if ($v === '') {
                    return null;
                }
                $buttons[] = ['sub_type' => 'url', 'index' => 0, 'text' => $v];
            } elseif (($btn['type'] ?? '') === 'FLOW') {
                $buttons[] = ['sub_type' => 'flow', 'index' => 0, 'token' => (string) ($ctx['flow_token'] ?? 'unused')];
            }
            return ['name' => $name, 'lang' => self::langOf($name), 'vars' => $vars, 'header' => $header, 'buttons' => $buttons];
        } catch (Throwable $e) {
            Logger::warning('WaTplMeta forPurpose failed: ' . $e->getMessage(), ['purpose' => $purpose], 'whatsapp');
            return null;
        }
    }

    /** Tests replace the delivery: fn(string $phone, string $text, string $country, array $meta): bool|string (Notify::whatsapp's shape). */
    public static $sendHook = null;

    /**
     * Send ONE registry template by its name (19 Nov 2026, i1 - AgentPro's
     * shg_withdraw_update): only when ready() (APPROVED, wa_tpl_meta_on, not
     * just refused), and only a template with no media header / URL / Flow
     * button (those need values this call does not carry). $vars: a list in
     * the template's {{1}}.. order, or keyed by the registry's var names.
     *
     * Delivery is Notify::whatsapp with purpose + meta_tpl: inside the
     * number's 24 h window the same words go free, outside it the template
     * (WaRoute::metaTemplate, under wa_paid_daily_limit). $country 'IN'/'NP'
     * (or '91'/'977') places a bare 10-digit number. true = it went;
     * false = not ready / not sendable / refused - the caller sends its text.
     */
    public static function sendNamed(string $phone, string $name, array $vars, string $country = 'IN'): bool
    {
        try {
            $name = trim($name);
            if ($name === '' || !self::ready($name)) {
                return false;
            }
            $s = self::shape($name);
            if ($s === null || $s['header'] !== '' || $s['button'] !== []) {
                return false;
            }
            $vals = [];
            $list = array_is_list($vars);
            foreach (array_values($s['vars']) as $i => $k) {
                $vals[] = self::flat((string) ($list ? ($vars[$i] ?? '') : ($vars[$k] ?? ($vars[(string) ($i + 1)] ?? ''))));
            }
            $purpose = isset(self::DEFS[$name])
                ? (string) (((array) self::DEFS[$name]['purpose'])[0] ?? $name)
                : (string) (self::KNOWN[$name]['purpose'] ?? $name);
            $body = isset(self::DEFS[$name]) ? (string) self::DEFS[$name]['body'] : '';
            $text = $body !== ''
                ? (string) preg_replace_callback('/\{\{(\d+)\}\}/', static fn(array $m): string => $vals[(int) $m[1] - 1] ?? '-', $body)
                : implode("\n", $vals);
            $c    = strtoupper(ltrim(trim($country), '+'));
            $hint = in_array($c, ['NP', '977'], true) ? 'NP' : (in_array($c, ['IN', '91'], true) ? 'IN' : null);
            $meta = ['purpose' => $purpose, 'meta_tpl' => ['name' => $name, 'lang' => self::LANG, 'vars' => $vals, 'header' => [], 'buttons' => []]];
            if (self::$sendHook !== null) {
                return (self::$sendHook)($phone, $text, (string) $hint, $meta) === true;
            }
            if (!class_exists('Notify')) {
                require_once __DIR__ . '/notify.php';
            }
            return Notify::whatsapp($phone, $text, null, $hint, [], null, $meta) === true;
        } catch (Throwable $e) {
            Logger::warning('WaTplMeta sendNamed failed: ' . $e->getMessage(), ['name' => $name], 'whatsapp');
            return false;
        }
    }

    /* -----------------------------------------------------------------
     *  Graph
     * ----------------------------------------------------------------- */

    /** @return array{token: string, waba: string, base: string} */
    private static function creds(): array
    {
        if (!defined('META_ACCESS_TOKEN') && defined('ROOT_PATH') && is_file(ROOT_PATH . '/whatsapp/config.php')) {
            try {
                require_once ROOT_PATH . '/whatsapp/config.php';
            } catch (Throwable $e) {
            }
        }
        $ver = defined('META_API_VERSION') && preg_match('/^v\d+\.\d+$/', (string) META_API_VERSION) ? (string) META_API_VERSION : 'v25.0';
        $waba = defined('META_WABA_ID') ? (string) META_WABA_ID : '';
        if ($waba === '') {
            $waba = preg_replace('/\D/', '', Settings::getString('whatsapp_waba_id', '')) ?? '';
        }
        return [
            'token' => defined('META_ACCESS_TOKEN') ? (string) META_ACCESS_TOKEN : '',
            'waba'  => $waba,
            'base'  => 'https://graph.facebook.com/' . $ver,
        ];
    }

    public static function configured(): bool
    {
        if (self::$graphHook !== null) {
            return true;
        }
        $c = self::creds();
        return $c['token'] !== '' && $c['waba'] !== '' && function_exists('curl_init');
    }

    /** @return array{http: int, json: array<string, mixed>} */
    private static function graph(string $method, string $path, ?array $body = null): array
    {
        if (self::$graphHook !== null) {
            return (self::$graphHook)($method, $path, $body);
        }
        $c  = self::creds();
        $ch = curl_init(str_starts_with($path, 'https://') ? $path : $c['base'] . $path);
        try {
            $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['token'], 'Content-Type: application/json']];
            if ($method === 'POST') {
                $opt[CURLOPT_POST] = true;
                $opt[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            curl_setopt_array($ch, $opt);
            $raw  = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            return ['http' => $http, 'json' => is_array($json) ? $json : []];
        } catch (Throwable $e) {
            return ['http' => 0, 'json' => []];
        } finally {
            curl_close($ch);
        }
    }

    private static function err(array $r): string
    {
        $e = (array) ($r['json']['error'] ?? []);
        $s = (string) ($e['error_user_msg'] ?? ($e['message'] ?? ('HTTP ' . $r['http'])));
        return mb_substr(trim($s . (isset($e['code']) ? ' (code ' . (int) $e['code'] . ')' : '')), 0, 300);
    }

    /**
     * Every template in the WABA, keyed by name (the 'en' row wins when a
     * name has several languages).
     *
     * @return array{ok: bool, error: string, list: array<string, array{status: string, category: string, language: string, id: string}>}
     */
    public static function listRemote(): array
    {
        $out = ['ok' => false, 'error' => '', 'list' => []];
        if (!self::configured()) {
            $out['error'] = 'Meta token / WABA id not configured.';
            return $out;
        }
        $c    = self::creds();
        $path = '/' . rawurlencode($c['waba']) . '/message_templates?fields=name,status,category,language,id&limit=200';
        for ($page = 0; $page < 10 && $path !== ''; $page++) {
            $r = self::graph('GET', $path);
            if ($r['http'] !== 200) {
                $out['error'] = self::err($r);
                return $out;
            }
            foreach ((array) ($r['json']['data'] ?? []) as $t) {
                $n = (string) ($t['name'] ?? '');
                if ($n === '' || (isset($out['list'][$n]) && $out['list'][$n]['language'] === self::LANG)) {
                    continue;
                }
                $out['list'][$n] = ['status' => strtoupper((string) ($t['status'] ?? '')), 'category' => strtoupper((string) ($t['category'] ?? '')),
                                    'language' => (string) ($t['language'] ?? ''), 'id' => (string) ($t['id'] ?? '')];
            }
            $next = (string) ($r['json']['paging']['next'] ?? '');
            $path = str_starts_with($next, 'https://graph.facebook.com/') ? $next : '';
        }
        $out['ok'] = true;
        return $out;
    }

    /**
     * Read every template's status into kv_store. Names this registry knows
     * that the WABA does not have are stored as MISSING.
     *
     * @return array{ok: bool, error: string, statuses: array<string, string>, other: int}
     */
    public static function sync(): array
    {
        $out = ['ok' => false, 'error' => '', 'statuses' => [], 'other' => 0];
        try {
            $l = self::listRemote();
            if (!$l['ok']) {
                $out['error'] = $l['error'];
                return $out;
            }
            $now = time();
            foreach ($l['list'] as $name => $t) {
                self::kvSet('status.' . $name, $t + ['at' => $now]);
                if (in_array($name, self::names(), true) || $name === 'shg_login_code') {
                    $out['statuses'][$name] = $t['status'];
                } else {
                    $out['other']++;
                }
            }
            foreach (array_merge(self::names(), ['shg_login_code']) as $name) {
                if (!isset($l['list'][$name])) {
                    self::kvSet('status.' . $name, ['status' => 'MISSING', 'category' => '', 'language' => '', 'id' => '', 'at' => $now]);
                    $out['statuses'][$name] = 'MISSING';
                }
            }
            self::kvSet('last_sync', ['at' => $now, 'count' => count($l['list'])]);
            $out['ok'] = true;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    /* -----------------------------------------------------------------
     *  Submit
     * ----------------------------------------------------------------- */

    /** A tiny one-page PDF (the DOCUMENT header's sample for Meta's review). */
    public static function samplePdf(): string
    {
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            null,
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $text = 'BT /F1 18 Tf 60 780 Td (S Hari Global - sample statement) Tj ET';
        $objs[3] = '<< /Length ' . strlen($text) . " >>\nstream\n" . $text . "\nendstream";
        $pdf = "%PDF-1.4\n";
        $off = [];
        foreach ($objs as $i => $o) {
            $off[$i] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($off as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    /** The app id the token belongs to (setting whatsapp_app_id, else GET /app). */
    private static function appId(): string
    {
        $id = preg_replace('/\D/', '', Settings::getString('whatsapp_app_id', '')) ?? '';
        if ($id !== '') {
            return $id;
        }
        $r = self::graph('GET', '/app?fields=id');
        return preg_replace('/\D/', '', (string) ($r['json']['id'] ?? '')) ?? '';
    }

    /**
     * Upload a sample header file with Meta's resumable upload API and
     * return its handle ('' on failure). $kind 'IMAGE' | 'DOCUMENT'.
     */
    public static function uploadSample(string $kind): string
    {
        if (self::$uploadHook !== null) {
            return (string) (self::$uploadHook)($kind);
        }
        try {
            if ($kind === 'DOCUMENT') {
                $bytes = self::samplePdf();
                $mime  = 'application/pdf';
                $fname = 'sample.pdf';
            } else {
                $file = '';
                foreach (['assets/img/icon-512.png', 'assets/img/apple-touch-icon.png'] as $f) {
                    if (is_file(ROOT_PATH . '/' . $f)) {
                        $file = ROOT_PATH . '/' . $f;
                        break;
                    }
                }
                if ($file === '') {
                    return '';
                }
                $bytes = (string) file_get_contents($file);
                $mime  = 'image/png';
                $fname = 'sample.png';
            }
            $app = self::appId();
            if ($app === '') {
                return '';
            }
            $c  = self::creds();
            $r1 = self::graph('POST', '/' . $app . '/uploads?file_name=' . rawurlencode($fname) . '&file_length=' . strlen($bytes) . '&file_type=' . rawurlencode($mime));
            $sid = (string) ($r1['json']['id'] ?? '');
            if ($sid === '' || !str_starts_with($sid, 'upload:')) {
                return '';
            }
            $ch = curl_init($c['base'] . '/' . $sid);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $bytes,
                CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Authorization: OAuth ' . $c['token'], 'file_offset: 0', 'Content-Type: ' . $mime],
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            return is_array($j) ? (string) ($j['h'] ?? '') : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * POST to Meta only the DEFS names the WABA does not have. $dry = list
     * what would go, send nothing. Each row: name, action (known / exists /
     * would_submit / submitted / failed / skipped / invalid), detail.
     *
     * @return array{ok: bool, error: string, submitted: int, rows: list<array{name: string, action: string, detail: string}>}
     */
    public static function submitMissing(bool $dry = true): array
    {
        $out = ['ok' => false, 'error' => '', 'submitted' => 0, 'rows' => []];
        try {
            $l = self::listRemote();
            if (!$l['ok']) {
                $out['error'] = $l['error'];
                return $out;
            }
            foreach (array_keys(self::KNOWN) as $name) {
                $st = $l['list'][$name]['status'] ?? 'MISSING';
                $out['rows'][] = ['name' => $name, 'action' => 'known', 'detail' => 'in the WABA by hand - not submitted again (' . $st . ')'];
            }
            $flow = self::flowPublished();
            foreach (self::defs() as $name => $d) {
                if (isset($l['list'][$name])) {
                    $out['rows'][] = ['name' => $name, 'action' => 'exists', 'detail' => $l['list'][$name]['status'] . ' ' . $l['list'][$name]['category']];
                    continue;
                }
                $bad = self::validate($name, $d);
                if ($bad !== []) {
                    $out['rows'][] = ['name' => $name, 'action' => 'invalid', 'detail' => implode('; ', $bad)];
                    continue;
                }
                if (($d['button']['type'] ?? '') === 'FLOW' && $flow === '') {
                    $out['rows'][] = ['name' => $name, 'action' => 'skipped', 'detail' => 'no published booking Flow yet (wa_flow_book_id / wa_flow_draft)'];
                    continue;
                }
                if ($dry) {
                    $out['rows'][] = ['name' => $name, 'action' => 'would_submit', 'detail' => 'UTILITY en' . ($d['header'] !== '' ? ', ' . $d['header'] . ' header' : '')
                        . (isset($d['button']) ? ', ' . $d['button']['type'] . ' button' : '') . ', ' . count($d['vars']) . ' variable(s)'];
                    continue;
                }
                $handle = '';
                if ($d['header'] !== '') {
                    $handle = self::uploadSample($d['header']);
                    if ($handle === '') {
                        $out['rows'][] = ['name' => $name, 'action' => 'failed', 'detail' => 'could not upload the ' . strtolower($d['header'])
                            . ' sample (set whatsapp_app_id to the Meta app id, or check the token)'];
                        continue;
                    }
                }
                $c = self::creds();
                $r = self::graph('POST', '/' . rawurlencode($c['waba']) . '/message_templates', self::payload($name, $d, $handle, $flow));
                if ($r['http'] === 200 && (string) ($r['json']['id'] ?? '') !== '') {
                    $st = strtoupper((string) ($r['json']['status'] ?? 'PENDING'));
                    self::kvSet('status.' . $name, ['status' => $st, 'category' => strtoupper((string) ($r['json']['category'] ?? 'UTILITY')),
                        'language' => self::LANG, 'id' => (string) $r['json']['id'], 'at' => time()]);
                    $out['rows'][] = ['name' => $name, 'action' => 'submitted', 'detail' => $st . ' ' . (string) ($r['json']['category'] ?? '')];
                    $out['submitted']++;
                    try {
                        Logger::audit('whatsapp.template_submitted', 'setting', $name, null, ['status' => $st], 'Meta template asked for (WaTplMeta)');
                    } catch (Throwable $e) {
                    }
                } else {
                    $out['rows'][] = ['name' => $name, 'action' => 'failed', 'detail' => self::err($r)];
                }
            }
            $out['ok'] = true;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    /**
     * 25 Nov 2026 (mp4): the sending number's own health from Meta (a GET, free): display
     * name + its approval, quality, messaging tier, sending status (health_status) - kept in
     * kv 'phone' for the "Meta स्वास्थ्य" card. Only on the office's button (never from a test
     * without $graphHook). @return array{ok: bool, error: string, phone: array<string, mixed>}
     */
    public static function phoneHealth(): array
    {
        $out = ['ok' => false, 'error' => '', 'phone' => []];
        try {
            if (!self::configured()) {
                $out['error'] = 'Meta token / WABA id not configured.';
                return $out;
            }
            $pid = defined('META_PHONE_NUMBER_ID') ? (string) META_PHONE_NUMBER_ID : '';
            if ($pid === '') {
                $pid = preg_replace('/\D/', '', Settings::getString('whatsapp_phone_id', '')) ?? '';
            }
            if ($pid === '') {
                $out['error'] = 'whatsapp_phone_id not configured.';
                return $out;
            }
            $base = 'display_phone_number,verified_name,name_status,quality_rating,messaging_limit_tier,code_verification_status,status,platform_type';
            $r = self::graph('GET', '/' . rawurlencode($pid) . '?fields=' . $base . ',health_status');
            if ($r['http'] !== 200) {
                $r = self::graph('GET', '/' . rawurlencode($pid) . '?fields=' . $base);   // an older Graph version without health_status
            }
            if ($r['http'] !== 200) {
                $out['error'] = self::err($r);
                return $out;
            }
            $j = $r['json'];
            $can = (string) ($j['health_status']['can_send_message'] ?? '');
            $why = [];
            foreach ((array) ($j['health_status']['entities'] ?? []) as $en) {
                foreach ((array) ($en['errors'] ?? []) as $er) {
                    $why[] = mb_substr(trim((string) ($er['error_description'] ?? '') . ' ' . (string) ($er['possible_solution'] ?? '')), 0, 200);
                }
            }
            $p = [
                'number' => (string) ($j['display_phone_number'] ?? ''), 'name' => (string) ($j['verified_name'] ?? ''),
                'name_status' => (string) ($j['name_status'] ?? ''), 'quality' => (string) ($j['quality_rating'] ?? ''),
                'tier' => (string) ($j['messaging_limit_tier'] ?? ''), 'verification' => (string) ($j['code_verification_status'] ?? ''),
                'status' => (string) ($j['status'] ?? ''), 'can_send' => $can, 'notes' => array_values(array_unique(array_filter($why))), 'at' => time(),
            ];
            self::kvSet('phone', $p);
            $out = ['ok' => true, 'error' => '', 'phone' => $p];
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    /** The last stored number health (phoneHealth), or null. */
    public static function phoneInfo(): ?array
    {
        return self::kvGet('phone');
    }

    /** The last 132001 / paused refusal of one template (code + time), or null. */
    public static function refusedInfo(string $name): ?array
    {
        return self::kvGet('refused.' . $name);
    }

    /**
     * For the admin page and the CLI: every registry name with its stored
     * status. @return list<array{name: string, title: string, kind: string, status: string, ready: bool, purpose: string, at: int}>
     */
    public static function overview(): array
    {
        $rows = [];
        foreach (self::KNOWN as $name => $k) {
            $i = self::info($name);
            $rows[] = ['name' => $name, 'title' => 'Existing (by hand)', 'kind' => 'known', 'status' => strtoupper((string) ($i['status'] ?? '')),
                       'ready' => self::ready($name), 'purpose' => (string) $k['purpose'], 'at' => (int) ($i['at'] ?? 0)];
        }
        foreach (self::DEFS as $name => $d) {
            $i = self::info($name);
            $rows[] = ['name' => $name, 'title' => (string) $d['title'], 'kind' => 'def', 'status' => strtoupper((string) ($i['status'] ?? '')),
                       'ready' => self::ready($name), 'purpose' => implode(', ', (array) $d['purpose']), 'at' => (int) ($i['at'] ?? 0)];
        }
        return $rows;
    }
}
