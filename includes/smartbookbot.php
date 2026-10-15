<?php
/**
 * =====================================================================
 *  SmartBookBot — QuickBot + Sahayak on the ONE engine (15 Oct 2026, §10).
 *
 *  "Ram 9876543210, 2 seats, Vadodara tomorrow, female" → the SAME fields
 *  the BookingCard has. The line is read on the server by the rule-based
 *  TicketBot::parse() (English + romanised Hindi / Nepali, Devanagari
 *  digits); missing fields come from SmartBook::defaults(). Code
 *  validates everything; the card shows the result and the passenger's
 *  Cash / Online tap is still the only thing that books (D4, D11).
 *
 *  A customer-facing line never accepts an agent / counter code (D13):
 *  parse() still recognises "SHG-027" so it is never mistaken for a phone
 *  number, but the field is dropped here.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/ticketbot.php';
require_once __DIR__ . '/smartbook.php';

final class SmartBookBot
{
    /**
     * @return array<string, mixed> name, phone, country, direction, date, point, pax[], pay,
     *                              found (what the line carried), missing (what the card must still ask)
     */
    public static function fields(string $text, string $mode = 'customer'): array
    {
        $p   = TicketBot::parse($text);
        $out = [
            'name'      => (string) $p['name'],
            'phone'     => (string) $p['phone'],
            'country'   => in_array($p['country'] ?? '', ['NP', '977'], true) ? 'NP' : ((string) ($p['country'] ?? '') !== '' ? 'IN' : ''),
            'direction' => in_array($p['direction'] ?? '', ['toNepal', 'toIndia'], true) ? (string) $p['direction'] : '',
            'date'      => Security::isValidDate((string) ($p['date'] ?? '')) ? (string) $p['date'] : '',
            'point'     => '',
            'pax'       => [],
            'pay'       => in_array($p['pay'] ?? '', ['cash', 'cod'], true) ? 'cash' : (in_array($p['pay'] ?? '', ['upi', 'esewa', 'online'], true) ? 'online' : ''),
            'found'     => array_values(array_diff((array) ($p['found'] ?? []), ['agentCode'])),
            'lang'      => (string) ($p['lang'] ?? 'en'),
        ];
        $dir = $out['direction'] !== '' ? $out['direction'] : 'toNepal';
        if ((string) $p['boarding'] !== '') {
            $names = array_map(static fn(array $x): string => $x['name'], SmartBook::points($dir, $mode)['list']);
            $out['point'] = SmartBook::matchPoint((string) $p['boarding'], $names);
            if ($out['point'] === '' && $out['direction'] === '') {
                // "Rupaidiha … Surat": the town may name a DROP of the return run
                $back = array_map(static fn(array $x): string => $x['name'], SmartBook::points('toIndia', $mode)['list']);
                $hit  = SmartBook::matchPoint((string) $p['boarding'], $back);
                if ($hit !== '' && stripos((string) $text, 'rupaidiha') !== false) {
                    $out['direction'] = 'toIndia';
                    $out['point']     = $hit;
                }
            }
        }
        $n = max(0, min(BookingService::maxSeatsFor($mode !== 'customer'), (int) $p['seats']));
        $g = AutoBerth::gender($p['gender'] ?? '');
        if ($n === 0 && $g !== null) {
            $n = 1;   // 15 Oct 2026: "Sita 98…, female, Vadodara bholi" = one traveller, gender known (desk Auto-book)
        }
        for ($i = 0; $i < $n; $i++) {
            $out['pax'][] = ['gender' => $i === 0 && $g !== null ? $g : '', 'band' => 'adult'];
        }
        $missing = [];
        foreach (['name', 'phone'] as $k) {
            if ($out[$k] === '') {
                $missing[] = $k;
            }
        }
        if ($out['pax'] === [] || in_array('', array_map(static fn(array $x): string => $x['gender'], $out['pax']), true)) {
            $missing[] = 'gender';
        }
        $out['missing'] = $missing;
        return $out;
    }
}
