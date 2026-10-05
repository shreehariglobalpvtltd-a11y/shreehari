<?php
/**
 * =====================================================================
 *  OfferCard — the festival offer pamphlet (6 Oct 2026).
 *
 *  Owner: "10% off offer — mail, Facebook, website, jaha jaha haalna
 *  sakinchha; pamphlet banayera". One 1080×1350 PNG (Facebook / Instagram
 *  / WhatsApp status size) drawn with GD from the offer that is RUNNING in
 *  Admin → Offers (Fare::runningOffers()) and the live direction fares
 *  (Fare::dirFares()), so the picture can never promise a discount or a
 *  fare the checkout does not give. Nepali / Hindi shaped by DevShape, as
 *  on the ticket and the private-cabin card (includes/promocard.php).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/fare.php';

final class OfferCard
{
    public const LANGS = ['ne', 'hi', 'en'];

    /** The offer the card shows: the first running one, or null. */
    public static function offer(): ?array
    {
        $o = Fare::runningOffers()[0] ?? null;
        return $o !== null && (float) $o['value'] > 0 ? $o : null;
    }

    /** "10%" or "₹200". */
    public static function amount(array $o): string
    {
        return $o['type'] === 'percent'
            ? rtrim(rtrim(number_format((float) $o['value'], 2), '0'), '.') . '%'
            : '₹' . number_format((float) $o['value']);
    }

    /** A fare after the offer (what the checkout would charge for one seat). */
    public static function after(array $o, float $fare): int
    {
        $cut = $o['type'] === 'percent' ? round($fare * (float) $o['value'] / 100) : (float) $o['value'];
        if ((float) ($o['max'] ?? 0) > 0) {
            $cut = min($cut, (float) $o['max']);
        }
        return (int) max(0, round($fare - $cut));
    }

    public static function festive(array $o): bool
    {
        return preg_match('/dashain|tihar|dasain|दशैं|तिहार/iu', $o['title'] . ' ' . $o['code']) === 1;
    }

    /** @return array<string,string> */
    public static function copy(string $lang, array $o, string $site): array
    {
        $amt = self::amount($o);
        $h = (int) ($o['ahead'] ?? 0);
        $till = $o['until'] !== '' ? date('j M Y', (int) strtotime($o['until'])) : '';
        $fest = self::festive($o);
        $all = [
            'ne' => ['kicker' => $fest ? 'दशैं – तिहार विशेष' : 'विशेष अफर', 'off' => 'छुट',
                     'how' => $h > 0 ? 'यात्रा भन्दा ' . $h . ' घण्टा अगाडि बुक गर्नुहोस्' : 'अहिले नै बुक गर्नुहोस्',
                     'go' => 'घर जाने  Gujarat – Rupaidiha', 'back' => 'फर्कने  Rupaidiha – Gujarat',
                     'till' => $till !== '' ? $till . ' सम्म मात्र' : '', 'note' => 'छुट आफैं लाग्छ — कुनै कोड चाहिँदैन',
                     'cta' => 'WhatsApp वा ' . $site . ' मा बुक गर्नुहोस्', 'pickup' => 'आफ्नो pickup को भाडामा ' . $amt . ' छुट'],
            'hi' => ['kicker' => $fest ? 'दशहरा – दिवाली विशेष' : 'विशेष ऑफ़र', 'off' => 'छूट',
                     'how' => $h > 0 ? 'यात्रा से ' . $h . ' घंटे पहले बुक करें' : 'अभी बुक करें',
                     'go' => 'घर जाना  Gujarat – Rupaidiha', 'back' => 'वापसी  Rupaidiha – Gujarat',
                     'till' => $till !== '' ? $till . ' तक ही' : '', 'note' => 'छूट अपने-आप लगती है — कोई कोड नहीं',
                     'cta' => 'WhatsApp या ' . $site . ' पर बुक करें', 'pickup' => 'अपने pickup के किराये पर ' . $amt . ' छूट'],
            'en' => ['kicker' => $fest ? 'DASHAIN – TIHAR SPECIAL' : 'SPECIAL OFFER', 'off' => 'OFF',
                     'how' => $h > 0 ? 'Book ' . $h . ' hours or more before departure' : 'Book now',
                     'go' => 'Going home  Gujarat – Rupaidiha', 'back' => 'Coming back  Rupaidiha – Gujarat',
                     'till' => $till !== '' ? 'Till ' . $till . ' only' : '', 'note' => 'Applied automatically — no code needed',
                     'cta' => 'Book on WhatsApp or ' . $site, 'pickup' => $amt . ' off your pickup\'s fare'],
        ];
        return $all[in_array($lang, self::LANGS, true) ? $lang : 'ne'];
    }

    /** The caption for Facebook / WhatsApp / email (plain text, all three languages). */
    public static function caption(array $o, string $phone, string $site): string
    {
        $f = Fare::dirFares();
        $amt = self::amount($o);
        $h = (int) ($o['ahead'] ?? 0);
        $till = $o['until'] !== '' ? date('j M', (int) strtotime($o['until'])) : '';
        $fest = self::festive($o);
        return '🪔 ' . ($fest ? 'दशैं–तिहार अफर' : 'विशेष अफर') . ' — ' . $amt . ' छुट!' . "\n"
             . ($h > 0 ? 'यात्रा भन्दा ' . $h . ' घण्टा अगाडि बुक गर्नुहोस्, भाडामा ' . $amt . ' छुट पाउनुहोस्।' : 'भाडामा ' . $amt . ' छुट।') . "\n"
             . '🚌 घर जाने Gujarat → Rupaidiha: ₹' . number_format($f['toNepal']) . ' → ₹' . number_format(self::after($o, $f['toNepal'])) . "\n"
             . '🚌 फर्कने Rupaidiha → Gujarat: ₹' . number_format($f['toIndia']) . ' → ₹' . number_format(self::after($o, $f['toIndia'])) . "\n"
             . ($till !== '' ? '⏳ ' . $till . ' सम्म मात्र · ' : '') . 'छुट आफैं लाग्छ, कोड चाहिँदैन।' . "\n\n"
             . ($fest ? 'Dashain–Tihar offer' : 'Special offer') . ': ' . $amt . ' off when you book ' . ($h > 0 ? $h . '+ hours before departure' : 'now')
             . ($till !== '' ? ' (till ' . $till . ')' : '') . ".\n"
             . '📞 ' . $phone . '  ·  🌐 https://' . $site . "\n"
             . '#Dashain #Tihar #Nepal #Gujarat #BusTicket #SHariGlobal';
    }

    /** @return string PNG bytes */
    public static function png(string $lang, array $o, string $phone, string $site, ?string $logoFile): string
    {
        $copy = self::copy($lang, $o, $site);
        $fares = Fare::dirFares();
        $font = dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari.ttf';
        if (!class_exists('DevShape') && is_file(__DIR__ . '/devshape.php')) {
            require_once __DIR__ . '/devshape.php';
        }
        $txt = static function ($im, float $size, int $x, int $y, int $col, string $s, bool $bold = false) use ($font): void {
            if (class_exists('DevShape') && DevShape::needs($s)
                && DevShape::gdText($im, $size, $x, $y, $col, $s, $bold ? [[0, 0], [1, 0]] : [[0, 0]])) {
                return;
            }
            imagettftext($im, $size, 0, $x, $y, $col, $font, $s);
            if ($bold) {
                imagettftext($im, $size, 0, $x + 1, $y, $col, $font, $s);
                imagettftext($im, $size, 0, $x + 2, $y, $col, $font, $s);
            }
        };
        $wd = static function (float $size, string $s) use ($font): int {
            if (class_exists('DevShape') && DevShape::needs($s) && ($w = DevShape::gdWidth($size, $s)) !== null) {
                return $w;
            }
            $b = @imagettfbbox($size, 0, $font, $s);
            return $b ? (int) abs($b[2] - $b[0]) : 0;
        };

        $W = 1080; $H = 1350;
        $im = imagecreatetruecolor($W, $H);
        imageantialias($im, true);
        $c = static fn (string $hex, int $a = 0): int => imagecolorallocatealpha($im, (int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2)), $a);
        // Deep maroon at the top to festive orange at the bottom.
        for ($y = 0; $y < $H; $y++) {
            $t = $y / $H;
            imageline($im, 0, $y, $W, $y, imagecolorallocate($im, (int) (96 + 140 * $t), (int) (10 + 70 * $t), (int) (24 - 10 * $t)));
        }
        $gold = $c('#FFD166'); $white = $c('#FFFFFF'); $cream = $c('#FFF3D6'); $maroon = $c('#5E0B15'); $dark = $c('#3B1D00');
        $glow = $c('#FFD166', 100);

        // Soft rangoli rings behind the big number.
        foreach ([[540, 520, 640], [540, 520, 520], [540, 520, 400]] as $i => [$cx, $cy, $d]) {
            imagesetthickness($im, 3);
            imageellipse($im, $cx, $cy, $d, $d, $c('#FFD166', 70 + 15 * $i));
        }
        for ($k = 0; $k < 24; $k++) {
            $a = $k * M_PI / 12;
            imagefilledellipse($im, (int) (540 + cos($a) * 320), (int) (520 + sin($a) * 320), 14, 14, $c('#FFD166', 60));
        }
        imagesetthickness($im, 1);

        // Gold frame.
        imagesetthickness($im, 6);
        imagerectangle($im, 28, 28, $W - 29, $H - 29, $gold);
        imagesetthickness($im, 2);
        imagerectangle($im, 44, 44, $W - 45, $H - 45, $c('#FFD166', 50));
        imagesetthickness($im, 1);

        // Diyas (little oil lamps) along the top — drawn, the font has no emoji.
        $diya = static function (int $x, int $y) use ($im, $c): void {
            imagefilledarc($im, $x, $y, 70, 40, 0, 180, $c('#B5542A'), IMG_ARC_PIE);
            imagefilledrectangle($im, $x - 35, $y - 4, $x + 35, $y + 2, $c('#8A3A1A'));
            imagefilledellipse($im, $x, $y - 22, 18, 34, $c('#FFB000'));
            imagefilledellipse($im, $x, $y - 18, 9, 18, $c('#FFF3B0'));
            imagefilledellipse($im, $x, $y - 22, 46, 54, $c('#FFD166', 105));
        };
        foreach ([150, 330, 750, 930] as $dx) {
            $diya($dx, 130);
        }

        // Logo in the middle of the lamps.
        if ($logoFile !== null && is_file($logoFile)) {
            $li = @(str_ends_with(strtolower($logoFile), '.png') ? imagecreatefrompng($logoFile) : imagecreatefromjpeg($logoFile));
            if ($li !== false) {
                $lh = 120; $lw = (int) round(imagesx($li) * $lh / max(1, imagesy($li)));
                imagecopyresampled($im, $li, (int) (($W - $lw) / 2), 60, 0, 0, $lw, $lh, imagesx($li), imagesy($li));
                imagedestroy($li);
            }
        }

        // Kicker ribbon.
        $y = 240;
        $kw = $wd(30, $copy['kicker']) + 90;
        $kx = (int) (($W - $kw) / 2);
        imagefilledrectangle($im, $kx, $y, $kx + $kw, $y + 64, $gold);
        imagefilledpolygon($im, [$kx - 30, $y + 32, $kx, $y, $kx, $y + 64], $gold);
        imagefilledpolygon($im, [$kx + $kw + 30, $y + 32, $kx + $kw, $y, $kx + $kw, $y + 64], $gold);
        $txt($im, 30, $kx + 45, $y + 46, $maroon, $copy['kicker'], true);

        // The big number.
        $amt = self::amount($o);
        $y = 560;
        $bw = $wd(190, $amt);
        $txt($im, 190, (int) (($W - $bw) / 2) - 70, $y, $glow, $amt, true);
        $txt($im, 190, (int) (($W - $bw) / 2) - 76, $y - 6, $white, $amt, true);
        $txt($im, 64, (int) (($W + $bw) / 2) - 40, $y - 6, $gold, $copy['off'], true);

        // How to get it.
        $y = 680;
        $txt($im, 38, (int) (($W - $wd(38, $copy['how'])) / 2), $y, $cream, $copy['how'], true);

        // Fare boxes: before → after (direction default fares).
        $y = 740;
        $box = static function (int $x, int $y, string $label, float $from, int $to) use ($im, $c, $txt, $wd, $gold, $white, $cream): void {
            imagefilledrectangle($im, $x, $y, $x + 440, $y + 190, $c('#3B0A0F', 30));
            imagefilledrectangle($im, $x, $y, $x + 440, $y + 8, $gold);
            $txt($im, 22, $x + 24, $y + 52, $cream, $label, true);
            $old = '₹' . number_format($from);
            $txt($im, 34, $x + 24, $y + 120, $c('#E8C9A0'), $old);
            $ow = $wd(34, $old);
            imagesetthickness($im, 4);
            imageline($im, $x + 20, $y + 106, $x + 30 + $ow, $y + 96, $c('#FF5A5A'));
            imagesetthickness($im, 1);
            $txt($im, 56, $x + 50 + $ow, $y + 128, $gold, '₹' . number_format($to), true);
            $txt($im, 17, $x + 24, $y + 168, $white, 'per seat');
        };
        $box(80, $y, $copy['go'], $fares['toNepal'], self::after($o, $fares['toNepal']));
        $box(560, $y, $copy['back'], $fares['toIndia'], self::after($o, $fares['toIndia']));

        // Fine print.
        $y = 990;
        $txt($im, 24, (int) (($W - $wd(24, $copy['pickup'])) / 2), $y, $cream, $copy['pickup']);
        $y += 46;
        $txt($im, 24, (int) (($W - $wd(24, $copy['note'])) / 2), $y, $cream, $copy['note']);
        if ($copy['till'] !== '') {
            $y += 62;
            $tw = $wd(30, $copy['till']) + 60;
            $tx = (int) (($W - $tw) / 2);
            imagefilledrectangle($im, $tx, $y - 42, $tx + $tw, $y + 14, $maroon);
            $txt($im, 30, $tx + 30, $y, $gold, $copy['till'], true);
        }

        // Footer band.
        imagefilledrectangle($im, 29, $H - 170, $W - 30, $H - 30, $gold);
        $txt($im, 28, (int) (($W - $wd(28, $copy['cta'])) / 2), $H - 108, $dark, $copy['cta'], true);
        $ph = 'Call / WhatsApp  ' . $phone;
        $txt($im, 30, (int) (($W - $wd(30, $ph)) / 2), $H - 56, $maroon, $ph, true);

        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);
        return (string) ob_get_clean();
    }
}
