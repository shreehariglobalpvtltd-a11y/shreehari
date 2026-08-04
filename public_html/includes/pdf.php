<?php
/**
 * =====================================================================
 *  Pdf — a minimal, self-contained PDF writer.
 *
 *  Enough of the PDF 1.4 specification to lay out a branded bus ticket,
 *  boarding pass and GST invoice: text in the 14 standard fonts, filled
 *  and stroked rectangles, lines, and embedded PNG/JPEG images (used for
 *  the QR code and logo). No FPDF, no TCPDF, no Composer.
 *
 *  Coordinates are in points (72 per inch) with the origin at the
 *  top-left, which is friendlier than PDF's native bottom-left; the
 *  Y axis is flipped internally when the content stream is written.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Pdf
{
    private float $width;
    private float $height;

    /** @var array<int, string> page content streams */
    private array $pages = [];

    private string $buffer = '';

    /** @var array<string, array{0: string, 1: string}> font resource name => [base font, subtype] */
    private array $fonts = [];

    /** @var array<int, array{data: string, width: int, height: int, colorspace: string, bits: int, filter: string, name: string, smask?: string}> */
    private array $images = [];

    private int $imageCounter = 0;

    /** @var array<int, int> byte offset of each object, for the xref table */
    private array $offsets = [];

    public function __construct(float $widthPt = 595.28, float $heightPt = 841.89) // A4 portrait
    {
        $this->width  = $widthPt;
        $this->height = $heightPt;

        // The standard 14 fonts need no embedding.
        $this->fonts = [
            'F1' => ['Helvetica', 'Type1'],
            'F2' => ['Helvetica-Bold', 'Type1'],
            'F3' => ['Helvetica-Oblique', 'Type1'],
            'F4' => ['Courier', 'Type1'],
            'F5' => ['Times-Roman', 'Type1'],
            'F6' => ['Times-Bold', 'Type1'],
        ];

        $this->newPage();
    }

    public function width(): float
    {
        return $this->width;
    }

    public function height(): float
    {
        return $this->height;
    }

    public function newPage(): void
    {
        $this->pages[] = '';
    }

    private function stream(string $content): void
    {
        $index = count($this->pages) - 1;
        $this->pages[$index] .= $content . "\n";
    }

    /** Flip a top-left Y into PDF's bottom-left space. */
    private function y(float $top): float
    {
        return $this->height - $top;
    }


    /* =================================================================
     *  Drawing primitives
     * ================================================================= */

    /**
     * @param array{0:int,1:int,2:int} $rgb 0-255
     */
    public function setFillColor(array $rgb): void
    {
        $this->stream(sprintf('%.3F %.3F %.3F rg', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255));
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    public function setStrokeColor(array $rgb): void
    {
        $this->stream(sprintf('%.3F %.3F %.3F RG', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255));
    }

    public function setLineWidth(float $w): void
    {
        $this->stream(sprintf('%.3F w', $w));
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    public function rect(float $x, float $y, float $w, float $h, array $rgb, bool $fill = true): void
    {
        if ($fill) {
            $this->setFillColor($rgb);
        } else {
            $this->setStrokeColor($rgb);
        }

        $this->stream(sprintf(
            '%.2F %.2F %.2F %.2F re %s',
            $x,
            $this->y($y + $h),
            $w,
            $h,
            $fill ? 'f' : 'S'
        ));
    }

    /**
     * Rounded rectangle using Bézier corners.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    public function roundedRect(float $x, float $y, float $w, float $h, float $r, array $rgb, bool $fill = true): void
    {
        $r = min($r, $w / 2, $h / 2);
        $k = 0.5523; // circle-to-Bézier constant

        $yb = $this->y($y + $h); // bottom in PDF space
        $yt = $this->y($y);      // top in PDF space

        $this->stream(sprintf('%.2F %.2F m', $x + $r, $yb));
        $this->stream(sprintf('%.2F %.2F l', $x + $w - $r, $yb));
        $this->stream(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x + $w - $r + $k * $r, $yb, $x + $w, $yb + $r - $k * $r, $x + $w, $yb + $r));
        $this->stream(sprintf('%.2F %.2F l', $x + $w, $yt - $r));
        $this->stream(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x + $w, $yt - $r + $k * $r, $x + $w - $r + $k * $r, $yt, $x + $w - $r, $yt));
        $this->stream(sprintf('%.2F %.2F l', $x + $r, $yt));
        $this->stream(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x + $r - $k * $r, $yt, $x, $yt - $r + $k * $r, $x, $yt - $r));
        $this->stream(sprintf('%.2F %.2F l', $x, $yb + $r));
        $this->stream(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x, $yb + $r - $k * $r, $x + $r - $k * $r, $yb, $x + $r, $yb));

        if ($fill) {
            $this->setFillColor($rgb);
            $this->stream('f');
        } else {
            $this->setStrokeColor($rgb);
            $this->stream('S');
        }
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb, float $width = 0.5): void
    {
        $this->setStrokeColor($rgb);
        $this->setLineWidth($width);
        $this->stream(sprintf('%.2F %.2F m %.2F %.2F l S', $x1, $this->y($y1), $x2, $this->y($y2)));
    }

    /**
     * Draw a line of text.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    public function text(float $x, float $y, string $text, float $size = 11, string $font = 'F1', array $rgb = [0, 0, 0]): void
    {
        if ($text === '') {
            return;
        }

        $this->setFillColor($rgb);
        $this->stream('BT');
        $this->stream(sprintf('/%s %.2F Tf', $font, $size));
        // Baseline sits ~0.8em below the given top edge.
        $this->stream(sprintf('%.2F %.2F Td', $x, $this->y($y + $size * 0.8)));
        $this->stream(sprintf('(%s) Tj', $this->escape($text)));
        $this->stream('ET');
    }

    /**
     * Right-aligned text ending at $xRight.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    public function textRight(float $xRight, float $y, string $text, float $size = 11, string $font = 'F1', array $rgb = [0, 0, 0]): void
    {
        $width = $this->textWidth($text, $size, $font);
        $this->text($xRight - $width, $y, $text, $size, $font, $rgb);
    }

    /**
     * Centre text within [$xLeft, $xRight].
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    public function textCenter(float $xLeft, float $xRight, float $y, string $text, float $size = 11, string $font = 'F1', array $rgb = [0, 0, 0]): void
    {
        $width = $this->textWidth($text, $size, $font);
        $this->text($xLeft + (($xRight - $xLeft) - $width) / 2, $y, $text, $size, $font, $rgb);
    }

    /**
     * Word-wrap text within a column, returning the Y just below it.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    public function textBlock(float $x, float $y, float $maxWidth, string $text, float $size = 10, string $font = 'F1', array $rgb = [0, 0, 0], float $lineHeight = 1.4): float
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $line  = '';
        $cursorY = $y;

        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;

            if ($this->textWidth($candidate, $size, $font) > $maxWidth && $line !== '') {
                $this->text($x, $cursorY, $line, $size, $font, $rgb);
                $cursorY += $size * $lineHeight;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }

        if ($line !== '') {
            $this->text($x, $cursorY, $line, $size, $font, $rgb);
            $cursorY += $size * $lineHeight;
        }

        return $cursorY;
    }

    /**
     * Approximate string width. Helvetica metrics are close enough for
     * layout of a one-page ticket; Courier is treated as monospaced.
     */
    public function textWidth(string $text, float $size, string $font = 'F1'): float
    {
        $base = $this->fonts[$font][0] ?? 'Helvetica';

        if (str_starts_with($base, 'Courier')) {
            return strlen($text) * $size * 0.6;
        }

        // Per-character widths (per 1000 units) for Helvetica — a compact
        // table covering the printable ASCII the ticket actually uses.
        static $widths = null;
        if ($widths === null) {
            $widths = self::helveticaWidths();
        }

        $bold  = str_contains($base, 'Bold');
        $total = 0.0;

        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char  = $text[$i];
            $units = $widths[$char] ?? 556;
            if ($bold) {
                $units *= 1.06;
            }
            $total += $units;
        }

        return ($total / 1000) * $size;
    }


    /* =================================================================
     *  Images (PNG / JPEG)
     * ================================================================= */

    /**
     * Embed a PNG or JPEG at the given box. Returns the resource name.
     *
     * Only 8-bit non-interlaced PNGs and baseline JPEGs are supported —
     * which is exactly what the QR generator and logo produce.
     */
    public function image(string $filePath, float $x, float $y, float $w, float $h): void
    {
        if (!is_file($filePath)) {
            return;
        }

        $raw  = (string) file_get_contents($filePath);
        $meta = $this->parseImage($raw, $filePath);

        if ($meta === null) {
            // Fall back to a bordered placeholder rather than failing the PDF.
            $this->rect($x, $y, $w, $h, [230, 230, 230], true);
            return;
        }

        $this->images[] = $meta;
        $name = $meta['name'];

        $this->stream('q');
        $this->stream(sprintf(
            '%.2F 0 0 %.2F %.2F %.2F cm',
            $w,
            $h,
            $x,
            $this->y($y + $h)
        ));
        $this->stream('/' . $name . ' Do');
        $this->stream('Q');
    }

    /**
     * Embed a PNG supplied as a binary string (the QR generator gives us
     * this straight from imagepng without a temp file).
     */
    public function imageFromString(string $binary, float $x, float $y, float $w, float $h): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shgpdf');
        if ($tmp === false) {
            Logger::error('PDF: tempnam() failed, QR/image dropped from output', [], 'pdf');
            return;
        }

        file_put_contents($tmp, $binary);
        $this->image($tmp, $x, $y, $w, $h);
        @unlink($tmp);
    }

    /**
     * @return array{data: string, width: int, height: int, colorspace: string, bits: int, filter: string, name: string, smask?: string}|null
     */
    private function parseImage(string $raw, string $path): ?array
    {
        $info = @getimagesizefromstring($raw);
        if ($info === false) {
            return null;
        }

        $this->imageCounter++;
        $name = 'Img' . $this->imageCounter;

        // JPEG — embed the raw DCT stream directly.
        if ($info[2] === IMAGETYPE_JPEG) {
            $colorspace = ($info['channels'] ?? 3) === 4 ? 'DeviceCMYK' : 'DeviceRGB';
            return [
                'data'       => $raw,
                'width'      => $info[0],
                'height'     => $info[1],
                'colorspace' => $colorspace,
                'bits'       => (int) ($info['bits'] ?? 8),
                'filter'     => 'DCTDecode',
                'name'       => $name,
            ];
        }

        // PNG — decode with GD and re-emit as a Flate RGB stream. This
        // sidesteps PNG's own chunk structure and interlacing entirely.
        if ($info[2] === IMAGETYPE_PNG && function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($raw);
            if ($img === false) {
                return null;
            }

            $w = imagesx($img);
            $h = imagesy($img);

            $rgb   = '';
            $alpha = '';
            $hasAlpha = false;

            for ($yy = 0; $yy < $h; $yy++) {
                for ($xx = 0; $xx < $w; $xx++) {
                    $colour = imagecolorat($img, $xx, $yy);
                    $r = ($colour >> 16) & 0xFF;
                    $g = ($colour >> 8) & 0xFF;
                    $b = $colour & 0xFF;
                    // GD alpha is 0 (opaque) .. 127 (transparent)
                    $a = ($colour >> 24) & 0x7F;

                    $rgb   .= chr($r) . chr($g) . chr($b);
                    $alpha .= chr(255 - (int) round($a * 255 / 127));
                    if ($a !== 0) {
                        $hasAlpha = true;
                    }
                }
            }

            imagedestroy($img);

            $meta = [
                'data'       => (string) gzcompress($rgb, 6),
                'width'      => $w,
                'height'     => $h,
                'colorspace' => 'DeviceRGB',
                'bits'       => 8,
                'filter'     => 'FlateDecode',
                'name'       => $name,
            ];

            if ($hasAlpha) {
                $meta['smask'] = (string) gzcompress($alpha, 6);
            }

            return $meta;
        }

        return null;
    }


    /* =================================================================
     *  Output
     * ================================================================= */

    private function escape(string $text): string
    {
        // PDF strings need escaping and use WinAnsi (Latin-1). Characters
        // outside Latin-1 (Devanagari) are dropped to a placeholder so the
        // stream stays valid — ticket labels are authored in English.
        $latin = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($latin === false) {
            $latin = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
        }

        return str_replace(
            ['\\', '(', ')', "\r", "\n"],
            ['\\\\', '\\(', '\\)', '\\r', '\\n'],
            $latin
        );
    }

    private function addObject(string $body): int
    {
        $this->offsets[] = strlen($this->buffer);
        $number = count($this->offsets);
        $this->buffer .= $number . " 0 obj\n" . $body . "\nendobj\n";
        return $number;
    }

    /**
     * Assemble the document and return the raw PDF bytes.
     */
    public function output(): string
    {
        $this->buffer  = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $this->offsets = [];

        $pageCount     = count($this->pages);
        $catalogId     = 1;
        $pagesId       = 2;

        // Reserve object ids: 1 catalog, 2 pages, then per-page (page +
        // content) objects, then fonts, then images.
        $firstPageObj  = 3;
        $pageObjectIds = [];
        $contentIds    = [];

        // Placeholder pass to know ids; we simply allocate sequentially.
        $nextId = $firstPageObj;
        for ($i = 0; $i < $pageCount; $i++) {
            $pageObjectIds[$i] = $nextId++;
            $contentIds[$i]    = $nextId++;
        }

        $fontIds = [];
        foreach ($this->fonts as $key => $spec) {
            $fontIds[$key] = $nextId++;
        }

        $imageIds = [];
        $smaskIds = [];
        foreach ($this->images as $index => $image) {
            if (isset($image['smask'])) {
                $smaskIds[$index] = $nextId++;
            }
            $imageIds[$index] = $nextId++;
        }

        // --- Catalog
        $this->addObject('<< /Type /Catalog /Pages ' . $pagesId . ' 0 R >>');

        // --- Pages tree
        $kids = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageObjectIds));
        $this->addObject(sprintf(
            '<< /Type /Pages /Count %d /Kids [ %s ] /MediaBox [0 0 %.2F %.2F] >>',
            $pageCount,
            $kids,
            $this->width,
            $this->height
        ));

        // Shared resource dictionary fragment.
        $fontRes = '';
        foreach ($fontIds as $key => $id) {
            $fontRes .= '/' . $key . ' ' . $id . ' 0 R ';
        }

        // --- Page + content objects
        foreach ($this->pages as $i => $content) {
            $xobjectRes = '';
            foreach ($this->images as $index => $image) {
                $xobjectRes .= '/' . $image['name'] . ' ' . $imageIds[$index] . ' 0 R ';
            }

            $resources = '<< /Font << ' . $fontRes . '>>';
            if ($xobjectRes !== '') {
                $resources .= ' /XObject << ' . $xobjectRes . '>>';
            }
            $resources .= ' >>';

            $this->addObject(sprintf(
                '<< /Type /Page /Parent %d 0 R /Contents %d 0 R /Resources %s >>',
                $pagesId,
                $contentIds[$i],
                $resources
            ));

            $compressed = (string) gzcompress($content, 6);
            $this->addObject(sprintf(
                "<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
                strlen($compressed),
                $compressed
            ));
        }

        // --- Fonts
        foreach ($this->fonts as $spec) {
            $this->addObject(sprintf(
                '<< /Type /Font /Subtype /%s /BaseFont /%s /Encoding /WinAnsiEncoding >>',
                $spec[1],
                $spec[0]
            ));
        }

        // --- Images (soft mask first when present)
        foreach ($this->images as $index => $image) {
            if (isset($image['smask'])) {
                $this->addObject(sprintf(
                    "<< /Type /XObject /Subtype /Image /Width %d /Height %d "
                    . "/ColorSpace /DeviceGray /BitsPerComponent 8 "
                    . "/Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
                    $image['width'],
                    $image['height'],
                    strlen($image['smask']),
                    $image['smask']
                ));
            }

            $extra = isset($image['smask']) ? ' /SMask ' . $smaskIds[$index] . ' 0 R' : '';

            $this->addObject(sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d "
                . "/ColorSpace /%s /BitsPerComponent %d /Filter /%s%s /Length %d >>\nstream\n%s\nendstream",
                $image['width'],
                $image['height'],
                $image['colorspace'],
                $image['bits'],
                $image['filter'],
                $extra,
                strlen($image['data']),
                $image['data']
            ));
        }

        // --- Cross-reference table
        $xrefOffset = strlen($this->buffer);
        $objectCount = count($this->offsets) + 1;

        $xref  = "xref\n0 " . $objectCount . "\n";
        $xref .= "0000000000 65535 f \n";
        foreach ($this->offsets as $offset) {
            $xref .= sprintf("%010d 00000 n \n", $offset);
        }

        $this->buffer .= $xref;
        $this->buffer .= sprintf(
            "trailer\n<< /Size %d /Root %d 0 R >>\nstartxref\n%d\n%%%%EOF",
            $objectCount,
            $catalogId,
            $xrefOffset
        );

        return $this->buffer;
    }

    /**
     * Write the PDF to disk.
     */
    public function save(string $path): string
    {
        ensureDir(dirname($path));
        $written = file_put_contents($path, $this->output());
        if ($written === false) {
            Logger::error('PDF: failed to write file (permissions or disk full)', ['path' => $path], 'pdf');
            throw new RuntimeException('Could not save PDF to ' . $path);
        }
        return $path;
    }

    /**
     * @return array<string, int>
     */
    private static function helveticaWidths(): array
    {
        // Widths in 1000-unit em space for the printable ASCII range.
        $w = [];
        $default = [
            ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667,
            "'" => 191, '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333,
            '.' => 278, '/' => 278, '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556,
            '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556, ':' => 278, ';' => 278,
            '<' => 584, '=' => 584, '>' => 584, '?' => 556, '@' => 1015, 'A' => 667, 'B' => 667,
            'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278,
            'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667,
            'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
            'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469,
            '_' => 556, '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556,
            'f' => 278, 'g' => 556, 'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222,
            'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333, 's' => 500,
            't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500,
            '{' => 334, '|' => 260, '}' => 334, '~' => 584,
        ];

        foreach ($default as $char => $width) {
            $w[$char] = $width;
        }

        return $w;
    }
}
