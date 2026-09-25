<?php
/**
 * =====================================================================
 *  PromoCard — the shareable "Private cabin" picture (23 Sep 2026, v3
 *  brief §6). 1080×1350 PNG for WhatsApp status / groups / social, drawn
 *  with GD from the live cabin prices (Fare::pricing()), so it can never
 *  quote a fare the checkout does not charge. Served by admin/promo-card.php.
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class PromoCard
{
    public const LANGS = ['en', 'hi', 'ne'];

    public static function copy(string $lang, string $site): array
    {
        $all = [
            'en' => ['title' => 'PRIVATE CABIN', 'sub' => 'Your own sleeper cabin  ·  Gujarat - Rupaidiha',
                     'pts' => ['Full privacy - the cabin is yours alone', 'More space, your own light and charging', 'Perfect for couples, family and friends'],
                     'cta' => 'Book on WhatsApp or ' . $site],
            'hi' => ['title' => 'प्राइवेट केबिन', 'sub' => 'आपका अपना स्लीपर केबिन  ·  गुजरात - रुपैडीहा',
                     'pts' => ['पूरी प्राइवेसी - केबिन सिर्फ़ आपका', 'ज़्यादा जगह, अपनी लाइट और चार्जिंग', 'कपल, परिवार और दोस्तों के लिए बेहतरीन'],
                     'cta' => 'WhatsApp या ' . $site . ' पर बुक करें'],
            'ne' => ['title' => 'प्राइभेट केबिन', 'sub' => 'तपाईंको आफ्नै स्लिपर केबिन  ·  गुजरात - रुपैडिहा',
                     'pts' => ['पूरा गोपनीयता - केबिन तपाईंको मात्र', 'बढी ठाउँ, आफ्नै बत्ती र चार्जिङ', 'जोडी, परिवार र साथीहरूका लागि उत्तम'],
                     'cta' => 'WhatsApp वा ' . $site . ' मा बुक गर्नुहोस्'],
        ];
        return $all[in_array($lang, self::LANGS, true) ? $lang : 'en'];
    }

    /** The WhatsApp text that goes with the picture. */
    public static function shareText(int $single, int $double, string $phone, string $site): string
    {
        return "*Private Cabin* - S Hari Global\nGujarat - Rupaidiha AC sleeper\n\nSingle cabin Rs " . number_format($single) . "\nDouble cabin Rs " . number_format($double)
             . "\n\nFull privacy · more space · perfect for family\nCall " . $phone . "\nhttps://" . $site;
    }

    /** @return string PNG bytes */
    public static function png(string $lang, int $single, int $double, string $phone, string $site, ?string $logoFile): string
    {
        $copy = self::copy($lang, $site);
        $font = dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari.ttf';
        $txt = static function ($im, float $size, int $x, int $y, int $col, string $s, bool $bold = false) use ($font): void {
            // Nepali / Hindi shaped by HarfBuzz, as on the ticket (integration, 24 Sep 2026).
            if (class_exists('DevShape') && DevShape::needs($s)
                && DevShape::gdText($im, $size, $x, $y, $col, $s, $bold ? [[0, 0], [1, 0]] : [[0, 0]])) {
                return;
            }
            imagettftext($im, $size, 0, $x, $y, $col, $font, $s);
            if ($bold) { imagettftext($im, $size, 0, $x + 1, $y, $col, $font, $s); }
        };
        $wd = static function (float $size, string $s) use ($font): int { if (class_exists('DevShape') && DevShape::needs($s) && ($w = DevShape::gdWidth($size, $s)) !== null) { return $w; } $b = @imagettfbbox($size, 0, $font, $s); return $b ? (int) abs($b[2] - $b[0]) : 0; };

        $W = 1080; $H = 1350;
        $im = imagecreatetruecolor($W, $H);
        imageantialias($im, true);
        $c = static fn(string $hex): int => imagecolorallocate($im, (int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2)));
        for ($y = 0; $y < $H; $y++) {
            $t = $y / $H;
            imageline($im, 0, $y, $W, $y, imagecolorallocate($im, (int) (12 + 6 * $t), (int) (48 - 20 * $t), (int) (108 - 60 * $t)));
        }
        $gold = $c('#F2C14E'); $white = $c('#FFFFFF'); $orange = $c('#F07C1F'); $pale = $c('#C7D6F0'); $navy = $c('#0C306C');
        $y = 70;
        if ($logoFile !== null && is_file($logoFile)) {
            $li = @(str_ends_with(strtolower($logoFile), '.png') ? imagecreatefrompng($logoFile) : imagecreatefromjpeg($logoFile));
            if ($li !== false) {
                $lh = 150; $lw = (int) round(imagesx($li) * $lh / max(1, imagesy($li)));
                imagecopyresampled($im, $li, (int) (($W - $lw) / 2), $y, 0, 0, $lw, $lh, imagesx($li), imagesy($li));
                imagedestroy($li);
            }
        }
        /* gold badge with a drawn crown (the font has no emoji) */
        $y = 300;
        $badge = 'PRIVATE  ·  COMFORT';
        $bw = $wd(22, $badge) + 96;
        $bx = (int) (($W - $bw) / 2);
        imagefilledrectangle($im, $bx, $y, $bx + $bw, $y + 54, $gold);
        imagefilledpolygon($im, [$bx + 22, $y + 40, $bx + 22, $y + 18, $bx + 31, $y + 30, $bx + 40, $y + 14, $bx + 49, $y + 30, $bx + 58, $y + 18, $bx + 58, $y + 40], $navy);
        $txt($im, 22, $bx + 72, $y + 38, $navy, $badge, true);
        $y += 130;
        $txt($im, 64, (int) (($W - $wd(64, $copy['title'])) / 2), $y, $white, $copy['title'], true);
        $y += 60;
        $txt($im, 24, (int) (($W - $wd(24, $copy['sub'])) / 2), $y, $pale, $copy['sub']);
        $y += 70;
        imagefilledrectangle($im, 90, $y, $W - 90, $y + 250, $c('#123A7A'));
        imagefilledrectangle($im, 90, $y, $W - 90, $y + 8, $orange);
        $txt($im, 20, 130, $y + 60, $pale, 'SINGLE CABIN  ·  1 person', true);
        $txt($im, 56, 130, $y + 140, $gold, '₹' . number_format($single), true);
        $txt($im, 20, 590, $y + 60, $pale, 'DOUBLE CABIN  ·  2 persons', true);
        $txt($im, 56, 590, $y + 140, $gold, '₹' . number_format($double), true);
        $txt($im, 18, 130, $y + 205, $pale, 'per cabin  ·  same price online and at the counter');
        $y += 320;
        foreach ($copy['pts'] as $pt) {
            imagefilledellipse($im, 120, $y - 10, 18, 18, $orange);
            $txt($im, 26, 150, $y, $white, $pt);
            $y += 66;
        }
        imagefilledrectangle($im, 0, $H - 150, $W, $H, $orange);
        $txt($im, 26, (int) (($W - $wd(26, $copy['cta'])) / 2), $H - 88, $white, $copy['cta'], true);
        $ph = 'Call  ' . $phone;
        $txt($im, 30, (int) (($W - $wd(30, $ph)) / 2), $H - 38, $white, $ph, true);

        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);
        return (string) ob_get_clean();
    }
}
