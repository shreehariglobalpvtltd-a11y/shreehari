<?php
/**
 * =====================================================================
 *  AiRules — the money rules, readable and changeable by message.
 *
 *  THE DIVISION OF LABOUR (owner ask, 26 Sep 2026)
 *  -----------------------------------------------
 *  The language model is allowed to UNDERSTAND a sentence — "Ahmedabad to
 *  Rupaidiha fare 2100 gara", "advance offer 15% gara", "offer band gara" —
 *  and nothing else. It hands this file a small, named intent. Everything
 *  that follows is ordinary PHP:
 *
 *    · who may command at all            → mayCommand()
 *    · is the value sane                 → validate() inside propose()
 *    · what exactly would change         → propose(), old value and new
 *    · the write itself                  → apply(), through Settings
 *    · the record of it                  → Logger::audit(), old → new
 *
 *  The model never sees a query, never builds one, and cannot reach a
 *  settings key that is not on the whitelist below. A change it proposes is
 *  shown back in words and is only written after the admin answers in a
 *  LATER message — the same two-step the office's other WhatsApp buttons use.
 *
 *  THREE INDEPENDENT GATES, all of which must be open:
 *    1. the sender resolves to an OFFICE account (manager / superadmin) —
 *       AiTools::whoIs() decides that from the staff register, not from
 *       anything in the message;
 *    2. `wa_rules_control` is on;
 *    3. the sender's number is on `wa_rules_numbers`.
 *  A customer number fails 1 and 3. A manager who is not on the list fails 3.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiRules
{
    /**
     * The ONLY settings keys a message may move, each with its own type,
     * bounds and human label. A key that is not here cannot be reached,
     * whatever the model asks for.
     *
     * @var array<string, array{type: string, label: string, min?: float, max?: float, group: string, public: bool}>
     */
    private const WRITABLE = [
        'advance_offer_on'      => ['type' => 'bool',  'label' => 'advance offer ON/OFF',        'group' => 'pricing', 'public' => true],
        'advance_offer_hours'   => ['type' => 'int',   'label' => 'advance booking hours',       'group' => 'pricing', 'public' => true, 'min' => 0,  'max' => 8760],
        'advance_offer_percent' => ['type' => 'float', 'label' => 'advance discount percent',    'group' => 'pricing', 'public' => true, 'min' => 0,  'max' => 100],
        'advance_offer_from'    => ['type' => 'date',  'label' => 'offer start date',            'group' => 'pricing', 'public' => true],
        'advance_offer_to'      => ['type' => 'date',  'label' => 'offer end date',              'group' => 'pricing', 'public' => true],
        'advance_offer_max_inr' => ['type' => 'float', 'label' => 'largest discount in rupees',  'group' => 'pricing', 'public' => true, 'min' => 0,  'max' => 1000000],
        'advance_offer_text'    => ['type' => 'text',  'label' => 'offer line on the home page', 'group' => 'pricing', 'public' => true],
        'fare_to_nepal'         => ['type' => 'float', 'label' => 'fallback fare Gujarat → Rupaidiha', 'group' => 'pricing', 'public' => true, 'min' => 1, 'max' => 1000000],
        'fare_to_india'         => ['type' => 'float', 'label' => 'fallback fare Rupaidiha → Gujarat', 'group' => 'pricing', 'public' => true, 'min' => 1, 'max' => 1000000],
    ];

    /* =================================================================
     *  Gate
     * ================================================================= */

    /**
     * May this sender change the money rules?
     *
     * @param  array<string,mixed> $ctx from AiTools::whoIs() / whoIsWeb()
     * @return array{ok: bool, why: string}
     */
    public static function mayCommand(array $ctx): array
    {
        if ((string) ($ctx['role'] ?? '') !== 'admin') {
            return ['ok' => false, 'why' => 'Only the office can change fares or offers. Please call the office.'];
        }
        if (!Settings::getBool('wa_rules_control', false)) {
            return ['ok' => false, 'why' => 'Changing fares by message is switched off. Turn it on in Admin → Fares & offers.'];
        }

        $allowed = self::allowedNumbers();
        if ($allowed === []) {
            return ['ok' => false, 'why' => 'No number is authorised for fare changes yet. Add one in Admin → Fares & offers.'];
        }

        $mine = normalisePhone((string) ($ctx['phone'] ?? ''));
        if ($mine === '' || !in_array($mine, $allowed, true)) {
            return ['ok' => false, 'why' => 'This number is not authorised to change fares. Do it in Admin → Fares & offers.'];
        }

        /* A web session reaches here only for a signed-in office account whose
           staff record carries an authorised number — the same person, the
           same proof. Nothing in the message can supply that number. */
        return ['ok' => true, 'why' => ''];
    }

    /**
     * The authorised numbers, normalised and de-duplicated.
     *
     * The de-duplication uses each number as an array KEY, and PHP turns a
     * numeric-string key into an int — so array_keys() handed back
     * [9726401507, …] as integers and the strict in_array() below refused
     * the office's own number. Cast back explicitly; this list is compared
     * against a normalised phone STRING everywhere it is used.
     *
     * @return array<int, string>
     */
    public static function allowedNumbers(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', Settings::getString('wa_rules_numbers', '')) ?: [] as $n) {
            $d = normalisePhone($n);
            if ($d !== '') {
                $out[$d] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    /* =================================================================
     *  Read
     * ================================================================= */

    /**
     * Everything a chatbot may say about pricing. No secrets, no numbers
     * belonging to staff, no settings key outside the whitelist.
     *
     * @return array<string, mixed>
     */
    public static function read(): array
    {
        $offer = Fare::advanceOffer();
        $cp    = Settings::getArray('cabin_pricing', Fare::pricing());

        return [
            'board'  => array_map(static fn(array $b): array => [
                'from'   => $b['from'],
                'to'     => $b['to'],
                'amount' => (float) $b['amount'],
                'pricedBy' => $b['source'] === 'rule' ? 'a fare rule' : 'the direction fallback',
            ], Fare::fareBoard()),
            'rules'  => Fare::fareRules(),
            'fallback' => Fare::dirFares(),
            'vip'    => [
                'single' => (float) ($cp['private']['single_1pax']['offline'] ?? 0),
                'double' => (float) ($cp['private']['double_2pax']['offline'] ?? 0),
            ],
            'advance' => [
                'on'      => (bool) $offer['on'],
                'running' => (bool) $offer['live'],
                'hours'   => (int) $offer['hours'],
                'percent' => (float) $offer['percent'],
                'from'    => (string) $offer['from'],
                'to'      => (string) $offer['to'],
                'max'     => (float) $offer['max'],
                'modes'   => (string) $offer['modes'],
                'title'   => (string) $offer['title'],
                'text'    => (string) $offer['text'],
            ],
            'offers' => Fare::runningOffers(),
        ];
    }

    /** The same thing as plain sentences, for a bot that must read it aloud. */
    public static function summary(): string
    {
        $r     = self::read();
        $lines = ['Fares now in force (per person, sharing sleeper):'];
        foreach ($r['board'] as $b) {
            $lines[] = '• ' . $b['from'] . ' → ' . $b['to'] . ' — ' . inr($b['amount']);
        }
        $lines[] = 'VIP private cabin: single ' . inr($r['vip']['single']) . ', double ' . inr($r['vip']['double']) . '.';

        $a = $r['advance'];
        $pct = rtrim(rtrim(number_format($a['percent'], 2, '.', ''), '0'), '.');
        $lines[] = 'Advance offer: ' . ($a['running'] ? 'RUNNING' : ($a['on'] ? 'ON but outside its dates' : 'OFF'))
            . ' — ' . $pct . '% when booked ' . $a['hours'] . ' hours or more before departure'
            . ($a['max'] > 0 ? ', up to ' . inr($a['max']) : '')
            . ($a['modes'] === 'all' ? ', on sharing and VIP private' : ', on ' . $a['modes'] . ' only')
            . (($a['from'] !== '' || $a['to'] !== '')
                ? ' (' . ($a['from'] !== '' ? $a['from'] : 'any') . ' to ' . ($a['to'] !== '' ? $a['to'] : 'any') . ')'
                : '') . '.';

        foreach ($r['offers'] as $o) {
            $lines[] = 'Running offer: ' . Fare::offerLine($o);
        }
        return implode("\n", $lines);
    }

    /* =================================================================
     *  Propose — what would change, in words, with nothing written
     * ================================================================= */

    /**
     * Turn a named intent into a checked, described change.
     *
     * Two shapes only:
     *   ['what' => 'fare', 'from' => 'Ahmedabad', 'to' => 'Rupaidiha', 'value' => 2100]
     *   ['what' => 'setting', 'key' => 'advance_offer_percent', 'value' => 15]
     *
     * @param  array<string,mixed> $args
     * @return array{ok: bool, why?: string, change?: array<string,mixed>, say?: string}
     */
    public static function propose(array $args): array
    {
        $what = strtolower(trim((string) ($args['what'] ?? '')));

        if ($what === 'fare') {
            return self::proposeFare($args);
        }
        if ($what === 'setting') {
            return self::proposeSetting($args);
        }
        return ['ok' => false, 'why' => 'Say either a fare (from, to, amount) or one setting (key, value).'];
    }

    /** @return array{ok: bool, why?: string, change?: array<string,mixed>, say?: string} */
    private static function proposeFare(array $args): array
    {
        $from = trim((string) ($args['from'] ?? ''));
        $to   = trim((string) ($args['to'] ?? ''));
        $amt  = (float) ($args['value'] ?? 0);

        if ($from === '' || $to === '') {
            return ['ok' => false, 'why' => 'Which journey? Give both the pickup and the destination.'];
        }
        if ($amt <= 0 || $amt > 1000000) {
            return ['ok' => false, 'why' => 'The fare must be between ₹1 and ₹10,00,000.'];
        }

        /* The names are resolved to OFFICIAL points here, so the rule that
           gets written cannot name a town that does not exist and then never
           match anything. A zone token is allowed through as itself. */
        $fromC = str_starts_with($from, '@') || $from === '*' ? $from : Fare::canonicalPoint($from);
        $toC   = str_starts_with($to, '@')   || $to === '*'   ? $to   : Fare::canonicalPoint($to);

        $known = static function (string $p): bool {
            if ($p === '*' || strtolower($p) === '@india' || strtolower($p) === '@nepal') {
                return true;
            }
            $pts = Fare::mainPoints();
            foreach (array_merge((array) ($pts['india'] ?? []), (array) ($pts['nepal'] ?? [])) as $k) {
                if (Fare::pkey((string) $k) === Fare::pkey($p)) {
                    return true;
                }
            }
            return false;
        };
        if (!$known($fromC) || !$known($toC)) {
            return ['ok' => false, 'why' => 'I do not sell ' . ($known($fromC) ? $toC : $fromC)
                . '. The points are: ' . implode(', ', array_merge(
                    (array) (Fare::mainPoints()['india'] ?? []),
                    (array) (Fare::mainPoints()['nepal'] ?? [])
                )) . '.'];
        }

        $before  = Fare::pointFare($fromC, $toC);
        $existing = Fare::ruleFare($fromC, $toC);

        /* An EXACT rule for this pair is edited in place; anything else (a
           zone catch-all, or no rule at all) gets a new rule inserted ABOVE
           the catch-alls, because the board is first-match-wins and a new row
           appended to the end would never be reached. */
        $exact = $existing !== null
            && Fare::pkey($existing['from']) === Fare::pkey($fromC)
            && Fare::pkey($existing['to']) === Fare::pkey($toC);

        return [
            'ok'     => true,
            'change' => [
                'what'   => 'fare',
                'from'   => $fromC,
                'to'     => $toC,
                'amount' => round($amt, 2),
                'before' => $before,
                'mode'   => $exact ? 'edit' : 'insert',
                'index'  => $exact ? (int) $existing['index'] : null,
            ],
            'say' => sprintf(
                '%s → %s: %s becomes %s. %s Nothing is saved yet — reply ho to confirm.',
                $fromC,
                $toC,
                inr($before),
                inr($amt),
                $exact ? 'This edits the existing rule.' : 'This adds a new rule above the catch-alls.'
            ),
        ];
    }

    /** @return array{ok: bool, why?: string, change?: array<string,mixed>, say?: string} */
    private static function proposeSetting(array $args): array
    {
        $key = trim((string) ($args['key'] ?? ''));
        if (!isset(self::WRITABLE[$key])) {
            return ['ok' => false, 'why' => 'That is not something I can change from here. I can change: '
                . implode(', ', array_map(static fn(array $s): string => $s['label'], self::WRITABLE)) . '.'];
        }
        $spec = self::WRITABLE[$key];
        $raw  = $args['value'] ?? null;

        $before = (string) Settings::get($key, '');
        $after  = null;

        switch ($spec['type']) {
            case 'bool':
                $s = mb_strtolower(trim((string) (is_bool($raw) ? ($raw ? '1' : '0') : $raw)));
                if (in_array($s, ['1', 'on', 'true', 'yes', 'ho', 'chalu', 'start'], true)) {
                    $after = '1';
                } elseif (in_array($s, ['0', 'off', 'false', 'no', 'band', 'bandh', 'stop'], true)) {
                    $after = '0';
                } else {
                    return ['ok' => false, 'why' => 'Say on or off for the ' . $spec['label'] . '.'];
                }
                break;

            case 'int':
            case 'float':
                if (!is_numeric($raw)) {
                    return ['ok' => false, 'why' => 'Give a number for the ' . $spec['label'] . '.'];
                }
                $n = (float) $raw;
                if (isset($spec['min']) && $n < $spec['min']) {
                    return ['ok' => false, 'why' => 'The ' . $spec['label'] . ' cannot be below ' . $spec['min'] . '.'];
                }
                if (isset($spec['max']) && $n > $spec['max']) {
                    return ['ok' => false, 'why' => 'The ' . $spec['label'] . ' cannot be above ' . $spec['max'] . '.'];
                }
                $after = $spec['type'] === 'int' ? (string) (int) round($n) : (string) round($n, 2);
                break;

            case 'date':
                $s = trim((string) $raw);
                if ($s !== '' && !Security::isValidDate($s)) {
                    return ['ok' => false, 'why' => 'Dates must be YYYY-MM-DD, or say "clear" to remove it.'];
                }
                $after = mb_strtolower($s) === 'clear' ? '' : $s;
                break;

            default:      // text
                $after = Security::clean((string) $raw, 240);
                if (trim($after) === '') {
                    return ['ok' => false, 'why' => 'Give the words to show for the ' . $spec['label'] . '.'];
                }
                break;
        }

        if ($before === $after) {
            return ['ok' => false, 'why' => 'The ' . $spec['label'] . ' is already ' . ($after === '' ? 'blank' : $after) . '.'];
        }

        /* The two dates have to stay the right way round whichever one is
           being moved, or the offer silently stops running. */
        if ($key === 'advance_offer_from' && $after !== '') {
            $to = trim(Settings::getString('advance_offer_to', ''));
            if ($to !== '' && $after > $to) {
                return ['ok' => false, 'why' => 'That start date is after the offer\'s end date (' . $to . ').'];
            }
        }
        if ($key === 'advance_offer_to' && $after !== '') {
            $from = trim(Settings::getString('advance_offer_from', ''));
            if ($from !== '' && $after < $from) {
                return ['ok' => false, 'why' => 'That end date is before the offer\'s start date (' . $from . ').'];
            }
        }

        $human = static function (string $k, string $v): string {
            if ($v === '') {
                return 'blank';
            }
            if ($k === 'advance_offer_on') {
                return $v === '1' ? 'ON' : 'OFF';
            }
            if (str_contains($k, 'percent')) {
                /* rtrim($v, '0') on the plain string "10" left "1", so the
                   preview read "1% becomes 12%" for a live 10% offer — the
                   one number an admin is most likely to be confirming.
                   Trim only what follows a decimal point. */
                return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') . '%';
            }
            if ($k === 'advance_offer_hours') {
                return $v . ' hours';
            }
            if (str_starts_with($k, 'fare_') || str_contains($k, '_inr')) {
                return inr((float) $v);
            }
            return $v;
        };

        return [
            'ok'     => true,
            'change' => ['what' => 'setting', 'key' => $key, 'value' => $after, 'before' => $before],
            'say'    => sprintf(
                '%s: %s becomes %s. Nothing is saved yet — reply ho to confirm.',
                ucfirst($spec['label']),
                $human($key, $before),
                $human($key, $after)
            ),
        ];
    }

    /* =================================================================
     *  Apply — the write, and the record of it
     * ================================================================= */

    /**
     * Perform a change that propose() built and an admin confirmed.
     *
     * Re-validates from scratch: a staged proposal is a description, never a
     * permission, and the board may have moved between the two messages.
     *
     * @param  array<string,mixed> $change from propose()['change']
     * @param  array<string,mixed> $ctx    whoIs() — who is answerable for it
     * @return array{ok: bool, say: string, data: array<string,mixed>}
     */
    public static function apply(array $change, array $ctx): array
    {
        $gate = self::mayCommand($ctx);
        if (!$gate['ok']) {
            return ['ok' => false, 'say' => $gate['why'], 'data' => []];
        }

        $who = trim((string) ($ctx['name'] ?? '')) !== ''
            ? (string) $ctx['name']
            : ('admin ' . (int) ($ctx['adminId'] ?? 0));
        $via = ((string) ($ctx['channel'] ?? 'whatsapp')) === 'web' ? 'the assistant on the site' : 'WhatsApp';
        $tag = $who . ' via ' . $via . ' (' . normalisePhone((string) ($ctx['phone'] ?? '')) . ')';

        if (($change['what'] ?? '') === 'fare') {
            $fresh = self::proposeFare([
                'from'  => (string) ($change['from'] ?? ''),
                'to'    => (string) ($change['to'] ?? ''),
                'value' => (float) ($change['amount'] ?? 0),
            ]);
            if (!$fresh['ok']) {
                return ['ok' => false, 'say' => (string) $fresh['why'], 'data' => []];
            }
            $c = $fresh['change'];

            $rules = Fare::fareRules();
            $old   = $rules;

            if ($c['mode'] === 'edit' && $c['index'] !== null && isset($rules[$c['index']])) {
                $rules[$c['index']]['amount'] = (float) $c['amount'];
                $rules[$c['index']]['note']   = 'set by ' . $who . ' on ' . todayISO();
            } else {
                /* First-match-wins: put the new rule above the first rule that
                   would have swallowed it, so it can actually be reached. */
                $row = [
                    'from'   => (string) $c['from'],
                    'to'     => (string) $c['to'],
                    'amount' => (float) $c['amount'],
                    'note'   => 'added by ' . $who . ' on ' . todayISO(),
                ];
                $at = 0;
                foreach ($rules as $i => $r) {
                    if (str_starts_with(trim($r['from']), '@') || trim($r['from']) === '*'
                        || str_starts_with(trim($r['to']), '@') || trim($r['to']) === '*') {
                        $at = $i;
                        break;
                    }
                    $at = $i + 1;
                }
                array_splice($rules, $at, 0, [$row]);
            }

            $clean = Fare::normaliseRules($rules);
            if ($clean === []) {
                return ['ok' => false, 'say' => 'That would leave no usable fare rule, so nothing was saved.', 'data' => []];
            }

            Settings::set('fare_rules', $clean, 'json', 'pricing', true);
            Settings::flush();

            Logger::audit(
                'pricing.set',
                'setting',
                'fare_rules',
                ['value' => json_encode($old)],
                ['value' => json_encode($clean)],
                sprintf('%s → %s set to %s by %s', $c['from'], $c['to'], inr((float) $c['amount']), $tag)
            );

            return [
                'ok'   => true,
                'say'  => sprintf('Saved. %s → %s is now %s. It applies to the next search and the next sale; tickets already sold keep their price.',
                                  $c['from'], $c['to'], inr((float) $c['amount'])),
                'data' => ['from' => $c['from'], 'to' => $c['to'], 'amount' => (float) $c['amount'],
                           'before' => (float) $c['before'], 'rules' => count($clean)],
            ];
        }

        if (($change['what'] ?? '') === 'setting') {
            $key = (string) ($change['key'] ?? '');
            if (!isset(self::WRITABLE[$key])) {
                return ['ok' => false, 'say' => 'That setting cannot be changed from here.', 'data' => []];
            }
            $fresh = self::proposeSetting(['key' => $key, 'value' => $change['value'] ?? null]);
            if (!$fresh['ok']) {
                return ['ok' => false, 'say' => (string) $fresh['why'], 'data' => []];
            }
            $spec  = self::WRITABLE[$key];
            $value = (string) $fresh['change']['value'];
            $old   = (string) $fresh['change']['before'];

            Settings::set($key, $value, $spec['type'] === 'date' || $spec['type'] === 'text' ? 'string' : $spec['type'],
                          $spec['group'], $spec['public']);
            Settings::flush();

            Logger::audit(
                'pricing.set',
                'setting',
                $key,
                ['value' => $old],
                ['value' => $value],
                sprintf('%s changed from %s to %s by %s', $spec['label'],
                        $old === '' ? '(blank)' : $old, $value === '' ? '(blank)' : $value, $tag)
            );

            return [
                'ok'   => true,
                'say'  => 'Saved. ' . ucfirst($spec['label']) . ' is now '
                          . ($value === '' ? 'blank' : ($key === 'advance_offer_on' ? ($value === '1' ? 'ON' : 'OFF') : $value)) . '.',
                'data' => ['key' => $key, 'before' => $old, 'after' => $value],
            ];
        }

        return ['ok' => false, 'say' => 'Nothing recognisable to change.', 'data' => []];
    }
}
