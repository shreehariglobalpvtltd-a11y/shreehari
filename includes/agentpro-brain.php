<?php
/**
 * =====================================================================
 *  includes/agentpro-brain.php — what does this WhatsApp message mean?
 *
 *  The VPS model is asked ONE thing per message: read it into a small
 *  JSON object (intent + the details), constrained by a JSON schema so a
 *  4 B model on a CPU still answers in valid JSON. ApWa then runs the
 *  matching handler — the same one a typed command reaches. The model
 *  never sells, edits or pays anything itself.
 *
 *  Why not a free tool loop? On CPU every round re-reads the prompt
 *  (tens of seconds) and small models wander between tools. One
 *  structured read is one round, and its answer is checked here before
 *  anything uses it.
 *
 *  The prompt's fixed part comes first and stays byte-identical between
 *  messages, so Ollama reuses its cached prefix; the changing part (today,
 *  who is writing, the message) comes last.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/agentpro-ai.php';

final class ApBrain
{
    public const INTENTS = ['book', 'edit', 'cancel', 'resend', 'status', 'balance', 'statement', 'withdraw', 'deposit',
                            'id_card', 'visiting_card', 'profile', 'directory', 'company', 'bus', 'report', 'passengers',
                            'tickets', 'portal', 'pending', 'help', 'join', 'question', 'other'];

    /**
     * Seconds the model may still take for this turn: what is left of the
     * turn's budget (ApWa::turnBudget, ~60 s) after the time already spent and
     * the longest wait for the model lock. ApAi::chat waits min(timeout,
     * waWait) for the lock, so with T = left − min(waWait, left/2) the wait
     * plus T never pass what is left (T ≥ left/2 ≥ the wait). Never under
     * 8 s — below that the answer would not come anyway, and the menu is the
     * reply.
     */
    public static function timeoutFor(float $t0, ?int $budget = null): int
    {
        $budget ??= max(15, min(120, Settings::getInt('ap_wa_turn_budget', 60)));
        $left = $budget - (microtime(true) - $t0);
        return max(8, (int) floor($left - min(ApAi::waWait(), $left / 2)));
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>|null null when the model could not answer
     */
    public static function understand(string $text, array $ctx, string $lang, ?array $member): ?array
    {
        if (!ApAi::enabled()) {
            return null;
        }
        $customer = $member === null;
        $hints = self::hints($text);
        $today = date('Y-m-d');
        $user = "Today: " . $today . ' (' . date('l') . '). Tomorrow: ' . date('Y-m-d', strtotime('+1 day'))
              . '. Day after: ' . date('Y-m-d', strtotime('+2 day')) . ".\n"
              . 'Sender: ' . ($customer ? 'a passenger writing about their own trip' : 'our ' . ($member['role'] === 'agent' ? 'ticketing agent' : 'staff member')
                  . ' ' . $member['full_name'] . ' (books for passengers; name/phone in a booking are the PASSENGER\'s)') . ".\n"
              . 'Reply language: ' . ['ne' => 'Nepali (Devanagari)', 'hi' => 'Hindi', 'en' => 'English'][$lang] . ".\n"
              . ($hints !== '' ? 'A simple parser already saw: ' . $hints . " (trust it unless the message clearly says otherwise).\n" : '')
              . "Message:\n" . mb_substr($text, 0, 800);
        $schema = [
            'type' => 'object',
            'properties' => [
                'intent'    => ['type' => 'string', 'enum' => self::INTENTS],
                'pnr'       => ['type' => 'string'],
                'seats'     => ['type' => 'integer'],
                'date'      => ['type' => 'string'],
                'boarding'  => ['type' => 'string'],
                'direction' => ['type' => 'string', 'enum' => ['', 'toNepal', 'toIndia']],
                'name'      => ['type' => 'string'],
                'phone'     => ['type' => 'string'],
                'names'     => ['type' => 'array', 'items' => ['type' => 'string']],
                'gender'    => ['type' => 'string'],
                'pay'       => ['type' => 'string'],
                'field'     => ['type' => 'string'],
                'value'     => ['type' => 'string'],
                'which'     => ['type' => 'string'],
                'amount'    => ['type' => 'number'],
                'ref'       => ['type' => 'string'],
                'days'      => ['type' => 'integer'],
                'query'     => ['type' => 'string'],
                'answer'    => ['type' => 'string'],
            ],
            'required' => ['intent'],
        ];
        // One turn never runs past its budget: the lock wait + this HTTP timeout fit inside what is left.
        $t0 = (float) ($ctx['t0'] ?? microtime(true));
        if (microtime(true) - $t0 > max(15, min(120, Settings::getInt('ap_wa_turn_budget', 60))) - 8) {
            return null;                                   // the turn is already spent: the menu answers
        }
        $timeout = self::timeoutFor($t0);
        $d = ApAi::json(self::system($customer), $user, $schema, [], ['purpose' => $customer ? 'wa_customer' : 'wa_member', 'max_tokens' => 350,
            'timeout' => $timeout]);
        if ($d === null) {
            return null;
        }
        return self::clean($d, $text);
    }

    /** The fixed part of the prompt: instructions, then the company's facts. */
    public static function system(bool $customer): string
    {
        static $cache = [];
        $k = $customer ? 'c' : 'm';
        if (isset($cache[$k])) {
            return $cache[$k];
        }
        $co = Settings::company();
        $intents = $customer
            ? "book | status | resend | cancel | edit | bus | company | join | question | other"
            : implode(' | ', self::INTENTS);
        $s = "You are the WhatsApp desk of " . $co['name'] . ", a bus company running daily AC sleeper coaches between Gujarat (India) "
           . "and Rupaidiha / Nepalgunj (Nepal). Read ONE WhatsApp message and return JSON saying what the sender wants. Messages arrive in "
           . "Nepali, Hindi, English, romanised Nepali or Hindi, or a mix.\n\n"
           . "intent is one of: " . $intents . ".\n"
           . "- book: wants a ticket. seats (number of travellers), date as YYYY-MM-DD, boarding = pickup town, direction = toNepal when going to "
           . "Nepal (jane/jaane/जाने), toIndia when coming back to India (aaune/फर्कने), else empty. name and phone (10 digits) of the passenger, "
           . "names = every traveller when there are several, gender (Male/Female), pay (cash/upi/esewa/bank).\n"
           . "- edit: change something on a ticket: pnr, field = name | phone | pickup | seat | date | gender | age, value = the NEW value, "
           . "which = the passenger (name or seat) when several travel.\n"
           . "- cancel, resend (send the ticket again), status (where is my ticket / bus): pnr.\n"
           . ($customer ? '' : "- balance, statement (days), withdraw (amount), deposit (amount, ref = UTR): the sender's own wallet.\n"
              . "- id_card, visiting_card, profile: the sender's own cards / details. directory: someone's number (query = the name). "
              . "report: today's sales. passengers: who travels (date). bus: buses on a date.\n"
              . "- tickets: the sender's own sold tickets (date when they name a day). portal: how to sign in to their web portal / app. "
              . "pending: (office staff) what is waiting for approval.\n"
              . "Never approve, pay, cancel or sell anything yourself: only say which intent it is.\n")
           . "- company: office address, CIN, phone numbers, email, website. join: wants to become an agent.\n"
           . "- question / other: put a SHORT helpful reply (1-4 lines, no markdown) in answer, in the requested language, using ONLY the "
           . "FACTS below. If the facts do not cover it, say the office will help and give the office phone.\n"
           . "Never invent a PNR, phone number, fare, seat or date. Leave unknown fields empty. A PNR looks like SHG-2026-00123.\n\n"
           . "FACTS\n"
           . "Company: " . $co['legal'] . " · CIN " . $co['cin'] . "\n"
           . "Head office: " . $co['address'] . "\n"
           . "Phone: " . $co['phone'] . " · WhatsApp +" . $co['whatsapp'] . " · Email " . $co['email'] . " · Web " . $co['web'] . "\n"
           . "Counters: " . AgentPro::fixMojibake($co['counters']) . "\n"
           . "Director: " . $co['operator'] . "\n"
           . self::routesFact()
           . "Payment: cash at the counter or on the bus, UPI " . Settings::getString('upi_id', '') . ", eSewa. Tickets come on WhatsApp as a picture.\n"
           . "Become an agent: " . appUrl('join.php') . "\n";
        return $cache[$k] = $s;
    }

    /** Active routes, one line each. */
    private static function routesFact(): string
    {
        try {
            $rows = Database::fetchAll(
                'SELECT from_city, to_city, dep_time FROM routes WHERE is_active = 1 ORDER BY sort_order, dep_time LIMIT 8'
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        if ($rows === []) {
            return '';
        }
        $l = array_map(static fn(array $r): string => $r['from_city'] . ' → ' . $r['to_city'] . ' ' . substr((string) $r['dep_time'], 0, 5), $rows);
        return 'Daily buses: ' . implode('; ', $l) . "\n";
    }

    /** What the deterministic parser already saw, so a small model need not re-derive it. */
    private static function hints(string $text): string
    {
        try {
            require_once __DIR__ . '/quickticket.php';
            require_once __DIR__ . '/ticketbot.php';
            $p = TicketBot::parse($text);
        } catch (Throwable $e) {
            return '';
        }
        $h = [];
        foreach (['seats', 'date', 'boarding', 'direction', 'phone'] as $k) {
            if (!empty($p[$k])) {
                $h[] = $k . '=' . $p[$k];
            }
        }
        if (preg_match('/\bSHG-[A-Z0-9]+(?:-[A-Z0-9]+)+\b/i', $text, $m)) {
            $h[] = 'pnr=' . strtoupper($m[0]);
        }
        return implode(', ', $h);
    }

    /**
     * Check every field before anything acts on it.
     *
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    public static function clean(array $d, string $text): array
    {
        $intent = (string) ($d['intent'] ?? 'other');
        $out = ['intent' => in_array($intent, self::INTENTS, true) ? $intent : 'other'];
        $pnr = strtoupper(trim((string) ($d['pnr'] ?? '')));
        // A PNR the model "found" must actually be in the message.
        if ($pnr !== '' && preg_match('/^SHG-[A-Z0-9]+(?:-[A-Z0-9]+)+$/', $pnr) === 1 && stripos($text, $pnr) !== false) {
            $out['pnr'] = $pnr;
        } elseif (preg_match('/\bSHG-[A-Z0-9]+(?:-[A-Z0-9]+)+\b/i', $text, $m)) {
            $out['pnr'] = strtoupper($m[0]);
        }
        $seats = (int) ($d['seats'] ?? 0);
        if ($seats > 0 && $seats <= 12) {
            $out['seats'] = $seats;
        }
        $date = (string) ($d['date'] ?? '');
        if (Security::isValidDate($date) && $date >= date('Y-m-d', strtotime('-1 day')) && $date <= date('Y-m-d', strtotime('+400 days'))) {
            $out['date'] = $date;
        }
        foreach (['boarding' => 80, 'name' => 120, 'gender' => 10, 'pay' => 10, 'field' => 10, 'value' => 120, 'which' => 60, 'ref' => 40, 'query' => 60] as $k => $max) {
            $v = trim((string) ($d[$k] ?? ''));
            if ($v !== '') {
                $out[$k] = mb_substr(Security::clean($v, $max), 0, $max);
            }
        }
        $dir = (string) ($d['direction'] ?? '');
        if (in_array($dir, ['toNepal', 'toIndia'], true)) {
            $out['direction'] = $dir;
        }
        // A phone number the model "found" must actually be in the message too.
        $phone = preg_replace('/\D/', '', (string) ($d['phone'] ?? '')) ?? '';
        $digits = preg_replace('/\D/', '', strtr($text, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9'])) ?? '';
        if (strlen($phone) >= 10 && str_contains($digits, substr($phone, -10))) {
            $out['phone'] = substr($phone, -10);
        }
        $names = array_values(array_filter(array_map(static fn($n): string => trim(Security::clean((string) $n, 120)), (array) ($d['names'] ?? [])),
            static fn(string $n): bool => mb_strlen($n) >= 2 && preg_match('/\d/', $n) !== 1));
        if (count($names) >= 2) {
            $out['names'] = array_slice($names, 0, 12);
        }
        $amount = (float) ($d['amount'] ?? 0);
        if ($amount > 0 && $amount < 1000000) {
            $out['amount'] = round($amount, 2);
        }
        $days = (int) ($d['days'] ?? 0);
        if ($days > 0 && $days <= 366) {
            $out['days'] = $days;
        }
        $field = strtolower((string) ($out['field'] ?? ''));
        $out['field'] = match ($field) {
            'boarding', 'pickup', 'board', 'stop' => 'pickup',
            'mobile', 'number', 'contact', 'phone' => 'phone',
            'berth', 'seat' => 'seat',
            'name', 'date', 'gender', 'age' => $field,
            default => '',
        };
        $answer = trim((string) ($d['answer'] ?? ''));
        if ($answer !== '') {
            // Plain WhatsApp text: no markdown headings, no thinking leftovers.
            $answer = trim((string) preg_replace(['/^#+\s*/m', '#<think>.*?</think>#s'], '', $answer));
            $out['answer'] = mb_substr($answer, 0, 900);
        }
        return $out;
    }
}
