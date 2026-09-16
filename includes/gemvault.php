<?php
/**
 * =====================================================================
 *  GemVault — every name and phone that reaches this company is an asset.
 *
 *  A passenger's number arrives through four doors: the app sign-in, an
 *  inbound WhatsApp message, a counter sale and an agent sale. Before this
 *  file only the FIRST of those created a durable record — Auth::loginUser()
 *  upserts `users`. A walk-in whose ticket the desk typed out, or a
 *  customer who has only ever messaged WhatsApp, existed solely as a
 *  `bookings.contact_phone` string. They had to re-dictate their name, town
 *  and country on every single visit, and the office could not answer
 *  "how many customers do we actually have?" at all.
 *
 *  So: one vault, keyed by the normalised 10-digit phone, which is the one
 *  identity every channel already agrees on (normalisePhone() maps
 *  +977-98…, 0091 98…, and a bare 98… to the same string).
 *
 *  THREE RULES THIS FILE KEEPS
 *  ---------------------------
 *  1. NEVER break a sale. upsert() and enrich() are called from post-commit
 *     positions and swallow every Throwable. A vault write failing must cost
 *     the company a profile row, never a ticket.
 *  2. ENRICHMENT IS ONE-WAY. A booking may add or sharpen what we know; it
 *     may never blank a field. A later sale with no name does not erase the
 *     name we already had.
 *  3. NO GENDER, EVER. The seat engine locks a shared cabin on the gender
 *     recorded per passenger at sale time. A REMEMBERED gender would let a
 *     months-old guess drive that lock, so this table has no such column and
 *     this class never reads one. Same reason no ID number is stored.
 *
 *  WHO MAY READ A GEM
 *  ------------------
 *  recall() takes an explicit viewer, because the ambient session cannot
 *  answer the question on its own: Auth::bookingScopeAdminId() returns NULL
 *  both for the office (may see everything) and for an anonymous public
 *  request (may see nothing). Passing 'self' is the caller stating "I have
 *  verified this request came from the owner of this number" — the WhatsApp
 *  webhook does that with the Twilio-signed From: header, the app with the
 *  session phone. A scoped counter agent never gets the vault view; they get
 *  TicketBot::profile(), which filters to their own book (8 Sep 2026).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class GemVault
{
    /** Channels we accept in the `channels` breadcrumb list. */
    private const CHANNELS = ['app', 'whatsapp', 'counter', 'agent', 'web', 'admin'];

    /** Request-scoped memo so one webhook turn does not re-read the vault five times. */
    private static array $memo = [];

    /**
     * Is this a number we must never message and never count as a customer?
     *
     * The desk types 0000000000 for a walk-in who gave no phone, and that
     * placeholder became the single most "frequent traveller" in the brain's
     * hot list until it was special-cased (7 Sep 2026). Repeated-digit and
     * short strings are the same class of non-number.
     */
    public static function isPlaceholder(string $phoneRaw): bool
    {
        $p = normalisePhone($phoneRaw);
        if ($p === '' || strlen($p) < 10) {
            return true;
        }
        // 0000000000, 1111111111, 9999999999 …
        if (preg_match('/^(\d)\1+$/', $p) === 1) {
            return true;
        }
        return !Security::isValidPhone($p);
    }

    /**
     * Record a contact. Called from every door; safe to call on every hit.
     *
     * Returns the normalised phone that was stored, or '' when the number was
     * a placeholder or the write failed. NEVER throws.
     *
     * @param string $channel one of self::CHANNELS
     * @param string $country 'NP'/'IN' or a dialing code ('977'/'91'); '' when
     *                        unknown — an unknown country is left NULL rather
     *                        than defaulted, because a wrong country sends the
     *                        ticket to a stranger.
     */
    public static function upsert(
        string $phoneRaw,
        string $name = '',
        string $country = '',
        string $channel = '',
        ?int $userId = null
    ): string {
        try {
            $phone = normalisePhone($phoneRaw);
            if ($phone === '' || self::isPlaceholder($phone)) {
                return '';
            }

            $clean = $name !== '' ? Security::clean($name, 120) : '';
            if (mb_strlen($clean) < 2) {
                $clean = '';
            }

            // 'NP'/'IN' and '977'/'91' both arrive here; countryDialCode() maps
            // the first, and the second we accept as-is. Anything else is
            // unknown, and unknown stays NULL.
            $cc = countryDialCode($country);
            if ($cc === '') {
                $digits = preg_replace('/\D/', '', $country) ?? '';
                $cc     = ($digits === '977' || $digits === '91') ? $digits : '';
            }

            $ch  = in_array($channel, self::CHANNELS, true) ? $channel : '';
            $now = date('Y-m-d H:i:s');

            $existing = Database::fetch(
                'SELECT phone, full_name, country_code, channels, user_id FROM user_profiles WHERE phone = :p LIMIT 1',
                ['p' => $phone]
            );

            if ($existing === null) {
                Database::query(
                    'INSERT IGNORE INTO user_profiles
                        (phone, user_id, full_name, country_code, channels, first_seen_at, last_seen_at)
                     VALUES (:p, :u, :n, :c, :ch, :f, :l)',
                    [
                        'p'  => $phone,
                        'u'  => $userId,
                        'n'  => $clean !== '' ? $clean : null,
                        'c'  => $cc !== '' ? $cc : null,
                        'ch' => $ch !== '' ? $ch : null,
                        'f'  => $now,
                        'l'  => $now,
                    ]
                );
                self::forget($phone);
                return $phone;
            }

            /* ---- one-way enrichment ---------------------------------
               Only ever fill a blank or replace with something the caller
               explicitly supplied. A counter sale that recorded no name must
               not wipe the name the customer gave at sign-in. */
            $update = ['last_seen_at' => $now];

            if ($clean !== '' && $clean !== (string) ($existing['full_name'] ?? '')) {
                // The name typed most recently is the name that prints on the
                // ticket, so a corrected spelling wins — same rule as
                // Auth::loginUser().
                $update['full_name'] = $clean;
            }
            if ($cc !== '' && $cc !== (string) ($existing['country_code'] ?? '')) {
                $update['country_code'] = $cc;
            }
            if ($userId !== null && (int) ($existing['user_id'] ?? 0) !== $userId) {
                $update['user_id'] = $userId;
            }
            if ($ch !== '') {
                $seen = array_values(array_filter(explode(',', (string) ($existing['channels'] ?? ''))));
                if (!in_array($ch, $seen, true)) {
                    $seen[] = $ch;
                    $update['channels'] = substr(implode(',', $seen), 0, 120);
                }
            }

            Database::update('user_profiles', $update, 'phone = :p', ['p' => $phone]);
            self::forget($phone);
            return $phone;
        } catch (Throwable $e) {
            self::warn('vault upsert failed', $phoneRaw, $e);
            return '';
        }
    }

    /**
     * Recompute the travel block for one phone from its CONFIRMED bookings.
     *
     * Unscoped on purpose: this runs as the system (post-commit hook or the
     * nightly hygiene job), not on behalf of an agent. Writing an aggregate
     * leaks nothing; recall() is where the scoping happens.
     *
     * Learns only from 'confirmed' and 'completed' — a pending booking that
     * later expires is not a travel habit.
     *
     * @return array<string,mixed>|null the values written, or null when there
     *                                  is nothing to learn from yet.
     */
    public static function enrich(string $phoneRaw): ?array
    {
        try {
            $phone = normalisePhone($phoneRaw);
            if ($phone === '' || self::isPlaceholder($phone)) {
                return null;
            }

            $rows = Database::fetchAll(
                "SELECT b.id, b.total_amount, b.is_cod, b.source, b.created_at, b.contact_country_code,
                        l.travel_date, l.boarding_stop, l.seat_count, s.route_id,
                        r.to_city
                   FROM bookings b
              LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
              LEFT JOIN schedules s    ON s.id = l.schedule_id
              LEFT JOIN routes r       ON r.id = s.route_id
                  WHERE b.contact_phone = :p
                    AND b.status IN ('confirmed','completed')
                  ORDER BY b.created_at DESC
                  LIMIT 200",
                ['p' => $phone]
            );

            if ($rows === []) {
                return null;
            }

            $stops = [];        // townKey => count
            $stopLabel = [];    // townKey => most recent display label
            $party = 0;
            $partyN = 0;
            $ltv = 0.0;
            $pay = [];
            $country = '';

            foreach ($rows as $r) {
                $key = Boarding::townKey((string) ($r['boarding_stop'] ?? ''));
                if ($key !== '') {
                    $stops[$key] = ($stops[$key] ?? 0) + 1;
                    if (!isset($stopLabel[$key])) {
                        $stopLabel[$key] = (string) $r['boarding_stop'];
                    }
                }
                $n = max(1, (int) ($r['seat_count'] ?? 1));
                $party += $n;
                $partyN++;
                $ltv += (float) ($r['total_amount'] ?? 0);

                $mode = !empty($r['is_cod']) ? 'cod' : 'online';
                $pay[$mode] = ($pay[$mode] ?? 0) + 1;

                // First non-empty country stamp wins (rows are newest-first),
                // and it is a CAPTURED value, never derived from the digits.
                $cc = preg_replace('/\D/', '', (string) ($r['contact_country_code'] ?? '')) ?? '';
                if ($country === '' && ($cc === '977' || $cc === '91')) {
                    $country = $cc;
                }
            }

            $latest = $rows[0];

            arsort($stops);
            $topKey = (string) (array_key_first($stops) ?? '');

            arsort($pay);
            $topPay = (string) (array_key_first($pay) ?? '');

            $update = [
                'travel_count'     => count($rows),
                'lifetime_value'   => round($ltv, 2),
                'avg_party_size'   => $partyN > 0 ? round($party / $partyN, 2) : null,
                'last_route_id'    => $latest['route_id'] !== null ? (int) $latest['route_id'] : null,
                'last_travel_date' => $latest['travel_date'] ?? null,
                'last_booked_at'   => $latest['created_at'] ?? null,
                'preferred_payment' => $topPay !== '' ? $topPay : null,
                'enriched_at'      => date('Y-m-d H:i:s'),
            ];

            /* The DERIVED block is a recomputation, not an accumulation, so
               every field here is assigned on every run — including to NULL.
               "One-way enrichment" protects the CONTACT facts (name, phone,
               country): those are things the customer told us, and a later
               blank must never erase them. A habit is different. If the
               evidence stops supporting "prefers the lower deck", keeping the
               old answer does not preserve data, it preserves a wrong
               prediction — and this value is about to be used to pick a
               berth for them. */
            $update['last_direction'] = !empty($latest['to_city'])
                ? QuickTicket::directionOf(['to_city' => (string) $latest['to_city']])
                : null;

            $update['preferred_boarding_key'] = $topKey !== '' ? $topKey : null;
            $update['preferred_boarding']     = $topKey !== '' ? ($stopLabel[$topKey] ?? null) : null;

            $deck = self::dominantDeck(array_map(static fn(array $r): int => (int) $r['id'], $rows));
            $update['preferred_deck'] = $deck !== '' ? $deck : null;

            // Country is a CONTACT fact: one-way, and only ever from a value
            // that was captured on a booking.
            if ($country !== '') {
                $update['country_code'] = $country;
            }

            /* The name is on the TICKET, not on the booking header, so a gem
               backfilled from years of counter sales would otherwise have no
               name at all — and a nameless gem cannot save a returning
               customer a single keystroke, which is the entire point.
               One-way, like every other contact fact: this FILLS a blank, and
               never overwrites a name somebody typed at sign-in. */
            $existingName = (string) Database::scalar(
                'SELECT full_name FROM user_profiles WHERE phone = :p LIMIT 1',
                ['p' => $phone],
                ''
            );
            if (trim($existingName) === '') {
                $learntName = self::primaryNameFor(array_map(static fn(array $r): int => (int) $r['id'], $rows));
                if ($learntName !== '') {
                    $update['full_name'] = $learntName;
                }
            }

            // The row may not exist yet (a sale for a number that never signed
            // in). Create the shell first so the UPDATE has something to hit.
            Database::query(
                'INSERT IGNORE INTO user_profiles (phone, first_seen_at, last_seen_at) VALUES (:p, :n, :n2)',
                ['p' => $phone, 'n' => date('Y-m-d H:i:s'), 'n2' => date('Y-m-d H:i:s')]
            );
            Database::update('user_profiles', $update, 'phone = :p', ['p' => $phone]);
            self::forget($phone);

            return $update;
        } catch (Throwable $e) {
            self::warn('vault enrich failed', $phoneRaw, $e);
            return null;
        }
    }

    /**
     * The name this passenger travels under, taken from the most recent
     * booking where they were the lead passenger.
     *
     * Most recent rather than most frequent: a corrected spelling is the
     * newest one, and the newest one is what should print on the next ticket.
     * A bracketed "(2)" placeholder that the bulk-seat form generates for
     * unnamed companions is not a name and is skipped.
     *
     * @param list<int> $bookingIds newest first
     */
    private static function primaryNameFor(array $bookingIds): string
    {
        $ids = array_slice(array_values(array_filter($bookingIds)), 0, 40);
        if ($ids === []) {
            return '';
        }
        $in   = implode(',', array_map('intval', $ids));
        $rows = Database::fetchAll(
            "SELECT booking_id, full_name FROM booking_passengers
              WHERE booking_id IN ({$in}) AND is_primary = 1 AND full_name <> ''
              ORDER BY booking_id DESC, id"
        );
        foreach ($rows as $r) {
            $nm = trim((string) $r['full_name']);
            if ($nm === '' || mb_strlen($nm) < 2) {
                continue;
            }
            if (preg_match('/\(\d+\)$/', $nm) === 1) {
                continue;   // "Ram (2)" — a generated companion slot, not a person
            }
            return Security::clean($nm, 120);
        }
        return '';
    }

    /**
     * Which deck does this passenger actually sleep on?
     *
     * Deck comes from the L/U prefix of the physical berth, which is stable
     * across every coach configuration. Window-vs-aisle deliberately is NOT
     * stored: that depends on the seats-per-row of the CURRENT mode map, and
     * changing a ratio re-interprets historic seat labels (Seats::mapChangeImpact).
     * A stored "window" would silently become wrong. Position is computed live
     * from Seats::layoutFor() at ranking time instead.
     *
     * @param list<int> $bookingIds
     * @return string 'lower' | 'upper' | ''
     */
    private static function dominantDeck(array $bookingIds): string
    {
        $ids = array_slice(array_values(array_filter($bookingIds)), 0, 200);
        if ($ids === []) {
            return '';
        }
        $in = implode(',', array_map('intval', $ids));
        $rows = Database::fetchAll(
            "SELECT seat_no FROM booking_seats WHERE booking_id IN ({$in}) AND released_at IS NULL"
        );
        $tally = ['lower' => 0, 'upper' => 0];
        foreach ($rows as $r) {
            $s = strtoupper(trim((string) $r['seat_no']));
            if ($s === '') {
                continue;
            }
            if ($s[0] === 'L') {
                $tally['lower']++;
            } elseif ($s[0] === 'U') {
                $tally['upper']++;
            }
        }
        if ($tally['lower'] === 0 && $tally['upper'] === 0) {
            return '';
        }
        // A tie is not a preference.
        if ($tally['lower'] === $tally['upper']) {
            return '';
        }
        return $tally['lower'] > $tally['upper'] ? 'lower' : 'upper';
    }

    /**
     * Everything we may tell THIS viewer about this number.
     *
     * @param string $viewer 'self'   — caller has verified the request is from
     *                                  the owner of this number (signed-in
     *                                  customer, Twilio-signed WhatsApp From:).
     *                       'system' — cron / internal job, no session.
     *                       'staff'  — a signed-in staff member (default). A
     *                                  counter agent is scoped to their own
     *                                  book; the office sees the vault.
     * @return array<string,mixed>|null
     */
    public static function recall(string $phoneRaw, string $viewer = 'staff'): ?array
    {
        $phone = normalisePhone($phoneRaw);
        if ($phone === '' || self::isPlaceholder($phone)) {
            return null;
        }

        if ($viewer === 'staff' && Auth::bookingScopeAdminId() !== null) {
            /* A scoped counter agent may only learn about passengers out of
               their OWN book. TicketBot::profile() already enforces exactly
               that, so hand the question straight to it and expose no vault
               row — otherwise one agent could read another agent's customers
               a number at a time, which is the leak the 8 Sep scoping closed. */
            return self::fromBotProfile($phone);
        }

        if (isset(self::$memo[$phone][$viewer])) {
            return self::$memo[$phone][$viewer];
        }

        try {
            $row = Database::fetch(
                'SELECT * FROM user_profiles WHERE phone = :p LIMIT 1',
                ['p' => $phone]
            );
            if ($row === null) {
                return self::$memo[$phone][$viewer] = self::fromBotProfile($phone);
            }

            // A phone the hygiene job folded into another (typo variant):
            // follow the pointer once so callers always land on the real gem.
            $merged = (string) ($row['merged_into'] ?? '');
            if ($merged !== '' && $merged !== $phone) {
                $row = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p LIMIT 1', ['p' => $merged]) ?? $row;
            }

            $out = [
                'phone'         => (string) $row['phone'],
                'name'          => (string) ($row['full_name'] ?? ''),
                'country'       => (string) ($row['country_code'] ?? ''),
                'lang'          => (string) ($row['preferred_lang'] ?? ''),
                'trips'         => (int) $row['travel_count'],
                'lifetimeValue' => (float) $row['lifetime_value'],
                'partySize'     => $row['avg_party_size'] !== null ? (float) $row['avg_party_size'] : null,
                'lastRouteId'   => $row['last_route_id'] !== null ? (int) $row['last_route_id'] : null,
                'lastDirection' => (string) ($row['last_direction'] ?? ''),
                'lastTravel'    => (string) ($row['last_travel_date'] ?? ''),
                'lastBooked'    => (string) ($row['last_booked_at'] ?? ''),
                'boarding'      => (string) ($row['preferred_boarding'] ?? ''),
                'boardingKey'   => (string) ($row['preferred_boarding_key'] ?? ''),
                'deck'          => (string) ($row['preferred_deck'] ?? ''),
                'payment'       => (string) ($row['preferred_payment'] ?? ''),
                'channels'      => array_values(array_filter(explode(',', (string) ($row['channels'] ?? '')))),
                'returning'     => (int) $row['travel_count'] > 0,
                'source'        => 'vault',
            ];

            return self::$memo[$phone][$viewer] = $out;
        } catch (Throwable $e) {
            self::warn('vault recall failed', $phoneRaw, $e);
            return null;
        }
    }

    /**
     * The scoped fallback: shape TicketBot::profile() into the recall() contract
     * so callers have ONE array shape to read regardless of who is asking.
     *
     * @return array<string,mixed>|null
     */
    private static function fromBotProfile(string $phone): ?array
    {
        try {
            require_once __DIR__ . '/ticketbot.php';
            $p = TicketBot::profile($phone);
            if ($p === null) {
                return null;
            }

            $trips    = (int) ($p['trips'] ?? 0);
            $boarding = is_array($p['boarding'] ?? null) ? $p['boarding'] : [];
            $stopLabel = (string) ($boarding['label'] ?? '');

            /* Two fields of profile() are deliberately NOT carried across:
                 · 'gender'  — a remembered gender must never reach the seat
                               lock (see the file header).
                 · 'country' — profile() infers it from id_type, and an
                               INFERRED country is exactly what sent Nepali
                               tickets to strangers in India. Only a captured
                               country_code counts, and a scoped agent's view
                               has none, so it stays blank. */
            return [
                'phone'         => $phone,
                'name'          => (string) ($p['name'] ?? ''),
                'country'       => '',
                'lang'          => '',
                'trips'         => $trips,
                'lifetimeValue' => round((float) ($p['avgTotal'] ?? 0) * $trips, 2),
                'partySize'     => isset($p['party']['size']) ? (float) $p['party']['size'] : null,
                'lastRouteId'   => null,
                'lastDirection' => (string) ($p['direction']['value'] ?? ''),
                'lastTravel'    => (string) ($p['lastTrip'] ?? ''),
                'lastBooked'    => (string) ($p['lastBooked'] ?? ''),
                'boarding'      => $stopLabel,
                'boardingKey'   => $stopLabel !== '' ? Boarding::townKey($stopLabel) : '',
                'deck'          => (string) ($p['deck']['value'] ?? ''),
                'payment'       => '',
                'channels'      => [],
                'returning'     => $trips > 0,
                'source'        => 'scoped-profile',
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Post-commit hook: a booking just reached a settled state, so record the
     * contact and re-learn their habits.
     *
     * Wired to EventBus 'booking.approved' rather than called from inside
     * BookingService::create(): emit() runs after the transaction commits and
     * never throws, so no vault problem can roll back or 500 a sale.
     *
     * @param array<string,mixed> $booking
     */
    public static function noteBooking(array $booking): void
    {
        $phone = (string) ($booking['contact_phone'] ?? '');
        if ($phone === '') {
            return;
        }

        $source  = (string) ($booking['source'] ?? 'web');
        $channel = match ($source) {
            'counter' => 'counter',
            'agent'   => 'agent',
            'admin'   => 'admin',
            'app'     => 'app',
            default   => 'web',
        };

        $cc = preg_replace('/\D/', '', (string) ($booking['contact_country_code'] ?? '')) ?? '';

        $name = '';
        try {
            $name = (string) Database::scalar(
                "SELECT full_name FROM booking_passengers
                  WHERE booking_id = :b AND is_primary = 1 ORDER BY id LIMIT 1",
                ['b' => (int) ($booking['id'] ?? 0)],
                ''
            );
        } catch (Throwable $ignored) {
            // A missing name is not worth a failed hook.
        }

        $userId = isset($booking['user_id']) && $booking['user_id'] !== null
            ? (int) $booking['user_id']
            : null;

        self::upsert($phone, $name, $cc, $channel, $userId);
        self::enrich($phone);
    }

    /**
     * Vault-wide counts for the hygiene job and the owner's health screen.
     *
     * @return array<string,int>
     */
    public static function stats(): array
    {
        try {
            $cut = date('Y-m-d', strtotime('-90 days'));
            return [
                'gems'        => (int) Database::scalar('SELECT COUNT(*) FROM user_profiles WHERE merged_into IS NULL', [], 0),
                'named'       => (int) Database::scalar('SELECT COUNT(*) FROM user_profiles WHERE merged_into IS NULL AND full_name IS NOT NULL', [], 0),
                'withCountry' => (int) Database::scalar("SELECT COUNT(*) FROM user_profiles WHERE merged_into IS NULL AND country_code IN ('977','91')", [], 0),
                'active90'    => (int) Database::scalar('SELECT COUNT(*) FROM user_profiles WHERE merged_into IS NULL AND last_travel_date >= :d', ['d' => $cut], 0),
                'returning'   => (int) Database::scalar('SELECT COUNT(*) FROM user_profiles WHERE merged_into IS NULL AND travel_count > 1', [], 0),
                'placeholder' => (int) Database::scalar('SELECT COUNT(*) FROM user_profiles WHERE is_placeholder = 1', [], 0),
                'merged'      => (int) Database::scalar('SELECT COUNT(*) FROM user_profiles WHERE merged_into IS NOT NULL', [], 0),
            ];
        } catch (Throwable $e) {
            return ['gems' => 0, 'named' => 0, 'withCountry' => 0, 'active90' => 0, 'returning' => 0, 'placeholder' => 0, 'merged' => 0];
        }
    }

    /** Drop the request memo for one phone (or all of it). */
    public static function forget(string $phone = ''): void
    {
        if ($phone === '') {
            self::$memo = [];
            return;
        }
        unset(self::$memo[normalisePhone($phone)]);
    }

    /** One place for "the vault had a problem", with the number masked. */
    private static function warn(string $what, string $phone, Throwable $e): void
    {
        try {
            Logger::warning($what, [
                'phone' => maskPhone($phone),
                'error' => $e->getMessage(),
            ], 'gemvault');
        } catch (Throwable $ignored) {
            // Logging must never take the caller down either.
        }
    }
}
