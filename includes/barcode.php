<?php
/**
 * Barcode — a Code 128 (subset B) encoder for the ticket's verification strip.
 *
 * 6 Nov 2026 (master upgrade §2: "QR only for payment; a barcode or secure
 * code for verification"). The ticket carries ONE barcode holding the scan
 * code Ticket::scanCode() makes ("SHG<booking id>-<security code>"). The code
 * half is the HMAC-keyed security code, so a barcode cannot be made up for a
 * booking without the server's key. Phones read it with BarcodeDetector
 * (code_128) in admin/scan.php; a USB barcode gun types it into the PNR box.
 *
 * Pure PHP + GD, no library. Nothing here touches the database.
 */
declare(strict_types=1);

final class Barcode
{
    /** Code 128 symbol widths (bar, space, bar, space, bar, space), values 0..106. */
    private const P = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];
    private const START_B = 104;
    private const STOP    = 106;

    /**
     * The module widths of $text, starting with a bar (quiet zones not included).
     * @return list<int>
     */
    public static function widths(string $text): array
    {
        if ($text === '' || preg_match('/^[\x20-\x7E]+$/', $text) !== 1) {
            throw new InvalidArgumentException('Code 128B takes printable ASCII only');
        }
        $vals = [self::START_B];
        $sum  = self::START_B;
        foreach (str_split($text) as $i => $ch) {
            $v = ord($ch) - 32;
            $vals[] = $v;
            $sum += $v * ($i + 1);
        }
        $vals[] = $sum % 103;
        $vals[] = self::STOP;
        $out = [];
        foreach ($vals as $v) {
            foreach (str_split(self::P[$v]) as $w) {
                $out[] = (int) $w;
            }
        }
        return $out;
    }

    /** Total modules of $text (bars + spaces, no quiet zone). */
    public static function modules(string $text): int
    {
        return array_sum(self::widths($text));
    }

    /** Draw $text as bars on a GD image, top-left at ($x, $y). Returns the width drawn. */
    public static function draw(\GdImage $im, string $text, int $x, int $y, int $module, int $height, int $color): int
    {
        $cx  = $x;
        $bar = true;
        foreach (self::widths($text) as $w) {
            $px = $w * $module;
            if ($bar) {
                imagefilledrectangle($im, $cx, $y, $cx + $px - 1, $y + $height - 1, $color);
            }
            $cx += $px;
            $bar = !$bar;
        }
        return $cx - $x;
    }

    /** A stand-alone PNG (white, 10-module quiet zones), for the PDF ticket. */
    public static function png(string $text, int $module = 3, int $height = 120): string
    {
        $w  = (self::modules($text) + 20) * $module;
        $im = imagecreatetruecolor($w, $height);
        imagefilledrectangle($im, 0, 0, $w, $height, imagecolorallocate($im, 255, 255, 255));
        self::draw($im, $text, 10 * $module, 0, $module, $height, imagecolorallocate($im, 0, 0, 0));
        ob_start();
        imagepng($im);
        $bin = (string) ob_get_clean();
        imagedestroy($im);
        return $bin;
    }
}
