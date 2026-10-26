<?php
/**
 * =====================================================================
 *  QrCode — self-contained QR Code generator.
 *
 *  No Composer package, no CDN, no external service. Everything needed
 *  to turn a string into a scannable PNG lives in this one file, so it
 *  runs on the most locked-down shared host.
 *
 *  Supports byte mode, QR versions 1–10 (up to ~271 bytes at level M —
 *  far more than a ticket payload needs) and all four error-correction
 *  levels. Implements the full Reed–Solomon + masking pipeline from the
 *  ISO/IEC 18004 specification.
 *
 *  Usage:
 *      QrCode::png('SHG-TICKET|...', '/path/out.png', 8);
 *      $dataUri = QrCode::dataUri('upi://pay?...');
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class QrCode
{
    public const ECC_L = 1;   // ~7%  recovery
    public const ECC_M = 0;   // ~15% recovery  (default — good print balance)
    public const ECC_Q = 3;   // ~25%
    public const ECC_H = 2;   // ~30%

    /** Total data codewords per (version, ecc level). Versions 1–10. */
    private const DATA_CODEWORDS = [
        // [L, M, Q, H]  — indexed so [level] maps via ORDER below
        1  => [19, 16, 13, 9],
        2  => [34, 28, 22, 16],
        3  => [55, 44, 34, 26],
        4  => [80, 64, 48, 36],
        5  => [108, 86, 62, 46],
        6  => [136, 108, 76, 60],
        7  => [156, 124, 88, 66],
        8  => [194, 154, 110, 86],
        9  => [232, 182, 132, 100],
        10 => [274, 216, 154, 122],
    ];

    /** ECC codewords per block and block layout, per (version, level). */
    private const ECC_BLOCKS = [
        // version => level => [ecCodewordsPerBlock, [ [count, dataCodewords], ... ] ]
        1  => [0 => [10, [[1, 16]]],  1 => [7, [[1, 19]]],  3 => [13, [[1, 13]]],  2 => [17, [[1, 9]]]],
        2  => [0 => [16, [[1, 28]]],  1 => [10, [[1, 34]]], 3 => [22, [[1, 22]]],  2 => [28, [[1, 16]]]],
        3  => [0 => [26, [[1, 44]]],  1 => [15, [[1, 55]]], 3 => [18, [[2, 17]]],  2 => [22, [[2, 13]]]],
        4  => [0 => [18, [[2, 32]]],  1 => [20, [[1, 80]]], 3 => [26, [[2, 24]]],  2 => [16, [[4, 9]]]],
        5  => [0 => [24, [[2, 43]]],  1 => [26, [[1, 108]]],3 => [18, [[2, 15], [2, 16]]], 2 => [22, [[2, 11], [2, 12]]]],
        6  => [0 => [16, [[4, 27]]],  1 => [18, [[2, 68]]], 3 => [24, [[4, 19]]],  2 => [28, [[4, 15]]]],
        7  => [0 => [18, [[4, 31]]],  1 => [20, [[2, 78]]], 3 => [18, [[2, 14], [4, 15]]], 2 => [26, [[4, 13], [1, 14]]]],
        8  => [0 => [22, [[2, 38], [2, 39]]], 1 => [24, [[2, 97]]], 3 => [22, [[4, 18], [2, 19]]], 2 => [26, [[4, 14], [2, 15]]]],
        9  => [0 => [22, [[3, 36], [2, 37]]], 1 => [30, [[2, 116]]], 3 => [20, [[4, 16], [4, 17]]], 2 => [24, [[4, 12], [4, 13]]]],
        10 => [0 => [26, [[4, 43], [1, 44]]], 1 => [18, [[2, 68], [2, 69]]], 3 => [24, [[6, 19], [2, 20]]], 2 => [28, [[6, 15], [2, 16]]]],
    ];

    /** Alignment-pattern centre coordinates per version. */
    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** GF(256) exponent and log tables, built once. */
    private static array $expTable = [];
    private static array $logTable = [];


    /* =================================================================
     *  Public API
     * ================================================================= */

    /**
     * Write a QR PNG to disk. Returns the path on success.
     */
    public static function png(string $text, string $path, int $scale = 8, int $margin = 4, int $ecc = self::ECC_M): string
    {
        $matrix = self::matrix($text, $ecc);
        $image  = self::renderPng($matrix, $scale, $margin);

        ensureDir(dirname($path));

        if (!imagepng($image, $path)) {
            imagedestroy($image);
            throw new RuntimeException('Could not write QR image to ' . $path);
        }

        imagedestroy($image);

        return $path;
    }

    /**
     * QR PNG as a base64 data URI — handy for embedding straight into
     * HTML or a generated PDF without a temp file.
     */
    public static function dataUri(string $text, int $scale = 8, int $margin = 4, int $ecc = self::ECC_M): string
    {
        $matrix = self::matrix($text, $ecc);
        $image  = self::renderPng($matrix, $scale, $margin);

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($binary);
    }

    /**
     * Raw module matrix (true = dark). Exposed for the PDF writer, which
     * draws QR modules as vector rectangles rather than a raster image.
     *
     * @return array<int, array<int, bool>>
     */
    public static function matrix(string $text, int $ecc = self::ECC_M): array
    {
        self::initGaloisField();

        $version = self::chooseVersion($text, $ecc);
        $bits    = self::encodeData($text, $version, $ecc);
        $final   = self::interleave($bits, $version, $ecc);

        return self::buildMatrix($final, $version, $ecc);
    }


    /* =================================================================
     *  Galois field GF(256) for Reed–Solomon
     * ================================================================= */

    private static function initGaloisField(): void
    {
        if (self::$expTable !== []) {
            return;
        }

        self::$expTable = array_fill(0, 512, 0);
        self::$logTable = array_fill(0, 256, 0);

        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$expTable[$i] = $x;
            self::$logTable[$x] = $i;

            $x <<= 1;
            if ($x & 0x100) {           // primitive polynomial 0x11D
                $x ^= 0x11D;
            }
        }

        for ($i = 255; $i < 512; $i++) {
            self::$expTable[$i] = self::$expTable[$i - 255];
        }
    }

    private static function gfMultiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$expTable[(self::$logTable[$a] + self::$logTable[$b]) % 255];
    }

    /**
     * Reed–Solomon error-correction codewords for one data block.
     *
     * @param array<int, int> $data
     * @return array<int, int>
     */
    private static function reedSolomon(array $data, int $eccCount): array
    {
        $generator = self::rsGenerator($eccCount);
        $remainder = array_merge($data, array_fill(0, $eccCount, 0));

        $dataLen = count($data);
        for ($i = 0; $i < $dataLen; $i++) {
            $coef = $remainder[$i];
            if ($coef === 0) {
                continue;
            }
            for ($j = 0; $j <= $eccCount; $j++) {
                $remainder[$i + $j] ^= self::gfMultiply($generator[$j], $coef);
            }
        }

        return array_slice($remainder, $dataLen, $eccCount);
    }

    /**
     * Generator polynomial for a given number of ECC codewords.
     *
     * @return array<int, int>
     */
    private static function rsGenerator(int $eccCount): array
    {
        $poly = [1];

        for ($i = 0; $i < $eccCount; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);

            foreach ($poly as $index => $coef) {
                $next[$index]     ^= $coef;
                $next[$index + 1] ^= self::gfMultiply($coef, self::$expTable[$i]);
            }

            $poly = $next;
        }

        return $poly;
    }


    /* =================================================================
     *  Data encoding (byte mode)
     * ================================================================= */

    private static function chooseVersion(string $text, int $ecc): int
    {
        $length = strlen($text);

        for ($version = 1; $version <= 10; $version++) {
            $capacityBits   = self::DATA_CODEWORDS[$version][self::eccOrder($ecc)] * 8;
            $charCountBits  = $version < 10 ? 8 : 16;
            // mode indicator (4) + char count + payload
            $requiredBits   = 4 + $charCountBits + ($length * 8);

            if ($requiredBits <= $capacityBits) {
                return $version;
            }
        }

        throw new RuntimeException('QR payload too large (' . $length . ' bytes). Shorten the ticket data.');
    }

    /**
     * Maps an ECC constant to its column order in the capacity tables.
     */
    private static function eccOrder(int $ecc): int
    {
        // Table columns are ordered L, M, Q, H.
        return match ($ecc) {
            self::ECC_L => 0,
            self::ECC_M => 1,
            self::ECC_Q => 2,
            self::ECC_H => 3,
            default     => 1,
        };
    }

    /**
     * Encode the payload into the padded data-codeword bit string.
     *
     * @return array<int, int> bits
     */
    private static function encodeData(string $text, int $version, int $ecc): array
    {
        $bits = [];

        // Mode indicator: byte mode = 0100
        self::appendBits($bits, 0b0100, 4);

        // Character count
        $charCountBits = $version < 10 ? 8 : 16;
        self::appendBits($bits, strlen($text), $charCountBits);

        // Payload
        foreach (str_split($text) as $char) {
            self::appendBits($bits, ord($char), 8);
        }

        $capacityCodewords = self::DATA_CODEWORDS[$version][self::eccOrder($ecc)];
        $capacityBits      = $capacityCodewords * 8;

        // Terminator (up to four zero bits)
        $terminator = min(4, $capacityBits - count($bits));
        self::appendBits($bits, 0, $terminator);

        // Pad to a byte boundary
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        // Pad bytes alternate 0xEC / 0x11 until the block is full
        $padBytes = [0xEC, 0x11];
        $index    = 0;
        while (count($bits) < $capacityBits) {
            self::appendBits($bits, $padBytes[$index % 2], 8);
            $index++;
        }

        return $bits;
    }

    /**
     * @param array<int, int> $bits
     */
    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }


    /* =================================================================
     *  Block interleaving + ECC
     * ================================================================= */

    /**
     * Split into blocks, compute ECC per block, then interleave data and
     * ECC codewords in the order the matrix placement expects.
     *
     * @param array<int, int> $bits
     * @return array<int, int> final bit stream
     */
    private static function interleave(array $bits, int $version, int $ecc): array
    {
        // Bits -> data codewords (bytes)
        $dataCodewords = [];
        for ($i = 0, $n = count($bits); $i < $n; $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | ($bits[$i + $j] ?? 0);
            }
            $dataCodewords[] = $byte;
        }

        [$eccPerBlock, $blockLayout] = self::ECC_BLOCKS[$version][$ecc];

        $dataBlocks = [];
        $eccBlocks  = [];
        $offset     = 0;

        foreach ($blockLayout as $group) {
            [$blockCount, $dataCount] = $group;

            for ($b = 0; $b < $blockCount; $b++) {
                $block         = array_slice($dataCodewords, $offset, $dataCount);
                $offset       += $dataCount;
                $dataBlocks[]  = $block;
                $eccBlocks[]   = self::reedSolomon($block, $eccPerBlock);
            }
        }

        // Interleave data codewords column by column.
        $result       = [];
        $maxDataLen   = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        // Then interleave ECC codewords.
        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        // Bytes -> bit stream
        $finalBits = [];
        foreach ($result as $byte) {
            self::appendBits($finalBits, $byte, 8);
        }

        return $finalBits;
    }


    /* =================================================================
     *  Matrix construction, masking and format info
     * ================================================================= */

    /**
     * @param array<int, int> $bits
     * @return array<int, array<int, bool>>
     */
    private static function buildMatrix(array $bits, int $version, int $ecc): array
    {
        $size = 21 + ($version - 1) * 4;

        // null = not yet set, so function patterns are distinguishable
        // from data during placement.
        $matrix   = array_fill(0, $size, array_fill(0, $size, null));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        self::placeFinderPatterns($matrix, $reserved, $size);
        self::placeSeparators($matrix, $reserved, $size);
        self::placeTimingPatterns($matrix, $reserved, $size);
        self::placeAlignmentPatterns($matrix, $reserved, $version);
        self::reserveFormatAreas($reserved, $size);
        if ($version >= 7) {
            self::reserveVersionAreas($reserved, $size);
        }

        // Dark module, always set.
        $matrix[$size - 8][8] = true;
        $reserved[$size - 8][8] = true;

        self::placeData($matrix, $reserved, $bits, $size);

        // Choose the mask that scores best, then bake it in.
        $bestMask   = self::selectMask($matrix, $reserved, $size);
        $maskedGrid = self::applyMask($matrix, $reserved, $size, $bestMask);

        self::placeFormatInfo($maskedGrid, $size, $ecc, $bestMask);
        if ($version >= 7) {
            self::placeVersionInfo($maskedGrid, $size, $version);
        }

        // Any module still null (shouldn't happen) becomes light.
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($maskedGrid[$r][$c] === null) {
                    $maskedGrid[$r][$c] = false;
                }
            }
        }

        return $maskedGrid;
    }

    /* Versions 7+ must carry two 6x3 version-information blocks. They were
       never written, so any payload over ~106 bytes (e.g. a UPI pay link)
       produced a QR that no scanner could read. */
    private static function reserveVersionAreas(array &$reserved, int $size): void
    {
        for ($i = 0; $i < 18; $i++) {
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $reserved[$b][$a] = true;
            $reserved[$a][$b] = true;
        }
    }

    private static function placeVersionInfo(array &$grid, int $size, int $version): void
    {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($version << 12) | $rem;

        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $grid[$b][$a] = $bit;
            $grid[$a][$b] = $bit;
        }
    }

    private static function placeFinderPatterns(array &$matrix, array &$reserved, int $size): void
    {
        $positions = [[0, 0], [$size - 7, 0], [0, $size - 7]];

        foreach ($positions as [$row, $col]) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $row + $r;
                    $cc = $col + $c;

                    if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) {
                        continue;
                    }

                    $isBorder = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                             || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6));
                    $isCore   = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;

                    $matrix[$rr][$cc]   = $isBorder || $isCore;
                    $reserved[$rr][$cc] = true;
                }
            }
        }
    }

    private static function placeSeparators(array &$matrix, array &$reserved, int $size): void
    {
        // Handled implicitly by the -1..7 sweep above writing false around
        // each finder; nothing extra needed, but keep the reserved flags.
        foreach ([[7, 0], [7, $size - 8], [$size - 8, 0]] as [$row, $col]) {
            for ($i = 0; $i < 8; $i++) {
                foreach ([[$row, $col + $i], [$row - 7 + $i, $col]] as [$r, $c]) {
                    if ($r >= 0 && $r < $size && $c >= 0 && $c < $size && $matrix[$r][$c] === null) {
                        $matrix[$r][$c]   = false;
                        $reserved[$r][$c] = true;
                    }
                }
            }
        }
    }

    private static function placeTimingPatterns(array &$matrix, array &$reserved, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $dark = ($i % 2 === 0);

            if ($matrix[6][$i] === null) {
                $matrix[6][$i]   = $dark;
                $reserved[6][$i] = true;
            }
            if ($matrix[$i][6] === null) {
                $matrix[$i][6]   = $dark;
                $reserved[$i][6] = true;
            }
        }
    }

    private static function placeAlignmentPatterns(array &$matrix, array &$reserved, int $version): void
    {
        $centres = self::ALIGNMENT[$version] ?? [];

        foreach ($centres as $row) {
            foreach ($centres as $col) {
                // Skip only the three finder corners. Testing $reserved here also
                // skipped (6,x)/(x,6), which sit on the timing lines from v7 up.
                $first = $centres[0];
                $last  = $centres[count($centres) - 1];
                if (($row === $first && ($col === $first || $col === $last)) || ($row === $last && $col === $first)) {
                    continue;
                }

                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $ring = max(abs($r), abs($c));
                        $matrix[$row + $r][$col + $c]   = ($ring !== 1);
                        $reserved[$row + $r][$col + $c] = true;
                    }
                }
            }
        }
    }

    private static function reserveFormatAreas(array &$reserved, int $size): void
    {
        for ($i = 0; $i < 9; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }
    }

    /**
     * Zig-zag data placement, skipping every reserved module.
     *
     * @param array<int, int> $bits
     */
    private static function placeData(array &$matrix, array $reserved, array $bits, int $size): void
    {
        $bitIndex  = 0;
        $bitCount  = count($bits);
        $direction = -1; // upward first

        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--; // the vertical timing column is skipped
            }

            for ($i = 0; $i < $size; $i++) {
                $row = $direction === -1 ? ($size - 1 - $i) : $i;

                for ($c = 0; $c < 2; $c++) {
                    $currentCol = $col - $c;

                    if ($reserved[$row][$currentCol] === true) {
                        continue;
                    }

                    $matrix[$row][$currentCol] = $bitIndex < $bitCount
                        ? ($bits[$bitIndex] === 1)
                        : false;
                    $bitIndex++;
                }
            }

            $direction = -$direction;
        }
    }

    /**
     * The eight mask predicates from the specification.
     */
    private static function maskCondition(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => (($row * $col) % 2) + (($row * $col) % 3) === 0,
            6 => ((($row * $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            7 => ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            default => false,
        };
    }

    /**
     * @return array<int, array<int, bool|null>>
     */
    private static function applyMask(array $matrix, array $reserved, int $size, int $mask): array
    {
        $out = $matrix;

        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($reserved[$r][$c] === true || $matrix[$r][$c] === null) {
                    continue;
                }
                if (self::maskCondition($mask, $r, $c)) {
                    $out[$r][$c] = !$matrix[$r][$c];
                }
            }
        }

        return $out;
    }

    private static function selectMask(array $matrix, array $reserved, int $size): int
    {
        $bestMask  = 0;
        $bestScore = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = self::applyMask($matrix, $reserved, $size, $mask);
            self::placeFormatInfo($candidate, $size, self::ECC_M, $mask); // format bits affect penalty
            $score = self::penalty($candidate, $size);

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMask  = $mask;
            }
        }

        return $bestMask;
    }

    /**
     * Standard four-rule penalty scoring for mask selection.
     */
    private static function penalty(array $grid, int $size): int
    {
        $penalty = 0;

        // Rule 1: runs of five or more same-colour modules in a line.
        for ($r = 0; $r < $size; $r++) {
            $runColour = null;
            $runLength = 0;
            for ($c = 0; $c < $size; $c++) {
                $cell = (bool) $grid[$r][$c];
                if ($cell === $runColour) {
                    $runLength++;
                } else {
                    if ($runLength >= 5) {
                        $penalty += 3 + ($runLength - 5);
                    }
                    $runColour = $cell;
                    $runLength = 1;
                }
            }
            if ($runLength >= 5) {
                $penalty += 3 + ($runLength - 5);
            }
        }
        for ($c = 0; $c < $size; $c++) {
            $runColour = null;
            $runLength = 0;
            for ($r = 0; $r < $size; $r++) {
                $cell = (bool) $grid[$r][$c];
                if ($cell === $runColour) {
                    $runLength++;
                } else {
                    if ($runLength >= 5) {
                        $penalty += 3 + ($runLength - 5);
                    }
                    $runColour = $cell;
                    $runLength = 1;
                }
            }
            if ($runLength >= 5) {
                $penalty += 3 + ($runLength - 5);
            }
        }

        // Rule 2: 2x2 blocks of one colour.
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = (bool) $grid[$r][$c];
                if ($v === (bool) $grid[$r][$c + 1]
                    && $v === (bool) $grid[$r + 1][$c]
                    && $v === (bool) $grid[$r + 1][$c + 1]) {
                    $penalty += 3;
                }
            }
        }

        // Rule 3: finder-like 1:1:3:1:1 patterns.
        $pattern1 = [true, false, true, true, true, false, true, false, false, false, false];
        $pattern2 = [false, false, false, false, true, false, true, true, true, false, true];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size - 10; $c++) {
                $match1 = true;
                $match2 = true;
                for ($k = 0; $k < 11; $k++) {
                    $cell = (bool) $grid[$r][$c + $k];
                    if ($cell !== $pattern1[$k]) $match1 = false;
                    if ($cell !== $pattern2[$k]) $match2 = false;
                }
                if ($match1 || $match2) $penalty += 40;
            }
        }
        for ($c = 0; $c < $size; $c++) {
            for ($r = 0; $r < $size - 10; $r++) {
                $match1 = true;
                $match2 = true;
                for ($k = 0; $k < 11; $k++) {
                    $cell = (bool) $grid[$r + $k][$c];
                    if ($cell !== $pattern1[$k]) $match1 = false;
                    if ($cell !== $pattern2[$k]) $match2 = false;
                }
                if ($match1 || $match2) $penalty += 40;
            }
        }

        // Rule 4: overall dark/light balance.
        $dark = 0;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($grid[$r][$c]) $dark++;
            }
        }
        $total   = $size * $size;
        $percent = ($dark * 100) / $total;
        $penalty += ((int) (abs($percent - 50) / 5)) * 10;

        return $penalty;
    }

    /**
     * Place the 15-bit format information (ECC level + mask) with its
     * BCH error correction, in both copies.
     */
    private static function placeFormatInfo(array &$grid, int $size, int $ecc, int $mask): void
    {
        $formatBits = self::formatBits($ecc, $mask);

        // Copy 1 — around the top-left finder.
        for ($i = 0; $i <= 5; $i++) {
            $grid[8][$i] = $formatBits[$i];
        }
        $grid[8][7] = $formatBits[6];
        $grid[8][8] = $formatBits[7];
        $grid[7][8] = $formatBits[8];
        for ($i = 9; $i <= 14; $i++) {
            $grid[14 - $i][8] = $formatBits[$i];
        }

        // Copy 2 — split across the other two finders.
        for ($i = 0; $i <= 7; $i++) {
            $grid[$size - 1 - $i][8] = $formatBits[$i];
        }
        for ($i = 8; $i <= 14; $i++) {
            $grid[8][$size - 15 + $i] = $formatBits[$i];
        }
    }

    /**
     * @return array<int, bool>
     */
    private static function formatBits(int $ecc, int $mask): array
    {
        // 5-bit data: 2 bits ECC level (spec order) + 3 bits mask.
        $eccBits = match ($ecc) {
            self::ECC_L => 0b01,
            self::ECC_M => 0b00,
            self::ECC_Q => 0b11,
            self::ECC_H => 0b10,
            default     => 0b00,
        };

        $data = ($eccBits << 3) | $mask;

        // BCH(15,5) error correction.
        $bch = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($bch >> $i) & 1) {
                $bch ^= 0b10100110111 << ($i - 10);
            }
        }

        $format = (($data << 10) | $bch) ^ 0b101010000010010;

        $bits = [];
        for ($i = 14; $i >= 0; $i--) {
            $bits[] = (($format >> $i) & 1) === 1;
        }

        return $bits;
    }


    /* =================================================================
     *  Rendering
     * ================================================================= */

    private static function renderPng(array $matrix, int $scale, int $margin): \GdImage
    {
        $modules   = count($matrix);
        $dimension = ($modules + 2 * $margin) * $scale;

        $image = imagecreatetruecolor($dimension, $dimension);

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        imagefilledrectangle($image, 0, 0, $dimension, $dimension, $white);

        for ($r = 0; $r < $modules; $r++) {
            for ($c = 0; $c < $modules; $c++) {
                if ($matrix[$r][$c]) {
                    $x = ($c + $margin) * $scale;
                    $y = ($r + $margin) * $scale;
                    imagefilledrectangle($image, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
                }
            }
        }

        return $image;
    }
}
